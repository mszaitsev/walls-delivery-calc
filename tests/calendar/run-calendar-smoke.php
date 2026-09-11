<?php
declare(strict_types=1);

use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Calendar\Admin\CalendarAdminPage;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\CalendarScheduler;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Calendar\CalendarDay;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';

		/** @var array<string, array<string, mixed>> */
		public array $rows = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array(
				'query' => $query,
				'args'  => $args,
			);
		}

		public function replace( string $table, array $data, array $format ): int {
			$key = $data['calendar_type'] . '|' . $data['calendar_date'];
			$this->rows[ $key ] = $data;
			return 1;
		}

		public function get_row( array $prepared, string $output ): ?array {
			$key = $prepared['args'][0] . '|' . $prepared['args'][1];
			return $this->rows[ $key ] ?? null;
		}

		public function get_results( array $prepared, string $output ): array {
			$calendar_type = $prepared['args'][0];
			$year          = (int) $prepared['args'][1];
			$rows          = array_filter(
				$this->rows,
				static fn( array $row ): bool => $row['calendar_type'] === $calendar_type && (int) substr( $row['calendar_date'], 0, 4 ) === $year
			);
			usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['calendar_date'], $b['calendar_date'] ) );
			return array_values( $rows );
		}

		public function get_var( array $prepared ): int {
			return count( $this->get_results( $prepared, ARRAY_A ) );
		}

		public function query( array $prepared ): int {
			$calendar_type = $prepared['args'][0];
			$year          = (int) $prepared['args'][1];
			foreach ( array_keys( $this->rows ) as $key ) {
				$row = $this->rows[ $key ];
				if ( $row['calendar_type'] === $calendar_type && (int) substr( $row['calendar_date'], 0, 4 ) === $year ) {
					unset( $this->rows[ $key ] );
				}
			}
			return 1;
		}
	}
}

/** @var array<string, mixed> $wdc_test_options */
$wdc_test_options = array();

function get_option( string $name, mixed $default = false ): mixed {
	global $wdc_test_options;
	return $wdc_test_options[ $name ] ?? $default;
}

function update_option( string $name, mixed $value, bool $autoload = true ): bool {
	global $wdc_test_options;
	$wdc_test_options[ $name ] = $value;
	return true;
}

$GLOBALS['wdc_calendar_actions'] = array();
$GLOBALS['wdc_calendar_unschedule_calls'] = 0;
function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
	$GLOBALS['wdc_calendar_actions'][ $hook ] = array( 'timestamp' => $timestamp, 'type' => 'single' );
	return 1;
}
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
	$GLOBALS['wdc_calendar_actions'][ $hook ] = array( 'timestamp' => $timestamp, 'type' => 'recurring', 'interval' => $interval );
	return 2;
}
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
	unset( $GLOBALS['wdc_calendar_actions'][ $hook ] );
	$GLOBALS['wdc_calendar_unschedule_calls']++;
}
function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool {
	return isset( $GLOBALS['wdc_calendar_actions'][ $hook ] );
}
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int|false {
	return $GLOBALS['wdc_calendar_actions'][ $hook ]['timestamp'] ?? false;
}

function current_time( string $type ): string {
	return '2026-05-20 12:00:00';
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

function current_user_can( string $capability ): bool {
	return true;
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function selected( mixed $selected, mixed $current, bool $display = true ): string {
	$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
	$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

function wp_nonce_field( string $action, string $name ): void {
	printf( '<input type="hidden" name="%s" value="test-nonce">', esc_attr( $name ) );
}

function sanitize_key( mixed $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? '';
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function calendar_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new wpdb();
$repository = new CalendarRepository( $wpdb );
$generator = new YearGenerator();
$timezone = new TimezoneService();
$settings = new SettingsRepository();
$calendar = new CalendarService( $repository, $generator, $settings, $timezone );

$repository->save_days( $generator->generate_year( CalendarTypes::CARRIER_RU, 2026 ) );
$repository->save_days( $generator->generate_year( CalendarTypes::SHOP, 2026 ) );

calendar_smoke_assert( false === $calendar->is_working_day( CalendarTypes::CARRIER_RU, '2026-05-23' ), 'carrier_ru Saturday must be non-working.' );
calendar_smoke_assert( false === $calendar->is_working_day( CalendarTypes::CARRIER_RU, '2026-05-24' ), 'carrier_ru Sunday must be non-working.' );
calendar_smoke_assert( true === $calendar->is_working_day( CalendarTypes::SHOP, '2026-05-23' ), 'shop Saturday must be working.' );
calendar_smoke_assert( false === $calendar->is_working_day( CalendarTypes::SHOP, '2026-05-24' ), 'shop Sunday must be non-working.' );

calendar_smoke_assert(
	'2026-05-21' === $timezone->normalize_order_date( '2026-05-20 20:00:00 Asia/Novosibirsk' ),
	'Cutoff after 19:00 must move effective order date to the next day.'
);

$calculator = new DeliveryDateCalculator( $calendar, $timezone, new DeliveryDateFormatter() );
$planned = $calculator->calculate( '2026-05-20 12:00:00 Asia/Novosibirsk', 1, DateRange::single( 5, DateRange::UNIT_CALENDAR_DAYS ) );
calendar_smoke_assert( '2026-05-21' === $planned->handoff_date, 'Handoff date must be 2026-05-21.' );
calendar_smoke_assert( '2026-05-26' === $planned->planned_date_min, 'Calendar-day planned date must be 2026-05-26.' );
calendar_smoke_assert( '2026-05-26' === $planned->planned_date_max, 'Calendar-day max planned date must be 2026-05-26.' );

$working = $calculator->calculate( '2026-05-20 12:00:00 Asia/Novosibirsk', 1, DateRange::single( 5, DateRange::UNIT_WORKING_DAYS ) );
calendar_smoke_assert( '2026-05-28' === $working->planned_date_min, 'Working-day delivery must skip carrier weekends.' );

$normalized = $calculator->normalize_lead_time( '2026-05-19', 2, DateRange::single( 2 ), false );
calendar_smoke_assert( 2 === $normalized['processing_calendar_days'] && 4 === $normalized['total_calendar_days']->min_days, 'Tue + 2 shop working days must add 2 calendar days plus carrier calendar days.' );

$normalized = $calculator->normalize_lead_time( '2026-05-22', 2, DateRange::single( 2 ), false );
calendar_smoke_assert( 3 === $normalized['processing_calendar_days'], 'Fri with working Saturday and Sunday off must produce 3 processing calendar days.' );

$normalized = $calculator->normalize_lead_time( '2026-05-23', 2, DateRange::single( 2 ), false );
calendar_smoke_assert( 3 === $normalized['processing_calendar_days'], 'Sat with Sunday off and Mon/Tue working must produce 3 processing calendar days.' );

$repository->save_day( new CalendarDay( '2026-05-25', false, 'test holiday', CalendarTypes::SHOP ) );
$normalized = $calculator->normalize_lead_time( '2026-05-22', 2, DateRange::single( 2 ), false );
calendar_smoke_assert( 4 === $normalized['processing_calendar_days'], 'Fri with Sun/Mon off must produce 4 processing calendar days.' );

$repository->save_day( new CalendarDay( '2026-05-27', false, 'test holiday', CalendarTypes::SHOP ) );
$normalized = $calculator->normalize_lead_time( '2026-05-23', 2, DateRange::single( 2 ), false );
calendar_smoke_assert( 5 === $normalized['processing_calendar_days'], 'Sat with Sun/Mon off and Wed off must produce 5 processing calendar days.' );

$zero = $calculator->normalize_lead_time( '2026-05-22', 0, DateRange::single( 2, DateRange::UNIT_WORKING_DAYS ), true );
calendar_smoke_assert( '2026-05-22' === $zero['handoff_date'] && 4 === $zero['carrier_calendar_days']->min_days, 'Processing 0 must keep handoff date and carrier working days must still start after handoff.' );

$calendar_carrier = $calculator->normalize_lead_time( '2026-05-22', 0, DateRange::single( 2 ), false );
calendar_smoke_assert( 2 === $calendar_carrier['carrier_calendar_days']->min_days, 'Carrier calendar flag disabled must keep carrier days as calendar days.' );

$working_carrier = $calculator->normalize_lead_time( '2026-05-22', 0, DateRange::single( 2 ), true );
calendar_smoke_assert( 4 === $working_carrier['carrier_calendar_days']->min_days, 'Carrier calendar flag enabled must convert carrier working days through carrier_ru calendar.' );

$range = $calculator->normalize_lead_time( '2026-05-22', 0, DateRange::range( 2, 3 ), true );
calendar_smoke_assert( 4 === $range['total_calendar_days']->min_days && 5 === $range['total_calendar_days']->max_days, 'Min/max carrier range must be converted independently.' );

$formatter = new DeliveryDateFormatter();
calendar_smoke_assert( 'Доставка планируется* с 12 августа (среда).' === $formatter->format_checkout_comment( '2026-08-12' ), 'Checkout planned delivery comment must use the canonical format.' );
calendar_smoke_assert( 'с 12 августа 2026' === $formatter->format_order_meta_value( '2026-08-12' ), 'Order planned delivery meta value must use the canonical format.' );

update_option(
	'wdc_calendar_attention_required',
	array(
		'carrier_ru_2026' => array(
			'calendar_type' => CalendarTypes::CARRIER_RU,
			'year'          => 2026,
		),
	),
	false
);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_REQUEST                  = array(
	'calendar_type' => CalendarTypes::CARRIER_RU,
	'year'          => '2026',
);
$_POST                     = array();

$admin_page = new CalendarAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.12.14' ),
	$calendar,
	$repository,
	$generator
);

ob_start();
$admin_page->render_page();
$calendar_html = (string) ob_get_clean();

$attention = get_option( 'wdc_calendar_attention_required', array() );
calendar_smoke_assert( isset( $attention['carrier_ru_2026'] ), 'Opening calendar admin page must not resolve calendar_attention_required.' );
foreach ( array( 'Календарь РФ/ТК', 'Календарь магазина', 'Сгенерировать год', 'Сохранить календарь', 'Календарь РФ/ТК, 2026 год', 'Январь', 'Пн' ) as $needle ) {
	calendar_smoke_assert( str_contains( $calendar_html, $needle ), 'Calendar admin page must render Russian label: ' . $needle );
}
foreach ( array( 'wdc-calendar-reason', 'wdc-calendar-day-state', 'name="days[2026-01-01][reason]"', '>w<', '>weekday<', '>weekend<', '>generated<', '>manual<', '>holiday<', 'рабочий день', 'нерабочий день' ) as $needle ) {
	calendar_smoke_assert( ! str_contains( $calendar_html, $needle ), 'Calendar admin page must not render reason marker: ' . $needle );
}
calendar_smoke_assert( str_contains( $calendar_html, 'wdc-calendar-day-toggle' ), 'Calendar UI must keep clickable working/non-working toggles.' );
$calendar_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/calendar-admin.css' );
foreach ( array( '.wdc-calendar-day.is-working', '.wdc-calendar-day.is-non-working', 'display: flex', 'align-items: center', 'justify-content: center', 'aspect-ratio: 1 / 1', 'transition:' ) as $needle ) {
	calendar_smoke_assert( str_contains( $calendar_css, $needle ), 'Calendar CSS must contain centered square day style: ' . $needle );
}

$scheduler = new CalendarScheduler( new ActionScheduler( new Logger() ), $calendar, $timezone );
foreach ( array(
	array( '2026-09-01 00:00:00', '2026-09-07 09:00' ),
	array( '2026-09-07 08:00:00', '2026-09-07 09:00' ),
	array( '2026-09-07 10:00:00', '2026-10-05 09:00' ),
	array( '2026-06-01 00:00:00', '2026-06-01 09:00' ),
	array( '2026-12-10 00:00:00', '2027-01-04 09:00' ),
) as [ $now, $expected ] ) {
	$timestamp = $scheduler->next_run_timestamp( new DateTimeImmutable( $now, $timezone->timezone() ) );
	calendar_smoke_assert( $expected === $timezone->format_timestamp( $timestamp, 'Y-m-d H:i' ), 'Monthly calendar next run mismatch for ' . $now );
}

$next_year = (int) $timezone->now()->modify( '+1 year' )->format( 'Y' );
$before_generation = count( $wpdb->rows );
$calendar->generate_next_year_if_needed();
$after_generation = count( $wpdb->rows );
calendar_smoke_assert( $after_generation > $before_generation && $repository->has_year( CalendarTypes::CARRIER_RU, $next_year ) && $repository->has_year( CalendarTypes::SHOP, $next_year ), 'Missing next-year calendars must be generated on a monthly run.' );
$calendar->generate_next_year_if_needed();
calendar_smoke_assert( $after_generation === count( $wpdb->rows ), 'A later monthly run must not overwrite existing next-year calendars.' );

$GLOBALS['wdc_calendar_actions'][ CalendarScheduler::HOOK ] = array( 'timestamp' => time() + 86400, 'type' => 'recurring', 'interval' => 86400 );
unset( $wdc_test_options['wdc_calendar_schedule_version'] );
$scheduler->schedule();
calendar_smoke_assert( 1 === $GLOBALS['wdc_calendar_unschedule_calls'] && 'single' === $GLOBALS['wdc_calendar_actions'][ CalendarScheduler::HOOK ]['type'], 'Legacy daily calendar action must be removed and replaced with one monthly single action.' );
$scheduled_after_migration = $GLOBALS['wdc_calendar_actions'][ CalendarScheduler::HOOK ]['timestamp'];
$scheduler->schedule();
calendar_smoke_assert( $scheduled_after_migration === $GLOBALS['wdc_calendar_actions'][ CalendarScheduler::HOOK ]['timestamp'], 'Repeated calendar ensure must not create a duplicate action.' );
unset( $GLOBALS['wdc_calendar_actions'][ CalendarScheduler::HOOK ] );
$scheduler->run();
calendar_smoke_assert( $after_generation === count( $wpdb->rows ) && 'single' === $GLOBALS['wdc_calendar_actions'][ CalendarScheduler::HOOK ]['type'], 'Calendar hook must preserve existing years and self-schedule the next monthly action.' );

echo "Calendar smoke test passed.\n";
