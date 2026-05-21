( function ( $, window ) {
	'use strict';

	var config = window.wdcPlatformAddressSuggestions || {};
	var namespace = '.wdcAddressSuggestions';
	var debounceTimer = null;
	var debounceDelay = 850;
	var activePrefix = 'shipping';
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

	function field( prefix, name ) {
		return $( '#' + prefix + '_' + name + ', input[name="' + prefix + '_' + name + '"]' ).first();
	}

	function activeAddressPrefix() {
		return field( 'shipping', 'address_1' ).length ? 'shipping' : 'billing';
	}

	function ensureHiddenFields( prefix ) {
		var anchor = field( prefix, 'address_2' );
		if ( ! anchor.length ) {
			anchor = field( prefix, 'address_1' );
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
		return {
			city_kladr_id: hidden( prefix, 'dadata_city_kladr_id' ).val() || '',
			city_fias_id: hidden( prefix, 'dadata_city_fias_id' ).val() || '',
			settlement_kladr_id: hidden( prefix, 'dadata_settlement_kladr_id' ).val() || '',
			settlement_fias_id: hidden( prefix, 'dadata_settlement_fias_id' ).val() || '',
			street_fias_id: hidden( prefix, 'dadata_street_fias_id' ).val() || ''
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
		var offset = input.offset();
		popup().css( {
			top: offset.top + input.outerHeight() + 4,
			left: offset.left,
			width: Math.min( 1300, Math.max( input.outerWidth(), 320 ) )
		} );
	}

	function request( stage, query, prefix, done ) {
		log( 'dadata stage', { stage: stage } );
		log( 'query', { query: query } );
		log( 'context', context( prefix ) );
		$.post( config.ajax_url || '', {
			action: 'wdc_platform_dadata_address_suggest',
			stage: stage,
			query: query,
			context: context( prefix )
		} ).done( function ( response ) {
			var items = response && response.items ? response.items : [];
			log( 'suggestions count', { count: items.length } );
			done( items );
		} ).fail( function () {
			log( 'suggestions failed' );
			done( [] );
		} );
	}

	function renderItems( input, items, onSelect ) {
		var box = popup();
		box.empty();
		if ( ! items.length ) {
			closePopup();
			return;
		}
		items.forEach( function ( item ) {
			$( '<button type="button" class="wdc-address-suggestions__item"></button>' )
				.attr( 'data-level', item.level || 'unknown' )
				.append( $( '<span class="wdc-address-suggestions__label"></span>' ).text( item.label || item.value || '' ) )
				.append( $( '<span class="wdc-address-suggestions__sublabel"></span>' ).text( item.subLabel || '' ) )
				.on( 'mousedown' + namespace, function ( event ) {
					event.preventDefault();
					onSelect( item );
				} )
				.appendTo( box );
		} );
		positionPopup( input );
		box.show();
	}

	function schedule( input, stage, prefix ) {
		window.clearTimeout( debounceTimer );
		debounceTimer = window.setTimeout( function () {
			var query = $.trim( input.val() || '' );
			if ( ! query ) {
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
			field( prefix, 'state' ).val( data.region || data.region_with_type || field( prefix, 'state' ).val() || '' );
			setHiddenData( prefix, item, 'city_selected' );
			hidden( prefix, 'dadata_street_fias_id' ).val( '' );
			hidden( prefix, 'dadata_street_kladr_id' ).val( '' );
			closePopup();
			$( document.body ).trigger( 'update_checkout' );
			return;
		}

		if ( 'street' === item.level ) {
			field( prefix, 'address_1' ).val( ( data.street_with_type || item.value || '' ) + ' ' ).focus();
			setHiddenData( prefix, item, 'street_selected' );
			log( 'status', { status: 'street_selected', notice: 'Добавьте номер дома' } );
			closePopup();
			return;
		}

		if ( 'house' === item.level || 'flat' === item.level ) {
			log( 'resolve request', { query: item.unrestrictedValue || item.value || '' } );
			request( 'resolve', item.unrestrictedValue || item.value || '', prefix, function ( items ) {
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
		field( prefix, 'city' ).val( data.city || data.settlement || field( prefix, 'city' ).val() || '' );
		field( prefix, 'state' ).val( data.region || data.region_with_type || field( prefix, 'state' ).val() || '' );
		field( prefix, 'address_1' ).val( $.trim( ( data.street_with_type || '' ) + ( house ? ', ' + house : '' ) ) );
		if ( data.flat && ! field( prefix, 'address_2' ).val() ) {
			field( prefix, 'address_2' ).val( data.flat );
		}
		field( prefix, 'postcode' ).val( data.postal_code || field( prefix, 'postcode' ).val() || '' );
		setHiddenData( prefix, item, 'resolved' );
		log( 'status', { status: 'resolved' } );
		closePopup();
		$( document.body ).trigger( 'update_checkout' );
	}

	function bind() {
		activePrefix = activeAddressPrefix();
		ensureHiddenFields( 'shipping' );
		ensureHiddenFields( 'billing' );
		$( document.body ).off( namespace );
		$( document ).off( namespace );
		$( document.body ).on( 'input' + namespace + ' change' + namespace + ' blur' + namespace, '#shipping_city,input[name="shipping_city"],#billing_city,input[name="billing_city"]', function () {
			var input = $( this );
			var prefix = input.attr( 'name' ) && input.attr( 'name' ).indexOf( 'billing_' ) === 0 ? 'billing' : 'shipping';
			schedule( input, 'city', prefix );
		} );
		$( document.body ).on( 'input' + namespace + ' change' + namespace + ' blur' + namespace, '#shipping_address_1,input[name="shipping_address_1"],#billing_address_1,input[name="billing_address_1"]', function () {
			var input = $( this );
			var prefix = input.attr( 'name' ) && input.attr( 'name' ).indexOf( 'billing_' ) === 0 ? 'billing' : 'shipping';
			var stage = hidden( prefix, 'dadata_street_fias_id' ).val() ? 'house_after_street' : 'address';
			schedule( input, stage, prefix );
		} );
		$( document.body ).on( 'input' + namespace + ' change' + namespace + ' blur' + namespace, '#shipping_address_2,input[name="shipping_address_2"],#billing_address_2,input[name="billing_address_2"]', function () {
			var input = $( this );
			var prefix = input.attr( 'name' ) && input.attr( 'name' ).indexOf( 'billing_' ) === 0 ? 'billing' : 'shipping';
			hidden( prefix, 'dadata_flat' ).val( input.val() || '' );
			if ( 'resolved' !== hidden( prefix, 'dadata_status' ).val() ) {
				hidden( prefix, 'dadata_status' ).val( 'manual' );
			}
		} );
		$( document ).on( 'mousedown' + namespace, function ( event ) {
			if ( ! $( event.target ).closest( '.wdc-address-suggestions' ).length ) {
				closePopup();
			}
		} );
	}

	$( bind );
	$( document.body ).on( 'updated_checkout' + namespace, bind );
}( jQuery, window ) );
