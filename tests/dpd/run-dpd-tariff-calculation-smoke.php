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
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffParcel;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequest;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffRequest;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffRequestBuilder;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function dpd_tariff_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-16 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-tariff-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_tariff_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_tariff_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();
	}
}

final class DpdTariffFakeSoapClient implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	public mixed $next_body;

	public function __construct() {
		$this->next_body = (object) array(
			'return' => (object) array(
				'serviceCode' => 'PCL',
				'serviceName' => 'DPD CLASSIC',
				'cost' => 1234.56,
				'days' => 3,
				'selfPickup' => true,
				'selfDelivery' => false,
			),
		);
	}

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$request = new DpdSoapRequest( $service, $method, $payload, $credentials, $options );
		$this->calls[] = array(
			'service' => $service,
			'method' => $method,
			'payload' => $payload,
			'soap_payload' => $request->payload_with_auth(),
			'debug_payload_shape' => $request->redacted_payload_shape(),
			'credentials' => $credentials,
			'options' => $options,
		);

		return new DpdSoapResponse( true, $this->next_body, array( 'fake' => true, 'wrapper' => $request->wrapper_mode(), 'debug_payload_shape' => $request->redacted_payload_shape() ) );
	}

	public function is_available(): bool {
		return true;
	}
}

$GLOBALS['wdc_dpd_tariff_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 100, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Новосибирская область', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск' ),
	array( 'id' => 200, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Москва', 'place_name' => 'Москва', 'place_type' => 'г', 'display_name' => 'Москва' ),
);
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 100, 'dpd_city_id' => '49455627', 'updated_at' => current_time( 'mysql' ) ),
	array( 'location_id' => 200, 'dpd_city_id' => '49694102', 'updated_at' => current_time( 'mysql' ) ),
);
$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'NSK-SENDER', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'name' => 'DPD Новосибирск', 'address' => 'ул Ленина, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 2, 'terminal_code' => 'MSK-AUTO', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD Москва', 'address' => 'ул Тверская, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 3, 'terminal_code' => 'MSK-SELECTED', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD Москва selected', 'address' => 'ул Арбат, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 4, 'terminal_code' => 'MSK-SELECTED', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD duplicate terminal', 'address' => 'ул Арбат, 1', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST,
		DpdSettings::TEST_CLIENT_NUMBER_KEY => 'test-client-number',
		'dpd_test_client_key' => 'test-client-key',
		DpdSettings::REQUEST_TIMEOUT_KEY => 20,
	)
);
$settings->save_tariff_settings_from_admin(
	array(
		DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY => 100,
		DpdSettings::TARIFF_DEFAULT_WEIGHT_G_KEY => 1500,
		DpdSettings::TARIFF_DEFAULT_LENGTH_CM_KEY => 30,
		DpdSettings::TARIFF_DEFAULT_WIDTH_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_HEIGHT_CM_KEY => 10,
		DpdSettings::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY => 2500,
	)
);

$builder = new DpdTariffRequestBuilder();
$payload = $builder->build(
	new DpdTariffRequest(
		'49455627',
		'49694102',
		array( new DpdTariffParcel( 4500, 38, 24, 21 ) ),
		2500,
		true,
		false,
		'PCL',
		'2026-06-17'
	)
);
dpd_tariff_assert( ! isset( $payload['auth'] ), 'Builder must not duplicate auth; DpdSoapRequest adds it centrally.' );
dpd_tariff_assert( '49455627' === $payload['pickup']['cityId'] && '49694102' === $payload['delivery']['cityId'], 'Builder must include pickup and delivery cityId.' );
dpd_tariff_assert( ! isset( $payload['extraService'] ) && ! isset( $payload['extraServices'] ), 'Builder must not include DPD extraService in Parcels2 payload.' );
dpd_tariff_assert( 4.5 === $payload['parcel'][0]['weight'] && 38.0 === $payload['parcel'][0]['length'] && 24.0 === $payload['parcel'][0]['width'] && 21.0 === $payload['parcel'][0]['height'] && 1 === $payload['parcel'][0]['quantity'] && 2500.0 === $payload['declaredValue'], 'Builder must include one parcel weight=4.5kg, dimensions 38x24x21, quantity=1 and declared value.' );
$multi_payload = $builder->build(
	new DpdTariffRequest(
		'49455627',
		'49694102',
		array( new DpdTariffParcel( 4500, 38, 24, 21, 1 ), new DpdTariffParcel( 4500, 38, 24, 21, 1 ) ),
		5000,
		true,
		true
	)
);
dpd_tariff_assert( 2 === count( $multi_payload['parcel'] ?? array() ) && 4.5 === $multi_payload['parcel'][1]['weight'], 'Builder must preserve explicitly provided multi-parcel requests as separate parcel entries.' );
$terminal_builder = new DpdTerminalCodeTariffRequestBuilder();
$terminal_payload = $terminal_builder->build(
	new DpdTerminalCodeTariffRequest(
		'49455627',
		'49694102',
		array( new DpdTariffParcel( 4500, 38, 24, 21, 1 ) ),
		5000,
		true,
		true,
		'NSK-SENDER',
		'MSK-AUTO',
		'PCL',
		'2026-06-17'
	)
);
dpd_tariff_assert( 'NSK-SENDER' === (string) ( $terminal_payload['pickup']['terminalCode'] ?? '' ) && 'MSK-AUTO' === (string) ( $terminal_payload['delivery']['terminalCode'] ?? '' ), 'Parcels3 terminalCode builder must include pickup and delivery terminalCode for pickup delivery.' );
dpd_tariff_assert( ! isset( $terminal_payload['extraService'] ) && ! isset( $terminal_payload['extraServices'] ), 'Parcels3 terminalCode builder must not include extra services.' );
$direct_request = new DpdSoapRequest( DpdEndpoints::SERVICE_CALCULATOR, 'getServiceCostByParcels2', $payload, new DpdCredentials( 'client-number', 'client-key', DpdSettings::ENV_TEST ) );
$direct_payload_with_auth = $direct_request->payload_with_auth();
dpd_tariff_assert( isset( $direct_payload_with_auth['auth']['clientNumber'], $direct_payload_with_auth['auth']['clientKey'] ) && ! isset( $direct_payload_with_auth['request'] ), 'DpdSoapRequest direct wrapper must add auth at the root.' );
$calculator_request = new DpdSoapRequest( DpdEndpoints::SERVICE_CALCULATOR, 'getServiceCostByParcels2', $payload, new DpdCredentials( 'client-number', 'client-key', DpdSettings::ENV_TEST ), array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST ) );
$calculator_payload_with_auth = $calculator_request->payload_with_auth();
$calculator_debug_shape = $calculator_request->redacted_payload_shape();
dpd_tariff_assert( isset( $calculator_payload_with_auth['request']['auth']['clientNumber'], $calculator_payload_with_auth['request']['auth']['clientKey'] ) && ! isset( $calculator_payload_with_auth['auth'] ), 'DpdSoapRequest request wrapper must add auth below request for calculator2.' );
dpd_tariff_assert( DpdSoapRequest::WRAPPER_REQUEST === $calculator_debug_shape['wrapper'] && 'yes' === $calculator_debug_shape['has_auth'] && ! str_contains( (string) wp_json_encode( $calculator_debug_shape ), 'client-key' ), 'Redacted payload shape must expose wrapper/auth presence without leaking clientKey.' );

$normalizer = new DpdTariffOptionNormalizer();
$single = $normalizer->normalize(
	new DpdSoapResponse(
		true,
		(object) array( 'return' => (object) array( 'serviceCode' => 'PCL', 'serviceName' => 'Classic', 'cost' => '321.50', 'deliveryPeriodMin' => 2, 'deliveryPeriodMax' => 4 ) )
	)
);
dpd_tariff_assert( 1 === count( $single ) && 'PCL' === $single[0]['service_code'] && 321.5 === $single[0]['cost'], 'Normalizer must support a single object response.' );
$multiple = $normalizer->normalize(
	array(
		'return' => array(
			'serviceCost' => array(
				array( 'serviceCode' => 'PCL', 'serviceName' => 'Classic', 'cost' => 321 ),
				array( 'service_code' => 'ECN', 'service_name' => 'Economy', 'price' => 222 ),
			),
		),
	)
);
dpd_tariff_assert( 2 === count( $multiple ) && 'ECN' === $multiple[1]['service_code'], 'Normalizer must support Parcels2 serviceCost array response and alternate casing.' );

$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$resolver = new DpdCityResolver( $delivery_codes );
$locations = new LocationRepository( $GLOBALS['wpdb'] );
$soap = new DpdTariffFakeSoapClient();
$api = new DpdApiClient( $settings, $soap );
$pickup_service = new DpdPickupPointService( new DpdPickupPointRepository( $GLOBALS['wpdb'] ), $delivery_codes );
$service = new DpdTariffCalculationService( $api, $resolver, $locations, $settings, $builder, $normalizer, $pickup_service, $terminal_builder );

$stored_options_before_missing_sender = $GLOBALS['wdc_dpd_tariff_options'];
$GLOBALS['wdc_dpd_tariff_options']['wdc_core_settings'][ DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY ] = 0;
$GLOBALS['wdc_dpd_tariff_options']['wdc_core_settings'][ DpdSettings::TARIFF_SENDER_DPD_CITY_ID_KEY ] = '';
$missing_sender_settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$missing_sender_service = new DpdTariffCalculationService( $api, $resolver, $locations, $missing_sender_settings, $builder, $normalizer, $pickup_service, $terminal_builder );
$missing_sender = $missing_sender_service->calculate( 200, array() );
dpd_tariff_assert( false === $missing_sender->success && in_array( 'DPD sender cityId is not configured.', $missing_sender->errors, true ), 'Service must return a controlled error without sender cityId.' );
$GLOBALS['wdc_dpd_tariff_options'] = $stored_options_before_missing_sender;

$stored_options_before_missing_credentials = $GLOBALS['wdc_dpd_tariff_options'];
$GLOBALS['wdc_dpd_tariff_options']['wdc_core_settings'][ DpdSettings::TEST_CLIENT_NUMBER_KEY ] = '';
$GLOBALS['wdc_dpd_tariff_options']['wdc_core_settings'][ DpdSettings::TEST_CLIENT_KEY_ENCRYPTED_KEY ] = '';
$soap_calls_before_missing_credentials = count( $soap->calls );
$missing_credentials = $service->calculate( 200, array( 'sender_dpd_city_id' => '49455627' ) );
dpd_tariff_assert( false === $missing_credentials->success && in_array( 'DPD credentials are incomplete for current environment.', $missing_credentials->errors, true ), 'Service must return a controlled error when credentials are incomplete.' );
dpd_tariff_assert( $soap_calls_before_missing_credentials === count( $soap->calls ), 'Service must not call SOAP when DPD credentials are incomplete.' );
$GLOBALS['wdc_dpd_tariff_options'] = $stored_options_before_missing_credentials;

$missing_receiver = $service->calculate( 999, array( 'sender_dpd_city_id' => '49455627' ) );
dpd_tariff_assert( false === $missing_receiver->success && str_contains( implode( ' ', $missing_receiver->errors ), 'DPD cityId was not found for receiver location_id' ), 'Service must return a controlled error without receiver cityId.' );

$result = $service->calculate(
	200,
	array(
		'weight_g' => 1500,
		'length_cm' => 30,
		'width_cm' => 20,
		'height_cm' => 10,
		'declared_value_rub' => 2500,
		'self_pickup' => true,
		'self_delivery' => false,
		'service_code' => 'PCL',
	)
);
dpd_tariff_assert( true === $result->success && 1 === count( $result->options ), 'Service must call fake DPD API and normalize single response.' );
dpd_tariff_assert( 1 === count( $soap->calls ), 'Service must make one DPD API call.' );
dpd_tariff_assert( DpdEndpoints::SERVICE_CALCULATOR === $soap->calls[0]['service'] && 'getServiceCostByParcels3' === $soap->calls[0]['method'], 'Service must call calculator2 getServiceCostByParcels3.' );
dpd_tariff_assert( '49455627' === $soap->calls[0]['payload']['pickup']['cityId'] && '49694102' === $soap->calls[0]['payload']['delivery']['cityId'], 'Service must pass expected sender/receiver cityId in payload.' );
dpd_tariff_assert( 'NSK-SENDER' === (string) ( $soap->calls[0]['payload']['pickup']['terminalCode'] ?? '' ) && ! isset( $soap->calls[0]['payload']['delivery']['terminalCode'] ), 'Courier Parcels3 payload must include pickup terminalCode and omit delivery terminalCode.' );
dpd_tariff_assert( DpdSoapRequest::WRAPPER_REQUEST === $soap->calls[0]['options']['wrapper'] && isset( $soap->calls[0]['soap_payload']['request']['auth']['clientNumber'], $soap->calls[0]['soap_payload']['request']['auth']['clientKey'] ), 'Calculator SOAP call must send auth inside the request wrapper.' );
dpd_tariff_assert( ! isset( $soap->calls[0]['payload']['auth'] ) && ! isset( $soap->calls[0]['payload']['request'] ), 'Business payload passed to DpdApiClient must remain auth-free and wrapper-free.' );
dpd_tariff_assert( ! isset( $soap->calls[0]['payload']['extraService'] ) && ! isset( $soap->calls[0]['payload']['extraServices'] ), 'Parcels3 business payload must not include extra services.' );
dpd_tariff_assert( DpdSoapRequest::WRAPPER_REQUEST === ( $result->meta['wrapper'] ?? '' ) && 'yes' === ( $result->meta['debug_payload_shape']['has_auth'] ?? '' ), 'Tariff result meta must expose redacted wrapper/auth debug shape.' );
dpd_tariff_assert( 'getServiceCostByParcels3' === (string) ( $result->meta['method'] ?? '' ), 'Tariff result meta must expose Parcels3 runtime method.' );
dpd_tariff_assert( ! str_contains( (string) wp_json_encode( $result->meta['debug_payload_shape'] ?? array() ), 'test-client-key' ), 'Tariff debug payload shape must not leak clientKey.' );

$explicit_multi_result = $service->calculate(
	200,
	array(
		'parcels' => array(
			new DpdTariffParcel( 4500, 38, 24, 21, 1 ),
			array( 'weight_g' => 4500, 'length_cm' => 38, 'width_cm' => 24, 'height_cm' => 21, 'quantity' => 1 ),
		),
		'declared_value_rub' => 5000,
		'sender_dpd_city_id' => '49455627',
		'self_pickup' => true,
		'self_delivery' => true,
	)
);
$multi_service_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_tariff_assert( true === $explicit_multi_result->success && 2 === count( $multi_service_payload['parcel'] ?? array() ), 'Service must pass valid explicit multi-parcel params to Parcels3 payload.' );
dpd_tariff_assert( 'MSK-AUTO' === (string) ( $multi_service_payload['delivery']['terminalCode'] ?? '' ), 'Pickup Parcels3 payload must auto-select delivery terminalCode when no buyer terminalCode is provided.' );

$soap->next_body = array(
	'return' => array(
		array( 'serviceCode' => 'PCL', 'serviceName' => 'Classic', 'cost' => 321 ),
		array( 'serviceCode' => 'MAX', 'serviceName' => 'Express', 'cost' => 654 ),
	),
);
$array_result = $service->calculate( 200, array( 'sender_dpd_city_id' => '49455627' ) );
dpd_tariff_assert( true === $array_result->success && 2 === count( $array_result->options ), 'Service must normalize array response.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$dpd_adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' );
dpd_tariff_assert( str_contains( $admin_source, 'DPD Расчет' ) && str_contains( $admin_source, 'Настройки расчета DPD' ), 'Admin page must keep the DPD calculation settings tab.' );
dpd_tariff_assert( ! str_contains( $admin_source, 'Тестовый расчет DPD' ) && ! str_contains( $admin_source, 'test_dpd_tariff_calculation' ) && ! str_contains( $admin_source, 'render_dpd_tariff_action_result' ), 'Admin page must not expose the removed DPD test calculation form/result block.' );
dpd_tariff_assert( ! str_contains( $admin_source, 'Город отправителя для отображения' ) && ! str_contains( $admin_source, 'TARIFF_SENDER_CITY_NAME_KEY' ), 'Admin page must not expose the removed sender display-only field.' );
dpd_tariff_assert( str_contains( $plugin_source, 'DpdQuoteCarrier' ) && str_contains( $plugin_source, 'DpdShipmentAdapter' ) && str_contains( $dpd_adapter_source, 'dpd_create_disabled' ), 'DPD may be registered for checkout quotes and dry-run shipment preview only.' );
dpd_tariff_assert( str_contains( $plugin_source, 'RussianPostInternationalCarrier' ) && str_contains( $plugin_source, 'RussianPostDomesticCarrier' ) && str_contains( $plugin_source, 'CdekCarrier' ), 'Existing CDEK/Russian Post runtime registrations must remain present.' );
dpd_tariff_assert( ! str_contains( $admin_source, 'createOrder' ) && ! str_contains( $admin_source, 'unitLoad' ), 'DPD tariff admin must not add shipment creation or unitLoad.' );
dpd_tariff_assert( str_contains( $admin_source, 'render_dpd_pickup_tab' ) && str_contains( $admin_source, 'getParcelShops' ), 'DPD parcel shop import must live in the separate pickup tab, not in tariff calculation runtime.' );

echo "DPD tariff calculation smoke test passed.\n";
