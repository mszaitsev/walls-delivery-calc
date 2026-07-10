<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'yandex-pricing-smoke-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Runtime\YandexDeliveryCarrier;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingRequestBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingResponseParser;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingParcel;
use WallsShop\WDC\Packaging\PackagingResult;

function yandex_pricing_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function current_time( string $type ): string { return '2026-06-29 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }

final class YandexPricingFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @param array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> $responses */
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		$response = array_shift( $this->responses ) ?? new YandexDeliveryApiResponse( 200, '{"pricing_total":"100 RUB"}' );
		if ( $response instanceof YandexDeliveryApiException ) {
			throw $response;
		}
		return $response;
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $yandex_location_mapping_v2 = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_pickup_points_v2 = array();
		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[dsf]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
	}
}

function yandex_pricing_request( array $context = array(), ?Package $package = null, ?Money $total = null, ?Address $address = null ): QuoteRequest {
	$total ??= Money::from_rubles( 1000 );
	$item = new PackageItem( 'SKU', 'Item', 1, $total, $total, 0, 0, 0, 0 );
	$package ??= Package::from_items( array( $item ), 0, $total, $total );
	$address ??= new Address( country_code: 'RU', city: 'Москва', street: 'Волжский бульвар', house: 'д 1 к1', raw_address: 'Москва, Волжский бульвар, д 1 к1' );

	return new QuoteRequest( 'RU', $address, $package, 'card', $total, '2026-06-29', array_merge( array( 'selected_location_id' => 10 ), $context ) );
}

/** @param array<string,mixed> $request @return array<string,mixed> */
function yandex_pricing_http_payload( array $request ): array {
	$decoded = json_decode( (string) ( $request['args']['body'] ?? '{}' ), true );

	return is_array( $decoded ) ? $decoded : array();
}

/** @return array<string,\WallsShop\WDC\Domain\Quote\DeliveryRate> */
function yandex_pricing_rates_by_id( mixed $quote ): array {
	return array_combine( array_map( static fn( $rate ): string => $rate->rate_id, $quote->rates ), $quote->rates );
}

$GLOBALS['wdc_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_location_mapping_v2 = array(
	array( 'location_id' => 10, 'yandex_geo_id' => 65, 'status' => 'mapped', 'is_primary' => 1 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 66, 'status' => 'manual', 'is_primary' => 0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 66, 'status' => 'mapped', 'is_primary' => 0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 999, 'status' => 'needs_review', 'is_primary' => 0 ),
);
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => '', 'name' => 'Без id', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1, 'available_for_dropoff' => 0 ),
	array( 'platform_station_id' => 'MSK-5POST', 'name' => 'ПВЗ 5post', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => '5post', 'yandex_geo_id' => 65, 'active' => 1, 'available_for_dropoff' => 0 ),
	array( 'platform_station_id' => 'MSK-MARKET', 'name' => 'ПВЗ Маркет', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 66, 'active' => 1, 'available_for_dropoff' => 0 ),
	array( 'platform_station_id' => 'MSK-INACTIVE', 'name' => 'Неактивный', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 66, 'active' => 0, 'available_for_dropoff' => 1 ),
	array( 'platform_station_id' => 'SPB-OTHER', 'name' => 'Другой geo', 'locality' => 'СПб', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 213, 'active' => 1, 'available_for_dropoff' => 1 ),
);

$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array(
	YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST,
	'yandex_delivery_test_bearer_token' => 'pricing-token',
	YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'diagnostic-station',
) );
$GLOBALS['wdc_options']['wdc_core_settings'][ YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ] = 'SRC-1';

$builder = new YandexDeliveryPricingRequestBuilder( new PackagingBuilder( PackagingBuilderConfig::defaults() ) );
$package = new Package( array(), Money::from_kopecks( 0 ), Money::from_kopecks( 0 ), 0, 0, 0, null, null, null, null, 'manual' );
$pickup_payload = $builder->pickup( yandex_pricing_request( array(), $package, Money::from_kopecks( 100000 ) ), 'SRC-1', 'DST-1' );
yandex_pricing_assert( 'self_pickup' === $pickup_payload['tariff'] && 'SRC-1' === $pickup_payload['source']['platform_station_id'] && 'DST-1' === $pickup_payload['destination']['platform_station_id'], 'Pickup request must include self_pickup source and destination station ids.' );
yandex_pricing_assert( 500 === $pickup_payload['total_weight'] && 100000 === $pickup_payload['total_assessed_price'] && 0 === $pickup_payload['client_price'] && 'already_paid' === $pickup_payload['payment_method'], 'Pickup request must include default weight, assessed price, client price and payment method.' );
yandex_pricing_assert( 1 === count( $pickup_payload['places'] ) && array( 'weight_gross' => 500, 'dx' => 20.0, 'dy' => 15.0, 'dz' => 10.0 ) === $pickup_payload['places'][0]['physical_dims'], 'Pickup request must include one PackagingResult place with generic default physical dims.' );
yandex_pricing_assert( $pickup_payload['total_weight'] === $pickup_payload['places'][0]['physical_dims']['weight_gross'], 'Request total_weight must match place gross weight.' );
yandex_pricing_assert( 'defaults' === (string) ( $builder->last_diagnostics()['package_builder_source'] ?? '' ) && 1 === (int) ( $builder->last_diagnostics()['places_count'] ?? 0 ), 'Request builder must expose safe PackagingResult diagnostics.' );

$multi_total = Money::from_rubles( 1500 );
$multi_package = Package::from_items(
	array(
		new PackageItem( 'LONG-1', 'Long item 1', 1, Money::from_rubles( 700 ), Money::from_rubles( 700 ), 300, 60, 5, 5 ),
		new PackageItem( 'LONG-2', 'Long item 2', 1, Money::from_rubles( 800 ), Money::from_rubles( 800 ), 400, 70, 6, 6 ),
	),
	0,
	$multi_total,
	$multi_total
);
$multi_payload = $builder->pickup( yandex_pricing_request( array(), $multi_package, $multi_total ), 'SRC-1', 'DST-1' );
yandex_pricing_assert( 2 === count( $multi_payload['places'] ), 'Multiple PackagingParcel objects must become multiple Yandex places.' );
yandex_pricing_assert( 700 === $multi_payload['total_weight'] && 700 === array_sum( array_map( static fn( array $place ): int => (int) $place['physical_dims']['weight_gross'], $multi_payload['places'] ) ), 'Yandex total_weight must equal the sum of all place weight_gross values.' );
yandex_pricing_assert( array( 'weight_gross' => 300, 'dx' => 60.0, 'dy' => 5.0, 'dz' => 5.0 ) === $multi_payload['places'][0]['physical_dims'], 'Yandex places must use PackagingParcel dimensions without DPD defaults.' );
yandex_pricing_assert( 'long_items_only' === (string) ( $builder->last_diagnostics()['package_builder_source'] ?? '' ) && 2 === (int) ( $builder->last_diagnostics()['parcels_count'] ?? 0 ), 'Yandex diagnostics must expose packaging source and parcels count.' );

$places_method = new ReflectionMethod( YandexDeliveryPricingRequestBuilder::class, 'places_from_packaging_result' );
$places_method->setAccessible( true );
$quantity_places = $places_method->invoke( $builder, new PackagingResult( array( new PackagingParcel( 250, 12.0, 11.0, 10.0, 3 ) ), array( 'package_builder_source' => 'test', 'packing_strategy' => 'test', 'parcels_count' => 1 ) ) );
yandex_pricing_assert( is_array( $quantity_places ) && 3 === count( $quantity_places ) && 750 === array_sum( array_map( static fn( array $place ): int => (int) $place['physical_dims']['weight_gross'], $quantity_places ) ), 'PackagingParcel quantity must be expanded into repeated Yandex places.' );
yandex_pricing_assert( array() === $places_method->invoke( $builder, new PackagingResult( array(), array() ) ), 'Empty PackagingResult must be treated as invalid for public Yandex places.' );
$fallback_method = new ReflectionMethod( YandexDeliveryPricingRequestBuilder::class, 'fallback_package_payload' );
$fallback_method->setAccessible( true );
$fallback_payload = $fallback_method->invoke( $builder, yandex_pricing_request( array(), new Package( array(), Money::from_kopecks( 0 ), Money::from_kopecks( 0 ), 0, 0, 0, null, null, null, null, 'manual' ), Money::from_kopecks( 100000 ) ) );
yandex_pricing_assert( 500 === (int) $fallback_payload['total_weight'] && array( 'weight_gross' => 500, 'dx' => 20.0, 'dy' => 15.0, 'dz' => 10.0 ) === $fallback_payload['places'][0]['physical_dims'], 'Legacy single-place fallback must keep generic PackagingBuilder defaults for Yandex.' );
$courier_payload = $builder->courier( yandex_pricing_request(), 'SRC-1', 'Москва, Волжский бульвар, д 1 к1' );
yandex_pricing_assert( 'time_interval' === $courier_payload['tariff'] && 'Москва, Волжский бульвар, д 1 к1' === $courier_payload['destination']['address'], 'Courier request must include time_interval and destination address.' );

$mapping = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
yandex_pricing_assert( array( 65, 66 ) === $mapping->geo_ids_for_location( 10 ), 'Mapping must return all mapped/manual geo ids without duplicates.' );
$pickup_repo = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
yandex_pricing_assert( 'MSK-MARKET' === (string) ( $pickup_repo->representative_destination_pickup_point_by_geo_ids( array( 66, 65, 66 ) )['platform_station_id'] ?? '' ), 'Representative PVZ must prefer pickup_point + market_l4g across all geo ids and ignore empty station ids/dropoff flag.' );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'P5', 'name' => '5post', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => '5post', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'T-MARKET', 'name' => 'Terminal market', 'locality' => 'Москва', 'type' => 'terminal', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
yandex_pricing_assert( 'P5' === (string) ( $pickup_repo->representative_destination_pickup_point_by_geo_ids( array( 65 ) )['platform_station_id'] ?? '' ), 'Representative PVZ fallback must prefer pickup_point + 5post before terminals.' );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'T-MARKET', 'name' => 'Terminal market', 'locality' => 'Москва', 'type' => 'terminal', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'T-5POST', 'name' => 'Terminal 5post', 'locality' => 'Москва', 'type' => 'terminal', 'operator_id' => '5post', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'T-OTHER', 'name' => 'Terminal other', 'locality' => 'Москва', 'type' => 'terminal', 'operator_id' => 'other', 'yandex_geo_id' => 65, 'active' => 1 ),
);
yandex_pricing_assert( 'T-MARKET' === (string) ( $pickup_repo->representative_destination_pickup_point_by_geo_ids( array( 65 ) )['platform_station_id'] ?? '' ), 'Representative PVZ fallback must prefer terminal + market_l4g.' );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[0]['active'] = 0;
yandex_pricing_assert( 'T-5POST' === (string) ( $pickup_repo->representative_destination_pickup_point_by_geo_ids( array( 65 ) )['platform_station_id'] ?? '' ), 'Representative PVZ fallback must prefer terminal + 5post after market_l4g.' );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[1]['active'] = 0;
yandex_pricing_assert( 'T-OTHER' === (string) ( $pickup_repo->representative_destination_pickup_point_by_geo_ids( array( 65 ) )['platform_station_id'] ?? '' ), 'Representative PVZ fallback must use any terminal last.' );

$parser = new YandexDeliveryPricingResponseParser();
$parsed = $parser->parse( array( 'body' => array( 'pricing_total' => '237.9 RUB', 'delivery_days' => 7 ) ) );
yandex_pricing_assert( 23790 === $parsed->price_kopecks && '7 дней' === $parsed->delivery_time_label(), 'Parser must parse decimal RUB and delivery_days.' );
yandex_pricing_assert( 23700 === $parser->parse( array( 'pricing_total' => '237 RUB' ) )->price_kopecks, 'Parser must parse integer RUB.' );
yandex_pricing_assert( '1 день' === $parser->parse( array( 'pricing_total' => '237 RUB', 'delivery_days' => 1 ) )->delivery_time_label(), 'Parser must format 1 day.' );
yandex_pricing_assert( '2 дня' === $parser->parse( array( 'pricing_total' => '237 RUB', 'delivery_days' => 2 ) )->delivery_time_label(), 'Parser must format 2 days.' );
yandex_pricing_assert( '5 дней' === $parser->parse( array( 'pricing_total' => '237 RUB', 'delivery_days' => 5 ) )->delivery_time_label(), 'Parser must format 5 days.' );
yandex_pricing_assert( '21 день' === $parser->parse( array( 'pricing_total' => '237 RUB', 'delivery_days' => 21 ) )->delivery_time_label(), 'Parser must format 21 days.' );
yandex_pricing_assert( '22 дня' === $parser->parse( array( 'pricing_total' => '237 RUB', 'delivery_days' => 22 ) )->delivery_time_label(), 'Parser must format 22 days.' );
yandex_pricing_assert( '25 дней' === $parser->parse( array( 'pricing_total' => '237 RUB', 'delivery_days' => 25 ) )->delivery_time_label(), 'Parser must format 25 days.' );
yandex_pricing_assert( 'без указания срока' === $parser->parse( array( 'pricing_total' => '237,5 RUB' ) )->delivery_time_label(), 'Parser must fallback missing delivery_days.' );

$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'MSK-MARKET', 'name' => 'ПВЗ Маркет', 'locality' => 'Москва', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 66, 'active' => 1 ),
);
$http = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"237.9 RUB","delivery_days":7}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"463.6 RUB","delivery_days":9}' ),
) );
$carrier = new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $http ), $mapping, $pickup_repo, null, $builder, $parser );
$quote = $carrier->quote( yandex_pricing_request() );
$rates = array_combine( array_map( static fn( $rate ): string => $rate->rate_id, $quote->rates ), $quote->rates );
yandex_pricing_assert( 23790 === $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->price->get_kopecks() && 'Яндекс до ПВЗ - 7 дней' === $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->title, 'Pickup checkout rate must use pricing response price and delivery days.' );
yandex_pricing_assert( 46360 === $rates[YandexDeliveryCarrier::COURIER_RATE_ID]->price->get_kopecks() && 'Яндекс до двери - 9 дней' === $rates[YandexDeliveryCarrier::COURIER_RATE_ID]->title, 'Courier checkout rate must use pricing response price and delivery days.' );
yandex_pricing_assert( 'checkout_address' === (string) ( $rates[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['courier_pricing_source'] ?? '' ) && false === ( $rates[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['courier_fallback_used'] ?? null ), 'Successful primary courier pricing must mark checkout_address and skip fallback.' );
yandex_pricing_assert( isset( $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['package'], $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['package_builder_source'] ) && (int) $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['places_count'] >= 1, 'Yandex checkout rate meta must include safe package diagnostics.' );
yandex_pricing_assert( isset( $http->requests[0], $http->requests[1] ), 'Pricing client must call API once per Yandex tariff.' );
$first_payload = json_decode( (string) $http->requests[0]['args']['body'], true );
yandex_pricing_assert( 'self_pickup' === (string) ( $first_payload['tariff'] ?? '' ) && 'MSK-MARKET' === (string) ( $first_payload['destination']['platform_station_id'] ?? '' ), 'Pickup checkout pricing payload must use representative destination platform station id.' );
$second_payload = json_decode( (string) $http->requests[1]['args']['body'], true );
yandex_pricing_assert( 'time_interval' === (string) ( $second_payload['tariff'] ?? '' ) && isset( $second_payload['destination']['address'] ), 'Courier checkout pricing payload must use destination address.' );

$GLOBALS['wdc_options']['wdc_core_settings'][ YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ] = '';
$missing_source_quote = $carrier->quote( yandex_pricing_request() );
yandex_pricing_assert( $missing_source_quote->rates[0]->disabled && str_contains( $missing_source_quote->rates[0]->disabled_reason, 'Не выбран ПВЗ сдачи' ), 'Missing source station must disable Yandex rate without fatal.' );
$GLOBALS['wdc_options']['wdc_core_settings'][ YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ] = 'SRC-1';
$error_pickup = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiResponse( 500, '{"message":"pickup failed"}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"463.6 RUB","delivery_days":9}' ),
) );
$error_quote = ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $error_pickup ), $mapping, $pickup_repo, null, $builder, $parser ) )->quote( yandex_pricing_request() );
yandex_pricing_assert( $error_quote->rates[0]->disabled && ! $error_quote->rates[1]->disabled && 46360 === $error_quote->rates[1]->price->get_kopecks(), 'Pickup API error must not break courier pricing.' );
$error_courier = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"237.9 RUB","delivery_days":7}' ),
	new YandexDeliveryApiResponse( 500, '{"message":"courier failed"}' ),
) );
$error_quote = ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $error_courier ), $mapping, $pickup_repo, null, $builder, $parser ) )->quote( yandex_pricing_request() );
yandex_pricing_assert( ! $error_quote->rates[0]->disabled && $error_quote->rates[1]->disabled && 23790 === $error_quote->rates[0]->price->get_kopecks(), 'Courier API error must not break pickup pricing.' );

// Empty checkout address: skip primary API and use representative PVZ address assembled from locality/street/house.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-COMPONENTS', 'name' => 'Representative', 'locality' => 'Москва', 'street' => 'Тестовая', 'house' => '5', 'full_address' => '', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$empty_address_http = new YandexPricingFakeHttp( array( new YandexDeliveryApiResponse( 200, '{"pricing_total":"321 RUB","delivery_days":4}' ) ) );
$empty_address_carrier = new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $empty_address_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser );
$empty_address_request = yandex_pricing_request( array( 'delivery_type' => DeliveryType::COURIER ), address: new Address( country_code: 'RU', city: 'Москва' ) );
$empty_address_quote = $empty_address_carrier->quote( $empty_address_request );
$empty_address_rate = $empty_address_quote->rates[0];
$empty_address_payload = yandex_pricing_http_payload( $empty_address_http->requests[0] ?? array() );
yandex_pricing_assert( 1 === count( $empty_address_http->requests ) && ! $empty_address_rate->disabled && 32100 === $empty_address_rate->price->get_kopecks(), 'Empty checkout address must skip primary API and return an active courier rate from one fallback request.' );
yandex_pricing_assert( 'Москва, Тестовая, 5' === (string) ( $empty_address_payload['destination']['address'] ?? '' ) && 'pickup_address_fallback' === (string) ( $empty_address_rate->meta['courier_pricing_source'] ?? '' ) && true === ( $empty_address_rate->meta['courier_fallback_used'] ?? null ), 'Courier fallback must assemble the representative PVZ address and mark fallback diagnostics.' );
yandex_pricing_assert( '' === $empty_address_request->destination->raw_address && '' === $empty_address_request->destination->street && '' === $empty_address_request->destination->house && ! str_contains( json_encode( $empty_address_rate->meta ) ?: '', 'Тестовая' ), 'Courier fallback must not mutate QuoteRequest destination or persist the PVZ address in rate meta.' );

// Primary API failure: retry once with the same representative pickup destination.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-FULL', 'name' => 'Representative full', 'locality' => 'Москва', 'full_address' => 'Москва, Резервная улица, 10', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$primary_error_http = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiException( 'address not recognized', array( 'error_code' => 'address_not_recognized' ) ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"345 RUB","delivery_days":5}' ),
) );
$primary_error_rate = ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $primary_error_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser ) )->quote( yandex_pricing_request( array( 'delivery_type' => DeliveryType::COURIER ) ) )->rates[0];
$primary_payload = yandex_pricing_http_payload( $primary_error_http->requests[0] ?? array() );
$primary_fallback_payload = yandex_pricing_http_payload( $primary_error_http->requests[1] ?? array() );
yandex_pricing_assert( 2 === count( $primary_error_http->requests ) && ! $primary_error_rate->disabled && 34500 === $primary_error_rate->price->get_kopecks(), 'Unrecognized checkout address must retry exactly once and use fallback response price.' );
yandex_pricing_assert( 'Москва, Волжский бульвар, д 1 к1' === (string) ( $primary_payload['destination']['address'] ?? '' ) && 'Москва, Резервная улица, 10' === (string) ( $primary_fallback_payload['destination']['address'] ?? '' ), 'Primary and fallback courier requests must use checkout and PVZ addresses respectively.' );
yandex_pricing_assert( 'address_not_recognized' === (string) ( $primary_error_rate->meta['courier_primary_error_code'] ?? '' ) && 'representative' === (string) ( $primary_error_rate->meta['courier_fallback_pickup_source'] ?? '' ) && 'REP-FULL' === (string) ( $primary_error_rate->meta['courier_fallback_platform_station_id'] ?? '' ), 'Fallback diagnostics must preserve primary error and representative station identity.' );
yandex_pricing_assert( ! str_contains( json_encode( $primary_error_rate->meta ) ?: '', 'Резервная улица' ), 'Courier fallback diagnostics must not store the PVZ address.' );

// Family-specific selected PVZ must be shared by pickup pricing and courier fallback.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-FULL', 'name' => 'Representative full', 'locality' => 'Москва', 'full_address' => 'Москва, Резервная улица, 10', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'SELECTED-FULL', 'name' => 'Selected full', 'locality' => 'Москва', 'full_address' => 'Москва, Выбранная улица, 20', 'type' => 'terminal', 'operator_id' => '5post', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$selected_context = array(
	'pickup_selections' => array(
		YandexDeliverySettings::CARRIER_KEY . ':pickup' => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':pickup', 'platform_station_id' => 'SELECTED-FULL' ),
	),
);
$selected_http = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"210 RUB","delivery_days":3}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"360 RUB","delivery_days":6}' ),
) );
$selected_rates = yandex_pricing_rates_by_id( ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $selected_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser ) )->quote( yandex_pricing_request( $selected_context, address: new Address( country_code: 'RU', city: 'Москва' ) ) ) );
$selected_pickup_payload = yandex_pricing_http_payload( $selected_http->requests[0] ?? array() );
$selected_courier_payload = yandex_pricing_http_payload( $selected_http->requests[1] ?? array() );
yandex_pricing_assert( 'SELECTED-FULL' === (string) ( $selected_pickup_payload['destination']['platform_station_id'] ?? '' ) && 'Москва, Выбранная улица, 20' === (string) ( $selected_courier_payload['destination']['address'] ?? '' ), 'Pickup pricing and courier fallback must use the same family-specific selected PVZ.' );
yandex_pricing_assert( 'selected' === (string) ( $selected_rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['pickup_source'] ?? '' ) && 'selected' === (string) ( $selected_rates[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['courier_fallback_pickup_source'] ?? '' ) && 'SELECTED-FULL' === (string) ( $selected_rates[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['courier_fallback_platform_station_id'] ?? '' ), 'Selected PVZ must have priority in both pickup and courier fallback diagnostics.' );

// Without selection, pickup pricing and courier fallback must share the representative PVZ.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-SHARED', 'name' => 'Representative shared', 'locality' => 'Москва', 'full_address' => 'Москва, Общая улица, 30', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$representative_http = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"220 RUB","delivery_days":3}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"370 RUB","delivery_days":6}' ),
) );
$representative_rates = yandex_pricing_rates_by_id( ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $representative_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser ) )->quote( yandex_pricing_request( address: new Address( country_code: 'RU', city: 'Москва' ) ) ) );
$representative_pickup_payload = yandex_pricing_http_payload( $representative_http->requests[0] ?? array() );
$representative_courier_payload = yandex_pricing_http_payload( $representative_http->requests[1] ?? array() );
yandex_pricing_assert( 'REP-SHARED' === (string) ( $representative_pickup_payload['destination']['platform_station_id'] ?? '' ) && 'Москва, Общая улица, 30' === (string) ( $representative_courier_payload['destination']['address'] ?? '' ), 'Pickup pricing and courier fallback must use the same representative PVZ when selection is absent.' );
yandex_pricing_assert( 'representative' === (string) ( $representative_rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->meta['pickup_source'] ?? '' ) && 'representative' === (string) ( $representative_rates[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['courier_fallback_pickup_source'] ?? '' ), 'Representative source must be reported consistently for pickup and courier fallback.' );

// Missing PVZ address: no fallback API request, courier disabled, pickup remains active.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-NO-ADDRESS', 'name' => 'No address', 'locality' => 'Москва', 'full_address' => '', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$no_address_http = new YandexPricingFakeHttp( array( new YandexDeliveryApiResponse( 200, '{"pricing_total":"230 RUB","delivery_days":3}' ) ) );
$no_address_rates = yandex_pricing_rates_by_id( ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $no_address_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser ) )->quote( yandex_pricing_request( address: new Address( country_code: 'RU', city: 'Москва' ) ) ) );
yandex_pricing_assert( 1 === count( $no_address_http->requests ) && ! $no_address_rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->disabled && $no_address_rates[YandexDeliveryCarrier::COURIER_RATE_ID]->disabled, 'Missing fallback PVZ address must skip courier fallback API without breaking pickup pricing.' );
yandex_pricing_assert( 'courier_fallback_pickup_address_missing' === (string) ( $no_address_rates[YandexDeliveryCarrier::COURIER_RATE_ID]->meta['courier_fallback_error_code'] ?? '' ), 'Disabled courier diagnostics must identify missing fallback PVZ address.' );

// Both primary and fallback API calls fail: exactly two calls and disabled courier.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-FAIL', 'name' => 'Fail', 'locality' => 'Москва', 'full_address' => 'Москва, Ошибочная улица, 40', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$double_error_http = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiException( 'primary failed', array( 'error_code' => 'primary_failed' ) ),
	new YandexDeliveryApiException( 'fallback failed', array( 'error_code' => 'fallback_failed' ) ),
) );
$double_error_rate = ( new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $double_error_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser ) )->quote( yandex_pricing_request( array( 'delivery_type' => DeliveryType::COURIER ) ) )->rates[0];
yandex_pricing_assert( 2 === count( $double_error_http->requests ) && $double_error_rate->disabled, 'Primary and fallback failures must perform at most two API calls and disable courier.' );
yandex_pricing_assert( 'primary_failed' === (string) ( $double_error_rate->meta['courier_primary_error_code'] ?? '' ) && 'fallback_failed' === (string) ( $double_error_rate->meta['courier_fallback_error_code'] ?? '' ) && 'courier_pricing_fallback_failed' === (string) ( $double_error_rate->meta['pricing_error'] ?? '' ), 'Disabled courier diagnostics must preserve both error codes and final fallback failure.' );

// Corrected checkout address must replace prior fallback pricing on the next quote.
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'REP-REFRESH', 'name' => 'Refresh', 'locality' => 'Москва', 'full_address' => 'Москва, Предварительная улица, 50', 'type' => 'pickup_point', 'operator_id' => 'market_l4g', 'yandex_geo_id' => 65, 'active' => 1 ),
);
$refresh_http = new YandexPricingFakeHttp( array(
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"380 RUB","delivery_days":6}' ),
	new YandexDeliveryApiResponse( 200, '{"pricing_total":"440 RUB","delivery_days":4}' ),
) );
$refresh_carrier = new YandexDeliveryCarrier( $settings, new YandexDeliveryApiClient( $settings, $refresh_http ), $mapping, $pickup_repo, null, new YandexDeliveryPricingRequestBuilder(), $parser );
$refresh_fallback_rate = $refresh_carrier->quote( yandex_pricing_request( array( 'delivery_type' => DeliveryType::COURIER ), address: new Address( country_code: 'RU', city: 'Москва' ) ) )->rates[0];
$refresh_primary_rate = $refresh_carrier->quote( yandex_pricing_request( array( 'delivery_type' => DeliveryType::COURIER ) ) )->rates[0];
$refresh_primary_payload = yandex_pricing_http_payload( $refresh_http->requests[1] ?? array() );
yandex_pricing_assert( 2 === count( $refresh_http->requests ) && 38000 === $refresh_fallback_rate->price->get_kopecks() && 44000 === $refresh_primary_rate->price->get_kopecks(), 'Corrected checkout address must replace fallback pricing on the next quote without an extra fallback call.' );
yandex_pricing_assert( true === ( $refresh_fallback_rate->meta['courier_fallback_used'] ?? null ) && 'checkout_address' === (string) ( $refresh_primary_rate->meta['courier_pricing_source'] ?? '' ) && false === ( $refresh_primary_rate->meta['courier_fallback_used'] ?? null ), 'Corrected address must reset courier pricing source to checkout_address.' );
yandex_pricing_assert( 'Москва, Волжский бульвар, д 1 к1' === (string) ( $refresh_primary_payload['destination']['address'] ?? '' ), 'Corrected courier request must use the real checkout address.' );

yandex_pricing_assert( YandexDeliveryCarrier::PICKUP_RATE_ID === $quote->rates[0]->rate_id && YandexDeliveryCarrier::COURIER_RATE_ID === $quote->rates[1]->rate_id, 'Yandex checkout rate ids must stay unchanged.' );

$checkout_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/YandexDeliveryCarrier.php' );
yandex_pricing_assert( ! str_contains( $checkout_source, 'temporary_zero_price' ) && ! str_contains( $checkout_source, 'temporary_checkout_rates' ), 'Yandex checkout must not keep temporary zero-price flags.' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
yandex_pricing_assert( str_contains( $plugin_source, 'YandexDeliveryPricingRequestBuilder::class' ) && str_contains( $plugin_source, 'new YandexDeliveryPricingRequestBuilder( $this->container->get( PackagingBuilder::class ) )' ) && str_contains( $plugin_source, 'YandexDeliveryPricingResponseParser::class' ), 'Yandex pricing services must be registered in DI with the generic PackagingBuilder.' );
$pricing_builder_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pricing/YandexDeliveryPricingRequestBuilder.php' );
yandex_pricing_assert( ! str_contains( $pricing_builder_source, 'DpdPackagingBuilderFactory' ) && ! str_contains( $pricing_builder_source, 'DpdSettings' ) && ! str_contains( $pricing_builder_source, 'Carriers\\Dpd' ), 'Yandex pricing request builder must not depend on DPD legacy packaging.' );

echo "Yandex Delivery pricing calculator smoke OK\n";
