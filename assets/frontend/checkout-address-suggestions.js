( function ( $, window, document ) {
	'use strict';

	var config = window.wdcPlatformAddressSuggestions || {};
	var namespace = '.wdcAddressSuggestions';
	var CITY_SELECTOR = '#shipping_city,input[name="shipping_city"],textarea[name="shipping_city"],#billing_city,input[name="billing_city"],textarea[name="billing_city"]';
	var ADDRESS_SELECTOR = '#shipping_address_1,input[name="shipping_address_1"],textarea[name="shipping_address_1"],#billing_address_1,input[name="billing_address_1"],textarea[name="billing_address_1"]';
	var ADDRESS_2_SELECTOR = '#shipping_address_2,input[name="shipping_address_2"],textarea[name="shipping_address_2"],#billing_address_2,input[name="billing_address_2"],textarea[name="billing_address_2"]';
	var debounceTimers = {};
	var debounceDelay = 300;
	var selectedStreet = {};
	var itemStore = {};
	var itemCounter = 0;
	var debugState = {
		scriptLoaded: true,
		configEnabled: !! config.enabled,
		fieldFound: false,
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
		'dadata_house_fias_id',
		'dadata_house_kladr_id',
		'dadata_block',
		'dadata_flat',
		'dadata_fias_id',
		'dadata_kladr_id',
		'dadata_fias_level'
	];

	function log( message, data ) {
		if ( config.debug && window.console && window.console.log ) {
			window.console.log( '[WDC DaData suggestions] ' + message, data || {} );
		}
	}

	function minChars() {
		return parseInt( config.min_chars || 3, 10 ) || 3;
	}

	function isUsable( input ) {
		return input.length && ! input.prop( 'disabled' ) && ! input.prop( 'readonly' );
	}

	function visibleUsable( elements ) {
		return elements.filter( ':visible' ).filter( function () {
			return isUsable( $( this ) );
		} );
	}

	function usableFallback( elements ) {
		return elements.filter( function () {
			return isUsable( $( this ) );
		} );
	}

	function firstUsable( selector ) {
		var all = $( selector );
		var visible = visibleUsable( all );
		if ( visible.length ) {
			return visible.first();
		}
		var fallback = usableFallback( all );
		return ( fallback.length ? fallback : all ).first();
	}

	function field( prefix, name ) {
		if ( 'state' === name ) {
			return firstUsable( '#' + prefix + '_state,select[name="' + prefix + '_state"],input[name="' + prefix + '_state"]' );
		}

		return firstUsable( '#' + prefix + '_' + name + ',input[name="' + prefix + '_' + name + '"],textarea[name="' + prefix + '_' + name + '"]' );
	}

	function prefixFromInput( input ) {
		var name = input.attr( 'name' ) || input.attr( 'id' ) || '';
		return 0 === name.indexOf( 'billing_' ) ? 'billing' : 'shipping';
	}

	function fieldKey( input ) {
		return input.attr( 'name' ) || input.attr( 'id' ) || 'unknown';
	}

	function ensureHiddenFields( prefix ) {
		var anchor = field( prefix, 'address_2' );
		if ( ! anchor.length ) {
			anchor = field( prefix, 'address_1' );
		}
		if ( ! anchor.length ) {
			anchor = field( prefix, 'city' );
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

	function setStatus( prefix, status ) {
		hidden( prefix, 'dadata_status' ).val( status );
		renderDebugBlock();
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
		var street = selectedStreet[ prefix ] || {};
		return {
			city_kladr_id: hidden( prefix, 'dadata_city_kladr_id' ).val() || '',
			city_fias_id: hidden( prefix, 'dadata_city_fias_id' ).val() || '',
			settlement_kladr_id: hidden( prefix, 'dadata_settlement_kladr_id' ).val() || '',
			settlement_fias_id: hidden( prefix, 'dadata_settlement_fias_id' ).val() || '',
			street_fias_id: street.fias_id || hidden( prefix, 'dadata_street_fias_id' ).val() || ''
		};
	}

	function popupFor( input ) {
		var key = fieldKey( input ).replace( /[^a-zA-Z0-9_-]/g, '-' );
		var id = 'wdc-address-suggestions-' + key;
		var box = $( '#' + id );
		if ( box.length ) {
			return box;
		}

		return $( '<div>', { id: id, class: 'wdc-address-suggestions', role: 'listbox' } ).insertAfter( input );
	}

	function closePopup() {
		$( '.wdc-address-suggestions' ).hide().empty();
	}

	function positionPopup( input, box ) {
		var offset = input.offset();
		if ( offset && input.outerHeight() ) {
			box.css( {
				position: 'absolute',
				top: offset.top + input.outerHeight() + 4,
				left: offset.left,
				width: Math.min( 1300, Math.max( input.outerWidth() || 0, 320 ) )
			} );
			return;
		}

		box.css( {
			position: 'static',
			width: '100%'
		} );
	}

	function showNotice( input, message ) {
		var notice = input.siblings( '.wdc-address-suggestions__notice' ).first();
		if ( ! notice.length ) {
			notice = $( '<p>', { class: 'wdc-address-suggestions__notice' } ).insertAfter( input );
		}
		notice.text( message ).show();
	}

	function renderDebugBlock() {
		if ( ! config.debug ) {
			return;
		}
		var address = firstUsable( ADDRESS_SELECTOR );
		if ( ! address.length ) {
			log( 'address field not found', checkoutInputsSnapshot() );
			return;
		}
		var block = address.siblings( '.wdc-address-suggestions-debug' ).first();
		if ( ! block.length ) {
			block = $( '<div>', { class: 'wdc-address-suggestions-debug' } ).insertAfter( address );
		}
		block.html(
			'<strong>DaData подсказки:</strong> script loaded<br>' +
			'config enabled: ' + ( debugState.configEnabled ? 'yes' : 'no' ) + '<br>' +
			'api key ready: ' + ( config.api_key_ready ? 'yes' : 'no' ) + '<br>' +
			'encryption ready: ' + ( config.encryption_ready ? 'yes' : 'no' ) + '<br>' +
			'address field: ' + ( debugState.fieldFound ? 'found' : 'not found' ) + '<br>' +
			'last query: ' + escapeHtml( debugState.lastQuery ) + '<br>' +
			'last ajax status: ' + escapeHtml( debugState.lastAjaxStatus ) + '<br>' +
			'last items count: ' + escapeHtml( debugState.lastItemsCount )
		);
		if ( config.suggestions_requested && ! config.enabled ) {
			showNotice( address, 'Подсказки DaData включены, но API-ключ не настроен или недоступно шифрование.' );
		}
	}

	function escapeHtml( value ) {
		return String( value || '' ).replace( /[&<>"']/g, function ( char ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[ char ];
		} );
	}

	function checkoutInputsSnapshot() {
		var names = [ 'billing_city', 'shipping_city', 'billing_address_1', 'shipping_address_1' ];
		var found = {};
		names.forEach( function ( name ) {
			var input = $( '#' + name + ',input[name="' + name + '"],textarea[name="' + name + '"]' );
			found[ name ] = {
				count: input.length,
				visible: visibleUsable( input ).length,
				enabled: usableFallback( input ).length
			};
		} );
		return found;
	}

	function diagnoseFields() {
		var address = firstUsable( ADDRESS_SELECTOR );
		debugState.fieldFound = !! address.length;
		log( address.length ? 'address field found' : 'address field not found', checkoutInputsSnapshot() );
		log( firstUsable( CITY_SELECTOR ).length ? 'city field found' : 'city field not found', checkoutInputsSnapshot() );
		if ( address.length ) {
			log( 'address field selector used', {
				selector: ADDRESS_SELECTOR,
				name: address.attr( 'name' ) || '',
				id: address.attr( 'id' ) || ''
			} );
		}
		renderDebugBlock();
	}

	function request( stage, query, prefix, done ) {
		var payload = {
			action: config.action || 'wdc_platform_dadata_address_suggest',
			nonce: config.nonce || '',
			stage: stage,
			query: query,
			context: context( prefix )
		};
		debugState.lastAjaxStatus = 'pending';
		renderDebugBlock();
		log( 'stage', { stage: stage } );
		log( 'query', { query: query, length: query.length } );
		log( 'ajax request start', payload );
		$.post( config.ajax_url || '', payload ).done( function ( response ) {
			var body = response && response.data ? response.data : response;
			var items = body && body.items ? body.items : [];
			debugState.lastAjaxStatus = body && false === body.success ? body.error_code || 'failed' : 'success';
			debugState.lastItemsCount = String( items.length );
			renderDebugBlock();
			log( 'ajax success items count', { count: items.length, error_code: body ? body.error_code || '' : '' } );
			done( items, body || {} );
		} ).fail( function ( xhr ) {
			debugState.lastAjaxStatus = 'fail ' + ( xhr && xhr.status ? xhr.status : 0 );
			debugState.lastItemsCount = '0';
			renderDebugBlock();
			log( 'ajax fail', { status: xhr && xhr.status ? xhr.status : 0 } );
			done( [], {} );
		} );
	}

	function renderItems( input, items, onSelect ) {
		var box = popupFor( input );
		box.empty();
		itemStore = {};
		if ( ! items.length ) {
			box.append( $( '<div>', { class: 'wdc-address-suggestions__empty', role: 'status' } ).text( ( config.strings && config.strings.not_found ) || 'Адрес не найден. Можно продолжить ручной ввод.' ) );
			positionPopup( input, box );
			box.show();
			log( 'suggestion popup opened', { items: 0 } );
			return;
		}
		items.forEach( function ( item ) {
			var key = 'item-' + ( ++itemCounter );
			itemStore[ key ] = item;
			$( '<button type="button" class="wdc-address-suggestions__item"></button>' )
				.attr( 'data-key', key )
				.append( $( '<span class="wdc-address-suggestions__label"></span>' ).text( item.label || item.value || '' ) )
				.append( $( '<span class="wdc-address-suggestions__sublabel"></span>' ).text( item.subLabel || '' ) )
				.on( 'mousedown' + namespace + ' click' + namespace, function ( event ) {
					event.preventDefault();
					event.stopPropagation();
					event.stopImmediatePropagation();
					var selected = itemStore[ $( this ).attr( 'data-key' ) || '' ];
					log( 'suggestion selected', { level: selected ? selected.level : 'unknown', label: selected ? selected.label || selected.value || '' : '' } );
					if ( selected ) {
						onSelect( selected );
					}
				} )
				.appendTo( box );
		} );
		positionPopup( input, box );
		box.show();
		log( 'suggestion popup opened', { items: items.length } );
	}

	function scheduleSearch( input, stage, prefix ) {
		var key = fieldKey( input );
		window.clearTimeout( debounceTimers[ key ] );
		debounceTimers[ key ] = window.setTimeout( function () {
			var query = $.trim( input.val() || '' );
			debugState.lastQuery = query;
			renderDebugBlock();
			if ( query.length < minChars() ) {
				closePopup();
				return;
			}
			if ( ! config.enabled ) {
				debugState.lastAjaxStatus = 'config disabled';
				renderDebugBlock();
				log( 'config disabled', config );
				return;
			}
			request( stage, query, prefix, function ( items ) {
				renderItems( input, items, function ( item ) {
					selectItem( input, prefix, item );
				} );
			} );
		}, debounceDelay );
	}

	function selectItem( input, prefix, item ) {
		var data = item.data || {};
		if ( 'city' === item.level || 'settlement' === item.level ) {
			field( prefix, 'city' ).val( data.city || data.settlement || data.region || item.value || '' );
			field( prefix, 'postcode' ).val( data.postal_code || field( prefix, 'postcode' ).val() || '' );
			field( prefix, 'state' ).val( data.region_code || data.region || data.region_with_type || field( prefix, 'state' ).val() || '' );
			setHiddenData( prefix, item, 'city_selected' );
			selectedStreet[ prefix ] = null;
			hidden( prefix, 'dadata_street_fias_id' ).val( '' );
			hidden( prefix, 'dadata_street_kladr_id' ).val( '' );
			closePopup();
			$( document.body ).trigger( 'update_checkout' );
			return;
		}

		if ( 'street' === item.level ) {
			field( prefix, 'address_1' ).val( ( data.street_with_type || item.value || '' ) + ' ' ).focus();
			selectedStreet[ prefix ] = {
				fias_id: data.street_fias_id || '',
				kladr_id: data.street_kladr_id || ''
			};
			setHiddenData( prefix, item, 'street_selected' );
			showNotice( input, ( config.strings && config.strings.add_house ) || 'Добавьте номер дома' );
			closePopup();
			return;
		}

		if ( 'house' === item.level || 'flat' === item.level ) {
			log( 'resolve request start', { query: item.unrestrictedValue || item.value || '' } );
			request( 'resolve', item.unrestrictedValue || item.value || '', prefix, function ( items ) {
				log( 'resolve request success', { items: items.length } );
				applyResolved( prefix, items[0] || item );
			} );
			return;
		}

		setHiddenData( prefix, item, 'manual' );
		closePopup();
	}

	function applyResolved( prefix, item ) {
		var data = item.data || {};
		var house = data.house || '';
		if ( data.block ) {
			house += ' ' + data.block;
		}
		field( prefix, 'city' ).val( data.city || data.settlement || data.region || field( prefix, 'city' ).val() || '' );
		field( prefix, 'state' ).val( data.region_code || data.region || data.region_with_type || field( prefix, 'state' ).val() || '' );
		field( prefix, 'address_1' ).val( $.trim( ( data.street_with_type || '' ) + ( house ? ', ' + house : '' ) ) );
		if ( data.flat && ! field( prefix, 'address_2' ).val() ) {
			field( prefix, 'address_2' ).val( data.flat );
		}
		field( prefix, 'postcode' ).val( data.postal_code || field( prefix, 'postcode' ).val() || '' );
		setHiddenData( prefix, item, 'resolved' );
		showNotice( field( prefix, 'address_1' ), ( config.strings && config.strings.selected ? config.strings.selected + ' ' : 'Адрес выбран: ' ) + ( item.label || item.value || '' ) );
		closePopup();
		$( document.body ).trigger( 'update_checkout' );
	}

	function bind() {
		ensureHiddenFields( 'shipping' );
		ensureHiddenFields( 'billing' );
		diagnoseFields();

		$( document.body )
			.off( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, CITY_SELECTOR )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, CITY_SELECTOR, function ( event ) {
				var input = $( event.target );
				var prefix = prefixFromInput( input );
				var query = $.trim( input.val() || '' );
				log( 'city input event', { query: query, length: query.length, stage: 'city' } );
				scheduleSearch( input, 'city', prefix );
			} );

		$( document.body )
			.off( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, ADDRESS_SELECTOR )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, ADDRESS_SELECTOR, function ( event ) {
				var input = $( event.target );
				var prefix = prefixFromInput( input );
				var query = $.trim( input.val() || '' );
				var stage = selectedStreet[ prefix ] || context( prefix ).street_fias_id ? 'house_after_street' : 'address';
				debugState.fieldFound = true;
				log( 'address input event', { query: query, length: query.length, stage: stage } );
				if ( 'resolved' !== hidden( prefix, 'dadata_status' ).val() ) {
					setStatus( prefix, 'manual' );
				}
				scheduleSearch( input, stage, prefix );
			} );

		$( document.body )
			.off( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, ADDRESS_2_SELECTOR )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, ADDRESS_2_SELECTOR, function ( event ) {
				var input = $( event.target );
				var prefix = prefixFromInput( input );
				hidden( prefix, 'dadata_flat' ).val( input.val() || '' );
				if ( 'resolved' !== hidden( prefix, 'dadata_status' ).val() ) {
					setStatus( prefix, 'manual' );
				}
			} );

		$( document )
			.off( 'mousedown' + namespace )
			.on( 'mousedown' + namespace, function ( event ) {
				if ( ! $( event.target ).closest( '.wdc-address-suggestions' ).length ) {
					closePopup();
				}
			} );
	}

	log( 'address suggestions script loaded', config );
	log( config.enabled ? 'config enabled' : 'config disabled', config );
	$( bind );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, bind );
}( jQuery, window, document ) );
