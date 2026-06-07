<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Locations\Postcodes\RussianPostCourierCalcPostcodeFillStateService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_courier_postcode_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string|int {
		return 'timestamp' === $type ? 1780830000 : '2026-06-07 12:00:00';
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public array $rows = array();
		public array $russian_post_pickup_rows = array();

		public function prepare( string $query, mixed ...$args ): array { return array( 'query' => $query, 'args' => $args ); }
		public function get_var( mixed $query ): mixed { return 0; }
		public function get_col( mixed $query ): array { return array(); }
		public function get_row( mixed $query, mixed $output = null ): ?array { return null; }
		public function query( mixed $query ): int { return 0; }
	}
}

final class CourierPostcodeFakeProbe {
	public int $calls = 0;
	public array $postcodes = array();

	public function __construct(
		private mixed $advance_time,
		private float $duration_seconds = 0.0,
		private array $success_postcodes = array(),
		private array $api_error_postcodes = array()
	) {
	}

	public function probe( string $postcode ): array {
		++$this->calls;
		$this->postcodes[] = $postcode;
		if ( is_callable( $this->advance_time ) ) {
			call_user_func( $this->advance_time, $this->duration_seconds );
		}
		if ( in_array( $postcode, $this->success_postcodes, true ) ) {
			return array( 'success' => true );
		}
		if ( in_array( $postcode, $this->api_error_postcodes, true ) ) {
			return array( 'success' => false, 'api_error' => true, 'error_code' => 'api_error', 'error_message' => 'api down' );
		}

		return array( 'success' => false, 'api_error' => false, 'error_code' => '2007', 'error_message' => 'no courier delivery' );
	}
}

function rp_courier_postcode_service( wpdb $wpdb, CourierPostcodeFakeProbe $probe, array &$time, array &$sleeps ): RussianPostCourierCalcPostcodeFillStateService {
	return new RussianPostCourierCalcPostcodeFillStateService(
		new LocationRepository( $wpdb ),
		$probe,
		$wpdb,
		static function () use ( &$time ): float {
			return (float) $time['value'];
		},
		static function ( int $microseconds ) use ( &$time, &$sleeps ): void {
			$sleeps[] = $microseconds;
			$time['value'] += $microseconds / 1000000;
		}
	);
}

function rp_courier_postcode_advance( array &$time ): callable {
	return static function ( float $seconds ) use ( &$time ): void {
		$time['value'] += $seconds;
	};
}

function rp_courier_postcode_job( array $candidates ): array {
	return array(
		'phase' => 'running',
		'status' => 'running',
		'total' => 1,
		'processed' => 0,
		'updated' => 0,
		'bulk_updated' => 0,
		'skipped' => 0,
		'marked_no_index' => 0,
		'failed' => 0,
		'errors' => 0,
		'consecutive_errors' => 0,
		'probes' => 0,
		'step_probes' => 0,
		'last_id' => 0,
		'candidate_offset' => 0,
		'current_location' => array(
			'id' => 1,
			'active' => 1,
			'country_code' => 'RU',
			'postal_code' => '630000',
			'russianpost_courier_calc_postal_code' => '',
		),
		'current_candidates' => $candidates,
	);
}

rp_courier_postcode_assert( RussianPostCourierCalcPostcodeFillStateService::MAX_PROBES_PER_STEP > 4, 'MAX_PROBES_PER_STEP must be greater than the old value 4.' );
rp_courier_postcode_assert( 6 === RussianPostCourierCalcPostcodeFillStateService::TARGET_PROBES_PER_SECOND, 'Target RPS must be 6.' );

$wpdb = new wpdb();
$wpdb->rows[1] = array( 'id' => 1, 'active' => 1, 'country_code' => 'RU', 'postal_code' => '630000', 'russianpost_courier_calc_postal_code' => '' );
$time = array( 'value' => 1000.0 );
$sleeps = array();
$probe = new CourierPostcodeFakeProbe( rp_courier_postcode_advance( $time ), 0.0 );
$service = rp_courier_postcode_service( $wpdb, $probe, $time, $sleeps );
$created = $service->create_job();
rp_courier_postcode_assert( 6 === (int) ( $created['target_rps'] ?? 0 ) && 18 === (int) ( $created['max_probes_per_step'] ?? 0 ) && 3 === (int) ( $created['max_step_seconds'] ?? 0 ), 'Created job must expose rate-limit diagnostics.' );

$candidates = array_map( static fn( int $i ): string => sprintf( '63%04d', $i ), range( 1, 25 ) );
$job = $service->step( rp_courier_postcode_job( $candidates ) );
rp_courier_postcode_assert( 18 === (int) $job['step_probes'] && 18 === $probe->calls, 'Step must stop at MAX_PROBES_PER_STEP.' );
rp_courier_postcode_assert( 17 === count( $sleeps ), 'Rate limiter must not sleep before the first probe and must pace subsequent fast probes.' );
rp_courier_postcode_assert( min( $sleeps ) >= 160000 && max( $sleeps ) <= 170000, 'Fast probes must be paced at about 6 requests per second.' );
rp_courier_postcode_assert( (float) $job['actual_step_rps'] >= 5.0 && (float) $job['actual_step_rps'] <= 7.0, 'Actual step RPS must be around 5-7/sec.' );

$time = array( 'value' => 2000.0 );
$sleeps = array();
$slow_probe = new CourierPostcodeFakeProbe( rp_courier_postcode_advance( $time ), 1.1 );
$slow_service = rp_courier_postcode_service( new wpdb(), $slow_probe, $time, $sleeps );
$slow_job = $slow_service->step( rp_courier_postcode_job( $candidates ) );
rp_courier_postcode_assert( (int) $slow_job['step_probes'] < RussianPostCourierCalcPostcodeFillStateService::MAX_PROBES_PER_STEP && (int) $slow_job['step_duration_ms'] >= 3000, 'Step must stop at MAX_STEP_SECONDS before reaching MAX_PROBES_PER_STEP.' );
rp_courier_postcode_assert( array() === $sleeps, 'Slow probes must not receive additional pacing sleep.' );

$time = array( 'value' => 3000.0 );
$sleeps = array();
$success_probe = new CourierPostcodeFakeProbe( rp_courier_postcode_advance( $time ), 0.0, array( '630003' ) );
$success_wpdb = new wpdb();
$success_wpdb->rows[1] = array( 'id' => 1, 'active' => 1, 'country_code' => 'RU', 'postal_code' => '630000', 'russianpost_courier_calc_postal_code' => '' );
$success_service = rp_courier_postcode_service( $success_wpdb, $success_probe, $time, $sleeps );
$success_job = $success_service->step( rp_courier_postcode_job( array( '630001', '630002', '630003', '630004' ) ) );
rp_courier_postcode_assert( 3 === (int) $success_job['step_probes'] && 1 === (int) $success_job['updated'] && '630003' === (string) $success_wpdb->rows[1]['russianpost_courier_calc_postal_code'], 'Success logic must save the first successful candidate and finish the location.' );

$time = array( 'value' => 4000.0 );
$sleeps = array();
$unavailable_probe = new CourierPostcodeFakeProbe( rp_courier_postcode_advance( $time ), 0.0 );
$unavailable_service = rp_courier_postcode_service( new wpdb(), $unavailable_probe, $time, $sleeps );
$unavailable_job = $unavailable_service->step( rp_courier_postcode_job( array( '640001', '640002' ) ) );
rp_courier_postcode_assert( 1 === (int) $unavailable_job['marked_no_index'] && 0 === (int) $unavailable_job['failed'], 'Unavailable candidates must still be marked as no-index, not failed.' );

$time = array( 'value' => 5000.0 );
$sleeps = array();
$api_error_probe = new CourierPostcodeFakeProbe( rp_courier_postcode_advance( $time ), 0.0, array(), array( '650001' ) );
$api_error_service = rp_courier_postcode_service( new wpdb(), $api_error_probe, $time, $sleeps );
$api_error_job = $api_error_service->step( rp_courier_postcode_job( array( '650001', '650002' ) ) );
rp_courier_postcode_assert( 1 === (int) $api_error_job['failed'] && 1 === (int) $api_error_job['errors'] && 1 === (int) $api_error_job['consecutive_errors'], 'API errors must keep existing failed/error accounting.' );

echo "Russian Post courier postcode fill smoke passed\n";
