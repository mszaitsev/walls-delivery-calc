<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
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
use WallsShop\WDC\Checkout\WooCommerce\PickupPointOrderDisplay;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRateRenderer;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRecalculationAdminController;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$GLOBALS['wdc_recalc_current_can'] = true;
$GLOBALS['wdc_recalc_nonce_ok'] = true;
$GLOBALS['wdc_recalc_orders'] = array();
$GLOBALS['wdc_recalc_options'] = array();

final class WdcRecalcAjaxResponse extends RuntimeException {
	public function __construct(
		public bool $success,
		public mixed $data,
		public int $status = 200
	) {
		parent::__construct( 'ajax response' );
	}
}

function recalc_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function recalc_smoke_run_node( string $script, string $message ): void {
	$tmp = tempnam( sys_get_temp_dir(), 'wdc-recalc-js-' );
	if ( false === $tmp ) {
		throw new RuntimeException( $message . ': cannot create temporary JS file.' );
	}
	$js_file = $tmp . '.js';
	if ( ! rename( $tmp, $js_file ) ) {
		@unlink( $tmp );
		throw new RuntimeException( $message . ': cannot prepare temporary JS file.' );
	}
	file_put_contents( $js_file, $script );
	$output = array();
	$code = 1;
	exec( 'node ' . escapeshellarg( $js_file ) . ' 2>&1', $output, $code );
	@unlink( $js_file );
	recalc_smoke_assert( 0 === $code, $message . ': ' . implode( "\n", $output ) );
}

function current_user_can( string $capability ): bool {
	return 'manage_woocommerce' === $capability && (bool) $GLOBALS['wdc_recalc_current_can'];
}

function check_ajax_referer( string $action, string|false $query_arg = false, bool $stop = true ): bool {
	return 'wdc_order_delivery_recalculation' === $action && (bool) $GLOBALS['wdc_recalc_nonce_ok'];
}

function wc_get_order( int $order_id ): mixed {
	return $GLOBALS['wdc_recalc_orders'][ $order_id ] ?? null;
}

function wp_send_json_success( mixed $data = null, int $status_code = 200 ): void {
	throw new WdcRecalcAjaxResponse( true, $data, $status_code );
}

function wp_send_json_error( mixed $data = null, int $status_code = 400 ): void {
	throw new WdcRecalcAjaxResponse( false, $data, $status_code );
}

function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_recalc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_recalc_options'][ $option ] = $value; return true; }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function wp_date( string $format ): string { return gmdate( $format ); }

final class WdcRecalcLocationDb extends wpdb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $locations = array();
	/** @var array<int,array<string,mixed>> */
	public array $russian_post_pickup_rows = array();
	public array $yandex_location_mapping_v2 = array();
	public array $yandex_delivery_pickup_points_v2 = array();
}

final class WdcRecalcProduct {
	public function __construct(
		private string $sku,
		private string $name,
		private float $weight,
		private float $length,
		private float $width,
		private float $height
	) {}
	public function get_sku(): string { return $this->sku; }
	public function get_name(): string { return $this->name; }
	public function get_weight(): float { return $this->weight; }
	public function get_length(): float { return $this->length; }
	public function get_width(): float { return $this->width; }
	public function get_height(): float { return $this->height; }
}

final class WdcRecalcOrderItem {
	public function __construct(
		private object $product,
		private int $quantity,
		private float $total,
		private string $name
	) {}
	public function get_product(): object { return $this->product; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): float { return $this->total; }
	public function get_name(): string { return $this->name; }
}

final class WdcRecalcShippingItem {
	public array $meta = array();

	public function __construct(
		private string $method_title,
		private float $total
	) {}
	public function get_method_title(): string { return $this->method_title; }
	public function get_total(): float { return $this->total; }
	public function set_method_id( string $method_id ): void { $this->meta['method_id'] = $method_id; }
	public function set_method_title( string $method_title ): void { $this->method_title = $method_title; }
	public function set_total( string $total ): void { $this->total = (float) $total; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

final class WdcRecalcOrder {
	public array $meta = array();
	public array $shipping_items = array( 'method_title' => 'Old delivery', 'total' => 111.0 );
	public float $total = 5111.0;
	public array $notes = array();
	public bool $saved = false;
	private string $shipping_country = 'RU';
	private string $shipping_city = 'Новосибирск';
	private string $shipping_postcode = '630099';
	private string $shipping_address_1 = 'Красный проспект';
	private string $shipping_address_2 = '1';
	private string $shipping_state = 'Новосибирская область';

	public function __construct( private int $id, private array $items ) {}
	public function get_id(): int { return $this->id; }
	public function get_items( string $type = '' ): array { return 'shipping' === $type ? ( array() === $this->shipping_items ? array() : ( array_is_list( $this->shipping_items ) ? $this->shipping_items : array( $this->shipping_items ) ) ) : $this->items; }
	public function get_item_count(): int { return 3; }
	public function get_subtotal(): float { return 5000.0; }
	public function get_payment_method(): string { return 'cod'; }
	public function get_shipping_country(): string { return $this->shipping_country; }
	public function get_billing_country(): string { return 'RU'; }
	public function get_shipping_city(): string { return $this->shipping_city; }
	public function get_shipping_postcode(): string { return $this->shipping_postcode; }
	public function get_shipping_address_1(): string { return $this->shipping_address_1; }
	public function get_shipping_address_2(): string { return $this->shipping_address_2; }
	public function get_shipping_state(): string { return $this->shipping_state; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function set_shipping_country( string $value ): void { $this->shipping_country = $value; }
	public function set_shipping_state( string $value ): void { $this->shipping_state = $value; }
	public function set_shipping_city( string $value ): void { $this->shipping_city = $value; }
	public function set_shipping_postcode( string $value ): void { $this->shipping_postcode = $value; }
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void { $this->shipping_address_2 = $value; }
	public function add_item( object $item ): void { $this->shipping_items[] = $item; }
	public function calculate_totals( bool $and_taxes = true ): void {
		$shipping = $this->get_items( 'shipping' )[0] ?? array();
		$shipping_total = is_array( $shipping ) ? (float) ( $shipping['total'] ?? 0 ) : ( is_object( $shipping ) && method_exists( $shipping, 'get_total' ) ? (float) $shipping->get_total() : 0.0 );
		$this->total = 5000.0 + $shipping_total;
	}
	public function get_total(): float { return $this->total; }
	public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): void { $this->notes[] = array( 'note' => $note, 'customer' => $is_customer_note ); }
	public function save(): void { $this->saved = true; }
}

final class WdcRecalcCarrier implements CarrierAdapterInterface {
	public function __construct( private string $key ) {}
	public function get_identity(): CarrierIdentity { return new CarrierIdentity( $this->key, $this->key, 'api', true ); }
	public function get_capabilities(): CarrierCapabilities { return new CarrierCapabilities( supports_quotes: true, supports_courier_delivery: true, supports_pickup_delivery: true ); }
	public function supports_country( string $countryCode ): bool { return 'RU' === strtoupper( $countryCode ); }
	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( RussianPostDomesticSettings::CARRIER_KEY === $this->key ) {
			return new DeliveryQuote( 'rp', $this->key, $request->destination, $request->package, array(
				$this->rate( 'russian_post_domestic:pickup:23030', '23030', 'Посылка онлайн', DeliveryType::PICKUP, 400, 3 ),
				$this->rate( 'russian_post_domestic:pickup:47030', '47030', 'Посылка 1 класса', DeliveryType::PICKUP, 550, 2 ),
				$this->rate( 'russian_post_domestic:courier:24030', '24030', 'Курьер онлайн', DeliveryType::COURIER, 700, 2 ),
				$this->rate( 'russian_post_domestic:courier:7030', '7030', 'EMS', DeliveryType::COURIER, 900, 1 ),
			) );
		}

		return new DeliveryQuote( 'demo', $this->key, $request->destination, $request->package, array(
			new DeliveryRate( 'demo:pickup', 'demo', 'Demo', 'demo', 'Demo service', 'pvz', 'Demo PVZ', DeliveryType::PICKUP, 'Demo pickup', Money::from_rubles( 300 ), Money::from_rubles( 450 ), Money::from_rubles( 450 ), DateRange::single( 4 ), '', '4 дня', array( 'Комментарий demo' ), false, '', true, false )
		) );
	}

	private function rate( string $rate_id, string $object, string $title, string $type, float $price, int $days ): DeliveryRate {
		return new DeliveryRate(
			$rate_id,
			RussianPostDomesticSettings::CARRIER_KEY,
			'Почта России',
			RussianPostDomesticSettings::SERVICE_KEY,
			'Почта России по РФ',
			$object,
			$title,
			$type,
			$title,
			Money::from_rubles( $price ),
			null,
			null,
			DateRange::single( $days ),
			'',
			$days . ' дня',
			array( 'Комментарий тарифа' ),
			false,
			'',
			DeliveryType::PICKUP === $type,
			DeliveryType::COURIER === $type,
			array(
				'tariff_selector_group' => true,
				'pickup_method_title' => 'Почта России до отделения',
				'courier_method_title' => 'Почта России до двери',
				'api_base_price_rub' => $price - 50,
				'api_price_with_vat_rub' => $price - 40,
				'final_price_rub' => $price,
				'products_weight_g' => 1000,
				'packaging_weight_g' => 200,
				'package_weight_with_packaging_g' => 1200,
				'include_packaging_weight' => true,
				'packaging_weight_mode' => 'fixed',
				'delivery_min_days' => $days,
				'delivery_max_days' => $days,
				'rules_source' => 'runtime',
				'rules_audit' => array( 'base' ),
				'package' => array(
					'products_weight_g' => 1000,
					'packaging_weight_g' => 200,
					'final_weight_g' => 1200,
					'total_weight_g' => 1200,
					'weight_g' => 1000,
					'include_packaging_weight' => true,
					'packaging_weight_mode' => 'fixed',
				),
				'api' => array(
					'api_base_price_rub' => $price - 50,
					'delivery_days' => $days,
					'delivery_text' => $days . ' дня',
					'request_params' => array( 'object' => $object ),
					'cache_hit' => false,
					'http_code' => 200,
				),
				'rules' => array(
					'rules_source' => 'runtime',
					'applied_rules' => array( 'base' ),
					'formula_visualization' => array( 'API + 50 руб.' ),
					'round_up_applied' => false,
					'minimum_price_applied' => false,
				),
				'result' => array(
					'final_price_rub' => $price,
					'final_delivery_text' => $days . ' дня',
				),
			)
		);
	}
}

final class WdcRecalcYandexLocationCarrier implements CarrierAdapterInterface {
	public function __construct( private int $expected_location_id ) {}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( 'yandex_delivery', 'Яндекс.Доставка', 'api', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true, supports_courier_delivery: true, supports_pickup_delivery: true );
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( $countryCode );
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$location_id = (int) ( $request->customer_context['location_id'] ?? 0 );
		$rates = array();
		if ( $this->expected_location_id === $location_id ) {
			$rates[] = new DeliveryRate(
				'yandex_pickup',
				'yandex_delivery',
				'Яндекс.Доставка',
				'yandex_delivery',
				'Яндекс.Доставка',
				'yandex_pickup',
				'Яндекс до ПВЗ',
				DeliveryType::PICKUP,
				'Яндекс до ПВЗ',
				Money::from_rubles( 535 ),
				null,
				null,
				DateRange::single( 8 ),
				'',
				'8 дней',
				array(),
				false,
				'',
				true,
				false,
				array(
					'pickup_family' => 'yandex_delivery:pickup',
					'pickup_source' => 'representative',
					'platform_station_id' => 'YANDEX-REPRESENTATIVE-92468',
				)
			);
		}

		return new DeliveryQuote( 'yandex-test', 'yandex_delivery', $request->destination, $request->package, $rates );
	}
}

final class WdcRecalcDadataSuggestionClient implements AddressSuggestionClientInterface {
	public array $requests = array();
	public function __construct( private bool $with_coordinates = true ) {}

	public function suggest( string $stage, string $query, array $context = array() ): array {
		$this->requests[] = compact( 'stage', 'query', 'context' );
		if ( str_contains( $query, 'варианты' ) ) {
			$first = array(
			'geo_lat' => $this->with_coordinates ? '55.0401' : '',
			'geo_lon' => $this->with_coordinates ? '82.9301' : '',
			'fias_id' => 'fake-nsk-address-fias-1',
			'house_fias_id' => 'fake-nsk-house-fias-1',
			'city_fias_id' => 'nsk-fias',
			'city_kladr_id' => '5400000100000',
			'fias_level' => '8',
				'country_iso_code' => 'RU',
				'region_with_type' => 'Новосибирская область',
				'city_with_type' => 'г Новосибирск',
				'street_with_type' => 'ул Некрасова',
				'house' => '63/1',
				'flat' => '10',
				'postal_code' => '630005',
			);
			$second = $first;
			$second['fias_id'] = 'fake-nsk-address-fias-2';
			$second['house_fias_id'] = 'fake-nsk-house-fias-2';
			$second['house'] = '63/2';
			$second['flat'] = '12';
			return array(
				'success' => true,
				'suggestions' => array(
					array(
						'value' => 'Новосибирск, ул Некрасова, 63/1, кв 10',
						'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, кв 10',
						'data' => $first,
					),
					array(
						'value' => 'Новосибирск, ул Некрасова, 63/2, кв 12',
						'unrestricted_value' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/2, кв 12',
						'data' => $second,
					),
				),
			);
		}
		$is_apartment_case = str_contains( $query, 'некрасова' ) || str_contains( $query, 'Некрасова' ) || str_contains( $query, '63/1' );
		$data = $is_apartment_case ? array(
			'geo_lat' => $this->with_coordinates ? '55.0401' : '',
			'geo_lon' => $this->with_coordinates ? '82.9301' : '',
			'fias_id' => 'fake-nsk-address-fias',
			'house_fias_id' => 'fake-nsk-house-fias',
			'city_fias_id' => 'nsk-fias',
			'city_kladr_id' => '5400000100000',
			'fias_level' => '8',
			'country_iso_code' => 'RU',
			'region_with_type' => 'Новосибирская область',
			'city_with_type' => 'г Новосибирск',
			'street_with_type' => 'ул Некрасова',
			'house' => '63/1',
			'flat' => '10',
			'postal_code' => '630005',
		) : array(
			'geo_lat' => $this->with_coordinates ? '54.9914' : '',
			'geo_lon' => $this->with_coordinates ? '73.3645' : '',
			'fias_id' => 'fake-address-fias',
			'fias_level' => '8',
			'country_iso_code' => 'RU',
			'region_with_type' => 'Омская область',
			'city_with_type' => 'г Омск',
			'street_with_type' => 'ул Ленина',
			'house' => '10',
			'postal_code' => '644099',
		);

		return array(
			'success' => true,
			'suggestions' => array(
				array(
					'value' => $is_apartment_case ? 'Новосибирск, ул Некрасова, 63/1, кв 10' : 'Омск, ул. Ленина, 10',
					'unrestricted_value' => $is_apartment_case ? 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, кв 10' : 'Омская область, г Омск, ул. Ленина, д 10',
					'data' => $data,
				),
			),
		);
	}
}

function wdc_recalc_address_suggestion_service( AddressSuggestionClientInterface $client ): AddressSuggestionService {
	$settings = new SettingsRepository();
	$settings->replace(
		array_merge(
			$settings->all(),
			array(
				'dadata_suggestions_enabled' => true,
				'dadata_suggestions_count' => 20,
				'dadata_suggestions_tokens' => array(
					array(
						'id' => 'test-token',
						'enabled' => true,
						'encrypted_token' => 'encrypted-test-token',
						'daily_limit' => 10000,
					),
				),
			)
		)
	);
	$encryption = new EncryptionService();
	$pool = new DaDataTokenPool( $settings, $encryption );

	return new AddressSuggestionService( new AddressSuggestionSettings( $settings, $encryption, $pool ), $client, new AddressSuggestionNormalizer() );
}

function wdc_recalc_service( ?OrderQuoteRequestMapper $mapper = null, array $extra_carriers = array() ): OrderDeliveryRecalculationService {
	$registry = new CarrierRegistry();
	$registry->register( new WdcRecalcCarrier( RussianPostDomesticSettings::CARRIER_KEY ) );
	$registry->register( new WdcRecalcCarrier( 'demo' ) );
	foreach ( $extra_carriers as $carrier ) {
		$registry->register( $carrier );
	}
	$logger = new CheckoutLogger();
	$orchestrator = new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger,
		wdc_recalc_lead_time_normalizer( 0 )
	);

	return new OrderDeliveryRecalculationService( $mapper ?? new OrderQuoteRequestMapper(), $orchestrator, new OrderShipmentRepository() );
}

function wdc_recalc_lead_time_normalizer( int $processing_days = 0 ): DeliveryLeadTimeNormalizer {
	$GLOBALS['wpdb'] ??= new wpdb();
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

function wdc_recalc_location_row( int $id, array $overrides = array() ): array {
	return array_merge(
		array(
			'id' => $id,
			'fias_id' => 'fias-' . $id,
			'city_fias_id' => '',
			'gar_id' => (string) ( 880000 + $id ),
			'gar_object_id' => 880000 + $id,
			'country_code' => 'RU',
			'region_name' => 'Новосибирская область',
			'region_type' => 'обл',
			'region_code' => '54',
			'city_name' => 'Новосибирск',
			'city_type' => 'г',
			'place_name' => 'Новосибирск',
			'place_type' => 'г',
			'settlement_name' => 'Новосибирск',
			'settlement_type' => 'г',
			'display_name' => 'Новосибирская область, г Новосибирск',
			'postal_code' => '630099',
			'active' => 1,
		),
		$overrides
	);
}

function wdc_recalc_location_repository( array $rows ): LocationRepository {
	$db = new WdcRecalcLocationDb();
	$db->locations = $rows;
	return new LocationRepository( $db );
}

function wdc_recalc_location_context( OrderQuoteRequestMapper $mapper, array $meta, ?array $selected_location = null ): array {
	$order = new WdcRecalcOrder(
		900,
		array(
			new WdcRecalcOrderItem( new WdcRecalcProduct( 'SKU-LOC', 'Товар', 0.5, 10, 20, 30 ), 1, 1000, 'Товар' ),
		)
	);
	$order->meta = $meta;
	return $mapper->map( $order, $selected_location )->customer_context;
}

function wdc_recalc_location_ajax(): CheckoutLocationAjax {
	$db = new WdcRecalcLocationDb();
	$db->locations = array(
		array(
			'id' => 201,
			'fias_id' => 'fias-override',
			'gar_id' => '7700000000000',
			'gar_object_id' => 7700000000000,
			'country_code' => 'RU',
			'region_name' => 'Москва',
			'region_type' => 'г',
			'region_code' => '77',
			'city_name' => 'Москва',
			'city_type' => 'г',
			'place_name' => 'Москва',
			'place_type' => 'г',
			'settlement_name' => 'Москва',
			'settlement_type' => 'г',
			'display_name' => 'Москва',
			'postal_code' => '101000',
			'searchable_text' => 'москва 101000 fias-override',
			'active' => 1,
		),
	);
	$repository = new LocationRepository( $db );
	return new CheckoutLocationAjax( new CheckoutLocationSearch( new LocationSearchService( $repository ) ), new SettingsRepository(), null );
}

function wdc_recalc_pickup_repository(): RussianPostPickupPointRepository {
	$db = new WdcRecalcLocationDb();
	$db->russian_post_pickup_rows = array(
		array(
			'point_code' => '101000-OPS',
			'point_type' => 'OPS',
			'postcode' => '101000',
			'region_name' => 'Москва',
			'city_name' => 'Москва',
			'address' => 'Москва, ул. Тверская, 1',
			'fias_location_guid' => 'fias-override',
			'latitude' => 55.75,
			'longitude' => 37.61,
			'active' => 1,
		),
		array(
			'point_code' => '125009-OPS',
			'point_type' => 'OPS',
			'postcode' => '125009',
			'region_name' => 'Москва',
			'city_name' => 'Москва',
			'address' => 'Москва, Никитский пер., 2',
			'fias_location_guid' => 'fias-override',
			'latitude' => 55.755,
			'longitude' => 37.605,
			'active' => 1,
		),
	);
	for ( $i = 1; $i <= 600; ++$i ) {
		$db->russian_post_pickup_rows[] = array(
			'point_code' => '77' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) . '-OPS',
			'point_type' => 'OPS',
			'postcode' => '77' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ),
			'region_name' => 'Москва',
			'city_name' => 'Москва',
			'address' => 'Москва, тестовое отделение ' . $i,
			'fias_location_guid' => 'fias-override',
			'latitude' => 55.7 + ( $i / 10000 ),
			'longitude' => 37.5 + ( $i / 10000 ),
			'active' => 1,
		);
	}
	$db->russian_post_pickup_rows[] = array(
			'point_code' => '190000-OPS',
			'point_type' => 'OPS',
			'postcode' => '190000',
			'region_name' => 'Санкт-Петербург',
			'city_name' => 'Санкт-Петербург',
			'address' => 'Санкт-Петербург, Невский пр., 1',
			'fias_location_guid' => 'fias-spb',
			'latitude' => 59.93,
			'longitude' => 30.31,
			'active' => 1,
	);
	return new RussianPostPickupPointRepository( $db );
}

$order = new WdcRecalcOrder(
	101,
	array(
		new WdcRecalcOrderItem( new WdcRecalcProduct( 'SKU-1', 'Товар', 0.5, 10, 20, 30 ), 2, 5000, 'Товар' ),
	)
);
$order->meta['_wdc_delivery_calculation_data'] = array( 'destination' => array( 'city_display_name' => 'Новосибирск', 'fias_id' => 'fias-1' ) );
$order->meta['_wdc_platform_city_postcode'] = '630099';
$order->meta['_wdc_platform_city_fias_id'] = 'fias-1';
$order->meta['_wdc_pickup_point_code'] = '630099-OPS';
$order->meta['_wdc_pickup_point_type'] = 'OPS';
$order->meta['_wdc_pickup_point_address'] = 'Новосибирск, Красный проспект, 10';
$order->meta['_wdc_pickup_point_postcode'] = '630099';
$order->meta['_wdc_pickup_point_snapshot'] = wp_json_encode( array( 'point_code' => '630099-OPS', 'point_name' => 'ОПС 630099', 'point_address' => 'Новосибирск, Красный проспект, 10' ) );
$GLOBALS['wdc_recalc_orders'][101] = $order;

$yandex_selection_request = ( new OrderQuoteRequestMapper() )->map(
	$order,
	null,
	array(
		'carrier_key' => 'yandex_delivery',
		'service_key' => 'yandex_delivery',
		'pickup_family' => 'yandex_delivery:pickup',
		'point_code' => 'YANDEX-PVZ-1',
		'platform_station_id' => 'YANDEX-PVZ-1',
		'snapshot' => array( 'carrier_key' => 'yandex_delivery', 'pickup_family' => 'yandex_delivery:pickup', 'platform_station_id' => 'YANDEX-PVZ-1' ),
	)
);
recalc_smoke_assert( 'YANDEX-PVZ-1' === (string) ( $yandex_selection_request->customer_context['pickup_selection']['platform_station_id'] ?? '' ), 'Yandex selected pickup must be passed to the checkout-compatible pickup_selection context.' );
recalc_smoke_assert( 'YANDEX-PVZ-1' === (string) ( $yandex_selection_request->customer_context['pickup_selections']['yandex_delivery:pickup']['platform_station_id'] ?? '' ), 'Yandex selected pickup must be passed in its family pickup_selections bucket.' );

$location_lookup_repository = wdc_recalc_location_repository(
	array(
		wdc_recalc_location_row( 92468, array( 'fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'city_fias_id' => '' ) ),
		wdc_recalc_location_row( 92469, array( 'fias_id' => '11111111-1111-1111-1111-111111111111', 'city_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' ) ),
		wdc_recalc_location_row( 92470, array( 'fias_id' => '22222222-2222-2222-2222-222222222222', 'city_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' ) ),
		wdc_recalc_location_row( 92500, array( 'fias_id' => '33333333-3333-3333-3333-333333333333', 'city_fias_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' ) ),
		wdc_recalc_location_row( 92501, array( 'fias_id' => '44444444-4444-4444-4444-444444444444', 'city_fias_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc' ) ),
		wdc_recalc_location_row( 92502, array( 'fias_id' => '55555555-5555-5555-5555-555555555555', 'city_fias_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc' ) ),
		wdc_recalc_location_row( 92600, array( 'fias_id' => 'gar-row', 'city_fias_id' => '', 'gar_id' => '889336', 'gar_object_id' => 889336 ) ),
		wdc_recalc_location_row( 92601, array( 'fias_id' => '66666666-6666-6666-6666-666666666666', 'city_fias_id' => '', 'gar_id' => '889337', 'gar_object_id' => 889337 ) ),
	)
);
$location_lookup_mapper = new OrderQuoteRequestMapper( $location_lookup_repository );
$exact_fias_context = wdc_recalc_location_context( $location_lookup_mapper, array( '_wdc_platform_location_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' ) );
recalc_smoke_assert( 92468 === (int) ( $exact_fias_context['location_id'] ?? 0 ), 'OrderQuoteRequestMapper must resolve exact FIAS through LocationRepository.' );
$exact_fias_priority_context = wdc_recalc_location_context( $location_lookup_mapper, array( '_wdc_platform_location_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '_wdc_platform_city_fias_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc' ) );
recalc_smoke_assert( 92468 === (int) ( $exact_fias_priority_context['location_id'] ?? 0 ), 'Exact fias_id must win over conflicting city_fias_id rows.' );
$unique_city_fias_context = wdc_recalc_location_context( $location_lookup_mapper, array( '_wdc_platform_city_fias_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' ) );
recalc_smoke_assert( 92500 === (int) ( $unique_city_fias_context['location_id'] ?? 0 ), 'Unique city_fias_id must resolve the original order location.' );
$duplicate_city_fias_context = wdc_recalc_location_context( $location_lookup_mapper, array( '_wdc_platform_city_fias_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc' ) );
recalc_smoke_assert( 0 === (int) ( $duplicate_city_fias_context['location_id'] ?? 0 ), 'Duplicate city_fias_id must not choose a random location.' );
$gar_context = wdc_recalc_location_context( $location_lookup_mapper, array( '_wdc_platform_gar_id' => '889336' ) );
recalc_smoke_assert( 92600 === (int) ( $gar_context['location_id'] ?? 0 ), 'GAR fallback must resolve by exact positive gar_object_id.' );
$numeric_priority_context = wdc_recalc_location_context( $location_lookup_mapper, array( '_wdc_platform_location_id' => 92700, '_wdc_platform_location_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '_wdc_platform_gar_id' => '889336' ) );
recalc_smoke_assert( 92700 === (int) ( $numeric_priority_context['location_id'] ?? 0 ), 'Saved numeric location id must have priority over repository lookup.' );
$explicit_priority_context = wdc_recalc_location_context(
	$location_lookup_mapper,
	array( '_wdc_platform_location_id' => 92700, '_wdc_platform_location_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '_wdc_platform_gar_id' => '889336' ),
	array( 'id' => 92800, 'display_name' => 'Москва', 'city_value' => 'Москва', 'country_code' => 'RU' )
);
recalc_smoke_assert( 92800 === (int) ( $explicit_priority_context['location_id'] ?? 0 ) && 92800 === (int) ( $explicit_priority_context['selected_location_id'] ?? 0 ), 'Explicit selected_location id must have priority over saved/repository location ids.' );
$repository_absent_context = wdc_recalc_location_context( new OrderQuoteRequestMapper(), array( '_wdc_platform_location_fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '_wdc_platform_gar_id' => '889336' ) );
recalc_smoke_assert( 0 === (int) ( $repository_absent_context['location_id'] ?? 0 ), 'Mapper without LocationRepository must not throw and must not invent a location_id.' );

$first_preview_repository = wdc_recalc_location_repository(
	array(
		wdc_recalc_location_row( 92468, array( 'fias_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd', 'city_fias_id' => '' ) ),
	)
);
$first_preview_order = new WdcRecalcOrder(
	127,
	array(
		new WdcRecalcOrderItem( new WdcRecalcProduct( 'SKU-YA', 'Товар', 0.5, 10, 20, 30 ), 1, 1000, 'Товар' ),
	)
);
$first_preview_order->meta['_wdc_platform_location_fias_id'] = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
$first_preview_before_meta = $first_preview_order->meta;
$first_preview_service = wdc_recalc_service( new OrderQuoteRequestMapper( $first_preview_repository ), array( new WdcRecalcYandexLocationCarrier( 92468 ) ) );
$first_preview = $first_preview_service->preview( $first_preview_order );
$first_preview_rates = array_column( $first_preview['rates'] ?? array(), null, 'id' );
recalc_smoke_assert( true === ( $first_preview['success'] ?? false ), 'First Yandex preview must succeed for the original order location.' );
recalc_smoke_assert( 92468 === (int) ( $first_preview['request']['customer_context']['location_id'] ?? 0 ), 'First Yandex preview must pass read-only resolved location_id into QuoteRequest.' );
recalc_smoke_assert( 92468 === (int) ( $first_preview['location']['id'] ?? 0 ) && 92468 === (int) ( $first_preview['location']['location_id'] ?? 0 ), 'First Yandex preview location payload must expose resolved id and location_id for pickup map search.' );
recalc_smoke_assert( empty( $first_preview['location']['is_override'] ), 'Read-only resolved location_id must not mark the original order city as an override.' );
recalc_smoke_assert( isset( $first_preview_rates['yandex_pickup'] ), 'First Yandex preview must include yandex_pickup without manually selecting another settlement.' );
recalc_smoke_assert( 'representative' === (string) ( $first_preview_rates['yandex_pickup']['rate_meta']['pickup_source'] ?? '' ), 'First Yandex preview must use representative pickup source, not a selected pickup.' );
recalc_smoke_assert( $first_preview_before_meta === $first_preview_order->meta, 'First Yandex preview must not write resolved location_id or any other data to order meta.' );

$metabox = new OrderDeliveryMetabox( new OrderShipmentRepository() );
ob_start();
$metabox->render( $order );
$metabox_html = (string) ob_get_clean();
recalc_smoke_assert( str_contains( $metabox_html, 'Пересчитать доставку' ), 'Metabox must contain recalculation button.' );
recalc_smoke_assert( ! str_contains( $metabox_html, 'data-wdc-order-delivery-preview' ), 'Metabox must not contain permanent inline preview container.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-modal' ), 'Metabox must contain modal markup.' );
recalc_smoke_assert( str_contains( $metabox_html, 'role="dialog"' ) && str_contains( $metabox_html, 'aria-modal="true"' ), 'Modal markup must expose dialog accessibility attributes.' );
recalc_smoke_assert( str_contains( $metabox_html, 'Пересчет доставки' ), 'Modal markup must contain heading.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-modal-status' ), 'Modal markup must contain status area.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-modal-content' ), 'Modal markup must contain content area.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-save' ) && str_contains( $metabox_html, 'disabled' ) && str_contains( $metabox_html, 'Сохранить новый вариант доставки' ), 'Modal markup must contain disabled save button.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-current-pickup' ) && str_contains( $metabox_html, '630099-OPS' ), 'Modal markup must contain current pickup payload.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-current-shipping-address' ) && str_contains( $metabox_html, 'Красный проспект' ), 'Modal markup must contain current shipping address payload for courier prefill.' );
recalc_smoke_assert( str_contains( $metabox_html, 'data-wdc-order-delivery-save-warning' ), 'Modal markup must contain non-blocking courier location warning area.' );
$metabox_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Admin/OrderDeliveryMetabox.php' );
recalc_smoke_assert( is_string( $metabox_source ) && str_contains( $metabox_source, '$is_dpd_context' ) && str_contains( $metabox_source, '$is_dpd_pickup' ) && str_contains( $metabox_source, 'return array();' ), 'Metabox current pickup source must distinguish saved DPD pickup from DPD courier/non-DPD orders.' );
recalc_smoke_assert( is_string( $metabox_source ) && str_contains( $metabox_source, "'terminal_code' => \$dpd_terminal_code" ) && ! str_contains( $metabox_source, "'terminal_code' => \$this->meta_string( \$order, '_wdc_dpd_pickup_terminal_code' ) ?: (string) ( \$snapshot['terminal_code'] ?? \$snapshot['point_code'] ?? '' )" ), 'Metabox current pickup source must not turn arbitrary snapshot point_code into DPD terminal_code.' );

$duplicate_location_order = new WdcRecalcOrder( 116, array() );
$duplicate_location_order->meta['_wdc_delivery_calculation_data'] = array( 'destination' => array( 'city_display_name' => 'Новосибирская область, г Новосибирск', 'region_name' => 'Новосибирская область', 'fias_id' => 'nsk-fias' ) );
ob_start();
$metabox->render( $duplicate_location_order );
$duplicate_location_html = (string) ob_get_clean();
recalc_smoke_assert( str_contains( $duplicate_location_html, 'Новосибирская область, г Новосибирск' ) && ! str_contains( $duplicate_location_html, 'Новосибирская область, Новосибирская область, г Новосибирск' ), 'Modal current location summary must not duplicate region when display_name already contains it.' );

$service = wdc_recalc_service();
$before_shipping = $order->shipping_items;
$before_total = $order->total;
$before_calc = $order->meta['_wdc_delivery_calculation_data'];
$before_shipping_city = $order->get_shipping_city();
$before_shipping_postcode = $order->get_shipping_postcode();
$preview = $service->preview( $order );

recalc_smoke_assert( true === $preview['success'], 'Preview must succeed for an order without shipment.' );
recalc_smoke_assert( 'RU' === ( $preview['request']['country_code'] ?? '' ), 'Preview must build QuoteRequest country from order.' );
recalc_smoke_assert( 1000 === ( $preview['request']['package']['total_weight_g'] ?? 0 ), 'Preview must build QuoteRequest package from order items.' );
recalc_smoke_assert( '630099' === ( $preview['request']['destination']['postcode'] ?? '' ), 'Preview must build destination postcode from order.' );
recalc_smoke_assert( empty( $preview['request']['customer_context']['location_override'] ?? false ), 'Preview without override must keep current order location behavior.' );

$location_ajax = wdc_recalc_location_ajax();
$search_payload = $location_ajax->payload( 'Москва', '', 'RU' );
$search_items = $search_payload['groups'][0]['items'] ?? array();
recalc_smoke_assert( array() !== $search_items && 'fias-override' === ( $search_items[0]['fias_id'] ?? '' ), 'Location search must return settlements through existing checkout location payload.' );
$selected_location = $search_items[0];
$override_preview = $service->preview( $order, $selected_location );
recalc_smoke_assert( true === $override_preview['success'], 'Preview with location override must succeed.' );
recalc_smoke_assert( str_contains( (string) ( $override_preview['request']['destination']['city'] ?? '' ), 'Москва' ), 'Preview with override must use selected location city.' );
recalc_smoke_assert( '101000' === ( $override_preview['request']['destination']['postcode'] ?? '' ), 'Preview with override must use selected location postcode.' );
recalc_smoke_assert( 'fias-override' === ( $override_preview['request']['destination']['fias_id'] ?? '' ), 'Preview with override must use selected location FIAS id.' );
recalc_smoke_assert( ! empty( $override_preview['request']['customer_context']['location_override'] ), 'Preview with override must mark customer context.' );
recalc_smoke_assert( str_contains( (string) ( $override_preview['location']['label'] ?? '' ), 'Москва' ), 'Preview with override must expose calculated location label.' );
$omsk_preview = $service->preview(
	$order,
	array(
		'display_name' => 'Омская область, г Омск',
		'region_name' => 'Омская область',
		'city_value' => 'г Омск',
		'fias_id' => 'fias-omsk',
		'postal_code' => '644099',
		'country_code' => 'RU',
	)
);
recalc_smoke_assert( 'Омская область, г Омск' === ( $omsk_preview['location']['label'] ?? '' ), 'Preview location label must use display_name without duplicating region.' );

$rate_ids = array_column( $preview['rates'], 'id' );
recalc_smoke_assert( in_array( 'demo:pickup', $rate_ids, true ), 'Preview must include rates from every available carrier/service path.' );
recalc_smoke_assert( in_array( 'russian_post_domestic:pickup', $rate_ids, true ), 'Preview must include Russian Post pickup group.' );
recalc_smoke_assert( in_array( 'russian_post_domestic:courier', $rate_ids, true ), 'Preview must include Russian Post courier group.' );
$rates_by_id = array_column( $preview['rates'], null, 'id' );
recalc_smoke_assert( true === ( $rates_by_id['russian_post_domestic:pickup']['requires_pickup_point'] ?? null ), 'Russian Post pickup group must require pickup point.' );
recalc_smoke_assert( false === ( $rates_by_id['russian_post_domestic:courier']['requires_pickup_point'] ?? null ), 'Russian Post courier group must not require pickup point.' );
foreach ( $preview['rates'] as $rate ) {
	recalc_smoke_assert( empty( $rate['selected'] ), 'No preview rate may be selected by default.' );
	foreach ( is_array( $rate['tariff_variants'] ?? null ) ? $rate['tariff_variants'] : array() as $tariff ) {
		recalc_smoke_assert( empty( $tariff['selected'] ), 'No preview tariff may be selected by default.' );
	}
}

$html = ( new OrderDeliveryRateRenderer() )->render( $preview['rates'] );
recalc_smoke_assert( str_contains( $html, 'Почта России до отделения' ) && str_contains( $html, 'Почта России до двери' ), 'Renderer must show Russian Post pickup and courier groups.' );
recalc_smoke_assert( str_contains( $html, 'Посылка онлайн' ) && str_contains( $html, 'EMS' ), 'Renderer must show domestic tariff rows.' );
recalc_smoke_assert( ! str_contains( $html, ' checked' ), 'Renderer must not preselect any radio input.' );
recalc_smoke_assert( str_contains( $html, 'data-rate-payload' ) && str_contains( $html, 'data-carrier-key' ) && str_contains( $html, 'data-service-key' ), 'Pickup rate payload must contain data for pickup selection.' );
$pickup_article_pos = strpos( $html, 'data-rate-id="russian_post_domestic:pickup"' );
$courier_article_pos = strpos( $html, 'data-rate-id="russian_post_domestic:courier"' );
$pickup_article_next = false === $pickup_article_pos ? false : strpos( $html, 'data-wdc-order-delivery-rate', $pickup_article_pos + 1 );
$courier_article_next = false === $courier_article_pos ? false : strpos( $html, 'data-wdc-order-delivery-rate', $courier_article_pos + 1 );
$pickup_article_html = false === $pickup_article_pos ? '' : substr( $html, $pickup_article_pos, false === $pickup_article_next ? null : $pickup_article_next - $pickup_article_pos );
$courier_article_html = false === $courier_article_pos ? '' : substr( $html, $courier_article_pos, false === $courier_article_next ? null : $courier_article_next - $courier_article_pos );
recalc_smoke_assert( false !== $pickup_article_pos && str_contains( $pickup_article_html, 'data-wdc-pickup-selector' ), 'Pickup rate UI must show pickup selection control markup.' );
recalc_smoke_assert( false !== $courier_article_pos && ! str_contains( $courier_article_html, 'data-wdc-pickup-selector' ), 'Courier rate UI must not show pickup selection controls.' );

recalc_smoke_assert( $before_shipping === $order->shipping_items, 'Preview must not change shipping item data.' );
recalc_smoke_assert( $before_total === $order->total, 'Preview must not change order totals.' );
recalc_smoke_assert( $before_calc === $order->meta['_wdc_delivery_calculation_data'], 'Preview must not change delivery calculation meta.' );
recalc_smoke_assert( $before_shipping_city === $order->get_shipping_city() && $before_shipping_postcode === $order->get_shipping_postcode(), 'Preview with override must not change shipping address fields.' );

$blocked = new WdcRecalcOrder( 102, array() );
$blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'status' => 'created' ) );
$GLOBALS['wdc_recalc_orders'][102] = $blocked;
recalc_smoke_assert( true === $service->preview( $blocked )['success'], 'Preview must remain available when shipment status is created.' );
ob_start();
$metabox->render( $blocked );
$blocked_metabox_html = (string) ob_get_clean();
recalc_smoke_assert( str_contains( $blocked_metabox_html, 'data-wdc-order-delivery-recalculate' ), 'Metabox must keep recalculation button even when save is blocked by shipment.' );
$tracking_blocked = new WdcRecalcOrder( 103, array() );
$tracking_blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'barcode' => 'RA123' ) );
recalc_smoke_assert( true === $service->preview( $tracking_blocked )['success'], 'Preview must remain available when shipment has tracking number.' );
$backlog_blocked = new WdcRecalcOrder( 104, array() );
$backlog_blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'backlog_order_id' => '123' ) );
recalc_smoke_assert( true === $service->preview( $backlog_blocked )['success'], 'Preview must remain available when shipment has backlog_order_id.' );

$pickup_repository = wdc_recalc_pickup_repository();
$address_client = new WdcRecalcDadataSuggestionClient();
$address_normalization = new OrderDeliveryAddressNormalizationService( null, $address_client, wdc_recalc_address_suggestion_service( $address_client ) );
$controller = new OrderDeliveryRecalculationAdminController( $service, new OrderDeliveryRateRenderer(), $location_ajax, $pickup_repository, '', '1', $address_normalization );
$_POST = array( 'order_id' => 101, 'nonce' => 'ok' );
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success && isset( $response->data['html'], $response->data['rates'], $response->data['request'] ), 'Endpoint must return html, rates and request on success.' );
}

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ) );
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must send JSON response for override preview.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success && str_contains( (string) ( $response->data['request']['destination']['city'] ?? '' ), 'Москва' ), 'Endpoint must pass selected location override to preview.' );
}

$_REQUEST = array( 'nonce' => 'ok', 'query' => 'Москва', 'country_code' => 'RU' );
try {
	$controller->ajax_location_search();
	recalc_smoke_assert( false, 'Location search endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$items = $response->data['groups'][0]['items'] ?? array();
	recalc_smoke_assert( $response->success && array() !== $items && 'fias-override' === ( $items[0]['fias_id'] ?? '' ), 'Location search endpoint must return settlements.' );
}

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'selected_rate' => wp_json_encode( $rates_by_id['russian_post_domestic:pickup'] ), 'mode' => 'location', 'query' => '', 'limit' => 2000 );
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Initial pickup endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$points = $response->data['points'] ?? array();
	$point_codes = array_column( $points, 'point_code' );
	recalc_smoke_assert( $response->success && count( $points ) > 300 && in_array( '101000-OPS', $point_codes, true ) && in_array( '125009-OPS', $point_codes, true ) && in_array( '770600-OPS', $point_codes, true ) && ! in_array( '190000-OPS', $point_codes, true ), 'Initial pickup endpoint must return all pickup points for selected settlement, not one postcode or a 300-row cap.' );
	recalc_smoke_assert( isset( $points[0]['point_type'], $points[0]['point_address'], $points[0]['point_postcode'], $points[0]['lat'], $points[0]['lng'], $points[0]['point_raw'] ), 'Pickup endpoint must return selectedPickupPoint map payload fields.' );
}
$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'selected_rate' => wp_json_encode( $rates_by_id['russian_post_domestic:pickup'] ), 'mode' => 'search', 'query' => '101000' );
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Manual pickup endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$points = $response->data['points'] ?? array();
	$point_codes = array_column( $points, 'point_code' );
	recalc_smoke_assert( $response->success && in_array( '101000-OPS', $point_codes, true ) && ! in_array( '125009-OPS', $point_codes, true ), 'Manual pickup endpoint must still allow exact postcode search.' );
}
$pickup_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/order-delivery-recalculation.js' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-wdc-pickup-picker-map' ), 'Pickup picker markup must contain map container.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-wdc-pickup-picker-list' ), 'Pickup picker markup must contain list container.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-index="' . "' + escapeAttribute( String( index ) ) + '" ), 'Pickup picker data-index attribute must use escapeAttribute().' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "runSearch( 'location' )" ) && str_contains( $pickup_js, "runSearch( 'search' )" ), 'Pickup picker JS must keep initial load and manual address search entrypoints.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "const value = mode === 'location' ? '' : String( query.value || '' ).trim();" ), 'Pickup picker initial load must not send postcode as backend query.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'prefillCurrentPickupIfAvailable' ) && str_contains( $pickup_js, 'data-wdc-order-delivery-current-pickup' ) && str_contains( $pickup_js, 'pickupMatchesRate( pickup, rate )' ), 'JS must prefill current pickup only when it matches the selected carrier.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'scrollActivePickupRow' ) && str_contains( $pickup_js, 'scrollIntoView' ) && str_contains( $pickup_js, 'setActivePoint' ), 'JS marker click must sync active marker and list row.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'geocodeAddressAction' ) && str_contains( $pickup_js, 'wdc_order_delivery_recalculate_geocode_address' ) && str_contains( $pickup_js, 'searchMarker:' ), 'JS manual address search must geocode through admin endpoint and pass a temporary search marker.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'loadPickupPointsForLocation' ) && str_contains( $pickup_js, "form.append( 'mode', modeOverride || 'location' );" ) && str_contains( $pickup_js, 'geocodeAddress( box, value )' ) && ! str_contains( $pickup_js, "loadPickupPointsForLocation( 'search', value )" ), 'JS pickup loader must keep location mode by default and use shared DaData geocoding for manual search.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "? 1000 : 2000" ) && ! str_contains( $pickup_js, "? 1000 : 300" ), 'Admin recalculation pickup loader must not cap Russian Post location lists at 300.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'renderSearchResults( \'address\', value' ) && str_contains( $pickup_js, 'provider.setCenter( searchMarker.lat, searchMarker.lng, 15 );' ), 'JS manual address search must keep city pickup points rendered and center the map on the DaData marker.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'let pointsGeneration = 0;' ) && str_contains( $pickup_js, 'let boundsGeneration = -1;' ) && str_contains( $pickup_js, 'let currentBounds = null;' ), 'Admin pickup picker must track bounds against the current loaded points generation.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function normalizeBounds' ) && str_contains( $pickup_js, 'function pointInsideBounds' ) && str_contains( $pickup_js, 'return lng >= bounds.west && lng <= bounds.east && lat >= bounds.south && lat <= bounds.north;' ), 'Admin pickup picker must use checkout-compatible west,south,east,north bounds filtering.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function visiblePickupPoints' ) && str_contains( $pickup_js, 'if ( ! currentBounds || boundsGeneration !== pointsGeneration )' ) && str_contains( $pickup_js, 'return points.filter( function ( point )' ), 'Admin pickup picker side list must not apply stale bounds from a previous points dataset.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'provider.renderMarkers( points' ) && ! str_contains( $pickup_js, 'provider.renderMarkers( visiblePoints' ), 'Admin pickup picker must keep the full marker dataset on the map while filtering only the side list.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'onBoundsChange: function ( bounds )' ) && str_contains( $pickup_js, 'scheduleBoundsRender( bounds );' ) && ! str_contains( $pickup_js, 'onBoundsChange: function () {}' ), 'Admin pickup picker bounds changes must rerender locally and not keep the empty callback.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function syncCurrentProviderBounds' ) && str_contains( $pickup_js, 'provider.getBounds' ) && str_contains( $pickup_js, 'function scheduleProviderBoundsSync' ) && str_contains( $pickup_js, 'scheduleProviderBoundsSync();' ), 'Admin pickup picker must synchronize provider bounds after map camera actions.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function initialMapCenter' ) && str_contains( $pickup_js, 'selectedPickupPoints.get( box )' ) && str_contains( $pickup_js, 'center: initialMapCenter()' ), 'Admin pickup picker initial center must prefer selected pickup/location coordinates before fallback.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'На текущем участке карты ПВЗ не видны. Отдалите карту или переместите её.' ) && str_contains( $pickup_js, 'Показано \' + visibleCount + \' из \' + totalCount + \' ПВЗ.' ), 'Admin pickup picker must show viewport counts and a distinct empty-viewport message.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'const visiblePoints = visiblePickupPoints();' ) && str_contains( $pickup_js, 'visiblePoints.map( function ( point, index )' ) && str_contains( $pickup_js, 'const point = findPoint( row.getAttribute( \'data-wdc-point-id\' ) );' ), 'Admin pickup picker rows must use visible indexes while selection stays based on full dataset point ids.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'через DaData' ) && str_contains( $pickup_js, "status.textContent = 'Ищем адрес...'" ) && str_contains( $pickup_js, "'Адрес найден.'" ) && str_contains( $pickup_js, "'Адрес не найден.'" ), 'Pickup map address search UI must not mention DaData.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-wdc-pickup-picker-confirm' ) && ! str_contains( $pickup_js, 'data-wdc-pickup-picker-choose' ) && ! str_contains( $pickup_js, 'data-wdc-pickup-popup-select' ) && ! str_contains( $pickup_js, 'wdc-order-delivery-pickup-picker__selected-grid' ), 'Admin recalculation pickup picker must use one bottom select button and no per-card duplicate select controls.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "'ПВЗ СДЭК'" ) && str_contains( $pickup_js, "'Постамат СДЭК'" ), 'Admin recalculation pickup picker must render CDEK pickup/postamat titles.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'searchMarkerFromQuery' ), 'JS manual address search must not use the first pickup point as an address marker fallback.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'data-wdc-pickup-address-block' ), 'Pickup UI must not render address normalization block.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-wdc-courier-address-block' ) && str_contains( $pickup_js, 'data-wdc-courier-address-suggestions' ) && ! str_contains( $pickup_js, 'data-wdc-normalize-courier-address' ), 'Courier UI source must render automatic suggestions without old check-address button.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "requestPreview( box, box.querySelector( '[data-wdc-order-delivery-modal-preview]' ) );" ), 'Location selection must trigger preview automatically.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function syncPreviewLocation' ) && str_contains( $pickup_js, 'syncPreviewLocation( box, payload.data && payload.data.location );' ) && str_contains( $pickup_js, 'selectedLocations.set( box, mergedLocation );' ) && str_contains( $pickup_js, 'updateLocationSummary( box, mergedLocation );' ), 'JS preview success must sync resolved location payload into selectedLocations.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function mergeMeaningfulFields' ) && str_contains( $pickup_js, 'const currentId = positiveLocationId( currentLocation.id || currentLocation.location_id );' ) && str_contains( $pickup_js, 'const previewId = positiveLocationId( previewLocation.id || previewLocation.location_id );' ), 'JS preview location sync must preserve full selected location payload while filling missing resolved id/location_id.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function isYandexPickupPoint' ) && str_contains( $pickup_js, "'yandex_delivery:pickup' === family" ) && str_contains( $pickup_js, "return '';" ), 'JS must detect Yandex pickup points and hide their technical display code.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'function pickupPointPresentationComment' ) && str_contains( $pickup_js, 'wdc-pickup-popup__title-comment' ) && str_contains( $pickup_js, 'wdc-pickup-list__title-comment' ), 'JS must render Yandex presentation_comment in popup and side card using checkout presentation classes.' );
$heading_pos = is_string( $pickup_js ) ? strpos( $pickup_js, 'class="wdc-order-delivery-pickup-picker__heading"><strong>\' + title + \'</strong>\' + commentHtml' ) : false;
$address_pos = is_string( $pickup_js ) ? strpos( $pickup_js, '</span><span>\' + escapeHtml( pickupPointLabel( point ) ) + \'</span>' ) : false;
recalc_smoke_assert( false !== $heading_pos && false !== $address_pos && $heading_pos < $address_pos, 'Pickup side card must render presentation_comment in a vertical heading container before the address.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'updateConfirmButton();' ) && str_contains( $pickup_js, 'renderPickupPoints();' ) && ! str_contains( $pickup_js, 'selectedPickupPoints.delete( box );' . PHP_EOL . "\t\t\trenderPickupPoints" ), 'Viewport rerender must not clear selected pickup or disable confirm when selected point moves out of view.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'Населенный пункт выбран. Нажмите' ), 'JS must not ask admin to click recalculate after selecting location.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'Проверьте адрес перед сохранением.' ) && ! str_contains( $pickup_js, 'Проверьте адрес через DaData перед сохранением.' ), 'Courier address hint must not mention DaData.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'Использовать этот адрес' ) && str_contains( $pickup_js, 'data-wdc-use-manual-courier-address disabled="disabled"' ), 'Courier block must render disabled manual address button by default.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-wdc-courier-address-suggestions' ) && str_contains( $pickup_js, 'data-wdc-courier-address-suggestion' ), 'Courier block must render DaData suggestions under address input.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'chooseCourierAddressSuggestion' ) && str_contains( $pickup_js, 'finalizeCourierAddress' ) && str_contains( $pickup_js, 'normalizedShippingAddresses.set( box, address );' ), 'Selecting courier suggestion must store normalized address state.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'requestCourierAddressSuggestions' ) && str_contains( $pickup_js, 'Выберите адрес из подсказок.' ), 'JS must render shared DaData suggestions without auto-selecting them.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'console.debug' ), 'JS must not output temporary courier suggest debug data.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'Адрес не удалось нормализовать. Можно использовать введенный адрес вручную.' ) && str_contains( $pickup_js, "manualButton.disabled = String( query || '' ).trim() === '';" ), 'Normalize failure must enable manual address button when input is not empty.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "source: 'admin_manual'" ) && str_contains( $pickup_js, 'Адрес будет сохранен без нормализации.' ), 'Manual courier address button must store admin_manual fallback payload.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'normalizedShippingAddresses.delete( box );' ) && str_contains( $pickup_js, 'manualButton.disabled = true;' ) && str_contains( $pickup_js, 'clearCourierAddressSuggestions( block );' ), 'Courier address input change must reset normalized/manual address state and suggestions.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'wdc_order_delivery_recalculate_save' ) && str_contains( $pickup_js, 'window.location.reload()' ), 'JS must call save endpoint and reload after success.' );
$pickup_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/order-delivery-recalculation.css' );
recalc_smoke_assert( is_string( $pickup_css ) && str_contains( $pickup_css, 'width: min(1500px, 95vw)' ) && str_contains( $pickup_css, 'height: min(860px, 90vh)' ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__list' ) && str_contains( $pickup_css, 'overflow-y: auto;' ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__footer' ), 'Pickup picker CSS must keep a large map layout and scroll the side list separately.' );
recalc_smoke_assert( is_string( $pickup_css ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__heading' ) && str_contains( $pickup_css, 'display: grid;' ) && str_contains( $pickup_css, 'gap: 4px;' ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__heading > strong' ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__heading > .wdc-pickup-list__title-comment' ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__heading > em' ) && str_contains( $pickup_css, 'margin-top: 2px;' ), 'Pickup picker CSS must render presentation comments on a separate heading line.' );
$yandex_provider_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js' );
$leaflet_provider_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js' );
recalc_smoke_assert( is_string( $yandex_provider_js ) && str_contains( $yandex_provider_js, 'function currentBoundsValue()' ) && str_contains( $yandex_provider_js, 'settings.onBoundsChange(bounds);' ) && str_contains( $yandex_provider_js, 'getBounds: currentBoundsValue' ), 'Yandex pickup map provider must expose read-only getBounds using the same converter as boundsChanged().' );
recalc_smoke_assert( is_string( $leaflet_provider_js ) && str_contains( $leaflet_provider_js, 'function currentBoundsValue()' ) && str_contains( $leaflet_provider_js, 'settings.onBoundsChange(bounds);' ) && str_contains( $leaflet_provider_js, 'getBounds: currentBoundsValue' ), 'Leaflet pickup map provider must expose read-only getBounds using the same converter as boundsChanged().' );
recalc_smoke_run_node( <<<'JS'
const assert = require('assert');

let points = [];
let pointsGeneration = 0;
let boundsGeneration = -1;
let currentBounds = null;
let fetchCount = 0;
let markerRenderCount = 0;
let focusCount = 0;
let selectedPoint = null;
let providerMarkers = [];
let providerBounds = null;

function normalizeBounds(bbox) {
	if (bbox && typeof bbox === 'object' && !Array.isArray(bbox)) {
		const westValue = parseFloat(bbox.west);
		const southValue = parseFloat(bbox.south);
		const eastValue = parseFloat(bbox.east);
		const northValue = parseFloat(bbox.north);
		if ([westValue, southValue, eastValue, northValue].some((value) => Number.isNaN(value))) {
			return null;
		}
		return { west: westValue, south: southValue, east: eastValue, north: northValue };
	}
	const values = Array.isArray(bbox) ? bbox : String(bbox || '').split(',');
	if (values.length < 4) {
		return null;
	}
	const west = parseFloat(values[0]);
	const south = parseFloat(values[1]);
	const east = parseFloat(values[2]);
	const north = parseFloat(values[3]);
	if ([west, south, east, north].some((value) => Number.isNaN(value))) {
		return null;
	}
	return { west, south, east, north };
}

function validPointCoordinates(point) {
	return point && point.lat !== null && point.lng !== null && Number.isFinite(parseFloat(point.lat)) && Number.isFinite(parseFloat(point.lng));
}

function pointInsideBounds(point, bounds) {
	bounds = normalizeBounds(bounds);
	if (!bounds || !validPointCoordinates(point)) {
		return false;
	}
	const lat = parseFloat(point.lat);
	const lng = parseFloat(point.lng);
	return lng >= bounds.west && lng <= bounds.east && lat >= bounds.south && lat <= bounds.north;
}

function visiblePickupPoints() {
	if (!currentBounds || boundsGeneration !== pointsGeneration) {
		return points;
	}
	return points.filter((point) => pointInsideBounds(point, currentBounds));
}

function viewportMessage() {
	const visibleCount = visiblePickupPoints().length;
	if (points.length > 0 && currentBounds && boundsGeneration === pointsGeneration && visibleCount <= 0) {
		return 'На текущем участке карты ПВЗ не видны. Отдалите карту или переместите её.';
	}
	return 'Показано ' + visibleCount + ' из ' + points.length + ' ПВЗ.';
}

function onBoundsChange(bounds) {
	const normalized = normalizeBounds(bounds);
	if (!normalized) {
		return;
	}
	currentBounds = normalized;
	boundsGeneration = pointsGeneration;
}

function loadPoints(nextPoints) {
	fetchCount += 1;
	pointsGeneration += 1;
	currentBounds = null;
	boundsGeneration = -1;
	points = nextPoints.slice();
}

function renderMarkers(nextPoints) {
	markerRenderCount += 1;
	providerMarkers = nextPoints.slice();
}

function syncCurrentProviderBounds() {
	const bounds = normalizeBounds(providerBounds);
	if (!bounds) {
		return false;
	}
	currentBounds = bounds;
	boundsGeneration = pointsGeneration;
	return true;
}

function focusPoint() {
	focusCount += 1;
}

const moscowA = { id: 'A', lat: 55.75, lng: 37.62 };
const moscowB = { id: 'B', lat: 55.76, lng: 37.63 };
const moscowC = { id: 'C', lat: 55.9, lng: 37.9 };

assert.strictEqual(pointInsideBounds(moscowA, '37.50,55.65,37.75,55.85'), true, 'Moscow point must be inside Moscow bbox.');
assert.strictEqual(pointInsideBounds(moscowA, '82.80,54.90,83.10,55.10'), false, 'Moscow point must be outside Novosibirsk bbox.');

onBoundsChange('82.80,54.90,83.10,55.10');
assert.strictEqual(boundsGeneration, 0, 'Initial bounds belong only to the empty dataset generation.');
loadPoints([moscowA, moscowB, moscowC]);
assert.strictEqual(boundsGeneration !== pointsGeneration, true, 'Loading Moscow points must invalidate stale Novosibirsk bounds.');
assert.strictEqual(visiblePickupPoints().length, 3, 'Before fresh bounds, stale Novosibirsk bounds must not hide Moscow points.');
assert.notStrictEqual(viewportMessage(), 'На текущем участке карты ПВЗ не видны. Отдалите карту или переместите её.', 'No false empty state during camera transition.');
renderMarkers(points);
assert.strictEqual(providerMarkers.length, 3, 'Provider must receive full marker dataset.');

providerBounds = '37.50,55.65,38.00,55.95';
assert.strictEqual(syncCurrentProviderBounds(), true, 'Provider getBounds result must sync after fitToMarkers/focusPoint.');
assert.strictEqual(boundsGeneration, pointsGeneration, 'Synced provider bounds must be tied to current generation.');
assert.strictEqual(visiblePickupPoints().length, 3, 'Fresh Moscow fit bounds must show Moscow points.');

selectedPoint = moscowA;
const fetchBeforePan = fetchCount;
const markerRendersBeforePan = markerRenderCount;
const focusBeforePan = focusCount;
onBoundsChange('37.50,55.65,37.75,55.85');
assert.deepStrictEqual(visiblePickupPoints().map((point) => point.id), ['A', 'B'], 'Narrow Moscow bounds must show only visible points.');
onBoundsChange('37.85,55.85,38.00,55.95');
assert.deepStrictEqual(visiblePickupPoints().map((point) => point.id), ['C'], 'Pan/zoom bounds must locally replace visible side-list points.');
assert.strictEqual(fetchCount, fetchBeforePan, 'Bounds changes must not perform REST fetches.');
assert.strictEqual(markerRenderCount, markerRendersBeforePan, 'Bounds changes must not rerender full marker dataset.');
assert.strictEqual(focusCount, focusBeforePan, 'Bounds changes must not refocus the selected point.');
assert.strictEqual(selectedPoint.id, 'A', 'Selected point must remain selected when it leaves the viewport.');

onBoundsChange('30.00,50.00,31.00,51.00');
assert.strictEqual(visiblePickupPoints().length, 0, 'Empty viewport with current-generation bounds must be allowed.');
assert.strictEqual(viewportMessage(), 'На текущем участке карты ПВЗ не видны. Отдалите карту или переместите её.', 'Empty viewport message appears only for current-generation bounds.');
JS
, 'Runtime JS smoke for pickup viewport bounds generation must pass' );
recalc_smoke_assert( $before_shipping === $order->shipping_items, 'Pickup endpoint must not change shipping item data.' );
recalc_smoke_assert( $before_total === $order->total, 'Pickup endpoint must not change order totals.' );
recalc_smoke_assert( $before_calc === $order->meta['_wdc_delivery_calculation_data'], 'Pickup endpoint must not change delivery calculation meta.' );
recalc_smoke_assert( $before_shipping_city === $order->get_shipping_city() && $before_shipping_postcode === $order->get_shipping_postcode(), 'Pickup endpoint must not change shipping address fields.' );

$replacement = new OrderDeliveryReplacementService( new OrderShipmentRepository(), new DeliveryDateFormatter() );
$pickup_point = array(
	'point_code' => '101000-OPS',
	'point_type' => 'OPS',
	'point_name' => 'ОПС 101000',
	'point_address' => 'Москва, ул. Тверская, 1',
	'point_postcode' => '101000',
	'point_raw' => array( 'point_code' => '101000-OPS' ),
);
$normalized_address = array(
	'country' => 'RU',
	'region' => 'Москва',
	'city' => 'Москва',
	'postcode' => '101000',
	'street' => 'Тверская',
	'house' => '10',
	'flat' => '5',
	'address_1' => 'Тверская, 10',
	'address_2' => '5',
	'full_address' => 'Москва, Тверская, 10, кв. 5',
	'fias_id' => 'address-fias',
	'gar_id' => 'address-gar',
	'normalized' => true,
	'fallback' => false,
	'source' => 'dadata',
);
$pickup_rate = $rates_by_id['russian_post_domestic:pickup'];
$pickup_rate['selected_tariff'] = $pickup_rate['tariff_variants'][0] ?? array();
$courier_rate = $rates_by_id['russian_post_domestic:courier'];
$courier_rate['selected_tariff'] = $courier_rate['tariff_variants'][0] ?? array();
$courier_rate['planned_delivery_date'] = '2026-08-12';
$courier_rate['selected_tariff']['planned_delivery_date'] = '2026-08-12';

$invalid_pickup = new WdcRecalcOrder( 105, array() );
$invalid_pickup->shipping_items = array( 'method_title' => 'Old delivery', 'total' => 111.0 );
$invalid_before_meta = $invalid_pickup->meta;
$invalid_result = $replacement->save(
	$invalid_pickup,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $pickup_rate,
		'selected_tariff' => $pickup_rate['selected_tariff'],
		'selected_pickup_point' => array(),
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( false === $invalid_result['success'] && $invalid_before_meta === $invalid_pickup->meta, 'Save pickup must require selectedPickupPoint and must not mutate order on validation error.' );
$valid_pickup_without_address = $replacement->save(
	$invalid_pickup,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $pickup_rate,
		'selected_tariff' => $pickup_rate['selected_tariff'],
		'selected_pickup_point' => $pickup_point,
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( true === $valid_pickup_without_address['success'], 'Save pickup must not require normalized shipping address.' );

$invalid_courier = new WdcRecalcOrder( 112, array() );
$invalid_courier_before = $invalid_courier->shipping_items;
$invalid_courier_result = $replacement->save(
	$invalid_courier,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $courier_rate,
		'selected_tariff' => $courier_rate['selected_tariff'],
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( false === $invalid_courier_result['success'] && $invalid_courier_before === $invalid_courier->shipping_items, 'Save courier must require normalized shipping address and avoid mutation on validation error.' );

$manual_courier_order = new WdcRecalcOrder( 114, array() );
$manual_courier_address = array(
	'country' => 'RU',
	'region' => 'Москва',
	'city' => 'Москва',
	'postcode' => '101000',
	'address_1' => 'Москва, Тверская, 10, кв 5',
	'address_2' => '',
	'full_address' => 'Москва, Тверская, 10, кв 5',
	'normalized' => false,
	'fallback' => true,
	'source' => 'admin_manual',
);
$manual_courier_result = $replacement->save(
	$manual_courier_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $courier_rate,
		'selected_tariff' => $courier_rate['selected_tariff'],
		'normalized_shipping_address' => $manual_courier_address,
	)
);
recalc_smoke_assert( true === $manual_courier_result['success'], 'Save courier must accept admin_manual fallback address.' );
recalc_smoke_assert( 'Москва, Тверская, 10, кв 5' === $manual_courier_order->get_shipping_address_1() && '' === $manual_courier_order->get_shipping_address_2(), 'Manual fallback courier address must be written as address_1 without address_2.' );
recalc_smoke_assert( (string) ( $selected_location['city_value'] ?? '' ) === $manual_courier_order->get_shipping_city() && (string) ( $selected_location['state_value'] ?? $selected_location['region_name'] ?? '' ) === $manual_courier_order->get_shipping_state(), 'Manual fallback courier save must use checkout-compatible selected location city/state.' );

$normalized_flat_order = new WdcRecalcOrder( 115, array() );
$normalized_flat_address = array_merge(
	$normalized_address,
	array(
		'address_1' => 'ул Некрасова, д 63/1, кв 10',
		'address_2' => '',
		'full_address' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, кв 10',
		'city' => 'г Новосибирск',
		'region' => 'Новосибирская область',
		'postcode' => '630005',
	)
);
$normalized_flat_result = $replacement->save(
	$normalized_flat_order,
	array(
		'selected_location' => array( 'display_name' => 'Новосибирская область, г Новосибирск', 'city_value' => 'г Новосибирск', 'region_name' => 'Новосибирская область', 'postal_code' => '630005', 'country_code' => 'RU' ),
		'selected_rate' => $courier_rate,
		'selected_tariff' => $courier_rate['selected_tariff'],
		'normalized_shipping_address' => $normalized_flat_address,
	)
);
recalc_smoke_assert( true === $normalized_flat_result['success'] && 'ул Некрасова, д 63/1, кв 10' === $normalized_flat_order->get_shipping_address_1() && '' === $normalized_flat_order->get_shipping_address_2(), 'Save normalized courier address must keep flat in address_1 and leave address_2 empty.' );
recalc_smoke_assert( 'г Новосибирск' === $normalized_flat_order->get_shipping_city() && 'Новосибирская область' === $normalized_flat_order->get_shipping_state(), 'Save normalized courier address must use checkout-compatible selected location city/state instead of full display_name.' );

$no_shipping_order = new WdcRecalcOrder( 106, array() );
$no_shipping_order->shipping_items = array();
$create_result = $replacement->save(
	$no_shipping_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $courier_rate,
		'selected_tariff' => $courier_rate['selected_tariff'],
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $create_result['success'] && 'wdc_platform_delivery' === ( $no_shipping_order->shipping_items['method_id'] ?? '' ), 'Save must create shipping item when order has none.' );
recalc_smoke_assert( 5700.0 === $no_shipping_order->total && $no_shipping_order->saved, 'Save must recalculate totals and save order after creating shipping item.' );
recalc_smoke_assert( isset( $no_shipping_order->meta['_wdc_delivery_calculation_data'], $no_shipping_order->meta['_wdc_platform_rate_id'], $no_shipping_order->meta['_wdc_platform_delivery_type'] ), 'Save must update WDC calculation and platform meta.' );
recalc_smoke_assert( array() !== $no_shipping_order->notes && false === $no_shipping_order->notes[0]['customer'], 'Save must add private order note.' );
recalc_smoke_assert( array( 'Планируемая* дата доставки' => 'с 12 августа 2026' ) === ( $no_shipping_order->shipping_items['meta'] ?? array() ), 'Russian Post domestic admin visible meta must contain only planned delivery date.' );

$unspecified_rate = $courier_rate;
$unspecified_rate['id'] = 'future:carrier';
$unspecified_rate['rate_id'] = 'future:carrier';
$unspecified_rate['carrier_key'] = 'future';
$unspecified_rate['service_key'] = 'future';
$unspecified_rate['label'] = 'Future carrier';
$unspecified_rate['delivery_comment'] = '';
$unspecified_rate['planned_delivery_date'] = '';
$unspecified_rate['planned_delivery_comment'] = '';
$unspecified_rate['selected_tariff'] = array();
$unspecified_rate['tariff_title'] = '';
$unspecified_rate['selected_tariff_title'] = '';
$unspecified_order = new WdcRecalcOrder( 118, array() );
$unspecified_order->shipping_items = array();
$unspecified_result = $replacement->save(
	$unspecified_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $unspecified_rate,
		'selected_tariff' => array(),
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $unspecified_result['success'] && array() === ( $unspecified_order->shipping_items['meta'] ?? array() ), 'Admin replacement visible meta must be omitted when planned date is missing.' );

$cdek_admin_rate = array(
	'id' => 'cdek:courier:137',
	'rate_id' => 'cdek:courier:137',
	'carrier_key' => 'cdek',
	'service_key' => 'cdek',
	'service_title' => 'СДЭК',
	'label' => 'СДЭК дверь тест',
	'delivery_type' => 'courier',
	'delivery_comment' => '10-14 дней',
	'planned_delivery_date' => '2026-08-12',
	'planned_delivery_comment' => '10-14 дней',
	'cost' => 650.0,
	'api_base_price_rub' => 520.0,
	'tariff_key' => '137',
	'tariff_title' => 'Посылка склад-дверь',
	'selected_tariff_object' => '137',
	'selected_tariff_title' => 'Посылка склад-дверь',
	'rules_source' => 'rule_engine',
	'rules_audit' => array( 'cdek-rule' ),
	'rate_meta' => array(
		'api_base_price_rub' => 520.0,
		'final_price_rub' => 650.0,
		'rules_source' => 'rule_engine',
		'rules_audit' => array( 'cdek-rule' ),
		'package' => array(
			'weight_g' => 1200,
			'products_weight_g' => 900,
			'packaging_weight_g' => 300,
			'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ),
		),
		'location' => array( 'cdek_to_city_code' => 44, 'cdek_to_city_name' => 'Москва' ),
		'request_payload_sanitized' => array( 'from_location' => array( 'code' => 270 ), 'to_location' => array( 'code' => 44 ) ),
		'response_tariff_sanitized' => array( 'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь' ),
	),
);
$cdek_admin_order = new WdcRecalcOrder( 117, array() );
$cdek_admin_order->shipping_items = array();
$cdek_admin_result = $replacement->save(
	$cdek_admin_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_admin_rate,
		'selected_tariff' => array(),
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $cdek_admin_result['success'], 'CDEK admin save must succeed for courier rate.' );
recalc_smoke_assert( 'СДЭК дверь тест, Посылка склад-дверь - 10-14 дней' === (string) ( $cdek_admin_order->shipping_items['method_title'] ?? '' ), 'CDEK admin save must build method title with custom method, tariff and delivery text.' );
recalc_smoke_assert( 1 === substr_count( (string) ( $cdek_admin_order->shipping_items['method_title'] ?? '' ), '10-14 дней' ), 'CDEK admin save method title must not duplicate delivery text.' );
recalc_smoke_assert( array( 'Планируемая* дата доставки' => 'с 12 августа 2026' ) === ( $cdek_admin_order->shipping_items['meta'] ?? array() ), 'CDEK admin replacement visible meta must contain only planned delivery date.' );
foreach ( array( 'carrier_key', 'rate_id', 'delivery_type', 'service_key', 'api_base_price_rub', 'tariff_key', 'selected_tariff_object', 'Перевозчик', 'Способ доставки', 'Тип доставки', 'Населенный пункт', 'Нормализация' ) as $forbidden_meta_key ) {
	recalc_smoke_assert( ! array_key_exists( $forbidden_meta_key, $cdek_admin_order->shipping_items['meta'] ?? array() ), 'CDEK admin replacement visible meta must not contain technical key: ' . $forbidden_meta_key );
}
$cdek_admin_calc = $cdek_admin_order->meta['_wdc_delivery_calculation_data'] ?? array();
recalc_smoke_assert( isset( $cdek_admin_order->meta['_wdc_platform_rate_meta'] ), 'CDEK admin save must keep hidden platform rate meta.' );
recalc_smoke_assert( 520.0 === (float) ( $cdek_admin_calc['api']['api_base_price_rub'] ?? 0 ) && 650.0 === (float) ( $cdek_admin_calc['result']['final_price_rub'] ?? 0 ), 'CDEK admin calculation data must preserve API base and final prices.' );
recalc_smoke_assert( 44 === (int) ( $cdek_admin_calc['api']['cdek_to_city_code'] ?? 0 ), 'CDEK admin calculation data must sync rate_meta.location.cdek_to_city_code into api data.' );
recalc_smoke_assert( 900 === (int) ( $cdek_admin_calc['package']['products_weight_g'] ?? 0 ) && 300 === (int) ( $cdek_admin_calc['package']['packaging_weight_g'] ?? 0 ) && 1200 === (int) ( $cdek_admin_calc['package']['final_weight_g'] ?? 0 ), 'CDEK admin calculation data must preserve package weights.' );
recalc_smoke_assert( array( 'cdek-rule' ) === ( $cdek_admin_calc['rules']['applied_rules'] ?? null ), 'CDEK admin calculation data must preserve rules data.' );

$cdek_pickup_rate = $cdek_admin_rate;
$cdek_pickup_rate['id'] = 'cdek:pickup:136';
$cdek_pickup_rate['rate_id'] = 'cdek:pickup:136';
$cdek_pickup_rate['label'] = 'СДЭК до пункта выдачи';
$cdek_pickup_rate['delivery_type'] = 'pickup';
$cdek_pickup_rate['requires_pickup_point'] = true;
$cdek_pickup_rate['tariff_key'] = '136';
$cdek_pickup_rate['tariff_title'] = 'Посылка склад-склад';
$cdek_pickup_rate['selected_tariff_title'] = 'Посылка склад-склад';
$cdek_pickup_rate['selected_tariff_object'] = '136';
$cdek_pickup_point = array(
	'carrier_key' => 'cdek',
	'point_code' => 'KEM7',
	'code' => 'KEM7',
	'cdek_code' => 'KEM7',
	'point_type' => 'POSTAMAT',
	'cdek_type' => 'POSTAMAT',
	'point_name' => 'CDEK Postamat',
	'point_address' => 'Kemerovo, Sovetskiy 10',
	'address' => 'Kemerovo, Sovetskiy 10',
	'point_postcode' => '650004',
	'postcode' => '650004',
	'city_name' => 'Kemerovo',
	'region_name' => 'Kemerovo region',
	'work_time' => '0.000000',
	'point_work_time' => '0.000000',
	'description' => 'Inside the shopping center',
	'storage_notice' => 'Срок хранения 3 дня',
	'raw_sanitized' => array( 'code' => 'KEM7', 'type' => 'POSTAMAT' ),
);
$cdek_pickup_order = new WdcRecalcOrder( 120, array() );
$cdek_pickup_order->shipping_items = array();
$cdek_pickup_result = $replacement->save(
	$cdek_pickup_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_pickup_rate,
		'selected_tariff' => array(),
		'selected_pickup_point' => $cdek_pickup_point,
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( true === $cdek_pickup_result['success'], 'CDEK admin pickup save must succeed with selected point.' );
recalc_smoke_assert( 'KEM7' === (string) ( $cdek_pickup_order->meta['_wdc_pickup_point_code'] ?? '' ), 'CDEK admin pickup save must keep CDEK point code instead of postcode.' );
recalc_smoke_assert( 'Kemerovo, Sovetskiy 10' === $cdek_pickup_order->get_shipping_address_1() && '650004' === $cdek_pickup_order->get_shipping_postcode(), 'CDEK admin pickup save must write pickup shipping address.' );
$cdek_pickup_calc = $cdek_pickup_order->meta['_wdc_delivery_calculation_data'] ?? array();
recalc_smoke_assert( 'Kemerovo, Sovetskiy 10' === ( $cdek_pickup_calc['pickup']['point_address'] ?? '' ) && '650004' === ( $cdek_pickup_calc['pickup']['point_postcode'] ?? '' ), 'CDEK admin pickup calculation data must keep full pickup address payload.' );
recalc_smoke_assert( '136' === (string) ( $cdek_pickup_order->meta['_wdc_platform_tariff_object'] ?? '' ) && '136' === (string) ( $cdek_pickup_calc['selected_tariff_object'] ?? '' ) && DeliveryType::PICKUP === (string) ( $cdek_pickup_calc['delivery_type'] ?? '' ), 'CDEK admin pickup save must keep selected tariff object and delivery_type for shipment modal fallback.' );
recalc_smoke_assert( 'Inside the shopping center' === ( $cdek_pickup_calc['pickup']['description'] ?? '' ), 'CDEK admin pickup calculation data must save description.' );
recalc_smoke_assert( '' === ( $cdek_pickup_calc['pickup']['work_time'] ?? '' ), 'CDEK admin pickup calculation data must not save numeric zero work_time.' );
recalc_smoke_assert( 'Срок хранения 3 дня' === ( $cdek_pickup_calc['pickup']['storage_notice'] ?? '' ), 'CDEK admin pickup calculation data must save POSTAMAT storage notice.' );
recalc_smoke_assert( str_contains( (string) ( $cdek_pickup_order->meta['_wdc_pickup_point_snapshot'] ?? '' ), 'Kemerovo, Sovetskiy 10' ) && str_contains( (string) ( $cdek_pickup_order->meta['_wdc_pickup_point_snapshot'] ?? '' ), 'Inside the shopping center' ), 'CDEK admin pickup snapshot meta must keep full point payload for order/email cards.' );
ob_start();
( new PickupPointOrderDisplay( new PickupPointCardRenderer(), new SettingsRepository() ) )->render( $cdek_pickup_order );
$cdek_pickup_order_card = (string) ob_get_clean();
recalc_smoke_assert( str_contains( $cdek_pickup_order_card, 'Постамат СДЭК' ), 'CDEK admin pickup order card must render POSTAMAT title.' );
recalc_smoke_assert( str_contains( $cdek_pickup_order_card, 'Kemerovo, Sovetskiy 10' ) && ! str_contains( $cdek_pickup_order_card, 'Код пункта:' ), 'CDEK admin pickup order card must render address and hide code row by default.' );
recalc_smoke_assert( str_contains( $cdek_pickup_order_card, 'Описание:' ) && str_contains( $cdek_pickup_order_card, 'Inside the shopping center' ), 'CDEK admin pickup order card must render description with label.' );
recalc_smoke_assert( str_contains( $cdek_pickup_order_card, 'Срок хранения 3 дня' ), 'CDEK admin pickup order card must render storage notice.' );
recalc_smoke_assert( ! str_contains( $cdek_pickup_order_card, 'Время работы:' ) && ! str_contains( $cdek_pickup_order_card, '0.000000' ), 'CDEK admin pickup order card must hide empty work_time and numeric zero values.' );

$cdek_existing_pickup_order = new WdcRecalcOrder( 121, array() );
$cdek_existing_pickup_order->shipping_items = array();
$cdek_existing_pickup_order->meta['_wdc_platform_pickup_code'] = 'MSK575';
$cdek_existing_pickup_order->meta['_wdc_pickup_point_code'] = 'MSK575';
$cdek_existing_pickup_order->meta['_wdc_pickup_point_address'] = '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1';
$cdek_existing_pickup_order->meta['_wdc_pickup_point_postcode'] = '101000';
$cdek_existing_pickup_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => 'cdek',
	'delivery_type' => DeliveryType::PICKUP,
	'pickup' => array(
		'carrier_key' => 'cdek',
		'service_key' => 'cdek',
		'pickup_family' => 'cdek:pickup',
		'point_code' => 'MSK575',
		'cdek_code' => 'MSK575',
		'delivery_point' => 'MSK575',
		'point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
		'point_postcode' => '101000',
	),
);
$cdek_existing_pickup_result = $replacement->save(
	$cdek_existing_pickup_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_pickup_rate,
		'selected_tariff' => array(),
		'selected_pickup_point' => array(
			'carrier_key' => 'cdek',
			'point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
			'address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
			'point_postcode' => '101000',
			'postcode' => '101000',
		),
		'normalized_shipping_address' => array(),
	)
);
$cdek_existing_calc = is_array( $cdek_existing_pickup_order->meta['_wdc_delivery_calculation_data'] ?? null ) ? $cdek_existing_pickup_order->meta['_wdc_delivery_calculation_data'] : array();
recalc_smoke_assert( true === $cdek_existing_pickup_result['success'], 'CDEK admin pickup save must reuse existing selected pickup code when manager keeps the same pickup point.' );
recalc_smoke_assert( 'MSK575' === (string) ( $cdek_existing_pickup_order->meta['_wdc_pickup_point_code'] ?? '' ) && 'MSK575' === (string) ( $cdek_existing_calc['pickup']['point_code'] ?? '' ) && 'MSK575' === (string) ( $cdek_existing_calc['pickup']['delivery_point'] ?? '' ), 'CDEK admin pickup save must keep existing CDEK code in canonical pickup meta and calculation data.' );
recalc_smoke_assert( '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1' === (string) ( $cdek_existing_calc['pickup']['point_address'] ?? '' ), 'CDEK admin pickup save must keep existing pickup address together with the fallback code.' );

$cdek_new_pickup_order = new WdcRecalcOrder( 122, array() );
$cdek_new_pickup_order->shipping_items = array();
$cdek_new_pickup_order->meta = $cdek_existing_pickup_order->meta;
$cdek_new_pickup_result = $replacement->save(
	$cdek_new_pickup_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_pickup_rate,
		'selected_tariff' => array(),
		'selected_pickup_point' => array_merge(
			$cdek_pickup_point,
			array(
				'point_code' => 'MSK999',
				'cdek_code' => 'MSK999',
				'delivery_point' => 'MSK999',
				'point_address' => 'Москва, новый ПВЗ',
				'address' => 'Москва, новый ПВЗ',
				'point_postcode' => '101000',
				'postcode' => '101000',
			)
		),
		'normalized_shipping_address' => array(),
	)
);
$cdek_new_calc = is_array( $cdek_new_pickup_order->meta['_wdc_delivery_calculation_data'] ?? null ) ? $cdek_new_pickup_order->meta['_wdc_delivery_calculation_data'] : array();
recalc_smoke_assert( true === $cdek_new_pickup_result['success'] && 'MSK999' === (string) ( $cdek_new_calc['pickup']['point_code'] ?? '' ), 'CDEK admin pickup save must prefer newly selected pickup code over existing saved code.' );

$cdek_address_only_order = new WdcRecalcOrder( 123, array() );
$cdek_address_only_order->shipping_items = array();
$cdek_address_only_result = $replacement->save(
	$cdek_address_only_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_pickup_rate,
		'selected_tariff' => array(),
		'selected_pickup_point' => array(
			'carrier_key' => 'cdek',
			'point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
			'address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
			'point_postcode' => '101000',
			'postcode' => '101000',
		),
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( false === $cdek_address_only_result['success'] && '' === (string) ( $cdek_address_only_order->meta['_wdc_pickup_point_code'] ?? '' ), 'CDEK admin pickup save must reject address-only pickup data without a CDEK point code.' );

$cdek_postcode_code_order = new WdcRecalcOrder( 124, array() );
$cdek_postcode_code_order->shipping_items = array();
$cdek_postcode_code_result = $replacement->save(
	$cdek_postcode_code_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_pickup_rate,
		'selected_tariff' => array(),
		'selected_pickup_point' => array(
			'carrier_key' => 'cdek',
			'point_code' => '101000',
			'cdek_code' => '101000',
			'point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
			'point_postcode' => '101000',
			'postcode' => '101000',
		),
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( false === $cdek_postcode_code_result['success'] && '' === (string) ( $cdek_postcode_code_order->meta['_wdc_pickup_point_code'] ?? '' ), 'CDEK admin pickup save must not use postcode as delivery_point.' );

$rp_postcode_pickup_order = new WdcRecalcOrder( 125, array() );
$rp_postcode_pickup_order->shipping_items = array();
$rp_postcode_pickup_result = $replacement->save(
	$rp_postcode_pickup_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $pickup_rate,
		'selected_tariff' => $pickup_rate['selected_tariff'],
		'selected_pickup_point' => array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'point_address' => 'Москва, ул. Тверская, 1',
			'point_postcode' => '101000',
			'postcode' => '101000',
		),
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( true === $rp_postcode_pickup_result['success'] && '101000' === (string) ( $rp_postcode_pickup_order->meta['_wdc_pickup_point_code'] ?? '' ), 'Russian Post admin pickup save must still allow postcode as OPS code.' );

$cdek_no_days_rate = $cdek_admin_rate;
$cdek_no_days_rate['rate_id'] = 'cdek:courier:no-days';
$cdek_no_days_rate['id'] = 'cdek:courier:no-days';
$cdek_no_days_rate['delivery_comment'] = '';
$cdek_no_days_rate['planned_delivery_date'] = '';
$cdek_no_days_rate['planned_delivery_comment'] = '';
$cdek_no_days_order = new WdcRecalcOrder( 119, array() );
$cdek_no_days_order->shipping_items = array();
$cdek_no_days_result = $replacement->save(
	$cdek_no_days_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $cdek_no_days_rate,
		'selected_tariff' => array(),
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $cdek_no_days_result['success'], 'CDEK admin save without delivery days must succeed.' );
recalc_smoke_assert( 'СДЭК дверь тест, Посылка склад-дверь' === (string) ( $cdek_no_days_order->shipping_items['method_title'] ?? '' ), 'CDEK admin save without delivery days must include method and tariff only.' );
recalc_smoke_assert( array() === ( $cdek_no_days_order->shipping_items['meta'] ?? array() ), 'CDEK admin save without planned date must omit visible meta.' );

$replace_order = new WdcRecalcOrder( 107, array() );
$replace_result = $replacement->save(
	$replace_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $pickup_rate,
		'selected_tariff' => $pickup_rate['selected_tariff'],
		'selected_pickup_point' => $pickup_point,
		'normalized_shipping_address' => array(),
	)
);
recalc_smoke_assert( true === $replace_result['success'] && 400.0 === (float) ( $replace_order->shipping_items['total'] ?? 0 ), 'Save must replace the single shipping item.' );
recalc_smoke_assert( 'Москва, ул. Тверская, 1' === $replace_order->get_shipping_address_1() && '' === $replace_order->get_shipping_address_2(), 'Save pickup must write pickup point address to WooCommerce shipping address.' );
recalc_smoke_assert( '101000' === $replace_order->get_shipping_postcode() && 'RU' === $replace_order->get_shipping_country(), 'Save pickup must write pickup postcode and country to shipping address.' );
recalc_smoke_assert( '101000-OPS' === ( $replace_order->meta['_wdc_pickup_point_code'] ?? '' ), 'Save pickup must write pickup meta.' );
recalc_smoke_assert( str_contains( (string) ( $replace_order->meta['_wdc_pickup_point_snapshot'] ?? '' ), '101000-OPS' ), 'Save pickup must write pickup raw snapshot.' );
recalc_smoke_assert( ! str_contains( (string) ( $replace_order->notes[0]['note'] ?? '' ), 'Тверская, 1' ), 'Order note must not include pickup point address.' );
recalc_smoke_assert( str_contains( (string) ( $replace_order->notes[0]['note'] ?? '' ), 'Прежний город:' ) && str_contains( (string) ( $replace_order->notes[0]['note'] ?? '' ), 'Новый город:' ), 'Save with changed location must include old/new city in note.' );
$saved_calc = $replace_order->meta['_wdc_delivery_calculation_data'] ?? array();
recalc_smoke_assert( is_array( $saved_calc ) && 1000 === ( $saved_calc['package']['products_weight_g'] ?? null ) && 200 === ( $saved_calc['package']['packaging_weight_g'] ?? null ) && 1200 === ( $saved_calc['package']['final_weight_g'] ?? null ), 'Saved calculation data must preserve checkout-compatible package products/packaging/final weight.' );
recalc_smoke_assert( 350.0 === (float) ( $saved_calc['api']['api_base_price_rub'] ?? 0 ) && 400.0 === (float) ( $saved_calc['result']['final_price_rub'] ?? 0 ), 'Saved calculation data must keep API base price separate from final price.' );
recalc_smoke_assert( '3 дня' === (string) ( $saved_calc['api']['api_delivery_text'] ?? '' ) && 3 === ( $saved_calc['api']['api_delivery_min_days'] ?? null ), 'Saved calculation data must preserve checkout-compatible API delivery days.' );
recalc_smoke_assert( array( 'base' ) === ( $saved_calc['rules']['applied_rules'] ?? null ) && array( 'API + 50 руб.' ) === ( $saved_calc['rules']['formula_visualization'] ?? null ), 'Saved calculation data must preserve applied rules and formula visualization.' );
recalc_smoke_assert( str_contains( (string) ( $replace_order->shipping_items['method_title'] ?? '' ), ' - 3 дня' ), 'Saved shipping method title must include delivery text.' );
ob_start();
$metabox->render( $replace_order );
$replace_metabox_html = (string) ob_get_clean();
foreach ( array( 'Вес товаров', 'Вес упаковки', 'Итоговый вес для API', 'Базовая стоимость API', 'Срок по API', 'Правила расчета' ) as $expected_row ) {
	recalc_smoke_assert( str_contains( $replace_metabox_html, $expected_row ), 'Metabox after admin save must render checkout-compatible row: ' . $expected_row );
}
recalc_smoke_assert( ! str_contains( $replace_metabox_html, 'Страна назначения' ), 'Metabox after RU domestic admin save must not render destination country.' );

$international_order = new WdcRecalcOrder( 113, array() );
$international_order->meta['_wdc_delivery_calculation_data'] = array(
	'service_title' => 'International',
	'selected_tariff_title' => 'INT',
	'delivery_type' => 'courier',
	'destination' => array( 'country_code' => 'KZ', 'country_name' => 'Казахстан' ),
	'package' => array( 'products_weight_g' => 1000, 'packaging_weight_g' => 0, 'final_weight_g' => 1000 ),
	'api' => array( 'api_base_price_rub' => 1000 ),
	'rules' => array(),
	'result' => array( 'final_price_rub' => 1000 ),
);
ob_start();
$metabox->render( $international_order );
$international_metabox_html = (string) ob_get_clean();
recalc_smoke_assert( str_contains( $international_metabox_html, 'Страна назначения' ) && str_contains( $international_metabox_html, 'KZ' ), 'Metabox must still render destination country for non-RU calculation data.' );

$object_shipping_order = new WdcRecalcOrder( 111, array() );
$object_shipping_order->shipping_items = array( new WdcRecalcShippingItem( 'Почта России до отделения', 318.42 ) );
$object_shipping_result = $replacement->save(
	$object_shipping_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $courier_rate,
		'selected_tariff' => $courier_rate['selected_tariff'],
		'normalized_shipping_address' => $normalized_address,
	)
);
$object_shipping_note = (string) ( $object_shipping_order->notes[0]['note'] ?? '' );
recalc_smoke_assert( true === $object_shipping_result['success'], 'Save must support object shipping item replacement.' );
recalc_smoke_assert( str_contains( $object_shipping_note, 'Было: Почта России до отделения - 318.42 руб.' ), 'Order note must read old shipping cost from object shipping item get_total().' );
recalc_smoke_assert( ! str_contains( $object_shipping_note, 'Было: Почта России до отделения - 0 руб.' ), 'Order note must not use zero cost for object shipping item.' );

$same_city_order = new WdcRecalcOrder( 108, array() );
$same_city_result = $replacement->save(
	$same_city_order,
	array(
		'selected_location' => array( 'display_name' => 'Новосибирск', 'city_value' => 'Новосибирск', 'region_name' => 'Новосибирская область', 'postal_code' => '630099', 'country_code' => 'RU' ),
		'selected_rate' => $courier_rate,
		'selected_tariff' => $courier_rate['selected_tariff'],
		'normalized_shipping_address' => array_merge( $normalized_address, array( 'city' => 'Новосибирск', 'region' => 'Новосибирская область', 'postcode' => '630099' ) ),
	)
);
recalc_smoke_assert( true === $same_city_result['success'] && ! str_contains( (string) ( $same_city_order->notes[0]['note'] ?? '' ), 'Прежний город:' ), 'Save without city change must add short note.' );

$multi_shipping_order = new WdcRecalcOrder( 109, array() );
$multi_shipping_order->shipping_items = array(
	array( 'method_title' => 'One', 'total' => 100.0 ),
	array( 'method_title' => 'Two', 'total' => 200.0 ),
);
$multi_before = $multi_shipping_order->shipping_items;
$multi_result = $replacement->save( $multi_shipping_order, array( 'selected_location' => $selected_location, 'selected_rate' => $courier_rate, 'selected_tariff' => $courier_rate['selected_tariff'] ) );
recalc_smoke_assert( false === $multi_result['success'] && $multi_before === $multi_shipping_order->shipping_items, 'Save must block orders with multiple shipping items without mutation.' );

$registered_order = new WdcRecalcOrder( 110, array() );
$registered_order->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'universal_status_code' => 'CREATED' ) );
$registered_result = $replacement->save( $registered_order, array( 'selected_location' => $selected_location, 'selected_rate' => $courier_rate, 'selected_tariff' => $courier_rate['selected_tariff'] ) );
recalc_smoke_assert( false === $registered_result['success'], 'Save must block registered shipment markers.' );

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'address_line' => 'Тверская, 10, 5' );
try {
	$controller->ajax_normalize_address();
	recalc_smoke_assert( false, 'Address normalization endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success && ! empty( $response->data['address']['normalized'] ), 'Address normalization endpoint must return normalized payload.' );
	recalc_smoke_assert( 'dadata' === ( $response->data['address']['source'] ?? '' ) && ! str_contains( (string) ( $response->data['message'] ?? '' ), 'Внешний нормализатор не настроен' ), 'Address normalization endpoint must use configured DaData suggestion client instead of bare fallback normalizer.' );
}

$nsk_location = array(
	'display_name' => 'Новосибирская область, г Новосибирск',
	'region_name' => 'Новосибирская область',
	'city_value' => 'г Новосибирск',
	'postal_code' => '630005',
	'country_code' => 'RU',
	'fias_id' => 'nsk-fias',
);
$requests_before_apartment = count( $address_client->requests );
$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $nsk_location ), 'address_line' => 'Новосибирск, некрасова, д 63/1, кв 10' );
try {
	$controller->ajax_normalize_address();
	recalc_smoke_assert( false, 'Address normalization endpoint must send JSON response for apartment address.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$address = $response->data['address'] ?? array();
	recalc_smoke_assert( $response->success && ! empty( $address['normalized'] ) && empty( $address['fallback'] ), 'Address normalization with apartment must return normalized non-fallback payload.' );
	recalc_smoke_assert( str_contains( (string) ( $address['address_1'] ?? '' ), 'Некрасова' ) && str_contains( (string) ( $address['address_1'] ?? '' ), '63/1' ) && str_contains( (string) ( $address['address_1'] ?? '' ), '10' ), 'Apartment address normalization must keep street, house and flat in address_1.' );
	recalc_smoke_assert( '' === (string) ( $address['address_2'] ?? '' ), 'Apartment address normalization must not put flat into address_2.' );
	recalc_smoke_assert( 'Новосибирск, некрасова, д 63/1' === (string) ( $address_client->requests[ $requests_before_apartment ]['query'] ?? '' ), 'Admin apartment address normalization must send DaData query without flat suffix.' );
	recalc_smoke_assert( ! isset( $response->data['debug'] ), 'Address normalization endpoint must not expose temporary debug payload.' );
}

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $nsk_location ), 'address_line' => 'Новосибирск варианты' );
try {
	$controller->ajax_normalize_address();
	recalc_smoke_assert( false, 'Address normalization endpoint must send JSON response for multiple suggestions.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success && ! empty( $response->data['requires_selection'] ), 'Multiple DaData suggestions response must require manager selection.' );
	recalc_smoke_assert( count( $response->data['suggestions'] ?? array() ) >= 2 && str_contains( (string) ( $response->data['message'] ?? '' ), 'Выберите' ), 'Multiple suggestions response must include suggestion choices and selection message.' );
	$first = $response->data['suggestions'][0]['address'] ?? array();
	recalc_smoke_assert( str_contains( (string) ( $first['address_1'] ?? '' ), 'кв 10' ) && '' === (string) ( $first['address_2'] ?? '' ), 'Multiple suggestion payload must keep flat in address_1 and leave address_2 empty.' );
	recalc_smoke_assert( ! isset( $response->data['debug'] ), 'Multiple suggestions response must not expose temporary debug payload.' );
}

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $nsk_location ), 'stage' => 'address', 'query' => 'Новосибирск Некрасова' );
try {
	$controller->ajax_address_suggest();
	recalc_smoke_assert( false, 'Admin address suggest endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$items = $response->data['items'] ?? array();
	recalc_smoke_assert( $response->success && count( $items ) >= 1, 'Admin courier must return shared address suggestions.' );
	recalc_smoke_assert( isset( $items[0]['level'], $items[0]['address'] ) && 'dadata' === (string) ( $items[0]['address']['source'] ?? '' ), 'Admin courier suggestions must include normalized save payloads from shared suggestion stack.' );
	recalc_smoke_assert( str_contains( (string) ( $items[0]['address']['address_1'] ?? '' ), 'Некрасова' ) && '' === (string) ( $items[0]['address']['address_2'] ?? '' ), 'Admin courier suggestion payload must keep lower address in address_1 and leave address_2 empty.' );
	recalc_smoke_assert( ! isset( $response->data['debug'] ), 'Admin address suggest endpoint must not expose temporary debug payload.' );
	recalc_smoke_assert( 'nsk-fias' === (string) ( $items[0]['address']['city_fias_id'] ?? '' ), 'Admin courier suggestion payload must include city FIAS for location mismatch warning.' );
}

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $nsk_location ), 'stage' => 'address_next', 'query' => 'Новосибирская область, г Новосибирск, ул Некрасова, д 63/1, 10', 'context' => wp_json_encode( array( 'selected_level' => 'house', 'desired_level' => 'flat', 'house_fias_id' => 'fake-nsk-house-fias' ) ) );
try {
	$controller->ajax_address_suggest();
	recalc_smoke_assert( false, 'Admin address_next endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$items = $response->data['items'] ?? array();
	recalc_smoke_assert( $response->success && count( $items ) >= 1 && in_array( 'flat', array_column( $items, 'level' ), true ), 'Admin courier house selection must trigger shared address_next flat suggestions.' );
}

$admin_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/order-delivery-recalculation.js' );
recalc_smoke_assert( str_contains( $admin_js, 'addressSuggestAction' ) && str_contains( $admin_js, 'wdc_order_delivery_recalculate_address_suggest' ), 'Admin courier must call dedicated thin address suggest endpoint.' );
recalc_smoke_assert( str_contains( $admin_js, 'requestCourierLowerLevelAfterHouse' ) && str_contains( $admin_js, "'address_next'" ) && str_contains( $admin_js, 'lowerLevelCourierItems' ), 'Admin courier must support house to flat suggestion flow.' );
recalc_smoke_assert( str_contains( $admin_js, 'data-wdc-courier-address-house-finalize' ) && str_contains( $admin_js, 'houseLevelCourierItem' ), 'Admin courier must support normalized house-level finalize link.' );
recalc_smoke_assert( ! str_contains( $admin_js, 'Проверить адрес' ) && ! str_contains( $admin_js, 'data-wdc-normalize-courier-address' ) && ! str_contains( $admin_js, 'normalizeCourierAddress' ), 'Admin courier suggestion flow must not render or handle the old check-address button.' );
recalc_smoke_assert( str_contains( $admin_js, 'Использовать этот адрес' ), 'Admin courier manual fallback button must remain available.' );
recalc_smoke_assert( ! str_contains( $admin_js, 'console.debug' ), 'Admin courier suggestion flow must not output temporary debug logs.' );
recalc_smoke_assert( str_contains( $admin_js, 'courierLocationWarning' ) && str_contains( $admin_js, 'населенный пункт в адресе доставки отличается' ) && str_contains( $admin_js, 'Не удалось подтвердить' ), 'Admin courier save flow must expose non-blocking location mismatch warning logic.' );
recalc_smoke_assert( str_contains( $admin_js, 'button.disabled = ! enabled' ) && str_contains( $admin_js, 'updateCourierLocationWarning' ), 'Courier location mismatch warning must update separately from save button disabling.' );
$admin_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Admin/OrderDeliveryRecalculationAdminController.php' );
$admin_service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Application/OrderDeliveryAddressNormalizationService.php' );
recalc_smoke_assert( str_contains( $admin_controller_source, 'ajax_address_suggest' ) && str_contains( $admin_service_source, 'AddressSuggestionService' ) && str_contains( $admin_service_source, 'AddressLineParser::lower_address_line' ), 'Admin courier must reuse shared AddressSuggestionService and AddressLineParser.' );

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'address_line' => 'Омск, Ленина, 10' );
try {
	$controller->ajax_geocode_address();
	recalc_smoke_assert( false, 'Geocode endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success && 54.9914 === (float) $response->data['lat'] && 73.3645 === (float) $response->data['lng'], 'Geocode endpoint must return lat/lng from raw DaData suggestion data.' );
	recalc_smoke_assert( 55.75 !== (float) $response->data['lat'] && 37.61 !== (float) $response->data['lng'], 'Geocode endpoint must not use the first pickup point coordinates.' );
	recalc_smoke_assert( str_contains( (string) ( $response->data['formatted_address'] ?? '' ), 'Омская область' ), 'Geocode endpoint must return formatted DaData address.' );
}

$no_coordinates_controller = new OrderDeliveryRecalculationAdminController( $service, new OrderDeliveryRateRenderer(), $location_ajax, $pickup_repository, '', '1', new OrderDeliveryAddressNormalizationService( null, new WdcRecalcDadataSuggestionClient( false ) ) );
$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'address_line' => 'Омск, Ленина, 10' );
try {
	$no_coordinates_controller->ajax_geocode_address();
	recalc_smoke_assert( false, 'Geocode endpoint without coordinates must send JSON error.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 400 === $response->status && str_contains( (string) ( $response->data['message'] ?? '' ), 'координаты' ), 'Geocode endpoint must return an error when DaData suggestion has no coordinates.' );
}

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'selected_rate' => wp_json_encode( $courier_rate ), 'selected_tariff' => wp_json_encode( $courier_rate['selected_tariff'] ) );
$_POST['normalized_shipping_address'] = wp_json_encode( $normalized_address );
try {
	$controller->ajax_save();
	recalc_smoke_assert( false, 'Save endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success, 'Save endpoint must save valid courier payload.' );
}

$_POST = array( 'order_id' => 102, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'query' => '101000' );
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Pickup endpoint must send JSON response when shipment exists.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success, 'Pickup endpoint must remain available when save is blocked by shipment.' );
}

$GLOBALS['wdc_recalc_current_can'] = false;
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must reject missing capability.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Endpoint must require manage_woocommerce.' );
}
$GLOBALS['wdc_recalc_current_can'] = false;
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Pickup endpoint must reject missing capability.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Pickup endpoint must require manage_woocommerce.' );
}
try {
	$controller->ajax_normalize_address();
	recalc_smoke_assert( false, 'Address normalization endpoint must reject missing capability.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Address normalization endpoint must require manage_woocommerce.' );
}
try {
	$controller->ajax_geocode_address();
	recalc_smoke_assert( false, 'Geocode endpoint must reject missing capability.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Geocode endpoint must require manage_woocommerce.' );
}
$GLOBALS['wdc_recalc_current_can'] = true;
$GLOBALS['wdc_recalc_nonce_ok'] = false;
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must reject bad nonce.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Endpoint must require nonce.' );
}
try {
	$controller->ajax_normalize_address();
	recalc_smoke_assert( false, 'Address normalization endpoint must reject bad nonce.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Address normalization endpoint must require nonce.' );
}
try {
	$controller->ajax_geocode_address();
	recalc_smoke_assert( false, 'Geocode endpoint must reject bad nonce.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Geocode endpoint must require nonce.' );
}

$yandex_order = new WdcRecalcOrder( 126, array() );
$yandex_rate = array(
	'id' => 'yandex_pickup',
	'rate_id' => 'yandex_pickup',
	'carrier_key' => 'yandex_delivery',
	'service_key' => 'yandex_delivery',
	'service_title' => 'Яндекс.Доставка',
	'label' => 'Яндекс до ПВЗ',
	'delivery_type' => DeliveryType::PICKUP,
	'cost' => 500,
	'delivery_comment' => '2 дня',
);
$missing_yandex_station = $replacement->save(
	$yandex_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_rate,
		'selected_pickup_point' => array( 'carrier_key' => 'yandex_delivery', 'pickup_family' => 'yandex_delivery:pickup' ),
	)
);
recalc_smoke_assert( false === $missing_yandex_station['success'], 'Yandex pickup save must reject a point without a station id.' );
$saved_yandex_station = $replacement->save(
	$yandex_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_rate,
		'selected_pickup_point' => array(
			'carrier_key' => 'yandex_delivery',
			'pickup_family' => 'yandex_delivery:pickup',
			'platform_station_id' => 'YANDEX-ORDER-1',
			'point_code' => 'forged-code',
			'point_address' => 'Новосибирск, Ленина, 1',
		),
	)
);
recalc_smoke_assert( true === $saved_yandex_station['success'] && 'YANDEX-ORDER-1' === (string) ( $yandex_order->meta['_wdc_pickup_point_code'] ?? '' ) && 'YANDEX-ORDER-1' === (string) ( $yandex_order->meta['_wdc_yandex_delivery_pickup_platform_station_id'] ?? '' ), 'Yandex pickup save must canonicalize point_code to platform_station_id and persist the checkout-compatible alias.' );
$saved_yandex_courier = $replacement->save(
	$yandex_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => array_merge( $yandex_rate, array( 'id' => 'yandex_courier', 'rate_id' => 'yandex_courier', 'delivery_type' => DeliveryType::COURIER, 'label' => 'Яндекс до двери' ) ),
		'normalized_shipping_address' => array( 'address_1' => 'Ленина, 2', 'normalized' => true, 'country' => 'RU' ),
	)
);
recalc_smoke_assert( true === $saved_yandex_courier['success'] && '' === (string) ( $yandex_order->meta['_wdc_yandex_delivery_pickup_platform_station_id'] ?? '' ), 'Yandex courier save must clear Yandex pickup meta.' );

$yandex_days_audit = array(
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
$yandex_admin_rate = array(
	'id' => 'yandex_courier',
	'rate_id' => 'yandex_courier',
	'carrier_key' => 'yandex_delivery',
	'service_key' => 'yandex_delivery',
	'service_title' => 'Яндекс.Доставка',
	'label' => 'Яндекс до двери - 8 дней',
	'delivery_type' => DeliveryType::COURIER,
	'cost' => 662.0,
	'api_base_price_rub' => 535.0,
	'pricing_total_kopecks' => 53500,
	'delivery_days' => array( 'min_days' => 10, 'max_days' => 10 ),
	'original_delivery_days' => array( 'min_days' => 8, 'max_days' => 8 ),
	'delivery_comment' => '10 дней',
	'rules_source' => 'rule_engine',
	'rules_audit' => $yandex_days_audit,
	'rate_meta' => array(
		'api_base_price_rub' => 535.0,
		'pricing_total_kopecks' => 53500,
		'delivery_min_days' => 8,
		'delivery_max_days' => 8,
		'api_delivery_days' => 8,
		'original_delivery_days' => array( 'min_days' => 8, 'max_days' => 8 ),
		'rules_source' => 'rule_engine',
		'rules_audit' => $yandex_days_audit,
	),
);
$yandex_admin_order = new WdcRecalcOrder( 128, array() );
$yandex_admin_order->shipping_items = array();
$yandex_admin_result = $replacement->save(
	$yandex_admin_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_admin_rate,
		'normalized_shipping_address' => $normalized_address,
	)
);
$yandex_admin_calc = $yandex_admin_order->meta['_wdc_delivery_calculation_data'] ?? array();
$yandex_admin_formula = $yandex_admin_calc['rules']['formula_visualization'] ?? array();
$yandex_admin_title = (string) ( $yandex_admin_order->shipping_items['method_title'] ?? '' );
recalc_smoke_assert( true === $yandex_admin_result['success'], 'Yandex admin courier save must succeed for 535 -> 662 regression rate.' );
recalc_smoke_assert( 535.0 === (float) ( $yandex_admin_calc['api']['api_base_price_rub'] ?? 0 ) && 662.0 === (float) ( $yandex_admin_calc['result']['final_price_rub'] ?? 0 ), 'Yandex admin persistence must keep API base 535 separate from final 662.' );
recalc_smoke_assert( 'Яндекс до двери - 10 дней' === $yandex_admin_title && ! str_contains( $yandex_admin_title, '8 дней' ) && 1 === substr_count( $yandex_admin_title, '10 дней' ) && ! str_contains( $yandex_admin_title, 'Array' ) && ! str_contains( $yandex_admin_title, '8 дней - 10 дней' ), 'Yandex admin method title must replace original 8 days with final 10 days without duplication.' );
recalc_smoke_assert( is_array( $yandex_admin_formula ) && 'Базовая цена API: 535 руб.' === ( $yandex_admin_formula[0] ?? '' ) && str_contains( implode( "\n", $yandex_admin_formula ), 'Срок доставки' ) && str_contains( implode( "\n", $yandex_admin_formula ), 'увеличить срок доставки' ) && str_contains( implode( "\n", $yandex_admin_formula ), '10 дней' ) && 'Итог: 662 руб.' === end( $yandex_admin_formula ), 'Yandex admin formula must persist base price, delivery-days audit and final price.' );

$yandex_final_title_rate = $yandex_admin_rate;
$yandex_final_title_rate['label'] = 'Яндекс до двери - 10 дней';
$yandex_final_title_order = new WdcRecalcOrder( 129, array() );
$yandex_final_title_order->shipping_items = array();
$yandex_final_title_result = $replacement->save(
	$yandex_final_title_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_final_title_rate,
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $yandex_final_title_result['success'] && 'Яндекс до двери - 10 дней' === (string) ( $yandex_final_title_order->shipping_items['method_title'] ?? '' ), 'Yandex admin title already ending with final delivery time must stay unchanged.' );

$yandex_no_days_title_rate = $yandex_admin_rate;
$yandex_no_days_title_rate['label'] = 'Яндекс до двери';
$yandex_no_days_title_order = new WdcRecalcOrder( 130, array() );
$yandex_no_days_title_order->shipping_items = array();
$yandex_no_days_title_result = $replacement->save(
	$yandex_no_days_title_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_no_days_title_rate,
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $yandex_no_days_title_result['success'] && 'Яндекс до двери - 10 дней' === (string) ( $yandex_no_days_title_order->shipping_items['method_title'] ?? '' ), 'Yandex admin title without delivery time must append final delivery time once.' );

$yandex_pricing_total_rate = $yandex_admin_rate;
unset( $yandex_pricing_total_rate['api_base_price_rub'], $yandex_pricing_total_rate['rate_meta']['api_base_price_rub'] );
$yandex_pricing_total_order = new WdcRecalcOrder( 131, array() );
$yandex_pricing_total_order->shipping_items = array();
$yandex_pricing_total_result = $replacement->save(
	$yandex_pricing_total_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_pricing_total_rate,
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $yandex_pricing_total_result['success'] && 535.0 === (float) ( $yandex_pricing_total_order->meta['_wdc_delivery_calculation_data']['api']['api_base_price_rub'] ?? 0 ), 'Yandex admin persistence must use pricing_total_kopecks fallback before final cost.' );

$yandex_money_array_rate = $yandex_admin_rate;
unset( $yandex_money_array_rate['api_base_price_rub'], $yandex_money_array_rate['pricing_total_kopecks'], $yandex_money_array_rate['rate_meta']['api_base_price_rub'], $yandex_money_array_rate['rate_meta']['pricing_total_kopecks'] );
$yandex_money_array_rate['original_cost'] = Money::from_rubles( 535 )->to_array();
$yandex_money_array_order = new WdcRecalcOrder( 132, array() );
$yandex_money_array_order->shipping_items = array();
$yandex_money_array_result = $replacement->save(
	$yandex_money_array_order,
	array(
		'selected_location' => $selected_location,
		'selected_rate' => $yandex_money_array_rate,
		'normalized_shipping_address' => $normalized_address,
	)
);
recalc_smoke_assert( true === $yandex_money_array_result['success'] && 535.0 === (float) ( $yandex_money_array_order->meta['_wdc_delivery_calculation_data']['api']['api_base_price_rub'] ?? 0 ), 'Yandex admin persistence must safely read Money::to_array() original_cost as 535 rubles.' );

$yandex_db = new WdcRecalcLocationDb();
$yandex_db->yandex_location_mapping_v2 = array(
	array( 'location_id' => 501, 'yandex_geo_id' => 77, 'status' => 'mapped' ),
	array( 'location_id' => 92468, 'yandex_geo_id' => 88, 'status' => 'mapped' ),
);
$yandex_db->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'YANDEX-ADDRESS', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Пункт выдачи заказов Яндекс Маркета', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Ленина, 10', 'yandex_geo_id' => 77, 'active' => 1 ),
	array( 'platform_station_id' => 'YANDEX-TITLE', 'operator_id' => '5post', 'type' => 'terminal', 'name' => '5Post', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Советская, 2', 'yandex_geo_id' => 77, 'active' => 1 ),
	array( 'platform_station_id' => 'TECHNICAL-ID', 'operator_id' => '5post', 'type' => 'pickup_point', 'name' => '5Post', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Красный проспект, 1', 'yandex_geo_id' => 88, 'active' => 1 ),
	array( 'platform_station_id' => 'YANDEX-TERMINAL', 'operator_id' => 'market_l4g', 'type' => 'terminal', 'name' => 'Постамат', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Гоголя, 7', 'yandex_geo_id' => 88, 'active' => 1 ),
	array( 'platform_station_id' => 'YANDEX-PARTNER', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Пункт выдачи заказов партнёра', 'locality' => 'Новосибирск', 'full_address' => 'Новосибирск, Фрунзе, 12', 'yandex_geo_id' => 88, 'active' => 1 ),
);
$yandex_search_controller = new OrderDeliveryRecalculationAdminController( $service, new OrderDeliveryRateRenderer(), $location_ajax, $pickup_repository, '', '1', $address_normalization, $replacement, null, null, new YandexDeliveryPickupPointV2Repository( $yandex_db ), new YandexLocationMappingV2Repository( $yandex_db ) );
$yandex_search = new ReflectionMethod( $yandex_search_controller, 'yandex_pickup_points' );
$yandex_search->setAccessible( true );
$yandex_location_points = $yandex_search->invoke( $yandex_search_controller, array( 'id' => 501 ), '', 'location' );
$yandex_address_points = $yandex_search->invoke( $yandex_search_controller, array( 'id' => 501 ), 'Ленина', 'search' );
$yandex_title_points = $yandex_search->invoke( $yandex_search_controller, array( 'id' => 501 ), '5 post', 'search' );
$yandex_station_points = $yandex_search->invoke( $yandex_search_controller, array( 'id' => 501 ), 'yandex-title', 'search' );
recalc_smoke_assert( 2 === count( $yandex_location_points ) && 1 === count( $yandex_address_points ) && 'YANDEX-ADDRESS' === (string) ( $yandex_address_points[0]['platform_station_id'] ?? '' ), 'Yandex location mode must return all local points, while address search must return only a match.' );
recalc_smoke_assert( 'Пункт выдачи Яндекс.Маркет' === (string) ( $yandex_address_points[0]['point_title'] ?? '' ), 'Yandex market pickup point must use checkout presentation title without technical code.' );
recalc_smoke_assert( 1 === count( $yandex_title_points ) && 'YANDEX-TITLE' === (string) ( $yandex_title_points[0]['platform_station_id'] ?? '' ) && 1 === count( $yandex_station_points ), 'Yandex search must match presentation title and platform_station_id case-insensitively.' );
$yandex_first_preview_points = $yandex_search->invoke( $yandex_search_controller, $first_preview['location'] ?? array(), '', 'location' );
recalc_smoke_assert( count( $yandex_first_preview_points ) >= 3, 'Yandex pickup helper must find points for the first preview resolved location payload.' );
$yandex_points_by_station = array_column( $yandex_first_preview_points, null, 'platform_station_id' );
recalc_smoke_assert( '5 Post (Пятерочка)' === (string) ( $yandex_points_by_station['TECHNICAL-ID']['point_title'] ?? '' ) && '' === (string) ( $yandex_points_by_station['TECHNICAL-ID']['display_code'] ?? 'not-empty' ) && 'Цена будет пересчитана, иногда сюда получается дороже!' === (string) ( $yandex_points_by_station['TECHNICAL-ID']['presentation_comment'] ?? '' ), 'Yandex 5Post formatter payload must expose checkout title/comment and keep display_code empty.' );
recalc_smoke_assert( 'Постамат Яндекса' === (string) ( $yandex_points_by_station['YANDEX-TERMINAL']['point_title'] ?? '' ) && str_contains( (string) ( $yandex_points_by_station['YANDEX-TERMINAL']['presentation_comment'] ?? '' ), '2-3 дня' ), 'Yandex terminal formatter payload must expose checkout terminal title and storage warning comment.' );
recalc_smoke_assert( 'Партнёрский пункт выдачи' === (string) ( $yandex_points_by_station['YANDEX-PARTNER']['point_title'] ?? '' ), 'Yandex market partner pickup payload must use checkout presentation title without technical code.' );
$GLOBALS['wdc_recalc_orders'][127] = $first_preview_order;
$yandex_fallback_controller = new OrderDeliveryRecalculationAdminController( $first_preview_service, new OrderDeliveryRateRenderer(), $location_ajax, $pickup_repository, '', '1', $address_normalization, $replacement, null, null, new YandexDeliveryPickupPointV2Repository( $yandex_db ), new YandexLocationMappingV2Repository( $yandex_db ) );
$first_preview_resolved_location = $first_preview_service->resolved_location_payload( $first_preview_order, null );
recalc_smoke_assert( 92468 === (int) ( $first_preview_resolved_location['location_id'] ?? 0 ), 'Resolved location payload helper must return location_id=92468 without running pricing.' );
$yandex_fallback_points_direct = $yandex_search->invoke( $yandex_fallback_controller, $first_preview_resolved_location, '', 'location' );
recalc_smoke_assert( count( $yandex_fallback_points_direct ) >= 3, 'Yandex fallback controller must find points for resolved location payload before ajax wrapping.' );
$_POST = array( 'order_id' => 127, 'nonce' => 'ok', 'selected_location' => wp_json_encode( array( 'label' => 'Новосибирск', 'fias_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd' ) ), 'selected_rate' => wp_json_encode( array( 'carrier_key' => 'yandex_delivery', 'service_key' => 'yandex_delivery' ) ), 'mode' => 'location', 'query' => '' );
$array_from_request = new ReflectionMethod( $yandex_fallback_controller, 'array_from_request' );
$array_from_request->setAccessible( true );
$posted_yandex_rate = $array_from_request->invoke( $yandex_fallback_controller, 'selected_rate' );
recalc_smoke_assert( 'yandex_delivery' === (string) ( $posted_yandex_rate['carrier_key'] ?? '' ), 'Yandex fallback ajax smoke must post selected_rate.carrier_key=yandex_delivery.' );
$selected_location_from_request = new ReflectionMethod( $yandex_fallback_controller, 'selected_location_from_request' );
$selected_location_from_request->setAccessible( true );
$posted_yandex_location = $selected_location_from_request->invoke( $yandex_fallback_controller );
$resolved_yandex_location_for_ajax = $first_preview_service->resolved_location_payload( $first_preview_order, is_array( $posted_yandex_location ) && array() !== $posted_yandex_location ? $posted_yandex_location : null );
if ( (int) ( $resolved_yandex_location_for_ajax['location_id'] ?? 0 ) <= 0 ) {
	$resolved_yandex_location_for_ajax = $first_preview_service->resolved_location_payload( $first_preview_order, null );
}
$merge_resolved_location_payload = new ReflectionMethod( $yandex_fallback_controller, 'merge_resolved_location_payload' );
$merge_resolved_location_payload->setAccessible( true );
$merged_yandex_location_for_ajax = $merge_resolved_location_payload->invoke( $yandex_fallback_controller, is_array( $posted_yandex_location ) ? $posted_yandex_location : array(), $resolved_yandex_location_for_ajax );
recalc_smoke_assert( 92468 === (int) ( $merged_yandex_location_for_ajax['location_id'] ?? 0 ), 'Yandex fallback ajax merge must produce location_id=92468 before pickup search.' );
recalc_smoke_assert( count( $yandex_search->invoke( $yandex_fallback_controller, $merged_yandex_location_for_ajax, '', 'location' ) ) >= 3, 'Yandex fallback ajax merged location must find destination points before endpoint call.' );
$GLOBALS['wdc_recalc_current_can'] = true;
$GLOBALS['wdc_recalc_nonce_ok'] = true;
try {
	$yandex_fallback_controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Yandex fallback pickup endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$fallback_points = $response->data['points'] ?? array();
	$fallback_station_ids = array_column( $fallback_points, 'platform_station_id' );
	recalc_smoke_assert( $response->success && count( $fallback_points ) >= 3 && in_array( 'TECHNICAL-ID', $fallback_station_ids, true ), 'Yandex pickup endpoint must resolve original order location server-side when JS payload has no numeric id. Got stations: ' . implode( ',', array_map( 'strval', $fallback_station_ids ) ) );
	recalc_smoke_assert( $first_preview_before_meta === $first_preview_order->meta, 'Yandex pickup endpoint fallback must not write resolved location_id to order meta.' );
}

echo "Order delivery recalculation smoke OK\n";
