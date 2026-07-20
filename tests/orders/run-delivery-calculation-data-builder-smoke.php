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

echo "Delivery calculation data builder smoke OK\n";
