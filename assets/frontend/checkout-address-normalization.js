( function ( $, window ) {
	'use strict';

	var namespace = '.wdcAddressNormalization';
	var debounceTimer = null;
	var debounceDelay = 900;
	var config = window.wdcPlatformAddressNormalization || {};

	function debugLog( message ) {
		if ( config.debug && window.console && window.console.log ) {
			window.console.log( '[WDC address normalization] ' + message );
		}
	}

	function addressOneValue() {
		var shipping = $( '#shipping_address_1, input[name="shipping_address_1"]' ).filter( ':visible:not(:disabled)' ).first().val();
		var billing = $( '#billing_address_1, input[name="billing_address_1"]' ).filter( ':visible:not(:disabled)' ).first().val();

		return $.trim( shipping || billing || '' );
	}

	function scheduleCheckoutUpdate() {
		debugLog( 'address input changed' );
		window.clearTimeout( debounceTimer );

		if ( '' === addressOneValue() ) {
			return;
		}

		debugLog( 'address debounce scheduled' );
		debounceTimer = window.setTimeout( function () {
			debugLog( 'update_checkout triggered for address normalization' );
			debugLog( 'address update_checkout triggered; check debug panel for DaData result' );
			$( document.body ).trigger( 'update_checkout' );
		}, debounceDelay );
	}

	function bindHandlers() {
		var selectors = [
			'#shipping_address_1',
			'input[name="shipping_address_1"]',
			'#shipping_address_2',
			'input[name="shipping_address_2"]',
			'#billing_address_1',
			'input[name="billing_address_1"]',
			'#billing_address_2',
			'input[name="billing_address_2"]'
		].join( ', ' );

		$( document.body ).off( 'input' + namespace + ' change' + namespace + ' blur' + namespace, selectors );
		$( document.body ).on( 'input' + namespace + ' change' + namespace + ' blur' + namespace, selectors, scheduleCheckoutUpdate );
	}

	$( bindHandlers );
	$( document.body ).on( 'updated_checkout' + namespace, bindHandlers );
}( jQuery, window ) );
