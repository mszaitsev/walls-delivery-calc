( function ( $ ) {
	'use strict';

	var config = window.wdcPlatformCitySelector || {};
	var namespace = '.wdcCitySelector';
	var citySelector = '#shipping_city, input[name="shipping_city"], #billing_city, input[name="billing_city"]';
	var timer = null;
	var selectedDisplay = '';
	var isSelecting = false;
	var locationStore = {};
	var locationSeq = 0;
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

	function hiddenValue( name ) {
		return String( $( 'form.checkout' ).first().find( 'input[name="' + name + '"]' ).val() || '' );
	}

	function clearHidden() {
		hiddenNames.forEach( function ( name ) {
			setHidden( name, '' );
		} );
		selectedDisplay = '';
		clearSelectedNotice();
	}

	function resultsBox( $field ) {
		var $box = $field.siblings( '.wdc-city-selector' ).first();
		if ( ! $box.length ) {
			$box = $( '<div class="wdc-city-selector" role="listbox" />' );
			$field.after( $box );
		}
		return $box;
	}

	function selectedNotice( $field ) {
		var $notice = $field.siblings( '.wdc-city-selector-selected' ).first();
		if ( ! $notice.length ) {
			$notice = $( '<div class="wdc-city-selector-selected" />' );
			$field.after( $notice );
		}
		return $notice;
	}

	function clearSelectedNotice() {
		$( '.wdc-city-selector-selected' ).remove();
	}

	function renderSelectedNotice( $field, label ) {
		clearSelectedNotice();
		selectedNotice( $field ).text( 'Выбран: ' + label );
	}

	function restoreSelectedNotice() {
		var displayName = hiddenValue( 'wdc_platform_location_display_name' );
		var $field = cityField();
		if ( displayName && $field.length ) {
			selectedDisplay = displayName;
			renderSelectedNotice( $field, displayName );
		}
	}

	function renderMessage( $box, message, className ) {
		$box.html( '<div class="wdc-city-selector__message ' + className + '">' + escapeHtml( message || '' ) + '</div>' );
	}

	function renderResults( $box, groups ) {
		locationStore = {};
		locationSeq = 0;

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
				var key = 'wdc_loc_' + String( ++locationSeq );
				locationStore[ key ] = location;
				html += '<div role="option" tabindex="0" class="wdc-city-selector__item" data-location-key="' + escapeHtml( key ) + '">';
				html += escapeHtml( label );
				html += '</div>';
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

	function setFieldValue( $field, value ) {
		if ( $field.length && undefined !== value && null !== value && '' !== String( value ) ) {
			$field.val( value ).trigger( 'input' ).trigger( 'change' );
		}
	}

	function setStateField( $state, location ) {
		var regionCode = location.region_code || '';
		var regionName = location.region_name || '';
		if ( ! $state.length ) {
			return;
		}

		if ( $state.is( 'select' ) ) {
			if ( regionCode && $state.find( 'option[value="' + regionCode.replace( /"/g, '\\"' ) + '"]' ).length ) {
				setFieldValue( $state, regionCode );
				return;
			}
			if ( regionName && $state.find( 'option[value="' + regionName.replace( /"/g, '\\"' ) + '"]' ).length ) {
				setFieldValue( $state, regionName );
			}
			return;
		}

		setFieldValue( $state, regionName || regionCode );
	}

	function selectLocationFromItem( $item ) {
		var key = String( $item.attr( 'data-location-key' ) || '' );
		var location = locationStore[ key ];
		if ( ! location ) {
			debug( 'selected payload missing', key );
			return;
		}

		selectLocation( location );
	}

	function selectLocation( location ) {
		isSelecting = true;
		debug( 'select location start' );
		debug( 'selected payload', location );

		var city = location.settlement_name || location.city_name || location.display_name || '';
		var label = city && location.region_name ? city + ' — ' + location.region_name : ( location.display_name || city );
		var $city = cityField();
		var $postcode = postcodeField();
		var $state = stateField();

		debug( 'fields before', {
			city: $city.val(),
			postcode: $postcode.val(),
			state: $state.val()
		} );

		setFieldValue( $city, city );
		setFieldValue( $postcode, location.postcode );
		setStateField( $state, location );

		debug( 'fields after', {
			city: $city.val(),
			postcode: $postcode.val(),
			state: $state.val()
		} );

		setHidden( 'wdc_platform_location_id', location.id );
		setHidden( 'wdc_platform_location_fias_id', location.fias_id );
		setHidden( 'wdc_platform_location_gar_id', location.gar_id );
		setHidden( 'wdc_platform_location_display_name', location.display_name || label );
		setHidden( 'wdc_platform_location_postcode', location.postcode );
		setHidden( 'wdc_platform_location_region_name', location.region_name );
		debug( 'hidden fields set' );

		$( '.wdc-city-selector' ).empty();
		debug( 'selector cleared' );
		selectedDisplay = location.display_name || label;
		renderSelectedNotice( $city, selectedDisplay );

		isSelecting = false;
		window.setTimeout( function () {
			debug( 'update_checkout triggered' );
			$( document.body ).trigger( 'update_checkout' );
		}, 50 );
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
		if ( selectedDisplay && query !== selectedDisplay && query !== hiddenValue( 'wdc_platform_location_display_name' ) ) {
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

	function blurTargetsSelector( event ) {
		return $( event.relatedTarget ).closest( '.wdc-city-selector' ).length || $( document.activeElement ).closest( '.wdc-city-selector' ).length;
	}

	function handleCityBlur( event ) {
		if ( isSelecting || blurTargetsSelector( event ) ) {
			debug( 'city blur ignored during selection' );
			return;
		}

		var $field = $( event.target );
		if ( ! isUsableField( $field ) ) {
			return;
		}

		var query = String( $field.val() || '' );
		if ( selectedDisplay && query !== selectedDisplay && query !== hiddenValue( 'wdc_platform_location_display_name' ) ) {
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
			restoreSelectedNotice();
		}
	}

	$( document.body ).off( 'input.wdcCitySelector keyup.wdcCitySelector change.wdcCitySelector paste.wdcCitySelector', citySelector );
	$( document.body ).on( 'input.wdcCitySelector keyup.wdcCitySelector change.wdcCitySelector paste.wdcCitySelector', citySelector, function ( event ) {
		handleCityInput( event );
	} );
	$( document.body ).off( 'blur' + namespace, citySelector );
	$( document.body ).on( 'blur' + namespace, citySelector, function ( event ) {
		handleCityBlur( event );
	} );
	$( document.body ).off( 'mousedown.wdcCitySelector click.wdcCitySelector keydown.wdcCitySelector', '.wdc-city-selector__item' );
	$( document.body ).on( 'mousedown.wdcCitySelector', '.wdc-city-selector__item', function ( event ) {
		debug( 'location item mousedown' );
		event.preventDefault();
		event.stopPropagation();
		selectLocationFromItem( $( this ) );
	} );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-selector__item', function ( event ) {
		debug( 'location item click' );
		event.preventDefault();
		event.stopPropagation();
		if ( ! isSelecting ) {
			selectLocationFromItem( $( this ) );
		}
	} );
	$( document.body ).on( 'keydown.wdcCitySelector', '.wdc-city-selector__item', function ( event ) {
		if ( 'Enter' !== event.key && ' ' !== event.key ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		selectLocationFromItem( $( this ) );
	} );

	$( init );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, init );
}( jQuery ) );
