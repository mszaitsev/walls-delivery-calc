<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$GLOBALS['wdc_order_session'] = new class {
	public array $data = array();
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
};
$GLOBALS['wdc_order_wc'] = new class {
	public mixed $session;
	public function __construct() { $this->session = $GLOBALS['wdc_order_session']; }
};

function order_meta_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function WC(): object { return $GLOBALS['wdc_order_wc']; }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function current_user_can( string $capability ): bool { return 'manage_woocommerce' === $capability; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }

function wdc_order_meta_real_rule_audit( float $base_price_rub = 3810.06 ): array {
	$engine = new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) );
	$context = new RuleEvaluationContext(
		Money::from_rubles( 5000 ),
		Money::from_rubles( $base_price_rub ),
		new Package( array(), Money::from_rubles( 5000 ), Money::from_rubles( 5000 ), 1000, 0, 1000 ),
		new Address( country_code: 'PL', country_name: 'Польша' ),
		DeliveryType::PICKUP,
		'card',
		'2026-05-26'
	);
	$result = $engine->apply_rules(
		array(
			new Rule( 101, 'Множитель', true, 10, 'service', 'russian_post_worldwide_parcel', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::MULTIPLY, 1.2, RuleOperationBases::RUBLES, false, false ),
			new Rule( 102, 'Делитель', true, 20, 'service', 'russian_post_worldwide_parcel', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DIVIDE, 0.89, RuleOperationBases::RUBLES, false, false ),
			new Rule( 103, 'Фикс. обработка', true, 30, 'service', 'russian_post_worldwide_parcel', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 200, RuleOperationBases::RUBLES, false, false ),
		),
		$context
	);

	$audit = array_map( static fn ( object $entry ): array => method_exists( $entry, 'to_array' ) ? $entry->to_array() : array(), $result->audit );
	foreach ( $audit as $entry ) {
		foreach ( array( 'rule_name', 'action_type', 'operation', 'operation_value', 'operation_base', 'before_value', 'after_value', 'applied' ) as $key ) {
			order_meta_smoke_assert( array_key_exists( $key, $entry ), 'Real RuleEngine audit must contain ' . $key . '.' );
		}
	}

	$formula = ( new RuleFormulaFormatter() )->lines( $base_price_rub, $audit, $result->final_price->get_rubles() );
	order_meta_smoke_assert( (bool) preg_grep( '/Множитель/u', $formula ), 'Formula must include multiply rule name from real audit.' );
	order_meta_smoke_assert( (bool) preg_grep( '/Делитель/u', $formula ), 'Formula must include divide rule name from real audit.' );
	order_meta_smoke_assert( (bool) preg_grep( '/Фикс\\. обработка/u', $formula ), 'Formula must include fixed increase rule name from real audit.' );
	order_meta_smoke_assert( (bool) preg_grep( '/умножить на 1\\.2/u', $formula ), 'Formula must render multiply value from real audit.' );
	order_meta_smoke_assert( (bool) preg_grep( '/разделить на 0\\.89/u', $formula ), 'Formula must render divide value from real audit.' );
	order_meta_smoke_assert( (bool) preg_grep( '/увеличить на 200 руб\\./u', $formula ), 'Formula must render fixed ruble increase from real audit.' );

	return $audit;
}

final class WdcOrderMetaSmokeOrder {
	public array $meta = array();
	public array $shipping = array();

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function set_shipping_address_1( string $value ): void { $this->shipping['address_1'] = $value; }
	public function set_shipping_address_2( string $value ): void { $this->shipping['address_2'] = $value; }
	public function set_shipping_city( string $value ): void { $this->shipping['city'] = $value; }
	public function set_shipping_postcode( string $value ): void { $this->shipping['postcode'] = $value; }
	public function set_shipping_country( string $value ): void { $this->shipping['country'] = $value; }
	public function get_shipping_city(): string { return (string) ( $this->shipping['city'] ?? '' ); }
}

final class WdcOrderMetaSmokeShippingItem {
	public array $meta = array();
	public string $method_title = '';

	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function set_method_title( string $title ): void {
		$this->method_title = $title;
	}
}

function wdc_order_meta_rate( array $overrides = array() ): array {
	$real_audit = wdc_order_meta_real_rule_audit();
	$rate_meta = array_merge(
		array(
			'api_base_price_rub' => 3810.06,
			'api_price_has_vat' => true,
			'api_price_with_vat_rub' => 3810.06,
			'vat_rate' => 0.2,
			'request_params' => array( 'object' => 4031, 'country-to' => 616, 'weight' => 1150 ),
			'cache_hit' => false,
			'http_code' => 200,
			'raw_response' => array( 'large' => str_repeat( 'x', 128 ) ),
			'country_mapping' => array(
				'country_code' => 'PL',
				'country_name' => 'Польша',
				'carrier_country_id' => '616',
			),
			'products_weight_g' => 1000,
			'packaging_weight_g' => 150,
			'package_weight_with_packaging_g' => 1150,
			'include_packaging_weight' => true,
			'packaging_weight_mode' => 'total_weight',
			'no_pickup_selection' => true,
			'final_price_rub' => 5338.0,
			'rules_audit' => $real_audit,
		),
		$overrides['rate_meta'] ?? array()
	);

	return array_merge(
		array(
			'carrier_key' => 'russian_post',
			'rate_id' => 'russian_post_worldwide_parcel',
			'delivery_type' => 'pickup',
			'service_key' => 'russian_post_worldwide_parcel',
			'service_title' => 'Почта России — международная доставка',
			'rules_source' => 'service',
			'round_up_applied' => true,
			'minimum_price_applied' => false,
			'cost' => '0',
			'comments' => array(),
			'rate_meta' => $rate_meta,
			'fallback_used' => false,
		),
		$overrides
	);
}

$session = new CheckoutSessionManager();
$persister = new OrderShippingMetaPersister( $session );
$rate = wdc_order_meta_rate();
$session->save_rates( array( 'russian_post_worldwide_parcel' => $rate ) );
WC()->session->set( 'chosen_shipping_methods', array( 'russian_post_worldwide_parcel' ) );

$order = new WdcOrderMetaSmokeOrder();
$persister->persist( $order, array() );
$item = new WdcOrderMetaSmokeShippingItem();
foreach ( array( 'carrier_key', 'rate_id', 'delivery_type', 'service_key', 'service_title', 'rules_source', 'round_up_applied', 'no_pickup_selection', 'rate_meta' ) as $technical_key ) {
	$item->add_meta_data( $technical_key, 'auto copied by WooCommerce', true );
}
$persister->persist_shipping_item_meta( $item, 0, array(), $order );

order_meta_smoke_assert( array( 'Способ доставки' => 'международная доставка Почтой России' ) === $item->meta, 'Russian Post visible shipping item meta must only contain delivery method.' );
$visible_blob = wp_json_encode( $item->meta );
foreach ( array( 'carrier_key', 'service_key', 'rules_source', 'no_pickup_selection', 'delivery_type', 'russian_post' ) as $technical ) {
	order_meta_smoke_assert( ! str_contains( (string) $visible_blob, $technical ), 'Technical meta must not be visible in shipping item meta: ' . $technical );
}

$calculation = $order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
order_meta_smoke_assert( is_array( $calculation ) && array() !== $calculation, 'Delivery calculation data must be saved.' );
order_meta_smoke_assert( ! array_key_exists( 'raw_response', $order->meta['_wdc_platform_rate_meta'] ?? array() ) && ! str_contains( wp_json_encode( $calculation ), 'raw_response' ), 'Order calculation data must not store raw API response.' );
order_meta_smoke_assert( 'Почта России — международная доставка' === ( $calculation['service_title'] ?? '' ), 'Calculation data must contain service title.' );
order_meta_smoke_assert( 'PL' === ( $calculation['destination']['country_code'] ?? '' ) && 'Польша' === ( $calculation['destination']['country_name'] ?? '' ), 'Calculation data must contain destination country.' );
order_meta_smoke_assert( 1000 === ( $calculation['package']['products_weight_g'] ?? 0 ) && 150 === ( $calculation['package']['packaging_weight_g'] ?? 0 ) && 1150 === ( $calculation['package']['final_weight_g'] ?? 0 ), 'Calculation data must contain package weights.' );
order_meta_smoke_assert( 3810.06 === ( $calculation['api']['api_base_price_rub'] ?? 0.0 ) && 5338.0 === ( $calculation['result']['final_price_rub'] ?? 0.0 ), 'Calculation data must contain API and final prices.' );
order_meta_smoke_assert( 'service' === ( $calculation['rules']['rules_source'] ?? '' ) && ! empty( $calculation['rules']['applied_rules'] ) && ! empty( $calculation['rules']['formula_visualization'] ), 'Calculation data must contain rules source, audit and formula.' );
order_meta_smoke_assert( (bool) preg_grep( '/Итог: 5 338 руб\\./u', $calculation['rules']['formula_visualization'] ), 'Formula final line must match actual shipping cost.' );
order_meta_smoke_assert( ! (bool) preg_grep( '/Округление вверх → 0 руб\\./u', $calculation['rules']['formula_visualization'] ), 'Formula must not render zero rounding for non-fallback rates.' );
order_meta_smoke_assert( ! isset( $calculation['result']['final_delivery_days_min'], $calculation['result']['final_delivery_days_max'] ), 'Empty Russian Post delivery days must not be saved.' );

$domestic_rate = array(
	'carrier_key' => 'russian_post_domestic',
	'rate_id' => 'russian_post_domestic_pickup',
	'delivery_type' => 'pickup',
	'service_key' => 'russian_post_domestic_pickup',
	'service_title' => 'Почта России — до отделения',
	'tariff_key' => '23030',
	'tariff_title' => 'Посылка онлайн',
	'selected_tariff_object' => '23030',
	'selected_tariff_title' => 'Посылка онлайн',
	'planned_delivery_comment' => '3 дня',
	'delivery_days' => array( 'min_days' => 3, 'max_days' => 3, 'unit' => 'calendar_days' ),
	'cost' => '450',
	'comments' => array(),
	'rate_meta' => array(
		'postcode' => '630099',
		'pay' => 45000,
		'nds' => 0,
		'paynds' => 45000,
		'delivery_min_days' => 1,
		'delivery_max_days' => 1,
		'transtype' => 1,
		'delivery_to' => '630099',
		'items_summary' => array( array( 'name' => 'base', 'serviceon' => 1 ) ),
		'package' => array( 'weight_g' => 1000 ),
		'no_pickup_selection' => true,
		'final_price_rub' => 450.0,
		'rules_audit' => array(),
	),
);
$session->save_rates( array( 'russian_post_domestic_pickup' => $domestic_rate ) );
WC()->session->set( 'chosen_shipping_methods', array( 'russian_post_domestic_pickup' ) );
$domestic_order = new WdcOrderMetaSmokeOrder();
$persister->persist( $domestic_order, array() );
$domestic_item = new WdcOrderMetaSmokeShippingItem();
$persister->persist_shipping_item_meta( $domestic_item, 0, array(), $domestic_order );
$domestic_calculation = $domestic_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
order_meta_smoke_assert( '23030' === ( $domestic_calculation['selected_tariff_object'] ?? '' ) && 'Посылка онлайн' === ( $domestic_calculation['selected_tariff_title'] ?? '' ), 'Domestic order payload must save selected tariff object and title.' );
order_meta_smoke_assert( 3 === ( $domestic_calculation['result']['final_delivery_days_min'] ?? 0 ) && 3 === ( $domestic_calculation['result']['final_delivery_days_max'] ?? 0 ), 'Domestic order payload must save final delivery min/max after rules.' );
order_meta_smoke_assert( in_array( 'Посылка онлайн', $domestic_item->meta, true ), 'Domestic visible shipping item meta must show selected tariff title.' );
order_meta_smoke_assert( in_array( '3 дня', $domestic_item->meta, true ), 'Domestic visible shipping item meta must show formatted delivery days.' );
order_meta_smoke_assert( 'Почта России — до отделения: Посылка онлайн - 3 дня' === $domestic_item->method_title, 'Domestic shipping item method title must include service, selected tariff, and delivery days.' );

ob_start();
( new OrderDeliveryMetabox() )->render( $domestic_order );
$domestic_html = (string) ob_get_clean();
order_meta_smoke_assert( str_contains( $domestic_html, '3 дня' ) && ! str_contains( $domestic_html, '3 дн.' ), 'Domestic order metabox must render formatted Russian delivery days.' );

ob_start();
( new OrderDeliveryMetabox() )->render( $order );
$html = (string) ob_get_clean();
foreach ( array( 'Польша (PL)', 'Вес товаров', '5 338 руб.', 'Базовая цена API', 'Правило &quot;Множитель&quot;', 'Правило &quot;Делитель&quot;', 'Правило &quot;Фикс. обработка&quot;' ) as $needle ) {
	order_meta_smoke_assert( str_contains( $html, $needle ), 'Order metabox must render calculation field: ' . $needle );
}
order_meta_smoke_assert( ! str_contains( $html, '0 дн.' ), 'Order metabox must not render empty delivery days.' );

$fallback_text = 'Наиболее вероятно, мы не сможем доставить посылку в вашу страну.';
$fallback_rate = wdc_order_meta_rate(
	array(
		'rate_id' => 'russian_post_worldwide_parcel:fallback',
		'cost' => '0',
		'rules_source' => 'skipped_fallback',
		'round_up_applied' => false,
		'rate_meta' => array(
			'fallback' => true,
			'terminal_fallback' => true,
			'skip_rules' => true,
			'skip_service_post_processing' => true,
			'fallback_reason' => 'unsupported_country_PL',
			'fallback_text' => $fallback_text,
			'rules_audit' => array(),
		),
	)
);
$session->save_rates( array( 'russian_post_worldwide_parcel:fallback' => $fallback_rate ) );
WC()->session->set( 'chosen_shipping_methods', array( 'russian_post_worldwide_parcel:fallback' ) );
$fallback_order = new WdcOrderMetaSmokeOrder();
$persister->persist( $fallback_order, array() );
$fallback_item = new WdcOrderMetaSmokeShippingItem();
$persister->persist_shipping_item_meta( $fallback_item, 0, array(), $fallback_order );
$fallback_calculation = $fallback_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
order_meta_smoke_assert( array( 'Способ доставки' => 'международная доставка Почтой России' ) === $fallback_item->meta, 'Fallback visible shipping item meta must stay clean.' );
order_meta_smoke_assert( 0.0 === ( $fallback_calculation['result']['final_price_rub'] ?? -1 ) && $fallback_text === ( $fallback_calculation['result']['fallback_text'] ?? '' ), 'Fallback calculation data must save zero final price and fallback text.' );
order_meta_smoke_assert( array() === ( $fallback_calculation['rules']['formula_visualization'] ?? array() ), 'Terminal fallback must not save rules formula.' );

$session->save_pickup_selection(
	array(
		'carrier_key' => 'demo',
		'rate_id' => 'demo:pickup',
		'point_code' => 'PVZ-1',
		'point_name' => 'ПВЗ тест',
		'point_address' => 'Тестовая, 1',
	)
);
$session->save_rates(
	array(
		'demo:pickup' => array(
			'carrier_key' => 'demo',
			'rate_id' => 'demo:pickup',
			'delivery_type' => 'pickup',
			'service_key' => 'demo_pickup',
			'service_title' => 'Demo pickup',
			'cost' => '10',
			'rate_meta' => array(),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:demo:pickup' ) );
$pickup_order = new WdcOrderMetaSmokeOrder();
$persister->persist( $pickup_order, array() );
$pickup_calculation = $pickup_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
order_meta_smoke_assert( 'PVZ-1' === ( $pickup_calculation['pickup']['point_code'] ?? '' ), 'Future pickup carriers must still be able to store selected pickup data in calculation data.' );

echo "Order delivery calculation meta smoke OK\n";
