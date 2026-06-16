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
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffParcel;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequest;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
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
		$this->calls[] = array(
			'service' => $service,
			'method' => $method,
			'payload' => $payload,
			'credentials' => $credentials,
			'options' => $options,
		);

		return new DpdSoapResponse( true, $this->next_body, array( 'fake' => true ) );
	}

	public function is_available(): bool {
		return true;
	}
}

$GLOBALS['wdc_dpd_tariff_options'] = array(
	DpdSettings::TEST_CLIENT_NUMBER_KEY => 'test-client-number',
	DpdSettings::TEST_CLIENT_KEY_ENCRYPTED_KEY => ( new EncryptionService() )->encrypt( 'test-client-key' ),
);
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 100, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Новосибирская область', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск' ),
	array( 'id' => 200, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Москва', 'place_name' => 'Москва', 'place_type' => 'г', 'display_name' => 'Москва' ),
);
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 100, 'dpd_city_id' => '49455627', 'updated_at' => current_time( 'mysql' ) ),
	array( 'location_id' => 200, 'dpd_city_id' => '49694102', 'updated_at' => current_time( 'mysql' ) ),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_tariff_settings_from_admin(
	array(
		DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY => 100,
		DpdSettings::TARIFF_SENDER_CITY_NAME_KEY => 'Новосибирск',
		DpdSettings::TARIFF_DEFAULT_WEIGHT_G_KEY => 1500,
		DpdSettings::TARIFF_DEFAULT_LENGTH_CM_KEY => 30,
		DpdSettings::TARIFF_DEFAULT_WIDTH_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_HEIGHT_CM_KEY => 10,
		DpdSettings::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY => 2500,
	)
);
$settings->save_tariff_action_result(
	array(
		'type' => 'success',
		'title' => 'DPD Расчет',
		'message' => 'Visible result',
		'details' => array( 'raw_count' => 1, 'options' => array( array( 'service_code' => 'PCL' ) ) ),
	)
);
$stored_result = $settings->get_tariff_action_result();
dpd_tariff_assert( 'DPD Расчет' === (string) $stored_result['title'] && '1' === (string) $stored_result['details']['raw_count'], 'DPD tariff action result must be saved for visible admin rendering.' );

$builder = new DpdTariffRequestBuilder();
$payload = $builder->build(
	new DpdTariffRequest(
		'49455627',
		'49694102',
		array( new DpdTariffParcel( 1500, 30, 20, 10 ) ),
		2500,
		true,
		false,
		'PCL',
		'2026-06-17'
	)
);
dpd_tariff_assert( ! isset( $payload['auth'] ), 'Builder must not duplicate auth; DpdSoapRequest adds it centrally.' );
dpd_tariff_assert( '49455627' === $payload['pickup']['cityId'] && '49694102' === $payload['delivery']['cityId'], 'Builder must include pickup and delivery cityId.' );
dpd_tariff_assert( 1.5 === $payload['parcel'][0]['weight'] && 30.0 === $payload['parcel'][0]['length'] && 2500.0 === $payload['declaredValue'], 'Builder must include parcel weight, dimensions and declared value.' );
$soap_request = new DpdSoapRequest( DpdEndpoints::SERVICE_CALCULATOR, 'getServiceCostByParcels3', $payload, new DpdCredentials( 'client-number', 'client-key', DpdSettings::ENV_TEST ) );
$payload_with_auth = $soap_request->payload_with_auth();
dpd_tariff_assert( isset( $payload_with_auth['auth']['clientNumber'], $payload_with_auth['auth']['clientKey'] ), 'DpdSoapRequest must add auth centrally.' );

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
			array( 'serviceCode' => 'PCL', 'serviceName' => 'Classic', 'cost' => 321 ),
			array( 'service_code' => 'ECN', 'service_name' => 'Economy', 'price' => 222 ),
		),
	)
);
dpd_tariff_assert( 2 === count( $multiple ) && 'ECN' === $multiple[1]['service_code'], 'Normalizer must support an array response and alternate casing.' );

$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$resolver = new DpdCityResolver( $delivery_codes );
$locations = new LocationRepository( $GLOBALS['wpdb'] );
$soap = new DpdTariffFakeSoapClient();
$api = new DpdApiClient( $settings, $soap );
$service = new DpdTariffCalculationService( $api, $resolver, $locations, $settings, $builder, $normalizer );

$stored_options_before_missing_sender = $GLOBALS['wdc_dpd_tariff_options'];
$GLOBALS['wdc_dpd_tariff_options']['wdc_core_settings'][ DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY ] = 0;
$GLOBALS['wdc_dpd_tariff_options']['wdc_core_settings'][ DpdSettings::TARIFF_SENDER_DPD_CITY_ID_KEY ] = '';
$missing_sender_settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$missing_sender_service = new DpdTariffCalculationService( $api, $resolver, $locations, $missing_sender_settings, $builder, $normalizer );
$missing_sender = $missing_sender_service->calculate( 200, array() );
dpd_tariff_assert( false === $missing_sender->success && in_array( 'DPD sender cityId is not configured.', $missing_sender->errors, true ), 'Service must return a controlled error without sender cityId.' );
$GLOBALS['wdc_dpd_tariff_options'] = $stored_options_before_missing_sender;

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
dpd_tariff_assert( str_contains( $admin_source, 'DPD Расчет' ) && str_contains( $admin_source, 'render_dpd_tariff_action_result' ) && str_contains( $admin_source, 'test_dpd_tariff_calculation' ), 'Admin page must expose an admin-only DPD tariff calculator with visible result storage.' );
dpd_tariff_assert( ! str_contains( $plugin_source, 'DpdCarrier' ) && ! str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'DPD must not be registered in checkout or shipment adapters.' );
dpd_tariff_assert( str_contains( $plugin_source, 'RussianPostInternationalCarrier' ) && str_contains( $plugin_source, 'RussianPostDomesticCarrier' ) && str_contains( $plugin_source, 'CdekCarrier' ), 'Existing CDEK/Russian Post runtime registrations must remain present.' );
dpd_tariff_assert( ! str_contains( $admin_source, 'createOrder' ) && ! str_contains( $admin_source, 'getParcelShops' ) && ! str_contains( $admin_source, 'unitLoad' ), 'DPD tariff admin must not add shipment creation, parcel shops or unitLoad.' );

echo "DPD tariff calculation smoke test passed.\n";
