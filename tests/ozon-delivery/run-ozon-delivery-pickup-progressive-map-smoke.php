<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( mixed $value ): string|false { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function wp_salt( string $scheme = 'auth' ): string { return 'test-salt-' . $scheme; }
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupProgressiveQueryService;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

function oz_progressive_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

final class OzonProgressiveWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */ public array $generations = array();
	/** @var array<int,array<string,mixed>> */ public array $points = array();
	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$replacement = is_float( $value ) ? sprintf( '%.12F', $value ) : (string) (int) $value;
			$sql = preg_replace( '/%[dfs]/', $replacement, $sql, 1 ) ?? $sql;
		}
		return $sql;
	}
	public function get_row( string $sql, mixed $output = null ): ?array {
		unset( $output );
		if ( str_contains( $sql, "state='active'" ) ) {
			$rows = array_values( array_filter( $this->generations, static fn( array $row ): bool => 'active' === $row['state'] ) );
			usort( $rows, static fn( array $a, array $b ): int => $b['id'] <=> $a['id'] );
			return $rows[0] ?? null;
		}
		return null;
	}
	/** @return array<int,array<string,mixed>> */
	public function get_results( string $sql, mixed $output = null ): array {
		unset( $output );
		if ( ! str_contains( $sql, 'wdc_candidates' ) ) { return array(); }
		preg_match( '/generation_id=(\d+)/', $sql, $generation );
		preg_match_all( '/latitude BETWEEN ([0-9.\-]+) AND ([0-9.\-]+)/', $sql, $latitude_ranges, PREG_SET_ORDER );
		preg_match_all( '/longitude BETWEEN ([0-9.\-]+) AND ([0-9.\-]+)/', $sql, $longitude_ranges, PREG_SET_ORDER );
		$area_lat = end( $latitude_ranges );
		$area_lng = end( $longitude_ranges );
		preg_match( '/\(latitude-([0-9.\-]+)\)/', $sql, $center_lat );
		preg_match( '/\(longitude-([0-9.\-]+)\)/', $sql, $center_lng );
		$viewport_lat = count( $latitude_ranges ) > 1 ? $latitude_ranges[0] : null;
		$viewport_lng = count( $longitude_ranges ) > 1 ? $longitude_ranges[0] : null;
		$rows = array();
		foreach ( $this->points as $row ) {
			if ( (int) $row['generation_id'] !== (int) ( $generation[1] ?? 0 ) || 1 !== (int) $row['is_active'] || null === $row['latitude'] || null === $row['longitude'] ) { continue; }
			$lat = (float) $row['latitude']; $lng = (float) $row['longitude'];
			if ( $lat < (float) $area_lat[1] || $lat > (float) $area_lat[2] || $lng < (float) $area_lng[1] || $lng > (float) $area_lng[2] ) { continue; }
			$row['wdc_phase'] = $viewport_lat && $lat >= (float) $viewport_lat[1] && $lat <= (float) $viewport_lat[2] && $lng >= (float) $viewport_lng[1] && $lng <= (float) $viewport_lng[2] ? 0 : 1;
			$row['wdc_distance'] = (string) round( ( ( $lat - (float) $center_lat[1] ) ** 2 + ( $lng - (float) $center_lng[1] ) ** 2 ) * 1000000000000 );
			$rows[] = $row;
		}
		usort( $rows, static fn( array $a, array $b ): int => array( (int) $a['wdc_phase'], (float) $a['wdc_distance'], (int) $a['point_id'] ) <=> array( (int) $b['wdc_phase'], (float) $b['wdc_distance'], (int) $b['point_id'] ) );
		if ( preg_match( '/WHERE \(wdc_phase>(\d+) OR \(wdc_phase=\d+ AND \(wdc_distance>([0-9.\-]+) OR \(wdc_distance=[0-9.\-]+ AND point_id>(\d+)\)\)\)\)/', $sql, $after ) ) {
			$cursor = array( (int) $after[1], (float) $after[2], (int) $after[3] );
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => array( (int) $row['wdc_phase'], (float) $row['wdc_distance'], (int) $row['point_id'] ) > $cursor ) );
		}
		preg_match( '/LIMIT (\d+)$/', $sql, $limit );
		return array_slice( $rows, 0, (int) ( $limit[1] ?? 2500 ) );
	}
}

/** @return array<string,mixed> */
function oz_progressive_point( int $generation, int $id, float $lat, float $lng, bool $active = true, ?int $max_weight = null ): array {
	return array( 'generation_id' => $generation, 'point_id' => $id, 'name' => 'Ozon ' . $id, 'type' => 0 === $id % 5 ? 'postamat' : 'pvz', 'full_address' => 'Адрес ' . $id, 'latitude' => $lat, 'longitude' => $lng, 'schedule' => '09:00-21:00', 'is_active' => $active ? 1 : 0, 'is_bulky' => 0, 'min_weight_g' => null, 'max_weight_g' => $max_weight, 'max_width_mm' => null, 'max_length_mm' => null, 'max_height_mm' => null );
}

foreach ( array( 2000, 5000, 10000 ) as $size ) {
	$db = new OzonProgressiveWpdb();
	$db->generations = array( array( 'id' => 7, 'state' => 'active' ), array( 'id' => 8, 'state' => 'building' ) );
	for ( $i = 0; $i < $size; ++$i ) {
		$lat = 55.75 + ( ( $i % 100 ) - 50 ) * 0.001;
		$lng = 37.61 + ( (int) ( $i / 100 ) - 50 ) * 0.001;
		$db->points[] = oz_progressive_point( 7, 100000 + $i, $lat, $lng, 0 !== $i % 101, 0 === $i % 17 ? 500 : null );
		if ( $i < 20 ) { $db->points[] = oz_progressive_point( 8, 900000 + $i, $lat, $lng ); }
	}
	$db->points[] = oz_progressive_point( 7, 777777, 56.25, 38.11 ); // Inside the radius bbox, outside the exact 60 km circle.
	$repository = new OzonDeliveryPickupRepository( $db );
	$provider = new OzonDeliveryPickupPointProvider( $repository );
	$service = new OzonDeliveryPickupProgressiveQueryService( $repository, $provider );
	$query = new CarrierPickupPointQuery( OzonDeliverySettings::CARRIER_KEY, 77, 'RU', '', 55.75, 37.61, new PickupCargoConstraints( 1000, 0, 0, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 60, 10 );
	$viewport = array( 'west' => 37.59, 'south' => 55.73, 'east' => 37.63, 'north' => 55.77 );
	$first = $service->start( $query, $viewport, 1500 );
	$repeat = $service->start( $query, $viewport, 1500 );
	oz_progressive_assert( $first['dataset'] === $repeat['dataset'] && $first['cursor'] === $repeat['cursor'], $size . ': initial dataset and continuation must be deterministic.' );
	oz_progressive_assert( count( $first['points'] ) <= 1500 && (int) $first['loaded'] === count( $first['points'] ), $size . ': first chunk must be bounded and report accepted unique points.' );
	$codes = array_map( static fn( $point ): string => $point->code, $first['points'] );
	$outside_seen = false;
	foreach ( $first['points'] as $point ) {
		$inside = $point->latitude >= $viewport['south'] && $point->latitude <= $viewport['north'] && $point->longitude >= $viewport['west'] && $point->longitude <= $viewport['east'];
		if ( ! $inside ) { $outside_seen = true; }
		oz_progressive_assert( ! $inside || ! $outside_seen, $size . ': all eligible viewport points must precede the distance-ordered remainder.' );
	}
	if ( 5000 === $size ) {
		oz_progressive_assert( count( $first['points'] ) > 0 && count( $first['points'] ) < 1500 && ! $outside_seen, 'A useful but smaller viewport portion must return immediately without padding first paint with distant points.' );
	}
	$current = $first;
	while ( ! $current['complete'] ) {
		$current = $service->next( $query, $current['dataset'], $current['cursor'], 1500 );
		$codes = array_merge( $codes, array_map( static fn( $point ): string => $point->code, $current['points'] ) );
	}
	oz_progressive_assert( count( $codes ) === count( array_unique( $codes ) ), $size . ': progressive chunks must not overlap.' );
	oz_progressive_assert( count( $codes ) === (int) $first['total'] && (int) $current['loaded'] === (int) $first['total'], $size . ': completion must emit every eligible point and exact loaded must equal total.' );
	oz_progressive_assert( ! in_array( '100000', $codes, true ) && ! in_array( '100017', $codes, true ) && ! in_array( '900000', $codes, true ) && ! in_array( '777777', $codes, true ), $size . ': inactive, cargo-ineligible, exact-radius-ineligible and inactive-generation points must be excluded.' );
	if ( 2000 === $size ) {
		$multi_box = new CarrierPickupPointQuery(
			OzonDeliverySettings::CARRIER_KEY,
			77,
			'RU',
			'',
			55.75,
			37.61,
			new PickupCargoConstraints( 800, 0, 0, 400, 2, array(
				array( 'weight_g' => 400, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ),
				array( 'weight_g' => 400, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ),
			) ),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			60,
			10
		);
		$multi_first = $service->start( $multi_box, $viewport, 1500 );
		$multi_codes = array_map( static fn( $point ): string => $point->code, $multi_first['points'] );
		while ( ! $multi_first['complete'] ) {
			$multi_first = $service->next( $multi_box, $multi_first['dataset'], $multi_first['cursor'], 1500 );
			$multi_codes = array_merge( $multi_codes, array_map( static fn( $point ): string => $point->code, $multi_first['points'] ) );
		}
		oz_progressive_assert( in_array( '100017', $multi_codes, true ), 'Multi-box per-place cargo eligibility must accept a point rejected by the equivalent single heavy place.' );
	}
	$db->generations[0]['state'] = 'obsolete';
	$db->generations[1]['state'] = 'active';
	try {
		$service->next( $query, $first['dataset'], $first['cursor'], 1500 );
		oz_progressive_assert( false, $size . ': generation switch must invalidate continuation.' );
	} catch ( RuntimeException $exception ) {
		oz_progressive_assert( 'ozon_progressive_generation_changed' === $exception->getMessage(), $size . ': generation mismatch must have the restart-safe error code.' );
	}
}

echo "Ozon Delivery progressive pickup map smoke passed.\n";
