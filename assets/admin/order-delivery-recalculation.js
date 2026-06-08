( function () {
	'use strict';

	const config = window.wdcOrderDeliveryRecalculation || {};
	const activeRequests = new WeakSet();
	const selectedLocations = new WeakMap();
	const selectedRates = new WeakMap();
	const selectedPickupPoints = new WeakMap();
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
		selectedRates.delete( box );
		selectedPickupPoints.delete( box );
		updatePickupSelectors( box );
	}

	function renderPreview( box, html ) {
		const content = modalContent( box );
		if ( ! content ) {
			return;
		}
		content.innerHTML = html || '';
		selectedRates.delete( box );
		selectedPickupPoints.delete( box );
		updatePickupSelectors( box );
	}

	function updatePickupSelectors( box ) {
		if ( ! box ) {
			return;
		}
		const selectedRate = selectedRates.get( box );
		const selectedPickup = selectedPickupPoints.get( box );
		box.querySelectorAll( '[data-wdc-pickup-selector]' ).forEach( function ( node ) {
			const rate = node.closest( '[data-wdc-order-delivery-rate]' );
			const visible = !! ( selectedRate && rate && rate.dataset.rateId === selectedRate.id );
			node.hidden = ! visible;
			const label = node.querySelector( '[data-wdc-selected-pickup-label]' );
			const button = node.querySelector( '[data-wdc-open-pickup-picker]' );
			if ( label ) {
				label.textContent = visible && selectedPickup ? 'ПВЗ: ' + pickupPointLabel( selectedPickup ) : 'ПВЗ не выбран';
			}
			if ( button ) {
				button.textContent = visible && selectedPickup ? 'Изменить ПВЗ' : 'Выбрать ПВЗ';
			}
		} );
	}

	function selectedRateChanged( input ) {
		const box = closestBox( input );
		if ( ! box ) {
			return;
		}
		const rate = input.closest( '[data-wdc-order-delivery-rate]' );
		if ( ! rate ) {
			return;
		}
		const payload = parseJson( rate.dataset.ratePayload || '{}' );
		payload.id = rate.dataset.rateId || payload.id || input.value || '';
		payload.delivery_type = rate.dataset.deliveryType || payload.delivery_type || '';
		payload.requires_pickup_point = '1' === String( rate.dataset.requiresPickup || '' );
		payload.carrier_key = rate.dataset.carrierKey || payload.carrier_key || '';
		payload.service_key = rate.dataset.serviceKey || payload.service_key || '';
		payload.selected_tariff = selectedTariffPayload( rate );
		selectedRates.set( box, payload );
		if ( ! payload.requires_pickup_point ) {
			selectedPickupPoints.delete( box );
		}
		updatePickupSelectors( box );
	}

	function selectedTariffPayload( rate ) {
		const explicit = rate.querySelector( '.wdc-order-delivery-tariff input[type="radio"]:checked' );
		const fallback = rate.querySelector( '.wdc-order-delivery-tariff input[type="radio"]' );
		const input = explicit || fallback;
		if ( ! input ) {
			return null;
		}
		const payload = parseJson( input.dataset.tariffPayload || '{}' );
		payload.object_code = payload.object_code || input.value || '';
		return payload;
	}

	function parseJson( text ) {
		try {
			return JSON.parse( text || '{}' ) || {};
		} catch ( error ) {
			return {};
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

	function orderId( box ) {
		const openButton = box ? box.querySelector( '[data-wdc-order-delivery-recalculate]' ) : null;
		return openButton ? String( openButton.dataset.orderId || '' ) : '';
	}

	function pickupPointLabel( point ) {
		return String( point.point_address || point.address || point.point_name || point.point_code || '' );
	}

	function normalizePickupPoint( point ) {
		point = point || {};
		return {
			point_code: String( point.point_code || '' ),
			point_type: String( point.point_type || 'OPS' ),
			point_name: String( point.point_name || point.postcode || point.point_code || '' ),
			point_address: String( point.point_address || point.address || '' ),
			point_postcode: String( point.point_postcode || point.postcode || point.postal_code || '' ),
			point_raw: point
		};
	}

	function openPickupPicker( box ) {
		const rate = selectedRates.get( box );
		if ( ! rate || ! rate.requires_pickup_point ) {
			return;
		}
		const location = selectedLocations.get( box ) || {};
		const root = document.createElement( 'div' );
		root.className = 'wdc-order-delivery-pickup-picker';
		root.innerHTML = [
			'<div class="wdc-order-delivery-pickup-picker__overlay" data-wdc-pickup-picker-close></div>',
			'<div class="wdc-order-delivery-pickup-picker__dialog" role="dialog" aria-modal="true" aria-label="Выбор ПВЗ">',
			'<button type="button" class="button-link wdc-order-delivery-pickup-picker__close" data-wdc-pickup-picker-close aria-label="Закрыть">×</button>',
			'<h2>Выбор ПВЗ / ОПС</h2>',
			'<div class="wdc-order-delivery-pickup-picker__search"><input type="search" data-wdc-pickup-picker-query placeholder="Поиск адреса или индекса"><button type="button" class="button" data-wdc-pickup-picker-search>Найти</button></div>',
			'<div class="wdc-order-delivery-pickup-picker__status" data-wdc-pickup-picker-status></div>',
			'<div class="wdc-order-delivery-pickup-picker__list" data-wdc-pickup-picker-list></div>',
			'</div>'
		].join( '' );
		document.body.appendChild( root );
		const query = root.querySelector( '[data-wdc-pickup-picker-query]' );
		const status = root.querySelector( '[data-wdc-pickup-picker-status]' );
		const list = root.querySelector( '[data-wdc-pickup-picker-list]' );
		let points = [];

		function close() {
			root.remove();
		}

		function runSearch() {
			const form = new FormData();
			const value = String( query.value || '' ).trim();
			form.append( 'action', config.pickupSearchAction || 'wdc_order_delivery_recalculate_pickup_search' );
			form.append( 'nonce', config.nonce || '' );
			form.append( 'order_id', orderId( box ) );
			form.append( 'selected_location', JSON.stringify( location ) );
			form.append( 'selected_rate', JSON.stringify( rate ) );
			form.append( 'query', value );
			form.append( 'limit', '50' );
			status.textContent = 'Идет поиск ПВЗ...';
			list.innerHTML = '';
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
						throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось найти ПВЗ.' );
					}
					points = Array.isArray( payload.data && payload.data.points ) ? payload.data.points.map( normalizePickupPoint ) : [];
					status.textContent = points.length ? 'Найдено: ' + points.length : 'ПВЗ не найдены.';
					renderPickupPoints();
				} )
				.catch( function ( error ) {
					status.textContent = error && error.message ? error.message : 'Не удалось найти ПВЗ.';
				} );
		}

		function renderPickupPoints() {
			if ( ! points.length ) {
				list.innerHTML = '<p class="description">ПВЗ не найдены.</p>';
				return;
			}
			list.innerHTML = [
				'<table class="widefat striped"><thead><tr><th>Индекс</th><th>Адрес</th><th>Выбрать</th></tr></thead><tbody>',
				points.map( function ( point, index ) {
					return '<tr><td>' + escapeHtml( point.point_postcode ) + '</td><td>' + escapeHtml( pickupPointLabel( point ) ) + '</td><td><button type="button" class="button" data-wdc-pickup-picker-choose data-index="' + escapeHtml( String( index ) ) + '">Выбрать</button></td></tr>';
				} ).join( '' ),
				'</tbody></table>'
			].join( '' );
		}

		root.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-wdc-pickup-picker-close]' ) ) {
				close();
				return;
			}
			if ( event.target.closest( '[data-wdc-pickup-picker-search]' ) ) {
				runSearch();
				return;
			}
			const choose = event.target.closest( '[data-wdc-pickup-picker-choose]' );
			if ( choose ) {
				const point = points[ Number( choose.dataset.index || 0 ) ];
				if ( point ) {
					selectedPickupPoints.set( box, point );
					updatePickupSelectors( box );
					close();
				}
			}
		} );
		query.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				runSearch();
			}
		} );
		query.value = String( location.postal_code || location.postcode || location.city_value || location.display_name || '' );
		query.focus();
		runSearch();
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

		const pickupButton = event.target && event.target.closest( '[data-wdc-open-pickup-picker]' );
		if ( pickupButton ) {
			event.preventDefault();
			openPickupPicker( closestBox( pickupButton ) );
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
		if ( ! input ) {
			return;
		}
		if ( 'wdc_order_delivery_preview_rate' === input.name ) {
			selectedRateChanged( input );
			return;
		}
		if ( input.name && input.name.indexOf( 'wdc_order_delivery_preview_tariff_' ) === 0 ) {
			const rate = input.closest( '[data-wdc-order-delivery-rate]' );
			const box = closestBox( input );
			if ( rate && box && selectedRates.get( box ) && selectedRates.get( box ).id === rate.dataset.rateId ) {
				selectedRateChanged( rate.querySelector( 'input[name="wdc_order_delivery_preview_rate"]' ) );
			}
		}
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
