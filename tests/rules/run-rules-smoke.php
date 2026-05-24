<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Common\DateRange;
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

function rules_context( float $delivery_price = 450, int $weight = 1000, string $city = 'Moscow', array $meta = array(), string $fias_id = '' ): RuleEvaluationContext {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), $weight, 120, 20, 15 );

	return new RuleEvaluationContext(
		Money::from_rubles( 1000 ),
		Money::from_rubles( $delivery_price ),
		Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ),
		new Address( country_code: 'RU', city: $city, street: 'Tverskaya', house: '1', raw_address: $city . ', Tverskaya 1', fias_id: $fias_id ),
		'courier',
		'card',
		'2026-05-21',
		array(),
		$meta
	);
}

function price_rule( string $name, string $operation, float $value, string $base = RuleOperationBases::RUBLES, bool $promo = false, bool $stop = false ): Rule {
	return new Rule( null, $name, true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, $operation, $value, $base, $promo, $stop );
}

$engine = new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) );

$hydrated_condition = new RuleCondition( 7, 3, 2, RuleConditionTypes::CITY, RuleOperators::CONTAINS, 'mos', null, array( 'Moscow' ) );
$hydrated_rule      = Rule::from_array(
	array(
		'name'            => 'Hydrate condition object',
		'action_type'     => RuleActionTypes::CHANGE_PRICE,
		'operation_type'  => RuleOperationTypes::INCREASE,
		'operation_base'  => RuleOperationBases::RUBLES,
		'conditions'      => array( $hydrated_condition ),
	)
);
rules_smoke_assert( 1 === count( $hydrated_rule->conditions ), 'Rule::from_array must keep condition objects.' );
rules_smoke_assert( RuleConditionTypes::CITY === $hydrated_rule->conditions[0]->condition_type, 'Hydrated condition type must be preserved.' );
rules_smoke_assert( RuleOperators::CONTAINS === $hydrated_rule->conditions[0]->operator, 'Hydrated condition operator must be preserved.' );
rules_smoke_assert( 'mos' === $hydrated_rule->conditions[0]->value_text, 'Hydrated condition value_text must be preserved.' );
rules_smoke_assert( array( 'Moscow' ) === $hydrated_rule->conditions[0]->value_json, 'Repository-style hydrate must preserve condition value_json.' );

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

$delivery_days_rule = new Rule(
	null,
	'Business days +2',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_DELIVERY_DAYS,
	RuleOperationTypes::INCREASE,
	2,
	RuleOperationBases::BUSINESS_DAYS,
	false,
	false
);
$context_with_days = RuleEvaluationContext::from_array( array_merge( rules_context()->to_array(), array( 'meta' => array( 'original_delivery_days' => 5 ) ) ) );
$result = $engine->apply_rules( array( $delivery_days_rule ), $context_with_days );
rules_smoke_assert( 7 === $result->final_delivery_days?->min_days, 'Delivery days increase must use the original delivery days.' );
rules_smoke_assert( DateRange::UNIT_BUSINESS_DAYS === $result->final_delivery_days?->unit, 'change_delivery_days must support business_days.' );

$calendar_days_rule = Rule::from_array(
	array(
		'name'            => 'Calendar days default',
		'target_type'     => 'default',
		'action_type'     => RuleActionTypes::CHANGE_DELIVERY_DAYS,
		'operation_type'  => RuleOperationTypes::EQUALS,
		'operation_value' => 3,
		'operation_base'  => RuleOperationBases::CALENDAR_DAYS,
	)
);
rules_smoke_assert( array() === $calendar_days_rule->validate(), 'calendar_days must be a valid operation base.' );

$payment_rule = new Rule(
	null,
	'Payment',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::PAYMENT_METHOD, RuleOperators::EQ, 'card' ) )
);
$result = $engine->apply_rules( array( $payment_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'payment_method condition must still work.' );

$city_fias_rule = new Rule(
	null,
	'City FIAS',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::CITY, RuleOperators::EQ, 'fias-nsk', null, array( 'fias_id' => 'fias-nsk', 'display_name' => 'Новосибирск' ) ) )
);
$result = $engine->apply_rules( array( $city_fias_rule ), rules_context( 450, 1000, 'Other city', array( 'selected_location_fias_id' => 'fias-nsk' ) ) );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'city condition must compare by selected location fias_id.' );
$result = $engine->apply_rules( array( $city_fias_rule ), rules_context( 450, 1000, 'Новосибирск', array( 'selected_location_fias_id' => 'fias-other' ) ) );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'city condition must be false for a different fias_id.' );
$city_neq_rule = Rule::from_array( array_merge( $city_fias_rule->to_array(), array( 'conditions' => array( new RuleCondition( null, null, 1, RuleConditionTypes::CITY, RuleOperators::NEQ, 'fias-nsk' ) ) ) ) );
$result = $engine->apply_rules( array( $city_neq_rule ), rules_context( 450, 1000, 'Other city', array( 'selected_location_fias_id' => 'fias-other' ) ) );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'city != condition must be true for different non-empty fias_id.' );
$result = $engine->apply_rules( array( $city_fias_rule ), rules_context( 450, 1000, 'Новосибирск') );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'city condition must be false when context fias_id is empty.' );
$result = $engine->apply_rules( array( $city_fias_rule ), rules_context( 450, 1000, 'fias-nsk') );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'city condition must not fall back to city text/display name.' );

$weight_rule = new Rule(
	null,
	'Weight grams',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::WEIGHT, RuleOperators::GTE, '', 12000 ) )
);
$result = $engine->apply_rules( array( $weight_rule ), rules_context( 450, 12000 ) );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'weight condition must compare grams without conversion.' );

$dimensions_rule = new Rule(
	null,
	'Dimensions',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::DIMENSIONS, RuleOperators::GTE, '', null, array( 'length_cm' => 100, 'height_cm' => 10 ) ) )
);
$result = $engine->apply_rules( array( $dimensions_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'dimensions condition must compare all filled fields.' );
$dimensions_ignore_empty = new RuleCondition( null, null, 1, RuleConditionTypes::DIMENSIONS, RuleOperators::GTE, '', null, array( 'length_cm' => 100 ) );
$result = $engine->apply_rules( array( Rule::from_array( array_merge( $dimensions_rule->to_array(), array( 'conditions' => array( $dimensions_ignore_empty ) ) ) ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'dimensions condition must ignore empty fields.' );

$volume_rule = new Rule(
	null,
	'Volume m3',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::VOLUME, RuleOperators::GTE, '', 0.036 ) )
);
$result = $engine->apply_rules( array( $volume_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'volume condition must compare cubic meters.' );

$date_rule = new Rule(
	null,
	'Date',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array( new RuleCondition( null, null, 1, RuleConditionTypes::DATE, RuleOperators::GTE, '2026-05-20' ) )
);
$result = $engine->apply_rules( array( $date_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'date condition must evaluate stored YYYY-MM-DD values.' );

$and_group_rule = new Rule(
	null,
	'AND group',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array(
		new RuleCondition( null, null, 1, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'RU' ),
		new RuleCondition( null, null, 1, RuleConditionTypes::PAYMENT_METHOD, RuleOperators::EQ, 'cash' ),
	),
	array( 1 => 'and', 2 => 'and', 3 => 'and' )
);
$result = $engine->apply_rules( array( $and_group_rule ), rules_context() );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'AND group must require all conditions.' );

$or_group_rule = Rule::from_array( array_merge( $and_group_rule->to_array(), array( 'name' => 'OR group', 'condition_group_logic' => array( 1 => 'or', 2 => 'and', 3 => 'and' ) ) ) );
$result = $engine->apply_rules( array( $or_group_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'OR group must require at least one condition.' );

$groups_or_rule = new Rule(
	null,
	'Groups OR',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_PRICE,
	RuleOperationTypes::DECREASE,
	10,
	RuleOperationBases::RUBLES,
	false,
	false,
	array(
		new RuleCondition( null, null, 1, RuleConditionTypes::PAYMENT_METHOD, RuleOperators::EQ, 'cash' ),
		new RuleCondition( null, null, 2, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'RU' ),
	),
	array( 1 => 'and', 2 => 'and', 3 => 'and' )
);
$result = $engine->apply_rules( array( $groups_or_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'Condition groups must combine via OR.' );

$result = $engine->apply_rules( array( Rule::from_array( array_merge( price_rule( 'No conditions', RuleOperationTypes::DECREASE, 10 )->to_array(), array( 'conditions' => array() ) ) ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'Rules without conditions must still apply.' );

echo "Rules smoke test passed.\n";
