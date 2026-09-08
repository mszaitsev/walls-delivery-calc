( function ( $, window, document ) {
	'use strict';

	var config = window.wdcPlatformAddressSuggestions || {};
	var namespace = '.wdcAddressSuggestions';
	var debounceDelay = 300;
	var debounceTimer = null;
	var activeRequest = null;
	var requestSeq = 0;
	var items = [];
	var activeIndex = -1;
	var finalizedPrefix = '';
	var finalizedComplete = false;
	var state = 'idle';
	var contextKey = '';
	var listboxId = 'wdc-address-autocomplete-listbox';
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
		'dadata_house_type_full',
		'dadata_house_fias_id',
		'dadata_house_kladr_id',
		'dadata_block',
		'dadata_block_type',
		'dadata_block_type_full',
		'dadata_stead',
		'dadata_stead_type',
		'dadata_flat',
		'dadata_flat_type',
		'dadata_flat_type_full',
		'dadata_fias_id',
		'dadata_kladr_id',
		'dadata_fias_level',
		'dadata_geo_lat',
		'dadata_geo_lon',
		'dadata_geo_lng'
	];

	function minChars() {
		return parseInt( config.min_chars || 3, 10 ) || 3;
	}

	function addressField() {
		return $( '#billing_address_1,input[name="billing_address_1"],textarea[name="billing_address_1"]' ).filter( ':visible' ).first();
	}

	function globalHidden( name ) {
		return $( 'input[name="' + name + '"]' ).first();
	}

	function hiddenValue( name ) {
		var field = globalHidden( name );
		return field.length ? String( field.val() || '' ).trim() : '';
	}

	function fieldValue( name ) {
		var field = $( '#' + name + ',input[name="' + name + '"],select[name="' + name + '"],textarea[name="' + name + '"]' ).first();
		return field.length ? String( field.val() || '' ).trim() : '';
	}

	function eligible() {
		var country = fieldValue( 'billing_country' ).toUpperCase();
		var source = hiddenValue( 'wdc_platform_location_selected_source' ).toLowerCase();
		var locationId = hiddenValue( 'wdc_platform_location_id' );
		var fiasId = hiddenValue( 'wdc_platform_location_fias_id' );
		return !! config.enabled && 'RU' === country && 'manual' !== source && !! ( locationId || fiasId );
	}

	function context() {
		return {
			country_code: fieldValue( 'billing_country' ).toUpperCase(),
			selected_source: hiddenValue( 'wdc_platform_location_selected_source' ),
			selected_location_id: hiddenValue( 'wdc_platform_location_id' ),
			selected_location_fias_id: hiddenValue( 'wdc_platform_location_fias_id' )
		};
	}

	function ensureHiddenFields() {
		var anchor = addressField();
		if ( ! anchor.length ) {
			return;
		}
		hiddenKeys.forEach( function ( key ) {
			var name = 'billing_' + key;
			if ( ! $( 'input[name="' + name + '"]' ).length ) {
				$( '<input>', { type: 'hidden', name: name, id: name, value: 'dadata_status' === key ? 'empty' : '' } ).insertAfter( anchor );
			}
		} );
	}

	function hidden( key ) {
		ensureHiddenFields();
		return $( 'input[name="billing_' + key + '"]' ).first();
	}

	function clearAddressHidden() {
		hiddenKeys.forEach( function ( key ) {
			hidden( key ).val( 'dadata_status' === key ? 'manual' : '' );
		} );
	}

	function setHiddenData( item, status ) {
		clearAddressHidden();
		var data = item && item.data ? item.data : {};
		hidden( 'dadata_status' ).val( status || 'resolved' );
		hidden( 'dadata_unrestricted_value' ).val( item ? item.unrestrictedValue || item.value || item.display_label || '' : '' );
		Object.keys( data ).forEach( function ( key ) {
			hidden( 'dadata_' + key ).val( data[ key ] || '' );
		} );
		hidden( 'dadata_fias_level' ).val( item ? item.fiasLevel || '' : '' );
	}

	function wrapper() {
		var input = addressField();
		var row = input.parent();
		row.addClass( 'wdc-address-autocomplete-field' );
		return row;
	}

	function dropdown() {
		var row = wrapper();
		var box = row.children( '.wdc-address-autocomplete' ).first();
		if ( ! box.length ) {
			box = $( '<div>', {
				id: listboxId,
				class: 'wdc-address-autocomplete',
				role: 'listbox'
			} ).appendTo( row );
		}
		return box;
	}

	function escapeHtml( value ) {
		return String( value || '' ).replace( /[&<>"']/g, function ( char ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ char ];
		} );
	}

	function setState( nextState ) {
		state = nextState;
		dropdown().attr( 'data-state', state );
		addressField().attr( {
			'aria-controls': listboxId,
			'aria-expanded': isOpen() ? 'true' : 'false',
			'autocomplete': 'off'
		} );
	}

	function isOpen() {
		return dropdown().hasClass( 'is-open' );
	}

	function closeDropdown() {
		window.clearTimeout( debounceTimer );
		abortRequest();
		dropdown().removeClass( 'is-open' ).empty();
		items = [];
		activeIndex = -1;
		addressField().attr( {
			'aria-expanded': 'false',
			'aria-activedescendant': ''
		} );
		setState( finalizedComplete ? 'finalized' : 'idle' );
	}

	function abortRequest() {
		requestSeq++;
		if ( activeRequest && activeRequest.abort ) {
			activeRequest.abort();
		}
		activeRequest = null;
	}

	function renderMessage( className, message ) {
		dropdown()
			.html( '<div class="wdc-address-autocomplete-row ' + className + '">' + escapeHtml( message ) + '</div>' )
			.addClass( 'is-open' );
		addressField().attr( 'aria-expanded', 'true' );
	}

	function renderItems( nextItems ) {
		var html = '';
		items = nextItems || [];
		activeIndex = -1;
		if ( ! items.length ) {
			setState( 'empty' );
			renderMessage( 'is-empty', 'Подходящих адресов не найдено — продолжите ввод вручную' );
			return;
		}
		items.forEach( function ( item, index ) {
			var optionId = 'wdc-address-option-' + index;
			html += '<button type="button" class="wdc-address-autocomplete-option" role="option" id="' + optionId + '" data-index="' + index + '" aria-selected="false">';
			html += '<span class="wdc-address-autocomplete-primary">' + escapeHtml( item.display_label || item.label || item.input_value || item.value || '' ) + '</span>';
			if ( item.secondary_label || item.subLabel ) {
				html += '<span class="wdc-address-autocomplete-secondary">' + escapeHtml( item.secondary_label || item.subLabel || '' ) + '</span>';
			}
			html += '</button>';
		} );
		setState( 'results' );
		dropdown().html( html ).addClass( 'is-open' );
		addressField().attr( 'aria-expanded', 'true' );
	}

	function renderFinalHelper() {
		setState( 'finalized' );
		dropdown()
			.html( '<button type="button" class="wdc-address-autocomplete-helper">Уточните квартиру, офис или помещение — либо нажмите здесь, если уточнение не требуется</button>' )
			.addClass( 'is-open' );
		addressField().attr( 'aria-expanded', 'true' );
	}

	function startsWithFinalizedPrefix( value ) {
		var current = String( value || '' );
		var prefix = finalizedPrefix;
		var rest = '';
		if ( ! prefix || current.length < prefix.length || current.slice( 0, prefix.length ) !== prefix ) {
			return false;
		}
		rest = current.slice( prefix.length );
		return '' === rest || /^,/.test( rest );
	}

	function placeCaretEnd( input ) {
		var node = input[0];
		if ( node && node.setSelectionRange ) {
			node.setSelectionRange( node.value.length, node.value.length );
		}
	}

	function triggerCheckoutUpdate() {
		$( document.body ).trigger( 'update_checkout' );
	}

	function selectItem( item ) {
		if ( ! item || ! eligible() ) {
			return;
		}
		var inputValue = String( item.input_value || '' ).trim();
		if ( '' === inputValue ) {
			return;
		}
		closeDropdown();
		setHiddenData( item, item.is_final ? 'resolved' : 'street_selected' );
		addressField().val( inputValue + ( item.is_final ? ', ' : '' ) );
		addressField().trigger( 'focus' );
		placeCaretEnd( addressField() );
		if ( item.is_final ) {
			finalizedPrefix = inputValue;
			finalizedComplete = true;
			renderFinalHelper();
			var selectedContext = JSON.stringify( context() );
			$.post( config.ajax_url || '', {
				action: config.selection_action || 'wdc_platform_dadata_suggestion_selected',
				nonce: config.nonce || '', prefix: 'billing', usage_type: 'final_selection',
				level: item.level, selection_token: item.selection_token || ''
			} ).always( function () {
				if ( selectedContext === JSON.stringify( context() ) && finalizedPrefix === inputValue ) {
					triggerCheckoutUpdate();
				}
			} );
			return;
		}
		finalizedPrefix = '';
		finalizedComplete = false;
		setState( 'typing' );
		closeDropdown();
		addressField().trigger( 'focus' );
		placeCaretEnd( addressField() );
	}

	function scheduleSearch() {
		var query = String( addressField().val() || '' );
		window.clearTimeout( debounceTimer );
		closeDropdown();
		if ( ! eligible() ) {
			abortRequest();
			finalizedPrefix = '';
			finalizedComplete = false;
			closeDropdown();
			return;
		}
		if ( '' === query.trim() ) {
			abortRequest();
			finalizedPrefix = '';
			finalizedComplete = false;
			clearAddressHidden();
			closeDropdown();
			return;
		}
		if ( finalizedComplete && startsWithFinalizedPrefix( query ) ) {
			abortRequest();
			renderFinalHelper();
			return;
		}
		if ( finalizedComplete && ! startsWithFinalizedPrefix( query ) ) {
			finalizedPrefix = '';
			finalizedComplete = false;
		}
		clearAddressHidden();
		if ( query.trim().length < minChars() ) {
			abortRequest();
			closeDropdown();
			setState( 'typing' );
			return;
		}
		setState( 'typing' );
		debounceTimer = window.setTimeout( function () {
			search( query );
		}, debounceDelay );
	}

	function search( query ) {
		if ( ! eligible() ) {
			return;
		}
		var seq = ++requestSeq;
		var searchContext = JSON.stringify( context() );
		if ( activeRequest && activeRequest.abort ) {
			activeRequest.abort();
		}
		setState( 'loading' );
		renderMessage( 'is-loading', 'Ищем адрес...' );
		activeRequest = $.post( config.ajax_url || '', {
			action: config.action || 'wdc_platform_dadata_address_suggest',
			nonce: config.nonce || '',
			stage: 'address_inline',
			query: query,
			prefix: 'billing',
			context: context()
		} ).done( function ( response ) {
			var body = response && response.data ? response.data : response;
			if ( seq !== requestSeq || ! eligible() || searchContext !== JSON.stringify( context() ) || query !== String( addressField().val() || '' ) ) {
				return;
			}
			if ( body && 'address_autocomplete_ineligible' === body.error_code ) {
				closeDropdown();
				return;
			}
			if ( ! body || false === body.success ) {
				setState( 'error' );
				renderMessage( 'is-error', 'Не удалось загрузить подсказки — можно продолжить ввод вручную' );
				return;
			}
			renderItems( body.items || [] );
		} ).fail( function ( xhr, statusText ) {
			if ( seq !== requestSeq || 'abort' === statusText ) {
				return;
			}
			setState( 'error' );
			renderMessage( 'is-error', 'Не удалось загрузить подсказки — можно продолжить ввод вручную' );
		} );
	}

	function setActiveIndex( nextIndex ) {
		var options = dropdown().find( '.wdc-address-autocomplete-option' );
		if ( ! options.length ) {
			return;
		}
		activeIndex = nextIndex < 0 ? options.length - 1 : nextIndex % options.length;
		options.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		options.eq( activeIndex ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		addressField().attr( 'aria-activedescendant', options.eq( activeIndex ).attr( 'id' ) || '' );
	}

	function bind() {
		if ( ! addressField().length ) {
			return;
		}
		refreshContext();
		ensureHiddenFields();
		$( document.body ).off( namespace );
		$( document ).off( namespace );
		addressField().attr( {
			'role': 'combobox',
			'aria-autocomplete': 'list',
			'aria-controls': listboxId,
			'aria-expanded': isOpen() ? 'true' : 'false',
			'autocomplete': 'off'
		} );
		$( document.body )
			.on( 'focus' + namespace, '#billing_address_1,input[name="billing_address_1"],textarea[name="billing_address_1"]', function () {
				if ( eligible() && finalizedComplete && startsWithFinalizedPrefix( $( this ).val() ) ) {
					renderFinalHelper();
				}
			} )
			.on( 'input' + namespace, '#billing_address_1,input[name="billing_address_1"],textarea[name="billing_address_1"]', function () {
				scheduleSearch();
			} )
			.on( 'blur' + namespace, '#billing_address_1', closeDropdown )
			.on( 'keydown' + namespace, '#billing_address_1,input[name="billing_address_1"],textarea[name="billing_address_1"]', function ( event ) {
				if ( 'ArrowDown' === event.key && isOpen() ) {
					event.preventDefault();
					setActiveIndex( activeIndex + 1 );
				} else if ( 'ArrowUp' === event.key && isOpen() ) {
					event.preventDefault();
					setActiveIndex( activeIndex - 1 );
				} else if ( 'Enter' === event.key && isOpen() && activeIndex >= 0 && items[ activeIndex ] ) {
					event.preventDefault();
					selectItem( items[ activeIndex ] );
				} else if ( 'Escape' === event.key ) {
					event.preventDefault();
					closeDropdown();
				} else if ( 'Tab' === event.key ) {
					closeDropdown();
				}
			} )
			.on( 'mousedown' + namespace, '.wdc-address-autocomplete-option', function ( event ) {
				event.preventDefault();
				selectItem( items[ parseInt( $( this ).attr( 'data-index' ) || '-1', 10 ) ] );
			} )
			.on( 'mousedown' + namespace, '.wdc-address-autocomplete-helper', function ( event ) {
				event.preventDefault();
				closeDropdown();
				addressField().trigger( 'blur' );
			} )
			.on( 'change' + namespace, '#billing_country', refreshContext );
		$( document ).on( 'mousedown' + namespace, function ( event ) {
			if ( ! $( event.target ).closest( '.wdc-address-autocomplete-field' ).length ) {
				closeDropdown();
			}
		} );
	}

	function refreshContext() {
		var next = JSON.stringify( context() );
		if ( contextKey && contextKey !== next ) {
			finalizedPrefix = '';
			finalizedComplete = false;
			clearAddressHidden();
			closeDropdown();
		}
		contextKey = next;
	}

	$( bind );
	$( document.body ).on( 'updated_checkout.wdcAddressLifecycle wc_fragments_refreshed.wdcAddressLifecycle', bind );
	$( document.body ).on( 'wdc:location-selected.wdcAddressLifecycle wdc:location-cleared.wdcAddressLifecycle', refreshContext );
}( jQuery, window, document ) );
