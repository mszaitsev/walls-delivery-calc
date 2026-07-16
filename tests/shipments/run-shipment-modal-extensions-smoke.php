<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
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
function selected( mixed $selected, mixed $current, bool $display = true ): string { $out = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $out; } return $out; }
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string { $out = (bool) $disabled === (bool) $current ? ' disabled="disabled"' : ''; if ( $display ) { echo $out; } return $out; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }

$extensions = array(
	new CdekShipmentModalExtension(),
	new DpdShipmentModalExtension( static fn(): array => array( 'Иван Петров' ) ),
	new RussianPostShipmentModalExtension(),
	new YandexShipmentModalExtension(),
);
$registry = new ShipmentModalExtensionRegistry( $extensions );
modal_ext_assert(
	array( CdekSettings::CARRIER_KEY, DpdSettings::CARRIER_KEY, RussianPostDomesticSettings::CARRIER_KEY, YandexDeliverySettings::CARRIER_KEY ) === $registry->keys(),
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

$root = dirname( __DIR__, 2 );
$metabox_source = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$cdek_source = (string) file_get_contents( $root . '/src/Shipments/Cdek/CdekShipmentModalExtension.php' );
$dpd_source = (string) file_get_contents( $root . '/src/Shipments/Dpd/DpdShipmentModalExtension.php' );
$rp_source = (string) file_get_contents( $root . '/src/Shipments/RussianPost/RussianPostShipmentModalExtension.php' );
$yandex_source = (string) file_get_contents( $root . '/src/Shipments/YandexDelivery/YandexShipmentModalExtension.php' );

modal_ext_assert( str_contains( $metabox_source, 'modal_extensions->get' ) && str_contains( $metabox_source, 'render_fields' ), 'Common metabox must call modal extensions through the registry.' );
modal_ext_assert( ! str_contains( $metabox_source, "name=\"date_pickup\"" ) && ! str_contains( $metabox_source, "name=\"postoffice_code\"" ) && ! str_contains( $metabox_source, "name=\"yandex_ready_from\"" ), 'Common delivery renderer must not keep DPD/Russian Post/Yandex delivery field markup.' );
modal_ext_assert( str_contains( $cdek_source, 'data-wdc-cdek-sender-door' ) && str_contains( $cdek_source, 'name="shipment_point"' ), 'CDEK extension must own sender point/door fields.' );
modal_ext_assert( str_contains( $dpd_source, 'data-wdc-dpd-date-pickup' ) && str_contains( $dpd_source, 'name="pickup_terminal_code"' ) && str_contains( $dpd_source, 'data-wdc-dpd-contact-history' ), 'DPD extension must own terminal/date/contact fields.' );
modal_ext_assert( str_contains( $rp_source, 'name="postoffice_code"' ), 'Russian Post extension must own postoffice field.' );
modal_ext_assert( str_contains( $yandex_source, 'data-wdc-yandex-source-station' ) && str_contains( $yandex_source, 'name="yandex_ready_from"' ) && str_contains( $yandex_source, 'data-wdc-yandex-offer-note' ), 'Yandex extension must own source station/ready interval fields.' );
foreach ( array( $cdek_source, $dpd_source, $rp_source, $yandex_source ) as $source ) {
	modal_ext_assert( ! str_contains( $source, '$_POST' ) && ! str_contains( $source, 'wp_remote_' ) && ! str_contains( $source, 'create(' ) && ! str_contains( $source, 'cancel' ), 'Modal extensions must not perform HTTP, create/cancel, or read $_POST.' );
}

echo "Shipment modal extensions smoke passed.\n";
