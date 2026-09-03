<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/run-dpd-checkout-runtime-smoke.php';
ob_end_clean();

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentDateResolver;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function dpd_order_recalc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @return array<string,mixed>
 */
function dpd_order_recalc_current_pickup_payload( DpdOrderRecalcOrder $order ): array {
	$method = new ReflectionMethod( OrderDeliveryMetabox::class, 'current_pickup_payload' );
	$method->setAccessible( true );
	$value = $method->invoke( new OrderDeliveryMetabox( new OrderShipmentRepository() ), $order );
	return is_array( $value ) ? $value : array();
}

$GLOBALS['wpdb']->locations[] = array( 'id' => 400, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Терминальная область', 'place_name' => 'Терминальный город', 'place_type' => 'г', 'display_name' => 'Терминальный город' );
$GLOBALS['wpdb']->delivery_codes[] = array( 'location_id' => 400, 'dpd_city_id' => '77700001', 'updated_at' => current_time( 'mysql' ) );
$GLOBALS['wpdb']->dpd_pickup_points[] = array( 'id' => 5, 'terminal_code' => 'ONLY-TERMINAL', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 77700001, 'city_name' => 'Терминальный город', 'name' => 'DPD terminal only', 'address' => 'ул Складская, 1', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 );
final class DpdOrderRecalcProduct {
	public function get_sku(): string { return 'dpd-order-sku'; }
	public function get_name(): string { return 'DPD order item'; }
	public function get_weight(): float { return 1.5; }
	public function get_length(): float { return 30.0; }
	public function get_width(): float { return 20.0; }
	public function get_height(): float { return 10.0; }
}

final class DpdOrderRecalcItem {
	public function get_quantity(): int { return 1; }
	public function get_total(): float { return 2500.0; }
	public function get_product(): DpdOrderRecalcProduct { return new DpdOrderRecalcProduct(); }
	public function get_name(): string { return 'DPD order item'; }
}

final class DpdOrderRecalcOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<string,mixed> */
	public array $shipping_items = array( 'method_title' => 'Old shipping', 'total' => 10.0, 'meta' => array() );
	public float $total = 2510.0;
	public bool $saved = false;
	/** @var array<int,string> */
	public array $notes = array();
	public string $shipping_country = 'RU';
	public string $shipping_state = 'Москва';
	public string $shipping_city = 'Москва';
	public string $shipping_postcode = '101000';
	public string $shipping_address_1 = 'Тверская 1';
	public string $shipping_address_2 = '';

	public function get_id(): int { return 640; }
	public function get_items( string $type = '' ): array { return 'shipping' === $type ? ( array() === $this->shipping_items ? array() : array( $this->shipping_items ) ) : array( new DpdOrderRecalcItem() ); }
	public function get_subtotal(): float { return 2500.0; }
	public function get_item_count(): int { return 1; }
	public function get_payment_method(): string { return 'cod'; }
	public function get_shipping_country(): string { return $this->shipping_country; }
	public function get_billing_country(): string { return 'RU'; }
	public function get_shipping_city(): string { return $this->shipping_city; }
	public function get_shipping_state(): string { return $this->shipping_state; }
	public function get_shipping_postcode(): string { return $this->shipping_postcode; }
	public function get_shipping_address_1(): string { return $this->shipping_address_1; }
	public function get_shipping_address_2(): string { return $this->shipping_address_2; }
	public function get_billing_first_name(): string { return 'Ivan'; }
	public function get_billing_last_name(): string { return 'Petrov'; }
	public function get_billing_phone(): string { return '+79990000000'; }
	public function get_billing_email(): string { return 'client@example.test'; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function set_shipping_country( string $value ): void { $this->shipping_country = $value; }
	public function set_shipping_state( string $value ): void { $this->shipping_state = $value; }
	public function set_shipping_city( string $value ): void { $this->shipping_city = $value; }
	public function set_shipping_postcode( string $value ): void { $this->shipping_postcode = $value; }
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void { $this->shipping_address_2 = $value; }
	public function calculate_totals( bool $and_taxes = true ): void { $this->total = 2500.0 + (float) ( $this->shipping_items['total'] ?? 0 ); }
	public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): void { $this->notes[] = $note; }
	public function save(): void { $this->saved = true; }
}

$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1', 'NDY' => '1' ),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
		DpdSettings::RUNTIME_ENABLE_COURIER_RATES_KEY => '1',
	)
);
dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'MAX', 'serviceName' => 'DPD Максимум', 'cost' => 100.25, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 2 ),
		array( 'serviceCode' => 'NDY', 'serviceName' => 'DPD Экспресс', 'cost' => 220.10, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 1 ),
	)
);

$order = new DpdOrderRecalcOrder();
$order->meta['_wdc_platform_location_id'] = '200';
$recalculation = new OrderDeliveryRecalculationService( new OrderQuoteRequestMapper(), $orchestrator, new OrderShipmentRepository() );
$preview = $recalculation->preview( $order, array( 'id' => 200, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' ) );
$dpd_groups = array_values( array_filter( $preview['rates'], static fn( array $rate ): bool => DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' ) ) );
dpd_order_recalc_assert( count( $dpd_groups ) >= 2, 'DPD pickup and courier groups must appear in order recalculation results.' );
$pickup_group = array_values( array_filter( $dpd_groups, static fn( array $rate ): bool => DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) )[0] ?? array();
$courier_group = array_values( array_filter( $dpd_groups, static fn( array $rate ): bool => DeliveryType::COURIER === (string) ( $rate['delivery_type'] ?? '' ) ) )[0] ?? array();
$pickup_tariff = $pickup_group['tariff_variants'][0] ?? array();
$courier_tariff = $courier_group['tariff_variants'][0] ?? array();
dpd_order_recalc_assert( 'MAX' === (string) ( $pickup_tariff['object_code'] ?? '' ) && 'MAX' === (string) ( $courier_tariff['object_code'] ?? '' ), 'DPD recalculation grouped variants must expose selected serviceCode.' );
dpd_order_recalc_assert( 'DPD до пункта выдачи' === (string) ( $pickup_group['label'] ?? '' ) && 'DPD курьером' === (string) ( $courier_group['label'] ?? '' ), 'DPD recalculation grouped titles must use DPD pickup/courier defaults.' );
$pickup_rate_meta = is_array( $pickup_tariff['rate_meta'] ?? null ) ? $pickup_tariff['rate_meta'] : array();
$pickup_request_payload = is_array( $pickup_rate_meta['request_payload_sanitized'] ?? null ) ? $pickup_rate_meta['request_payload_sanitized'] : array();
$pickup_terminal_selection = is_array( $pickup_rate_meta['dpd_delivery_terminal_selection'] ?? null ) ? $pickup_rate_meta['dpd_delivery_terminal_selection'] : array();
dpd_order_recalc_assert( 200 === (int) ( $preview['request']['customer_context']['location_id'] ?? 0 ) && '49694102' === (string) ( $pickup_rate_meta['dpd_receiver_city_id'] ?? '' ), 'DPD recalculation diagnostics must expose receiver_location_id and receiver_city_id.' );
dpd_order_recalc_assert( 'MSK-AUTO' === (string) ( $pickup_terminal_selection['selected_terminal_code'] ?? '' ) && 'parcel_shop' === (string) ( $pickup_terminal_selection['selected_type'] ?? '' ) && 'auto' === (string) ( $pickup_rate_meta['dpd_delivery_terminal_source'] ?? '' ), 'DPD pickup diagnostics must expose auto parcel_shop terminal selection.' );
dpd_order_recalc_assert( true === ( $pickup_request_payload['selfDelivery'] ?? null ) && 'MSK-AUTO' === (string) ( $pickup_request_payload['delivery']['terminalCode'] ?? '' ), 'DPD pickup request_payload_sanitized must contain selfDelivery=true and delivery.terminalCode.' );
dpd_order_recalc_assert( 'MSK-SELECTED' !== (string) ( $pickup_terminal_selection['selected_terminal_code'] ?? '' ), 'DPD auto pickup must avoid terminal_self_delivery duplicate when another parcel_shop exists.' );
dpd_order_recalc_assert( 2 === (int) ( $pickup_rate_meta['dpd_raw_count'] ?? 0 ) && 0 === (int) ( $pickup_rate_meta['dpd_skipped_disallowed_count'] ?? 0 ) && 0 === (int) ( $pickup_rate_meta['dpd_skipped_no_cost_count'] ?? 0 ) && 0 === (int) ( $pickup_rate_meta['dpd_filter_removed_count'] ?? 0 ), 'DPD pickup diagnostics must expose raw/filter counters.' );
$location_id_preview = $recalculation->preview( $order, array( 'location_id' => 200, 'dpd_city_id' => 49694102, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' ) );
$location_id_dpd_groups = array_values( array_filter( $location_id_preview['rates'], static fn( array $rate ): bool => DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' ) ) );
dpd_order_recalc_assert( count( $location_id_dpd_groups ) >= 2, 'DPD order recalculation preview must keep DPD rates when selected_location uses location_id instead of id.' );
dpd_order_recalc_assert( 200 === (int) ( $location_id_preview['request']['customer_context']['location_id'] ?? 0 ) && 49694102 === (int) ( $location_id_preview['request']['customer_context']['dpd_receiver_city_id'] ?? 0 ), 'Order recalculation QuoteRequest must preserve location_id and DPD cityId context.' );

$mapper = new OrderQuoteRequestMapper();
$moscow_location = array( 'id' => 200, 'location_id' => 200, 'dpd_city_id' => 49694102, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' );
$stale_pickup_order = new DpdOrderRecalcOrder();
$stale_pickup_order->meta['_wdc_platform_location_id'] = '100';
$stale_pickup_order->meta['_wdc_dpd_pickup_terminal_code'] = 'NSK-SENDER';
$stale_pickup_order->meta['_wdc_pickup_point_code'] = 'NSK-SENDER';
$stale_pickup_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] = array(
	'destination' => array( 'location_id' => 100, 'city_display_name' => 'Новосибирск' ),
	'pickup' => array( 'terminal_code' => 'NSK-SENDER', 'point_code' => 'NSK-SENDER' ),
);
$stale_moscow_request = $mapper->map( $stale_pickup_order, $moscow_location, array() );
dpd_order_recalc_assert( 200 === (int) ( $stale_moscow_request->customer_context['selected_location_id'] ?? 0 ) && 49694102 === (int) ( $stale_moscow_request->customer_context['dpd_receiver_city_id'] ?? 0 ), 'DPD mapper must keep explicit Moscow location/city mapping when order has old pickup meta.' );
dpd_order_recalc_assert( ! isset( $stale_moscow_request->customer_context['dpd_selected_terminal_code'] ), 'DPD mapper must not carry saved terminalCode when selected canonical location differs from saved order location.' );
$calls_before_stale_preview = count( $soap->calls );
$stale_moscow_preview = $recalculation->preview( $stale_pickup_order, $moscow_location );
$stale_moscow_pickup_payload = $soap->calls[ $calls_before_stale_preview ]['payload'] ?? array();
$stale_moscow_groups = array_values( array_filter( $stale_moscow_preview['rates'], static fn( array $rate ): bool => DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' ) && DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) );
dpd_order_recalc_assert( array() !== $stale_moscow_groups && 'MSK-AUTO' === (string) ( $stale_moscow_pickup_payload['delivery']['terminalCode'] ?? '' ) && 'NSK-SENDER' !== (string) ( $stale_moscow_pickup_payload['delivery']['terminalCode'] ?? '' ), 'Changed-location DPD recalculation must drop stale saved terminalCode and auto-select a receiver parcel_shop for the new city.' );

$missing_location_order = new DpdOrderRecalcOrder();
$missing_location_order->meta['_wdc_dpd_pickup_terminal_code'] = 'NSK-SENDER';
$missing_location_request = $mapper->map( $missing_location_order, $moscow_location, array() );
dpd_order_recalc_assert( ! isset( $missing_location_request->customer_context['dpd_selected_terminal_code'] ), 'DPD mapper must not reuse saved pickup meta when the order had no canonical saved location and admin selected a new canonical location.' );

$same_location_order = new DpdOrderRecalcOrder();
$same_location_order->meta['_wdc_platform_location_id'] = '200';
$same_location_order->meta['_wdc_dpd_pickup_terminal_code'] = 'MSK-SELECTED';
$same_location_request = $mapper->map( $same_location_order, $moscow_location, array() );
dpd_order_recalc_assert( 'MSK-SELECTED' === (string) ( $same_location_request->customer_context['dpd_selected_terminal_code'] ?? '' ), 'DPD mapper may reuse saved terminalCode when selected canonical location matches saved order location.' );

$same_text_different_id_order = new DpdOrderRecalcOrder();
$same_text_different_id_order->meta['_wdc_platform_location_id'] = '100';
$same_text_different_id_order->meta['_wdc_dpd_pickup_terminal_code'] = 'NSK-SENDER';
$same_text_different_id_request = $mapper->map( $same_text_different_id_order, array_merge( $moscow_location, array( 'id' => 200, 'display_name' => 'Новосибирск', 'city_value' => 'Новосибирск' ) ), array() );
dpd_order_recalc_assert( ! isset( $same_text_different_id_request->customer_context['dpd_selected_terminal_code'] ), 'DPD mapper must compare selected destination by canonical id, not display_name.' );

$non_dpd_pickup_request = $mapper->map( new DpdOrderRecalcOrder(), $moscow_location, array( 'carrier_key' => 'cdek', 'pickup_family' => 'cdek:pickup', 'point_code' => 'CDEK123' ) );
dpd_order_recalc_assert( ! isset( $non_dpd_pickup_request->customer_context['dpd_selected_terminal_code'] ), 'DPD mapper must ignore non-DPD selected pickup point_code.' );

$explicit_dpd_request = $mapper->map( $stale_pickup_order, $moscow_location, array( 'carrier_key' => 'dpd', 'pickup_family' => 'dpd:pickup', 'terminal_code' => 'MSK-SELECTED', 'point_code' => 'MSK-SELECTED' ) );
dpd_order_recalc_assert( 'MSK-SELECTED' === (string) ( $explicit_dpd_request->customer_context['dpd_selected_terminal_code'] ?? '' ), 'Explicit DPD selected pickup must have priority over changed-location stale terminal suppression.' );

$dpd_pickup_order = new DpdOrderRecalcOrder();
$dpd_pickup_order->meta['_wdc_platform_carrier_key'] = 'dpd';
$dpd_pickup_order->meta['_wdc_platform_delivery_type'] = 'pickup';
$dpd_pickup_order->meta['_wdc_platform_tariff_object'] = 'NDY';
$dpd_pickup_order->meta['_wdc_dpd_pickup_terminal_code'] = 'MSK-SAVED';
$dpd_pickup_order->meta['_wdc_pickup_carrier_key'] = 'dpd';
$dpd_pickup_order->meta['_wdc_pickup_point_code'] = 'MSK-SAVED';
$dpd_pickup_order->meta['_wdc_pickup_point_address'] = 'Москва, saved DPD point';
$dpd_current_pickup = dpd_order_recalc_current_pickup_payload( $dpd_pickup_order );
dpd_order_recalc_assert( 'dpd' === (string) ( $dpd_current_pickup['carrier_key'] ?? '' ) && 'MSK-SAVED' === (string) ( $dpd_current_pickup['terminal_code'] ?? '' ), 'Existing DPD pickup order with any tariff must expose saved DPD terminalCode for prefill.' );

$dpd_courier_order = new DpdOrderRecalcOrder();
$dpd_courier_order->meta['_wdc_platform_carrier_key'] = 'dpd';
$dpd_courier_order->meta['_wdc_platform_delivery_type'] = 'courier';
$dpd_courier_order->meta['_wdc_dpd_pickup_terminal_code'] = 'STALE-DPD';
$dpd_courier_order->meta['_wdc_pickup_carrier_key'] = 'dpd';
$dpd_courier_order->meta['_wdc_pickup_point_code'] = 'STALE-DPD';
$dpd_courier_order->shipping_address_1 = 'Тверская 1';
dpd_order_recalc_assert( array() === dpd_order_recalc_current_pickup_payload( $dpd_courier_order ), 'Existing DPD courier order must not expose shipping address or stale terminalCode as current pickup.' );
dpd_order_recalc_assert( array() === dpd_order_recalc_current_pickup_payload( new DpdOrderRecalcOrder() ), 'Order without saved delivery must not expose current pickup.' );

$cdek_pickup_order = new DpdOrderRecalcOrder();
$cdek_pickup_order->meta['_wdc_platform_carrier_key'] = 'cdek';
$cdek_pickup_order->meta['_wdc_platform_delivery_type'] = 'pickup';
$cdek_pickup_order->meta['_wdc_pickup_carrier_key'] = 'cdek';
$cdek_pickup_order->meta['_wdc_pickup_point_code'] = 'KEM7';
$cdek_pickup_order->meta['_wdc_pickup_point_address'] = 'Kemerovo, Sovetskiy 10';
$cdek_current_pickup = dpd_order_recalc_current_pickup_payload( $cdek_pickup_order );
dpd_order_recalc_assert( 'cdek' === (string) ( $cdek_current_pickup['carrier_key'] ?? '' ) && 'KEM7' === (string) ( $cdek_current_pickup['point_code'] ?? '' ) && '' === (string) ( $cdek_current_pickup['terminal_code'] ?? '' ), 'CDEK current pickup prefill must remain non-DPD and must not receive DPD terminal_code.' );

$russian_post_pickup_order = new DpdOrderRecalcOrder();
$russian_post_pickup_order->meta['_wdc_platform_carrier_key'] = 'russian_post_domestic';
$russian_post_pickup_order->meta['_wdc_platform_delivery_type'] = 'pickup';
$russian_post_pickup_order->meta['_wdc_pickup_carrier_key'] = 'russian_post_domestic';
$russian_post_pickup_order->meta['_wdc_pickup_point_code'] = '101000-OPS';
$russian_post_pickup_order->meta['_wdc_pickup_point_address'] = 'Москва, ОПС 101000';
$russian_post_current_pickup = dpd_order_recalc_current_pickup_payload( $russian_post_pickup_order );
dpd_order_recalc_assert( 'russian_post_domestic' === (string) ( $russian_post_current_pickup['carrier_key'] ?? '' ) && '101000-OPS' === (string) ( $russian_post_current_pickup['point_code'] ?? '' ) && '' === (string) ( $russian_post_current_pickup['terminal_code'] ?? '' ), 'Russian Post current pickup prefill must remain non-DPD and must not receive DPD terminal_code.' );

$renderer_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Admin/OrderDeliveryRateRenderer.php' );
dpd_order_recalc_assert( str_contains( $renderer_source, 'data-carrier-key' ) && str_contains( $renderer_source, 'ПВЗ не выбран' ), 'DPD pickup recalculation UI must start with an explicit empty pickup state.' );
$pickup_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/order-delivery-recalculation.js' );
dpd_order_recalc_assert( str_contains( $pickup_js, "'Пункт выдачи DPD'" ) && str_contains( $pickup_js, 'pickupPointDisplayCode( point )' ) && ! str_contains( $pickup_js, "'Пункт выдачи DPD ' + pickupPointDisplayCode" ), 'DPD pickup picker title must render as "Пункт выдачи DPD {code}" through the shared title/code renderer.' );
dpd_order_recalc_assert( str_contains( $pickup_js, "return isDpdPickupPoint( point ) ? 'Код пункта:' : 'Код/индекс:'" ), 'DPD pickup picker must use "Код пункта" instead of the Russian Post code/index label.' );
dpd_order_recalc_assert( str_contains( $pickup_js, "if ( 'dpd' === carrier )" ) && str_contains( $pickup_js, 'pickupCodeForCarrier( pickup, carrier ) !== \'\'' ) && str_contains( $pickup_js, 'selectedPickupPoints.delete( box );' ), 'DPD pickup prefill must be carrier-aware and must not reuse non-DPD pickup or shipping address state.' );
dpd_order_recalc_assert( str_contains( $pickup_js, "setStatus( box, 'Для pickup-варианта выберите ПВЗ.', 'error' );" ), 'Saving pickup without an explicitly selected point must be blocked in the admin UI.' );
dpd_order_recalc_assert( str_contains( $pickup_js, "if ( options.selectedPickupPoint )" ) && str_contains( $pickup_js, "form.append( 'selected_pickup_point'" ) && str_contains( $pickup_js, 'restoreDpdPickupPreview' ), 'DPD preview must send selected_pickup_point only after explicit pickup choice and then restore it in UI.' );
dpd_order_recalc_assert( str_contains( $pickup_js, "'ПВЗ СДЭК'" ) && str_contains( $pickup_js, "'Постамат СДЭК'" ), 'CDEK pickup labels must remain in the admin recalculation picker.' );
dpd_order_recalc_assert( str_contains( $pickup_js, "'Код/индекс:'" ) && str_contains( $pickup_js, "'Отделение Почты России'" ), 'Russian Post pickup title and code/index label must remain available.' );

$pickup_payload = $soap->calls[ count( $soap->calls ) - 2 ]['payload'] ?? array();
$courier_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_order_recalc_assert( 'getServiceCostByParcels3' === (string) ( $soap->calls[ count( $soap->calls ) - 1 ]['method'] ?? '' ), 'DPD order recalculation must use Parcels3 runtime pricing.' );
dpd_order_recalc_assert( 'NSK-SENDER' === (string) ( $pickup_payload['pickup']['terminalCode'] ?? '' ) && 'MSK-AUTO' === (string) ( $pickup_payload['delivery']['terminalCode'] ?? '' ), 'DPD pickup recalculation must use sender terminalCode and auto-selected receiver terminalCode.' );
dpd_order_recalc_assert( 'NSK-SENDER' === (string) ( $courier_payload['pickup']['terminalCode'] ?? '' ) && ! isset( $courier_payload['delivery']['terminalCode'] ), 'DPD courier recalculation must use sender terminalCode and no delivery terminalCode.' );
dpd_order_recalc_assert( is_array( $pickup_payload['parcel'] ?? null ) && 2500.0 === (float) ( $pickup_payload['declaredValue'] ?? 0 ), 'DPD order recalculation must build parcel[] and declaredValue from order items.' );
$terminal_only_preview = $recalculation->preview( $order, array( 'id' => 400, 'dpd_city_id' => 77700001, 'display_name' => 'Терминальный город', 'city_value' => 'Терминальный город', 'region_name' => 'Терминальная область', 'postal_code' => '777000', 'country_code' => 'RU' ) );
$terminal_only_dpd_groups = array_values( array_filter( $terminal_only_preview['rates'], static fn( array $rate ): bool => DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' ) ) );
$terminal_only_pickup_groups = array_values( array_filter( $terminal_only_dpd_groups, static fn( array $rate ): bool => DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) );
$terminal_only_courier_groups = array_values( array_filter( $terminal_only_dpd_groups, static fn( array $rate ): bool => DeliveryType::COURIER === (string) ( $rate['delivery_type'] ?? '' ) ) );
dpd_order_recalc_assert( array() === $terminal_only_pickup_groups && array() !== $terminal_only_courier_groups, 'DPD courier must stay available when pickup is unavailable because receiver city has no active parcel_shop.' );
$missing_pickup_quote = $carrier->quote( dpd_checkout_request( 400, 1500, DeliveryType::PICKUP ) );
$missing_errors = is_array( $missing_pickup_quote->raw_reference['errors'] ?? null ) ? $missing_pickup_quote->raw_reference['errors'] : array();
dpd_order_recalc_assert( str_contains( implode( ' ', array_map( 'strval', $missing_errors ) ), 'DPD pickup tariff unavailable: no active parcel_shop for receiver cityId 77700001' ), 'DPD missing pickup quote must expose a clear no active parcel_shop diagnostic reason.' );
dpd_order_recalc_assert( 400 === (int) ( $missing_pickup_quote->raw_reference['receiver_location_id'] ?? 0 ) && '77700001' === (string) ( $missing_pickup_quote->raw_reference['receiver_city_id'] ?? '' ) && 'auto' === (string) ( $missing_pickup_quote->raw_reference['delivery_terminal_source'] ?? '' ), 'DPD missing pickup diagnostics must expose receiver ids and auto terminal source.' );
dpd_order_recalc_assert( 'ONLY-TERMINAL' !== (string) ( $missing_pickup_quote->raw_reference['delivery_terminal_code'] ?? '' ), 'DPD pickup must not use terminal_self_delivery as receiver pickup point.' );

$selected_preview = $recalculation->preview(
	$order,
	array( 'id' => 200, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' ),
	array( 'carrier_key' => 'dpd', 'terminal_code' => 'MSK-SELECTED', 'point_code' => 'MSK-SELECTED' )
);
$selected_pickup_payload = $soap->calls[ count( $soap->calls ) - 2 ]['payload'] ?? array();
dpd_order_recalc_assert( 'MSK-SELECTED' === (string) ( $selected_pickup_payload['delivery']['terminalCode'] ?? '' ), 'Selected DPD pickup point must override auto-selected terminalCode in recalculation preview.' );
dpd_order_recalc_assert( count( array_filter( $selected_preview['rates'], static fn( array $rate ): bool => 'dpd' === (string) ( $rate['carrier_key'] ?? '' ) ) ) >= 2, 'DPD selected-terminal preview must still expose DPD rates.' );
$selected_dpd_groups = array_values( array_filter( $selected_preview['rates'], static fn( array $rate ): bool => DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' ) ) );
$selected_pickup_group = array_values( array_filter( $selected_dpd_groups, static fn( array $rate ): bool => DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) )[0] ?? array();
$selected_pickup_tariff = $selected_pickup_group['tariff_variants'][0] ?? array();

$replacement = new OrderDeliveryReplacementService( new OrderShipmentRepository(), new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) );
$blocked_pickup = $replacement->save(
	new DpdOrderRecalcOrder(),
	array(
		'selected_location' => array( 'id' => 200, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' ),
		'selected_rate' => $selected_pickup_group,
		'selected_tariff' => $selected_pickup_tariff,
		'selected_pickup_point' => array(),
		'normalized_shipping_address' => array(),
	)
);
dpd_order_recalc_assert( false === $blocked_pickup['success'] && 'Для pickup-варианта выберите ПВЗ.' === $blocked_pickup['message'], 'Saving DPD pickup without an explicitly selected pickup point must be blocked.' );

$selected_point = array(
	'id' => 'dpd:MSK-SELECTED',
	'carrier_key' => 'dpd',
	'service_key' => 'dpd',
	'pickup_family' => 'dpd:pickup',
	'point_code' => 'MSK-SELECTED',
	'terminal_code' => 'MSK-SELECTED',
	'point_type' => 'parcel_shop',
	'point_title' => 'Пункт выдачи DPD',
	'point_name' => 'DPD Москва selected',
	'point_address' => 'ул Арбат, 1',
	'city_name' => 'Москва',
	'lat' => '55.75',
	'lng' => '37.60',
	'dpd_source' => 'getParcelShops',
);
$save_pickup = $replacement->save(
	$order,
	array(
		'selected_location' => array( 'id' => 200, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' ),
		'selected_rate' => $selected_pickup_group,
		'selected_tariff' => $selected_pickup_tariff,
		'selected_pickup_point' => $selected_point,
		'normalized_shipping_address' => array(),
	)
);
dpd_order_recalc_assert( true === $save_pickup['success'], 'Saving DPD pickup recalculation must succeed.' );
dpd_order_recalc_assert( 'dpd' === (string) ( $order->meta['_wdc_platform_carrier_key'] ?? '' ) && 'dpd' === (string) ( $order->meta['_wdc_platform_service_key'] ?? '' ), 'Saving DPD pickup must write carrier/service keys.' );
dpd_order_recalc_assert( 'pickup' === (string) ( $order->meta['_wdc_platform_delivery_type'] ?? '' ) && 'MAX' === (string) ( $order->meta['_wdc_platform_tariff_object'] ?? '' ), 'Saving DPD pickup must write delivery type and serviceCode.' );
dpd_order_recalc_assert( 'MSK-SELECTED' === (string) ( $order->meta['_wdc_pickup_point_code'] ?? '' ) && 'MSK-SELECTED' === (string) ( $order->meta['_wdc_dpd_pickup_terminal_code'] ?? '' ), 'Saving DPD pickup must write shared and alias pickup terminal meta.' );
dpd_order_recalc_assert( 'parcel_shop' === (string) ( $order->meta['_wdc_dpd_pickup_type'] ?? '' ) && 'DPD Москва selected' === (string) ( $order->meta['_wdc_dpd_pickup_name'] ?? '' ) && 'ул Арбат, 1' === (string) ( $order->meta['_wdc_dpd_pickup_address'] ?? '' ), 'Saving DPD pickup must write DPD alias type/name/address meta.' );
dpd_order_recalc_assert( 'dpd:pickup' === (string) ( $order->meta['_wdc_pickup_family'] ?? '' ) && is_string( $order->meta['_wdc_pickup_point_snapshot'] ?? null ), 'Saving DPD pickup must write shared pickup family and snapshot.' );
$calc = $order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
dpd_order_recalc_assert( 'dpd' === (string) ( $calc['carrier_key'] ?? '' ) && 'MAX' === (string) ( $calc['selected_tariff_object'] ?? '' ) && 'MSK-SELECTED' === (string) ( $calc['pickup']['terminal_code'] ?? '' ), 'Saving DPD pickup must write calculation data with selected terminal.' );
dpd_order_recalc_assert( 'NSK-SENDER' === (string) ( $calc['api']['dpd_pickup_terminal_code'] ?? '' ) && 'MSK-SELECTED' === (string) ( $calc['api']['dpd_delivery_terminal_code'] ?? '' ), 'Saved DPD rate meta must keep Parcels3 sender and quoted receiver terminal diagnostics.' );
dpd_order_recalc_assert( str_contains( (string) ( $order->shipping_items['method_title'] ?? '' ), 'DPD' ) && (float) ( $order->shipping_items['total'] ?? 0 ) >= 100.0 && 1 === count( $order->shipping_items['meta'] ?? array() ), 'Saving DPD pickup must update WooCommerce shipping item title, total and compact visible meta.' );

$pickup_service = new DpdPickupPointService( new DpdPickupPointRepository( $GLOBALS['wpdb'] ), new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] ) );
$draft_factory = new OrderShipmentDraftFactory( $services, new ShipmentServiceSettings(), null, null, null, null, null, $settings, $pickup_service, new DpdShipmentDateResolver() );
$draft = $draft_factory->create_request_from_order( $order );
dpd_order_recalc_assert( 'dpd' === $draft->carrier_key && 'pickup' === $draft->delivery_type && 'MAX' === (string) ( $draft->meta['service_code'] ?? '' ), 'Shipment draft after DPD pickup recalculation must see carrier, delivery type and serviceCode.' );
dpd_order_recalc_assert( 'NSK-SENDER' === (string) ( $draft->meta['pickup_terminal_code'] ?? '' ) && 'MSK-SELECTED' === (string) ( $draft->meta['delivery_terminal_code'] ?? '' ), 'Shipment draft after DPD pickup recalculation must see sender and receiver terminalCode.' );

$save_courier = $replacement->save(
	$order,
	array(
		'selected_location' => array( 'id' => 200, 'display_name' => 'Москва', 'city_value' => 'Москва', 'region_name' => 'Москва', 'postal_code' => '101000', 'country_code' => 'RU' ),
		'selected_rate' => $courier_group,
		'selected_tariff' => $courier_tariff,
		'selected_pickup_point' => array(),
		'normalized_shipping_address' => array( 'source' => 'admin_manual', 'fallback' => true, 'address_1' => 'Тверская 1', 'address_2' => '', 'country' => 'RU', 'region' => 'Москва', 'city' => 'Москва', 'postcode' => '101000' ),
	)
);
dpd_order_recalc_assert( true === $save_courier['success'], 'Saving DPD courier recalculation must succeed.' );
dpd_order_recalc_assert( 'courier' === (string) ( $order->meta['_wdc_platform_delivery_type'] ?? '' ), 'Saving DPD courier must write courier delivery type.' );
dpd_order_recalc_assert( '' === (string) ( $order->meta['_wdc_pickup_point_code'] ?? '' ) && '' === (string) ( $order->meta['_wdc_dpd_pickup_terminal_code'] ?? '' ), 'Saving DPD courier must clear shared pickup meta and DPD receiver terminal alias.' );
$courier_calc = $order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
dpd_order_recalc_assert( array() === ( $courier_calc['pickup'] ?? array() ) && '' === (string) ( $courier_calc['api']['dpd_delivery_terminal_code'] ?? '' ), 'Saving DPD courier calculation data must not keep pickup block or receiver terminalCode.' );
$courier_draft = $draft_factory->create_request_from_order( $order );
dpd_order_recalc_assert( 'courier' === $courier_draft->delivery_type && '' === (string) ( $courier_draft->meta['delivery_terminal_code'] ?? '' ), 'Shipment draft after DPD courier recalculation must not keep receiver terminalCode.' );

$adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' );
dpd_order_recalc_assert( str_contains( $adapter_source, 'createOrder2' ) && ! str_contains( $adapter_source, 'dpd_create_disabled' ) && ! str_contains( $adapter_source, "'dry_run' => true" ) && ! str_contains( $adapter_source, 'live_api_call' ), 'DPD recalculation smoke must keep manual createOrder2 available without legacy preview debug meta.' );

echo "DPD order recalculation smoke OK\n";
