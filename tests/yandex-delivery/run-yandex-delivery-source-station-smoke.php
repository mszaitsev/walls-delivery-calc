<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function yandex_source_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-29 12:00:00'; }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = false ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_pickup_points_v2 = array();

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( array( '%d', '%s' ), array( '%d', "'%s'" ), $query ), $args ); }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%' ); }
	}
}

use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'NSK-1', 'name' => 'ПВЗ Березовая', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Березовая 1', 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'MSK-1', 'name' => 'ПВЗ Тверская', 'locality' => 'Москва', 'full_address' => 'Москва, Тверская 10', 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-2', 'name' => 'ПВЗ без сдачи', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Красный 2', 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => 'KZN-1', 'name' => 'Неактивный ПВЗ', 'locality' => 'Казань', 'full_address' => 'Казань, Баумана 1', 'available_for_dropoff' => 1, 'active' => 0 ),
);

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$cities = $repository->source_dropoff_cities();
yandex_source_assert( array( 'Москва', 'Новосибирск' ) === $cities, 'Source station city list must include only active dropoff cities.' );
$points = $repository->source_dropoff_points();
yandex_source_assert( 2 === count( $points ), 'Source station point list must include only active dropoff points.' );
yandex_source_assert( 'MSK-1' === (string) ( $points[0]['platform_station_id'] ?? '' ) && 'NSK-1' === (string) ( $points[1]['platform_station_id'] ?? '' ), 'Source station points must be sorted by locality/name.' );
yandex_source_assert( 'Новосибирск, Березовая 1' === (string) ( $repository->find( 'NSK-1' )['full_address'] ?? '' ), 'Selected platform_station_id must restore the full address from the local PVZ database.' );

$GLOBALS['wdc_options'] = array();
$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
yandex_source_assert( '' === $settings->source_platform_station_id(), 'Yandex source platform station must be empty by default so checkout keeps current behavior.' );
$GLOBALS['wdc_options']['wdc_core_settings'] = array( YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY => 'NSK-1<script>' );
yandex_source_assert( 'NSK-1script' === $settings->source_platform_station_id(), 'Yandex source platform station getter must sanitize saved station id.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
yandex_source_assert( str_contains( $admin_source, 'save_yandex_delivery_calculation_settings' ) && str_contains( $admin_source, 'sanitize_yandex_delivery_calculation_settings_from_post' ), 'Admin calculation tab must save Yandex source station settings.' );
yandex_source_assert( str_contains( $admin_source, 'YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY' ), 'Admin source station must use the shared Yandex setting key.' );
yandex_source_assert( str_contains( $admin_source, 'Точка сдачи отправлений Яндекс.Доставки' ) && str_contains( $admin_source, 'Город сдачи' ) && str_contains( $admin_source, 'ПВЗ сдачи' ), 'Admin calculation tab must render Russian source station controls.' );
yandex_source_assert( str_contains( $admin_source, 'data-city' ) && str_contains( $admin_source, 'syncYandexSourceStations' ), 'Admin source station UI must filter PVZ options by selected city.' );
yandex_source_assert( str_contains( $admin_source, 'Сохраненный platform_station_id' ) && str_contains( $admin_source, 'Адрес выбранного ПВЗ' ) && str_contains( $admin_source, 'readonly' ), 'Admin source station UI must display readonly saved station id and address.' );
yandex_source_assert( str_contains( $admin_source, 'не найден в локальной базе ПВЗ после последнего импорта' ), 'Admin source station UI must warn when saved station id is missing after import.' );
yandex_source_assert( ! str_contains( $admin_source, "name=\"yandex_delivery_source_station_address\"" ), 'Admin source station must not save the address field.' );

$settings_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/YandexDeliverySettings.php' );
yandex_source_assert( str_contains( $settings_source, 'public const SOURCE_PLATFORM_STATION_ID_KEY' ) && str_contains( $settings_source, 'source_platform_station_id()' ), 'Yandex settings must expose source platform station id for future checkout stages.' );

$checkout_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/YandexDeliveryCarrier.php' );
yandex_source_assert( str_contains( $checkout_source, 'yandex_pickup' ) && str_contains( $checkout_source, 'yandex_courier' ) && str_contains( $checkout_source, 'без указания срока' ), 'Existing Yandex checkout pickup/courier rates must remain available with the temporary delivery time.' );
yandex_source_assert( ! str_contains( $checkout_source, 'SOURCE_PLATFORM_STATION_ID_KEY' ) && ! str_contains( $checkout_source, 'source_platform_station_id' ), 'Current checkout must not depend on the source station setting yet.' );

echo "Yandex Delivery source station smoke OK\n";