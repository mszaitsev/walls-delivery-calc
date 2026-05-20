<?php
declare(strict_types=1);

use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

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

function current_time( string $type ): string {
	return '2026-05-20 12:00:00';
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

echo "Calendar smoke test passed.\n";
