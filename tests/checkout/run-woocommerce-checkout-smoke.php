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

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql' ): string {
		return '2026-08-04 12:00:00';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = (string) $checked === (string) $current ? 'checked="checked"' : '';
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
		/** @var array<string,array<string,mixed>> */
		private array $calendar_days = array();

		public function get_results( string $query, mixed $output = null ): array {
			return array();
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( "/calendar_type = '([^']+)'.*calendar_date = '([^']+)'/s", $query, $matches ) ) {
				return $this->calendar_days[ $matches[1] . ':' . $matches[2] ] ?? null;
			}

			return null;
		}

		public function get_var( string $query ): mixed {
			if ( preg_match( "/calendar_type = '([^']+)'.*YEAR\\(calendar_date\\) = (\\d+)/s", $query, $matches ) ) {
				$count = 0;
				foreach ( $this->calendar_days as $day ) {
					if ( $matches[1] === (string) ( $day['calendar_type'] ?? '' ) && $matches[2] === substr( (string) ( $day['calendar_date'] ?? '' ), 0, 4 ) ) {
						$count++;
					}
				}

				return $count;
			}

			return 0;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->insert_id++;

			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			return true;
		}

		public function replace( string $table, array $data, array $format = array() ): bool {
			if ( str_ends_with( $table, 'wdc_calendar_days' ) ) {
				$this->calendar_days[ (string) $data['calendar_type'] . ':' . (string) $data['calendar_date'] ] = $data;
			}

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
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\Address\CheckoutAddressNormalizer;
use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\PickupMapCheckout;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
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

/**
 * @param array<string,mixed> $meta
 */
function wc_checkout_label_rate( string $title, DateRange $delivery_days, ?DateRange $original_delivery_days = null, array $meta = array(), string $delivery_type = DeliveryType::PICKUP, string $carrier_key = 'yandex_delivery' ): DeliveryRate {
	return new DeliveryRate(
		$carrier_key . ':label-smoke:' . md5( $title . serialize( $delivery_days->to_array() ) ),
		$carrier_key,
		$carrier_key,
		$carrier_key,
		$carrier_key,
		'label-smoke',
		'Label smoke',
		$delivery_type,
		$title,
		Money::from_rubles( 500 ),
		null,
		null,
		$delivery_days,
		'',
		'diagnostic delivery comment',
		array(),
		false,
		'',
		false,
		false,
		$meta,
		null,
		$original_delivery_days
	);
}

function wc_checkout_grouped_tariff_rate( string $tariff_key, string $tariff_title, int $min_days, int $max_days, string $planned_comment, float $price_rub ): DeliveryRate {
	return new DeliveryRate(
		'dpd:pickup:' . $tariff_key,
		'dpd',
		'DPD',
		'dpd',
		'DPD до ПВЗ',
		$tariff_key,
		$tariff_title,
		DeliveryType::PICKUP,
		'DPD до ПВЗ, ' . $tariff_title . ' - ' . DeliveryDaysFormatter::format( DateRange::range( $min_days, $max_days ) ),
		Money::from_rubles( $price_rub ),
		null,
		null,
		DateRange::range( $min_days, $max_days ),
		'2026-07-27',
		$planned_comment,
		array(),
		false,
		'',
		false,
		false,
		array(
			'tariff_selector_group' => true,
			'checkout_group_id' => 'dpd:pickup',
		),
		Money::from_rubles( $price_rub ),
		DateRange::range( $min_days, $max_days )
	);
}

function wc_checkout_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param array<string,array<string,mixed>> $rates
 * @param array<string,mixed>               $city_context
 * @return array<string,mixed>
 */
function wc_checkout_pickup_map_initial_context( array $rates, array $city_context, string $chosen_method ): array {
	$session = new CheckoutSessionManager();
	$session->save_rates( $rates );
	$session->save_city_context( $city_context );
	WC()->session->set( 'chosen_shipping_methods', array( $chosen_method ) );
	$checkout = new PickupMapCheckout( $session, new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.128.21' ), new SettingsRepository() );
	$method = new ReflectionMethod( $checkout, 'initial_context' );
	$method->setAccessible( true );
	$context = $method->invoke( $checkout );

	return is_array( $context ) ? $context : array();
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

final class WdcCheckoutSmokeFallbackNormalizer implements AddressNormalizerInterface {
	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		return new AddressNormalizationResult(
			$input,
			new Address(
				country_code: strtoupper( (string) ( $context['country_code'] ?? '' ) ),
				region_name: (string) ( $context['region_name'] ?? '' ),
				city: (string) ( $context['city'] ?? '' ),
				postcode: (string) ( $context['postcode'] ?? '' ),
				raw_address: $input,
				fallback: true
			),
			false,
			0.0,
			'fallback',
			'fallback',
			''
		);
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
		$logger,
		wc_checkout_smoke_lead_time_normalizer( 0 )
	);
}

function wc_checkout_smoke_lead_time_normalizer( int $processing_days = 0 ): DeliveryLeadTimeNormalizer {
	$settings = new SettingsRepository();
	$settings->set( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY, $processing_days );
	$timezone = new TimezoneService();
	$formatter = new DeliveryDateFormatter();

	return new DeliveryLeadTimeNormalizer(
		$settings,
		new DeliveryServiceSettingsRepository(),
		new DeliveryDateCalculator( new CalendarService( new CalendarRepository(), new YearGenerator(), $settings, $timezone ), $timezone, $formatter ),
		$formatter
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

$yandex_pickup_label = $rate_mapper->map( wc_checkout_label_rate( 'Яндекс до ПВЗ - 2 дня', DateRange::single( 4 ), DateRange::single( 2 ) ) )['label'];
wc_checkout_smoke_assert( 'Яндекс до ПВЗ - 4 дня' === $yandex_pickup_label && ! str_contains( $yandex_pickup_label, '—' ) && ! str_contains( $yandex_pickup_label, '2 дня' ) && 1 === substr_count( $yandex_pickup_label, '4 дня' ) && 1 === substr_count( $yandex_pickup_label, ' - ' ), 'Yandex pickup WC label must replace the original API delivery suffix with the final rule-adjusted delivery days and one shared separator.' );
$yandex_courier_label = $rate_mapper->map( wc_checkout_label_rate( 'Яндекс курьером - 2 дня', DateRange::single( 4 ), DateRange::single( 2 ), array(), DeliveryType::COURIER ) )['label'];
wc_checkout_smoke_assert( 'Яндекс курьером - 4 дня' === $yandex_courier_label && ! str_contains( $yandex_courier_label, '—' ) && ! str_contains( $yandex_courier_label, '2 дня' ) && 1 === substr_count( $yandex_courier_label, '4 дня' ) && 1 === substr_count( $yandex_courier_label, ' - ' ), 'Yandex courier WC label must use only final rule-adjusted delivery days and one shared separator.' );
$other_carrier_label = $rate_mapper->map( wc_checkout_label_rate( 'Другая служба — 2-4 дня', DateRange::range( 4, 6 ), DateRange::range( 2, 4 ), carrier_key: 'other_carrier' ) )['label'];
wc_checkout_smoke_assert( 'Другая служба — 4-6 дней' === $other_carrier_label && ! str_contains( $other_carrier_label, '2-4 дня' ), 'Another carrier single-rate WC label must replace only the original delivery range suffix.' );
$normalized_range_label = $rate_mapper->map( wc_checkout_label_rate( 'DPD до двери, DPD Эконом - 4-5 дней', DateRange::range( 6, 7 ), DateRange::range( 4, 5 ), carrier_key: 'dpd' ) )['label'];
wc_checkout_smoke_assert( 'DPD до двери, DPD Эконом - 6-7 дней' === $normalized_range_label && ! str_contains( $normalized_range_label, '4-5 дней' ) && 1 === substr_count( $normalized_range_label, 'дней' ), 'Normalized DPD label must replace carrier raw range instead of appending a second delivery range.' );
$ruled_range_label = $rate_mapper->map( wc_checkout_label_rate( 'DPD до двери, DPD Эконом - 4-5 дней', DateRange::range( 8, 9 ), DateRange::range( 4, 5 ), carrier_key: 'dpd' ) )['label'];
wc_checkout_smoke_assert( 'DPD до двери, DPD Эконом - 8-9 дней' === $ruled_range_label && ! str_contains( $ruled_range_label, '4-5 дней' ) && 1 === substr_count( $ruled_range_label, 'дней' ), 'Rule-adjusted DPD label must keep one final delivery range after normalization and rules.' );
$normalizer_regression_package = \WallsShop\WDC\Domain\Package\Package::from_items( array(), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
$normalizer_regression_request = new \WallsShop\WDC\Domain\Quote\QuoteRequest( 'RU', new \WallsShop\WDC\Domain\Address\Address( country_code: 'RU', city: 'Новосибирск' ), $normalizer_regression_package, '', Money::from_rubles( 1000 ), '2026-08-04' );
$normalizer_regression_rate = wc_checkout_label_rate( 'DPD до двери, DPD Эконом - 4-5 дней', DateRange::range( 4, 5 ), null, array(), DeliveryType::COURIER, 'dpd' );
$normalizer_regression_normalized = wc_checkout_smoke_lead_time_normalizer( 2 )->normalize( $normalizer_regression_rate, null, $normalizer_regression_request );
$normalizer_regression_label = $rate_mapper->map( $normalizer_regression_normalized )['label'];
wc_checkout_smoke_assert( 'DPD до двери, DPD Эконом - 6-7 дней' === $normalizer_regression_label && 4 === $normalizer_regression_normalized->original_delivery_days?->min_days && 5 === $normalizer_regression_normalized->original_delivery_days?->max_days && ! str_contains( $normalizer_regression_label, '4-5 дней' ) && 1 === substr_count( $normalizer_regression_label, 'дней' ), 'DeliveryLeadTimeNormalizer must preserve raw carrier days so mapper replaces the old title suffix after shop processing.' );
$normalizer_regression_rule = new Rule( null, 'Add delivery days', true, 10, 'default', '', RuleActionTypes::CHANGE_DELIVERY_DAYS, RuleOperationTypes::INCREASE, 2, RuleOperationBases::CALENDAR_DAYS, false, false );
$normalizer_regression_context = new \WallsShop\WDC\Rules\Domain\RuleEvaluationContext(
	Money::from_rubles( 1000 ),
	$normalizer_regression_normalized->price,
	$normalizer_regression_package,
	$normalizer_regression_request->destination,
	$normalizer_regression_normalized->delivery_type,
	'',
	'2026-08-04',
	array(),
	array_merge(
		$normalizer_regression_normalized->meta,
		array(
			'original_delivery_days' => $normalizer_regression_normalized->delivery_days->min_days,
			'original_delivery_min_days' => $normalizer_regression_normalized->delivery_days->min_days,
			'original_delivery_max_days' => $normalizer_regression_normalized->delivery_days->max_days,
		)
	)
);
$normalizer_regression_applied = ( new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ) )->apply( $normalizer_regression_normalized, $normalizer_regression_context, array( $normalizer_regression_rule ) )['rate'];
$normalizer_regression_rule_label = $rate_mapper->map( $normalizer_regression_applied )['label'];
wc_checkout_smoke_assert( 'DPD до двери, DPD Эконом - 8-9 дней' === $normalizer_regression_rule_label && 4 === $normalizer_regression_applied->original_delivery_days?->min_days && 5 === $normalizer_regression_applied->original_delivery_days?->max_days && ! str_contains( $normalizer_regression_rule_label, '4-5 дней' ) && 1 === substr_count( $normalizer_regression_rule_label, 'дней' ), 'RuleAppliedRateBuilder must keep raw carrier days so mapper replaces the old title suffix after rules.' );
$title_without_days = $rate_mapper->map( wc_checkout_label_rate( 'Служба доставки', DateRange::single( 5 ) ) )['label'];
wc_checkout_smoke_assert( 'Служба доставки - 5 дней' === $title_without_days && ! str_contains( $title_without_days, '—' ) && ! str_ends_with( $title_without_days, ':' ), 'Single-rate title without delivery days must append the final label once with the shared separator and no manual colon.' );
$title_with_final_days = $rate_mapper->map( wc_checkout_label_rate( 'Служба доставки — 5 дней', DateRange::single( 5 ), DateRange::single( 5 ) ) )['label'];
wc_checkout_smoke_assert( 'Служба доставки — 5 дней' === $title_with_final_days && 1 === substr_count( $title_with_final_days, '5 дней' ), 'Single-rate title that already ends with final delivery days must remain unchanged.' );
$title_without_final_days = $rate_mapper->map( wc_checkout_label_rate( 'Служба без срока', new DateRange() ) )['label'];
wc_checkout_smoke_assert( 'Служба без срока' === $title_without_final_days, 'Single-rate title must remain unchanged when final delivery days are empty.' );
$grouped_title = $rate_mapper->map( wc_checkout_label_rate( 'Сгруппированная служба', DateRange::single( 7 ), DateRange::single( 2 ), array( 'domestic_tariff_grouped' => true, 'tariff_variants' => array( array( 'object_code' => 'A' ), array( 'object_code' => 'B' ) ) ) ) )['label'];
wc_checkout_smoke_assert( 'Сгруппированная служба' === $grouped_title, 'Grouped/tariff-selector WC label must keep its existing format without an appended common delivery term.' );

$render_method = (object) array(
	'id' => 'single:pickup',
	'meta_data' => array(
		'carrier_key' => 'demo',
		'rate_id' => 'single:pickup',
		'delivery_type' => DeliveryType::PICKUP,
		'requires_pickup_point' => false,
		'planned_delivery_comment' => '4 дня',
		'comments' => array( 'Пользовательский комментарий' ),
	),
);
ob_start();
( new CheckoutRateRenderer() )->render( $render_method );
$rendered_rate_html = (string) ob_get_clean();
	wc_checkout_smoke_assert( str_contains( $rendered_rate_html, '4 дня' ) && str_contains( $rendered_rate_html, 'Пользовательский комментарий' ), 'Single-rate renderer must output planned_delivery_comment and preserve ordinary meta comments.' );

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

$cdek_by_pickup_rate = array(
	'rate_id' => 'cdek:pickup:136',
	'id' => 'cdek:pickup:136',
	'carrier_key' => 'cdek',
	'service_key' => 'cdek',
	'delivery_type' => DeliveryType::PICKUP,
	'requires_pickup_point' => true,
	'meta' => array( 'location' => array( 'cdek_to_country_code' => 'BY', 'cdek_to_city_code' => 9220, 'cdek_to_city_name' => 'Минск' ), 'country_code' => 'BY' ),
);
$cdek_kz_pickup_rate = $cdek_by_pickup_rate;
$cdek_kz_pickup_rate['meta'] = array( 'location' => array( 'cdek_to_country_code' => 'KZ', 'cdek_to_city_code' => 152 ), 'country_code' => 'KZ' );
$by_context = wc_checkout_pickup_map_initial_context( array( 'cdek:pickup:136' => $cdek_by_pickup_rate ), array( 'country_code' => 'BY', 'city_name' => 'Minsk', 'city_code' => 9220 ), 'wdc_platform_delivery:cdek:pickup:136' );
wc_checkout_smoke_assert( 'BY' === (string) ( $by_context['country_code'] ?? '' ) && 9220 === (int) ( $by_context['cdek_city_code'] ?? 0 ), 'Generic pickup map must allow active CDEK BY pickup rate for BY checkout destination.' );
$by_rate_meta_context = wc_checkout_pickup_map_initial_context( array( 'cdek:pickup:136' => $cdek_by_pickup_rate ), array( 'country_code' => 'BY', 'city_name' => '' ), 'wdc_platform_delivery:cdek:pickup:136' );
wc_checkout_smoke_assert( 'BY' === (string) ( $by_rate_meta_context['country_code'] ?? '' ) && 9220 === (int) ( $by_rate_meta_context['city_code'] ?? 0 ) && 9220 === (int) ( $by_rate_meta_context['cdek_city_code'] ?? 0 ) && 'Минск' === (string) ( $by_rate_meta_context['city_name'] ?? '' ), 'PickupMapCheckout must canonicalize CDEK rate location cdek_to_* fields for BY manual city context.' );
$kz_context = wc_checkout_pickup_map_initial_context( array( 'cdek:pickup:136' => $cdek_kz_pickup_rate ), array( 'country_code' => 'KZ', 'city_name' => 'Almaty', 'city_code' => 152 ), 'wdc_platform_delivery:cdek:pickup:136' );
wc_checkout_smoke_assert( 'KZ' === (string) ( $kz_context['country_code'] ?? '' ) && 152 === (int) ( $kz_context['cdek_city_code'] ?? 0 ), 'Generic pickup map must allow active CDEK KZ pickup rate for KZ checkout destination.' );
$mismatch_context = wc_checkout_pickup_map_initial_context( array( 'cdek:pickup:136' => $cdek_by_pickup_rate ), array( 'country_code' => 'KZ', 'city_name' => 'Almaty', 'city_code' => 152 ), 'wdc_platform_delivery:cdek:pickup:136' );
wc_checkout_smoke_assert( array() === $mismatch_context, 'Generic pickup map must block checkout country when it differs from active pickup rate country.' );
$no_rate_context = wc_checkout_pickup_map_initial_context( array(), array( 'country_code' => 'BY', 'city_name' => 'Minsk', 'city_code' => 9220 ), '' );
wc_checkout_smoke_assert( array() === $no_rate_context, 'Generic pickup map must keep legacy RU-only fallback when no active pickup rate exists.' );
$russian_post_pickup_rate = array(
	'rate_id' => 'russian_post_domestic:pickup',
	'id' => 'russian_post_domestic:pickup',
	'carrier_key' => 'russian_post_domestic',
	'service_key' => 'russian_post_domestic',
	'delivery_type' => DeliveryType::PICKUP,
	'requires_pickup_point' => true,
);
$russian_post_by_context = wc_checkout_pickup_map_initial_context( array( 'russian_post_domestic:pickup' => $russian_post_pickup_rate ), array( 'country_code' => 'BY', 'city_name' => 'Minsk' ), 'wdc_platform_delivery:russian_post_domestic:pickup' );
wc_checkout_smoke_assert( array() === $russian_post_by_context, 'Generic pickup map must not make Russian Post pickup international without a rate country.' );
$pickup_map_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/PickupMapCheckout.php' );
$pickup_checkout_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' );
wc_checkout_smoke_assert( ! str_contains( $pickup_map_source, 'CdekSettings' ), 'Generic PickupMapCheckout must not import or reference CdekSettings.' );
wc_checkout_smoke_assert( ! str_contains( $pickup_checkout_js, "['RU', 'AM', 'BY', 'KZ', 'KG']" ) && ! str_contains( $pickup_checkout_js, 'RU, AM, BY, KZ, KG' ), 'Frontend pickup checkout map must not hardcode the CDEK supported country allowlist.' );

$fingerprint_selection = array(
	'id' => 'cdek:MIN40',
	'carrier_key' => 'cdek',
	'service_key' => 'cdek',
	'pickup_family' => 'cdek:pickup',
	'rate_id' => 'cdek:pickup:136',
	'point_code' => 'MIN40',
	'point_address' => 'Minsk, Point',
	'city_name' => 'Minsk',
	'region_name' => 'Minsk region',
	'country_code' => 'BY',
	'snapshot' => array( 'country_code' => 'BY', 'city' => 'Minsk', 'region' => 'Minsk region', 'point_code' => 'MIN40' ),
);
$fingerprint_session = new CheckoutSessionManager();
$fingerprint_session->save_city_context( array( 'country_code' => 'BY', 'city_name' => 'Minsk', 'region_name' => 'Minsk region' ) );
$fingerprint_session->save_pickup_selection( $fingerprint_selection );
$by_fingerprint = (string) ( $fingerprint_session->pickup_selections()['cdek:pickup']['destination_fingerprint'] ?? '' );
wc_checkout_smoke_assert( str_starts_with( $by_fingerprint, 'country=BY|' ), 'Pickup destination fingerprint must include BY country code.' );
wc_checkout_smoke_assert( true === $fingerprint_session->pickup_selection_matches( 'cdek', 'cdek:pickup:137' ), 'Changing only the CDEK pickup tariff inside the same destination/family must preserve selection.' );
$fingerprint_session->save_city_context( array( 'country_code' => 'KZ', 'city_name' => 'Minsk', 'region_name' => 'Minsk region' ) );
wc_checkout_smoke_assert( false === $fingerprint_session->pickup_selection_matches( 'cdek', 'cdek:pickup:136' ), 'Changing checkout country from BY to KZ with the same city text must invalidate pickup selection.' );
$kz_fingerprint_session = new CheckoutSessionManager();
$kz_fingerprint_session->save_city_context( array( 'country_code' => 'KZ', 'city_name' => 'Minsk', 'region_name' => 'Minsk region' ) );
$kz_fingerprint_selection = $fingerprint_selection;
$kz_fingerprint_selection['country_code'] = 'KZ';
$kz_fingerprint_selection['snapshot']['country_code'] = 'KZ';
$kz_fingerprint_session->save_pickup_selection( $kz_fingerprint_selection );
$kz_fingerprint = (string) ( $kz_fingerprint_session->pickup_selections()['cdek:pickup']['destination_fingerprint'] ?? '' );
wc_checkout_smoke_assert( '' !== $by_fingerprint && '' !== $kz_fingerprint && $by_fingerprint !== $kz_fingerprint, 'Pickup destination fingerprint must distinguish BY/Minsk from KZ/Minsk.' );
$legacy_selection_session = new CheckoutSessionManager();
$legacy_selection_session->save_city_context( array( 'city_name' => 'Minsk', 'region_name' => 'Minsk region' ) );
$legacy_selection = $fingerprint_selection;
unset( $legacy_selection['country_code'], $legacy_selection['snapshot']['country_code'] );
$legacy_selection_session->save_pickup_selection( $legacy_selection );
wc_checkout_smoke_assert( is_bool( $legacy_selection_session->pickup_selection_matches( 'cdek', 'cdek:pickup:136' ) ), 'Legacy pickup selection without country_code must not cause a fatal error.' );
$address_runtime_reflection = new ReflectionClass( CheckoutAddressRuntime::class );
$address_runtime = $address_runtime_reflection->newInstanceWithoutConstructor();
$conflict_method = $address_runtime_reflection->getMethod( 'posted_destination_conflicts_with_pickup' );
$conflict_method->setAccessible( true );
wc_checkout_smoke_assert( true === $conflict_method->invoke( $address_runtime, array( 'country_code' => 'KZ', 'city' => 'Minsk' ), $fingerprint_selection ), 'Checkout destination conflict detection must treat country change as a conflict.' );
wc_checkout_smoke_assert( false === $conflict_method->invoke( $address_runtime, array( 'country_code' => 'BY', 'city' => 'Minsk' ), $fingerprint_selection ), 'Checkout destination conflict detection must allow the same pickup country/city.' );

$location_db = new class extends wpdb {
	public array $locations = array();
};
$location_db->locations = array(
	array(
		'id' => 101,
		'country_code' => 'RU',
		'region_name' => 'Московская область',
		'region_code' => '77',
		'city_name' => 'Минск',
		'place_name' => 'Минск',
		'place_type' => 'г',
		'display_name' => 'Московская область, г Минск',
		'postal_code' => '101000',
		'searchable_text' => 'московская область г минск минск 101000',
		'fias_id' => 'ru-minsk-fias',
		'gar_object_id' => 101,
		'active' => 1,
	),
	array(
		'id' => 102,
		'country_code' => 'BY',
		'region_name' => 'Минская область',
		'city_name' => 'Минск',
		'place_name' => 'Минск',
		'place_type' => 'г',
		'display_name' => 'Минская область, г Минск',
		'postal_code' => '220000',
		'searchable_text' => 'минская область г минск минск 220000',
		'active' => 1,
	),
);
$location_repository = new LocationRepository( $location_db );
$country_city_resolver = new CheckoutCityResolver( $location_repository, new CheckoutLocationSearch( new LocationSearchService( $location_repository ) ) );
$by_minsk_location = $country_city_resolver->resolve_city( 'Минск', 'BY' );
wc_checkout_smoke_assert( $by_minsk_location instanceof Location && 'BY' === $by_minsk_location->country_code, 'CheckoutCityResolver must resolve same-name city within requested BY country.' );
wc_checkout_smoke_assert( '220000' === $country_city_resolver->resolve_postcode( 'Минск', 'BY' ), 'CheckoutCityResolver must resolve postcode from same-country BY location.' );

$manual_location_db = new class extends wpdb {
	public array $locations = array();
};
$manual_location_db->locations = array(
	array(
		'id' => 201,
		'country_code' => 'RU',
		'region_name' => 'Московская область',
		'region_code' => '77',
		'city_name' => 'Минск',
		'place_name' => 'Минск',
		'place_type' => 'г',
		'display_name' => 'Московская область, г Минск',
		'postal_code' => '101000',
		'searchable_text' => 'московская область г минск минск 101000',
		'fias_id' => 'ru-only-minsk-fias',
		'gar_object_id' => 201,
		'active' => 1,
	),
);
$manual_repository = new LocationRepository( $manual_location_db );
$manual_city_resolver = new CheckoutCityResolver( $manual_repository, new CheckoutLocationSearch( new LocationSearchService( $manual_repository ) ) );
wc_checkout_smoke_assert( null === $manual_city_resolver->resolve_city( 'Минск', 'BY' ), 'CheckoutCityResolver must not return RU namesake for BY city lookup.' );
wc_checkout_smoke_assert( null === $manual_city_resolver->resolve_postcode( 'Минск', 'BY' ), 'CheckoutCityResolver must not autofill BY postcode from RU namesake.' );
$manual_session = new CheckoutSessionManager();
$manual_runtime = new CheckoutAddressRuntime(
	new CheckoutAddressNormalizer( new WdcCheckoutSmokeFallbackNormalizer(), new WdcCheckoutSmokeFallbackNormalizer() ),
	$manual_city_resolver,
	$manual_session
);
$manual_runtime->resolve_checkout_address(
	array(
		'shipping_country' => 'BY',
		'shipping_state' => 'Минская область',
		'shipping_city' => 'Минск',
		'shipping_postcode' => '',
		'shipping_address_1' => 'проспект Независимости',
	)
);
$manual_context = $manual_session->city_context();
wc_checkout_smoke_assert( 'BY' === (string) ( $manual_context['country_code'] ?? '' ) && 'Минск' === (string) ( $manual_context['city_name'] ?? '' ), 'Manual BY checkout city context must preserve country and city when local BY location is absent.' );
wc_checkout_smoke_assert( 'Минская область' === (string) ( $manual_context['region_name'] ?? '' ), 'Manual BY checkout city context must preserve shipping_state region.' );
wc_checkout_smoke_assert( '' === (string) ( $manual_context['postcode'] ?? '' ), 'Manual BY checkout city context must not autofill postcode from RU namesake.' );

$checkout_formatter = LocationDisplayNameFormatter::from_rules( array() );
$formatter_minsk = Location::from_array(
	array(
		'id' => 210003,
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'region_type' => 'область',
		'district_name' => 'Минский',
		'district_type' => 'р-н',
		'city_name' => 'Минск',
		'city_type' => 'г',
		'settlement_name' => 'Минск',
		'settlement_type' => 'г',
		'place_name' => 'Минск',
		'place_type' => 'г',
		'display_name' => 'Минская обл., Минский р-н, г Минск',
		'active' => true,
	)
);
$formatter_dzerzhinsk = Location::from_array(
	array(
		'id' => 210005,
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'region_type' => 'область',
		'district_name' => 'Дзержинский',
		'district_type' => 'р-н',
		'city_name' => 'Дзержинск',
		'city_type' => 'г',
		'settlement_name' => 'Дзержинск',
		'settlement_type' => 'г',
		'place_name' => 'Дзержинск',
		'place_type' => 'г',
		'display_name' => 'Минская обл., Дзержинский р-н, г Дзержинск',
		'active' => true,
	)
);
$formatter_aleksandrovo = Location::from_array(
	array(
		'id' => 228315,
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'region_type' => 'область',
		'district_name' => 'Минский',
		'district_type' => 'р-н',
		'settlement_name' => 'Александрово',
		'settlement_type' => 'д',
		'place_name' => 'Александрово',
		'place_type' => 'д',
		'display_name' => 'Минская обл., Минский р-н, д Александрово',
		'active' => true,
	)
);
wc_checkout_smoke_assert( 'г Минск - Минский р-н, Минская область' === $checkout_formatter->format_checkout_location_option( $formatter_minsk ), 'BY Minsk picker option must keep contextual district and region.' );
wc_checkout_smoke_assert( 'г Минск' === $checkout_formatter->format_checkout_city_value( $formatter_minsk ), 'BY Minsk checkout city_value must be own city only.' );
wc_checkout_smoke_assert( 'Минская область' === $checkout_formatter->format_checkout_state_value( $formatter_minsk ), 'BY Minsk checkout state_value must be region only.' );
wc_checkout_smoke_assert( 'г Дзержинск' === $checkout_formatter->format_checkout_city_value( $formatter_dzerzhinsk ), 'BY Dzerzhinsk checkout city_value must be own city only.' );
wc_checkout_smoke_assert( 'д Александрово' === $checkout_formatter->format_checkout_city_value( $formatter_aleksandrovo ), 'BY Aleksandrovo checkout city_value must preserve village type.' );

$selected_location_session = new CheckoutSessionManager();
$selected_location_runtime = new CheckoutAddressRuntime(
	new CheckoutAddressNormalizer( new WdcCheckoutSmokeFallbackNormalizer(), new WdcCheckoutSmokeFallbackNormalizer() ),
	$manual_city_resolver,
	$selected_location_session
);
$selected_location_runtime->resolve_checkout_address(
	array(
		'shipping_country' => 'BY',
		'shipping_state' => 'Минская область',
		'shipping_city' => 'г Минск',
		'shipping_postcode' => '',
		'shipping_address_1' => 'проспект Независимости',
		'wdc_platform_location_id' => '210003',
		'wdc_platform_location_country_code' => 'BY',
		'wdc_platform_location_city_name' => 'Минск',
		'wdc_platform_location_city_type' => 'г',
		'wdc_platform_location_place_name' => 'Минск',
		'wdc_platform_location_place_type' => 'г',
		'wdc_platform_location_district_name' => 'Минский',
		'wdc_platform_location_district_type' => 'р-н',
		'wdc_platform_location_region_name' => 'Минская',
		'wdc_platform_location_region_type' => 'обл.',
		'wdc_platform_location_display_name' => 'Минская обл., Минский р-н, г Минск',
		'wdc_platform_location_selected_source' => 'modal',
	)
);
$selected_location_context = $selected_location_session->city_context();
wc_checkout_smoke_assert( 'BY' === (string) ( $selected_location_context['country_code'] ?? '' ) && '210003' === (string) ( $selected_location_context['location_id'] ?? '' ), 'Hidden BY selected location must save local DB city context.' );
wc_checkout_smoke_assert( 'Минск' === (string) ( $selected_location_context['city_name'] ?? '' ) && 'Минск' === (string) ( $selected_location_context['settlement_name'] ?? '' ), 'Hidden BY selected location must keep canonical city/settlement names without type or hierarchy.' );
wc_checkout_smoke_assert( 'Минская' === (string) ( $selected_location_context['region_name'] ?? '' ), 'Hidden BY selected location must keep canonical region name.' );
wc_checkout_smoke_assert( 'local_db' !== (string) ( $selected_location_context['source'] ?? '' ) || 'г Минск' !== (string) ( $selected_location_context['city_name'] ?? '' ), 'Hidden selected location must not save visible typed city as canonical city_name.' );

$country_mismatch_session = new CheckoutSessionManager();
$country_mismatch_session->save_city_context( array( 'country_code' => 'RU', 'city_name' => 'Новосибирск', 'region_name' => 'Новосибирская область' ) );
$country_mismatch_runtime = new CheckoutAddressRuntime(
	new CheckoutAddressNormalizer( new WdcCheckoutSmokeFallbackNormalizer(), new WdcCheckoutSmokeFallbackNormalizer() ),
	$manual_city_resolver,
	$country_mismatch_session
);
$country_mismatch_runtime->resolve_checkout_address(
	array(
		'shipping_country' => 'BY',
		'shipping_city' => '',
		'shipping_state' => '',
		'wdc_platform_location_id' => '101',
		'wdc_platform_location_country_code' => 'RU',
		'wdc_platform_location_city_name' => 'Новосибирск',
		'wdc_platform_location_display_name' => 'Новосибирская обл., г Новосибирск',
	)
);
$country_mismatch_context = $country_mismatch_session->city_context();
wc_checkout_smoke_assert( 'RU' !== (string) ( $country_mismatch_context['country_code'] ?? '' ) && '101' !== (string) ( $country_mismatch_context['location_id'] ?? '' ), 'Server-side checkout runtime must reject selected location metadata from another country.' );

$large_region_db = new class extends wpdb {
	public array $locations = array();
};
$large_region_db->locations[] = array(
	'id' => 210003,
	'country_code' => 'BY',
	'region_name' => 'Минская',
	'region_type' => 'обл.',
	'district_name' => 'Минский',
	'district_type' => 'р-н',
	'city_name' => 'Минск',
	'city_type' => 'г',
	'settlement_name' => 'Минск',
	'settlement_type' => 'г',
	'place_name' => 'Минск',
	'place_type' => 'г',
	'display_name' => 'Минская обл., Минский р-н, г Минск',
	'searchable_text' => 'by минская обл минский р-н г минск',
	'active' => 1,
);
$large_region_db->locations[] = array(
	'id' => 210004,
	'country_code' => 'RU',
	'region_name' => 'Московская область',
	'city_name' => 'Минск',
	'place_name' => 'Минск',
	'place_type' => 'г',
	'display_name' => 'Московская область, г Минск',
	'searchable_text' => 'московская область г минск',
	'active' => 1,
);
$large_region_db->locations[] = array(
	'id' => 210005,
	'country_code' => 'BY',
	'region_name' => 'Минская',
	'region_type' => 'обл.',
	'district_name' => 'Дзержинский',
	'district_type' => 'р-н',
	'city_name' => 'Дзержинск',
	'city_type' => 'г',
	'settlement_name' => 'Дзержинск',
	'settlement_type' => 'г',
	'place_name' => 'Дзержинск',
	'place_type' => 'г',
	'display_name' => 'Минская обл., Дзержинский р-н, г Дзержинск',
	'searchable_text' => 'by минская обл дзержинский р-н г дзержинск',
	'active' => 1,
);
$large_region_db->locations[] = array(
	'id' => 210006,
	'country_code' => 'RU',
	'region_name' => 'Новосибирская',
	'region_type' => 'обл.',
	'district_name' => '',
	'city_name' => 'Новосибирск',
	'city_type' => 'г',
	'settlement_name' => 'Новосибирск',
	'place_name' => 'Новосибирск',
	'place_type' => 'г',
	'display_name' => 'Новосибирск',
	'searchable_text' => 'новосибирская обл г новосибирск',
	'fias_id' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456',
	'gar_object_id' => 210006,
	'active' => 1,
);
foreach ( range( 1, 1200 ) as $index ) {
	$name = sprintf( 'А-региональный-%04d', $index );
	$large_region_db->locations[] = array(
		'id' => 211000 + $index,
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'region_type' => 'обл.',
		'district_name' => 'Минский',
		'district_type' => 'р-н',
		'city_name' => '',
		'city_type' => '',
		'settlement_name' => $name,
		'settlement_type' => 'д',
		'place_name' => $name,
		'place_type' => 'д',
		'display_name' => 'Минская обл., Минский р-н, д ' . $name,
		'searchable_text' => 'by минская обл минский р-н д ' . mb_strtolower( $name, 'UTF-8' ),
		'active' => 1,
	);
}
$large_region_search = new CheckoutLocationSearch( new LocationSearchService( new LocationRepository( $large_region_db ) ) );
$large_minsk = $large_region_search->search_for_picker( 'минск', 100, 10, '', 'BY' );
$large_minsk_ids = array_map( static fn( Location $location ): int => (int) $location->id, $large_minsk['items'] ?? array() );
wc_checkout_smoke_assert( 210003 === (int) ( $large_minsk_ids[0] ?? 0 ), 'BY Minsk checkout search must keep exact own-name result ahead of large Minsk-region context matches.' );
wc_checkout_smoke_assert( ! in_array( 210004, $large_minsk_ids, true ), 'BY checkout search must exclude RU namesake when country_code=BY.' );
$large_region = $large_region_search->search_for_picker( 'минская область', 100, 10, '', 'BY' );
wc_checkout_smoke_assert( (int) ( $large_region['total'] ?? 0 ) > 10 && ! empty( $large_region['groups'] ), 'BY region search must still return Minsk-region context results and groups.' );
$large_dzerzhinsk = $large_region_search->search_for_picker( 'дзержинск', 100, 10, '', 'BY' );
wc_checkout_smoke_assert( 210005 === (int) ( ( $large_dzerzhinsk['items'][0] ?? null ) instanceof Location ? $large_dzerzhinsk['items'][0]->id : 0 ), 'BY Dzerzhinsk checkout search keeps exact own-name result.' );
$ru_novosibirsk = $large_region_search->search_for_picker( 'новосибирск', 100, 10, '', 'RU' );
wc_checkout_smoke_assert( 210006 === (int) ( ( $ru_novosibirsk['items'][0] ?? null ) instanceof Location ? $ru_novosibirsk['items'][0]->id : 0 ), 'RU checkout exact city search still works with country_code=RU.' );
$ru_moscow_region = $large_region_search->search_for_picker( 'московская область', 100, 10, '', 'RU' );
wc_checkout_smoke_assert( in_array( 210004, array_map( static fn( Location $location ): int => (int) $location->id, $ru_moscow_region['items'] ?? array() ), true ), 'RU region search still returns region-context locations.' );

$exact_after_prefix_db = new class extends wpdb {
	public array $locations = array();
};
foreach ( range( 1, 1200 ) as $index ) {
	$name = sprintf( 'Минск-%04d', $index );
	$exact_after_prefix_db->locations[] = array(
		'id' => 220000 + $index,
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'region_type' => 'обл.',
		'district_name' => 'Минский',
		'district_type' => 'р-н',
		'city_name' => '',
		'city_type' => '',
		'settlement_name' => $name,
		'settlement_type' => 'д',
		'place_name' => $name,
		'place_type' => 'д',
		'display_name' => 'Минская обл., Минский р-н, д ' . $name,
		'searchable_text' => 'by минская обл минский р-н д ' . mb_strtolower( $name, 'UTF-8' ),
		'active' => 1,
	);
}
$exact_after_prefix_db->locations[] = array(
	'id' => 210003,
	'country_code' => 'BY',
	'region_name' => 'Минская',
	'region_type' => 'обл.',
	'district_name' => 'Минский',
	'district_type' => 'р-н',
	'city_name' => 'Минск',
	'city_type' => 'г',
	'settlement_name' => 'Минск',
	'settlement_type' => 'г',
	'place_name' => 'Минск',
	'place_type' => 'г',
	'display_name' => 'Минская обл., Минский р-н, г Минск',
	'searchable_text' => 'by минская обл минский р-н г минск',
	'active' => 1,
);
$exact_after_prefix_search = new CheckoutLocationSearch( new LocationSearchService( new LocationRepository( $exact_after_prefix_db ) ) );
$exact_after_prefix = $exact_after_prefix_search->search_for_picker( 'минск', 100, 10, '', 'BY' );
$exact_after_prefix_ids = array_map( static fn( Location $location ): int => (int) $location->id, $exact_after_prefix['items'] ?? array() );
wc_checkout_smoke_assert( 210003 === (int) ( $exact_after_prefix_ids[0] ?? 0 ), 'in-memory direct candidate ordering keeps exact BY Minsk before 1200 prefix own-name rows.' );

$production_search_db = new class extends wpdb {
	public array $direct_rows = array();
	public array $broad_rows = array();
	public array $queries = array();
	public array $last_prepare_args = array();

	public function __construct() {
		unset( $this->locations );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->queries[] = $query;
		$this->last_prepare_args = $args;

		return $query;
	}

	public function get_results( string $query, mixed $output = null ): array {
		unset( $output );
		if ( str_contains( $query, 'l.region_name LIKE' ) || str_contains( $query, 'l.district_name LIKE' ) ) {
			return $this->broad_rows;
		}

		return $this->direct_rows;
	}
};
$production_search_db->direct_rows = array( $large_region_db->locations[0] );
$production_search_db->broad_rows = array_slice( $large_region_db->locations, 4, 900 );
$production_picker = new CheckoutLocationSearch( new LocationSearchService( new LocationRepository( $production_search_db ) ) );
$production_minsk = $production_picker->search_for_picker( 'минск', 100, 10, '', 'BY' );
$production_minsk_ids = array_map( static fn( Location $location ): int => (int) $location->id, $production_minsk['items'] ?? array() );
$production_sql = implode( "\n", $production_search_db->queries );
wc_checkout_smoke_assert( 210003 === (int) ( $production_minsk_ids[0] ?? 0 ), 'production-path checkout search includes direct BY Minsk before broad region candidates.' );
wc_checkout_smoke_assert( str_contains( $production_sql, 'l.place_name LIKE' ) && str_contains( $production_sql, 'l.city_name LIKE' ) && str_contains( $production_sql, 'l.settlement_name LIKE' ) && str_contains( $production_sql, 'l.region_name LIKE' ), 'production-path checkout search runs separate direct own-name and broad hierarchy candidate queries.' );
wc_checkout_smoke_assert( str_contains( $production_sql, 'CASE' ) && str_contains( $production_sql, 'LOWER(l.place_name) = ' ) && str_contains( $production_sql, 'LOWER(l.city_name) = ' ) && str_contains( $production_sql, 'LOWER(l.settlement_name) = ' ), 'production-path direct checkout query keeps exact own-name CASE ordering.' );

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

$session->save_selected_tariff( 'dpd:pickup', array( 'object_code' => '1800' ) );
$dpd_grouped_rates = array(
	wc_checkout_grouped_tariff_rate( 'economy', 'DPD Эконом', 8, 10, 'Доставка планируется* с 28 июля (вторник).', 247 ),
	wc_checkout_grouped_tariff_rate( '1800', 'DPD 18:00', 7, 9, 'Доставка планируется* с 27 июля (понедельник).', 395 ),
	wc_checkout_grouped_tariff_rate( 'classic', 'DPD Classic', 3, 5, 'Доставка планируется* с 23 июля (четверг).', 952 ),
);
$dpd_grouped = $reflection->invoke( $method, $dpd_grouped_rates )[0] ?? null;
wc_checkout_smoke_assert( $dpd_grouped instanceof DeliveryRate, 'DPD grouped selector regression must build a synthetic rate.' );
wc_checkout_smoke_assert( '7-9 дней' === DeliveryDaysFormatter::format( $dpd_grouped->delivery_days ) && 'Доставка планируется* с 27 июля (понедельник).' === $dpd_grouped->planned_delivery_comment, 'DPD grouped synthetic rate must keep duration and planned-date comment separate.' );
$dpd_variants = $dpd_grouped->meta['tariff_variants'] ?? array();
wc_checkout_smoke_assert( isset( $dpd_variants[1]['delivery_days'], $dpd_variants[1]['delivery_days_label'], $dpd_variants[1]['planned_delivery_comment'] ) && '7-9 дней' === $dpd_variants[1]['delivery_days_label'], 'Tariff variant payload must contain delivery_days, delivery_days_label, and planned_delivery_comment.' );
$dpd_mapped = $rate_mapper->map( $dpd_grouped );
$dpd_render_method = (object) array(
	'id' => $dpd_mapped['id'],
	'meta_data' => $dpd_mapped['meta_data'],
);
ob_start();
( new CheckoutRateRenderer() )->render( $dpd_render_method );
$dpd_grouped_html = (string) ob_get_clean();
$selector_start = strpos( $dpd_grouped_html, '<div class="wdc-domestic-tariff-selector"' );
$selector_end = false !== $selector_start ? strpos( $dpd_grouped_html, '</div>', $selector_start ) : false;
$dpd_selector_html = false !== $selector_start && false !== $selector_end ? substr( $dpd_grouped_html, $selector_start, $selector_end - $selector_start ) : '';
wc_checkout_smoke_assert( str_contains( $dpd_grouped_html, 'DPD Эконом - 8-10 дней' ) && str_contains( $dpd_grouped_html, 'DPD 18:00 - 7-9 дней' ) && str_contains( $dpd_grouped_html, 'DPD Classic - 3-5 дней' ), 'Tariff selector must render tariff duration ranges.' );
wc_checkout_smoke_assert( '' !== $dpd_selector_html && ! (bool) preg_match( '/wdc-domestic-tariff-selector__line-text[^>]*>[^<]*Доставка планируется\\*/u', $dpd_selector_html ), 'Tariff selector rows must not render planned delivery comments.' );
wc_checkout_smoke_assert( 1 === substr_count( $dpd_grouped_html, 'class="wdc-platform-planned-delivery-comment wdc-shipping-rate-comment"' ) && str_contains( $dpd_grouped_html, 'wdc-platform-planned-delivery-comment wdc-shipping-rate-comment">Доставка планируется* с 27 июля (понедельник).' ), 'Grouped checkout rate must render exactly one active planned delivery comment.' );
wc_checkout_smoke_assert( ! str_contains( $dpd_grouped_html, 'wdc-platform-planned-delivery-comment wdc-shipping-rate-comment">7-9 дней' ), 'Grouped checkout rate must not render a bare duration as the planned delivery comment.' );
$domestic_tariff_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/domestic-tariff-selector.js' );
wc_checkout_smoke_assert( str_contains( $domestic_tariff_js, 'data-planned-delivery-comment' ) && str_contains( $domestic_tariff_js, '.wdc-platform-planned-delivery-comment' ), 'Tariff selector JavaScript must update the shared planned comment from variant payload.' );
$stored_rates = $session->rates();
$first_rate   = array_key_first( $stored_rates );
WC()->session->set( 'chosen_shipping_methods', array( $first_rate ) );
$order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $order );
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
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $order );
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
			'planned_delivery_date' => '2026-08-12',
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
	'pickup_family' => 'technical:pickup',
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
$cdek_persister = new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) );
$cdek_persister->persist_shipping_item_meta( $cdek_item );
wc_checkout_smoke_assert( 'СДЭК курьер, Посылка склад-дверь - 10-14 дней' === $cdek_item->method_title, 'CDEK checkout shipping item method title must stay user-facing.' );
	wc_checkout_smoke_assert( array( 'Планируемая* дата доставки' => 'с 12 августа 2026' ) === $cdek_item->meta, 'CDEK checkout shipping item visible meta must contain only planned delivery date.' );
foreach ( array( 'carrier_key', 'rate_id', 'delivery_type', 'pickup_family', 'service_key', 'api_base_price_rub', 'tariff_key', 'selected_tariff_object', 'Перевозчик', 'Способ доставки', 'Тип доставки', 'Населенный пункт', 'Нормализация' ) as $forbidden_meta_key ) {
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
			'planned_delivery_date' => '2026-08-12',
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
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $cdek_pickup_order );
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
			'planned_delivery_date' => '2026-08-12',
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
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist_shipping_item_meta( $custom_cdek_item );
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
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist_shipping_item_meta( $no_days_cdek_item );
wc_checkout_smoke_assert( 'СДЭК дверь тест, Посылка склад-дверь' === $no_days_cdek_item->method_title, 'CDEK checkout method title without delivery days must include method and tariff only.' );
	wc_checkout_smoke_assert( array() === $no_days_cdek_item->meta, 'CDEK checkout visible meta without planned date must be omitted.' );

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
			'planned_delivery_date' => '2026-08-12',
			'planned_delivery_comment' => '3-5 дней',
			'tariff_title' => 'Посылка 1 класса',
			'selected_tariff_title' => 'Посылка 1 класса',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:russian_post_domestic:pickup' ) );
$domestic_item = new WdcSmokeShippingItem();
$domestic_item->meta = array_fill_keys( $compact_forbidden_meta_keys, 'technical' );
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist_shipping_item_meta( $domestic_item );
	wc_checkout_smoke_assert( array( 'Планируемая* дата доставки' => 'с 12 августа 2026' ) === $domestic_item->meta, 'Russian Post domestic checkout visible meta must contain only planned delivery date.' );

$session->save_rates(
	array(
		'russian_post:international' => array(
			'rate_id' => 'russian_post:international',
			'carrier_key' => 'russian_post',
			'service_key' => 'russian_post',
			'label' => 'Почта России международная - 8-12 дней',
			'delivery_type' => 'courier',
			'planned_delivery_date' => '2026-08-12',
			'planned_delivery_comment' => '8-12 дней',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:russian_post:international' ) );
$international_item = new WdcSmokeShippingItem();
$international_item->meta = array_fill_keys( $compact_forbidden_meta_keys, 'technical' );
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist_shipping_item_meta( $international_item );
	wc_checkout_smoke_assert( array( 'Планируемая* дата доставки' => 'с 12 августа 2026' ) === $international_item->meta, 'Russian Post international checkout visible meta must contain only planned delivery date.' );

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
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist_shipping_item_meta( $unspecified_item );
	wc_checkout_smoke_assert( array() === $unspecified_item->meta, 'Checkout visible meta must be omitted when planned date is missing.' );

$delivery_days_audit = array(
	array(
		'applied' => true,
		'action_type' => 'change_delivery_days',
		'rule_name' => 'Срок доставки',
		'operation' => 'increase',
		'operation_value' => 2,
		'operation_base' => 'calendar_days',
		'after_value' => array( 'min_days' => 10, 'max_days' => 10 ),
	),
);
$session->save_rates(
	array(
		'yandex_courier_535_662' => array(
			'rate_id' => 'yandex_courier',
			'carrier_key' => 'yandex_delivery',
			'service_key' => 'yandex_delivery',
			'service_title' => 'Яндекс.Доставка',
			'label' => 'Яндекс до двери - 8 дней',
			'delivery_type' => 'courier',
			'cost' => 662.0,
			'delivery_days' => array( 'min_days' => 10, 'max_days' => 10 ),
			'delivery_comment' => '10 дней',
			'original_delivery_days' => array( 'min_days' => 8, 'max_days' => 8 ),
			'rules_source' => 'rule_engine',
			'rate_meta' => array(
				'api_base_price_rub' => 535.0,
				'pricing_total_kopecks' => 53500,
				'delivery_min_days' => 8,
				'delivery_max_days' => 8,
				'api_delivery_days' => 8,
				'rules_source' => 'rule_engine',
				'rules_audit' => $delivery_days_audit,
			),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_courier_535_662' ) );
$yandex_checkout_order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $yandex_checkout_order );
$yandex_checkout_calc = $yandex_checkout_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
$yandex_checkout_formula = $yandex_checkout_calc['rules']['formula_visualization'] ?? array();
wc_checkout_smoke_assert( 535.0 === (float) ( $yandex_checkout_calc['api']['api_base_price_rub'] ?? 0 ) && 662.0 === (float) ( $yandex_checkout_calc['result']['final_price_rub'] ?? 0 ), 'Yandex checkout persistence must keep API base 535 separate from final 662.' );
wc_checkout_smoke_assert( is_array( $yandex_checkout_formula ) && 'Базовая цена API: 535 руб.' === ( $yandex_checkout_formula[0] ?? '' ) && str_contains( implode( "\n", $yandex_checkout_formula ), 'Срок доставки' ) && str_contains( implode( "\n", $yandex_checkout_formula ), 'увеличить срок доставки' ) && str_contains( implode( "\n", $yandex_checkout_formula ), '10 дней' ) && in_array( 'Итог: 662 руб.', $yandex_checkout_formula, true ), 'Yandex checkout formula must persist base price, delivery-days audit and final price.' );

$session->save_rates(
	array(
		'yandex_pricing_total_fallback' => array(
			'rate_id' => 'yandex_courier',
			'carrier_key' => 'yandex_delivery',
			'service_key' => 'yandex_delivery',
			'label' => 'Яндекс до двери',
			'delivery_type' => 'courier',
			'cost' => 662.0,
			'rate_meta' => array( 'pricing_total_kopecks' => 53500 ),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:yandex_pricing_total_fallback' ) );
$pricing_total_order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $pricing_total_order );
wc_checkout_smoke_assert( 535.0 === (float) ( $pricing_total_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ]['api']['api_base_price_rub'] ?? 0 ), 'Yandex checkout persistence must use pricing_total_kopecks fallback before final cost.' );

$session->save_rates(
	array(
		'money_array_original_cost' => array(
			'rate_id' => 'yandex_courier',
			'carrier_key' => 'yandex_delivery',
			'service_key' => 'yandex_delivery',
			'label' => 'Яндекс до двери',
			'delivery_type' => 'courier',
			'cost' => 662.0,
			'original_cost' => Money::from_rubles( 535 )->to_array(),
			'rate_meta' => array(),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:money_array_original_cost' ) );
$money_array_order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $money_array_order );
wc_checkout_smoke_assert( 535.0 === (float) ( $money_array_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ]['api']['api_base_price_rub'] ?? 0 ), 'Checkout persistence must safely read Money::to_array() original_cost as 535 rubles.' );

WC()->session->set( 'chosen_shipping_methods', array( 'legacy_method:rate' ) );
$order = new WdcSmokeOrder();
( new OrderShippingMetaPersister( $session, new DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $order );
wc_checkout_smoke_assert( array() === $order->meta, 'Persister must ignore non-WDC selected shipping methods.' );

echo "WooCommerce checkout smoke test passed.\n";
