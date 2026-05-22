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
				selectedStreet: null,
				mode: 'address',
				lastResolved: null
			};
		}
		return addressPickerState[ prefix ];
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

	function context( prefix ) {
		var current = stateFor( prefix );
		var street = current.selectedStreet || {};
		return {
			city_kladr_id: hidden( prefix, 'dadata_city_kladr_id' ).val() || '',
			city_fias_id: hidden( prefix, 'dadata_city_fias_id' ).val() || '',
			settlement_kladr_id: hidden( prefix, 'dadata_settlement_kladr_id' ).val() || '',
			settlement_fias_id: hidden( prefix, 'dadata_settlement_fias_id' ).val() || '',
			street_fias_id: street.fias_id || hidden( prefix, 'dadata_street_fias_id' ).val() || ''
		};
	}

	function openingQuery( prefix ) {
		var region = hidden( prefix, 'dadata_region_with_type' ).val() || hidden( prefix, 'dadata_region' ).val() || globalHiddenValue( 'wdc_platform_location_region_name' ) || fieldValue( prefix, 'state' );
		var city = hidden( prefix, 'dadata_city_with_type' ).val() || hidden( prefix, 'dadata_city' ).val() || hidden( prefix, 'dadata_settlement_with_type' ).val() || hidden( prefix, 'dadata_settlement' ).val() || globalHiddenValue( 'wdc_platform_location_display_name' ) || fieldValue( prefix, 'city' );
		var address = fieldValue( prefix, 'address_1' );
		var parts = [];
		if ( region ) {
			parts.push( region );
		}
		if ( city ) {
			parts.push( city );
		}
		if ( address ) {
			parts.push( address );
			return parts.join( ', ' );
		}
		return parts.length ? parts.join( ', ' ) + ', ' : '';
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

	function showHint( message, withChangeStreet ) {
		var html = message ? '<span>' + escapeHtml( message ) + '</span>' : '';
		if ( withChangeStreet ) {
			html += ' <button type="button" class="wdc-address-picker-change-street">Изменить улицу</button>';
		}
		hintBox().html( html );
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
			'api key ready: ' + ( config.api_key_ready ? 'yes' : 'no' ) + '<br>' +
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

	function request( stage, query, prefix, done ) {
		debugState.lastStage = stage;
		debugState.lastQuery = query;
		debugState.lastAjaxStatus = 'pending';
		renderDebugBlock();
		log( 'stage', { stage: stage } );
		log( 'query', { query: query } );
		log( 'ajax request start', { stage: stage, query: query, context: context( prefix ) } );
		$.post( config.ajax_url || '', {
			action: config.action || 'wdc_platform_dadata_address_suggest',
			nonce: config.nonce || '',
			stage: stage,
			query: query,
			context: context( prefix )
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

	function renderEmpty( query ) {
		resultsBox().html(
			'<div class="wdc-address-picker-empty">Адрес не найден. Можно продолжить ручной ввод.</div>' +
			'<button type="button" class="wdc-address-picker-manual">Использовать введенный адрес</button>'
		);
		resultsBox().find( '.wdc-address-picker-manual' ).data( 'manual-query', query );
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
			var query = String( searchInput().val() || '' );
			if ( '' === query.trim() ) {
				stateFor( prefix ).selectedStreet = null;
				stateFor( prefix ).mode = 'address';
			}
			var stage = stateFor( prefix ).selectedStreet ? 'house_after_street' : 'address';
			log( 'modal search input', { query: query, stage: stage } );
			if ( query.trim().length < minChars() ) {
				resultsBox().empty();
				return;
			}
			if ( ! config.enabled ) {
				debugState.lastAjaxStatus = 'config disabled';
				renderDebugBlock();
				return;
			}
			request( stage, query, prefix, function ( items ) {
				renderResults( items, query );
			} );
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
		showHint( stateFor( activePrefix ).selectedStreet ? 'Добавьте номер дома' : '', !! stateFor( activePrefix ).selectedStreet );
		log( 'address picker opened', { active_prefix: activePrefix } );
		window.setTimeout( function () {
			searchInput().trigger( 'focus' ).trigger( 'select' );
		}, 20 );
		if ( searchInput().val().trim().length >= minChars() ) {
			scheduleModalSearch();
		}
	}

	function closeAddressPicker() {
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
			stateFor( prefix ).selectedStreet = {
				fias_id: data.street_fias_id || '',
				kladr_id: data.street_kladr_id || ''
			};
			stateFor( prefix ).mode = 'house_after_street';
			firstUsable( prefix, 'address_1' ).val( ( data.street_with_type || item.value || '' ) + ' ' );
			setHiddenData( prefix, item, 'street_selected' );
			hidden( prefix, 'dadata_house' ).val( '' );
			hidden( prefix, 'dadata_house_fias_id' ).val( '' );
			hidden( prefix, 'dadata_house_kladr_id' ).val( '' );
			searchInput().val( ( data.street_with_type || item.value || '' ) + ' ' );
			resultsBox().empty();
			showHint( 'Добавьте номер дома', true );
			log( 'street selected', item );
			return;
		}
		if ( 'house' === item.level || 'flat' === item.level ) {
			log( 'house selected', item );
			log( 'resolve request start', { query: item.unrestrictedValue || item.value || '' } );
			request( 'resolve', item.unrestrictedValue || item.value || '', prefix, function ( items ) {
				log( 'resolve request success', { count: items.length } );
				applyResolved( prefix, items[0] || item );
			} );
		}
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
		if ( data.flat && ! firstUsable( prefix, 'address_2' ).val() ) {
			firstUsable( prefix, 'address_2' ).val( data.flat );
		}
		setHiddenData( prefix, item, 'resolved' );
		stateFor( prefix ).lastResolved = item;
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
		clearAddressHidden( prefix );
		hidden( prefix, 'dadata_status' ).val( 'manual' );
		hidden( prefix, 'dadata_unrestricted_value' ).val( value );
		stateFor( prefix ).selectedStreet = null;
		stateFor( prefix ).mode = 'address';
		log( 'manual fallback selected', { value: value } );
		closeAddressPicker();
		showSelectedNotice( prefix, 'Адрес введен вручную' );
		$( document.body ).trigger( 'update_checkout' );
	}

	function changeStreet() {
		stateFor( activePrefix ).selectedStreet = null;
		stateFor( activePrefix ).mode = 'address';
		searchInput().val( '' ).trigger( 'focus' );
		resultsBox().empty();
		showHint( '' );
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
				selectItem( itemStore[ $( this ).attr( 'data-key' ) || '' ] );
			} )
			.on( 'click' + namespace, '.wdc-address-picker-manual', manualFallback )
			.on( 'click' + namespace, '.wdc-address-picker-change-street', changeStreet )
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
