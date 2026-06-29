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
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_location_mapping_v2 = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_pickup_points_v2 = array();

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[dsf]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function esc_like( string $text ): string { return addcslashes( $text, '_%' ); }
	}
}

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 10, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'display_name' => 'Новосибирск', 'searchable_text' => 'новосибирск', 'active' => 1 ),
	array( 'id' => 20, 'country_code' => 'RU', 'region_name' => 'Москва', 'city_name' => 'Москва', 'display_name' => 'Москва', 'searchable_text' => 'москва', 'active' => 1 ),
);
$GLOBALS['wpdb']->yandex_location_mapping_v2 = array(
	array( 'location_id' => 10, 'yandex_geo_id' => 65, 'status' => 'mapped', 'is_primary' => 1, 'confidence' => 99.0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 999, 'status' => 'needs_review', 'is_primary' => 0, 'confidence' => 50.0 ),
	array( 'location_id' => 20, 'yandex_geo_id' => 213, 'status' => 'mapped', 'is_primary' => 1, 'confidence' => 99.0 ),
);
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'NSK-1', 'name' => 'ПВЗ Березовая', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Березовая 1', 'yandex_geo_id' => 65, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-4', 'name' => 'ПВЗ Станционная', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Станционная 4', 'yandex_geo_id' => 65, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-2', 'name' => 'ПВЗ без сдачи', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Красный 2', 'yandex_geo_id' => 65, 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-3', 'name' => 'Неактивный ПВЗ', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Ленина 3', 'yandex_geo_id' => 65, 'available_for_dropoff' => 1, 'active' => 0 ),
	array( 'platform_station_id' => 'MSK-1', 'name' => 'ПВЗ Тверская', 'locality' => 'Москва', 'full_address' => 'Москва, Тверская 10', 'yandex_geo_id' => 213, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => '', 'name' => 'Без station id', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Без id', 'yandex_geo_id' => 65, 'available_for_dropoff' => 1, 'active' => 1 ),
);

$locations = new LocationRepository( $GLOBALS['wpdb'] );
$found_locations = $locations->search( 'Новосибирск', 20, 'RU' );
yandex_source_assert( 1 === count( $found_locations ) && 10 === (int) $found_locations[0]->id, 'Source city selector must search wp_wdc_locations and return a location_id.' );

$mapping = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
yandex_source_assert( 65 === $mapping->primary_geo_id_for_location( 10 ), 'Source station lookup must resolve yandex_geo_id through location mapping v2 by location_id.' );
yandex_source_assert( 0 === $mapping->primary_geo_id_for_location( 999 ), 'Missing source location mapping must return no yandex_geo_id.' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php' );
$source_dropoff_start = strpos( $repository_source, 'public function source_dropoff_points_by_geo_id' );
$source_dropoff_end = strpos( $repository_source, '/** @param array<string,mixed> $filters */', false === $source_dropoff_start ? 0 : $source_dropoff_start );
$source_dropoff_method = false !== $source_dropoff_start && false !== $source_dropoff_end ? substr( $repository_source, $source_dropoff_start, $source_dropoff_end - $source_dropoff_start ) : '';
yandex_source_assert( '' !== $source_dropoff_method, 'Source dropoff repository method must exist.' );
yandex_source_assert( ! str_contains( $source_dropoff_method, '$limit' ) && ! str_contains( $source_dropoff_method, 'LIMIT' ) && ! str_contains( $source_dropoff_method, 'array_slice' ) && ! str_contains( $source_dropoff_method, 'min( 1000' ), 'Source dropoff repository method must not limit points inside one yandex_geo_id.' );

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$points = $repository->source_dropoff_points_by_geo_id( 65 );
yandex_source_assert( 2 === count( $points ), 'Source station point list must return all matching active dropoff points for the selected yandex_geo_id.' );
yandex_source_assert( array( 'NSK-1', 'NSK-4' ) === array_column( $points, 'platform_station_id' ), 'Source station points must keep stable name/platform_station_id sorting.' );
yandex_source_assert( ! in_array( 'MSK-1', array_column( $points, 'platform_station_id' ), true ), 'PVZ with another yandex_geo_id must not be shown.' );
yandex_source_assert( ! in_array( 'NSK-2', array_column( $points, 'platform_station_id' ), true ), 'available_for_dropoff=0 PVZ must not be shown.' );
yandex_source_assert( ! in_array( 'NSK-3', array_column( $points, 'platform_station_id' ), true ), 'inactive PVZ must not be shown.' );
yandex_source_assert( 'Новосибирск, Березовая 1' === (string) ( $repository->find( 'NSK-1' )['full_address'] ?? '' ), 'Saved platform_station_id must restore the full address from the local PVZ database.' );
$inactive = $repository->find( 'NSK-3' );
yandex_source_assert( is_array( $inactive ) && empty( $inactive['active'] ) && ! empty( $inactive['available_for_dropoff'] ), 'Saved inactive PVZ must be detectable as problematic.' );
$not_dropoff = $repository->find( 'NSK-2' );
yandex_source_assert( is_array( $not_dropoff ) && ! empty( $not_dropoff['active'] ) && empty( $not_dropoff['available_for_dropoff'] ), 'Saved available_for_dropoff=0 PVZ must be detectable as problematic.' );

$GLOBALS['wdc_options'] = array();
$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
yandex_source_assert( '' === $settings->source_platform_station_id(), 'Yandex source platform station must be empty by default so checkout keeps current behavior.' );
yandex_source_assert( 0 === $settings->source_location_id(), 'Yandex source location id must be empty by default.' );
$GLOBALS['wdc_options']['wdc_core_settings'] = array( YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY => 'NSK-1<script>', YandexDeliverySettings::SOURCE_LOCATION_ID_KEY => 10 );
yandex_source_assert( 'NSK-1script' === $settings->source_platform_station_id(), 'Yandex source platform station getter must sanitize saved station id.' );
yandex_source_assert( 10 === $settings->source_location_id(), 'Yandex source location id getter must restore saved location_id.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
yandex_source_assert( str_contains( $admin_source, 'save_yandex_delivery_calculation_settings' ) && str_contains( $admin_source, 'sanitize_yandex_delivery_calculation_settings_from_post' ), 'Admin calculation tab must save Yandex source station settings.' );
yandex_source_assert( str_contains( $admin_source, 'YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY' ) && str_contains( $admin_source, 'YandexDeliverySettings::SOURCE_LOCATION_ID_KEY' ), 'Admin source station must use shared Yandex setting keys.' );
yandex_source_assert( str_contains( $admin_source, 'primary_geo_id_for_location' ) && str_contains( $admin_source, 'source_dropoff_points_by_geo_id' ), 'Admin source station must resolve PVZ through location_id -> yandex_geo_id.' );
yandex_source_assert( ! str_contains( $admin_source, 'source_dropoff_points()' ) && ! str_contains( $admin_source, 'source_dropoff_cities()' ), 'Admin source station must not load a global first-5000 PVZ list.' );
yandex_source_assert( str_contains( $admin_source, 'Точка сдачи отправлений Яндекс.Доставки' ) && str_contains( $admin_source, 'Город / населенный пункт сдачи' ) && str_contains( $admin_source, 'ПВЗ сдачи' ), 'Admin calculation tab must render Russian source station controls.' );
yandex_source_assert( str_contains( $admin_source, 'Фильтр ПВЗ по адресу' ) && str_contains( $admin_source, 'Введите минимум 3 символа адреса' ) && str_contains( $admin_source, 'По фильтру ПВЗ не найдены.' ), 'Admin source station UI must render the address filter and empty-filter message.' );
yandex_source_assert( str_contains( $admin_source, 'data-address' ) && str_contains( $admin_source, 'query.length >= 3' ) && str_contains( $admin_source, 'toLocaleLowerCase' ) && str_contains( $admin_source, 'addressText.indexOf(query)' ), 'Admin source station filter must use client-side case-insensitive data-address search from 3 characters.' );
yandex_source_assert( str_contains( $admin_source, 'wp_ajax_wdc_yandex_delivery_source_station' ) && str_contains( $admin_source, 'check_ajax_referer' ) && str_contains( $admin_source, 'current_user_can' ), 'Admin AJAX source station endpoint must have nonce and capability checks.' );
yandex_source_assert( str_contains( $admin_source, 'Сохраненный platform_station_id' ) && str_contains( $admin_source, 'Адрес выбранного ПВЗ' ) && str_contains( $admin_source, 'readonly' ), 'Admin source station UI must display readonly saved station id and address.' );
yandex_source_assert( str_contains( $admin_source, 'не найден в локальной базе ПВЗ после последнего импорта' ) && str_contains( $admin_source, 'сейчас не активен или недоступен для сдачи отправлений' ), 'Admin source station UI must warn for missing, inactive, or non-dropoff saved PVZ.' );
yandex_source_assert( ! str_contains( $admin_source, "name=\"yandex_delivery_source_station_address\"" ), 'Admin source station must not save the address field.' );

$settings_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/YandexDeliverySettings.php' );
yandex_source_assert( str_contains( $settings_source, 'public const SOURCE_PLATFORM_STATION_ID_KEY' ) && str_contains( $settings_source, 'source_platform_station_id()' ), 'Yandex settings must expose source platform station id for future checkout stages.' );
yandex_source_assert( str_contains( $settings_source, 'public const SOURCE_LOCATION_ID_KEY' ) && str_contains( $settings_source, 'source_location_id()' ), 'Yandex settings must expose optional source location id for UI restoration.' );

$checkout_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/YandexDeliveryCarrier.php' );
yandex_source_assert( str_contains( $checkout_source, 'yandex_pickup' ) && str_contains( $checkout_source, 'yandex_courier' ) && str_contains( $checkout_source, 'без указания срока' ), 'Existing Yandex checkout pickup/courier rates must remain available with the temporary delivery time.' );
yandex_source_assert( ! str_contains( $checkout_source, 'SOURCE_PLATFORM_STATION_ID_KEY' ) && ! str_contains( $checkout_source, 'source_platform_station_id' ), 'Current checkout must not depend on the source station setting yet.' );

echo "Yandex Delivery source station smoke OK\n";
