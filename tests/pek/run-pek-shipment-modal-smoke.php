<?php
declare(strict_types=1);

function pek_modal_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__, 2 );
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
pek_modal_assert( str_contains( $modal, 'recipient_type' ) && str_contains( $modal, 'physical' ), 'Modal must show physical recipient mode.' );
pek_modal_assert( ! str_contains( strtolower( $modal ), 'passport' ) && ! str_contains( $modal, 'identityCard' ), 'Modal must not request passport/identityCard.' );
pek_modal_assert( str_contains( $metabox, 'extensions/pek.js' ), 'PEK JS extension must be enqueued through extension chain.' );
pek_modal_assert( str_contains( $pek_js, "carrierKey: 'pek'" ), 'PEK JS must register carrier hooks.' );
pek_modal_assert( str_contains( $picker_js, 'window.wdcShipmentPickupPicker' ) && str_contains( $pek_js, 'wdcShipmentPickupPicker' ), 'Generic picker API must have a working PEK consumer.' );
pek_modal_assert( ! str_contains( $modal, 'name="pickup_point_code"' ), 'PEK modal extension must not emit duplicate pickup_point_code.' );
pek_modal_assert( ! preg_match( "/carrier\\s*={2,3}\\s*['\"]pek['\"]|carrier\\s*!={1,2}\\s*['\"]pek['\"]/", $core_js . $preview_js . $status_js ), 'Generic shipment JS must not branch on PEK.' );

echo "PEK shipment modal smoke passed.\n";
