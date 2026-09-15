<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Calendar;

final class CalendarDay {
	public function __construct(
		public readonly string $date,
		public readonly bool $working,
		public readonly string $reason = '',
		public readonly string $calendar_type = 'shop'
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'date'          => $this->date,
			'working'       => $this->working,
			'reason'        => $this->reason,
			'calendar_type' => $this->calendar_type,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['date'] ?? '' ),
			(bool) ( $data['working'] ?? false ),
			(string) ( $data['reason'] ?? '' ),
			(string) ( $data['calendar_type'] ?? 'shop' )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $this->date ) ) {
			$errors[] = 'date must be YYYY-MM-DD';
		}

		if ( ! in_array( $this->calendar_type, array( 'carrier_ru', 'shop' ), true ) ) {
			$errors[] = 'calendar_type must be carrier_ru or shop';
		}

		return $errors;
	}
}
