<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Shipments\Analytics\CreatedShipmentIdentityResolver;
use WallsShop\WDC\Shipments\Analytics\OrderAnalyticsShipmentSelector;
use WallsShop\WDC\Shipments\Analytics\OrderSelectedDeliveryIdentityResolver;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Manual\ManualShipmentAdapter;
use WallsShop\WDC\Shipments\Manual\ManualShipmentService;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function wdc_manual_shipment_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { unset( $type ); return '2026-09-05 12:00:00'; }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ?? '' ); }
function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }

final class ManualShipmentFakeOrder {
	/** @param array<string,mixed> $meta */
	public function __construct(
		private int $id,
		private array $meta
	) {
	}

	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { unset( $single ); return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	public function get_order_number(): string { return 'ORD-' . $this->id; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_shipping_state(): string { return 'Новосибирская область'; }
	public function get_shipping_city(): string { return 'Новосибирск'; }
	public function get_shipping_postcode(): string { return '630000'; }
	public function get_shipping_address_1(): string { return 'ул. Д. Бедного, 57'; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_billing_first_name(): string { return 'Иван'; }
	public function get_billing_last_name(): string { return 'Иванов'; }
	public function get_billing_phone(): string { return '+79990000000'; }
	public function get_billing_email(): string { return 'buyer@example.test'; }
	public function get_items(): array { return array(); }
	public function get_subtotal(): float { return 0.0; }
	public function get_payment_method(): string { return 'cod'; }
}

function manual_shipment_order( string $service_key, string $service_title, string $delivery_type = DeliveryType::PICKUP, string $carrier_key = ManualDeliverySettings::CARRIER_KEY ): ManualShipmentFakeOrder {
	$calculation = array(
		'carrier_key' => $carrier_key,
		'service_key' => $service_key,
		'service' => array(
			'carrier_key' => $carrier_key,
			'service_key' => $service_key,
			'title' => $service_title,
		),
		'api' => array(
			'api_base_price_rub' => 500,
		),
		'result' => array(
			'final_price_rub' => 600,
		),
	);

	return new ManualShipmentFakeOrder(
		random_int( 1000, 9999 ),
		array(
			'_wdc_platform_carrier_key' => $carrier_key,
			'_wdc_platform_service_key' => $service_key,
			'_wdc_platform_service_title' => $service_title,
			'_wdc_platform_rate_id' => ManualDeliverySettings::CARRIER_KEY . ':' . $service_key,
			'_wdc_platform_delivery_type' => $delivery_type,
			'_wdc_delivery_calculation_data' => $calculation,
			'_wdc_pickup_point_title' => 'Исторический ПВЗ',
		)
	);
}

function manual_shipment_components(): array {
	$repository = new OrderShipmentRepository();
	$comparison = new ShipmentActualCostComparisonService();
	$base_costs = new ShipmentBaseApiCostResolver();
	$actual_resolver = new ShipmentActualCostResolver( $comparison, $base_costs );
	$actual_service = new ShipmentActualCostService( $repository );
	$manual_service = new ManualShipmentService( $repository, $actual_service );
	$adapter = new ManualShipmentAdapter( $manual_service, $actual_resolver );

	return array( $repository, $adapter, $actual_service, $actual_resolver );
}

[$repository, $adapter, $actual_service, $actual_resolver] = manual_shipment_components();
$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
wdc_manual_shipment_assert( $registry->get( ManualDeliverySettings::CARRIER_KEY ) === $adapter, 'Manual shipment adapter must be registered once under carrier=manual.' );
try {
	new CarrierShipmentAdapterRegistry( array( $adapter, $adapter ) );
	throw new RuntimeException( 'Duplicate manual shipment adapter must be rejected.' );
} catch ( InvalidArgumentException ) {
}

$request = new ShipmentCreateRequest( 1, ManualDeliverySettings::CARRIER_KEY, DeliveryType::PICKUP, 'manual:manual_pickup_test', new Address( country_code: 'RU', city: 'Новосибирск' ), null, array(), Money::from_kopecks( 0 ) );
wdc_manual_shipment_assert( $adapter->supports( $request ), 'Manual adapter must support carrier=manual requests.' );
wdc_manual_shipment_assert( ! $adapter->create( $request )->success, 'Manual adapter must not support API shipment create.' );
wdc_manual_shipment_assert( array() !== $adapter->build_safe_payload_preview( $request )['errors'], 'Manual adapter preview must be unsupported.' );
wdc_manual_shipment_assert( false === $adapter->supports_status_auto_sync(), 'Manual adapter must not register status autosync behavior.' );
$empty_status = $adapter->status_payload( manual_shipment_order( 'manual_pickup_test', 'Старая доставка' ), array() );
wdc_manual_shipment_assert( ! array_key_exists( 'document_actions', $empty_status ), 'Manual adapter payload must leave document actions to the shared document provider registry.' );

$order = manual_shipment_order( 'manual_pickup_test', 'Старая доставка' );
$result = $adapter->attach_manual(
	$order,
	array(
		'barcode' => 'ABC-123',
		'tracking_number' => 'ABC-123',
		'service_key' => 'browser_attempt_to_override',
		'actual_cost' => '550.50',
	)
);
wdc_manual_shipment_assert( ! empty( $result['success'] ), 'Manual attach must succeed for a manual order.' );
$shipment = $repository->find_by_carrier( $order, ManualDeliverySettings::CARRIER_KEY );
wdc_manual_shipment_assert( ManualDeliverySettings::CARRIER_KEY === $shipment['carrier_key'], 'Manual shipment must store carrier_key=manual.' );
wdc_manual_shipment_assert( 'manual_pickup_test' === $shipment['service_key'], 'Manual shipment must preserve concrete historical service_key from order context.' );
wdc_manual_shipment_assert( 'Старая доставка' === $shipment['service_title'], 'Manual shipment must preserve historical service title from order context.' );
wdc_manual_shipment_assert( 'ABC-123' === $shipment['tracking_number'] && 'ABC-123' === $shipment['barcode'], 'Manual shipment number must become the canonical shipment identifier.' );
wdc_manual_shipment_assert( 'attached_manually' === $shipment['status'], 'Manual shipment must use local manual attachment status.' );
wdc_manual_shipment_assert( 55050 === $shipment['actual_cost_kopecks'], 'Manual attach actual cost must be stored as integer kopecks.' );
wdc_manual_shipment_assert( 'RUB' === $shipment['actual_cost_currency'] && 'manual' === $shipment['actual_cost_source'], 'Manual actual cost must use generic canonical currency/source fields.' );
wdc_manual_shipment_assert( ! array_key_exists( 'manual_actual_cost', $shipment ) && ! array_key_exists( 'manual_delivery_cost', $shipment ), 'Manual shipment must not create manual-prefixed actual cost storage.' );

$status = $adapter->status_payload( $order, $shipment );
wdc_manual_shipment_assert( false === $status['can_create'] && false === $status['can_cancel'] && false === $status['can_update_status'] && true === $status['can_remove_from_order'], 'Manual shipment capabilities must expose only local remove after attach.' );
wdc_manual_shipment_assert( '550.50 руб.' === $status['actual_cost_label'] && 50000 === $status['base_api_cost_kopecks'] && 'warning' === $status['actual_cost_compare_status'], 'Manual actual cost comparison must use canonical base cost, not customer shipping total.' );

$updated = $actual_service->manual_set( $order, ManualDeliverySettings::CARRIER_KEY, 60000 );
wdc_manual_shipment_assert( 60000 === $updated['actual_cost_kopecks'], 'Generic actual-cost service must update manual shipment amount.' );
$cleared = $actual_service->clear( $order, ManualDeliverySettings::CARRIER_KEY );
wdc_manual_shipment_assert( ! array_key_exists( 'actual_cost_kopecks', $cleared ) && ! array_key_exists( 'actual_cost_source', $cleared ), 'Generic actual-cost service must clear manual shipment actual cost fields.' );

$duplicate = $adapter->attach_manual( $order, array( 'barcode' => 'ABC-123' ) );
wdc_manual_shipment_assert( empty( $duplicate['success'] ), 'Duplicate manual attach for the same order/carrier must fail safe.' );
$remove = $adapter->remove_from_order( $order );
wdc_manual_shipment_assert( ! empty( $remove['success'] ) && array() === $repository->find_by_carrier( $order, ManualDeliverySettings::CARRIER_KEY ), 'Manual local remove must delete only the canonical shipment record.' );
wdc_manual_shipment_assert( ManualDeliverySettings::CARRIER_KEY === $order->get_meta( '_wdc_platform_carrier_key', true ) && 'Исторический ПВЗ' === $order->get_meta( '_wdc_pickup_point_title', true ), 'Manual local remove must leave delivery and pickup order snapshots intact.' );

$wrong_order = manual_shipment_order( 'ozon_pickup', 'Ozon', DeliveryType::PICKUP, 'ozon_delivery' );
wdc_manual_shipment_assert( empty( $adapter->attach_manual( $wrong_order, array( 'barcode' => 'OZ-1' ) )['success'] ), 'Manual attach must reject non-manual order delivery context.' );

foreach ( array( DeliveryType::PICKUP, DeliveryType::COURIER, DeliveryType::UNKNOWN ) as $type ) {
	[$type_repository, $type_adapter] = manual_shipment_components();
	$type_order = manual_shipment_order( 'manual_' . $type, 'Manual ' . $type, $type );
	$type_result = $type_adapter->attach_manual( $type_order, array( 'barcode' => strtoupper( $type ) . '-1' ) );
	$type_shipment = $type_repository->find_by_carrier( $type_order, ManualDeliverySettings::CARRIER_KEY );
	wdc_manual_shipment_assert( ! empty( $type_result['success'] ) && $type === $type_shipment['delivery_type'], 'Manual shipment attach must work identically for pickup/courier/custom delivery types.' );
}

$selector = new OrderAnalyticsShipmentSelector( new OrderSelectedDeliveryIdentityResolver(), new CreatedShipmentIdentityResolver() );
$analytics_order = manual_shipment_order( 'manual_b', 'Manual B' );
$selected = $selector->select(
	$analytics_order,
	array(
		'manual-a' => array( 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_key' => 'manual_a', 'tracking_number' => 'A-1', 'actual_cost_kopecks' => 10000 ),
		'manual-b' => array( 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_key' => 'manual_b', 'tracking_number' => 'B-1', 'actual_cost_kopecks' => 20000 ),
	)
);
wdc_manual_shipment_assert( null !== $selected && 'manual-b' === $selected->shipment_key && 'manual_b' === $selected->service_key, 'Shipment analytics selector must distinguish multiple manual services by service_key.' );

$root = dirname( __DIR__, 2 );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
$creation = file_get_contents( $root . '/src/Shipments/Application/ShipmentCreationService.php' ) ?: '';
$shipment_js = file_get_contents( $root . '/assets/admin/shipments/shipment-polling.js' ) . file_get_contents( $root . '/assets/admin/shipments/shipment-status.js' );
$manual_service_source = file_get_contents( $root . '/src/Shipments/Manual/ManualShipmentService.php' ) ?: '';

wdc_manual_shipment_assert( str_contains( $plugin, 'ManualShipmentAdapter::class' ) && str_contains( $plugin, 'CarrierShipmentAdapterRegistry' ), 'Plugin composition root must register the manual shipment adapter in the carrier adapter registry.' );
wdc_manual_shipment_assert( ! str_contains( $plugin, 'ManualShipmentDocumentProvider' ) && ! str_contains( $plugin, 'ManualShipmentModalExtension' ) && ! str_contains( $plugin, 'ManualShipmentPersistenceMapper' ), 'Manual shipment stage must not register document, modal, or fake persistence mapper providers.' );
wdc_manual_shipment_assert( ! str_contains( $creation, 'ManualShipment' ) && ! str_contains( $creation, "'manual'" ) && ! str_contains( $creation, '"manual"' ), 'ShipmentCreationService must not gain a manual-specific API-create branch.' );
wdc_manual_shipment_assert( ! str_contains( $shipment_js, "carrier === 'manual'" ) && ! str_contains( $shipment_js, 'ManualShipment' ), 'Generic shipment JS must not contain manual-specific branches.' );
wdc_manual_shipment_assert( str_contains( $manual_service_source, 'OrderShipmentRepository' ) && str_contains( $manual_service_source, 'ShipmentActualCostService' ), 'Manual shipment service must persist through OrderShipmentRepository and delegate actual cost to the common service.' );
wdc_manual_shipment_assert( ! str_contains( $manual_service_source, 'wp_remote_' ) && ! str_contains( $manual_service_source, 'ApiClient' ) && ! str_contains( $manual_service_source, 'curl_' ), 'Manual shipment service must not perform external API calls.' );

echo "Manual delivery shipment smoke passed.\n";
