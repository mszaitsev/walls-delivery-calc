( function ( $ ) {
	'use strict';

	$( document.body ).on( 'change', '.wdc-checkout-sort', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );

	function shippingCell() {
		return $( 'tr.woocommerce-shipping-totals.shipping:not(.just_label) > td' ).first();
	}

	function relocateDeliveryMessages() {
		var $shippingCell = shippingCell();
		var $existing = $shippingCell.children( '.wdc-checkout-delivery-messages' ).first();
		if ( $existing.length ) {
			return $existing;
		}

		var $row = $( '.wdc-checkout-delivery-messages-row' ).first();
		if ( ! $row.length || ! $shippingCell.length ) {
			return $();
		}

		var $messages = $row.find( '.wdc-checkout-delivery-messages' ).first().detach();
		if ( ! $messages.length ) {
			return $();
		}

		$row.addClass( 'wdc-checkout-delivery-messages-row--relocated' );
		$messages.prependTo( $shippingCell );

		return $messages;
	}

	function relocateSortControl( $messages ) {
		var $shippingCell = shippingCell();
		var $inline = $shippingCell.children( '.wdc-checkout-sort-inline' ).first();
		if ( $inline.length ) {
			if ( $messages && $messages.length ) {
				$inline.insertAfter( $messages );
			}
			return;
		}

		var $row = $( '.wdc-checkout-sort-row' ).first();
		if ( ! $row.length || ! $shippingCell.length ) {
			return;
		}
		var $select = $row.find( '.wdc-checkout-sort' ).first().detach();
		var label = $.trim( $row.find( 'th' ).first().text() || '' );
		$inline = $( '<div class="wdc-checkout-sort-inline" />' )
			.append( $( '<span class="wdc-checkout-sort-inline__label" />' ).text( label ) )
			.append( $select );

		$row.addClass( 'wdc-checkout-sort-row--relocated' );
		if ( $messages.length ) {
			$inline.insertAfter( $messages );
			return;
		}
		$inline.prependTo( $shippingCell );
	}

	function relocateDeliveryControls() {
		var $messages = relocateDeliveryMessages();
		relocateSortControl( $messages );
	}

	$( relocateDeliveryControls );
	$( document.body ).on( 'updated_checkout', relocateDeliveryControls );

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
