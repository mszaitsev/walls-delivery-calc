<?php
declare(strict_types=1);
$root = dirname( __DIR__, 2 );
$admin = file_get_contents( $root . '/src/Carriers/OzonDelivery/Admin/OzonDeliveryAdminPage.php' ) ?: '';
$routing = file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
function oz_pickup_admin_assert( bool $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } }
oz_pickup_admin_assert( str_contains( $routing, "\$tabs['ozon_pickup'] = 'ПВЗ Ozon'" ) && str_contains( $routing, 'render_ozon_pickup_tab' ) && str_contains( $admin, 'Обновить справочник ПВЗ' ), 'Ozon pickup must be a carrier-specific standard service tab.' );
oz_pickup_admin_assert( str_contains( $admin, 'wp_nonce_field' ) && str_contains( $admin, 'ozon_delivery_pickup_auto_sync' ) && str_contains( $admin, 'Время по Новосибирску (GMT+7)' ), 'Pickup controls require the standard secure admin form and WDC timezone note.' );
oz_pickup_admin_assert( str_contains( $plugin, 'OzonDeliveryPickupScheduler::class' ), 'Pickup is wired carrier-owned through Plugin.php.' );
oz_pickup_admin_assert( str_contains( $admin, 'wdc_ozon_pickup_notice' ) && str_contains( $admin, 'Не удалось запустить синхронизацию ПВЗ Ozon.' ) && str_contains( $routing, "'wdc_ozon_pickup_notice', 'failed'" ), 'Failed manual starts must return a safe administrator-visible result.' );
oz_pickup_admin_assert( str_contains( $admin, 'Остановить импорт' ) && str_contains( $admin, 'stop_ozon_delivery_pickup_import' ) && str_contains( $admin, 'data-wdc-ozon-stop' ), 'Running Ozon pickup import must expose a secure manual stop action.' );
oz_pickup_admin_assert( str_contains( $admin, 'Проверка локального справочника' ) && str_contains( $admin, 'pickup_preview' ) && str_contains( $admin, 'OzonDeliveryPickupPointProvider' ) && str_contains( $admin, 'Найти ПВЗ в локальном справочнике' ), 'Pickup UI must offer a bounded local provider preview.' );
oz_pickup_admin_assert( str_contains( $admin, 'Показано ПВЗ в радиусе 20 км:' ) && str_contains( $admin, 'Диагностическая выборка ограничена 20 точками.' ) && str_contains( $admin, "count( \$preview['points'] )" ) && ! str_contains( $admin, 'Найдено ПВЗ в радиусе 20 км' ), 'Preview must label its dynamic diagnostic sample without claiming a total.' );
oz_pickup_admin_assert( ! str_contains( $admin, 'OzonDeliveryApiClient' ) && ! str_contains( $admin, 'access_token' ) && ! str_contains( $admin, 'Authorization' ), 'Pickup UI must not call API or render token data.' );
echo "Ozon Delivery pickup admin smoke passed.\n";
