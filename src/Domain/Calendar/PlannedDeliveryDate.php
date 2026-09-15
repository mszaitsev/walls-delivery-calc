<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Calendar;

use WallsShop\WDC\Domain\Common\DateRange;

final class PlannedDeliveryDate {
	public function __construct(
		public readonly string $order_date,
		public readonly string $effective_order_date,
		public readonly int $shop_processing_days,
		public readonly string $handoff_date,
		public readonly DateRange $carrier_days,
		public readonly string $planned_date_min,
		public readonly string $planned_date_max,
		public readonly string $comment = ''
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'order_date'           => $this->order_date,
			'effective_order_date' => $this->effective_order_date,
			'shop_processing_days' => $this->shop_processing_days,
			'handoff_date'         => $this->handoff_date,
			'carrier_days'         => $this->carrier_days->to_array(),
			'planned_date_min'     => $this->planned_date_min,
			'planned_date_max'     => $this->planned_date_max,
			'comment'              => $this->comment,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['order_date'] ?? '' ),
			(string) ( $data['effective_order_date'] ?? '' ),
			(int) ( $data['shop_processing_days'] ?? 0 ),
			(string) ( $data['handoff_date'] ?? '' ),
			DateRange::from_array( is_array( $data['carrier_days'] ?? null ) ? $data['carrier_days'] : array() ),
			(string) ( $data['planned_date_min'] ?? '' ),
			(string) ( $data['planned_date_max'] ?? '' ),
			(string) ( $data['comment'] ?? '' )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		foreach ( array( 'order_date' => $this->order_date, 'effective_order_date' => $this->effective_order_date, 'handoff_date' => $this->handoff_date, 'planned_date_min' => $this->planned_date_min, 'planned_date_max' => $this->planned_date_max ) as $field => $date ) {
			if ( '' !== $date && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$errors[] = $field . ' must be YYYY-MM-DD';
			}
		}

		if ( $this->shop_processing_days < 0 ) {
			$errors[] = 'shop_processing_days must be greater than or equal to 0';
		}

		return array_merge( $errors, $this->carrier_days->validate() );
	}
}
