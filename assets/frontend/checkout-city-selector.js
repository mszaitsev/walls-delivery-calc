( function ( $ ) {
	'use strict';

	var config = window.wdcPlatformCitySelector || {};
	var namespace = '.wdcCitySelector';
	var citySelector = '#shipping_city, input[name="shipping_city"], #billing_city, input[name="billing_city"]';
	var timer = null;
	var suppressTimer = null;
	var selectedDisplay = '';
	var isSelecting = false;
	var suppressSearch = false;
	var pickerOpen = false;
	var activeCityField = null;
	var initialManualValue = '';
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
		if ( activeCityField && isUsableField( activeCityField ) ) {
			return $( activeCityField );
		}
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
		if ( $field.length && label ) {
			selectedNotice( $field ).text( 'Выбран: ' + label );
		}
	}

	function restoreSelectedNotice() {
		var displayName = hiddenValue( 'wdc_platform_location_display_name' );
		var $field = cityField();
		if ( displayName && $field.length ) {
			selectedDisplay = displayName;
			renderSelectedNotice( $field, displayName );
		}
	}

	function picker() {
		var $picker = $( '.wdc-city-picker-overlay' );
		if ( $picker.length ) {
			return $picker;
		}

		$picker = $(
			'<div class="wdc-city-picker-overlay" aria-hidden="true">' +
				'<div class="wdc-city-picker-panel" role="dialog" aria-modal="true" aria-label="Выберите населенный пункт">' +
					'<div class="wdc-city-picker-header">' +
						'<div class="wdc-city-picker-title">Выберите населенный пункт</div>' +
						'<button type="button" class="wdc-city-picker-close" aria-label="Закрыть">×</button>' +
					'</div>' +
					'<input type="search" class="wdc-city-picker-search" autocomplete="off" placeholder="Начните вводить населенный пункт">' +
					'<div class="wdc-city-picker-results" role="listbox"></div>' +
				'</div>' +
			'</div>'
		);
		$( document.body ).append( $picker );

		return $picker;
	}

	function searchInput() {
		return picker().find( '.wdc-city-picker-search' );
	}

	function resultsBox() {
		return picker().find( '.wdc-city-picker-results' );
	}

	function renderMessage( message, className ) {
		resultsBox().html( '<div class="wdc-city-picker-message ' + className + '">' + escapeHtml( message || '' ) + '</div>' );
	}

	function renderResults( groups, limitReached, limit ) {
		locationStore = {};
		locationSeq = 0;

		var limitMessage = limitReached ? '<div class="wdc-city-picker-limit">Показаны первые ' + escapeHtml( limit ) + ' результатов. Уточните запрос.</div>' : '';
		if ( ! groups || ! groups.length ) {
			renderMessage( config.strings && config.strings.not_found ? config.strings.not_found : '', 'is-empty' );
			return;
		}

		var html = limitMessage + '<div class="wdc-city-picker-groups">';
		groups.forEach( function ( group ) {
			html += '<div class="wdc-city-picker-group">';
			html += '<div class="wdc-city-picker-region">' + escapeHtml( group.region || '' ) + '</div>';
			( group.locations || [] ).forEach( function ( location ) {
				var city = location.settlement_name || location.city_name || '';
				var region = location.region_name || '';
				var label = city && region ? city + ' — ' + region : ( location.display_name || city );
				var key = 'wdc_loc_' + String( ++locationSeq );
				locationStore[ key ] = location;
				html += '<div role="option" tabindex="0" class="wdc-city-selector__item wdc-city-picker-item" data-location-key="' + escapeHtml( key ) + '">';
				html += escapeHtml( label );
				html += '</div>';
			} );
			html += '</div>';
		} );
		html += '</div>';
		resultsBox().html( html );
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( char ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ char ];
		} );
	}

	function search( query ) {
		if ( ! config.ajax_url ) {
			debug( 'ajax url missing' );
			return;
		}

		debug( 'ajax request start', query );
		renderMessage( config.strings && config.strings.searching ? config.strings.searching : '', 'is-loading' );

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
			var groups = response && response.data && response.data.groups ? response.data.groups : [];
			debug( 'ajax success groups count', groups.length );
			debug( 'limit reached', !! ( response && response.data && response.data.limit_reached ) );
			if ( response && response.success ) {
				renderResults( groups, !! response.data.limit_reached, response.data.limit || config.location_search_limit || 100 );
				return;
			}
			renderMessage( config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} ).fail( function ( xhr ) {
			debug( 'ajax fail', xhr );
			renderMessage( config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} );
	}

	function scheduleSearch( query ) {
		window.clearTimeout( timer );
		if ( suppressSearch ) {
			debug( 'search suppressed', 'picker input' );
			return;
		}

		timer = window.setTimeout( function () {
			if ( query.length < ( config.min_chars || 3 ) ) {
				renderMessage( config.strings && config.strings.start ? config.strings.start : '', 'is-hint' );
				return;
			}

			debug( 'search input query', query );
			search( query );
		}, 300 );
	}

	function openPicker( $field ) {
		if ( ! isUsableField( $field ) ) {
			return;
		}

		activeCityField = $field[0];
		initialManualValue = String( $field.val() || '' );
		pickerOpen = true;
		picker().attr( 'aria-hidden', 'false' ).addClass( 'is-open' );
		searchInput().val( initialManualValue );
		debug( 'city picker opened' );

		window.setTimeout( function () {
			searchInput().trigger( 'focus' ).trigger( 'select' );
		}, 20 );

		if ( initialManualValue.length >= ( config.min_chars || 3 ) ) {
			search( initialManualValue );
		} else {
			renderMessage( config.strings && config.strings.start ? config.strings.start : '', 'is-hint' );
		}
	}

	function closePicker( options ) {
		options = options || {};
		if ( ! pickerOpen && ! picker().hasClass( 'is-open' ) ) {
			return;
		}

		pickerOpen = false;
		picker().attr( 'aria-hidden', 'true' ).removeClass( 'is-open' );
		debug( 'city picker closed' );

		if ( options.manualFallback ) {
			applyManualFallback();
		}
	}

	function applyManualFallback() {
		if ( isSelecting || suppressSearch ) {
			return;
		}

		var $field = cityField();
		var query = String( searchInput().val() || '' ).trim();
		if ( ! $field.length || '' === query || query === initialManualValue ) {
			return;
		}

		debug( 'manual fallback city', query );
		clearHidden();
		setFieldValue( $field, query );
		window.setTimeout( function () {
			$( document.body ).trigger( 'update_checkout' );
		}, 50 );
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
		suppressSearch = true;
		window.clearTimeout( suppressTimer );
		debug( 'location selected' );
		debug( 'suppressSearch enabled' );
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

		resultsBox().empty();
		closePicker();
		selectedDisplay = location.display_name || label;
		renderSelectedNotice( $city, selectedDisplay );

		isSelecting = false;
		window.setTimeout( function () {
			debug( 'update_checkout triggered' );
			$( document.body ).trigger( 'update_checkout' );
		}, 50 );
		suppressTimer = window.setTimeout( function () {
			suppressSearch = false;
			debug( 'suppressSearch disabled by timeout' );
		}, 1000 );
	}

	function handleCityInput( event ) {
		var $field = $( event.target );
		if ( suppressSearch ) {
			debug( 'search suppressed', event.type );
			return;
		}
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

		if ( selectedDisplay && query !== selectedDisplay && query !== hiddenValue( 'wdc_platform_location_display_name' ) ) {
			clearHidden();
		}
		openPicker( $field );
		searchInput().val( query );
		scheduleSearch( query );
	}

	function handleCityBlur() {
		if ( suppressSearch || isSelecting || pickerOpen ) {
			debug( 'city blur ignored during picker' );
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
		restoreSelectedNotice();
	}

	function afterCheckoutUpdated() {
		init();
		suppressSearch = false;
		window.clearTimeout( suppressTimer );
		debug( 'suppressSearch disabled after updated_checkout' );
	}

	$( document.body ).off( 'focus.wdcCitySelector click.wdcCitySelector', citySelector );
	$( document.body ).on( 'focus.wdcCitySelector click.wdcCitySelector', citySelector, function ( event ) {
		openPicker( $( event.target ) );
	} );
	$( document.body ).off( 'input.wdcCitySelector keyup.wdcCitySelector change.wdcCitySelector paste.wdcCitySelector', citySelector );
	$( document.body ).on( 'input.wdcCitySelector keyup.wdcCitySelector change.wdcCitySelector paste.wdcCitySelector', citySelector, function ( event ) {
		handleCityInput( event );
	} );
	$( document.body ).off( 'blur.wdcCitySelector', citySelector );
	$( document.body ).on( 'blur.wdcCitySelector', citySelector, handleCityBlur );
	$( document.body ).off( 'input.wdcCitySelector keyup.wdcCitySelector paste.wdcCitySelector', '.wdc-city-picker-search' );
	$( document.body ).on( 'input.wdcCitySelector keyup.wdcCitySelector paste.wdcCitySelector', '.wdc-city-picker-search', function () {
		scheduleSearch( String( $( this ).val() || '' ) );
	} );
	$( document.body ).off( 'mousedown.wdcCitySelector click.wdcCitySelector keydown.wdcCitySelector', '.wdc-city-selector__item' );
	$( document.body ).on( 'mousedown.wdcCitySelector', '.wdc-city-selector__item', function ( event ) {
		event.preventDefault();
		event.stopPropagation();
		selectLocationFromItem( $( this ) );
	} );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-selector__item', function ( event ) {
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
	$( document.body ).off( 'click.wdcCitySelector', '.wdc-city-picker-close' );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-picker-close', function () {
		closePicker( { manualFallback: true } );
	} );
	$( document.body ).off( 'mousedown.wdcCitySelector', '.wdc-city-picker-overlay' );
	$( document.body ).on( 'mousedown.wdcCitySelector', '.wdc-city-picker-overlay', function ( event ) {
		if ( event.target === this ) {
			closePicker( { manualFallback: true } );
		}
	} );
	$( document ).off( 'keydown.wdcCitySelector' );
	$( document ).on( 'keydown.wdcCitySelector', function ( event ) {
		if ( 'Escape' === event.key && pickerOpen ) {
			event.preventDefault();
			closePicker( { manualFallback: true } );
		}
	} );

	$( init );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, afterCheckoutUpdated );
}( jQuery ) );
