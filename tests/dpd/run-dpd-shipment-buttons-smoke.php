<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/shipments/admin-js-bundle-source.php';

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
$reload_1401 = $policy->resolve( array( 'dpd_order_number' => 'DPD1', 'carrier_operation_code' => '1401' ) );
dpd_buttons_assert( $reload_1401['update'] && $reload_1401['cancel'] && ! $reload_1401['remove'], 'Reloaded DPD shipment with generic 1401 operation code must show update/cancel and hide remove.' );
$reload_1301 = $policy->resolve( array( 'dpd_order_number' => 'DPD1', 'carrier_operation_code' => '1301' ) );
dpd_buttons_assert( $reload_1301['update'] && ! $reload_1301['cancel'] && $reload_1301['remove'], 'Reloaded DPD shipment with generic 1301 operation code must show update/remove and hide cancel.' );
$adapter = new DpdShipmentAdapter( new DpdShipmentPayloadBuilder( new DpdSettings( new SettingsRepository(), new EncryptionService() ) ), null, null, $policy );
$payload = $adapter->status_payload( new stdClass(), array( 'dpd_sent_places' => array( array( 'number' => '1', 'weight_kg' => 6.7, 'length_cm' => 38, 'width_cm' => 24, 'height_cm' => 24 ), array( 'number' => '2', 'weight_kg' => 1.2, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10 ) ) ) );
$created_payload = $adapter->status_payload( new stdClass(), array( 'dpd_order_number' => 'RUNEW', 'dpd_event_code' => '1401', 'dpd_event_marker' => 'OrderCreate', 'dpd_event_name' => 'Заказ создан', 'dpd_event_time' => '2026-06-21T19:05:01+03:00', 'tracking_checked_at' => '2026-06-21 19:05:05' ) );
dpd_buttons_assert( '1401' === $created_payload['carrier_operation_code'] && 'OrderCreate' === $created_payload['carrier_operation_marker'] && 'Заказ создан' === $created_payload['carrier_status_title'], 'DPD status payload after create+1401 must expose event fields for UI.' );
dpd_buttons_assert( $created_payload['can_update_status'] && $created_payload['can_cancel'] && ! $created_payload['can_remove_from_order'] && ! empty( $created_payload['can_download_dpd_documents'] ), 'Initial DPD status payload for 1401 must show update/cancel/download and hide remove.' );
$reload_payload = $adapter->status_payload( new stdClass(), array( 'dpd_order_number' => 'RUNEW', 'carrier_operation_code' => '1401' ) );
dpd_buttons_assert( $reload_payload['can_update_status'] && $reload_payload['can_cancel'] && ! $reload_payload['can_remove_from_order'], 'Reloaded DPD shipment after create+1401 must keep update/cancel and hide remove.' );
$initial_1301_payload = $adapter->status_payload( new stdClass(), array( 'dpd_order_number' => 'RUNEW', 'dpd_event_code' => '1301' ) );
dpd_buttons_assert( $initial_1301_payload['can_update_status'] && ! $initial_1301_payload['can_cancel'] && $initial_1301_payload['can_remove_from_order'] && empty( $initial_1301_payload['can_download_dpd_documents'] ), 'Initial DPD status payload for 1301 must show update/remove and hide cancel/download.' );
dpd_buttons_assert( $created_payload['can_cancel'] === $reload_payload['can_cancel'] && $created_payload['can_remove_from_order'] === $reload_payload['can_remove_from_order'], 'Initial and AJAX-like DPD 1401 payloads must expose the same button policy.' );
dpd_buttons_assert( str_contains( (string) ( $payload['dpd_places_summary'] ?? '' ), '1) 6.7 кг, 38×24×24 см' ) && str_contains( (string) ( $payload['dpd_places_summary'] ?? '' ), '2) 1.2 кг, 20×15×10 см' ), 'DPD status payload must expose manager-readable sent places summary.' );
$presentation = $adapter->presentation();
dpd_buttons_assert( 'Внести номер DPD вручную' === $presentation['manual_attach_button_label'] && 'Номер DPD' === $presentation['manual_attach_placeholder'], 'DPD manual attach UI text must be configured.' );
$source = wdc_shipment_admin_js_bundle_source();
dpd_buttons_assert( str_contains( $source, 'temporary_can_remove' ) && str_contains( $source, 'continueShipmentLifecycle' ) && str_contains( $source, 'continueLifecycleAction' ) && ! str_contains( $source, 'startDpdRegistrationPolling' ) && ! str_contains( $source, 'submitDpdRegistration' ), 'Admin JS must keep DPD temporary remove while using the neutral lifecycle continuation contract.' );
$css_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.css' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
dpd_buttons_assert( str_contains( $metabox_source, 'button_policy()->resolve' ) && str_contains( $metabox_source, "\$can_cancel = ! empty( \$button_policy['can_cancel'] )" ), 'Initial metabox render must use DPD status payload for cancel button visibility.' );
dpd_buttons_assert( str_contains( $metabox_source, 'wdc-shipment-inline-spinner' ) && str_contains( $css_source, '.wdc-shipment-inline-spinner' ) && str_contains( $css_source, '@keyframes wdc-shipment-spin' ), 'Registration polling indicator must render a real animated CSS spinner.' );
dpd_buttons_assert( str_contains( $source, 'setShipmentPollingIndicator(box, true)' ) && str_contains( $source, 'setShipmentPollingIndicator(box, false)' ) && str_contains( $source, "indicator.hidden = !visible" ), 'Registration spinner must be visible only while polling is active.' );
dpd_buttons_assert( str_contains( $source, 'carrier_operation_code || status.carrier_operation_address' ) && str_contains( $source, 'carrier_operation_marker || status.carrier_operation_index' ), 'Admin JS operationSummary must render DPD date/code/marker with CDEK fallback.' );
dpd_buttons_assert( str_contains( $source, 'data-wdc-dpd-places-summary' ) && str_contains( $source, 'dpd_places_summary' ), 'Admin JS must render DPD sent places summary in the shipment block.' );
dpd_buttons_assert( str_contains( $source, 'data-wdc-status-updated' ) && str_contains( $source, 'updated_at' ), 'Admin JS must render updated_at when the shipment block provides it.' );
echo "DPD shipment buttons smoke passed
";
