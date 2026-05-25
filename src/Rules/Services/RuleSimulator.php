<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Services;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEngineResult;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;

final class RuleSimulator {
	public function __construct(
		private RuleEngine $engine
	) {
	}

	/**
	 * @param array<int,Rule> $rules
	 */
	public function simulate( array $rules, ?RuleEvaluationContext $context = null ): RuleEngineResult {
		return $this->engine->apply_rules( $rules, $context ?? $this->sample_context() );
	}

	public function sample_context(): RuleEvaluationContext {
		$item = new PackageItem(
			sku: 'SAMPLE',
			name: 'Sample item',
			quantity: 1,
			unit_price: Money::from_rubles( 1000 ),
			total_price: Money::from_rubles( 1000 ),
			weight_g: 12000,
			length_cm: 10,
			width_cm: 10,
			height_cm: 10
		);

		$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );

		return new RuleEvaluationContext(
			order_total: Money::from_rubles( 1000 ),
			delivery_price: Money::from_rubles( 450 ),
			package: $package,
			destination: new Address( country_code: 'RU', city: 'Moscow', street: 'Tverskaya', house: '1', raw_address: 'Moscow, Tverskaya 1' ),
			delivery_type: 'courier',
			payment_method: 'card',
			calculation_date: '2026-05-21'
		);
	}
}
