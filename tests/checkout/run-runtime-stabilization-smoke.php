<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_test_options'] = array();
$GLOBALS['wdc_test_actions'] = array();
$GLOBALS['wdc_test_filters'] = array();

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

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['wdc_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['wdc_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, mixed $callback ): void {
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return in_array( $capability, array( 'manage_options', 'manage_woocommerce' ), true );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
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

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . DIRECTORY_SEPARATOR;
	}
}

if ( ! class_exists( 'WC_Settings_API' ) ) {
	class WC_Settings_API {
		/** @var array<string,mixed> */
		public array $settings = array();
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method extends WC_Settings_API {
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
		/** @var array<int,array<string,mixed>> */
		public array $pickup_rows = array();

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( ! preg_match( "/carrier_key = '([^']+)'.*country_code = '([^']+)'/", $query, $matches ) ) {
				return array();
			}

			return array_values(
				array_filter(
					$this->pickup_rows,
					static fn ( array $row ): bool => (bool) ( $row['active'] ?? 0 )
						&& $row['carrier_key'] === $matches[1]
						&& $row['country_code'] === $matches[2]
				)
			);
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( ! preg_match( "/carrier_key = '([^']+)'.*point_code = '([^']+)'/", $query, $matches ) ) {
				return null;
			}

			foreach ( $this->pickup_rows as $row ) {
				if ( $row['carrier_key'] === $matches[1] && $row['point_code'] === $matches[2] ) {
					return $row;
				}
			}

			return null;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->insert_id++;
			$data['id'] = $this->insert_id;
			$this->pickup_rows[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			return true;
		}

		public function get_var( string $query ): int {
			return count( $this->pickup_rows );
		}

		public function query( string $query ): bool {
			return true;
		}
	}
}

$GLOBALS['wpdb'] = new wpdb();

final class WdcRuntimeSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}
}

final class WdcRuntimeSmokeWooCommerce {
	public WdcRuntimeSmokeSession $session;

	public function __construct() {
		$this->session = new WdcRuntimeSmokeSession();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcRuntimeSmokeWooCommerce {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new WdcRuntimeSmokeWooCommerce();
		}
		return $wc;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\DemoCarrier;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDebugPanel;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDeliveryTypeSelector;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutFeatureGate;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSortSelector;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointRenderer;
use WallsShop\WDC\Checkout\WooCommerce\ShippingMethodRegistrar;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\FeatureFlags;
use WallsShop\WDC\Core\Plugin;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\Services\DemoPickupProvider;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;

function runtime_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function runtime_smoke_environment(): PluginEnvironment {
	return new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.12.2' );
}

function runtime_smoke_request( string $delivery_type = '' ): QuoteRequest {
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Новосибирск' ),
		Package::from_items( array(), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ),
		'',
		Money::from_rubles( 1000 ),
		'2026-05-21',
		'' !== $delivery_type ? array( 'delivery_type' => $delivery_type ) : array()
	);
}

function runtime_smoke_orchestrator_with_demo(): CheckoutOrchestrator {
	$logger   = new CheckoutLogger();
	$registry = new CarrierRegistry();
	$registry->register( new DemoCarrier() );

	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger
	);
}

$reflection = new ReflectionClass( NewShippingMethod::class );
$settings_property = $reflection->getProperty( 'settings' );
runtime_smoke_assert( NewShippingMethod::class !== $settings_property->getDeclaringClass()->getName(), 'NewShippingMethod must not redeclare WC_Settings_API::$settings.' );
runtime_smoke_assert( $settings_property->isPublic(), 'Inherited WC_Settings_API::$settings must remain public.' );

$settings = new SettingsRepository();
$flags    = new FeatureFlags();
$gate     = new CheckoutFeatureGate( $flags, $settings );
runtime_smoke_assert( ! $gate->enabled(), 'Feature gate must be false by default.' );
$flags->set_new_shipping_method_enabled( true );
runtime_smoke_assert( $gate->enabled(), 'Feature gate must honor FeatureFlags dev override.' );
$flags->set_new_shipping_method_enabled( false );

$plugin = new Plugin( runtime_smoke_environment() );
$plugin->register();
$container = $plugin->container();

NewShippingMethod::configure(
	$container->get( CheckoutOrchestrator::class ),
	$container->get( WooCommercePackageMapper::class ),
	$container->get( WooCommerceRateMapper::class ),
	$container->get( CheckoutSessionManager::class ),
	$container->get( RuleRepository::class ),
	$settings,
	runtime_smoke_environment(),
	new Logger()
);
$method = new NewShippingMethod();
runtime_smoke_assert( $method instanceof NewShippingMethod, 'NewShippingMethod must instantiate without a settings visibility fatal.' );

runtime_smoke_assert( isset( $GLOBALS['wdc_test_filters']['woocommerce_shipping_methods'] ), 'Shipping method filter must be registered.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_actions']['woocommerce_after_shipping_rate'] ), 'Checkout rate renderer hook must not register while feature gate is false.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_actions']['woocommerce_review_order_before_shipping'] ), 'Address renderer hook must not register while feature gate is false.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_actions']['wp_enqueue_scripts'] ), 'Frontend CSS enqueue hook must not register while feature gate is false.' );

/** @var ShippingMethodRegistrar $registrar */
$registrar = $container->get( ShippingMethodRegistrar::class );
runtime_smoke_assert( array() === $registrar->register_shipping_method( array() ), 'Shipping method registration must be disabled while feature gate is false.' );

$settings->set( 'enable_new_checkout_shipping', true );
runtime_smoke_assert( $gate->enabled(), 'Feature gate must be enabled through SettingsRepository.' );
runtime_smoke_assert( isset( $registrar->register_shipping_method( array() )[ NewShippingMethod::METHOD_ID ] ), 'Shipping method registration must be enabled through settings.' );

$demo_orchestrator = runtime_smoke_orchestrator_with_demo();
$all_rates = $demo_orchestrator->calculate( runtime_smoke_request(), array(), RateSorter::CHEAPEST, false )->rates;
runtime_smoke_assert( 2 === count( $all_rates ), 'Orchestrator must return pickup and courier rates.' );
runtime_smoke_assert( array( DeliveryType::PICKUP, DeliveryType::COURIER ) === array_map( static fn ( object $rate ): string => $rate->delivery_type, $all_rates ), 'Demo rates must include pickup and courier.' );
runtime_smoke_assert( 2 === count( $demo_orchestrator->calculate( runtime_smoke_request( DeliveryType::PICKUP ), array(), RateSorter::CHEAPEST, false )->rates ), 'Selected pickup delivery type must not hide courier.' );
runtime_smoke_assert( 2 === count( $demo_orchestrator->calculate( runtime_smoke_request( DeliveryType::COURIER ), array(), RateSorter::CHEAPEST, false )->rates ), 'Selected courier delivery type must not hide pickup.' );

$fast_rates = $demo_orchestrator->calculate( runtime_smoke_request(), array(), RateSorter::FASTEST, false )->rates;
runtime_smoke_assert( DeliveryType::COURIER === $fast_rates[0]->delivery_type, 'Fastest sort must put courier first.' );
$cheap_rates = $demo_orchestrator->calculate( runtime_smoke_request(), array(), RateSorter::CHEAPEST, false )->rates;
runtime_smoke_assert( DeliveryType::PICKUP === $cheap_rates[0]->delivery_type, 'Cheapest sort must put pickup first.' );

$settings_page = new SettingsAdminPage( $settings );
$sanitized = $settings_page->sanitize_settings(
	array(
		'enable_new_checkout_shipping' => '1',
		'checkout_sort_mode'           => 'unexpected',
		'show_checkout_debug_panel'    => 'on',
	)
);
runtime_smoke_assert( true === $sanitized['enable_new_checkout_shipping'], 'enable_new_checkout_shipping must sanitize to true.' );
runtime_smoke_assert( RateSorter::CHEAPEST === $sanitized['checkout_sort_mode'], 'Invalid checkout_sort_mode must fall back to cheapest.' );
runtime_smoke_assert( true === $sanitized['show_checkout_debug_panel'], 'show_checkout_debug_panel must sanitize to true.' );
runtime_smoke_assert( false === $sanitized['enable_demo_carrier'], 'Missing enable_demo_carrier checkbox must sanitize to false.' );

$GLOBALS['wdc_test_options'] = array(
	'wdc_core_settings' => array(
		'enable_new_checkout_shipping' => true,
		'enable_demo_carrier'          => false,
		'checkout_sort_mode'           => RateSorter::CHEAPEST,
		'show_checkout_debug_panel'    => false,
	),
);
$plugin_without_demo = new Plugin( runtime_smoke_environment() );
$plugin_without_demo->register();
/** @var CarrierRegistry $registry */
$registry = $plugin_without_demo->container()->get( CarrierRegistry::class );
runtime_smoke_assert( ! $registry->has( 'demo' ), 'Demo carrier must not be registered when disabled.' );
/** @var CheckoutOrchestrator $orchestrator */
$orchestrator = $plugin_without_demo->container()->get( CheckoutOrchestrator::class );
$fallback = $orchestrator->calculate( runtime_smoke_request() );
runtime_smoke_assert( $fallback->fallback_used, 'Orchestrator must return fallback when no carriers are registered.' );
runtime_smoke_assert( 'fallback' === $fallback->rates[0]->carrier_key, 'Fallback rate must be returned instead of fatal.' );

$debug_session = new CheckoutSessionManager();
$debug_session->save_debug( array( 'rates_count' => 1, 'fallback_used' => true ) );
$debug_gate = new CheckoutFeatureGate( new FeatureFlags(), new SettingsRepository() );
ob_start();
( new CheckoutDebugPanel( $debug_session, $debug_gate ) )->render();
$debug_output = (string) ob_get_clean();
runtime_smoke_assert( '' === $debug_output, 'Debug panel must be hidden when show_checkout_debug_panel is false.' );

$settings->replace(
	array_merge(
		$settings->all(),
		array(
			'enable_new_checkout_shipping' => true,
			'show_checkout_debug_panel'    => true,
		)
	)
);
ob_start();
( new CheckoutDebugPanel( $debug_session, new CheckoutFeatureGate( new FeatureFlags(), $settings ) ) )->render();
$debug_output = (string) ob_get_clean();
runtime_smoke_assert( str_contains( $debug_output, 'Отладка checkout WDC' ), 'Debug panel must render when explicitly enabled.' );

runtime_smoke_assert( 'Калькулятор доставок' === $method->method_title, 'Shipping method title must be Russian.' );

$errors = new class {
	/** @var array<string,string> */
	public array $errors = array();
	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
	}
};
$validation_session = new CheckoutSessionManager();
$validation_session->save_rates(
	array(
		'demo:pickup' => array(
			'carrier_key'   => 'demo',
			'rate_id'       => 'demo:pickup',
			'delivery_type' => DeliveryType::PICKUP,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'demo:pickup' ) );
( new CheckoutValidation( $validation_session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
runtime_smoke_assert( 'Выберите пункт выдачи.' === ( $errors->errors['wdc_pickup_required'] ?? '' ), 'Pickup validation label must be Russian.' );

$validation_session->save_pickup_selection(
	array(
		'carrier_key'   => 'demo',
		'rate_id'       => 'demo:pickup',
		'point_code'    => 'demo-nsk-001',
		'point_address' => 'Красный проспект, 25',
	)
);
$errors->errors = array();
( new CheckoutValidation( $validation_session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
runtime_smoke_assert( array() === $errors->errors, 'Matching pickup selection must validate.' );

$validation_session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key'   => 'demo',
			'rate_id'       => 'demo:courier',
			'delivery_type' => DeliveryType::COURIER,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'demo:courier' ) );
( new CheckoutValidation( $validation_session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
runtime_smoke_assert( array() === $errors->errors, 'Courier rate must ignore stale pickup selection.' );

$repo = new PickupPointRepository();
$repo->save_many( ( new DemoPickupProvider( dirname( __DIR__, 2 ) . '/database/demo/pickup-points-demo.json' ) )->load_points() );
runtime_smoke_assert( count( $repo->search( 'demo', 'RU', 'Новосибирск' ) ) >= 3, 'Demo pickup search must find Новосибирск.' );
runtime_smoke_assert( count( $repo->search( 'demo', 'RU', 'новосибирск' ) ) >= 3, 'Demo pickup search must find lowercase Новосибирск.' );
runtime_smoke_assert( count( $repo->search( 'demo', 'RU', 'Новосиб' ) ) >= 3, 'Demo pickup search must find partial Новосиб.' );

$renderer = new CheckoutDeliveryTypeSelector( new CheckoutSessionManager(), $repo, new DemoPickupProvider(), new PickupPointRenderer() );
$rate = new class {
	public function get_meta_data(): array {
		return array(
			'carrier_key'           => 'demo',
			'rate_id'               => 'demo:pickup',
			'delivery_type'         => 'pickup',
			'requires_pickup_point' => true,
		);
	}
};
ob_start();
$renderer->render( $rate );
$selector_output = (string) ob_get_clean();
runtime_smoke_assert( ! str_contains( $selector_output, 'wdc_platform_delivery_type' ), 'Delivery type radio must not render.' );
runtime_smoke_assert( str_contains( $selector_output, 'Выберите пункт выдачи' ), 'Pickup selector label must be Russian.' );

$sort_session = new CheckoutSessionManager();
$sort_selector = new CheckoutSortSelector( $sort_session, $settings );
$sort_selector->capture_update_order_review( 'wdc_platform_checkout_sort_mode=fastest' );
runtime_smoke_assert( RateSorter::FASTEST === $sort_session->selected_sort_mode(), 'Sort selector must save fastest in session.' );

$fallback_rate = ( new FallbackRateFactory() )->create();
runtime_smoke_assert( 'Нет видимых доступных вариантов доставки, обратитесь к менеджеру магазина' === $fallback_rate->title, 'Fallback rate label must be Russian.' );
runtime_smoke_assert( 'Калькулятор доставок' === __( 'Калькулятор доставок', 'walls-delivery-calc' ), 'Menu label must exist in Russian.' );
runtime_smoke_assert( AdminMenu::MENU_SLUG === 'wdc-platform', 'Top-level menu slug must be stable.' );

echo "Runtime stabilization smoke test passed.\n";
