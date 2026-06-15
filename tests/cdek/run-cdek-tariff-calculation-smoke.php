<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;

function cdek_tariff_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-10 12:00:00'; }
function wp_date( string $format ): string { return gmdate( $format ); }
function wp_salt( string $scheme = '' ): string { return 'cdek-tariff-smoke-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_cdek_tariff_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_cdek_tariff_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_cdek_tariff_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_cdek_tariff_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_cdek_tariff_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_cdek_tariff_transients'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public object $session;

			public function __construct() {
				$this->session = new class {
					/** @var array<string,mixed> */
					public array $data = array();

					public function get( string $key, mixed $default = null ): mixed {
						return $this->data[ $key ] ?? $default;
					}

					public function set( string $key, mixed $value ): void {
						$this->data[ $key ] = $value;
					}
				};
			}
		};
	}

	return $wc;
}
function wc_get_logger(): object {
	return new class {
		/**
		 * @param array<string,mixed> $context
		 */
		public function log( string $level, string $message, array $context = array() ): void {
			$GLOBALS['wdc_cdek_tariff_logs'][] = compact( 'level', 'message', 'context' );
		}
	};
}

/**
 * @return array<string,mixed>
 */
function cdek_tariff_find_log( string $message ): array {
	foreach ( array_reverse( $GLOBALS['wdc_cdek_tariff_logs'] ?? array() ) as $log ) {
		if ( is_array( $log ) && $message === (string) ( $log['message'] ?? '' ) ) {
			return $log;
		}
	}

	return array();
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();
		/** @var array<int,array<string,mixed>> */
		public array $countries = array();
		/** @var array<int,array<string,mixed>> */
		public array $settings = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries[] = $data;
				return true;
			}
			if ( str_contains( $table, 'wdc_delivery_service_settings' ) ) {
				$data['id'] = ++$this->insert_id;
				$this->settings[] = $data;
				return true;
			}
			$data['id'] = ++$this->insert_id;
			$this->services[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			if ( str_contains( $table, 'wdc_delivery_service_settings' ) ) {
				foreach ( $this->settings as $index => $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
						$this->settings[ $index ] = array_merge( $row, $data );
					}
				}
				return true;
			}
			foreach ( $this->services as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$this->services[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}

		public function delete( string $table, array $where, array $format = array() ): bool {
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries = array_values( array_filter( $this->countries, static fn( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) !== (int) ( $where['service_id'] ?? 0 ) ) );
			}
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = (\d+)/', $query, $service ) && preg_match( "/setting_key = '([^']+)'/", $query, $key ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) ( $row['service_id'] ?? 0 ) === (int) $service[1] && (string) ( $row['setting_key'] ?? '' ) === $key[1] ) {
						return $row;
					}
				}
			}
			if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( str_contains( $query, 'ORDER BY deleted ASC' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = (\d+)/', $query, $matches ) ) {
				$id = (int) $matches[1];
				return array_values( array_filter( $this->settings, static fn( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) === $id ) );
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return array_values( array_filter( $this->services, static fn( array $row ): bool => empty( $row['deleted'] ) ) );
			}
			return array();
		}

		public function get_var( string $query ): mixed {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = (\d+)/', $query, $service ) && preg_match( "/setting_key = '([^']+)'/", $query, $key ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) ( $row['service_id'] ?? 0 ) === (int) $service[1] && (string) ( $row['setting_key'] ?? '' ) === $key[1] ) {
						return $row['id'] ?? null;
					}
				}
			}

			return null;
		}

		public function get_col( string $query ): array {
			if ( preg_match( '/service_id = (\d+)/', $query, $matches ) ) {
				$id = (int) $matches[1];
				return array_values( array_map( static fn( array $row ): string => (string) $row['country_code'], array_filter( $this->countries, static fn( array $row ): bool => (int) $row['service_id'] === $id ) ) );
			}
			return array();
		}
	}
}

final class WdcCdekTariffSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}
}

final class WdcCdekTariffSmokeShippingItem {
	public string $method_title = '';
	/** @var array<string,mixed> */
	public array $meta = array();

	public function set_method_title( string $method_title ): void {
		$this->method_title = $method_title;
	}

	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}
}

final class CdekTariffFakeHttpClient implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	public bool $tariff_error = false;
	/** @var null|array<int,array<string,mixed>> */
	public ?array $tariff_codes_override = null;
	/** @var array<int,array<int,array<string,mixed>>> */
	public array $tariff_codes_responses = array();
	/** @var array<int,array<int,array<string,mixed>>> */
	public array $location_responses = array();

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( 'access_token' => 'runtime-token', 'expires_in' => 3600 ) ) );
		}
		if ( str_contains( $url, '/v2/location/cities' ) ) {
			if ( array() !== $this->location_responses ) {
				return new CdekApiResponse( 200, (string) json_encode( array_shift( $this->location_responses ) ) );
			}
			return new CdekApiResponse( 200, (string) json_encode( array( array( 'code' => 270, 'city' => 'Москва', 'region' => 'Москва', 'fias_guid' => 'dest-fias' ) ) ) );
		}
		if ( str_contains( $url, '/v2/calculator/tarifflist' ) ) {
			if ( $this->tariff_error ) {
				return new CdekApiResponse( 400, (string) json_encode( array( 'code' => 'INVALID_ROUTE', 'message' => 'bad route', 'Account' => 'account-id', 'access_token' => 'runtime-token' ) ) );
			}
			if ( null !== $this->tariff_codes_override ) {
				return new CdekApiResponse( 200, (string) json_encode( array( 'tariff_codes' => $this->tariff_codes_override ) ) );
			}
			if ( array() !== $this->tariff_codes_responses ) {
				return new CdekApiResponse( 200, (string) json_encode( array( 'tariff_codes' => array_shift( $this->tariff_codes_responses ) ) ) );
			}
			return new CdekApiResponse(
				200,
				(string) json_encode(
					array(
						'tariff_codes' => array(
							array( 'tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 350.5, 'period_min' => 2, 'period_max' => 4, 'calendar_min' => 2, 'calendar_max' => 5 ),
							array( 'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3, 'delivery_sum' => 520, 'period_min' => 1, 'period_max' => 1 ),
							array( 'tariff_code' => 999, 'tariff_name' => 'Неизвестный', 'delivery_mode' => 9, 'delivery_sum' => 1, 'period_min' => 1, 'period_max' => 1 ),
						),
					)
				)
			);
		}
		return new CdekApiResponse( 404, '{}' );
	}
}

function cdek_tariff_settings( CdekTariffFakeHttpClient $http, bool $credentials = true ): array {
	$GLOBALS['wdc_cdek_tariff_options'] = array();
	$GLOBALS['wdc_cdek_tariff_transients'] = array();
	$settings_repository = new SettingsRepository();
	$settings = new CdekSettings( $settings_repository, new EncryptionService() );
	$settings->save_from_admin(
		array(
			CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
			CdekSettings::TEST_ACCOUNT_KEY => $credentials ? 'account-id' : '',
			'cdek_test_secure_password' => $credentials ? 'secure-password' : '',
			CdekSettings::SENDER_CITY_CODE_KEY => '270',
			CdekSettings::SHIPMENT_POINT_KEY => ' nsk69 ',
			CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
			CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
			CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY => '30',
			CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY => '20',
			CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY => '10',
		)
	);
	$tokens = new CdekOAuthTokenService( $settings, $http );
	$client = new CdekApiClient( $tokens, $settings, $http );
	$carrier = new CdekCarrier( $settings, $client, new CdekLocationResolver( $client, new Logger() ), new Logger() );
	return array( $settings, $client, $carrier );
}

function cdek_tariff_request( string $delivery_type = DeliveryType::PICKUP ): QuoteRequest {
	$item = new PackageItem( 'sku', 'Товар', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 700, 12, 8, 4 );
	return cdek_tariff_request_with_items( array( $item ), 300, $delivery_type );
}

/**
 * @param array<int,PackageItem> $items
 */
function cdek_tariff_request_with_items( array $items, int $packaging_weight_g = 0, string $delivery_type = DeliveryType::PICKUP ): QuoteRequest {
	$package = Package::from_items( $items, $packaging_weight_g, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
	return cdek_tariff_request_with_package( $package, $delivery_type );
}

function cdek_tariff_request_with_package( Package $package, string $delivery_type = DeliveryType::PICKUP ): QuoteRequest {
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', region_name: 'Москва', city: 'Москва', postcode: '101000', street: 'Тверская', house: '1', raw_address: 'Тверская 1', fias_id: 'dest-fias' ),
		$package,
		'cod',
		Money::from_rubles( 1000 ),
		'2026-06-10',
		array( 'delivery_type' => $delivery_type, 'city_name' => 'Москва', 'selected_location_region' => 'Москва', 'selected_location_fias_id' => 'dest-fias' )
	);
}

function cdek_tariff_location_request( string $city, string $region = '', string $fias = '', array $context = array() ): QuoteRequest {
	$item = new PackageItem( 'sku', 'Товар', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 700, 12, 8, 4 );
	$package = Package::from_items( array( $item ), 300, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', region_name: $region, city: $city, postcode: '101000', street: '', house: '', raw_address: '', fias_id: $fias ),
		$package,
		'cod',
		Money::from_rubles( 1000 ),
		'2026-06-10',
		array_merge( array( 'delivery_type' => DeliveryType::PICKUP, 'city_name' => $city, 'selected_location_region' => $region, 'selected_location_fias_id' => $fias ), $context )
	);
}

/**
 * @return array<int,array<string,string>>
 */
function cdek_tariff_location_queries( CdekTariffFakeHttpClient $http ): array {
	$queries = array();
	foreach ( $http->requests as $request ) {
		if ( ! str_contains( $request['url'], '/v2/location/cities' ) ) {
			continue;
		}
		$query = array();
		parse_str( (string) ( parse_url( $request['url'], PHP_URL_QUERY ) ?: '' ), $query );
		$queries[] = array_map( 'strval', $query );
	}

	return $queries;
}

/**
 * @return array<string,mixed>
 */
function cdek_tariff_last_tarifflist_payload( CdekTariffFakeHttpClient $http ): array {
	$requests = array_values( array_filter( $http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
	$request = end( $requests );
	if ( ! is_array( $request ) ) {
		return array();
	}
	$payload = json_decode( (string) ( $request['args']['body'] ?? '' ), true );

	return is_array( $payload ) ? $payload : array();
}

/**
 * @return array<int,array<string,mixed>>
 */
function cdek_tariff_tarifflist_payloads( CdekTariffFakeHttpClient $http ): array {
	$payloads = array();
	foreach ( $http->requests as $request ) {
		if ( ! str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) {
			continue;
		}
		$payload = json_decode( (string) ( $request['args']['body'] ?? '' ), true );
		if ( is_array( $payload ) ) {
			$payloads[] = $payload;
		}
	}

	return $payloads;
}

/**
 * @param array<int,DeliveryRate> $rates
 * @return array<int,string>
 */
function cdek_tariff_rate_codes( array $rates ): array {
	return array_values( array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $rates ) );
}

function cdek_tariff_orchestrator( CdekCarrier $carrier, ?DeliveryServiceRegistry $service_registry = null, ?DeliveryServiceManager $service_manager = null, ?QuoteCache $quote_cache = null ): CheckoutOrchestrator {
	$registry = new CarrierRegistry();
	$registry->register( $carrier );
	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( new CheckoutLogger( new Logger() ) ),
		new CheckoutLogger( new Logger() ),
		$quote_cache,
		$service_registry,
		$service_manager
	);
}

function cdek_tariff_service_runtime( CdekCarrier $carrier, bool $enabled ): array {
	$GLOBALS['wpdb'] = new wpdb();
	$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
	$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
	$service = $services->ensure_cdek_service();
	$services->update_service( (int) $service->id, array( 'enabled' => $enabled ? 1 : 0 ) );
	$countries->replace_countries( (int) $service->id, array( 'RU' ) );
	$manager = new DeliveryServiceManager( $services, $countries, new RuleRepository( $GLOBALS['wpdb'] ), ( new ReflectionClass( RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor() );
	return array( new DeliveryServiceRegistry( $services, ( function () use ( $carrier ): CarrierRegistry { $registry = new CarrierRegistry(); $registry->register( $carrier ); return $registry; } )() ), $manager );
}

$fallback_http = new CdekTariffFakeHttpClient();
$fallback_http->location_responses = array(
	array(),
	array(),
	array( array( 'code' => 270, 'city' => 'Новосибирск', 'region' => 'Новосибирская область' ) ),
);
[ , $fallback_client ] = cdek_tariff_settings( $fallback_http, true );
$fallback_resolver = new CdekLocationResolver( $fallback_client, new Logger() );
$fallback_result = $fallback_resolver->resolve( cdek_tariff_location_request( 'Новосибирск', 'Новосибирская область', 'missing-fias' ) );
cdek_tariff_assert( true === (bool) ( $fallback_result['success'] ?? false ), 'CDEK resolver must fallback to city only when stricter attempts return empty.' );
cdek_tariff_assert( 270 === (int) ( $fallback_result['city_code'] ?? 0 ), 'CDEK resolver fallback must return city code.' );
cdek_tariff_assert( 'city_only' === (string) ( $fallback_result['selected_attempt_label'] ?? '' ), 'CDEK resolver must report selected fallback attempt.' );
cdek_tariff_assert( 3 === (int) ( $fallback_result['attempts_count'] ?? 0 ), 'CDEK resolver diagnostics must include attempts count.' );
cdek_tariff_assert( array( 'fias_guid_only', 'city_region', 'city_only' ) === array_values( $fallback_result['attempts_labels'] ?? array() ), 'CDEK resolver diagnostics must include attempt labels.' );

$normalized_http = new CdekTariffFakeHttpClient();
$normalized_http->location_responses = array(
	array(),
	array( array( 'code' => 270, 'city' => 'Новосибирск', 'region' => 'Новосибирская область' ) ),
);
[ , $normalized_client ] = cdek_tariff_settings( $normalized_http, true );
$normalized_result = ( new CdekLocationResolver( $normalized_client, new Logger() ) )->resolve( cdek_tariff_location_request( 'г Новосибирск' ) );
$normalized_queries = cdek_tariff_location_queries( $normalized_http );
cdek_tariff_assert( true === (bool) ( $normalized_result['success'] ?? false ), 'CDEK resolver must resolve normalized city names.' );
cdek_tariff_assert( 'Новосибирск' === (string) ( $normalized_queries[1]['city'] ?? '' ), 'CDEK resolver must query normalized city without type prefix.' );

$fias_http = new CdekTariffFakeHttpClient();
$fias_http->location_responses = array(
	array( array( 'code' => 270, 'city' => 'Новосибирск', 'region' => 'Новосибирская область', 'fias_guid' => 'exact-fias' ) ),
);
[ , $fias_client ] = cdek_tariff_settings( $fias_http, true );
$fias_result = ( new CdekLocationResolver( $fias_client, new Logger() ) )->resolve( cdek_tariff_location_request( 'Новосибирск', 'Новосибирская область', 'exact-fias' ) );
cdek_tariff_assert( true === (bool) ( $fias_result['success'] ?? false ) && 1.0 === (float) ( $fias_result['confidence'] ?? 0 ), 'CDEK resolver FIAS exact match must have confidence 1.0.' );

$city_only_http = new CdekTariffFakeHttpClient();
$city_only_http->location_responses = array(
	array( array( 'code' => 270, 'city' => 'Новосибирск', 'region' => 'Новосибирская область' ) ),
);
[ , $city_only_client ] = cdek_tariff_settings( $city_only_http, true );
$city_only_result = ( new CdekLocationResolver( $city_only_client, new Logger() ) )->resolve( cdek_tariff_location_request( 'Новосибирск' ) );
cdek_tariff_assert( true === (bool) ( $city_only_result['success'] ?? false ) && (float) ( $city_only_result['confidence'] ?? 0 ) >= 0.85, 'CDEK resolver city exact without region must be confident enough.' );

$low_confidence_http = new CdekTariffFakeHttpClient();
$low_confidence_http->location_responses = array(
	array( array( 'code' => 270, 'city' => 'Новосибирск', 'region' => 'Томская область' ) ),
);
[ , $low_confidence_client ] = cdek_tariff_settings( $low_confidence_http, true );
$low_confidence_result = ( new CdekLocationResolver( $low_confidence_client, new Logger() ) )->resolve( cdek_tariff_location_request( 'Новосибирск', 'Новосибирская область' ) );
cdek_tariff_assert( false === (bool) ( $low_confidence_result['success'] ?? true ) && 'low_confidence' === (string) ( $low_confidence_result['reason'] ?? '' ), 'CDEK resolver low confidence match must not be successful.' );

$not_found_http = new CdekTariffFakeHttpClient();
$not_found_http->location_responses = array(
	array(),
	array( array( 'code' => 270, 'city' => 'Новосибирск', 'region' => 'Новосибирская область' ) ),
);
[ , $not_found_client ] = cdek_tariff_settings( $not_found_http, true );
$not_found_resolver = new CdekLocationResolver( $not_found_client, new Logger() );
$not_found_first = $not_found_resolver->resolve( cdek_tariff_location_request( 'Новосибирск' ) );
$not_found_second = $not_found_resolver->resolve( cdek_tariff_location_request( 'Новосибирск' ) );
cdek_tariff_assert( false === (bool) ( $not_found_first['success'] ?? true ) && true === (bool) ( $not_found_second['success'] ?? false ), 'CDEK resolver must not cache not_found as permanent failure.' );

$http = new CdekTariffFakeHttpClient();
[ $settings, $client, $carrier ] = cdek_tariff_settings( $http, true );

$settings->save_tariff_calculation_from_admin(
	array(
		CdekSettings::SENDER_CITY_CODE_KEY => '270',
		CdekSettings::SHIPMENT_POINT_KEY => 'NSK69',
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::INSURANCE_PERCENT_KEY => '0,75',
	)
);
cdek_tariff_assert( 0.75 === $settings->insurance_percent(), 'CDEK insurance percent must accept comma decimal input.' );
$settings->save_tariff_calculation_from_admin( array( CdekSettings::INSURANCE_PERCENT_KEY => '0.75' ) );
cdek_tariff_assert( 0.75 === $settings->insurance_percent(), 'CDEK insurance percent must accept dot decimal input.' );
$settings->save_tariff_calculation_from_admin( array( CdekSettings::INSURANCE_PERCENT_KEY => '3' ) );
cdek_tariff_assert( 3.0 === $settings->insurance_percent(), 'CDEK insurance percent must accept whole percent input.' );
$settings->save_tariff_calculation_from_admin( array( CdekSettings::INSURANCE_PERCENT_KEY => '-2' ) );
cdek_tariff_assert( 0.0 === $settings->insurance_percent(), 'CDEK insurance percent must clamp negative values to zero.' );
$settings->save_tariff_calculation_from_admin(
	array(
		CdekSettings::SENDER_CITY_CODE_KEY => '270',
		CdekSettings::SHIPMENT_POINT_KEY => 'NSK69',
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY => '30',
		CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY => '20',
		CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY => '10',
		CdekSettings::INSURANCE_PERCENT_KEY => '0',
	)
);

$admin_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$cdek_settings_start = strpos( $admin_source, 'private function render_cdek_settings_tab' );
$cdek_calculation_rows_start = strpos( $admin_source, 'private function render_cdek_tariff_calculation_rows' );
$cdek_settings_source = false !== $cdek_settings_start && false !== $cdek_calculation_rows_start ? substr( $admin_source, $cdek_settings_start, $cdek_calculation_rows_start - $cdek_settings_start ) : '';
cdek_tariff_assert( ! str_contains( $cdek_settings_source, 'Расчет тарифов' ), 'CDEK tariff calculation block must not remain in credentials tab.' );
cdek_tariff_assert( str_contains( $admin_source, 'render_cdek_tariff_calculation_rows' ) && str_contains( $admin_source, 'Цена страховки' ), 'CDEK tariff calculation block with insurance percent must be rendered in calculation tab.' );
cdek_tariff_assert( str_contains( $admin_source, 'Город отправителя СДЭК для тарифов от двери' ) && str_contains( $admin_source, 'Используется в from_location.city при регистрации отправлений СДЭК с забором от двери.' ), 'CDEK sender city setting must use the operational from-door label and description.' );
$calculation_rows_source = substr( $admin_source, (int) $cdek_calculation_rows_start, 1200 );
cdek_tariff_assert( false !== strpos( $calculation_rows_source, 'CdekSettings::SENDER_CITY_NAME_KEY' ) && false !== strpos( $calculation_rows_source, 'CdekSettings::SENDER_CITY_CODE_KEY' ) && strpos( $calculation_rows_source, 'CdekSettings::SENDER_CITY_NAME_KEY' ) < strpos( $calculation_rows_source, 'CdekSettings::SENDER_CITY_CODE_KEY' ), 'CDEK sender city name must be the first tariff-calculation field before sender city code.' );
cdek_tariff_assert( str_contains( $admin_source, 'render_cdek_rules_calculator' ) && str_contains( $admin_source, 'Тестовый калькулятор СДЭК' ), 'CDEK rules tab must render the test calculator block.' );
cdek_tariff_assert( str_contains( $admin_source, 'name="cdek_test[region]"' ) && str_contains( $admin_source, 'name="cdek_test[city]"' ) && str_contains( $admin_source, 'name="cdek_test[weight_g]"' ) && str_contains( $admin_source, 'name="cdek_test[length_cm]"' ) && str_contains( $admin_source, 'name="cdek_test[width_cm]"' ) && str_contains( $admin_source, 'name="cdek_test[height_cm]"' ) && str_contains( $admin_source, 'name="cdek_test[declared_value]"' ), 'CDEK test calculator must expose region/city/weight/dimensions/value fields.' );
$cdek_calculator_start = strpos( $admin_source, 'private function render_cdek_rules_calculator' );
$cdek_calculator_end = strpos( $admin_source, 'private function render_create_form' );
$cdek_calculator_source = false !== $cdek_calculator_start && false !== $cdek_calculator_end ? substr( $admin_source, $cdek_calculator_start, $cdek_calculator_end - $cdek_calculator_start ) : '';
cdek_tariff_assert( ! str_contains( $cdek_calculator_source, 'type="date"' ) && ! str_contains( $cdek_calculator_source, 'cdek_test[date]' ), 'CDEK test calculator must not ask for calculation date.' );
cdek_tariff_assert( str_contains( $admin_source, '$this->cdek_carrier->quote( $request )' ) && str_contains( $admin_source, 'DeliveryType::PICKUP, DeliveryType::COURIER' ), 'CDEK test calculator must reuse CdekCarrier runtime quote path for pickup and courier tariffs.' );
cdek_tariff_assert( str_contains( $admin_source, 'Не удалось определить код города СДЭК для указанного города.' ), 'CDEK test calculator must show a readable city-code lookup error.' );
cdek_tariff_assert( str_contains( $admin_source, 'Цена API до правил' ) && str_contains( $admin_source, 'Срок API до правил' ) && str_contains( $admin_source, 'Цена после правил' ) && str_contains( $admin_source, 'Срок после правил' ), 'CDEK test calculator result must show API and final price/term columns.' );
cdek_tariff_assert( str_contains( $admin_source, '! $is_cdek' ) && str_contains( $admin_source, 'set_service_simulation_runner( null )' ), 'CDEK rules tab must disable the inherited service rules checker.' );

$GLOBALS['wdc_cdek_tariff_logs'] = array();
$pickup_quote = $carrier->quote( cdek_tariff_request( DeliveryType::PICKUP ) );
cdek_tariff_assert( 1 === count( $pickup_quote->rates ), 'Pickup CDEK tariff must be mapped to one rate.' );
$pickup_rate = $pickup_quote->rates[0];
cdek_tariff_assert( $pickup_rate instanceof DeliveryRate, 'Pickup rate must be DeliveryRate.' );
cdek_tariff_assert( '136' === $pickup_rate->tariff_key, 'Pickup tariff_code must be saved.' );
cdek_tariff_assert( 'Посылка склад-склад' === $pickup_rate->tariff_name, 'Pickup tariff_name must be saved.' );
cdek_tariff_assert( 350.5 === $pickup_rate->price->get_rubles(), 'CDEK delivery_sum must be mapped as rubles.' );
cdek_tariff_assert( '2-4 дня' === (string) $pickup_rate->meta['api_delivery_days_text'], 'CDEK period must be mapped to delivery days text.' );
cdek_tariff_assert( DeliveryType::PICKUP === $pickup_rate->delivery_type, 'delivery_mode 4 warehouse-warehouse must be pickup.' );
cdek_tariff_assert( 'СДЭК до пункта выдачи, Посылка склад-склад - 2-4 дня' === $pickup_rate->title, 'CDEK pickup runtime title must include method, tariff and delivery days.' );
cdek_tariff_assert( true === $pickup_rate->requires_pickup_point, 'CDEK pickup rate must require pickup point selection.' );
cdek_tariff_assert( ! array_key_exists( 'package_count', $pickup_rate->meta ) && ! array_key_exists( 'packages_payload_sanitized', $pickup_rate->meta ), 'CDEK package payload diagnostics must not add extra rate meta fields.' );
$location_log = cdek_tariff_find_log( 'CDEK location resolved.' );
cdek_tariff_assert( true === (bool) ( $location_log['context']['success'] ?? false ), 'CDEK location resolve result must be logged.' );
cdek_tariff_assert( 270 === (int) ( $location_log['context']['city_code'] ?? 0 ), 'CDEK location resolve log must include city_code.' );
$tarifflist_log = cdek_tariff_find_log( 'CDEK tarifflist succeeded.' );
cdek_tariff_assert( array() === $tarifflist_log, 'CDEK successful tarifflist calls must not emit routine debug logs.' );
$filter_log = cdek_tariff_find_log( 'CDEK tariff filter completed.' );
cdek_tariff_assert( DeliveryType::PICKUP === (string) ( $filter_log['context']['requested_delivery_type'] ?? '' ), 'CDEK filter log must include requested delivery type.' );
cdek_tariff_assert( 1 === (int) ( $filter_log['context']['matched_rates_count'] ?? 0 ), 'CDEK filter log must include matched rates count.' );
cdek_tariff_assert( 1 === (int) ( $filter_log['context']['skipped_unknown_count'] ?? 0 ), 'CDEK filter log must include skipped unknown count.' );
cdek_tariff_assert( 1 === (int) ( $filter_log['context']['skipped_other_type_count'] ?? 0 ), 'CDEK filter log must include skipped other delivery type count.' );

$courier_quote = $carrier->quote( cdek_tariff_request( DeliveryType::COURIER ) );
cdek_tariff_assert( 1 === count( $courier_quote->rates ), 'Courier CDEK tariff must be mapped to one rate.' );
$courier_rate = $courier_quote->rates[0];
cdek_tariff_assert( DeliveryType::COURIER === $courier_rate->delivery_type, 'delivery_mode 3 warehouse-door must be courier.' );
cdek_tariff_assert( 'СДЭК курьер, Посылка склад-дверь - 1 день' === $courier_rate->title, 'CDEK courier runtime title must include method, tariff and delivery days.' );
cdek_tariff_assert( '137' === $courier_rate->tariff_key, 'Unknown delivery mode must be skipped.' );

$wc_rate = ( new WooCommerceRateMapper() )->map( $courier_rate );
cdek_tariff_assert( $courier_rate->title === (string) $wc_rate['label'] && 1 === substr_count( (string) $wc_rate['label'], '1 день' ), 'CDEK checkout rate label must include delivery days once.' );
$checkout_rate = array_merge(
	$wc_rate['meta_data'],
	array(
		'label' => $wc_rate['label'],
		'cost' => (float) $wc_rate['cost'],
	)
);
$checkout_session = new CheckoutSessionManager();
$checkout_session->save_rates( array( $courier_rate->rate_id => $checkout_rate ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:' . $courier_rate->rate_id ) );
$checkout_item = new WdcCdekTariffSmokeShippingItem();
$checkout_item->meta = array(
	'carrier_key' => 'cdek',
	'rate_id' => $courier_rate->rate_id,
	'delivery_type' => DeliveryType::COURIER,
	'service_key' => 'cdek',
	'api_base_price_rub' => 520,
	'tariff_key' => '137',
	'selected_tariff_object' => '137',
	'Перевозчик' => 'cdek',
	'Способ доставки' => 'cdek:courier',
	'Тип доставки' => 'Курьер',
	'Населенный пункт' => 'Новосибирск',
	'Нормализация' => 'manual',
);
$checkout_persister = new OrderShippingMetaPersister( $checkout_session );
$checkout_persister->persist_shipping_item_meta( $checkout_item );
cdek_tariff_assert( $courier_rate->title === $checkout_item->method_title, 'CDEK checkout shipping item method title must keep method, tariff and delivery text. Expected "' . $courier_rate->title . '", got "' . $checkout_item->method_title . '".' );
cdek_tariff_assert( array( 'Срок доставки' => '1 день' ) === $checkout_item->meta, 'CDEK checkout shipping item visible meta must contain only delivery time.' );
foreach ( array( 'carrier_key', 'rate_id', 'delivery_type', 'service_key', 'api_base_price_rub', 'tariff_key', 'selected_tariff_object', 'Перевозчик', 'Способ доставки', 'Тип доставки', 'Населенный пункт', 'Нормализация' ) as $forbidden_meta_key ) {
	cdek_tariff_assert( ! array_key_exists( $forbidden_meta_key, $checkout_item->meta ), 'CDEK checkout visible meta must not contain technical key: ' . $forbidden_meta_key );
}
$checkout_order = new WdcCdekTariffSmokeOrder();
$checkout_persister->persist( $checkout_order );
$checkout_calc = $checkout_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
cdek_tariff_assert( isset( $checkout_order->meta['_wdc_platform_rate_meta'], $checkout_calc['api'], $checkout_calc['package'], $checkout_calc['rules'] ), 'CDEK checkout hidden rate meta and calculation data must be saved.' );
cdek_tariff_assert( 520.0 === (float) ( $checkout_calc['api']['api_base_price_rub'] ?? 0 ), 'CDEK checkout calculation data must keep API base price.' );
cdek_tariff_assert( isset( $checkout_order->meta['_wdc_platform_rate_meta']['request_payload_sanitized'], $checkout_order->meta['_wdc_platform_rate_meta']['response_tariff_sanitized'] ), 'CDEK checkout hidden rate meta must keep sanitized request and response data.' );

$tariff_request = array_values( array_filter( $http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) )[0];
cdek_tariff_assert( 'POST' === $tariff_request['method'], 'CDEK tarifflist must use POST.' );
cdek_tariff_assert( str_contains( $tariff_request['url'], '/v2/calculator/tarifflist' ), 'CDEK tarifflist endpoint mismatch.' );
cdek_tariff_assert( 'Bearer runtime-token' === ( $tariff_request['args']['headers']['Authorization'] ?? '' ), 'CDEK tarifflist must send bearer token.' );
$payload = json_decode( (string) $tariff_request['args']['body'], true );
cdek_tariff_assert( 270 === (int) ( $payload['from_location']['code'] ?? 0 ), 'CDEK from_location.code mismatch.' );
cdek_tariff_assert( 270 === (int) ( $payload['to_location']['code'] ?? 0 ), 'CDEK to_location.code mismatch.' );
cdek_tariff_assert( 'NSK69' === (string) ( $payload['shipment_point'] ?? '' ), 'CDEK shipment_point must be sent in main tarifflist payload.' );
cdek_tariff_assert( 1 === ( $payload['currency'] ?? null ), 'CDEK tarifflist currency must be int 1 for RUB.' );
cdek_tariff_assert( ! str_contains( (string) $tariff_request['args']['body'], '"currency":"RUB"' ) && ! str_contains( (string) $tariff_request['args']['body'], '"RUB"' ), 'CDEK tarifflist payload must not contain string RUB currency.' );
cdek_tariff_assert( 1 === count( $payload['packages'] ?? array() ), 'CDEK aggregate packaging weight must not produce a separate package.' );
cdek_tariff_assert( 1000 === (int) ( $payload['packages'][0]['weight'] ?? 0 ), 'CDEK aggregate packaging weight must be added once to the first package.' );
cdek_tariff_assert( 12 === (int) ( $payload['packages'][0]['length'] ?? 0 ) && 8 === (int) ( $payload['packages'][0]['width'] ?? 0 ) && 4 === (int) ( $payload['packages'][0]['height'] ?? 0 ), 'CDEK package dimensions mismatch.' );

$single_http = new CdekTariffFakeHttpClient();
[ , , $single_carrier ] = cdek_tariff_settings( $single_http, true );
$single_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-single', 'Один товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 900, 9, 8, 7 ) ) ) );
$single_payload = cdek_tariff_tarifflist_payloads( $single_http )[0] ?? array();
cdek_tariff_assert( 1 === count( $single_payload['packages'] ?? array() ) && array( 'weight' => 900, 'length' => 9, 'width' => 8, 'height' => 7 ) === ( $single_payload['packages'][0] ?? null ), 'CDEK one item quantity=1 without packaging must produce one package.' );

$quantity_http = new CdekTariffFakeHttpClient();
[ , , $quantity_carrier ] = cdek_tariff_settings( $quantity_http, true );
$quantity_item = new PackageItem( 'sku-qty', 'Товар qty', 4, Money::from_rubles( 100 ), Money::from_rubles( 400 ), 1000, 36, 12, 12 );
$quantity_carrier->quote( cdek_tariff_request_with_items( array( $quantity_item ) ) );
$quantity_payloads = cdek_tariff_tarifflist_payloads( $quantity_http );
$quantity_payload = $quantity_payloads[0] ?? array();
$quantity_single_payload = $quantity_payloads[1] ?? array();
cdek_tariff_assert( 4 === count( $quantity_payload['packages'] ?? array() ), 'CDEK item quantity=4 must produce four packages.' );
cdek_tariff_assert( array( 1000, 1000, 1000, 1000 ) === array_map( static fn( array $package ): int => (int) ( $package['weight'] ?? 0 ), $quantity_payload['packages'] ?? array() ), 'CDEK item quantity=4 without packaging must keep four 1000 g packages.' );
cdek_tariff_assert( array( 'weight' => 1000, 'length' => 36, 'width' => 12, 'height' => 12 ) === ( $quantity_payload['packages'][3] ?? null ), 'CDEK repeated package must keep unit item weight and dimensions.' );
cdek_tariff_assert( 'NSK69' === (string) ( $quantity_single_payload['shipment_point'] ?? '' ), 'CDEK shipment_point must be sent in single-package payload.' );
cdek_tariff_assert( 1 === count( $quantity_single_payload['packages'] ?? array() ) && 4000 === (int) ( $quantity_single_payload['packages'][0]['weight'] ?? 0 ), 'CDEK quantity=4 item fitting 50x50x30 must produce a single-package second payload.' );
$quantity_single_box = $quantity_single_payload['packages'][0] ?? array();
cdek_tariff_assert( isset( $quantity_single_box['length'], $quantity_single_box['width'], $quantity_single_box['height'] ) && (int) $quantity_single_box['length'] <= 50 && (int) $quantity_single_box['width'] <= 50 && (int) $quantity_single_box['height'] <= 30, 'CDEK single-package payload must include calculated box dimensions within 50x50x30 for four 36x12x12 items.' );

$quantity_packaging_http = new CdekTariffFakeHttpClient();
[ , , $quantity_packaging_carrier ] = cdek_tariff_settings( $quantity_packaging_http, true );
$quantity_packaging_carrier->quote( cdek_tariff_request_with_items( array( $quantity_item ), 200 ) );
$quantity_packaging_payload = cdek_tariff_tarifflist_payloads( $quantity_packaging_http )[0] ?? array();
cdek_tariff_assert( 4 === count( $quantity_packaging_payload['packages'] ?? array() ), 'CDEK packaging_weight_g must not create an extra package for item payloads.' );
cdek_tariff_assert( array( 1200, 1000, 1000, 1000 ) === array_map( static fn( array $package ): int => (int) ( $package['weight'] ?? 0 ), $quantity_packaging_payload['packages'] ?? array() ), 'CDEK packaging_weight_g must be added once to the first package only.' );

$mixed_http = new CdekTariffFakeHttpClient();
[ , , $mixed_carrier ] = cdek_tariff_settings( $mixed_http, true );
$mixed_carrier->quote(
	cdek_tariff_request_with_items(
		array(
			new PackageItem( 'sku-a', 'Товар A', 2, Money::from_rubles( 100 ), Money::from_rubles( 200 ), 500, 10, 11, 12 ),
			new PackageItem( 'sku-b', 'Товар B', 3, Money::from_rubles( 100 ), Money::from_rubles( 300 ), 600, 20, 21, 22 ),
		)
	)
);
$mixed_payload = cdek_tariff_tarifflist_payloads( $mixed_http )[0] ?? array();
cdek_tariff_assert( 5 === count( $mixed_payload['packages'] ?? array() ), 'CDEK different items must produce sum(quantity) packages.' );

$defaults_http = new CdekTariffFakeHttpClient();
[ , , $defaults_carrier ] = cdek_tariff_settings( $defaults_http, true );
$defaults_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-defaults', 'Без размеров', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 450, 0, 5, 0 ) ) ) );
$defaults_payload = cdek_tariff_tarifflist_payloads( $defaults_http )[0] ?? array();
cdek_tariff_assert( array( 'weight' => 450, 'length' => 30, 'width' => 5, 'height' => 10 ) === ( $defaults_payload['packages'][0] ?? null ), 'CDEK missing item dimensions must fallback per dimension to CDEK defaults.' );

$packaging_http = new CdekTariffFakeHttpClient();
[ , , $packaging_carrier ] = cdek_tariff_settings( $packaging_http, true );
$packaging_carrier->quote(
	cdek_tariff_request_with_items(
		array(
			new PackageItem( 'sku-product', 'Товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 800, 15, 10, 5 ),
			new PackageItem( 'WDC_PACKAGING', 'Упаковка', 1, Money::from_rubles( 0 ), Money::from_rubles( 0 ), 150, 1, 1, 1 ),
		)
	)
);
$packaging_payload = cdek_tariff_tarifflist_payloads( $packaging_http )[0] ?? array();
cdek_tariff_assert( 2 === count( $packaging_payload['packages'] ?? array() ) && array( 'weight' => 150, 'length' => 1, 'width' => 1, 'height' => 1 ) === ( $packaging_payload['packages'][1] ?? null ), 'CDEK WDC_PACKAGING item must be sent as a separate package.' );

$packaging_with_property_http = new CdekTariffFakeHttpClient();
[ , , $packaging_with_property_carrier ] = cdek_tariff_settings( $packaging_with_property_http, true );
$packaging_with_property_carrier->quote(
	cdek_tariff_request_with_items(
		array(
			new PackageItem( 'sku-product', 'Товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 800, 15, 10, 5 ),
			new PackageItem( 'WDC_PACKAGING', 'Упаковка', 1, Money::from_rubles( 0 ), Money::from_rubles( 0 ), 200, 1, 1, 1 ),
		),
		200
	)
);
$packaging_with_property_payload = cdek_tariff_tarifflist_payloads( $packaging_with_property_http )[0] ?? array();
cdek_tariff_assert( array( 800, 200 ) === array_map( static fn( array $package ): int => (int) ( $package['weight'] ?? 0 ), $packaging_with_property_payload['packages'] ?? array() ), 'CDEK WDC_PACKAGING item must prevent applying package packaging_weight_g again.' );

$aggregate_http = new CdekTariffFakeHttpClient();
[ , , $aggregate_carrier ] = cdek_tariff_settings( $aggregate_http, true );
$aggregate_package = new Package( array(), Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 0, 200, 1234, 40, 30, 20, null, 'manual' );
$aggregate_carrier->quote( cdek_tariff_request_with_package( $aggregate_package ) );
$aggregate_payload = cdek_tariff_last_tarifflist_payload( $aggregate_http );
cdek_tariff_assert( 1 === count( $aggregate_payload['packages'] ?? array() ) && 1234 === (int) ( $aggregate_payload['packages'][0]['weight'] ?? 0 ), 'CDEK no-items aggregated fallback must keep one package with total_weight_g.' );

$oversized_http = new CdekTariffFakeHttpClient();
[ , , $oversized_carrier ] = cdek_tariff_settings( $oversized_http, true );
$oversized_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-big', 'Большой товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000, 60, 10, 10 ) ) ) );
cdek_tariff_assert( 1 === count( cdek_tariff_tarifflist_payloads( $oversized_http ) ), 'CDEK single-package request must not run when an item cannot fit 50x50x30 in any orientation.' );

$merge_http = new CdekTariffFakeHttpClient();
$merge_http->tariff_codes_responses = array(
	array(
		array( 'tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 350, 'period_min' => 3, 'period_max' => 3 ),
	),
	array(
		array( 'tariff_code' => 136, 'tariff_name' => 'Дубль из single', 'delivery_mode' => 4, 'delivery_sum' => 100, 'period_min' => 1, 'period_max' => 1 ),
		array( 'tariff_code' => 138, 'tariff_name' => 'Экспресс склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 1, 'period_max' => 1 ),
	),
);
[ , , $merge_carrier ] = cdek_tariff_settings( $merge_http, true );
$merge_quote = $merge_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-fit', 'Товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000, 10, 10, 10 ) ) ) );
cdek_tariff_assert( array( '136', '138' ) === cdek_tariff_rate_codes( $merge_quote->rates ), 'CDEK single-package tariffs must add only new tariff_code values and ignore duplicates.' );

$insurance_http = new CdekTariffFakeHttpClient();
$insurance_http->tariff_codes_responses = array(
	array(
		array( 'tariff_code' => 701, 'tariff_name' => 'Страховой склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 525, 'period_min' => 2, 'period_max' => 2 ),
	),
	array(
		array( 'tariff_code' => 701, 'tariff_name' => 'Дубль страховой', 'delivery_mode' => 4, 'delivery_sum' => 100, 'period_min' => 1, 'period_max' => 1 ),
	),
);
[ $insurance_settings, , $insurance_carrier ] = cdek_tariff_settings( $insurance_http, true );
$insurance_settings->save_tariff_calculation_from_admin(
	array(
		CdekSettings::SENDER_CITY_CODE_KEY => '270',
		CdekSettings::SHIPMENT_POINT_KEY => 'NSK69',
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY => '30',
		CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY => '20',
		CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY => '10',
		CdekSettings::INSURANCE_PERCENT_KEY => '0.75',
	)
);
$insurance_quote = $insurance_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-insured', 'Товар', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000, 10, 10, 10 ) ) ) );
$insurance_rate = $insurance_quote->rates[0] ?? null;
cdek_tariff_assert( $insurance_rate instanceof DeliveryRate && 532.5 === $insurance_rate->price->get_rubles(), 'CDEK base API cost must include insurance amount before rules.' );
cdek_tariff_assert( 525.0 === (float) ( $insurance_rate->meta['cdek_delivery_cost_before_insurance'] ?? 0 ) && 7.5 === (float) ( $insurance_rate->meta['cdek_insurance_amount'] ?? 0 ), 'CDEK insurance breakdown must keep delivery cost before insurance and insurance amount.' );
cdek_tariff_assert( 532.5 === (float) ( $insurance_rate->meta['api_base_price_rub'] ?? 0 ), 'CDEK api_base_price_rub must include insurance amount.' );

$insurance_rule_http = new CdekTariffFakeHttpClient();
$insurance_rule_http->tariff_codes_responses = array(
	array(
		array( 'tariff_code' => 702, 'tariff_name' => 'Страховой склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 525, 'period_min' => 2, 'period_max' => 2 ),
	),
	array(
		array( 'tariff_code' => 702, 'tariff_name' => 'Дубль страховой', 'delivery_mode' => 4, 'delivery_sum' => 100, 'period_min' => 1, 'period_max' => 1 ),
	),
);
[ $insurance_rule_settings, , $insurance_rule_carrier ] = cdek_tariff_settings( $insurance_rule_http, true );
$insurance_rule_settings->save_tariff_calculation_from_admin(
	array(
		CdekSettings::SENDER_CITY_CODE_KEY => '270',
		CdekSettings::SHIPMENT_POINT_KEY => 'NSK69',
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY => '30',
		CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY => '20',
		CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY => '10',
		CdekSettings::INSURANCE_PERCENT_KEY => '0.75',
	)
);
$insurance_rule = new Rule( 701, 'Add 100 rub after insurance', true, 10, RuleRepository::TARGET_DEFAULT, '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 100, RuleOperationBases::RUBLES, false, false );
$insurance_ruled = cdek_tariff_orchestrator( $insurance_rule_carrier )->calculate( cdek_tariff_request_with_items( array( new PackageItem( 'sku-insured', 'Товар', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000, 10, 10, 10 ) ) ), array( $insurance_rule ), RateSorter::CHEAPEST, false );
$insurance_ruled_rate = $insurance_ruled->rates[0] ?? null;
cdek_tariff_assert( $insurance_ruled_rate instanceof DeliveryRate && 632.5 === $insurance_ruled_rate->price->get_rubles() && 532.5 === (float) ( $insurance_ruled_rate->meta['api_base_price_rub'] ?? 0 ), 'CDEK rules must apply to delivery cost with insurance and keep insured base API cost in meta.' );

$same_period_price_http = new CdekTariffFakeHttpClient();
$same_period_price_http->tariff_codes_override = array(
	array( 'tariff_code' => 301, 'tariff_name' => 'Дорогой склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 700, 'period_min' => 2, 'period_max' => 4 ),
	array( 'tariff_code' => 302, 'tariff_name' => 'Дешевый склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 4 ),
);
[ , , $same_period_price_carrier ] = cdek_tariff_settings( $same_period_price_http, true );
$same_period_price_quote = $same_period_price_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-big', 'Большой товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000, 60, 10, 10 ) ) ) );
cdek_tariff_assert( array( '302' ) === cdek_tariff_rate_codes( $same_period_price_quote->rates ), 'CDEK same period_min/period_max group must keep the cheapest rate.' );

$same_period_name_http = new CdekTariffFakeHttpClient();
$same_period_name_http->tariff_codes_override = array(
	array( 'tariff_code' => 401, 'tariff_name' => 'Я тариф', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 2 ),
	array( 'tariff_code' => 402, 'tariff_name' => 'Просто посылка', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 2 ),
	array( 'tariff_code' => 403, 'tariff_name' => 'Тариф склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 2 ),
	array( 'tariff_code' => 404, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 2 ),
);
[ , , $same_period_name_carrier ] = cdek_tariff_settings( $same_period_name_http, true );
$same_period_name_quote = $same_period_name_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-big', 'Большой товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000, 60, 10, 10 ) ) ) );
cdek_tariff_assert( array( '404' ) === cdek_tariff_rate_codes( $same_period_name_quote->rates ), 'CDEK same price priority must prefer CDEK name Посылка склад-склад over склад-склад, посылка, then alphabet.' );

$same_period_alpha_http = new CdekTariffFakeHttpClient();
$same_period_alpha_http->tariff_codes_override = array(
	array( 'tariff_code' => 501, 'tariff_name' => 'Я тариф', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 2 ),
	array( 'tariff_code' => 502, 'tariff_name' => 'А тариф', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 2 ),
);
[ , , $same_period_alpha_carrier ] = cdek_tariff_settings( $same_period_alpha_http, true );
$same_period_alpha_quote = $same_period_alpha_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-big', 'Большой товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000, 60, 10, 10 ) ) ) );
cdek_tariff_assert( array( '502' ) === cdek_tariff_rate_codes( $same_period_alpha_quote->rates ), 'CDEK same price/name fallback must keep alphabetically first CDEK name.' );

$faster_cheaper_http = new CdekTariffFakeHttpClient();
$faster_cheaper_http->tariff_codes_override = array(
	array( 'tariff_code' => 601, 'tariff_name' => 'Быстрый дешевый', 'delivery_mode' => 4, 'delivery_sum' => 400, 'period_min' => 1, 'period_max' => 5 ),
	array( 'tariff_code' => 602, 'tariff_name' => 'Медленный дорогой', 'delivery_mode' => 4, 'delivery_sum' => 500, 'period_min' => 3, 'period_max' => 3 ),
	array( 'tariff_code' => 603, 'tariff_name' => 'Быстрый дорогой', 'delivery_mode' => 4, 'delivery_sum' => 600, 'period_min' => 1, 'period_max' => 1 ),
);
[ , , $faster_cheaper_carrier ] = cdek_tariff_settings( $faster_cheaper_http, true );
$faster_cheaper_quote = $faster_cheaper_carrier->quote( cdek_tariff_request_with_items( array( new PackageItem( 'sku-big', 'Большой товар', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000, 60, 10, 10 ) ) ) );
cdek_tariff_assert( array( '601' ) === cdek_tariff_rate_codes( $faster_cheaper_quote->rates ), 'CDEK faster and cheaper rule must remove rates with other.period_min <= current.period_min and lower price.' );

[ $service_registry, $service_manager ] = cdek_tariff_service_runtime( $carrier, false );
$disabled_result = cdek_tariff_orchestrator( $carrier, $service_registry, $service_manager )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, false );
cdek_tariff_assert( array() === array_values( array_filter( $disabled_result->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) ), 'Disabled CDEK service must not produce rates.' );

$missing_http = new CdekTariffFakeHttpClient();
[ , , $missing_carrier ] = cdek_tariff_settings( $missing_http, false );
[ $enabled_registry, $enabled_manager ] = cdek_tariff_service_runtime( $missing_carrier, true );
$GLOBALS['wdc_cdek_tariff_logs'] = array();
$missing_result = cdek_tariff_orchestrator( $missing_carrier, $enabled_registry, $enabled_manager )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, false );
cdek_tariff_assert( array() === array_values( array_filter( $missing_result->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) ), 'Missing CDEK credentials must not produce rates or fatal.' );
$empty_reason_log = cdek_tariff_find_log( 'CDEK quote returned empty.' );
cdek_tariff_assert( 'unsupported_or_credentials_missing' === (string) ( $empty_reason_log['context']['reason'] ?? '' ), 'CDEK empty quote reason must be logged.' );

$http = new CdekTariffFakeHttpClient();
[ $settings, $client, $carrier ] = cdek_tariff_settings( $http, true );
[ $enabled_registry, $enabled_manager ] = cdek_tariff_service_runtime( $carrier, true );
$success_result = cdek_tariff_orchestrator( $carrier, $enabled_registry, $enabled_manager )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, false );
$cdek_rates = array_values( array_filter( $success_result->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
cdek_tariff_assert( 2 === count( $cdek_rates ), 'Enabled CDEK service must produce pickup and courier runtime rates.' );

$cache_http = new CdekTariffFakeHttpClient();
[ , , $cache_carrier ] = cdek_tariff_settings( $cache_http, true );
[ $cache_registry, $cache_manager ] = cdek_tariff_service_runtime( $cache_carrier, true );
$success_cache = new QuoteCache();
$success_cache->invalidate_all();
$cache_first = cdek_tariff_orchestrator( $cache_carrier, $cache_registry, $cache_manager, $success_cache )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, true );
$cache_first_cdek = array_values( array_filter( $cache_first->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
$cache_tarifflist_count = count( array_filter( $cache_http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
$cache_http->tariff_error = true;
$cache_second = cdek_tariff_orchestrator( $cache_carrier, $cache_registry, $cache_manager, $success_cache )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, true );
$cache_second_cdek = array_values( array_filter( $cache_second->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
$cache_tarifflist_after_hit = count( array_filter( $cache_http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
cdek_tariff_assert( 2 === count( $cache_first_cdek ) && 2 === count( $cache_second_cdek ) && $cache_tarifflist_count === $cache_tarifflist_after_hit, 'Successful CDEK quote cache must serve previous rates for the same context without a new tarifflist call.' );

$no_match_http = new CdekTariffFakeHttpClient();
$no_match_http->tariff_codes_override = array(
	array( 'tariff_code' => 137, 'tariff_name' => 'Courier only', 'delivery_mode' => 3, 'delivery_sum' => 520, 'period_min' => 1, 'period_max' => 1 ),
	array( 'tariff_code' => 999, 'tariff_name' => 'Unknown mode', 'delivery_mode' => 9, 'delivery_sum' => 1, 'period_min' => 1, 'period_max' => 1 ),
);
$GLOBALS['wdc_cdek_tariff_logs'] = array();
[ , , $no_match_carrier ] = cdek_tariff_settings( $no_match_http, true );
$no_match_quote = $no_match_carrier->quote( cdek_tariff_request( DeliveryType::PICKUP ) );
cdek_tariff_assert( array() === $no_match_quote->rates && 'no_tariffs_available' === $no_match_quote->error_code, 'CDEK tariff response with no matching tariffs must produce no rates.' );
$no_match_filter_log = cdek_tariff_find_log( 'CDEK tariff filter completed.' );
cdek_tariff_assert( 0 === (int) ( $no_match_filter_log['context']['matched_rates_count'] ?? -1 ), 'CDEK no-match filter log must include zero matched rates.' );
cdek_tariff_assert( 1 === (int) ( $no_match_filter_log['context']['skipped_unknown_count'] ?? 0 ), 'CDEK no-match filter log must include unknown skipped count.' );
cdek_tariff_assert( 1 === (int) ( $no_match_filter_log['context']['skipped_other_type_count'] ?? 0 ), 'CDEK no-match filter log must include other type skipped count.' );
$no_match_warning = cdek_tariff_find_log( 'CDEK tariff response has no matching tariffs for delivery type.' );
cdek_tariff_assert( DeliveryType::PICKUP === (string) ( $no_match_warning['context']['requested_delivery_type'] ?? '' ), 'CDEK no matching tariffs warning must include requested delivery type.' );
$no_tariffs_reason_log = cdek_tariff_find_log( 'CDEK quote returned empty.' );
cdek_tariff_assert( 'no_tariffs_available' === (string) ( $no_tariffs_reason_log['context']['reason'] ?? '' ), 'CDEK no_tariffs_available reason must be logged.' );

$no_match_cache = new QuoteCache();
$no_match_cache->invalidate_all();
$no_match_cached_first = cdek_tariff_orchestrator( $no_match_carrier, null, null, $no_match_cache )->calculate( cdek_tariff_request( DeliveryType::PICKUP ), array(), RateSorter::CHEAPEST, true );
$no_match_cached_count = count( array_filter( $no_match_http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
$no_match_cached_second = cdek_tariff_orchestrator( $no_match_carrier, null, null, $no_match_cache )->calculate( cdek_tariff_request( DeliveryType::PICKUP ), array(), RateSorter::CHEAPEST, true );
$no_match_cached_count_after = count( array_filter( $no_match_http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
$no_match_cached_first_cdek = array_values( array_filter( $no_match_cached_first->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
$no_match_cached_second_cdek = array_values( array_filter( $no_match_cached_second->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
cdek_tariff_assert( array() === $no_match_cached_first_cdek && array() === $no_match_cached_second_cdek && $no_match_cached_count_after > $no_match_cached_count, 'CDEK empty successful quote with zero rates must not be cached as a stable no-rates result.' );

$rule = new Rule( 1, 'Add 100 rub', true, 10, RuleRepository::TARGET_DEFAULT, '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 100, RuleOperationBases::RUBLES, false, false );
$ruled = cdek_tariff_orchestrator( $carrier )->calculate( cdek_tariff_request(), array( $rule ), RateSorter::CHEAPEST, false );
$ruled_cdek = array_values( array_filter( $ruled->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) )[0] ?? null;
cdek_tariff_assert( $ruled_cdek instanceof DeliveryRate && 450.5 === $ruled_cdek->price->get_rubles(), 'Rule engine must be able to change CDEK final price.' );
cdek_tariff_assert( 350.5 === (float) ( $ruled_cdek->meta['api_base_price_rub'] ?? 0 ), 'CDEK base API price must remain in meta after rules.' );

$api = is_array( $pickup_rate->meta['request_payload_sanitized'] ?? null ) ? $pickup_rate->meta['request_payload_sanitized'] : array();
cdek_tariff_assert( 270 === (int) ( $api['from_location']['code'] ?? 0 ), 'request_payload_sanitized must keep from city code.' );
cdek_tariff_assert( 1000 === (int) ( $pickup_rate->meta['package']['weight_g'] ?? 0 ), 'CDEK package weight meta mismatch.' );
cdek_tariff_assert( 700 === (int) ( $pickup_rate->meta['package']['items_weight_g'] ?? 0 ), 'CDEK products weight meta mismatch.' );
cdek_tariff_assert( 300 === (int) ( $pickup_rate->meta['package']['packaging_weight_g'] ?? 0 ), 'CDEK packaging weight meta mismatch.' );

$preview = ( new OrderDeliveryRecalculationService( new OrderQuoteRequestMapper(), cdek_tariff_orchestrator( $carrier, $enabled_registry, $enabled_manager ), ( new ReflectionClass( OrderShipmentRepository::class ) )->newInstanceWithoutConstructor() ) )->preview( new class {
	public function get_items(): array { return array( new class {
		public function get_quantity(): int { return 1; }
		public function get_total(): int { return 1000; }
		public function get_name(): string { return 'Товар'; }
		public function get_product(): object { return new class {
			public function get_sku(): string { return 'sku'; }
			public function get_name(): string { return 'Товар'; }
			public function get_weight(): float { return 1.0; }
			public function get_length(): int { return 12; }
			public function get_width(): int { return 8; }
			public function get_height(): int { return 4; }
		}; }
	} ); }
	public function get_subtotal(): int { return 1000; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_billing_country(): string { return 'RU'; }
	public function get_shipping_city(): string { return 'Москва'; }
	public function get_shipping_postcode(): string { return '101000'; }
	public function get_shipping_address_1(): string { return 'Тверская'; }
	public function get_shipping_address_2(): string { return '1'; }
	public function get_shipping_state(): string { return 'Москва'; }
	public function get_payment_method(): string { return 'cod'; }
	public function get_item_count(): int { return 1; }
	public function get_id(): int { return 10; }
	public function get_meta( string $key, bool $single = true ): mixed { return 'dest-fias' === $key ? 'dest-fias' : ''; }
} );
$preview_cdek_rates = array_values( array_filter( $preview['rates'], static fn( array $rate ): bool => CdekCarrier::KEY === (string) ( $rate['carrier_key'] ?? '' ) ) );
cdek_tariff_assert( count( $preview_cdek_rates ) >= 1, 'CDEK rates must appear in admin recalculation preview.' );
$preview_cdek_tariff_labels = array();
foreach ( $preview_cdek_rates as $preview_cdek_rate ) {
	foreach ( is_array( $preview_cdek_rate['tariff_variants'] ?? null ) ? $preview_cdek_rate['tariff_variants'] : array() as $preview_tariff ) {
		$preview_cdek_tariff_labels[] = (string) ( $preview_tariff['label'] ?? '' );
	}
}
cdek_tariff_assert( in_array( 'СДЭК до пункта выдачи, Посылка склад-склад - 2-4 дня', $preview_cdek_tariff_labels, true ) || in_array( 'СДЭК курьер, Посылка склад-дверь - 1 день', $preview_cdek_tariff_labels, true ), 'CDEK admin preview tariff payload label must include method, tariff and delivery days.' );

$custom_http = new CdekTariffFakeHttpClient();
$GLOBALS['wpdb'] = new wpdb();
$custom_services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$custom_countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$custom_service = $custom_services->ensure_cdek_service();
$custom_services->update_service( (int) $custom_service->id, array( 'enabled' => 1 ) );
$custom_countries->replace_countries( (int) $custom_service->id, array( 'RU' ) );
$custom_service_settings = new WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] );
$custom_service_settings->set_setting( (int) $custom_service->id, 'pickup_method_title', 'Custom CDEK pickup', 'string' );
$custom_service_settings->set_setting( (int) $custom_service->id, 'courier_method_title', 'Custom CDEK courier', 'string' );
$custom_settings = new CdekSettings( new SettingsRepository(), new EncryptionService(), $custom_services, $custom_service_settings );
$custom_settings->save_from_admin(
	array(
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
		CdekSettings::TEST_ACCOUNT_KEY => 'account-id',
		'cdek_test_secure_password' => 'secure-password',
		CdekSettings::SENDER_CITY_CODE_KEY => '270',
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY => '30',
		CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY => '20',
		CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY => '10',
	)
);
$custom_client = new CdekApiClient( new CdekOAuthTokenService( $custom_settings, $custom_http ), $custom_settings, $custom_http );
$custom_carrier = new CdekCarrier( $custom_settings, $custom_client, new CdekLocationResolver( $custom_client, new Logger() ), new Logger() );
$custom_pickup_quote = $custom_carrier->quote( cdek_tariff_request( DeliveryType::PICKUP ) );
$custom_courier_quote = $custom_carrier->quote( cdek_tariff_request( DeliveryType::COURIER ) );
cdek_tariff_assert( 'Custom CDEK pickup, Посылка склад-склад - 2-4 дня' === ( $custom_pickup_quote->rates[0]->title ?? '' ), 'Custom CDEK pickup title must be applied to full runtime rate title.' );
cdek_tariff_assert( 'Custom CDEK courier, Посылка склад-дверь - 1 день' === ( $custom_courier_quote->rates[0]->title ?? '' ), 'Custom CDEK courier title must be applied to full runtime rate title.' );
cdek_tariff_assert( 'Custom CDEK pickup' === (string) ( $custom_pickup_quote->rates[0]->meta['pickup_method_title'] ?? '' ), 'Custom CDEK pickup title must be saved in rate meta.' );
cdek_tariff_assert( 'Custom CDEK courier' === (string) ( $custom_courier_quote->rates[0]->meta['courier_method_title'] ?? '' ), 'Custom CDEK courier title must be saved in rate meta.' );
$custom_manager = new DeliveryServiceManager( $custom_services, $custom_countries, new RuleRepository( $GLOBALS['wpdb'] ), ( new ReflectionClass( RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor() );
$custom_registry = new DeliveryServiceRegistry( $custom_services, ( function () use ( $custom_carrier ): CarrierRegistry { $registry = new CarrierRegistry(); $registry->register( $custom_carrier ); return $registry; } )() );
$custom_preview = ( new OrderDeliveryRecalculationService( new OrderQuoteRequestMapper(), cdek_tariff_orchestrator( $custom_carrier, $custom_registry, $custom_manager ), ( new ReflectionClass( OrderShipmentRepository::class ) )->newInstanceWithoutConstructor() ) )->preview( new class {
	public function get_items(): array { return array( new class {
		public function get_quantity(): int { return 1; }
		public function get_total(): int { return 1000; }
		public function get_name(): string { return 'Товар'; }
		public function get_product(): object { return new class {
			public function get_sku(): string { return 'sku'; }
			public function get_name(): string { return 'Товар'; }
			public function get_weight(): float { return 1.0; }
			public function get_length(): int { return 12; }
			public function get_width(): int { return 8; }
			public function get_height(): int { return 4; }
		}; }
	} ); }
	public function get_subtotal(): int { return 1000; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_billing_country(): string { return 'RU'; }
	public function get_shipping_city(): string { return 'Москва'; }
	public function get_shipping_postcode(): string { return '101000'; }
	public function get_shipping_address_1(): string { return 'Тверская'; }
	public function get_shipping_address_2(): string { return '1'; }
	public function get_shipping_state(): string { return 'Москва'; }
	public function get_payment_method(): string { return 'cod'; }
	public function get_item_count(): int { return 1; }
	public function get_id(): int { return 10; }
	public function get_meta( string $key, bool $single = true ): mixed { return 'dest-fias' === $key ? 'dest-fias' : ''; }
} );
$custom_preview_labels = array_map( static fn( array $rate ): string => (string) ( $rate['label'] ?? '' ), array_filter( $custom_preview['rates'], static fn( array $rate ): bool => CdekCarrier::KEY === (string) ( $rate['carrier_key'] ?? '' ) ) );
cdek_tariff_assert( in_array( 'Custom CDEK pickup', $custom_preview_labels, true ), 'Custom CDEK pickup title must be applied in admin recalculation preview.' );
cdek_tariff_assert( in_array( 'Custom CDEK courier', $custom_preview_labels, true ), 'Custom CDEK courier title must be applied in admin recalculation preview.' );
$custom_preview_tariff_labels = array();
foreach ( array_filter( $custom_preview['rates'], static fn( array $rate ): bool => CdekCarrier::KEY === (string) ( $rate['carrier_key'] ?? '' ) ) as $custom_preview_rate ) {
	foreach ( is_array( $custom_preview_rate['tariff_variants'] ?? null ) ? $custom_preview_rate['tariff_variants'] : array() as $custom_preview_tariff ) {
		$custom_preview_tariff_labels[] = (string) ( $custom_preview_tariff['label'] ?? '' );
	}
}
cdek_tariff_assert( in_array( 'Custom CDEK pickup, Посылка склад-склад - 2-4 дня', $custom_preview_tariff_labels, true ), 'Custom CDEK pickup title must be applied to admin preview tariff label.' );
cdek_tariff_assert( in_array( 'Custom CDEK courier, Посылка склад-дверь - 1 день', $custom_preview_tariff_labels, true ), 'Custom CDEK courier title must be applied to admin preview tariff label.' );

$serialized_meta = json_encode( $pickup_rate->meta, JSON_UNESCAPED_UNICODE );
cdek_tariff_assert( is_string( $serialized_meta ) && ! str_contains( $serialized_meta, 'secure-password' ) && ! str_contains( $serialized_meta, 'runtime-token' ), 'CDEK saved meta/debug must not include secret or token.' );

$error_http = new CdekTariffFakeHttpClient();
$error_http->tariff_error = true;
$GLOBALS['wdc_cdek_tariff_logs'] = array();
[ , , $error_carrier ] = cdek_tariff_settings( $error_http, true );
$error_quote = $error_carrier->quote( cdek_tariff_request( DeliveryType::PICKUP ) );
$details = is_array( $error_quote->raw_reference['api_error_details'] ?? null ) ? $error_quote->raw_reference['api_error_details'] : array();
cdek_tariff_assert( 'api_error' === $error_quote->error_code, 'CDEK tarifflist API error must produce an empty quote with api_error.' );
cdek_tariff_assert( 400 === (int) ( $details['http_code'] ?? 0 ), 'CDEK API error diagnostics must include HTTP status code.' );
cdek_tariff_assert( '/v2/calculator/tarifflist' === (string) ( $details['endpoint'] ?? '' ), 'CDEK API error diagnostics must include endpoint.' );
cdek_tariff_assert( 270 === (int) ( $details['request']['from_location']['code'] ?? 0 ), 'CDEK API error diagnostics must include sanitized request payload.' );
cdek_tariff_assert( 'INVALID_ROUTE' === (string) ( $details['cdek_error_code'] ?? '' ), 'CDEK API error diagnostics must include CDEK error code.' );
cdek_tariff_assert( 'bad route' === (string) ( $details['cdek_error_message'] ?? '' ), 'CDEK API error diagnostics must include CDEK error message.' );
cdek_tariff_assert( '[redacted]' === (string) ( $details['response']['Account'] ?? '' ), 'CDEK API error diagnostics must redact Account.' );
cdek_tariff_assert( '[redacted]' === (string) ( $details['response']['access_token'] ?? '' ), 'CDEK API error diagnostics must redact access_token.' );
$error_log = cdek_tariff_find_log( 'CDEK tarifflist failed.' );
cdek_tariff_assert( is_array( $error_log ) && 'CDEK tarifflist failed.' === (string) ( $error_log['message'] ?? '' ), 'CDEK tarifflist failure must be written to WooCommerce log.' );
cdek_tariff_assert( 400 === (int) ( $error_log['context']['http_code'] ?? 0 ), 'CDEK tarifflist log must include HTTP status code.' );
cdek_tariff_assert( DeliveryType::PICKUP === (string) ( $error_log['context']['delivery_type'] ?? '' ), 'CDEK tarifflist log must include delivery type.' );
$api_empty_log = cdek_tariff_find_log( 'CDEK quote returned empty.' );
cdek_tariff_assert( 'api_error' === (string) ( $api_empty_log['context']['reason'] ?? '' ), 'CDEK api_error empty quote reason must be logged.' );
$error_cache = new QuoteCache();
$error_cache->invalidate_all();
$error_cached_first = cdek_tariff_orchestrator( $error_carrier, null, null, $error_cache )->calculate( cdek_tariff_request( DeliveryType::PICKUP ), array(), RateSorter::CHEAPEST, true );
$error_cached_count = count( array_filter( $error_http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
$error_cached_second = cdek_tariff_orchestrator( $error_carrier, null, null, $error_cache )->calculate( cdek_tariff_request( DeliveryType::PICKUP ), array(), RateSorter::CHEAPEST, true );
$error_cached_count_after = count( array_filter( $error_http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) );
$error_cached_first_cdek = array_values( array_filter( $error_cached_first->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
$error_cached_second_cdek = array_values( array_filter( $error_cached_second->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
cdek_tariff_assert( array() === $error_cached_first_cdek && array() === $error_cached_second_cdek && $error_cached_count_after > $error_cached_count, 'CDEK api_error quote must not be cached as a zero-rate quote.' );
$serialized_error_debug = json_encode( array( $details, $error_log ), JSON_UNESCAPED_UNICODE );
cdek_tariff_assert( is_string( $serialized_error_debug ) && ! str_contains( $serialized_error_debug, 'runtime-token' ) && ! str_contains( $serialized_error_debug, 'secure-password' ) && ! str_contains( $serialized_error_debug, 'account-id' ), 'CDEK tarifflist diagnostics must not expose token, secret, or account.' );
$cache_manager_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/Cache/DeliveryQuoteCacheManager.php' ) ?: '';
cdek_tariff_assert( str_contains( $cache_manager_source, 'wdc_cdek_city_' ) && str_contains( $cache_manager_source, 'wdc_cdek_deliverypoints_' ) && ! str_contains( $cache_manager_source, 'wdc_cdek_oauth_' ), 'Delivery quote cache reset must include CDEK quote/location point caches without clearing CDEK token cache.' );

echo "CDEK tariff calculation smoke test passed.\n";
