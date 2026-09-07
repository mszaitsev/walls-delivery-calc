<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;
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

function rules_context( float $delivery_price = 450, int $weight = 1000, string $city = 'Moscow', array $meta = array(), string $fias_id = '', ?float $order_total = null, ?float $all_cart_items_total = null ): RuleEvaluationContext {
	$order_total ??= 1000;
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ), $weight, 120, 20, 15 );

	return new RuleEvaluationContext(
		Money::from_rubles( $order_total ),
		Money::from_rubles( $delivery_price ),
		Package::from_items( array( $item ), 0, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ) ),
		new Address( country_code: 'RU', city: $city, street: 'Tverskaya', house: '1', raw_address: $city . ', Tverskaya 1', fias_id: $fias_id ),
		'courier',
		'card',
		'2026-05-21',
		array(),
		$meta,
		null === $all_cart_items_total ? null : Money::from_rubles( $all_cart_items_total )
	);
}

function price_rule( string $name, string $operation, float $value, string $base = RuleOperationBases::RUBLES, bool $promo = false, bool $stop = false ): Rule {
	return new Rule( null, $name, true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, $operation, $value, $base, $promo, $stop );
}

function conditional_price_rule( string $name, string $condition_type, string $operator, float $condition_value ): Rule {
	return new Rule(
		null,
		$name,
		true,
		10,
		'default',
		'',
		RuleActionTypes::CHANGE_PRICE,
		RuleOperationTypes::INCREASE,
		100,
		RuleOperationBases::RUBLES,
		false,
		false,
		array( new RuleCondition( null, null, 1, $condition_type, $operator, '', $condition_value ) )
	);
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

$mixed_context = rules_context( 400, 1000, 'Moscow', array(), '', 3000, 5000 );
$result = $engine->apply_rules( array( price_rule( '+100 RUB', RuleOperationTypes::INCREASE, 100 ) ), $mixed_context );
rules_smoke_assert( 50000 === $result->final_price?->get_kopecks(), '+100 RUB must remain based on fixed rubles.' );
$result = $engine->apply_rules( array( price_rule( '+10% delivery', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_DELIVERY ) ), $mixed_context );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), '+10% delivery must use delivery price base 400.' );
$result = $engine->apply_rules( array( price_rule( '+10% physical', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_ORDER ) ), $mixed_context );
rules_smoke_assert( 70000 === $result->final_price?->get_kopecks(), 'Existing percent_of_order must still use physical/package total 3000.' );
$result = $engine->apply_rules( array( price_rule( '+10% cart', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_CART ) ), $mixed_context );
rules_smoke_assert( 90000 === $result->final_price?->get_kopecks(), 'New percent_of_cart must use all cart items total 5000.' );
$result = $engine->apply_rules( array( price_rule( '+10% physical+delivery', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_ORDER_AND_DELIVERY ) ), $mixed_context );
rules_smoke_assert( 74000 === $result->final_price?->get_kopecks(), 'Existing percent_of_order_and_delivery must use package total plus delivery.' );
$result = $engine->apply_rules( array( price_rule( '+10% cart+delivery', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_CART_AND_DELIVERY ) ), $mixed_context );
rules_smoke_assert( 94000 === $result->final_price?->get_kopecks(), 'New percent_of_cart_and_delivery must use all cart items total plus delivery.' );
$result = $engine->apply_rules( array( price_rule( '+10% cart fallback', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_CART ) ), rules_context( 400, 1000, 'Moscow', array(), '', 1000 ) );
rules_smoke_assert( 50000 === $result->final_price?->get_kopecks(), 'Contexts without all_cart_items_total must fall back to order_total.' );
rules_smoke_assert( RuleOperationBases::is_valid( 'percent_of_order' ) && RuleOperationBases::is_valid( 'percent_of_order_and_delivery' ), 'Persisted old operation base keys must remain valid.' );
rules_smoke_assert( RuleOperationBases::is_valid( RuleOperationBases::PERCENT_OF_CART ) && RuleOperationBases::is_valid( RuleOperationBases::PERCENT_OF_CART_AND_DELIVERY ), 'New cart operation base keys must validate.' );
rules_smoke_assert( RuleConditionTypes::is_valid( RuleConditionTypes::ORDER_TOTAL ), 'Persisted order_total condition type must remain valid.' );
rules_smoke_assert( RuleConditionTypes::is_valid( RuleConditionTypes::CART_TOTAL ), 'New cart_total condition type must validate.' );

$result = $engine->apply_rules( array( conditional_price_rule( 'Physical total threshold', RuleConditionTypes::ORDER_TOTAL, RuleOperators::GTE, 4000 ) ), $mixed_context );
rules_smoke_assert( 40000 === $result->final_price?->get_kopecks(), 'Existing order_total condition must still use physical/package total 3000.' );
$result = $engine->apply_rules( array( conditional_price_rule( 'Cart total threshold', RuleConditionTypes::CART_TOTAL, RuleOperators::GTE, 4000 ) ), $mixed_context );
rules_smoke_assert( 50000 === $result->final_price?->get_kopecks(), 'New cart_total condition must use all cart items total 5000.' );

foreach ( array(
	RuleOperators::EQ  => true,
	RuleOperators::GTE => true,
	RuleOperators::GT  => false,
	RuleOperators::LTE => true,
	RuleOperators::LT  => false,
) as $operator => $expected ) {
	$result = $engine->apply_rules( array( conditional_price_rule( 'Cart boundary ' . $operator, RuleConditionTypes::CART_TOTAL, $operator, 5000 ) ), $mixed_context );
	rules_smoke_assert( ( 50000 === $result->final_price?->get_kopecks() ) === $expected, 'cart_total boundary operator ' . $operator . ' must compare against 5000.' );
}

$result = $engine->apply_rules( array( conditional_price_rule( 'Cart total fallback', RuleConditionTypes::CART_TOTAL, RuleOperators::GTE, 3000 ) ), rules_context( 400, 1000, 'Moscow', array(), '', 3000 ) );
rules_smoke_assert( 50000 === $result->final_price?->get_kopecks(), 'cart_total condition must fall back to order_total outside Woo context.' );

$persisted_order_total_rule = Rule::from_array(
	array(
		'name'           => 'Persisted order_total condition',
		'action_type'    => RuleActionTypes::CHANGE_PRICE,
		'operation_type' => RuleOperationTypes::INCREASE,
		'operation_base' => RuleOperationBases::RUBLES,
		'conditions'     => array(
			array(
				'condition_group' => 1,
				'condition_type'  => 'order_total',
				'operator'        => RuleOperators::GTE,
				'value_number'    => 4000,
			),
		),
	)
);
rules_smoke_assert( RuleConditionTypes::ORDER_TOTAL === $persisted_order_total_rule->conditions[0]->condition_type, 'Persisted old condition_type order_total must roundtrip without rename.' );

$formatter = new RuleFormulaFormatter();
$audit_to_array = static fn( array $audit ): array => array_map( static fn( $entry ): array => $entry->to_array(), $audit );
$cart_lines = $formatter->lines(
	400,
	$audit_to_array( $engine->apply_rules( array( price_rule( '+10% cart formula', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_CART ) ), $mixed_context )->audit ),
	900
);
rules_smoke_assert( str_contains( implode( "\n", $cart_lines ), 'увеличить на 10% от всей корзины' ), 'Formula formatter must render percent_of_cart label.' );
$physical_lines = $formatter->lines(
	400,
	$audit_to_array( $engine->apply_rules( array( price_rule( '+10% physical formula', RuleOperationTypes::INCREASE, 10, RuleOperationBases::PERCENT_OF_ORDER ) ), $mixed_context )->audit ),
	700
);
rules_smoke_assert( str_contains( implode( "\n", $physical_lines ), 'увеличить на 10% от физ. товаров' ) && ! str_contains( implode( "\n", $physical_lines ), '% от заказа' ), 'Formula formatter must relabel percent_of_order without changing its persisted key.' );

$result = $engine->apply_rules( array( price_rule( '*2', RuleOperationTypes::MULTIPLY, 2 ) ), rules_context() );
rules_smoke_assert( 90000 === $result->final_price?->get_kopecks(), 'multiply must produce 900 RUB.' );

$result = $engine->apply_rules( array( price_rule( '/2', RuleOperationTypes::DIVIDE, 2 ) ), rules_context() );
rules_smoke_assert( 22500 === $result->final_price?->get_kopecks(), 'divide must produce 225 RUB.' );

$comma_rule = Rule::from_array( array_merge( price_rule( '*1,5', RuleOperationTypes::MULTIPLY, 1.5 )->to_array(), array( 'operation_value' => '1,5' ) ) );
rules_smoke_assert( 1.5 === $comma_rule->operation_value, 'Comma decimal normalization must hydrate to decimal value.' );

$comment_rule = new Rule( null, 'Add comment', true, 10, 'default', '', RuleActionTypes::ADD_COMMENT, RuleOperationTypes::EQUALS, 0, RuleOperationBases::RUBLES, false, false, array(), array( 1 => 'and', 2 => 'and', 3 => 'and' ), 'Позвонить за час' );
$result = $engine->apply_rules( array( $comment_rule ), rules_context() );
rules_smoke_assert( array( 'Позвонить за час' ) === $result->comments, 'add_comment must add operation_text to RuleEngineResult comments.' );
$rate_builder = new RuleAppliedRateBuilder( $engine );
$rate = new DeliveryRate( 'rate-1', 'demo', 'Demo', 'svc', 'Service', 'tariff', 'Tariff', 'courier', 'Demo delivery', Money::from_rubles( 450 ), null, null, DateRange::single( 5 ) );
$built = $rate_builder->apply( $rate, rules_context(), array( $comment_rule ) );
rules_smoke_assert( in_array( 'Позвонить за час', $built['rate']->comments, true ), 'Runtime rate builder must pass rule comments to delivery rate comments.' );

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
	),
	array( 1 => 'and', 2 => 'and', 3 => 'and' ),
	'',
	'condition_1_or_2_or_3'
);
$result = $engine->apply_rules( array( $group_rule ), rules_context() );
rules_smoke_assert( 50000 === $result->final_price?->get_kopecks(), 'Explicit OR expression must combine groups when selected.' );

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
$context_with_range = RuleEvaluationContext::from_array( array_merge( rules_context()->to_array(), array( 'meta' => array( 'original_delivery_min_days' => 5, 'original_delivery_max_days' => 6 ) ) ) );
$result = $engine->apply_rules( array( $delivery_days_rule ), $context_with_range );
rules_smoke_assert( 7 === $result->final_delivery_days?->min_days && 8 === $result->final_delivery_days?->max_days, 'Delivery day rules must preserve and shift ranges.' );

$calendar_increase_rule = Rule::from_array(
	array(
		'name'            => 'Calendar days +2',
		'target_type'     => 'default',
		'action_type'     => RuleActionTypes::CHANGE_DELIVERY_DAYS,
		'operation_type'  => RuleOperationTypes::INCREASE,
		'operation_value' => 2,
		'operation_base'  => RuleOperationBases::CALENDAR_DAYS,
	)
);
$result = $engine->apply_rules( array( $calendar_increase_rule ), RuleEvaluationContext::from_array( array_merge( rules_context()->to_array(), array( 'meta' => array( 'original_delivery_days' => 1 ) ) ) ) );
rules_smoke_assert( 3 === $result->final_delivery_days?->min_days && 3 === $result->final_delivery_days?->max_days && DateRange::UNIT_CALENDAR_DAYS === $result->final_delivery_days?->unit, '1 calendar day +2 must become 3 calendar days.' );
$result = $engine->apply_rules( array( $calendar_increase_rule ), RuleEvaluationContext::from_array( array_merge( rules_context()->to_array(), array( 'meta' => array( 'original_delivery_min_days' => 1, 'original_delivery_max_days' => 3 ) ) ) ) );
rules_smoke_assert( 3 === $result->final_delivery_days?->min_days && 5 === $result->final_delivery_days?->max_days && DateRange::UNIT_CALENDAR_DAYS === $result->final_delivery_days?->unit, '1-3 calendar days +2 must become 3-5 calendar days.' );
$result = $engine->apply_rules( array( $delivery_days_rule ), RuleEvaluationContext::from_array( array_merge( rules_context()->to_array(), array( 'meta' => array( 'original_delivery_min_days' => 1, 'original_delivery_max_days' => 3 ) ) ) ) );
rules_smoke_assert( 3 === $result->final_delivery_days?->min_days && 5 === $result->final_delivery_days?->max_days && DateRange::UNIT_BUSINESS_DAYS === $result->final_delivery_days?->unit, '1-3 business days +2 must become 3-5 business days.' );

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
	array( 1 => 'and', 2 => 'and', 3 => 'and' ),
	'',
	'condition_1_or_2_or_3'
);
$result = $engine->apply_rules( array( $groups_or_rule ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'Explicit condition_group_expression can combine groups via OR.' );
rules_smoke_assert( 'condition_1' === Rule::from_array( array( 'name' => 'Default expression' ) )->condition_group_expression, 'Default condition_group_expression must be condition_1.' );

rules_smoke_assert( Rule::DEFAULT_GROUP_EXPRESSION === Rule::normalized_group_expression( 'bad-expression' ), 'Invalid group expression must normalize to default.' );

$expression_conditions = array(
	new RuleCondition( null, null, 1, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'RU' ),
	new RuleCondition( null, null, 2, RuleConditionTypes::PAYMENT_METHOD, RuleOperators::EQ, 'cash' ),
	new RuleCondition( null, null, 3, RuleConditionTypes::DELIVERY_TYPE, RuleOperators::EQ, 'courier' ),
);
$expression_rule = static function ( string $expression, ?array $conditions = null ) use ( $expression_conditions ): Rule {
	return new Rule(
		null,
		'Expression ' . $expression,
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
		$conditions ?? $expression_conditions,
		array( 1 => 'and', 2 => 'and', 3 => 'and' ),
		'',
		$expression
	);
};

$result = $engine->apply_rules( array( $expression_rule( 'condition_1' ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'condition_1 must match only group 1.' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_2' ) ), rules_context() );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'condition_2 must be false when group 2 is false.' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_1_and_2' ) ), rules_context() );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'condition_1_and_2 must require both groups.' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_1_or_2' ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'condition_1_or_2 must accept either group.' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_1_and_2_or_3' ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'condition_1_and_2_or_3 must evaluate as (1 AND 2) OR 3.' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_1_or_2_and_3' ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'condition_1_or_2_and_3 must evaluate as 1 OR (2 AND 3).' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_1_and_2_and_3' ) ), rules_context() );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'condition_1_and_2_and_3 must require all groups.' );
$result = $engine->apply_rules( array( $expression_rule( 'condition_1_and_2', array( new RuleCondition( null, null, 1, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'RU' ) ) ) ), rules_context() );
rules_smoke_assert( 45000 === $result->final_price?->get_kopecks(), 'Empty group used in expression must count false.' );

$result = $engine->apply_rules( array( Rule::from_array( array_merge( price_rule( 'No conditions', RuleOperationTypes::DECREASE, 10 )->to_array(), array( 'conditions' => array() ) ) ) ), rules_context() );
rules_smoke_assert( 44000 === $result->final_price?->get_kopecks(), 'Rules without conditions must still apply.' );

echo "Rules smoke test passed.\n";
