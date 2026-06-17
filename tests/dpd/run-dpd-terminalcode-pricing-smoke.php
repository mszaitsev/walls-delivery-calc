<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdEndpoints;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffDiagnosticRequest;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffDiagnosticRequestBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffDiagnosticService;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;

function dpd_terminalcode_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-18 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-terminalcode-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_terminalcode_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_terminalcode_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class DpdTerminalCodeFakeSoapClient implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$request = new DpdSoapRequest( $service, $method, $payload, $credentials, $options );
		$this->calls[] = array(
			'service' => $service,
			'method' => $method,
			'payload' => $payload,
			'soap_payload' => $request->payload_with_auth(),
			'options' => $options,
		);

		$cost = 'getServiceCostByParcels3' === $method ? 1500 : 1200;
		return new DpdSoapResponse(
			true,
			(object) array(
				'return' => (object) array(
					'serviceCode' => 'PCL',
					'serviceName' => 'DPD CLASSIC',
					'cost' => $cost,
					'days' => 3,
					'selfPickup' => true,
					'selfDelivery' => true,
				),
			),
			array(
				'wrapper' => $request->wrapper_mode(),
				'debug_payload_shape' => $request->redacted_payload_shape(),
			)
		);
	}

	public function is_available(): bool { return true; }
}

$GLOBALS['wdc_dpd_terminalcode_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 77, 'dpd_city_id' => '49455627', 'updated_at' => current_time( 'mysql' ) ),
	array( 'location_id' => 101, 'dpd_city_id' => '1', 'updated_at' => current_time( 'mysql' ) ),
);
$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'UNIQUE-PS', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 1, 'city_name' => 'City1', 'name' => 'A unique', 'address' => 'A street', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 2, 'terminal_code' => 'DUP-PS', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 1, 'city_name' => 'City1', 'name' => 'B duplicate parcel', 'address' => 'B street', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 3, 'terminal_code' => 'DUP-PS', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 1, 'city_name' => 'City1', 'name' => 'B duplicate terminal', 'address' => 'Terminal street', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
	array( 'id' => 4, 'terminal_code' => 'ONLY-DUP', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 2, 'city_name' => 'City2', 'name' => 'Only duplicate parcel', 'address' => 'Only street', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 5, 'terminal_code' => 'ONLY-DUP', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 2, 'city_name' => 'City2', 'name' => 'Only duplicate terminal', 'address' => 'Only terminal', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
	array( 'id' => 6, 'terminal_code' => 'AMB-1', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 3, 'city_name' => 'City3', 'name' => 'Amb 1', 'address' => 'Amb 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 7, 'terminal_code' => 'AMB-1', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 3, 'city_name' => 'City3', 'name' => 'Amb 1 terminal', 'address' => 'Amb 1 terminal', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
	array( 'id' => 8, 'terminal_code' => 'AMB-2', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 3, 'city_name' => 'City3', 'name' => 'Amb 2', 'address' => 'Amb 2', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 9, 'terminal_code' => 'AMB-2', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 3, 'city_name' => 'City3', 'name' => 'Amb 2 terminal', 'address' => 'Amb 2 terminal', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
	array( 'id' => 10, 'terminal_code' => 'TERM-ONLY', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 4, 'city_name' => 'City4', 'name' => 'Term only', 'address' => 'Terminal only', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST,
		DpdSettings::TEST_CLIENT_NUMBER_KEY => '1000000000',
		'dpd_test_client_key' => 'secret',
	)
);
$settings->save_tariff_settings_from_admin(
	array(
		DpdSettings::TARIFF_SENDER_DPD_CITY_ID_KEY => '49455627',
		DpdSettings::TARIFF_DEFAULT_WEIGHT_G_KEY => 1000,
		DpdSettings::TARIFF_DEFAULT_LENGTH_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_WIDTH_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_HEIGHT_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY => 1000,
	)
);

$request_builder = new DpdTerminalCodeTariffDiagnosticRequestBuilder();
$payload = $request_builder->build(
	new DpdTerminalCodeTariffDiagnosticRequest(
		'49455627',
		'49694102',
		'UNIQUE-PS',
		array( new WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffParcel( 2500, 30, 20, 10, 2 ) ),
		3000,
		true,
		true,
		'PICKUP-1',
		'PCL'
	)
);
dpd_terminalcode_assert( '49455627' === $payload['pickup']['cityId'] && 'PICKUP-1' === $payload['pickup']['terminalCode'], 'Parcels3 diagnostic payload must include pickup cityId and optional pickup terminalCode.' );
dpd_terminalcode_assert( '49694102' === $payload['delivery']['cityId'] && 'UNIQUE-PS' === $payload['delivery']['terminalCode'], 'Parcels3 diagnostic payload must include delivery cityId and delivery terminalCode.' );
dpd_terminalcode_assert( 3000.0 === $payload['declaredValue'] && 1.0 !== $payload['parcel'][0]['weight'] && 2 === $payload['parcel'][0]['quantity'], 'Parcels3 diagnostic payload must include declaredValue and parcel[].' );
dpd_terminalcode_assert( ! isset( $payload['extraService'], $payload['extraServices'] ), 'Parcels3 diagnostic payload must not include extraService unless explicitly added in a future stage.' );

$repository = new DpdPickupPointRepository( $GLOBALS['wpdb'] );
$pickup_service = new DpdPickupPointService( $repository, new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] ) );
$selection = $pickup_service->find_diagnostic_parcel_shop_for_city_id( 1 );
dpd_terminalcode_assert( 'UNIQUE-PS' === $selection['selected_terminal_code'] && 'parcel_shop' === $selection['selected_type'] && false === $selection['fallback_duplicate_was_used'], 'Diagnostic selector must prefer an unambiguous parcel_shop without terminal_self_delivery duplicate.' );
$selection_by_location = $pickup_service->find_diagnostic_parcel_shop_for_location_id( 101 );
dpd_terminalcode_assert( 'UNIQUE-PS' === $selection_by_location['selected_terminal_code'], 'Diagnostic selector must resolve receiver location_id to DPD cityId before choosing a parcel_shop.' );
$fallback = $pickup_service->find_diagnostic_parcel_shop_for_city_id( 2 );
dpd_terminalcode_assert( 'ONLY-DUP' === $fallback['selected_terminal_code'] && true === $fallback['fallback_duplicate_was_used'], 'Diagnostic selector may fallback to the only duplicated parcel_shop in a city.' );
$ambiguous = $pickup_service->find_diagnostic_parcel_shop_for_city_id( 3 );
dpd_terminalcode_assert( null === $ambiguous['point'] && true === $ambiguous['ambiguous'] && in_array( 'No unambiguous parcel_shop terminalCode found for cityId. Choose terminalCode manually.', $ambiguous['warnings'], true ), 'Diagnostic selector must reject multiple duplicated parcel_shop candidates as ambiguous.' );
$terminal_only = $pickup_service->find_diagnostic_parcel_shop_for_city_id( 4 );
dpd_terminalcode_assert( null === $terminal_only['point'] && '' === $terminal_only['selected_terminal_code'], 'Diagnostic selector must not choose terminal_self_delivery as a standalone candidate.' );

$soap = new DpdTerminalCodeFakeSoapClient();
$api = new DpdApiClient( $settings, $soap );
$diagnostic = new DpdTerminalCodeTariffDiagnosticService( $api, $settings, $request_builder, new DpdTariffOptionNormalizer() );
$result = $diagnostic->calculate(
	array(
		'pickup_city_id' => '49455627',
		'delivery_city_id' => '49694102',
		'pickup_terminal_code' => 'PICKUP-1',
		'delivery_terminal_code' => 'UNIQUE-PS',
		'declared_value_rub' => 3000,
		'parcels' => array( array( 'weight_g' => 2500, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10, 'quantity' => 2 ) ),
		'self_pickup' => true,
		'self_delivery' => true,
		'service_code' => 'PCL',
		'terminal_selection' => $selection,
	)
);
dpd_terminalcode_assert( $result->success && 1 === count( $result->parcels3_options ) && 1 === count( $result->parcels2_options ), 'Diagnostic service must call Parcels3 and Parcels2 comparison successfully.' );
dpd_terminalcode_assert( 'getServiceCostByParcels3' === $soap->calls[0]['method'] && DpdEndpoints::SERVICE_CALCULATOR === $soap->calls[0]['service'], 'Diagnostic service must call calculator2/getServiceCostByParcels3 first.' );
dpd_terminalcode_assert( DpdSoapRequest::WRAPPER_REQUEST === (string) ( $soap->calls[0]['options']['wrapper'] ?? '' ), 'getServiceCostByParcels3 wrapper must be request.' );
dpd_terminalcode_assert( isset( $soap->calls[0]['soap_payload']['request']['auth'] ) && ! isset( $soap->calls[0]['payload']['auth'] ), 'DpdSoapRequest must add auth centrally for Parcels3.' );
dpd_terminalcode_assert( 'UNIQUE-PS' === $soap->calls[0]['payload']['delivery']['terminalCode'] && 'PICKUP-1' === $soap->calls[0]['payload']['pickup']['terminalCode'], 'Parcels3 SOAP payload must contain delivery and optional pickup terminalCode.' );
dpd_terminalcode_assert( isset( $soap->calls[0]['payload']['parcel'][0], $soap->calls[0]['payload']['declaredValue'] ), 'Parcels3 SOAP payload must contain declaredValue and parcel[].' );
dpd_terminalcode_assert( ! isset( $soap->calls[0]['payload']['extraService'], $soap->calls[0]['payload']['extraServices'] ), 'Parcels3 SOAP payload must not contain extraService when not set.' );
dpd_terminalcode_assert( 'getServiceCostByParcels2' === $soap->calls[1]['method'] && ! isset( $soap->calls[1]['payload']['delivery']['terminalCode'], $soap->calls[1]['payload']['pickup']['terminalCode'] ), 'Comparison Parcels2 payload must not include terminalCode.' );
dpd_terminalcode_assert( 300.0 === (float) ( $result->comparison[0]['delta'] ?? 0 ), 'Diagnostic comparison must show Parcels3-Parcels2 delta.' );

$settings->save_tariff_action_result(
	array(
		'type' => 'warning',
		'title' => 'DPD terminalCode диагностика getServiceCostByParcels3',
		'message' => 'one-shot',
		'details' => array( 'comparison' => $result->comparison ),
	)
);
dpd_terminalcode_assert( 'one-shot' === (string) $settings->get_tariff_action_result()['message'], 'TerminalCode diagnostic must use existing DPD tariff one-shot result storage.' );
$settings->clear_tariff_action_result();
dpd_terminalcode_assert( array() === $settings->get_tariff_action_result(), 'TerminalCode diagnostic one-shot result must clear after render.' );

$api_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/DpdApiClient.php' ) ?: '';
$runtime_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php' ) ?: '';
$quote_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/DpdQuoteCarrier.php' ) ?: '';
$admin_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$plugin_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' ) ?: '';
dpd_terminalcode_assert( str_contains( $api_source, 'getServiceCostByParcels3' ) && str_contains( $api_source, "'wrapper' => DpdSoapRequest::WRAPPER_REQUEST" ), 'DpdApiClient must expose getServiceCostByParcels3 with request wrapper.' );
dpd_terminalcode_assert( str_contains( $runtime_source, 'getServiceCostByParcels2' ) && ! str_contains( $runtime_source, 'getServiceCostByParcels3' ) && ! str_contains( $runtime_source, 'terminalCode' ), 'Runtime DpdTariffCalculationService must remain Parcels2/cityId-only.' );
dpd_terminalcode_assert( ! str_contains( $quote_source, 'getServiceCostByParcels3' ) && ! str_contains( $quote_source, 'terminalCode' ), 'DpdQuoteCarrier must not use Parcels3 or terminalCode in checkout runtime.' );
dpd_terminalcode_assert( str_contains( $admin_source, 'test_dpd_terminalcode_tariff_calculation' ) && str_contains( $admin_source, 'DPD terminalCode диагностика' ), 'Admin page must register DPD terminalCode diagnostic action and block.' );
dpd_terminalcode_assert( ! str_contains( $plugin_source, 'DpdShipmentAdapter' ) && ! str_contains( $admin_source, 'DpdShipmentAdapter' ), 'DPD shipment adapter/metabox must not be added in terminalCode pricing diagnostics stage.' );

echo "DPD terminalCode pricing smoke OK\n";
