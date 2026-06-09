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
	 * @return array<string,mixed>
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

		$attempts = $this->query_attempts( $fias, $city, $region, $request );
		$diagnostics = array();
		$best = $this->failed( 'not_found' );

		foreach ( $attempts as $attempt ) {
			$items = array();
			$http_code = 0;
			try {
				$result = $this->client->cities( $attempt['query'] );
				$http_code = (int) ( $result['http_code'] ?? 0 );
				$items = is_array( $result['body'] ?? null ) ? $result['body'] : array();
			} catch ( CdekApiException $exception ) {
				$http_code = (int) ( $exception->details()['http_code'] ?? 0 );
				$this->logger->warning(
					'CDEK location resolve failed.',
					array(
						'message' => $exception->getMessage(),
						'city' => $city,
						'region' => $region,
						'attempt_label' => $attempt['label'],
						'http_code' => $http_code,
					)
				);
				$diagnostics[] = $this->attempt_diagnostics( $attempt['label'], $attempt['query'], $http_code, 0 );

				return array_merge( $this->failed( 'api_error' ), $this->resolve_diagnostics( $diagnostics, '', 'api_error' ) );
			}

			$diagnostics[] = $this->attempt_diagnostics( $attempt['label'], $attempt['query'], $http_code, count( $items ) );
			$match = $this->best_match( $items, $fias, $attempt['match_city'], $attempt['match_region'] );
			if ( (float) ( $match['confidence'] ?? 0.0 ) > (float) ( $best['confidence'] ?? 0.0 ) ) {
				$best = $match;
				$best['selected_attempt_label'] = $attempt['label'];
			}
			if ( ! empty( $match['success'] ) ) {
				$selected = array_merge( $match, $this->resolve_diagnostics( $diagnostics, $attempt['label'], '' ) );
				$this->store( $cache_key, $selected );

				return $selected;
			}
		}

		$reason = (string) ( $best['reason'] ?? 'not_found' );

		return array_merge( $best, $this->resolve_diagnostics( $diagnostics, (string) ( $best['selected_attempt_label'] ?? '' ), $reason ) );
	}

	/**
	 * @param array<int|string,mixed> $items
	 * @return array<string,mixed>
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
				if ( '' === $region ) {
					$confidence = 0.86;
				} elseif ( '' === $item_region || $this->regions_compatible( $region, $item_region ) ) {
					$confidence = 0.9;
				} else {
					$confidence = 0.7;
				}
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
	 * @return array<string,mixed>
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

		return trim( preg_replace( '/[^a-zа-яА-Я0-9]+/u', ' ', $value ) ?? $value );
	}

	private function normalized_city_for_query( string $city ): string {
		return $this->title_case( $this->normalize( $city ) );
	}

	private function title_case( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' );
		}

		return ucwords( $value );
	}

	private function regions_compatible( string $expected, string $actual ): bool {
		$expected = $this->normalize( $expected );
		$actual = $this->normalize( $actual );

		return '' === $expected || '' === $actual || str_contains( $actual, $expected ) || str_contains( $expected, $actual );
	}

	/**
	 * @return array<int,array{label:string,query:array<string,string>,match_city:string,match_region:string}>
	 */
	private function query_attempts( string $fias, string $city, string $region, QuoteRequest $request ): array {
		$attempts = array();
		if ( '' !== $fias ) {
			$attempts[] = $this->attempt( 'fias_guid_only', array( 'fias_guid' => $fias ), $city, $region );
		}
		if ( '' !== $city && '' !== $region ) {
			$attempts[] = $this->attempt( 'city_region', array( 'city' => $city, 'region' => $region ), $city, $region );
		}
		if ( '' !== $city ) {
			$attempts[] = $this->attempt( 'city_only', array( 'city' => $city ), $city, '' );
			$normalized_city = $this->normalized_city_for_query( $city );
			if ( '' !== $normalized_city && $normalized_city !== trim( $city ) ) {
				$attempts[] = $this->attempt( 'normalized_city_only', array( 'city' => $normalized_city ), $normalized_city, '' );
			}
		}

		$context_city = $this->city_from_context( $request );
		if ( '' !== $context_city && $this->normalize( $context_city ) !== $this->normalize( $city ) ) {
			$attempts[] = $this->attempt( 'context_city_only', array( 'city' => $context_city ), $context_city, '' );
		}

		return $this->unique_attempts( $attempts );
	}

	/**
	 * @param array<string,string> $query
	 * @return array{label:string,query:array<string,string>,match_city:string,match_region:string}
	 */
	private function attempt( string $label, array $query, string $match_city, string $match_region ): array {
		$query = array_merge( array( 'country_codes' => 'RU' ), $query );

		return array( 'label' => $label, 'query' => $query, 'match_city' => $match_city, 'match_region' => $match_region );
	}

	/**
	 * @param array<int,array{label:string,query:array<string,string>,match_city:string,match_region:string}> $attempts
	 * @return array<int,array{label:string,query:array<string,string>,match_city:string,match_region:string}>
	 */
	private function unique_attempts( array $attempts ): array {
		$seen = array();
		$unique = array();
		foreach ( $attempts as $attempt ) {
			$key = (string) json_encode( $attempt['query'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[] = $attempt;
		}

		return $unique;
	}

	private function city_from_context( QuoteRequest $request ): string {
		foreach ( array( 'display_name', 'selected_location_name' ) as $key ) {
			$value = trim( (string) ( $request->customer_context[ $key ] ?? '' ) );
			$city = $this->extract_city_from_text( $value );
			if ( '' !== $city ) {
				return $city;
			}
		}

		return '';
	}

	private function extract_city_from_text( string $value ): string {
		$parts = array_values( array_filter( array_map( 'trim', preg_split( '/[,;]+/u', $value ) ?: array() ) ) );
		foreach ( $parts as $part ) {
			if ( preg_match( '/\b(область|обл|край|республика|район)\b/u', $part ) ) {
				continue;
			}
			$city = $this->normalized_city_for_query( $part );
			if ( '' !== $city ) {
				return $city;
			}
		}

		return $this->normalized_city_for_query( $value );
	}

	/**
	 * @param array<string,string> $query
	 * @return array<string,mixed>
	 */
	private function attempt_diagnostics( string $label, array $query, int $http_code, int $items_count ): array {
		return array(
			'label' => $label,
			'query' => $query,
			'http_code' => $http_code,
			'items_count' => $items_count,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $attempts
	 * @return array<string,mixed>
	 */
	private function resolve_diagnostics( array $attempts, string $selected_label, string $reason ): array {
		return array(
			'attempts_count' => count( $attempts ),
			'attempts_labels' => array_values( array_map( static fn( array $attempt ): string => (string) $attempt['label'], $attempts ) ),
			'attempts' => $attempts,
			'selected_attempt_label' => $selected_label,
			'final_reason' => $reason,
		);
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
