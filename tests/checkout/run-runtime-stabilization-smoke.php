<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_test_options'] = array();
$GLOBALS['wdc_test_actions'] = array();
$GLOBALS['wdc_test_filters'] = array();
$GLOBALS['wdc_test_scripts'] = array();
$GLOBALS['wdc_test_localized_scripts'] = array();
$GLOBALS['wdc_test_styles'] = array();

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

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, bool $in_footer = false ): void {
		$GLOBALS['wdc_test_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): void {
		$GLOBALS['wdc_test_localized_scripts'][ $handle ][ $object_name ] = $l10n;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false ): void {
		$GLOBALS['wdc_test_styles'][ $handle ] = compact( 'src', 'deps', 'ver' );
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( string $handle, string $status = 'enqueued' ): bool {
		return 'wc-checkout' === $handle && 'registered' === $status;
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

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return '2026-05-21 12:00:00';
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

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return 'nonce-' . $action === $nonce;
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
		/** @var array<int,array<string,mixed>> */
		public array $location_rows = array();
		/** @var array<int,array<string,mixed>> */
		public array $rule_rows = array();
		/** @var array<int,array<string,mixed>> */
		public array $rule_condition_rows = array();

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_rule_conditions' ) ) {
				preg_match( '/rule_id = ([0-9]+)/', $query, $matches );
				$rule_id = (int) ( $matches[1] ?? 0 );
				return array_values( array_filter( $this->rule_condition_rows, static fn ( array $row ): bool => (int) $row['rule_id'] === $rule_id ) );
			}

			if ( str_contains( $query, 'wdc_rules' ) ) {
				$rows = $this->rule_rows;
				if ( str_contains( $query, 'enabled = 1' ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) $row['enabled'] === 1 ) );
				}
				if ( preg_match( "/target_type = '([^']*)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['target_type'] === $matches[1] ) );
				}
				if ( preg_match( "/target_value = '([^']*)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['target_value'] === $matches[1] ) );
				}
				usort(
					$rows,
					static fn ( array $a, array $b ): int => ( (int) $a['promo_shipping'] <=> (int) $b['promo_shipping'] )
						?: ( (int) $a['priority'] <=> (int) $b['priority'] )
						?: ( (int) $a['id'] <=> (int) $b['id'] )
				);
				return $rows;
			}

			if ( str_contains( $query, 'wdc_locations' ) ) {
				preg_match( "/searchable_text LIKE '([^']+)'/", $query, $like_matches );
				$needle = trim( (string) ( $like_matches[1] ?? '' ), '%' );
				preg_match( '/LIMIT ([0-9]+)/', $query, $limit_matches );
				$limit = (int) ( $limit_matches[1] ?? 50 );
				$rows = array_values(
					array_filter(
						$this->location_rows,
						static fn ( array $row ): bool => (bool) ( $row['active'] ?? 0 )
							&& ( '' === $needle || str_contains( (string) ( $row['searchable_text'] ?? '' ), $needle ) )
					)
				);
				usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) $a['display_name'], (string) $b['display_name'] ) );
				return array_slice( $rows, 0, max( 1, $limit ) );
			}

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
			if ( str_contains( $table, 'wdc_locations' ) ) {
				$this->location_rows[] = $data;
			} else {
				$this->pickup_rows[] = $data;
			}
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			return true;
		}

		public function get_var( string $query ): int {
			return count( $this->pickup_rows );
		}

		public function query( string $query ): bool {
			if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'wdc_rules' ) ) {
				foreach ( $this->rule_rows as $index => $row ) {
					if ( '' === (string) ( $row['target_type'] ?? '' ) ) {
						$this->rule_rows[ $index ]['target_type'] = 'default';
						$this->rule_rows[ $index ]['target_value'] = '';
					}
				}
			}

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

	public function __unset( string $key ): void {
		unset( $this->data[ $key ] );
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
require_once dirname( __DIR__ ) . '/fixtures/TestDemoCarrier.php';
require_once dirname( __DIR__ ) . '/fixtures/TestPickupProvider.php';

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
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
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Services\KeyboardLayoutTransformer;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

function runtime_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function runtime_smoke_environment(): PluginEnvironment {
	return new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.12.13' );
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

$checkout_rules_method = ( new ReflectionClass( NewShippingMethod::class ) )->getMethod( 'checkout_rules' );
$checkout_rules_method->setAccessible( true );
$rules_db = new wpdb();
NewShippingMethod::configure(
	$container->get( CheckoutOrchestrator::class ),
	$container->get( WooCommercePackageMapper::class ),
	$container->get( WooCommerceRateMapper::class ),
	$container->get( CheckoutSessionManager::class ),
	new RuleRepository( $rules_db ),
	$settings,
	runtime_smoke_environment(),
	new Logger()
);
$method_without_rules = new NewShippingMethod();
runtime_smoke_assert( array() === $checkout_rules_method->invoke( $method_without_rules ), 'No default rules must return an empty checkout rules list.' );
runtime_smoke_assert( is_readable( dirname( __DIR__ ) . '/fixtures/demo/rules-demo.json' ), 'Demo rules fixture should exist for fallback regression coverage.' );
runtime_smoke_assert( array() === $checkout_rules_method->invoke( $method_without_rules ), 'Demo rules must not be used as checkout fallback.' );

$rules_db->rule_rows[] = array(
	'id'              => 1,
	'name'            => 'Default checkout rule',
	'enabled'         => 1,
	'priority'        => 10,
	'target_type'     => 'default',
	'target_value'    => '',
	'action_type'     => RuleActionTypes::CHANGE_PRICE,
	'operation_type'  => RuleOperationTypes::DECREASE,
	'operation_value' => 100,
	'operation_base'  => RuleOperationBases::RUBLES,
	'promo_shipping'  => 0,
	'stop_processing' => 0,
	'created_at'      => '2026-05-21 12:00:00',
	'updated_at'      => '2026-05-21 12:00:00',
);
$checkout_rules = $checkout_rules_method->invoke( new NewShippingMethod() );
runtime_smoke_assert( 1 === count( $checkout_rules ), 'Existing default rules must be used by checkout runtime.' );
runtime_smoke_assert( 'Default checkout rule' === $checkout_rules[0]->name, 'Checkout runtime must return the stored default rule.' );

runtime_smoke_assert( isset( $GLOBALS['wdc_test_filters']['woocommerce_shipping_methods'] ), 'Shipping method filter must be registered.' );
runtime_smoke_assert( isset( $GLOBALS['wdc_test_actions']['wp_ajax_' . CheckoutLocationAjax::ACTION] ), 'Location AJAX endpoint must register for logged-in users.' );
runtime_smoke_assert( isset( $GLOBALS['wdc_test_actions']['wp_ajax_nopriv_' . CheckoutLocationAjax::ACTION] ), 'Location AJAX endpoint must register for guests.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_actions']['woocommerce_after_shipping_rate'] ), 'Checkout rate renderer hook must not register while feature gate is false.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_actions']['woocommerce_review_order_before_shipping'] ), 'Address renderer hook must not register while feature gate is false.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_actions']['wp_enqueue_scripts'] ), 'Frontend CSS enqueue hook must not register while feature gate is false.' );

/** @var ShippingMethodRegistrar $registrar */
$registrar = $container->get( ShippingMethodRegistrar::class );
runtime_smoke_assert( array() === $registrar->register_shipping_method( array() ), 'Shipping method registration must be disabled while feature gate is false.' );
$city_selector_config = $registrar->city_selector_config();
runtime_smoke_assert( 'https://example.test/wp-admin/admin-ajax.php' === $city_selector_config['ajax_url'], 'City selector config must expose AJAX URL.' );
runtime_smoke_assert( 3 === $city_selector_config['min_chars'], 'City selector config must require three characters.' );
runtime_smoke_assert( str_starts_with( $city_selector_config['nonce'], 'nonce-' ), 'City selector config must expose nonce.' );
runtime_smoke_assert( 100 === (int) ( $city_selector_config['checkout_location_search_limit'] ?? 0 ), 'City selector config must expose checkout location search limit.' );
runtime_smoke_assert( 'Идёт поиск, подождите несколько секунд' === $city_selector_config['strings']['searching'], 'City selector config strings must be Russian.' );

$location_repository = new LocationRepository( $GLOBALS['wpdb'] );
( new LocationImportService( $location_repository ) )->import_from_json_file( dirname( __DIR__ ) . '/fixtures/demo/locations-demo.json' );
$location_settings = new SettingsRepository();
$keyboard_layout = new KeyboardLayoutTransformer();
runtime_smoke_assert( 'новос' === $keyboard_layout->latin_to_cyrillic_layout( 'yjdjc' ), 'Keyboard layout must map yjdjc to новос.' );
runtime_smoke_assert( 'привет' === $keyboard_layout->latin_to_cyrillic_layout( 'ghbdtn' ), 'Keyboard layout must map ghbdtn to привет.' );
runtime_smoke_assert( in_array( 'новос', $keyboard_layout->variants( 'yjdjc' ), true ), 'Keyboard variants must include corrected query.' );
$location_search_service = new LocationSearchService( $location_repository, $keyboard_layout );
$location_country_index = new LocationCountryIndexService( $location_repository );
$location_ajax = new CheckoutLocationAjax( new CheckoutLocationSearch( $location_search_service ), $location_settings, $location_country_index );
$location_payload = $location_ajax->payload( 'Новос' );
runtime_smoke_assert( 'Новосибирская область' === ( $location_payload['groups'][0]['region'] ?? '' ), 'Location AJAX payload must group Новос by region.' );
runtime_smoke_assert( 'Новосибирск' === ( $location_payload['groups'][0]['locations'][0]['city_name'] ?? '' ), 'Location AJAX payload must return Новосибирск.' );
runtime_smoke_assert( 'Новосибирск' === ( $location_ajax->payload( 'yjdjc' )['groups'][0]['locations'][0]['city_name'] ?? '' ), 'Location search must find Новосибирск through keyboard layout correction.' );
runtime_smoke_assert( array() === $location_ajax->payload( 'Berlin' )['groups'], 'Keyboard layout correction must not make Berlin match Russian cities accidentally.' );
runtime_smoke_assert( in_array( 'RU', $location_country_index->countries(), true ), 'Runtime city selector receives supported location countries.' );
runtime_smoke_assert( false === (bool) ( $location_ajax->payload( 'Новосибирск', '', 'PL' )['local_database_available'] ?? true ), 'Runtime city selector disables local DB for unsupported country.' );
runtime_smoke_assert( 100 === $location_payload['limit'], 'Location AJAX payload must include default limit.' );
runtime_smoke_assert( isset( $location_payload['limit_reached'] ), 'Location AJAX payload must include limit_reached.' );
$location_settings->set( 'checkout_location_search_limit', 10 );
runtime_smoke_assert( 10 === $location_ajax->payload( 'Новос' )['limit'], 'Location AJAX must use SettingsRepository checkout_location_search_limit.' );
$location_settings->set( 'checkout_location_search_limit', 999 );
runtime_smoke_assert( 500 === $location_ajax->payload( 'Новос' )['limit'], 'Location AJAX must clamp checkout_location_search_limit to max.' );
$location_settings->set( 'checkout_location_search_limit', 100 );
runtime_smoke_assert( array() === $location_ajax->payload( 'xx' )['groups'], 'Short location AJAX query must return empty groups.' );
runtime_smoke_assert( array() === $location_ajax->payload( 'НеизвестныйГород' )['groups'], 'Unknown location AJAX query must return empty groups.' );
$_REQUEST = array(
	'nonce' => 'bad-nonce',
	'query' => 'Новос',
);
ob_start();
$location_ajax->handle();
$nonce_error = json_decode( (string) ob_get_clean(), true );
runtime_smoke_assert( false === ( $nonce_error['success'] ?? true ), 'Location AJAX must reject nonce mismatch.' );
$_REQUEST = array(
	'nonce' => wp_create_nonce( CheckoutLocationAjax::NONCE_ACTION ),
	'query' => 'Новос',
);
ob_start();
$location_ajax->handle();
$ajax_response = json_decode( (string) ob_get_clean(), true );
runtime_smoke_assert( true === ( $ajax_response['success'] ?? false ), 'Location AJAX handle must return success for valid nonce.' );
runtime_smoke_assert( 'Новосибирск' === ( $ajax_response['data']['groups'][0]['locations'][0]['city_name'] ?? '' ), 'Location AJAX handle must return grouped Новосибирск results.' );

$city_selector_js = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-city-selector.js' ) );
foreach ( array( 'updated_checkout', '.wdcCitySelector', 'input[name="shipping_city"]', 'wdc_platform_search_locations', 'update_checkout', 'wdc_platform_location_id', 'event.target', ':visible', ':disabled', 'city input event', 'ajax request start', 'locationStore', 'data-location-key', 'mousedown.wdcCitySelector', 'isSelecting', 'preventDefault', 'stopPropagation', 'stopImmediatePropagation', 'wdc-city-selector-selected', 'setTimeout', 'suppressSearch', 'search suppressed', 'suppressSearch disabled after updated_checkout', 'wdc-city-picker-overlay', 'wdc-city-picker-panel', 'wdc-city-picker-close', 'Escape', 'wdc-city-picker-search', 'wdc-city-picker-use-manual', 'wdc-city-picker-clear', 'manual fallback city', 'manual city button mousedown', 'fallback selection start', 'fallback city applied', 'picker closed after fallback', 'update_checkout triggered after fallback', 'closePicker', 'applyManualFallbackCity', 'applySelectedLocation', 'originalCityValue', 'Использовать введенное название', 'Очистить название', 'corrected query', 'correction used', 'supported_location_countries', 'currentCountryCode', 'localDatabaseAvailable', 'country_code: currentCountryCode()' ) as $needle ) {
	runtime_smoke_assert( str_contains( $city_selector_js, $needle ), 'City selector JS must contain ' . $needle . '.' );
}
preg_match( '/function closePicker\(\) \{(?P<body>.*?)\n\t\}/s', $city_selector_js, $close_picker_match );
runtime_smoke_assert( isset( $close_picker_match['body'] ), 'City selector JS must expose closePicker function body.' );
runtime_smoke_assert( ! str_contains( $close_picker_match['body'], 'applyManualFallbackCity' ), 'closePicker must not call applyManualFallbackCity.' );
runtime_smoke_assert( ! str_contains( $close_picker_match['body'], 'update_checkout' ), 'closePicker must not trigger update_checkout.' );
runtime_smoke_assert( str_contains( $city_selector_js, ".on( 'click.wdcCitySelector', '.wdc-city-picker-close', function ( event )" ) && str_contains( $city_selector_js, "stopEvent( event );\n\t\tclosePicker();" ), 'Close button handler must call closePicker only.' );
runtime_smoke_assert( str_contains( $city_selector_js, "if ( event.target === this ) {\n\t\t\tclosePicker();" ), 'Outside overlay click must call closePicker only.' );
runtime_smoke_assert( str_contains( $city_selector_js, "if ( 'Escape' === event.key && pickerOpen ) {\n\t\t\tevent.preventDefault();\n\t\t\tclosePicker();" ), 'Escape handler must call closePicker only.' );
runtime_smoke_assert( str_contains( $city_selector_js, ".on( 'input.wdcCitySelector', '.wdc-city-picker-search'" ), 'City selector JS must search from modal input events.' );
runtime_smoke_assert( ! str_contains( $city_selector_js, "keyup.wdcCitySelector change.wdcCitySelector paste.wdcCitySelector', citySelector" ), 'City selector JS must not search from external city keyup/change/paste.' );
runtime_smoke_assert( ! str_contains( $city_selector_js, 'data-location="' ), 'City selector JS must not store encoded JSON in data-location.' );
runtime_smoke_assert( ! str_contains( $city_selector_js, 'JSON.stringify( location )' ), 'City selector JS must not stringify location payload into HTML attributes.' );
runtime_smoke_assert( ! str_contains( $city_selector_js, 'locations-demo.json' ), 'City selector JS must not preload full location dataset.' );
runtime_smoke_assert( ! str_contains( $city_selector_js, 'skipManualFallback' ), 'City selector JS must not use close-time manual fallback flags.' );
runtime_smoke_assert( ! str_contains( $city_selector_js, 'update_checkout triggered after empty city' ), 'City selector JS must not trigger checkout update for empty close.' );

$city_selector_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-city-selector.css' );
foreach ( array( 'max-width: 1300px', 'column-count: 2', 'break-inside: avoid', '@media (max-width: 900px)', 'column-count: 1', 'width: 100%', 'min-width: 0', 'position: fixed' ) as $needle ) {
	runtime_smoke_assert( str_contains( $city_selector_css, $needle ), 'City selector CSS must contain ' . $needle . '.' );
}
runtime_smoke_assert( ! str_contains( $city_selector_css, 'grid-template-columns: repeat(2' ), 'Desktop city selector CSS must not use equal-height grid columns.' );

$checkout_sort_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-sort.js' );
foreach ( array( '.wdc-platform-pickup-point', 'pickup select changed', 'pickup carrier', 'pickup rate id', 'pickup point code', 'update_checkout triggered after pickup selection' ) as $needle ) {
	runtime_smoke_assert( str_contains( $checkout_sort_js, $needle ), 'Pickup frontend JS must contain ' . $needle . '.' );
}
$delivery_type_selector_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' );
runtime_smoke_assert( ! str_contains( $delivery_type_selector_source, 'Для курьерской доставки будет использован адрес, указанный в checkout.' ), 'Checkout delivery type selector must not auto-render courier customer comment.' );
$rate_renderer_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/CheckoutRateRenderer.php' );
$rate_mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/WooCommerceRateMapper.php' );
$new_shipping_method_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/NewShippingMethod.php' );
$checkout_rates_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-rates.css' );
runtime_smoke_assert( str_contains( $rate_renderer_source, '<div class="wdc-platform-delivery-comment wdc-shipping-rate-comment">' ), 'Rate renderer must render comments as block elements, not inline-only spans.' );
runtime_smoke_assert( str_contains( $rate_renderer_source, "empty( \$meta['tariff_variants'] )" ), 'Domestic tariff selector rates must not duplicate planned_delivery_comment below the selector.' );
runtime_smoke_assert( str_contains( $rate_renderer_source, "empty( \$meta['domestic_tariff_grouped'] )" ), 'Domestic single-tariff grouped rates must keep planned_delivery_comment in the label only.' );
runtime_smoke_assert( str_contains( $rate_renderer_source, 'count( $variants ) < 2' ), 'Domestic tariff selector must not render radio list for a single tariff.' );
runtime_smoke_assert( str_contains( $rate_renderer_source, "wdc-domestic-tariff-selector__crossed-price" ), 'Domestic tariff selector must render per-variant crossed price.' );
runtime_smoke_assert( str_contains( $rate_mapper_source, "'domestic_tariff_grouped'" ), 'WooCommerce rate meta must expose the domestic grouped marker to checkout rendering.' );
runtime_smoke_assert( str_contains( $new_shipping_method_source, 'domestic_method_title' ) && str_contains( $new_shipping_method_source, "\$title . ' - ' . \$days" ), 'Domestic grouped method label must include service, selected tariff, and delivery days.' );
runtime_smoke_assert( str_contains( $new_shipping_method_source, '$this->delivery_comment( $rate->delivery_days )' ) && str_contains( $new_shipping_method_source, 'DeliveryDaysFormatter::format' ), 'Domestic selector rows must derive formatted delivery comments from final delivery days.' );
runtime_smoke_assert( str_contains( $checkout_rates_css, '.wdc-platform-delivery-comment' ) && str_contains( $checkout_rates_css, 'flex-basis: 100%' ) && str_contains( $checkout_rates_css, '.wdc-shipping-rate-comment' ) && str_contains( $checkout_rates_css, 'display: block' ), 'Checkout comments CSS must force each service/rule comment onto its own line.' );
$src_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src' ) );
foreach ( $src_iterator as $src_file ) {
	if ( ! $src_file->isFile() || 'php' !== $src_file->getExtension() ) {
		continue;
	}
	runtime_smoke_assert( ! str_contains( (string) file_get_contents( $src_file->getPathname() ), 'Для курьерской доставки будет использован адрес, указанный в checkout.' ), 'Courier auto-comment text must not exist in src unless explicitly configured.' );
}

$settings->set( 'enable_new_checkout_shipping', true );
runtime_smoke_assert( $gate->enabled(), 'Feature gate must be enabled through SettingsRepository.' );
runtime_smoke_assert( isset( $registrar->register_shipping_method( array() )[ NewShippingMethod::METHOD_ID ] ), 'Shipping method registration must be enabled through settings.' );
$registrar->enqueue_assets();
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_scripts']['wdc-platform-address-normalization'] ), 'Address normalization script must not enqueue.' );
runtime_smoke_assert( ! isset( $GLOBALS['wdc_test_scripts']['wdc-platform-address-suggestions'] ), 'Address suggestions script must not enqueue when DaData suggestions are disabled.' );
runtime_smoke_assert( isset( $GLOBALS['wdc_test_scripts']['wdc-platform-city-selector'] ), 'Local city selector script must enqueue when DaData suggestions are disabled.' );

$GLOBALS['wdc_test_scripts'] = array();
$GLOBALS['wdc_test_styles'] = array();
$GLOBALS['wdc_test_localized_scripts'] = array();
$settings->set( 'dadata_suggestions_enabled', true );
$registrar->enqueue_assets();
runtime_smoke_assert( isset( $GLOBALS['wdc_test_scripts']['wdc-platform-address-suggestions'] ), 'Address suggestions script must enqueue when DaData suggestions are requested even if API key is missing.' );
runtime_smoke_assert( isset( $GLOBALS['wdc_test_styles']['wdc-platform-address-suggestions'] ), 'Address suggestions CSS must enqueue when DaData suggestions are requested.' );
runtime_smoke_assert( isset( $GLOBALS['wdc_test_scripts']['wdc-platform-city-selector'] ), 'Local city selector script must still enqueue when DaData suggestions are requested.' );
$suggestions_config = $GLOBALS['wdc_test_localized_scripts']['wdc-platform-address-suggestions']['wdcPlatformAddressSuggestions'] ?? array();
runtime_smoke_assert( true === ( $suggestions_config['suggestions_requested'] ?? false ), 'Address suggestions config must show suggestions_requested=true.' );
runtime_smoke_assert( false === ( $suggestions_config['enabled'] ?? true ), 'Address suggestions config must show enabled=false when API key is missing.' );
runtime_smoke_assert( false === ( $suggestions_config['tokens_ready'] ?? true ), 'Address suggestions config must show tokens_ready=false when tokens are missing.' );
runtime_smoke_assert( 0 === (int) ( $suggestions_config['total_tokens_count'] ?? -1 ), 'Address suggestions config must expose total_tokens_count.' );
runtime_smoke_assert( 0 === (int) ( $suggestions_config['available_tokens_count'] ?? -1 ), 'Address suggestions config must expose available_tokens_count.' );
runtime_smoke_assert( array_key_exists( 'encryption_ready', $suggestions_config ), 'Address suggestions config must expose encryption_ready.' );
runtime_smoke_assert( ! array_key_exists( 'api_key', $suggestions_config ) && ! array_key_exists( 'token', $suggestions_config ), 'Address suggestions frontend config must not expose DaData credentials.' );

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

$quote_cache = new QuoteCache();
$service_cache_key_a = $quote_cache->cache_key( runtime_smoke_request(), 'demo', '', 'service_a' );
$service_cache_key_b = $quote_cache->cache_key( runtime_smoke_request(), 'demo', '', 'service_b' );
runtime_smoke_assert( $service_cache_key_a !== $service_cache_key_b, 'Quote cache key must include service_key.' );
runtime_smoke_assert( $service_cache_key_a === $quote_cache->cache_key( runtime_smoke_request(), 'demo', '', 'service_a' ), 'Quote cache key must remain stable per service.' );
$quote_cache->set( runtime_smoke_request(), 'demo', new DeliveryQuote( 'quote-a', 'demo', runtime_smoke_request()->destination, runtime_smoke_request()->package ), '', 'service_a' );
$quote_cache->set( runtime_smoke_request(), 'demo', new DeliveryQuote( 'quote-b', 'demo', runtime_smoke_request()->destination, runtime_smoke_request()->package ), '', 'service_b' );
runtime_smoke_assert( 'quote-a' === $quote_cache->get( runtime_smoke_request(), 'demo', '', 'service_a' )?->quote_id, 'Quote cache hit must stay isolated for service_a.' );
runtime_smoke_assert( 'quote-b' === $quote_cache->get( runtime_smoke_request(), 'demo', '', 'service_b' )?->quote_id, 'Quote cache hit must stay isolated for service_b.' );

$settings_page = new SettingsAdminPage( $settings );
$legacy_location_limit_key = 'location' . '_search' . '_limit';
runtime_smoke_assert( 0 === $settings->get_int( $legacy_location_limit_key, 0 ), 'SettingsRepository must not default the legacy location limit key.' );
runtime_smoke_assert( 100 === $settings->get_int( 'checkout_location_search_limit', 0 ), 'SettingsRepository must default checkout_location_search_limit to 100.' );
$sanitized = $settings_page->sanitize_settings(
	array(
		'enable_new_checkout_shipping' => '1',
		'checkout_sort_mode'           => 'unexpected',
		'show_checkout_debug_panel'    => 'on',
		'checkout_location_search_limit' => '100',
	)
);
runtime_smoke_assert( true === $sanitized['enable_new_checkout_shipping'], 'enable_new_checkout_shipping must sanitize to true.' );
runtime_smoke_assert( RateSorter::CHEAPEST === $sanitized['checkout_sort_mode'], 'Invalid checkout_sort_mode must fall back to cheapest.' );
runtime_smoke_assert( true === $sanitized['show_checkout_debug_panel'], 'show_checkout_debug_panel must sanitize to true.' );
runtime_smoke_assert( ! array_key_exists( $legacy_location_limit_key, $sanitized ), 'Legacy location limit key must not be sanitized.' );
runtime_smoke_assert( 100 === $sanitized['checkout_location_search_limit'], 'checkout_location_search_limit=100 must sanitize to 100.' );
runtime_smoke_assert( 10 === $settings_page->sanitize_settings( array( 'checkout_location_search_limit' => '5' ) )['checkout_location_search_limit'], 'checkout_location_search_limit below min must clamp to 10.' );
runtime_smoke_assert( 500 === $settings_page->sanitize_settings( array( 'checkout_location_search_limit' => '999' ) )['checkout_location_search_limit'], 'checkout_location_search_limit above max must clamp to 500.' );

$GLOBALS['wdc_test_options'] = array(
	'wdc_core_settings' => array(
		'enable_new_checkout_shipping' => true,
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

runtime_smoke_assert( 'Калькулятор доставки w.ALL.s' === $method->method_title, 'Shipping method title must be updated.' );

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

$validation_session->clear_pickup_selection();
$validation_session->save_rates(
	array(
		'russian_post_worldwide_parcel' => array(
			'carrier_key'            => 'russian_post',
			'rate_id'                => 'russian_post_worldwide_parcel',
			'service_key'            => 'russian_post_worldwide_parcel',
			'delivery_type'          => DeliveryType::PICKUP,
			'requires_pickup_point'  => false,
			'no_pickup_selection'    => true,
			'rate_meta'              => array(
				'no_pickup_selection'   => true,
			),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'russian_post_worldwide_parcel' ) );
$errors->errors = array();
( new CheckoutValidation( $validation_session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
runtime_smoke_assert( array() === $errors->errors, 'Russian Post international pickup rate must validate without pickup point selection.' );

$repo = new PickupPointRepository();
$repo->save_many( ( new TestPickupProvider( dirname( __DIR__ ) . '/fixtures/demo/pickup-points-demo.json' ) )->load_points() );
runtime_smoke_assert( count( $repo->search( 'demo', 'RU', 'Новосибирск' ) ) >= 3, 'Demo pickup search must find Новосибирск.' );
runtime_smoke_assert( count( $repo->search( 'demo', 'RU', 'новосибирск' ) ) >= 3, 'Demo pickup search must find lowercase Новосибирск.' );
runtime_smoke_assert( count( $repo->search( 'demo', 'RU', 'Новосиб' ) ) >= 3, 'Demo pickup search must find partial Новосиб.' );

$renderer = new CheckoutDeliveryTypeSelector( new CheckoutSessionManager(), $repo, new PickupPointRenderer() );
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

$rp_rate = new class {
	public function get_meta_data(): array {
		return array(
			'carrier_key'           => 'russian_post',
			'rate_id'               => 'russian_post_worldwide_parcel',
			'service_key'           => 'russian_post_worldwide_parcel',
			'delivery_type'         => 'pickup',
			'requires_pickup_point' => true,
			'no_pickup_selection'   => true,
			'rate_meta'             => array(
				'no_pickup_selection'   => true,
			),
		);
	}
};
ob_start();
$renderer->render( $rp_rate );
$rp_selector_output = (string) ob_get_clean();
runtime_smoke_assert( ! str_contains( $rp_selector_output, 'Выберите пункт выдачи' ) && ! str_contains( $rp_selector_output, 'wdc-platform-pickup-point' ), 'Russian Post international must not render pickup selector UI.' );

$pickup_mode_scan = '';
foreach ( array( '/src', '/tests', '/docs' ) as $scan_dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . $scan_dir ) );
	foreach ( $iterator as $scan_file ) {
		if ( $scan_file->isFile() ) {
			$pickup_mode_scan .= (string) file_get_contents( $scan_file->getPathname() );
		}
	}
}
$removed_pickup_mode_key = 'pickup_selection_' . 'mode';
$removed_pickup_mode_value = 'post_' . 'office';
runtime_smoke_assert( ! str_contains( $pickup_mode_scan, $removed_pickup_mode_key ), 'Removed pickup selection mode key must not be referenced after cleanup.' );
runtime_smoke_assert( ! str_contains( $pickup_mode_scan, $removed_pickup_mode_value ), 'Removed technical pickup mode value must not be referenced after cleanup.' );

$sort_session = new CheckoutSessionManager();
$sort_selector = new CheckoutSortSelector( $sort_session, $settings );
WC()->session->set( 'shipping_for_package_0', array( 'cached' => true ) );
$sort_selector->capture_update_order_review( 'wdc_platform_checkout_sort_mode=fastest' );
runtime_smoke_assert( RateSorter::FASTEST === $sort_session->selected_sort_mode(), 'Sort selector must save fastest in session.' );
runtime_smoke_assert( null === WC()->session->get( 'shipping_for_package_0' ), 'Sort selector must clear WooCommerce shipping cache when sort changes.' );

$fallback_rate = ( new FallbackRateFactory() )->create();
runtime_smoke_assert( 'Нет видимых доступных вариантов доставки, обратитесь к менеджеру магазина' === $fallback_rate->title, 'Fallback rate label must be Russian.' );
runtime_smoke_assert( 'Калькулятор доставок' === __( 'Калькулятор доставок', 'walls-delivery-calc' ), 'Menu label must exist in Russian.' );
runtime_smoke_assert( AdminMenu::MENU_SLUG === 'wdc-platform', 'Top-level menu slug must be stable.' );

echo "Runtime stabilization smoke test passed.\n";
