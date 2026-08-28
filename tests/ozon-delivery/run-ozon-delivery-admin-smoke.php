<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
function oau_assert( bool $ok, string $message ): void { if ( ! $ok ) { fwrite( STDERR, "[FAIL] {$message}\n" ); exit( 1 ); } }
$root = dirname( __DIR__, 2 ); $admin = file_get_contents( $root . '/src/Carriers/OzonDelivery/Admin/OzonDeliveryAdminPage.php' ) ?: ''; $routing = file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: ''; $repository = file_get_contents( $root . '/src/DeliveryServices/DeliveryServiceRepository.php' ) ?: ''; $manager = file_get_contents( $root . '/src/DeliveryServices/DeliveryServiceManager.php' ) ?: ''; $plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
oau_assert( str_contains( $repository, 'ensure_ozon_delivery_service' ) && str_contains( $repository, 'OzonDeliverySettings::SERVICE_KEY' ) && str_contains( $manager, 'ozon_delivery_service_exists' ) && str_contains( $manager, "array( 'RU' )" ), 'Ozon must be an idempotent builtin DeliveryService with RU initial country.' );
oau_assert( str_contains( $routing, "'main' => 'Основное'" ) && str_contains( $routing, "'calculation' => 'Расчет'" ) && str_contains( $routing, "'rules' => 'Правила'" ) && str_contains( $routing, "\$tabs['ozon_api'] = 'API Ozon'" ) && str_contains( $routing, 'render_ozon_api_tab' ), 'Ozon must use generic service tabs with one carrier-specific API Ozon tab.' );
oau_assert( ! str_contains( $routing, 'class="button" href="<?php echo esc_url( admin_url( \'admin.php?page=\' . self::MENU_SLUG . \'&service=\' . OzonDeliverySettings::SERVICE_KEY ) ); ?>"' ), 'standalone Ozon shortcut must not render before the service table.' );
oau_assert( str_contains( $admin, 'Client Secret сохранён' ) && ! str_contains( $admin, 'value="<?php echo esc_attr( $this->credentials->client_secret()' ), 'secret must never render in HTML.' );
oau_assert( str_contains( $admin, 'Самостоятельная сдача.' ) && str_contains( $admin, 'Забор курьером со склада отправителя не используется.' ), 'self-drop-off invariant must be visible.' );
oau_assert( ! str_contains( $admin, 'Redirect URI' ) && ! str_contains( $admin, 'callback' ) && ! str_contains( $admin, 'Подключить Ozon' ), 'client_credentials admin UI must have no redirect/callback controls.' );
oau_assert( str_contains( $admin, 'HTTP status' ) && str_contains( $admin, 'Код ошибки' ) && str_contains( $admin, 'Операция' ) && str_contains( $admin, 'Структура ответа' ) && str_contains( $admin, 'Размер ответа' ), 'admin diagnostic must render safe OAuth evidence.' );
oau_assert( ! str_contains( $plugin, 'OzonDeliveryCarrier' ) && ! str_contains( $plugin, 'OzonDeliveryShipment' ), 'foundation must not register Ozon checkout or shipment runtime.' );
echo "Ozon Delivery admin smoke passed.\n";
