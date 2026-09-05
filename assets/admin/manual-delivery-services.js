( function () {
	'use strict';

	const config = window.wdcManualDeliveryAdmin || {};
	const ajaxUrl = config.ajaxUrl || '';
	const nonce = config.nonce || '';

	function postSearch( action, query ) {
		const data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', nonce );
		data.append( 'query', query );

		return fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			return payload && payload.success && payload.data && Array.isArray( payload.data.items ) ? payload.data.items : [];
		} );
	}

	function clear( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function addButton( results, label, onClick ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button';
		button.style.margin = '0 6px 6px 0';
		button.textContent = label;
		button.addEventListener( 'click', onClick );
		results.appendChild( button );
	}

	function hasItem( list, value ) {
		return Array.prototype.some.call( list.querySelectorAll( 'li[data-value]' ), function ( item ) {
			return item.dataset.value === value;
		} );
	}

	function appendRegion( list, region ) {
		const country = String( region.country_code || 'RU' ).toUpperCase();
		const name = String( region.region_name || '' );
		const value = country + '|' + name;
		if ( ! name || hasItem( list, value ) ) {
			return;
		}
		const item = document.createElement( 'li' );
		item.dataset.value = value;
		item.textContent = ( region.label || ( name + ' — ' + country ) ) + ' ';
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'manual_regions[]';
		input.value = JSON.stringify( { country_code: country, region_name: name } );
		item.appendChild( input );
		item.appendChild( removeButton() );
		list.appendChild( item );
	}

	function appendLocation( list, location ) {
		const country = String( location.country_code || 'RU' ).toUpperCase();
		const name = String( location.location_name || '' );
		const region = String( location.region_name || '' );
		const value = country + '|' + name + '|' + region;
		if ( ! name || ! region || hasItem( list, value ) ) {
			return;
		}
		const item = document.createElement( 'li' );
		item.dataset.value = value;
		item.textContent = ( location.label || ( name + ' — ' + region + ' — ' + country ) ) + ' ';
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'manual_locations[]';
		input.value = JSON.stringify( { country_code: country, location_name: name, region_name: region } );
		item.appendChild( input );
		item.appendChild( removeButton() );
		list.appendChild( item );
	}

	function removeButton() {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link-delete';
		button.dataset.wdcManualRemove = '1';
		button.textContent = 'Удалить';
		return button;
	}

	function syncPricingSections() {
		const select = document.querySelector( 'select[name="manual_pricing_mode"]' );
		if ( ! select ) {
			return;
		}
		document.querySelectorAll( '[data-wdc-manual-pricing-section]' ).forEach( function ( section ) {
			const active = section.dataset.wdcManualPricingSection === select.value;
			section.style.display = active ? '' : 'none';
			section.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( field ) {
				field.disabled = ! active;
			} );
		} );
	}

	function syncDeliveryTypeSections() {
		const select = document.querySelector( 'select[name="manual_delivery_type"]' );
		if ( ! select ) {
			return;
		}
		document.querySelectorAll( '[data-wdc-manual-delivery-type-custom]' ).forEach( function ( section ) {
			section.style.display = select.value === 'custom' ? '' : 'none';
		} );
		document.querySelectorAll( '[data-wdc-manual-delivery-type-pickup]' ).forEach( function ( section ) {
			section.style.display = select.value === 'pickup' ? '' : 'none';
		} );
	}

	function nextPickupIndex( list ) {
		return Array.prototype.reduce.call( list.querySelectorAll( '[data-wdc-manual-pickup-row]' ), function ( max, row ) {
			return Math.max( max, Number( row.dataset.wdcManualPickupIndex || 0 ) );
		}, -1 ) + 1;
	}

	function input( name, value, type ) {
		const field = document.createElement( 'input' );
		field.type = type || 'hidden';
		field.name = name;
		field.value = value || '';
		return field;
	}

	function appendPickupPoint( list, location ) {
		const index = nextPickupIndex( list );
		const prefix = 'manual_pickup_points[' + index + ']';
		const country = String( location.country_code || 'RU' ).toUpperCase();
		const name = String( location.location_name || '' );
		const region = String( location.region_name || '' );
		if ( ! name || ! region ) {
			return;
		}
		const row = document.createElement( 'tr' );
		row.dataset.wdcManualPickupRow = '1';
		row.dataset.wdcManualPickupIndex = String( index );
		const title = document.createElement( 'td' );
		title.appendChild( input( prefix + '[code]', '' ) );
		title.appendChild( input( prefix + '[location_id]', String( location.id || '' ) ) );
		title.appendChild( input( prefix + '[country_code]', country ) );
		title.appendChild( input( prefix + '[location_name]', name ) );
		title.appendChild( input( prefix + '[region_name]', region ) );
		const titleInput = input( prefix + '[title]', '', 'text' );
		titleInput.placeholder = 'Название';
		title.appendChild( titleInput );
		row.appendChild( title );

		const locationCell = document.createElement( 'td' );
		locationCell.textContent = location.label || ( name + ' — ' + region + ' — ' + country );
		row.appendChild( locationCell );

		const address = document.createElement( 'td' );
		const addressInput = input( prefix + '[address]', '', 'text' );
		addressInput.placeholder = 'Адрес';
		addressInput.required = true;
		address.appendChild( addressInput );
		[ 'postcode', 'work_time', 'comment' ].forEach( function ( key ) {
			const field = input( prefix + '[' + key + ']', '', key === 'postcode' ? 'text' : 'text' );
			field.placeholder = key === 'postcode' ? 'Индекс' : ( key === 'work_time' ? 'Режим работы' : 'Комментарий' );
			field.style.marginTop = '4px';
			address.appendChild( field );
		} );
		row.appendChild( address );

		const coordinates = document.createElement( 'td' );
		const lat = input( prefix + '[latitude]', '', 'text' );
		const lng = input( prefix + '[longitude]', '', 'text' );
		lat.placeholder = 'Широта';
		lng.placeholder = 'Долгота';
		lat.style.width = '95px';
		lng.style.width = '95px';
		coordinates.appendChild( lat );
		coordinates.appendChild( lng );
		row.appendChild( coordinates );

		const active = document.createElement( 'td' );
		const label = document.createElement( 'label' );
		active.appendChild( input( prefix + '[active]', '0' ) );
		const checkbox = input( prefix + '[active]', '1', 'checkbox' );
		checkbox.checked = true;
		label.appendChild( checkbox );
		label.appendChild( document.createTextNode( ' да' ) );
		active.appendChild( label );
		row.appendChild( active );

		const actions = document.createElement( 'td' );
		const deleted = input( prefix + '[delete]', '0' );
		deleted.dataset.wdcManualPickupDelete = '1';
		const remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link-delete';
		remove.dataset.wdcManualPickupRemove = '1';
		remove.textContent = 'Удалить';
		actions.appendChild( deleted );
		actions.appendChild( remove );
		row.appendChild( actions );
		list.appendChild( row );
	}

	function addWeightRangeRow( list ) {
		const row = document.createElement( 'tr' );
		row.dataset.wdcManualWeightRangeRow = '1';
		[
			[ 'manual_weight_range_from_kg[]', 'small-text' ],
			[ 'manual_weight_range_to_kg[]', 'small-text' ],
			[ 'manual_weight_range_price_rub[]', 'regular-text' ]
		].forEach( function ( spec ) {
			const cell = document.createElement( 'td' );
			const input = document.createElement( 'input' );
			input.name = spec[0];
			input.className = spec[1];
			cell.appendChild( input );
			row.appendChild( cell );
		} );
		const actions = document.createElement( 'td' );
		const remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link-delete';
		remove.dataset.wdcManualRemoveRange = '1';
		remove.textContent = 'Удалить';
		actions.appendChild( remove );
		row.appendChild( actions );
		list.appendChild( row );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.matches( 'select[name="manual_pricing_mode"]' ) ) {
			syncPricingSections();
		}
		if ( event.target && event.target.matches( 'select[name="manual_delivery_type"]' ) ) {
			syncDeliveryTypeSections();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		const removeRange = event.target && event.target.closest( '[data-wdc-manual-remove-range]' );
		if ( removeRange ) {
			event.preventDefault();
			const row = removeRange.closest( '[data-wdc-manual-weight-range-row]' );
			if ( row ) {
				row.remove();
			}
			return;
		}

		const addRange = event.target && event.target.closest( '[data-wdc-manual-add-weight-range]' );
		if ( addRange ) {
			event.preventDefault();
			const box = addRange.closest( '[data-wdc-manual-weight-ranges]' );
			const list = box ? box.querySelector( '[data-wdc-manual-weight-range-list]' ) : null;
			if ( list ) {
				addWeightRangeRow( list );
			}
			return;
		}

		const remove = event.target && event.target.closest( '[data-wdc-manual-remove]' );
		if ( remove ) {
			event.preventDefault();
			const item = remove.closest( 'li' );
			if ( item ) {
				item.remove();
			}
			return;
		}

		const removePickup = event.target && event.target.closest( '[data-wdc-manual-pickup-remove]' );
		if ( removePickup ) {
			event.preventDefault();
			const row = removePickup.closest( '[data-wdc-manual-pickup-row]' );
			const deleted = row ? row.querySelector( '[data-wdc-manual-pickup-delete]' ) : null;
			if ( row && deleted ) {
				deleted.value = '1';
				row.style.display = 'none';
			}
			return;
		}

		const regionSearch = event.target && event.target.closest( '[data-wdc-manual-region-search]' );
		if ( regionSearch ) {
			event.preventDefault();
			const box = regionSearch.closest( '[data-wdc-manual-regions]' );
			const query = box ? box.querySelector( '[data-wdc-manual-region-query]' ) : null;
			const results = box ? box.querySelector( '[data-wdc-manual-region-results]' ) : null;
			const list = box ? box.querySelector( '[data-wdc-manual-region-list]' ) : null;
			if ( ! query || ! results || ! list ) {
				return;
			}
			clear( results );
			postSearch( 'wdc_manual_delivery_region_search', query.value ).then( function ( items ) {
				items.forEach( function ( item ) {
					addButton( results, item.label || item.region_name, function () {
						appendRegion( list, item );
					} );
				} );
			} );
			return;
		}

		const pickupLocationSearch = event.target && event.target.closest( '[data-wdc-manual-pickup-location-search]' );
		if ( pickupLocationSearch ) {
			event.preventDefault();
			const box = pickupLocationSearch.closest( '[data-wdc-manual-pickup-points]' );
			const query = box ? box.querySelector( '[data-wdc-manual-pickup-location-query]' ) : null;
			const results = box ? box.querySelector( '[data-wdc-manual-pickup-location-results]' ) : null;
			const list = box ? box.querySelector( '[data-wdc-manual-pickup-list]' ) : null;
			if ( ! query || ! results || ! list ) {
				return;
			}
			clear( results );
			postSearch( 'wdc_manual_delivery_location_search', query.value ).then( function ( items ) {
				items.forEach( function ( item ) {
					addButton( results, item.label || '', function () {
						appendPickupPoint( list, item );
					} );
				} );
			} );
			return;
		}

		const locationSearch = event.target && event.target.closest( '[data-wdc-manual-location-search]' );
		if ( locationSearch ) {
			event.preventDefault();
			const box = locationSearch.closest( '[data-wdc-manual-locations]' );
			const query = box ? box.querySelector( '[data-wdc-manual-location-query]' ) : null;
			const results = box ? box.querySelector( '[data-wdc-manual-location-results]' ) : null;
			const list = box ? box.querySelector( '[data-wdc-manual-location-list]' ) : null;
			if ( ! query || ! results || ! list ) {
				return;
			}
			clear( results );
			postSearch( 'wdc_manual_delivery_location_search', query.value ).then( function ( items ) {
				items.forEach( function ( item ) {
					addButton( results, item.label || '', function () {
						appendLocation( list, item );
					} );
				} );
			} );
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			syncPricingSections();
			syncDeliveryTypeSections();
		} );
	} else {
		syncPricingSections();
		syncDeliveryTypeSections();
	}
}() );
