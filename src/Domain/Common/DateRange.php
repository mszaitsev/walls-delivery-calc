<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Common;

final class DateRange {
	public const UNIT_CALENDAR_DAYS = 'calendar_days';
	public const UNIT_WORKING_DAYS  = 'working_days';
	public const UNIT_BUSINESS_DAYS = 'business_days';

	public function __construct(
		public readonly ?int $min_days = null,
		public readonly ?int $max_days = null,
		public readonly string $unit = self::UNIT_CALENDAR_DAYS
	) {
	}

	public static function single( int $days, string $unit = self::UNIT_CALENDAR_DAYS ): self {
		return new self( $days, $days, $unit );
	}

	public static function range( ?int $min, ?int $max, string $unit = self::UNIT_CALENDAR_DAYS ): self {
		return new self( $min, $max, $unit );
	}

	public function is_empty(): bool {
		return null === $this->min_days && null === $this->max_days;
	}

	/**
	 * @return array{min_days:?int,max_days:?int,unit:string}
	 */
	public function to_array(): array {
		return array(
			'min_days' => $this->min_days,
			'max_days' => $this->max_days,
			'unit'     => $this->unit,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			array_key_exists( 'min_days', $data ) && null !== $data['min_days'] ? (int) $data['min_days'] : null,
			array_key_exists( 'max_days', $data ) && null !== $data['max_days'] ? (int) $data['max_days'] : null,
			(string) ( $data['unit'] ?? self::UNIT_CALENDAR_DAYS )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( ! in_array( $this->unit, array( self::UNIT_CALENDAR_DAYS, self::UNIT_WORKING_DAYS, self::UNIT_BUSINESS_DAYS ), true ) ) {
			$errors[] = 'unit must be calendar_days, working_days, or business_days';
		}

		if ( null !== $this->min_days && $this->min_days < 0 ) {
			$errors[] = 'min_days must be greater than or equal to 0';
		}

		if ( null !== $this->max_days && $this->max_days < 0 ) {
			$errors[] = 'max_days must be greater than or equal to 0';
		}

		if ( null !== $this->min_days && null !== $this->max_days && $this->min_days > $this->max_days ) {
			$errors[] = 'min_days must be less than or equal to max_days';
		}

		return $errors;
	}
}
