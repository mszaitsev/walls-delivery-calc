( function () {
	function updateShopProcessingRows() {
		var modeSelect = document.getElementById( 'wdc_shop_processing_mode' );

		if ( ! modeSelect ) {
			return;
		}

		var mode = modeSelect.value === 'dynamic' ? 'dynamic' : 'fixed';
		var rows = document.querySelectorAll( '[data-wdc-shop-processing-mode]' );

		rows.forEach( function ( row ) {
			var visible = row.getAttribute( 'data-wdc-shop-processing-mode' ) === mode;

			row.hidden = ! visible;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var modeSelect = document.getElementById( 'wdc_shop_processing_mode' );

		if ( ! modeSelect ) {
			return;
		}

		updateShopProcessingRows();
		modeSelect.addEventListener( 'change', updateShopProcessingRows );
	} );
}() );
