<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

function yd_geo_manual_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "[FAIL] {$message}\n" );
		exit( 1 );
	}
}

function current_time( string $type ): string { return '2026-06-24 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;

$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	/** @var array<int,array<string,mixed>> */
	public array $yandex_delivery_geo_mappings = array();
	/** @var array<int,array<string,mixed>> */
	public array $locations = array();
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_var( string $query ): mixed { return null; }
	public function get_col( string $query ): array { return array(); }
	public function get_results( string $query, string $output = ARRAY_A ): array { return array(); }
	public function query( string $query ): int { return 0; }
	public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
};

function yd_geo_manual_raw( array $matched_by, string $reason ): string {
	return json_encode( array( 'scoring' => array( 'matched_by' => $matched_by, 'reason' => $reason ) ), JSON_UNESCAPED_UNICODE ) ?: '{}';
}

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 1, 'display_name' => 'Новосибирская область, г Новосибирск', 'region_name' => 'Новосибирская область', 'place_type' => 'г' ),
	array( 'id' => 2, 'display_name' => 'Новосибирская область, г Бердск', 'region_name' => 'Новосибирская область', 'place_type' => 'г' ),
	array( 'id' => 3, 'display_name' => 'Томская область, с Спорное', 'region_name' => 'Томская область', 'place_type' => 'с' ),
	array( 'id' => 4, 'display_name' => 'Омская область, г Омск', 'region_name' => 'Омская область', 'place_type' => 'г' ),
	array( 'id' => 5, 'display_name' => 'Алтайский край, с Ошибка', 'region_name' => 'Алтайский край', 'place_type' => 'с' ),
	array( 'id' => 6, 'display_name' => 'Край, с Bulk 1', 'region_name' => 'Край', 'place_type' => 'с' ),
	array( 'id' => 7, 'display_name' => 'Край, с Bulk 2', 'region_name' => 'Край', 'place_type' => 'с' ),
);

$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->save_mapping( array( 'location_id' => 1, 'yandex_geo_id' => 111, 'yandex_locality' => 'Новосибирск', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 92, 'is_primary' => 0, 'raw_json' => yd_geo_manual_raw( array( 'locality_exact' ), 'region differs' ) ) );
$repository->save_mapping( array( 'location_id' => 1, 'yandex_geo_id' => 112, 'yandex_locality' => 'Новосибирский', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 81, 'is_primary' => 0, 'raw_json' => yd_geo_manual_raw( array( 'region_match' ), 'weak locality' ) ) );
$repository->save_mapping( array( 'location_id' => 2, 'yandex_geo_id' => 222, 'yandex_locality' => 'Бердск', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 70, 'is_primary' => 0, 'raw_json' => yd_geo_manual_raw( array( 'locality_exact' ), 'manual required' ) ) );
$repository->save_mapping( array( 'location_id' => 3, 'yandex_geo_id' => null, 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND, 'confidence' => 0, 'is_primary' => 0 ) );
$repository->save_mapping( array( 'location_id' => 4, 'yandex_geo_id' => 444, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$repository->save_technical_error_marker( 5, 'Ошибка', 'timeout' );

$queue = $repository->find_needs_review_locations( array( 'page' => 1, 'per_page' => 10 ) );
yd_geo_manual_assert( 2 === count( $queue ) && 1 === (int) $queue[0]['location_id'] && 2 === count( $queue[0]['candidates'] ), 'Queue must group needs_review candidates by location_id.' );
yd_geo_manual_assert( 'Новосибирская область, г Новосибирск' === $queue[0]['display_name'] && array( 'locality_exact' ) === $queue[0]['candidates'][0]['matched_by'], 'Queue rows must include location metadata and scoring diagnostics.' );
yd_geo_manual_assert( 2 === $repository->count_needs_review_locations(), 'Queue count must count grouped location_id values.' );
yd_geo_manual_assert( 1 === count( $repository->find_needs_review_locations( array( 'page' => 1, 'per_page' => 1 ) ) ) && 1 === count( $repository->find_needs_review_locations( array( 'page' => 2, 'per_page' => 1 ) ) ), 'Queue pagination must page grouped locations.' );
yd_geo_manual_assert( 1 === count( $repository->find_needs_review_locations( array( 'search' => 'Бердск', 'per_page' => 10 ) ) ), 'Queue search filter must match display_name/candidate text.' );
yd_geo_manual_assert( 0 === count( $repository->find_needs_review_locations( array( 'search' => 'Омск', 'per_page' => 10 ) ) ), 'Mapped/not_found rows must not enter needs_review queue.' );

yd_geo_manual_assert( true === $repository->approve_mapping( 1, 111 ), 'approve_mapping must accept an existing candidate.' );
$approved_rows = $repository->find_by_location_id( 1 );
$approved_raw = json_decode( (string) $approved_rows[0]['raw_json'], true );
yd_geo_manual_assert( 111 === $repository->find_primary_geo_id( 1 ) && YandexDeliveryGeoMappingStatus::MAPPED === (string) $approved_rows[0]['status'] && 1 === (int) $approved_rows[0]['is_primary'], 'Approve must make selected candidate mapped + primary.' );
yd_geo_manual_assert( 0 === (int) $approved_rows[1]['is_primary'], 'Approve must keep other candidates non-primary.' );
yd_geo_manual_assert( 'approved' === (string) ( $approved_raw['manual_review']['action'] ?? '' ) && 'admin' === (string) ( $approved_raw['manual_review']['source'] ?? '' ), 'Approve must write manual_review approved audit.' );
yd_geo_manual_assert( false === $repository->approve_mapping( 5, YandexDeliveryGeoMappingRepository::TECHNICAL_ERROR_GEO_ID ), 'Technical marker 999999999 must not be approvable.' );

yd_geo_manual_assert( true === $repository->reject_mapping( 2, 'Нет подходящего geo_id' ), 'reject_mapping must reject an existing location.' );
$rejected_rows = $repository->find_by_location_id( 2 );
$rejected_raw = json_decode( (string) $rejected_rows[0]['raw_json'], true );
yd_geo_manual_assert( 1 === count( $rejected_rows ) && YandexDeliveryGeoMappingStatus::NOT_FOUND === (string) $rejected_rows[0]['status'] && null === $rejected_rows[0]['yandex_geo_id'], 'Reject must leave one normal not_found row with NULL geo_id.' );
yd_geo_manual_assert( null === $repository->find_primary_geo_id( 2 ) && 'rejected' === (string) ( $rejected_raw['manual_review']['action'] ?? '' ), 'Reject must clear working primary and write rejected audit.' );
yd_geo_manual_assert( 0 === count( $repository->find_needs_review_locations( array( 'search' => 'Бердск', 'per_page' => 10 ) ) ), 'Rejected location must not remain in needs_review queue.' );

$repository->save_mapping( array( 'location_id' => 6, 'yandex_geo_id' => 666, 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 50, 'is_primary' => 0 ) );
$repository->save_mapping( array( 'location_id' => 7, 'yandex_geo_id' => 777, 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 51, 'is_primary' => 0 ) );
yd_geo_manual_assert( 2 === $repository->bulk_reject_locations( array( 6, 7 ), 'bulk obvious reject' ), 'bulk_reject_locations must return processed count.' );
yd_geo_manual_assert( YandexDeliveryGeoMappingStatus::NOT_FOUND === (string) $repository->find_by_location_id( 6 )[0]['status'] && YandexDeliveryGeoMappingStatus::NOT_FOUND === (string) $repository->find_by_location_id( 7 )[0]['status'], 'Bulk reject must save not_found for each selected location.' );

$repository_source = file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php' ) ?: '';
$admin_source = file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$project_status = file_get_contents( WDC_PLUGIN_DIR . 'docs/project-status.md' ) ?: '';
$plugin_source = file_get_contents( WDC_PLUGIN_DIR . 'walls-delivery-calc.php' ) ?: '';

yd_geo_manual_assert( str_contains( $repository_source, 'function find_needs_review_locations' ) && str_contains( $repository_source, 'function approve_mapping' ) && str_contains( $repository_source, 'function bulk_reject_locations' ), 'Repository must expose manual review methods.' );
yd_geo_manual_assert( str_contains( $admin_source, 'Ручная проверка спорных совпадений' ) && str_contains( $admin_source, 'Подтвердить' ) && str_contains( $admin_source, 'Отказать в сопоставлении' ), 'Manual review UI must expose heading and action buttons.' );
yd_geo_manual_assert( str_contains( $admin_source, 'approve_yandex_delivery_geo_mapping' ) && str_contains( $admin_source, 'reject_yandex_delivery_geo_mapping' ) && str_contains( $admin_source, 'bulk_reject_yandex_delivery_geo_mapping' ), 'Manual review POST actions must exist.' );
yd_geo_manual_assert( str_contains( $admin_source, 'is_running()' ) && str_contains( $admin_source, 'Ручная обработка временно заблокирована' ), 'Manual review handlers/UI must keep runner running guard.' );
yd_geo_manual_assert( str_contains( $plugin_source, 'Version: 0.88.0' ) && str_contains( $plugin_source, "WDC_VERSION', '0.88.0" ), 'Plugin version must be 0.88.0.' );
yd_geo_manual_assert( str_contains( $project_status, '0.88.0 Yandex Geo Manual Review Queue' ), 'Project status must document manual review queue.' );

echo "Yandex Delivery geo manual review smoke test passed.\n";