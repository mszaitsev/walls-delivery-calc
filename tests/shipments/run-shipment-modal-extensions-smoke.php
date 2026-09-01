<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentModalExtension;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentModalExtension;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentModalExtension;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentModalExtension;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentModalExtension;

function modal_ext_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_attr__( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_NOQUOTES, 'UTF-8' ); }
function selected( mixed $selected, mixed $current, bool $display = true ): string { $out = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $out; } return $out; }
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string { $out = (bool) $disabled === (bool) $current ? ' disabled="disabled"' : ''; if ( $display ) { echo $out; } return $out; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }

function modal_ext_render( callable $callback ): string {
	ob_start();
	$callback();
	return (string) ob_get_clean();
}

final class ModalExtensionsFakeOrder {
	public function get_shipping_city(): string { return 'Новосибирск'; }
	public function get_shipping_state(): string { return 'Новосибирская область'; }
	public function get_shipping_postcode(): string { return '630001'; }
	public function get_shipping_address_1(): string { return 'Красный проспект 1'; }
	public function get_shipping_address_2(): string { return ''; }
}

final class ModalExtensionsFakeDb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $points = array();
	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$query = preg_replace( '/%[sdf]/', is_numeric( $value ) ? (string) (int) $value : "'" . (string) $value . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}
	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $output );
		if ( str_contains( $query, "state='active'" ) ) {
			return array( 'id' => 1, 'state' => 'active' );
		}
		if ( preg_match( '/point_id=(\d+)/', $query, $matches ) ) {
			return $this->points[ (int) $matches[1] ] ?? null;
		}
		return null;
	}
}

$ozon_db = new ModalExtensionsFakeDb();
$ozon_db->points[777] = array(
	'generation_id' => 1,
	'point_id' => 777,
	'name' => 'ПВЗ Ozon',
	'type' => 'pvz',
	'full_address' => 'Новосибирск, Красный проспект 1',
	'latitude' => 55.03,
	'longitude' => 82.92,
	'schedule' => '09:00-21:00',
	'is_active' => 1,
	'min_weight_g' => 1,
	'max_weight_g' => 10000,
	'max_length_mm' => 500,
	'max_width_mm' => 500,
	'max_height_mm' => 300,
);

$extensions = array(
	new CdekShipmentModalExtension(),
	new DpdShipmentModalExtension( static fn(): array => array( 'Иван Петров' ) ),
	new RussianPostShipmentModalExtension(),
	new YandexShipmentModalExtension(),
	new OzonDeliveryShipmentModalExtension( new OzonDeliveryPickupRepository( $ozon_db ) ),
);
$registry = new ShipmentModalExtensionRegistry( $extensions );
modal_ext_assert(
	array( CdekSettings::CARRIER_KEY, DpdSettings::CARRIER_KEY, RussianPostDomesticSettings::CARRIER_KEY, YandexDeliverySettings::CARRIER_KEY, OzonDeliverySettings::CARRIER_KEY ) === $registry->keys(),
	'Production modal extension keys must match shipment carriers.'
);
modal_ext_assert( $registry->get( 'unknown' ) === null, 'Unknown carrier must resolve to null.' );
foreach ( $extensions as $extension ) {
	modal_ext_assert( $extension instanceof CarrierShipmentModalExtensionInterface, 'Every modal extension must implement the interface.' );
}
try {
	new ShipmentModalExtensionRegistry( array( new CdekShipmentModalExtension(), new CdekShipmentModalExtension() ) );
	modal_ext_assert( false, 'Duplicate modal extension key must fail.' );
} catch ( InvalidArgumentException ) {
}

$order = new ModalExtensionsFakeOrder();
$services = array(
	array(
		'delivery_type' => 'pickup',
		'service_key' => 'pickup',
		'title' => 'ПВЗ',
		'tariffs' => array(
			array(
				'object_code' => 'T1',
				'title' => 'Тариф 1',
				'has_declared_value' => true,
				'delivery_mode' => 2,
			),
		),
	),
);
$base_request = array(
	'delivery_type' => 'pickup',
	'services' => array(),
	'recipient_address' => array(
		'postcode' => '630001',
		'region_name' => 'Новосибирская область',
		'city' => 'Новосибирск',
		'raw_address' => '630001, Новосибирск, Красный проспект 1',
	),
	'pickup_point' => array( 'point_code' => 'PVZ-1' ),
	'meta' => array(
		'tariff_object' => 'T1',
		'pickup_point_found' => true,
		'pickup_point_row' => array(
			'point_type' => 'PVZ',
			'point_title' => 'ПВЗ',
			'postcode' => '630001',
			'address' => 'Адрес ПВЗ',
		),
		'pickup_location_context' => array(
			'city_name' => 'Новосибирск',
			'region_name' => 'Новосибирская область',
			'postal_code' => '630001',
			'address' => 'Адрес ПВЗ',
		),
	),
);
$base_draft = array(
	'services' => $services,
	'request' => $base_request,
	'postoffice_codes' => array( '630005' ),
);

$cdek = $registry->get( CdekSettings::CARRIER_KEY );
modal_ext_assert( $cdek instanceof CdekShipmentModalExtension, 'CDEK extension must be registered.' );
$cdek_context = $cdek->modal_context( $order, $base_draft );
modal_ext_assert( is_array( $cdek_context['selected_service_tariffs'] ?? null ) && 'T1' === (string) ( $cdek_context['selected_tariff_object'] ?? '' ), 'CDEK modal context must own selected tariff data.' );
$cdek_fields_html = modal_ext_render( static fn() => $cdek->render_fields( $order, $base_draft, $cdek_context ) );
$cdek_pickup_html = modal_ext_render( static fn() => $cdek->render_pickup_fields( $order, $base_draft, $cdek_context ) );
modal_ext_assert( str_contains( $cdek_fields_html, 'name="shipment_point"' ) && str_contains( $cdek_fields_html, 'name="sender_shipment_point"' ) && str_contains( $cdek_fields_html, 'data-wdc-cdek-sender-door' ), 'CDEK render_fields must keep shipment point and sender door markup.' );
modal_ext_assert( str_contains( $cdek_pickup_html, 'name="delivery_point"' ) && str_contains( $cdek_pickup_html, 'name="pickup_point_code"' ), 'CDEK render_pickup_fields must keep recipient pickup hidden fields.' );

$dpd = $registry->get( DpdSettings::CARRIER_KEY );
modal_ext_assert( $dpd instanceof DpdShipmentModalExtension, 'DPD extension must be registered.' );
$dpd_draft = $base_draft;
$dpd_draft['request']['meta']['pickup_terminal_code'] = 'DPD-1';
$dpd_draft['request']['meta']['date_pickup'] = '2026-07-20';
$dpd_draft['request']['meta']['sender_terminal'] = array( 'address' => 'DPD terminal', 'city_name' => 'Новосибирск' );
$dpd_context = $dpd->modal_context( $order, $dpd_draft );
modal_ext_assert( ! empty( $dpd_context['requires_successful_preview'] ) && 'Создать отправление DPD' === (string) ( $dpd_context['modal_create_button_label'] ?? '' ), 'DPD modal context must own preview gate and modal create label.' );
$dpd_fields_html = modal_ext_render( static fn() => $dpd->render_fields( $order, $dpd_draft, $dpd_context ) );
modal_ext_assert( str_contains( $dpd_fields_html, 'name="pickup_terminal_code"' ) && str_contains( $dpd_fields_html, 'name="date_pickup"' ) && str_contains( $dpd_fields_html, 'data-wdc-dpd-contact-history' ), 'DPD render_fields must keep terminal/date/contact markup.' );

$russian_post = $registry->get( RussianPostDomesticSettings::CARRIER_KEY );
modal_ext_assert( $russian_post instanceof RussianPostShipmentModalExtension, 'Russian Post extension must be registered.' );
$rp_context = $russian_post->modal_context( $order, $base_draft );
modal_ext_assert( is_array( $rp_context['postoffice_codes'] ?? null ) && '' !== (string) ( $rp_context['pickup_display_value'] ?? '' ), 'Russian Post modal context must own postoffice and pickup presentation values.' );
$rp_fields_html = modal_ext_render( static fn() => $russian_post->render_fields( $order, $base_draft, $rp_context ) );
$rp_pickup_html = modal_ext_render( static fn() => $russian_post->render_pickup_fields( $order, $base_draft, $rp_context ) );
modal_ext_assert( str_contains( $rp_fields_html, 'name="postoffice_code"' ) && str_contains( $rp_pickup_html, 'name="pickup_point_code"' ) && str_contains( $rp_pickup_html, 'data-wdc-pickup-postcode-field' ), 'Russian Post extension must keep postoffice and pickup hidden fields.' );

$yandex = $registry->get( YandexDeliverySettings::CARRIER_KEY );
modal_ext_assert( $yandex instanceof YandexShipmentModalExtension, 'Yandex extension must be registered.' );
$yandex_draft = $base_draft;
$yandex_draft['request']['meta']['yandex_ready_from'] = '2026-07-20T10:00:00+07:00';
$yandex_draft['request']['meta']['yandex_ready_to'] = '2026-07-20T12:00:00+07:00';
$yandex_context = $yandex->modal_context( $order, $yandex_draft );
modal_ext_assert( false === (bool) ( $yandex_context['requires_tariff'] ?? true ), 'Yandex modal context must own no-tariff capability.' );
$yandex_fields_html = modal_ext_render( static fn() => $yandex->render_fields( $order, $yandex_draft, $yandex_context ) );
$yandex_courier_html = modal_ext_render( static fn() => $yandex->render_courier_fields( $order, $yandex_draft, $yandex_context ) );
modal_ext_assert( str_contains( $yandex_fields_html, 'name="yandex_source_platform_station_id"' ) && str_contains( $yandex_fields_html, 'name="yandex_ready_from"' ) && str_contains( $yandex_fields_html, 'data-wdc-yandex-source-station' ) && str_contains( $yandex_courier_html, 'name="courier_original_address"' ), 'Yandex extension must keep source station, ready interval and courier address markup.' );

$ozon = $registry->get( OzonDeliverySettings::CARRIER_KEY );
modal_ext_assert( $ozon instanceof OzonDeliveryShipmentModalExtension, 'Ozon Delivery extension must be registered.' );
$ozon_draft = $base_draft;
$ozon_draft['request']['meta']['pickup_point_code'] = '777';
$ozon_context = $ozon->modal_context( $order, $ozon_draft );
modal_ext_assert( true === (bool) ( $ozon_context['point_found'] ?? false ) && 10000 === (int) ( $ozon_context['max_weight_g'] ?? 0 ) && 500 === (int) ( $ozon_context['max_length_mm'] ?? 0 ) && 300 === (int) ( $ozon_context['max_height_mm'] ?? 0 ), 'Ozon modal context must expose selected pickup point limits.' );
$ozon_pickup_html = modal_ext_render( static fn() => $ozon->render_pickup_fields( $order, $ozon_draft, $ozon_context ) );
modal_ext_assert( str_contains( $ozon_pickup_html, 'Ограничения выбранного ПВЗ Ozon' ) && str_contains( $ozon_pickup_html, 'data-wdc-ozon-place-limits' ) && str_contains( $ozon_pickup_html, 'data-max-weight-g="10000"' ) && str_contains( $ozon_pickup_html, 'data-max-length-mm="500"' ) && str_contains( $ozon_pickup_html, 'data-max-height-mm="300"' ) && str_contains( $ozon_pickup_html, 'data-wdc-ozon-place-limit-warning' ), 'Ozon pickup fields must render selected point limits and dynamic warning host.' );
$ozon_courier_draft = $base_draft;
$ozon_courier_draft['request']['delivery_type'] = 'courier';
$ozon_courier_draft['request']['rate_id'] = 'ozon_delivery:courier';
$ozon_courier_draft['request']['pickup_point'] = null;
$ozon_courier_draft['request']['meta'] = array(
	'delivery_type' => 'courier',
	'courier_original_address' => '630099, Новосибирск, улица Ленина, 10',
	'courier_address_source' => 'trusted_order_snapshot',
	'normalization_valid' => true,
	'courier_address_snapshot' => array(
		'postcode' => '630099',
		'country' => 'Россия',
		'region' => 'Новосибирская область',
		'city' => 'г Новосибирск',
		'street' => 'улица Ленина',
		'house' => '10',
		'flat' => '12',
		'geo_lat' => '55.0415',
		'geo_lon' => '82.9346',
		'normalized_address' => '630099, Новосибирская область, г Новосибирск, улица Ленина, 10',
	),
);
$ozon_courier_context = $ozon->modal_context( $order, $ozon_courier_draft );
$ozon_courier_html = modal_ext_render( static fn() => $ozon->render_courier_fields( $order, $ozon_courier_draft, $ozon_courier_context ) );
modal_ext_assert( 'courier' === (string) ( $ozon_courier_context['delivery_type'] ?? '' ) && ! empty( $ozon_courier_context['normalization_valid'] ), 'Ozon courier modal context must expose validated structured recipient address state.' );
modal_ext_assert( str_contains( $ozon_courier_html, 'data-wdc-normalize-address' ) && str_contains( $ozon_courier_html, 'name="courier_original_address"' ) && str_contains( $ozon_courier_html, 'data-wdc-normalized-address-json' ) && str_contains( $ozon_courier_html, 'data-wdc-ozon-courier-field="postcode"' ) && str_contains( $ozon_courier_html, 'name="ozon_courier_apartment"' ), 'Ozon courier fields must reuse generic server-side address normalization and render manager-editable supported courier details.' );
modal_ext_assert( str_contains( $ozon_courier_html, 'Проанализировать адрес' ) && ! str_contains( $ozon_courier_html, 'Проверить адрес через DaData' ), 'Ozon courier normalize button must use the manager-facing analyze label.' );
modal_ext_assert( ! str_contains( $ozon_courier_html, 'Курьерское отправление Ozon создаётся только по подтвержденному структурированному адресу получателя' ), 'Ozon courier modal must not show technical browser/FIAS security commentary.' );
modal_ext_assert( str_contains( $ozon_courier_html, 'data-wdc-ozon-courier-address-grid' ) && str_contains( $ozon_courier_html, 'data-wdc-ozon-courier-field="country"' ), 'Ozon courier modal must render compact structured address grid markup including country.' );
modal_ext_assert( ! str_contains( $ozon_courier_html, 'name="geo_lat"' ) && ! str_contains( $ozon_courier_html, 'name="geo_lon"' ) && ! str_contains( $ozon_courier_html, 'name="fias_id"' ), 'Ozon courier modal must not expose coordinates/FIAS as browser-authoritative form fields.' );

$root = dirname( __DIR__, 2 );
$metabox_source = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$cdek_source = (string) file_get_contents( $root . '/src/Shipments/Cdek/CdekShipmentModalExtension.php' );
$dpd_source = (string) file_get_contents( $root . '/src/Shipments/Dpd/DpdShipmentModalExtension.php' );
$rp_source = (string) file_get_contents( $root . '/src/Shipments/RussianPost/RussianPostShipmentModalExtension.php' );
$yandex_source = (string) file_get_contents( $root . '/src/Shipments/YandexDelivery/YandexShipmentModalExtension.php' );
$ozon_source = (string) file_get_contents( $root . '/src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentModalExtension.php' );
$shipment_css = (string) file_get_contents( $root . '/assets/admin/shipments-admin.css' );

modal_ext_assert( str_contains( $metabox_source, 'modal_extensions->get' ) && str_contains( $metabox_source, 'render_fields' ), 'Common metabox must call modal extensions through the registry.' );
modal_ext_assert( ! str_contains( $metabox_source, "name=\"date_pickup\"" ) && ! str_contains( $metabox_source, "name=\"postoffice_code\"" ) && ! str_contains( $metabox_source, "name=\"yandex_ready_from\"" ), 'Common delivery renderer must not keep DPD/Russian Post/Yandex delivery field markup.' );
$render_start = strpos( $metabox_source, 'private function render_inner' );
$render_end = strpos( $metabox_source, 'private function dpd_courier_contact_history', false === $render_start ? 0 : $render_start );
$render_source = false !== $render_start && false !== $render_end ? substr( $metabox_source, $render_start, $render_end - $render_start ) : '';
$context_start = strpos( $render_source, '$modal_extension_context' );
$pre_context_source = false !== $context_start ? substr( $render_source, 0, $context_start ) : $render_source;
modal_ext_assert( '' !== $render_source && ! str_contains( $render_source, '$is_cdek' ) && ! str_contains( $render_source, '$is_dpd' ) && ! str_contains( $render_source, '$is_russian_post' ) && ! str_contains( $render_source, '$is_yandex' ), 'Common render path must not use carrier flags.' );
modal_ext_assert( ! str_contains( $render_source, '! $is_yandex' ) && ! str_contains( $render_source, '$is_dpd' ) && ! str_contains( $render_source, 'requires_postoffice' ), 'Common render path must not contain carrier-specific capability defaults.' );
modal_ext_assert( ! str_contains( $pre_context_source, 'foreach ( $services as $service )' ) && ! str_contains( $pre_context_source, 'selected_tariff_title' ), 'Common render path must not prepare carrier tariff presentation before modal_context().' );
modal_ext_assert( str_contains( $cdek_source, 'data-wdc-cdek-sender-door' ) && str_contains( $cdek_source, 'name="shipment_point"' ), 'CDEK extension must own sender point/door fields.' );
modal_ext_assert( str_contains( $dpd_source, 'data-wdc-dpd-date-pickup' ) && str_contains( $dpd_source, 'name="pickup_terminal_code"' ) && str_contains( $dpd_source, 'data-wdc-dpd-contact-history' ), 'DPD extension must own terminal/date/contact fields.' );
modal_ext_assert( str_contains( $rp_source, 'name="postoffice_code"' ), 'Russian Post extension must own postoffice field.' );
modal_ext_assert( str_contains( $yandex_source, 'data-wdc-yandex-source-station' ) && str_contains( $yandex_source, 'name="yandex_ready_from"' ) && str_contains( $yandex_source, 'data-wdc-yandex-offer-note' ), 'Yandex extension must own source station/ready interval fields.' );
modal_ext_assert( str_contains( $ozon_source, 'data-wdc-ozon-place-limits' ) && str_contains( $ozon_source, 'data-wdc-ozon-place-limit-warning' ), 'Ozon extension must own selected point limit markup.' );
modal_ext_assert( str_contains( $shipment_css, '.wdc-ozon-courier-address-grid' ) && str_contains( $shipment_css, 'grid-template-columns: repeat(2, minmax(0, 1fr))' ) && str_contains( $shipment_css, '@media (max-width: 782px)' ), 'Shipment admin CSS must keep Ozon courier address fields in a responsive two-column grid.' );
foreach ( array( $cdek_source, $dpd_source, $rp_source, $yandex_source, $ozon_source ) as $source ) {
	modal_ext_assert( ! str_contains( $source, '$_POST' ) && ! str_contains( $source, 'wp_remote_' ) && ! str_contains( $source, 'create(' ) && ! str_contains( $source, 'cancel' ), 'Modal extensions must not perform HTTP, create/cancel, or read $_POST.' );
}

echo "Shipment modal extensions smoke passed.\n";
