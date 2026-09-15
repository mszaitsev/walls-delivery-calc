<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_weekly_schedule_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_option( string $name, mixed $default = false ): mixed {
	return $default;
}

use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupSchedule;

$timezone = new TimezoneService();
$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), new EncryptionService() );
$schedule = new RussianPostPickupSchedule( $settings, $timezone );
$fresh = $schedule->effective_settings( false );
rp_weekly_schedule_assert( array( 'weekday' => 1, 'time' => '09:00', 'explicit' => false ) === $fresh, 'Fresh schedule must default to Monday 09:00 Novosibirsk.' );
rp_weekly_schedule_assert( 96 === count( RussianPostPickupSchedule::time_options() ), 'Schedule must contain exactly 96 quarter-hour slots.' );
rp_weekly_schedule_assert( '00:00' === RussianPostPickupSchedule::time_options()[0] && '23:45' === RussianPostPickupSchedule::time_options()[95], 'Schedule slots must span 00:00 through 23:45.' );

$cases = array(
	array( '2026-09-14 08:00:00', 1, '09:00', '2026-09-14 09:00' ),
	array( '2026-09-14 10:00:00', 1, '09:00', '2026-09-21 09:00' ),
	array( '2026-09-13 12:00:00', 1, '09:00', '2026-09-14 09:00' ),
	array( '2026-09-14 08:00:00', 7, '00:00', '2026-09-20 00:00' ),
	array( '2026-09-14 08:00:00', 1, '23:45', '2026-09-14 23:45' ),
);
foreach ( $cases as [ $now, $weekday, $time, $expected ] ) {
	$timestamp = $schedule->next_timestamp( $weekday, $time, new DateTimeImmutable( $now, $timezone->timezone() ) );
	rp_weekly_schedule_assert( $expected === $timezone->format_timestamp( $timestamp, 'Y-m-d H:i' ), 'Unexpected weekly occurrence for ' . $now . ', weekday ' . $weekday . ', time ' . $time . '.' );
}

$instant_utc = new DateTimeImmutable( '2026-09-14 01:00:00', new DateTimeZone( 'UTC' ) );
$instant_amsterdam = $instant_utc->setTimezone( new DateTimeZone( 'Europe/Amsterdam' ) );
rp_weekly_schedule_assert(
	$schedule->next_timestamp( 1, '09:00', $instant_utc ) === $schedule->next_timestamp( 1, '09:00', $instant_amsterdam ),
	'Weekly calculation must be independent of site/server timezone and use Asia/Novosibirsk.'
);

$legacy_timestamp = ( new DateTimeImmutable( '2026-09-16 03:37:00', $timezone->timezone() ) )->getTimestamp();
$legacy = $schedule->effective_settings( $legacy_timestamp );
rp_weekly_schedule_assert( 3 === $legacy['weekday'] && '03:37' === $legacy['time'] && false === $legacy['explicit'], 'An existing pre-setting event must remain the effective schedule without an arbitrary shift.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/RussianPost/RussianPostPickupSchedule.php' );
rp_weekly_schedule_assert( str_contains( $source, "wp_schedule_event( \$this->next_timestamp" ) && str_contains( $source, "'weekly'" ), 'Schedule owner must create a native weekly WP-Cron event from the calculated first timestamp.' );
rp_weekly_schedule_assert( str_contains( $source, 'wp_clear_scheduled_hook' ) && str_contains( $source, 'matches(' ), 'Schedule owner must replace mismatched events and preserve matching ones.' );

echo "Russian Post weekly schedule smoke passed.\n";
