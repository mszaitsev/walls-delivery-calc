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
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdParcelBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffRequestBuilder;
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
use WallsShop\WDC\Domain\Package\PackageItem;
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
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();

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
				array( 'serviceCode' => 'NDY', 'serviceName' => 'DPD Экспресс', 'cost' => 220.10, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 1 ),
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

/**
 * @param array<int,array<string,mixed>> $services
 */
function dpd_checkout_fake_services( DpdCheckoutFakeSoapClient $soap, array $services ): void {
	$soap->next_body = array( 'return' => $services );
}

/**
 * @return array<int,string>
 */
function dpd_checkout_tariff_keys( array $rates ): array {
	return array_values( array_map( static fn( $rate ): string => $rate->tariff_key, $rates ) );
}

function dpd_checkout_request( int $location_id = 200, int $weight_g = 1500, string $delivery_type = '' ): QuoteRequest {
	$customer_context = array( 'selected_location_id' => (string) $location_id );
	if ( '' !== $delivery_type ) {
		$customer_context['delivery_type'] = $delivery_type;
	}

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', postcode: '101000', street: 'Тверская', house: '1' ),
		new Package( array(), Money::from_rubles( 2500 ), Money::from_rubles( 2500 ), $weight_g, 0, $weight_g, 30, 20, 10, null, 'cart' ),
		'',
		Money::from_rubles( 2500 ),
		'2026-06-17',
		$customer_context
	);
}

function dpd_checkout_request_with_package( Package $package, string $delivery_type = '' ): QuoteRequest {
	$customer_context = array( 'selected_location_id' => '200' );
	if ( '' !== $delivery_type ) {
		$customer_context['delivery_type'] = $delivery_type;
	}

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', postcode: '101000', street: 'Тверская', house: '1' ),
		$package,
		'',
		$package->cart_total,
		'2026-06-17',
		$customer_context
	);
}

function dpd_checkout_regular_items_package( int $quantity ): Package {
	return new Package(
		array( new PackageItem( 'sku-regular', 'Обычный товар', $quantity, Money::from_rubles( 100 ), Money::from_rubles( 100 * $quantity ), 1000, 36, 11, 11 ) ),
		Money::from_rubles( 100 * $quantity ),
		Money::from_rubles( 100 * $quantity ),
		1000 * $quantity,
		0,
		1000 * $quantity,
		null,
		null,
		null,
		null,
		'cart'
	);
}

function dpd_checkout_build_carrier( DpdCheckoutFakeSoapClient $soap, DpdSettings $settings ): DpdQuoteCarrier {
	$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
	$resolver = new DpdCityResolver( $delivery_codes );
	$locations = new LocationRepository( $GLOBALS['wpdb'] );
	$api = new DpdApiClient( $settings, $soap );
	$pickup_service = new DpdPickupPointService( new DpdPickupPointRepository( $GLOBALS['wpdb'] ), $delivery_codes );
	$service = new DpdTariffCalculationService( $api, $resolver, $locations, $settings, new DpdTariffRequestBuilder(), new DpdTariffOptionNormalizer(), $pickup_service, new DpdTerminalCodeTariffRequestBuilder() );

	return new DpdQuoteCarrier( $settings, $service, new DpdParcelBuilder( $settings ), new Logger() );
}

function dpd_checkout_orchestrator( CarrierRegistry $registry, DeliveryServiceRepository $services, DeliveryServiceCountryRepository $countries, DpdSettings $settings ): CheckoutOrchestrator {
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
		null,
		$settings
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
$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'NSK-SENDER', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'name' => 'DPD Новосибирск', 'address' => 'ул Ленина, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 2, 'terminal_code' => 'MSK-AUTO', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD Москва auto', 'address' => 'ул Тверская, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 3, 'terminal_code' => 'MSK-SELECTED', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD Москва selected', 'address' => 'ул Арбат, 1', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 4, 'terminal_code' => 'MSK-SELECTED', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49694102, 'city_name' => 'Москва', 'name' => 'DPD duplicate terminal', 'address' => 'ул Арбат, 1', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$default_settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
dpd_checkout_assert( array( 'ECN', 'CSM', 'MXO' ) === $default_settings->runtime_enabled_service_codes(), 'DPD default enabled service codes must stay ECN,CSM,MXO.' );
dpd_checkout_assert( ! $default_settings->runtime_courier_rates_enabled(), 'DPD courier runtime rates must be disabled by default.' );
$settings_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/DpdSettings.php' );
$carrier_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/DpdQuoteCarrier.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
foreach ( array( 'runtime_pickup_mode', 'runtime_delivery_mode', 'runtime_method_title_prefix', 'RUNTIME_PICKUP_MODE_KEY', 'RUNTIME_DELIVERY_MODE_KEY', 'RUNTIME_METHOD_TITLE_PREFIX_KEY', 'Забор для checkout', 'Доставка для checkout', 'Префикс метода checkout' ) as $forbidden_runtime_setting ) {
	dpd_checkout_assert( ! str_contains( $settings_source . $carrier_source . $admin_source, $forbidden_runtime_setting ), 'DPD runtime/UI must not use legacy setting: ' . $forbidden_runtime_setting );
}
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
	)
);
$settings->save_runtime_titles_from_admin(
	array(
		DpdSettings::RUNTIME_PICKUP_TITLE_KEY => DpdSettings::DEFAULT_PICKUP_METHOD_TITLE,
		DpdSettings::RUNTIME_COURIER_TITLE_KEY => DpdSettings::DEFAULT_COURIER_METHOD_TITLE,
	)
);
$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1', 'NDY' => '1' ),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
	)
);
$carrier = dpd_checkout_build_carrier( $soap, $settings );
$missing_city = $carrier->quote( dpd_checkout_request( 300 ) );
dpd_checkout_assert( array() === $missing_city->rates, 'DPD checkout carrier must return no rates without receiver dpd_city_id.' );

$quote = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( 2 === count( $quote->rates ), 'DPD checkout carrier must map MAX and NDY and skip missing-cost options.' );
dpd_checkout_assert( 'MAX' === $quote->rates[0]->tariff_key && 'NDY' === $quote->rates[1]->tariff_key, 'DPD checkout rates must preserve returned service codes.' );
dpd_checkout_assert( ! empty( $quote->rates[0]->meta['tariff_selector_group'] ) && ( $quote->rates[0]->meta['checkout_group_id'] ?? '' ) === ( $quote->rates[1]->meta['checkout_group_id'] ?? '' ), 'DPD checkout tariff options must be marked for grouped tariff selector output.' );
dpd_checkout_assert( 'DPD до пункта выдачи, DPD Максимум - 1-2 дня' === $quote->rates[0]->title && 'DPD до пункта выдачи, DPD Экспресс - 1 день' === $quote->rates[1]->title, 'DPD pickup titles must use method title, tariff title and delivery days.' );
dpd_checkout_assert( DeliveryType::PICKUP === $quote->rates[0]->delivery_type && ! $quote->rates[0]->requires_courier_address && $quote->rates[0]->requires_pickup_point && ! empty( $quote->rates[0]->meta['dpd_pickup_point_selection_enabled'] ), 'DPD pickup runtime must require checkout pickup point selection.' );
dpd_checkout_assert( isset( $soap->calls[0]['soap_payload']['request']['auth'] ), 'DPD checkout runtime must call calculator2 with request.auth SOAP wrapper.' );
dpd_checkout_assert( 'getServiceCostByParcels3' === $soap->calls[0]['method'], 'DPD checkout runtime must use getServiceCostByParcels3.' );
dpd_checkout_assert( 'NSK-SENDER' === (string) ( $soap->calls[0]['payload']['pickup']['terminalCode'] ?? '' ) && 'MSK-AUTO' === (string) ( $soap->calls[0]['payload']['delivery']['terminalCode'] ?? '' ), 'DPD pickup payload must include sender and auto-selected delivery terminalCode.' );
dpd_checkout_assert( 1.5 === $soap->calls[0]['payload']['parcel'][0]['weight'] && 2500.0 === $soap->calls[0]['payload']['declaredValue'], 'DPD checkout payload must use cart weight and declared value.' );
dpd_checkout_assert( true === ( $soap->calls[0]['payload']['selfPickup'] ?? null ) && true === ( $soap->calls[0]['payload']['selfDelivery'] ?? null ), 'DPD pickup payload must always use selfPickup=true and selfDelivery=true.' );
dpd_checkout_assert( 1 === count( $soap->calls[0]['payload']['parcel'] ?? array() ) && 1 === (int) ( $soap->calls[0]['payload']['parcel'][0]['quantity'] ?? 0 ), 'DPD checkout payload must send one packaging parcel, not cart items.' );
$base_quote_id = $quote->quote_id;
$selected_terminal_quote = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Москва', postcode: '101000' ), new Package( array(), Money::from_rubles( 2500 ), Money::from_rubles( 2500 ), 1500, 0, 1500, 30, 20, 10, null, 'cart' ), '', Money::from_rubles( 2500 ), '2026-06-17', array( 'selected_location_id' => '200', 'dpd_selected_terminal_code' => 'MSK-SELECTED' ) ) );
dpd_checkout_assert( 'MSK-SELECTED' === (string) ( $soap->calls[ count( $soap->calls ) - 1 ]['payload']['delivery']['terminalCode'] ?? '' ), 'DPD pickup payload must use buyer-selected delivery terminalCode after selection.' );
dpd_checkout_assert( $base_quote_id !== $selected_terminal_quote->quote_id, 'DPD quote_id must change when selected delivery terminalCode changes.' );
dpd_checkout_assert( $base_quote_id !== $carrier->quote( dpd_checkout_request( 200, 2200 ) )->quote_id, 'DPD quote_id must change when weight changes.' );
dpd_checkout_assert( $base_quote_id !== $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Москва', postcode: '101000' ), new Package( array(), Money::from_rubles( 2600 ), Money::from_rubles( 2600 ), 1500, 0, 1500, 40, 20, 10, null, 'cart' ), '', Money::from_rubles( 2600 ), '2026-06-17', array( 'selected_location_id' => '200' ) ) )->quote_id, 'DPD quote_id must change when dimensions or declared value change.' );
$calls_before_courier_disabled = count( $soap->calls );
$courier_disabled = $carrier->quote( dpd_checkout_request( 200, 1500, DeliveryType::COURIER ) );
dpd_checkout_assert( array() === $courier_disabled->rates && $calls_before_courier_disabled === count( $soap->calls ), 'DPD courier quote must be empty without API call when courier rates are disabled.' );
dpd_checkout_assert( 'courier_rates_disabled' === $courier_disabled->error_code && 'courier_rates_disabled' === ( $courier_disabled->raw_reference['fallback_reason'] ?? '' ), 'DPD courier disabled quote must expose courier_rates_disabled reason.' );
dpd_checkout_assert( $base_quote_id !== $courier_disabled->quote_id, 'DPD quote_id must change when delivery_type changes.' );

$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1' ),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
	)
);
$filtered = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( 1 === count( $filtered->rates ) && 'MAX' === $filtered->rates[0]->tariff_key, 'DPD enabled service codes must filter NDY when only MAX is checked.' );
dpd_checkout_assert( $base_quote_id !== $filtered->quote_id, 'DPD quote_id must change when enabled service codes change.' );

$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array(),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
	)
);
$none_enabled = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array() === $none_enabled->rates, 'DPD checkout carrier must return no rates when all DPD service code checkboxes are off.' );

$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1', 'NDY' => '1' ),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
		DpdSettings::RUNTIME_ENABLE_COURIER_RATES_KEY => '1',
	)
);
$courier = $carrier->quote( dpd_checkout_request( 200, 1500, DeliveryType::COURIER ) );
$courier_call = $soap->calls[ count( $soap->calls ) - 1 ] ?? array();
dpd_checkout_assert( 2 === count( $courier->rates ), 'DPD courier quote must expose enabled tariffs when courier rates are enabled.' );
dpd_checkout_assert( true === ( $courier_call['payload']['selfPickup'] ?? null ) && false === ( $courier_call['payload']['selfDelivery'] ?? null ), 'DPD courier payload must use selfPickup=true and selfDelivery=false.' );
dpd_checkout_assert( 'NSK-SENDER' === (string) ( $courier_call['payload']['pickup']['terminalCode'] ?? '' ) && ! isset( $courier_call['payload']['delivery']['terminalCode'] ), 'DPD courier payload must include pickup terminalCode and omit delivery terminalCode.' );
dpd_checkout_assert( DeliveryType::COURIER === $courier->rates[0]->delivery_type && $courier->rates[0]->requires_courier_address && ! $courier->rates[0]->requires_pickup_point, 'DPD courier delivery must require courier address without pickup point selection.' );
dpd_checkout_assert( 'DPD курьером, DPD Максимум - 1-2 дня' === $courier->rates[0]->title, 'DPD courier title must use courier method title, tariff title and days.' );
dpd_checkout_assert( ( $quote->rates[0]->meta['checkout_group_id'] ?? '' ) !== ( $courier->rates[0]->meta['checkout_group_id'] ?? '' ), 'Same DPD serviceCode must stay in separate pickup/courier groups.' );
dpd_checkout_assert( $base_quote_id !== $courier->quote_id, 'DPD quote_id must change when courier rates are enabled and courier delivery type is requested.' );

$package_box = new Package( array(), Money::from_rubles( 5000 ), Money::from_rubles( 5000 ), 4500, 0, 4500, 38, 24, 21, null, 'cart' );
$box_quote = $carrier->quote( dpd_checkout_request_with_package( $package_box ) );
$box_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 4.5 === (float) ( $box_payload['parcel'][0]['weight'] ?? 0 ) && 38.0 === (float) ( $box_payload['parcel'][0]['length'] ?? 0 ) && 24.0 === (float) ( $box_payload['parcel'][0]['width'] ?? 0 ) && 21.0 === (float) ( $box_payload['parcel'][0]['height'] ?? 0 ) && 1 === (int) ( $box_payload['parcel'][0]['quantity'] ?? 0 ), 'DPD parcel builder must use package-level dimensions as one 4.5kg 38x24x21 box.' );
dpd_checkout_assert( 'package_dimensions' === (string) ( $box_quote->raw_reference['package_builder_source'] ?? '' ), 'DPD quote diagnostics must expose package builder source.' );

$items_package = new Package(
	array(
		new PackageItem( 'sku-a', 'Товар A', 2, Money::from_rubles( 1000 ), Money::from_rubles( 2000 ), 1000, 36, 12, 12 ),
		new PackageItem( 'sku-b', 'Товар B', 1, Money::from_rubles( 500 ), Money::from_rubles( 500 ), 500, 10, 10, 10 ),
	),
	Money::from_rubles( 2500 ),
	Money::from_rubles( 2500 ),
	2500,
	0,
	2500,
	null,
	null,
	null,
	null,
	'cart'
);
$items_quote = $carrier->quote( dpd_checkout_request_with_package( $items_package ) );
$items_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 1 === count( $items_payload['parcel'] ?? array() ) && in_array( (string) ( $items_quote->raw_reference['package_builder_source'] ?? '' ), array( 'one_box_3d', 'two_boxes_3d', 'items_stacked_rows' ), true ), 'DPD parcel builder must collapse regular cart items into calculated packaging parcels.' );

$fallback_package = new Package( array(), Money::from_rubles( 0 ), Money::from_rubles( 0 ), 0, 0, 0, null, null, null, null, 'cart' );
$fallback_quote = $carrier->quote( dpd_checkout_request_with_package( $fallback_package ) );
$fallback_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 1.0 === (float) ( $fallback_payload['parcel'][0]['weight'] ?? 0 ) && 20.0 === (float) ( $fallback_payload['parcel'][0]['length'] ?? 0 ) && 20.0 === (float) ( $fallback_payload['parcel'][0]['width'] ?? 0 ) && 20.0 === (float) ( $fallback_payload['parcel'][0]['height'] ?? 0 ) && 'defaults' === (string) ( $fallback_quote->raw_reference['package_builder_source'] ?? '' ), 'DPD parcel builder must fallback to DPD default parcel dimensions and weight.' );

$regular_4_quote = $carrier->quote( dpd_checkout_request_with_package( dpd_checkout_regular_items_package( 4 ) ) );
$regular_4_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 1 === count( $regular_4_payload['parcel'] ?? array() ) && ! ( 36.0 === (float) ( $regular_4_payload['parcel'][0]['length'] ?? 0 ) && 11.0 === (float) ( $regular_4_payload['parcel'][0]['width'] ?? 0 ) && 11.0 === (float) ( $regular_4_payload['parcel'][0]['height'] ?? 0 ) ), 'DPD 4 regular items must build one package parcel, not one item dimensions.' );
dpd_checkout_assert( 'one_box_3d' === (string) ( $regular_4_quote->raw_reference['package_builder_source'] ?? '' ), 'DPD 4 regular items should use one-box 3D packing when it fits.' );

$regular_5_quote = $carrier->quote( dpd_checkout_request_with_package( dpd_checkout_regular_items_package( 5 ) ) );
$regular_5_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 1 === count( $regular_5_payload['parcel'] ?? array() ) && 'one_box_3d' === (string) ( $regular_5_quote->raw_reference['package_builder_source'] ?? '' ), 'DPD 5 regular items should fit one 3D box.' );

$regular_9_quote = $carrier->quote( dpd_checkout_request_with_package( dpd_checkout_regular_items_package( 9 ) ) );
$regular_9_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( (int) ( $regular_9_quote->raw_reference['parcels_count'] ?? 0 ) <= 2 && in_array( (string) ( $regular_9_quote->raw_reference['package_builder_source'] ?? '' ), array( 'one_box_3d', 'two_boxes_3d', 'items_stacked_rows' ), true ), 'DPD 9 regular items must finish as packed parcels or fallback without fatal.' );

$long_package = new Package(
	array( new PackageItem( 'sku-long', 'Длинномер', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 300, 70, 5, 5 ) ),
	Money::from_rubles( 100 ),
	Money::from_rubles( 100 ),
	300,
	0,
	300,
	null,
	null,
	null,
	null,
	'cart'
);
$long_quote = $carrier->quote( dpd_checkout_request_with_package( $long_package ) );
$long_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 1 === count( $long_payload['parcel'] ?? array() ) && 70.0 === (float) ( $long_payload['parcel'][0]['length'] ?? 0 ) && 5.0 === (float) ( $long_payload['parcel'][0]['width'] ?? 0 ) && 5.0 === (float) ( $long_payload['parcel'][0]['height'] ?? 0 ) && 0.3 === (float) ( $long_payload['parcel'][0]['weight'] ?? 0 ) && 'long_items_only' === (string) ( $long_quote->raw_reference['package_builder_source'] ?? '' ), 'DPD long item over 49 cm must become its own parcel.' );

$mixed_package = new Package(
	array(
		new PackageItem( 'sku-long', 'Длинномер', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 300, 70, 5, 5 ),
		new PackageItem( 'sku-regular', 'Обычный товар', 2, Money::from_rubles( 100 ), Money::from_rubles( 200 ), 1000, 36, 11, 11 ),
	),
	Money::from_rubles( 300 ),
	Money::from_rubles( 300 ),
	2300,
	0,
	2300,
	null,
	null,
	null,
	null,
	'cart'
);
$mixed_quote = $carrier->quote( dpd_checkout_request_with_package( $mixed_package ) );
$mixed_payload = $soap->calls[ count( $soap->calls ) - 1 ]['payload'] ?? array();
dpd_checkout_assert( 2 === count( $mixed_payload['parcel'] ?? array() ) && 70.0 === (float) ( $mixed_payload['parcel'][0]['length'] ?? 0 ) && 36.0 === (float) ( $mixed_payload['parcel'][1]['length'] ?? 0 ) && 22.0 === (float) ( $mixed_payload['parcel'][1]['width'] ?? 0 ) && 11.0 === (float) ( $mixed_payload['parcel'][1]['height'] ?? 0 ), 'DPD mixed basket must produce one long-item parcel plus one regular-items parcel.' );
dpd_checkout_assert( 2 === (int) ( $mixed_quote->raw_reference['parcels_count'] ?? 0 ) && 1 === (int) ( $mixed_quote->raw_reference['long_item_parcels_count'] ?? 0 ) && 2 === (int) ( $mixed_quote->raw_reference['regular_items_count'] ?? 0 ) && 'mixed_long_items_one_box_3d' === (string) ( $mixed_quote->raw_reference['package_builder_source'] ?? '' ), 'DPD mixed basket diagnostics must expose parcel counts and source.' );
dpd_checkout_assert( 2.0 === (float) ( $mixed_payload['parcel'][1]['weight'] ?? 0 ), 'DPD long item must not participate in the regular-items parcel weight.' );

$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'ECN' => '1', 'CSM' => '1' ),
		'dpd_runtime_tariff_title' => array( 'ECN' => 'DPD Эконом', 'CSM' => 'DPD Онлайн-экспресс' ),
		DpdSettings::RUNTIME_ENABLE_COURIER_RATES_KEY => '1',
	)
);
dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 500, 'deliveryPeriodMin' => 3, 'deliveryPeriodMax' => 5 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'DPD Онлайн-экспресс', 'cost' => 700, 'deliveryPeriodMin' => 3, 'deliveryPeriodMax' => 5 ),
	)
);
$same_duration = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array( 'ECN' ) === dpd_checkout_tariff_keys( $same_duration->rates ), 'DPD filter must keep the cheapest tariff for identical delivery days.' );
dpd_checkout_assert( 1 === (int) ( $same_duration->raw_reference['dpd_filter_removed_count'] ?? 0 ) && 'CSM' === (string) ( $same_duration->raw_reference['dpd_filter_removed_tariffs'][0]['tariff_key'] ?? '' ), 'DPD filter diagnostics must list removed same-duration tariff.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 500, 'deliveryPeriodMin' => 2, 'deliveryPeriodMax' => 3 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'Экспресс', 'cost' => 700, 'deliveryPeriodMin' => 4, 'deliveryPeriodMax' => 5 ),
	)
);
$faster_cheaper = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array( 'ECN' ) === dpd_checkout_tariff_keys( $faster_cheaper->rates ), 'DPD filter must remove a slower and more expensive tariff regardless of tariff name.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 900, 'deliveryPeriodMin' => 2, 'deliveryPeriodMax' => 3 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'DPD Онлайн-экспресс', 'cost' => 700, 'deliveryPeriodMin' => 4, 'deliveryPeriodMax' => 5 ),
	)
);
$faster_expensive = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array( 'ECN', 'CSM' ) === dpd_checkout_tariff_keys( $faster_expensive->rates ), 'DPD filter must keep faster but more expensive alternatives.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 900, 'deliveryPeriodMin' => 2, 'deliveryPeriodMax' => 3 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'DPD Онлайн-экспресс', 'cost' => 500, 'deliveryPeriodMin' => 4, 'deliveryPeriodMax' => 5 ),
	)
);
$slower_cheaper = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array( 'ECN', 'CSM' ) === dpd_checkout_tariff_keys( $slower_cheaper->rates ), 'DPD filter must keep slower but cheaper alternatives.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 500, 'deliveryPeriodMin' => 3, 'deliveryPeriodMax' => 5 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'DPD Онлайн-экспресс', 'cost' => 500, 'deliveryPeriodMin' => 4, 'deliveryPeriodMax' => 5 ),
	)
);
$same_max_wider_min = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array( 'ECN' ) === dpd_checkout_tariff_keys( $same_max_wider_min->rates ), 'DPD filter must remove a tariff with same max days, worse min days and equal price.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 500 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'DPD Онлайн-экспресс', 'cost' => 700, 'deliveryPeriodMin' => 3, 'deliveryPeriodMax' => 5 ),
	)
);
$unknown_days = $carrier->quote( dpd_checkout_request() );
dpd_checkout_assert( array( 'ECN', 'CSM' ) === dpd_checkout_tariff_keys( $unknown_days->rates ), 'DPD filter must keep unknown-duration tariffs.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'ECN', 'serviceName' => 'DPD Эконом', 'cost' => 500, 'deliveryPeriodMin' => 2, 'deliveryPeriodMax' => 3 ),
		array( 'serviceCode' => 'CSM', 'serviceName' => 'DPD Онлайн-экспресс', 'cost' => 700, 'deliveryPeriodMin' => 4, 'deliveryPeriodMax' => 5 ),
	)
);
$filtered_pickup = $carrier->quote( dpd_checkout_request( 200, 1500, DeliveryType::PICKUP ) );
$filtered_courier = $carrier->quote( dpd_checkout_request( 200, 1500, DeliveryType::COURIER ) );
dpd_checkout_assert( array( 'ECN' ) === dpd_checkout_tariff_keys( $filtered_pickup->rates ) && array( 'ECN' ) === dpd_checkout_tariff_keys( $filtered_courier->rates ), 'DPD filtering must be applied independently inside pickup and courier groups.' );
dpd_checkout_assert( ( $filtered_pickup->rates[0]->meta['checkout_group_id'] ?? '' ) !== ( $filtered_courier->rates[0]->meta['checkout_group_id'] ?? '' ), 'DPD filtered pickup and courier groups must stay separate.' );

dpd_checkout_fake_services(
	$soap,
	array(
		array( 'serviceCode' => 'MAX', 'serviceName' => 'DPD Максимум', 'cost' => 100.25, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 2 ),
		array( 'serviceCode' => 'NDY', 'serviceName' => 'DPD Экспресс', 'cost' => 220.10, 'deliveryPeriodMin' => 1, 'deliveryPeriodMax' => 1 ),
		array( 'serviceCode' => 'BAD', 'serviceName' => 'No cost' ),
	)
);

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$dpd_service = $services->ensure_dpd_service();
$countries->replace_countries( (int) $dpd_service->id, array( 'RU' ) );
$registry = new CarrierRegistry();
$registry->register( $carrier );
dpd_checkout_assert( $registry->has( DpdSettings::CARRIER_KEY ), 'DPD must be registered in checkout CarrierRegistry at this stage.' );
$orchestrator = dpd_checkout_orchestrator( $registry, $services, $countries, $settings );
$calls_before_disabled = count( $soap->calls );
$disabled_result = $orchestrator->calculate( dpd_checkout_request(), array(), RateSorter::CHEAPEST, false );
dpd_checkout_assert( $calls_before_disabled === count( $soap->calls ), 'Disabled DPD delivery service must not call DPD checkout carrier.' );
dpd_checkout_assert( array() === array_values( array_filter( $disabled_result->rates, static fn( $rate ): bool => DpdSettings::CARRIER_KEY === $rate->carrier_key ) ), 'Disabled DPD delivery service must not expose DPD rates.' );

$services->update_service( (int) $dpd_service->id, array( 'enabled' => 1, 'minimum_price_rub' => 150, 'round_up_to_ruble' => 1 ) );
$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1', 'NDY' => '1' ),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
	)
);
$enabled_result = $orchestrator->calculate( dpd_checkout_request(), array(), RateSorter::CHEAPEST, false );
$dpd_rates = array_values( array_filter( $enabled_result->rates, static fn( $rate ): bool => DpdSettings::CARRIER_KEY === $rate->carrier_key ) );
dpd_checkout_assert( 2 === count( $dpd_rates ) && array( DeliveryType::PICKUP ) === array_values( array_unique( array_map( static fn( $rate ): string => $rate->delivery_type, $dpd_rates ) ) ), 'Enabled DPD delivery service must expose only pickup rates when courier rates are disabled.' );
dpd_checkout_assert( ! empty( $dpd_rates[0]->meta['tariff_selector_group'] ) && 'DPD Максимум' === (string) ( $dpd_rates[0]->meta['selected_tariff_title'] ?? '' ) && 150.0 === $dpd_rates[0]->price->get_rubles(), 'DPD checkout rates must use grouped tariff meta and common minimum price post-processing before WooCommerce selector grouping.' );

$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'MAX' => '1', 'NDY' => '1' ),
		'dpd_runtime_tariff_title' => array( 'MAX' => 'DPD Максимум', 'NDY' => 'DPD Экспресс' ),
		DpdSettings::RUNTIME_ENABLE_COURIER_RATES_KEY => '1',
	)
);
$enabled_with_courier = $orchestrator->calculate( dpd_checkout_request(), array(), RateSorter::CHEAPEST, false );
$dpd_split_rates = array_values( array_filter( $enabled_with_courier->rates, static fn( $rate ): bool => DpdSettings::CARRIER_KEY === $rate->carrier_key ) );
$split_delivery_types = array_values( array_unique( array_map( static fn( $rate ): string => $rate->delivery_type, $dpd_split_rates ) ) );
sort( $split_delivery_types );
dpd_checkout_assert( 4 === count( $dpd_split_rates ) && array( DeliveryType::COURIER, DeliveryType::PICKUP ) === $split_delivery_types, 'Enabled DPD courier rates must add separate pickup and courier checkout entries.' );
$max_rates = array_values( array_filter( $dpd_split_rates, static fn( $rate ): bool => 'MAX' === $rate->tariff_key ) );
dpd_checkout_assert( 2 === count( $max_rates ) && ( $max_rates[0]->meta['checkout_group_id'] ?? '' ) !== ( $max_rates[1]->meta['checkout_group_id'] ?? '' ), 'Same DPD serviceCode must be preserved separately for pickup and courier orchestrator entries.' );
dpd_checkout_assert( ! ( new CarrierShipmentAdapterRegistry() )->has( DpdSettings::CARRIER_KEY ), 'Empty shipment registry must not contain DPD before explicit adapter registration.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
dpd_checkout_assert( str_contains( $plugin_source, 'DpdQuoteCarrier' ) && str_contains( $plugin_source, 'DpdShipmentAdapter' ) && str_contains( $plugin_source, 'array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ) ), $this->container->get( Logger::class )' ), 'Plugin must register DPD checkout carrier and manual live-create adapter without adding auto-create.' );

echo "DPD checkout runtime smoke test passed.\n";
