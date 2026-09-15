<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

final class DeliveryDateFormatter {
	/** @var array<int, string> */
	private array $months = array(
		1  => 'января',
		2  => 'февраля',
		3  => 'марта',
		4  => 'апреля',
		5  => 'мая',
		6  => 'июня',
		7  => 'июля',
		8  => 'августа',
		9  => 'сентября',
		10 => 'октября',
		11 => 'ноября',
		12 => 'декабря',
	);

	/** @var array<int, string> */
	private array $weekdays = array(
		1 => 'понедельник',
		2 => 'вторник',
		3 => 'среда',
		4 => 'четверг',
		5 => 'пятница',
		6 => 'суббота',
		7 => 'воскресенье',
	);

	public function format_comment( string $min_date, string $max_date ): string {
		unset( $max_date );

		return $this->format_checkout_comment( $min_date );
	}

	public function format_checkout_comment( string $date ): string {
		if ( '' === trim( $date ) ) {
			return '';
		}

		$date_value = $this->date( $date );

		return sprintf(
			'Доставка планируется* с %d %s (%s).',
			(int) $date_value->format( 'j' ),
			$this->months[ (int) $date_value->format( 'n' ) ],
			$this->weekdays[ (int) $date_value->format( 'N' ) ]
		);
	}

	public function format_order_meta_value( string $date ): string {
		if ( '' === trim( $date ) ) {
			return '';
		}

		$date_value = $this->date( $date );

		return sprintf(
			'с %d %s %d',
			(int) $date_value->format( 'j' ),
			$this->months[ (int) $date_value->format( 'n' ) ],
			(int) $date_value->format( 'Y' )
		);
	}

	private function date( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( TimezoneService::TIMEZONE ) );
	}
}
