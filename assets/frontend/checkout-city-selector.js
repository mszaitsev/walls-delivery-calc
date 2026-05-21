( function ( $ ) {
	'use strict';

	var config = window.wdcPlatformCitySelector || {};
	var namespace = '.wdcCitySelector';
	var citySelector = '#shipping_city, input[name="shipping_city"], #billing_city, input[name="billing_city"]';
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

	function debug() {
		if ( config.debug && window.console && window.console.log ) {
			window.console.log.apply( window.console, [ 'wdc city selector:' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	function isUsableField( field ) {
		var $field = $( field );
		return $field.length && $field.is( ':visible' ) && ! $field.is( ':disabled' ) && 'hidden' !== String( $field.attr( 'type' ) || '' ).toLowerCase();
	}

	function firstUsableField( selectors ) {
		for ( var index = 0; index < selectors.length; index++ ) {
			var $fields = $( selectors[ index ] );
			for ( var fieldIndex = 0; fieldIndex < $fields.length; fieldIndex++ ) {
				if ( isUsableField( $fields[ fieldIndex ] ) ) {
					return $( $fields[ fieldIndex ] );
				}
			}
		}

		return $();
	}

	function cityField() {
		return firstUsableField( [ '#shipping_city', 'input[name="shipping_city"]', '#billing_city', 'input[name="billing_city"]' ] );
	}

	function postcodeField() {
		return firstUsableField( [ '#shipping_postcode', 'input[name="shipping_postcode"]', '#billing_postcode', 'input[name="billing_postcode"]' ] );
	}

	function stateField() {
		return firstUsableField( [ '#shipping_state', 'select[name="shipping_state"]', 'input[name="shipping_state"]', '#billing_state', 'select[name="billing_state"]', 'input[name="billing_state"]' ] );
	}

	function checkoutForm( $field ) {
		var $form = $field.closest( 'form.checkout' );
		return $form.length ? $form : $( 'form.checkout' ).first();
	}

	function ensureHiddenFields( $form ) {
		hiddenNames.forEach( function ( name ) {
			if ( ! $form.find( 'input[name="' + name + '"]' ).length ) {
				$form.append( '<input type="hidden" name="' + name + '" value="">' );
			}
		} );
	}

	function setHidden( name, value ) {
		var $form = $( 'form.checkout' ).first();
		$form.find( 'input[name="' + name + '"]' ).val( value || '' );
	}

	function clearHidden() {
		hiddenNames.forEach( function ( name ) {
			setHidden( name, '' );
		} );
		selectedDisplay = '';
	}

	function resultsBox( $field ) {
		var $box = $field.siblings( '.wdc-city-selector' ).first();
		if ( ! $box.length ) {
			$box = $( '<div class="wdc-city-selector" role="listbox" />' );
			$field.after( $box );
		}
		return $box;
	}

	function renderMessage( $box, message, className ) {
		$box.html( '<div class="wdc-city-selector__message ' + className + '">' + escapeHtml( message || '' ) + '</div>' );
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
				var city = location.settlement_name || location.city_name || '';
				var region = location.region_name || '';
				var label = city && region ? city + ' — ' + region : ( location.display_name || city );
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
		if ( ! config.ajax_url ) {
			debug( 'ajax url missing' );
			return;
		}

		debug( 'ajax request start', query );
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
			debug( 'ajax success', response );
			if ( response && response.success ) {
				renderResults( $box, response.data ? response.data.groups : [] );
				return;
			}
			renderMessage( $box, config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} ).fail( function ( xhr ) {
			debug( 'ajax fail', xhr );
			renderMessage( $box, config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} );
	}

	function selectLocation( location ) {
		var city = location.settlement_name || location.city_name || '';
		var $city = cityField();

		$city.val( city ).trigger( 'change' );
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
		selectedDisplay = city;
		resultsBox( $city ).empty();
		$( document.body ).trigger( 'update_checkout' );
	}

	function handleCityInput( event ) {
		var $field = $( event.target );
		if ( ! isUsableField( $field ) ) {
			debug( 'city input ignored unusable field', event.type );
			return;
		}

		var query = String( $field.val() || '' );
		debug( 'city input event', {
			eventType: event.type,
			fieldId: $field.attr( 'id' ) || '',
			fieldName: $field.attr( 'name' ) || '',
			query: query,
			queryLength: query.length
		} );

		window.clearTimeout( timer );
		if ( selectedDisplay && query !== selectedDisplay ) {
			clearHidden();
		}

		timer = window.setTimeout( function () {
			if ( query.length < ( config.min_chars || 3 ) ) {
				resultsBox( $field ).empty();
				return;
			}

			debug( 'search scheduled', query );
			search( query, $field );
		}, 300 );
	}

	function handleCityBlur( event ) {
		var $field = $( event.target );
		if ( ! isUsableField( $field ) ) {
			return;
		}

		var query = String( $field.val() || '' );
		if ( selectedDisplay && query !== selectedDisplay ) {
			clearHidden();
		}
		if ( query.length >= ( config.min_chars || 3 ) && ! selectedDisplay ) {
			$( document.body ).trigger( 'update_checkout' );
		}
	}

	function init() {
		var $field = cityField();
		var $form = $field.length ? checkoutForm( $field ) : $( 'form.checkout' ).first();

		debug( 'selector initialized' );
		debug( $field.length ? 'city field found' : 'city field not found' );
		debug( 'ajax url', config.ajax_url || '' );

		if ( $form.length ) {
			ensureHiddenFields( $form );
		}
		if ( $field.length ) {
			resultsBox( $field );
		}
	}

	$( document.body ).off( 'input' + namespace + ' keyup' + namespace + ' change' + namespace + ' paste' + namespace, citySelector );
	$( document.body ).on( 'input' + namespace + ' keyup' + namespace + ' change' + namespace + ' paste' + namespace, citySelector, function ( event ) {
		handleCityInput( event );
	} );
	$( document.body ).off( 'blur' + namespace, citySelector );
	$( document.body ).on( 'blur' + namespace, citySelector, function ( event ) {
		handleCityBlur( event );
	} );
	$( document.body ).off( 'click' + namespace, '.wdc-city-selector__item' );
	$( document.body ).on( 'click' + namespace, '.wdc-city-selector__item', function () {
		selectLocation( JSON.parse( decodeURIComponent( $( this ).attr( 'data-location' ) || '{}' ) ) );
	} );

	$( init );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, init );
}( jQuery ) );
