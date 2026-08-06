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
pek_picker_assert( str_contains( $pek, "purpose: 'sender_warehouse'" ) || str_contains( $pek, 'purpose: "sender_warehouse"' ), 'PEK picker request must mark sender warehouse purpose.' );
pek_picker_assert( str_contains( $pek, "carrierKey: 'pek'" ) || str_contains( $pek, 'carrierKey: "pek"' ), 'PEK picker context must include carrier key.' );
pek_picker_assert( str_contains( $generic, 'entitySingular' ) && str_contains( $generic, 'emptyText' ) && str_contains( $generic, 'codeLabel' ), 'Generic picker must accept carrier-owned wording options.' );
pek_picker_assert( str_contains( $pek, "entitySingular: 'склад'" ) && str_contains( $pek, "confirmText: 'Выбрать этот склад'" ) && str_contains( $pek, "emptyText: 'Склады ПЭК не найдены'" ) && str_contains( $pek, "codeLabel: 'Warehouse ID'" ), 'PEK sender warehouse picker must use warehouse wording.' );
pek_picker_assert( str_contains( $pek, 'wdc:shipment-carrier-field-change' ), 'PEK picker selection must emit generic shipment carrier field-change event.' );
pek_picker_assert( ! str_contains( $pek, 'wdc:shipment-pickup-search-open' ), 'PEK extension must not dispatch the old unhandled picker event.' );

echo "PEK shipment picker smoke passed.\n";
