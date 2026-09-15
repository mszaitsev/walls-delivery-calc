<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		unset( $key );
		return $default;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['wdc_guard_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$priorities = $GLOBALS['wdc_guard_hooks'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				list( $callback, $accepted_args ) = $registered;
				$callback( ...array_slice( $args, 0, $accepted_args ) );
			}
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
	}
}

final class WdcGuardSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	/** @return array<string,mixed> */
	public function get_session_data(): array {
		return $this->data;
	}

	public function __unset( string $key ): void {
		unset( $this->data[ $key ] );
	}
}

final class WdcGuardSmokeWooCommerce {
	public WdcGuardSmokeSession $session;

	public function __construct() {
		$this->session = new WdcGuardSmokeSession();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcGuardSmokeWooCommerce {
		static $wc = null;
		$wc ??= new WdcGuardSmokeWooCommerce();
		return $wc;
	}
}

final class WdcGuardSmokeErrors {
	/** @var array<string,string> */
	public array $errors = array();

	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
	}
}

final class WdcGuardSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	public string $shipping_address_1 = '';
	public int $save_count = 0;

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function set_shipping_country( string $value ): void {}
	public function set_shipping_state( string $value ): void {}
	public function set_shipping_city( string $value ): void {}
	public function set_shipping_postcode( string $value ): void {}
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void {}
	public function save(): int {
		$this->save_count++;
		return 87291;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\CurrentWdcRateResolver;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;

function checkout_guard_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function checkout_guard_yandex_rate( array $overrides = array() ): array {
	return array_replace_recursive(
		array(
			'carrier_key' => 'yandex_delivery',
			'service_key' => 'yandex_delivery',
			'rate_id' => 'yandex_pickup',
			'delivery_type' => 'pickup',
			'requires_pickup_point' => true,
			'no_pickup_selection' => false,
			'fixed_pickup_point_snapshot' => array(),
			'cost' => 385,
			'rate_meta' => array(
				'pickup_source' => 'representative',
				'destination_platform_station_id' => '0196abc9abe4725681505d6e26f12ba6',
			),
		),
		$overrides
	);
}

/** @return array{0:CheckoutSessionManager,1:CurrentWdcRateResolver,2:CheckoutValidation,3:OrderShippingMetaPersister} */
function checkout_guard_services(): array {
	$session = new CheckoutSessionManager();
	$resolver = new CurrentWdcRateResolver( $session );
	$validation = new CheckoutValidation( $session, null, null, null, null, null, $resolver );
	$persister = new OrderShippingMetaPersister(
		$session,
		new DeliveryDateFormatter(),
		new DeliveryCalculationDataBuilder( new RuleFormulaFormatter() ),
		null,
		null,
		$resolver
	);
	return array( $session, $resolver, $validation, $persister );
}

function checkout_guard_set_destination( CheckoutSessionManager $session, int $location_id ): void {
	$session->save_city_context(
		array(
			'country_code' => 'RU',
			'location_id' => $location_id,
			'city_name' => 'г Челябинск',
			'region_name' => 'Челябинская область',
			'postcode' => '454000',
		)
	);
}

function checkout_guard_expect_block( CheckoutValidation $validation ): void {
	try {
		$validation->guard_order_creation( new WdcGuardSmokeOrder(), array() );
	} catch ( Exception $exception ) {
		checkout_guard_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === $exception->getMessage(), 'Guard must reuse the Yandex pickup validation message.' );
		return;
	}

	throw new RuntimeException( 'Pickup order creation must be blocked.' );
}

// Direct #87291 fixture: representative quote station is not a customer selection.
WC()->session = new WdcGuardSmokeSession();
list( $session, $resolver, $validation ) = checkout_guard_services();
checkout_guard_set_destination( $session, 146136 );
$session->save_rates( array( 'yandex_pickup' => checkout_guard_yandex_rate() ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_pickup' ) );
checkout_guard_assert( 'yandex_pickup' === (string) ( $resolver->resolve()['rate_id'] ?? '' ), 'Shared resolver must select the same current Yandex rate that persistence will use.' );
checkout_guard_expect_block( $validation );

// Deterministic race: POST state A validates, then mutable session state changes to B.
WC()->session = new WdcGuardSmokeSession();
$GLOBALS['wdc_guard_hooks'] = array();
list( $session, $resolver, $validation, $persister ) = checkout_guard_services();
checkout_guard_set_destination( $session, 146136 );
$session->save_rates(
	array(
		'yandex_courier' => array(
			'carrier_key' => 'yandex_delivery',
			'service_key' => 'yandex_delivery',
			'rate_id' => 'yandex_courier',
			'delivery_type' => 'courier',
			'requires_pickup_point' => false,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_courier' ) );
$errors = new WdcGuardSmokeErrors();
$validation->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:yandex_courier' ),
		'shipping_city' => 'г Челябинск',
		'billing_address_1' => 'ул. Тестовая, д. 1',
	),
	$errors
);
checkout_guard_assert( array() === $errors->errors, 'POST state A must pass normal checkout validation before the session mutation.' );

$session->save_rates( array( 'yandex_pickup' => checkout_guard_yandex_rate() ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_pickup' ) );
$validation->register();
$persister->register();
checkout_guard_assert( isset( $GLOBALS['wdc_guard_hooks']['woocommerce_checkout_create_order'][19] ), 'Final guard must run at priority 19.' );
checkout_guard_assert( isset( $GLOBALS['wdc_guard_hooks']['woocommerce_checkout_create_order'][20] ), 'Order metadata persistence must remain at priority 20.' );
$race_order = new WdcGuardSmokeOrder();
try {
	do_action( 'woocommerce_checkout_create_order', $race_order, array() );
	$race_order->save();
	throw new RuntimeException( 'Mutated pickup state B must abort the create-order hook chain.' );
} catch ( Exception $exception ) {
	checkout_guard_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === $exception->getMessage(), 'Race guard must return the carrier-specific checkout error.' );
}
checkout_guard_assert( array() === $race_order->meta, 'Exception must stop the hook chain before OrderShippingMetaPersister writes invalid pickup metadata.' );
checkout_guard_assert( 0 === $race_order->save_count, 'WooCommerce order save must remain unreachable after the create-order guard exception.' );

// A stale selection from another destination must not satisfy the guard.
WC()->session = new WdcGuardSmokeSession();
list( $session, , $validation ) = checkout_guard_services();
checkout_guard_set_destination( $session, 999999 );
$session->save_pickup_selection_for_family(
	'yandex_delivery:pickup',
	array(
		'carrier_key' => 'yandex_delivery',
		'service_key' => 'yandex_delivery',
		'pickup_family' => 'yandex_delivery:pickup',
		'rate_id' => 'yandex_pickup',
		'point_code' => 'STALE-STATION',
		'platform_station_id' => 'STALE-STATION',
	)
);
checkout_guard_set_destination( $session, 146136 );
$session->save_rates( array( 'yandex_pickup' => checkout_guard_yandex_rate() ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_pickup' ) );
checkout_guard_expect_block( $validation );

// A valid current Yandex selection passes and persists the established aliases.
WC()->session = new WdcGuardSmokeSession();
list( $session, , $validation, $persister ) = checkout_guard_services();
checkout_guard_set_destination( $session, 146136 );
$session->save_rates( array( 'yandex_pickup' => checkout_guard_yandex_rate() ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_pickup' ) );
$session->save_pickup_selection_for_family(
	'yandex_delivery:pickup',
	array(
		'carrier_key' => 'yandex_delivery',
		'service_key' => 'yandex_delivery',
		'pickup_family' => 'yandex_delivery:pickup',
		'rate_id' => 'yandex_pickup',
		'point_code' => 'CUSTOMER-STATION',
		'platform_station_id' => 'CUSTOMER-STATION',
		'point_address' => 'г Челябинск, ул. Покупателя, д. 1',
		'snapshot' => array(
			'carrier_key' => 'yandex_delivery',
			'pickup_family' => 'yandex_delivery:pickup',
			'point_code' => 'CUSTOMER-STATION',
			'platform_station_id' => 'CUSTOMER-STATION',
			'point_address' => 'г Челябинск, ул. Покупателя, д. 1',
		),
	)
);
$validation->guard_order_creation( new WdcGuardSmokeOrder(), array() );
$valid_order = new WdcGuardSmokeOrder();
$persister->persist( $valid_order, array() );
checkout_guard_assert( 'CUSTOMER-STATION' === (string) ( $valid_order->meta['_wdc_platform_pickup_code'] ?? '' ), 'Valid selection must persist the platform pickup code.' );
checkout_guard_assert( 'CUSTOMER-STATION' === (string) ( $valid_order->meta['_wdc_pickup_point_code'] ?? '' ), 'Valid selection must persist the canonical pickup point code.' );
checkout_guard_assert( str_contains( (string) ( $valid_order->meta['_wdc_pickup_point_snapshot'] ?? '' ), 'CUSTOMER-STATION' ), 'Valid selection must persist the pickup snapshot.' );
checkout_guard_assert( 'CUSTOMER-STATION' === (string) ( $valid_order->meta['_wdc_yandex_delivery_pickup_platform_station_id'] ?? '' ), 'Valid selection must preserve the Yandex station alias.' );

// Explicit bypass contracts remain unchanged.
foreach (
	array(
		'no-selection flag' => checkout_guard_yandex_rate( array( 'no_pickup_selection' => true ) ),
		'fixed pickup snapshot' => checkout_guard_yandex_rate( array( 'fixed_pickup_point_snapshot' => array( 'point_title' => 'Фиксированный пункт', 'point_address' => 'г Челябинск' ) ) ),
		'pickup without requirement' => checkout_guard_yandex_rate( array( 'requires_pickup_point' => false ) ),
		'non-pickup rate' => checkout_guard_yandex_rate( array( 'delivery_type' => 'courier' ) ),
	) as $label => $rate
) {
	WC()->session = new WdcGuardSmokeSession();
	list( $session, , $validation ) = checkout_guard_services();
	$session->save_rates( array( 'yandex_pickup' => $rate ) );
	WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_pickup' ) );
	$validation->guard_order_creation( new WdcGuardSmokeOrder(), array() );
	checkout_guard_assert( true, 'Guard must preserve ' . $label . '.' );
}

WC()->session = new WdcGuardSmokeSession();
list( , , $validation ) = checkout_guard_services();
WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
$validation->guard_order_creation( new WdcGuardSmokeOrder(), array() );

echo "Checkout order creation pickup guard smoke test passed.\n";
