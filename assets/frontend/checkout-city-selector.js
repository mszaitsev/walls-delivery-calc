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
	var originalCityValue = '';
	var forceRegionCode = '';
	var autoResolveTimer = null;
	var explicitSelection = false;
	var searchRequestSeq = 0;
	var activeSearchSeq = 0;
	var currentSearchQuery = '';
	var currentBaseQuery = '';
	var lastSearchQuery = '';
	var currentSearchForceRegionCode = '';
	var lastSearchForceRegionCode = '';
	var locationStore = {};
	var locationSeq = 0;
	var lastCountryCode = '';
	var hiddenNames = [
		'wdc_platform_location_id',
		'wdc_platform_location_fias_id',
		'wdc_platform_location_gar_object_id',
		'wdc_platform_location_kladr_id',
		'wdc_platform_location_display_name',
		'wdc_platform_location_postcode',
		'wdc_platform_location_region_code',
		'wdc_platform_location_region_name',
		'wdc_platform_location_region_type',
		'wdc_platform_location_district_name',
		'wdc_platform_location_district_type',
		'wdc_platform_location_city_name',
		'wdc_platform_location_city_type',
		'wdc_platform_location_place_name',
		'wdc_platform_location_place_type',
		'wdc_platform_location_selected_source'
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

	function countryField( prefix ) {
		return firstUsableField( [ '#' + prefix + '_country', 'select[name="' + prefix + '_country"]', 'input[name="' + prefix + '_country"]' ] );
	}

	function shippingAddressActive() {
		var $toggle = $( '#ship-to-different-address-checkbox, input[name="ship_to_different_address"]' ).first();
		if ( $toggle.length ) {
			return $toggle.is( ':checked' );
		}
		return countryField( 'shipping' ).length && ! countryField( 'billing' ).length;
	}

	function currentCountryCode() {
		var $country = shippingAddressActive() ? countryField( 'shipping' ) : countryField( 'billing' );
		if ( ! $country.length ) {
			$country = countryField( 'billing' ).length ? countryField( 'billing' ) : countryField( 'shipping' );
		}
		return String( $country.val() || '' ).toUpperCase();
	}

	function supportedLocationCountries() {
		return Array.isArray( config.supported_location_countries ) ? config.supported_location_countries.map( function ( code ) {
			return String( code || '' ).toUpperCase();
		} ) : [];
	}

	function localDatabaseAvailable() {
		var country = currentCountryCode();
		return !! country && supportedLocationCountries().indexOf( country ) !== -1;
	}

	function handleCountryAvailabilityChanged() {
		var country = currentCountryCode();
		var supported = localDatabaseAvailable();
		if ( lastCountryCode && country !== lastCountryCode ) {
			lastCountryCode = country;
			activeSearchSeq = ++searchRequestSeq;
			lastSearchQuery = '';
			lastSearchForceRegionCode = '';
			forceRegionCode = '';
			if ( pickerOpen ) {
				closePicker();
			}
			clearHidden();
			explicitSelection = false;
		}
		lastCountryCode = country;
		if ( ! supported ) {
			window.clearTimeout( autoResolveTimer );
			activeSearchSeq = ++searchRequestSeq;
			if ( pickerOpen ) {
				closePicker();
			}
			clearHidden();
		}
		return supported;
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

	function renderSelectedNotice( $field, label, postcode, invalid ) {
		clearSelectedNotice();
		if ( $field.length && label ) {
			var text = invalid ? label : 'Выбран: ' + label;
			if ( ! invalid && postcode ) {
				text += ', ' + postcode;
			}
			selectedNotice( $field ).toggleClass( 'is-invalid', !! invalid ).text( text );
		}
	}

	function restoreSelectedNotice() {
		if ( ! localDatabaseAvailable() ) {
			clearHidden();
			return;
		}
		var displayName = hiddenValue( 'wdc_platform_location_display_name' );
		var $field = cityField();
		if ( displayName && $field.length ) {
			selectedDisplay = displayName;
			renderSelectedNotice( $field, displayName, hiddenValue( 'wdc_platform_location_postcode' ), false );
		}
	}

	function checkoutFieldText( $field ) {
		if ( ! $field.length ) {
			return '';
		}
		if ( $field.is( 'select' ) ) {
			var selected = $field.find( 'option:selected' );
			var text = selected.length ? String( selected.text() || '' ) : '';
			return $.trim( text && text !== String( $field.val() || '' ) ? text : String( $field.val() || '' ) );
		}
		return $.trim( String( $field.val() || '' ) );
	}

	function initialPickerQuery( $field ) {
		var city = $.trim( String( $field.val() || '' ) );
		var region = checkoutFieldText( stateField() );
		if ( false === config.include_region_in_query ) {
			return city;
		}
		if ( region && city ) {
			return region + ', ' + city;
		}
		return city || region || '';
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
					'<div class="wdc-city-picker-actions">' +
						'<button type="button" class="wdc-city-picker-use-manual" disabled>Использовать введенное название</button>' +
						'<button type="button" class="wdc-city-picker-clear" disabled>Очистить название</button>' +
					'</div>' +
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

	function updatePickerActions() {
		var value = $.trim( String( searchInput().val() || '' ) );
		picker().find( '.wdc-city-picker-use-manual, .wdc-city-picker-clear' ).prop( 'disabled', '' === value );
	}

	function renderMessage( message, className ) {
		updatePickerActions();
		resultsBox().html( '<div class="wdc-city-picker-message ' + className + '">' + escapeHtml( message || '' ) + '</div>' );
	}

	function renderFallbackMessage( message ) {
		updatePickerActions();
		resultsBox().html( '<div class="wdc-city-picker-message is-empty">' + escapeHtml( message || '' ) + '</div>' );
	}

	function renderResults( groups, limitReached, limit ) {
		updatePickerActions();
		locationStore = {};
		locationSeq = 0;

		var limitMessage = limitReached ? '<div class="wdc-city-picker-limit">Показаны первые ' + escapeHtml( limit ) + ' результатов. Уточните запрос.</div>' : '';
		if ( ! groups || ! groups.length ) {
			renderFallbackMessage( config.strings && config.strings.not_found ? config.strings.not_found : '' );
			return;
		}

		var html = limitMessage + '<div class="wdc-city-picker-groups">';
		groups.forEach( function ( group ) {
			html += '<div class="wdc-city-picker-group">';
			html += '<div class="wdc-city-picker-region">' + escapeHtml( group.region_label || group.region || '' ) + '</div>';
			( group.items || group.locations || [] ).forEach( function ( location ) {
				var label = location.option_label || location.label || location.display_name || location.settlement_name || location.city_name || '';
				var key = 'wdc_loc_' + String( ++locationSeq );
				locationStore[ key ] = location;
				html += '<div role="option" tabindex="0" class="wdc-city-selector__item wdc-city-picker-item" data-location-key="' + escapeHtml( key ) + '">';
				html += escapeHtml( label );
				html += '</div>';
			} );
			if ( group.has_more ) {
				html += '<button type="button" class="wdc-city-picker-show-region" data-region-code="' + escapeHtml( group.region_code || group.region_key || '' ) + '" data-region-label="' + escapeHtml( group.region_label || group.region || '' ) + '">Показать все варианты в области</button>';
			}
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

	function isShortSearchAlias( query ) {
		return 'мо' === $.trim( String( query || '' ) ).toLowerCase();
	}

	function search( query, options ) {
		options = options || {};
		query = String( query || '' );
		if ( ! localDatabaseAvailable() ) {
			clearHidden();
			renderMessage( '', 'is-hint' );
			return;
		}
		var requestForceRegionCode = undefined !== options.forceRegionCode ? String( options.forceRegionCode || '' ) : forceRegionCode;
		if ( ! config.ajax_url ) {
			debug( 'ajax url missing' );
			return;
		}
		if ( query.length < ( config.min_chars || 3 ) && ! requestForceRegionCode && ! isShortSearchAlias( query ) ) {
			renderMessage( config.strings && config.strings.start ? config.strings.start : '', 'is-hint' );
			return;
		}
		if ( ! options.force && query === lastSearchQuery && requestForceRegionCode === lastSearchForceRegionCode ) {
			debug( 'search skipped unchanged query', query );
			return;
		}

		var seq = ++searchRequestSeq;
		activeSearchSeq = seq;
		currentSearchQuery = query;
		currentSearchForceRegionCode = requestForceRegionCode;
		lastSearchQuery = query;
		lastSearchForceRegionCode = requestForceRegionCode;
		debug( 'ajax request start', query );
		renderMessage( config.strings && config.strings.searching ? config.strings.searching : '', 'is-loading' );

		$.ajax( {
			url: config.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'wdc_platform_search_locations',
				nonce: config.nonce,
				query: query,
				country_code: currentCountryCode(),
				limit: config.checkout_location_search_limit || 100,
				region_limit: config.location_region_limit || 10,
				force_region_code: requestForceRegionCode
			}
		} ).done( function ( response ) {
			if ( seq !== activeSearchSeq || query !== currentSearchQuery || requestForceRegionCode !== currentSearchForceRegionCode ) {
				debug( 'stale ajax response ignored', { seq: seq, active: activeSearchSeq, query: query, current: currentSearchQuery, forceRegionCode: requestForceRegionCode, currentForceRegionCode: currentSearchForceRegionCode } );
				return;
			}
			var groups = response && response.data && response.data.groups ? response.data.groups : [];
			if ( response && response.data && false === response.data.local_database_available ) {
				clearHidden();
				renderResults( [], false, response.data.limit || config.checkout_location_search_limit || 100 );
				return;
			}
			debug( 'ajax success groups count', groups.length );
			debug( 'limit reached', !! ( response && response.data && response.data.limit_reached ) );
			debug( 'corrected query', response && response.data ? response.data.corrected_query || '' : '' );
			debug( 'correction used', !! ( response && response.data && response.data.correction_used ) );
			if ( response && response.success ) {
				renderResults( groups, !! response.data.limit_reached, response.data.limit || config.checkout_location_search_limit || 100 );
				return;
			}
			renderMessage( config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} ).fail( function ( xhr ) {
			if ( seq !== activeSearchSeq || query !== currentSearchQuery || requestForceRegionCode !== currentSearchForceRegionCode ) {
				debug( 'stale ajax failure ignored', { seq: seq, active: activeSearchSeq, query: query, current: currentSearchQuery, forceRegionCode: requestForceRegionCode, currentForceRegionCode: currentSearchForceRegionCode } );
				return;
			}
			debug( 'ajax fail', xhr );
			renderMessage( config.strings && config.strings.error ? config.strings.error : '', 'is-error' );
		} );
	}

	function scheduleSearch( query ) {
		window.clearTimeout( timer );
		if ( ! localDatabaseAvailable() ) {
			clearHidden();
			return;
		}
		if ( suppressSearch ) {
			debug( 'search suppressed', 'picker input' );
			return;
		}

		timer = window.setTimeout( function () {
			if ( query.length < ( config.min_chars || 3 ) && ! isShortSearchAlias( query ) ) {
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
		if ( ! localDatabaseAvailable() ) {
			clearHidden();
			return;
		}
		if ( pickerOpen && activeCityField === $field[0] ) {
			searchInput().trigger( 'focus' ).trigger( 'select' );
			return;
		}

		activeCityField = $field[0];
		originalCityValue = initialPickerQuery( $field );
		forceRegionCode = '';
		currentBaseQuery = originalCityValue;
		lastSearchQuery = '';
		lastSearchForceRegionCode = '';
		pickerOpen = true;
		picker().attr( 'aria-hidden', 'false' ).addClass( 'is-open' );
		searchInput().val( originalCityValue );
		updatePickerActions();
		debug( 'city picker opened' );

		window.setTimeout( function () {
			searchInput().trigger( 'focus' ).trigger( 'select' );
		}, 20 );

		if ( originalCityValue.length >= ( config.min_chars || 3 ) || isShortSearchAlias( originalCityValue ) ) {
			search( originalCityValue, { force: true } );
		} else {
			renderMessage( config.strings && config.strings.start ? config.strings.start : '', 'is-hint' );
		}
	}

	function closePicker() {
		pickerOpen = false;
		activeSearchSeq = ++searchRequestSeq;
		currentSearchQuery = '';
		currentSearchForceRegionCode = '';
		activeCityField = isUsableField( activeCityField ) ? activeCityField : null;
		$( '.wdc-city-picker-overlay, .wdc-city-picker-panel, .wdc-city-selector' ).remove();
		debug( 'city picker closed' );
	}

	function stopEvent( event ) {
		event.preventDefault();
		event.stopPropagation();
		if ( event.stopImmediatePropagation ) {
			event.stopImmediatePropagation();
		}
	}

	function applyManualFallbackCity( query ) {
		if ( isSelecting ) {
			return;
		}

		isSelecting = true;
		suppressSearch = true;
		window.clearTimeout( suppressTimer );
		debug( 'fallback selection start' );

		var $field = cityField();
		query = String( query || '' ).trim();
		if ( ! $field.length || '' === query ) {
			isSelecting = false;
			return;
		}

		debug( 'manual fallback city', query );
		explicitSelection = false;
		clearHidden();
		setFieldValue( $field, query );
		selectedDisplay = query;
		debug( 'fallback city applied' );
		closePicker();
		debug( 'picker closed after fallback' );
		if ( localDatabaseAvailable() ) {
			renderSelectedNotice( $field, 'Просим проверить название и внести верный населенный пункт', '', true );
		}
		isSelecting = false;
		window.setTimeout( function () {
			debug( 'update_checkout triggered after fallback' );
			$( document.body ).trigger( 'update_checkout' );
		}, 50 );
		suppressTimer = window.setTimeout( function () {
			suppressSearch = false;
			debug( 'suppressSearch disabled by timeout' );
		}, 1000 );
	}

	function setFieldValue( $field, value ) {
		if ( $field.length && undefined !== value && null !== value && '' !== String( value ) ) {
			$field.val( value ).trigger( 'input' ).trigger( 'change' );
		}
	}

	function setStateField( $state, location ) {
		var regionCode = location.region_code || '';
		var regionName = location.state_value || location.region_name || '';
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

		applySelectedLocation( location, { updateCheckout: true, explicit: true, source: 'modal', updateFields: true } );
	}

	function applySelectedLocation( location, options ) {
		options = options || {};
		if ( ! localDatabaseAvailable() || String( location.country_code || currentCountryCode() ).toUpperCase() !== currentCountryCode() ) {
			clearHidden();
			return;
		}
		var updateCheckout = false !== options.updateCheckout;
		var updateFields = false !== options.updateFields;
		var source = options.source || ( false === options.explicit ? 'auto' : 'modal' );
		isSelecting = true;
		suppressSearch = true;
		window.clearTimeout( suppressTimer );
		debug( 'location selected' );
		debug( 'suppressSearch enabled' );
		debug( 'selected payload', location );

		var city = location.city_value || location.settlement_name || location.city_name || location.display_name || '';
		var label = location.display_name || location.option_label || city;
		var $city = cityField();
		var $postcode = postcodeField();
		var $state = stateField();

		debug( 'fields before', {
			city: $city.val(),
			postcode: $postcode.val(),
			state: $state.val()
		} );

		if ( updateFields ) {
			setFieldValue( $city, city );
			if ( location.postal_code ) {
				setFieldValue( $postcode, location.postal_code );
			}
			setStateField( $state, location );
		}

		debug( 'fields after', {
			city: $city.val(),
			postcode: $postcode.val(),
			state: $state.val()
		} );

		setHidden( 'wdc_platform_location_id', location.id );
		setHidden( 'wdc_platform_location_fias_id', location.fias_id );
		setHidden( 'wdc_platform_location_gar_object_id', location.gar_object_id || location.gar_id );
		setHidden( 'wdc_platform_location_kladr_id', location.kladr_id );
		setHidden( 'wdc_platform_location_display_name', location.display_name || label );
		setHidden( 'wdc_platform_location_postcode', location.postal_code );
		setHidden( 'wdc_platform_location_region_code', location.region_code );
		setHidden( 'wdc_platform_location_region_name', location.region_name );
		setHidden( 'wdc_platform_location_region_type', location.region_type );
		setHidden( 'wdc_platform_location_district_name', location.district_name );
		setHidden( 'wdc_platform_location_district_type', location.district_type );
		setHidden( 'wdc_platform_location_city_name', location.city_name );
		setHidden( 'wdc_platform_location_city_type', location.city_type );
		setHidden( 'wdc_platform_location_place_name', location.place_name || location.settlement_name );
		setHidden( 'wdc_platform_location_place_type', location.place_type );
		setHidden( 'wdc_platform_location_selected_source', source );
		explicitSelection = true === options.explicit;
		debug( 'hidden fields set' );

		if ( updateCheckout || true === options.explicit ) {
			resultsBox().empty();
			closePicker();
		}
		selectedDisplay = location.display_name || label;
		renderSelectedNotice( $city, selectedDisplay, location.postal_code, false );

		isSelecting = false;
		if ( updateCheckout ) {
			window.setTimeout( function () {
				debug( 'update_checkout triggered' );
				$( document.body ).trigger( 'update_checkout' );
			}, 50 );
		}
		suppressTimer = window.setTimeout( function () {
			suppressSearch = false;
			debug( 'suppressSearch disabled by timeout' );
		}, 1000 );
	}

	function handleExternalCityChanged( event ) {
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
			explicitSelection = false;
			clearHidden();
			scheduleAutoResolve();
		}
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
		if ( ! handleCountryAvailabilityChanged() ) {
			return;
		}
		restoreSelectedNotice();
		if ( ! hasSelectedLocation() ) {
			scheduleAutoResolve();
		}
	}

	function afterCheckoutUpdated() {
		init();
		suppressSearch = false;
		window.clearTimeout( suppressTimer );
		debug( 'suppressSearch disabled after updated_checkout' );
		if ( localDatabaseAvailable() ) {
			restoreSelectedNotice();
		}
	}

	function showInvalidNotice() {
		if ( ! localDatabaseAvailable() ) {
			clearHidden();
			return;
		}
		renderSelectedNotice( cityField(), 'Просим проверить название и внести верный населенный пункт', '', true );
	}

	function hasSelectedLocation() {
		return !! ( hiddenValue( 'wdc_platform_location_fias_id' ) && hiddenValue( 'wdc_platform_location_display_name' ) );
	}

	function scheduleAutoResolve() {
		window.clearTimeout( autoResolveTimer );
		if ( ! localDatabaseAvailable() ) {
			clearHidden();
			return;
		}
		if ( explicitSelection || pickerOpen || isSelecting || hasSelectedLocation() ) {
			return;
		}
		autoResolveTimer = window.setTimeout( autoResolve, 450 );
	}

	function autoResolve() {
		if ( ! config.ajax_url || ! localDatabaseAvailable() || explicitSelection || pickerOpen || isSelecting || hasSelectedLocation() ) {
			return;
		}
		var regionText = checkoutFieldText( stateField() );
		var cityText = checkoutFieldText( cityField() );
		if ( ! regionText && ! cityText ) {
			clearHidden();
			return;
		}
		$.ajax( {
			url: config.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: {
				action: config.resolve_action || 'wdc_platform_resolve_checkout_location',
				nonce: config.nonce,
				country_code: currentCountryCode(),
				region_text: regionText,
				city_text: cityText
			}
		} ).done( function ( response ) {
			var body = response && response.data ? response.data : {};
			if ( false === body.local_database_available ) {
				clearHidden();
				return;
			}
			if ( response && response.success && 'resolved' === body.status && body.selected ) {
				if ( hiddenValue( 'wdc_platform_location_fias_id' ) === String( body.selected.fias_id || '' ) ) {
					restoreSelectedNotice();
					return;
				}
				applySelectedLocation( body.selected, { updateCheckout: false, explicit: false, source: 'auto', updateFields: false } );
				return;
			}
			clearHidden();
			showInvalidNotice();
		} ).fail( function () {
			clearHidden();
			showInvalidNotice();
		} );
	}

	$( document.body ).off( 'focus.wdcCitySelector click.wdcCitySelector', citySelector );
	$( document.body ).on( 'focus.wdcCitySelector click.wdcCitySelector', citySelector, function ( event ) {
		openPicker( $( event.target ) );
	} );
	$( document.body ).off( 'input.wdcCitySelector change.wdcCitySelector', citySelector );
	$( document.body ).on( 'input.wdcCitySelector change.wdcCitySelector', citySelector, function ( event ) {
		handleExternalCityChanged( event );
	} );
	$( document.body ).off( 'change.wdcCitySelector blur.wdcCitySelector', '#shipping_state, select[name="shipping_state"], input[name="shipping_state"], #billing_state, select[name="billing_state"], input[name="billing_state"]' );
	$( document.body ).on( 'change.wdcCitySelector blur.wdcCitySelector', '#shipping_state, select[name="shipping_state"], input[name="shipping_state"], #billing_state, select[name="billing_state"], input[name="billing_state"]', function () {
		if ( ! isSelecting && ! suppressSearch ) {
			explicitSelection = false;
			clearHidden();
			scheduleAutoResolve();
		}
	} );
	$( document.body ).off( 'change.wdcCitySelector', '#shipping_country, select[name="shipping_country"], input[name="shipping_country"], #billing_country, select[name="billing_country"], input[name="billing_country"], #ship-to-different-address-checkbox, input[name="ship_to_different_address"]' );
	$( document.body ).on( 'change.wdcCitySelector', '#shipping_country, select[name="shipping_country"], input[name="shipping_country"], #billing_country, select[name="billing_country"], input[name="billing_country"], #ship-to-different-address-checkbox, input[name="ship_to_different_address"]', function () {
		if ( handleCountryAvailabilityChanged() ) {
			scheduleAutoResolve();
		}
	} );
	$( document.body ).off( 'blur.wdcCitySelector', citySelector );
	$( document.body ).on( 'blur.wdcCitySelector', citySelector, handleCityBlur );
	$( document.body ).off( 'input.wdcCitySelector', '.wdc-city-picker-search' );
	$( document.body ).on( 'input.wdcCitySelector', '.wdc-city-picker-search', function () {
		forceRegionCode = '';
		currentBaseQuery = String( $( this ).val() || '' );
		updatePickerActions();
		scheduleSearch( currentBaseQuery );
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
	$( document.body ).off( 'click.wdcCitySelector', '.wdc-city-picker-show-region' );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-picker-show-region', function ( event ) {
		stopEvent( event );
		forceRegionCode = String( $( this ).attr( 'data-region-code' ) || '' );
		var label = String( $( this ).attr( 'data-region-label' ) || '' );
		var current = currentBaseQuery || String( searchInput().val() || '' );
		if ( label && current.indexOf( label ) !== 0 ) {
			searchInput().val( label + ( current ? ', ' + current : ', ' ) );
		}
		updatePickerActions();
		search( current, { force: true, forceRegionCode: forceRegionCode } );
	} );
	$( document.body ).off( 'click.wdcCitySelector', '.wdc-city-picker-close' );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-picker-close', function ( event ) {
		stopEvent( event );
		closePicker();
	} );
	$( document.body ).off( 'mousedown.wdcCitySelector click.wdcCitySelector keydown.wdcCitySelector', '.wdc-city-picker-use-manual' );
	$( document.body ).on( 'mousedown.wdcCitySelector', '.wdc-city-picker-use-manual', function ( event ) {
		debug( 'manual city button mousedown' );
		stopEvent( event );
		applyManualFallbackCity( searchInput().val() );
	} );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-picker-use-manual', function ( event ) {
		stopEvent( event );
	} );
	$( document.body ).on( 'keydown.wdcCitySelector', '.wdc-city-picker-use-manual', function ( event ) {
		if ( 'Enter' !== event.key && ' ' !== event.key ) {
			return;
		}
		stopEvent( event );
		applyManualFallbackCity( searchInput().val() );
	} );
	$( document.body ).off( 'click.wdcCitySelector', '.wdc-city-picker-clear' );
	$( document.body ).on( 'click.wdcCitySelector', '.wdc-city-picker-clear', function ( event ) {
		stopEvent( event );
		forceRegionCode = '';
		currentBaseQuery = '';
		lastSearchQuery = '';
		lastSearchForceRegionCode = '';
		searchInput().val( '' );
		updatePickerActions();
		renderMessage( config.strings && config.strings.start ? config.strings.start : '', 'is-hint' );
	} );
	$( document.body ).off( 'mousedown.wdcCitySelector click.wdcCitySelector', '.wdc-city-picker-panel' );
	$( document.body ).on( 'mousedown.wdcCitySelector click.wdcCitySelector', '.wdc-city-picker-panel', function ( event ) {
		event.stopPropagation();
	} );
	$( document.body ).off( 'mousedown.wdcCitySelector', '.wdc-city-picker-overlay' );
	$( document.body ).on( 'mousedown.wdcCitySelector', '.wdc-city-picker-overlay', function ( event ) {
		if ( event.target === this ) {
			closePicker();
		}
	} );
	$( document ).off( 'keydown.wdcCitySelector' );
	$( document ).on( 'keydown.wdcCitySelector', function ( event ) {
		if ( 'Escape' === event.key && pickerOpen ) {
			event.preventDefault();
			closePicker();
		}
	} );

	$( init );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, afterCheckoutUpdated );
}( jQuery ) );
