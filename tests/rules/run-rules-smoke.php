<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rules_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function rules_context( float $delivery_price = 450, int $weight = 1000, string $city = 'Moscow' ): RuleEvaluationContext {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), $weight, 10, 10, 10 );

	return new RuleEvaluationContext(
		Money::from_rubles( 1000 ),
		Money::from_rubles( $delivery_price ),
		Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ),
		new Address( country_code: 'RU', city: $city, street: 'Tverskaya', house: '1', raw_address: $city . ', Tverskaya 1' ),
		'courier',
		'card',
		'2026-05-21'
	);
}

function price_rule( string $name, string $operation, float $value, string $base = RuleOperationBases::RUBLES, bool $promo = false, bool $stop = false ): Rule {
	return new Rule( null, $name, true, 10, 'rate', 'demo', RuleActionTypes::CHANGE_PRICE, $operation, $value, $base, $promo, $stop );
}

$engine = new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) );

$result = $engine->apply_rules( array( price_rule( '+200', RuleOperationTypes::INCREASE, 200 ) ), rules_context() );
rules_smoke_assert( 65000 === $result->final_price?->get_kopecks(), '+200 RUB must produce 650 RUB.' );

$result = $engine->apply_rules( array( price_rule( '-10%', RuleOperationTypes::DECREASE, 10, RuleOperationBases::PERCENT_OF_DELIVERY ) ), rules_context() );
rules_smoke_assert( 40500 === $result->final_price?->get_kopecks(), '-10% delivery must produce 405 RUB.' );

$result = $engine->apply_rules( array( price_rule( 'promo -500', RuleOperationTypes::DECREASE, 500, RuleOperationBases::RUBLES, true ) ), rules_context() );
rules_smoke_assert( 100 === $result->final_price?->get_kopecks(), 'Promo discount must clamp final price to 1 RUB.' );
rules_smoke_assert( 45000 === $result->crossed_price?->get_kopecks(), 'Promo discount must preserve crossed price before promo.' );

$disable_rule = new Rule(
	null,
	'Disable heavy',
	true,
	10,
	'rate',
	'Weight limit exceeded',
	RuleActionTypes::DISABLE_RATE,
	RuleOperationTypes::EQUALS,
	0,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::WEIGHT, RuleOperators::GT, '', 10000 ) )
);
$result = $engine->apply_rules( array( $disable_rule ), rules_context( 450, 12000 ) );
rules_smoke_assert( $result->disabled, 'Weight > 10000 must disable rate.' );

$group_rule = new Rule(
	null,
	'Grouped',
	true,
	10,
	'rate',
	'demo',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::INCREASE,
	50,
	RuleOperationBases::RUBLES,
	false,
	false,
	array(
		new RuleCondition( null, null, 1, RuleConditionTypes::CITY, RuleOperators::EQ, 'Novosibirsk' ),
		new RuleCondition( null, null, 1, RuleConditionTypes::PAYMENT_METHOD, RuleOperators::EQ, 'card' ),
		new RuleCondition( null, null, 2, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'RU' ),
	)
);
$result = $engine->apply_rules( array( $group_rule ), rules_context() );
rules_smoke_assert( 50000 === $result->final_price?->get_kopecks(), 'Condition groups must be AND inside group and OR between groups.' );

$result = $engine->apply_rules(
	array(
		price_rule( 'stop', RuleOperationTypes::INCREASE, 100, RuleOperationBases::RUBLES, false, true ),
		price_rule( 'after stop', RuleOperationTypes::INCREASE, 100 ),
	),
	rules_context()
);
rules_smoke_assert( 55000 === $result->final_price?->get_kopecks(), 'stop_processing must stop subsequent rules.' );
rules_smoke_assert( count( $result->audit ) >= 1, 'Audit entries must be generated.' );

echo "Rules smoke test passed.\n";
