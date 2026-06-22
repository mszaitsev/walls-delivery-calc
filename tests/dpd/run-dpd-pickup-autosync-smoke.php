<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointAutoSync;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointImportService;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointNormalizer;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function dpd_pickup_autosync_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-22 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-pickup-autosync-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_pickup_autosync_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_pickup_autosync_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes' ): bool {
	if ( array_key_exists( $key, $GLOBALS['wdc_dpd_pickup_autosync_options'] ) ) {
		return false;
	}
	$GLOBALS['wdc_dpd_pickup_autosync_options'][ $key ] = $value;
	return true;
}
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_dpd_pickup_autosync_options'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function add_action( string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['wdc_dpd_pickup_autosync_actions'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' ); }
function wp_next_scheduled( string $hook, array $args = array() ): int|false {
	foreach ( $GLOBALS['wdc_dpd_pickup_autosync_events'] as $event ) {
		if ( $hook === $event['hook'] && $args === $event['args'] ) {
			return (int) $event['timestamp'];
		}
	}
	return false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	$GLOBALS['wdc_dpd_pickup_autosync_events'][] = compact( 'timestamp', 'recurrence', 'hook', 'args' );
	return true;
}
function wp_clear_scheduled_hook( string $hook ): int {
	$before = count( $GLOBALS['wdc_dpd_pickup_autosync_events'] );
	$GLOBALS['wdc_dpd_pickup_autosync_events'] = array_values( array_filter( $GLOBALS['wdc_dpd_pickup_autosync_events'], static fn( array $event ): bool => $hook !== $event['hook'] ) );
	$GLOBALS['wdc_dpd_pickup_autosync_clear_count'] = ( $GLOBALS['wdc_dpd_pickup_autosync_clear_count'] ?? 0 ) + 1;
	return $before - count( $GLOBALS['wdc_dpd_pickup_autosync_events'] );
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class DpdPickupAutoSyncFakeSoapClient implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	public string $mode = 'success';

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'options' );
		if ( 'empty' === $this->mode ) {
			$key = 'getParcelShops' === $method ? 'parcelShop' : 'terminal';
			return new DpdSoapResponse( true, (object) array( 'return' => (object) array( $key => array() ) ) );
		}

		if ( 'getParcelShops' === $method ) {
			return new DpdSoapResponse(
				true,
				(object) array(
					'return' => (object) array(
						'parcelShop' => (object) array(
							'code' => 'AUTO_PS1',
							'parcelShopType' => 'П',
							'address' => (object) array( 'cityId' => 44, 'countryCode' => 'RU', 'cityName' => 'Москва', 'street' => 'Тверская', 'houseNo' => '1' ),
							'geoCoordinates' => (object) array( 'latitude' => '55.755800', 'longitude' => '37.617300' ),
						),
					),
				)
			);
		}

		return new DpdSoapResponse(
			true,
			(object) array(
				'return' => (object) array(
					'terminal' => (object) array(
						'terminalCode' => 'AUTO_TERM1',
						'terminalName' => 'DPD терминал',
						'address' => (object) array( 'cityId' => 44, 'countryCode' => 'RU', 'cityName' => 'Москва', 'street' => 'Складская', 'houseNo' => '2' ),
						'geoCoordinates' => (object) array( 'latitude' => '55.700000', 'longitude' => '37.600000' ),
					),
				),
			)
		);
	}

	public function is_available(): bool { return true; }
}

function dpd_pickup_autosync_reset_runtime(): void {
	$GLOBALS['wdc_dpd_pickup_autosync_events'] = array();
	$GLOBALS['wdc_dpd_pickup_autosync_actions'] = array();
	$GLOBALS['wdc_dpd_pickup_autosync_clear_count'] = 0;
	unset( $GLOBALS['wdc_dpd_pickup_autosync_options']['wdc_dpd_pickup_import_lock'] );
}

function dpd_pickup_autosync_build(): array {
	$GLOBALS['wdc_dpd_pickup_autosync_options'] = array();
	$GLOBALS['wpdb'] = new wpdb();
	dpd_pickup_autosync_reset_runtime();

	$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin(
		array(
			DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST,
			DpdSettings::TEST_CLIENT_NUMBER_KEY => '1000000000',
			'dpd_test_client_key' => 'secret',
		)
	);
	$soap = new DpdPickupAutoSyncFakeSoapClient();
	$repository = new DpdPickupPointRepository( $GLOBALS['wpdb'] );
	$importer = new DpdPickupPointImportService( new DpdApiClient( $settings, $soap ), new DpdPickupPointNormalizer(), $repository, $settings );
	$scheduler = new DpdPickupPointAutoSync( $settings, $importer );

	return array( $settings, $soap, $repository, $importer, $scheduler );
}

$options = DpdPickupPointAutoSync::time_options();
dpd_pickup_autosync_assert( 97 === count( $options ), 'Autosync time select must contain 97 options including empty.' );
dpd_pickup_autosync_assert( 'Не выбрано' === $options[''] && isset( $options['00:00'], $options['00:15'], $options['23:45'] ), 'Autosync time select must include empty, 00:00, 00:15 and 23:45.' );

[ $settings, $soap, $repository, $importer, $scheduler ] = dpd_pickup_autosync_build();
dpd_pickup_autosync_assert( '09:00' === $settings->sanitize_pickup_autosync_time( '09:00' ), '09:00 must be accepted.' );
dpd_pickup_autosync_assert( '09:15' === $settings->sanitize_pickup_autosync_time( '09:15' ), '09:15 must be accepted.' );
dpd_pickup_autosync_assert( '' === $settings->sanitize_pickup_autosync_time( '09:10' ), '09:10 must be rejected.' );
dpd_pickup_autosync_assert( '' === $settings->sanitize_pickup_autosync_time( 'abc' ), 'abc must be rejected.' );
$msk_date = new DateTimeImmutable( '2026-06-22 00:00:00', new DateTimeZone( 'UTC' ) );
dpd_pickup_autosync_assert( '2026-06-22 06:00' === gmdate( 'Y-m-d H:i', $scheduler->msk_time_to_utc_timestamp( '09:00', $msk_date ) ), '09:00 MSK must become 06:00 UTC.' );
dpd_pickup_autosync_assert( '2026-06-21 21:15' === gmdate( 'Y-m-d H:i', $scheduler->msk_time_to_utc_timestamp( '00:15', $msk_date ) ), '00:15 MSK must become previous day 21:15 UTC.' );
dpd_pickup_autosync_assert( '2026-06-22 20:45' === gmdate( 'Y-m-d H:i', $scheduler->msk_time_to_utc_timestamp( '23:45', $msk_date ) ), '23:45 MSK must become 20:45 UTC.' );

$scheduler->reschedule();
$scheduler->run_cron( '09:00' );
dpd_pickup_autosync_assert( array() === $GLOBALS['wdc_dpd_pickup_autosync_events'] && 0 === count( $soap->calls ), 'Disabled autosync must not schedule or execute imports.' );

[ $settings, $soap, $repository, $importer, $scheduler ] = dpd_pickup_autosync_build();
$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1' ) );
$scheduler->reschedule();
dpd_pickup_autosync_assert( array() === $GLOBALS['wdc_dpd_pickup_autosync_events'], 'Enabled autosync with empty times must not schedule events.' );

$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1', DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY => '09:00' ) );
$scheduler->reschedule();
dpd_pickup_autosync_assert( 1 === count( $GLOBALS['wdc_dpd_pickup_autosync_events'] ) && array( '09:00' ) === $GLOBALS['wdc_dpd_pickup_autosync_events'][0]['args'], 'One selected time must schedule one daily event.' );

dpd_pickup_autosync_reset_runtime();
$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1', DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY => '09:00', DpdSettings::PICKUP_AUTOSYNC_TIME_2_KEY => '12:15', DpdSettings::PICKUP_AUTOSYNC_TIME_3_KEY => '23:45' ) );
$scheduler->reschedule();
dpd_pickup_autosync_assert( 3 === count( $GLOBALS['wdc_dpd_pickup_autosync_events'] ), 'Three selected times must schedule three daily events.' );

dpd_pickup_autosync_reset_runtime();
$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1', DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY => '09:00', DpdSettings::PICKUP_AUTOSYNC_TIME_2_KEY => '09:00', DpdSettings::PICKUP_AUTOSYNC_TIME_3_KEY => '09:00' ) );
$scheduler->reschedule();
dpd_pickup_autosync_assert( 1 === count( $GLOBALS['wdc_dpd_pickup_autosync_events'] ), 'Duplicate selected times must produce one effective cron event.' );

$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1', DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY => '18:30' ) );
$scheduler->reschedule();
dpd_pickup_autosync_assert( 1 === count( $GLOBALS['wdc_dpd_pickup_autosync_events'] ) && array( '18:30' ) === $GLOBALS['wdc_dpd_pickup_autosync_events'][0]['args'] && $GLOBALS['wdc_dpd_pickup_autosync_clear_count'] >= 2, 'Saving settings and rescheduling must clear old events and create new events.' );

[ $settings, $soap, $repository, $importer, $scheduler ] = dpd_pickup_autosync_build();
$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1', DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY => '09:00' ) );
$scheduler->run_cron( '09:00' );
dpd_pickup_autosync_assert( 2 === count( $soap->calls ), 'Cron callback must call DpdPickupPointImportService::import_all once, fetching both DPD pickup sources.' );
$last = $settings->last_pickup_import_report();
dpd_pickup_autosync_assert( 'auto_cron' === (string) ( $last['context'] ?? '' ) && 'success' === (string) ( $last['status'] ?? '' ) && 2 === $repository->count_all(), 'Successful cron import must update last import report and store points.' );

dpd_pickup_autosync_reset_runtime();
$GLOBALS['wdc_dpd_pickup_autosync_options']['wdc_dpd_pickup_import_lock'] = array( 'token' => 'busy', 'expires' => time() + 600 );
$before_calls = count( $soap->calls );
$scheduler->run_cron( '09:00' );
$lock_report = $settings->last_pickup_import_report();
dpd_pickup_autosync_assert( $before_calls === count( $soap->calls ) && 'skipped_lock_busy' === (string) ( $lock_report['status'] ?? '' ), 'Busy lock must skip cron import without fatal errors or API calls.' );

unset( $GLOBALS['wdc_dpd_pickup_autosync_options']['wdc_dpd_pickup_import_lock'] );
$GLOBALS['wpdb']->dpd_pickup_points = array( array( 'id' => 1, 'terminal_code' => 'KEEP_OLD', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 44, 'city_name' => 'Москва', 'address' => 'old', 'source' => 'getParcelShops', 'is_active' => 1 ) );
$soap->mode = 'empty';
$scheduler->run_cron( '09:00' );
$failed = $settings->last_pickup_import_report();
dpd_pickup_autosync_assert( 'error' === (string) ( $failed['status'] ?? '' ) && null !== $repository->find_by_terminal_code( 'KEEP_OLD' ), 'Failed cron import must record error and preserve existing pickup data.' );

[ $settings, $soap, $repository, $importer, $scheduler ] = dpd_pickup_autosync_build();
$manual_report = $importer->import_all();
$settings->save_pickup_autosync_settings_from_admin( array( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY => '1', DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY => '09:00' ) );
$scheduler->run_cron( '09:00' );
$cron_report = $settings->last_pickup_import_report();
dpd_pickup_autosync_assert( str_contains( $manual_report->source, 'getParcelShops' ) && str_contains( $manual_report->source, 'getTerminalsSelfDelivery2' ) && str_contains( (string) $cron_report['source'], 'getParcelShops' ) && str_contains( (string) $cron_report['source'], 'getTerminalsSelfDelivery2' ), 'Manual import and cron import must use the same DPD pickup import service path and sources.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$cron_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/Pickup/DpdPickupPointAutoSync.php' );
dpd_pickup_autosync_assert( str_contains( $admin_source, 'save_pickup_autosync_settings_from_admin' ) && str_contains( $admin_source, 'dpd_pickup_autosync_time_row' ), 'DPD pickup tab must expose autosync settings.' );
dpd_pickup_autosync_assert( str_contains( $cron_source, 'import_all( self::CONTEXT )' ) && ! str_contains( $cron_source, 'ShipmentStatusAutoSync' ), 'DPD pickup autosync must remain separate from DPD shipment status autosync.' );

echo "DPD pickup autosync smoke test passed.\n";
