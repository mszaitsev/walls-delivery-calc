( function ( $, window, document ) {
	'use strict';

	var config = window.wdcPlatformAddressSuggestions || {};
	var namespace = '.wdcAddressSuggestions';
	var debounceTimer = null;
	var debounceDelay = 300;
	var activePrefix = 'billing';
	var activeAddressField = null;
	var pickerOpen = false;
	var itemStore = {};
	var itemCounter = 0;
	var addressPickerState = {};
	var debugState = {
		scriptLoaded: true,
		configEnabled: !! config.enabled,
		activePrefix: 'billing',
		modalOpened: 'no',
		lastStage: '',
		lastQuery: '',
		lastAjaxStatus: '',
		lastItemsCount: ''
	};
	var hiddenKeys = [
		'dadata_status',
		'dadata_unrestricted_value',
		'dadata_region',
		'dadata_region_with_type',
		'dadata_region_fias_id',
		'dadata_region_kladr_id',
		'dadata_city',
		'dadata_city_with_type',
		'dadata_city_fias_id',
		'dadata_city_kladr_id',
		'dadata_settlement',
		'dadata_settlement_with_type',
		'dadata_settlement_fias_id',
		'dadata_settlement_kladr_id',
		'dadata_street',
		'dadata_street_with_type',
		'dadata_street_fias_id',
		'dadata_street_kladr_id',
		'dadata_house',
		'dadata_house_type',
		'dadata_house_fias_id',
		'dadata_house_kladr_id',
		'dadata_block',
		'dadata_block_type',
		'dadata_stead',
		'dadata_stead_type',
		'dadata_flat',
		'dadata_fias_id',
		'dadata_kladr_id',
		'dadata_fias_level'
	];

	function log( message, data ) {
		if ( config.debug && window.console && window.console.log ) {
			window.console.log( '[WDC DaData address picker] ' + message, data || {} );
		}
	}

	function minChars() {
		return parseInt( config.min_chars || 3, 10 ) || 3;
	}

	function selectorFor( prefix, name ) {
		if ( 'state' === name ) {
			return '#' + prefix + '_state,select[name="' + prefix + '_state"],input[name="' + prefix + '_state"]';
		}
		return '#' + prefix + '_' + name + ',input[name="' + prefix + '_' + name + '"],textarea[name="' + prefix + '_' + name + '"]';
	}

	function isUsable( element ) {
		var field = $( element );
		return field.length && ! field.prop( 'disabled' ) && ! field.prop( 'readonly' );
	}

	function visibleUsable( selector ) {
		return $( selector ).filter( ':visible' ).filter( function () {
			return isUsable( this );
		} );
	}

	function firstUsable( prefix, name ) {
		var selector = selectorFor( prefix, name );
		var visible = visibleUsable( selector );
		if ( visible.length ) {
			return visible.first();
		}
		return $( selector ).filter( function () {
			return isUsable( this );
		} ).first();
	}

	function globalHidden( name ) {
		return $( 'input[name="' + name + '"]' ).first();
	}

	function globalHiddenValue( name ) {
		var field = globalHidden( name );
		return field.length ? String( field.val() || '' ) : '';
	}

	function setGlobalHidden( name, value ) {
		var field = globalHidden( name );
		if ( field.length ) {
			field.val( value || '' );
		}
	}

	function fieldValue( prefix, name ) {
		var field = firstUsable( prefix, name );
		return field.length ? String( field.val() || '' ).trim() : '';
	}

	function checkoutFieldValue( prefix, name ) {
		var field = firstUsable( prefix, name );
		if ( ! field.length ) {
			return '';
		}
		if ( 'state' === name && field.is( 'select' ) ) {
			var selected = field.find( 'option:selected' );
			var text = selected.length ? cleanQueryPart( selected.text() ) : '';
			return text || cleanQueryPart( field.val() );
		}
		return cleanQueryPart( field.val() );
	}

	function cleanQueryPart( value ) {
		var cleaned = String( value || '' )
			.replace( /\s+/g, ' ' )
			.replace( /^[\s,]+|[\s,]+$/g, '' )
			.trim();
		var duplicate = cleaned.match( /^(.+?)\s+-\s+(.+)$/ );
		if ( duplicate && duplicate[1] && duplicate[2] ) {
			return duplicate[1].trim();
		}
		return cleaned;
	}

	function activeCheckoutPrefix() {
		var toggle = $( '#ship-to-different-address-checkbox,input[name="ship_to_different_address"]' ).first();
		var shippingChecked = toggle.length ? toggle.is( ':checked' ) : false;
		var shippingActive = shippingChecked && ( visibleUsable( selectorFor( 'shipping', 'address_1' ) ).length > 0 || visibleUsable( selectorFor( 'shipping', 'city' ) ).length > 0 );
		log( 'shipping mode active', { active: shippingActive } );
		log( 'billing mode active', { active: ! shippingActive } );
		return shippingActive ? 'shipping' : 'billing';
	}

	function stateFor( prefix ) {
		if ( ! addressPickerState[ prefix ] ) {
			addressPickerState[ prefix ] = {
				lastResolved: null,
				selectedHouseItem: null,
				selectedHouseQuery: '',
				selectedHouseBaseQuery: '',
				selectedHouseDisplayBase: '',
				selectedHouseContext: {},
				awaitingFlatSelection: false,
				nextLevelMode: ''
			};
		}
		return addressPickerState[ prefix ];
	}

	function clearHouseLookupState( prefix ) {
		var state = stateFor( prefix );
		state.selectedHouseItem = null;
		state.selectedHouseQuery = '';
		state.selectedHouseBaseQuery = '';
		state.selectedHouseDisplayBase = '';
		state.selectedHouseContext = {};
		state.awaitingFlatSelection = false;
		state.nextLevelMode = '';
	}

	function ensureHiddenFields( prefix ) {
		var anchor = firstUsable( prefix, 'address_2' );
		if ( ! anchor.length ) {
			anchor = firstUsable( prefix, 'address_1' );
		}
		if ( ! anchor.length ) {
			anchor = firstUsable( prefix, 'city' );
		}
		hiddenKeys.forEach( function ( key ) {
			var name = prefix + '_' + key;
			if ( $( 'input[name="' + name + '"]' ).length ) {
				return;
			}
			$( '<input>', { type: 'hidden', name: name, id: name, value: 'dadata_status' === key ? 'empty' : '' } ).insertAfter( anchor );
		} );
	}

	function hidden( prefix, key ) {
		ensureHiddenFields( prefix );
		return $( 'input[name="' + prefix + '_' + key + '"]' );
	}

	function clearAddressHidden( prefix ) {
		[
			'dadata_unrestricted_value',
			'dadata_street',
			'dadata_street_with_type',
			'dadata_street_fias_id',
			'dadata_street_kladr_id',
			'dadata_house',
			'dadata_house_type',
			'dadata_house_fias_id',
			'dadata_house_kladr_id',
			'dadata_block',
			'dadata_block_type',
			'dadata_stead',
			'dadata_stead_type',
			'dadata_flat',
			'dadata_fias_id',
			'dadata_kladr_id',
			'dadata_fias_level'
		].forEach( function ( key ) {
			hidden( prefix, key ).val( '' );
		} );
	}

	function setHiddenData( prefix, item, status ) {
		var data = item && item.data ? item.data : {};
		hidden( prefix, 'dadata_status' ).val( status || 'manual' );
		hidden( prefix, 'dadata_unrestricted_value' ).val( item ? item.unrestrictedValue || '' : '' );
		Object.keys( data ).forEach( function ( key ) {
			hidden( prefix, 'dadata_' + key ).val( data[ key ] || '' );
		} );
		hidden( prefix, 'dadata_fias_level' ).val( item ? item.fiasLevel || '' : '' );
	}

	function context( prefix, extra ) {
		var base = {
			city_kladr_id: hidden( prefix, 'dadata_city_kladr_id' ).val() || '',
			city_fias_id: hidden( prefix, 'dadata_city_fias_id' ).val() || '',
			settlement_kladr_id: hidden( prefix, 'dadata_settlement_kladr_id' ).val() || '',
			settlement_fias_id: hidden( prefix, 'dadata_settlement_fias_id' ).val() || '',
			selected_display_name: globalHiddenValue( 'wdc_platform_location_display_name' ),
			city: firstUsable( prefix, 'city' ).val() || ''
		};
		extra = extra || {};
		Object.keys( extra ).forEach( function ( key ) {
			base[ key ] = extra[ key ];
		} );
		return base;
	}

	function openingQuery( prefix ) {
		var selectedFiasId = globalHiddenValue( 'wdc_platform_location_fias_id' );
		var selectedDisplayName = globalHiddenValue( 'wdc_platform_location_display_name' );
		var selectedAddress = checkoutFieldValue( prefix, 'address_1' );
		if ( selectedFiasId && selectedDisplayName ) {
			var selectedQuery = selectedDisplayName + ', ' + ( selectedAddress || '' );
			log( 'opening query built', { locationSource: 'local_selected', fias_id: selectedFiasId, display_name: selectedDisplayName, addressSource: 'checkout_address_1', address: selectedAddress, query: selectedQuery } );
			return selectedQuery;
		}
		var region = checkoutFieldValue( prefix, 'state' );
		var city = checkoutFieldValue( prefix, 'city' );
		var address = checkoutFieldValue( prefix, 'address_1' );
		var parts = [];
		if ( region ) {
			parts.push( region );
		}
		if ( city ) {
			parts.push( city );
		}
		if ( address ) {
			parts.push( address );
			var fullQuery = parts.join( ', ' );
			log( 'opening query built', { regionSource: 'checkout_state', region: region, citySource: 'checkout_city', city: city, addressSource: 'checkout_address_1', address: address, query: fullQuery } );
			return fullQuery;
		}
		var query = parts.length ? parts.join( ', ' ) + ', ' : '';
		log( 'opening query built', { regionSource: 'checkout_state', region: region, citySource: 'checkout_city', city: city, addressSource: 'checkout_address_1', address: address, query: query } );
		return query;
	}

	function houseWithType( data ) {
		if ( data.house ) {
			return String( ( data.house_type || 'д' ) + ' ' + data.house ).trim();
		}
		if ( data.stead ) {
			return String( ( data.stead_type || 'уч' ) + ' ' + data.stead ).trim();
		}
		return '';
	}

	function formatStreetHouse( data ) {
		var parts = [];
		if ( data.street_with_type || data.street ) {
			parts.push( data.street_with_type || data.street );
		}
		if ( houseWithType( data ) ) {
			parts.push( houseWithType( data ) );
		}
		if ( data.block ) {
			parts.push( String( ( data.block_type || 'к' ) + ' ' + data.block ).trim() );
		}
		if ( data.flat ) {
			parts.push( String( ( data.flat_type || 'кв' ) + ' ' + data.flat ).trim() );
		}
		if ( data.room || data.room_number || data.premise ) {
			parts.push( String( ( data.room_type || data.premise_type || 'пом' ) + ' ' + ( data.room || data.room_number || data.premise ) ).trim() );
		}
		return parts.join( ', ' );
	}

	function formatAddressWithoutRegionCity( data ) {
		return formatStreetHouse( data );
	}

	function formatFullAddressWithoutCountry( item ) {
		var data = item.data || {};
		var unrestricted = String( item.unrestrictedValue || item.value || '' ).replace( /^Россия,\s*/i, '' );
		if ( unrestricted ) {
			return unrestricted;
		}
		return [
			data.region_with_type || data.region || '',
			data.city_with_type || data.city || data.settlement_with_type || data.settlement || '',
			formatStreetHouse( data )
		].filter( Boolean ).join( ', ' );
	}

	function ensureTrailingComma( value ) {
		var text = String( value || '' ).replace( /\s+/g, ' ' ).replace( /\s*,\s*$/g, '' ).trim();
		return text ? text + ', ' : '';
	}

	function normalizeHouseBaseForCompare( value ) {
		return String( value || '' ).toLowerCase().replace( /\s+/g, ' ' ).replace( /\s*,\s*/g, ', ' ).trim();
	}

	function startsWithHouseBase( query, base ) {
		var normalizedQuery = normalizeHouseBaseForCompare( query );
		var normalizedBase = normalizeHouseBaseForCompare( base );
		var remainder = '';
		if ( ! normalizedBase || normalizedQuery.length < normalizedBase.length || normalizedQuery.slice( 0, normalizedBase.length ) !== normalizedBase ) {
			return false;
		}
		remainder = normalizedQuery.slice( normalizedBase.length );
		return '' === remainder || /^[\s,]+/.test( remainder );
	}

	function queryMatchesSelectedHouseBase( query, state ) {
		return startsWithHouseBase( query, state.selectedHouseBaseQuery ) || startsWithHouseBase( query, state.selectedHouseDisplayBase );
	}

	function currentLocationFias() {
		return {
			region: globalHiddenValue( 'wdc_platform_location_region_fias_id' ),
			city: globalHiddenValue( 'wdc_platform_location_fias_id' )
		};
	}

	function dadataLocationFias( data ) {
		return {
			region: data.region_fias_id || '',
			city: data.city_fias_id || data.settlement_fias_id || ''
		};
	}

	function sameSelectedLocation( data ) {
		var current = currentLocationFias();
		var dadata = dadataLocationFias( data );
		return !! ( current.city && dadata.city && current.city === dadata.city && ( ! current.region || ! dadata.region || current.region === dadata.region ) );
	}

	function localLocationMatchesDadata( data ) {
		return sameSelectedLocation( data );
	}

	function picker() {
		var overlay = $( '.wdc-address-picker-overlay' );
		if ( overlay.length ) {
			return overlay;
		}
		overlay = $(
			'<div class="wdc-address-picker-overlay" aria-hidden="true">' +
				'<div class="wdc-address-picker-panel" role="dialog" aria-modal="true" aria-label="Выберите адрес доставки">' +
					'<div class="wdc-address-picker-header">' +
						'<div class="wdc-address-picker-title">Выберите адрес доставки</div>' +
						'<button type="button" class="wdc-address-picker-close" aria-label="Закрыть">×</button>' +
					'</div>' +
					'<input type="search" class="wdc-address-picker-search" autocomplete="off" placeholder="Начните вводить улицу и дом">' +
					'<div class="wdc-address-picker-hint"></div>' +
					'<div class="wdc-address-picker-results" role="listbox"></div>' +
				'</div>' +
			'</div>'
		);
		$( document.body ).append( overlay );
		return overlay;
	}

	function searchInput() {
		return picker().find( '.wdc-address-picker-search' );
	}

	function resultsBox() {
		return picker().find( '.wdc-address-picker-results' );
	}

	function hintBox() {
		return picker().find( '.wdc-address-picker-hint' );
	}

	function showHint( message ) {
		hintBox().html( message ? '<span>' + escapeHtml( message ) + '</span>' : '' );
	}

	function showFlatHintWithHouseFinalize() {
		var hint = hintBox();
		hint.empty();
		$( '<span>' ).text( 'Уточните квартиру, помещение или офис (если номера нет - ' ).appendTo( hint );
		$( '<button>', {
			type: 'button',
			class: 'wdc-address-picker-house-finalize',
			text: 'нажмите здесь'
		} ).appendTo( hint );
		$( '<span>' ).text( ')' ).appendTo( hint );
	}

	function escapeHtml( value ) {
		return String( value || '' ).replace( /[&<>"']/g, function ( char ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ char ];
		} );
	}

	function renderDebugBlock() {
		if ( ! config.debug ) {
			return;
		}
		var address = firstUsable( activePrefix, 'address_1' );
		var block = address.siblings( '.wdc-address-suggestions-debug' ).first();
		if ( ! block.length ) {
			block = $( '<div>', { class: 'wdc-address-suggestions-debug' } ).insertAfter( address );
		}
		block.html(
			'<strong>DaData подсказки:</strong> script loaded<br>' +
			'config enabled: ' + ( debugState.configEnabled ? 'yes' : 'no' ) + '<br>' +
			'tokens ready: ' + ( config.tokens_ready ? 'yes' : 'no' ) + '<br>' +
			'total tokens: ' + escapeHtml( config.total_tokens_count || 0 ) + '<br>' +
			'available tokens: ' + escapeHtml( config.available_tokens_count || 0 ) + '<br>' +
			'encryption ready: ' + ( config.encryption_ready ? 'yes' : 'no' ) + '<br>' +
			'active mode: ' + escapeHtml( debugState.activePrefix ) + '<br>' +
			'active prefix: ' + escapeHtml( debugState.activePrefix ) + '<br>' +
			'active address field: ' + escapeHtml( selectorFor( debugState.activePrefix, 'address_1' ) ) + '<br>' +
			'active city field: ' + escapeHtml( selectorFor( debugState.activePrefix, 'city' ) ) + '<br>' +
			'modal opened: ' + escapeHtml( debugState.modalOpened ) + '<br>' +
			'last stage: ' + escapeHtml( debugState.lastStage ) + '<br>' +
			'last query: ' + escapeHtml( debugState.lastQuery ) + '<br>' +
			'last ajax status: ' + escapeHtml( debugState.lastAjaxStatus ) + '<br>' +
			'last items count: ' + escapeHtml( debugState.lastItemsCount )
		);
	}

	function showSelectedNotice( prefix, message ) {
		var address = firstUsable( prefix, 'address_1' );
		var notice = address.siblings( '.wdc-address-picker-selected' ).first();
		if ( ! notice.length ) {
			notice = $( '<div>', { class: 'wdc-address-picker-selected' } ).insertAfter( address );
		}
		notice.text( message );
	}

	function request( stage, query, prefix, done, extraContext ) {
		debugState.lastStage = stage;
		debugState.lastQuery = query;
		debugState.lastAjaxStatus = 'pending';
		renderDebugBlock();
		log( 'stage', { stage: stage } );
		log( 'query', { query: query } );
		log( 'ajax request start', { stage: stage, query: query, context: context( prefix, extraContext ) } );
		$.post( config.ajax_url || '', {
			action: config.action || 'wdc_platform_dadata_address_suggest',
			nonce: config.nonce || '',
			stage: stage,
			query: query,
			context: context( prefix, extraContext )
		} ).done( function ( response ) {
			var body = response && response.data ? response.data : response;
			var items = body && body.items ? body.items : [];
			debugState.lastAjaxStatus = body && false === body.success ? body.error_code || 'failed' : 'success';
			debugState.lastItemsCount = String( items.length );
			renderDebugBlock();
			log( 'ajax success items count', { count: items.length } );
			done( items, body || {} );
		} ).fail( function ( xhr ) {
			debugState.lastAjaxStatus = 'fail ' + ( xhr && xhr.status ? xhr.status : 0 );
			debugState.lastItemsCount = '0';
			renderDebugBlock();
			log( 'ajax fail', { status: xhr && xhr.status ? xhr.status : 0 } );
			done( [], {} );
		} );
	}

	function lowerLevelItems( items ) {
		return items.filter( function ( item ) {
			return 'flat' === item.level || 'room' === item.level || 'premise' === item.level;
		} );
	}

	function houseLevelItem( item ) {
		var clone = $.extend( true, {}, item || {} );
		var data = clone.data || {};
		[
			'flat',
			'flat_type',
			'flat_type_full',
			'room',
			'room_number',
			'room_type',
			'room_type_full',
			'premise',
			'premise_type',
			'premise_type_full'
		].forEach( function ( key ) {
			delete data[ key ];
		} );
		data.flat = '';
		clone.data = data;
		clone.level = 'house';
		clone.isDeliverable = true;
		clone.fiasLevel = data.fias_level || clone.fiasLevel || '';
		return clone;
	}

	function requestLowerLevelAfterHouse( prefix, item ) {
		var data = item.data || {};
		var query = item.unrestrictedValue || item.value || item.label || '';
		var displayBase = formatAddressWithoutRegionCity( data ) || query;
		var nextLevelQuery = ensureTrailingComma( query );
		var houseContext = {
			selected_level: 'house',
			desired_level: 'flat',
			house_fias_id: data.house_fias_id || '',
			house_kladr_id: data.house_kladr_id || ''
		};
		var state = stateFor( prefix );
		state.selectedHouseItem = item;
		state.selectedHouseQuery = query;
		state.selectedHouseBaseQuery = query;
		state.selectedHouseDisplayBase = displayBase;
		state.selectedHouseContext = houseContext;
		state.awaitingFlatSelection = true;
		state.nextLevelMode = 'address_next';
		searchInput().val( nextLevelQuery );
		firstUsable( prefix, 'address_1' ).val( displayBase );
		showFlatHintWithHouseFinalize();
		log( 'lower-level request after house selection', { query: query } );
		request( 'address_next', query, prefix, function ( items ) {
			var lower = lowerLevelItems( items );
			if ( lower.length ) {
				renderResults( lower, query );
				showFlatHintWithHouseFinalize();
				return;
			}
			clearHouseLookupState( prefix );
			applyResolved( prefix, item );
		}, houseContext );
		window.setTimeout( function () {
			searchInput().trigger( 'focus' );
			var input = searchInput()[0];
			if ( input && input.setSelectionRange ) {
				input.setSelectionRange( input.value.length, input.value.length );
			}
		}, 20 );
	}

	function trackSelectionUsage( item, usageType ) {
		if ( ! config.ajax_url ) {
			return;
		}
		$.post( config.ajax_url, {
			action: config.selection_action || 'wdc_platform_dadata_suggestion_selected',
			nonce: config.nonce || '',
			level: item && item.level ? item.level : 'unknown',
			usage_type: usageType || 'suggestion_click'
		} ).done( function ( response ) {
			log( 'selection usage counted', response || {} );
		} ).fail( function () {
			log( 'selection usage failed' );
		} );
	}

	function renderEmpty( query ) {
		resultsBox().html(
			'<div class="wdc-address-picker-empty">Адрес не найден. Можно продолжить ручной ввод.</div>' +
			'<button type="button" class="wdc-address-picker-manual">Использовать введенный адрес</button>'
		);
		resultsBox().find( '.wdc-address-picker-manual' ).data( 'manual-query', query );
	}

	function renderUnavailable( query, errorCode ) {
		debugState.lastAjaxStatus = errorCode || 'suggestions unavailable';
		renderDebugBlock();
		resultsBox().html(
			'<div class="wdc-address-picker-empty">Подсказки адреса временно недоступны. Введите адрес вручную.</div>' +
			'<button type="button" class="wdc-address-picker-manual">Использовать введенный адрес</button>'
		);
		resultsBox().find( '.wdc-address-picker-manual' ).data( 'manual-query', query );
		log( 'dadata suggestions unavailable', { error_code: errorCode || '' } );
	}

	function renderResults( items, query ) {
		itemStore = {};
		itemCounter = 0;
		if ( ! items.length ) {
			renderEmpty( query );
			return;
		}
		var html = '<div class="wdc-address-picker-groups">';
		items.forEach( function ( item ) {
			var key = 'address-item-' + ( ++itemCounter );
			itemStore[ key ] = item;
			html += '<button type="button" class="wdc-address-picker-item" data-key="' + escapeHtml( key ) + '">';
			html += '<span class="wdc-address-picker-label">' + escapeHtml( item.label || item.value || '' ) + '</span>';
			html += '<span class="wdc-address-picker-sublabel">' + escapeHtml( item.subLabel || '' ) + '</span>';
			html += '</button>';
		} );
		html += '</div>';
		resultsBox().html( html );
	}

	function scheduleModalSearch() {
		window.clearTimeout( debounceTimer );
		debounceTimer = window.setTimeout( function () {
			var prefix = activePrefix;
			var state = stateFor( prefix );
			var query = String( searchInput().val() || '' );
			var stage = state.awaitingFlatSelection ? 'address_next' : 'address';
			var requestContext = state.awaitingFlatSelection ? state.selectedHouseContext : null;
			if ( state.awaitingFlatSelection && ! queryMatchesSelectedHouseBase( query, state ) ) {
				clearHouseLookupState( prefix );
				state = stateFor( prefix );
				stage = 'address';
				requestContext = null;
			}
			log( 'modal search input', { query: query, stage: stage } );
			if ( query.trim().length < minChars() ) {
				resultsBox().empty();
				return;
			}
			if ( ! config.enabled ) {
				debugState.lastAjaxStatus = 'config disabled';
				renderDebugBlock();
				renderUnavailable( query, 'config_disabled' );
				return;
			}
			request( stage, query, prefix, function ( items, body ) {
				if ( body && ( 'no_available_dadata_token' === body.error_code || 'dadata_daily_limit_exhausted' === body.error_code ) ) {
					renderUnavailable( query, body.error_code );
					return;
				}
				if ( state.awaitingFlatSelection ) {
					var lower = lowerLevelItems( items );
					if ( lower.length ) {
						renderResults( lower, query );
						showFlatHintWithHouseFinalize();
						return;
					}
					resultsBox().html( '<div class="wdc-address-picker-empty">Квартиры не найдены. Выберите из списка или продолжите ввод.</div>' );
					showFlatHintWithHouseFinalize();
					return;
				}
				renderResults( items, query );
			}, requestContext );
		}, debounceDelay );
	}

	function openAddressPicker( target ) {
		activePrefix = activeCheckoutPrefix();
		activeAddressField = firstUsable( activePrefix, 'address_1' );
		if ( ! activeAddressField.length || ( target && target !== activeAddressField[0] ) ) {
			activeAddressField = $( target );
		}
		ensureHiddenFields( activePrefix );
		pickerOpen = true;
		debugState.activePrefix = activePrefix;
		debugState.modalOpened = 'yes';
		renderDebugBlock();
		picker().attr( 'aria-hidden', 'false' ).addClass( 'is-open' );
		searchInput().val( openingQuery( activePrefix ) );
		resultsBox().empty();
		showHint( '' );
		log( 'address picker opened', { active_prefix: activePrefix } );
		window.setTimeout( function () {
			searchInput().trigger( 'focus' ).trigger( 'select' );
		}, 20 );
		if ( searchInput().val().trim().length >= minChars() ) {
			scheduleModalSearch();
		}
	}

	function closeAddressPicker() {
		clearHouseLookupState( activePrefix );
		pickerOpen = false;
		debugState.modalOpened = 'no';
		renderDebugBlock();
		picker().attr( 'aria-hidden', 'true' ).removeClass( 'is-open' );
		resultsBox().empty();
		log( 'modal closed' );
	}

	function selectItem( item ) {
		var prefix = activePrefix;
		var data = item.data || {};
		if ( 'street' === item.level ) {
			clearHouseLookupState( prefix );
			firstUsable( prefix, 'address_1' ).val( ( data.street_with_type || item.value || '' ) + ' ' );
			setHiddenData( prefix, item, 'street_selected' );
			hidden( prefix, 'dadata_house' ).val( '' );
			hidden( prefix, 'dadata_house_fias_id' ).val( '' );
			hidden( prefix, 'dadata_house_kladr_id' ).val( '' );
			searchInput().val( ensureTrailingComma( item.unrestrictedValue || item.value || item.label || data.street_with_type || '' ) );
			resultsBox().empty();
			showHint( 'Уточните номер дома' );
			log( 'street selected', item );
			searchInput().trigger( 'focus' );
			scheduleModalSearch();
			return;
		}
		if ( 'house' === item.level ) {
			log( 'house selected', item );
			requestLowerLevelAfterHouse( prefix, item );
			return;
		}
		if ( 'flat' === item.level || 'room' === item.level || 'premise' === item.level ) {
			log( 'final address selected', item );
			clearHouseLookupState( prefix );
			applyResolved( prefix, item );
		}
	}

	function finalizeHouseWithoutFlat() {
		var prefix = activePrefix;
		var state = stateFor( prefix );
		var item = state.selectedHouseItem;
		var houseItem = null;
		if ( ! item ) {
			return;
		}
		houseItem = houseLevelItem( item );
		searchInput().val( String( state.selectedHouseBaseQuery || state.selectedHouseDisplayBase || searchInput().val() || '' ).replace( /\s*,\s*$/g, '' ).trim() );
		clearHouseLookupState( prefix );
		applyResolved( prefix, houseItem );
	}

	function applyResolved( prefix, item ) {
		var data = item.data || {};
		var sameLocation = sameSelectedLocation( data );
		var matchedLocalLocation = localLocationMatchesDadata( data );
		var addressLine = sameLocation || matchedLocalLocation ? formatAddressWithoutRegionCity( data ) : formatFullAddressWithoutCountry( item );
		if ( sameLocation ) {
			firstUsable( prefix, 'address_1' ).val( addressLine );
		} else {
			firstUsable( prefix, 'city' ).val( data.city || data.settlement || data.region || firstUsable( prefix, 'city' ).val() || '' );
			firstUsable( prefix, 'state' ).val( data.region_code || data.region || data.region_with_type || firstUsable( prefix, 'state' ).val() || '' );
			firstUsable( prefix, 'address_1' ).val( addressLine );
			setGlobalHidden( 'wdc_platform_location_fias_id', data.city_fias_id || data.settlement_fias_id || '' );
			setGlobalHidden( 'wdc_platform_location_display_name', data.city || data.settlement || data.city_with_type || data.settlement_with_type || '' );
			setGlobalHidden( 'wdc_platform_location_region_name', data.region_with_type || data.region || '' );
		}
		if ( data.postal_code ) {
			firstUsable( prefix, 'postcode' ).val( data.postal_code );
			setGlobalHidden( 'wdc_platform_location_postcode', data.postal_code );
		}
		firstUsable( prefix, 'address_2' ).val( '' );
		setHiddenData( prefix, item, 'resolved' );
		stateFor( prefix ).lastResolved = item;
		clearHouseLookupState( prefix );
		trackSelectionUsage( item, 'final_selection' );
		closeAddressPicker();
		showSelectedNotice( prefix, 'Адрес выбран: ' + ( item.label || item.value || '' ) );
		$( document.body ).trigger( 'update_checkout' );
	}

	function manualFallback() {
		var prefix = activePrefix;
		var value = String( searchInput().val() || '' ).trim();
		if ( '' === value ) {
			closeAddressPicker();
			return;
		}
		firstUsable( prefix, 'address_1' ).val( value );
		clearHouseLookupState( prefix );
		clearAddressHidden( prefix );
		hidden( prefix, 'dadata_status' ).val( 'manual' );
		hidden( prefix, 'dadata_unrestricted_value' ).val( value );
		log( 'manual fallback selected', { value: value } );
		closeAddressPicker();
		showSelectedNotice( prefix, 'Адрес введен вручную' );
		$( document.body ).trigger( 'update_checkout' );
	}

	function bind() {
		activePrefix = activeCheckoutPrefix();
		var addressSelector = selectorFor( activePrefix, 'address_1' );
		activeAddressField = firstUsable( activePrefix, 'address_1' );
		ensureHiddenFields( activePrefix );
		log( 'using address field selector', { selector: addressSelector } );
		log( activeAddressField && activeAddressField.length ? 'address field found' : 'address field not found', { selector: addressSelector } );
		renderDebugBlock();
		$( document.body ).off( namespace );
		$( document.body )
			.on( 'mousedown' + namespace + ' focus' + namespace + ' click' + namespace, addressSelector, function ( event ) {
				openAddressPicker( event.target );
			} )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, '.wdc-address-picker-search', scheduleModalSearch )
			.on( 'mousedown' + namespace + ' click' + namespace, '.wdc-address-picker-item', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				if ( event.stopImmediatePropagation ) {
					event.stopImmediatePropagation();
				}
				var selectedItem = itemStore[ $( this ).attr( 'data-key' ) || '' ];
				trackSelectionUsage( selectedItem, 'suggestion_click' );
				selectItem( selectedItem );
			} )
			.on( 'click' + namespace, '.wdc-address-picker-manual', manualFallback )
			.on( 'click' + namespace, '.wdc-address-picker-house-finalize', function ( event ) {
				event.preventDefault();
				finalizeHouseWithoutFlat();
			} )
			.on( 'click' + namespace, '.wdc-address-picker-close', closeAddressPicker )
			.on( 'mousedown' + namespace, '.wdc-address-picker-overlay', function ( event ) {
				if ( event.target === this ) {
					closeAddressPicker();
				}
			} )
			.on( 'mousedown' + namespace + ' click' + namespace, '.wdc-address-picker-panel', function ( event ) {
				event.stopPropagation();
			} );
		$( document ).off( 'keydown' + namespace ).on( 'keydown' + namespace, function ( event ) {
			if ( 'Escape' === event.key && pickerOpen ) {
				event.preventDefault();
				closeAddressPicker();
			}
		} );
	}

	log( 'address suggestions script loaded', config );
	log( config.enabled ? 'config enabled' : 'config disabled', config );
	$( bind );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, bind );
}( jQuery, window, document ) );
