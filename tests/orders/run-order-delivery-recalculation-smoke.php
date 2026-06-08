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
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRateRenderer;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRecalculationAdminController;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
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
function get_option( string $option, mixed $default = false ): mixed { return $default; }
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

final class WdcRecalcOrder {
	public array $meta = array();
	public array $shipping_items = array( 'method_title' => 'Old delivery', 'total' => 111.0 );
	public float $total = 5111.0;

	public function __construct( private int $id, private array $items ) {}
	public function get_id(): int { return $this->id; }
	public function get_items(): array { return $this->items; }
	public function get_item_count(): int { return 3; }
	public function get_subtotal(): float { return 5000.0; }
	public function get_payment_method(): string { return 'cod'; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_billing_country(): string { return 'RU'; }
	public function get_shipping_city(): string { return 'Новосибирск'; }
	public function get_shipping_postcode(): string { return '630099'; }
	public function get_shipping_address_1(): string { return 'Красный проспект'; }
	public function get_shipping_address_2(): string { return '1'; }
	public function get_shipping_state(): string { return 'Новосибирская область'; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
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
				'api_base_price_rub' => $price,
				'final_price_rub' => $price,
				'package' => array( 'weight_g' => 1000 ),
			)
		);
	}
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
			'latitude' => 55.75,
			'longitude' => 37.61,
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
recalc_smoke_assert( str_contains( $metabox_html, 'disabled' ) && str_contains( $metabox_html, 'Сохранение будет добавлено следующим шагом' ), 'Modal markup must contain disabled save placeholder.' );

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
recalc_smoke_assert( false === $service->preview( $blocked )['success'], 'Preview must be blocked when shipment status is created.' );
ob_start();
$metabox->render( $blocked );
$blocked_metabox_html = (string) ob_get_clean();
recalc_smoke_assert( str_contains( $blocked_metabox_html, 'Пересчет доставки недоступен: по заказу уже создано отправление.' ) && ! str_contains( $blocked_metabox_html, 'data-wdc-order-delivery-recalculate' ), 'Blocked metabox must show explanation instead of active button.' );
$tracking_blocked = new WdcRecalcOrder( 103, array() );
$tracking_blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'barcode' => 'RA123' ) );
recalc_smoke_assert( false === $service->preview( $tracking_blocked )['success'], 'Preview must be blocked when shipment has tracking number.' );
$backlog_blocked = new WdcRecalcOrder( 104, array() );
$backlog_blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'backlog_order_id' => '123' ) );
recalc_smoke_assert( false === $service->preview( $backlog_blocked )['success'], 'Preview must be blocked when shipment has backlog_order_id.' );

$pickup_repository = wdc_recalc_pickup_repository();
$controller = new OrderDeliveryRecalculationAdminController( $service, new OrderDeliveryRateRenderer(), $location_ajax, $pickup_repository );
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

$_POST = array( 'order_id' => 101, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'selected_rate' => wp_json_encode( $rates_by_id['russian_post_domestic:pickup'] ), 'query' => '101000' );
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Pickup endpoint must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	$points = $response->data['points'] ?? array();
	recalc_smoke_assert( $response->success && array() !== $points && '101000-OPS' === ( $points[0]['point_code'] ?? '' ), 'Pickup endpoint must return pickup points for selected location.' );
	recalc_smoke_assert( isset( $points[0]['point_type'], $points[0]['point_address'], $points[0]['point_postcode'], $points[0]['point_raw'] ), 'Pickup endpoint must return selectedPickupPoint payload fields.' );
}
recalc_smoke_assert( $before_shipping === $order->shipping_items, 'Pickup endpoint must not change shipping item data.' );
recalc_smoke_assert( $before_total === $order->total, 'Pickup endpoint must not change order totals.' );
recalc_smoke_assert( $before_calc === $order->meta['_wdc_delivery_calculation_data'], 'Pickup endpoint must not change delivery calculation meta.' );
recalc_smoke_assert( $before_shipping_city === $order->get_shipping_city() && $before_shipping_postcode === $order->get_shipping_postcode(), 'Pickup endpoint must not change shipping address fields.' );

$_POST = array( 'order_id' => 102, 'nonce' => 'ok', 'selected_location' => wp_json_encode( $selected_location ), 'query' => '101000' );
try {
	$controller->ajax_pickup_search();
	recalc_smoke_assert( false, 'Pickup endpoint must block orders with created shipment.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 400 === $response->status, 'Pickup endpoint must be blocked by created shipment.' );
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
$GLOBALS['wdc_recalc_current_can'] = true;
$GLOBALS['wdc_recalc_nonce_ok'] = false;
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must reject bad nonce.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Endpoint must require nonce.' );
}

echo "Order delivery recalculation smoke OK\n";
