<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class CdekLocationResolver {
	public function __construct(
		private CdekApiClient $client,
		private Logger $logger
	) {
	}

	/**
	 * @return array{success:bool,city_code:int,city_name:string,region:string,source:string,confidence:float,reason:string}
	 */
	public function resolve( QuoteRequest $request ): array {
		$fias = $this->destination_fias( $request );
		$city = $this->destination_city( $request );
		$region = $this->destination_region( $request );
		if ( '' === $city && '' === $fias ) {
			return $this->failed( 'city_required' );
		}

		$cache_key = $this->cache_key( $fias, $city, $region );
		$cached = $this->cached( $cache_key );
		if ( array() !== $cached ) {
			$cached['source'] = 'cache:' . (string) ( $cached['source'] ?? 'cdek_location_cities' );
			return $cached;
		}

		try {
			$result = $this->client->cities(
				array_filter(
					array(
						'country_codes' => 'RU',
						'city' => $city,
						'region' => $region,
						'fias_guid' => $fias,
					),
					static fn( mixed $value ): bool => '' !== trim( (string) $value )
				)
			);
		} catch ( CdekApiException $exception ) {
			$this->logger->warning( 'CDEK location resolve failed.', array( 'message' => $exception->getMessage(), 'city' => $city, 'region' => $region ) );
			return $this->failed( 'api_error' );
		}

		$items = is_array( $result['body'] ?? null ) ? $result['body'] : array();
		$match = $this->best_match( $items, $fias, $city, $region );
		if ( empty( $match['success'] ) ) {
			return $match;
		}

		$this->store( $cache_key, $match );

		return $match;
	}

	/**
	 * @param array<int|string,mixed> $items
	 * @return array{success:bool,city_code:int,city_name:string,region:string,source:string,confidence:float,reason:string}
	 */
	private function best_match( array $items, string $fias, string $city, string $region ): array {
		$best = $this->failed( 'not_found' );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$code = (int) ( $item['code'] ?? $item['city_code'] ?? 0 );
			if ( $code <= 0 ) {
				continue;
			}
			$item_city = (string) ( $item['city'] ?? $item['city_name'] ?? '' );
			$item_region = (string) ( $item['region'] ?? $item['region_name'] ?? '' );
			$item_fias = strtolower( trim( (string) ( $item['fias_guid'] ?? $item['fias_id'] ?? '' ) ) );
			$confidence = 0.0;
			if ( '' !== $fias && '' !== $item_fias && strtolower( $fias ) === $item_fias ) {
				$confidence = 1.0;
			} elseif ( '' !== $city && $this->normalize( $city ) === $this->normalize( $item_city ) ) {
				$confidence = '' === $region || '' === $item_region || str_contains( $this->normalize( $item_region ), $this->normalize( $region ) ) || str_contains( $this->normalize( $region ), $this->normalize( $item_region ) ) ? 0.9 : 0.7;
			}
			if ( $confidence > (float) ( $best['confidence'] ?? 0 ) ) {
				$best = array(
					'success' => $confidence >= 0.85,
					'city_code' => $code,
					'city_name' => $item_city,
					'region' => $item_region,
					'source' => 'cdek_location_cities',
					'confidence' => $confidence,
					'reason' => $confidence >= 0.85 ? '' : 'low_confidence',
				);
			}
		}

		return $best;
	}

	/**
	 * @return array{success:bool,city_code:int,city_name:string,region:string,source:string,confidence:float,reason:string}
	 */
	private function failed( string $reason ): array {
		return array(
			'success' => false,
			'city_code' => 0,
			'city_name' => '',
			'region' => '',
			'source' => '',
			'confidence' => 0.0,
			'reason' => $reason,
		);
	}

	private function destination_fias( QuoteRequest $request ): string {
		return trim( (string) ( $request->destination->fias_id ?: ( $request->customer_context['selected_location_fias_id'] ?? $request->customer_context['location_fias_id'] ?? $request->customer_context['fias_id'] ?? '' ) ) );
	}

	private function destination_city( QuoteRequest $request ): string {
		return trim( (string) ( $request->destination->settlement ?: $request->destination->city ?: ( $request->customer_context['city_name'] ?? $request->customer_context['selected_location_name'] ?? $request->customer_context['display_name'] ?? '' ) ) );
	}

	private function destination_region( QuoteRequest $request ): string {
		return trim( (string) ( $request->destination->region_name ?: ( $request->customer_context['selected_location_region'] ?? '' ) ) );
	}

	private function normalize( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
		$value = str_replace( 'ё', 'е', $value );
		$value = preg_replace( '/\b(город|г|область|обл|край|республика|респ)\b\.?/u', ' ', $value ) ?? $value;

		return trim( preg_replace( '/[^a-zа-я0-9]+/u', ' ', $value ) ?? $value );
	}

	private function cache_key( string $fias, string $city, string $region ): string {
		return 'wdc_cdek_city_' . sha1( strtolower( implode( '|', array( $fias, $city, $region ) ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cached( string $key ): array {
		$value = function_exists( 'get_transient' ) ? get_transient( $key ) : false;

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function store( string $key, array $value ): void {
		if ( function_exists( 'set_transient' ) ) {
			$day = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
			set_transient( $key, $value, 30 * $day );
		}
	}
}
