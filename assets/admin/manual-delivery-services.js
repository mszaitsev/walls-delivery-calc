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
		if ( ! region || hasItem( list, region ) ) {
			return;
		}
		const item = document.createElement( 'li' );
		item.dataset.value = region;
		item.textContent = region + ' ';
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'manual_regions[]';
		input.value = region;
		item.appendChild( input );
		item.appendChild( removeButton() );
		list.appendChild( item );
	}

	function appendLocation( list, location ) {
		const name = String( location.location_name || '' );
		const region = String( location.region_name || '' );
		const value = name + '|' + region;
		if ( ! name || ! region || hasItem( list, value ) ) {
			return;
		}
		const item = document.createElement( 'li' );
		item.dataset.value = value;
		item.textContent = name + ' — ' + region + ' ';
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'manual_locations[]';
		input.value = JSON.stringify( { location_name: name, region_name: region } );
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

	document.addEventListener( 'click', function ( event ) {
		const remove = event.target && event.target.closest( '[data-wdc-manual-remove]' );
		if ( remove ) {
			event.preventDefault();
			const item = remove.closest( 'li' );
			if ( item ) {
				item.remove();
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
						appendRegion( list, item.region_name || item.label || '' );
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
}() );
