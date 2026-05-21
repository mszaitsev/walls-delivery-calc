( function ( $ ) {
	'use strict';

	var config = window.wdcPlatformCitySelector || {};
	var timer = null;
	var selectedDisplay = '';
	var hiddenNames = [
		'wdc_platform_location_id',
		'wdc_platform_location_fias_id',
		'wdc_platform_location_gar_id',
		'wdc_platform_location_display_name',
		'wdc_platform_location_postcode',
		'wdc_platform_location_region_name'
	];

	function cityField() {
		var $shipping = $( '#shipping_city' );
		return $shipping.length ? $shipping : $( '#billing_city' );
	}

	function postcodeField() {
		var $shipping = $( '#shipping_postcode' );
		return $shipping.length ? $shipping : $( '#billing_postcode' );
	}

	function stateField() {
		var $shipping = $( '#shipping_state' );
		return $shipping.length ? $shipping : $( '#billing_state' );
	}

	function ensureHiddenFields( $form ) {
		hiddenNames.forEach( function ( name ) {
			if ( ! $form.find( 'input[name="' + name + '"]' ).length ) {
				$form.append( '<input type="hidden" name="' + name + '" value="">' );
			}
		} );
	}

	function setHidden( name, value ) {
		$( 'input[name="' + name + '"]' ).val( value || '' );
	}

	function clearHidden() {
		hiddenNames.forEach( function ( name ) {
			setHidden( name, '' );
		} );
		selectedDisplay = '';
	}

	function resultsBox( $field ) {
		var $box = $( '.wdc-city-selector' );
		if ( ! $box.length ) {
			$box = $( '<div class="wdc-city-selector" role="listbox" />' );
			$field.after( $box );
		}
		return $box;
	}

	function renderMessage( $box, message, className ) {
		$box.html( '<div class="wdc-city-selector__message ' + className + '">' + message + '</div>' );
	}

	function renderResults( $box, groups ) {
		if ( ! groups || ! groups.length ) {
			renderMessage( $box, config.strings && config.strings.not_found ? config.strings.not_found : '', 'is-empty' );
			return;
		}

		var html = '';
		groups.forEach( function ( group ) {
			html += '<div class="wdc-city-selector__group">';
			html += '<div class="wdc-city-selector__region">' + escapeHtml( group.region || '' ) + '</div>';
			( group.locations || [] ).forEach( function ( location ) {
				var label = location.display_name || location.city_name || location.settlement_name || '';
				html += '<button type="button" class="wdc-city-selector__item" data-location="' + encodeURIComponent( JSON.stringify( location ) ) + '">';
				html += escapeHtml( label );
				html += '</button>';
			} );
			html += '</div>';
		} );
		$box.html( html );
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( char ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ char ];
		} );
	}

	function search( query, $field ) {
		var $box = resultsBox( $field );
		renderMessage( $box, config.strings && config.strings.searching ? config.strings.searching : '', 'is-loading' );

		$.ajax( {
			url: config.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'wdc_platform_search_locations',
				nonce: config.nonce,
				query: query
			}
		} ).done( function ( response ) {
			if ( response && response.success ) {
				renderResults( $box, response.data ? response.data.groups : [] );
				return;
			}
			renderMessage( $box, config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} ).fail( function () {
			renderMessage( $box, config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} );
	}

	function selectLocation( location ) {
		var city = location.settlement_name || location.city_name || '';
		cityField().val( city ).trigger( 'change' );
		if ( location.postcode ) {
			postcodeField().val( location.postcode ).trigger( 'change' );
		}
		if ( location.region_name || location.region_code ) {
			stateField().val( location.region_code || location.region_name ).trigger( 'change' );
		}

		setHidden( 'wdc_platform_location_id', location.id );
		setHidden( 'wdc_platform_location_fias_id', location.fias_id );
		setHidden( 'wdc_platform_location_gar_id', location.gar_id );
		setHidden( 'wdc_platform_location_display_name', location.display_name );
		setHidden( 'wdc_platform_location_postcode', location.postcode );
		setHidden( 'wdc_platform_location_region_name', location.region_name );
		selectedDisplay = location.display_name || city;
		$( '.wdc-city-selector' ).empty();
		$( document.body ).trigger( 'update_checkout' );
	}

	function init() {
		var $field = cityField();
		if ( ! $field.length || ! config.ajax_url ) {
			return;
		}

		var $form = $field.closest( 'form.checkout' );
		if ( ! $form.length ) {
			$form = $( 'form.checkout' );
		}
		ensureHiddenFields( $form );
		resultsBox( $field ).html( '<div class="wdc-city-selector__hint">' + ( config.strings && config.strings.start ? config.strings.start : '' ) + '</div>' );

		$( document.body ).on( 'click', '.wdc-city-selector__item', function () {
			selectLocation( JSON.parse( decodeURIComponent( $( this ).attr( 'data-location' ) || '{}' ) ) );
		} );

		$field.on( 'input', function () {
			var query = String( $field.val() || '' );
			window.clearTimeout( timer );
			if ( selectedDisplay && query !== selectedDisplay ) {
				clearHidden();
			}
			timer = window.setTimeout( function () {
				if ( query.length < ( config.min_chars || 3 ) ) {
					resultsBox( $field ).empty();
					$( document.body ).trigger( 'update_checkout' );
					return;
				}
				search( query, $field );
				$( document.body ).trigger( 'update_checkout' );
			}, 300 );
		} );
	}

	$( init );
}( jQuery ) );
