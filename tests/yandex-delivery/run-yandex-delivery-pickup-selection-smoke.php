<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function yandex_pickup_selection_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function __( string $text, string $domain = '' ): string { return $text; }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html__( string $text, string $domain = '' ): string { return esc_html( $text ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function rest_ensure_response( mixed $data ): mixed { return $data; }
function wp_verify_nonce( string $nonce, string $action ): bool { return true; }
$GLOBALS['wdc_yandex_test_current_datetime'] = new DateTimeImmutable( '2026-07-11 00:30:00', new DateTimeZone( 'Europe/Moscow' ) );
function current_datetime(): DateTimeImmutable { return $GLOBALS['wdc_yandex_test_current_datetime']; }
function current_time( string $type ): string { return current_datetime()->format( 'Y-m-d H:i:s' ); }

function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public object $session;
			public function __construct() {
				$this->session = new class {
					public array $data = array();
					public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
					public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
					public function set_customer_session_cookie( bool $set ): void {}
					public function save_data(): void {}
				};
			}
		};
	}

	return $wc;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_pickup_points_v2 = array();
		public array $yandex_location_mapping_v2 = array();
		public function prepare( string $query, mixed ...$args ): string { return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class YandexPickupSelectionRequest {
	public function __construct( private array $params ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? ''; }
	public function get_header( string $key ): string { return ''; }
}
final class YandexPickupSelectionErrors {
	public array $errors = array();
	public function add( string $code, string $message ): void { $this->errors[ $code ] = $message; }
	public function has( string $code ): bool { return isset( $this->errors[ $code ] ); }
}
final class YandexPickupSelectionOrder {
	public array $meta = array();
	public string $shipping_address_1 = '';
	public string $shipping_city = '';
	public string $shipping_state = '';
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void {}
	public function set_shipping_city( string $value ): void { $this->shipping_city = $value; }
	public function set_shipping_state( string $value ): void { $this->shipping_state = $value; }
	public function set_shipping_postcode( string $value ): void {}
	public function set_shipping_country( string $value ): void {}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_location_mapping_v2 = array(
	array( 'location_id' => 10, 'yandex_geo_id' => 65, 'status' => 'mapped', 'is_primary' => 1 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 66, 'status' => 'manual', 'is_primary' => 0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 67, 'status' => 'needs_review', 'is_primary' => 0 ),
);
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'DST-A', 'operator_station_id' => 'OA', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Яндекс Ленина', 'yandex_geo_id' => 65, 'region' => 'Новосибирская обл.', 'locality' => 'Новосибирск', 'postal_code' => '630001', 'full_address' => 'Новосибирск, ул Ленина, 1', 'latitude' => 55.030199, 'longitude' => 82.92043, 'instruction' => 'Вход с улицы', 'schedule_text' => 'Пн-Вс 10-22', 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => 'DST-B', 'operator_station_id' => 'OB', 'operator_id' => '5post', 'type' => 'terminal', 'name' => '5Post Красный', 'yandex_geo_id' => 66, 'region' => 'Новосибирская обл.', 'locality' => 'Новосибирск', 'full_address' => 'Красный проспект, 2', 'latitude' => null, 'longitude' => null, 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => '', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Без station', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'DST-INACTIVE', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Закрытый', 'yandex_geo_id' => 65, 'active' => 0 ),
	array( 'platform_station_id' => 'DST-DROPOFF', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Без dropoff', 'yandex_geo_id' => 66, 'active' => 1, 'available_for_dropoff' => 0 ),
);
for ( $i = 1; $i <= 1005; ++$i ) {
	$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = array(
		'platform_station_id' => 'DST-BULK-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ),
		'operator_id' => 0 === $i % 2 ? 'market_l4g' : '5post',
		'type' => 0 === $i % 3 ? 'terminal' : 'pickup_point',
		'name' => 'Массовый ПВЗ ' . $i,
		'yandex_geo_id' => 0 === $i % 2 ? 65 : 66,
		'locality' => 'Новосибирск',
		'full_address' => 'Новосибирск, тестовая ' . $i,
		'available_for_dropoff' => 0,
		'active' => 1,
	);
}

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$mapping = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$formatter = new YandexDeliveryCheckoutPickupPointFormatter();
$geo_ids = $mapping->geo_ids_for_location( 10 );
$rows = $repository->destination_pickup_points_by_geo_ids( $geo_ids, 10 );
yandex_pickup_selection_assert( array( 65, 66 ) === $geo_ids, 'Yandex mapping must return all mapped/manual geo ids for location_id.' );
yandex_pickup_selection_assert( 1008 === count( $rows ), 'Yandex destination repository must return all active pickup/terminal rows from all geo ids without applying the legacy limit.' );
yandex_pickup_selection_assert( in_array( (string) ( $rows[0]['platform_station_id'] ?? '' ), array( 'DST-A', 'DST-DROPOFF' ), true ) && 'pickup_point' === (string) ( $rows[0]['type'] ?? '' ), 'Yandex destination repository must sort pickup_point before terminal and keep market_l4g priority.' );
yandex_pickup_selection_assert( null !== $repository->destination_pickup_point_by_platform_station_id( 'DST-DROPOFF' ), 'Yandex destination lookup must not require available_for_dropoff.' );
yandex_pickup_selection_assert( null === $repository->destination_pickup_point_by_platform_station_id( 'DST-INACTIVE' ), 'Yandex destination lookup must reject inactive rows.' );
$formatted = $formatter->format( $rows[0] );
yandex_pickup_selection_assert( YandexDeliverySettings::CARRIER_KEY === (string) $formatted['carrier_key'] && (string) ( $rows[0]['platform_station_id'] ?? '' ) === (string) $formatted['platform_station_id'] && (string) $formatted['platform_station_id'] === (string) $formatted['point_code'], 'Yandex formatter must emit common checkout pickup point identity.' );
yandex_pickup_selection_assert( ! array_key_exists( 'raw_json', $formatted ) && ! array_key_exists( 'raw_json', $formatted['snapshot'] ?? array() ), 'Yandex formatter must not expose raw_json.' );


$presentation_cases = array(
	array(
		'row' => array( 'platform_station_id' => 'TECH-5POST', 'operator_id' => '5post', 'type' => 'pickup_point', 'name' => 'Любое имя', 'full_address' => 'Адрес 5Post', 'active' => 1 ),
		'title' => '5 Post (Пятерочка)',
		'comment' => 'Цена будет пересчитана, иногда сюда получается дороже!',
	),
	array(
		'row' => array( 'platform_station_id' => 'TECH-MARKET', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Пункт выдачи заказов Яндекс Маркета', 'full_address' => 'Адрес Маркет', 'active' => 1 ),
		'title' => 'Пункт выдачи Яндекс.Маркет',
		'comment' => '',
	),
	array(
		'row' => array( 'platform_station_id' => 'TECH-PARTNER', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Пункт выдачи заказов партнёра', 'full_address' => 'Адрес партнера', 'active' => 1 ),
		'title' => 'Партнёрский пункт выдачи',
		'comment' => '',
	),
	array(
		'row' => array( 'platform_station_id' => 'TECH-TERMINAL', 'operator_id' => 'market_l4g', 'type' => 'terminal', 'name' => 'Другое имя', 'full_address' => 'Адрес терминал', 'active' => 1 ),
		'title' => 'Постамат Яндекса',
		'comment' => 'Срок хранения посылки - 2-3 дня!',
	),
	array(
		'row' => array( 'platform_station_id' => 'TECH-FALLBACK', 'operator_id' => 'other', 'type' => 'pickup_point', 'name' => 'Другое имя', 'full_address' => 'Адрес fallback', 'active' => 1 ),
		'title' => 'Выдача посылок Яндекс.Доставки',
		'comment' => '',
	),
);
foreach ( $presentation_cases as $case ) {
	$point = $formatter->format( $case['row'] );
	$station = (string) $case['row']['platform_station_id'];
	foreach ( array( 'point_title', 'card_title', 'display_title', 'title' ) as $field ) {
		yandex_pickup_selection_assert( $case['title'] === (string) ( $point[ $field ] ?? '' ), 'Yandex formatter must expose presentation title in ' . $field . '.' );
		yandex_pickup_selection_assert( ! str_contains( (string) ( $point[ $field ] ?? '' ), $station ), 'Yandex user-facing title field must not contain platform_station_id: ' . $field . '.' );
	}
	yandex_pickup_selection_assert( '' === (string) ( $point['display_code'] ?? 'not-empty' ) && '' === (string) ( $point['snapshot']['display_code'] ?? 'not-empty' ), 'Yandex formatter must keep display_code empty.' );
	yandex_pickup_selection_assert( $case['comment'] === (string) ( $point['presentation_comment'] ?? '' ) && $case['comment'] === (string) ( $point['snapshot']['presentation_comment'] ?? '' ), 'Yandex formatter must expose presentation_comment separately from description.' );
	yandex_pickup_selection_assert( $station === (string) ( $point['point_code'] ?? '' ) && $station === (string) ( $point['platform_station_id'] ?? '' ) && $station === (string) ( $point['snapshot']['platform_station_id'] ?? '' ), 'Yandex formatter must keep technical platform_station_id in identity fields.' );
}

$points_controller = new PickupPointsRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), null, null, null, null, $repository, $mapping, $formatter );
$points = $points_controller->points( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'location_id' => '10', 'limit' => '10' ) );
yandex_pickup_selection_assert( 1008 === count( $points ) && 'yandex_delivery:pickup' === (string) ( $points[0]['pickup_family'] ?? '' ), 'Pickup REST endpoint must ignore REST limit and return all Yandex points in common map/list shape.' );
$searched = $points_controller->search( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'location_id' => '10', 'q' => 'Красный' ) );
yandex_pickup_selection_assert( 1 === count( $searched ) && 'DST-B' === (string) ( $searched[0]['platform_station_id'] ?? '' ), 'Pickup REST endpoint must search Yandex points by address/name.' );

$session = new CheckoutSessionManager();
$pickup_rate_id = 'yandex_pickup';
$courier_rate_id = 'yandex_courier';
$session->save_rates( array(
	$pickup_rate_id => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'service_key' => YandexDeliverySettings::SERVICE_KEY, 'rate_id' => $pickup_rate_id, 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => true, 'rate_meta' => array( 'pickup_source' => 'representative' ) ),
	$courier_rate_id => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'service_key' => YandexDeliverySettings::SERVICE_KEY, 'rate_id' => $courier_rate_id, 'delivery_type' => DeliveryType::COURIER, 'requires_pickup_point' => false, 'requires_courier_address' => true ),
	'dpd:pickup:max' => array( 'carrier_key' => 'dpd', 'service_key' => 'dpd', 'rate_id' => 'dpd:pickup:max', 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => true ),
) );
$session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Новосибирск', 'region_name' => 'Новосибирская обл.', 'country_code' => 'RU' ) );
yandex_pickup_selection_assert( 'yandex_delivery:pickup' === $session->shipping_method_family( 'yandex_pickup' ), 'CheckoutSessionManager must map yandex_pickup to yandex_delivery:pickup.' );

$checkout_rest = new CheckoutPickupPointRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), $session, null, null, null, $repository, $formatter, null, null, new WooCommerceSessionBootstrapper() );
$save_response = $checkout_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'DST-A' ) ) );
yandex_pickup_selection_assert( 'DST-A' === (string) ( $save_response['pickup_point']['platform_station_id'] ?? '' ), 'Checkout save endpoint must persist selected Yandex platform_station_id.' );
yandex_pickup_selection_assert( 'yandex_delivery:pickup' === (string) ( $save_response['active_pickup_family'] ?? '' ), 'Checkout save endpoint must store Yandex selection in yandex_delivery:pickup bucket.' );
$resolve = $checkout_rest->resolve_location( new YandexPickupSelectionRequest( array( 'point' => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'point_code' => 'DST-A' ) ) ) );
yandex_pickup_selection_assert( false === (bool) ( $resolve['requires_location_change'] ?? true ) && null === ( $resolve['location'] ?? null ), 'Yandex resolve_location must skip Russian Post location resolver path.' );
$shipping_method_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/NewShippingMethod.php' );
yandex_pickup_selection_assert( str_contains( $shipping_method_source, "'pickup_selections' => \$pickup_selections" ) && str_contains( $shipping_method_source, 'pickup_selections_for_current_destination( true )' ), 'NewShippingMethod must pass current-destination family-specific pickup_selections into QuoteRequest customer context.' );

$validation = new CheckoutValidation( $session, new CheckoutAddressValidation( $session ), null, null, $repository, $formatter );
$errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск' ), $errors );
yandex_pickup_selection_assert( false === $errors->has( 'wdc_pickup_required' ), 'Yandex pickup validation must pass with selected Yandex PVZ.' );
$session->clear_pickup_selection( 'test' );
$missing_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск' ), $missing_errors );
yandex_pickup_selection_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === (string) ( $missing_errors->errors['wdc_pickup_required'] ?? '' ), 'Yandex pickup validation must fail without selected Yandex PVZ.' );
$wrong_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск', 'wdc_pickup_point_code' => 'NSK-PS-1', 'wdc_pickup_carrier_key' => 'dpd', 'wdc_pickup_family' => 'dpd:pickup' ), $wrong_errors );
yandex_pickup_selection_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === (string) ( $wrong_errors->errors['wdc_pickup_required'] ?? '' ), 'Yandex pickup validation must reject pickup selected for another carrier.' );
$courier_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $courier_rate_id ), 'shipping_city' => 'Новосибирск', 'shipping_address_1' => 'ул Ленина, 1' ), $courier_errors );
yandex_pickup_selection_assert( false === $courier_errors->has( 'wdc_pickup_required' ), 'Yandex courier must not require pickup point.' );

$client_old_timestamp = '2020-01-01T00:00:00+00:00';
$fivepost_response = $checkout_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'DST-B', 'selection_intent' => 'explicit', 'point' => array( 'point_code' => 'DST-B', 'selected_at' => $client_old_timestamp ) ) ) );
$explicit_selected_at = (string) ( $fivepost_response['pickup_point']['selected_at'] ?? '' );
yandex_pickup_selection_assert( 'DST-B' === (string) ( $fivepost_response['pickup_point']['platform_station_id'] ?? '' ), '5Post must use the common Yandex pickup save flow without losing platform_station_id.' );
yandex_pickup_selection_assert( false !== strtotime( $explicit_selected_at ) && abs( time() - (int) strtotime( $explicit_selected_at ) ) <= 10, 'Explicit selected_at must match current server UTC time.' );
yandex_pickup_selection_assert( '5post' === (string) ( $fivepost_response['pickup_point']['operator_id'] ?? '' ) && '5post' === (string) ( $fivepost_response['pickup_point']['snapshot']['operator_id'] ?? '' ) && '' !== (string) ( $fivepost_response['pickup_point']['selected_at'] ?? '' ) && $client_old_timestamp !== (string) ( $fivepost_response['pickup_point']['selected_at'] ?? '' ), 'Explicit REST save must keep 5Post identity and replace any client timestamp with a new server UTC selected_at.' );
yandex_pickup_selection_assert( '5post' === (string) ( $fivepost_response['pickup_point']['operator_id'] ?? $fivepost_response['pickup_point']['snapshot']['operator_id'] ?? '' ), 'The saved Yandex pickup regression point must remain identified as 5Post in its safe presentation payload.' );
$fivepost_current_day = $session->pickup_selection_for_family( 'yandex_delivery:pickup' );
$fivepost_current_day['selected_at'] = '2026-07-10T22:00:00+00:00';
$session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $fivepost_current_day );
$technical_response = $checkout_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'shipping_method_id' => 'wdc_platform:yandex_pickup', 'point_code' => 'DST-B', 'selection_intent' => 'technical', 'point' => array( 'point_code' => 'DST-B', 'selected_at' => '2026-07-11T12:00:00+00:00' ) ) ) );
yandex_pickup_selection_assert( '2026-07-10T22:00:00+00:00' === (string) ( $technical_response['pickup_point']['selected_at'] ?? '' ) && '5post' === (string) ( $technical_response['pickup_point']['operator_id'] ?? '' ) && 'DST-B' === (string) ( $technical_response['pickup_point']['platform_station_id'] ?? '' ) && 'yandex_pickup' === (string) ( $technical_response['pickup_point']['rate_id'] ?? '' ), 'Technical REST save must preserve existing selected_at, operator and station while allowing normalized rate-id synchronization.' );
$session->save_pickup_selection_for_family( 'dpd:pickup', array( 'carrier_key' => 'dpd', 'service_key' => 'dpd', 'pickup_family' => 'dpd:pickup', 'point_code' => 'DPD-OTHER', 'platform_station_id' => '' ) );
$saved_yandex = $session->pickup_selection_for_family( 'yandex_delivery:pickup' );
yandex_pickup_selection_assert( 'dpd' === (string) ( $session->pickup_selection()['carrier_key'] ?? '' ) && 'DST-B' === (string) ( $saved_yandex['point_code'] ?? '' ) && 'DST-B' === (string) ( $saved_yandex['platform_station_id'] ?? '' ), 'A global selection from another carrier must not overwrite the saved 5Post Yandex family selection.' );
$fivepost_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск', 'wdc_pickup_point_code' => 'DST-B', 'wdc_pickup_carrier_key' => 'yandex_delivery', 'wdc_pickup_family' => 'yandex_delivery:pickup' ), $fivepost_errors );
yandex_pickup_selection_assert( false === $fivepost_errors->has( 'wdc_pickup_required' ), 'Active Yandex POST and family-specific 5Post selection must pass checkout validation.' );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_rate_id ) );
$order = new YandexPickupSelectionOrder();
( new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $order, array() );
yandex_pickup_selection_assert( 'DST-B' === (string) ( $order->meta['_wdc_pickup_point_code'] ?? '' ) && 'DST-B' === (string) ( $order->meta['_wdc_pickup_platform_station_id'] ?? '' ), 'Order meta must save canonical selected 5Post point code/platform_station_id.' );
yandex_pickup_selection_assert( 'yandex_delivery:pickup' === (string) ( $order->meta['_wdc_pickup_family'] ?? '' ), 'Hidden order meta must preserve the selected Yandex pickup family.' );
yandex_pickup_selection_assert( 'DST-B' === (string) ( $order->meta['_wdc_yandex_delivery_pickup_platform_station_id'] ?? '' ), 'Order meta must save the 5Post platform_station_id through the Yandex alias.' );
yandex_pickup_selection_assert( '5Post Красный' === (string) ( $order->meta['_wdc_yandex_delivery_pickup_name'] ?? '' ) && 'Красный проспект, 2' === (string) ( $order->meta['_wdc_yandex_delivery_pickup_address'] ?? '' ), 'Order meta must save selected 5Post name/address through the common Yandex flow.' );
yandex_pickup_selection_assert( ! str_contains( (string) ( $order->meta['_wdc_pickup_point_snapshot'] ?? '' ), 'raw_json' ), 'Order meta must not save raw Yandex JSON.' );
$calculation = is_array( $order->meta[OrderShippingMetaPersister::CALCULATION_META_KEY] ?? null ) ? $order->meta[OrderShippingMetaPersister::CALCULATION_META_KEY] : array();
yandex_pickup_selection_assert( 'DST-B' === (string) ( $calculation['pickup']['platform_station_id'] ?? '' ) && 'yandex_delivery:pickup' === (string) ( $calculation['pickup']['pickup_family'] ?? '' ), 'Calculation meta pickup block must preserve selected 5Post station and Yandex family.' );

$selection_for_day = static function ( string $operator_id, string $selected_at, string $station_id ): array {
	return array(
		'carrier_key' => 'yandex_delivery',
		'service_key' => 'yandex_delivery',
		'pickup_family' => 'yandex_delivery:pickup',
		'rate_id' => 'yandex_pickup',
		'id' => 'yandex_delivery:' . $station_id,
		'point_code' => $station_id,
		'platform_station_id' => $station_id,
		'operator_id' => $operator_id,
		'selected_at' => $selected_at,
		'point_address' => 'Москва, тестовый ПВЗ',
		'address' => 'Москва, тестовый ПВЗ',
		'snapshot' => array(
			'carrier_key' => 'yandex_delivery',
			'pickup_family' => 'yandex_delivery:pickup',
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'operator_id' => $operator_id,
			'address' => 'Москва, тестовый ПВЗ',
		),
	);
};

$technical_missing_session = new CheckoutSessionManager();
$technical_missing_session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
$missing_timestamp_selection = $selection_for_day( '5post', '', 'DST-B' );
$technical_missing_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $missing_timestamp_selection );
$technical_missing_rest = new CheckoutPickupPointRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), $technical_missing_session, null, null, null, $repository, $formatter, null, null, new WooCommerceSessionBootstrapper() );
$technical_missing_response = $technical_missing_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => 'yandex_delivery', 'shipping_method_id' => 'yandex_pickup', 'point_code' => 'DST-B', 'selection_intent' => 'technical' ) ) );
yandex_pickup_selection_assert( ! array_key_exists( 'selected_at', $technical_missing_response['pickup_point'] ?? array() ), 'Technical save without an existing/incoming timestamp must not create selected_at.' );
yandex_pickup_selection_assert( true === $technical_missing_session->expire_stale_yandex_5post_selection() && array() === $technical_missing_session->pickup_selection_for_family( 'yandex_delivery:pickup' ), '5Post technical save without selected_at must remain eligible for normal expiration.' );

$client_extension_session = new CheckoutSessionManager();
$client_extension_session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
$client_extension_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $selection_for_day( '5post', '2026-07-10T20:00:00+00:00', 'DST-B' ) );
$client_extension_rest = new CheckoutPickupPointRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), $client_extension_session, null, null, null, $repository, $formatter, null, null, new WooCommerceSessionBootstrapper() );
$client_extension_response = $client_extension_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => 'yandex_delivery', 'shipping_method_id' => 'yandex_pickup', 'point_code' => 'DST-B', 'selection_intent' => 'technical', 'point' => array( 'point_code' => 'DST-B', 'selected_at' => '2026-07-11T12:00:00+00:00' ) ) ) );
yandex_pickup_selection_assert( '2026-07-10T20:00:00+00:00' === (string) ( $client_extension_response['pickup_point']['selected_at'] ?? '' ), 'Incoming technical payload must not override the existing family selected_at.' );
yandex_pickup_selection_assert( true === $client_extension_session->expire_stale_yandex_5post_selection(), 'A client extension attempt must not prevent yesterday 5Post from expiring.' );
$explicit_repeat_response = $client_extension_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => 'yandex_delivery', 'shipping_method_id' => 'yandex_pickup', 'point_code' => 'DST-B', 'selection_intent' => 'explicit', 'point' => array( 'point_code' => 'DST-B', 'selected_at' => '2026-07-10T20:00:00+00:00' ) ) ) );
$explicit_repeat_at = (string) ( $explicit_repeat_response['pickup_point']['selected_at'] ?? '' );
yandex_pickup_selection_assert( '' !== $explicit_repeat_at && '2026-07-10T20:00:00+00:00' !== $explicit_repeat_at, 'A real repeated explicit selection must receive a new server selected_at.' );
$previous_test_now = $GLOBALS['wdc_yandex_test_current_datetime'];
$GLOBALS['wdc_yandex_test_current_datetime'] = ( new DateTimeImmutable( $explicit_repeat_at ) )->setTimezone( new DateTimeZone( 'Europe/Moscow' ) );
yandex_pickup_selection_assert( false === $client_extension_session->expire_stale_yandex_5post_selection(), 'A newly repeated explicit 5Post selection must remain current on its WordPress calendar day.' );
$GLOBALS['wdc_yandex_test_current_datetime'] = $previous_test_now;

$today_session = new CheckoutSessionManager();
$today_session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
$today_selection = $selection_for_day( '5post', '2026-07-10T22:00:00+00:00', 'DST-TODAY' );
$today_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $today_selection );
yandex_pickup_selection_assert( false === $today_session->expire_stale_yandex_5post_selection() && 'DST-TODAY' === (string) ( $today_session->pickup_selection_for_family( 'yandex_delivery:pickup' )['platform_station_id'] ?? '' ), 'A 5Post selection whose UTC timestamp maps to today in Moscow must remain selected.' );
$today_session->update_pickup_selection_rate_id( 'wdc_platform:yandex_pickup' );
yandex_pickup_selection_assert( '2026-07-10T22:00:00+00:00' === (string) ( $today_session->pickup_selection_for_family( 'yandex_delivery:pickup' )['selected_at'] ?? '' ), 'Technical rate-id rebinding must not refresh selected_at.' );

$warning_method = (object) array(
	'id' => 'wdc_platform:yandex_pickup',
	'meta_data' => array(
		'carrier_key' => 'yandex_delivery',
		'rate_id' => 'yandex_pickup',
		'delivery_type' => 'pickup',
		'pickup_family' => 'yandex_delivery:pickup',
		'comments' => array( 'Обычный комментарий' ),
	),
);
ob_start();
( new CheckoutRateRenderer( $today_session ) )->render( $warning_method );
$warning_html = (string) ob_get_clean();
$comment_position = strpos( $warning_html, 'Обычный комментарий' );
$warning_position = strpos( $warning_html, 'wdc-yandex-5post-warning' );
yandex_pickup_selection_assert( false !== $comment_position && false !== $warning_position && $comment_position < $warning_position && str_contains( $warning_html, 'При выборе 5Post цена доставки могла стать дороже. Попробуйте выбрать другой ПВЗ' ), 'Current 5Post warning must render with exact text after ordinary rate comments.' );

$yesterday_session = new CheckoutSessionManager();
$yesterday_session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
$yesterday_session->save_pickup_selection_for_family( 'dpd:pickup', array( 'carrier_key' => 'dpd', 'pickup_family' => 'dpd:pickup', 'point_code' => 'DPD-KEPT', 'point_address' => 'DPD address' ) );
$yesterday_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $selection_for_day( '5post', '2026-07-10T20:00:00+00:00', 'DST-YESTERDAY' ) );
yandex_pickup_selection_assert( true === $yesterday_session->expire_stale_yandex_5post_selection(), 'A 5Post selection from the previous Moscow calendar day must expire.' );
yandex_pickup_selection_assert( array() === $yesterday_session->pickup_selection_for_family( 'yandex_delivery:pickup' ) && 'DPD-KEPT' === (string) ( $yesterday_session->pickup_selection_for_family( 'dpd:pickup' )['point_code'] ?? '' ), 'Expiration must clear only yandex_delivery:pickup and preserve other family buckets.' );

$market_session = new CheckoutSessionManager();
$market_session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
$market_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $selection_for_day( 'market_l4g', '2026-07-09T20:00:00+00:00', 'DST-MARKET' ) );
yandex_pickup_selection_assert( false === $market_session->expire_stale_yandex_5post_selection() && 'DST-MARKET' === (string) ( $market_session->pickup_selection_for_family( 'yandex_delivery:pickup' )['platform_station_id'] ?? '' ), 'market_l4g selections must not expire based on selected_at.' );
ob_start();
( new CheckoutRateRenderer( $market_session ) )->render( $warning_method );
$market_html = (string) ob_get_clean();
yandex_pickup_selection_assert( ! str_contains( $market_html, 'wdc-yandex-5post-warning' ), 'market_l4g must not render the 5Post warning.' );
$courier_method = (object) array( 'id' => 'wdc_platform:yandex_courier', 'meta_data' => array( 'carrier_key' => 'yandex_delivery', 'rate_id' => 'yandex_courier', 'delivery_type' => 'courier', 'pickup_family' => 'yandex_delivery:courier' ) );
ob_start();
( new CheckoutRateRenderer( $today_session ) )->render( $courier_method );
$courier_html = (string) ob_get_clean();
yandex_pickup_selection_assert( ! str_contains( $courier_html, 'wdc-yandex-5post-warning' ), 'Yandex courier must not render the 5Post pickup warning.' );

$missing_time_session = new CheckoutSessionManager();
$missing_time_session->save_pickup_selection_for_family( 'dpd:pickup', array( 'carrier_key' => 'dpd', 'pickup_family' => 'dpd:pickup', 'point_code' => 'DPD-STILL-KEPT' ) );
$missing_time_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $selection_for_day( '5post', '', 'DST-NO-TIME' ) );
yandex_pickup_selection_assert( true === $missing_time_session->expire_stale_yandex_5post_selection() && 'DPD-STILL-KEPT' === (string) ( $missing_time_session->pickup_selection_for_family( 'dpd:pickup' )['point_code'] ?? '' ), 'A 5Post selection without selected_at must expire without clearing other families.' );

$stale_post_session = new CheckoutSessionManager();
$stale_post_session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
$stale_post_session->save_rates( array( 'yandex_pickup' => array( 'carrier_key' => 'yandex_delivery', 'rate_id' => 'yandex_pickup', 'delivery_type' => 'pickup', 'requires_pickup_point' => true, 'pickup_family' => 'yandex_delivery:pickup' ) ) );
$stale_post_session->save_pickup_selection_for_family( 'dpd:pickup', array( 'carrier_key' => 'dpd', 'pickup_family' => 'dpd:pickup', 'point_code' => 'DPD-POST-KEPT' ) );
$stale_post_session->save_pickup_selection_for_family( 'yandex_delivery:pickup', $selection_for_day( '5post', '2026-07-10T20:00:00+00:00', 'DST-B' ) );
$stale_validation = new CheckoutValidation( $stale_post_session, new CheckoutAddressValidation( $stale_post_session ), null, null, $repository, $formatter );
$stale_post = array( 'shipping_method' => array( 'yandex_pickup' ), 'shipping_city' => 'Москва', 'wdc_pickup_point_code' => 'DST-B', 'wdc_pickup_carrier_key' => 'yandex_delivery', 'wdc_pickup_family' => 'yandex_delivery:pickup' );
$_POST = $stale_post;
$stale_validation->preload_from_post();
$stale_errors = new YandexPickupSelectionErrors();
$stale_validation->validate( $stale_post, $stale_errors );
yandex_pickup_selection_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === (string) ( $stale_errors->errors['wdc_pickup_required'] ?? '' ), 'Stale 5Post hidden fields must not restore the expired selection during the same checkout request.' );
yandex_pickup_selection_assert( array() === $stale_post_session->pickup_selection_for_family( 'yandex_delivery:pickup' ) && 'DPD-POST-KEPT' === (string) ( $stale_post_session->pickup_selection_for_family( 'dpd:pickup' )['point_code'] ?? '' ), 'Midnight validation must keep Yandex expired and preserve other family selections.' );
$_POST = array();

$shipping_method_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/NewShippingMethod.php' );
$expiration_position = strpos( $shipping_method_source, 'expire_stale_yandex_5post_selection()' );
$context_position = strpos( $shipping_method_source, 'pickup_selections_for_current_destination( true )' );
yandex_pickup_selection_assert( false !== $expiration_position && false !== $context_position && $expiration_position < $context_position, 'NewShippingMethod must expire stale 5Post before QuoteRequest receives pickup selections, allowing representative pricing fallback.' );
$pickup_map_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/PickupMapCheckout.php' );
yandex_pickup_selection_assert( str_contains( $pickup_map_source, 'expire_stale_yandex_5post_selection()' ), 'PickupMapCheckout must expire stale 5Post before localizing selected pickup UI state.' );
$checkout_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-rates.css' );
yandex_pickup_selection_assert( str_contains( $checkout_css, '.wdc-yandex-5post-warning' ), 'Checkout styles must define a dedicated 5Post warning block.' );
echo "Yandex Delivery checkout pickup selection smoke test passed.\n";
