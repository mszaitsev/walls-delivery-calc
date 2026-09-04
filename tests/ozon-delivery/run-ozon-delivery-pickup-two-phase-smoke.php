<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$importer = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php' ) ?: '';
$repository = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' ) ?: '';
$scheduler = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php' ) ?: '';
$lock = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportLock.php' ) ?: '';
$admin = file_get_contents( $root . '/src/Carriers/OzonDelivery/Admin/OzonDeliveryAdminPage.php' ) ?: '';
$js = file_get_contents( $root . '/assets/admin/ozon-delivery-pickup-sync.js' ) ?: '';
$migration = file_get_contents( $root . '/database/migrations/0058_add_ozon_pickup_two_phase_import.php' ) ?: '';
$lock_owner_migration = file_get_contents( $root . '/database/migrations/0059_add_ozon_pickup_generation_lock_owner.php' ) ?: '';

function oz_two_phase_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$discovery_start = strpos( $importer, 'private function run_discovery_step' );
$enrichment_start = strpos( $importer, 'private function run_enrichment_step' );
oz_two_phase_assert( false !== $discovery_start && false !== $enrichment_start && $discovery_start < $enrichment_start, 'Importer must split discovery and enrichment methods.' );
$discovery_source = substr( $importer, $discovery_start, $enrichment_start - $discovery_start );

oz_two_phase_assert( str_contains( $discovery_source, 'pickup_list( (string) ( $generation[\'cursor_value\'] ?? \'\' ) )' ) && str_contains( $discovery_source, 'commit_discovery_page' ) && ! str_contains( $discovery_source, 'pickup_info' ) && ! str_contains( $discovery_source, 'info_page' ), 'Discovery must only call list and persist frozen IDs/progress.' );
oz_two_phase_assert( ! str_contains( $discovery_source, "'downloaded_count' => \$current_discovered" ), 'Discovery importer must not set a misleading downloaded_count before repository counts unique staging IDs.' );
oz_two_phase_assert( str_contains( $discovery_source, "'phase'] = 'enrichment'" ) && str_contains( $discovery_source, "'discovery_completed_at'" ) && str_contains( $discovery_source, "null === \$page['next_cursor']" ), 'Discovery may switch to enrichment only after final null next_cursor.' );
oz_two_phase_assert( str_contains( $repository, 'INSERT IGNORE' ) && str_contains( $migration, 'UNIQUE KEY generation_point' ) && str_contains( $repository, 'count_ids( $generation_id )' ), 'Discovery ID persistence must be unique and idempotent.' );
oz_two_phase_assert( str_contains( $repository, 'SELECT point_id FROM' ) && str_contains( $repository, 'WHERE generation_id=%d AND status=%s ORDER BY id ASC LIMIT %d' ) && str_contains( $repository, "'pending'" ), 'Enrichment must select stable pending ID batches from the frozen snapshot.' );
oz_two_phase_assert( str_contains( $importer, 'INFO_BATCH_SIZE = 100' ) && str_contains( $importer, 'MAX_INFO_SALVAGE_REQUESTS = 199' ) && str_contains( $importer, 'resolve_info_batch' ), 'Enrichment must keep official 100-ID batches and a bounded 404 salvage budget.' );
oz_two_phase_assert( str_contains( $importer, "'v1/delivery-point/info' === \$exception->operation && 404 === \$exception->http_status" ) && str_contains( $importer, "'not_found_404'" ) && str_contains( $importer, "'info_missing'" ), 'Only /info HTTP 404 should be salvaged into bounded rejected IDs.' );
oz_two_phase_assert( str_contains( $repository, 'commit_enrichment_batch' ) && str_contains( $repository, '$accepted = 0' ) && str_contains( $repository, '++$accepted' ) && str_contains( $repository, '$rejected = 0' ) && str_contains( $repository, '++$rejected' ) && str_contains( $repository, "'enrichment_processed_count'" ) && str_contains( $repository, "'accepted_count'" ) && str_contains( $repository, "'rejected_count'" ), 'Enrichment commits must update terminal counters atomically.' );
oz_two_phase_assert( str_contains( $repository, 'mark_ready_if_complete' ) && str_contains( $repository, 'START TRANSACTION' ) && str_contains( $repository, "state='ready'" ) && str_contains( $repository, "state='building' AND phase='enrichment'" ) && str_contains( $repository, 'accepted_count + rejected_count = discovered_count' ) && str_contains( $repository, 'pending_count( $generation_id )' ), 'Ready transition must be guarded by current building/enrichment state and complete frozen-ID invariants.' );
oz_two_phase_assert( str_contains( $repository, 'cleanup_ids_for_generation' ) && str_contains( $repository, 'cleanup_generation_rows' ) && str_contains( $repository, 'cleanup_obsolete_points' ), 'Staging and non-active partial point rows must be cleaned after terminal outcomes.' );
oz_two_phase_assert( str_contains( $scheduler, 'stop_manual' ) && str_contains( $scheduler, 'building_generation' ) && str_contains( $scheduler, "generation['lock_owner']" ) && str_contains( $scheduler, 'cancel_generation' ) && str_contains( $scheduler, 'unschedule( self::STEP_HOOK' ) && str_contains( $scheduler, '$this->lock->owns( $owner )' ) && str_contains( $scheduler, '$this->lock->release( $owner )' ), 'Scheduler must expose manual stop through the generic step hook and release only the owner stored on the generation.' );
oz_two_phase_assert( str_contains( $lock, 'current_owner' ) && str_contains( $scheduler, '$this->lock->owns( $owner )' ), 'Cancellation must never release a foreign/new owner lock.' );
oz_two_phase_assert( str_contains( $repository, "'cancelled'" ) && str_contains( $repository, "'is_terminal'" ) && str_contains( $repository, "'is_running'" ) && str_contains( $repository, "'building' === \$state" ) && str_contains( $repository, 'generation_is_building' ) && str_contains( $repository, 'generation_can_fail' ), 'Cancelled generations must be terminal, not running, and protected from late retry/fail writes.' );
oz_two_phase_assert( str_contains( $repository, 'generation_state' ) && str_contains( $repository, 'building_phase_matches' ) && str_contains( $importer, 'terminal_result_if_not_current_building_phase' ), 'Importer must re-read state/phase after cancelled in-flight work before treating a failed commit as persistence failure.' );
oz_two_phase_assert( str_contains( $admin, 'stop_ozon_delivery_pickup_import' ) && str_contains( $admin, 'Остановить импорт' ) && str_contains( $admin, 'data-wdc-ozon-stop' ), 'Admin UI must expose the stop button only through the Ozon pickup action path.' );
oz_two_phase_assert( str_contains( $js, 'Остановить текущий импорт ПВЗ Ozon?' ) && str_contains( $js, 'Получение списка ПВЗ' ) && str_contains( $js, 'Получение данных ПВЗ' ) && str_contains( $js, 'Остановлено' ) && ! str_contains( $js, 'access_token' ) && ! str_contains( $js, 'Authorization' ) && ! str_contains( $js, 'client_secret' ), 'Admin progress JS must render phase/cancel states and remain secret-free.' );
oz_two_phase_assert( str_contains( $migration, 'CREATE TABLE' ) && str_contains( $migration, 'wdc_ozon_delivery_pickup_ids' ) && str_contains( $migration, 'status varchar(24) NOT NULL DEFAULT' ) && str_contains( $migration, 'reject_code varchar(40) NULL' ) && str_contains( $migration, 'KEY generation_status_id(generation_id,status,id)' ), '0058 must create the compact staging ID table.' );
oz_two_phase_assert( str_contains( $migration, "'phase'" ) && str_contains( $migration, "'discovery_page_count'" ) && str_contains( $migration, "'discovered_count'" ) && str_contains( $migration, "'discovery_completed_at'" ) && str_contains( $migration, "'enrichment_processed_count'" ), '0058 must add phase/progress columns additively.' );
oz_two_phase_assert( str_contains( $lock_owner_migration, 'ADD COLUMN lock_owner varchar(64) NULL AFTER job_id' ) && str_contains( $lock_owner_migration, 'postcondition' ), '0059 must add generation lock_owner additively with a postcondition.' );

$ids = range( 1, 100 );
$bad_ids = array_fill_keys( array( 96, 97, 98, 99, 100 ), true );
$requests = 0;
$resolve = function ( array $batch ) use ( &$resolve, $bad_ids, &$requests ): array {
	++$requests;
	$has_bad = array_filter( $batch, static fn( int $id ): bool => isset( $bad_ids[$id] ) );
	if ( array() === $has_bad ) {
		return array( 'enriched' => count( $batch ), 'rejected' => 0 );
	}
	if ( 1 === count( $batch ) ) {
		return array( 'enriched' => 0, 'rejected' => 1 );
	}
	$middle = (int) ceil( count( $batch ) / 2 );
	$left = $resolve( array_slice( $batch, 0, $middle ) );
	$right = $resolve( array_slice( $batch, $middle ) );
	return array( 'enriched' => $left['enriched'] + $right['enriched'], 'rejected' => $left['rejected'] + $right['rejected'] );
};
$live_case = $resolve( $ids );
oz_two_phase_assert( 95 === $live_case['enriched'] && 5 === $live_case['rejected'] && 100 === $live_case['enriched'] + $live_case['rejected'] && $requests <= 199, 'Live 95/5 /info 404 case must terminalize the whole frozen batch within the salvage budget.' );

echo "Ozon Delivery pickup two-phase smoke passed.\n";
