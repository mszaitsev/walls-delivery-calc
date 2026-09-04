<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$admin = file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$pickup_admin = file_get_contents( $root . '/src/Carriers/OzonDelivery/Admin/OzonDeliveryAdminPage.php' ) ?: '';
$repository = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' ) ?: '';
$js = file_get_contents( $root . '/assets/admin/ozon-delivery-pickup-sync.js' ) ?: '';

function oz_progress_assert( bool $value, string $message ): void {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
}

oz_progress_assert( str_contains( $admin, 'wp_ajax_wdc_ozon_delivery_pickup_status' ) && str_contains( $admin, 'check_ajax_referer' ) && str_contains( $admin, 'current_user_can' ) && str_contains( $admin, 'pickup_status()' ), 'Ozon progress AJAX must be capability/nonce-protected and read local status only.' );
oz_progress_assert( str_contains( $repository, 'progress_updated_at' ) && str_contains( $repository, "'is_running'" ) && str_contains( $repository, "'building' === \$state" ) && str_contains( $repository, "'active_count'" ) && str_contains( $repository, "'safe_error_operation'" ) && str_contains( $repository, "'failed_cursor'" ), 'status model must expose safe generation progress, diagnostics and active snapshot metadata.' );
oz_progress_assert( str_contains( $repository, "'phase'" ) && str_contains( $repository, "'discovery_page_count'" ) && str_contains( $repository, "'discovered_count'" ) && str_contains( $repository, "'enrichment_processed_count'" ) && str_contains( $repository, "'pending_count'" ) && str_contains( $repository, "'cancelled'" ), 'status model must expose phase-aware counters and cancelled as terminal.' );
oz_progress_assert( str_contains( $pickup_admin, 'Фаза' ) && str_contains( $pickup_admin, 'Обработано страниц списка' ) && str_contains( $pickup_admin, 'Получено ID' ) && str_contains( $pickup_admin, 'Осталось' ) && str_contains( $pickup_admin, 'Остановить импорт' ), 'admin pickup tab must render phase-aware progress and stop action.' );
oz_progress_assert( str_contains( $js, 'wdc_ozon_delivery_pickup_status' ) && str_contains( $js, 'pending' ) && str_contains( $js, 'setInterval' ) && str_contains( $js, 'stop()' ) && str_contains( $js, 'Синхронизация давно не обновлялась' ), 'polling must prevent overlap, stop on terminal state and warn about a stall.' );
oz_progress_assert( str_contains( $js, 'Повтор запроса' ) && str_contains( $js, 'safe_error_operation' ) && str_contains( $js, 'failed_attempt' ) && str_contains( $js, 'safe_error_retryable' ), 'progress UI must surface safe retry/final failure diagnostics.' );
oz_progress_assert( str_contains( $js, 'Получение списка ПВЗ' ) && str_contains( $js, 'Получение данных ПВЗ' ) && str_contains( $js, 'Остановлено' ) && str_contains( $js, 'Остановить текущий импорт ПВЗ Ozon?' ), 'progress UI must render discovery/enrichment/cancelled states and confirm manual stop.' );
oz_progress_assert( ! str_contains( $js, 'MAX_PAGES' ) && ! str_contains( $js, '%' ) && ! str_contains( $js, 'access_token' ) && ! str_contains( $js, 'Authorization' ) && ! str_contains( $js, 'Bearer' ) && ! str_contains( $js, 'client_secret' ), 'progress UI must be indeterminate and contain no secrets.' );

echo "Ozon Delivery pickup progress smoke passed.\n";
