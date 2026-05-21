( function ( $, window, document ) {
	'use strict';

	var config = window.wdcPlatformAddressSuggestions || {};
	var namespace = '.wdcAddressSuggestions';
	var CITY_SELECTOR = '#shipping_city,input[name="shipping_city"],#billing_city,input[name="billing_city"]';
	var ADDRESS_SELECTOR = '#shipping_address_1,input[name="shipping_address_1"],#billing_address_1,input[name="billing_address_1"]';
	var ADDRESS_2_SELECTOR = '#shipping_address_2,input[name="shipping_address_2"],#billing_address_2,input[name="billing_address_2"]';
	var debounceTimer = null;
	var debounceDelay = 850;
	var selectedStreet = {};
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

	function visibleEnabled( elements ) {
		return elements.filter( ':visible' ).filter( function () {
			return ! $( this ).prop( 'disabled' );
		} );
	}

	function candidates( prefix, name ) {
		if ( 'state' === name ) {
			return $( '#' + prefix + '_state,select[name="' + prefix + '_state"],input[name="' + prefix + '_state"]' );
		}

		return $( '#' + prefix + '_' + name + ',input[name="' + prefix + '_' + name + '"]' );
	}

	function field( prefix, name ) {
		var all = candidates( prefix, name );
		var usable = visibleEnabled( all );
		return ( usable.length ? usable : all ).first();
	}

	function prefixFromInput( input ) {
		var name = input.attr( 'name' ) || input.attr( 'id' ) || '';
		return 0 === name.indexOf( 'billing_' ) ? 'billing' : 'shipping';
	}

	function checkoutInputsSnapshot() {
		var names = [
			'billing_city',
			'shipping_city',
			'billing_address_1',
			'shipping_address_1'
		];
		var found = {};
		names.forEach( function ( name ) {
			var input = $( '#' + name + ',input[name="' + name + '"]' );
			found[ name ] = {
				count: input.length,
				visible: visibleEnabled( input ).length
			};
		} );
		return found;
	}

	function diagnoseFields() {
		var city = visibleEnabled( $( CITY_SELECTOR ) );
		var address = visibleEnabled( $( ADDRESS_SELECTOR ) );
		log( city.length ? 'city field found' : 'city field not found', checkoutInputsSnapshot() );
		log( address.length ? 'address field found' : 'address field not found', checkoutInputsSnapshot() );
		if ( address.length ) {
			log( 'address field selector used', {
				selector: ADDRESS_SELECTOR,
				name: address.first().attr( 'name' ) || '',
				id: address.first().attr( 'id' ) || ''
			} );
		}
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
		log( 'status', { status: status } );
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

	function clearStreet( prefix ) {
		selectedStreet[ prefix ] = null;
		hidden( prefix, 'dadata_street' ).val( '' );
		hidden( prefix, 'dadata_street_with_type' ).val( '' );
		hidden( prefix, 'dadata_street_fias_id' ).val( '' );
		hidden( prefix, 'dadata_street_kladr_id' ).val( '' );
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

	function popup() {
		var box = $( '.wdc-address-suggestions' );
		if ( box.length ) {
			return box;
		}
		return $( '<div class="wdc-address-suggestions" role="listbox"></div>' ).appendTo( document.body );
	}

	function closePopup() {
		popup().hide().empty();
	}

	function positionPopup( input ) {
		var offset = input.offset() || { top: 0, left: 0 };
		var width = Math.min( 1300, Math.max( input.outerWidth() || 0, 320 ) );
		popup().css( {
			top: offset.top + ( input.outerHeight() || 0 ) + 4,
			left: offset.left,
			width: width
		} );
	}

	function showNotice( input, message ) {
		var id = 'wdc-address-suggestions-notice';
		var notice = $( '#' + id );
		if ( ! notice.length ) {
			notice = $( '<p>', { id: id, class: 'wdc-address-suggestions__notice' } ).insertAfter( input );
		}
		notice.text( message ).show();
	}

	function request( stage, query, prefix, done ) {
		var payload = {
			action: config.action || 'wdc_platform_dadata_address_suggest',
			nonce: config.nonce || '',
			stage: stage,
			query: query,
			context: context( prefix )
		};
		log( 'stage', { stage: stage } );
		log( 'query', { query: query } );
		log( 'ajax request start', payload );
		$.post( config.ajax_url || '', payload ).done( function ( response ) {
			var body = response && response.data ? response.data : response;
			var items = body && body.items ? body.items : [];
			log( 'ajax success items count', { count: items.length, error_code: body ? body.error_code || '' : '' } );
			done( items, body || {} );
		} ).fail( function ( xhr ) {
			log( 'ajax fail', { status: xhr && xhr.status ? xhr.status : 0 } );
			done( [], {} );
		} );
	}

	function emptyItem() {
		return $( '<div class="wdc-address-suggestions__empty" role="status"></div>' )
			.text( ( config.strings && config.strings.not_found ) || 'Адрес не найден. Можно продолжить ручной ввод.' );
	}

	function renderItems( input, items, onSelect ) {
		var box = popup();
		box.empty();
		if ( ! items.length ) {
			box.append( emptyItem() );
			positionPopup( input );
			box.show();
			log( 'suggestion popup opened', { items: 0 } );
			return;
		}
		items.forEach( function ( item ) {
			$( '<button type="button" class="wdc-address-suggestions__item"></button>' )
				.attr( 'data-level', item.level || 'unknown' )
				.append( $( '<span class="wdc-address-suggestions__label"></span>' ).text( item.label || item.value || '' ) )
				.append( $( '<span class="wdc-address-suggestions__sublabel"></span>' ).text( item.subLabel || '' ) )
				.on( 'mousedown' + namespace, function ( event ) {
					event.preventDefault();
					log( 'suggestion selected', { level: item.level || 'unknown', label: item.label || item.value || '' } );
					onSelect( item );
				} )
				.appendTo( box );
		} );
		positionPopup( input );
		box.show();
		log( 'suggestion popup opened', { items: items.length } );
	}

	function schedule( input, stage, prefix ) {
		window.clearTimeout( debounceTimer );
		debounceTimer = window.setTimeout( function () {
			var query = $.trim( input.val() || '' );
			if ( query.length < minChars() ) {
				closePopup();
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
		log( 'selected level', { level: item.level } );
		if ( 'city' === item.level || 'settlement' === item.level ) {
			field( prefix, 'city' ).val( data.city || data.settlement || data.region || item.value || '' );
			field( prefix, 'postcode' ).val( data.postal_code || field( prefix, 'postcode' ).val() || '' );
			field( prefix, 'state' ).val( data.region_code || data.region || data.region_with_type || field( prefix, 'state' ).val() || '' );
			setHiddenData( prefix, item, 'city_selected' );
			clearStreet( prefix );
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
		showNotice( field( prefix, 'address_1' ), ( config.strings && config.strings.selected ) ? config.strings.selected + ' ' + ( item.label || item.value || '' ) : 'Адрес выбран: ' + ( item.label || item.value || '' ) );
		closePopup();
		$( document.body ).trigger( 'update_checkout' );
	}

	function bind() {
		if ( ! config.enabled ) {
			log( 'config disabled', config );
			return;
		}

		ensureHiddenFields( 'shipping' );
		ensureHiddenFields( 'billing' );
		diagnoseFields();

		$( document.body )
			.off( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace + ' change' + namespace + ' blur' + namespace, CITY_SELECTOR )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace + ' change' + namespace + ' blur' + namespace, CITY_SELECTOR, function () {
				var input = $( this );
				var prefix = prefixFromInput( input );
				log( 'city input event', { prefix: prefix, value: input.val() || '' } );
				schedule( input, 'city', prefix );
			} );

		$( document.body )
			.off( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace + ' change' + namespace + ' blur' + namespace, ADDRESS_SELECTOR )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace + ' change' + namespace + ' blur' + namespace, ADDRESS_SELECTOR, function () {
				var input = $( this );
				var prefix = prefixFromInput( input );
				var stage = context( prefix ).street_fias_id ? 'house_after_street' : 'address';
				log( 'address input event', { prefix: prefix, value: input.val() || '', stage: stage } );
				if ( 'resolved' !== hidden( prefix, 'dadata_status' ).val() ) {
					setStatus( prefix, 'manual' );
				}
				schedule( input, stage, prefix );
			} );

		$( document.body )
			.off( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace + ' change' + namespace + ' blur' + namespace, ADDRESS_2_SELECTOR )
			.on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace + ' change' + namespace + ' blur' + namespace, ADDRESS_2_SELECTOR, function () {
				var input = $( this );
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
	if ( ! config.enabled ) {
		log( 'config disabled', config );
		return;
	}
	log( 'config enabled', config );

	$( bind );
	$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, bind );
}( jQuery, window, document ) );
