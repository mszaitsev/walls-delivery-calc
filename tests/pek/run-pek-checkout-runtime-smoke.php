<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Checkout\PekCheckoutQuoteContextResolver;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\Pek\Quote\PekLightCargoSurchargePolicy;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteMessageSanitizer;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuotePlannedDateTimeResolver;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteResponseParser;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
use WallsShop\WDC\Carriers\Runtime\PekCarrier;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\CheckoutPickupPointProviderQueryResolver;

function pek_checkout_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_checkout_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_checkout_options'][ $option ] = $value; return true; }
function current_time( string $type ): string { return 'mysql' === $type ? '2026-08-04 12:00:00' : '2026-08-04'; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( $GLOBALS['pek_checkout_current_datetime'] ?? '2026-08-04 12:07:00', new DateTimeZone( 'UTC' ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function get_transient( string $key ): mixed { return $GLOBALS['pek_checkout_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['pek_checkout_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_checkout_transients'][ $key ] ); return true; }
function wc_get_logger(): object { return $GLOBALS['pek_checkout_wc_logger']; }

if ( ! class_exists( 'WC_Session_Handler' ) ) {
	class WC_Session_Handler {
		/** @var array<string,mixed> */
		public static array $persisted_data = array();
		/** @var array<string,mixed> */
		public array $data = array();
		public bool $initialized = false;
		public bool $cookie_set = false;
		public function init(): void { $this->initialized = true; $this->data = self::$persisted_data; }
		public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; self::$persisted_data[ $key ] = $value; }
		public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
		public function save_data(): void { self::$persisted_data = $this->data; }
		public function set_customer_session_cookie( bool $set ): void { $this->cookie_set = $set; }
	}
}

final class PekCheckoutFakeWooLogger {
	public array $entries = array();
	public function log( string $level, string $message, array $context = array() ): void {
		$this->entries[] = compact( 'level', 'message', 'context' );
	}
}

final class PekCheckoutFakeSession {
	public array $data = array();
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function save_data(): void {}
}

final class PekCheckoutFakeWoo {
	public mixed $session;
	public function __construct() {
		$this->session = new PekCheckoutFakeSession();
	}
}

function WC(): PekCheckoutFakeWoo {
	$GLOBALS['pek_checkout_wc'] ??= new PekCheckoutFakeWoo();
	return $GLOBALS['pek_checkout_wc'];
}

final class PekCheckoutFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args ): array {
		$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$this->requests[] = array( 'method' => strtoupper( $method ), 'url' => $url, 'body' => is_array( $body ) ? $body : array(), 'args' => $args );
		$response = array_shift( $this->responses ) ?? array();
		if ( is_array( $response ) && array_key_exists( 'status', $response ) && array_key_exists( 'body', $response ) ) {
			return array( 'status' => (int) $response['status'], 'body' => wp_json_encode( $response['body'], JSON_UNESCAPED_UNICODE ) ?: '{}' );
		}

		return array( 'status' => 200, 'body' => wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) ?: '{}' );
	}
}

final class PekCheckoutFakeProvider implements CarrierPickupPointProviderInterface {
	public array $queries = array();
	/** @param array<int,PickupPoint> $points */
	public function __construct( private array $points ) {}
	public function carrier_key(): string { return PekSettings::CARRIER_KEY; }
	public function search( CarrierPickupPointQuery $query ): array {
		$this->queries[] = $query;
		return $this->points;
	}
	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint {
		foreach ( $this->points as $point ) {
			if ( $point->code === $query->point_code ) {
				return $point;
			}
		}

		return null;
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $pek_location_mappings = array();
		public string $last_error = '';
		public bool $pek_location_mapping_insert_fails = false;
		public bool $pek_location_mapping_update_fails = false;
		public bool $pek_location_mapping_read_fails = false;
		public bool $pek_location_mapping_delete_fails = false;
		public bool $pek_location_mapping_statistics_fails = false;
	}
}
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

function pek_checkout_location_rows(): array {
	if ( isset( $GLOBALS['pek_checkout_location_rows'] ) && is_array( $GLOBALS['pek_checkout_location_rows'] ) ) {
		return $GLOBALS['pek_checkout_location_rows'];
	}

	return array(
		array(
			'id' => 153912,
			'country_code' => 'RU',
			'region_name' => 'Москва',
			'region_type' => 'г',
			'city_name' => 'Москва',
			'city_type' => 'г',
			'place_name' => 'Москва',
			'place_type' => 'г',
			'display_name' => 'Москва',
			'latitude' => 55.755864,
			'longitude' => 37.617698,
			'active' => 1,
			'fias_id' => 'moscow-fias',
			'gar_object_id' => 153912,
			'region_code' => '77',
		),
	);
}

function pek_checkout_zone_response(): array {
	return array(
		array(
			'zoneId' => 'moscow-zone',
			'zoneName' => 'Москва Садовое кольцо',
			'branchUID' => 'moscow-east',
			'branchTitle' => 'Москва Восток',
			'mainWarehouseId' => 'main-wh',
			'warehousePoint' => array( 'latitude' => 55.7, 'longitude' => 37.7 ),
		),
	);
}

function pek_checkout_address_zone_response(): array {
	return array(
		'zoneId' => 'moscow-zone',
		'zoneName' => 'Москва Садовое кольцо',
		'branchUID' => 'moscow-east',
		'branchTitle' => 'Москва Восток',
		'mainWarehouseId' => 'main-wh',
		'GeoData' => array(
			'precision' => 'exact',
			'Address' => array(
				'formatted' => 'Россия, Москва',
				'country_code' => 'RU',
			),
		),
	);
}

function pek_checkout_calc_response( float $cost, int $days = 4 ): array {
	return array(
		'hasError' => false,
		'currencyCode' => '643',
		'branchSenderUID' => 'sender-branch',
		'branchSender' => 'Новосибирск',
		'branchReceiverUID' => 'receiver-branch',
		'branchReceiver' => 'Москва',
		'transfers' => array(
			array(
				'type' => 3,
				'hasError' => false,
				'costTotal' => $cost,
				'estDeliveryTime' => $days,
				'services' => array(
					array( 'serviceType' => 'Перевозка', 'senderCity' => 'Новосибирск', 'cost' => $cost, 'info' => 'Автоперевозка', 'services' => null ),
				),
			),
		),
	);
}

function pek_checkout_point( string $code, string $source = 'free', string $division_name = '' ): PickupPoint {
	return new PickupPoint(
		PekSettings::CARRIER_KEY,
		$code,
		'Россия, Москва, терминал ' . $code,
		'',
		'Москва',
		'',
		55.75,
		37.61,
		'terminal',
		'Пн-Пт 09:00-18:00',
		'',
		null,
		true,
		array( 'source' => $source, 'point_type_label' => 'Терминал', 'division_name' => $division_name )
	);
}

function pek_checkout_boot( array $responses, array $points ): array {
	$GLOBALS['pek_checkout_options'] = array();
	$GLOBALS['pek_checkout_transients'] = array();
	$GLOBALS['pek_checkout_wc_logger'] = new PekCheckoutFakeWooLogger();
	defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'pek-checkout-test-key' );
	$wpdb = new wpdb();
	$wpdb->locations = pek_checkout_location_rows();
	$repository = new SettingsRepository();
	$settings = new PekSettings( $repository );
	$credentials = new PekCredentials( $repository, new EncryptionService() );
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'checkout-login', 'pek_api_key' => 'checkout-secret' ) );
	$settings->save_from_admin( array( PekSettings::SENDER_INN_KEY => '5400000000', PekSettings::SENDER_KPP_KEY => '540001001', PekSettings::CLIENT_CARD_KEY => 'card-secret' ) );
	$repository->set( PekSettings::SENDER_WAREHOUSE_KEY, array( 'warehouseId' => 'sender-wh', 'source' => 'free', 'branchTimezone' => 'UTC' ) );
	$http = new PekCheckoutFakeHttp( $responses );
	$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
	$address_builder = new PekAddressBuilder();
	$resolver = new PekLocationResolver( new LocationRepository( $wpdb ), $address_builder, new PekLocationMappingRepository( $wpdb ), $api, $settings );
	$provider = new PekCheckoutFakeProvider( $points );
	$providers = new CarrierPickupPointProviderRegistry( array( $provider ) );
	$planned = new PekQuotePlannedDateTimeResolver( $settings );
	$formatter = new PekCheckoutPickupPointFormatter();
	$context = new PekCheckoutQuoteContextResolver( $settings, new LocationRepository( $wpdb ), $resolver, $address_builder, $providers, $planned, $formatter );
	$quote_service = new PekQuoteService( $credentials, $api, new PekQuoteRequestBuilder( $settings, new PekQuoteCargoBuilder() ), new PekQuoteResponseParser(), new PekQuoteMessageSanitizer( $credentials, $settings ), new PekLightCargoSurchargePolicy( $settings ), new Logger() );
	$carrier = new PekCarrier( $settings, $credentials, $context, $quote_service, $planned, new Logger() );

	return array( $carrier, $http, $provider );
}

function pek_checkout_request( array $context = array(), ?Address $address = null, int $weight_g = 1000, int $packaging_weight_g = 0 ): QuoteRequest {
	$declared = Money::from_kopecks( 100000 );
	return new QuoteRequest(
		'RU',
		$address ?? new Address( country_code: 'RU', city: 'Москва', raw_address: '', normalized: true ),
		new Package( array(), $declared, $declared, $weight_g, $packaging_weight_g, $weight_g + $packaging_weight_g, 10, 10, 10, 1000, 'cart' ),
		'',
		$declared,
		'2026-08-04',
		array_merge( array( 'selected_location_id' => 153912 ), $context )
	);
}

function pek_checkout_calc_payloads( PekCheckoutFakeHttp $http ): array {
	return array_values( array_filter(
		array_map( static fn( array $request ): array => $request['body'], $http->requests ),
		static fn( array $body ): bool => isset( $body['cargos'] )
	) );
}

function pek_checkout_stored_rate_from_mapper( DeliveryRate $rate ): array {
	$mapped = ( new WooCommerceRateMapper() )->map( $rate );

	return array_merge(
		$mapped['meta_data'],
		array(
			'rate_id' => $rate->rate_id,
			'label' => $mapped['label'],
			'cost' => $mapped['cost'],
			'planned_delivery_comment' => $rate->planned_delivery_comment,
			'delivery_days' => $rate->delivery_days->to_array(),
			'fallback_used' => false,
			'service_title' => $rate->service_name,
		)
	);
}

function pek_checkout_resolver_with_rate( array $stored_rate ): CheckoutPickupPointProviderQueryResolver {
	$GLOBALS['pek_checkout_wc'] = new PekCheckoutFakeWoo();
	$session = new CheckoutSessionManager();
	$session->save_rates( array( PekSettings::PICKUP_RATE_ID => $stored_rate ) );

	return new CheckoutPickupPointProviderQueryResolver( $session );
}

list( $carrier, $http, $provider ) = pek_checkout_boot(
	array( pek_checkout_zone_response(), pek_checkout_calc_response( 1000.00 ), pek_checkout_calc_response( 2000.00 ) ),
	array( pek_checkout_point( 'main-wh' ), pek_checkout_point( 'paid-wh', 'paid' ) )
);

$identity = $carrier->get_identity();
pek_checkout_assert( 'pek' === $identity->key && 'ПЭК' === $identity->name && $identity->enabled, 'PekCarrier identity must be enabled when credentials are complete.' );
$capabilities = $carrier->get_capabilities();
pek_checkout_assert( $capabilities->supports_quotes && $capabilities->supports_pickup_delivery && $capabilities->supports_courier_delivery && ! $capabilities->supports_status_sync && ! $capabilities->supports_international, 'PekCarrier capabilities must match checkout runtime scope.' );
pek_checkout_assert( $carrier->supports_country( 'RU' ) && ! $carrier->supports_country( 'KZ' ), 'PekCarrier checkout runtime must be RU-only.' );

$quote = $carrier->quote( pek_checkout_request() );
pek_checkout_assert( $quote->success && 2 === count( $quote->rates ), 'One PEK quote call must return pickup and courier rates when both modes succeed.' );
$pickup = $quote->rates[0];
$courier = $quote->rates[1];
pek_checkout_assert( PekSettings::PICKUP_RATE_ID === $pickup->rate_id && PekSettings::COURIER_RATE_ID === $courier->rate_id, 'PEK rates must use stable rate IDs.' );
pek_checkout_assert( DeliveryType::PICKUP === $pickup->delivery_type && DeliveryType::COURIER === $courier->delivery_type, 'PEK rates must expose canonical delivery types.' );
pek_checkout_assert( $pickup->requires_pickup_point && ! $pickup->requires_courier_address && ! $courier->requires_pickup_point && $courier->requires_courier_address, 'PEK pickup/courier requirement flags must be canonical.' );
pek_checkout_assert( 109000 === $pickup->price->get_kopecks() && 209000 === $courier->price->get_kopecks(), 'PEK DeliveryRate price must use final adjusted quote price.' );
pek_checkout_assert( 100000 === (int) $pickup->meta['pek_carrier_price_kopecks'] && 7000 === (int) $pickup->meta['pek_bag_surcharge_kopecks'] && 2000 === (int) $pickup->meta['pek_sealing_surcharge_kopecks'], 'PEK rate meta must preserve carrier price and store surcharges separately.' );
pek_checkout_assert( PekSettings::PICKUP_FAMILY === (string) $pickup->meta['pickup_family'] && is_array( $pickup->meta['pickup_provider_query'] ?? null ), 'PEK pickup rate must carry trusted provider query snapshot.' );
pek_checkout_assert( (string) $pickup->meta['pickup_provider_query']['destination_fingerprint'] === (string) $pickup->meta['pickup_provider_query']['provider_destination_fingerprint'], 'PEK provider query snapshot must expose provider_destination_fingerprint alongside legacy destination_fingerprint.' );
$formatter_smoke = new PekCheckoutPickupPointFormatter();
$uuid = '8c4eeef5-1f90-11f1-b8ab-00155d24b451';
$free_formatted = $formatter_smoke->format( pek_checkout_point( 'free-wh', 'free', $uuid ), 'provider-fp', 153912, 'RU' );
$paid_formatted = $formatter_smoke->format( pek_checkout_point( 'paid-wh', 'paid', $uuid ), 'provider-fp', 153912, 'RU' );
pek_checkout_assert( 'Собственный пункт выдачи ПЭК' === (string) $free_formatted['point_title'] && 'Собственный пункт выдачи ПЭК' === (string) $free_formatted['point_type_label'] && '' === (string) $free_formatted['presentation_comment'], 'PEK free terminal presentation must use own pickup title without surcharge warning.' );
pek_checkout_assert( 'Партнерский пункт выдачи ПЭК' === (string) $paid_formatted['point_title'] && 'Партнерский пункт выдачи ПЭК' === (string) $paid_formatted['point_type_label'] && 'Возможна небольшая доплата за доставку в этот пункт' === (string) $paid_formatted['presentation_comment'], 'PEK paid terminal presentation must use partner title and warning.' );
pek_checkout_assert( '' === (string) $paid_formatted['point_name'] && '' === (string) $paid_formatted['snapshot']['point_name'] && ! str_contains( wp_json_encode( $paid_formatted, JSON_UNESCAPED_UNICODE ) ?: '', $uuid ), 'PEK formatter must not expose internal UUID as public point name.' );
pek_checkout_assert( 'paid-wh' === (string) $paid_formatted['point_code'] && 'provider-fp' === (string) $paid_formatted['provider_destination_fingerprint'], 'PEK formatter must keep technical warehouse code and provider fingerprint.' );
$stored_pickup_rate = pek_checkout_stored_rate_from_mapper( $pickup );
pek_checkout_assert( is_array( $stored_pickup_rate['rate_meta'] ?? null ) && ! isset( $stored_pickup_rate['meta'] ), 'Realistic WooCommerce stored PEK rate must expose nested rate_meta, not fake-only meta.' );
$query_resolver = pek_checkout_resolver_with_rate( $stored_pickup_rate );
$trusted_query = $query_resolver->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
pek_checkout_assert( PekSettings::CARRIER_KEY === $trusted_query->carrier_key && 153912 === $trusted_query->location_id && 'RU' === $trusted_query->country_code && 1000 === $trusted_query->cargo->weight_g && 1 === $trusted_query->cargo->places_count, 'Production WooCommerce stored PEK rate must resolve trusted provider query from rate_meta.' );
pek_checkout_assert( '' !== $query_resolver->destination_fingerprint( PekSettings::PICKUP_RATE_ID ), 'Production PEK stored pickup rate must expose non-empty destination fingerprint.' );
$GLOBALS['pek_checkout_wc'] = new PekCheckoutFakeWoo();
$checkout_lifecycle_session = new CheckoutSessionManager();
$checkout_lifecycle_session->save_rates( array( PekSettings::PICKUP_RATE_ID => $stored_pickup_rate ) );
WC_Session_Handler::$persisted_data = WC()->session->data;
WC()->session = null;
$bootstrapper = new WooCommerceSessionBootstrapper();
pek_checkout_assert( $bootstrapper->ensure() && WC()->session instanceof WC_Session_Handler && WC()->session->initialized && WC()->session->cookie_set, 'PEK separate REST lifecycle must bootstrap the same WooCommerce customer session.' );
$rest_lifecycle_resolver = new CheckoutPickupPointProviderQueryResolver( new CheckoutSessionManager() );
$rest_lifecycle_query = $rest_lifecycle_resolver->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
pek_checkout_assert( 153912 === $rest_lifecycle_query->location_id && 1000 === $rest_lifecycle_query->cargo->weight_g && '' !== $rest_lifecycle_resolver->destination_fingerprint( PekSettings::PICKUP_RATE_ID ), 'PEK separate REST lifecycle must restore stored rates before trusted provider query resolution.' );
$base_snapshot = $pickup->meta['pickup_provider_query'];
foreach ( array(
	'numeric_pair' => array( 'latitude' => 55.7558, 'longitude' => 37.6173, 'expected_latitude' => 55.7558, 'expected_longitude' => 37.6173 ),
	'numeric_strings' => array( 'latitude' => '55.7558', 'longitude' => '37.6173', 'expected_latitude' => 55.7558, 'expected_longitude' => 37.6173 ),
	'zero_pair' => array( 'latitude' => '0', 'longitude' => 0, 'expected_latitude' => 0.0, 'expected_longitude' => 0.0 ),
	'null_pair' => array( 'latitude' => null, 'longitude' => null, 'expected_latitude' => null, 'expected_longitude' => null ),
) as $case => $coordinates ) {
	$snapshot = array_merge( $base_snapshot, array( 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude'] ) );
	$stored_rate = array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => $snapshot ) ) );
	$query = pek_checkout_resolver_with_rate( $stored_rate )->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
	pek_checkout_assert( $coordinates['expected_latitude'] === $query->latitude && $coordinates['expected_longitude'] === $query->longitude && array() === $query->validate(), 'PEK trusted resolver must accept valid coordinate state: ' . $case );
}
$missing_coordinate_snapshot = array_diff_key( $base_snapshot, array( 'latitude' => true, 'longitude' => true ) );
$missing_coordinate_rate = $stored_pickup_rate;
$missing_coordinate_rate['rate_meta']['pickup_provider_query'] = $missing_coordinate_snapshot;
$missing_coordinate_query = pek_checkout_resolver_with_rate( $missing_coordinate_rate )->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
pek_checkout_assert( null === $missing_coordinate_query->latitude && null === $missing_coordinate_query->longitude && array() === $missing_coordinate_query->validate(), 'PEK trusted resolver must accept legacy snapshots with both coordinate keys absent.' );
foreach ( array(
	'missing_rate_meta' => array_diff_key( $stored_pickup_rate, array( 'rate_meta' => true ) ),
	'empty_fingerprint' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'destination_fingerprint' => '' ) ) ) ),
	'forged_courier' => array_replace( pek_checkout_stored_rate_from_mapper( $courier ), array( 'rate_id' => PekSettings::PICKUP_RATE_ID, 'rate_meta' => array( 'pickup_provider_query' => $pickup->meta['pickup_provider_query'] ) ) ),
	'string_requires_pickup' => array_replace( $stored_pickup_rate, array( 'requires_pickup_point' => 'true' ) ),
	'partial_latitude' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => 55.7, 'longitude' => null ) ) ) ),
	'partial_longitude' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => null, 'longitude' => 37.6 ) ) ) ),
	'empty_coordinate_strings' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => '', 'longitude' => '' ) ) ) ),
	'array_coordinate' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => array(), 'longitude' => 37.6 ) ) ) ),
	'latitude_out_of_range' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => 91, 'longitude' => 37.6 ) ) ) ),
	'longitude_out_of_range' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => 55.7, 'longitude' => 181 ) ) ) ),
	'infinite_coordinate' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => INF, 'longitude' => 37.6 ) ) ) ),
	'nan_coordinate' => array_replace_recursive( $stored_pickup_rate, array( 'rate_meta' => array( 'pickup_provider_query' => array( 'latitude' => NAN, 'longitude' => 37.6 ) ) ) ),
) as $case => $stored_rate ) {
	try {
		pek_checkout_resolver_with_rate( $stored_rate )->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
		pek_checkout_assert( false, 'PEK trusted resolver must reject invalid stored rate case: ' . $case );
	} catch ( RuntimeException $exception ) {
		pek_checkout_assert( in_array( $exception->getMessage(), array( 'provider_rate_context_missing', 'provider_rate_context_mismatch' ), true ), 'PEK trusted resolver invalid case must use stable context errors: ' . $case );
	}
}
foreach ( array(
	'wrong_family' => array( 'pickup_family' => 'forged:pickup' ),
	'wrong_service' => array( 'service_key' => 'forged' ),
	'missing_service' => array( 'service_key' => '' ),
	'wrong_carrier' => array( 'carrier_key' => 'forged' ),
) as $case => $override ) {
	try {
		pek_checkout_resolver_with_rate( array_replace( $stored_pickup_rate, $override ) )->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
		pek_checkout_assert( false, 'PEK trusted resolver must reject mismatched stored rate envelope: ' . $case );
	} catch ( RuntimeException $exception ) {
		pek_checkout_assert( 'provider_rate_context_mismatch' === $exception->getMessage(), 'PEK trusted resolver mismatch case must use provider_rate_context_mismatch: ' . $case );
	}
}
$payloads = pek_checkout_calc_payloads( $http );
pek_checkout_assert( 'main-wh' === (string) ( $payloads[0]['receiverWarehouseId'] ?? '' ), 'Preliminary pickup quote must use mapped suitable receiver warehouse.' );
pek_checkout_assert( false === $payloads[0]['cargos'][0]['isHP'] && 0 === $payloads[0]['cargos'][0]['sealingPositionsCount'], 'PEK checkout payload must not request bag/protective packaging/plombing through API.' );
pek_checkout_assert( true === $payloads[1]['isDelivery'] && isset( $payloads[1]['delivery']['coordinates'] ), 'Location-level courier quote may use canonical city coordinates.' );
$base_context_planned = (string) ( $carrier->quote_cache_context( pek_checkout_request() )['pek_planned_datetime_bucket'] ?? '' );
pek_checkout_assert( '' !== $base_context_planned, 'PEK quote cache context must include plannedDateTime.' );
pek_checkout_assert( $base_context_planned === (string) ( $payloads[0]['plannedDateTime'] ?? '' ) && $base_context_planned === (string) ( $payloads[1]['plannedDateTime'] ?? '' ), 'PEK plannedDateTime must be identical between quote cache context and calculator payloads in one calculation lifecycle.' );

$memo_repository = new SettingsRepository();
$memo_settings = new PekSettings( $memo_repository );
$memo_repository->set( PekSettings::SENDER_WAREHOUSE_KEY, array( 'warehouseId' => 'sender-wh', 'source' => 'free', 'branchTimezone' => 'UTC' ) );
$GLOBALS['pek_checkout_current_datetime'] = '2026-08-04 12:14:59';
$memo_resolver = new PekQuotePlannedDateTimeResolver( $memo_settings );
pek_checkout_assert( '2026-08-04T13:15:00' === $memo_resolver->resolve(), 'PEK plannedDateTime first resolve must round 12:14:59 +1h to 13:15.' );
$GLOBALS['pek_checkout_current_datetime'] = '2026-08-04 12:15:01';
pek_checkout_assert( '2026-08-04T13:15:00' === $memo_resolver->resolve(), 'PEK plannedDateTime must memoize within the same resolver instance.' );
pek_checkout_assert( '2026-08-04T13:30:00' === ( new PekQuotePlannedDateTimeResolver( $memo_settings ) )->resolve(), 'A new PEK plannedDateTime resolver instance must compute a fresh request-scoped value.' );
$GLOBALS['pek_checkout_current_datetime'] = '2026-08-04 12:07:00';

$GLOBALS['pek_checkout_location_rows'] = array(
	array(
		'id' => 153912,
		'country_code' => 'RU',
		'region_name' => 'Москва',
		'region_type' => 'г',
		'city_name' => 'Москва',
		'city_type' => 'г',
		'place_name' => 'Москва',
		'place_type' => 'г',
		'display_name' => 'Москва',
		'latitude' => null,
		'longitude' => null,
		'active' => 1,
		'fias_id' => 'moscow-fias',
		'gar_object_id' => 153912,
		'region_code' => '77',
	),
);
list( $address_only_carrier, $address_only_http, $address_only_provider ) = pek_checkout_boot(
	array( pek_checkout_address_zone_response(), pek_checkout_calc_response( 1000.00 ), pek_checkout_calc_response( 2000.00 ) ),
	array( pek_checkout_point( 'main-wh' ) )
);
$address_only_quote = $address_only_carrier->quote( pek_checkout_request() );
unset( $GLOBALS['pek_checkout_location_rows'] );
pek_checkout_assert( $address_only_quote->success && count( $address_only_quote->rates ) >= 1, 'PEK address-only canonical mapping must still produce checkout rates.' );
$address_only_pickup = $address_only_quote->rates[0];
pek_checkout_assert( array_key_exists( 'latitude', $address_only_pickup->meta['pickup_provider_query'] ) && array_key_exists( 'longitude', $address_only_pickup->meta['pickup_provider_query'] ) && null === $address_only_pickup->meta['pickup_provider_query']['latitude'] && null === $address_only_pickup->meta['pickup_provider_query']['longitude'], 'PEK address-only mapping must store null/null coordinates in trusted provider snapshot.' );
$address_only_stored = pek_checkout_stored_rate_from_mapper( $address_only_pickup );
$address_only_query = pek_checkout_resolver_with_rate( $address_only_stored )->resolve( PekSettings::PICKUP_RATE_ID, PekSettings::CARRIER_KEY, PekSettings::PICKUP_FAMILY );
pek_checkout_assert( 153912 === $address_only_query->location_id && null === $address_only_query->latitude && null === $address_only_query->longitude && array() === $address_only_query->validate(), 'Production stored PEK address-only rate must resolve trusted provider query with null coordinates.' );
pek_checkout_assert( 1 === count( $address_only_provider->queries ) && null === $address_only_provider->queries[0]->latitude && null === $address_only_provider->queries[0]->longitude, 'PEK terminal provider must remain usable for address-only checkout mappings.' );

$provider_fingerprint = (string) $pickup->meta['pickup_provider_query']['provider_destination_fingerprint'];
$selection = array(
	'carrier_key' => 'pek',
	'service_key' => 'pek',
	'pickup_family' => PekSettings::PICKUP_FAMILY,
	'point_code' => 'paid-wh',
	'destination_fingerprint' => $provider_fingerprint,
	'provider_destination_fingerprint' => $provider_fingerprint,
);
$GLOBALS['pek_checkout_wc'] = new PekCheckoutFakeWoo();
$normalized_session = new CheckoutSessionManager();
$normalized_session->save_city_context( array( 'country_code' => 'RU', 'location_id' => 153912 ) );
$normalized_session->save_pickup_selection_for_family( PekSettings::PICKUP_FAMILY, $selection );
$normalized_selection = $normalized_session->pickup_selection_for_family( PekSettings::PICKUP_FAMILY );
pek_checkout_assert( $provider_fingerprint === (string) ( $normalized_selection['provider_destination_fingerprint'] ?? '' ) && 'country=RU|location_id=153912' === (string) ( $normalized_selection['destination_fingerprint'] ?? '' ), 'CheckoutSessionManager must preserve provider fingerprint separately from generic location fingerprint.' );
list( $selected_carrier, $selected_http ) = pek_checkout_boot(
	array( pek_checkout_zone_response(), pek_checkout_calc_response( 1267.92 ), pek_checkout_calc_response( 2000.00 ) ),
	array( pek_checkout_point( 'main-wh' ), pek_checkout_point( 'paid-wh', 'paid' ) )
);
$selected_quote = $selected_carrier->quote( pek_checkout_request( array( 'pickup_selections' => array( PekSettings::PICKUP_FAMILY => $normalized_selection ) ) ) );
$selected_payloads = pek_checkout_calc_payloads( $selected_http );
pek_checkout_assert( 'paid-wh' === (string) ( $selected_payloads[0]['receiverWarehouseId'] ?? '' ), 'Selected PEK terminal must become receiverWarehouseId for pickup calculator quote.' );
pek_checkout_assert( 135792 === $selected_quote->rates[0]->price->get_kopecks() && 'selection' === (string) $selected_quote->rates[0]->meta['pek_receiver_warehouse_source'], 'Selected PEK partner terminal must recalculate pickup price using the selected warehouse.' );
pek_checkout_assert( $selected_quote->quote_id !== $quote->quote_id, 'Selected terminal must change PEK quote ID/cache identity.' );

list( $courier_only, $courier_only_http ) = pek_checkout_boot(
	array( pek_checkout_zone_response(), pek_checkout_calc_response( 2000.00 ) ),
	array()
);
$courier_only_quote = $courier_only->quote( pek_checkout_request() );
pek_checkout_assert( $courier_only_quote->success && 1 === count( $courier_only_quote->rates ) && PekSettings::COURIER_RATE_ID === $courier_only_quote->rates[0]->rate_id, 'Pickup point absence must not suppress courier PEK rate.' );

list( $full_address_carrier, $full_address_http ) = pek_checkout_boot(
	array( pek_checkout_zone_response(), pek_checkout_calc_response( 1000.00 ), pek_checkout_calc_response( 2000.00 ) ),
	array( pek_checkout_point( 'main-wh' ) )
);
$full_address = new Address( country_code: 'RU', city: 'Москва', street: 'улица Большая Лубянка', house: '2', raw_address: 'Россия, Москва, улица Большая Лубянка, 2', normalized: true );
$full_address_quote = $full_address_carrier->quote( pek_checkout_request( array(), $full_address ) );
$full_payloads = pek_checkout_calc_payloads( $full_address_http );
pek_checkout_assert( 'full_address' === (string) $full_address_quote->rates[1]->meta['pek_courier_quote_scope'] && ! isset( $full_payloads[1]['delivery']['coordinates'] ), 'Full-address PEK courier quote must omit city-center coordinates.' );

$base_context = $carrier->quote_cache_context( pek_checkout_request() );
$selected_context = $carrier->quote_cache_context( pek_checkout_request( array( 'pickup_selections' => array( PekSettings::PICKUP_FAMILY => $normalized_selection ) ) ) );
$full_context = $carrier->quote_cache_context( pek_checkout_request( array(), $full_address ) );
pek_checkout_assert( $base_context !== $selected_context && $base_context !== $full_context && $provider_fingerprint === (string) ( $selected_context['pek_selection_provider_destination_fingerprint'] ?? '' ), 'PEK quote cache context must distinguish selected terminal/fingerprint and full courier address.' );
$quote_cache = new QuoteCache();
$preliminary_key = $quote_cache->cache_key( pek_checkout_request(), PekSettings::CARRIER_KEY, 'pickup', PekSettings::SERVICE_KEY, $base_context );
$selected_key = $quote_cache->cache_key( pek_checkout_request( array( 'pickup_selections' => array( PekSettings::PICKUP_FAMILY => $normalized_selection ) ) ), PekSettings::CARRIER_KEY, 'pickup', PekSettings::SERVICE_KEY, $selected_context );
$same_selected_key = $quote_cache->cache_key( pek_checkout_request( array( 'pickup_selections' => array( PekSettings::PICKUP_FAMILY => $normalized_selection ) ) ), PekSettings::CARRIER_KEY, 'pickup', PekSettings::SERVICE_KEY, $selected_context );
$other_destination_selection = $normalized_selection;
$other_destination_selection['provider_destination_fingerprint'] = str_repeat( 'a', 64 );
$other_destination_selection['snapshot']['provider_destination_fingerprint'] = str_repeat( 'a', 64 );
$other_context = $carrier->quote_cache_context( pek_checkout_request( array( 'pickup_selections' => array( PekSettings::PICKUP_FAMILY => $other_destination_selection ) ) ) );
$other_selected_key = $quote_cache->cache_key( pek_checkout_request( array( 'pickup_selections' => array( PekSettings::PICKUP_FAMILY => $other_destination_selection ) ) ), PekSettings::CARRIER_KEY, 'pickup', PekSettings::SERVICE_KEY, $other_context );
pek_checkout_assert( $preliminary_key !== $selected_key && $selected_key === $same_selected_key && $selected_key !== $other_selected_key, 'PEK quote cache key must miss after terminal/fingerprint changes and stay stable for the same selected point.' );
$context_json = wp_json_encode( $selected_context, JSON_UNESCAPED_UNICODE ) ?: '';
pek_checkout_assert( ! str_contains( $context_json, 'checkout-secret' ) && ! str_contains( $context_json, 'card-secret' ) && ! str_contains( $context_json, '5400000000' ), 'PEK quote cache context must not include raw credentials or contract identifiers.' );

$checkout_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/Runtime/CheckoutOrchestrator.php' ) ?: '';
pek_checkout_assert( ! str_contains( $checkout_source, 'PekCarrier' ) && ! str_contains( $checkout_source, "'pek'" ), 'CheckoutOrchestrator must not add a PEK-specific branch.' );
$pickup_rest_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' ) ?: '';
pek_checkout_assert( strpos( $pickup_rest_source, 'save_registry_backed_selection( $request' ) < strpos( $pickup_rest_source, "'cdek' === \$carrier" ), 'Registry-backed PEK selection save must run before legacy carrier/browser-payload fallback.' );
$pek_carrier_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/PekCarrier.php' ) ?: '';
pek_checkout_assert( ! str_contains( $pek_carrier_source, 'transportingTypes' ) && ! str_contains( $pek_carrier_source, 'senderCityId' ) && ! str_contains( $pek_carrier_source, 'receiverCityId' ) && ! str_contains( $pek_carrier_source, 'overSize' ), 'PEK checkout runtime must not introduce deprecated calculator fields.' );
pek_checkout_assert( ! is_dir( dirname( __DIR__, 2 ) . '/src/Shipments/Pek' ), 'Checkout runtime stage must not add PEK Shipment Framework files.' );

echo "PEK checkout runtime smoke passed.\n";
