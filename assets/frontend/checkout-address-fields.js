( function ( $ ) {
	'use strict';

	var namespace = '.wdcAddressFields';
	var addressSelector = '#billing_address_1, #shipping_address_1';

	function activeAddressField() {
		var $shipping = $( '#shipping_address_1' );
		var $billing = $( '#billing_address_1' );
		var $shipToDifferent = $( '#ship-to-different-address-checkbox, input[name="ship_to_different_address"]' ).first();
		var shippingActive = $shipping.length && ( $shipToDifferent.is( ':checked' ) || ( !$billing.length && $shipping.length ) );

		return shippingActive ? $shipping.first() : $billing.first();
	}

	function selectedShippingMethod() {
		var $checked = $( 'input[name^="shipping_method"]:checked' ).first();
		if ( $checked.length ) {
			return $checked;
		}

		return $( 'select[name^="shipping_method"]' ).first();
	}

	function selectedRateMeta() {
		var $method = selectedShippingMethod();
		if ( !$method.length ) {
			return $();
		}

		return $method.closest( 'li' ).find( '.wdc-platform-rate-meta' ).first();
	}

	function selectedMethodRequiresCourierAddress() {
		var $meta = selectedRateMeta();
		if ( !$meta.length ) {
			return false;
		}

		return '1' === String( $meta.attr( 'data-wdc-requires-courier-address' ) || '' )
			|| 'courier' === String( $meta.attr( 'data-wdc-delivery-type' ) || '' ).toLowerCase();
	}

	function labelFor( $field ) {
		var id = String( $field.attr( 'id' ) || '' );
		if ( '' === id ) {
			return $();
		}

		return $( 'label[for="' + id.replace( /"/g, '\\"' ) + '"]' ).first();
	}

	function setLabelRequired( $field, required ) {
		var $label = labelFor( $field );
		if ( !$label.length ) {
			return;
		}

		var $optional = $label.find( '.optional' );
		var $marker = $label.find( '.required' ).first();
		if ( required ) {
			$optional.attr( 'hidden', 'hidden' ).hide();
			if ( !$marker.length ) {
				$label.append( ' <abbr class="required" title="обязательно" data-wdc-address-required-marker>*</abbr>' );
			}
			return;
		}

		$label.find( '[data-wdc-address-required-marker]' ).remove();
		$optional.removeAttr( 'hidden' ).show();
	}

	function setFieldRequired( $field, required ) {
		if ( !$field.length ) {
			return;
		}

		var $wrapper = $( '#' + String( $field.attr( 'id' ) || '' ) + '_field' );
		$field.prop( 'required', required );
		if ( required ) {
			$field.attr( 'aria-required', 'true' );
			$wrapper.addClass( 'validate-required wdc-courier-address-required' );
		} else {
			$field.removeAttr( 'required' ).attr( 'aria-required', 'false' );
			$wrapper.removeClass( 'validate-required wdc-courier-address-required' );
		}
		setLabelRequired( $field, required );
	}

	function updateAddressRequiredState() {
		var required = selectedMethodRequiresCourierAddress();
		var $active = activeAddressField();

		$( addressSelector ).each( function () {
			setFieldRequired( $( this ), false );
		} );
		setFieldRequired( $active, required );
	}

	function bind() {
		$( document.body ).off( 'change' + namespace, 'input[name^="shipping_method"], select[name^="shipping_method"], #ship-to-different-address-checkbox, input[name="ship_to_different_address"]' );
		$( document.body ).on( 'change' + namespace, 'input[name^="shipping_method"], select[name^="shipping_method"], #ship-to-different-address-checkbox, input[name="ship_to_different_address"]', updateAddressRequiredState );
		$( document.body ).off( 'updated_checkout' + namespace );
		$( document.body ).on( 'updated_checkout' + namespace, updateAddressRequiredState );
		updateAddressRequiredState();
	}

	window.WDCCheckoutAddressFields = {
		update: updateAddressRequiredState
	};

	$( bind );
}( jQuery ) );
