<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'pek-shipment-integration-smoke-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 1;
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $pek_location_mappings = array();
		/** @var array<int,array<string,mixed>> */
		public array $pek_terminals = array();
		public function insert( string $table, array $data, array $format = array() ): bool { unset( $table, $data, $format ); return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool { unset( $table, $data, $where, $format, $where_format ); return true; }
		public function get_results( string $query, string $output = OBJECT ): array { unset( $query, $output ); return array(); }
		public function get_row( string $query, string $output = OBJECT ): mixed { unset( $query, $output ); return null; }
		public function prepare( string $query, mixed ...$args ): string { unset( $args ); return $query; }
		public function get_charset_collate(): string { return ''; }
	}
}

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
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $order_id ): ?object {
		return $GLOBALS['wdc_pek_integration_orders'][ $order_id ] ?? null;
	}
}

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseSearchCache;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCargoConstraintsConverter;
use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalSearchCache;
use WallsShop\WDC\Carriers\Pek\Pickup\PekPickupPointProvider;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalService;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Pek\PekManualAttachContextResolver;
use WallsShop\WDC\Shipments\Pek\PekPrivateAccessTokenService;
use WallsShop\WDC\Shipments\Pek\PekSenderCounterpartService;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekShipmentCargoBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentCorrelationResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentCourierAddressResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentCreateResponseParser;
use WallsShop\WDC\Shipments\Pek\PekShipmentDeclaredValueResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentDestinationResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Pek\PekShipmentProductWeightResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentRecipientBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentRequestBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentSenderWarehouseResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentService;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusService;
use WallsShop\WDC\Shipments\Pek\PekSmsReleaseAvailabilityService;
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

function pek_integration_fixture( string $name ): array {
	$path = dirname( __DIR__ ) . '/pek/fixtures/' . $name;
	$data = json_decode( file_get_contents( $path ) ?: '', true );
	pek_integration_assert( is_array( $data ), 'Fixture must be valid JSON: ' . $name );

	return $data;
}

function pek_integration_assert_same_payload( array $actual, array $expected, string $label ): void {
	$actual_json = wp_json_encode( $actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$expected_json = wp_json_encode( $expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	pek_integration_assert( $actual_json === $expected_json, $label . " payload mismatch.\nExpected: " . (string) $expected_json . "\nActual: " . (string) $actual_json );
}

function pek_integration_set_dadata( PekIntegrationOrder $order, string $scope, string $region, string $city, string $street, string $house, string $flat = '', string $settlement = '' ): void {
	$order->update_meta_data( '_' . $scope . '_dadata_status', 'house_selected' );
	$order->update_meta_data( '_' . $scope . '_dadata_region_with_type', $region );
	$order->update_meta_data( '_' . $scope . '_dadata_city_with_type', $city );
	$order->update_meta_data( '_' . $scope . '_dadata_settlement_with_type', $settlement );
	$order->update_meta_data( '_' . $scope . '_dadata_street_with_type', $street );
	$order->update_meta_data( '_' . $scope . '_dadata_house', $house );
	$order->update_meta_data( '_' . $scope . '_dadata_flat', $flat );
}

final class PekIntegrationFakeHttp implements PekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $calls = array();
	/** @var array<int,array<string,mixed>> */
	public array $submit_bodies = array();
	/** @var array<int,string> */
	public array $statuses = array();
	/** @var array<int,array<string,mixed>> */
	public array $cancellations = array();
	public string $submit_mode = 'success';
	public string $connected_services_mode = 'success';
	public string $confirmed_counterparties_mode = 'success';
	public string $token_mode = 'success';

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
		if ( str_contains( $url, '/auth/createtokentoaccessprivatedata/' ) ) {
			if ( 'malformed' === $this->token_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'access_token' => array(), 'token_type' => 'Bearer', 'expires_in_unix' => '1893456000' ), JSON_UNESCAPED_UNICODE ) );
			}
			return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/private-token-response.json' ) ?: '{}' );
		}
		if ( str_contains( $url, '/branches/nearestdepartments/' ) ) {
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'freeDepartments' => array(
							array(
								'warehouseId' => 'WH-R',
								'branchId' => 'BR-R',
								'branchTitle' => 'Москва',
								'divisionName' => 'Склад получателя',
								'address' => 'Россия, Московская область, Видное, Терминальная, дом 2',
								'departmentTypeId' => 1,
								'departmentType' => 'Склад',
								'coordinates' => array( 'latitude' => '55.5', 'longitude' => '37.7' ),
								'maxWeight' => 1000,
								'maxVolume' => 100,
								'maxDimension' => 10,
								'maxWeightOnePlace' => 1000,
								'maxCount' => 100,
							),
						),
						'paidDepartments' => array(),
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/branches/all/' ) ) {
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'branches' => array(
							array(
								'id' => 'BR-S',
								'title' => 'Новосибирск',
								'timezone' => 'UTC+07:00',
								'divisions' => array(
									array(
										'name' => 'Склад A',
										'departmentTypeId' => 1,
										'departmentType' => 'Склад',
										'kindsOfTransportation' => array( array( 'type' => 3, 'operations' => array( 'Прием грузов' ) ) ),
										'warehouses' => array(
											array(
												'id' => 'WH-A',
												'address' => 'Россия, Новосибирск, Складская, дом 1',
												'coordinatesobj' => array( 'latitude' => '55.0', 'longitude' => '82.9' ),
												'types' => array( 3 ),
												'maxWeight' => 1000,
												'maxVolume' => 100,
												'maxDimension' => 10,
												'maxWeightOnePlace' => 1000,
												'maxCount' => 100,
											),
										),
									),
								),
							),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/branches/findzonebyaddress/' ) ) {
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'zoneId' => 'ZONE-R',
						'zoneName' => 'Москва',
						'branchUID' => 'BR-R',
						'branchTitle' => 'Москва',
						'mainWarehouseId' => 'WH-R',
						'GeoData' => array(
							'precision' => 'exact',
							'Address' => array(
								'formatted' => 'Россия, Московская область, Видное',
								'country_code' => 'RU',
							),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/counterparts/confirmedaccesstocounterparties/' ) ) {
			if ( 'card_mismatch' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-response.json' );
				$rows[0]['counterpartClientCard'] = 'OTHER-CARD';
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'bad_inn_letters' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-response.json' );
				$rows[0]['legal']['inn'] = '54ABC0000000';
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'bad_kpp_letters' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-response.json' );
				$rows[0]['legal']['kpp'] = '54000A001';
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/confirmed-counterparties-response.json' ) ?: '[]' );
		}
		if ( str_contains( $url, '/counterparts/connecteddiscountsservicesagreements/' ) ) {
			if ( 'available_string' === $this->connected_services_mode ) {
				$data = pek_integration_fixture( 'connected-services-response.json' );
				$data['availableTypesOfDelivery'] = array( '3' );
				return array( 'status' => 200, 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'duplicate_cod' === $this->connected_services_mode ) {
				$data = pek_integration_fixture( 'connected-services-response.json' );
				$data['specialConditionsWithParams'][0]['params'][] = array( 'key' => 'CODMaxSum', 'type' => 'Money', 'values' => 100000.00 );
				return array( 'status' => 200, 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'cod_20000000' === $this->connected_services_mode ) {
				$data = pek_integration_fixture( 'connected-services-response.json' );
				$data['specialConditionsWithParams'][0]['params'][0]['values'] = 20000000.00;
				return array( 'status' => 200, 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'malformed_sibling' === $this->connected_services_mode ) {
				$data = pek_integration_fixture( 'connected-services-response.json' );
				$data['specialConditionsWithParams'][] = 'malformed';
				return array( 'status' => 200, 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'special_assoc' === $this->connected_services_mode ) {
				$data = pek_integration_fixture( 'connected-services-response.json' );
				$data['specialConditionsWithParams'] = array( 'row' => $data['specialConditionsWithParams'][0] );
				return array( 'status' => 200, 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'missing_uid' === $this->connected_services_mode ) {
				$data = pek_integration_fixture( 'connected-services-response.json' );
				unset( $data['specialConditionsWithParams'][0]['specialCondition']['UID'] );
				return array( 'status' => 200, 'body' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
			return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/connected-services-response.json' ) ?: '{}' );
		}
		if ( str_contains( $url, '/branches/checknocalcservices/' ) ) {
			if ( 'geography_malformed' === $this->connected_services_mode ) {
				return array(
					'status' => 200,
					'body' => wp_json_encode( array( array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ), 'malformed' ), JSON_UNESCAPED_UNICODE ),
				);
			}
			return array(
				'status' => 200,
				'body' => wp_json_encode( array( array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ) ), JSON_UNESCAPED_UNICODE ),
			);
		}
		if ( str_contains( $url, '/order/cancellation/' ) ) {
			$this->cancellations[] = array( 'method' => $method, 'args' => $args );
			return array(
				'status' => 200,
				'body' => wp_json_encode( array( array( 'code' => 'PEK-777', 'success' => true ) ), JSON_UNESCAPED_UNICODE ),
			);
		}
		if ( str_contains( $url, '/preregistration/submit/' ) ) {
			$decoded = json_decode( (string) ( $args['body'] ?? '' ), true );
			$this->submit_bodies[] = is_array( $decoded ) ? $decoded : array();
			if ( 'http500' === $this->submit_mode ) {
				return array( 'status' => 500, 'body' => '{}' );
			}
			if ( 'http400' === $this->submit_mode ) {
				return array( 'status' => 400, 'body' => wp_json_encode( array( 'title' => 'Validation', 'message' => 'Invalid request' ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'logical200' === $this->submit_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'error' => array( 'title' => 'Validation', 'message' => 'Invalid request', 'fields' => array() ) ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'malformed' === $this->submit_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'documentId' => 136, 'cargos' => array( array( 'cargoCode' => array() ) ) ), JSON_UNESCAPED_UNICODE ) );
			}
			return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/preregistration-submit-response.json' ) ?: '{}' );
		}

		return array( 'status' => 500, 'body' => '{}' );
	}
}

final class PekIntegrationProduct {
	public function get_weight(): string {
		return '1.25';
	}

	public function get_length(): string {
		return '20';
	}

	public function get_width(): string {
		return '20';
	}

	public function get_height(): string {
		return '10';
	}

	public function get_sku(): string {
		return 'SKU-1';
	}
}

final class PekIntegrationItem {
	public function __construct( private string $name, private int $quantity, private string $total, private string $tax = '0.00' ) {
	}

	public function get_product(): object {
		return new PekIntegrationProduct();
	}

	public function get_quantity(): int {
		return $this->quantity;
	}

	public function get_total(): string {
		return $this->total;
	}

	public function get_total_tax(): string {
		return $this->tax;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_id(): int {
		return 1;
	}
}

final class PekIntegrationOrder {
	/** @var array<string,mixed> */
	private array $meta = array();
	/** @var array<string,string> */
	private array $shipping = array(
		'country' => 'RU',
		'state' => 'Московская область',
		'city' => 'Видное',
		'postcode' => '142700',
		'address_1' => 'улица Советская, дом 10',
		'address_2' => 'квартира 5',
		'first_name' => 'Иван',
		'last_name' => 'Иванов',
		'phone' => '89991234567',
	);
	/** @var array<string,string> */
	private array $billing = array(
		'country' => 'RU',
		'state' => 'Московская область',
		'city' => 'Видное',
		'postcode' => '142700',
		'address_1' => 'улица Советская, дом 10',
		'address_2' => 'квартира 5',
		'phone' => '89991234567',
		'email' => 'buyer@example.test',
	);

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

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_shipping_country(): string {
		return $this->shipping['country'];
	}

	public function get_shipping_state(): string {
		return $this->shipping['state'];
	}

	public function get_shipping_city(): string {
		return $this->shipping['city'];
	}

	public function get_shipping_postcode(): string {
		return $this->shipping['postcode'];
	}

	public function get_shipping_address_1(): string {
		return $this->shipping['address_1'];
	}

	public function get_shipping_address_2(): string {
		return $this->shipping['address_2'];
	}

	public function get_shipping_first_name(): string {
		return $this->shipping['first_name'];
	}

	public function get_shipping_last_name(): string {
		return $this->shipping['last_name'];
	}

	public function get_shipping_phone(): string {
		return $this->shipping['phone'];
	}

	public function get_billing_phone(): string {
		return $this->billing['phone'];
	}

	public function get_billing_email(): string {
		return $this->billing['email'];
	}

	public function get_billing_country(): string { return $this->billing['country']; }
	public function get_billing_state(): string { return $this->billing['state']; }
	public function get_billing_city(): string { return $this->billing['city']; }
	public function get_billing_postcode(): string { return $this->billing['postcode']; }
	public function get_billing_address_1(): string { return $this->billing['address_1']; }
	public function get_billing_address_2(): string { return $this->billing['address_2']; }

	/** @param array<string,string> $values */
	public function set_shipping_fields( array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( array_key_exists( $key, $this->shipping ) ) {
				$this->shipping[ $key ] = $value;
			}
		}
	}

	/** @param array<string,string> $values */
	public function set_billing_fields( array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( array_key_exists( $key, $this->billing ) ) {
				$this->billing[ $key ] = $value;
			}
		}
	}

	public function get_items( string $type = '' ): array {
		unset( $type );
		return array( new PekIntegrationItem( 'Товар', 2, '100000.00' ) );
	}
}

$GLOBALS['wdc_pek_integration_options'] = array();
$settings_repository = new SettingsRepository();
$encryption = new EncryptionService();
$settings_repository->set( PekSettings::LOGIN_KEY, 'fake-login' );
$settings_repository->set( PekSettings::API_KEY_ENCRYPTED_KEY, $encryption->encrypt( 'fake-api-key' ) );
$settings_repository->set( PekSettings::REQUESTS_PER_MINUTE_KEY, 100 );
$settings_repository->set( PekSettings::CLIENT_CARD_KEY, 'CLIENT-CARD' );
$settings_repository->set( PekSettings::SENDER_LEGAL_FORM_KEY, 1 );
$settings_repository->set( PekSettings::SENDER_FS_KEY, 'ООО' );
$settings_repository->set( PekSettings::SENDER_FULL_NAME_KEY, 'Тестовый отправитель' );
$settings_repository->set( PekSettings::SENDER_INN_KEY, '5400000000' );
$settings_repository->set( PekSettings::SENDER_KPP_KEY, '540001001' );
$settings_repository->set( PekSettings::SENDER_REGISTRATION_COUNTRY_KEY, 'RU' );
$settings_repository->set( PekSettings::SENDER_CONTACT_NAME_KEY, 'Петров Петр' );
$settings_repository->set( PekSettings::SENDER_PHONE_KEY, '83831234567' );
$settings_repository->set( PekSettings::SENDER_EMAIL_KEY, 'sender@example.test' );

$settings = new PekSettings( $settings_repository );
$credentials = new PekCredentials( $settings_repository, $encryption );
$http = new PekIntegrationFakeHttp();
$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
$settings->save_sender_warehouse(
	array(
		'warehouseId' => 'WH-A',
		'branchId' => 'BR-S',
		'title' => 'Склад A',
		'address' => 'Россия, Новосибирск, Складская, дом 1',
		'source' => 'default',
	)
);
$repository = new OrderShipmentRepository();
$actual_costs = new ShipmentActualCostService( $repository );
$mapping = new PekStatusMapping();
$status_service = new PekShipmentStatusService( $api, $mapping, $repository, $actual_costs );
$button_policy = new PekShipmentButtonPolicy( $mapping );
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array(
		'id' => 77,
		'country_code' => 'RU',
		'region_name' => 'Московская область',
		'region_type' => 'область',
		'city_name' => 'Видное',
		'city_type' => 'г',
		'display_name' => 'Видное',
		'active' => 1,
		'latitude' => '',
		'longitude' => '',
	),
);
$GLOBALS['wpdb']->pek_location_mappings = array();
$GLOBALS['wpdb']->pek_terminals = array();
$drafts = new OrderShipmentDraftFactory( new DeliveryServiceRepository( $GLOBALS['wpdb'] ), new ShipmentServiceSettings(), null, null, null, null, null, null, null, null, null, null, $settings, new PekShipmentCourierAddressResolver() );
$manual_contexts = new PekManualAttachContextResolver( $drafts, $repository );
$shipment_service = new PekShipmentService( $api, $status_service, $repository, $button_policy, $actual_costs, $mapping, $manual_contexts );
$actual_cost_resolver = new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
$destination_resolver = new PekShipmentDestinationResolver(
	new PekPickupPointProvider(
		new PekTerminalService(
			new PekLocationResolver( new LocationRepository( $GLOBALS['wpdb'] ), new PekAddressBuilder(), new PekLocationMappingRepository( $GLOBALS['wpdb'] ), $api, $settings ),
			$api,
			new PekCargoConstraintsConverter(),
			new PekDestinationTerminalSearchCache(),
			new PekTerminalRepository( $GLOBALS['wpdb'] ),
			$settings
		)
	),
	new PekLocationResolver( new LocationRepository( $GLOBALS['wpdb'] ), new PekAddressBuilder(), new PekLocationMappingRepository( $GLOBALS['wpdb'] ), $api, $settings )
);
$request_builder = new PekShipmentRequestBuilder(
	$settings,
	new PekShipmentDeclaredValueResolver(),
	new PekShipmentSenderWarehouseResolver( $settings, new PekSenderWarehouseService( $api, $settings, new PekSenderWarehouseSearchCache() ) ),
	new PekShipmentCargoBuilder( $settings ),
	new PekShipmentRecipientBuilder( new PekShipmentCourierAddressResolver() ),
	new PekShipmentCorrelationResolver(),
	new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ),
	$destination_resolver,
	new PekShipmentProductWeightResolver( $settings ),
	$credentials
);
$adapter = new PekShipmentAdapter(
	$api,
	$request_builder,
	$status_service,
	$shipment_service,
	$button_policy,
	new PekShipmentCreateResponseParser(),
	$actual_cost_resolver
);
$creation = new ShipmentCreationService( $repository, array( $adapter ), $actual_costs, null, null, array( new PekShipmentPersistenceMapper() ) );
pek_integration_assert( $creation instanceof ShipmentCreationService && $drafts instanceof OrderShipmentDraftFactory && $request_builder instanceof PekShipmentRequestBuilder, 'Integration smoke must construct real draft factory, request builder, adapter, creation service and mapper.' );

$counterparts = new PekSenderCounterpartService( $api, new PekPrivateAccessTokenService( $api ), $settings, $credentials );
$verified = $counterparts->verify_and_save();
pek_integration_assert( true === $verified['success'], 'Counterpart must be verified through fake private API before shipment builder.' );
pek_integration_assert( '' !== (string) ( $settings->sender_counterpart_snapshot()['identity_hash'] ?? '' ), 'Verified counterpart snapshot must include identity_hash.' );

$sms = new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings );
$settings_repository->set( PekSettings::SMS_RELEASE_LIMIT_RUB_KEY, 500000 );
$sms_result = $sms->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( $sms_result->success && 10000000 === $sms_result->effective_limit_kopecks, 'Official scalar CODMaxSum must produce effective 100000 RUB SMS limit.' );
$sms_over = $sms->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000001 );
pek_integration_assert( ! $sms_over->success, 'Declared value above official scalar CODMaxSum must fail before submit.' );
$http->connected_services_mode = 'available_string';
$sms_string_type = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_string_type->success, 'availableTypesOfDelivery numeric strings must fail strict SMS validation.' );
$http->connected_services_mode = 'duplicate_cod';
$sms_duplicate_cod = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_duplicate_cod->success, 'Duplicate CODMaxSum params must fail closed.' );
$http->connected_services_mode = 'cod_20000000';
$settings_repository->set( PekSettings::SMS_RELEASE_LIMIT_RUB_KEY, 50000000 );
$sms_exact_cache = new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings );
$sms_high_success = $sms_exact_cache->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 1500000000 );
$sms_high_fail = $sms_exact_cache->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 2000000001 );
pek_integration_assert( $sms_high_success->success && ! $sms_high_fail->success, 'SMS cache key must include exact declared value and must not merge values above the old cap.' );
$settings_repository->set( PekSettings::SMS_RELEASE_LIMIT_RUB_KEY, 500000 );
$http->connected_services_mode = 'malformed_sibling';
$sms_malformed_sibling = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_malformed_sibling->success, 'Malformed sibling row in specialConditionsWithParams must fail the whole SMS check.' );
$http->connected_services_mode = 'special_assoc';
$sms_special_assoc = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_special_assoc->success, 'Associative specialConditionsWithParams root must fail strict SMS validation.' );
$http->connected_services_mode = 'missing_uid';
$sms_missing_uid = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_missing_uid->success, 'Missing SMS UID must fail strict SMS validation.' );
$http->connected_services_mode = 'geography_malformed';
$sms_bad_geography = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_bad_geography->success, 'Malformed checknocalcservices row must fail strict geography validation.' );
$http->connected_services_mode = 'success';
$http->token_mode = 'malformed';
$submit_count_before_bad_token = count( $http->submit_bodies );
$bad_token_sms = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $bad_token_sms->success && $submit_count_before_bad_token === count( $http->submit_bodies ), 'Malformed private token must fail SMS path without submit.' );
$http->token_mode = 'success';

$admin_base = array(
	PekSettings::REQUEST_TIMEOUT_KEY => 15,
	PekSettings::REQUESTS_PER_MINUTE_KEY => 100,
	PekSettings::SENDER_LEGAL_FORM_KEY => 1,
	PekSettings::SENDER_FS_KEY => 'ООО',
	PekSettings::SENDER_FULL_NAME_KEY => 'Тестовый отправитель',
	PekSettings::SENDER_CONTACT_NAME_KEY => 'Петров Петр',
	PekSettings::CLIENT_CARD_KEY => 'CLIENT-CARD',
	PekSettings::DEFAULT_CARGO_DESCRIPTION_KEY => 'Товары интернет-магазина',
	PekSettings::SENDER_INN_KEY => '5400000000',
	PekSettings::SENDER_KPP_KEY => '540001001',
	PekSettings::SENDER_REGISTRATION_COUNTRY_KEY => 'RU',
	PekSettings::SENDER_PHONE_KEY => '83831234567',
	PekSettings::SENDER_EMAIL_KEY => 'sender@example.test',
	PekSettings::WAREHOUSE_SEARCH_RADIUS_KEY => 50,
	PekSettings::WAREHOUSE_SEARCH_LIMIT_KEY => 5,
	PekSettings::DESTINATION_TERMINAL_SEARCH_RADIUS_KEY => 50,
	PekSettings::DESTINATION_TERMINAL_SEARCH_LIMIT_KEY => 50,
	PekSettings::DESTINATION_TERMINAL_CACHE_TTL_KEY => 600,
	PekSettings::LOCATION_MAPPING_TTL_DAYS_KEY => 30,
	PekSettings::SMS_RELEASE_LIMIT_RUB_KEY => 500000,
	PekSettings::LIGHT_CARGO_BAG_PRICE_RUB_KEY => '70',
	PekSettings::LIGHT_CARGO_SEALING_PRICE_RUB_KEY => '20',
	PekSettings::LIGHT_CARGO_WEIGHT_LIMIT_G_KEY => 3000,
);
$settings->save_sender_counterpart( '11111111-2222-3333-4444-555555555555', $settings->sender_counterpart_snapshot() );
$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_PHONE_KEY => '83830000000', PekSettings::SENDER_EMAIL_KEY => 'sender2@example.test' ) ) );
pek_integration_assert( '' !== $settings->sender_counterpart_guid(), 'Phone/email changes must preserve verified counterpart identity.' );
$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_INN_KEY => '5400000001' ) ) );
pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'INN identity change must clear counterpart verification.' );
$settings->save_from_admin( $admin_base );
$counterparts->verify_and_save();
$http->confirmed_counterparties_mode = 'card_mismatch';
$settings_repository->set( PekSettings::CLIENT_CARD_KEY, 'OTHER-REQUESTED-CARD' );
$card_mismatch = $counterparts->verify_and_save();
pek_integration_assert( false === $card_mismatch['success'], 'Client-card mismatch must block counterpart verification.' );
$http->confirmed_counterparties_mode = 'success';
$settings_repository->set( PekSettings::CLIENT_CARD_KEY, 'CLIENT-CARD' );
$counterparts->verify_and_save();
$settings_repository->set( PekSettings::SENDER_EMAIL_KEY, 'sender@example.test' );
try {
	$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_EMAIL_KEY => 'bad-email' ) ) );
	pek_integration_assert( false, 'Invalid sender email must be rejected.' );
} catch ( InvalidArgumentException ) {
	pek_integration_assert( 'sender@example.test' === $settings->sender_email(), 'Invalid sender email must not erase existing valid value.' );
}
$counterparts->verify_and_save();
$before_atomic_guid = $settings->sender_counterpart_guid();
$before_atomic_snapshot = $settings->sender_counterpart_snapshot();
$before_atomic_inn = $settings->sender_inn();
try {
	$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_INN_KEY => '5400000999', PekSettings::SENDER_EMAIL_KEY => 'bad-email' ) ) );
	pek_integration_assert( false, 'Invalid sender email with changed INN must be rejected atomically.' );
} catch ( InvalidArgumentException ) {
	pek_integration_assert( $before_atomic_inn === $settings->sender_inn() && $before_atomic_guid === $settings->sender_counterpart_guid() && $before_atomic_snapshot === $settings->sender_counterpart_snapshot(), 'Invalid settings save must not partially write INN or counterpart state.' );
}
$before_numeric_guid = $settings->sender_counterpart_guid();
$before_numeric_snapshot = $settings->sender_counterpart_snapshot();
$before_numeric_timeout = $settings->request_timeout();
try {
	$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::REQUEST_TIMEOUT_KEY => 'not-a-number', PekSettings::SENDER_INN_KEY => '5400000999' ) ) );
	pek_integration_assert( false, 'Invalid numeric PEK setting must be rejected atomically.' );
} catch ( InvalidArgumentException ) {
	pek_integration_assert( $before_numeric_timeout === $settings->request_timeout() && $before_numeric_guid === $settings->sender_counterpart_guid() && $before_numeric_snapshot === $settings->sender_counterpart_snapshot(), 'Invalid numeric setting must not partially write settings or counterpart state.' );
}
$before_login_guid = $settings->sender_counterpart_guid();
$old_login_hash = $credentials->account_login_hash();
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'new-login', 'pek_api_key' => '' ) );
if ( $old_login_hash !== $credentials->account_login_hash() ) {
	$settings->save_sender_counterpart( '', array() );
}
pek_integration_assert( '' === $settings->sender_counterpart_guid(), 'Changing PEK login must clear counterpart verification.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'fake-login', 'pek_api_key' => '' ) );
$counterparts->verify_and_save();
$before_api_key_guid = $settings->sender_counterpart_guid();
$api_key_login_hash = $credentials->account_login_hash();
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'fake-login', 'pek_api_key' => 'rotated-fake-api-key' ) );
if ( $api_key_login_hash !== $credentials->account_login_hash() ) {
	$settings->save_sender_counterpart( '', array() );
}
pek_integration_assert( $before_api_key_guid === $settings->sender_counterpart_guid(), 'API key rotation with unchanged PEK login must preserve counterpart verification.' );
$http->confirmed_counterparties_mode = 'bad_inn_letters';
pek_integration_assert( false === $counterparts->verify_and_save()['success'], 'Malformed API INN characters must fail counterpart verification.' );
$http->confirmed_counterparties_mode = 'bad_kpp_letters';
pek_integration_assert( false === $counterparts->verify_and_save()['success'], 'Malformed API KPP characters must fail counterpart verification.' );
$http->confirmed_counterparties_mode = 'success';
$counterparts->verify_and_save();
pek_integration_assert( isset( $before_login_guid ), 'Counterpart account-binding setup must keep local variables live.' );

$order = new PekIntegrationOrder( 1001 );
$GLOBALS['wdc_pek_integration_orders'][1001] = $order;
$order->update_meta_data( '_shipping_dadata_status', 'house_selected' );
$order->update_meta_data( '_shipping_dadata_region_with_type', 'Московская область' );
$order->update_meta_data( '_shipping_dadata_city_with_type', 'г Видное' );
$order->update_meta_data( '_shipping_dadata_street_with_type', 'улица Советская' );
$order->update_meta_data( '_shipping_dadata_house', '10' );
$order->update_meta_data( '_shipping_dadata_flat', 'квартира 5' );
$order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$order->update_meta_data(
	'_wdc_delivery_calculation_data',
	array(
		'carrier_key' => PekSettings::CARRIER_KEY,
		'delivery_type' => DeliveryType::COURIER,
		'destination' => array(
			'location_id' => 77,
		),
		'package' => array(
			'products_weight_g' => 2500,
			'packaging_weight_g' => 500,
			'final_weight_g' => 3000,
			'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ),
		),
	)
);
$request = $drafts->create_request_from_order( $order );
pek_integration_assert( PekSettings::CARRIER_KEY === $request->carrier_key, 'Real draft factory must create PEK request.' );
$snapshot = $settings->sender_counterpart_snapshot();
$snapshot['account_login_hash'] = str_repeat( '0', 64 );
$settings->save_sender_counterpart( '11111111-2222-3333-4444-555555555555', $snapshot );
$connected_before_stale_account = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
$stale_account_preview = $creation->safe_preview( $request );
$connected_after_stale_account = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
pek_integration_assert( array() !== $stale_account_preview['errors'] && $connected_before_stale_account === $connected_after_stale_account, 'Wrong PEK account_login_hash must block preview before connected-services API call.' );
$counterparts->verify_and_save();
$before_submit = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/preregistration/submit/' ) ) );
$preview = $creation->safe_preview( $request );
$after_preview_submit = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/preregistration/submit/' ) ) );
pek_integration_assert( $before_submit === $after_preview_submit, 'Safe preview must not submit PEK preregistration.' );
pek_integration_assert( 'POST' === $preview['method'] && '/preregistration/submit/' === $preview['path'] && array() === $preview['errors'], 'Safe preview must return canonical PEK envelope: ' . wp_json_encode( $preview, JSON_UNESCAPED_UNICODE ) );
pek_integration_assert( 'shipping_dadata' === (string) ( $preview['body']['courier_address_source'] ?? '' ) && ! empty( $preview['body']['courier_region_present'] ) && ! empty( $preview['body']['courier_house_present'] ) && '' !== (string) ( $preview['body']['courier_address_hash'] ?? '' ), 'Safe preview must expose courier address evidence without raw address.' );
$create_result = $creation->create( $order, $request );
$submit_calls = array_values( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/preregistration/submit/' ) ) );
pek_integration_assert( true === $create_result->success, 'Production chain create must succeed through fake PEK submit.' );
pek_integration_assert( 1 === count( $submit_calls ), 'Fake preregistration submit must be called exactly once in success case.' );
pek_integration_assert_same_payload( $http->submit_bodies[0] ?? array(), pek_integration_fixture( 'preregistration-submit-courier.json' ), 'Courier production chain' );
$created = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
pek_integration_assert( '999940950644' === $created['tracking_number'] && '136' === $created['external_id'], 'Creation service and mapper must persist PEK identifiers.' );
pek_integration_assert_plain_data( $created );

$pickup_order = new PekIntegrationOrder( 1004 );
$GLOBALS['wdc_pek_integration_orders'][1004] = $pickup_order;
$pickup_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$pickup_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::PICKUP );
$pickup_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::PICKUP_RATE_ID );
$pickup_order->update_meta_data(
	'_wdc_delivery_calculation_data',
	array(
		'carrier_key' => PekSettings::CARRIER_KEY,
		'delivery_type' => DeliveryType::PICKUP,
		'destination' => array( 'location_id' => 77 ),
		'pickup' => array(
			'point_code' => 'WH-R',
			'address' => 'Россия, Московская область, Видное, Терминальная, дом 2',
			'branchId' => 'BR-R',
			'provider_destination_fingerprint' => 'pickup-fingerprint',
		),
		'package' => array(
			'products_weight_g' => 1200,
			'packaging_weight_g' => 41,
			'final_weight_g' => 1241,
			'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 20 ),
		),
	)
);
$pickup_order->update_meta_data(
	'_wdc_platform_rate_meta',
	array(
		'point_code' => 'WH-R',
		'provider_destination_fingerprint' => 'pickup-fingerprint',
		'pickup_provider_query' => array(
			'location_id' => 77,
			'address' => 'Россия, Московская область, Видное',
			'destination_fingerprint' => 'pickup-fingerprint',
		),
	)
);
$pickup_before_submit = count( $http->submit_bodies );
$pickup_request = $drafts->create_request_from_order( $pickup_order );
$pickup_with_coordinates = $pickup_request->to_array();
$pickup_with_coordinates['meta']['pickup_provider_query']['latitude'] = '55.5';
$pickup_with_coordinates['meta']['pickup_provider_query']['longitude'] = '37.7';
$pickup_with_coordinates['meta']['pickup_provider_query']['radius_km'] = 9999;
$pickup_with_coordinates['meta']['pickup_provider_query']['limit'] = 999;
$resolved_with_coordinates = $destination_resolver->resolve( ShipmentCreateRequest::from_array( $pickup_with_coordinates ) );
pek_integration_assert( 'BR-R' === $resolved_with_coordinates['branch_id'], 'Valid pickup coordinate pair must resolve while radius/limit stay carrier-bounded.' );
foreach (
	array(
		'partial' => array( 'latitude' => '55.5', 'longitude' => '' ),
		'array' => array( 'latitude' => array( '55.5' ), 'longitude' => '37.7' ),
		'bool' => array( 'latitude' => true, 'longitude' => '37.7' ),
		'nan' => array( 'latitude' => NAN, 'longitude' => '37.7' ),
		'out_of_range' => array( 'latitude' => '91', 'longitude' => '37.7' ),
	) as $coordinate_case => $coordinate_values
) {
	$bad_coordinates = $pickup_request->to_array();
	$bad_coordinates['meta']['pickup_provider_query']['latitude'] = $coordinate_values['latitude'];
	$bad_coordinates['meta']['pickup_provider_query']['longitude'] = $coordinate_values['longitude'];
	try {
		$destination_resolver->resolve( ShipmentCreateRequest::from_array( $bad_coordinates ) );
		pek_integration_assert( false, 'Malformed pickup coordinates must fail: ' . $coordinate_case );
	} catch ( RuntimeException $expected ) {
		pek_integration_assert( str_contains( $expected->getMessage(), 'координаты' ), 'Malformed pickup coordinate failure must be public-safe: ' . $coordinate_case );
	}
}
$pickup_preview = $creation->safe_preview( $pickup_request );
pek_integration_assert( count( $http->submit_bodies ) === $pickup_before_submit, 'Pickup safe preview must not submit PEK preregistration.' );
pek_integration_assert( DeliveryType::PICKUP === $pickup_request->delivery_type && 'WH-R' === (string) ( $pickup_preview['body']['receiver_warehouse_id'] ?? '' ), 'Pickup draft/preview must carry selected receiver warehouse.' );
$pickup_result = $creation->create( $pickup_order, $pickup_request );
pek_integration_assert( true === $pickup_result->success, 'Pickup production chain create must succeed through fake PEK submit.' );
pek_integration_assert( count( $http->submit_bodies ) === $pickup_before_submit + 1, 'Pickup fake submit must be called exactly once.' );
pek_integration_assert_same_payload( $http->submit_bodies[ $pickup_before_submit ] ?? array(), pek_integration_fixture( 'preregistration-submit-pickup.json' ), 'Pickup production chain' );
$pickup_created = $repository->find_by_carrier( $pickup_order, PekSettings::CARRIER_KEY );
pek_integration_assert( 'WH-R' === $pickup_created['pek_receiver_warehouse_id'] && 'BR-R' === $pickup_created['pek_receiver_branch_id'], 'Pickup fresh point code and branch must persist.' );
pek_integration_assert_plain_data( $pickup_created );

$duplicate = $creation->create( $order, $request );
pek_integration_assert( false === $duplicate->success && 'shipment_already_created' === $duplicate->error_code, 'Created PEK shipment must block duplicate automatic create.' );

$uncertain_order = new PekIntegrationOrder( 1002 );
$GLOBALS['wdc_pek_integration_orders'][1002] = $uncertain_order;
$uncertain_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$uncertain_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$uncertain_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$uncertain_order->update_meta_data( '_wdc_delivery_calculation_data', $order->get_meta( '_wdc_delivery_calculation_data', true ) );
$uncertain_request = $drafts->create_request_from_order( $uncertain_order );
$http->submit_mode = 'http500';
$uncertain_result = $creation->create( $uncertain_order, $uncertain_request );
pek_integration_assert( false === $uncertain_result->success && 'pek_uncertain_submit' === $uncertain_result->error_code, 'HTTP 500 submit must create uncertain result.' );
$uncertain_shipment = $repository->find_by_carrier( $uncertain_order, PekSettings::CARRIER_KEY );
pek_integration_assert( ! empty( $uncertain_shipment['pending_creation_in_carrier'] ) && '' !== (string) ( $uncertain_shipment['pek_correlation'] ?? '' ), 'HTTP 500 uncertain result must persist pending correlation.' );
pek_integration_assert_plain_data( $uncertain_shipment );

$malformed_order = new PekIntegrationOrder( 1003 );
$GLOBALS['wdc_pek_integration_orders'][1003] = $malformed_order;
$malformed_order->update_meta_data( '_shipping_dadata_status', 'house_selected' );
$malformed_order->update_meta_data( '_shipping_dadata_region_with_type', 'Московская область' );
$malformed_order->update_meta_data( '_shipping_dadata_city_with_type', 'г Видное' );
$malformed_order->update_meta_data( '_shipping_dadata_street_with_type', 'улица Советская' );
$malformed_order->update_meta_data( '_shipping_dadata_house', '10' );
$malformed_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$malformed_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$malformed_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$malformed_order->update_meta_data( '_wdc_delivery_calculation_data', $order->get_meta( '_wdc_delivery_calculation_data', true ) );
$malformed_request = $drafts->create_request_from_order( $malformed_order );
$http->submit_mode = 'malformed';
$malformed_result = $creation->create( $malformed_order, $malformed_request );
pek_integration_assert( false === $malformed_result->success && 'pek_uncertain_submit' === $malformed_result->error_code && ! empty( $repository->find_by_carrier( $malformed_order, PekSettings::CARRIER_KEY )['pending_creation_in_carrier'] ), 'Malformed HTTP 200 submit must persist uncertain pending shipment.' );

$logical_order = new PekIntegrationOrder( 1005 );
$GLOBALS['wdc_pek_integration_orders'][1005] = $logical_order;
$logical_order->update_meta_data( '_shipping_dadata_status', 'house_selected' );
$logical_order->update_meta_data( '_shipping_dadata_region_with_type', 'Московская область' );
$logical_order->update_meta_data( '_shipping_dadata_city_with_type', 'г Видное' );
$logical_order->update_meta_data( '_shipping_dadata_street_with_type', 'улица Советская' );
$logical_order->update_meta_data( '_shipping_dadata_house', '10' );
$logical_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$logical_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$logical_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$logical_order->update_meta_data( '_wdc_delivery_calculation_data', $order->get_meta( '_wdc_delivery_calculation_data', true ) );
$logical_request = $drafts->create_request_from_order( $logical_order );
$http->submit_mode = 'logical200';
$logical_result = $creation->create( $logical_order, $logical_request );
pek_integration_assert( false === $logical_result->success && array() === $repository->find_by_carrier( $logical_order, PekSettings::CARRIER_KEY ), 'Structured HTTP 200 logical rejection must not persist pending shipment.' );

$http400_order = new PekIntegrationOrder( 1006 );
$GLOBALS['wdc_pek_integration_orders'][1006] = $http400_order;
$http400_order->update_meta_data( '_shipping_dadata_status', 'house_selected' );
$http400_order->update_meta_data( '_shipping_dadata_region_with_type', 'Московская область' );
$http400_order->update_meta_data( '_shipping_dadata_city_with_type', 'г Видное' );
$http400_order->update_meta_data( '_shipping_dadata_street_with_type', 'улица Советская' );
$http400_order->update_meta_data( '_shipping_dadata_house', '10' );
$http400_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$http400_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$http400_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$http400_order->update_meta_data( '_wdc_delivery_calculation_data', $order->get_meta( '_wdc_delivery_calculation_data', true ) );
$http400_request = $drafts->create_request_from_order( $http400_order );
$http->submit_mode = 'http400';
$http400_result = $creation->create( $http400_order, $http400_request );
pek_integration_assert( false === $http400_result->success && array() === $repository->find_by_carrier( $http400_order, PekSettings::CARRIER_KEY ), 'HTTP 400 rejection must not persist pending shipment.' );
$http->submit_mode = 'success';

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
$order->update_meta_data( '_wdc_platform_carrier_key', 'yandex_delivery' );
$order->update_meta_data(
	'_wdc_delivery_calculation_data',
	array(
		'carrier_key' => 'yandex_delivery',
		'delivery_type' => DeliveryType::COURIER,
		'package' => array(
			'products_weight_g' => 9999,
			'final_weight_g' => 9999,
			'dimensions_cm' => array( 'length' => 99, 'width' => 99, 'height' => 99 ),
		),
	)
);

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
pek_integration_assert( 1000 === (int) ( $attached['places'][0]['weight_g'] ?? 0 ) && 10 === (int) ( $attached['places'][0]['length_cm'] ?? 0 ), 'Manual attach must preserve pending places when order was edited or carrier changed.' );
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
$address_order = new PekIntegrationOrder( 2001 );
$address_order->set_billing_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'Тверская улица, дом 1', 'address_2' => 'кв. 9' ) );
pek_integration_set_dadata( $address_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', 'квартира 5' );
pek_integration_set_dadata( $address_order, 'billing', 'Москва', 'Москва', 'Тверская улица', '1', '9' );
$shipping_evidence = $addresses->from_order_with_evidence( $address_order );
pek_integration_assert( 'shipping_dadata' === $shipping_evidence['evidence']['courier_address_source'] && str_contains( $shipping_evidence['address']->raw_address, 'Видное' ), 'Valid shipping DaData must remain the courier destination even when billing DaData differs.' );

$address_order_woo = new PekIntegrationOrder( 2002 );
$address_order_woo->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Новая, дом 11', 'address_2' => 'кв. 6' ) );
$address_order_woo->set_billing_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'Тверская улица, дом 1', 'address_2' => 'кв. 9' ) );
pek_integration_set_dadata( $address_order_woo, 'billing', 'Москва', 'Москва', 'Тверская улица', '1', '9' );
$woo_evidence = $addresses->from_order_with_evidence( $address_order_woo );
pek_integration_assert( 'billing_dadata' !== $woo_evidence['evidence']['courier_address_source'] && str_contains( $woo_evidence['address']->raw_address, 'Новая' ) && ! str_contains( $woo_evidence['address']->raw_address, 'Тверская' ), 'Billing DaData must not override a distinct filled shipping destination.' );

$stale_order = new PekIntegrationOrder( 2003 );
pek_integration_set_dadata( $stale_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '5' );
$stale_order->set_shipping_fields( array( 'address_1' => 'улица Новая, дом 11', 'address_2' => 'кв. 6' ) );
$stale_evidence = $addresses->from_order_with_evidence( $stale_order );
pek_integration_assert( ! str_contains( $stale_evidence['address']->raw_address, 'Советская' ) && str_contains( $stale_evidence['address']->raw_address, 'Новая' ), 'Stale DaData snapshot must not override edited Woo shipping address.' );

$billing_fallback_order = new PekIntegrationOrder( 2004 );
$billing_fallback_order->set_shipping_fields( array( 'country' => '', 'state' => '', 'city' => '', 'postcode' => '', 'address_1' => '', 'address_2' => '' ) );
$billing_fallback_order->set_billing_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'Тверская улица, дом 1', 'address_2' => 'кв. 5' ) );
pek_integration_set_dadata( $billing_fallback_order, 'billing', 'Москва', 'Москва', 'Тверская улица', '1', '5' );
$billing_evidence = $addresses->from_order_with_evidence( $billing_fallback_order );
pek_integration_assert( 'billing_dadata' === $billing_evidence['evidence']['courier_address_source'], 'Billing DaData may be used only when shipping destination is empty.' );

$non_ru_order = new PekIntegrationOrder( 2005 );
$non_ru_order->set_shipping_fields( array( 'country' => 'KZ', 'city' => 'Алматы', 'state' => '', 'address_1' => 'улица Абая, дом 10', 'address_2' => '' ) );
pek_integration_set_dadata( $non_ru_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10' );
try {
	$addresses->from_order_with_evidence( $non_ru_order );
	pek_integration_assert( false, 'Non-RU order with stale RU DaData must fail RU-only shipment creation.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'только RU' ), 'Non-RU courier failure must be explicit.' );
}

$settlement_order = new PekIntegrationOrder( 2006 );
$settlement_order->set_shipping_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $settlement_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Сосенское' );
$settlement_evidence = $addresses->from_order_with_evidence( $settlement_order );
pek_integration_assert( str_contains( $settlement_evidence['address']->raw_address, 'Москва' ) && str_contains( $settlement_evidence['address']->raw_address, 'поселение Сосенское' ), 'City and settlement must both be preserved when distinct.' );

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
$prefixed_apartment = $addresses->normalize( new Address( country_code: 'RU', region_name: 'Московская область', city: 'Видное', street: 'улица Советская', house: '10 к1', apartment: 'квартира 5' ) );
pek_integration_assert( 'Россия, Московская область, Видное, улица Советская, дом 10 к1, кв. 5' === $addresses->address_stock( $prefixed_apartment ), 'addressStock must include region and normalize apartment prefix once.' );
$moscow = $addresses->normalize( new Address( country_code: 'RU', region_name: 'город федерального значения Москва', city: 'Москва', street: 'Тверская улица', house: '1' ) );
pek_integration_assert( 'Россия, Москва, Тверская улица, дом 1' === $addresses->address_stock( $moscow ), 'Federal city region must not be duplicated.' );
$parsed = $addresses->normalize( new Address( country_code: 'RU', city: 'Москва', raw_address: '2-я Мелитопольская улица, 12Ас1' ) );
pek_integration_assert( '12Ас1' === $parsed->house, 'Courier parser must accept explicit house with letter/corpus.' );

$request_builder_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentRequestBuilder.php' ) ?: '';
pek_integration_assert( str_contains( $request_builder_source, "if ( '' !== \$client_card )" ), 'PEK request builder must omit empty counterpartClientCard.' );
pek_integration_assert( ! str_contains( $request_builder_source, "'counterpartClientCard' => \$this->settings->client_card()" ), 'PEK request builder must not serialize empty client card directly.' );

$all_urls = implode( "\n", array_map( static fn ( array $call ): string => $call['url'], $http->calls ) );
pek_integration_assert( str_contains( $all_urls, '/preregistration/submit/' ), 'Integration smoke must invoke fake PEK preregistration submit.' );
pek_integration_assert( ! str_contains( $all_urls, '/order/print/' ), 'Integration smoke must not download production documents.' );
pek_integration_assert( ! str_contains( $all_urls, '/cargos/cancelandreturncargo/' ), 'Integration smoke must not call PEK return API.' );

echo "PEK shipment integration smoke passed.\n";
