<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Pickup\RussianPost\RussianPostImportBatchProfiler;

function rp_profiler_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function rp_profile_fixture( string $strategy, int $matches, int $unique_keys, int $ambiguous = 0, int $no_match = 0, int $skipped = 0 ): array {
	$profiler = new RussianPostImportBatchProfiler( 1, 0, '2026-09-11 12:00:00' );
	$objects = $matches + $ambiguous + $no_match + $skipped;
	$profiler->measure( 'payload_read_ms', static fn(): array => range( 1, $objects ) );
	for ( $index = 0; $index < $matches; ++$index ) {
		$key = $strategy . '-' . ( $index % max( 1, $unique_keys ) );
		$profiler->record_lookup( $strategy, $key );
		if ( $index < $unique_keys ) {
			$profiler->record_lookup_query( $strategy, 0.01 );
		}
		$match_strategy = 'postcode' === $strategy ? 'postal_code' : $strategy;
		$profiler->record_match( array( 'status' => 'unique', 'strategy' => $match_strategy ) );
	}
	for ( $index = 0; $index < $ambiguous; ++$index ) {
		$profiler->record_match( array( 'status' => 'ambiguous', 'strategy' => $strategy ) );
	}
	for ( $index = 0; $index < $no_match; ++$index ) {
		$profiler->record_match( array( 'status' => 'none', 'strategy' => 'no_match' ) );
	}
	$profiler->record_skipped( $skipped );
	$profiler->measure( 'parse_ms', static fn(): array => json_decode( '[{"ok":true}]', true ) ?? array() );
	$profiler->measure( 'normalize_ms', static fn(): string => trim( ' value ' ) );
	$profiler->measure( 'location_match_ms', static fn(): bool => true );
	$profiler->measure( 'staging_prepare_ms', static fn(): bool => true );
	$profiler->measure( 'staging_write_ms', static fn(): bool => true );
	$profiler->measure( 'checkpoint_ms', static fn(): bool => true );
	$profiler->measure( 'lock_renew_ms', static fn(): bool => true );

	return $profiler->finish( 12345, $objects );
}

$fias = rp_profile_fixture( 'fias', 500, 500 );
$postcode = rp_profile_fixture( 'postcode', 500, 37 );
$region_city = rp_profile_fixture( 'region_city', 500, 75 );
$mixed = rp_profile_fixture( 'fias', 470, 100, 10, 12, 8 );

rp_profiler_assert( 500 === (int) $fias['match_counts']['matched_fias'] && 500 === (int) $fias['lookup_counts']['unique_fias_keys'], 'FIAS fixture counters must describe 500 unique FIAS matches.' );
rp_profiler_assert( 500 === (int) $postcode['match_counts']['matched_postal_code'] && 500 === (int) $postcode['lookup_counts']['postcode_lookups'] && 37 === (int) $postcode['lookup_counts']['unique_postcode_keys'], 'Repeated postcode fixture must distinguish total lookups from unique keys.' );
rp_profiler_assert( 500 === (int) $region_city['match_counts']['matched_region_city'] && 75 === (int) $region_city['query_counts']['region_city_lookup_queries'], 'Region/city fixture must expose strategy matches and lookup query counts.' );
rp_profiler_assert( 10 === (int) $mixed['match_counts']['ambiguous'] && 12 === (int) $mixed['match_counts']['no_match'] && 8 === (int) $mixed['match_counts']['skipped'], 'Mixed fixture must retain ambiguous, no-match, and skipped counters.' );

foreach ( array( $fias, $postcode, $region_city, $mixed ) as $profile ) {
	foreach ( array( 'payload_read_ms', 'parse_ms', 'normalize_ms', 'location_match_ms', 'staging_prepare_ms', 'staging_write_ms', 'checkpoint_ms', 'lock_renew_ms', 'total_batch_ms' ) as $timing ) {
		rp_profiler_assert( isset( $profile[ $timing ] ) && (int) $profile[ $timing ] >= 0, 'Profiler timing must be present and non-negative: ' . $timing );
	}
	$encoded = (string) json_encode( $profile );
	rp_profiler_assert( ! str_contains( $encoded, 'raw-address' ) && ! str_contains( $encoded, 'fias-0' ) && ! str_contains( $encoded, 'postcode-0' ), 'Persistable profile must contain counters only, never raw lookup values.' );
}

$source_rows = array_map( static fn( int $id ): array => array( 'id' => $id, 'value' => 'row-' . $id ), range( 1, 500 ) );
$uninstrumented_rows = array_map( static fn( array $row ): array => array_merge( $row, array( 'normalized' => true ) ), $source_rows );
$parity_profiler = new RussianPostImportBatchProfiler( 2, 12345, '2026-09-11 12:00:01' );
$instrumented_rows = array();
foreach ( $source_rows as $row ) {
	$instrumented_rows[] = $parity_profiler->measure( 'normalize_ms', static fn(): array => array_merge( $row, array( 'normalized' => true ) ) );
}
rp_profiler_assert( $uninstrumented_rows === $instrumented_rows, 'Profiling wrappers must be transparent to representative row results.' );

$benchmark_json = (string) json_encode( array( 'id' => 1, 'postcode' => '630001', 'city' => 'Новосибирск' ), JSON_UNESCAPED_UNICODE );
$benchmark_started = hrtime( true );
for ( $repeat = 0; $repeat < 100; ++$repeat ) {
	for ( $index = 0; $index < 500; ++$index ) {
		$item = json_decode( $benchmark_json, true );
		$value = trim( (string) ( $item['city'] ?? '' ) );
	}
}
$baseline_ms = ( hrtime( true ) - $benchmark_started ) / 1000000;
$benchmark_started = hrtime( true );
for ( $repeat = 0; $repeat < 100; ++$repeat ) {
	$benchmark_profiler = new RussianPostImportBatchProfiler( $repeat + 1, 0, '' );
	for ( $index = 0; $index < 500; ++$index ) {
		$item = $benchmark_profiler->measure( 'parse_ms', static fn(): mixed => json_decode( $benchmark_json, true ) );
		$value = $benchmark_profiler->measure( 'normalize_ms', static fn(): string => trim( (string) ( $item['city'] ?? '' ) ) );
	}
	$benchmark_profiler->finish( 1, 500 );
}
$profiled_ms = ( hrtime( true ) - $benchmark_started ) / 1000000;
$overhead_ms_per_batch = max( 0.0, ( $profiled_ms - $baseline_ms ) / 100 );
echo sprintf( "Profiler synthetic bookkeeping: baseline=%.3fms/batch profiled=%.3fms/batch delta=%.3fms/batch.\n", $baseline_ms / 100, $profiled_ms / 100, $overhead_ms_per_batch );

echo "Russian Post import profiler smoke test passed.\n";
