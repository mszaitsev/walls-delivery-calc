<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Shipments;

use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\TimezoneService;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentDateResolver {
	private const CUTOFF_TIME = '17:00:00';

	public function __construct(
		private ?CalendarService $calendar = null,
		private ?TimezoneService $timezone = null
	) {
	}

	/**
	 * @return array{date:string,calendar_used:bool,fallback_used:bool}
	 */
	public function default_date( ?DateTimeImmutable $now = null ): array {
		$now = $now ?? ( $this->timezone instanceof TimezoneService ? $this->timezone->now() : new DateTimeImmutable( 'now', new DateTimeZone( TimezoneService::TIMEZONE ) ) );
		$date = $now->format( 'H:i:s' ) >= self::CUTOFF_TIME
			? $now->modify( '+1 day' )->format( 'Y-m-d' )
			: $now->format( 'Y-m-d' );

		if ( $this->calendar instanceof CalendarService ) {
			return array(
				'date' => $this->calendar->get_next_working_day( CalendarTypes::SHOP, $date ),
				'calendar_used' => true,
				'fallback_used' => false,
			);
		}

		return array(
			'date' => $date,
			'calendar_used' => false,
			'fallback_used' => true,
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate( string $date, ?DateTimeImmutable $now = null ): array {
		$errors = array();
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array( 'Дата отправки DPD должна быть в формате YYYY-MM-DD.' );
		}

		$now = $now ?? ( $this->timezone instanceof TimezoneService ? $this->timezone->now() : new DateTimeImmutable( 'now', new DateTimeZone( TimezoneService::TIMEZONE ) ) );
		if ( $date < $now->format( 'Y-m-d' ) ) {
			$errors[] = 'Дата отправки DPD не может быть в прошлом.';
		}
		if ( $this->calendar instanceof CalendarService && ! $this->calendar->is_working_day( CalendarTypes::SHOP, $date ) ) {
			$errors[] = 'Дата отправки DPD должна быть рабочим днем магазина.';
		}

		return $errors;
	}
}
