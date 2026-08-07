<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );

function pek_picker_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, '[pek.shipment-picker] ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$generic = file_get_contents( $root . '/assets/admin/shipments/shipment-picker.js' ) ?: '';
$pek     = file_get_contents( $root . '/assets/admin/shipments/extensions/pek.js' ) ?: '';

pek_picker_assert( str_contains( $generic, 'window.wdcShipmentPickupPicker' ), 'Generic shipment picker must expose a carrier-agnostic API.' );
pek_picker_assert( str_contains( $generic, 'open: function (form, options)' ), 'Generic shipment picker API must expose open(form, options).' );
pek_picker_assert( ! str_contains( $generic, "carrier === 'pek'" ) && ! str_contains( $generic, 'carrier === "pek"' ), 'Generic picker must not contain a PEK carrier branch.' );
pek_picker_assert( str_contains( $pek, 'window.wdcShipmentPickupPicker' ) && str_contains( $pek, "typeof picker.open !== 'function'" ) && str_contains( $pek, 'picker.open(form' ), 'PEK extension must consume the generic picker API.' );
pek_picker_assert( str_contains( $generic, 'settings.onChoose(point) === false' ), 'Generic picker must allow a carrier onChoose hook to keep the dialog open on invalid selection.' );
pek_picker_assert( str_contains( $pek, 'handleClick: function (event)' ) && str_contains( $pek, 'data-wdc-pek-open-sender-warehouse-picker' ), 'PEK sender warehouse picker must be opened through the carrier handleClick hook.' );
pek_picker_assert( ! str_contains( $pek, 'addEventListener(\'click\'' ) && ! str_contains( $pek, 'addEventListener("click"' ), 'PEK sender warehouse picker must not depend exclusively on onModalReady click listeners.' );
pek_picker_assert( str_contains( $pek, "purpose: 'sender_warehouse'" ) || str_contains( $pek, 'purpose: "sender_warehouse"' ), 'PEK picker request must mark sender warehouse purpose.' );
pek_picker_assert( str_contains( $pek, "carrierKey: 'pek'" ) || str_contains( $pek, 'carrierKey: "pek"' ), 'PEK picker context must include carrier key.' );
pek_picker_assert( str_contains( $pek, 'data-wdc-pek-sender-warehouse-context' ) && str_contains( $pek, 'data.latitude' ) && str_contains( $pek, 'data.longitude' ), 'PEK picker context must carry current sender warehouse address and coordinates.' );
pek_picker_assert( str_contains( $generic, 'entitySingular' ) && str_contains( $generic, 'emptyText' ) && str_contains( $generic, 'codeLabel' ), 'Generic picker must accept carrier-owned wording options.' );
pek_picker_assert( str_contains( $pek, "entitySingular: 'склад'" ) && str_contains( $pek, "confirmText: 'Выбрать этот склад'" ) && str_contains( $pek, "emptyText: 'Склады ПЭК не найдены'" ) && str_contains( $pek, "codeLabel: 'Warehouse ID'" ), 'PEK sender warehouse picker must use warehouse wording.' );
pek_picker_assert( str_contains( $pek, 'isCanonicalWarehouseId' ) && str_contains( $pek, 'ПЭК не вернул корректный warehouse ID для выбранного склада.' ) && ! str_contains( $pek, 'point.warehouseId || point.point_code || point.code' ), 'PEK sender warehouse picker must require a canonical warehouseId instead of falling back to point_code/code.' );
pek_picker_assert( substr_count( $pek, "dispatchEvent(new Event('change'" ) === 1 && strpos( $pek, "sourceField.value = 'shipment_modal_override';" ) < strpos( $pek, 'idField.value = String(warehouse.warehouseId)' ) && strpos( $pek, 'idField.value = String(warehouse.warehouseId)' ) < strpos( $pek, "dispatchEvent(new Event('change'" ), 'PEK picker selection must update override source/id atomically before one preview-triggering change event.' );
pek_picker_assert( ! str_contains( $pek, 'wdc:shipment-pickup-search-open' ), 'PEK extension must not dispatch the old unhandled picker event.' );

echo "PEK shipment picker smoke passed.\n";
