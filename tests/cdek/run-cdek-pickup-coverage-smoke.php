<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Cdek\CdekPickupCoverageService;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

function coverage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function current_time( string $type ): string { return '2026-09-14 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'coverage-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['coverage_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['coverage_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['coverage_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['coverage_transients'][ $key ] = $value; $GLOBALS['coverage_ttls'][ $key ] = $expiration; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function rest_ensure_response( mixed $value ): mixed { return $value; }
function wc_get_logger(): object { return new class { public function log( string $level, string $message, array $context = array() ): void { $GLOBALS['coverage_logs'][] = compact( 'level', 'message', 'context' ); } }; }
function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public object $session;
			public function __construct() {
				$this->session = new class {
					public array $data = array();
					public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
					public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
					public function __unset( string $key ): void { unset( $this->data[ $key ] ); }
				};
			}
		};
	}
	return $wc;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $regions = array();
	}
}

final class CoverageHttp implements CdekHttpClientInterface {
	public bool $fail_page_one = false;
	public int $region_requests = 0;
	public array $tariff_destinations = array();

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		unset( $method );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, '{"access_token":"test","expires_in":3600}' );
		}
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		if ( str_contains( $url, '/v2/location/cities' ) && isset( $query['region_code'] ) ) {
			++$this->region_requests;
			$page = (int) ( $query['page'] ?? 0 );
			if ( $this->fail_page_one && 1 === $page ) {
				return new CdekApiResponse( 500, '{"message":"temporary"}' );
			}
			$count = $page < 2 ? 1000 : ( 2 === $page ? 45 : 0 );
			$rows = array();
			for ( $index = 0; $index < $count; ++$index ) {
				$code = $page * 1000 + $index + 3000;
				$rows[] = array( 'code' => $code, 'city' => 'City ' . $code, 'country_code' => 'RU', 'region_code' => (int) $query['region_code'], 'sub_region' => 'другой округ' );
			}
			if ( 0 === $page ) {
				$rows[0] = array( 'code' => 1097, 'city' => 'Балашиха', 'fias_guid' => '27c5', 'country_code' => 'RU', 'region_code' => 9, 'sub_region' => 'городской округ Балашиха' );
				$rows[1] = array( 'code' => 391, 'city' => 'Железнодорожный микрорайон', 'fias_guid' => '33cd', 'country_code' => 'RU', 'region_code' => 9, 'sub_region' => '  ГОРОДСКОЙ   ОКРУГ БАЛАШИХА ' );
				$rows[2] = array( 'code' => 1711, 'city' => 'Пуршево', 'country_code' => 'RU', 'region_code' => 9, 'sub_region' => 'городской округ Балашиха' );
				$rows[3] = array( 'code' => 1727, 'city' => 'Федурново', 'country_code' => 'RU', 'region_code' => 9, 'sub_region' => 'городской округ Балашиха' );
			}
			if ( 1 === $page ) {
				$rows[10]['code'] = 391;
			}
			return new CdekApiResponse( 200, (string) json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
		}
		if ( str_contains( $url, '/v2/location/cities' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( array( 'code' => 1097, 'city' => 'Балашиха', 'country_code' => 'RU', 'region' => 'Московская область', 'region_code' => 9, 'sub_region' => 'городской округ Балашиха', 'fias_guid' => '27c5' ) ), JSON_UNESCAPED_UNICODE ) );
		}
		if ( str_contains( $url, '/v2/deliverypoints' ) ) {
			$city = (int) ( $query['city_code'] ?? 0 );
			$code = 391 === $city ? 'ZHLD25' : 'BLSH6';
			$uuid = 391 === $city ? 'child-uuid' : 'primary-uuid';
			return new CdekApiResponse( 200, (string) json_encode( array( array( 'code' => $code, 'uuid' => $uuid, 'type' => 'PVZ', 'is_handout' => true, 'note' => 'Обычный комментарий СДЭК', 'location' => array( 'country_code' => 'RU', 'city_code' => $city, 'city' => 391 === $city ? 'Железнодорожный' : 'Балашиха', 'address' => 'Адрес', 'latitude' => 55.7, 'longitude' => 37.9 ) ) ), JSON_UNESCAPED_UNICODE ) );
		}
		if ( str_contains( $url, '/v2/calculator/tarifflist' ) ) {
			$payload = json_decode( (string) ( $args['body'] ?? '{}' ), true );
			$destination = (int) ( $payload['to_location']['code'] ?? 0 );
			$this->tariff_destinations[] = $destination;
			return new CdekApiResponse( 200, (string) json_encode( array( 'tariff_codes' => array( array( 'tariff_code' => 136, 'tariff_name' => 'Склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 391 === $destination ? 491 : 390, 'period_min' => 391 === $destination ? 3 : 2, 'period_max' => 391 === $destination ? 5 : 4 ) ) ), JSON_UNESCAPED_UNICODE ) );
		}
		return new CdekApiResponse( 404, '{}' );
	}
}

$GLOBALS['coverage_options'] = array();
$GLOBALS['coverage_transients'] = array();
$GLOBALS['coverage_ttls'] = array();
$GLOBALS['coverage_logs'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 82077, 'active' => 1, 'country_code' => 'RU', 'region_code' => '50', 'region_name' => 'Московская область', 'city_name' => 'Балашиха', 'place_name' => 'Балашиха', 'display_name' => 'г Балашиха', 'fias_id' => '27c5', 'city_fias_id' => '27c5', 'gar_object_id' => 1 ),
	array( 'id' => 82078, 'active' => 1, 'country_code' => 'RU', 'region_code' => '50', 'region_name' => 'Московская область', 'city_name' => 'Балашиха', 'place_name' => 'Пуршево', 'display_name' => 'д Пуршево', 'fias_id' => 'purshevo', 'city_fias_id' => '27c5', 'gar_object_id' => 2 ),
	array( 'id' => 82079, 'active' => 1, 'country_code' => 'RU', 'region_code' => '50', 'region_name' => 'Московская область', 'city_name' => 'Без city FIAS', 'place_name' => 'Без city FIAS', 'display_name' => 'Без city FIAS', 'fias_id' => 'only-fias', 'city_fias_id' => '', 'gar_object_id' => 3 ),
	array( 'id' => 82080, 'active' => 0, 'country_code' => 'RU', 'region_code' => '50', 'region_name' => 'Московская область', 'city_name' => 'Неактивный', 'place_name' => 'Неактивный', 'display_name' => 'Неактивный', 'fias_id' => 'inactive', 'city_fias_id' => 'inactive', 'gar_object_id' => 4 ),
	array( 'id' => 82081, 'active' => 1, 'country_code' => 'KZ', 'region_code' => '', 'region_name' => 'Алматы', 'city_name' => 'Алматы', 'place_name' => 'Алматы', 'display_name' => 'Алматы', 'fias_id' => 'foreign', 'city_fias_id' => 'foreign', 'gar_object_id' => 5 ),
);

$settings = new CdekSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST, CdekSettings::TEST_ACCOUNT_KEY => 'a', 'cdek_test_secure_password' => 'b', CdekSettings::SENDER_CITY_CODE_KEY => '270' ) );
$http = new CoverageHttp();
$client = new CdekApiClient( new CdekOAuthTokenService( $settings, $http ), $settings, $http );
$logger = new Logger();
$repo = new LocationRepository( $GLOBALS['wpdb'] );
$coverage = new CdekPickupCoverageService( $client, $settings, $repo, $logger );
$primary = array( 'success' => true, 'city_code' => 1097, 'city_name' => 'Балашиха', 'country_code' => 'RU', 'region_code' => 9, 'sub_region' => 'городской округ Балашиха', 'fias_guid' => '27c5' );

$cities = $coverage->cities_for_location( array( 'location_id' => 82077 ), $primary );
coverage_assert( array( 391, 1097, 1711, 1727 ) === array_column( $cities, 'code' ), 'Upper canonical city must include exact normalized subregion coverage deterministically.' );
coverage_assert( 3 === $http->region_requests, '1000 + 1000 + 45 pagination must stop on the final short page.' );
$cached = array_values( array_filter( $GLOBALS['coverage_transients'], static fn( mixed $value, string $key ): bool => is_array( $value ) && str_starts_with( $key, CdekPickupCoverageService::CACHE_PREFIX ), ARRAY_FILTER_USE_BOTH ) );
coverage_assert( array() !== $cached && strlen( serialize( $cached[0] ) ) < 300000, 'Minimal Moscow-like 2045-row directory cache should remain compact.' );
coverage_assert( array( 1711 ) === array_column( $coverage->cities_for_location( array( 'location_id' => 82078 ), array_merge( $primary, array( 'city_code' => 1711, 'city_name' => 'Пуршево' ) ) ), 'code' ), 'Canonical descendant must remain primary-only.' );
coverage_assert( array( 1097 ) === array_column( $coverage->cities_for_location( array( 'location_id' => 82079 ), $primary ), 'code' ), 'Canonical location without city_fias_id must remain primary-only.' );
coverage_assert( array( 1097 ) === array_column( $coverage->cities_for_location( array( 'location_id' => 82080 ), $primary ), 'code' ), 'Inactive canonical location must remain primary-only.' );
coverage_assert( array( 1097 ) === array_column( $coverage->cities_for_location( array( 'location_id' => 82081 ), $primary ), 'code' ), 'Non-RU canonical location must remain primary-only.' );
coverage_assert( array( 1097 ) === array_column( $coverage->cities_for_location( array(), $primary ), 'code' ), 'Manual noncanonical location must remain primary-only.' );
coverage_assert( array( 1097 ) === array_column( $coverage->cities_for_location( array( 'location_id' => 82077 ), array_merge( $primary, array( 'region_code' => 0 ) ) ), 'code' ), 'Primary without region_code must remain primary-only.' );
coverage_assert( array( 1097 ) === array_column( $coverage->cities_for_location( array( 'location_id' => 82077 ), array_merge( $primary, array( 'sub_region' => '' ) ) ), 'code' ), 'Primary without sub_region must remain primary-only.' );
coverage_assert( array() === $coverage->cities_for_location( array( 'location_id' => 82077 ), array_merge( $primary, array( 'success' => false ) ) ), 'Failed primary CDEK resolution must not produce coverage.' );

$points = new CdekDeliveryPointService( $client, $settings, new CdekLocationResolver( $client, $settings, $logger ), $logger, $coverage );
$merged = $points->pointsForLocation( array( 'location_id' => 82077, 'country_code' => 'RU' ), array( 'handout_only' => true ) );
$child = array_values( array_filter( $merged, static fn( array $point ): bool => 'ZHLD25' === (string) ( $point['point_code'] ?? '' ) ) );
$primary_points = array_values( array_filter( $merged, static fn( array $point ): bool => 'BLSH6' === (string) ( $point['point_code'] ?? '' ) ) );
coverage_assert( 2 === count( $merged ) && 1 === count( $primary_points ), 'Coverage point merge must deterministically dedupe repeated UUIDs across covered cities.' );
coverage_assert( ! isset( $primary_points[0]['presentation_comment'] ), 'Primary-city point must not carry the geography requote presentation.' );
coverage_assert( 1 === count( $child ) && 391 === (int) $child[0]['cdek_city_code'], 'Child point must preserve its actual CDEK city code.' );
coverage_assert( 'Стоимость будет рассчитана заново (особенность географии СДЭК)' === (string) ( $child[0]['presentation_comment'] ?? '' ), 'Child point must carry the exact geography requote presentation.' );
coverage_assert( 'Обычный комментарий СДЭК' === (string) ( $child[0]['description'] ?? '' ), 'Child geography presentation must not overwrite the existing CDEK point description.' );

$item = new PackageItem( 'sku', 'Товар', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 500, 10, 10, 10 );
$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
$base_context = array( 'delivery_type' => 'pickup', 'selected_location_id' => 82077, 'selected_location_fias_id' => '27c5' );
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', region_name: 'Московская область', city: 'Балашиха', fias_id: '27c5' ), $package, '', Money::from_rubles( 1000 ), '2026-09-14', $base_context );
$carrier = new CdekCarrier( $settings, $client, new CdekLocationResolver( $client, $settings, $logger ), $logger, $points );
$primary_quote = $carrier->quote( $request );
coverage_assert( 1097 === (int) end( $http->tariff_destinations ), 'Unselected pickup quote must use primary CDEK city 1097.' );
$destination_fingerprint = 'country=RU|location_id=82077';
$selection = array( 'carrier_key' => 'cdek', 'pickup_family' => 'cdek:pickup', 'point_code' => 'ZHLD25', 'cdek_city_code' => 391, 'destination_fingerprint' => $destination_fingerprint, 'snapshot' => array( 'carrier_key' => 'cdek', 'pickup_family' => 'cdek:pickup', 'point_code' => 'ZHLD25', 'cdek_city_code' => 391, 'city' => 'Железнодорожный микрорайон', 'destination_fingerprint' => $destination_fingerprint ) );
$child_request = new QuoteRequest( 'RU', $request->destination, $package, '', Money::from_rubles( 1000 ), '2026-09-14', array_merge( $base_context, array( 'pickup_selections' => array( 'cdek:pickup' => $selection ) ) ) );
$child_quote = $carrier->quote( $child_request );
coverage_assert( 391 === (int) end( $http->tariff_destinations ), 'Server-validated child selection must use effective CDEK city 391.' );
coverage_assert( 390.0 === $primary_quote->rates[0]->price->get_rubles() && 491.0 === $child_quote->rates[0]->price->get_rubles(), 'Child effective destination must produce a fresh CDEK price.' );
coverage_assert( '2-4 дня' === (string) ( $primary_quote->rates[0]->meta['api_delivery_days_text'] ?? '' ) && '3-5 дней' === (string) ( $child_quote->rates[0]->meta['api_delivery_days_text'] ?? '' ), 'Child effective destination must update CDEK delivery days.' );
coverage_assert( (string) $primary_quote->rates[0]->planned_delivery_comment !== (string) $child_quote->rates[0]->planned_delivery_comment, 'Child effective destination must update the planned delivery comment.' );
coverage_assert( 1097 === (int) ( $child_quote->rates[0]->meta['location']['cdek_primary_city_code'] ?? 0 ) && 391 === (int) ( $child_quote->rates[0]->meta['location']['cdek_effective_city_code'] ?? 0 ), 'Rate metadata must preserve primary 1097 and effective 391.' );
$stale_selection = array_replace_recursive( $selection, array( 'destination_fingerprint' => 'country=RU|location_id=82078', 'snapshot' => array( 'destination_fingerprint' => 'country=RU|location_id=82078' ) ) );
$stale_request = new QuoteRequest( 'RU', $request->destination, $package, '', Money::from_rubles( 1000 ), '2026-09-14', array_merge( $base_context, array( 'pickup_selections' => array( 'cdek:pickup' => $stale_selection ) ) ) );
$carrier->quote( $stale_request );
coverage_assert( 1097 === (int) end( $http->tariff_destinations ), 'Stale CDEK selection for another canonical destination must fail closed to primary city 1097.' );
$missing_fingerprint = $selection;
unset( $missing_fingerprint['destination_fingerprint'], $missing_fingerprint['snapshot']['destination_fingerprint'] );
$missing_fingerprint_request = new QuoteRequest( 'RU', $request->destination, $package, '', Money::from_rubles( 1000 ), '2026-09-14', array_merge( $base_context, array( 'pickup_selections' => array( 'cdek:pickup' => $missing_fingerprint ) ) ) );
$carrier->quote( $missing_fingerprint_request );
coverage_assert( 1097 === (int) end( $http->tariff_destinations ), 'CDEK selection without destination fingerprint must fail closed to primary city 1097.' );
$courier_request = new QuoteRequest( 'RU', $request->destination, $package, '', Money::from_rubles( 1000 ), '2026-09-14', array_merge( $base_context, array( 'delivery_type' => 'courier', 'pickup_selections' => array( 'cdek:pickup' => $selection ) ) ) );
$carrier->quote( $courier_request );
coverage_assert( 1097 === (int) end( $http->tariff_destinations ), 'CDEK courier quote must always keep primary city regardless of pickup selection.' );
$reload_session = new CheckoutSessionManager();
$reload_session->clear_pickup_selection( 'test_reset' );
$reload_session->save_city_context( array( 'location_id' => 82077, 'selected_location_id' => 82077, 'country_code' => 'RU', 'city_name' => 'Балашиха', 'region_name' => 'Московская область', 'fias_id' => '27c5' ) );
$reload_session->save_pickup_selection( $selection );
coverage_assert( $destination_fingerprint === (string) ( $reload_session->pickup_selections()['cdek:pickup']['destination_fingerprint'] ?? '' ), 'CheckoutSessionManager must persist the current canonical destination fingerprint with the CDEK selection.' );
$reload_request = new QuoteRequest( 'RU', $request->destination, $package, '', Money::from_rubles( 1000 ), '2026-09-14', array_merge( $base_context, array( 'pickup_selections' => $reload_session->pickup_selections_for_current_destination() ) ) );
$carrier->quote( $reload_request );
coverage_assert( 391 === (int) end( $http->tariff_destinations ), 'Reloaded same-destination child selection must remain valid and use effective city 391.' );
$quote_cache = new QuoteCache();
coverage_assert( $quote_cache->cache_key( $request, 'cdek', 'pickup', 'cdek' ) !== $quote_cache->cache_key( $child_request, 'cdek', 'pickup', 'cdek' ), 'Effective CDEK destination must change quote cache identity.' );

$session = new CheckoutSessionManager();
$session->save_rates( array( 'cdek:pickup:136' => array(
	'rate_id' => 'cdek:pickup:136', 'carrier_key' => 'cdek', 'service_key' => 'cdek', 'delivery_type' => 'pickup', 'requires_pickup_point' => true,
	'meta' => array( 'carrier_key' => 'cdek', 'service_key' => 'cdek', 'delivery_type' => 'pickup', 'location_id' => 82077, 'location' => array( 'cdek_to_country_code' => 'RU', 'cdek_to_city_code' => 391, 'cdek_primary_city_code' => 1097, 'cdek_primary_city_name' => 'Балашиха', 'wdc_location_id' => 82077 ), 'request_payload_sanitized' => array( 'to_location' => array( 'code' => 391 ) ) ),
) ) );
$session->save_city_context( array( 'location_id' => 82077, 'selected_location_id' => 82077, 'country_code' => 'RU', 'city_name' => 'Балашиха', 'region_name' => 'Московская область', 'fias_id' => '27c5' ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:cdek:pickup:136' ) );
$controller = new CheckoutPickupPointRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), $session, null, $points, null, null, null, null, null, new WooCommerceSessionBootstrapper() );
$saved = $controller->save( array( 'carrier' => 'cdek', 'shipping_method_id' => 'cdek:pickup:136', 'point_id' => 'cdek:ZHLD25', 'point_code' => 'ZHLD25', 'point' => array( 'cdek_city_code' => 999, 'point_address' => 'forged' ) ) );
coverage_assert( 'ZHLD25' === (string) ( $saved['pickupSelections']['cdek:pickup']['point_code'] ?? '' ) && 391 === (int) ( $session->pickup_selections()['cdek:pickup']['cdek_city_code'] ?? 0 ), 'Server-side save must accept allowed child and persist authoritative city 391.' );
$rejected = $controller->save( array( 'carrier' => 'cdek', 'shipping_method_id' => 'cdek:pickup:136', 'point_id' => 'cdek:OUTSIDE', 'point_code' => 'OUTSIDE', 'point' => array( 'cdek_city_code' => 391, 'is_handout' => true ) ) );
coverage_assert( 'not_found' === (string) ( $rejected['code'] ?? '' ), 'Forged browser city code must not make an unrelated point valid.' );

$GLOBALS['coverage_transients'] = array();
$http->fail_page_one = true;
$failed_primary = array_merge( $primary, array( 'region_code' => 10 ) );
$fallback = $coverage->cities_for_location( array( 'location_id' => 82077 ), $failed_primary );
coverage_assert( array( 1097 ) === array_column( $fallback, 'code' ), 'Directory API failure must fall back to primary-only.' );
$partial_directories = array_filter( $GLOBALS['coverage_transients'], static fn( mixed $value, string $key ): bool => str_starts_with( $key, CdekPickupCoverageService::CACHE_PREFIX ), ARRAY_FILTER_USE_BOTH );
coverage_assert( array() === $partial_directories, 'Directory API failure must not persist a partial cache.' );

$moscow_like = array();
for ( $index = 1; $index <= 6045; ++$index ) {
	$moscow_like[ 'округ ' . ( $index % 120 ) ][] = array( 'code' => $index, 'city' => 'Населенный пункт ' . $index );
}
$moscow_like_bytes = strlen( serialize( $moscow_like ) );
coverage_assert( $moscow_like_bytes < 1000000, 'Minimal normalized 6045-city subregion index must remain below 1 MB serialized.' );

echo 'CDEK pickup coverage smoke OK; serialized 2045-row bytes=' . strlen( serialize( $cached[0] ) ) . '; Moscow-like 6045-row bytes=' . $moscow_like_bytes . PHP_EOL;
