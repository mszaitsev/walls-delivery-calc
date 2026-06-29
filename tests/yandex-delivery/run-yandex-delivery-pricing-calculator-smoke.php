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

$builder = new YandexDeliveryPricingRequestBuilder();
$package = new Package( array(), Money::from_kopecks( 0 ), Money::from_kopecks( 0 ), 0, 0, 0, null, null, null, null, 'manual' );
$pickup_payload = $builder->pickup( yandex_pricing_request( array(), $package, Money::from_kopecks( 100000 ) ), 'SRC-1', 'DST-1' );
yandex_pricing_assert( 'self_pickup' === $pickup_payload['tariff'] && 'SRC-1' === $pickup_payload['source']['platform_station_id'] && 'DST-1' === $pickup_payload['destination']['platform_station_id'], 'Pickup request must include self_pickup source and destination station ids.' );
yandex_pricing_assert( 500 === $pickup_payload['total_weight'] && 100000 === $pickup_payload['total_assessed_price'] && 0 === $pickup_payload['client_price'] && 'already_paid' === $pickup_payload['payment_method'], 'Pickup request must include default weight, assessed price, client price and payment method.' );
yandex_pricing_assert( array( 'weight_gross' => 500, 'dx' => 20, 'dy' => 15, 'dz' => 10 ) === $pickup_payload['places'][0]['physical_dims'], 'Pickup request must include default physical dims.' );
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
yandex_pricing_assert( 23790 === $parsed->price_kopecks && '7 дн.' === $parsed->delivery_time_label(), 'Parser must parse decimal RUB and delivery_days.' );
yandex_pricing_assert( 23700 === $parser->parse( array( 'pricing_total' => '237 RUB' ) )->price_kopecks, 'Parser must parse integer RUB.' );
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
yandex_pricing_assert( 23790 === $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->price->get_kopecks() && 'Яндекс до ПВЗ — 7 дн.' === $rates[YandexDeliveryCarrier::PICKUP_RATE_ID]->title, 'Pickup checkout rate must use pricing response price and delivery days.' );
yandex_pricing_assert( 46360 === $rates[YandexDeliveryCarrier::COURIER_RATE_ID]->price->get_kopecks() && 'Яндекс до двери — 9 дн.' === $rates[YandexDeliveryCarrier::COURIER_RATE_ID]->title, 'Courier checkout rate must use pricing response price and delivery days.' );
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
yandex_pricing_assert( YandexDeliveryCarrier::PICKUP_RATE_ID === $quote->rates[0]->rate_id && YandexDeliveryCarrier::COURIER_RATE_ID === $quote->rates[1]->rate_id, 'Yandex checkout rate ids must stay unchanged.' );

$checkout_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/YandexDeliveryCarrier.php' );
yandex_pricing_assert( ! str_contains( $checkout_source, 'temporary_zero_price' ) && ! str_contains( $checkout_source, 'temporary_checkout_rates' ), 'Yandex checkout must not keep temporary zero-price flags.' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
yandex_pricing_assert( str_contains( $plugin_source, 'YandexDeliveryPricingRequestBuilder::class' ) && str_contains( $plugin_source, 'YandexDeliveryPricingResponseParser::class' ), 'Yandex pricing services must be registered in DI.' );

echo "Yandex Delivery pricing calculator smoke OK\n";
