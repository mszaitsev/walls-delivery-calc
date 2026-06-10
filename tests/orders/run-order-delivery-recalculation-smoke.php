<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Core\Autoloader;
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

final class WdcRecalcDadataSuggestionClient implements AddressSuggestionClientInterface {
	public function __construct( private bool $with_coordinates = true ) {}

	public function suggest( string $stage, string $query, array $context = array() ): array {
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

function wdc_recalc_service(): OrderDeliveryRecalculationService {
	$registry = new CarrierRegistry();
	$registry->register( new WdcRecalcCarrier( RussianPostDomesticSettings::CARRIER_KEY ) );
	$registry->register( new WdcRecalcCarrier( 'demo' ) );
	$logger = new CheckoutLogger();
	$orchestrator = new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger
	);

	return new OrderDeliveryRecalculationService( new OrderQuoteRequestMapper(), $orchestrator, new OrderShipmentRepository() );
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
		array(
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
		),
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

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'selected_rate' => wp_json_encode( $rates_by_id['russian_post_domestic:pickup'] ), 'mode' => 'location', 'query' => '', 'limit' => 300 );
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Initial pickup endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$points = $response->data['points'] ?? array();
	$point_codes = array_column( $points, 'point_code' );
	recalc_smoke_assert( $response->success && in_array( '101000-OPS', $point_codes, true ) && in_array( '125009-OPS', $point_codes, true ) && ! in_array( '190000-OPS', $point_codes, true ), 'Initial pickup endpoint must return all pickup points for selected settlement, not one postcode.' );
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
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'prefillCurrentPickupIfAvailable' ) && str_contains( $pickup_js, 'data-wdc-order-delivery-current-pickup' ), 'JS must prefill current pickup when location is unchanged.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'scrollActivePickupRow' ) && str_contains( $pickup_js, 'scrollIntoView' ) && str_contains( $pickup_js, 'setActivePoint' ), 'JS marker click must sync active marker and list row.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'geocodeAddressAction' ) && str_contains( $pickup_js, 'wdc_order_delivery_recalculate_geocode_address' ) && str_contains( $pickup_js, 'searchMarker:' ), 'JS manual address search must geocode through admin endpoint and pass a temporary search marker.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'loadPickupPointsForLocation' ) && str_contains( $pickup_js, "form.append( 'mode', 'location' );" ) && ! str_contains( $pickup_js, "form.append( 'mode', mode );" ), 'JS manual address search must keep pickup loading in location mode instead of filtering pickup points by address query.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'renderSearchResults( \'address\', value' ) && str_contains( $pickup_js, 'provider.setCenter( searchMarker.lat, searchMarker.lng, 15 );' ), 'JS manual address search must keep city pickup points rendered and center the map on the DaData marker.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'searchMarkerFromQuery' ), 'JS manual address search must not use the first pickup point as an address marker fallback.' );
recalc_smoke_assert( is_string( $pickup_js ) && ! str_contains( $pickup_js, 'data-wdc-pickup-address-block' ), 'Pickup UI must not render address normalization block.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, 'data-wdc-courier-address-block' ) && str_contains( $pickup_js, 'data-wdc-courier-address-suggestions' ) && ! str_contains( $pickup_js, 'data-wdc-normalize-courier-address' ), 'Courier UI source must render automatic suggestions without old check-address button.' );
recalc_smoke_assert( is_string( $pickup_js ) && str_contains( $pickup_js, "requestPreview( box, box.querySelector( '[data-wdc-order-delivery-modal-preview]' ) );" ), 'Location selection must trigger preview automatically.' );
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
recalc_smoke_assert( is_string( $pickup_css ) && str_contains( $pickup_css, 'overflow: hidden;' ) && str_contains( $pickup_css, '.wdc-order-delivery-pickup-picker__list' ) && str_contains( $pickup_css, 'overflow: auto;' ), 'Pickup picker CSS must keep dialog in viewport and scroll the list separately.' );
recalc_smoke_assert( $before_shipping === $order->shipping_items, 'Pickup endpoint must not change shipping item data.' );
recalc_smoke_assert( $before_total === $order->total, 'Pickup endpoint must not change order totals.' );
recalc_smoke_assert( $before_calc === $order->meta['_wdc_delivery_calculation_data'], 'Pickup endpoint must not change delivery calculation meta.' );
recalc_smoke_assert( $before_shipping_city === $order->get_shipping_city() && $before_shipping_postcode === $order->get_shipping_postcode(), 'Pickup endpoint must not change shipping address fields.' );

$replacement = new OrderDeliveryReplacementService( new OrderShipmentRepository() );
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

$cdek_admin_rate = array(
	'id' => 'cdek:courier:137',
	'rate_id' => 'cdek:courier:137',
	'carrier_key' => 'cdek',
	'service_key' => 'cdek',
	'service_title' => 'СДЭК',
	'label' => 'СДЭК курьер, Посылка склад-дверь - 10-14 дней',
	'delivery_type' => 'courier',
	'delivery_comment' => '10-14 дней',
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
recalc_smoke_assert( 'СДЭК курьер, Посылка склад-дверь - 10-14 дней' === (string) ( $cdek_admin_order->shipping_items['method_title'] ?? '' ), 'CDEK admin save must keep method title with tariff and delivery text.' );
recalc_smoke_assert( array( 'Срок доставки' => '10-14 дней' ) === ( $cdek_admin_order->shipping_items['meta'] ?? array() ), 'CDEK admin replacement visible meta must contain only delivery time.' );
foreach ( array( 'carrier_key', 'rate_id', 'delivery_type', 'service_key', 'api_base_price_rub', 'tariff_key', 'selected_tariff_object', 'Перевозчик', 'Способ доставки', 'Тип доставки', 'Населенный пункт', 'Нормализация' ) as $forbidden_meta_key ) {
	recalc_smoke_assert( ! array_key_exists( $forbidden_meta_key, $cdek_admin_order->shipping_items['meta'] ?? array() ), 'CDEK admin replacement visible meta must not contain technical key: ' . $forbidden_meta_key );
}
$cdek_admin_calc = $cdek_admin_order->meta['_wdc_delivery_calculation_data'] ?? array();
recalc_smoke_assert( isset( $cdek_admin_order->meta['_wdc_platform_rate_meta'] ), 'CDEK admin save must keep hidden platform rate meta.' );
recalc_smoke_assert( 520.0 === (float) ( $cdek_admin_calc['api']['api_base_price_rub'] ?? 0 ) && 650.0 === (float) ( $cdek_admin_calc['result']['final_price_rub'] ?? 0 ), 'CDEK admin calculation data must preserve API base and final prices.' );
recalc_smoke_assert( 900 === (int) ( $cdek_admin_calc['package']['products_weight_g'] ?? 0 ) && 300 === (int) ( $cdek_admin_calc['package']['packaging_weight_g'] ?? 0 ) && 1200 === (int) ( $cdek_admin_calc['package']['final_weight_g'] ?? 0 ), 'CDEK admin calculation data must preserve package weights.' );
recalc_smoke_assert( array( 'cdek-rule' ) === ( $cdek_admin_calc['rules']['applied_rules'] ?? null ), 'CDEK admin calculation data must preserve rules data.' );

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
$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $nsk_location ), 'address_line' => 'Новосибирск, некрасова, д 63/1, кв 10' );
try {
	$controller->ajax_normalize_address();
	recalc_smoke_assert( false, 'Address normalization endpoint must send JSON response for apartment address.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$address = $response->data['address'] ?? array();
	recalc_smoke_assert( $response->success && ! empty( $address['normalized'] ) && empty( $address['fallback'] ), 'Address normalization with apartment must return normalized non-fallback payload.' );
	recalc_smoke_assert( str_contains( (string) ( $address['address_1'] ?? '' ), 'Некрасова' ) && str_contains( (string) ( $address['address_1'] ?? '' ), '63/1' ) && str_contains( (string) ( $address['address_1'] ?? '' ), '10' ), 'Apartment address normalization must keep street, house and flat in address_1.' );
	recalc_smoke_assert( '' === (string) ( $address['address_2'] ?? '' ), 'Apartment address normalization must not put flat into address_2.' );
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

echo "Order delivery recalculation smoke OK\n";
