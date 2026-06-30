<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\YandexDeliveryCarrier;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingRequestBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingResponseParser;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'yandex-checkout-rates-smoke-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function yandex_checkout_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-29 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }

final class YandexCheckoutRatesFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @param array<int,YandexDeliveryApiResponse> $responses */
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );

		return array_shift( $this->responses ) ?? new YandexDeliveryApiResponse( 200, '{"pricing_total":"100 RUB","delivery_days":1}' );
	}
}


if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $services = array();
		public array $settings = array();
		public array $countries = array();
		public array $rules = array();
		public array $yandex_location_mapping_v2 = array();
		public array $yandex_delivery_pickup_points_v2 = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$rows =& $this->rows_for_table( $table );
			$rows[] = $data;
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
			$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $where ): bool {
				foreach ( $where as $key => $value ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
						return true;
					}
				}
				return false;
			} ) );
			return true;
		}

		public function replace( string $table, array $data, array $format = array() ): bool {
			$rows =& $this->rows_for_table( $table );
			foreach ( $rows as $index => $row ) {
				if ( str_contains( $table, 'wdc_delivery_service_countries' ) && (int) ( $row['service_id'] ?? 0 ) === (int) ( $data['service_id'] ?? 0 ) && (string) ( $row['country_code'] ?? '' ) === (string) ( $data['country_code'] ?? '' ) ) {
					$rows[ $index ] = array_merge( $row, $data );
					return true;
				}
			}
			return $this->insert( $table, $data, $format );
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( str_contains( $query, 'ORDER BY deleted ASC' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( "/service_id = ([0-9]+).*setting_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) $row['service_id'] === (int) $matches[1] && (string) $row['setting_key'] === $matches[2] ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_var( string $query ): mixed {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( "/service_id = ([0-9]+).*setting_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) $row['service_id'] === (int) $matches[1] && (string) $row['setting_key'] === $matches[2] ) {
						return $row['id'];
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				$rows = array_values( array_filter( $this->services, static fn ( array $row ): bool => empty( $row['deleted'] ) ) );
				if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['service_key'] === $matches[1] ) );
				}
				return $rows;
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_filter( $this->settings, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) );
			}
			if ( str_contains( $query, 'wdc_rules' ) ) {
				return array();
			}
			return array();
		}

		public function get_col( string $query ): array {
			if ( preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_map( static fn ( array $row ): string => (string) $row['country_code'], array_filter( $this->countries, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) ) );
			}
			return array();
		}

		private function &rows_for_table( string $table ): array {
			if ( str_contains( $table, 'wdc_delivery_service_settings' ) ) {
				return $this->settings;
			}
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				return $this->countries;
			}
			if ( str_contains( $table, 'wdc_rules' ) ) {
				return $this->rules;
			}
			return $this->services;
		}
	}
}

function yandex_checkout_pricing_payload( array $request ): array {
	$body = $request['args']['body'] ?? '{}';
	$decoded = json_decode( (string) $body, true );

	return is_array( $decoded ) ? $decoded : array();
}

function yandex_checkout_request( array $context = array() ): QuoteRequest {
	$total = Money::from_rubles( 1000 );
	$item = new PackageItem( 'SKU', 'Item', 1, $total, $total, 1000, 10, 10, 10 );

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', street: 'Тверская', house: '1', raw_address: 'Москва, Тверская 1' ),
		Package::from_items( array( $item ), 0, $total, $total ),
		'card',
		$total,
		'2026-06-29',
		array_merge( array( 'selected_location_id' => 10 ), $context )
	);
}

$GLOBALS['wpdb'] = new wpdb();
$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$settings = new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$rules = new RuleRepository( $GLOBALS['wpdb'] );
$service = $services->ensure_yandex_delivery_service();
yandex_checkout_assert( null !== $service->id, 'Yandex Delivery service must be created.' );
$services->update_service( (int) $service->id, array( 'enabled' => 1 ) );
$countries->replace_countries( (int) $service->id, array( 'RU' ) );

$yandex_settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService(), $services, $settings );
$yandex_settings->save_from_admin( array(
	YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST,
	'yandex_delivery_test_bearer_token' => 'checkout-token',
	YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'diagnostic-station',
) );
$settings->set_setting( (int) $service->id, YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY, 'SRC-1', 'string' );
$GLOBALS['wpdb']->yandex_location_mapping_v2 = array(
	array( 'location_id' => 10, 'yandex_geo_id' => 65, 'status' => 'mapped', 'is_primary' => 1 ),
);
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'DST-1', 'name' => 'ПВЗ', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'DST-SELECTED', 'name' => 'Выбранный ПВЗ', 'locality' => 'Москва', 'type' => 'terminal', 'operator_id' => '5post', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$pricing_http = new YandexCheckoutRatesFakeHttp( array(
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"237.9 RUB","delivery_days":7}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"463.6 RUB","delivery_days":9}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"300 RUB","delivery_days":5}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"500 RUB","delivery_days":6}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"199 RUB","delivery_days":4}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"410 RUB","delivery_days":8}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"188 RUB","delivery_days":3}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"420 RUB","delivery_days":8}' ),
) );
$registry = new CarrierRegistry();
$registry->register( new YandexDeliveryCarrier( $yandex_settings, new YandexDeliveryApiClient( $yandex_settings, $pricing_http ), new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] ), new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] ), null, new YandexDeliveryPricingRequestBuilder(), new YandexDeliveryPricingResponseParser() ) );
$manager = new DeliveryServiceManager( $services, $countries, $rules, ( new ReflectionClass( RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor() );
$orchestrator = new CheckoutOrchestrator(
	$registry,
	new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
	new RateSorter(),
	new FallbackRateFactory(),
	new CarrierExecutionGuard( new CheckoutLogger() ),
	new CheckoutLogger(),
	null,
	new DeliveryServiceRegistry( $services, $registry ),
	$manager
);

$result = $orchestrator->calculate( yandex_checkout_request(), array(), RateSorter::CHEAPEST, false );
$rates = $result->rates;
yandex_checkout_assert( 2 === count( $rates ), 'Yandex Delivery checkout must expose pickup and courier rates.' );
$ids = array_map( static fn ( $rate ): string => $rate->rate_id, $rates );
yandex_checkout_assert( in_array( YandexDeliveryCarrier::PICKUP_RATE_ID, $ids, true ) && in_array( YandexDeliveryCarrier::COURIER_RATE_ID, $ids, true ), 'Yandex Delivery rates must have separate pickup and courier ids.' );
yandex_checkout_assert( count( array_unique( $ids ) ) === count( $ids ), 'Yandex Delivery rate ids must be unique.' );
$by_id = array_combine( $ids, $rates );
yandex_checkout_assert( 'Яндекс до ПВЗ — 7 дней' === $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID]->title, 'Yandex pickup title must use settings title and pricing delivery time.' );
yandex_checkout_assert( 'Яндекс до двери — 9 дней' === $by_id[YandexDeliveryCarrier::COURIER_RATE_ID]->title, 'Yandex courier title must use settings title and pricing delivery time.' );
$pickup_delivery_rate = $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID];
yandex_checkout_assert( DeliveryType::PICKUP === $pickup_delivery_rate->delivery_type && true === $pickup_delivery_rate->requires_pickup_point && YandexDeliverySettings::CARRIER_KEY === $pickup_delivery_rate->carrier_key && YandexDeliveryCarrier::PICKUP_RATE_ID === $pickup_delivery_rate->rate_id, 'Yandex pickup DeliveryRate must carry pickup type, pickup requirement, carrier key and rate id before WooCommerce mapping.' );
$mapped_yandex_pickup = ( new WooCommerceRateMapper() )->map( $pickup_delivery_rate );
yandex_checkout_assert( true === ( $mapped_yandex_pickup['meta_data']['requires_pickup_point'] ?? null ) && YandexDeliverySettings::CARRIER_KEY . ':pickup' === (string) ( $mapped_yandex_pickup['meta_data']['pickup_family'] ?? '' ) && DeliveryType::PICKUP === (string) ( $mapped_yandex_pickup['meta_data']['delivery_type'] ?? '' ) && YandexDeliverySettings::CARRIER_KEY === (string) ( $mapped_yandex_pickup['meta_data']['carrier_key'] ?? '' ) && YandexDeliveryCarrier::PICKUP_RATE_ID === (string) ( $mapped_yandex_pickup['meta_data']['rate_id'] ?? '' ), 'WooCommerceRateMapper must preserve Yandex pickup point meta for checkout rendering.' );
yandex_checkout_assert( 23790 === (int) ( $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['pricing_total_kopecks'] ?? 0 ) && 46360 === (int) ( $by_id[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['pricing_total_kopecks'] ?? 0 ), 'Yandex checkout rates must keep pricing-calculator prices in rate meta.' );
yandex_checkout_assert( 23800 === $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID]->price->get_kopecks() && 46400 === $by_id[YandexDeliveryCarrier::COURIER_RATE_ID]->price->get_kopecks(), 'Yandex checkout final prices must use regular delivery-service post-processing.' );
yandex_checkout_assert( 'representative' === (string) ( $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['pickup_source'] ?? '' ), 'Yandex pickup pricing must mark representative fallback before buyer selection.' );
yandex_checkout_assert( 'DST-1' === (string) ( $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['destination_platform_station_id'] ?? '' ), 'Yandex pickup pricing must use representative destination station before buyer selection.' );
$first_pickup_payload = yandex_checkout_pricing_payload( $pricing_http->requests[0] ?? array() );
yandex_checkout_assert( 'DST-1' === (string) ( $first_pickup_payload['destination']['platform_station_id'] ?? '' ), 'Yandex pricing payload must send representative destination station before selection.' );
foreach ( $rates as $rate ) {
	yandex_checkout_assert( str_contains( $rate->title, ' — ' ) && 1 === preg_match( '/— [0-9]+ (день|дня|дней)$/u', $rate->title ), 'Yandex rate title must always use "Название — срок" format with pricing delivery days.' );
}

$settings->set_setting( (int) $service->id, YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY, 'Самовывоз Яндекс', 'string' );
$settings->set_setting( (int) $service->id, YandexDeliverySettings::COURIER_METHOD_TITLE_KEY, 'Курьер Яндекс', 'string' );
$result = $orchestrator->calculate( yandex_checkout_request(), array(), RateSorter::CHEAPEST, false );
$ids = array_map( static fn ( $rate ): string => $rate->rate_id, $result->rates );
$by_id = array_combine( $ids, $result->rates );
yandex_checkout_assert( 'Самовывоз Яндекс — 5 дней' === $by_id[YandexDeliveryCarrier::PICKUP_RATE_ID]->title, 'Changed pickup title in settings must affect checkout.' );
yandex_checkout_assert( 'Курьер Яндекс — 6 дней' === $by_id[YandexDeliveryCarrier::COURIER_RATE_ID]->title, 'Changed courier title in settings must affect checkout.' );

$selected_request = yandex_checkout_request( array(
	'delivery_type' => DeliveryType::PICKUP,
	'pickup_selection' => array(
		'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
		'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':pickup',
		'point_code' => 'DST-SELECTED',
		'platform_station_id' => 'DST-SELECTED',
	),
) );
$selected_result = $orchestrator->calculate( $selected_request, array(), RateSorter::CHEAPEST, false );
$selected_rates_by_id = array_combine( array_map( static fn ( $rate ): string => $rate->rate_id, $selected_result->rates ), $selected_result->rates );
$selected_rate = $selected_rates_by_id[YandexDeliveryCarrier::PICKUP_RATE_ID] ?? null;
yandex_checkout_assert( null !== $selected_rate, 'Selected Yandex pickup calculation must return pickup rate.' );
yandex_checkout_assert( 'selected' === (string) ( $selected_rate->meta['pickup_source'] ?? '' ) && 'DST-SELECTED' === (string) ( $selected_rate->meta['destination_platform_station_id'] ?? '' ), 'Selected Yandex PVZ must have priority over representative station.' );
$selected_payload = yandex_checkout_pricing_payload( $pricing_http->requests[4] ?? array() );
yandex_checkout_assert( 'DST-SELECTED' === (string) ( $selected_payload['destination']['platform_station_id'] ?? '' ), 'Yandex pricing payload must send selected platform_station_id.' );


$family_selected_request = yandex_checkout_request( array(
	'delivery_type' => DeliveryType::PICKUP,
	'pickup_selection' => array(
		'carrier_key' => 'dpd',
		'pickup_family' => 'dpd:pickup',
		'point_code' => 'DPD-OTHER',
	),
	'pickup_selections' => array(
		YandexDeliverySettings::CARRIER_KEY . ':pickup' => array(
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':pickup',
			'snapshot' => array(
				'platform_station_id' => 'DST-FAMILY',
			),
		),
	),
) );
$family_selected_result = $orchestrator->calculate( $family_selected_request, array(), RateSorter::CHEAPEST, false );
$family_rates_by_id = array_combine( array_map( static fn ( $rate ): string => $rate->rate_id, $family_selected_result->rates ), $family_selected_result->rates );
$family_rate = $family_rates_by_id[YandexDeliveryCarrier::PICKUP_RATE_ID] ?? null;
yandex_checkout_assert( null !== $family_rate, 'Family-specific Yandex pickup calculation must return pickup rate.' );
yandex_checkout_assert( 'selected' === (string) ( $family_rate->meta['pickup_source'] ?? '' ) && 'DST-FAMILY' === (string) ( $family_rate->meta['destination_platform_station_id'] ?? '' ), 'Family-specific Yandex PVZ must win over global selection from another carrier.' );
$family_payload = yandex_checkout_pricing_payload( $pricing_http->requests[6] ?? array() );
yandex_checkout_assert( 'DST-FAMILY' === (string) ( $family_payload['destination']['platform_station_id'] ?? '' ), 'Yandex pricing payload must send family-specific selected platform_station_id.' );

echo "Yandex Delivery checkout rates smoke test passed.\n";
