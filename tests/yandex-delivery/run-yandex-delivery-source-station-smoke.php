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
	array( 'location_id' => 10, 'yandex_geo_id' => 66, 'status' => 'manual', 'is_primary' => 0, 'confidence' => 91.0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 66, 'status' => 'mapped', 'is_primary' => 0, 'confidence' => 90.0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 999, 'status' => 'needs_review', 'is_primary' => 0, 'confidence' => 50.0 ),
	array( 'location_id' => 20, 'yandex_geo_id' => 213, 'status' => 'mapped', 'is_primary' => 1, 'confidence' => 99.0 ),
);
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'NSK-1', 'name' => 'ПВЗ Березовая', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Березовая 1', 'yandex_geo_id' => 65, 'latitude' => 55.030199, 'longitude' => 82.92043, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-4', 'name' => 'ПВЗ Станционная', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Станционная 4', 'yandex_geo_id' => 65, 'latitude' => 55.020199, 'longitude' => 82.93043, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-5', 'name' => 'ПВЗ Фрунзе', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Фрунзе 5', 'yandex_geo_id' => 66, 'latitude' => 55.040199, 'longitude' => 82.94043, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-FAR', 'name' => 'ПВЗ Дальний', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Дальний 99', 'yandex_geo_id' => 65, 'latitude' => 55.300000, 'longitude' => 83.300000, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-2', 'name' => 'ПВЗ без сдачи', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Красный 2', 'yandex_geo_id' => 65, 'latitude' => 55.050199, 'longitude' => 82.95043, 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => 'NSK-3', 'name' => 'Неактивный ПВЗ', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Ленина 3', 'yandex_geo_id' => 65, 'latitude' => 55.060199, 'longitude' => 82.96043, 'available_for_dropoff' => 1, 'active' => 0 ),
	array( 'platform_station_id' => 'NSK-6', 'name' => 'Без координат', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Без координат', 'yandex_geo_id' => 65, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => 'MSK-1', 'name' => 'ПВЗ Тверская', 'locality' => 'Москва', 'full_address' => 'Москва, Тверская 10', 'yandex_geo_id' => 213, 'latitude' => 55.760199, 'longitude' => 37.62043, 'available_for_dropoff' => 1, 'active' => 1 ),
	array( 'platform_station_id' => '', 'name' => 'Без station id', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Без id', 'yandex_geo_id' => 65, 'latitude' => 55.070199, 'longitude' => 82.97043, 'available_for_dropoff' => 1, 'active' => 1 ),
);

$locations = new LocationRepository( $GLOBALS['wpdb'] );
$found_locations = $locations->search( 'Новосибирск', 20, 'RU' );
yandex_source_assert( 1 === count( $found_locations ) && 10 === (int) $found_locations[0]->id, 'Source city selector must search wp_wdc_locations and return a location_id.' );

$mapping = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
yandex_source_assert( 65 === $mapping->primary_geo_id_for_location( 10 ), 'Primary source geo id helper must remain available for compatibility.' );
yandex_source_assert( array( 65, 66 ) === $mapping->geo_ids_for_location( 10 ), 'Source station lookup must resolve all mapped/manual yandex_geo_id values through location mapping v2 by location_id.' );
yandex_source_assert( 0 === $mapping->primary_geo_id_for_location( 999 ), 'Missing source primary location mapping must return no yandex_geo_id.' );
yandex_source_assert( array() === $mapping->geo_ids_for_location( 999 ), 'Missing source location mapping must return no yandex_geo_id list.' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php' );
$source_dropoff_start = strpos( $repository_source, 'public function source_dropoff_points_by_geo_ids' );
$source_dropoff_end = strpos( $repository_source, '/** @param array<string,mixed> $filters */', false === $source_dropoff_start ? 0 : $source_dropoff_start );
$source_dropoff_method = false !== $source_dropoff_start && false !== $source_dropoff_end ? substr( $repository_source, $source_dropoff_start, $source_dropoff_end - $source_dropoff_start ) : '';
yandex_source_assert( '' !== $source_dropoff_method, 'Source dropoff repository method must exist.' );
yandex_source_assert( ! str_contains( $source_dropoff_method, '$limit' ) && ! str_contains( $source_dropoff_method, 'LIMIT' ) && ! str_contains( $source_dropoff_method, 'array_slice' ) && ! str_contains( $source_dropoff_method, 'min( 1000' ), 'Source dropoff repository method must not limit points inside selected yandex_geo_id values.' );

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$points = $repository->source_dropoff_points_by_geo_ids( array( 66, 65, 66, 0, -1 ) );
yandex_source_assert( 5 === count( $points ), 'Source station point list must return all matching active dropoff points for all selected yandex_geo_id values, including admin rows without map coordinates.' );
$point_ids = array_column( $points, 'platform_station_id' );
sort( $point_ids, SORT_STRING );
yandex_source_assert( array( 'NSK-1', 'NSK-4', 'NSK-5', 'NSK-6', 'NSK-FAR' ) === $point_ids, 'Source station point list must include all expected active dropoff ids without relying on Russian byte-sort order.' );
yandex_source_assert( 4 === count( $repository->source_dropoff_points_by_geo_id( 65 ) ), 'Single geo id wrapper must remain compatible.' );
$map_points = $repository->source_dropoff_map_points_by_geo_ids( array( 65, 66 ), 20 );
$map_ids = array_column( $map_points, 'platform_station_id' );
sort( $map_ids, SORT_STRING );
yandex_source_assert( array( 'NSK-1', 'NSK-4', 'NSK-5', 'NSK-FAR' ) === $map_ids, 'Modal initial source dropoff map must use source yandex_geo_ids and exclude points without coordinates.' );
$near_points = $repository->search_source_dropoff_points_near( 55.0302, 82.9204, 10.0, 20 );
$near_ids = array_column( $near_points, 'platform_station_id' );
yandex_source_assert( in_array( 'NSK-1', $near_ids, true ) && in_array( 'NSK-4', $near_ids, true ) && in_array( 'NSK-5', $near_ids, true ), 'Nearby source search must include close Novosibirsk dropoff points.' );
yandex_source_assert( ! in_array( 'NSK-FAR', $near_ids, true ) && ! in_array( 'MSK-1', $near_ids, true ), 'Nearby source search must exclude points outside the requested radius.' );
$distances = array_map( static fn( array $row ): float => (float) ( $row['distance_km'] ?? -1 ), $near_points );
$sorted_distances = $distances;
sort( $sorted_distances, SORT_NUMERIC );
yandex_source_assert( $distances === $sorted_distances && min( $distances ) >= 0, 'Nearby source search must add distance_km and sort by Haversine distance ascending.' );
yandex_source_assert( null !== $repository->source_dropoff_point_by_platform_station_id( 'NSK-1' ) && null === $repository->source_dropoff_point_by_platform_station_id( 'NSK-2' ) && null === $repository->source_dropoff_point_by_platform_station_id( 'NSK-3' ), 'Backend source override validation helper must accept only active available_for_dropoff points.' );
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
yandex_source_assert( str_contains( $admin_source, 'geo_ids_for_location' ) && str_contains( $admin_source, 'source_dropoff_points_by_geo_ids' ), 'Admin source station must resolve PVZ through location_id -> all linked yandex_geo_id values.' );
$source_helper_start = strpos( $admin_source, 'private function yandex_delivery_source_points_for_location' );
$source_helper_end = strpos( $admin_source, 'private function yandex_delivery_location_label', false === $source_helper_start ? 0 : $source_helper_start );
$source_helper = false !== $source_helper_start && false !== $source_helper_end ? substr( $admin_source, $source_helper_start, $source_helper_end - $source_helper_start ) : '';
yandex_source_assert( '' !== $source_helper && ! str_contains( $source_helper, 'primary_geo_id_for_location' ) && str_contains( $source_helper, 'geo_ids_for_location' ), 'Admin source helper must not use only primary_geo_id_for_location for PVZ listing.' );
yandex_source_assert( str_contains( $admin_source, 'Найдено ПВЗ сдачи: %1$d; yandex_geo_id: %2$s.' ) && str_contains( $admin_source, 'Для выбранного населенного пункта нет связанных yandex_geo_id в location mapping.' ) && str_contains( $admin_source, 'Для связанных yandex_geo_id нет активных ПВЗ, доступных для сдачи отправлений.' ), 'Admin source station messages must describe all linked geo ids.' );
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
yandex_source_assert( str_contains( $checkout_source, 'yandex_pickup' ) && str_contains( $checkout_source, 'yandex_courier' ) && str_contains( $checkout_source, 'source_platform_station_id' ), 'Yandex checkout pickup/courier pricing must use the saved source station setting.' );
yandex_source_assert( ! str_contains( $checkout_source, 'pickup map' ) && ! str_contains( $checkout_source, 'selected_yandex_pickup' ), 'Current Yandex checkout must not implement buyer PVZ map selection yet.' );

$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$yandex_modal_extension_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/YandexDelivery/YandexShipmentModalExtension.php' );
yandex_source_assert( str_contains( $yandex_modal_extension_source, 'data-wdc-open-yandex-source-dropoff-picker' ) && str_contains( $yandex_modal_extension_source, 'name="yandex_source_station_overridden"' ) && str_contains( $yandex_modal_extension_source, 'ПВЗ отправления Яндекс' ), 'Yandex modal extension must render a temporary source dropoff selector with the existing source platform station field.' );
yandex_source_assert( str_contains( $metabox_source, 'purpose' ) && str_contains( $metabox_source, "'source_dropoff'" ) && str_contains( $metabox_source, 'source_dropoff_map_points_by_geo_ids' ) && str_contains( $metabox_source, 'search_source_dropoff_points_near' ) && str_contains( $metabox_source, 'yandex_source_dropoff_ajax_row' ), 'Shipment modal pickup search endpoint must expose scoped initial and nearby Yandex source dropoff points through the shared map search action.' );
yandex_source_assert( str_contains( $metabox_source, 'geo_ids_for_location' ) && str_contains( $metabox_source, 'source_location_id' ) && str_contains( $metabox_source, 'Не удалось определить область поиска ПВЗ.' ) && ! str_contains( $metabox_source, "search_source_dropoff_points(\n\t\t\t\tarray(" ), 'Shipment modal Yandex source search must use source_location_id/geo ids or nearby coordinates instead of global first-page dropoff search.' );
yandex_source_assert( str_contains( $metabox_source, 'validate_yandex_source_station' ) && str_contains( $metabox_source, 'ПВЗ отправления Яндекс не найден.' ) && str_contains( $metabox_source, 'Выбранный ПВЗ Яндекс не принимает отправления.' ) && str_contains( $metabox_source, 'Выбранный ПВЗ Яндекс сейчас недоступен.' ), 'Preview/create validation must reject forged Yandex source overrides before shipment API calls.' );
yandex_source_assert( ! str_contains( $metabox_source, "update_option( 'yandex_source" ) && ! str_contains( $metabox_source, "update_post_meta( \$order_id, '_wdc_yandex_source" ), 'Temporary source dropoff selector must not persist override into settings or order meta.' );

$draft_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/OrderShipmentDraftFactory.php' );
yandex_source_assert( str_contains( $draft_source, "'yandex_source_platform_station_id' => \$source_station" ) && str_contains( $draft_source, "'yandex_source_station_overridden' => \$source_station_overridden ? '1' : '0'" ) && str_contains( $draft_source, "'yandex_pickup_platform_station_id' => \$pickup_code" ), 'DraftFactory must submit selected temporary source separately from unchanged destination pickup point.' );

$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
yandex_source_assert( str_contains( $js_source, 'function yandexSourceDropoffContext' ) && str_contains( $js_source, 'purpose: \'source_dropoff\'' ) && str_contains( $js_source, "data.append('purpose', context.purpose || '')" ) && str_contains( $js_source, "data.append('source_location_id'" ) && str_contains( $js_source, "data.append('source_platform_station_id'" ), 'Shipment admin JS must route Yandex source map searches through the shared picker with purpose=source_dropoff and source context.' );
yandex_source_assert( str_contains( $js_source, 'function pickupAddressSearchContext' ) && str_contains( $js_source, '!isYandexSourceDropoffContext(context)' ) && str_contains( $js_source, 'result.location_id = context.locationId || \'\'' ) && str_contains( $js_source, 'result.include_points = false' ) && str_contains( $js_source, 'window.WDCPickupApi.addressSearch(value, pickupAddressSearchContext(context), controller.signal)' ), 'Yandex source address search must use an unscoped all-Russia context without source location_id while other pickers keep scoped address search.' );
yandex_source_assert( str_contains( $js_source, 'loadYandexSourceNearby' ) && str_contains( $js_source, "[10, 25, 50]" ) && str_contains( $js_source, "'nearby'" ) && str_contains( $js_source, "radiusKm: radius" ) && ! str_contains( $js_source, 'source_dropoff_global' ), 'Yandex source address search must reload nearby points with 10/25/50 km radius expansion and no global fallback.' );
yandex_source_assert( str_contains( $js_source, 'provider.renderMarkers(points, { activePointId: previewPoint ? pointId(previewPoint) : null, searchMarker: searchMarker })' ) && str_contains( $js_source, 'provider.setCenter(center.lat, center.lng, 14)' ), 'Yandex source picker must preserve the search marker while replacing selectable dropoff markers and centering on scoped coordinates.' );
yandex_source_assert( str_contains( $js_source, 'function updateYandexSourceDropoffDraft' ) && str_contains( $js_source, '[data-wdc-yandex-source-station-id]' ) && str_contains( $js_source, '[data-wdc-yandex-source-station-overridden]' ) && str_contains( $js_source, 'requestPreview(form)' ), 'Yandex source dropoff selection must update hidden fields and refresh preview inside the current modal form.' );
yandex_source_assert( str_contains( $js_source, 'function resetYandexSourceDropoff' ) && str_contains( $js_source, 'dataset.defaultId' ) && ! str_contains( $js_source, 'localStorage' ) && ! str_contains( $js_source, 'sessionStorage' ), 'Yandex source reset must restore DOM defaults without browser storage persistence.' );

$pickup_api_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/wdc-pickup-api.js' );
yandex_source_assert( str_contains( $pickup_api_source, "params.set('purpose', context.purpose)" ) && str_contains( $pickup_api_source, "params.set('include_points', context.include_points ? '1' : '0')" ), 'Shared pickup address-search API must forward purpose/include_points only from the explicit context and must not invent a location_id fallback.' );

$rest_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/PickupPointsRestController.php' );
yandex_source_assert( str_contains( $rest_source, "\$purpose = sanitize_key( \$this->param( \$request, 'purpose' ) )" ) && str_contains( $rest_source, "YandexDeliverySettings::CARRIER_KEY === \$carrier && 'source_dropoff' === \$purpose" ) && str_contains( $rest_source, '? 0' ), 'REST address-search must ignore forged/source location_id for Yandex source_dropoff so DaData query stays unscoped.' );

$address_search_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Search/PickupAddressSearchService.php' );
yandex_source_assert( str_contains( $address_search_source, "\$this->scoped_query( \$query, \$location )" ) && str_contains( $address_search_source, "\$this->dadata_context( \$location, \$country_code )" ) && str_contains( $address_search_source, "\$this->cache_key( \$query, \$location_id, \$country_code, \$include_points )" ) && str_contains( $address_search_source, ". '|' . \$location_id . '|'" ), 'Address search must still scope only when location_id is non-zero, and cache keys must differ between scoped and unscoped searches.' );

echo "Yandex Delivery source station smoke OK\n";
