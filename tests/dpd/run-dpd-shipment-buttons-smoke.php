<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentButtonPolicy;

function dpd_buttons_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_buttons_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_buttons_options'][ $key ] = $value; return true; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }

$policy = new DpdShipmentButtonPolicy();
dpd_buttons_assert( $policy->resolve( array() ) === array( 'create' => true, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => false ), 'No DPD shipment must expose create/manual only.' );
$pending = $policy->resolve( array( 'carrier_key' => 'dpd', 'dpd_registration_state' => 'pending' ) );
dpd_buttons_assert( $pending['update'] && $pending['remove'] && ! $pending['create'] && ! $pending['manual_attach'] && ! $pending['cancel'], 'Pending DPD registration must expose update/remove only.' );
foreach ( array( '1001', '1101', '1201', '1401', '1501' ) as $code ) { dpd_buttons_assert( $policy->resolve( array( 'dpd_order_number' => 'DPD1', 'dpd_event_code' => $code ) )['cancel'], 'DPD event ' . $code . ' must allow cancel.' ); }
dpd_buttons_assert( ! $policy->resolve( array( 'dpd_order_number' => 'DPD1', 'dpd_event_code' => '1301' ) )['cancel'] && $policy->resolve( array( 'dpd_order_number' => 'DPD1', 'dpd_event_code' => '1301' ) )['remove'], 'DPD 1301 must hide cancel and show remove.' );
dpd_buttons_assert( ! $policy->resolve( array( 'dpd_order_number' => 'DPD1', 'dpd_event_code' => '2201' ) )['cancel'], 'Other DPD event codes must not allow cancel.' );
$adapter = new DpdShipmentAdapter( new DpdShipmentPayloadBuilder( new DpdSettings( new SettingsRepository(), new EncryptionService() ) ), null, null, $policy );
dpd_buttons_assert( array() === $adapter->label_actions( new stdClass(), array( 'dpd_order_number' => 'DPD1' ) ), 'DPD documents/labels action must be absent.' );
$presentation = $adapter->presentation();
dpd_buttons_assert( 'Внести номер DPD вручную' === $presentation['manual_attach_button_label'] && 'Номер DPD' === $presentation['manual_attach_placeholder'], 'DPD manual attach UI text must be configured.' );
$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
dpd_buttons_assert( str_contains( $source, 'temporary_can_remove' ) && str_contains( $source, 'startDpdRegistrationPolling' ) && str_contains( $source, 'registration_attempt_id' ), 'Admin JS must include DPD temporary remove and two-stage polling markers.' );
echo "DPD shipment buttons smoke passed
";
