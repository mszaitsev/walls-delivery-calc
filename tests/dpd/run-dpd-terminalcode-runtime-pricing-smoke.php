<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdPackagingBuilderFactory;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffRequestBuilder;
use WallsShop\WDC\Carriers\Runtime\DpdQuoteCarrier;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function dpd_terminal_runtime_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-18 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-terminal-runtime-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_terminal_runtime_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_terminal_runtime_options'][ $key ] = $value; return true; }
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

final class DpdTerminalRuntimeFakeSoapClient implements DpdSoapClientInterface {
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

		return new DpdSoapResponse(
			true,
			array( 'return' => array( array( 'serviceCode' => 'MAX', 'serviceName' => 'DPD Максимум', 'cost' => 150.5, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 2 ) ) ),
			array( 'wrapper' => $request->wrapper_mode(), 'debug_payload_shape' => $request->redacted_payload_shape() )
		);
	}

	public function is_available(): bool {
		return true;
	}
}

function dpd_terminal_runtime_request( string $delivery_type = DeliveryType::PICKUP, string $terminal_code = '' ): QuoteRequest {
	$context = array(
		'selected_location_id' => '200',
		'delivery_type' => $delivery_type,
	);
	if ( '' !== $terminal_code ) {
		$context['dpd_selected_terminal_code'] = $terminal_code;
	}

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', postcode: '101000', street: 'Тверская', house: '1' ),
		new Package( array(), Money::from_rubles( 2500 ), Money::from_rubles( 2500 ), 1500, 0, 1500, 30, 20, 10, null, 'cart' ),
		'',
		Money::from_rubles( 2500 ),
		'2026-06-18',
		$context
	);
}

function dpd_terminal_runtime_carrier( DpdTerminalRuntimeFakeSoapClient $soap, DpdSettings $settings ): DpdQuoteCarrier {
	$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
	$pickup_service = new DpdPickupPointService( new DpdPickupPointRepository( $GLOBALS['wpdb'] ), $delivery_codes );
	$tariffs = new DpdTariffCalculationService(
		new DpdApiClient( $settings, $soap ),
		new DpdCityResolver( $delivery_codes ),
		new LocationRepository( $GLOBALS['wpdb'] ),
		$settings,
		new DpdTariffRequestBuilder(),
		new DpdTariffOptionNormalizer(),
		$pickup_service,
		new DpdTerminalCodeTariffRequestBuilder()
	);

	$dpd_packaging = new DpdPackagingBuilderFactory( new PackagingWeightCalculator( new SettingsRepository() ) );

	return new DpdQuoteCarrier( $settings, $tariffs, $dpd_packaging->create(), new Logger() );
}

$GLOBALS['wdc_dpd_terminal_runtime_options'] = array();
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
	array( 'id' => 2, 'terminal_code' => 'MSK-AUTO', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD Москва auto', 'address' => 'ул Тверская, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 3, 'terminal_code' => 'MSK-SELECTED', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD Москва selected', 'address' => 'ул Арбат, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 4, 'terminal_code' => 'MSK-SELECTED', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD duplicate terminal', 'address' => 'ул Арбат, 1', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
	array( 'id' => 5, 'terminal_code' => 'TERM-ONLY', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD terminal only', 'address' => 'ул Терминальная, 1', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST,
		DpdSettings::TEST_CLIENT_NUMBER_KEY => 'test-client-number',
		'dpd_test_client_key' => 'test-client-key',
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
$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1' ),
		DpdSettings::RUNTIME_ENABLE_COURIER_RATES_KEY => '1',
	)
);

$soap = new DpdTerminalRuntimeFakeSoapClient();
$carrier = dpd_terminal_runtime_carrier( $soap, $settings );
$pickup_quote = $carrier->quote( dpd_terminal_runtime_request() );
$pickup_payload = $soap->calls[0]['payload'] ?? array();
dpd_terminal_runtime_assert( 1 === count( $pickup_quote->rates ) && 'getServiceCostByParcels3' === $soap->calls[0]['method'], 'Pickup runtime must use Parcels3.' );
dpd_terminal_runtime_assert( 'NSK-SENDER' === (string) ( $pickup_payload['pickup']['terminalCode'] ?? '' ), 'Pickup runtime payload must contain sender pickup terminalCode.' );
dpd_terminal_runtime_assert( 'MSK-AUTO' === (string) ( $pickup_payload['delivery']['terminalCode'] ?? '' ), 'Pickup runtime payload must auto-select delivery terminalCode before buyer selection.' );
dpd_terminal_runtime_assert( true === ( $pickup_payload['selfPickup'] ?? null ) && true === ( $pickup_payload['selfDelivery'] ?? null ), 'Pickup runtime payload must use selfPickup=true and selfDelivery=true.' );

$selected_quote = $carrier->quote( dpd_terminal_runtime_request( DeliveryType::PICKUP, 'MSK-SELECTED' ) );
$selected_payload = $soap->calls[1]['payload'] ?? array();
dpd_terminal_runtime_assert( 'MSK-SELECTED' === (string) ( $selected_payload['delivery']['terminalCode'] ?? '' ), 'Pickup runtime must use buyer-selected terminalCode after selection.' );
dpd_terminal_runtime_assert( $pickup_quote->quote_id !== $selected_quote->quote_id, 'DPD quote_id must change when delivery terminalCode changes.' );

$invalid_selected = $carrier->quote( dpd_terminal_runtime_request( DeliveryType::PICKUP, 'TERM-ONLY' ) );
dpd_terminal_runtime_assert( array() === $invalid_selected->rates, 'terminal_self_delivery must not be used as runtime delivery terminal.' );
dpd_terminal_runtime_assert( 'TERM-ONLY' === (string) ( $invalid_selected->raw_reference['delivery_terminal_code'] ?? '' ) && 'selected' === (string) ( $invalid_selected->raw_reference['delivery_terminal_source'] ?? '' ) && array() === ( $invalid_selected->raw_reference['delivery_terminal_selection'] ?? null ), 'Invalid explicit terminal diagnostics must expose selected source and empty selection.' );

$courier_quote = $carrier->quote( dpd_terminal_runtime_request( DeliveryType::COURIER ) );
$courier_payload = $soap->calls[2]['payload'] ?? array();
dpd_terminal_runtime_assert( 1 === count( $courier_quote->rates ) && 'getServiceCostByParcels3' === $soap->calls[2]['method'], 'Courier runtime must use Parcels3.' );
dpd_terminal_runtime_assert( 'NSK-SENDER' === (string) ( $courier_payload['pickup']['terminalCode'] ?? '' ) && ! isset( $courier_payload['delivery']['terminalCode'] ), 'Courier payload must include pickup terminalCode and omit delivery terminalCode.' );

$pickup_service = new DpdPickupPointService( new DpdPickupPointRepository( $GLOBALS['wpdb'] ), new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] ) );
$runtime_selected = $pickup_service->find_runtime_parcel_shop_by_terminal_code( 'MSK-SELECTED', 49694102 );
dpd_terminal_runtime_assert( 'parcel_shop' === (string) ( $runtime_selected['type'] ?? '' ), 'Runtime terminal lookup must prefer parcel_shop over duplicate terminal_self_delivery.' );

$GLOBALS['wpdb']->dpd_pickup_points = array_values(
	array_filter(
		$GLOBALS['wpdb']->dpd_pickup_points,
		static fn( array $row ): bool => ! ( 49694102 === (int) ( $row['city_id'] ?? 0 ) && 'parcel_shop' === (string) ( $row['type'] ?? '' ) )
	)
);
$no_parcel_shop_quote = $carrier->quote( dpd_terminal_runtime_request() );
dpd_terminal_runtime_assert( array() === $no_parcel_shop_quote->rates, 'No receiver parcel_shop must make pickup quote empty.' );

$GLOBALS['wpdb']->dpd_pickup_points[] = array( 'id' => 6, 'terminal_code' => 'MSK-DUP', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD duplicated parcel', 'address' => 'ул Дубль, 1', 'source' => 'getParcelShops', 'is_active' => 1 );
$GLOBALS['wpdb']->dpd_pickup_points[] = array( 'id' => 7, 'terminal_code' => 'MSK-DUP', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD duplicated terminal', 'address' => 'ул Дубль, 1', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 );
$fallback_quote = $carrier->quote( dpd_terminal_runtime_request() );
$fallback_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_terminal_runtime_assert( 1 === count( $fallback_quote->rates ) && 'MSK-DUP' === (string) ( $fallback_payload['delivery']['terminalCode'] ?? '' ), 'Runtime fallback must allow duplicated parcel_shop when no unambiguous parcel_shop exists.' );

$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' );
dpd_terminal_runtime_assert( str_contains( $js_source, "requiresRateRefreshAfterPickupSave" ) && str_contains( $js_source, "update_checkout" ) && str_contains( $js_source, "dpd:pickup" ), 'Checkout JS must trigger update_checkout after saving a DPD pickup point.' );

$carrier_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/DpdQuoteCarrier.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$dpd_adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' );
dpd_terminal_runtime_assert( str_contains( $carrier_source, 'getServiceCostByParcels3' ) || str_contains( $carrier_source, 'DpdTariffCalculationService' ), 'DpdQuoteCarrier must route runtime pricing through the tariff service.' );
dpd_terminal_runtime_assert( ! str_contains( $plugin_source . $admin_source, 'DpdTerminalCodeTariffDiagnostic' ) && ! str_contains( $admin_source, 'test_dpd_terminalcode_tariff_calculation' ), 'Admin terminalCode diagnostic UI/service must be removed.' );
dpd_terminal_runtime_assert( str_contains( $plugin_source, 'DpdShipmentAdapter' ) && str_contains( $dpd_adapter_source, 'createOrder2' ) && str_contains( $dpd_adapter_source, 'supports_status_auto_sync(): bool' ), 'DPD may have manual createOrder2 adapter while terminalCode runtime pricing remains separate.' );

echo "DPD terminalCode runtime pricing smoke test passed.\n";
