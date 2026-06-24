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

function yd_geo_manual_raw( array $matched_by, string $reason, string $address = '' ): string {
	$raw = array( 'scoring' => array( 'matched_by' => $matched_by, 'reason' => $reason ) );
	if ( '' !== $address ) {
		$raw['variant'] = array( 'address' => $address );
	}
	return json_encode( $raw, JSON_UNESCAPED_UNICODE ) ?: '{}';
}

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 1, 'display_name' => 'Новосибирская область, г Новосибирск', 'region_name' => 'Новосибирская область', 'place_type' => 'г' ),
	array( 'id' => 2, 'display_name' => 'Регион Бердск, г Бердск', 'region_name' => 'Регион Бердск', 'place_type' => 'г' ),
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
yd_geo_manual_assert( 1 === count( $repository->find_needs_review_locations( array( 'max_location_id_exclusive' => 2, 'per_page' => 10 ) ) ), 'Queue max_location_id_exclusive filter must keep only location_id below the cursor.' );
yd_geo_manual_assert( 1 === $repository->count_needs_review_locations( array( 'max_location_id_exclusive' => 2 ) ), 'Queue count must honor max_location_id_exclusive.' );
yd_geo_manual_assert( 0 === count( $repository->find_needs_review_locations( array( 'max_location_id_exclusive' => 1, 'per_page' => 10 ) ) ), 'Queue max_location_id_exclusive must exclude equal and greater location_id values.' );

yd_geo_manual_assert( true === $repository->approve_mapping( 1, 111 ), 'approve_mapping must accept an existing candidate.' );
$approved_rows = $repository->find_by_location_id( 1 );
$approved_raw = json_decode( (string) $approved_rows[0]['raw_json'], true );
yd_geo_manual_assert( 111 === $repository->find_primary_geo_id( 1 ) && YandexDeliveryGeoMappingStatus::MAPPED === (string) $approved_rows[0]['status'] && 1 === (int) $approved_rows[0]['is_primary'], 'Approve must make selected candidate mapped + primary.' );
yd_geo_manual_assert( 0 === (int) $approved_rows[1]['is_primary'] && YandexDeliveryGeoMappingStatus::NEEDS_REVIEW !== (string) $approved_rows[1]['status'], 'Approve must keep other candidates non-primary and clear needs_review status.' );
$queue_after_approve = $repository->find_needs_review_locations( array( 'search' => 'Новосибирск', 'per_page' => 10 ) );
yd_geo_manual_assert( 0 === count( $queue_after_approve ), 'Approved location must disappear from needs_review queue.' );
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

$GLOBALS['wpdb']->locations[] = array( 'id' => 8, 'display_name' => 'респ Адыгея, поселок Комсомольский', 'region_name' => 'Адыгея', 'place_type' => 'п' );
$GLOBALS['wpdb']->locations[] = array( 'id' => 9, 'display_name' => 'респ Адыгея, с Мусорное', 'region_name' => 'Адыгея', 'place_type' => 'с' );
$GLOBALS['wpdb']->locations[] = array( 'id' => 10, 'display_name' => 'Пермский край, г Пермь', 'region_name' => 'Пермский край', 'place_type' => 'г' );
$GLOBALS['wpdb']->locations[] = array( 'id' => 11, 'display_name' => 'респ Адыгея, г Безопасный', 'region_name' => 'Адыгея', 'place_type' => 'г' );
$GLOBALS['wpdb']->locations[] = array( 'id' => 12, 'display_name' => 'респ Адыгея, с Техошибка', 'region_name' => 'Адыгея', 'place_type' => 'с' );
$repository->save_mapping( array( 'location_id' => 8, 'yandex_geo_id' => 801, 'yandex_locality' => 'посёлок Комсомольский', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 60, 'is_primary' => 0, 'raw_json' => yd_geo_manual_raw( array(), '', 'посёлок Комсомольский, Республика Адыгея' ) ) );
$repository->save_mapping( array( 'location_id' => 8, 'yandex_geo_id' => 802, 'yandex_locality' => 'Комсомольский, Пермский край', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 61, 'is_primary' => 0 ) );
$repository->save_mapping( array( 'location_id' => 9, 'yandex_geo_id' => 901, 'yandex_locality' => 'Мусорное, Пермский край', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 62, 'is_primary' => 0 ) );
$repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 1001, 'yandex_locality' => 'Пермь, Пермский край', 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'confidence' => 63, 'is_primary' => 0 ) );
$repository->save_mapping( array( 'location_id' => 11, 'yandex_geo_id' => 1101, 'yandex_locality' => 'Маппед, Пермский край', 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$repository->save_mapping( array( 'location_id' => 11, 'yandex_geo_id' => 1102, 'yandex_locality' => 'Manual, Пермский край', 'status' => YandexDeliveryGeoMappingStatus::MANUAL, 'confidence' => 100, 'is_primary' => 0 ) );
$repository->save_technical_error_marker( 12, 'Техошибка', 'timeout' );
$cleanup = $repository->cleanup_needs_review_by_region( 'Адыг' );
yd_geo_manual_assert( array( 'matched_locations' => 2, 'checked_candidates' => 3, 'removed_candidates' => 2, 'converted_to_not_found' => 1 ) === $cleanup, 'cleanup_needs_review_by_region() must remove mismatching candidates by partial region fragment and convert empty locations to not_found.' );
$rows8 = $repository->find_by_location_id( 8 );
yd_geo_manual_assert( 1 === count( array_filter( $rows8, static fn( array $row ): bool => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === (string) $row['status'] ) ) && 801 === (int) $rows8[0]['yandex_geo_id'], 'Region cleanup must keep candidate whose Yandex locality or raw_json address contains the region fragment.' );
$rows9 = $repository->find_by_location_id( 9 );
yd_geo_manual_assert( 1 === count( $rows9 ) && YandexDeliveryGeoMappingStatus::NOT_FOUND === (string) $rows9[0]['status'], 'Region cleanup must convert a location to not_found when all needs_review candidates are removed and no primary exists.' );
yd_geo_manual_assert( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === (string) $repository->find_by_location_id( 10 )[0]['status'], 'Region cleanup must skip locations whose region_name does not contain the fragment.' );
yd_geo_manual_assert( 2 === count( $repository->find_by_location_id( 11 ) ) && 1101 === $repository->find_primary_geo_id( 11 ), 'Region cleanup must not delete mapped/manual rows.' );
yd_geo_manual_assert( YandexDeliveryGeoMappingRepository::TECHNICAL_ERROR_GEO_ID === (int) $repository->find_by_location_id( 12 )[0]['yandex_geo_id'], 'Region cleanup must not delete technical marker rows.' );

$repository_source = file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php' ) ?: '';
$admin_source = file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$project_status = file_get_contents( WDC_PLUGIN_DIR . 'docs/project-status.md' ) ?: '';
$plugin_source = file_get_contents( WDC_PLUGIN_DIR . 'walls-delivery-calc.php' ) ?: '';

yd_geo_manual_assert( str_contains( $repository_source, 'function find_needs_review_locations' ) && str_contains( $repository_source, 'function approve_mapping' ) && str_contains( $repository_source, 'function bulk_reject_locations' ) && str_contains( $repository_source, 'function cleanup_needs_review_by_region' ) && str_contains( $repository_source, 'contains_fragment' ), 'Repository must expose manual review and region cleanup methods.' );
yd_geo_manual_assert( str_contains( $repository_source, 'REGION_CLEANUP_LOCATION_BATCH = 500' ) && str_contains( $repository_source, 'count_region_cleanup_locations' ) && str_contains( $repository_source, 'count_region_cleanup_candidates' ) && str_contains( $repository_source, 'find_region_cleanup_location_ids_after' ) && str_contains( $repository_source, 'DELETE m FROM' ) && ! str_contains( $repository_source, 'SELECT m.*, l.region_name AS location_region_name FROM ' ), 'Region cleanup real DB path must use SQL counts, batched location ids and DELETE JOIN instead of loading all candidates.' );
yd_geo_manual_assert( str_contains( $repository_source, 'candidate_contains_region_fragment' ) && str_contains( $repository_source, "(string) ( \$row['raw_json'] ?? '' )" ) && str_contains( $repository_source, 'm.raw_json IS NULL OR m.raw_json NOT LIKE %s' ), 'Region cleanup must keep candidates when raw_json variant/address contains the region fragment.' );
yd_geo_manual_assert( str_contains( $repository_source, 'SELECT DISTINCT m.location_id FROM {$mapping_table}' ) && str_contains( $repository_source, 'LIMIT %d OFFSET %d' ) && str_contains( $repository_source, 'm.location_id IN ({$placeholders})' ), 'Needs review queue real DB path must page grouped location_id values before loading candidate rows.' );
preg_match( '/private function render_yandex_delivery_geo_manual_review_section[\s\S]*?private function render_yandex_delivery_geo_analysis_tab/', $admin_source, $manual_match );
$manual_section = (string) ( $manual_match[0] ?? '' );
yd_geo_manual_assert( str_contains( $manual_section, 'style="width: 100%;"' ) && str_contains( $manual_section, 'widefat striped' ), 'Manual queue table must use full available width.' );
yd_geo_manual_assert( ! str_contains( $manual_section, '<th><?php echo esc_html__( \'Отказ\'' ) && str_contains( $manual_section, 'Подтвердить' ), 'Manual queue must remove reject column and keep approve column.' );
yd_geo_manual_assert( str_contains( $manual_section, '<strong><code>geo_id=' ) && str_contains( $manual_section, 'class="description">confidence=' ), 'Candidate block must show the first-line geo_id/address in bold and separate diagnostic text.' );
yd_geo_manual_assert( ! str_contains( $manual_section, 'placeholder="<?php echo esc_attr( __( \'Комментарий' ) && str_contains( $manual_section, 'Отказать выбранным в сопоставлении' ), 'Manual queue must remove reject comments and expose bulk reject action.' );
yd_geo_manual_assert( str_contains( $manual_section, 'wdc-yandex-geo-review-select-page' ) && str_contains( $manual_section, 'wdc-yandex-geo-review-location-checkbox' ), 'Manual queue must provide select-page checkbox.' );
yd_geo_manual_assert( str_contains( $admin_source, 'approve_yandex_delivery_geo_mapping' ) && str_contains( $admin_source, 'reject_yandex_delivery_geo_mapping' ) && str_contains( $admin_source, 'bulk_reject_yandex_delivery_geo_mapping' ) && str_contains( $admin_source, 'cleanup_yandex_delivery_geo_needs_review_by_region' ) && str_contains( $admin_source, 'Очистка needs_review по региону' ), 'Manual review POST actions and region cleanup UI must exist.' );
yd_geo_manual_assert( str_contains( $manual_section, 'name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>"' ) && str_contains( $manual_section, 'name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>"' ) && str_contains( $manual_section, 'name="service" value="<?php echo esc_attr( $service->service_key ); ?>"' ) && str_contains( $manual_section, 'name="tab" value="yandex_delivery_geo"' ), 'Region cleanup form must preserve page/service/service_key/tab hidden fields.' );
yd_geo_manual_assert( substr_count( $admin_source, 'cleanup_yandex_delivery_geo_needs_review_by_region' ) >= 5 && str_contains( $admin_source, "'cleanup_yandex_delivery_geo_needs_review_by_region' => 'yandex_delivery_geo'" ) && str_contains( $admin_source, "'title' => 'Очистка needs_review по региону'" ), 'Region cleanup must be registered for handling, redirected back to mapping tab, and show action result title.' );
yd_geo_manual_assert( ! str_contains( $manual_section, '->cleanup_needs_review_by_region(' ) && 1 === substr_count( $admin_source, '->cleanup_needs_review_by_region(' ), 'Manual review render must not execute region cleanup; cleanup must be called only from the POST handler.' );
yd_geo_manual_assert( str_contains( $admin_source, 'yandex_delivery_geo_show_analysis' ) && str_contains( $admin_source, 'Показать аналитику' ) && str_contains( $admin_source, '! $show_analysis' ), 'Mapping tab render must not call heavy analysis by default.' );
yd_geo_manual_assert( str_contains( $admin_source, 'is_running()' ) && str_contains( $admin_source, 'Ручная обработка будет доступна после завершения или постановки процесса на паузу.' ) && ! str_contains( $admin_source, 'Ручная обработка временно заблокирована' ), 'Manual review handlers/UI must keep runner running guard.' );
yd_geo_manual_assert( str_contains( $admin_source, 'max_location_id_exclusive' ) && str_contains( $admin_source, "array( 'full', 'unprocessed' )" ) && str_contains( $admin_source, 'Очередь ограничена уже обработанной частью полного маппинга' ) && str_contains( $admin_source, 'Ручная обработка доступна только для уже обработанной части полного маппинга' ), 'Admin source must guard paused full/unprocessed-runner manual review by next_location_id.' );
yd_geo_manual_assert( str_contains( $plugin_source, 'Version: 0.91.3' ) && str_contains( $plugin_source, "WDC_VERSION', '0.91.3" ), 'Plugin version must be 0.91.3.' );
yd_geo_manual_assert( str_contains( $project_status, '0.91.3 Yandex Geo Manual Region Cleanup Redirect' ), 'Project status must document manual region cleanup.' );

echo "Yandex Delivery geo manual review smoke test passed.\n";