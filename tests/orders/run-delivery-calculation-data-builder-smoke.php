<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function builder_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function builder_smoke_rate( array $overrides = array() ): array {
	$rate_meta = array_merge(
		array(
			'api_base_price_rub' => 100.0,
			'final_price_rub' => 100.0,
			'delivery_min_days' => 7,
			'delivery_max_days' => 9,
			'carrier_delivery_days_original' => array( 'min_days' => 7, 'max_days' => 9, 'unit' => 'calendar_days' ),
			'shop_processing_working_days' => 2,
			'shop_processing_calendar_days' => 3,
			'carrier_days_are_working' => true,
			'carrier_delivery_calendar_days' => array( 'min_days' => 9, 'max_days' => 10, 'unit' => 'calendar_days' ),
			'total_calendar_days' => array( 'min_days' => 12, 'max_days' => 13, 'unit' => 'calendar_days' ),
			'rules_audit' => array(),
		),
		is_array( $overrides['rate_meta'] ?? null ) ? $overrides['rate_meta'] : array()
	);

	$rate = array_merge(
		array(
			'carrier_key' => 'demo',
			'service_key' => 'demo',
			'service_title' => 'Demo',
			'rate_id' => 'demo:pickup',
			'selected_tariff_object' => 'base',
			'selected_tariff_title' => 'Base',
			'delivery_type' => 'pickup',
			'cost' => 100.0,
			'delivery_days' => array( 'min_days' => 12, 'max_days' => 13, 'unit' => 'calendar_days' ),
			'planned_delivery_date' => '2026-07-27',
			'planned_delivery_comment' => 'Доставка планируется* с 27 июля (понедельник).',
			'rate_meta' => $rate_meta,
			'round_up_applied' => false,
			'minimum_price_applied' => false,
		),
		$overrides
	);
	$rate['rate_meta'] = $rate_meta;

	return $rate;
}

$builder = new DeliveryCalculationDataBuilder( new RuleFormulaFormatter() );
$rate = builder_smoke_rate();
$checkout = $builder->build(
	$rate,
	array(
		'destination' => array( 'city_display_name' => 'Москва' ),
		'pickup' => array( 'point_code' => 'CHK' ),
	)
);
$admin = $builder->build(
	$rate,
	array(
		'destination' => array( 'city_display_name' => 'Санкт-Петербург' ),
		'pickup' => array( 'point_code' => 'ADM' ),
	)
);
builder_smoke_assert( $checkout['api'] === $admin['api'] && $checkout['rules'] === $admin['rules'] && $checkout['result'] === $admin['result'], 'Builder must keep api/rules/result parity for checkout and admin contexts.' );
builder_smoke_assert( $checkout['package'] === $admin['package'], 'Builder must keep package parity for checkout and admin contexts.' );

$zero_package = $builder->build(
	array(
		'rate_id' => 'test',
		'cost' => 100,
		'rate_meta' => array(
			'products_weight_g' => 0,
			'packaging_weight_g' => 0,
			'package_weight_with_packaging_g' => 0,
			'include_packaging_weight' => false,
			'package' => array(
				'weight_g' => 0,
				'packaging_weight_g' => 0,
				'include_packaging_weight' => false,
			),
		),
	)
);
$package = $zero_package['package'] ?? array();
foreach ( array( 'products_weight_g', 'packaging_weight_g', 'final_weight_g', 'include_packaging_weight' ) as $key ) {
	builder_smoke_assert( array_key_exists( $key, $package ), 'Builder package data must keep key: ' . $key );
}
builder_smoke_assert( 0 === $package['products_weight_g'], 'Builder package data must preserve zero products weight.' );
builder_smoke_assert( 0 === $package['packaging_weight_g'], 'Builder package data must preserve zero packaging weight.' );
builder_smoke_assert( 0 === $package['final_weight_g'], 'Builder package data must preserve zero final weight.' );
builder_smoke_assert( false === $package['include_packaging_weight'], 'Builder package data must preserve explicit false include_packaging_weight.' );

$non_zero_package = $builder->build(
	array(
		'rate_id' => 'test',
		'cost' => 100,
		'rate_meta' => array(
			'products_weight_g' => 1200,
			'packaging_weight_g' => 150,
			'package_weight_with_packaging_g' => 1350,
			'include_packaging_weight' => true,
		),
	)
)['package'] ?? array();
builder_smoke_assert( 1200 === $non_zero_package['products_weight_g'] && 150 === $non_zero_package['packaging_weight_g'] && 1350 === $non_zero_package['final_weight_g'] && true === $non_zero_package['include_packaging_weight'], 'Builder package data must preserve non-zero package values.' );

$formula = $checkout['rules']['formula_visualization'] ?? array();
foreach ( array( 'Базовый срок API: 7-9 дней', 'Время обработки магазином: 3 дня', 'Доставка: рабочие в календарные 7-9 → 9-10 дней', 'Итог: 12-13 дней' ) as $line ) {
	builder_smoke_assert( in_array( $line, $formula, true ), 'Builder lead-time audit must include line: ' . $line );
}

$calendar_days = $builder->build(
	builder_smoke_rate(
		array(
			'delivery_days' => array( 'min_days' => 10, 'max_days' => 12, 'unit' => 'calendar_days' ),
			'rate_meta' => array(
				'carrier_days_are_working' => false,
				'carrier_delivery_calendar_days' => array( 'min_days' => 7, 'max_days' => 9, 'unit' => 'calendar_days' ),
				'total_calendar_days' => array( 'min_days' => 10, 'max_days' => 12, 'unit' => 'calendar_days' ),
			),
		)
	)
);
builder_smoke_assert( ! (bool) preg_grep( '/Доставка: рабочие в календарные/u', $calendar_days['rules']['formula_visualization'] ?? array() ), 'Builder must not render conversion line when carrier days are calendar days.' );

$zero_processing = $builder->build(
	builder_smoke_rate(
		array(
			'delivery_days' => array( 'min_days' => 9, 'max_days' => 10, 'unit' => 'calendar_days' ),
			'rate_meta' => array(
				'shop_processing_working_days' => 0,
				'shop_processing_calendar_days' => 0,
				'total_calendar_days' => array( 'min_days' => 9, 'max_days' => 10, 'unit' => 'calendar_days' ),
			),
		)
	)
);
builder_smoke_assert( ! (bool) preg_grep( '/Время обработки магазином/u', $zero_processing['rules']['formula_visualization'] ?? array() ), 'Builder must not render processing line when processing is zero.' );

$rules_changed = $builder->build(
	builder_smoke_rate(
		array(
			'delivery_days' => array( 'min_days' => 14, 'max_days' => 15, 'unit' => 'calendar_days' ),
		)
	)
);
builder_smoke_assert( in_array( 'Итог: 14-15 дней', $rules_changed['rules']['formula_visualization'] ?? array(), true ) && ! in_array( 'Итог: 12-13 дней', $rules_changed['rules']['formula_visualization'] ?? array(), true ), 'Builder final lead-time audit must use final delivery_days after rules.' );

$no_price_formula = $builder->build(
	builder_smoke_rate(
		array(
			'round_up_applied' => false,
			'minimum_price_applied' => false,
			'rate_meta' => array( 'rules_audit' => array() ),
		)
	)
);
builder_smoke_assert( in_array( 'Базовый срок API: 7-9 дней', $no_price_formula['rules']['formula_visualization'] ?? array(), true ), 'Builder must keep lead-time audit when price formula is absent.' );

$carrier_specific = $builder->build(
	builder_smoke_rate(
		array(
			'carrier_key' => 'dpd',
			'rate_meta' => array(
				'location' => array( 'cdek_to_city_code' => 270 ),
				'dpd_service_code' => 'PCL',
				'dpd_sender_city_id' => '1',
				'dpd_receiver_city_id' => '2',
				'dpd_tariff_method' => 'getServiceCostByParcels3',
			),
		)
	)
);
builder_smoke_assert( 270 === (int) ( $carrier_specific['api']['cdek_to_city_code'] ?? 0 ) && 'PCL' === (string) ( $carrier_specific['api']['dpd_service_code'] ?? '' ) && 'getServiceCostByParcels3' === (string) ( $carrier_specific['api']['dpd_tariff_method'] ?? '' ), 'Builder must preserve representative CDEK/DPD calculation fields.' );

$pek_adjusted = $builder->build(
	array(
		'carrier_key' => 'pek',
		'service_key' => 'pek',
		'service_title' => 'ПЭК',
		'rate_id' => 'pek:pickup',
		'selected_tariff_object' => 'pek_ltl_pickup',
		'selected_tariff_title' => 'До терминала',
		'delivery_type' => 'pickup',
		'cost' => '1100',
		'rate_meta' => array(
			'api_base_price_rub' => 1017.92,
			'pek_carrier_base_price_rub' => 927.92,
			'pek_carrier_price_kopecks' => 92792,
			'pek_bag_surcharge_kopecks' => 7000,
			'pek_sealing_surcharge_kopecks' => 2000,
			'pek_light_cargo_surcharge_kopecks' => 9000,
			'final_price_rub' => 1100,
		),
	)
);
$pek_formula = $pek_adjusted['rules']['formula_visualization'] ?? array();
builder_smoke_assert( 1017.92 === (float) ( $pek_adjusted['api']['api_base_price_rub'] ?? 0 ) && 927.92 === (float) ( $pek_adjusted['api']['pek_carrier_base_price_rub'] ?? 0 ) && 92792 === (int) ( $pek_adjusted['api']['pek_carrier_price_kopecks'] ?? 0 ), 'PEK calculation data must keep adjusted API base and pure carrier cost separately.' );
builder_smoke_assert( abs( 82.08 - (float) ( $pek_adjusted['rules']['price_delta_rub'] ?? 0 ) ) < 0.0001, 'PEK store surcharges must not be counted as Rule Engine price delta.' );
builder_smoke_assert( in_array( 'Добавлен мешок и пломбировка', $pek_formula, true ) && ! in_array( 'Добавлен мешок и пломбировка', $pek_adjusted['rules']['applied_rules'] ?? array(), true ), 'PEK light-cargo surcharge note must appear in formula, not applied_rules.' );

$pek_no_rules = $builder->build(
	array(
		'carrier_key' => 'pek',
		'service_key' => 'pek',
		'service_title' => 'ПЭК',
		'rate_id' => 'pek:pickup',
		'delivery_type' => 'pickup',
		'cost' => '1017.92',
		'rate_meta' => array(
			'api_base_price_rub' => 1017.92,
			'final_price_rub' => 1017.92,
			'pek_bag_surcharge_kopecks' => 7000,
			'pek_sealing_surcharge_kopecks' => 2000,
		),
	)
);
$pek_no_rules_formula = $pek_no_rules['rules']['formula_visualization'] ?? array();
builder_smoke_assert( in_array( 'Базовая цена API: 1 017.92 руб.', $pek_no_rules_formula, true ) && in_array( 'Добавлен мешок и пломбировка', $pek_no_rules_formula, true ) && in_array( 'Итог: 1 017.92 руб.', $pek_no_rules_formula, true ), 'PEK surcharge note must create a formula even without regular rules, round, or minimum.' );

foreach ( array(
	array( 7000, 2000, 'Добавлен мешок и пломбировка' ),
	array( 7000, 0, 'Добавлен мешок' ),
	array( 0, 2000, 'Добавлена пломбировка' ),
) as $case ) {
	$result = $builder->build(
		array(
			'carrier_key' => 'pek',
			'cost' => '100',
			'rate_meta' => array(
				'api_base_price_rub' => 100.0,
				'final_price_rub' => 100.0,
				'pek_bag_surcharge_kopecks' => $case[0],
				'pek_sealing_surcharge_kopecks' => $case[1],
			),
		)
	);
	builder_smoke_assert( in_array( $case[2], $result['rules']['formula_visualization'] ?? array(), true ), 'Builder must render PEK surcharge comment: ' . $case[2] );
}
$pek_zero_surcharge = $builder->build(
	array(
		'carrier_key' => 'pek',
		'cost' => '100',
		'rate_meta' => array(
			'api_base_price_rub' => 100.0,
			'final_price_rub' => 100.0,
			'pek_bag_surcharge_kopecks' => 0,
			'pek_sealing_surcharge_kopecks' => 0,
		),
	)
);
builder_smoke_assert( array() === ( $pek_zero_surcharge['rules']['formula_visualization'] ?? array() ), 'Builder must not render PEK surcharge comment when both configurable surcharges are zero.' );

echo "Delivery calculation data builder smoke OK\n";
