( function ( $ ) {
	'use strict';

	$( document.body ).on( 'change', '.wdc-checkout-sort', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );
}( jQuery ) );
