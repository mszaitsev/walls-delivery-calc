<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Calendar/Services/TimezoneService.php';

use WallsShop\WDC\Calendar\Services\TimezoneService;

function scheduled_task_timezone_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$timezone = new TimezoneService();
scheduled_task_timezone_assert( 'Asia/Novosibirsk' === $timezone->timezone()->getName(), 'WDC timezone owner must use Asia/Novosibirsk.' );

$utc_now = new DateTimeImmutable( '2026-09-10 00:00:00', new DateTimeZone( 'UTC' ) );
$amsterdam_now = new DateTimeImmutable( '2026-09-10 02:00:00', new DateTimeZone( 'Europe/Amsterdam' ) );
$utc_result = $timezone->next_local_time_timestamp( '09:00', $utc_now );
$amsterdam_result = $timezone->next_local_time_timestamp( '09:00', $amsterdam_now );
scheduled_task_timezone_assert( $utc_result === $amsterdam_result, 'WDC scheduling must not depend on the site timezone.' );
scheduled_task_timezone_assert( '2026-09-10 02:00' === gmdate( 'Y-m-d H:i', $utc_result ), '09:00 Novosibirsk must correspond to 02:00 UTC.' );
scheduled_task_timezone_assert( '10.09.2026 09:00' === $timezone->format_timestamp( $utc_result ), 'Admin formatting must convert UTC timestamps to Novosibirsk.' );

$before = new DateTimeImmutable( '2026-09-10 00:10:00', $timezone->timezone() );
$after = new DateTimeImmutable( '2026-09-10 00:40:00', $timezone->timezone() );
scheduled_task_timezone_assert( '2026-09-09 17:30' === gmdate( 'Y-m-d H:i', $timezone->next_local_time_timestamp( '00:30', $before ) ), 'Before 00:30 must schedule the current Novosibirsk date.' );
scheduled_task_timezone_assert( '2026-09-10 17:30' === gmdate( 'Y-m-d H:i', $timezone->next_local_time_timestamp( '00:30', $after ) ), 'After 00:30 must schedule the next Novosibirsk date.' );
scheduled_task_timezone_assert( '2026-09-10 16:30' === gmdate( 'Y-m-d H:i', $timezone->next_local_time_timestamp( '23:30', $utc_now ) ), '23:30 Novosibirsk must correspond to 16:30 UTC.' );
scheduled_task_timezone_assert( 0 === $timezone->next_local_time_timestamp( '24:00', $utc_now ), 'Invalid HH:MM values must fail closed.' );

$root = dirname( __DIR__, 2 );
$catalog = (string) file_get_contents( $root . '/src/Admin/ScheduledTaskCatalog.php' );
$overview = (string) file_get_contents( $root . '/src/Admin/AdminMenu.php' );
$dpd = (string) file_get_contents( $root . '/src/Carriers/Dpd/Pickup/DpdPickupPointAutoSync.php' );
$yandex = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexDeliveryGeoPipelineV2Runner.php' );
$ozon = (string) file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php' );
foreach ( array( 'calendar', 'gar', 'fias', 'shipment_statuses', 'dpd_pickup', 'yandex_geo', 'russian_post_pickup', 'ozon_pickup' ) as $key ) {
	scheduled_task_timezone_assert( str_contains( $catalog, "'" . $key . "'" ), 'Catalog must include known task key: ' . $key );
}
scheduled_task_timezone_assert( str_contains( $catalog, "'Отключена'" ) && str_contains( $catalog, "'Не запланировано'" ) && str_contains( $catalog, "'Запланировано'" ), 'Catalog must distinguish disabled, missing, and scheduled states.' );
scheduled_task_timezone_assert( str_contains( $catalog, 'pickup_autosync_times()' ) && ! str_contains( $catalog, 'pickup_autosync_time_options()' ), 'Overview must use effective DPD slots only.' );
scheduled_task_timezone_assert( str_contains( $overview, 'Запланированные задачи' ) && str_contains( $overview, 'Время указано по Новосибирску (GMT+7).' ), 'Overview must contain the exact heading and timezone note.' );
scheduled_task_timezone_assert( strpos( $overview, '$this->shipment_cost_analytics->render();' ) < strpos( $overview, 'Запланированные задачи' ), 'Scheduled tasks must render after existing overview content.' );
scheduled_task_timezone_assert( ! str_contains( $dpd, "modify( '+3 hours' )" ) && ! str_contains( $dpd, "modify( '-3 hours' )" ), 'DPD must not use manual offsets.' );
scheduled_task_timezone_assert( ! str_contains( $yandex, 'Europe/Moscow' ) && str_contains( $yandex, 'TimezoneService' ), 'Yandex must use the canonical timezone owner.' );
scheduled_task_timezone_assert( ! str_contains( $ozon, 'wp_timezone' ) && str_contains( $ozon, 'TimezoneService' ), 'Ozon must not use the site timezone.' );

echo "Scheduled task timezone smoke passed.\n";
