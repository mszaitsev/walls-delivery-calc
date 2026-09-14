<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class CdekPickupCoverageService {
	public const CACHE_PREFIX = 'wdc_cdek_region_directory_';
	private const PAGE_SIZE = 1000;
	private const MAX_PAGES = 50;

	public function __construct(
		private CdekApiClient $client,
		private CdekSettings $settings,
		private LocationRepository $locations,
		private Logger $logger
	) {
	}

	/** @return array<int,array<string,mixed>> */
	public function cities_for_location( array $location, array $primary ): array {
		$primary_row = $this->primary_row( $primary );
		if ( array() === $primary_row || ! $this->expansion_eligible( (int) ( $location['location_id'] ?? 0 ) ) ) {
			return array() === $primary_row ? array() : array( $primary_row );
		}

		$country = strtoupper( trim( (string) ( $primary['country_code'] ?? '' ) ) );
		$region_code = (int) ( $primary['region_code'] ?? 0 );
		$group = $this->normalize_sub_region( (string) ( $primary['sub_region'] ?? '' ) );
		if ( '' === $country || $region_code <= 0 || '' === $group ) {
			return array( $primary_row );
		}

		$directory = $this->region_directory( $country, $region_code );
		if ( null === $directory ) {
			return array( $primary_row );
		}
		$rows = is_array( $directory[ $group ] ?? null ) ? $directory[ $group ] : array();
		$by_code = array( (int) $primary_row['code'] => $primary_row );
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (int) ( $row['code'] ?? 0 ) > 0 ) {
				$by_code[ (int) $row['code'] ] = $row;
			}
		}
		ksort( $by_code, SORT_NUMERIC );

		return array_values( $by_code );
	}

	public function expansion_eligible( int $location_id ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location || ! $location->active || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
			return false;
		}
		$fias = strtolower( trim( $location->fias_id ) );
		$city_fias = strtolower( trim( $location->city_fias_id ) );

		return '' !== $fias && '' !== $city_fias && $fias === $city_fias;
	}

	/** @return array<string,mixed> */
	public function canonical_location_context( int $location_id ): array {
		if ( $location_id <= 0 ) {
			return array();
		}
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location || ! $location->active ) {
			return array();
		}

		return array_merge( $location->to_array(), array( 'location_id' => $location_id ) );
	}

	/** @return array<string,array<int,array<string,mixed>>>|null */
	private function region_directory( string $country, int $region_code ): ?array {
		$key = self::CACHE_PREFIX . sha1( $this->settings->environment() . '|' . $country . '|' . $region_code );
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$groups = array();
		$seen = array();
		try {
			for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
				$result = $this->client->cities(
					array( 'country_codes' => $country, 'region_code' => $region_code, 'page' => $page, 'size' => self::PAGE_SIZE )
				);
				$body = $result['body'] ?? null;
				if ( ! is_array( $body ) ) {
					throw new \RuntimeException( 'malformed_body' );
				}
				$count = count( $body );
				foreach ( $body as $item ) {
					if ( ! is_array( $item ) ) {
						throw new \RuntimeException( 'malformed_city_row' );
					}
					$row = $this->minimal_row( $item, $country, $region_code );
					$code = (int) ( $row['code'] ?? 0 );
					if ( $code <= 0 ) {
						throw new \RuntimeException( 'malformed_city_code' );
					}
					$group = $this->normalize_sub_region( (string) ( $row['sub_region'] ?? '' ) );
					if ( '' === $group || isset( $seen[ $code ] ) || $country !== (string) ( $row['country_code'] ?? '' ) || $region_code !== (int) ( $row['region_code'] ?? 0 ) ) {
						continue;
					}
					$seen[ $code ] = true;
					$groups[ $group ][] = array( 'code' => $code, 'city' => (string) ( $row['city'] ?? '' ) );
				}
				if ( $count < self::PAGE_SIZE ) {
					ksort( $groups );
					foreach ( $groups as &$rows ) {
						usort( $rows, static fn( array $a, array $b ): int => (int) $a['code'] <=> (int) $b['code'] );
					}
					unset( $rows );
					if ( function_exists( 'set_transient' ) ) {
						set_transient( $key, $groups, $this->ttl() );
					}
					return $groups;
				}
			}
			throw new \RuntimeException( 'page_limit' );
		} catch ( CdekApiException|\RuntimeException $exception ) {
			$details = $exception instanceof CdekApiException ? $exception->details() : array();
			$this->logger->warning( 'CDEK region directory failed.', array(
				'carrier' => CdekSettings::CARRIER_KEY,
				'country_code' => $country,
				'region_code' => $region_code,
				'http_code' => (int) ( $details['http_code'] ?? 0 ),
				'reason' => $exception instanceof CdekApiException ? 'api_error' : $exception->getMessage(),
			) );
			return null;
		}
	}

	/** @return array<string,mixed> */
	private function primary_row( array $primary ): array {
		$code = (int) ( $primary['city_code'] ?? 0 );
		if ( empty( $primary['success'] ) || $code <= 0 ) {
			return array();
		}
		return array(
			'code' => $code,
			'city' => (string) ( $primary['city_name'] ?? '' ),
			'fias_guid' => (string) ( $primary['fias_guid'] ?? '' ),
			'country_code' => strtoupper( trim( (string) ( $primary['country_code'] ?? '' ) ) ),
			'region_code' => (int) ( $primary['region_code'] ?? 0 ),
			'sub_region' => trim( (string) ( $primary['sub_region'] ?? '' ) ),
		);
	}

	/** @return array<string,mixed> */
	private function minimal_row( array $item, string $country, int $region_code ): array {
		return array(
			'code' => (int) ( $item['code'] ?? $item['city_code'] ?? 0 ),
			'city' => trim( (string) ( $item['city'] ?? $item['city_name'] ?? '' ) ),
			'fias_guid' => trim( (string) ( $item['fias_guid'] ?? $item['fias_id'] ?? '' ) ),
			'country_code' => strtoupper( trim( (string) ( $item['country_code'] ?? $country ) ) ),
			'region_code' => (int) ( $item['region_code'] ?? $region_code ),
			'sub_region' => trim( (string) ( $item['sub_region'] ?? '' ) ),
			'latitude' => is_numeric( $item['latitude'] ?? null ) ? (float) $item['latitude'] : null,
			'longitude' => is_numeric( $item['longitude'] ?? null ) ? (float) $item['longitude'] : null,
		);
	}

	private function normalize_sub_region( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ) ) : strtolower( trim( $value ) );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	private function ttl(): int {
		$end = strtotime( 'today 23:59:59' );
		return is_int( $end ) ? max( 1, $end - time() ) : 86400;
	}
}
