<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wdc_checkout_selection_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
		$GLOBALS['wdc_checkout_selection_options'][ $key ] = $value;

		return true;
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
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql' ): string {
		return '2026-09-06 12:00:00';
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger(): object {
		return new class() {
			/** @param array<string,mixed> $context */
			public function log( string $level, string $message, array $context = array() ): void {
				$GLOBALS['wdc_checkout_selection_logs'][] = array( 'level' => $level, 'message' => $message, 'context' => $context );
			}
		};
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

		/** @param array<string,mixed> $rate */
		public function add_rate( array $rate ): void {
			$this->rates[] = $rate;
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';

		public function get_row( string $query, mixed $output = null ): ?array {
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			return array();
		}

		public function get_var( string $query ): mixed {
			return 0;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			return true;
		}

		public function replace( string $table, array $data, array $format = array() ): bool {
			return true;
		}

		public function delete( string $table, array $where, array $where_format = array() ): bool {
			return true;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}
	}
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new wpdb();

final class WdcCheckoutSelectionSmokeSession {
	/** @var array<string,mixed> */
	public array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}
}

final class WdcCheckoutSelectionSmokeWooCommerce {
	public WdcCheckoutSelectionSmokeSession $session;

	public function __construct() {
		$this->session = new WdcCheckoutSelectionSmokeSession();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcCheckoutSelectionSmokeWooCommerce {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new WdcCheckoutSelectionSmokeWooCommerce();
		}

		return $wc;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutFeatureGate;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\ShippingMethodRegistrar;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;

function checkout_selection_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		$logs = $GLOBALS['wdc_checkout_selection_logs'] ?? array();
		$last = array();
		if ( is_array( $logs ) && array() !== $logs ) {
			$last = $logs[ array_key_last( $logs ) ];
		}
		if ( is_array( $last ) && '' !== (string) ( $last['message'] ?? '' ) ) {
			$message .= ' Last log: ' . (string) $last['message'] . ' ' . wp_json_encode( $last['context'] ?? array() );
		}
		throw new RuntimeException( $message );
	}
}

final class CheckoutSelectionSmokeCarrier implements CarrierAdapterInterface {
	public string $scenario = 'courier-survives';
	public int $courier_price = 450;

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( 'selection_demo', 'Selection Demo', 'fixed', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true, supports_pickup_delivery: true, supports_courier_delivery: true );
	}

	public function supports_country( string $countryCode ): bool {
		return true;
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$rates = match ( $this->scenario ) {
			'method-removed' => array( $this->pickup_rate( 'selection_demo:pickup', 360, 'Selection pickup', 'selection_demo:pickup' ) ),
			'pickup-valid' => array( $this->pickup_rate( 'selection_demo:pickup', 390, 'Selection pickup', 'selection_demo:pickup' ) ),
			'pickup-rejected' => array(
				$this->pickup_rate(
					'selection_demo:pickup',
					390,
					'Selection pickup',
					'selection_demo:pickup',
					array(
						'pickup_selection_rejected' => true,
						'pickup_selection_rejected_family' => 'selection_demo:pickup',
						'pickup_selection_rejected_code' => 'selection_demo_pickup_rejected',
						'pickup_selection_rejected_message' => 'Выберите другой пункт выдачи.',
					)
				),
				$this->pickup_rate( 'other_demo:pickup', 410, 'Other pickup', 'other_demo:pickup' ),
			),
			'grouped-valid' => array(
				$this->group_rate( 'selection_demo:tariff-a', 'A', 610 ),
				$this->group_rate( 'selection_demo:tariff-b', 'B', 720 ),
			),
			'grouped-stale' => array(
				$this->group_rate( 'selection_demo:tariff-a', 'A', 610 ),
			),
			'self-pickup' => array(
				new DeliveryRate(
					'self_pickup',
					'self_pickup',
					'Самовывоз',
					'self_pickup',
					'Самовывоз',
					'self_pickup',
					'Самовывоз',
					DeliveryType::PICKUP,
					'Самовывоз',
					Money::from_rubles( 0 ),
					null,
					null,
					DateRange::single( 0 ),
					'',
					'',
					array(),
					false,
					'',
					false,
					false,
					array(
						'fixed_pickup_point_snapshot' => array( 'title' => 'Самовывоз из магазина' ),
						'no_pickup_selection' => true,
						'final_price_rub' => 0,
					)
				),
			),
			default => array( $this->courier_rate( $this->courier_price ), $this->pickup_rate( 'selection_demo:pickup', 390, 'Selection pickup', 'selection_demo:pickup' ) ),
		};

		return new DeliveryQuote( 'selection-demo', 'selection_demo', $request->destination, $request->package, $rates, true, '', '', false, 'manual' );
	}

	private function courier_rate( int $price_rub ): DeliveryRate {
		return new DeliveryRate(
			'selection_demo:courier',
			'selection_demo',
			'Selection Demo',
			'selection_demo',
			'Selection Demo',
			'courier',
			'Courier',
			DeliveryType::COURIER,
			'Renamed courier title',
			Money::from_rubles( $price_rub ),
			null,
			null,
			DateRange::single( 3 ),
			'',
			'Fresh ' . $price_rub,
			array(),
			false,
			'',
			false,
			true,
			array( 'final_price_rub' => $price_rub )
		);
	}

	/** @param array<string,mixed> $meta */
	private function pickup_rate( string $rate_id, int $price_rub, string $title, string $family, array $meta = array() ): DeliveryRate {
		return new DeliveryRate(
			$rate_id,
			explode( ':', $rate_id )[0],
			$title,
			explode( ':', $rate_id )[0],
			$title,
			'pickup',
			$title,
			DeliveryType::PICKUP,
			$title,
			Money::from_rubles( $price_rub ),
			null,
			null,
			DateRange::single( 5 ),
			'',
			'Fresh pickup',
			array(),
			false,
			'',
			true,
			false,
			array_merge( array( 'pickup_family' => $family, 'final_price_rub' => $price_rub ), $meta )
		);
	}

	private function group_rate( string $rate_id, string $object_code, int $price_rub ): DeliveryRate {
		return new DeliveryRate(
			$rate_id,
			'selection_demo',
			'Selection Demo',
			'selection_demo',
			'Selection Demo',
			$object_code,
			'Tariff ' . $object_code,
			DeliveryType::PICKUP,
			'Selection pickup',
			Money::from_rubles( $price_rub ),
			null,
			null,
			DateRange::single( 'A' === $object_code ? 4 : 6 ),
			'',
			'Fresh tariff ' . $object_code,
			array(),
			false,
			'',
			true,
			false,
			array(
				'tariff_selector_group' => true,
				'checkout_group_id' => 'selection_demo:pickup',
				'pickup_family' => 'selection_demo:pickup',
				'final_price_rub' => $price_rub,
			)
		);
	}
}

function checkout_selection_orchestrator( CheckoutSelectionSmokeCarrier $carrier ): CheckoutOrchestrator {
	$logger = new CheckoutLogger( new Logger() );
	$registry = new CarrierRegistry();
	$registry->register( $carrier );

	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger,
		new DeliveryLeadTimeNormalizer(
			new SettingsRepository(),
			new DeliveryServiceSettingsRepository(),
			new DeliveryDateCalculator( new CalendarService( new CalendarRepository(), new YearGenerator(), new SettingsRepository(), new TimezoneService() ), new TimezoneService(), new DeliveryDateFormatter() ),
			new DeliveryDateFormatter()
		)
	);
}

function checkout_selection_package( float $contents_cost = 1000.0 ): array {
	return array(
		'destination' => array( 'country' => 'RU', 'city' => 'Moscow', 'postcode' => '101000', 'address_1' => 'Tverskaya', 'address_2' => '1' ),
		'contents_cost' => $contents_cost,
		'contents_weight' => 1.25,
		'contents' => array(
			array(
				'quantity' => 1,
				'line_total' => $contents_cost,
			),
		),
	);
}

function checkout_selection_method( CheckoutSelectionSmokeCarrier $carrier, CheckoutSessionManager $session ): NewShippingMethod {
	$settings = new SettingsRepository();
	$settings->set( 'checkout_sort_mode', RateSorter::CHEAPEST );
	NewShippingMethod::configure(
		checkout_selection_orchestrator( $carrier ),
		new WooCommercePackageMapper( null, $session ),
		new WooCommerceRateMapper(),
		$session,
		new RuleRepository(),
		$settings,
		new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.155.3' ),
		new Logger()
	);

	return new NewShippingMethod();
}

function checkout_selection_registrar( CheckoutSelectionSmokeCarrier $carrier, CheckoutSessionManager $session ): ShippingMethodRegistrar {
	$settings = new SettingsRepository();
	$settings->set( 'enable_new_checkout_shipping', true );

	return new ShippingMethodRegistrar(
		new CheckoutFeatureGate( $settings ),
		$settings,
		checkout_selection_orchestrator( $carrier ),
		new WooCommercePackageMapper( null, $session ),
		new WooCommerceRateMapper(),
		$session,
		new RuleRepository(),
		new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.155.3' ),
		new Logger()
	);
}

function checkout_selection_reset(): CheckoutSessionManager {
	WC()->session = new WdcCheckoutSelectionSmokeSession();

	return new CheckoutSessionManager();
}

$carrier = new CheckoutSelectionSmokeCarrier();
$session = checkout_selection_reset();
$carrier->scenario = 'courier-survives';
$carrier->courier_price = 450;
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:courier' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
$stored = $session->rates();
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':selection_demo:courier' ) === $chosen, 'Courier method identity must survive cart recalculation when the fresh rate still exists.' );
checkout_selection_assert( 450.0 === (float) ( $stored['selection_demo:courier']['cost'] ?? 0 ) && 300.0 !== (float) ( $stored['selection_demo:courier']['cost'] ?? 0 ) && isset( $stored['selection_demo:courier']['rate_meta']['final_price_rub'] ), 'Preserved courier selection must use fresh authoritative price and rate metadata.' );
$carrier->courier_price = 520;
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 7000 ) );
$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
$stored = $session->rates();
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':selection_demo:courier' ) === $chosen && 1 === count( $chosen ), 'Repeated checkout updates must not duplicate or oscillate the preserved selected method.' );
checkout_selection_assert( 520.0 === (float) ( $stored['selection_demo:courier']['cost'] ?? 0 ), 'Repeated checkout updates must keep using the latest authoritative rate data.' );

$session = checkout_selection_reset();
$carrier->scenario = 'method-removed';
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:courier' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
checkout_selection_assert( array() === WC()->session->get( 'chosen_shipping_methods', array() ), 'Missing WDC method must not remain chosen after fresh recalculation.' );
checkout_selection_assert( isset( $session->rates()['selection_demo:pickup'] ) && ! isset( $session->rates()['selection_demo:courier'] ), 'Method removal test must be based on the fresh authoritative rate set.' );

$session = checkout_selection_reset();
$carrier->scenario = 'pickup-valid';
$session->save_pickup_selection_for_family( 'selection_demo:pickup', array( 'carrier_key' => 'selection_demo', 'service_key' => 'selection_demo', 'pickup_family' => 'selection_demo:pickup', 'point_code' => 'ABC', 'point_address' => 'Pickup ABC' ) );
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) === WC()->session->get( 'chosen_shipping_methods', array() ), 'Pickup outer method must survive cart recalculation when the selected point remains carrier-valid.' );
checkout_selection_assert( 'ABC' === (string) ( $session->pickup_selection_for_family( 'selection_demo:pickup' )['point_code'] ?? '' ), 'Carrier-valid pickup point selection must remain stored for its family.' );

$session = checkout_selection_reset();
$carrier->scenario = 'pickup-rejected';
$session->save_pickup_selection_for_family( 'selection_demo:pickup', array( 'carrier_key' => 'selection_demo', 'service_key' => 'selection_demo', 'pickup_family' => 'selection_demo:pickup', 'point_code' => 'BAD', 'point_address' => 'Pickup BAD' ) );
$session->save_pickup_selection_for_family( 'other_demo:pickup', array( 'carrier_key' => 'other_demo', 'service_key' => 'other_demo', 'pickup_family' => 'other_demo:pickup', 'point_code' => 'KEEP', 'point_address' => 'Other point' ) );
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 9000 ) );
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) === WC()->session->get( 'chosen_shipping_methods', array() ), 'Rejected pickup point must not reset the still-available pickup shipping method.' );
checkout_selection_assert( array() === $session->pickup_selection_for_family( 'selection_demo:pickup' ), 'Rejected pickup point must clear only its affected family.' );
checkout_selection_assert( 'KEEP' === (string) ( $session->pickup_selection_for_family( 'other_demo:pickup' )['point_code'] ?? '' ), 'Rejected pickup point cleanup must preserve unrelated pickup families.' );

$session = checkout_selection_reset();
$carrier->scenario = 'method-removed';
$session->save_pickup_selection_for_family( 'selection_demo:pickup', array( 'carrier_key' => 'selection_demo', 'service_key' => 'selection_demo', 'pickup_family' => 'selection_demo:pickup', 'point_code' => 'ABC', 'point_address' => 'Pickup ABC' ) );
$session->save_pickup_selection_for_family( 'other_demo:pickup', array( 'carrier_key' => 'other_demo', 'service_key' => 'other_demo', 'pickup_family' => 'other_demo:pickup', 'point_code' => 'KEEP', 'point_address' => 'Other point' ) );
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:courier' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
checkout_selection_assert( 'ABC' === (string) ( $session->pickup_selection_for_family( 'selection_demo:pickup' )['point_code'] ?? '' ), 'Available pickup family must keep its selection even when another selected method disappears.' );
checkout_selection_assert( array() === $session->pickup_selection_for_family( 'other_demo:pickup' ), 'Pickup family absent from the fresh rate set must be cleared without a global reset.' );

$session = checkout_selection_reset();
$carrier->scenario = 'grouped-valid';
$session->save_selected_tariff( 'selection_demo:pickup', array( 'object_code' => 'B', 'title' => 'Old title', 'final_price_rub' => 300 ) );
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
$stored = $session->rates();
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) === WC()->session->get( 'chosen_shipping_methods', array() ), 'Grouped outer shipping method must survive when its checkout group remains available.' );
checkout_selection_assert( 'B' === (string) ( $stored['selection_demo:pickup']['selected_tariff_object'] ?? '' ) && '720' === (string) ( $stored['selection_demo:pickup']['cost'] ?? '' ), 'Existing grouped tariff object must be matched to fresh rate data instead of stale session price.' );

$session = checkout_selection_reset();
$carrier->scenario = 'grouped-stale';
$session->save_selected_tariff( 'selection_demo:pickup', array( 'object_code' => 'B', 'title' => 'Removed tariff', 'final_price_rub' => 300 ) );
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
$stored = $session->rates();
$selected = $session->selected_tariff( 'selection_demo:pickup' );
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ) === WC()->session->get( 'chosen_shipping_methods', array() ), 'Grouped outer method must remain chosen when the group exists after stale inner tariff removal.' );
checkout_selection_assert( 'A' === (string) ( $stored['selection_demo:pickup']['selected_tariff_object'] ?? '' ) && 'A' === (string) ( $selected['object_code'] ?? '' ), 'Missing grouped tariff object must reconcile to a current canonical variant.' );

$session = checkout_selection_reset();
$carrier->scenario = 'self-pickup';
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':self_pickup' ) );
$method = checkout_selection_method( $carrier, $session );
$method->calculate_shipping( checkout_selection_package( 5000 ) );
$stored = $session->rates();
checkout_selection_assert( array( NewShippingMethod::METHOD_ID . ':self_pickup' ) === WC()->session->get( 'chosen_shipping_methods', array() ), 'Self-pickup-shaped checkout-only rates must preserve method identity when the fresh rate remains available.' );
checkout_selection_assert( isset( $stored['self_pickup']['fixed_pickup_point_snapshot'] ) && 0.0 === (float) ( $stored['self_pickup']['cost'] ?? -1 ), 'Self-pickup preservation must still use fresh zero-price fixed-card metadata.' );

$session = checkout_selection_reset();
$registrar = checkout_selection_registrar( $carrier, $session );
$method_a = NewShippingMethod::METHOD_ID . ':selection_demo:a';
$method_b = NewShippingMethod::METHOD_ID . ':selection_demo:b';
$method_c = NewShippingMethod::METHOD_ID . ':selection_demo:c';
$old_rate_order = array( $method_a, $method_b, $method_c );
$new_rate_order = array( $method_b, $method_a, $method_c );
checkout_selection_assert( $old_rate_order !== $new_rate_order, 'WooCommerce treats ordered rate-key changes as significant for chosen-method arbitration.' );
checkout_selection_assert( $method_b === $registrar->preserve_chosen_wdc_method( $method_a, array_fill_keys( $new_rate_order, new stdClass() ), $method_b ), 'Fresh previous WDC choice must survive Woo default-selection filter when rate order changes to B,A,C.' );
checkout_selection_assert( $method_b === $registrar->preserve_chosen_wdc_method( $method_c, array_fill_keys( array( $method_c, $method_a, $method_b ), new stdClass() ), $method_b ), 'Fresh previous WDC choice must survive Woo default-selection filter when rate order changes to C,A,B.' );
checkout_selection_assert( $method_b === $registrar->preserve_chosen_wdc_method( $method_a, array( $method_b => (object) array( 'cost' => 250 ), $method_a => (object) array( 'cost' => 350 ) ), $method_b ), 'Price-caused reorder must keep the previously selected WDC method with the fresh rate key.' );
checkout_selection_assert( $method_a === $registrar->preserve_chosen_wdc_method( $method_a, array( $method_a => new stdClass(), $method_c => new stdClass() ), $method_b ), 'Disappeared previous WDC method must fall back to WooCommerce default.' );
checkout_selection_assert( $method_b === $registrar->preserve_chosen_wdc_method( $method_a, array_fill_keys( array( $method_b, $method_a ), new stdClass() ), 'wdc_platform:selection_demo:b' ), 'Legacy WDC chosen prefix must normalize to the canonical fresh Woo method id.' );
checkout_selection_assert( 'flat_rate:1' === $registrar->preserve_chosen_wdc_method( 'flat_rate:1', array( 'external_rate:2' => new stdClass() ), 'external_rate:2' ), 'Woo chosen-method filter must not override non-WDC shipping methods.' );
checkout_selection_assert( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' === $registrar->preserve_chosen_wdc_method( $method_a, array( NewShippingMethod::METHOD_ID . ':selection_demo:pickup' => new stdClass(), $method_a => new stdClass() ), NewShippingMethod::METHOD_ID . ':selection_demo:pickup' ), 'Pickup WDC method must survive order-only changes when the fresh pickup rate exists.' );
checkout_selection_assert( NewShippingMethod::METHOD_ID . ':self_pickup' === $registrar->preserve_chosen_wdc_method( $method_a, array( $method_a => new stdClass(), NewShippingMethod::METHOD_ID . ':self_pickup' => new stdClass() ), NewShippingMethod::METHOD_ID . ':self_pickup' ), 'Self-pickup WDC method must survive order-only changes when the fresh zero-price rate exists.' );

$new_shipping_method_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/NewShippingMethod.php' );
$reconcile_start = strpos( $new_shipping_method_source, 'private function reconcile_shipping_method_choices' );
$reconcile_source = false === $reconcile_start ? '' : substr( $new_shipping_method_source, $reconcile_start, 2500 );
foreach ( array( "'cdek'", "'self_pickup'", "'dpd'", "'yandex_delivery'", "'pek'" ) as $forbidden_branch ) {
	checkout_selection_assert( ! str_contains( $reconcile_source, $forbidden_branch ), 'Checkout selection reconciliation must not branch on carrier key ' . $forbidden_branch . '.' );
}
checkout_selection_assert( str_contains( $new_shipping_method_source, 'save_rates( $stored )' ) && strpos( $new_shipping_method_source, 'save_rates( $stored )' ) < strpos( $new_shipping_method_source, 'reconcile_shipping_method_choices' ), 'Shipping choice reconciliation must happen after fresh rates are saved.' );
$shipping_registrar_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/ShippingMethodRegistrar.php' );
checkout_selection_assert( str_contains( $shipping_registrar_source, "add_filter( 'woocommerce_shipping_chosen_method'" ) && str_contains( $shipping_registrar_source, 'preserve_chosen_wdc_method' ) && str_contains( $shipping_registrar_source, 'fresh_wdc_rate_id' ), 'Shipping registrar must hook the final WooCommerce chosen-method boundary using fresh package rates.' );

echo "Checkout selection smoke test passed.\n";
