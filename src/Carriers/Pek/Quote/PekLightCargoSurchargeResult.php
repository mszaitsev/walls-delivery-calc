<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

defined( 'ABSPATH' ) || exit;

final class PekLightCargoSurchargeResult {
	public const REASON_APPLIED = 'applied';
	public const REASON_WEIGHT_NOT_KNOWN = 'weight_not_known';
	public const REASON_WEIGHT_AT_OR_ABOVE_LIMIT = 'weight_at_or_above_limit';
	public const REASON_ZERO_SURCHARGE = 'zero_surcharge';

	/** @param array<int,array{code:string,title:string,price_kopecks:int}> $surcharges */
	public function __construct(
		public readonly bool $eligible,
		public readonly bool $applied,
		public readonly int $product_weight_g,
		public readonly int $weight_limit_g,
		public readonly int $bag_price_kopecks,
		public readonly int $sealing_price_kopecks,
		public readonly int $total_surcharge_kopecks,
		public readonly string $reason,
		public readonly array $surcharges
	) {
		if ( $product_weight_g < 0 || $weight_limit_g < 1 || $bag_price_kopecks < 0 || $sealing_price_kopecks < 0 || $total_surcharge_kopecks < 0 ) {
			throw new \InvalidArgumentException( 'PEK light-cargo surcharge values are invalid.' );
		}
		if ( ! in_array( $reason, array( self::REASON_APPLIED, self::REASON_WEIGHT_NOT_KNOWN, self::REASON_WEIGHT_AT_OR_ABOVE_LIMIT, self::REASON_ZERO_SURCHARGE ), true ) ) {
			throw new \InvalidArgumentException( 'PEK light-cargo surcharge reason is invalid.' );
		}
	}

	/** @return array<string,mixed> */
	public function to_pricing_adjustment(): array {
		return array(
			'product_weight_g' => $this->product_weight_g,
			'light_cargo_weight_limit_g' => $this->weight_limit_g,
			'light_cargo_eligible' => $this->eligible,
			'bag_surcharge_kopecks' => $this->bag_price_kopecks,
			'sealing_surcharge_kopecks' => $this->sealing_price_kopecks,
			'total_surcharge_kopecks' => $this->total_surcharge_kopecks,
			'surcharge_applied' => $this->applied,
			'surcharge_reason' => $this->reason,
		);
	}
}
