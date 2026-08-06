<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'pek-shipment-integration-smoke-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wdc_pek_integration_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['wdc_pek_integration_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		return $GLOBALS['wdc_pek_integration_transients'][ $key ]['value'] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['wdc_pek_integration_transients'][ $key ] = array( 'value' => $value, 'expiration' => $expiration );
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		unset( $type );
		return '2026-08-06 12:30:00';
	}
}
if ( ! function_exists( 'current_datetime' ) ) {
	function current_datetime(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-08-06 12:30:00', wp_timezone() );
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( 'UTC' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		unset( $hook, $args );
	}
}

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekManualAttachContextResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekShipmentCourierAddressResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentCreateResponseParser;
use WallsShop\WDC\Shipments\Pek\PekShipmentRequestBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentService;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusService;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function pek_integration_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function pek_integration_assert_plain_data( mixed $value, string $path = 'shipment' ): void {
	pek_integration_assert( ! is_object( $value ), 'Persisted shipment must not contain object at ' . $path );
	pek_integration_assert( ! is_resource( $value ), 'Persisted shipment must not contain resource at ' . $path );
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $child ) {
			pek_integration_assert_plain_data( $child, $path . '.' . (string) $key );
		}
	}
}

final class PekIntegrationFakeHttp implements PekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $calls = array();
	/** @var array<int,string> */
	public array $statuses = array();
	/** @var array<int,array<string,mixed>> */
	public array $cancellations = array();

	public function request( string $method, string $url, array $args ): array {
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/cargos/status/' ) ) {
			$status = array_shift( $this->statuses );
			$status = is_string( $status ) ? $status : 'Прибыл';
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'cargos' => array(
							array(
								'cargo' => array(
									'code' => 'PEK-777',
									'cargoBarCode' => 'BAR-777',
									'positionBarCodes' => array( 'POS-1', 'POS-2' ),
								),
								'info' => array(
									'cargoStatus' => $status,
									'cargoStatusId' => '42',
									'takeOnStockDateTime' => 'Принят к перевозке' === $status ? '2026-08-06 12:00:00' : '',
								),
								'receiver' => array(
									'receivingBySMSCode' => true,
									'receivingByDocument' => false,
								),
								'services' => array(
									'sum' => '123.45',
								),
							),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/order/cancellation/' ) ) {
			$this->cancellations[] = array( 'method' => $method, 'args' => $args );
			return array(
				'status' => 200,
				'body' => wp_json_encode( array( array( 'code' => 'PEK-777', 'success' => true ) ), JSON_UNESCAPED_UNICODE ),
			);
		}

		return array( 'status' => 500, 'body' => '{}' );
	}
}

final class PekIntegrationOrder {
	/** @var array<string,mixed> */
	private array $meta = array();

	public function __construct( private int $id ) {
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): void {
	}
}

$GLOBALS['wdc_pek_integration_options'] = array();
$settings_repository = new SettingsRepository();
$encryption = new EncryptionService();
$settings_repository->set( PekSettings::LOGIN_KEY, 'fake-login' );
$settings_repository->set( PekSettings::API_KEY_ENCRYPTED_KEY, $encryption->encrypt( 'fake-api-key' ) );
$settings_repository->set( PekSettings::REQUESTS_PER_MINUTE_KEY, 100 );
$settings_repository->set( PekSettings::CLIENT_CARD_KEY, 'CLIENT-CARD-1' );

$settings = new PekSettings( $settings_repository );
$credentials = new PekCredentials( $settings_repository, $encryption );
$http = new PekIntegrationFakeHttp();
$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
$repository = new OrderShipmentRepository();
$actual_costs = new ShipmentActualCostService( $repository );
$mapping = new PekStatusMapping();
$status_service = new PekShipmentStatusService( $api, $mapping, $repository, $actual_costs );
$button_policy = new PekShipmentButtonPolicy( $mapping );
$dummy_drafts = ( new ReflectionClass( OrderShipmentDraftFactory::class ) )->newInstanceWithoutConstructor();
$manual_contexts = new PekManualAttachContextResolver( $dummy_drafts, $repository );
$shipment_service = new PekShipmentService( $api, $status_service, $repository, $button_policy, $actual_costs, $mapping, $manual_contexts );
$actual_cost_resolver = new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
$adapter = new PekShipmentAdapter(
	$api,
	( new ReflectionClass( PekShipmentRequestBuilder::class ) )->newInstanceWithoutConstructor(),
	$status_service,
	$shipment_service,
	$button_policy,
	new PekShipmentCreateResponseParser(),
	$actual_cost_resolver
);

$order = new PekIntegrationOrder( 1001 );
$pending = array(
	'carrier_key' => PekSettings::CARRIER_KEY,
	'status' => 'pending_creation_in_carrier',
	'universal_status_code' => 'pending_creation_in_carrier',
	'pending_creation_in_carrier' => true,
	'created_at' => '2026-08-06 12:00:00',
	'service_key' => PekSettings::SERVICE_KEY,
	'service_title' => 'ПЭК',
	'delivery_type' => DeliveryType::PICKUP,
	'shipment_mode' => DeliveryType::PICKUP,
	'rate_id' => PekSettings::PICKUP_RATE_ID,
	'places' => array( array( 'place_number' => 1, 'weight_g' => 1000, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10 ) ),
	'order_num' => '1001',
	'pek_correlation' => 'wdc-pek-correlation-1001',
	'request_summary' => array( 'correlation' => 'wdc-pek-correlation-1001', 'receiver_mode' => 'pickup' ),
	'request_snapshot' => array( 'method' => 'POST', 'path' => '/preregistration/submit/', 'body' => array( 'receiver_mode' => 'pickup' ) ),
	'pek_sender_warehouse_id' => 'WH-A',
	'pek_sender_warehouse_title' => 'Склад A',
	'pek_sender_warehouse_source' => 'default',
	'pek_receiver_warehouse_id' => 'WH-R',
	'pek_receiver_warehouse_source' => 'fresh_pickup',
	'pek_receiver_branch_id' => 'BR-R',
	'recipient_type' => 'physical',
	'declared_value_kopecks' => 100000,
	'sealing_requested' => true,
	'sms_release_requested' => true,
	'sms_release_confirmed' => true,
	'sms_release_effective_limit_kopecks' => 50000000,
);
$repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $pending );

$pending_payload = $adapter->status_payload( $order, $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY ) );
pek_integration_assert( true === $pending_payload['has_shipment'], 'Pending shipment must be visible.' );
pek_integration_assert( false === $pending_payload['can_create'], 'Pending shipment must block automatic create.' );
pek_integration_assert( true === $pending_payload['can_attach_manual'], 'Pending shipment must expose manual attach.' );
pek_integration_assert( false === $pending_payload['can_update_status'], 'Pending shipment must hide status update.' );
pek_integration_assert( false === $pending_payload['can_cancel'], 'Pending shipment must hide cancellation.' );
pek_integration_assert( true === $pending_payload['can_remove_from_order'], 'Pending shipment must expose local remove.' );

$metabox_policy = ( new ShipmentMetaboxButtonPolicy() )->resolve( PekSettings::CARRIER_KEY, $pending, $pending_payload );
pek_integration_assert( true === $metabox_policy['show_manual_attach'], 'Generic metabox policy must show manual attach for pending PEK.' );
pek_integration_assert( true === $metabox_policy['show_remove'], 'Generic metabox policy must show local remove for pending PEK.' );
pek_integration_assert( false === $metabox_policy['show_create'] && false === $metabox_policy['show_update'] && false === $metabox_policy['show_cancel'], 'Generic metabox policy must hide create/update/cancel for pending PEK.' );

$http->statuses = array( 'Прибыл' );
$attach_result = $shipment_service->attach_manual( $order, array( 'tracking_number' => 'PEK-777' ) );
pek_integration_assert( true === $attach_result['success'], 'Manual attach must succeed with generic tracking-only payload.' );
$attached = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
pek_integration_assert( 'PEK-777' === $attached['tracking_number'], 'Manual attach result must persist tracking number.' );
pek_integration_assert( DeliveryType::PICKUP === $attached['delivery_type'], 'Manual attach must restore pickup delivery type from pending context.' );
pek_integration_assert( 'ready_for_pickup' === $attached['universal_status_code'], 'Pickup status "Прибыл" must map to ready_for_pickup during reconciliation.' );
pek_integration_assert( 'wdc-pek-correlation-1001' === $attached['pek_correlation'], 'Manual attach must preserve canonical correlation.' );
pek_integration_assert( '2026-08-06 12:00:00' === $attached['created_at'], 'Manual attach must preserve original created_at.' );
pek_integration_assert( isset( $attached['reconciled_at'] ), 'Manual attach must add reconciled_at.' );
pek_integration_assert( false === $attached['pending_creation_in_carrier'], 'Manual attach must clear active pending state.' );
pek_integration_assert( 12345 === $attached['actual_cost_kopecks'], 'Manual attach must merge actual cost from PEK status services.sum.' );
pek_integration_assert_plain_data( $attached );

$http->statuses = array( 'Прибыл' );
$courier_status = $status_service->fetch( 'PEK-777', DeliveryType::COURIER );
pek_integration_assert( 'in_transit' === $courier_status['universal_status_code'], 'Courier status "Прибыл" must remain in_transit.' );

$unknown = array_merge( $attached, array( 'pek_cargo_status' => 'UNKNOWN', 'status_title' => 'UNKNOWN', 'universal_status_code' => 'unknown', 'created_at' => '2026-08-06 12:00:00' ) );
pek_integration_assert( false === $button_policy->resolve( $unknown )['cancel'], 'UNKNOWN status must not expose cancellation.' );
$accepted = array_merge( $attached, array( 'pek_cargo_status' => 'Принят к перевозке', 'status_title' => 'Принят к перевозке', 'universal_status_code' => 'created_in_carrier', 'created_at' => '2026-08-06 12:00:00' ) );
pek_integration_assert( false === $button_policy->resolve( $accepted )['cancel'], 'Accepted PEK status must not expose cancellation.' );
$open = array_merge( $attached, array( 'pek_cargo_status' => 'Оформлен', 'status_title' => 'Оформлен', 'pek_take_on_stock_datetime' => '', 'universal_status_code' => 'created_in_carrier', 'manual_attach' => false, 'created_at' => '2026-08-06 12:00:00' ) );
pek_integration_assert( true === $button_policy->resolve( $open )['cancel'], 'Pre-acceptance PEK status must expose cancellation before age gate.' );

$before_cancel_calls = count( $http->cancellations );
$repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $unknown );
pek_integration_assert( false === $shipment_service->cancel_in_carrier( $order )['success'], 'UNKNOWN status must fail cancellation locally.' );
pek_integration_assert( $before_cancel_calls === count( $http->cancellations ), 'UNKNOWN status must make zero cancellation API calls.' );

$repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $accepted );
pek_integration_assert( false === $shipment_service->cancel_in_carrier( $order )['success'], 'Accepted status must fail cancellation locally.' );
pek_integration_assert( $before_cancel_calls === count( $http->cancellations ), 'Accepted status must make zero cancellation API calls.' );

$repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $open );
$http->statuses = array( 'Оформлен' );
pek_integration_assert( true === $shipment_service->cancel_in_carrier( $order )['success'], 'Fresh pre-acceptance status must allow cancellation.' );
pek_integration_assert( $before_cancel_calls + 1 === count( $http->cancellations ), 'Pre-acceptance cancellation must call PEK exactly once.' );

$addresses = new PekShipmentCourierAddressResolver();
foreach ( array( '1-я Тверская улица', 'улица 1905 года', '40 лет Победы' ) as $street_only ) {
	try {
		$addresses->normalize( new Address( country_code: 'RU', city: 'Москва', street: $street_only, raw_address: $street_only ) );
		pek_integration_assert( false, 'Street-only courier address must fail: ' . $street_only );
	} catch ( RuntimeException $expected ) {
		pek_integration_assert( str_contains( $expected->getMessage(), 'улицей и номером дома' ), 'Courier address failure must be public-safe.' );
	}
}
$structured = $addresses->normalize( new Address( country_code: 'RU', city: 'Москва', street: 'Тверская улица', house: '1', apartment: '5', raw_address: 'Тверская улица, 1, кв. 5' ) );
$address_stock = $addresses->address_stock( $structured );
pek_integration_assert( str_contains( $address_stock, 'дом 1' ) && str_contains( $address_stock, 'кв. 5' ), 'addressStock must contain structured house and apartment.' );
$parsed = $addresses->normalize( new Address( country_code: 'RU', city: 'Москва', raw_address: '2-я Мелитопольская улица, 12Ас1' ) );
pek_integration_assert( '12Ас1' === $parsed->house, 'Courier parser must accept explicit house with letter/corpus.' );

$request_builder_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentRequestBuilder.php' ) ?: '';
pek_integration_assert( str_contains( $request_builder_source, "if ( '' !== \$client_card )" ), 'PEK request builder must omit empty counterpartClientCard.' );
pek_integration_assert( ! str_contains( $request_builder_source, "'counterpartClientCard' => \$this->settings->client_card()" ), 'PEK request builder must not serialize empty client card directly.' );

$all_urls = implode( "\n", array_map( static fn ( array $call ): string => $call['url'], $http->calls ) );
pek_integration_assert( ! str_contains( $all_urls, '/preregistration/submit/' ), 'Integration smoke must not submit PEK preregistration.' );
pek_integration_assert( ! str_contains( $all_urls, '/order/print/' ), 'Integration smoke must not download production documents.' );
pek_integration_assert( ! str_contains( $all_urls, '/cargos/cancelandreturncargo/' ), 'Integration smoke must not call PEK return API.' );

echo "PEK shipment integration smoke passed.\n";
