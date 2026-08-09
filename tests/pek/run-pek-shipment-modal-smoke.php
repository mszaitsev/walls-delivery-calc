<?php
declare(strict_types=1);

function pek_modal_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', $root . '/src' ) )->register();

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string { unset( $domain ); return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed { unset( $key ); return $default; }
}

$plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
$modal = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentModalExtension.php' ) ?: '';
$metabox = file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' ) ?: '';
$core_js = file_get_contents( $root . '/assets/admin/shipments/shipment-core.js' ) ?: '';
$picker_js = file_get_contents( $root . '/assets/admin/shipments/shipment-picker.js' ) ?: '';
$preview_js = file_get_contents( $root . '/assets/admin/shipments/shipment-preview.js' ) ?: '';
$status_js = file_get_contents( $root . '/assets/admin/shipments/shipment-status.js' ) ?: '';
$pek_js = file_get_contents( $root . '/assets/admin/shipments/extensions/pek.js' ) ?: '';

pek_modal_assert( str_contains( $plugin, 'PekShipmentModalExtension::class' ), 'PEK modal extension must be registered.' );
pek_modal_assert( str_contains( $modal, 'data-wdc-pek-sender-warehouse-id' ), 'Modal must expose carrier-owned sender warehouse override field.' );
pek_modal_assert( str_contains( $modal, 'pek_sender_warehouse_default_id' ) && str_contains( $modal, 'pek_sender_warehouse_override_id' ) && str_contains( $modal, 'pek_sender_warehouse_override_source' ), 'Modal must distinguish settings default sender warehouse from shipment-local override.' );
pek_modal_assert( str_contains( $modal, 'data-wdc-pek-sender-warehouse-context' ) && str_contains( $modal, 'data-latitude' ) && str_contains( $modal, 'data-longitude' ), 'Modal must pass safe current sender warehouse context to the picker.' );
pek_modal_assert( str_contains( $modal, 'recipient_type' ) && str_contains( $modal, 'physical' ), 'Modal must show physical recipient mode.' );
pek_modal_assert( ! str_contains( strtolower( $modal ), 'passport' ) && ! str_contains( $modal, 'identityCard' ), 'Modal must not request passport/identityCard.' );
pek_modal_assert( str_contains( $metabox, 'extensions/pek.js' ), 'PEK JS extension must be enqueued through extension chain.' );
pek_modal_assert( str_contains( $pek_js, "carrierKey: 'pek'" ), 'PEK JS must register carrier hooks.' );
pek_modal_assert( str_contains( $preview_js, "dispatchShipmentCarrierHook('afterPreviewUpdated'" ) && str_contains( $pek_js, 'afterPreviewUpdated' ), 'Shipment preview must allow carrier extensions to render safe technical preview diagnostics.' );
pek_modal_assert( str_contains( $pek_js, 'function smsStageLabel' ) && str_contains( $pek_js, 'Проверка выдачи по СМС' ) && str_contains( $pek_js, 'Проверка доступности услуги по направлению' ) && str_contains( $pek_js, 'Получение приватного токена' ) && str_contains( $pek_js, 'Проверка подключённых услуг контрагента' ) && str_contains( $pek_js, 'Проверка ответа об услуге СМС' ) && str_contains( $pek_js, 'Проверка лимита выдачи по СМС' ) && str_contains( $pek_js, 'Услуга недоступна по условиям ПЭК' ), 'PEK JS must render closed localized SMS diagnostic stages.' );
pek_modal_assert( str_contains( $pek_js, 'sms_diagnostic' ) && str_contains( $pek_js, 'field_errors' ) && str_contains( $pek_js, 'response_shape' ) && str_contains( $pek_js, 'textContent' ) && ! str_contains( $pek_js, 'innerHTML' ), 'PEK SMS diagnostic UI must render safe allowlisted fields without interpreting PEK HTML.' );
pek_modal_assert( str_contains( $picker_js, 'window.wdcShipmentPickupPicker' ) && str_contains( $pek_js, 'wdcShipmentPickupPicker' ), 'Generic picker API must have a working PEK consumer.' );
pek_modal_assert( ! str_contains( $modal, 'name="pickup_point_code"' ), 'PEK modal extension must not emit duplicate pickup_point_code.' );
pek_modal_assert( ! preg_match( "/carrier\\s*={2,3}\\s*['\"]pek['\"]|carrier\\s*!={1,2}\\s*['\"]pek['\"]/", $core_js . $preview_js . $status_js ), 'Generic shipment JS must not branch on PEK.' );

$settings = new \WallsShop\WDC\Carriers\Pek\PekSettings( new \WallsShop\WDC\Infrastructure\Settings\SettingsRepository(), new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$extension = new \WallsShop\WDC\Shipments\Pek\PekShipmentModalExtension( $settings );
$base_request = array(
	'carrier_key' => 'pek',
	'delivery_type' => 'courier',
	'recipient_address' => array( 'raw_address' => 'Россия, Московская область, Видное, улица Советская, дом 10, кв. 5' ),
	'meta' => array( 'pek_courier_address_evidence' => array( 'courier_address_source' => 'shipping_dadata' ) ),
);
$context = $extension->modal_context( new stdClass(), array( 'request' => $base_request ) );
ob_start();
$extension->render_courier_fields( new stdClass(), array( 'request' => $base_request ), $context );
$courier_html = (string) ob_get_clean();
pek_modal_assert( str_contains( $courier_html, 'Россия, Московская область, Видное, улица Советская, дом 10, кв. 5' ) && str_contains( $courier_html, 'shipping_dadata' ), 'Courier modal must display canonical shipping request address and source.' );

$billing_request = $base_request;
$billing_request['recipient_address']['raw_address'] = 'Россия, Санкт-Петербург, Невский проспект, дом 20, офис 7';
$billing_request['meta']['pek_courier_address_evidence']['courier_address_source'] = 'billing_dadata';
$billing_context = $extension->modal_context( new stdClass(), array( 'request' => $billing_request ) );
ob_start();
$extension->render_courier_fields( new stdClass(), array( 'request' => $billing_request ), $billing_context );
$billing_html = (string) ob_get_clean();
pek_modal_assert( str_contains( $billing_html, 'Невский проспект, дом 20, офис 7' ) && str_contains( $billing_html, 'billing_dadata' ), 'Courier modal must display billing fallback destination when draft request uses billing address.' );

$parsed_request = $base_request;
$parsed_request['recipient_address']['raw_address'] = 'Россия, Москва, улица Новая, дом 11';
$parsed_request['meta']['pek_courier_address_evidence']['courier_address_source'] = 'parsed_address_1';
$parsed_context = $extension->modal_context( new stdClass(), array( 'request' => $parsed_request ) );
ob_start();
$extension->render_courier_fields( new stdClass(), array( 'request' => $parsed_request ), $parsed_context );
$parsed_html = (string) ob_get_clean();
pek_modal_assert( str_contains( $parsed_html, 'улица Новая, дом 11' ) && ! str_contains( $parsed_html, 'Старый' ), 'Courier modal must display current Woo-derived address instead of stale DaData.' );

$pickup_request = array(
	'carrier_key' => 'pek',
	'delivery_type' => 'pickup',
	'pickup_point' => array( 'address' => 'Новосибирск, склад ПЭК, ул. Терминальная, 1' ),
	'meta' => array( 'pickup_point_row' => array( 'address' => 'fallback terminal' ), 'pickup_point_code' => 'warehouse-guid' ),
);
$pickup_context = $extension->modal_context( new stdClass(), array( 'request' => $pickup_request ) );
ob_start();
$extension->render_pickup_fields( new stdClass(), array( 'request' => $pickup_request ), $pickup_context );
$pickup_html = (string) ob_get_clean();
pek_modal_assert( str_contains( $pickup_html, 'Новосибирск, склад ПЭК, ул. Терминальная, 1' ) && ! str_contains( $pickup_html, 'warehouse-guid' ), 'Pickup modal must display selected terminal address instead of technical warehouse ID.' );
pek_modal_assert( ! str_contains( $courier_html . $billing_html . $pickup_html, 'token' ) && ! str_contains( $courier_html . $billing_html . $pickup_html, 'access_token' ), 'Modal render must not expose credentials, tokens, or raw API response fields.' );

echo "PEK shipment modal smoke passed.\n";
