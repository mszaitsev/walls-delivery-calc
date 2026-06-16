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
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\DpdQuoteCarrier;
use WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;

function dpd_checkout_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-17 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-checkout-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_checkout_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_checkout_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();
		/** @var array<int,array<string,mixed>> */
		public array $countries = array();
		/** @var array<int,array<string,mixed>> */
		public array $rules = array();
		/** @var array<int,array<string,mixed>> */
		public array $conditions = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries[] = $data;
			} elseif ( str_contains( $table, 'wdc_delivery_services' ) ) {
				$this->services[] = $data;
			}
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			$rows =& $this->rows_for_table( $table );
			foreach ( $rows as $index => $row ) {
				$matches = true;
				foreach ( $where as $key => $value ) {
					$matches = $matches && (string) ( $row[ $key ] ?? '' ) === (string) $value;
				}
				if ( $matches ) {
					$rows[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}

		public function delete( string $table, array $where, array $format = array() ): bool {
			$rows =& $this->rows_for_table( $table );
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $where ): bool {
						foreach ( $where as $key => $value ) {
							if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
								return true;
							}
						}
						return false;
					}
				)
			);
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (int) $row['id'] === (int) $matches[1] ) {
						return $row;
					}
				}
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( str_contains( $query, 'ORDER BY deleted ASC' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				$rows = array_values( array_filter( $this->services, static fn( array $row ): bool => empty( $row['deleted'] ) ) );
				if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) $row['service_key'] === $matches[1] ) );
				}
				return $rows;
			}
			if ( str_contains( $query, 'wdc_rules' ) || str_contains( $query, 'wdc_rule_conditions' ) ) {
				return array();
			}
			return array();
		}

		public function get_col( string $query ): array {
			if ( preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_map( static fn( array $row ): string => (string) $row['country_code'], array_filter( $this->countries, static fn( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) ) );
			}
			return array();
		}

		private function &rows_for_table( string $table ): array {
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				return $this->countries;
			}
			return $this->services;
		}
	}
}

final class DpdCheckoutFakeSoapClient implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	public mixed $next_body;

	public function __construct() {
		$this->next_body = array(
			'return' => array(
				array( 'serviceCode' => 'MAX', 'serviceName' => 'DPD Максимум', 'cost' => 100.25, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 2 ),
				array( 'serviceCode' => 'NDY', 'serviceName' => 'DPD Экспресс', 'cost' => 220.10, 'deliveryPeriodMin' => 2, 'deliveryPeriodMax' => 3 ),
				array( 'serviceCode' => 'BAD', 'serviceName' => 'No cost' ),
			),
		);
	}

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$request = new DpdSoapRequest( $service, $method, $payload, $credentials, $options );
		$this->calls[] = array( 'service' => $service, 'method' => $method, 'payload' => $payload, 'soap_payload' => $request->payload_with_auth(), 'options' => $options );

		return new DpdSoapResponse( true, $this->next_body, array( 'wrapper' => $request->wrapper_mode(), 'debug_payload_shape' => $request->redacted_payload_shape() ) );
	}

	public function is_available(): bool {
		return true;
	}
}

function dpd_checkout_request( int $location_id = 200, int $weight_g = 1500 ): QuoteRequest {
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', postcode: '101000', street: 'Тверская', house: '1' ),
		new Package( array(), Money::from_rubles( 2500 ), Money::from_rubles( 2500 ), $weight_g, 0, $weight_g, 30, 20, 10, null, 'cart' ),
		'',
		Money::from_rubles( 2500 ),
		'2026-06-17',
		array( 'selected_location_id' => (string) $location_id )
	);
}

function dpd_checkout_build_carrier( DpdCheckoutFakeSoapClient $soap, DpdSettings $settings ): DpdQuoteCarrier {
	$resolver = new DpdCityResolver( new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] ) );
	$locations = new LocationRepository( $GLOBALS['wpdb'] );
	$api = new DpdApiClient( $settings, $soap );
	$service = new DpdTariffCalculationService( $api, $resolver, $locations, $settings, new DpdTariffRequestBuilder(), new DpdTariffOptionNormalizer() );

	return new DpdQuoteCarrier( $settings, $service, new Logger() );
}

function dpd_checkout_orchestrator( CarrierRegistry $registry, DeliveryServiceRepository $services, DeliveryServiceCountryRepository $countries ): CheckoutOrchestrator {
	$rules = new RuleRepository( $GLOBALS['wpdb'] );
	$rule_engine = new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) );
	$logger = new Logger();
	$rp_settings = new RussianPostSettings( new SettingsRepository(), $services );
	$rp_directory = new RussianPostCountryDirectory( new RussianPostApiClient( $rp_settings, $logger ), $logger, null, null, $rp_settings );
	$manager = new DeliveryServiceManager( $services, $countries, $rules, $rp_directory );

	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( $rule_engine ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( new CheckoutLogger( $logger ) ),
		new CheckoutLogger( $logger ),
		null,
		new DeliveryServiceRegistry( $services, $registry ),
		$manager,
		null
	);
}

$GLOBALS['wdc_dpd_checkout_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 100, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Новосибирская область', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск' ),
	array( 'id' => 200, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Москва', 'place_name' => 'Москва', 'place_type' => 'г', 'display_name' => 'Москва' ),
	array( 'id' => 300, 'country_code' => 'RU', 'active' => 1, 'region_name' => 'Томская область', 'place_name' => 'Томск', 'place_type' => 'г', 'display_name' => 'Томск' ),
);
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 100, 'dpd_city_id' => '49455627', 'updated_at' => current_time( 'mysql' ) ),
	array( 'location_id' => 200, 'dpd_city_id' => '49694102', 'updated_at' => current_time( 'mysql' ) ),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$soap = new DpdCheckoutFakeSoapClient();
$carrier = dpd_checkout_build_carrier( $soap, $settings );
$no_credentials = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array() === $no_credentials->rates && 0 === count( $soap->calls ), 'DPD checkout carrier must return no rates and no SOAP call without credentials.' );

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
		DpdSettings::TARIFF_DEFAULT_WEIGHT_G_KEY => 1000,
		DpdSettings::TARIFF_DEFAULT_LENGTH_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_WIDTH_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_HEIGHT_CM_KEY => 20,
		DpdSettings::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY => 1000,
		DpdSettings::RUNTIME_ALLOWED_SERVICE_CODES_KEY => '',
		DpdSettings::RUNTIME_METHOD_TITLE_PREFIX_KEY => 'DPD',
		DpdSettings::RUNTIME_PICKUP_MODE_KEY => 'door',
	)
);
$carrier = dpd_checkout_build_carrier( $soap, $settings );
$missing_city = $carrier->quote( dpd_checkout_request( 300 ) );
dpd_checkout_assert( array() === $missing_city->rates, 'DPD checkout carrier must return no rates without receiver dpd_city_id.' );

$quote = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( 2 === count( $quote->rates ), 'DPD checkout carrier must map MAX and NDY and skip missing-cost options.' );
dpd_checkout_assert( 'MAX' === $quote->rates[0]->tariff_key && 'NDY' === $quote->rates[1]->tariff_key, 'DPD checkout rates must preserve returned service codes.' );
dpd_checkout_assert( 'DPD Максимум' === $quote->rates[0]->title && 'DPD Экспресс' === $quote->rates[1]->title, 'DPD checkout method titles must use configured prefix and serviceName.' );
dpd_checkout_assert( DeliveryType::COURIER === $quote->rates[0]->delivery_type && $quote->rates[0]->requires_courier_address && ! $quote->rates[0]->requires_pickup_point, 'DPD checkout runtime must be courier delivery-to-door only.' );
dpd_checkout_assert( isset( $soap->calls[0]['soap_payload']['request']['auth'] ), 'DPD checkout runtime must call calculator2 with request.auth SOAP wrapper.' );
dpd_checkout_assert( 1.5 === $soap->calls[0]['payload']['parcel'][0]['weight'] && 2500.0 === $soap->calls[0]['payload']['declaredValue'], 'DPD checkout payload must use cart weight and declared value.' );

$settings->save_tariff_settings_from_admin(
	array(
		DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY => 100,
		DpdSettings::RUNTIME_ALLOWED_SERVICE_CODES_KEY => 'MAX',
		DpdSettings::RUNTIME_METHOD_TITLE_PREFIX_KEY => 'DPD',
		DpdSettings::RUNTIME_PICKUP_MODE_KEY => 'door',
	)
);
$filtered = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( 1 === count( $filtered->rates ) && 'MAX' === $filtered->rates[0]->tariff_key, 'DPD allowed_service_codes must filter NDY when only MAX is allowed.' );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$dpd_service = $services->ensure_dpd_service();
$countries->replace_countries( (int) $dpd_service->id, array( 'RU' ) );
$registry = new CarrierRegistry();
$registry->register( $carrier );
dpd_checkout_assert( $registry->has( DpdSettings::CARRIER_KEY ), 'DPD must be registered in checkout CarrierRegistry at this stage.' );
$orchestrator = dpd_checkout_orchestrator( $registry, $services, $countries );
$calls_before_disabled = count( $soap->calls );
$disabled_result = $orchestrator->calculate( dpd_checkout_request(), array(), RateSorter::CHEAPEST, false );
dpd_checkout_assert( $calls_before_disabled === count( $soap->calls ), 'Disabled DPD delivery service must not call DPD checkout carrier.' );
dpd_checkout_assert( array() === array_values( array_filter( $disabled_result->rates, static fn( $rate ): bool => DpdSettings::CARRIER_KEY === $rate->carrier_key ) ), 'Disabled DPD delivery service must not expose DPD rates.' );

$services->update_service( (int) $dpd_service->id, array( 'enabled' => 1, 'minimum_price_rub' => 150, 'round_up_to_ruble' => 1 ) );
$settings->save_tariff_settings_from_admin(
	array(
		DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY => 100,
		DpdSettings::RUNTIME_ALLOWED_SERVICE_CODES_KEY => 'MAX,NDY',
		DpdSettings::RUNTIME_METHOD_TITLE_PREFIX_KEY => 'DPD',
		DpdSettings::RUNTIME_PICKUP_MODE_KEY => 'door',
	)
);
$enabled_result = $orchestrator->calculate( dpd_checkout_request(), array(), RateSorter::CHEAPEST, false );
$dpd_rates = array_values( array_filter( $enabled_result->rates, static fn( $rate ): bool => DpdSettings::CARRIER_KEY === $rate->carrier_key ) );
dpd_checkout_assert( 2 === count( $dpd_rates ), 'Enabled DPD delivery service must expose DPD checkout rates.' );
dpd_checkout_assert( 'DPD Максимум' === $dpd_rates[0]->title && 150.0 === $dpd_rates[0]->price->get_rubles(), 'DPD checkout rates must preserve method title and use common minimum price post-processing.' );
dpd_checkout_assert( ! ( new CarrierShipmentAdapterRegistry() )->has( DpdSettings::CARRIER_KEY ), 'DPD must not be registered in CarrierShipmentAdapterRegistry.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
dpd_checkout_assert( str_contains( $plugin_source, 'DpdQuoteCarrier' ) && ! str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'Plugin must register DPD checkout carrier but not DPD shipment adapter.' );

echo "DPD checkout runtime smoke test passed.\n";
