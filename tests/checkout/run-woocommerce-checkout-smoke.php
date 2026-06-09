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
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
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
use WallsShop\WDC\Infrastructure\Security\EncryptionService;

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

final class WdcCheckoutApartmentSuggestionClient implements AddressSuggestionClientInterface {
	public int $calls = 0;
	/** @var array<int,string> */
	public array $queries = array();

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	public function suggest( string $stage, string $query, array $context = array() ): array {
		$this->calls++;
		$this->queries[] = $query;
		if ( 'address_next' === $stage ) {
			$house = array(
				'fias_level' => '8',
				'country_iso_code' => 'RU',
				'region_with_type' => 'Новосибирская область',
				'city_with_type' => 'г Новосибирск',
				'street_with_type' => 'ул Некрасова',
				'house' => '63/1',
				'house_fias_id' => 'house-fias',
				'postal_code' => '630005',
			);
			$flat_1 = array_merge( $house, array( 'fias_level' => '9', 'flat' => '1' ) );
			$flat_2 = array_merge( $house, array( 'fias_level' => '9', 'flat' => '2' ) );
			return array(
				'success' => true,
				'status_code' => 200,
				'body' => array( 'query' => $query ),
				'suggestions' => array(
					array( 'value' => 'Новосибирск, ул Некрасова, 63/1', 'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1', 'data' => $house ),
					array( 'value' => 'Новосибирск, ул Некрасова, 63/1, кв 1', 'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, кв 1', 'data' => $flat_1 ),
					array( 'value' => 'Новосибирск, ул Некрасова, 63/1, кв 2', 'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, кв 2', 'data' => $flat_2 ),
				),
			);
		}
		$has_flat = str_contains( $query, 'кв' ) || str_contains( $query, 'квартира' ) || str_contains( $query, 'apt' );
		if ( str_contains( $query, 'без-flat-только' ) && $has_flat ) {
			return array( 'success' => true, 'suggestions' => array(), 'status_code' => 200, 'body' => array( 'query' => $query ) );
		}
		if ( str_contains( $query, 'некрасова' ) || str_contains( $query, 'Некрасова' ) ) {
			return array(
				'success' => true,
				'status_code' => 200,
				'body' => array( 'query' => $query ),
				'suggestions' => array(
					array(
						'value' => 'Новосибирск, ул Некрасова, 63/1',
						'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1',
						'data' => array(
							'fias_level' => '8',
							'country_iso_code' => 'RU',
							'region_with_type' => 'Новосибирская область',
							'city_with_type' => 'г Новосибирск',
							'street_with_type' => 'ул Некрасова',
							'house' => '63/1',
							'house_fias_id' => 'house-fias',
							'flat' => str_contains( $query, 'кв 10' ) ? '10' : '',
							'postal_code' => '630005',
						),
					),
				),
			);
		}

		return array( 'success' => true, 'suggestions' => array(), 'status_code' => 200, 'body' => array( 'query' => $query ) );
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

$suggestion_settings_repo = new SettingsRepository();
$suggestion_settings_repo->set( 'dadata_suggestions_enabled', true );
$suggestion_settings_repo->set(
	DaDataTokenPool::OPTION_KEY,
	array(
		array(
			'id' => 'fake-token',
			'enabled' => true,
			'encrypted_token' => 'encrypted',
			'daily_limit' => 100,
		),
	)
);
$suggestion_pool = new DaDataTokenPool( $suggestion_settings_repo, new EncryptionService() );
$suggestion_client = new WdcCheckoutApartmentSuggestionClient();
$suggestions = new AddressSuggestionService( new AddressSuggestionSettings( $suggestion_settings_repo, new EncryptionService(), $suggestion_pool ), $suggestion_client, new AddressSuggestionNormalizer() );
$with_flat = $suggestions->suggest( 'address', 'Новосибирск, некрасова, д 63/1, кв 10', array( 'country_code' => 'RU', 'selected_display_name' => 'Новосибирск' ) );
$with_flat_item = $with_flat['items'][0] ?? array();
wc_checkout_smoke_assert( true === ( $with_flat['success'] ?? false ) && true === ( $with_flat_item['isDeliverable'] ?? false ), 'Checkout address suggestions must normalize address with apartment.' );
wc_checkout_smoke_assert( str_contains( (string) ( $with_flat_item['label'] ?? '' ), 'Некрасова' ) && str_contains( (string) ( $with_flat_item['label'] ?? '' ), '63/1' ) && str_contains( (string) ( $with_flat_item['label'] ?? '' ), 'кв 10' ), 'Checkout address suggestion label must include street, house and flat.' );
wc_checkout_smoke_assert( ! isset( $with_flat['debug']['request_body'] ) && ! str_contains( json_encode( $with_flat['debug'] ?? array() ) ?: '', 'Authorization' ), 'Checkout address suggestions debug must not expose request body or secrets.' );
$restored_flat = $suggestions->suggest( 'address', 'некрасова 63/1 кв 1', array( 'country_code' => 'RU', 'selected_display_name' => 'Новосибирск', 'city' => 'Новосибирск' ) );
$restored_item = $restored_flat['items'][0] ?? array();
wc_checkout_smoke_assert( true === ( $restored_item['isDeliverable'] ?? false ) && '1' === (string) ( $restored_item['data']['flat'] ?? '' ) && str_contains( (string) ( $restored_item['label'] ?? '' ), 'кв 1' ), 'Checkout address suggestions must restore flat from input when DaData omits flat.' );
wc_checkout_smoke_assert( true === ( $restored_flat['debug']['flat_restored_from_input'] ?? false ), 'Checkout address suggestions debug must mark restored flat from input.' );
$fallback_client = new WdcCheckoutApartmentSuggestionClient();
$fallback_suggestions = new AddressSuggestionService( new AddressSuggestionSettings( $suggestion_settings_repo, new EncryptionService(), $suggestion_pool ), $fallback_client, new AddressSuggestionNormalizer() );
$without_flat_fallback = $fallback_suggestions->suggest( 'address', 'без-flat-только новосибирск некрасова 63/1 кв 1', array( 'country_code' => 'RU', 'selected_display_name' => 'Новосибирск' ) );
wc_checkout_smoke_assert( true === ( $without_flat_fallback['items'][0]['isDeliverable'] ?? false ), 'Checkout address suggestions must retry without flat when query with flat returns no suggestions.' );
wc_checkout_smoke_assert( $fallback_client->calls <= 5 && in_array( 'без-flat-только новосибирск некрасова 63/1', $fallback_client->queries, true ), 'Checkout address suggestions query variants must stay within a reasonable limit and include query without flat.' );
$next_client = new WdcCheckoutApartmentSuggestionClient();
$next_suggestions = new AddressSuggestionService( new AddressSuggestionSettings( $suggestion_settings_repo, new EncryptionService(), $suggestion_pool ), $next_client, new AddressSuggestionNormalizer() );
$next = $next_suggestions->suggest( 'address_next', '630005, Новосибирская обл, г Новосибирск, ул Некрасова, д 63/1', array( 'country_code' => 'RU', 'city_kladr_id' => '5400000100000', 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456', 'selected_level' => 'house', 'desired_level' => 'flat', 'house_fias_id' => 'house-fias', 'house_kladr_id' => 'house-kladr' ) );
wc_checkout_smoke_assert( true === ( $next['success'] ?? false ) && 3 === count( $next['items'] ?? array() ), 'Address next must return mixed house and flat suggestions.' );
wc_checkout_smoke_assert( 'house' === ( $next['items'][0]['level'] ?? '' ) && 'flat' === ( $next['items'][1]['level'] ?? '' ) && 'flat' === ( $next['items'][2]['level'] ?? '' ), 'Address next must preserve house and mark flats as lower-level items.' );
wc_checkout_smoke_assert( 'address_next_relaxed' === ( $next['debug']['selected_variant'] ?? '' ) && 2 === ( $next['debug']['lower_level_count'] ?? 0 ) && ! isset( $next['debug']['request_body'] ), 'Address next debug must expose relaxed variant/lower-level count without request body.' );
$dadata_client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/AddressSuggestions/DaDataSuggestionClient.php' );
$address_next_start = strpos( $dadata_client_source, "if ( 'address_next' === \$stage )" );
$address_next_end = false !== $address_next_start ? strpos( $dadata_client_source, "if ( 'address' === \$stage )", $address_next_start ) : false;
$address_next_source = false !== $address_next_start && false !== $address_next_end ? substr( $dadata_client_source, $address_next_start, $address_next_end - $address_next_start ) : '';
wc_checkout_smoke_assert( '' !== $address_next_source && str_contains( $address_next_source, 'locations_boost' ) && ! str_contains( $address_next_source, 'from_bound' ) && ! str_contains( $address_next_source, 'to_bound' ) && ! str_contains( $address_next_source, 'restrict_value' ), 'DaData address_next request must be relaxed and avoid strict flat bounds/restrict_value.' );

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
