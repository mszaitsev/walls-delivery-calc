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
use WallsShop\WDC\Orders\Admin\OrderDeliveryRateRenderer;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRecalculationAdminController;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

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
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_date( string $format ): string { return gmdate( $format ); }

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

$service = wdc_recalc_service();
$before_shipping = $order->shipping_items;
$before_total = $order->total;
$before_calc = $order->meta['_wdc_delivery_calculation_data'];
$preview = $service->preview( $order );

recalc_smoke_assert( true === $preview['success'], 'Preview must succeed for an order without shipment.' );
recalc_smoke_assert( 'RU' === ( $preview['request']['country_code'] ?? '' ), 'Preview must build QuoteRequest country from order.' );
recalc_smoke_assert( 1000 === ( $preview['request']['package']['total_weight_g'] ?? 0 ), 'Preview must build QuoteRequest package from order items.' );
recalc_smoke_assert( '630099' === ( $preview['request']['destination']['postcode'] ?? '' ), 'Preview must build destination postcode from order.' );

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

recalc_smoke_assert( $before_shipping === $order->shipping_items, 'Preview must not change shipping item data.' );
recalc_smoke_assert( $before_total === $order->total, 'Preview must not change order totals.' );
recalc_smoke_assert( $before_calc === $order->meta['_wdc_delivery_calculation_data'], 'Preview must not change delivery calculation meta.' );

$blocked = new WdcRecalcOrder( 102, array() );
$blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'status' => 'created' ) );
recalc_smoke_assert( false === $service->preview( $blocked )['success'], 'Preview must be blocked when shipment status is created.' );
$tracking_blocked = new WdcRecalcOrder( 103, array() );
$tracking_blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'barcode' => 'RA123' ) );
recalc_smoke_assert( false === $service->preview( $tracking_blocked )['success'], 'Preview must be blocked when shipment has tracking number.' );
$backlog_blocked = new WdcRecalcOrder( 104, array() );
$backlog_blocked->meta['_wdc_shipments'] = array( 'russian_post_domestic' => array( 'backlog_order_id' => '123' ) );
recalc_smoke_assert( false === $service->preview( $backlog_blocked )['success'], 'Preview must be blocked when shipment has backlog_order_id.' );

$controller = new OrderDeliveryRecalculationAdminController( $service, new OrderDeliveryRateRenderer() );
$_POST = array( 'order_id' => 101, 'nonce' => 'ok' );
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must send JSON response.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( $response->success && isset( $response->data['html'], $response->data['rates'], $response->data['request'] ), 'Endpoint must return html, rates and request on success.' );
}

$GLOBALS['wdc_recalc_current_can'] = false;
try {
	$controller->ajax_preview();
	recalc_smoke_assert( false, 'Controller must reject missing capability.' );
} catch ( WdcRecalcAjaxResponse $response ) {
	recalc_smoke_assert( ! $response->success && 403 === $response->status, 'Endpoint must require manage_woocommerce.' );
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
