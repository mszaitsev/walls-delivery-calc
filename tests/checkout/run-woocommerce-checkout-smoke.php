<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wdc_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
		$GLOBALS['wdc_test_options'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . DIRECTORY_SEPARATOR;
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public string $id = '';
		public int $instance_id = 0;
		public string $method_title = '';
		public string $method_description = '';
		public string $enabled = 'yes';
		public string $title = '';
		/** @var array<int,string> */
		public array $supports = array();
		/** @var array<int,array<string,mixed>> */
		public array $rates = array();

		public function add_rate( array $rate ): void {
			$this->rates[] = $rate;
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;

		public function get_results( string $query, mixed $output = null ): array {
			return array();
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			return null;
		}

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->insert_id++;

			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			return true;
		}

		public function delete( string $table, array $where, array $where_format = array() ): bool {
			return true;
		}

		public function query( string $query ): bool {
			return true;
		}
	}
}

$GLOBALS['wpdb'] = new wpdb();

final class WdcSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}
}

final class WdcSmokeWooCommerce {
	public WdcSmokeSession $session;

	public function __construct() {
		$this->session = new WdcSmokeSession();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcSmokeWooCommerce {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new WdcSmokeWooCommerce();
		}

		return $wc;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once dirname( __DIR__ ) . '/fixtures/TestDemoCarrier.php';

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

function wc_checkout_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class WdcSmokeProduct {
	public function get_sku(): string {
		return 'SMOKE-SKU';
	}

	public function get_name(): string {
		return 'Smoke product';
	}

	public function get_weight(): float {
		return 1.25;
	}
}

final class WdcSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}
}

function wc_checkout_smoke_package( string $country = 'RU' ): array {
	return array(
		'destination'     => array(
			'country'   => $country,
			'city'      => 'Moscow',
			'postcode'  => '101000',
			'address_1' => 'Tverskaya',
			'address_2' => '1',
		),
		'contents_cost'   => 1000,
		'contents_weight' => 1.25,
		'contents'        => array(
			array(
				'data'       => new WdcSmokeProduct(),
				'quantity'   => 2,
				'line_total' => 1000,
			),
		),
	);
}

function wc_checkout_smoke_orchestrator(): CheckoutOrchestrator {
	$logger   = new CheckoutLogger();
	$registry = new CarrierRegistry();
	$registry->register( new TestDemoCarrier() );

	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger
	);
}

$mapper  = new WooCommercePackageMapper();
$request = $mapper->map( wc_checkout_smoke_package() );
wc_checkout_smoke_assert( 'RU' === $request->country_code, 'Package mapper must map destination country.' );
wc_checkout_smoke_assert( 'Moscow' === $request->destination->city, 'Package mapper must map destination city.' );
wc_checkout_smoke_assert( 100000 === $request->order_total->get_kopecks(), 'Package mapper must map order total.' );
wc_checkout_smoke_assert( 2 === $request->package->get_total_quantity(), 'Package mapper must map items quantity.' );
wc_checkout_smoke_assert( 2500 === $request->package->get_total_weight_g(), 'Package mapper must map contents weight.' );
wc_checkout_smoke_assert( count( $request->package->items ) === 1, 'Package mapper must keep package items.' );

$orchestrator = wc_checkout_smoke_orchestrator();
$result       = $orchestrator->calculate( $request );
wc_checkout_smoke_assert( count( $result->rates ) >= 2, 'TestDemoCarrier must return checkout rates.' );

$promo = new Rule( null, 'Smoke promo -500', true, 10, 'rate', 'demo', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 500, RuleOperationBases::RUBLES, true, false );
$result = $orchestrator->calculate( $request, array( $promo ), RateSorter::CHEAPEST, false );
wc_checkout_smoke_assert( 100 === $result->rates[0]->price->get_kopecks(), 'Promo rule must floor demo pickup to 1 ruble.' );
wc_checkout_smoke_assert( 35000 === $result->rates[0]->crossed_price?->get_kopecks(), 'Promo rule must expose crossed price.' );

$rate_mapper = new WooCommerceRateMapper();
$mapped      = $rate_mapper->map( $result->rates[0] );
wc_checkout_smoke_assert( isset( $mapped['id'], $mapped['label'], $mapped['cost'], $mapped['meta_data'] ), 'WooCommerceRateMapper output must be valid.' );
wc_checkout_smoke_assert( is_array( $mapped['meta_data']['crossed_price'] ), 'WooCommerceRateMapper must expose crossed price rendering data.' );

$fallback = $orchestrator->calculate( $mapper->map( wc_checkout_smoke_package( 'US' ) ) );
wc_checkout_smoke_assert( $fallback->fallback_used, 'Fallback must appear for unsupported checkout destination.' );
wc_checkout_smoke_assert( 'fallback' === $fallback->rates[0]->carrier_key, 'Fallback checkout rate must be returned.' );

$session = new CheckoutSessionManager();
$session->save_selected_delivery_type( 'pickup' );
$session->save_sort_mode( RateSorter::FASTEST );
wc_checkout_smoke_assert( 'pickup' === $session->selected_delivery_type(), 'Session manager must save delivery type.' );
wc_checkout_smoke_assert( RateSorter::FASTEST === $session->selected_sort_mode(), 'Session manager must save sort mode.' );

$settings = new SettingsRepository();
$settings->set( 'checkout_sort_mode', RateSorter::CHEAPEST );
NewShippingMethod::configure(
	$orchestrator,
	$mapper,
	$rate_mapper,
	$session,
	new RuleRepository(),
	$settings,
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.10.0' ),
	new Logger()
);

$method = new NewShippingMethod();
$method->calculate_shipping( wc_checkout_smoke_package() );
wc_checkout_smoke_assert( count( $method->rates ) > 0, 'New WC shipping method must add rates.' );
wc_checkout_smoke_assert( isset( $method->rates[0]['meta_data']['planned_delivery_comment'] ), 'WC rate must contain planned delivery comment metadata.' );

$stored_rates = $session->rates();
$first_rate   = array_key_first( $stored_rates );
WC()->session->set( 'chosen_shipping_methods', array( $first_rate ) );
$order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
wc_checkout_smoke_assert( isset( $order->meta['_wdc_platform_carrier_key'] ), 'Order meta persister must save carrier key.' );
wc_checkout_smoke_assert( isset( $order->meta['_wdc_platform_rate_id'] ), 'Order meta persister must save rate id.' );
wc_checkout_smoke_assert( array_key_exists( '_wdc_platform_fallback_used', $order->meta ), 'Order meta persister must save fallback flag.' );

$session->save_rates(
	array(
		'demo:pickup'  => array(
			'carrier_key'                  => 'demo',
			'rate_id'                      => 'demo:pickup',
			'delivery_type'                => 'pickup',
			'crossed_price'                => null,
			'planned_delivery_comment'     => 'Pickup comment',
			'comments'                     => array(),
			'fallback_used'                => false,
		),
		'demo:courier' => array(
			'carrier_key'                  => 'demo',
			'rate_id'                      => 'demo:courier',
			'delivery_type'                => 'courier',
			'crossed_price'                => null,
			'planned_delivery_comment'     => 'Courier comment',
			'comments'                     => array(),
			'fallback_used'                => false,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:demo:courier' ) );
$order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
wc_checkout_smoke_assert( 'demo:courier' === ( $order->meta['_wdc_platform_rate_id'] ?? '' ), 'Persister must save selected courier from full WooCommerce rate id.' );
wc_checkout_smoke_assert( 'courier' === ( $order->meta['_wdc_platform_delivery_type'] ?? '' ), 'Persister must not fall back to first pickup rate.' );

WC()->session->set( 'chosen_shipping_methods', array( 'legacy_method:rate' ) );
$order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
wc_checkout_smoke_assert( array() === $order->meta, 'Persister must ignore non-WDC selected shipping methods.' );

echo "WooCommerce checkout smoke test passed.\n";
