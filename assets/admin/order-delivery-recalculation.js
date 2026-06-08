( function () {
	'use strict';

	const config = window.wdcOrderDeliveryRecalculation || {};
	const activeRequests = new WeakSet();
	const selectedLocations = new WeakMap();
	const searchTimers = new WeakMap();

	function closestBox( element ) {
		return element ? element.closest( '[data-wdc-order-delivery-recalculation]' ) : null;
	}

	function modal( box ) {
		return box ? box.querySelector( '[data-wdc-order-delivery-modal]' ) : null;
	}

	function modalDialog( box ) {
		const node = modal( box );
		return node ? node.querySelector( '.wdc-order-delivery-modal__dialog' ) : null;
	}

	function modalContent( box ) {
		const node = modal( box );
		return node ? node.querySelector( '[data-wdc-order-delivery-modal-content]' ) : null;
	}

	function currentLocationNode( box ) {
		const node = modal( box );
		return node ? node.querySelector( '[data-wdc-order-delivery-location-current]' ) : null;
	}

	function setStatus( box, message, type ) {
		const node = modal( box );
		const status = node && node.querySelector( '[data-wdc-order-delivery-modal-status]' );
		if ( ! status ) {
			return;
		}
		status.textContent = message || '';
		status.dataset.status = type || '';
	}

	function setLoading( button, loading ) {
		if ( ! button ) {
			return;
		}
		button.disabled = !! loading;
		button.dataset.originalText = button.dataset.originalText || button.textContent;
		button.textContent = loading ? 'Расчет...' : button.dataset.originalText;
	}

	function setPreviewButtonsLoading( box, loading ) {
		if ( ! box ) {
			return;
		}
		box.querySelectorAll( '[data-wdc-order-delivery-recalculate], [data-wdc-order-delivery-modal-preview]' ).forEach( function ( button ) {
			setLoading( button, loading );
		} );
	}

	function openModal( box ) {
		const node = modal( box );
		if ( ! node ) {
			return;
		}
		ensureInitialLocation( box );
		node.hidden = false;
		document.body.classList.add( 'wdc-order-delivery-modal-open' );
		window.setTimeout( function () {
			const close = node.querySelector( '[data-wdc-order-delivery-modal-close]' );
			if ( close && close.focus ) {
				close.focus();
				return;
			}
			const dialog = modalDialog( box );
			if ( dialog && dialog.focus ) {
				dialog.focus();
			}
		}, 0 );
	}

	function closeModal( box ) {
		const node = modal( box );
		if ( ! node ) {
			return;
		}
		node.hidden = true;
		if ( ! document.querySelector( '[data-wdc-order-delivery-modal]:not([hidden])' ) ) {
			document.body.classList.remove( 'wdc-order-delivery-modal-open' );
		}
	}

	function resetModal( box ) {
		const content = modalContent( box );
		if ( content ) {
			content.innerHTML = '';
		}
		clearPickupPlaceholders( box );
	}

	function renderPreview( box, html ) {
		const content = modalContent( box );
		if ( ! content ) {
			return;
		}
		content.innerHTML = html || '';
		clearPickupPlaceholders( box );
	}

	function clearPickupPlaceholders( root ) {
		root.querySelectorAll( '[data-wdc-pickup-placeholder]' ).forEach( function ( node ) {
			node.hidden = true;
		} );
	}

	function selectedRateChanged( input ) {
		const box = closestBox( input );
		if ( ! box ) {
			return;
		}
		clearPickupPlaceholders( box );
		const rate = input.closest( '[data-wdc-order-delivery-rate]' );
		if ( ! rate || '1' !== String( rate.dataset.requiresPickup || '' ) ) {
			return;
		}
		const placeholder = rate.querySelector( '[data-wdc-pickup-placeholder]' );
		if ( placeholder ) {
			placeholder.hidden = false;
		}
	}

	function ensureInitialLocation( box ) {
		if ( selectedLocations.has( box ) ) {
			return;
		}
		const script = box.querySelector( '[data-wdc-order-delivery-current-location]' );
		let location = {};
		if ( script ) {
			try {
				location = JSON.parse( script.textContent || '{}' ) || {};
			} catch ( error ) {
				location = {};
			}
		}
		selectedLocations.set( box, location );
		updateLocationSummary( box, location );
	}

	function updateLocationSummary( box, location ) {
		const current = currentLocationNode( box );
		if ( current ) {
			current.textContent = locationLabel( location ) || 'Не указан';
		}
		const input = box.querySelector( '[data-wdc-order-delivery-location-input]' );
		if ( input && locationLabel( location ) ) {
			input.value = locationLabel( location );
		}
	}

	function locationLabel( location ) {
		if ( ! location ) {
			return '';
		}
		return String( location.label || location.option_label || location.display_name || location.city_value || location.city_name || location.place_name || '' );
	}

	function requestPreview( box, button ) {
		if ( ! box ) {
			return;
		}
		ensureInitialLocation( box );
		const openButton = box ? box.querySelector( '[data-wdc-order-delivery-recalculate]' ) : null;
		const orderId = openButton ? String( openButton.dataset.orderId || '' ) : '';
		if ( ! box || ! orderId || activeRequests.has( box ) ) {
			return;
		}

		const form = new FormData();
		form.append( 'action', config.action || 'wdc_order_delivery_recalculate_preview' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'order_id', orderId );
		form.append( 'selected_location', JSON.stringify( selectedLocations.get( box ) || {} ) );

		activeRequests.add( box );
		openModal( box );
		resetModal( box );
		setStatus( box, 'Считаем доступные варианты доставки...', 'loading' );
		setPreviewButtonsLoading( box, true );

		window.fetch( config.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось пересчитать доставку.' );
				}
				renderPreview( box, payload.data && payload.data.html ? payload.data.html : '' );
				if ( payload.data && payload.data.location && payload.data.location.label ) {
					setStatus( box, 'Расчет выполнен для: ' + payload.data.location.label, 'success' );
				} else {
					setStatus( box, 'Preview рассчитан. Сохранение доставки будет добавлено следующим шагом.', 'success' );
				}
			} )
			.catch( function ( error ) {
				setStatus( box, error && error.message ? error.message : 'Не удалось пересчитать доставку.', 'error' );
			} )
			.finally( function () {
				activeRequests.delete( box );
				setPreviewButtonsLoading( box, false );
			} );
	}

	function searchLocations( box, query ) {
		const results = box.querySelector( '[data-wdc-order-delivery-location-results]' );
		const location = selectedLocations.get( box ) || {};
		if ( ! results ) {
			return;
		}
		if ( query.trim().length < 3 ) {
			results.innerHTML = '<p class="description">Введите минимум 3 символа.</p>';
			return;
		}

		const form = new FormData();
		form.append( 'action', config.locationSearchAction || 'wdc_order_delivery_recalculate_location_search' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'query', query );
		form.append( 'country_code', location.country_code || 'RU' );
		results.innerHTML = '<p class="description">Идет поиск...</p>';

		window.fetch( config.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось найти населенный пункт.' );
				}
				renderLocationResults( box, payload.data || {} );
			} )
			.catch( function ( error ) {
				results.innerHTML = '<p class="description">' + escapeHtml( error && error.message ? error.message : 'Не удалось найти населенный пункт.' ) + '</p>';
			} );
	}

	function renderLocationResults( box, payload ) {
		const results = box.querySelector( '[data-wdc-order-delivery-location-results]' );
		if ( ! results ) {
			return;
		}
		const items = [];
		( payload.groups || [] ).forEach( function ( group ) {
			( group.items || group.locations || [] ).forEach( function ( item ) {
				items.push( item );
			} );
		} );
		if ( ! items.length ) {
			results.innerHTML = '<p class="description">Населенные пункты не найдены.</p>';
			return;
		}
		results.innerHTML = items.slice( 0, 20 ).map( function ( item ) {
			return '<button type="button" class="button-link wdc-order-delivery-location__result" data-wdc-order-delivery-location-option data-location="' + escapeAttribute( JSON.stringify( item ) ) + '">' + escapeHtml( locationLabel( item ) ) + '</button>';
		} ).join( '' );
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( char ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ char ];
		} );
	}

	function escapeAttribute( value ) {
		return escapeHtml( value );
	}

	document.addEventListener( 'click', function ( event ) {
		const openButton = event.target && event.target.closest( '[data-wdc-order-delivery-recalculate]' );
		if ( openButton ) {
			event.preventDefault();
			requestPreview( closestBox( openButton ), openButton );
			return;
		}

		const previewButton = event.target && event.target.closest( '[data-wdc-order-delivery-modal-preview]' );
		if ( previewButton ) {
			event.preventDefault();
			requestPreview( closestBox( previewButton ), previewButton );
			return;
		}

		const editButton = event.target && event.target.closest( '[data-wdc-order-delivery-location-edit]' );
		if ( editButton ) {
			event.preventDefault();
			const box = closestBox( editButton );
			const search = box && box.querySelector( '[data-wdc-order-delivery-location-search]' );
			if ( search ) {
				search.hidden = ! search.hidden;
				const input = search.querySelector( '[data-wdc-order-delivery-location-input]' );
				if ( ! search.hidden && input && input.focus ) {
					input.focus();
				}
			}
			return;
		}

		const option = event.target && event.target.closest( '[data-wdc-order-delivery-location-option]' );
		if ( option ) {
			event.preventDefault();
			const box = closestBox( option );
			let location = {};
			try {
				location = JSON.parse( option.dataset.location || '{}' ) || {};
			} catch ( error ) {
				location = {};
			}
			if ( box ) {
				selectedLocations.set( box, location );
				updateLocationSummary( box, location );
				const search = box.querySelector( '[data-wdc-order-delivery-location-search]' );
				if ( search ) {
					search.hidden = true;
				}
				resetModal( box );
				setStatus( box, 'Населенный пункт выбран. Нажмите «Пересчитать», чтобы обновить preview.', '' );
			}
			return;
		}

		const closeButton = event.target && event.target.closest( '[data-wdc-order-delivery-modal-close]' );
		if ( closeButton ) {
			event.preventDefault();
			const box = closestBox( closeButton );
			if ( box ) {
				closeModal( box );
			}
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		const input = event.target;
		if ( ! input || ! input.matches || ! input.matches( '[data-wdc-order-delivery-location-input]' ) ) {
			return;
		}
		const box = closestBox( input );
		if ( ! box ) {
			return;
		}
		if ( searchTimers.has( input ) ) {
			window.clearTimeout( searchTimers.get( input ) );
		}
		searchTimers.set( input, window.setTimeout( function () {
			searchLocations( box, String( input.value || '' ) );
		}, 300 ) );
	} );

	document.addEventListener( 'change', function ( event ) {
		const input = event.target;
		if ( ! input || 'wdc_order_delivery_preview_rate' !== input.name ) {
			return;
		}
		selectedRateChanged( input );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}
		document.querySelectorAll( '[data-wdc-order-delivery-modal]:not([hidden])' ).forEach( function ( node ) {
			const box = closestBox( node );
			if ( box ) {
				closeModal( box );
			}
		} );
	} );
} )();
