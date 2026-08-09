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
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['wdc_pek_integration_transients'][ $key ] );
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( preg_replace( '/[\x00-\x1F\x7F]+/u', '', (string) $value ) ?? (string) $value );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
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
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
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
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusResponseNormalizer;
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

function pek_integration_has_recursive_key( mixed $value, string $needle ): bool {
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( $value as $key => $child ) {
		if ( (string) $key === $needle || pek_integration_has_recursive_key( $child, $needle ) ) {
			return true;
		}
	}

	return false;
}

function pek_integration_fixture( string $name ): array {
	$path = dirname( __DIR__ ) . '/pek/fixtures/' . $name;
	$data = json_decode( file_get_contents( $path ) ?: '', true );
	pek_integration_assert( is_array( $data ), 'Fixture must be valid JSON: ' . $name );

	return $data;
}

function pek_integration_assert_no_private_markers( mixed $value, string $label ): void {
	$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$json = is_string( $json ) ? $json : '';
	foreach ( array( 'PRIVATE_TITLE', 'PRIVATE_GUID', 'PRIVATE_CARD', 'PRIVATE_INN', 'PRIVATE_KPP', 'PRIVATE_PASSPORT_SERIES', 'PRIVATE_PASSPORT_NUMBER', 'PRIVATE_TOKEN' ) as $marker ) {
		pek_integration_assert( ! str_contains( $json, $marker ), $label . ' must not contain private marker ' . $marker );
	}
}

function pek_integration_assert_same_payload( array $actual, array $expected, string $label ): void {
	$actual_json = wp_json_encode( $actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$expected_json = wp_json_encode( $expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	pek_integration_assert( $actual_json === $expected_json, $label . " payload mismatch.\nExpected: " . (string) $expected_json . "\nActual: " . (string) $actual_json );
}

function pek_integration_count_calls( PekIntegrationFakeHttp $http, string $needle ): int {
	return count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], $needle ) ) );
}

const PEK_INTEGRATION_SENDER_WAREHOUSE_A = '85974fc8-d0b8-11e5-9833-00155d668909';
const PEK_INTEGRATION_SENDER_WAREHOUSE_A_UPPER = '85974FC8-D0B8-11E5-9833-00155D668909';
const PEK_INTEGRATION_SENDER_WAREHOUSE_B = '5c7775d4-0013-11ec-80cf-00155d4a0436';
const PEK_INTEGRATION_RECEIVER_WAREHOUSE = '77968d17-65bc-11e9-80cd-00155d4a0436';
const PEK_INTEGRATION_MISSING_WAREHOUSE = 'c496b0c6-8e45-11df-bb3b-0019bbc941ce';

function pek_integration_set_dadata( PekIntegrationOrder $order, string $scope, string $region, string $city, string $street, string $house, string $flat = '', string $settlement = '', string $block = '', string $block_type = '', string $city_fias_id = '', string $settlement_fias_id = '' ): void {
	$order->update_meta_data( '_' . $scope . '_dadata_status', 'house_selected' );
	$order->update_meta_data( '_' . $scope . '_dadata_region_with_type', $region );
	$order->update_meta_data( '_' . $scope . '_dadata_region_fias_id', 'region-fias-' . $scope );
	$order->update_meta_data( '_' . $scope . '_dadata_city_with_type', $city );
	$order->update_meta_data( '_' . $scope . '_dadata_city_fias_id', $city_fias_id );
	$order->update_meta_data( '_' . $scope . '_dadata_settlement_with_type', $settlement );
	$order->update_meta_data( '_' . $scope . '_dadata_settlement_fias_id', $settlement_fias_id );
	$order->update_meta_data( '_' . $scope . '_dadata_street_with_type', $street );
	$order->update_meta_data( '_' . $scope . '_dadata_house', $house );
	$order->update_meta_data( '_' . $scope . '_dadata_house_type', 'д' );
	$order->update_meta_data( '_' . $scope . '_dadata_house_type_full', 'дом' );
	$order->update_meta_data( '_' . $scope . '_dadata_block', $block );
	$order->update_meta_data( '_' . $scope . '_dadata_block_type', $block_type );
	$order->update_meta_data( '_' . $scope . '_dadata_block_type_full', 'к' === $block_type ? 'корпус' : ( 'стр' === $block_type ? 'строение' : '' ) );
	$order->update_meta_data( '_' . $scope . '_dadata_flat', $flat );
	$order->update_meta_data( '_' . $scope . '_dadata_flat_type', '' !== $flat ? 'кв' : '' );
	$order->update_meta_data( '_' . $scope . '_dadata_flat_type_full', '' !== $flat ? 'квартира' : '' );
	$order->update_meta_data( '_' . $scope . '_dadata_fias_id', '' !== $settlement_fias_id ? $settlement_fias_id : $city_fias_id );
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
	public string $status_mode = 'expanded';
	public string $branches_all_mode = 'success';
	public string $sender_nearest_mode = 'success';
	public string $findzone_address_mode = 'exact';

	private function requested_cargo_code( array $args ): string {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$codes = is_array( $body ) ? ( $body['cargoCodes'] ?? array() ) : array();
		if ( is_array( $codes ) && isset( $codes[0] ) && is_string( $codes[0] ) && '' !== trim( $codes[0] ) ) {
			return trim( $codes[0] );
		}

		return 'PEK-777';
	}

	public function request( string $method, string $url, array $args ): array {
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/cargos/basicstatus/' ) ) {
			$status = array_shift( $this->statuses );
			$status = is_string( $status ) ? $status : 'Оформлен';
			$code = $this->requested_cargo_code( $args );
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'cargos' => array(
							array(
								'cargo' => array(
									'code' => $code,
								),
								'info' => array(
									'cargoStatus' => $status,
								),
							),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/cargos/status/' ) ) {
			if ( 'expanded_403' === $this->status_mode ) {
				return array( 'status' => 403, 'body' => wp_json_encode( array( 'title' => 'Forbidden', 'message' => 'Expanded status unavailable' ), JSON_UNESCAPED_UNICODE ) );
			}
			$status = array_shift( $this->statuses );
			$status = is_string( $status ) ? $status : 'Прибыл';
			$code = $this->requested_cargo_code( $args );
			if ( 'malformed_status' === $this->status_mode ) {
				return array(
					'status' => 200,
					'body' => wp_json_encode(
						array(
							'cargos' => array(
								array(
									'cargo' => array( 'code' => $code ),
									'info' => array( 'cargoStatus' => array( 'bad' ) ),
								),
							),
						),
						JSON_UNESCAPED_UNICODE
					),
				);
			}
			$sms_flag = 'expanded_false' === $this->status_mode ? false : true;
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'cargos' => array(
							array(
								'cargo' => array(
									'code' => $code,
									'cargoBarCode' => 'BAR-777',
									'positionBarCodes' => array( 'POS-1', 'POS-2' ),
								),
								'info' => array(
									'cargoStatus' => $status,
									'cargoStatusId' => '42',
									'takeOnStockDateTime' => 'Принят к перевозке' === $status ? '2026-08-06 12:00:00' : '',
								),
								'receiver' => array(
									'receivingBySMSCode' => $sms_flag,
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
			if ( 'http500' === $this->token_mode ) {
				return array( 'status' => 500, 'body' => wp_json_encode( array( 'error' => array( 'title' => 'boom' ) ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'malformed' === $this->token_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'access_token' => array(), 'token_type' => 'Bearer', 'expires_in_unix' => '1893456000' ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'private_marker_malformed' === $this->token_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'access_token' => 'PRIVATE_TOKEN', 'token_type' => 'Wrong', 'expires_in_unix' => '1893456000' ), JSON_UNESCAPED_UNICODE ) );
			}
			return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/private-token-response.json' ) ?: '{}' );
		}
		if ( str_contains( $url, '/branches/nearestdepartments/' ) ) {
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$address = is_array( $body ) ? (string) ( $body['address'] ?? '' ) : '';
			$is_sender = str_contains( $address, 'Новосибирск' );
			if ( $is_sender && 'http403' === $this->sender_nearest_mode ) {
				return array( 'status' => 403, 'body' => wp_json_encode( array( 'error' => array( 'title' => 'Forbidden', 'message' => 'Access denied' ) ), JSON_UNESCAPED_UNICODE ) );
			}
			$warehouse_id = $is_sender ? PEK_INTEGRATION_SENDER_WAREHOUSE_A_UPPER : PEK_INTEGRATION_RECEIVER_WAREHOUSE;
			if ( $is_sender && str_contains( $address, 'Большая' ) ) {
				$warehouse_id = PEK_INTEGRATION_SENDER_WAREHOUSE_B;
			}
			if ( $is_sender && 'missing_id' === $this->sender_nearest_mode ) {
				$warehouse_id = PEK_INTEGRATION_MISSING_WAREHOUSE;
			}
			$branch_id = str_contains( $address, 'Новосибирск' ) ? 'BR-S' : 'BR-R';
			$branch_title = str_contains( $address, 'Новосибирск' ) ? 'Новосибирск' : 'Москва';
			$division_name = $warehouse_id === PEK_INTEGRATION_SENDER_WAREHOUSE_B ? 'Склад B' : ( str_contains( $address, 'Новосибирск' ) ? 'Склад A' : 'Склад получателя' );
			$department_address = str_contains( $address, 'Новосибирск' ) ? ( $warehouse_id === PEK_INTEGRATION_SENDER_WAREHOUSE_B ? 'Россия, Новосибирск, улица Большая, 280' : 'Россия, Новосибирск, Складская, дом 1' ) : 'Россия, Московская область, Видное, Терминальная, дом 2';
			$max_weight = ( $is_sender && 'constraints_exceeded' === $this->sender_nearest_mode ) ? 0.1 : 1000;
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'free_only' === $this->sender_nearest_mode && $is_sender ? 'paidDepartments' : 'freeDepartments' => array(
							array(
								'warehouseId' => $warehouse_id,
								'branchId' => $branch_id,
								'branchTitle' => $branch_title,
								'branchName' => $branch_title,
								'divisionName' => $division_name,
								'address' => $department_address,
								'departmentTypeId' => 1,
								'departmentType' => 'Склад',
								'coordinates' => array( 'latitude' => '55.5', 'longitude' => '37.7' ),
								'maxWeight' => $max_weight,
								'maxVolume' => 100,
								'maxDimension' => 10,
								'maxWeightOnePlace' => 1000,
								'maxCount' => 100,
							),
						),
						'free_only' === $this->sender_nearest_mode && $is_sender ? 'freeDepartments' : 'paidDepartments' => array(),
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/branches/all/' ) ) {
			$division_kinds = array( array( 'type' => 3, 'operations' => array( 'Прием грузов' ) ) );
			$warehouse_kinds = array();
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$requested_warehouse_id = is_array( $body ) ? (string) ( $body['warehouseId'] ?? '' ) : '';
			if ( 'filtered_empty_then_unfiltered_success' === $this->branches_all_mode && '' !== $requested_warehouse_id ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'branches' => array() ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'filtered_empty_then_unfiltered_missing' === $this->branches_all_mode && '' !== $requested_warehouse_id ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'branches' => array() ), JSON_UNESCAPED_UNICODE ) );
			}
			$warehouse_id = PEK_INTEGRATION_SENDER_WAREHOUSE_A;
			$warehouse_limits = array(
				'maxWeight' => 1000,
				'maxVolume' => 100,
				'maxDimension' => 10,
				'maxWeightOnePlace' => 1000,
				'maxCount' => 100,
			);
			if ( 'missing_id' === $this->branches_all_mode ) {
				$warehouse_id = PEK_INTEGRATION_MISSING_WAREHOUSE;
			}
			if ( 'filtered_empty_then_unfiltered_missing' === $this->branches_all_mode ) {
				$warehouse_id = PEK_INTEGRATION_MISSING_WAREHOUSE;
			}
			if ( 'no_ltl_type' === $this->branches_all_mode ) {
				$division_kinds = array( array( 'type' => 1, 'operations' => array( 'Прием грузов' ) ) );
			}
			if ( 'no_acceptance' === $this->branches_all_mode ) {
				$division_kinds = array( array( 'type' => 3, 'operations' => array( 'Выдача грузов' ) ) );
			}
			if ( 'warehouse_capability' === $this->branches_all_mode ) {
				$division_kinds = array();
				$warehouse_kinds = array( array( 'type' => 3, 'operations' => array( 'Приём грузов' ) ) );
			}
			if ( 'multiple_type3_rows' === $this->branches_all_mode ) {
				$division_kinds = array(
					array( 'type' => 3, 'operations' => array( 'Выдача грузов' ) ),
					array( 'type' => 3, 'operations' => array( 'Прием грузов' ) ),
				);
			}
			if ( 'constraints_exceeded' === $this->branches_all_mode ) {
				$warehouse_limits['maxWeight'] = 0.1;
			}
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
										'kindsOfTransportation' => $division_kinds,
										'warehouses' => array(
											array(
												'id' => $warehouse_id,
												'address' => 'Россия, Новосибирск, Складская, дом 1',
												'coordinatesobj' => array( 'latitude' => '55.0', 'longitude' => '82.9' ),
												'types' => array( 3 ),
												'kindsOfTransportation' => $warehouse_kinds,
												'maxWeight' => $warehouse_limits['maxWeight'],
												'maxVolume' => $warehouse_limits['maxVolume'],
												'maxDimension' => $warehouse_limits['maxDimension'],
												'maxWeightOnePlace' => $warehouse_limits['maxWeightOnePlace'],
												'maxCount' => $warehouse_limits['maxCount'],
											),
											array(
												'id' => PEK_INTEGRATION_SENDER_WAREHOUSE_B,
												'address' => 'Россия, Новосибирск, улица Большая, 280',
												'coordinatesobj' => array( 'latitude' => '55.1', 'longitude' => '82.8' ),
												'types' => array( 3 ),
												'kindsOfTransportation' => $warehouse_kinds,
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
			if ( 'empty' === $this->findzone_address_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array(), JSON_UNESCAPED_UNICODE ) );
			}
			$precision = 'near' === $this->findzone_address_mode ? 'near' : 'exact';
			if ( 'bad' === $this->findzone_address_mode ) {
				$precision = 'bad';
			}
			if ( 'missing_precision' === $this->findzone_address_mode ) {
				$precision = '';
			}
			$country = 'country_mismatch' === $this->findzone_address_mode ? 'KZ' : 'RU';
			$geodata = array(
				'Address' => array(
					'formatted' => 'Россия, г Москва, Ходынский б-р, дом 13, кв. 1',
					'country_code' => $country,
				),
			);
			if ( '' !== $precision ) {
				$geodata['precision'] = $precision;
			}
			return array(
				'status' => 200,
				'body' => wp_json_encode(
					array(
						'zoneId' => 'fake-zone',
						'zoneName' => 'Москва',
						'branchUID' => 'fake-moscow-branch',
						'branchTitle' => 'Москва',
						'mainWarehouseId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
						'GeoData' => $geodata,
					),
					JSON_UNESCAPED_UNICODE
				),
			);
		}
		if ( str_contains( $url, '/counterparts/confirmedaccesstocounterparties/' ) ) {
			if ( 'mixed_legal' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/confirmed-counterparties-mixed-response.json' ) ?: '[]' );
			}
			if ( 'mixed_ip' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-mixed-response.json' );
				$rows[1]['legalForm'] = 2;
				$rows[1]['guid'] = '33333333-3333-4333-8333-333333333333';
				$rows[1]['legal']['inn'] = '540000000000';
				$rows[1]['legal']['kpp'] = null;
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'physical_only' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 200, 'body' => file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/confirmed-counterparties-physical-only-response.json' ) ?: '[]' );
			}
			if ( 'unknown_legal_form' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'legalForm' => 4 ) ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'legal_form_string' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'legalForm' => '3' ) ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'legal_form_bool' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'legalForm' => true ) ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'malformed_after_physical' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-mixed-response.json' );
				$rows[1]['legal']['inn'] = array( 'PRIVATE_INN' );
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'physical_malformed_optional' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-mixed-response.json' );
				$rows[0]['title'] = array( 'PRIVATE_TITLE' );
				$rows[0]['guid'] = array( 'PRIVATE_GUID' );
				$rows[0]['counterpartClientCard'] = array( 'PRIVATE_CARD' );
				$rows[0]['documents'] = array( array( 'series' => 'PRIVATE_PASSPORT_SERIES', 'number' => 'PRIVATE_PASSPORT_NUMBER' ) );
				$rows[0]['legal'] = array( 'inn' => 'PRIVATE_INN', 'kpp' => 'PRIVATE_KPP' );
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'no_match' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-response.json' );
				$rows[0]['legal']['inn'] = '5400000001';
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'multiple_exact' === $this->confirmed_counterparties_mode ) {
				$rows = pek_integration_fixture( 'confirmed-counterparties-response.json' );
				$rows[] = $rows[0];
				$rows[1]['guid'] = '44444444-4444-4444-8444-444444444444';
				return array( 'status' => 200, 'body' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'http_failure' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 500, 'body' => wp_json_encode( array( 'error' => array( 'title' => 'boom' ) ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'logical_error' === $this->confirmed_counterparties_mode ) {
				return array( 'status' => 200, 'body' => wp_json_encode( array( 'error' => array( 'title' => 'Rejected', 'message' => 'Rejected' ) ), JSON_UNESCAPED_UNICODE ) );
			}
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
					'body' => wp_json_encode( array( array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ), array( 'specialCondition' => 'malformed' ) ), JSON_UNESCAPED_UNICODE ),
				);
			}
			if ( 'geography_missing_uid' === $this->connected_services_mode ) {
				return array(
					'status' => 200,
					'body' => wp_json_encode( array( array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ), array( 'specialCondition' => array() ) ), JSON_UNESCAPED_UNICODE ),
				);
			}
			if ( 'geography_duplicate' === $this->connected_services_mode ) {
				return array(
					'status' => 200,
					'body' => wp_json_encode( array( array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ), array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ) ), JSON_UNESCAPED_UNICODE ),
				);
			}
			if ( 'geography_unrelated' === $this->connected_services_mode ) {
				return array(
					'status' => 200,
					'body' => wp_json_encode( array( array( 'specialCondition' => array( 'UID' => '00000000-0000-0000-0000-000000000000' ) ), array( 'specialCondition' => array( 'UID' => 'ffb40421-4761-11e8-80c9-00155d668927' ) ) ), JSON_UNESCAPED_UNICODE ),
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
				return array(
					'status' => 200,
					'body' => wp_json_encode(
						array(
							'error' => array(
								'title' => 'Validation',
								'message' => 'Invalid request for +79991234567 receiver@example.test CARD-123',
								'fields' => array(
									array( 'Key' => 'cargos[0].receiver.personPhones', 'Value' => array( 'Phone +79991234567 is rejected.' ) ),
									array( 'Key' => 'cargos[0].receiver.email', 'Value' => array( 'Email receiver@example.test is rejected.' ) ),
									array( 'Key' => 'sender.counterpartClientCard', 'Value' => array( 'Card CARD-123 is rejected.' ) ),
								),
							),
						),
						JSON_UNESCAPED_UNICODE
					),
				);
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
		'first_name' => 'Иван',
		'last_name' => 'Иванов',
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

	public function get_billing_first_name(): string {
		return $this->billing['first_name'];
	}

	public function get_billing_last_name(): string {
		return $this->billing['last_name'];
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
$settings_repository->set( PekSettings::REQUESTS_PER_MINUTE_KEY, 1000 );
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

$settings = new PekSettings( $settings_repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$credentials = new PekCredentials( $settings_repository, $encryption );
$http = new PekIntegrationFakeHttp();
$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
$settings->save_sender_warehouse(
	array(
		'warehouseId' => PEK_INTEGRATION_SENDER_WAREHOUSE_A,
		'branchId' => 'BR-S',
		'title' => 'Склад A',
		'address' => 'Россия, Новосибирск, Складская, дом 1',
		'source' => 'default',
	)
);
$repository = new OrderShipmentRepository();
$actual_costs = new ShipmentActualCostService( $repository );
$mapping = new PekStatusMapping();
$status_service = new PekShipmentStatusService( $api, $mapping, $repository, $actual_costs, new PekShipmentStatusResponseNormalizer() );
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
	array(
		'id' => 78,
		'country_code' => 'RU',
		'region_name' => 'Москва',
		'region_type' => 'г',
		'city_name' => 'Москва',
		'city_type' => 'г',
		'city_fias_id' => 'moscow-city-fias',
		'fias_id' => 'moscow-city-fias',
		'settlement_name' => 'Москва',
		'display_name' => 'Москва',
		'active' => 1,
		'latitude' => '',
		'longitude' => '',
	),
	array(
		'id' => 79,
		'country_code' => 'RU',
		'region_name' => 'Москва',
		'region_type' => 'г',
		'city_name' => 'Москва',
		'city_type' => 'г',
		'city_fias_id' => 'moscow-city-fias',
		'fias_id' => 'sosenskoye-fias',
		'settlement_name' => 'поселение Сосенское',
		'settlement_type' => 'поселение',
		'display_name' => 'Москва, поселение Сосенское',
		'active' => 1,
		'latitude' => '',
		'longitude' => '',
	),
	array(
		'id' => 80,
		'country_code' => 'RU',
		'region_name' => 'Московская область',
		'region_type' => 'область',
		'city_name' => 'Видное',
		'city_type' => 'г',
		'display_name' => 'Видное без FIAS',
		'active' => 1,
		'latitude' => '',
		'longitude' => '',
	),
	array(
		'id' => 81,
		'country_code' => 'RU',
		'region_name' => 'Москва',
		'region_type' => 'г',
		'city_name' => 'Москва',
		'city_type' => 'г',
		'city_fias_id' => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
		'fias_id' => '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
		'settlement_name' => 'Москва',
		'display_name' => 'Москва live-style FIAS',
		'active' => 1,
		'latitude' => '',
		'longitude' => '',
	),
	array(
		'id' => 82,
		'country_code' => 'RU',
		'region_name' => 'Санкт-Петербург',
		'region_type' => 'г',
		'city_name' => 'Санкт-Петербург',
		'city_type' => 'г',
		'city_fias_id' => 'c2deb16a-0330-4f05-821f-1d09c93331e6',
		'fias_id' => 'c2deb16a-0330-4f05-821f-1d09c93331e6',
		'settlement_name' => 'Санкт-Петербург',
		'display_name' => 'Санкт-Петербург live-style FIAS',
		'active' => 1,
		'latitude' => '',
		'longitude' => '',
	),
);
$GLOBALS['wpdb']->pek_location_mappings = array();
$GLOBALS['wpdb']->pek_terminals = array();
$drafts = new OrderShipmentDraftFactory( new DeliveryServiceRepository( $GLOBALS['wpdb'] ), new ShipmentServiceSettings(), null, null, null, null, null, null, null, null, null, null, $settings, new PekShipmentCourierAddressResolver() );
$manual_contexts = new PekManualAttachContextResolver( $drafts, $repository );
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
	new PekLocationResolver( new LocationRepository( $GLOBALS['wpdb'] ), new PekAddressBuilder(), new PekLocationMappingRepository( $GLOBALS['wpdb'] ), $api, $settings ),
	new LocationRepository( $GLOBALS['wpdb'] )
);
$sender_warehouse_service = new PekSenderWarehouseService( $api, $settings, new PekSenderWarehouseSearchCache() );
$request_builder = new PekShipmentRequestBuilder(
	$settings,
	new PekShipmentDeclaredValueResolver(),
	new PekShipmentSenderWarehouseResolver( $settings, $sender_warehouse_service ),
	new PekShipmentCargoBuilder( $settings ),
	new PekShipmentRecipientBuilder( new PekShipmentCourierAddressResolver(), new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() ),
	new PekShipmentCorrelationResolver(),
	new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ),
	$destination_resolver,
	new PekShipmentProductWeightResolver( $settings ),
	$credentials,
	new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer()
);
$attempt_uuid_sequence = array(
	'11111111-1111-4111-8111-111111111111',
	'22222222-2222-4222-8222-222222222222',
	'33333333-3333-4333-8333-333333333333',
	'44444444-4444-4444-8444-444444444444',
	'55555555-5555-4555-8555-555555555555',
);
$attempts = new ShipmentCreationAttemptService(
	$repository,
	static function () use ( &$attempt_uuid_sequence ): string {
		$id = array_shift( $attempt_uuid_sequence );
		return is_string( $id ) ? $id : '99999999-9999-4999-8999-999999999999';
	}
);
$shipment_service = new PekShipmentService( $api, $status_service, $repository, $button_policy, $actual_costs, $mapping, $manual_contexts, $attempts );
$adapter = new PekShipmentAdapter(
	$api,
	$request_builder,
	$status_service,
	$shipment_service,
	$button_policy,
	new PekShipmentCreateResponseParser(),
	$actual_cost_resolver
);
$creation = new ShipmentCreationService( $repository, array( $adapter ), $actual_costs, null, null, array( new PekShipmentPersistenceMapper() ), $attempts );
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
pek_integration_assert( ! $sms_bad_geography->success, 'Malformed checknocalcservices sibling after valid SMS row must fail strict geography validation.' );
$http->connected_services_mode = 'geography_missing_uid';
$sms_missing_geography_uid = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_missing_geography_uid->success, 'Geography row without UID must fail strict validation.' );
$http->connected_services_mode = 'geography_duplicate';
$sms_duplicate_geography = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $sms_duplicate_geography->success, 'Duplicate geography SMS UID rows must fail closed.' );
$http->connected_services_mode = 'geography_unrelated';
$sms_unrelated_geography = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( $sms_unrelated_geography->success, 'Unrelated valid geography row plus exactly one SMS row must pass.' );
$http->connected_services_mode = 'success';
$http->token_mode = 'malformed';
$submit_count_before_bad_token = count( $http->submit_bodies );
$bad_token_sms = ( new PekSmsReleaseAvailabilityService( $api, new PekPrivateAccessTokenService( $api ), $settings ) )->check( '11111111-2222-3333-4444-555555555555', 'BR-S', 'BR-R', 10000000 );
pek_integration_assert( ! $bad_token_sms->success && $submit_count_before_bad_token === count( $http->submit_bodies ), 'Malformed private token must fail SMS path without submit.' );
$http->token_mode = 'success';

$admin_base = array(
	PekSettings::REQUEST_TIMEOUT_KEY => 15,
	PekSettings::REQUESTS_PER_MINUTE_KEY => 1000,
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

$http->confirmed_counterparties_mode = 'mixed_legal';
$mixed_legal = $counterparts->verify_and_save();
pek_integration_assert( true === $mixed_legal['success'] && 1 === (int) ( $mixed_legal['diagnostic']['physical_rows'] ?? 0 ) && 1 === (int) ( $mixed_legal['diagnostic']['legal_entity_rows'] ?? 0 ) && 1 === (int) ( $mixed_legal['diagnostic']['matched_rows'] ?? 0 ), 'Mixed physical + legal counterparty list must verify configured legal entity.' );
pek_integration_assert( '22222222-2222-4222-8222-222222222222' === $settings->sender_counterpart_guid(), 'Mixed legal verification must persist matched legal GUID, not physical GUID.' );
pek_integration_assert_no_private_markers( $mixed_legal, 'Mixed legal verification result' );
pek_integration_assert_no_private_markers( $settings->sender_counterpart_snapshot(), 'Mixed legal snapshot' );
pek_integration_assert_no_private_markers( $GLOBALS['wdc_pek_integration_options'], 'Mixed legal options' );

$http->confirmed_counterparties_mode = 'physical_malformed_optional';
$physical_malformed_optional = $counterparts->verify_and_save();
pek_integration_assert( true === $physical_malformed_optional['success'], 'Malformed optional fields inside official physical row must not poison relevant legal matching.' );
pek_integration_assert_no_private_markers( $physical_malformed_optional, 'Physical optional skip result' );
pek_integration_assert_no_private_markers( $settings->sender_counterpart_snapshot(), 'Physical optional skip snapshot' );

$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_LEGAL_FORM_KEY => 2, PekSettings::SENDER_INN_KEY => '540000000000', PekSettings::SENDER_KPP_KEY => '' ) ) );
$http->confirmed_counterparties_mode = 'mixed_ip';
$mixed_ip = $counterparts->verify_and_save();
pek_integration_assert( true === $mixed_ip['success'] && 1 === (int) ( $mixed_ip['diagnostic']['physical_rows'] ?? 0 ) && 1 === (int) ( $mixed_ip['diagnostic']['entrepreneur_rows'] ?? 0 ), 'Mixed physical + IP counterparty list must verify configured IP.' );
pek_integration_assert_no_private_markers( $mixed_ip, 'Mixed IP verification result' );
$settings->save_from_admin( $admin_base );

$http->confirmed_counterparties_mode = 'physical_only';
$physical_only = $counterparts->verify_and_save();
pek_integration_assert( false === $physical_only['success'] && 'counterpart_match' === (string) ( $physical_only['diagnostic']['stage'] ?? '' ) && 'no_match' === (string) ( $physical_only['diagnostic']['reason'] ?? '' ) && 1 === (int) ( $physical_only['diagnostic']['physical_rows'] ?? 0 ), 'Physical-only counterparty list must be no-match, not contract failure.' );
pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Physical-only verification failure must clear old counterpart snapshot.' );
pek_integration_assert_no_private_markers( $physical_only, 'Physical-only result' );
pek_integration_assert_no_private_markers( $GLOBALS['wdc_pek_integration_options'], 'Physical-only options' );

foreach ( array( 'unknown_legal_form' => 'unsupported_legal_form', 'legal_form_string' => 'legal_form_type', 'legal_form_bool' => 'legal_form_type', 'malformed_after_physical' => 'legal_inn_type' ) as $mode => $reason ) {
	$http->confirmed_counterparties_mode = 'success';
	$counterparts->verify_and_save();
	$http->confirmed_counterparties_mode = $mode;
	$result = $counterparts->verify_and_save();
	pek_integration_assert( false === $result['success'] && 'counterpart_contract' === (string) ( $result['diagnostic']['stage'] ?? '' ) && $reason === (string) ( $result['diagnostic']['reason'] ?? '' ), 'Counterpart contract failure must expose safe reason for mode ' . $mode );
	pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Counterpart contract failure must clear old snapshot for mode ' . $mode );
	pek_integration_assert_no_private_markers( $result, 'Counterpart contract failure result ' . $mode );
}

foreach ( array( 'no_match' => 'no_match', 'multiple_exact' => 'multiple_matches' ) as $mode => $reason ) {
	$http->confirmed_counterparties_mode = 'success';
	$counterparts->verify_and_save();
	$http->confirmed_counterparties_mode = $mode;
	$result = $counterparts->verify_and_save();
	pek_integration_assert( false === $result['success'] && 'counterpart_match' === (string) ( $result['diagnostic']['stage'] ?? '' ) && $reason === (string) ( $result['diagnostic']['reason'] ?? '' ), 'Counterpart matching failure must expose safe reason for mode ' . $mode );
	pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Counterpart matching failure must clear old snapshot for mode ' . $mode );
}

$http->confirmed_counterparties_mode = 'success';
$counterparts->verify_and_save();
$http->token_mode = 'private_marker_malformed';
$token_failure = ( new PekSenderCounterpartService( $api, new PekPrivateAccessTokenService( $api ), $settings, $credentials ) )->verify_and_save();
pek_integration_assert( false === $token_failure['success'] && 'private_token' === (string) ( $token_failure['diagnostic']['stage'] ?? '' ) && str_contains( (string) $token_failure['message'], 'private token' ), 'Private token failure must have a distinct safe stage/message.' );
pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Private token failure must clear old counterpart snapshot.' );
pek_integration_assert_no_private_markers( $token_failure, 'Private token failure result' );
$http->token_mode = 'success';

foreach ( array( 'http_failure' => 'counterpart_api', 'logical_error' => 'counterpart_logical' ) as $mode => $stage ) {
	$http->confirmed_counterparties_mode = 'success';
	$counterparts->verify_and_save();
	$http->confirmed_counterparties_mode = $mode;
	$result = $counterparts->verify_and_save();
	pek_integration_assert( false === $result['success'] && $stage === (string) ( $result['diagnostic']['stage'] ?? '' ), 'Counterpart API/logical failure must expose distinct stage for mode ' . $mode );
	pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Counterpart API/logical failure must clear old snapshot for mode ' . $mode );
}

$http->confirmed_counterparties_mode = 'success';
$counterparts->verify_and_save();
$http->confirmed_counterparties_mode = 'card_mismatch';
$settings_repository->set( PekSettings::CLIENT_CARD_KEY, 'OTHER-REQUESTED-CARD' );
$card_mismatch = $counterparts->verify_and_save();
pek_integration_assert( false === $card_mismatch['success'], 'Client-card mismatch must block counterpart verification.' );
pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Failed counterpart verification must clear old GUID and snapshot.' );
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
$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_PHONE_KEY => '8 (999) 123-45-67' ) ) );
pek_integration_assert( '+79991234567' === $settings->sender_phone(), 'Sender phone 8XXXXXXXXXX with formatting must normalize to +7.' );
$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_PHONE_KEY => '79991234567' ) ) );
pek_integration_assert( '+79991234567' === $settings->sender_phone(), 'Sender phone 7XXXXXXXXXX must normalize to +7.' );
$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_PHONE_KEY => '+79991234567' ) ) );
pek_integration_assert( '+79991234567' === $settings->sender_phone(), 'Sender phone +7XXXXXXXXXX must stay normalized.' );
$before_phone = $settings->sender_phone();
foreach ( array( '+7abc9991234567', '++79991234567', "+7999\n1234567" ) as $bad_sender_phone ) {
	try {
		$settings->save_from_admin( array_merge( $admin_base, array( PekSettings::SENDER_PHONE_KEY => $bad_sender_phone ) ) );
		pek_integration_assert( false, 'Malformed sender phone must be rejected: ' . json_encode( $bad_sender_phone ) );
	} catch ( InvalidArgumentException ) {
		pek_integration_assert( $before_phone === $settings->sender_phone(), 'Invalid sender phone must be rejected atomically without changing previous setting.' );
	}
}
$settings->save_from_admin( $admin_base );
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
pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Malformed API INN must clear counterpart verification.' );
$counterparts->verify_and_save();
$http->confirmed_counterparties_mode = 'bad_kpp_letters';
pek_integration_assert( false === $counterparts->verify_and_save()['success'], 'Malformed API KPP characters must fail counterpart verification.' );
pek_integration_assert( '' === $settings->sender_counterpart_guid() && array() === $settings->sender_counterpart_snapshot(), 'Malformed API KPP must clear counterpart verification.' );
$http->confirmed_counterparties_mode = 'success';
$counterparts->verify_and_save();
$GLOBALS['wdc_pek_integration_transients'] = array();
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
$courier_draft = $drafts->draft_array( $order );
$courier_services = is_array( $courier_draft['services'] ?? null ) ? $courier_draft['services'] : array();
pek_integration_assert( 1 === count( $courier_services ) && DeliveryType::COURIER === (string) ( $courier_services[0]['delivery_type'] ?? '' ) && 'ПЭК курьером' === (string) ( $courier_services[0]['title'] ?? '' ), 'PEK courier draft must expose exactly one trusted modal scenario.' );
try {
	$drafts->create_request_from_admin_data( $order, array( 'delivery_type' => DeliveryType::PICKUP ) );
	pek_integration_assert( false, 'Browser must not switch PEK courier order to pickup mode.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'Сценарий доставки ПЭК изменился' ), 'Tampered PEK delivery_type must fail closed with public stale-modal message.' );
}
$admin_default_request = $drafts->create_request_from_admin_data( $order, array( 'delivery_type' => DeliveryType::COURIER, 'pek_sender_warehouse_default_id' => PEK_INTEGRATION_SENDER_WAREHOUSE_A ) );
pek_integration_assert( ! array_key_exists( 'pek_sender_warehouse_id', $admin_default_request->meta ), 'Initial modal must not post settings default as shipment_modal_override.' );
$admin_stale_id_request = $drafts->create_request_from_admin_data( $order, array( 'delivery_type' => DeliveryType::COURIER, 'pek_sender_warehouse_default_id' => PEK_INTEGRATION_SENDER_WAREHOUSE_A, 'pek_sender_warehouse_override_id' => PEK_INTEGRATION_SENDER_WAREHOUSE_B ) );
pek_integration_assert( ! array_key_exists( 'pek_sender_warehouse_id', $admin_stale_id_request->meta ), 'Sender warehouse override ID without explicit source must be ignored as stale browser state.' );
try {
	$drafts->create_request_from_admin_data( $order, array( 'delivery_type' => DeliveryType::COURIER, 'pek_sender_warehouse_override_source' => 'shipment_modal_override', 'pek_sender_warehouse_override_id' => 'not-a-uuid' ) );
	pek_integration_assert( false, 'Incomplete PEK sender warehouse override pair must fail closed.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'Выберите склад ещё раз' ), 'Incomplete PEK sender warehouse override pair must use public-safe message.' );
}
$admin_override_request = $drafts->create_request_from_admin_data( $order, array( 'delivery_type' => DeliveryType::COURIER, 'pek_sender_warehouse_default_id' => PEK_INTEGRATION_SENDER_WAREHOUSE_A, 'pek_sender_warehouse_override_source' => 'shipment_modal_override', 'pek_sender_warehouse_override_id' => PEK_INTEGRATION_SENDER_WAREHOUSE_B ) );
pek_integration_assert( PEK_INTEGRATION_SENDER_WAREHOUSE_B === (string) ( $admin_override_request->meta['pek_sender_warehouse_id'] ?? '' ) && 'shipment_modal_override' === (string) ( $admin_override_request->meta['pek_sender_warehouse_source'] ?? '' ) && PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $settings->sender_warehouse()['warehouseId'] ?? '' ), 'Explicit modal sender warehouse override must stay local and leave settings default unchanged.' );
$warehouse_constraints = new PickupCargoConstraints( 3000, 4000, 20, 3000, 1 );
$branches_calls_before = pek_integration_count_calls( $http, '/branches/all/' );
$warehouse_valid = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A_UPPER, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( true === $warehouse_valid['success'] && 'nearest_fresh_revalidation' === (string) ( $warehouse_valid['diagnostic']['source'] ?? '' ) && 0 === pek_integration_count_calls( $http, '/branches/all/' ) - $branches_calls_before && PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $warehouse_valid['snapshot']['warehouseId'] ?? '' ), 'Saved sender warehouse must normalize UUID case and revalidate through nearestdepartments without branches/all.' );
$hash = hash( 'sha256', PEK_INTEGRATION_SENDER_WAREHOUSE_A );
pek_integration_assert( ( $warehouse_valid['diagnostic']['warehouse_id_hash'] ?? '' ) === $hash && ( $warehouse_valid['diagnostic']['matched_id_hash'] ?? '' ) === $hash && (int) ( $warehouse_valid['diagnostic']['free_count'] ?? 0 ) > 0 && 1 === (int) ( $warehouse_valid['diagnostic']['exact_match_count'] ?? 0 ), 'Sender nearest validation diagnostic must expose safe ID hashes and nearest counters.' );
$invalid_id_calls_before = pek_integration_count_calls( $http, '/branches/all/' );
$invalid_id = $sender_warehouse_service->validate_snapshot( 'WH-A', $warehouse_constraints );
pek_integration_assert( false === $invalid_id['success'] && $invalid_id_calls_before === pek_integration_count_calls( $http, '/branches/all/' ), 'Invalid non-UUID sender warehouse ID must fail before any warehouse lookup.' );
$http->sender_nearest_mode = 'missing_id';
$nearest_missing = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( false === $nearest_missing['success'] && 'nearest_exact_not_found' === (string) ( $nearest_missing['diagnostic']['reason'] ?? '' ) && 0 === pek_integration_count_calls( $http, '/branches/all/' ) - $branches_calls_before, 'Saved sender warehouse missing from fresh nearestdepartments must fail closed without branches/all rescue.' );
$http->sender_nearest_mode = 'constraints_exceeded';
$warehouse_constraints_fail = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( false === $warehouse_constraints_fail['success'] && false === (bool) ( $warehouse_constraints_fail['diagnostic']['constraints_match'] ?? true ), 'Sender warehouse constraints mismatch must be diagnosable after nearest revalidation.' );
$http->sender_nearest_mode = 'free_only';
$paid_sender_search = $sender_warehouse_service->search( 'Россия, Новосибирск', $warehouse_constraints );
pek_integration_assert( $paid_sender_search['success'] && array() === $paid_sender_search['items'], 'Sender self-delivery search must not expose paidDepartments as sender warehouses.' );
$http->sender_nearest_mode = 'success';
$search_before = pek_integration_count_calls( $http, '/branches/nearestdepartments/' );
$sender_search = $sender_warehouse_service->search( 'Россия, Новосибирск', $warehouse_constraints );
pek_integration_assert( $sender_search['success'] && PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $sender_search['items'][0]['warehouseId'] ?? '' ) && 1 === pek_integration_count_calls( $http, '/branches/nearestdepartments/' ) - $search_before, 'Sender warehouse search must normalize nearestdepartments warehouseId into canonical UUID.' );
$selected_from_search = $sender_warehouse_service->select_from_cached_search( PEK_INTEGRATION_SENDER_WAREHOUSE_A_UPPER );
pek_integration_assert( $selected_from_search['success'] && PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $selected_from_search['snapshot']['warehouseId'] ?? '' ), 'Cached sender warehouse selection must match uppercase nearestdepartments UUID case-insensitively and persist canonical ID.' );
$trusted_sender_snapshot = $settings->sender_warehouse();
$http->sender_nearest_mode = 'http403';
$cache_before_403 = $sender_warehouse_service->last_search_for_current_user();
$failed_sender_search = $sender_warehouse_service->search( 'Россия, Новосибирск, улица Большая, 280', $warehouse_constraints );
$cache_after_403 = $sender_warehouse_service->last_search_for_current_user();
pek_integration_assert( empty( $failed_sender_search['success'] ) && 'Не удалось получить список складов ПЭК. Повторите попытку позже.' === (string) ( $failed_sender_search['message'] ?? '' ) && 'pek_http_403' === (string) ( $failed_sender_search['diagnostic']['reason'] ?? '' ), 'Sender warehouse search HTTP 403 must return controlled safe failure.' );
pek_integration_assert( PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $cache_after_403['items'][0]['warehouseId'] ?? '' ) && $cache_before_403 === $cache_after_403, 'Sender warehouse search must preserve previous trusted cache on HTTP 403.' );
$sender_warehouse_service->clear_last_search_for_current_user();
$fallback_valid = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( true === $fallback_valid['success'] && 'persisted_snapshot_access_fallback' === (string) ( $fallback_valid['diagnostic']['source'] ?? '' ) && true === (bool) ( $fallback_valid['diagnostic']['fallback_used'] ?? false ) && false === (bool) ( $fallback_valid['diagnostic']['fresh_check'] ?? true ) && 'pek_http_403' === (string) ( $fallback_valid['diagnostic']['fallback_reason'] ?? '' ), 'Trusted persisted sender warehouse snapshot may fallback on nearestdepartments HTTP 403.' );
$settings->save_sender_warehouse( array() );
$fallback_missing_snapshot = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( false === $fallback_missing_snapshot['success'] && 'pek_http_403' === (string) ( $fallback_missing_snapshot['diagnostic']['reason'] ?? '' ), 'Nearestdepartments HTTP 403 without trusted persisted snapshot must fail closed.' );
$settings->save_sender_warehouse( $trusted_sender_snapshot );
$fallback_id_mismatch = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_B, $warehouse_constraints, 'Россия, Новосибирск, улица Большая, 280' );
pek_integration_assert( false === $fallback_id_mismatch['success'] && 'persisted_snapshot_id_mismatch' === (string) ( $fallback_id_mismatch['diagnostic']['reason'] ?? '' ), 'Nearestdepartments HTTP 403 must not fallback from persisted A to requested B.' );
$limited_snapshot = $trusted_sender_snapshot;
$limited_snapshot['limits']['maxWeight'] = 0.1;
$settings->save_sender_warehouse( $limited_snapshot );
$fallback_constraints_fail = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( false === $fallback_constraints_fail['success'] && false === (bool) ( $fallback_constraints_fail['diagnostic']['constraints_match'] ?? true ), 'Persisted snapshot fallback must still enforce local cargo limits.' );
$closed_snapshot = $trusted_sender_snapshot;
$closed_snapshot['branchTimezone'] = 'UTC+07:00';
$closed_snapshot['availability']['departmentClosingDate'] = '2000-01-01';
$settings->save_sender_warehouse( $closed_snapshot );
$fallback_closed_fail = $sender_warehouse_service->validate_snapshot( PEK_INTEGRATION_SENDER_WAREHOUSE_A, $warehouse_constraints, 'Россия, Новосибирск, Складская, дом 1' );
pek_integration_assert( false === $fallback_closed_fail['success'] && false === (bool) ( $fallback_closed_fail['diagnostic']['availability_match'] ?? true ), 'Persisted snapshot fallback must fail closed when stored availability says closed/unavailable.' );
$settings->save_sender_warehouse( $trusted_sender_snapshot );
$default_preview_submit_before = count( $http->submit_bodies );
$default_preview_branches_before = pek_integration_count_calls( $http, '/branches/all/' );
$default_preview = $creation->safe_preview( $admin_default_request );
pek_integration_assert( array() === $default_preview['errors'] && PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $default_preview['body']['sender_warehouse_id'] ?? '' ) && 'persisted_snapshot_access_fallback' === (string) ( $default_preview['body']['sender_warehouse_validation_source'] ?? '' ) && true === (bool) ( $default_preview['body']['sender_warehouse_fallback_used'] ?? false ) && false === (bool) ( $default_preview['body']['sender_warehouse_fresh_check'] ?? true ) && 'pek_http_403' === (string) ( $default_preview['body']['sender_warehouse_fallback_reason'] ?? '' ) && array() === $default_preview['errors'] && in_array( PekSenderWarehouseService::FALLBACK_WARNING, $default_preview['warnings'], true ) && $default_preview_submit_before === count( $http->submit_bodies ) && $default_preview_branches_before === pek_integration_count_calls( $http, '/branches/all/' ), 'Initial preview may use trusted persisted sender warehouse fallback on nearestdepartments HTTP 403 without submit or branches/all.' );
$http->sender_nearest_mode = 'success';
$replacement_search = $sender_warehouse_service->search( 'Россия, Новосибирск, улица Большая, 280', $warehouse_constraints );
pek_integration_assert( $replacement_search['success'] && PEK_INTEGRATION_SENDER_WAREHOUSE_B === (string) ( $sender_warehouse_service->last_search_for_current_user()['items'][0]['warehouseId'] ?? '' ), 'Successful sender warehouse search must replace the preserved stale cache.' );
$default_preview_json = wp_json_encode( $default_preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$default_preview_json = is_string( $default_preview_json ) ? $default_preview_json : '';
foreach ( array( 'personPhones', 'individual', '+79991234567', 'receiver@example.test', 'identityCard' ) as $forbidden_preview_marker ) {
	pek_integration_assert( ! str_contains( $default_preview_json, $forbidden_preview_marker ), 'PEK safe preview must not expose receiver PII marker: ' . $forbidden_preview_marker );
}
$alternate_search = $replacement_search;
pek_integration_assert( $alternate_search['success'] && PEK_INTEGRATION_SENDER_WAREHOUSE_B === (string) ( $alternate_search['items'][0]['warehouseId'] ?? '' ), 'Alternate sender warehouse picker search must cache the selected warehouse with current constraints.' );
$override_preview_submit_before = count( $http->submit_bodies );
$override_preview_branches_before = pek_integration_count_calls( $http, '/branches/all/' );
$override_preview = $creation->safe_preview( $admin_override_request );
pek_integration_assert( array() === $override_preview['errors'] && PEK_INTEGRATION_SENDER_WAREHOUSE_B === (string) ( $override_preview['body']['sender_warehouse_id'] ?? '' ) && PEK_INTEGRATION_SENDER_WAREHOUSE_A !== (string) ( $override_preview['body']['sender_warehouse_id'] ?? '' ) && 'shipment_modal_override' === (string) ( $override_preview['body']['sender_warehouse_source'] ?? '' ) && (string) ( $default_preview['body']['correlation_hash'] ?? '' ) !== (string) ( $override_preview['body']['correlation_hash'] ?? '' ) && PEK_INTEGRATION_SENDER_WAREHOUSE_A === (string) ( $settings->sender_warehouse()['warehouseId'] ?? '' ) && $override_preview_submit_before === count( $http->submit_bodies ) && $override_preview_branches_before === pek_integration_count_calls( $http, '/branches/all/' ), 'Explicit sender warehouse override must use cached nearest selection, change correlation, and affect only current shipment preview.' );
$good_sender_phone = $settings->sender_phone();
$settings_repository->set( PekSettings::SENDER_PHONE_KEY, '+7abc9991234567' );
$legacy_phone_external_before = array( pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$legacy_phone_preview = $creation->safe_preview( $request );
$legacy_phone_external_after = array( pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $legacy_phone_preview['errors'] && str_contains( (string) ( $legacy_phone_preview['errors'][0] ?? '' ), 'телефон отправителя' ) && $legacy_phone_external_before === $legacy_phone_external_after, 'Legacy malformed sender phone must block preview before private/SMS/submit calls and must not be cleaned into validity.' );
$settings_repository->set( PekSettings::SENDER_PHONE_KEY, $good_sender_phone );
$http->confirmed_counterparties_mode = 'physical_only';
$failed_refresh = $counterparts->verify_and_save();
pek_integration_assert( false === $failed_refresh['success'] && '' === $settings->sender_counterpart_guid(), 'Failed counterpart refresh must clear snapshot before preview.' );
$failed_refresh_external_before = array( pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$failed_refresh_preview = $creation->safe_preview( $request );
$failed_refresh_external_after = array( pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $failed_refresh_preview['errors'] && $failed_refresh_external_before === $failed_refresh_external_after, 'Builder after failed counterpart verification must stop before private connected-services API and submit.' );
$http->confirmed_counterparties_mode = 'success';
$counterparts->verify_and_save();
$snapshot = $settings->sender_counterpart_snapshot();
$snapshot['account_login_hash'] = str_repeat( '0', 64 );
$settings->save_sender_counterpart( '11111111-2222-3333-4444-555555555555', $snapshot );
$connected_before_stale_account = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
$stale_account_preview = $creation->safe_preview( $request );
$connected_after_stale_account = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
pek_integration_assert( array() !== $stale_account_preview['errors'] && $connected_before_stale_account === $connected_after_stale_account, 'Wrong PEK account_login_hash must block preview before connected-services API call.' );
$counterparts->verify_and_save();
$location_mismatch_order = new PekIntegrationOrder( 1092 );
$GLOBALS['wdc_pek_integration_orders'][1092] = $location_mismatch_order;
$location_mismatch_order->set_shipping_fields( array( 'state' => 'Санкт-Петербург', 'city' => 'Санкт-Петербург', 'address_1' => 'Невский проспект, дом 10', 'address_2' => '' ) );
pek_integration_set_dadata( $location_mismatch_order, 'shipping', 'Санкт-Петербург', 'Санкт-Петербург', 'Невский проспект', '10' );
$location_mismatch_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$location_mismatch_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$location_mismatch_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$location_mismatch_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 77 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$location_mismatch_request = $drafts->create_request_from_order( $location_mismatch_order );
$connected_before_location_mismatch = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
$submit_before_location_mismatch = count( $http->submit_bodies );
$location_mismatch_preview = $creation->safe_preview( $location_mismatch_request );
$connected_after_location_mismatch = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
pek_integration_assert( array() !== $location_mismatch_preview['errors'] && str_contains( (string) ( $location_mismatch_preview['errors'][0] ?? '' ), 'Повторно рассчитайте доставку ПЭК' ), 'Location mismatch must fail with public recalculation message: ' . (string) ( $location_mismatch_preview['errors'][0] ?? '' ) );
pek_integration_assert( $connected_before_location_mismatch === $connected_after_location_mismatch && $submit_before_location_mismatch === count( $http->submit_bodies ), 'Location mismatch must stop before SMS/private services and preregistration submit.' );

$city_level_order = new PekIntegrationOrder( 1093 );
$GLOBALS['wdc_pek_integration_orders'][1093] = $city_level_order;
$city_level_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $city_level_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Сосенское', '', '', 'moscow-city-fias', 'sosenskoye-fias' );
$city_level_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$city_level_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$city_level_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$city_level_order->update_meta_data( '_wdc_platform_location_fias_id', 'moscow-city-fias' );
$city_level_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 78 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$city_level_preview = $creation->safe_preview( $drafts->create_request_from_order( $city_level_order ) );
pek_integration_assert( array() === $city_level_preview['errors'] && 'city' === (string) ( $city_level_preview['body']['courier_location_level'] ?? '' ) && true === (bool) ( $city_level_preview['body']['courier_parent_city_match'] ?? false ), 'City-level canonical location must allow verified child settlement with matching parent city FIAS.' );

$settlement_level_order = new PekIntegrationOrder( 1094 );
$GLOBALS['wdc_pek_integration_orders'][1094] = $settlement_level_order;
$settlement_level_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $settlement_level_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Сосенское', '', '', 'moscow-city-fias', 'sosenskoye-fias' );
$settlement_level_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$settlement_level_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$settlement_level_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$settlement_level_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 79 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$settlement_level_preview = $creation->safe_preview( $drafts->create_request_from_order( $settlement_level_order ) );
pek_integration_assert( array() === $settlement_level_preview['errors'] && 'settlement' === (string) ( $settlement_level_preview['body']['courier_location_level'] ?? '' ) && true === (bool) ( $settlement_level_preview['body']['courier_settlement_match'] ?? false ), 'Settlement-level canonical location must require and accept exact settlement identity.' );

$parent_only_order = new PekIntegrationOrder( 1095 );
$GLOBALS['wdc_pek_integration_orders'][1095] = $parent_only_order;
$parent_only_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $parent_only_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', '', '', '', 'moscow-city-fias', '' );
$parent_only_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$parent_only_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$parent_only_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$parent_only_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 79 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$connected_before_parent_only = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
$parent_only_preview = $creation->safe_preview( $drafts->create_request_from_order( $parent_only_order ) );
$connected_after_parent_only = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/counterparts/connecteddiscountsservicesagreements/' ) ) );
pek_integration_assert( array() !== $parent_only_preview['errors'] && $connected_before_parent_only === $connected_after_parent_only, 'Settlement-level location cannot be authorized by parent city only and must stop before private SMS call.' );

$city_fias_mismatch_order = new PekIntegrationOrder( 1096 );
$GLOBALS['wdc_pek_integration_orders'][1096] = $city_fias_mismatch_order;
$city_fias_mismatch_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $city_fias_mismatch_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', '', '', '', 'different-city-fias', '' );
$city_fias_mismatch_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$city_fias_mismatch_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$city_fias_mismatch_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$city_fias_mismatch_order->update_meta_data( '_wdc_platform_location_fias_id', 'moscow-city-fias' );
$city_fias_mismatch_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 78 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$before_city_fias_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$city_fias_mismatch_preview = $creation->safe_preview( $drafts->create_request_from_order( $city_fias_mismatch_order ) );
$after_city_fias_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $city_fias_mismatch_preview['errors'] && $before_city_fias_external === $after_city_fias_external, 'Explicit city FIAS mismatch must not fall back to same city name and must stop before PEK mapping/SMS/private/submit.' );

$settlement_fias_mismatch_order = new PekIntegrationOrder( 1097 );
$GLOBALS['wdc_pek_integration_orders'][1097] = $settlement_fias_mismatch_order;
$settlement_fias_mismatch_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $settlement_fias_mismatch_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Сосенское', '', '', 'moscow-city-fias', 'different-settlement-fias' );
$settlement_fias_mismatch_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$settlement_fias_mismatch_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$settlement_fias_mismatch_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$settlement_fias_mismatch_order->update_meta_data( '_wdc_platform_location_fias_id', 'sosenskoye-fias' );
$settlement_fias_mismatch_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 79 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$before_settlement_fias_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$settlement_fias_mismatch_preview = $creation->safe_preview( $drafts->create_request_from_order( $settlement_fias_mismatch_order ) );
$after_settlement_fias_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $settlement_fias_mismatch_preview['errors'] && $before_settlement_fias_external === $after_settlement_fias_external, 'Explicit settlement FIAS mismatch must not fall back to same settlement name and must stop before PEK mapping/SMS/private/submit.' );

$selected_fias_mismatch_order = new PekIntegrationOrder( 1098 );
$GLOBALS['wdc_pek_integration_orders'][1098] = $selected_fias_mismatch_order;
$selected_fias_mismatch_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $selected_fias_mismatch_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', '', '', '', 'moscow-city-fias', '' );
$selected_fias_mismatch_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$selected_fias_mismatch_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$selected_fias_mismatch_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$selected_fias_mismatch_order->update_meta_data( '_wdc_platform_location_fias_id', 'different-selected-fias' );
$selected_fias_mismatch_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 78 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$before_selected_fias_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$selected_fias_mismatch_preview = $creation->safe_preview( $drafts->create_request_from_order( $selected_fias_mismatch_order ) );
$after_selected_fias_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $selected_fias_mismatch_preview['errors'] && $before_selected_fias_external === $after_selected_fias_external, 'Selected platform FIAS mismatch must stop before PEK mapping/SMS/private/submit.' );

$canonical_ids_absent_order = new PekIntegrationOrder( 1099 );
$GLOBALS['wdc_pek_integration_orders'][1099] = $canonical_ids_absent_order;
$canonical_ids_absent_order->set_shipping_fields( array( 'state' => 'Московская область', 'city' => 'Видное', 'address_1' => 'улица Советская, дом 10', 'address_2' => '' ) );
pek_integration_set_dadata( $canonical_ids_absent_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '', '', '', '', 'request-city-fias', '' );
$canonical_ids_absent_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$canonical_ids_absent_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$canonical_ids_absent_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$canonical_ids_absent_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 80 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$canonical_ids_absent_preview = $creation->safe_preview( $drafts->create_request_from_order( $canonical_ids_absent_order ) );
pek_integration_assert( array() === $canonical_ids_absent_preview['errors'] && true === (bool) ( $canonical_ids_absent_preview['body']['courier_location_match'] ?? false ), 'Canonical IDs absent must still allow bounded normalized-name fallback.' );

$woo_fallback_selected_mismatch_order = new PekIntegrationOrder( 1100 );
$GLOBALS['wdc_pek_integration_orders'][1100] = $woo_fallback_selected_mismatch_order;
$woo_fallback_selected_mismatch_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $woo_fallback_selected_mismatch_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '', '', '', '', 'vidnoe-old-fias', '' );
$woo_fallback_selected_mismatch_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$woo_fallback_selected_mismatch_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$woo_fallback_selected_mismatch_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$woo_fallback_selected_mismatch_order->update_meta_data( '_wdc_platform_location_fias_id', 'different-selected-fias' );
$woo_fallback_selected_mismatch_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 78 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$before_woo_selected_mismatch_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$woo_fallback_selected_mismatch_preview = $creation->safe_preview( $drafts->create_request_from_order( $woo_fallback_selected_mismatch_order ) );
$after_woo_selected_mismatch_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $woo_fallback_selected_mismatch_preview['errors'] && $before_woo_selected_mismatch_external === $after_woo_selected_mismatch_external, 'Stale DaData rejected + Woo fallback must still enforce selected-location FIAS before PEK calls.' );

$woo_fallback_selected_match_order = new PekIntegrationOrder( 1101 );
$GLOBALS['wdc_pek_integration_orders'][1101] = $woo_fallback_selected_match_order;
$woo_fallback_selected_match_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $woo_fallback_selected_match_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '', '', '', '', 'vidnoe-old-fias', '' );
$woo_fallback_selected_match_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$woo_fallback_selected_match_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$woo_fallback_selected_match_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$woo_fallback_selected_match_order->update_meta_data( '_wdc_platform_location_fias_id', 'moscow-city-fias' );
$woo_fallback_selected_match_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 78 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$woo_fallback_selected_match_preview = $creation->safe_preview( $drafts->create_request_from_order( $woo_fallback_selected_match_order ) );
pek_integration_assert( array() === $woo_fallback_selected_match_preview['errors'] && in_array( (string) ( $woo_fallback_selected_match_preview['body']['courier_address_source'] ?? '' ), array( 'woo_structured', 'parsed_address_1' ), true ) && ! array_key_exists( 'courier_selected_location_fias_id', $woo_fallback_selected_match_preview['body'] ) && '' !== (string) ( $woo_fallback_selected_match_preview['body']['courier_selected_location_fias_hash'] ?? '' ), 'Woo fallback must keep selected-location FIAS authority while exposing only safe hashes.' );

$woo_fallback_no_selected_order = new PekIntegrationOrder( 1102 );
$GLOBALS['wdc_pek_integration_orders'][1102] = $woo_fallback_no_selected_order;
$woo_fallback_no_selected_order->set_shipping_fields( array( 'state' => 'Московская область', 'city' => 'Видное', 'address_1' => 'улица Советская, дом 10', 'address_2' => '' ) );
$woo_fallback_no_selected_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$woo_fallback_no_selected_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$woo_fallback_no_selected_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$woo_fallback_no_selected_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 80 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$woo_fallback_no_selected_preview = $creation->safe_preview( $drafts->create_request_from_order( $woo_fallback_no_selected_order ) );
pek_integration_assert( array() === $woo_fallback_no_selected_preview['errors'] && true === (bool) ( $woo_fallback_no_selected_preview['body']['courier_location_match'] ?? false ) && 'request_location_id' === (string) ( $woo_fallback_no_selected_preview['body']['courier_location_identity_source'] ?? '' ), 'Woo fallback with selected FIAS absent must keep bounded normalized-name fallback.' );

$house_fias_order = new PekIntegrationOrder( 1103 );
$GLOBALS['wdc_pek_integration_orders'][1103] = $house_fias_order;
$house_fias_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $house_fias_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Сосенское', '', '', 'moscow-city-fias', '' );
$house_fias_order->update_meta_data( '_shipping_dadata_fias_id', 'house-full-address-fias' );
$house_fias_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$house_fias_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$house_fias_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$house_fias_order->update_meta_data( '_wdc_platform_location_fias_id', 'sosenskoye-fias' );
$house_fias_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 79 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$house_fias_preview = $creation->safe_preview( $drafts->create_request_from_order( $house_fias_order ) );
pek_integration_assert( array() === $house_fias_preview['errors'] && true === (bool) ( $house_fias_preview['body']['courier_settlement_match'] ?? false ), 'House/full-address FIAS must not be confused with settlement FIAS.' );

$billing_selected_mismatch_order = new PekIntegrationOrder( 1104 );
$GLOBALS['wdc_pek_integration_orders'][1104] = $billing_selected_mismatch_order;
$billing_selected_mismatch_order->set_shipping_fields( array( 'country' => 'RU', 'state' => '', 'city' => '', 'address_1' => '', 'address_2' => '' ) );
$billing_selected_mismatch_order->set_billing_fields( array( 'country' => 'RU', 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
$billing_selected_mismatch_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$billing_selected_mismatch_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$billing_selected_mismatch_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$billing_selected_mismatch_order->update_meta_data( '_wdc_platform_location_fias_id', 'different-selected-fias' );
$billing_selected_mismatch_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 78 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$before_billing_selected_mismatch_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$billing_selected_mismatch_preview = $creation->safe_preview( $drafts->create_request_from_order( $billing_selected_mismatch_order ) );
$after_billing_selected_mismatch_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $billing_selected_mismatch_preview['errors'] && $before_billing_selected_mismatch_external === $after_billing_selected_mismatch_external, 'Billing fallback must enforce the same selected-location FIAS binding before PEK calls.' );

$typed_settlement_fallback_order = new PekIntegrationOrder( 1105 );
$GLOBALS['wdc_pek_integration_orders'][1105] = $typed_settlement_fallback_order;
$typed_settlement_fallback_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $typed_settlement_fallback_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'Сосенское', '', '', '', '' );
$typed_settlement_fallback_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$typed_settlement_fallback_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$typed_settlement_fallback_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$typed_settlement_fallback_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 79 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$typed_settlement_fallback_preview = $creation->safe_preview( $drafts->create_request_from_order( $typed_settlement_fallback_order ) );
pek_integration_assert( array() === $typed_settlement_fallback_preview['errors'] && true === (bool) ( $typed_settlement_fallback_preview['body']['courier_settlement_match'] ?? false ), 'Settlement typed-name fallback must match bounded project settlement prefixes when IDs are absent.' );

$different_settlement_fallback_order = new PekIntegrationOrder( 1106 );
$GLOBALS['wdc_pek_integration_orders'][1106] = $different_settlement_fallback_order;
$different_settlement_fallback_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $different_settlement_fallback_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Другое', '', '', '', '' );
$different_settlement_fallback_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$different_settlement_fallback_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$different_settlement_fallback_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$different_settlement_fallback_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array( 'location_id' => 79 ), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$before_different_settlement_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$different_settlement_fallback_preview = $creation->safe_preview( $drafts->create_request_from_order( $different_settlement_fallback_order ) );
$after_different_settlement_external = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $different_settlement_fallback_preview['errors'] && $before_different_settlement_external === $after_different_settlement_external, 'Different settlement name fallback must fail before PEK calls.' );

$live_fias_fallback_order = new PekIntegrationOrder( 1107 );
$GLOBALS['wdc_pek_integration_orders'][1107] = $live_fias_fallback_order;
$live_fias_fallback_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'Ходынский б-р, дом 13', 'address_2' => 'кв. 1' ) );
$live_fias_fallback_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$live_fias_fallback_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$live_fias_fallback_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$live_fias_fallback_order->update_meta_data( '_wdc_platform_city_fias_id', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' );
$live_fias_fallback_order->update_meta_data( '_wdc_platform_rate_meta', array( 'destination_fingerprint' => 'country=RU|location_id=81' ) );
$live_fias_fallback_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array(), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$live_fias_fallback_request = $drafts->create_request_from_order( $live_fias_fallback_order );
pek_integration_assert( 0 === (int) ( $live_fias_fallback_request->meta['pek_destination_location_id'] ?? 0 ), 'Live-style pre-0.134.18 courier order must reproduce missing numeric destination location ID.' );
$live_fias_evidence = is_array( $live_fias_fallback_request->meta['pek_courier_address_evidence'] ?? null ) ? $live_fias_fallback_request->meta['pek_courier_address_evidence'] : array();
pek_integration_assert( '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' === (string) ( $live_fias_evidence['courier_order_city_fias_id'] ?? '' ) && '' === (string) ( $live_fias_evidence['courier_selected_location_fias_id'] ?? '' ), 'Historical courier evidence must carry generic order city FIAS without selected-location FIAS.' );
$live_fias_findzone_before = pek_integration_count_calls( $http, '/branches/findzonebyaddress/' );
$live_fias_submit_before = count( $http->submit_bodies );
$live_fias_fallback_preview = $creation->safe_preview( $live_fias_fallback_request );
pek_integration_assert( array() === $live_fias_fallback_preview['errors'] && $live_fias_findzone_before + 1 === pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ) && $live_fias_submit_before === count( $http->submit_bodies ), 'Existing live-style courier order must recover destination identity from persisted generic city FIAS and reach findzonebyaddress without submit.' );
pek_integration_assert( 81 === (int) ( $live_fias_fallback_preview['body']['courier_location_id'] ?? 0 ) && true === (bool) ( $live_fias_fallback_preview['body']['courier_location_match'] ?? false ) && 'order_city_fias_fallback' === (string) ( $live_fias_fallback_preview['body']['courier_location_identity_source'] ?? '' ) && 'fresh_address_zone' === (string) ( $live_fias_fallback_preview['body']['courier_branch_source'] ?? '' ), 'Live-style city FIAS fallback preview must expose safe identity source and fresh address-zone branch evidence.' );
pek_integration_assert( ! empty( $live_fias_fallback_preview['body']['courier_order_city_fias_present'] ) && '' !== (string) ( $live_fias_fallback_preview['body']['courier_order_city_fias_hash'] ?? '' ) && ! array_key_exists( 'courier_order_city_fias_id', $live_fias_fallback_preview['body'] ), 'Safe preview must expose order city FIAS presence/hash without raw FIAS.' );

$selected_fias_wins_order = new PekIntegrationOrder( 1108 );
$GLOBALS['wdc_pek_integration_orders'][1108] = $selected_fias_wins_order;
$selected_fias_wins_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'Ходынский б-р, дом 13', 'address_2' => 'кв. 1' ) );
$selected_fias_wins_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$selected_fias_wins_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$selected_fias_wins_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$selected_fias_wins_order->update_meta_data( '_wdc_platform_location_fias_id', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' );
$selected_fias_wins_order->update_meta_data( '_wdc_platform_city_fias_id', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' );
$selected_fias_wins_order->update_meta_data( '_wdc_platform_rate_meta', array( 'destination_fingerprint' => 'country=RU|location_id=81' ) );
$selected_fias_wins_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array(), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$selected_fias_wins_preview = $creation->safe_preview( $drafts->create_request_from_order( $selected_fias_wins_order ) );
pek_integration_assert( array() === $selected_fias_wins_preview['errors'] && 'selected_location_fias_fallback' === (string) ( $selected_fias_wins_preview['body']['courier_location_identity_source'] ?? '' ), 'Explicit selected-location FIAS must win over generic order city FIAS when both resolve to the same Location.' );

$contradictory_fias_order = new PekIntegrationOrder( 1109 );
$GLOBALS['wdc_pek_integration_orders'][1109] = $contradictory_fias_order;
$contradictory_fias_order->set_shipping_fields( array( 'state' => 'Москва', 'city' => 'Москва', 'address_1' => 'Ходынский б-р, дом 13', 'address_2' => 'кв. 1' ) );
$contradictory_fias_order->update_meta_data( '_wdc_platform_carrier_key', PekSettings::CARRIER_KEY );
$contradictory_fias_order->update_meta_data( '_wdc_platform_delivery_type', DeliveryType::COURIER );
$contradictory_fias_order->update_meta_data( '_wdc_platform_rate_id', PekSettings::COURIER_RATE_ID );
$contradictory_fias_order->update_meta_data( '_wdc_platform_location_fias_id', 'c2deb16a-0330-4f05-821f-1d09c93331e6' );
$contradictory_fias_order->update_meta_data( '_wdc_platform_city_fias_id', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' );
$contradictory_fias_order->update_meta_data( '_wdc_platform_rate_meta', array( 'destination_fingerprint' => 'country=RU|location_id=81' ) );
$contradictory_fias_order->update_meta_data( '_wdc_delivery_calculation_data', array( 'carrier_key' => PekSettings::CARRIER_KEY, 'delivery_type' => DeliveryType::COURIER, 'destination' => array(), 'package' => array( 'products_weight_g' => 2500, 'packaging_weight_g' => 500, 'final_weight_g' => 3000, 'dimensions_cm' => array( 'length' => 20, 'width' => 20, 'height' => 10 ) ) ) );
$contradictory_external_before = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
$contradictory_fias_preview = $creation->safe_preview( $drafts->create_request_from_order( $contradictory_fias_order ) );
$contradictory_external_after = array( pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ), pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
pek_integration_assert( array() !== $contradictory_fias_preview['errors'] && $contradictory_external_before === $contradictory_external_after, 'Contradictory selected-location FIAS must fail closed before PEK API and must not be hidden by order city FIAS fallback.' );

$before_submit = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/preregistration/submit/' ) ) );
$before_courier_findzone = pek_integration_count_calls( $http, '/branches/findzonebyaddress/' );
$before_courier_coordinates = pek_integration_count_calls( $http, '/branches/findzonebycoordinates/' );
$preview = $creation->safe_preview( $request, $order );
$after_preview_submit = count( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/preregistration/submit/' ) ) );
pek_integration_assert( $before_submit === $after_preview_submit, 'Safe preview must not submit PEK preregistration.' );
pek_integration_assert( 'POST' === $preview['method'] && '/preregistration/submit/' === $preview['path'] && array() === $preview['errors'], 'Safe preview must return canonical PEK envelope: ' . wp_json_encode( $preview, JSON_UNESCAPED_UNICODE ) );
pek_integration_assert( true === (bool) ( $preview['body']['creation_attempt_present'] ?? false ) && 1 === (int) ( $preview['body']['creation_attempt_generation'] ?? 0 ) && 'active' === (string) ( $preview['body']['creation_attempt_state'] ?? '' ), 'Explicit preview must reserve generic creation attempt A.' );
pek_integration_assert( 'shipping_dadata' === (string) ( $preview['body']['courier_address_source'] ?? '' ) && ! empty( $preview['body']['courier_region_present'] ) && ! empty( $preview['body']['courier_house_present'] ) && '' !== (string) ( $preview['body']['courier_address_hash'] ?? '' ), 'Safe preview must expose courier address evidence without raw address.' );
pek_integration_assert( $before_courier_findzone + 1 === pek_integration_count_calls( $http, '/branches/findzonebyaddress/' ) && $before_courier_coordinates === pek_integration_count_calls( $http, '/branches/findzonebycoordinates/' ), 'Courier Shipment Framework preview must use findzonebyaddress for the actual address and not canonical coordinates.' );
$courier_findzone_calls = array_values( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/branches/findzonebyaddress/' ) ) );
$courier_findzone_body = json_decode( (string) ( $courier_findzone_calls[ count( $courier_findzone_calls ) - 1 ]['args']['body'] ?? '' ), true );
pek_integration_assert( is_array( $courier_findzone_body ) && (string) ( $courier_findzone_body['address'] ?? '' ) === $request->recipient_address->raw_address, 'Courier findzonebyaddress must receive the trusted full recipient address.' );
$sms_geography_calls = array_values( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/branches/checknocalcservices/' ) ) );
$sms_geography_body = json_decode( (string) ( $sms_geography_calls[ count( $sms_geography_calls ) - 1 ]['args']['body'] ?? '' ), true );
pek_integration_assert( is_array( $sms_geography_body ) && 'fake-moscow-branch' === (string) ( $sms_geography_body['branchReceiverId'] ?? '' ), 'SMS geography must use receiver branch from fresh actual-address findzone response.' );
pek_integration_assert( 77 === (int) ( $preview['body']['courier_location_id'] ?? 0 ) && true === (bool) ( $preview['body']['courier_location_match'] ?? false ) && 'request_location_id' === (string) ( $preview['body']['courier_location_identity_source'] ?? '' ) && 'fresh_address_zone' === (string) ( $preview['body']['courier_branch_source'] ?? '' ), 'Safe preview must expose courier location identity and fresh actual-address branch binding evidence.' );
pek_integration_assert( 'exact' === (string) ( $preview['body']['courier_address_precision'] ?? '' ) && ! empty( $preview['body']['courier_zone_present'] ) && ! empty( $preview['body']['courier_main_warehouse_present'] ) && ! empty( $preview['body']['courier_pek_formatted_address_present'] ) && '' !== (string) ( $preview['body']['courier_pek_formatted_address_hash'] ?? '' ), 'Courier safe preview must expose non-PII findzonebyaddress evidence.' );
pek_integration_assert( 'city' === (string) ( $preview['body']['courier_location_level'] ?? '' ) && true === (bool) ( $preview['body']['courier_parent_city_match'] ?? false ) && ! array_key_exists( 'courier_city_fias_id', $preview['body'] ), 'Safe preview must expose only safe location match evidence without raw FIAS IDs.' );
$http->findzone_address_mode = 'near';
$near_preview = $creation->safe_preview( $request );
pek_integration_assert( array() === $near_preview['errors'] && array() !== $near_preview['warnings'] && 'near' === (string) ( $near_preview['body']['courier_address_precision'] ?? '' ), 'Near courier findzone precision must allow preview with a public warning.' );
foreach ( array( 'bad', 'missing_precision', 'empty', 'country_mismatch' ) as $findzone_mode ) {
	$http->findzone_address_mode = $findzone_mode;
	$downstream_before = array( pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
	$bad_preview = $creation->safe_preview( $request );
	$downstream_after = array( pek_integration_count_calls( $http, '/branches/checknocalcservices/' ), pek_integration_count_calls( $http, '/auth/createtokentoaccessprivatedata/' ), pek_integration_count_calls( $http, '/counterparts/connecteddiscountsservicesagreements/' ), count( $http->submit_bodies ) );
	pek_integration_assert( array() !== $bad_preview['errors'] && $downstream_before === $downstream_after, 'Bad/malformed courier findzone must fail before SMS/private/submit: ' . $findzone_mode );
}
$http->findzone_address_mode = 'exact';
$create_result = $creation->create( $order, $request );
$submit_calls = array_values( array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call['url'], '/preregistration/submit/' ) ) );
pek_integration_assert( true === $create_result->success, 'Production chain create must succeed through fake PEK submit.' );
pek_integration_assert( 1 === count( $submit_calls ), 'Fake preregistration submit must be called exactly once in success case.' );
pek_integration_assert( (string) ( $preview['body']['correlation_hash'] ?? '' ) === hash( 'sha256', (string) ( $http->submit_bodies[0]['cargos'][0]['common']['customerCorrelation'] ?? '' ) ), 'PEK preview and create must use the same generic attempt and customerCorrelation.' );
pek_integration_assert_same_payload( $http->submit_bodies[0] ?? array(), pek_integration_fixture( 'preregistration-submit-courier.json' ), 'Courier production chain' );
$created = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
pek_integration_assert( '999940950644' === $created['tracking_number'] && '136' === $created['external_id'], 'Creation service and mapper must persist PEK identifiers.' );
pek_integration_assert( is_string( $created['creation_attempt_id'] ?? null ) && preg_match( '/^[0-9a-f-]{36}$/', (string) $created['creation_attempt_id'] ) && 1 === (int) ( $created['creation_attempt_generation'] ?? 0 ), 'Successful PEK shipment must persist generic creation attempt A.' );
$created_attempt_a = (string) $created['creation_attempt_id'];
pek_integration_assert_plain_data( $created );

$http->status_mode = 'expanded';
$http->statuses = array( 'Прибыл' );
$expanded_status_result = $status_service->update( $order );
$expanded_shipment = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
pek_integration_assert( true === $expanded_status_result['success'] && 'expanded' === (string) ( $expanded_shipment['pek_status_source'] ?? '' ), 'Expanded status update must persist source.' );
pek_integration_assert( true === (bool) ( $expanded_shipment['pek_receiving_by_sms_code'] ?? false ) && 12345 === (int) ( $expanded_shipment['actual_cost_kopecks'] ?? 0 ), 'Expanded status must persist receiver flag and actual cost.' );

$http->status_mode = 'expanded_403';
$http->statuses = array( 'Оформлен' );
$basic_status_result = $status_service->update( $order );
$basic_shipment = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
pek_integration_assert( true === $basic_status_result['success'] && 'basic' === (string) ( $basic_shipment['pek_status_source'] ?? '' ), 'Explicit 403 expanded failure must use basic status fallback.' );
pek_integration_assert( true === (bool) ( $basic_shipment['pek_receiving_by_sms_code'] ?? false ) && 12345 === (int) ( $basic_shipment['actual_cost_kopecks'] ?? 0 ), 'Basic fallback must preserve expanded-only receiver flags and actual cost.' );
pek_integration_assert( '42' === (string) ( $basic_shipment['pek_cargo_status_id'] ?? '' ), 'Basic fallback must not erase status ID when basic response omits it.' );

$http->status_mode = 'expanded_false';
$http->statuses = array( 'Прибыл' );
$false_status_result = $status_service->update( $order );
$false_shipment = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
pek_integration_assert( true === $false_status_result['success'] && false === (bool) ( $false_shipment['pek_receiving_by_sms_code'] ?? true ), 'Explicit false from expanded status must replace previous true.' );

$before_malformed_status = $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
$http->status_mode = 'malformed_status';
try {
	$status_service->update( $order );
	pek_integration_assert( false, 'Malformed expanded HTTP 200 status must fail.' );
} catch ( RuntimeException ) {
	pek_integration_assert( $before_malformed_status === $repository->find_by_carrier( $order, PekSettings::CARRIER_KEY ), 'Malformed expanded status must not change persisted shipment state.' );
}
$http->status_mode = 'expanded';

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
			'point_code' => PEK_INTEGRATION_RECEIVER_WAREHOUSE,
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
		'point_code' => PEK_INTEGRATION_RECEIVER_WAREHOUSE,
		'provider_destination_fingerprint' => 'pickup-fingerprint',
		'pickup_provider_query' => array(
			'location_id' => 77,
			'address' => 'Россия, Московская область, Видное',
			'destination_fingerprint' => 'pickup-fingerprint',
		),
	)
);
$pickup_before_submit = count( $http->submit_bodies );
$pickup_draft = $drafts->draft_array( $pickup_order );
$pickup_services = is_array( $pickup_draft['services'] ?? null ) ? $pickup_draft['services'] : array();
pek_integration_assert( 1 === count( $pickup_services ) && DeliveryType::PICKUP === (string) ( $pickup_services[0]['delivery_type'] ?? '' ) && 'ПЭК до терминала' === (string) ( $pickup_services[0]['title'] ?? '' ), 'PEK pickup draft must expose exactly one trusted modal scenario.' );
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
pek_integration_assert( DeliveryType::PICKUP === $pickup_request->delivery_type && PEK_INTEGRATION_RECEIVER_WAREHOUSE === (string) ( $pickup_preview['body']['receiver_warehouse_id'] ?? '' ), 'Pickup draft/preview must carry selected receiver warehouse.' );
$pickup_result = $creation->create( $pickup_order, $pickup_request );
pek_integration_assert( true === $pickup_result->success, 'Pickup production chain create must succeed through fake PEK submit.' );
pek_integration_assert( count( $http->submit_bodies ) === $pickup_before_submit + 1, 'Pickup fake submit must be called exactly once.' );
pek_integration_assert_same_payload( $http->submit_bodies[ $pickup_before_submit ] ?? array(), pek_integration_fixture( 'preregistration-submit-pickup.json' ), 'Pickup production chain' );
$pickup_created = $repository->find_by_carrier( $pickup_order, PekSettings::CARRIER_KEY );
pek_integration_assert( PEK_INTEGRATION_RECEIVER_WAREHOUSE === $pickup_created['pek_receiver_warehouse_id'] && 'BR-R' === $pickup_created['pek_receiver_branch_id'], 'Pickup fresh point code and branch must persist.' );
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
pek_integration_assert( is_string( $uncertain_shipment['creation_attempt_id'] ?? null ) && preg_match( '/^[0-9a-f-]{36}$/', (string) $uncertain_shipment['creation_attempt_id'] ), 'HTTP 500 uncertain result must persist generic creation attempt A.' );
$uncertain_attempt_record = $attempts->current_record_for_request( $uncertain_order, $uncertain_request );
pek_integration_assert( 'pending' === (string) ( $uncertain_attempt_record['state'] ?? '' ) && (string) $uncertain_shipment['creation_attempt_id'] === (string) ( $uncertain_attempt_record['current_attempt_id'] ?? '' ), 'Uncertain pending must keep generic attempt in pending state.' );
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
delete_transient( 'wdc_pek_request_budget_' . gmdate( 'YmdHi' ) );
$logical_result = $creation->create( $logical_order, $logical_request );
pek_integration_assert( false === $logical_result->success && array() === $repository->find_by_carrier( $logical_order, PekSettings::CARRIER_KEY ), 'Structured HTTP 200 logical rejection must not persist pending shipment.' );
$logical_diag = is_array( $logical_result->raw_reference['diagnostic'] ?? null ) ? $logical_result->raw_reference['diagnostic'] : array();
pek_integration_assert( 'pek_logical_error' === (string) ( $logical_result->error_code ?? '' ) && 'shipment_create_logical' === (string) ( $logical_diag['failure_stage'] ?? '' ), 'Structured logical rejection must stay definite and expose shipment_create_logical stage.' );
pek_integration_assert( '/preregistration/submit/' === (string) ( $logical_diag['endpoint'] ?? '' ) && 200 === (int) ( $logical_diag['http_status'] ?? 0 ), 'Structured logical rejection diagnostic must include endpoint and HTTP status.' );
pek_integration_assert( str_contains( (string) ( $logical_diag['api_error_message'] ?? '' ), 'Validation' ) && str_contains( (string) ( $logical_diag['api_error_message'] ?? '' ), '[redacted]' ), 'Structured logical rejection diagnostic must include redacted PEK API error message.' );
pek_integration_assert( is_array( $logical_diag['field_errors'] ?? null ) && 3 === count( $logical_diag['field_errors'] ), 'Structured logical rejection diagnostic must include normalized field errors.' );
pek_integration_assert( 'object' === (string) ( $logical_diag['response_shape']['root_type'] ?? '' ) && true === (bool) ( $logical_diag['response_shape']['error_present'] ?? false ) && 3 === (int) ( $logical_diag['response_shape']['fields_count'] ?? 0 ), 'Structured logical rejection diagnostic must include safe response shape with error fields count.' );
$logical_diag_json = wp_json_encode( $logical_diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$logical_diag_json = is_string( $logical_diag_json ) ? $logical_diag_json : '';
foreach ( array( '+79991234567', 'receiver@example.test', 'CARD-123', 'raw_response', 'raw_request', 'identityCard' ) as $forbidden_marker ) {
	pek_integration_assert( ! str_contains( $logical_diag_json, $forbidden_marker ), 'Structured logical rejection diagnostic must not leak marker: ' . $forbidden_marker );
}
$logical_attempt_after_rejection = $attempts->current_record_for_request( $logical_order, $logical_request );
pek_integration_assert( 1 === (int) ( $logical_attempt_after_rejection['generation'] ?? 0 ) && 'active' === (string) ( $logical_attempt_after_rejection['state'] ?? '' ), 'Deterministic PEK rejection must keep generation 1 active for corrected retry.' );

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
	'creation_attempt_id' => $created_attempt_a,
	'creation_attempt_generation' => 1,
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
	'pek_sender_warehouse_id' => PEK_INTEGRATION_SENDER_WAREHOUSE_A,
	'pek_sender_warehouse_title' => 'Склад A',
	'pek_sender_warehouse_source' => 'default',
	'pek_receiver_warehouse_id' => PEK_INTEGRATION_RECEIVER_WAREHOUSE,
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
pek_integration_assert( $created_attempt_a === (string) ( $attached['creation_attempt_id'] ?? '' ), 'Manual attach must preserve generic creation attempt from pending evidence.' );
$attached_attempt_record = $attempts->current_record_for_request( $order, $request );
pek_integration_assert( $created_attempt_a === (string) ( $attached_attempt_record['current_attempt_id'] ?? '' ) && 'active' === (string) ( $attached_attempt_record['state'] ?? '' ), 'Manual attach must transition generic attempt state from pending to active.' );
pek_integration_assert( '2026-08-06 12:00:00' === $attached['created_at'], 'Manual attach must preserve original created_at.' );
pek_integration_assert( isset( $attached['reconciled_at'] ), 'Manual attach must add reconciled_at.' );
pek_integration_assert( false === $attached['pending_creation_in_carrier'], 'Manual attach must clear active pending state.' );
pek_integration_assert( 12345 === $attached['actual_cost_kopecks'], 'Manual attach must merge actual cost from PEK status services.sum.' );
pek_integration_assert_plain_data( $attached );

$GLOBALS['wdc_pek_integration_transients'] = array();
$http->status_mode = 'expanded';
$http->statuses = array( 'Прибыл' );
$courier_status = $status_service->fetch( 'PEK-777', DeliveryType::COURIER );
pek_integration_assert( 'in_transit' === $courier_status['universal_status_code'], 'Courier status "Прибыл" must remain in_transit.' );

$unknown = array_merge( $attached, array( 'pek_cargo_status' => 'UNKNOWN', 'status_title' => 'UNKNOWN', 'universal_status_code' => 'unknown', 'created_at' => '2026-08-06 12:00:00' ) );
pek_integration_assert( false === $button_policy->resolve( $unknown )['cancel'], 'UNKNOWN status must not expose cancellation.' );
$accepted = array_merge( $attached, array( 'pek_cargo_status' => 'Принят к перевозке', 'status_title' => 'Принят к перевозке', 'universal_status_code' => 'created_in_carrier', 'created_at' => '2026-08-06 12:00:00' ) );
pek_integration_assert( false === $button_policy->resolve( $accepted )['cancel'], 'Accepted PEK status must not expose cancellation.' );
$open = array_merge( $attached, array( 'pek_cargo_status' => 'Оформлен', 'status_title' => 'Оформлен', 'pek_take_on_stock_datetime' => '', 'universal_status_code' => 'created_in_carrier', 'manual_attach' => false, 'created_at' => '2026-08-06 12:00:00' ) );
pek_integration_assert( true === $button_policy->resolve( $open )['cancel'], 'Pre-acceptance PEK status must expose cancellation before age gate.' );

$GLOBALS['wdc_pek_integration_transients'] = array();
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
$recipient_builder = new PekShipmentRecipientBuilder( $addresses, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$recipient_phone_order = new PekIntegrationOrder( 3001 );
$recipient_phone_order->set_shipping_fields( array( 'phone' => '8 (999) 123-45-67' ) );
$recipient_payload = $recipient_builder->build_physical_recipient( $recipient_phone_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
pek_integration_assert( '+79991234567' === (string) ( $recipient_payload['personPhones'][0]['phone'] ?? '' ), 'Recipient shipping phone must normalize through PEK RU phone contract.' );
$recipient_billing_fallback_order = new PekIntegrationOrder( 3002 );
$recipient_billing_fallback_order->set_shipping_fields( array( 'phone' => '' ) );
$recipient_billing_fallback_order->set_billing_fields( array( 'phone' => '79991234567' ) );
$recipient_fallback_payload = $recipient_builder->build_physical_recipient( $recipient_billing_fallback_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
pek_integration_assert( '+79991234567' === (string) ( $recipient_fallback_payload['personPhones'][0]['phone'] ?? '' ), 'Empty shipping phone may fall back to billing phone.' );
$recipient_malformed_order = new PekIntegrationOrder( 3003 );
$recipient_malformed_order->set_shipping_fields( array( 'phone' => '+7abc9991234567' ) );
$recipient_malformed_order->set_billing_fields( array( 'phone' => '79991234567' ) );
try {
	$recipient_builder->build_physical_recipient( $recipient_malformed_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
	pek_integration_assert( false, 'Malformed non-empty shipping recipient phone must not silently fall back to billing.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'корректный телефон получателя' ), 'Malformed recipient phone must fail with SMS public message.' );
}
foreach ( array( "\n+79991234567", "+79991234567\t", "++79991234567" ) as $bad_phone ) {
	$recipient_bad_control_order = new PekIntegrationOrder( 3004 );
	$recipient_bad_control_order->set_shipping_fields( array( 'phone' => $bad_phone ) );
	try {
		$recipient_builder->build_physical_recipient( $recipient_bad_control_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
		pek_integration_assert( false, 'Malformed recipient phone must fail before trimming/stripping.' );
	} catch ( RuntimeException $expected ) {
		pek_integration_assert( str_contains( $expected->getMessage(), 'корректный телефон получателя' ), 'Recipient phone control chars/multiple plus signs must be rejected.' );
	}
}
$recipient_shipping_name_order = new PekIntegrationOrder( 3005 );
$recipient_shipping_name_order->set_shipping_fields( array( 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '79991234567' ) );
$recipient_shipping_name_order->set_billing_fields( array( 'first_name' => 'Пётр', 'last_name' => 'Сидоров' ) );
$recipient_shipping_name_payload = $recipient_builder->build_physical_recipient( $recipient_shipping_name_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
pek_integration_assert( 'Петров Иван' === (string) ( $recipient_shipping_name_payload['title'] ?? '' ) && 'Петров Иван' === (string) ( $recipient_shipping_name_payload['person'] ?? '' ) && 'Иван' === (string) ( $recipient_shipping_name_payload['individual']['firstName'] ?? '' ) && 'Петров' === (string) ( $recipient_shipping_name_payload['individual']['lastName'] ?? '' ), 'PEK recipient must use shipping first and last names when present.' );
$recipient_billing_name_order = new PekIntegrationOrder( 3006 );
$recipient_billing_name_order->set_shipping_fields( array( 'first_name' => '', 'last_name' => '', 'phone' => '79991234567' ) );
$recipient_billing_name_order->set_billing_fields( array( 'first_name' => 'Пётр', 'last_name' => 'Сидоров' ) );
$recipient_billing_name_payload = $recipient_builder->build_physical_recipient( $recipient_billing_name_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
pek_integration_assert( 'Сидоров Пётр' === (string) ( $recipient_billing_name_payload['title'] ?? '' ) && 'Сидоров Пётр' === (string) ( $recipient_billing_name_payload['person'] ?? '' ), 'PEK recipient may use billing first and last names only when shipping names are both absent.' );
$recipient_partial_shipping_order = new PekIntegrationOrder( 3007 );
$recipient_partial_shipping_order->set_shipping_fields( array( 'first_name' => 'Иван', 'last_name' => '', 'phone' => '79991234567' ) );
$recipient_partial_shipping_order->set_billing_fields( array( 'first_name' => 'Пётр', 'last_name' => 'Сидоров' ) );
try {
	$recipient_builder->build_physical_recipient( $recipient_partial_shipping_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
	pek_integration_assert( false, 'Partial shipping recipient name must not be mixed with billing fallback.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'фамилия получателя' ), 'Partial shipping first name must fail with missing last name.' );
}
$recipient_partial_last_order = new PekIntegrationOrder( 3008 );
$recipient_partial_last_order->set_shipping_fields( array( 'first_name' => '', 'last_name' => 'Петров', 'phone' => '79991234567' ) );
$recipient_partial_last_order->set_billing_fields( array( 'first_name' => 'Пётр', 'last_name' => 'Сидоров' ) );
try {
	$recipient_builder->build_physical_recipient( $recipient_partial_last_order, $request, PEK_INTEGRATION_RECEIVER_WAREHOUSE );
	pek_integration_assert( false, 'Partial shipping recipient last name must not be mixed with billing fallback.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'имя получателя' ), 'Partial shipping last name must fail with missing first name.' );
}
foreach ( array( 'patronymic', 'middleName', 'secondName' ) as $forbidden_key ) {
	pek_integration_assert( ! pek_integration_has_recursive_key( $recipient_shipping_name_payload, $forbidden_key ) && ! pek_integration_has_recursive_key( $recipient_billing_name_payload, $forbidden_key ), 'PEK recipient payload must not contain unsupported name key: ' . $forbidden_key );
}
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

$unparseable_stale_order = new PekIntegrationOrder( 2013 );
pek_integration_set_dadata( $unparseable_stale_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '5' );
$unparseable_stale_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'ул. Новая 11', 'address_2' => '' ) );
try {
	$addresses->from_order_with_evidence( $unparseable_stale_order );
	pek_integration_assert( false, 'Unparseable edited Woo address must not be authorized by stale DaData.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'подтверждённый полный адрес' ), 'Unparseable edited address must fail closed instead of returning old DaData.' );
}

$region_mismatch_order = new PekIntegrationOrder( 2014 );
pek_integration_set_dadata( $region_mismatch_order, 'shipping', 'Краснодарский край', 'Красный', 'улица Советская', '10' );
$region_mismatch_order->set_shipping_fields( array( 'city' => 'Красный', 'state' => 'Ростовская область', 'address_1' => 'улица Советская, дом 10', 'address_2' => '' ) );
$region_mismatch_evidence = $addresses->from_order_with_evidence( $region_mismatch_order );
pek_integration_assert( ! str_contains( $region_mismatch_evidence['address']->raw_address, 'Краснодарский край' ) && str_contains( $region_mismatch_evidence['address']->raw_address, 'Ростовская область' ), 'Region mismatch must reject DaData candidate for same-named city.' );

$settlement_city_match_order = new PekIntegrationOrder( 2015 );
$settlement_city_match_order->set_shipping_fields( array( 'city' => 'поселение Сосенское', 'state' => 'Москва', 'address_1' => 'улица Липовый парк, дом 2', 'address_2' => '' ) );
pek_integration_set_dadata( $settlement_city_match_order, 'shipping', 'Москва', 'Москва', 'улица Липовый парк', '2', '', 'поселение Сосенское' );
$settlement_city_match = $addresses->from_order_with_evidence( $settlement_city_match_order );
pek_integration_assert( 'shipping_dadata' === $settlement_city_match['evidence']['courier_address_source'], 'Woo city may match candidate settlement while candidate city keeps the parent locality.' );

$billing_fallback_order = new PekIntegrationOrder( 2004 );
$billing_fallback_order->set_shipping_fields( array( 'country' => '', 'state' => '', 'city' => '', 'postcode' => '', 'address_1' => '', 'address_2' => '' ) );
$billing_fallback_order->set_billing_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'Тверская улица, дом 1', 'address_2' => 'кв. 5' ) );
pek_integration_set_dadata( $billing_fallback_order, 'billing', 'Москва', 'Москва', 'Тверская улица', '1', '5' );
$billing_evidence = $addresses->from_order_with_evidence( $billing_fallback_order );
pek_integration_assert( 'billing_dadata' === $billing_evidence['evidence']['courier_address_source'], 'Billing DaData may be used only when shipping destination is empty.' );

$country_only_shipping_order = new PekIntegrationOrder( 2016 );
$country_only_shipping_order->set_shipping_fields( array( 'country' => 'RU', 'state' => '', 'city' => '', 'postcode' => '', 'address_1' => '', 'address_2' => '' ) );
$country_only_shipping_order->set_billing_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'Тверская улица, дом 1', 'address_2' => '' ) );
pek_integration_set_dadata( $country_only_shipping_order, 'billing', 'Москва', 'Москва', 'Тверская улица', '1' );
$country_only_evidence = $addresses->from_order_with_evidence( $country_only_shipping_order );
pek_integration_assert( 'billing_dadata' === $country_only_evidence['evidence']['courier_address_source'], 'Shipping country alone must not block valid billing fallback.' );

$city_only_shipping_order = new PekIntegrationOrder( 2017 );
$city_only_shipping_order->set_shipping_fields( array( 'country' => 'RU', 'state' => 'Москва', 'city' => 'Москва', 'postcode' => '', 'address_1' => '', 'address_2' => '' ) );
$city_only_shipping_order->set_billing_fields( array( 'city' => 'Москва', 'state' => 'Москва', 'address_1' => 'Тверская улица, дом 1', 'address_2' => '' ) );
pek_integration_set_dadata( $city_only_shipping_order, 'billing', 'Москва', 'Москва', 'Тверская улица', '1' );
$city_only_evidence = $addresses->from_order_with_evidence( $city_only_shipping_order );
pek_integration_assert( 'billing_dadata' === $city_only_evidence['evidence']['courier_address_source'], 'Shipping country plus city without address must not be treated as a complete courier destination.' );

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
$parsed_corpus = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, д 10, к 1' ) );
pek_integration_assert( 'Россия, Московская область, Видное, улица Советская, дом 10 к 1' === $addresses->address_stock( $parsed_corpus ), 'Courier parser must preserve comma-separated corpus in addressStock.' );
$parsed_structure = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, дом 10, стр. 2' ) );
pek_integration_assert( 'Россия, Московская область, Видное, улица Советская, дом 10 стр. 2' === $addresses->address_stock( $parsed_structure ), 'Courier parser must preserve comma-separated structure in addressStock.' );
$raw_office = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, дом 10, офис 7' ) );
pek_integration_assert( str_contains( $addresses->address_stock( $raw_office ), 'офис 7' ) && ! str_contains( $addresses->address_stock( $raw_office ), 'кв. 7' ), 'Raw address parser must preserve office unit type.' );
$raw_premise = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, дом 10, помещение 3' ) );
pek_integration_assert( str_contains( $addresses->address_stock( $raw_premise ), 'помещение 3' ) && ! str_contains( $addresses->address_stock( $raw_premise ), 'кв. 3' ), 'Raw address parser must preserve premise unit type.' );
$raw_apartment = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, дом 10, кв. 5' ) );
pek_integration_assert( str_contains( $addresses->address_stock( $raw_apartment ), 'кв. 5' ), 'Raw address parser must preserve apartment unit type.' );
$address2_office = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, дом 10', apartment: 'офис 7' ) );
pek_integration_assert( str_contains( $addresses->address_stock( $address2_office ), 'офис 7' ) && ! str_contains( $addresses->address_stock( $address2_office ), 'кв. 7' ), 'Woo address_2 office must remain office.' );
$address2_premise = $addresses->normalize( new Address( country_code: 'RU', city: 'Видное', region_name: 'Московская область', raw_address: 'улица Советская, дом 10', apartment: 'помещение 3' ) );
pek_integration_assert( str_contains( $addresses->address_stock( $address2_premise ), 'помещение 3' ) && ! str_contains( $addresses->address_stock( $address2_premise ), 'кв. 3' ), 'Woo address_2 premise must remain premise.' );
$typed_block_order = new PekIntegrationOrder( 2018 );
$typed_block_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Советская, дом 10, к 1', 'address_2' => '' ) );
pek_integration_set_dadata( $typed_block_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '', '', '1', 'к' );
$typed_block_evidence = $addresses->from_order_with_evidence( $typed_block_order );
pek_integration_assert( str_contains( $typed_block_evidence['address']->raw_address, 'дом 10 к 1' ) && ! str_contains( $typed_block_evidence['address']->raw_address, 'дом 10 1' ), 'Typed DaData block must be appended with type and never as ambiguous bare number.' );
$office_order = new PekIntegrationOrder( 2019 );
$office_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Советская, дом 10', 'address_2' => 'офис 7' ) );
pek_integration_set_dadata( $office_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '7' );
$office_order->update_meta_data( '_shipping_dadata_flat_type', 'офис' );
$office_evidence = $addresses->from_order_with_evidence( $office_order );
pek_integration_assert( str_contains( $addresses->address_stock( $office_evidence['address'] ), 'офис 7' ) && ! str_contains( $addresses->address_stock( $office_evidence['address'] ), 'кв. 7' ) && 'office' === (string) ( $office_evidence['evidence']['courier_unit_type'] ?? '' ), 'flat_type=office must not be formatted as apartment.' );
$office_full_type_order = new PekIntegrationOrder( 2024 );
$office_full_type_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Советская, дом 10', 'address_2' => 'офис 7' ) );
pek_integration_set_dadata( $office_full_type_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '7' );
$office_full_type_order->update_meta_data( '_shipping_dadata_flat_type', 'кв' );
$office_full_type_order->update_meta_data( '_shipping_dadata_flat_type_full', 'офис' );
$office_full_type_evidence = $addresses->from_order_with_evidence( $office_full_type_order );
pek_integration_assert( str_contains( $addresses->address_stock( $office_full_type_evidence['address'] ), 'офис 7' ) && ! str_contains( $addresses->address_stock( $office_full_type_evidence['address'] ), 'кв. 7' ), 'PEK resolver must prefer full DaData unit type over short compatibility value.' );
$premise_order = new PekIntegrationOrder( 2020 );
$premise_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Советская, дом 10', 'address_2' => 'помещение 3' ) );
pek_integration_set_dadata( $premise_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '3' );
$premise_order->update_meta_data( '_shipping_dadata_flat_type', 'помещение' );
$premise_evidence = $addresses->from_order_with_evidence( $premise_order );
pek_integration_assert( str_contains( $addresses->address_stock( $premise_evidence['address'] ), 'помещение 3' ) && ! str_contains( $addresses->address_stock( $premise_evidence['address'] ), 'кв. 3' ) && 'premise' === (string) ( $premise_evidence['evidence']['courier_unit_type'] ?? '' ), 'flat_type=premise must not be formatted as apartment.' );
$untyped_block_order = new PekIntegrationOrder( 2021 );
$untyped_block_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Советская, дом 10', 'address_2' => '' ) );
pek_integration_set_dadata( $untyped_block_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10', '', '', '1', '' );
try {
	$addresses->from_order_with_evidence( $untyped_block_order );
	pek_integration_assert( false, 'Untyped non-empty DaData block must not silently disappear.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'корпус' ), 'Untyped non-empty block must fail with safe public message.' );
}
$unsupported_house_type_order = new PekIntegrationOrder( 2023 );
$unsupported_house_type_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'улица Советская, дом 10', 'address_2' => '' ) );
pek_integration_set_dadata( $unsupported_house_type_order, 'shipping', 'Московская область', 'г Видное', 'улица Советская', '10' );
$unsupported_house_type_order->update_meta_data( '_shipping_dadata_house_type_full', '' );
$unsupported_house_type_order->update_meta_data( '_shipping_dadata_house_type', 'владение' );
try {
	$addresses->from_order_with_evidence( $unsupported_house_type_order );
	pek_integration_assert( false, 'Unsupported non-empty house_type must fail closed.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'тип дома' ), 'Unsupported house_type must fail with safe public message.' );
}
$stead_order = new PekIntegrationOrder( 2022 );
$stead_order->set_shipping_fields( array( 'city' => 'Видное', 'state' => 'Московская область', 'address_1' => 'участок 15', 'address_2' => '' ) );
$stead_order->update_meta_data( '_shipping_dadata_status', 'house_selected' );
$stead_order->update_meta_data( '_shipping_dadata_region_with_type', 'Московская область' );
$stead_order->update_meta_data( '_shipping_dadata_city_with_type', 'г Видное' );
$stead_order->update_meta_data( '_shipping_dadata_street_with_type', '' );
$stead_order->update_meta_data( '_shipping_dadata_house', '' );
$stead_order->update_meta_data( '_shipping_dadata_stead', '15' );
$stead_order->update_meta_data( '_shipping_dadata_stead_type', 'участок' );
try {
	$addresses->from_order_with_evidence( $stead_order );
	pek_integration_assert( false, 'Unsupported stead must not become ordinary house.' );
} catch ( RuntimeException $expected ) {
	pek_integration_assert( str_contains( $expected->getMessage(), 'улицей и номером дома' ) || str_contains( $expected->getMessage(), 'подтверждённый полный адрес' ), 'Unsupported stead must fail closed with public-safe address message.' );
}

$request_builder_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentRequestBuilder.php' ) ?: '';
pek_integration_assert( str_contains( $request_builder_source, "if ( '' !== \$client_card )" ), 'PEK request builder must omit empty counterpartClientCard.' );
pek_integration_assert( ! str_contains( $request_builder_source, "'counterpartClientCard' => \$this->settings->client_card()" ), 'PEK request builder must not serialize empty client card directly.' );

$all_urls = implode( "\n", array_map( static fn ( array $call ): string => $call['url'], $http->calls ) );
pek_integration_assert( str_contains( $all_urls, '/preregistration/submit/' ), 'Integration smoke must invoke fake PEK preregistration submit.' );
pek_integration_assert( ! str_contains( $all_urls, '/order/print/' ), 'Integration smoke must not download production documents.' );
pek_integration_assert( ! str_contains( $all_urls, '/cargos/cancelandreturncargo/' ), 'Integration smoke must not call PEK return API.' );

echo "PEK shipment integration smoke passed.\n";
