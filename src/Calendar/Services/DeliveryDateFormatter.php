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
		if ( $min_date === $max_date ) {
			$date = $this->date( $min_date );

			return sprintf(
				'Доставка планируется примерно на %d %s, %s',
				(int) $date->format( 'j' ),
				$this->months[ (int) $date->format( 'n' ) ],
				$this->weekdays[ (int) $date->format( 'N' ) ]
			);
		}

		$min = $this->date( $min_date );
		$max = $this->date( $max_date );

		if ( $min->format( 'Y-m' ) === $max->format( 'Y-m' ) ) {
			return sprintf(
				'Доставка планируется примерно %d-%d %s',
				(int) $min->format( 'j' ),
				(int) $max->format( 'j' ),
				$this->months[ (int) $max->format( 'n' ) ]
			);
		}

		return sprintf(
			'Доставка планируется примерно %d %s-%d %s',
			(int) $min->format( 'j' ),
			$this->months[ (int) $min->format( 'n' ) ],
			(int) $max->format( 'j' ),
			$this->months[ (int) $max->format( 'n' ) ]
		);
	}

	private function date( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( TimezoneService::TIMEZONE ) );
	}
}
