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
		const lat = point.lat !== null && point.lat !== undefined ? parseFloat( point.lat ) : null;
		const lng = point.lng !== null && point.lng !== undefined ? parseFloat( point.lng ) : null;
		const postcode = String( point.point_postcode || point.postcode || point.postal_code || '' );
		const address = String( point.point_address || point.address || '' );
		return {
			id: String( point.id || point.point_code || postcode || address || '' ),
			point_code: String( point.point_code || '' ),
			point_type: String( point.point_type || 'OPS' ),
			point_name: String( point.point_name || postcode || point.point_code || '' ),
			point_address: address,
			point_postcode: postcode,
			postcode: postcode,
			postal_code: postcode,
			address: address,
			city_name: String( point.city_name || point.city || '' ),
			region_name: String( point.region_name || '' ),
			lat: Number.isFinite( lat ) ? lat : null,
			lng: Number.isFinite( lng ) ? lng : null,
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
			'<h2>Выбор ПВЗ</h2>',
			'<div class="wdc-order-delivery-pickup-picker__search"><input type="search" data-wdc-pickup-picker-query placeholder="Поиск адреса или индекса"><button type="button" class="button" data-wdc-pickup-picker-search>Найти</button></div>',
			'<div class="wdc-order-delivery-pickup-picker__status" data-wdc-pickup-picker-status></div>',
			'<div class="wdc-order-delivery-pickup-picker__layout">',
			'<div class="wdc-order-delivery-pickup-picker__map" data-wdc-pickup-picker-map></div>',
			'<div class="wdc-order-delivery-pickup-picker__side">',
			'<div class="wdc-order-delivery-pickup-picker__selected" data-wdc-pickup-picker-selected>Выберите ПВЗ на карте или в списке.</div>',
			'<div class="wdc-order-delivery-pickup-picker__list" data-wdc-pickup-picker-list></div>',
			'</div>',
			'</div>',
			'</div>'
		].join( '' );
		document.body.appendChild( root );
		const query = root.querySelector( '[data-wdc-pickup-picker-query]' );
		const status = root.querySelector( '[data-wdc-pickup-picker-status]' );
		const mapElement = root.querySelector( '[data-wdc-pickup-picker-map]' );
		const selected = root.querySelector( '[data-wdc-pickup-picker-selected]' );
		const list = root.querySelector( '[data-wdc-pickup-picker-list]' );
		const providerName = config.mapProvider === 'yandex' ? 'yandex' : 'leaflet';
		const providerFactory = window.WDCPickupMapProviders && window.WDCPickupMapProviders[ providerName ];
		let provider = null;
		let points = [];
		let previewPoint = null;

		function close() {
			if ( provider && provider.destroy ) {
				provider.destroy();
			}
			root.remove();
		}

		function pointId( point ) {
			return String( point && ( point.id || point.point_code || point.postcode || point.address ) || '' );
		}

		function findPoint( id ) {
			id = String( id || '' );
			return points.find( function ( point ) {
				return pointId( point ) === id;
			} ) || null;
		}

		function renderPopup( point ) {
			return [
				'<div class="wdc-pickup-popup">',
				'<h3 class="wdc-pickup-popup__title">' + escapeHtml( point.point_name || point.point_postcode || '' ) + '</h3>',
				'<div class="wdc-pickup-popup__section"><strong>Индекс:</strong><span>' + escapeHtml( point.point_postcode || '' ) + '</span></div>',
				'<div class="wdc-pickup-popup__section"><strong>Адрес:</strong><span>' + escapeHtml( pickupPointLabel( point ) ) + '</span></div>',
				'<button type="button" class="button button-primary wdc-pickup-popup__select" data-wdc-pickup-popup-select data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '">Выбрать этот ПВЗ</button>',
				'</div>'
			].join( '' );
		}

		function preview( point ) {
			previewPoint = point;
			if ( selected ) {
				selected.innerHTML = [
					'<div class="wdc-order-delivery-pickup-picker__selected-grid">',
					'<span><strong>Индекс</strong>' + escapeHtml( point.point_postcode || '' ) + '</span>',
					'<span><strong>Адрес</strong>' + escapeHtml( pickupPointLabel( point ) ) + '</span>',
					'<button type="button" class="button button-primary" data-wdc-pickup-picker-choose data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '">Выбрать этот ПВЗ</button>',
					'</div>'
				].join( '' );
			}
			if ( provider && provider.setActivePoint ) {
				provider.setActivePoint( pointId( point ) );
			}
			if ( provider && provider.openPointPopup ) {
				provider.openPointPopup( point, renderPopup( point ), { forceReopen: true } );
			}
			renderPickupPoints();
		}

		function choosePoint( point ) {
			selectedPickupPoints.set( box, point );
			updatePickupSelectors( box );
			close();
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
					previewPoint = null;
					const withCoordinates = points.filter( function ( point ) {
						return point.lat !== null && point.lng !== null;
					} ).length;
					status.textContent = points.length
						? 'Найдено: ' + points.length + ( withCoordinates < points.length ? '. Часть ПВЗ без координат доступна только в списке.' : '' )
						: 'ПВЗ не найдены.';
					if ( selected ) {
						selected.textContent = 'Выберите ПВЗ на карте или в списке.';
					}
					if ( provider && provider.renderMarkers ) {
						provider.renderMarkers( points, { activePointId: null } );
						if ( provider.fitToMarkers ) {
							provider.fitToMarkers();
						}
					}
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
				'<table class="widefat striped wdc-order-delivery-pickup-picker__table"><thead><tr><th>Индекс</th><th>Адрес</th><th>Выбрать</th></tr></thead><tbody>',
				points.map( function ( point, index ) {
					const active = previewPoint && pointId( previewPoint ) === pointId( point ) ? ' class="is-active"' : '';
					return '<tr data-wdc-pickup-picker-row data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '" data-index="' + escapeAttribute( String( index ) ) + '"' + active + '><td>' + escapeHtml( point.point_postcode ) + '</td><td>' + escapeHtml( pickupPointLabel( point ) ) + '</td><td><button type="button" class="button" data-wdc-pickup-picker-choose data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '">Выбрать</button></td></tr>';
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
			const chooseButton = event.target.closest( '[data-wdc-pickup-picker-choose], [data-wdc-pickup-popup-select]' );
			if ( chooseButton ) {
				const point = findPoint( chooseButton.getAttribute( 'data-wdc-point-id' ) );
				if ( point ) {
					choosePoint( point );
				}
				return;
			}
			const row = event.target.closest( '[data-wdc-pickup-picker-row]' );
			if ( row ) {
				const point = findPoint( row.getAttribute( 'data-wdc-point-id' ) );
				if ( point ) {
					preview( point );
				}
			}
		} );
		query.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				runSearch();
			}
		} );
		window.wdcPickupCheckout = Object.assign( {}, window.wdcPickupCheckout || {}, {
			mapProvider: providerName,
			yandexApiKeyPresent: !! config.yandexApiKeyPresent,
			yandexApiKey: config.yandexApiKey || '',
			pickupPointTypes: config.pickupPointTypes || {}
		} );
		if ( ! providerFactory || typeof providerFactory.create !== 'function' ) {
			status.textContent = 'Карта недоступна, выберите ПВЗ из списка.';
		} else if ( providerName === 'yandex' && ! config.yandexApiKeyPresent ) {
			status.textContent = 'Карта недоступна: для Яндекс.Карт не задан API key. Выберите ПВЗ из списка.';
		} else {
			provider = providerFactory.create( mapElement, {
				center: { lat: 55.0302, lng: 82.9204, zoom: 11 },
				yandexApiKey: config.yandexApiKey || '',
				onBoundsChange: function () {}
			} );
			if ( provider && provider.onPointClick ) {
				provider.onPointClick( function ( point ) {
					preview( point );
				} );
			}
			if ( provider && provider.onPopupSelect ) {
				provider.onPopupSelect( function ( point ) {
					choosePoint( point );
				} );
			}
			window.setTimeout( function () {
				if ( provider && provider.invalidateSize ) {
					provider.invalidateSize();
				}
			}, 50 );
		}
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
