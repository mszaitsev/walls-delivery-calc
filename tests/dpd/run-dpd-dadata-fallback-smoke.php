<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-dpd-dadata-key' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
	}
}

function current_time( string $type ): string { return '2026-06-16 12:20:00'; }
function wp_date( string $format ): string { return gmdate( $format, strtotime( '2026-06-16 12:20:00' ) ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_dadata_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_dadata_options'][ $key ] = $value; return true; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_dpd_dadata_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_dpd_dadata_options'][ $key ] ?? false; }
function wp_salt( string $scheme = '' ): string { return 'wdc-test-salt-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array {
	$GLOBALS['wdc_dpd_dadata_http_requests'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['wdc_dpd_dadata_http_queue'] );
}

require_once __DIR__ . '/../../src/Domain/Status/DeliveryStatus.php';
require_once __DIR__ . '/../../src/Shipments/Cdek/CdekStatusMappingService.php';
require_once __DIR__ . '/../../src/Shipments/Dpd/DpdStatusMapping.php';
require_once __DIR__ . '/../../src/Carriers/Cdek/CdekSettings.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSettings.php';
require_once __DIR__ . '/../../src/Carriers/YandexDelivery/YandexDeliverySettings.php';
require_once __DIR__ . '/../../src/Infrastructure/Settings/SettingsRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Security/EncryptionService.php';
require_once __DIR__ . '/../../src/Infrastructure/Logging/LogRedactor.php';
require_once __DIR__ . '/../../src/Infrastructure/Logging/Logger.php';
require_once __DIR__ . '/../../src/Checkout/AddressSuggestions/DaDataTokenPool.php';
require_once __DIR__ . '/../../src/Checkout/AddressSuggestions/AddressSuggestionSettings.php';
require_once __DIR__ . '/../../src/Locations/ValueObjects/Location.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationRepository.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationDeliveryCodeRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdDaDataDeliveryClientInterface.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/WpDpdDaDataDeliveryClient.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdDaDataDeliveryFallbackService.php';

use WallsShop\WDC\Carriers\Dpd\Geography\DpdDaDataDeliveryFallbackService;
use WallsShop\WDC\Carriers\Dpd\Geography\WpDpdDaDataDeliveryClient;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function dpd_dadata_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_dpd_dadata_options'] = array();
$GLOBALS['wdc_dpd_dadata_http_requests'] = array();
$GLOBALS['wdc_dpd_dadata_http_queue'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body' => wp_json_encode( array( 'suggestions' => array( array( 'data' => array( 'dpd_id' => '49455627' ) ) ) ) ),
	),
	array(
		'response' => array( 'code' => 200 ),
		'body' => wp_json_encode( array( 'suggestions' => array() ) ),
	),
);
$GLOBALS['wpdb']->locations = array(
	array(
		'id' => 10,
		'fias_id' => '8DEA00E3-9AAB-4D8E-887C-EF2AAA546456',
		'gar_id' => '1',
		'gar_object_id' => 1,
		'kladr_id' => '5400000100000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'city_name' => 'Новосибирск',
		'city_type' => 'г',
		'settlement_name' => 'Новосибирск',
		'place_name' => 'Новосибирск',
		'place_type' => 'г',
		'display_name' => 'Новосибирск',
		'active' => 1,
	),
	array(
		'id' => 11,
		'fias_id' => '11111111-2222-3333-4444-555555555555',
		'gar_id' => '2',
		'gar_object_id' => 2,
		'kladr_id' => '5400000200000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'city_name' => 'Бердск',
		'city_type' => 'г',
		'settlement_name' => 'Бердск',
		'place_name' => 'Бердск',
		'place_type' => 'г',
		'display_name' => 'Бердск',
		'active' => 1,
	),
);

$settings = new SettingsRepository();
$encryption = new EncryptionService();
$pool = new DaDataTokenPool( $settings, $encryption );
$pool->save_tokens_from_admin(
	array(
		'id' => array( 'dpd-dadata-token' ),
		'label' => array( 'DaData' ),
		'token' => array( 'secret-api-key' ),
		'daily_limit' => array( 10000 ),
		'enabled' => array( 0 => '1' ),
	)
);
$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$service = new DpdDaDataDeliveryFallbackService(
	new LocationRepository( $GLOBALS['wpdb'] ),
	$delivery_codes,
	new WpDpdDaDataDeliveryClient( new AddressSuggestionSettings( $settings, $encryption, $pool ), $pool, new Logger() )
);

$result = $service->resolve_location_id( 10 );
dpd_dadata_assert( true === $result['success'], 'DaData fallback saves mapping when data.dpd_id is present' );
dpd_dadata_assert( '49455627' === $delivery_codes->get_dpd_city_id( 10 ), 'DaData fallback writes dpd_city_id' );
dpd_dadata_assert( 1 === $pool->usage_today( 'dpd-dadata-token' ), 'DaData delivery fallback increments shared token usage counter' );
dpd_dadata_assert( 1 === count( $GLOBALS['wdc_dpd_dadata_http_requests'] ), 'DaData delivery fallback performs one HTTP request' );
dpd_dadata_assert( str_contains( $GLOBALS['wdc_dpd_dadata_http_requests'][0]['url'], 'findById/delivery' ), 'DaData delivery fallback uses findById/delivery endpoint' );

$empty = $service->resolve_location_id( 11 );
dpd_dadata_assert( false === $empty['success'], 'DaData fallback returns failure when data.dpd_id is missing' );
dpd_dadata_assert( null === $delivery_codes->get_dpd_city_id( 11 ), 'empty DaData response does not save mapping' );
dpd_dadata_assert( 2 === $pool->usage_today( 'dpd-dadata-token' ), 'empty DaData response still counts the attempted request' );

echo "DPD DaData fallback smoke OK\n";
