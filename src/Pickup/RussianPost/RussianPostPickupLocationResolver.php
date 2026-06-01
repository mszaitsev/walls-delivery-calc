<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupLocationResolver {
	private \wpdb $wpdb;

	/** @var array<string,array{status:string,strategy:string,location_id:int|null,location:Location|null}> */
	private array $fias_cache = array();

	/** @var array<string,array{status:string,strategy:string,location_id:int|null,location:Location|null}> */
	private array $postal_cache = array();

	/** @var array<string,array{status:string,strategy:string,location_id:int|null,location:Location|null}> */
	private array $region_city_cache = array();

	/** @var array<string,int> */
	private array $stats = array(
		'fias_queries' => 0,
		'postal_queries' => 0,
		'region_city_queries' => 0,
		'cache_hits' => 0,
	);

	public function __construct(
		private LocationRepository $locations,
		?\wpdb $db = null
	) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function resolve_location_id_for_pickup_row( array $row ): ?int {
		$result = $this->resolve( $row );

		return 'unique' === $result['status'] ? $result['location_id'] : null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{status:string,strategy:string,location_id:int|null,location:Location|null}
	 */
	public function resolve( array $row ): array {
		$fias = $this->normalize_guid( (string) ( $row['fias_location_guid'] ?? '' ) );
		if ( '' !== $fias ) {
			$result = $this->resolve_by_fias( $fias, (string) ( $row['fias_location_guid'] ?? '' ) );
			if ( 'unique' === $result['status'] ) {
				return $result;
			}
		}

		$postcode = preg_replace( '/\D+/', '', (string) ( $row['postcode'] ?? $row['postal_code'] ?? '' ) ) ?? '';
		if ( '' !== $postcode && '999999999' !== $postcode ) {
			$result = $this->resolve_by_postal_code( $postcode );
			if ( 'none' !== $result['status'] ) {
				return $result;
			}
		}

		$region = trim( (string) ( $row['region_name'] ?? '' ) );
		$city = trim( (string) ( $row['city_name'] ?? $row['settlement_name'] ?? '' ) );
		if ( '' !== $region && '' !== $city ) {
			$result = $this->resolve_by_region_city( $region, $city );
			if ( 'none' !== $result['status'] ) {
				return $result;
			}
		}

		return $this->result( 'none', 'no_match' );
	}

	/**
	 * @return array<string,int>
	 */
	public function cache_stats(): array {
		return $this->stats;
	}

	/**
	 * @return array{status:string,strategy:string,location_id:int|null,location:Location|null}
	 */
	private function resolve_by_fias( string $normalized_fias, string $raw_fias ): array {
		if ( isset( $this->fias_cache[ $normalized_fias ] ) ) {
			++$this->stats['cache_hits'];
			return $this->fias_cache[ $normalized_fias ];
		}
		++$this->stats['fias_queries'];

		$location = $this->locations->find_by_fias_id( $raw_fias );
		$this->fias_cache[ $normalized_fias ] = $location instanceof Location && null !== $location->id && $location->id > 0
			? $this->result( 'unique', 'fias', $location )
			: $this->result( 'none', 'no_match' );

		return $this->fias_cache[ $normalized_fias ];
	}

	/**
	 * @return array{status:string,strategy:string,location_id:int|null,location:Location|null}
	 */
	private function resolve_by_postal_code( string $postcode ): array {
		if ( isset( $this->postal_cache[ $postcode ] ) ) {
			++$this->stats['cache_hits'];
			return $this->postal_cache[ $postcode ];
		}
		++$this->stats['postal_queries'];

		$candidates = $this->locations_by_postcode( $postcode );
		if ( 1 === count( $candidates ) ) {
			$this->postal_cache[ $postcode ] = $this->result( 'unique', 'postal_code', $candidates[0] );
		} elseif ( count( $candidates ) > 1 ) {
			$this->postal_cache[ $postcode ] = $this->result( 'ambiguous', 'postal_code' );
		} else {
			$this->postal_cache[ $postcode ] = $this->result( 'none', 'no_match' );
		}

		return $this->postal_cache[ $postcode ];
	}

	/**
	 * @return array{status:string,strategy:string,location_id:int|null,location:Location|null}
	 */
	private function resolve_by_region_city( string $region, string $city ): array {
		$key = $this->normalize_text( $region ) . '|' . $this->normalize_text( $city );
		if ( isset( $this->region_city_cache[ $key ] ) ) {
			++$this->stats['cache_hits'];
			return $this->region_city_cache[ $key ];
		}
		++$this->stats['region_city_queries'];

		$candidates = $this->locations->search_by_tokens( array( $region, $city ), 20, true, '', 'RU' );
		$candidates = array_values(
			array_filter(
				$candidates,
				fn( Location $location ): bool => $this->same_normalized( $region, $location->region_name )
					&& $this->same_normalized( $city, $location->resolved_place_name() . ' ' . $location->city_name . ' ' . $location->display_name )
			)
		);

		if ( 1 === count( $candidates ) ) {
			$this->region_city_cache[ $key ] = $this->result( 'unique', 'region_city', $candidates[0] );
		} elseif ( count( $candidates ) > 1 ) {
			$this->region_city_cache[ $key ] = $this->result( 'ambiguous', 'region_city' );
		} else {
			$this->region_city_cache[ $key ] = $this->result( 'none', 'no_match' );
		}

		return $this->region_city_cache[ $key ];
	}

	/**
	 * @return array<int,Location>
	 */
	private function locations_by_postcode( string $postcode ): array {
		if ( property_exists( $this->wpdb, 'locations' ) && is_array( $this->wpdb->locations ) ) {
			$rows = array_values( array_filter( $this->wpdb->locations, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 1 ) && $postcode === (string) ( $row['postal_code'] ?? '' ) ) );

			return array_map( static fn( array $row ): Location => Location::from_array( $row ), $rows );
		}

		$locations = $this->wpdb->prefix . 'wdc_locations';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$locations} WHERE active = 1 AND postal_code = %s LIMIT 2", $postcode ),
			ARRAY_A
		);

		return array_map( static fn( array $row ): Location => Location::from_array( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array{status:string,strategy:string,location_id:int|null,location:Location|null}
	 */
	private function result( string $status, string $strategy, ?Location $location = null ): array {
		return array(
			'status' => $status,
			'strategy' => $strategy,
			'location_id' => $location instanceof Location ? $location->id : null,
			'location' => $location,
		);
	}

	private function same_normalized( string $needle, string $haystack ): bool {
		$needle = $this->normalize_text( $needle );
		$haystack = $this->normalize_text( $haystack );

		return '' !== $needle && '' !== $haystack && ( $needle === $haystack || str_contains( $haystack, $needle ) );
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/[^a-zа-я0-9]+/u', ' ', $value ) ?? $value;

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	private function normalize_guid( string $value ): string {
		return strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
	}
}
