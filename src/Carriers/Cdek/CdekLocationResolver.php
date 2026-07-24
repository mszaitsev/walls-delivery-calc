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
		private CdekSettings $settings,
		private Logger $logger
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function resolve( QuoteRequest $request ): array {
		$country = $this->destination_country( $request );
		$fias = 'RU' === $country ? $this->destination_fias( $request ) : '';
		$city = $this->destination_city( $request );
		$region = $this->destination_region( $request );
		$postcode = $this->destination_postcode( $request );
		if ( '' === $country ) {
			return $this->failed( 'unsupported_country' );
		}
		if ( '' === $city && '' === $fias ) {
			return $this->failed( 'city_required' );
		}

		$cache_key = $this->cache_key( $country, $fias, $city, $region, $postcode, $request );
		$cached = $this->cached( $cache_key );
		if ( array() !== $cached ) {
			$cached['source'] = 'cache:' . (string) ( $cached['source'] ?? 'cdek_location_cities' );
			return $cached;
		}

		$attempts = $this->query_attempts( $country, $fias, $city, $region, $postcode, $request );
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
			$match = $this->best_match( $items, $country, $fias, $attempt['match_city'], $attempt['match_region'], $postcode );
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
	private function best_match( array $items, string $country, string $fias, string $city, string $region, string $postcode ): array {
		$matches = array();
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
			$item_country = strtoupper( trim( (string) ( $item['country_code'] ?? $item['country'] ?? $country ) ) );
			if ( '' !== $item_country && $item_country !== $country ) {
				continue;
			}
			$item_fias = strtolower( trim( (string) ( $item['fias_guid'] ?? $item['fias_id'] ?? '' ) ) );
			$item_postcode = preg_replace( '/\D+/', '', (string) ( $item['postal_code'] ?? $item['postcode'] ?? '' ) ) ?? '';
			if ( 'RU' === $country && '' !== $fias && '' !== $item_fias && strtolower( $fias ) === $item_fias ) {
				$confidence = 1.0;
			} elseif ( '' === $city || $this->normalize( $city ) !== $this->normalize( $item_city ) ) {
				continue;
			} elseif ( '' !== $postcode && '' !== $item_postcode && $postcode !== $item_postcode ) {
				continue;
			} elseif ( '' !== $region && '' !== $item_region && ! $this->regions_compatible( $region, $item_region ) ) {
				continue;
			} else {
				$confidence = '' !== $region || '' !== $postcode ? 0.95 : 0.9;
			}
			$matches[ $code ] = array(
				'success' => true,
					'city_code' => $code,
					'city_name' => $item_city,
				'country_code' => $country,
					'region' => $item_region,
					'source' => 'cdek_location_cities',
					'confidence' => $confidence,
				'reason' => '',
			);
		}

		if ( 1 === count( $matches ) ) {
			return array_values( $matches )[0];
		}

		return $this->failed( count( $matches ) > 1 ? 'ambiguous' : 'not_found' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failed( string $reason ): array {
		return array(
			'success' => false,
			'city_code' => 0,
			'city_name' => '',
			'country_code' => '',
			'region' => '',
			'source' => '',
			'confidence' => 0.0,
			'reason' => $reason,
		);
	}

	private function destination_fias( QuoteRequest $request ): string {
		return trim( (string) ( $request->destination->fias_id ?: ( $request->customer_context['selected_location_fias_id'] ?? $request->customer_context['location_fias_id'] ?? $request->customer_context['fias_id'] ?? '' ) ) );
	}

	private function destination_country( QuoteRequest $request ): string {
		$country = strtoupper( trim( (string) ( $request->country_code ?: $request->destination->country_code ?: ( $request->customer_context['country_code'] ?? '' ) ) ) );
		return in_array( $country, CdekSettings::SUPPORTED_COUNTRIES, true ) ? $country : '';
	}

	private function destination_city( QuoteRequest $request ): string {
		return trim( (string) ( $request->destination->settlement ?: $request->destination->city ?: ( $request->customer_context['city_name'] ?? $request->customer_context['selected_location_name'] ?? $request->customer_context['display_name'] ?? '' ) ) );
	}

	private function destination_region( QuoteRequest $request ): string {
		return trim( (string) ( $request->destination->region_name ?: ( $request->customer_context['selected_location_region'] ?? '' ) ) );
	}

	private function destination_postcode( QuoteRequest $request ): string {
		return preg_replace( '/\D+/', '', (string) ( $request->destination->postcode ?: ( $request->customer_context['postcode'] ?? $request->customer_context['postal_code'] ?? '' ) ) ) ?? '';
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
	private function query_attempts( string $country, string $fias, string $city, string $region, string $postcode, QuoteRequest $request ): array {
		$attempts = array();
		$lat = $this->coordinate_from_context( $request, array( 'lat', 'latitude', 'geo_lat' ) );
		$lng = $this->coordinate_from_context( $request, array( 'lng', 'lon', 'longitude', 'geo_lon' ) );
		if ( null !== $lat && null !== $lng ) {
			$attempts[] = $this->attempt( $country, 'coordinates', array( 'latitude' => (string) $lat, 'longitude' => (string) $lng, 'size' => '10' ), $city, $region );
		}
		if ( 'RU' === $country && '' !== $fias ) {
			$attempts[] = $this->attempt( $country, 'fias_guid_only', array( 'fias_guid' => $fias ), $city, $region );
		}
		if ( '' !== $city && '' !== $postcode ) {
			$attempts[] = $this->attempt( $country, 'city_postcode', array( 'city' => $city, 'postal_code' => $postcode ), $city, $region );
		}
		if ( '' !== $city ) {
			$attempts[] = $this->attempt( $country, 'city_only', array( 'city' => $city ), $city, $region );
			$normalized_city = $this->normalized_city_for_query( $city );
			if ( '' !== $normalized_city && $normalized_city !== trim( $city ) ) {
				$attempts[] = $this->attempt( $country, 'normalized_city_only', array( 'city' => $normalized_city ), $normalized_city, $region );
			}
		}

		$context_city = $this->city_from_context( $request );
		if ( '' !== $context_city && $this->normalize( $context_city ) !== $this->normalize( $city ) ) {
			$attempts[] = $this->attempt( $country, 'context_city_only', array( 'city' => $context_city ), $context_city, $region );
		}

		return $this->unique_attempts( $attempts );
	}

	/**
	 * @param array<int,string> $keys
	 */
	private function coordinate_from_context( QuoteRequest $request, array $keys ): ?float {
		foreach ( $keys as $key ) {
			$value = $request->customer_context[ $key ] ?? null;
			if ( null === $value || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
				continue;
			}

			return (float) $value;
		}

		return null;
	}

	/**
	 * @param array<string,string> $query
	 * @return array{label:string,query:array<string,string>,match_city:string,match_region:string}
	 */
	private function attempt( string $country, string $label, array $query, string $match_city, string $match_region ): array {
		$query = array_merge( array( 'country_codes' => $country ), $query );

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

	private function cache_key( string $country, string $fias, string $city, string $region, string $postcode, QuoteRequest $request ): string {
		$lat = (string) ( $this->coordinate_from_context( $request, array( 'lat', 'latitude', 'geo_lat' ) ) ?? '' );
		$lng = (string) ( $this->coordinate_from_context( $request, array( 'lng', 'lon', 'longitude', 'geo_lon' ) ) ?? '' );
		return 'wdc_cdek_city_' . sha1( strtolower( implode( '|', array( $this->settings->environment(), $country, $fias, $city, $region, $postcode, $lat, $lng ) ) ) );
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
			$ttl = max( 60, strtotime( 'tomorrow' ) - time() );
			set_transient( $key, $value, $ttl );
		}
	}
}
