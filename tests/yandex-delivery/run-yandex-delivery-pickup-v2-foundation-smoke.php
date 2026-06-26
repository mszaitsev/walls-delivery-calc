<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2ImportService;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2ScheduleFormatter;
use WallsShop\WDC\Pickup\RussianPost\RussianPostWorkTimeFormatter;

function yd_pickup_v2_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-26 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { $GLOBALS['wdc_yandex_delivery_pickup_v2_schema'] = $queries; return array(); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_pickup_points_v2 = array();

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

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_yandex_delivery_pickup_v2_schema'] = '';

$migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0035_create_yandex_delivery_pickups_v2.php' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php' );
$import_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2ImportService.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );

yd_pickup_v2_assert( str_contains( $migration_source, 'YandexDeliveryPickupPointV2Repository' ), 'Migration must use the v2 repository.' );
yd_pickup_v2_assert( str_contains( $repository_source, 'wdc_yandex_delivery_pickup_points_v2' ), 'Repository must use a separate v2 table.' );
yd_pickup_v2_assert( ! str_contains( $repository_source, 'payment_methods' ), 'V2 repository must not store payment_methods.' );
yd_pickup_v2_assert( ! str_contains( $repository_source, 'pickup_services' ), 'V2 repository must not store pickup_services.' );
yd_pickup_v2_assert( ! str_contains( $repository_source, 'available_for_c2c_dropoff' ), 'V2 repository must not store available_for_c2c_dropoff.' );
foreach ( array( 'schema()', 'upsert(', 'find(', 'search(', 'count(' ) as $method ) {
	yd_pickup_v2_assert( str_contains( $repository_source, $method ), 'V2 repository must expose ' . $method . '.' );
}
foreach ( array( 'normalizePickupPoint', 'import_from_json_file', 'array_chunk( $rows, self::BATCH_SIZE )', 'repository->upsert' ) as $fragment ) {
	yd_pickup_v2_assert( str_contains( $import_source, $fragment ), 'V2 import service must contain ' . $fragment . '.' );
}
yd_pickup_v2_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_pickup_v2'] = 'Яндекс ПВЗ v2';" ), 'Admin page must register the Yandex pickup v2 tab.' );
yd_pickup_v2_assert( str_contains( $admin_source, 'Скачать и импортировать полный список' ) && ! str_contains( $admin_source, 'Текущий импорт пока недоступен' ), 'V2 tab must render runner UI instead of the old placeholder.' );

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$schema = $repository->schema();
foreach ( array( 'platform_station_id', 'operator_station_id', 'operator_id', 'type', 'name', 'yandex_geo_id', 'country', 'region', 'sub_region', 'locality', 'street', 'house', 'housing', 'building', 'apartment', 'postal_code', 'full_address', 'latitude', 'longitude', 'instruction', 'phone', 'schedule_text', 'is_yandex_branded', 'is_market_partner', 'is_dark_store', 'is_post_office', 'available_for_dropoff', 'deactivation_date', 'deactivation_date_predicted_debt', 'location_details_json', 'station_contact_json', 'active', 'last_seen_at', 'raw_hash', 'created_at', 'updated_at' ) as $column ) {
	yd_pickup_v2_assert( str_contains( $schema, $column ), 'V2 schema must include column ' . $column . '.' );
}
foreach ( array( 'payment_methods', 'pickup_services', 'available_for_c2c_dropoff' ) as $excluded ) {
	yd_pickup_v2_assert( ! str_contains( $schema, $excluded ), 'V2 schema must exclude ' . $excluded . '.' );
}

$schedule_formatter = new YandexDeliveryPickupPointV2ScheduleFormatter();
yd_pickup_v2_assert( "Пн–Пт: 09:00–21:00\nСб–Вс: 10:00–20:00" === $schedule_formatter->format( array( array( 'days' => 'пн-пт', 'time' => '09:00-21:00' ), array( 'days' => 'сб-вс', 'time' => '10:00-20:00' ) ) ), 'Yandex v2 schedule must use compact weekly text.' );
yd_pickup_v2_assert( 'Пн–Вс: 09:00–21:00' === $schedule_formatter->format( array( array( 'days' => 'ежедневно', 'time' => '09:00-21:00' ) ) ), 'Yandex v2 equal daily schedule must match Russian Post compact style.' );
yd_pickup_v2_assert( 'Пн–Вс: 09:00–21:00' === ( new RussianPostWorkTimeFormatter() )->format( array( 'пн, открыто: 09:00 - 21:00', 'вт, открыто: 09:00 - 21:00', 'ср, открыто: 09:00 - 21:00', 'чт, открыто: 09:00 - 21:00', 'пт, открыто: 09:00 - 21:00', 'сб, открыто: 09:00 - 21:00', 'вс, открыто: 09:00 - 21:00' ) ), 'Russian Post formatter must keep the same compact result after helper extraction.' );

$service = new YandexDeliveryPickupPointV2ImportService( $repository, $schedule_formatter );
$raw = array(
	'id' => '63e07227-30f8-4afc-bc3f-22b76fa1672c',
	'operator_station_id' => '10017733479',
	'operator_id' => 'market_l4g',
	'name' => 'Пункт выдачи заказов Яндекс Маркета',
	'type' => 'pickup_point',
	'position' => array( 'latitude' => 55.606040954589837, 'longitude' => 37.473125457763672 ),
	'address' => array(
		'geoId' => 215263,
		'country' => 'Россия',
		'region' => 'Москва',
		'subRegion' => '',
		'locality' => 'район Коммунарка',
		'street' => 'Новомихайловское шоссе',
		'house' => '1 к2',
		'housing' => '',
		'apartment' => '',
		'building' => '',
		'comment' => 'На общественном транспорте - вход со двора',
		'full_address' => 'район Коммунарка Новомихайловское шоссе 1 к2',
		'postal_code' => '108820',
	),
	'schedule' => array( array( 'days' => 'пн-пт', 'time' => '09:00-21:00' ), array( 'days' => 'сб-вс', 'time' => '10:00-20:00' ) ),
	'location_details' => array( 'floor' => 1, 'entrance' => 'left', 'nested' => array( 'keep' => true ) ),
	'station_contact' => array( 'phones' => array( '+79990000000' ), 'email' => 'pvz@example.test' ),
	'payment_methods' => array( 'already_paid' ),
	'pickup_services' => array( 'fitting_room' ),
	'available_for_c2c_dropoff' => true,
	'is_yandex_branded' => true,
	'is_market_partner' => true,
	'is_dark_store' => false,
	'is_post_office' => false,
	'available_for_dropoff' => true,
);
$normalized = $service->normalizePickupPoint( $raw );
yd_pickup_v2_assert( null !== $normalized, 'normalizePickupPoint must return a row for a valid point.' );
yd_pickup_v2_assert( '63e07227-30f8-4afc-bc3f-22b76fa1672c' === $normalized['platform_station_id'], 'Normalizer must keep platform_station_id.' );
yd_pickup_v2_assert( '10017733479' === $normalized['operator_station_id'] && 'market_l4g' === $normalized['operator_id'], 'Normalizer must keep operator ids.' );
yd_pickup_v2_assert( '215263' === (string) $normalized['yandex_geo_id'] && 'район Коммунарка' === $normalized['locality'], 'Normalizer must keep geoId and locality.' );
yd_pickup_v2_assert( "Пн–Пт: 09:00–21:00\nСб–Вс: 10:00–20:00" === $normalized['schedule_text'], 'Normalizer must store compact schedule_text.' );
yd_pickup_v2_assert( str_contains( (string) $normalized['location_details_json'], '"nested":{"keep":true}' ), 'Normalizer must preserve full LocationDetails JSON.' );
yd_pickup_v2_assert( str_contains( (string) $normalized['station_contact_json'], 'pvz@example.test' ), 'Normalizer must preserve full StationContact JSON.' );
yd_pickup_v2_assert( ! array_key_exists( 'payment_methods', $normalized ) && ! array_key_exists( 'pickup_services', $normalized ) && ! array_key_exists( 'available_for_c2c_dropoff', $normalized ), 'Normalizer must exclude unused fields.' );
yd_pickup_v2_assert( 40 === strlen( (string) $normalized['raw_hash'] ), 'Normalizer must compute SHA1 raw_hash.' );

$real_restrictions = array();
foreach ( range( 1, 7 ) as $day ) {
	$real_restrictions[] = array(
		'days' => array( $day ),
		'time_from' => array( 'hours' => 9, 'minutes' => 0 ),
		'time_to' => array( 'hours' => 21, 'minutes' => 0 ),
	);
}
$real_raw = array(
	'id' => '0193ce8e4735706382d7869c4a1d9e4d',
	'operator_station_id' => '10031767577',
	'operator_id' => 'market_l4g',
	'name' => 'Пункт выдачи заказов Яндекс Маркета',
	'type' => 'pickup_point',
	'position' => array(
		'latitude' => 55.046301,
		'longitude' => 82.936428,
	),
	'address' => array(
		'geoId' => 102058,
		'country' => 'Россия',
		'region' => 'Новосибирская область',
		'subRegion' => '',
		'locality' => 'Новосибирск',
		'street' => 'улица Некрасова',
		'house' => '82',
		'housing' => '',
		'apartment' => '',
		'building' => '',
		'comment' => 'Пройти один квартал от станции метро Маршала Покрышкина.',
		'full_address' => 'Новосибирск улица Некрасова 82',
		'postal_code' => '630005',
	),
	'contact' => array(
		'phone' => '+74951570020',
	),
	'schedule' => array(
		'time_zone' => 7,
		'restrictions' => $real_restrictions,
	),
	'is_yandex_branded' => true,
	'is_market_partner' => true,
	'is_dark_store' => false,
	'is_post_office' => false,
	'available_for_dropoff' => true,
	'available_for_c2c_dropoff' => true,
	'pickup_services' => array(
		'is_fitting_allowed' => true,
	),
	'payment_methods' => array(
		'already_paid',
	),
);
$real_normalized = $service->normalizePickupPoint( $real_raw );
yd_pickup_v2_assert( null !== $real_normalized, 'Real Yandex fixture must normalize.' );
yd_pickup_v2_assert( '' !== (string) $real_normalized['schedule_text'], 'Real Yandex fixture schedule_text must not be empty.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['schedule_text'], 'Пн–Вс' ), 'Real Yandex fixture schedule_text must contain compact weekday range.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['schedule_text'], '09:00–21:00' ), 'Real Yandex fixture schedule_text must contain formatted time range.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['location_details_json'], '"geoId":102058' ), 'Real Yandex fixture LocationDetails JSON must contain geoId.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['location_details_json'], 'Новосибирская область' ), 'Real Yandex fixture LocationDetails JSON must contain region.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['location_details_json'], 'Новосибирск' ), 'Real Yandex fixture LocationDetails JSON must contain locality.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['location_details_json'], 'Новосибирск улица Некрасова 82' ), 'Real Yandex fixture LocationDetails JSON must contain full_address.' );
yd_pickup_v2_assert( str_contains( (string) $real_normalized['station_contact_json'], '+74951570020' ), 'Real Yandex fixture StationContact JSON must contain phone.' );
yd_pickup_v2_assert( '+74951570020' === (string) $real_normalized['phone'], 'Real Yandex fixture phone column must use contact.phone.' );
yd_pickup_v2_assert( ! array_key_exists( 'payment_methods', $real_normalized ), 'Real Yandex fixture must not normalize payment_methods.' );
yd_pickup_v2_assert( ! array_key_exists( 'pickup_services', $real_normalized ), 'Real Yandex fixture must not normalize pickup_services.' );
yd_pickup_v2_assert( ! array_key_exists( 'available_for_c2c_dropoff', $real_normalized ), 'Real Yandex fixture must not normalize available_for_c2c_dropoff.' );
$save = $repository->upsert( array( $normalized ) );
yd_pickup_v2_assert( 1 === $save['saved'] && 1 === $repository->count(), 'Repository must upsert a normalized v2 point.' );
yd_pickup_v2_assert( null !== $repository->find( '63e07227-30f8-4afc-bc3f-22b76fa1672c' ), 'Repository find must return the v2 point.' );
yd_pickup_v2_assert( 1 === count( $repository->search( array( 'locality' => 'Коммунарка' ) ) ), 'Repository search must filter v2 points.' );

$temp_file = tempnam( sys_get_temp_dir(), 'yd-pickup-v2-' );
yd_pickup_v2_assert( false !== $temp_file, 'Smoke temp file must be created.' );
file_put_contents( $temp_file, wp_json_encode( array( 'points' => array( $raw, array_merge( $raw, array( 'id' => 'second-point', 'name' => 'Второй ПВЗ' ) ) ) ), JSON_UNESCAPED_UNICODE ) );
$import_report = $service->import_from_json_file( $temp_file );
@unlink( $temp_file );
yd_pickup_v2_assert( 2 === $import_report['received'] && 2 === $import_report['normalized'] && 2 === $import_report['saved'] && 1 === $import_report['batches'], 'JSON file import must read all rows and import one batch.' );
yd_pickup_v2_assert( 2 === $repository->count(), 'JSON file import must persist imported v2 rows.' );

echo "Yandex Delivery pickup v2 foundation smoke OK\n";
