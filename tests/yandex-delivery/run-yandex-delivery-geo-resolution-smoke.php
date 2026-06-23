<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'yandex-delivery-geo-resolution-smoke-key' );
defined( 'WDC_PLUGIN_DIR' ) || define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingBatchService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMatchScorer;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoResolutionPolicy;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function yd_geo_resolution_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-23 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'yandex-delivery-geo-resolution-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_yandex_delivery_geo_resolution_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_yandex_delivery_geo_resolution_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_yandex_delivery_geo_resolution_options'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { return array(); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_geo_mappings = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class YdGeoResolutionFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> */
	private array $queue;

	public function __construct( YandexDeliveryApiResponse|YandexDeliveryApiException ...$responses ) {
		$this->queue = $responses;
	}

	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$next = array_shift( $this->queue ) ?? new YandexDeliveryApiResponse( 200, '{"locations":[]}' );
		if ( $next instanceof YandexDeliveryApiException ) {
			throw $next;
		}

		return $next;
	}
}

function yd_geo_resolution_candidate( int $geo_id, float $confidence, array $matched_by ): array {
	return array(
		'yandex_geo_id' => $geo_id,
		'confidence'    => $confidence,
		'scoring'       => array(
			'confidence' => $confidence,
			'matched_by' => $matched_by,
			'reason'     => implode( ',', $matched_by ),
		),
	);
}

function yd_geo_resolution_response( array $locations ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( 200, json_encode( array( 'locations' => $locations ), JSON_UNESCAPED_UNICODE ) ?: '{}' );
}

function yd_geo_resolution_location( int $id, string $name, string $region = 'Новосибирская область' ): array {
	return array( 'id' => $id, 'country_code' => 'RU', 'region_name' => $region, 'city_name' => $name, 'place_name' => $name, 'place_type' => 'г', 'display_name' => $region . ', г ' . $name, 'active' => 1 );
}

function yd_geo_resolution_gumerovo_location( int $id ): array {
	return array(
		'id' => $id,
		'country_code' => 'RU',
		'region_name' => 'Башкортостан',
		'region_type' => 'респ',
		'district_name' => 'Аургазинский',
		'district_type' => 'р-н',
		'city_name' => '',
		'place_name' => 'Гумерово',
		'place_type' => 'д',
		'display_name' => 'респ Башкортостан, Аургазинский р-н, деревня Гумерово',
		'active' => 1,
	);
}

function yd_geo_resolution_city_context_location( int $id, string $city, string $place, string $place_type, string $region ): array {
	return array(
		'id' => $id,
		'country_code' => 'RU',
		'region_name' => $region,
		'region_type' => 'респ',
		'city_name' => $city,
		'city_type' => 'г',
		'place_name' => $place,
		'place_type' => $place_type,
		'display_name' => 'г ' . $city . ', ' . $place_type . ' ' . $place,
		'active' => 1,
	);
}

function yd_geo_resolution_maikop_podgorny_candidates(): array {
	return array(
		array( 'geo_id' => 8101, 'address' => 'посёлок Подгорный, городской округ Майкоп, Республика Адыгея (Адыгея)' ),
		array( 'geo_id' => 8102, 'address' => 'посёлок Подгорный, Красногвардейский район, Республика Адыгея (Адыгея)' ),
	);
}

function yd_geo_resolution_ufa_arkaul_candidates(): array {
	return array(
		array( 'geo_id' => 8201, 'address' => 'деревня Аркаул, городской округ Уфа, Республика Башкортостан' ),
		array( 'geo_id' => 8202, 'address' => 'деревня Аркаул, Благовещенский район, Республика Башкортостан' ),
		array( 'geo_id' => 8203, 'address' => 'деревня Аркаул, Караидельский район, Республика Башкортостан' ),
	);
}

function yd_geo_resolution_salavat_candidates(): array {
	return array(
		array( 'geo_id' => 8301, 'address' => 'город Салават, городской округ Салават, Республика Башкортостан' ),
		array( 'geo_id' => 8302, 'address' => 'СНТ Салават, Республика Башкортостан' ),
	);
}

function yd_geo_resolution_salavat_plain_candidates(): array {
	return array(
		array( 'geo_id' => 11115, 'address' => 'Салават, Республика Башкортостан' ),
		array( 'geo_id' => 99692, 'address' => 'СНТ Салават, Республика Башкортостан' ),
	);
}
$policy = new YandexDeliveryGeoResolutionPolicy();
$decision = $policy->resolve( array( yd_geo_resolution_candidate( 100, 100.0, array( 'locality_exact', 'region_match' ) ), yd_geo_resolution_candidate( 101, 55.0, array( 'weak_substring' ) ) ) );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $decision['resolution'] && 100 === $decision['primary_geo_id'], 'Confident best candidate with wide second gap must resolve to mapped primary.' );
$gumero_decision = $policy->resolve(
	array(
		yd_geo_resolution_candidate( 168754, 100.0, array( 'locality_exact', 'region_match', 'district_match', 'type_match', 'type_equivalent' ) ),
		yd_geo_resolution_candidate( 99694, 95.0, array( 'locality_exact', 'region_match', 'type_match', 'type_equivalent' ) ),
		yd_geo_resolution_candidate( 189353, 95.0, array( 'locality_exact', 'region_match', 'type_match', 'type_equivalent' ) ),
		yd_geo_resolution_candidate( 168051, 0.0, array( 'region_match', 'district_match', 'type_match', 'type_equivalent' ) ),
	)
);
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $gumero_decision['resolution'] && 168754 === $gumero_decision['primary_geo_id'] && 'district_tiebreak_primary' === $gumero_decision['reason'], 'Gumerovo district tie-breaker must select the unique locality/region/district/type candidate.' );

$duplicate_district_decision = $policy->resolve(
	array(
		yd_geo_resolution_candidate( 7001, 100.0, array( 'locality_exact', 'region_match', 'district_match', 'type_match' ) ),
		yd_geo_resolution_candidate( 7002, 96.0, array( 'locality_exact', 'region_match', 'district_match', 'type_match' ) ),
	)
);
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $duplicate_district_decision['resolution'], 'District tie-breaker must not map when a close candidate has the same locality/region/district signals.' );

$maikop_decision = $policy->resolve(
	array(
		yd_geo_resolution_candidate( 8101, 95.0, array( 'locality_exact', 'region_match', 'city_context_match', 'type_match' ) ),
		yd_geo_resolution_candidate( 8102, 95.0, array( 'locality_exact', 'region_match', 'type_match' ) ),
	)
);
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $maikop_decision['resolution'] && 8101 === $maikop_decision['primary_geo_id'] && 'city_context_tiebreak_primary' === $maikop_decision['reason'], 'Maykop Podgorny city-context tie-breaker must select the city district candidate.' );

$ufa_decision = $policy->resolve(
	array(
		yd_geo_resolution_candidate( 8201, 95.0, array( 'locality_exact', 'region_match', 'city_context_match', 'type_match' ) ),
		yd_geo_resolution_candidate( 8202, 95.0, array( 'locality_exact', 'region_match', 'type_match' ) ),
		yd_geo_resolution_candidate( 8203, 95.0, array( 'locality_exact', 'region_match', 'type_match' ) ),
	)
);
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $ufa_decision['resolution'] && 8201 === $ufa_decision['primary_geo_id'] && 'city_context_tiebreak_primary' === $ufa_decision['reason'], 'Ufa Arkaul city-context tie-breaker must select the city district candidate.' );

$salavat_decision = $policy->resolve(
	array(
		yd_geo_resolution_candidate( 8301, 95.0, array( 'locality_exact', 'region_match', 'city_context_match', 'type_match' ) ),
		yd_geo_resolution_candidate( 8302, 95.0, array( 'locality_exact', 'region_match', 'type_mismatch' ) ),
	)
);
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $salavat_decision['resolution'] && 8301 === $salavat_decision['primary_geo_id'] && 'city_context_tiebreak_primary' === $salavat_decision['reason'], 'Salavat city-context tie-breaker must select the city candidate over СНТ.' );

$duplicate_city_context_decision = $policy->resolve(
	array(
		yd_geo_resolution_candidate( 8401, 95.0, array( 'locality_exact', 'region_match', 'city_context_match', 'type_match' ) ),
		yd_geo_resolution_candidate( 8402, 95.0, array( 'locality_exact', 'region_match', 'city_context_match', 'type_match' ) ),
	)
);
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $duplicate_city_context_decision['resolution'], 'City-context tie-breaker must not map when a close candidate has the same city-context signals.' );

$decision = $policy->resolve( array( yd_geo_resolution_candidate( 200, 55.0, array( 'locality_exact', 'region_mismatch' ) ) ) );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $decision['resolution'], 'locality_exact with wrong region must be needs_review, not not_found.' );

$decision = $policy->resolve( array( yd_geo_resolution_candidate( 300, 100.0, array( 'locality_exact', 'region_match' ) ), yd_geo_resolution_candidate( 301, 95.0, array( 'locality_exact', 'region_match' ) ) ) );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $decision['resolution'], 'Multiple strong locality candidates without enough gap must resolve to needs_review.' );

$decision = $policy->resolve( array( yd_geo_resolution_candidate( 400, 70.0, array( 'region_match', 'district_match', 'type_match' ) ) ) );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $decision['resolution'], 'Region/district/type context without locality must resolve to needs_review.' );

$decision = $policy->resolve( array( yd_geo_resolution_candidate( 500, 0.0, array( 'type_match' ) ), yd_geo_resolution_candidate( 501, 0.0, array() ) ) );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NOT_FOUND === $decision['resolution'] && null === $decision['primary_geo_id'], 'Candidates without relevant textual/context signals must resolve to not_found.' );

$GLOBALS['wdc_yandex_delivery_geo_resolution_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	yd_geo_resolution_location( 1, 'Казань', 'Республика Татарстан' ),
	yd_geo_resolution_location( 2, 'Новосибирск' ),
	yd_geo_resolution_location( 3, 'Пусто' ),
	yd_geo_resolution_gumerovo_location( 706 ),
	yd_geo_resolution_city_context_location( 707, 'Майкоп', 'Подгорный', 'пос', 'Адыгея' ),
	yd_geo_resolution_city_context_location( 708, 'Уфа', 'Аркаул', 'д', 'Башкортостан' ),
	yd_geo_resolution_city_context_location( 709, 'Салават', 'Салават', 'г', 'Башкортостан' ),
	yd_geo_resolution_city_context_location( 228, 'Салават', 'Салават', 'г', 'Башкортостан' ),
);

$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$service = new YandexDeliveryGeoMappingService(
	new LocationRepository( $GLOBALS['wpdb'] ),
	new YandexDeliveryApiClient(
		$settings,
		new YdGeoResolutionFakeHttp(
			yd_geo_resolution_response( array( array( 'geo_id' => 43, 'address' => 'Казань, Республика Татарстан (Татарстан)' ) ) ),
			yd_geo_resolution_response( array( array( 'geo_id' => 65, 'address' => 'Новосибирск, Франция' ) ) ),
			yd_geo_resolution_response( array() ),
			yd_geo_resolution_response( array( array( 'geo_id' => 168754, 'address' => 'деревня Гумерово, Таштамакский сельсовет, Аургазинский район, Республика Башкортостан' ), array( 'geo_id' => 99694, 'address' => 'деревня Гумерово, Петровский сельсовет, Ишимбайский район, Республика Башкортостан' ), array( 'geo_id' => 189353, 'address' => 'деревня Гумерово, Кадыргуловский сельсовет, Давлекановский район, Республика Башкортостан' ), array( 'geo_id' => 168051, 'address' => 'деревня Староитикеево, Батыровский сельсовет, Аургазинский район, Республика Башкортостан' ) ) ),
			yd_geo_resolution_response( yd_geo_resolution_maikop_podgorny_candidates() ),
			yd_geo_resolution_response( yd_geo_resolution_ufa_arkaul_candidates() ),
			yd_geo_resolution_response( yd_geo_resolution_salavat_candidates() ),
			yd_geo_resolution_response( yd_geo_resolution_salavat_plain_candidates() )
		)
	),
	$repository,
	new YandexDeliveryGeoMatchScorer(),
	new YandexDeliveryGeoResolutionPolicy()
);

$mapped = $service->detect_for_location_id( 1 );
$mapped_rows = $repository->find_by_location_id( 1 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $mapped['status'] && 1 === count( $mapped_rows ) && 1 === (int) $mapped_rows[0]['is_primary'] && 43 === $repository->find_primary_geo_id( 1 ), 'mapped resolution must save only the primary row with default policy.' );

$needs_review = $service->detect_for_location_id( 2 );
$needs_review_rows = $repository->find_by_location_id( 2 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $needs_review['status'] && 1 === count( $needs_review_rows ) && empty( $needs_review_rows[0]['is_primary'] ) && null === $repository->find_primary_geo_id( 2 ), 'needs_review resolution must save candidates without primary.' );

$not_found = $service->detect_for_location_id( 3 );
$not_found_rows = $repository->find_by_location_id( 3 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::NOT_FOUND === $not_found['status'] && 1 === count( $not_found_rows ) && null === $not_found_rows[0]['yandex_geo_id'], 'not_found resolution must save one NULL geo_id diagnostic row.' );

$gumero_result = $service->detect_for_location_id( 706 );
$gumero_rows = $repository->find_by_location_id( 706 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $gumero_result['status'] && 1 === count( $gumero_rows ) && 1 === (int) $gumero_rows[0]['is_primary'] && 168754 === $repository->find_primary_geo_id( 706 ), 'Gumerovo service integration must save one mapped primary row.' );

$maikop_result = $service->detect_for_location_id( 707 );
$maikop_rows = $repository->find_by_location_id( 707 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $maikop_result['status'] && 1 === count( $maikop_rows ) && 1 === (int) $maikop_rows[0]['is_primary'] && 8101 === $repository->find_primary_geo_id( 707 ), 'Maykop Podgorny service integration must save one city-context mapped primary row.' );

$ufa_result = $service->detect_for_location_id( 708 );
$ufa_rows = $repository->find_by_location_id( 708 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $ufa_result['status'] && 1 === count( $ufa_rows ) && 1 === (int) $ufa_rows[0]['is_primary'] && 8201 === $repository->find_primary_geo_id( 708 ), 'Ufa Arkaul service integration must save one city-context mapped primary row.' );

$salavat_result = $service->detect_for_location_id( 709 );
$salavat_rows = $repository->find_by_location_id( 709 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $salavat_result['status'] && 1 === count( $salavat_rows ) && 1 === (int) $salavat_rows[0]['is_primary'] && 8301 === $repository->find_primary_geo_id( 709 ), 'Salavat service integration must save one city-context mapped primary row.' );

$plain_salavat_result = $service->detect_for_location_id( 228 );
$plain_salavat_rows = $repository->find_by_location_id( 228 );
yd_geo_resolution_assert( YandexDeliveryGeoMappingStatus::MAPPED === $plain_salavat_result['status'] && 1 === count( $plain_salavat_rows ) && 1 === (int) $plain_salavat_rows[0]['is_primary'] && 11115 === $repository->find_primary_geo_id( 228 ), 'Plain Salavat service integration must map city over СНТ by type_mismatch penalty.' );


$GLOBALS['wdc_yandex_delivery_geo_resolution_options'] = array();
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$GLOBALS['wpdb']->locations = array( yd_geo_resolution_gumerovo_location( 10 ) );
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array();
$batch_repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$batch_service = new YandexDeliveryGeoMappingService(
	new LocationRepository( $GLOBALS['wpdb'] ),
	new YandexDeliveryApiClient( $settings, new YdGeoResolutionFakeHttp( yd_geo_resolution_response( array( array( 'geo_id' => 168754, 'address' => 'деревня Гумерово, Таштамакский сельсовет, Аургазинский район, Республика Башкортостан' ), array( 'geo_id' => 99694, 'address' => 'деревня Гумерово, Петровский сельсовет, Ишимбайский район, Республика Башкортостан' ), array( 'geo_id' => 189353, 'address' => 'деревня Гумерово, Кадыргуловский сельсовет, Давлекановский район, Республика Башкортостан' ), array( 'geo_id' => 168051, 'address' => 'деревня Староитикеево, Батыровский сельсовет, Аургазинский район, Республика Башкортостан' ) ) ) ) ),
	$batch_repository,
	new YandexDeliveryGeoMatchScorer(),
	new YandexDeliveryGeoResolutionPolicy()
);
$batch = new YandexDeliveryGeoMappingBatchService( new LocationRepository( $GLOBALS['wpdb'] ), $batch_repository, $batch_service );
$batch->start( 1, 1 );
$batch_state = $batch->run_step();
yd_geo_resolution_assert( 1 === $batch_state['mapped'] && 0 === $batch_state['ambiguous'] && 0 === $batch_state['errors'], 'Batch classifier must count Gumerovo district tie-breaker result as mapped without ambiguity or errors.' );

$GLOBALS['wdc_yandex_delivery_geo_resolution_options'] = array();
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$GLOBALS['wpdb']->locations = array( yd_geo_resolution_city_context_location( 20, 'Уфа', 'Аркаул', 'д', 'Башкортостан' ) );
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array();
$city_batch_repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$city_batch_service = new YandexDeliveryGeoMappingService(
	new LocationRepository( $GLOBALS['wpdb'] ),
	new YandexDeliveryApiClient( $settings, new YdGeoResolutionFakeHttp( yd_geo_resolution_response( yd_geo_resolution_ufa_arkaul_candidates() ) ) ),
	$city_batch_repository,
	new YandexDeliveryGeoMatchScorer(),
	new YandexDeliveryGeoResolutionPolicy()
);
$city_batch = new YandexDeliveryGeoMappingBatchService( new LocationRepository( $GLOBALS['wpdb'] ), $city_batch_repository, $city_batch_service );
$city_batch->start( 1, 1 );
$city_batch_state = $city_batch->run_step();
yd_geo_resolution_assert( 1 === $city_batch_state['mapped'] && 0 === $city_batch_state['ambiguous'] && 0 === $city_batch_state['errors'], 'Batch classifier must count city-context tie-breaker result as mapped without ambiguity or errors.' );

$GLOBALS['wdc_yandex_delivery_geo_resolution_options'] = array();
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$GLOBALS['wpdb']->locations = array( yd_geo_resolution_city_context_location( 228, 'Салават', 'Салават', 'г', 'Башкортостан' ) );
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array();
$salavat_batch_repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$salavat_batch_service = new YandexDeliveryGeoMappingService(
	new LocationRepository( $GLOBALS['wpdb'] ),
	new YandexDeliveryApiClient( $settings, new YdGeoResolutionFakeHttp( yd_geo_resolution_response( yd_geo_resolution_salavat_plain_candidates() ) ) ),
	$salavat_batch_repository,
	new YandexDeliveryGeoMatchScorer(),
	new YandexDeliveryGeoResolutionPolicy()
);
$salavat_batch = new YandexDeliveryGeoMappingBatchService( new LocationRepository( $GLOBALS['wpdb'] ), $salavat_batch_repository, $salavat_batch_service );
$salavat_batch->start( 1, 1 );
$salavat_batch_state = $salavat_batch->run_step();
yd_geo_resolution_assert( 1 === $salavat_batch_state['mapped'] && 0 === $salavat_batch_state['ambiguous'] && 0 === $salavat_batch_state['errors'], 'Batch classifier must count plain Salavat type-mismatch penalty result as mapped without ambiguity or errors.' );

$status_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingStatus.php' );
$policy_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoResolutionPolicy.php' );
$service_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingService.php' );
$batch_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingBatchService.php' );
$repository_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php' );
$plugin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' );
$primary_start = strpos( $repository_source, 'function find_primary_geo_id' );
$primary_end = strpos( $repository_source, 'function set_primary', false === $primary_start ? 0 : $primary_start );
$primary_source = false !== $primary_start && false !== $primary_end ? substr( $repository_source, $primary_start, $primary_end - $primary_start ) : '';

yd_geo_resolution_assert( str_contains( $status_source, "NEEDS_REVIEW = 'needs_review'" ) && str_contains( $status_source, 'self::NEEDS_REVIEW' ), 'YandexDeliveryGeoMappingStatus::all() must contain needs_review.' );
yd_geo_resolution_assert( str_contains( $service_source, 'YandexDeliveryGeoResolutionPolicy' ) && str_contains( $service_source, 'resolution_policy->resolve' ), 'YandexDeliveryGeoMappingService must use YandexDeliveryGeoResolutionPolicy.' );
yd_geo_resolution_assert( str_contains( $batch_source, 'YandexDeliveryGeoMappingStatus::NEEDS_REVIEW' ), 'Batch service must classify needs_review.' );
yd_geo_resolution_assert( '' !== $primary_source && ! str_contains( $primary_source, 'NEEDS_REVIEW' ) && str_contains( $primary_source, 'is_primary' ), 'find_primary_geo_id() must not use needs_review as a working mapping.' );
yd_geo_resolution_assert( str_contains( $policy_source, 'district_tiebreak_primary' ) && str_contains( $policy_source, 'locality_exact' ) && str_contains( $policy_source, 'region_match' ) && str_contains( $policy_source, 'district_match' ) && str_contains( $policy_source, 'type_match' ), 'Resolution policy source must contain district tie-breaker exact locality/region/district/type guard.' );
yd_geo_resolution_assert( str_contains( $policy_source, 'city_context_tiebreak_primary' ) && str_contains( $policy_source, 'city_context_match' ) && str_contains( $policy_source, 'has_city_context_exact_signal' ), 'Resolution policy source must contain city-context tie-breaker guard.' );
yd_geo_resolution_assert( str_contains( $plugin_source, 'YandexDeliveryGeoResolutionPolicy::class' ) && str_contains( $plugin_source, 'new YandexDeliveryGeoResolutionPolicy()' ) && str_contains( $plugin_source, '$this->container->get( YandexDeliveryGeoResolutionPolicy::class )' ), 'Plugin must register and inject YandexDeliveryGeoResolutionPolicy.' );

echo "Yandex Delivery geo resolution smoke OK\n";
