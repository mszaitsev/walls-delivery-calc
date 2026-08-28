<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
function oau_assert( bool $ok, string $message ): void { if ( ! $ok ) { fwrite( STDERR, "[FAIL] {$message}\n" ); exit( 1 ); } }
$root = dirname( __DIR__, 2 ); $admin = file_get_contents( $root . '/src/Carriers/OzonDelivery/Admin/OzonDeliveryAdminPage.php' ) ?: ''; $routing = file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: ''; $plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
oau_assert( str_contains( $routing, 'OzonDeliverySettings::SERVICE_KEY') && str_contains( $routing, 'ozon_delivery_admin->render()' ), 'Ozon virtual route must be available under Delivery Services.' );
oau_assert( str_contains( $admin, 'Client Secret сохранён' ) && ! str_contains( $admin, 'value="<?php echo esc_attr( $this->credentials->client_secret()' ), 'secret must never render in HTML.' );
oau_assert( str_contains( $admin, 'Самостоятельная сдача.' ) && str_contains( $admin, 'Забор курьером со склада отправителя не используется.' ), 'self-drop-off invariant must be visible.' );
oau_assert( ! str_contains( $admin, 'Redirect URI' ) && ! str_contains( $admin, 'callback' ) && ! str_contains( $admin, 'Подключить Ozon' ), 'client_credentials admin UI must have no redirect/callback controls.' );
oau_assert( ! str_contains( $plugin, 'OzonDeliveryCarrier' ) && ! str_contains( $plugin, 'OzonDeliveryShipment' ), 'foundation must not register Ozon checkout or shipment runtime.' );
echo "Ozon Delivery admin smoke passed.\n";
