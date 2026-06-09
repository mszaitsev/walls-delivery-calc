<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
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

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();
		/** @var array<int,array<string,mixed>> */
		public array $countries = array();

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
			$data['id'] = ++$this->insert_id;
			$this->services[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
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
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return array_values( array_filter( $this->services, static fn( array $row ): bool => empty( $row['deleted'] ) ) );
			}
			return array();
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

final class CdekTariffFakeHttpClient implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	public bool $tariff_error = false;

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( 'access_token' => 'runtime-token', 'expires_in' => 3600 ) ) );
		}
		if ( str_contains( $url, '/v2/location/cities' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( array( 'code' => 270, 'city' => 'Москва', 'region' => 'Москва', 'fias_guid' => 'dest-fias' ) ) ) );
		}
		if ( str_contains( $url, '/v2/calculator/tarifflist' ) ) {
			if ( $this->tariff_error ) {
				return new CdekApiResponse( 400, (string) json_encode( array( 'code' => 'INVALID_ROUTE', 'message' => 'bad route', 'Account' => 'account-id', 'access_token' => 'runtime-token' ) ) );
			}
			return new CdekApiResponse(
				200,
				(string) json_encode(
					array(
						'tariff_codes' => array(
							array( 'tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 1, 'delivery_sum' => 350.5, 'period_min' => 2, 'period_max' => 4, 'calendar_min' => 2, 'calendar_max' => 5 ),
							array( 'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 2, 'delivery_sum' => 520, 'period_min' => 1, 'period_max' => 1 ),
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
	$package = Package::from_items( array( $item ), 300, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
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

function cdek_tariff_orchestrator( CdekCarrier $carrier, ?DeliveryServiceRegistry $service_registry = null, ?DeliveryServiceManager $service_manager = null ): CheckoutOrchestrator {
	$registry = new CarrierRegistry();
	$registry->register( $carrier );
	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( new CheckoutLogger( new Logger() ) ),
		new CheckoutLogger( new Logger() ),
		null,
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

$http = new CdekTariffFakeHttpClient();
[ $settings, $client, $carrier ] = cdek_tariff_settings( $http, true );

$pickup_quote = $carrier->quote( cdek_tariff_request( DeliveryType::PICKUP ) );
cdek_tariff_assert( 1 === count( $pickup_quote->rates ), 'Pickup CDEK tariff must be mapped to one rate.' );
$pickup_rate = $pickup_quote->rates[0];
cdek_tariff_assert( $pickup_rate instanceof DeliveryRate, 'Pickup rate must be DeliveryRate.' );
cdek_tariff_assert( '136' === $pickup_rate->tariff_key, 'Pickup tariff_code must be saved.' );
cdek_tariff_assert( 'Посылка склад-склад' === $pickup_rate->tariff_name, 'Pickup tariff_name must be saved.' );
cdek_tariff_assert( 350.5 === $pickup_rate->price->get_rubles(), 'CDEK delivery_sum must be mapped as rubles.' );
cdek_tariff_assert( '2-4 дня' === (string) $pickup_rate->meta['api_delivery_days_text'], 'CDEK period must be mapped to delivery days text.' );
cdek_tariff_assert( DeliveryType::PICKUP === $pickup_rate->delivery_type, 'delivery_mode 1 must be pickup.' );
cdek_tariff_assert( false === $pickup_rate->requires_pickup_point, 'CDEK pickup point selection is intentionally not required yet.' );

$courier_quote = $carrier->quote( cdek_tariff_request( DeliveryType::COURIER ) );
cdek_tariff_assert( 1 === count( $courier_quote->rates ), 'Courier CDEK tariff must be mapped to one rate.' );
cdek_tariff_assert( DeliveryType::COURIER === $courier_quote->rates[0]->delivery_type, 'delivery_mode 2 must be courier.' );
cdek_tariff_assert( '137' === $courier_quote->rates[0]->tariff_key, 'Unknown delivery mode must be skipped.' );

$tariff_request = array_values( array_filter( $http->requests, static fn( array $request ): bool => str_contains( $request['url'], '/v2/calculator/tarifflist' ) ) )[0];
cdek_tariff_assert( 'POST' === $tariff_request['method'], 'CDEK tarifflist must use POST.' );
cdek_tariff_assert( str_contains( $tariff_request['url'], '/v2/calculator/tarifflist' ), 'CDEK tarifflist endpoint mismatch.' );
cdek_tariff_assert( 'Bearer runtime-token' === ( $tariff_request['args']['headers']['Authorization'] ?? '' ), 'CDEK tarifflist must send bearer token.' );
$payload = json_decode( (string) $tariff_request['args']['body'], true );
cdek_tariff_assert( 270 === (int) ( $payload['from_location']['code'] ?? 0 ), 'CDEK from_location.code mismatch.' );
cdek_tariff_assert( 270 === (int) ( $payload['to_location']['code'] ?? 0 ), 'CDEK to_location.code mismatch.' );
cdek_tariff_assert( 1000 === (int) ( $payload['packages'][0]['weight'] ?? 0 ), 'CDEK package weight mismatch.' );
cdek_tariff_assert( 12 === (int) ( $payload['packages'][0]['length'] ?? 0 ) && 8 === (int) ( $payload['packages'][0]['width'] ?? 0 ) && 4 === (int) ( $payload['packages'][0]['height'] ?? 0 ), 'CDEK package dimensions mismatch.' );

[ $service_registry, $service_manager ] = cdek_tariff_service_runtime( $carrier, false );
$disabled_result = cdek_tariff_orchestrator( $carrier, $service_registry, $service_manager )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, false );
cdek_tariff_assert( array() === array_values( array_filter( $disabled_result->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) ), 'Disabled CDEK service must not produce rates.' );

$missing_http = new CdekTariffFakeHttpClient();
[ , , $missing_carrier ] = cdek_tariff_settings( $missing_http, false );
[ $enabled_registry, $enabled_manager ] = cdek_tariff_service_runtime( $missing_carrier, true );
$missing_result = cdek_tariff_orchestrator( $missing_carrier, $enabled_registry, $enabled_manager )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, false );
cdek_tariff_assert( array() === array_values( array_filter( $missing_result->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) ), 'Missing CDEK credentials must not produce rates or fatal.' );

$http = new CdekTariffFakeHttpClient();
[ $settings, $client, $carrier ] = cdek_tariff_settings( $http, true );
[ $enabled_registry, $enabled_manager ] = cdek_tariff_service_runtime( $carrier, true );
$success_result = cdek_tariff_orchestrator( $carrier, $enabled_registry, $enabled_manager )->calculate( cdek_tariff_request(), array(), RateSorter::CHEAPEST, false );
$cdek_rates = array_values( array_filter( $success_result->rates, static fn( DeliveryRate $rate ): bool => CdekCarrier::KEY === $rate->carrier_key ) );
cdek_tariff_assert( 2 === count( $cdek_rates ), 'Enabled CDEK service must produce pickup and courier runtime rates.' );

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
cdek_tariff_assert( count( array_filter( $preview['rates'], static fn( array $rate ): bool => CdekCarrier::KEY === (string) ( $rate['carrier_key'] ?? '' ) ) ) >= 1, 'CDEK rates must appear in admin recalculation preview.' );

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
$error_log = end( $GLOBALS['wdc_cdek_tariff_logs'] );
cdek_tariff_assert( is_array( $error_log ) && 'CDEK tarifflist failed.' === (string) ( $error_log['message'] ?? '' ), 'CDEK tarifflist failure must be written to WooCommerce log.' );
cdek_tariff_assert( 400 === (int) ( $error_log['context']['http_code'] ?? 0 ), 'CDEK tarifflist log must include HTTP status code.' );
cdek_tariff_assert( DeliveryType::PICKUP === (string) ( $error_log['context']['delivery_type'] ?? '' ), 'CDEK tarifflist log must include delivery type.' );
$serialized_error_debug = json_encode( array( $details, $error_log ), JSON_UNESCAPED_UNICODE );
cdek_tariff_assert( is_string( $serialized_error_debug ) && ! str_contains( $serialized_error_debug, 'runtime-token' ) && ! str_contains( $serialized_error_debug, 'secure-password' ) && ! str_contains( $serialized_error_debug, 'account-id' ), 'CDEK tarifflist diagnostics must not expose token, secret, or account.' );

echo "CDEK tariff calculation smoke test passed.\n";
