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
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
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


function wc_checkout_sort_rate( string $carrier, string $tariff_key, int $original_price_rub, int $final_price_rub, int $original_days, int $final_days, bool $selector = false, string $group_id = '' ): DeliveryRate {
	$meta = $selector ? array( 'tariff_selector_group' => true, 'checkout_group_id' => $group_id ) : array();

	return new DeliveryRate(
		$carrier . ':' . $tariff_key,
		$carrier,
		$carrier,
		$carrier,
		$carrier,
		$tariff_key,
		$tariff_key,
		DeliveryType::PICKUP,
		$carrier . ' ' . $tariff_key,
		Money::from_rubles( $final_price_rub ),
		null,
		null,
		DateRange::single( $final_days ),
		'',
		$final_days . ' дн.',
		array(),
		false,
		'',
		false,
		false,
		$meta,
		Money::from_rubles( $original_price_rub ),
		DateRange::single( $original_days )
	);
}
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
	public string $shipping_country = '';
	public string $shipping_state = '';
	public string $shipping_city = '';
	public string $shipping_postcode = '';
	public string $shipping_address_1 = '';
	public string $shipping_address_2 = 'filled';

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function set_shipping_country( string $value ): void { $this->shipping_country = $value; }
	public function set_shipping_state( string $value ): void { $this->shipping_state = $value; }
	public function set_shipping_city( string $value ): void { $this->shipping_city = $value; }
	public function set_shipping_postcode( string $value ): void { $this->shipping_postcode = $value; }
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void { $this->shipping_address_2 = $value; }
}

final class WdcSmokeShippingItem {
	public string $method_title = '';
	/** @var array<string,mixed> */
	public array $meta = array();

	public function set_method_title( string $method_title ): void {
		$this->method_title = $method_title;
	}

	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}
}

final class WdcSmokeCheckoutErrors {
	/** @var array<string,string> */
	public array $errors = array();

	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
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
			$flat_values = str_contains( $query, 'кв 9' ) || str_contains( $query, ', 9' ) ? array_map( 'strval', range( 9, 28 ) ) : array( '1', '2' );
			$flat_items = array(
				array( 'value' => 'Новосибирск, ул Некрасова, 63/1', 'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1', 'data' => $house ),
			);
			foreach ( $flat_values as $flat_value ) {
				$flat = array_merge( $house, array( 'fias_level' => '9', 'flat' => $flat_value ) );
				$flat_items[] = array( 'value' => 'Новосибирск, ул Некрасова, 63/1, кв ' . $flat_value, 'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, кв ' . $flat_value, 'data' => $flat );
			}
			return array(
				'success' => true,
				'status_code' => 200,
				'body' => array( 'query' => $query ),
				'suggestions' => $flat_items,
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
$next_filtered = $next_suggestions->suggest( 'address_next', '630005, Новосибирская обл, г Новосибирск, ул Некрасова, д 63/1, 9', array( 'country_code' => 'RU', 'city_kladr_id' => '5400000100000', 'city_fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456', 'selected_level' => 'house', 'desired_level' => 'flat', 'house_fias_id' => 'house-fias', 'house_kladr_id' => 'house-kladr' ) );
$next_filtered_labels = implode( ' | ', array_map( static fn( array $item ): string => (string) ( $item['label'] ?? '' ), $next_filtered['items'] ?? array() ) );
wc_checkout_smoke_assert( true === ( $next_filtered['success'] ?? false ) && 21 === count( $next_filtered['items'] ?? array() ), 'Address next must keep returning mixed house and filtered flat suggestions while typing flat number without cutting the list to four.' );
wc_checkout_smoke_assert( 20 === ( $next_filtered['debug']['lower_level_count'] ?? 0 ) && str_contains( $next_filtered_labels, 'кв 9' ) && str_contains( $next_filtered_labels, 'кв 28' ), 'Address next must return all flat suggestions matching typed apartment number within the 20-item limit.' );
$dadata_client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/AddressSuggestions/DaDataSuggestionClient.php' );
$address_next_start = strpos( $dadata_client_source, "if ( 'address_next' === \$stage )" );
$address_next_end = false !== $address_next_start ? strpos( $dadata_client_source, "if ( 'address' === \$stage )", $address_next_start ) : false;
$address_next_source = false !== $address_next_start && false !== $address_next_end ? substr( $dadata_client_source, $address_next_start, $address_next_end - $address_next_start ) : '';
wc_checkout_smoke_assert( '' !== $address_next_source && str_contains( $address_next_source, 'locations_boost' ) && ! str_contains( $address_next_source, 'from_bound' ) && ! str_contains( $address_next_source, 'to_bound' ) && ! str_contains( $address_next_source, 'restrict_value' ), 'DaData address_next request must be relaxed and avoid strict flat bounds/restrict_value.' );
$checkout_address_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.js' );
wc_checkout_smoke_assert( str_contains( $checkout_address_js, 'wdc-address-picker-house-finalize' ) && str_contains( $checkout_address_js, 'function finalizeHouseWithoutFlat()' ), 'Checkout address picker must expose house-level finalize action while lower-level suggestions exist.' );
wc_checkout_smoke_assert( str_contains( $checkout_address_js, 'houseLevelItem( item )' ) && str_contains( $checkout_address_js, 'applyResolved( prefix, houseItem );' ) && str_contains( $checkout_address_js, "'flat'," ), 'House-level finalize must apply normalized house item with flat data removed.' );

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


$reflection = new ReflectionMethod( NewShippingMethod::class, 'rates_for_wc' );
$reflection->setAccessible( true );
$session->save_sort_mode( RateSorter::CHEAPEST );
$session->save_selected_tariff( 'dpd:pickup', array() );
$grouped_rates = array(
	wc_checkout_sort_rate( 'yandex', 'ya', 300, 150, 3, 3 ),
	wc_checkout_sort_rate( 'dpd', 'A', 100, 200, 2, 2, true, 'dpd:pickup' ),
	wc_checkout_sort_rate( 'dpd', 'B', 200, 50, 4, 4, true, 'dpd:pickup' ),
	wc_checkout_sort_rate( 'yandex', 'courier', 300, 250, 2, 2 ),
	wc_checkout_sort_rate( 'russian_post', 'rp', 300, 300, 5, 5 ),
);
$wc_rates = $reflection->invoke( $method, $grouped_rates );
wc_checkout_smoke_assert( array( 'yandex', 'dpd', 'yandex', 'russian_post' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->carrier_key, $wc_rates ), 'WC grouped selector must be sorted among methods by active final price.' );
wc_checkout_smoke_assert( 'dpd:pickup' === $wc_rates[1]->rate_id && 'A' === (string) ( $wc_rates[1]->meta['selected_tariff_object'] ?? '' ), 'WC grouped selector must use first original-price variant as active when no selected tariff exists.' );
wc_checkout_smoke_assert( array( 'A', 'B' ) === array_map( static fn( array $variant ): string => (string) $variant['object_code'], $wc_rates[1]->meta['tariff_variants'] ?? array() ), 'WC grouped selector variants must keep original-price order.' );

$session->save_selected_tariff( 'dpd:pickup', array( 'object_code' => 'B' ) );
$wc_rates = $reflection->invoke( $method, $grouped_rates );
wc_checkout_smoke_assert( array( 'dpd', 'yandex', 'yandex', 'russian_post' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->carrier_key, $wc_rates ), 'WC grouped selector method ordering must use selected tariff final price.' );
wc_checkout_smoke_assert( 'B' === (string) ( $wc_rates[0]->meta['selected_tariff_object'] ?? '' ) && 50.0 === (float) $wc_rates[0]->price->get_rubles(), 'WC grouped selector active method must use the selected tariff values.' );
wc_checkout_smoke_assert( array( 'A', 'B' ) === array_map( static fn( array $variant ): string => (string) $variant['object_code'], $wc_rates[0]->meta['tariff_variants'] ?? array() ), 'WC selected grouped selector variants must still keep original-price order.' );
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

$session->save_rates(
	array(
		'cdek:courier' => array(
			'rate_id' => 'cdek:courier',
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'service_title' => 'СДЭК',
			'label' => 'СДЭК курьер, Посылка склад-дверь - 10-14 дней',
			'delivery_type' => 'courier',
			'planned_delivery_comment' => '10-14 дней',
			'delivery_comment' => '10-14 дней',
			'cost' => 520.0,
			'tariff_key' => '137',
			'tariff_title' => 'Посылка склад-дверь',
			'selected_tariff_object' => '137',
			'selected_tariff_title' => 'Посылка склад-дверь',
			'api_base_price_rub' => 520.0,
			'rules_source' => 'none',
			'rate_meta' => array(
				'api_base_price_rub' => 520.0,
				'package' => array( 'weight_g' => 1000, 'items_weight_g' => 700, 'packaging_weight_g' => 300 ),
				'request_payload_sanitized' => array( 'from_location' => array( 'code' => 270 ) ),
				'response_tariff_sanitized' => array( 'tariff_code' => 137 ),
			),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:cdek:courier' ) );
$cdek_item = new WdcSmokeShippingItem();
$cdek_item->meta = array(
	'carrier_key' => 'cdek',
	'rate_id' => 'cdek:courier',
	'delivery_type' => 'courier',
	'service_key' => 'cdek',
	'api_base_price_rub' => 520,
	'tariff_key' => '137',
	'selected_tariff_object' => '137',
	'Перевозчик' => 'cdek',
	'Способ доставки' => 'cdek:courier',
	'Тип доставки' => 'Курьер',
	'Населенный пункт' => 'Новосибирск',
	'Нормализация' => 'manual',
);
$cdek_persister = new OrderShippingMetaPersister( $session );
$cdek_persister->persist_shipping_item_meta( $cdek_item );
wc_checkout_smoke_assert( 'СДЭК курьер, Посылка склад-дверь - 10-14 дней' === $cdek_item->method_title, 'CDEK checkout shipping item method title must stay user-facing.' );
wc_checkout_smoke_assert( array( 'Срок доставки' => '10-14 дней' ) === $cdek_item->meta, 'CDEK checkout shipping item visible meta must contain only delivery time.' );
foreach ( array( 'carrier_key', 'rate_id', 'delivery_type', 'service_key', 'api_base_price_rub', 'tariff_key', 'selected_tariff_object', 'Перевозчик', 'Способ доставки', 'Тип доставки', 'Населенный пункт', 'Нормализация' ) as $forbidden_meta_key ) {
	wc_checkout_smoke_assert( ! array_key_exists( $forbidden_meta_key, $cdek_item->meta ), 'CDEK checkout visible meta must not contain technical key: ' . $forbidden_meta_key );
}
$cdek_order = new WdcSmokeOrder();
$cdek_persister->persist( $cdek_order );
wc_checkout_smoke_assert( isset( $cdek_order->meta['_wdc_platform_rate_meta'], $cdek_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ), 'CDEK checkout hidden meta must keep platform rate meta and calculation data.' );
$cdek_calc = $cdek_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ];
wc_checkout_smoke_assert( 520.0 === (float) ( $cdek_calc['api']['api_base_price_rub'] ?? 0 ) && 1000 === (int) ( $cdek_calc['package']['final_weight_g'] ?? 0 ), 'CDEK checkout calculation data must preserve API and package data.' );

$session->save_rates(
	array(
		'cdek:pickup:136' => array(
			'rate_id' => 'cdek:pickup:136',
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'service_title' => 'СДЭК до пункта выдачи',
			'label' => 'СДЭК до пункта выдачи, Посылка склад-склад - 2-4 дня',
			'delivery_type' => 'pickup',
			'requires_pickup_point' => true,
			'planned_delivery_comment' => '2-4 дня',
			'delivery_comment' => '2-4 дня',
			'cost' => 350.5,
			'tariff_key' => '136',
			'tariff_title' => 'Посылка склад-склад',
			'selected_tariff_title' => 'Посылка склад-склад',
			'selected_tariff_object' => '136',
			'rate_meta' => array(
				'package' => array( 'weight_g' => 1200 ),
				'request_payload_sanitized' => array( 'to_location' => array( 'code' => 270 ) ),
			),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:cdek:pickup:136' ) );
$cdek_pickup = array(
	'id' => 'cdek:KEM7',
	'carrier_key' => 'cdek',
	'rate_id' => 'cdek:pickup:136',
	'point_code' => 'KEM7',
	'point_type' => 'POSTAMAT',
	'point_name' => 'CDEK Postamat',
	'point_address' => 'Kemerovo, Sovetskiy 10',
	'point_postcode' => '650004',
	'city_name' => 'Kemerovo',
	'region_name' => 'Kemerovo region',
	'lat' => 55.3547,
	'lng' => 86.0873,
	'point_work_time' => 'Daily 10-22',
	'description' => 'Inside the shopping center',
	'storage_notice' => 'Срок хранения 3 дня',
	'cdek_code' => 'KEM7',
	'snapshot' => array(
		'id' => 'cdek:KEM7',
		'carrier_key' => 'cdek',
		'point_code' => 'KEM7',
		'point_type' => 'POSTAMAT',
		'postcode' => '650004',
		'address' => 'Kemerovo, Sovetskiy 10',
		'city' => 'Kemerovo',
		'region' => 'Kemerovo region',
		'description' => 'Inside the shopping center',
		'storage_notice' => 'Срок хранения 3 дня',
		'cdek_code' => 'KEM7',
	),
);
$session->clear_pickup_selection( 'cdek_hidden_fields_restore_smoke' );
$errors = new WdcSmokeCheckoutErrors();
( new CheckoutValidation( $session ) )->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:cdek:pickup:136' ),
		'shipping_city' => 'Kemerovo',
		'wdc_pickup_point_code' => 'KEM7',
		'wdc_pickup_carrier_key' => 'cdek',
		'wdc_pickup_point_type' => 'POSTAMAT',
		'wdc_pickup_point_name' => 'CDEK Postamat',
		'wdc_pickup_point_address' => 'Kemerovo, Sovetskiy 10',
		'wdc_pickup_point_postcode' => '650004',
		'wdc_pickup_city_name' => 'Kemerovo',
		'wdc_pickup_region_name' => 'Kemerovo region',
		'wdc_pickup_work_time' => '0.000000',
		'wdc_pickup_description' => 'Inside the shopping center',
		'wdc_pickup_storage_notice' => 'Срок хранения 3 дня',
		'wdc_pickup_cdek_code' => 'KEM7',
	),
	$errors
);
wc_checkout_smoke_assert( array() === $errors->errors, 'CDEK pickup selected in checkout must pass validation.' );
wc_checkout_smoke_assert( 'Kemerovo, Sovetskiy 10' === (string) ( $session->checkout_pickup_point()['point_address'] ?? '' ) && 'Inside the shopping center' === (string) ( $session->checkout_pickup_point()['description'] ?? '' ) && 'KEM7' === (string) ( $session->checkout_pickup_point()['cdek_code'] ?? '' ), 'CDEK hidden fields restore must keep the full checkout pickup payload in session.' );
$cdek_pickup_order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $cdek_pickup_order );
$cdek_pickup_calc = $cdek_pickup_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ];
wc_checkout_smoke_assert( 'KEM7' === ( $cdek_pickup_calc['pickup']['point_code'] ?? '' ) && '650004' === ( $cdek_pickup_calc['pickup']['point_postcode'] ?? '' ), 'CDEK checkout order create must save point_code separately from postcode.' );
wc_checkout_smoke_assert( 'Inside the shopping center' === ( $cdek_pickup_calc['pickup']['description'] ?? '' ) && 'Срок хранения 3 дня' === ( $cdek_pickup_calc['pickup']['storage_notice'] ?? '' ), 'CDEK checkout order create must save pickup description and storage notice.' );
wc_checkout_smoke_assert( '' === ( $cdek_pickup_calc['pickup']['work_time'] ?? '' ), 'CDEK checkout order create must not save numeric zero work_time as meaningful text.' );
wc_checkout_smoke_assert( 'Kemerovo, Sovetskiy 10' === $cdek_pickup_order->shipping_address_1 && '' === $cdek_pickup_order->shipping_address_2, 'CDEK checkout order create must write pickup shipping address.' );

$session->save_rates(
	array(
		'cdek:courier:custom' => array(
			'rate_id' => 'cdek:courier:custom',
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'label' => 'СДЭК дверь тест',
			'delivery_type' => 'courier',
			'delivery_comment' => '10-14 дней',
			'planned_delivery_comment' => '10-14 дней',
			'cost' => 520.0,
			'tariff_title' => 'Посылка склад-дверь',
			'selected_tariff_title' => 'Посылка склад-дверь',
			'selected_tariff_object' => '137',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:cdek:courier:custom' ) );
$custom_cdek_item = new WdcSmokeShippingItem();
( new OrderShippingMetaPersister( $session ) )->persist_shipping_item_meta( $custom_cdek_item );
wc_checkout_smoke_assert( 'СДЭК дверь тест, Посылка склад-дверь - 10-14 дней' === $custom_cdek_item->method_title, 'CDEK checkout method title must use custom service title, selected tariff and delivery days.' );
wc_checkout_smoke_assert( 1 === substr_count( $custom_cdek_item->method_title, '10-14 дней' ), 'CDEK checkout method title must not duplicate delivery days.' );

$session->save_rates(
	array(
		'cdek:courier:no-days' => array(
			'rate_id' => 'cdek:courier:no-days',
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'label' => 'СДЭК дверь тест',
			'delivery_type' => 'courier',
			'cost' => 520.0,
			'tariff_title' => 'Посылка склад-дверь',
			'selected_tariff_title' => 'Посылка склад-дверь',
			'selected_tariff_object' => '137',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:cdek:courier:no-days' ) );
$no_days_cdek_item = new WdcSmokeShippingItem();
( new OrderShippingMetaPersister( $session ) )->persist_shipping_item_meta( $no_days_cdek_item );
wc_checkout_smoke_assert( 'СДЭК дверь тест, Посылка склад-дверь' === $no_days_cdek_item->method_title, 'CDEK checkout method title without delivery days must include method and tariff only.' );
wc_checkout_smoke_assert( array( 'Срок доставки' => 'не указан' ) === $no_days_cdek_item->meta, 'CDEK checkout visible meta without delivery days must be not specified.' );

$compact_forbidden_meta_keys = array( 'carrier_key', 'rate_id', 'delivery_type', 'service_key', 'api_base_price_rub', 'tariff_key', 'selected_tariff_object', 'Перевозчик', 'Способ доставки', 'Тип доставки', 'Населенный пункт', 'Нормализация', 'Код ПВЗ', 'Адрес ПВЗ' );

$session->save_rates(
	array(
		'russian_post_domestic:pickup' => array(
			'rate_id' => 'russian_post_domestic:pickup',
			'carrier_key' => 'russian_post_domestic',
			'service_key' => 'russian_post_domestic',
			'service_title' => 'Почта России до отделения',
			'label' => 'Почта России до отделения, Посылка 1 класса - 3-5 дней',
			'delivery_type' => 'pickup',
			'delivery_days' => array( 'min_days' => 3, 'max_days' => 5 ),
			'planned_delivery_comment' => '3-5 дней',
			'tariff_title' => 'Посылка 1 класса',
			'selected_tariff_title' => 'Посылка 1 класса',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:russian_post_domestic:pickup' ) );
$domestic_item = new WdcSmokeShippingItem();
$domestic_item->meta = array_fill_keys( $compact_forbidden_meta_keys, 'technical' );
( new OrderShippingMetaPersister( $session ) )->persist_shipping_item_meta( $domestic_item );
wc_checkout_smoke_assert( array( 'Срок доставки' => '3-5 дней' ) === $domestic_item->meta, 'Russian Post domestic checkout visible meta must contain only delivery time.' );

$session->save_rates(
	array(
		'russian_post:international' => array(
			'rate_id' => 'russian_post:international',
			'carrier_key' => 'russian_post',
			'service_key' => 'russian_post',
			'label' => 'Почта России международная - 8-12 дней',
			'delivery_type' => 'courier',
			'planned_delivery_comment' => '8-12 дней',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:russian_post:international' ) );
$international_item = new WdcSmokeShippingItem();
$international_item->meta = array_fill_keys( $compact_forbidden_meta_keys, 'technical' );
( new OrderShippingMetaPersister( $session ) )->persist_shipping_item_meta( $international_item );
wc_checkout_smoke_assert( array( 'Срок доставки' => '8-12 дней' ) === $international_item->meta, 'Russian Post international checkout visible meta must contain only delivery time.' );

$session->save_rates(
	array(
		'future:carrier' => array(
			'rate_id' => 'future:carrier',
			'carrier_key' => 'future',
			'service_key' => 'future',
			'label' => 'Future carrier',
			'delivery_type' => 'courier',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:future:carrier' ) );
$unspecified_item = new WdcSmokeShippingItem();
$unspecified_item->meta = array_fill_keys( $compact_forbidden_meta_keys, 'technical' );
( new OrderShippingMetaPersister( $session ) )->persist_shipping_item_meta( $unspecified_item );
wc_checkout_smoke_assert( array( 'Срок доставки' => 'не указан' ) === $unspecified_item->meta, 'Checkout visible meta must use not specified when delivery time is missing.' );

WC()->session->set( 'chosen_shipping_methods', array( 'legacy_method:rate' ) );
$order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
wc_checkout_smoke_assert( array() === $order->meta, 'Persister must ignore non-WDC selected shipping methods.' );

echo "WooCommerce checkout smoke test passed.\n";
