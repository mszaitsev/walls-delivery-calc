( function ( $ ) {
	'use strict';

	$( document.body ).on( 'change', '.wdc-checkout-sort', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );

	function relocateSortControl() {
		var $row = $( '.wdc-checkout-sort-row' ).first();
		var $shippingCell = $( 'tr.woocommerce-shipping-totals.shipping:not(.just_label) > td' ).first();
		if ( ! $row.length || ! $shippingCell.length || $shippingCell.find( '.wdc-checkout-sort-inline' ).length ) {
			return;
		}

		var $select = $row.find( '.wdc-checkout-sort' ).first().detach();
		var label = $.trim( $row.find( 'th' ).first().text() || '' );
		$row.addClass( 'wdc-checkout-sort-row--relocated' );
		$( '<div class="wdc-checkout-sort-inline" />' )
			.append( $( '<span class="wdc-checkout-sort-inline__label" />' ).text( label ) )
			.append( $select )
			.prependTo( $shippingCell );
	}

	$( relocateSortControl );
	$( document.body ).on( 'updated_checkout', relocateSortControl );

	$( document.body ).on( 'change', '.wdc-platform-pickup-point', function () {
		var $select = $( this );
		var $wrapper = $select.closest( '.wdc-pickup-selector' );
		var debug = window.wdcPlatformCitySelector && window.wdcPlatformCitySelector.debug && window.console && window.console.log;
		if ( debug ) {
			window.console.log( 'wdc pickup selector: pickup select changed' );
			window.console.log( 'wdc pickup selector: pickup carrier', $wrapper.find( 'input[name="wdc_platform_pickup_carrier"]' ).val() || '' );
			window.console.log( 'wdc pickup selector: pickup rate id', $wrapper.find( 'input[name="wdc_platform_pickup_rate_id"]' ).val() || '' );
			window.console.log( 'wdc pickup selector: pickup point code', $select.val() || '' );
			window.console.log( 'wdc pickup selector: update_checkout triggered after pickup selection' );
		}
		$( document.body ).trigger( 'update_checkout' );
	} );
}( jQuery ) );
