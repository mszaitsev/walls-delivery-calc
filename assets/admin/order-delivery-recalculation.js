( function () {
	'use strict';

	const config = window.wdcOrderDeliveryRecalculation || {};
	const activeRequests = new WeakSet();
	const selectedLocations = new WeakMap();
	const selectedRates = new WeakMap();
	const selectedPickupPoints = new WeakMap();
	const normalizedShippingAddresses = new WeakMap();
	const courierAddressStates = new WeakMap();
	const activeSaveRequests = new WeakSet();
	const searchTimers = new WeakMap();
	const courierAddressTimers = new WeakMap();

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

	function jsonScriptPayload( box, selector ) {
		const script = box ? box.querySelector( selector ) : null;
		if ( ! script ) {
			return {};
		}
		try {
			return JSON.parse( script.textContent || '{}' ) || {};
		} catch ( error ) {
			return {};
		}
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
		normalizedShippingAddresses.delete( box );
		updatePickupSelectors( box );
		updateCourierAddressBlocks( box );
		updateSaveButton( box );
	}

	function renderPreview( box, html ) {
		const content = modalContent( box );
		if ( ! content ) {
			return;
		}
		content.innerHTML = html || '';
		selectedRates.delete( box );
		selectedPickupPoints.delete( box );
		normalizedShippingAddresses.delete( box );
		updatePickupSelectors( box );
		updateCourierAddressBlocks( box );
		updateSaveButton( box );
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
		updateCourierAddressBlocks( box );
		updateSaveButton( box );
	}

	function updateCourierAddressBlocks( box ) {
		if ( ! box ) {
			return;
		}
		const selectedRate = selectedRates.get( box );
		box.querySelectorAll( '[data-wdc-order-delivery-rate]' ).forEach( function ( rateNode ) {
			const visible = !! ( selectedRate && ! selectedRate.requires_pickup_point && rateNode.dataset.rateId === selectedRate.id );
			let block = rateNode.querySelector( '[data-wdc-courier-address-block]' );
			if ( visible && ! block ) {
				block = document.createElement( 'div' );
				block.className = 'wdc-order-delivery-courier-address';
				block.setAttribute( 'data-wdc-courier-address-block', '1' );
				block.innerHTML = [
					'<strong>Адрес доставки</strong>',
					'<input type="text" class="widefat" data-wdc-courier-address-line placeholder="Улица, дом, квартира">',
					'<button type="button" class="button" data-wdc-use-manual-courier-address disabled="disabled">Использовать этот адрес</button>',
					'<div class="wdc-order-delivery-courier-address__suggestions" data-wdc-courier-address-suggestions></div>',
					'<div class="wdc-order-delivery-courier-address__status" data-wdc-courier-address-status></div>',
					'<div class="wdc-order-delivery-courier-address__result" data-wdc-courier-address-result></div>'
				].join( '' );
				rateNode.appendChild( block );
			}
			if ( ! block ) {
				return;
			}
			block.hidden = ! visible;
			if ( visible ) {
				const input = block.querySelector( '[data-wdc-courier-address-line]' );
				if ( input && ! input.value ) {
					input.value = shippingAddressLine( currentShippingAddress( box ) );
				}
				const normalized = normalizedShippingAddresses.get( box );
				const status = block.querySelector( '[data-wdc-courier-address-status]' );
				const result = block.querySelector( '[data-wdc-courier-address-result]' );
				const manualButton = block.querySelector( '[data-wdc-use-manual-courier-address]' );
				if ( status ) {
					status.textContent = normalized ? ( normalized.fallback ? 'Адрес будет сохранен без нормализации.' : 'Адрес нормализован.' ) : 'Проверьте адрес перед сохранением.';
				}
				if ( result ) {
					result.textContent = normalized ? String( normalized.full_address || normalized.address_1 || '' ) : '';
				}
				if ( manualButton && normalized ) {
					manualButton.disabled = true;
				}
			}
		} );
	}

	function updateSaveButton( box ) {
		const button = box ? box.querySelector( '[data-wdc-order-delivery-save]' ) : null;
		if ( ! button ) {
			return;
		}
		const rate = selectedRates.get( box );
		let enabled = !! rate;
		if ( enabled && rate.requires_pickup_point ) {
			enabled = !! selectedPickupPoints.get( box );
		} else if ( enabled ) {
			enabled = isValidCourierAddress( normalizedShippingAddresses.get( box ) );
		}
		button.disabled = ! enabled || activeSaveRequests.has( box );
		updateCourierLocationWarning( box );
	}

	function isValidCourierAddress( address ) {
		if ( ! address ) {
			return false;
		}
		const addressLine = String( address.address_1 || address.full_address || '' ).trim();
		return addressLine !== '' && ( ( !! address.normalized && ! address.fallback ) || ( !! address.fallback && address.source === 'admin_manual' ) );
	}

	function updateCourierLocationWarning( box ) {
		const node = box ? box.querySelector( '[data-wdc-order-delivery-save-warning]' ) : null;
		if ( ! node ) {
			return;
		}
		const rate = selectedRates.get( box );
		const address = normalizedShippingAddresses.get( box );
		if ( ! rate || rate.requires_pickup_point || ! isValidCourierAddress( address ) ) {
			node.hidden = true;
			node.textContent = '';
			node.dataset.status = '';
			return;
		}
		const warning = courierLocationWarning( selectedLocations.get( box ) || {}, address || {} );
		if ( '' === warning ) {
			node.hidden = true;
			node.textContent = '';
			node.dataset.status = '';
			return;
		}
		node.hidden = false;
		node.textContent = warning;
		node.dataset.status = 'warning';
	}

	function courierLocationWarning( location, address ) {
		if ( ! address || address.fallback || address.source === 'admin_manual' ) {
			return 'Не удалось подтвердить, что населенный пункт адреса совпадает с расчетом тарифа.';
		}
		if ( courierLocationsMatch( location || {}, address || {} ) ) {
			return '';
		}
		const rateLabel = locationLabel( location ) || 'не указан';
		const addressLabel = addressLocationLabel( address ) || 'не указан';
		return 'Внимание: населенный пункт в адресе доставки отличается от населенного пункта, для которого рассчитан тариф. Расчет: ' + rateLabel + '. Адрес: ' + addressLabel + '.';
	}

	function courierLocationsMatch( location, address ) {
		const locationIds = [
			location.fias_id,
			location.location_fias_id,
			location.city_fias_id,
			location.settlement_fias_id,
			location.gar_object_id,
			location.gar_id
		].map( normalizeId ).filter( Boolean );
		const addressIds = [
			address.location_fias_id,
			address.city_fias_id,
			address.settlement_fias_id,
			address.city_kladr_id,
			address.settlement_kladr_id,
			address.location_gar_id,
			address.gar_object_id,
			address.gar_id
		].map( normalizeId ).filter( Boolean );
		if ( locationIds.length && addressIds.length && locationIds.some( function ( id ) {
			return addressIds.indexOf( id ) !== -1;
		} ) ) {
			return true;
		}
		const locationCity = normalizePlaceName( location.city_value || location.place_name || location.city_name || location.display_name || location.label || '' );
		const addressCity = normalizePlaceName( address.city || address.city_value || address.settlement || '' );
		const locationRegion = normalizeRegionName( location.region_name || location.state_value || location.display_name || '' );
		const addressRegion = normalizeRegionName( address.region || address.region_name || '' );
		return '' !== locationCity && '' !== addressCity && locationCity === addressCity && ( '' === locationRegion || '' === addressRegion || locationRegion === addressRegion );
	}

	function normalizeId( value ) {
		return String( value || '' ).trim().toLowerCase();
	}

	function normalizePlaceName( value ) {
		return String( value || '' )
			.toLowerCase()
			.replace( /ё/g, 'е' )
			.replace( /\b(город|г|село|с|поселок|посёлок|пгт|деревня|д|станица|ст)\b\.?/g, ' ' )
			.replace( /[^a-zа-я0-9]+/g, ' ' )
			.trim();
	}

	function normalizeRegionName( value ) {
		return String( value || '' )
			.toLowerCase()
			.replace( /ё/g, 'е' )
			.replace( /\b(область|обл|край|республика|респ|ао|автономный округ|округ)\b\.?/g, ' ' )
			.replace( /[^a-zа-я0-9]+/g, ' ' )
			.trim();
	}

	function addressLocationLabel( address ) {
		return [ address.region || address.region_name || '', address.city || address.city_value || '' ].filter( function ( part ) {
			return '' !== String( part || '' ).trim();
		} ).join( ', ' );
	}

	function clearCourierAddressSuggestions( block ) {
		const suggestions = block ? block.querySelector( '[data-wdc-courier-address-suggestions]' ) : null;
		if ( suggestions ) {
			suggestions.innerHTML = '';
		}
	}

	function renderCourierAddressSuggestions( box, block, items ) {
		const suggestions = block ? block.querySelector( '[data-wdc-courier-address-suggestions]' ) : null;
		if ( ! suggestions ) {
			return;
		}
		if ( ! Array.isArray( items ) || items.length === 0 ) {
			suggestions.innerHTML = '';
			return;
		}
		suggestions.innerHTML = items.map( function ( item ) {
			return '<button type="button" class="button-link wdc-order-delivery-courier-address__suggestion" data-wdc-courier-address-suggestion data-item="' + escapeAttribute( JSON.stringify( item || {} ) ) + '">' +
				'<span>' + escapeHtml( item.label || item.value || '' ) + '</span>' +
				( item.subLabel ? '<small>' + escapeHtml( item.subLabel ) + '</small>' : '' ) +
				'</button>';
		} ).join( '' );
		updateSaveButton( box );
	}

	function courierAddressState( block ) {
		if ( ! courierAddressStates.has( block ) ) {
			courierAddressStates.set( block, {
				selectedHouseItem: null,
				selectedHouseBaseQuery: '',
				selectedHouseDisplayBase: '',
				selectedHouseContext: {},
				awaitingFlatSelection: false
			} );
		}
		return courierAddressStates.get( block );
	}

	function clearCourierAddressState( block ) {
		if ( block ) {
			courierAddressStates.delete( block );
		}
	}

	function lowerLevelCourierItems( items ) {
		return items.filter( function ( item ) {
			return item && ( item.level === 'flat' || item.level === 'room' || item.level === 'premise' );
		} );
	}

	function ensureTrailingComma( value ) {
		const text = String( value || '' ).replace( /\s+/g, ' ' ).replace( /\s*,\s*$/g, '' ).trim();
		return text ? text + ', ' : '';
	}

	function normalizeHouseBaseForCompare( value ) {
		return String( value || '' ).toLowerCase().replace( /\s+/g, ' ' ).replace( /\s*,\s*/g, ', ' ).trim();
	}

	function startsWithHouseBase( query, base ) {
		const normalizedQuery = normalizeHouseBaseForCompare( query );
		const normalizedBase = normalizeHouseBaseForCompare( base );
		const remainder = normalizedQuery.slice( normalizedBase.length );
		return !! normalizedBase && normalizedQuery.slice( 0, normalizedBase.length ) === normalizedBase && ( remainder === '' || /^[\s,]+/.test( remainder ) );
	}

	function queryMatchesCourierHouseBase( query, state ) {
		return startsWithHouseBase( query, state.selectedHouseBaseQuery ) || startsWithHouseBase( query, state.selectedHouseDisplayBase );
	}

	function courierAddressPayload( item ) {
		return item && item.address ? item.address : {};
	}

	function houseLevelCourierItem( item ) {
		const clone = JSON.parse( JSON.stringify( item || {} ) );
		const data = clone.data || {};
		[
			'flat',
			'flat_type',
			'flat_type_full',
			'room',
			'room_number',
			'room_type',
			'room_type_full',
			'premise',
			'premise_type',
			'premise_type_full'
		].forEach( function ( key ) {
			delete data[ key ];
		} );
		data.flat = '';
		clone.data = data;
		clone.level = 'house';
		if ( clone.address ) {
			clone.address = Object.assign( {}, clone.address, { flat: '', address_2: '', normalized: true, fallback: false, source: 'dadata' } );
		}
		return clone;
	}

	function showCourierFlatHint( block ) {
		const status = block ? block.querySelector( '[data-wdc-courier-address-status]' ) : null;
		if ( ! status ) {
			return;
		}
		status.innerHTML = '<span>Уточните квартиру, помещение или офис (если номера нет - </span><button type="button" class="button-link wdc-order-delivery-courier-address__house-finalize" data-wdc-courier-address-house-finalize>нажмите здесь</button><span>)</span>';
	}

	function finalizeCourierAddress( box, block, item ) {
		const input = block ? block.querySelector( '[data-wdc-courier-address-line]' ) : null;
		const status = block ? block.querySelector( '[data-wdc-courier-address-status]' ) : null;
		const result = block ? block.querySelector( '[data-wdc-courier-address-result]' ) : null;
		const manualButton = block ? block.querySelector( '[data-wdc-use-manual-courier-address]' ) : null;
		const address = courierAddressPayload( item );
		normalizedShippingAddresses.set( box, address );
		if ( input ) {
			input.value = String( address.full_address || item.unrestrictedValue || item.value || item.label || address.address_1 || '' );
		}
		if ( status ) {
			status.textContent = 'Адрес нормализован.';
		}
		if ( result ) {
			result.textContent = String( address.full_address || address.address_1 || '' );
		}
		if ( manualButton ) {
			manualButton.disabled = true;
		}
		clearCourierAddressSuggestions( block );
		clearCourierAddressState( block );
		updateSaveButton( box );
	}

	function requestCourierLowerLevelAfterHouse( box, block, item ) {
		const input = block ? block.querySelector( '[data-wdc-courier-address-line]' ) : null;
		const data = item && item.data ? item.data : {};
		const query = String( item.unrestrictedValue || item.value || item.label || '' );
		const state = courierAddressState( block );
		state.selectedHouseItem = item;
		state.selectedHouseBaseQuery = query;
		state.selectedHouseDisplayBase = String( item.label || query );
		state.selectedHouseContext = {
			selected_level: 'house',
			desired_level: 'flat',
			house_fias_id: String( data.house_fias_id || '' ),
			house_kladr_id: String( data.house_kladr_id || '' ),
			city_fias_id: String( data.city_fias_id || data.settlement_fias_id || '' ),
			city_kladr_id: String( data.city_kladr_id || data.settlement_kladr_id || '' )
		};
		state.awaitingFlatSelection = true;
		if ( input ) {
			input.value = ensureTrailingComma( query );
			input.focus();
		}
		showCourierFlatHint( block );
		requestCourierAddressSuggestions( box, block, 'address_next', query, state.selectedHouseContext )
			.then( function ( items ) {
				const lower = lowerLevelCourierItems( items );
				if ( lower.length ) {
					renderCourierAddressSuggestions( box, block, lower );
					showCourierFlatHint( block );
					return;
				}
				finalizeCourierAddress( box, block, item );
			} )
			.catch( function () {
				finalizeCourierAddress( box, block, item );
			} );
	}

	function chooseCourierAddressSuggestion( button ) {
		const box = closestBox( button );
		const block = button ? button.closest( '[data-wdc-courier-address-block]' ) : null;
		const input = block ? block.querySelector( '[data-wdc-courier-address-line]' ) : null;
		if ( ! box || ! block ) {
			return;
		}
		let item = {};
		try {
			item = JSON.parse( button.dataset.item || '{}' ) || {};
		} catch ( error ) {
			item = {};
		}
		if ( item.level === 'street' ) {
			clearCourierAddressState( block );
			if ( input ) {
				input.value = ensureTrailingComma( item.unrestrictedValue || item.value || item.label || '' );
				input.focus();
			}
			runCourierAddressSuggest( box, block, 'address', input ? input.value : '', {} );
			return;
		}
		if ( item.level === 'house' ) {
			requestCourierLowerLevelAfterHouse( box, block, item );
			return;
		}
		if ( item.level === 'flat' || item.level === 'room' || item.level === 'premise' ) {
			finalizeCourierAddress( box, block, item );
		}
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
		if ( payload.requires_pickup_point ) {
			prefillCurrentPickupIfAvailable( box );
			normalizedShippingAddresses.delete( box );
		} else {
			selectedPickupPoints.delete( box );
			normalizedShippingAddresses.delete( box );
		}
		updatePickupSelectors( box );
		updateCourierAddressBlocks( box );
	}

	function prefillCurrentPickupIfAvailable( box ) {
		if ( selectedPickupPoints.has( box ) || locationChanged( box ) ) {
			return;
		}
		const pickup = normalizePickupPoint( jsonScriptPayload( box, '[data-wdc-order-delivery-current-pickup]' ) );
		if ( pickup.point_code || pickup.point_address ) {
			selectedPickupPoints.set( box, pickup );
		}
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
		const location = jsonScriptPayload( box, '[data-wdc-order-delivery-current-location]' );
		selectedLocations.set( box, location );
		updateLocationSummary( box, location );
	}

	function locationChanged( box ) {
		const current = jsonScriptPayload( box, '[data-wdc-order-delivery-current-location]' );
		const selected = selectedLocations.get( box ) || {};
		return locationSignature( current ) !== locationSignature( selected );
	}

	function locationSignature( location ) {
		if ( ! location ) {
			return '';
		}
		return [
			location.location_id || location.id || '',
			location.fias_id || '',
			location.gar_object_id || location.gar_id || '',
			location.postal_code || location.postcode || '',
			locationLabel( location )
		].map( function ( value ) {
			return String( value || '' ).trim().toLowerCase();
		} ).join( '|' );
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
		return String( location.display_name || location.label || location.option_label || location.city_value || location.city_name || location.place_name || '' );
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

	function currentShippingAddress( box ) {
		return jsonScriptPayload( box, '[data-wdc-order-delivery-current-shipping-address]' );
	}

	function shippingAddressLine( address ) {
		return [
			address.postcode || '',
			address.region || '',
			address.city || '',
			address.address_1 || '',
			address.address_2 || ''
		].map( function ( part ) {
			return String( part || '' ).trim();
		} ).filter( Boolean ).join( ', ' );
	}

	function pickupPointLabel( point ) {
		return String( point.point_address || point.address || point.point_name || point.point_code || '' );
	}

	function isCdekPickupPoint( point ) {
		return 'cdek' === String( point && ( point.carrier_key || point.carrier ) || '' ).toLowerCase();
	}

	function pickupPointDisplayCode( point ) {
		if ( isCdekPickupPoint( point ) ) {
			return String( point.point_code || point.cdek_code || '' );
		}
		return String( point.point_postcode || point.postcode || point.postal_code || point.point_code || '' );
	}

	function pickupPointTitle( point ) {
		if ( isCdekPickupPoint( point ) ) {
			return 'POSTAMAT' === String( point.point_type || point.cdek_type || '' ).toUpperCase() ? 'Постамат СДЭК' : 'Пункт выдачи СДЭК';
		}
		return String( point.point_type || '' ).toUpperCase() === 'APS' ? 'Почтомат Почты России' : 'Отделение Почты России';
	}

	function pickupPointStorageNotice( point ) {
		const notice = meaningfulText( point && point.storage_notice );
		if ( notice ) {
			return notice;
		}
		return isCdekPickupPoint( point ) && 'POSTAMAT' === String( point.point_type || point.cdek_type || '' ).toUpperCase() ? 'Срок хранения 3 дня' : '';
	}

	function meaningfulText( value ) {
		if ( value === null || value === undefined || Array.isArray( value ) || typeof value === 'object' ) {
			return '';
		}
		const text = String( value ).trim();
		if ( ! text ) {
			return '';
		}
		const normalized = text.replace( ',', '.' );
		if ( normalized !== '' && ! Number.isNaN( Number( normalized ) ) && Number( normalized ) === 0 ) {
			return '';
		}
		return text;
	}

	function firstMeaningfulText() {
		for ( let i = 0; i < arguments.length; i++ ) {
			const text = meaningfulText( arguments[ i ] );
			if ( text ) {
				return text;
			}
		}
		return '';
	}

	function saveDelivery( button ) {
		const box = closestBox( button );
		const rate = box ? selectedRates.get( box ) : null;
		if ( ! box || ! rate || activeSaveRequests.has( box ) ) {
			return;
		}
		const form = new FormData();
		form.append( 'action', config.saveAction || 'wdc_order_delivery_recalculate_save' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'order_id', orderId( box ) );
		form.append( 'selected_location', JSON.stringify( selectedLocations.get( box ) || {} ) );
		form.append( 'selected_rate', JSON.stringify( rate ) );
		form.append( 'selected_tariff', JSON.stringify( rate.selected_tariff || {} ) );
		form.append( 'selected_pickup_point', JSON.stringify( selectedPickupPoints.get( box ) || {} ) );
		form.append( 'normalized_shipping_address', JSON.stringify( rate.requires_pickup_point ? {} : ( normalizedShippingAddresses.get( box ) || {} ) ) );
		activeSaveRequests.add( box );
		setLoading( button, true );
		setStatus( box, 'Сохраняем новый вариант доставки...', 'loading' );
		updateSaveButton( box );
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
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось сохранить доставку.' );
				}
				setStatus( box, payload.data && payload.data.message ? payload.data.message : 'Новый вариант доставки сохранен.', 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 250 );
			} )
			.catch( function ( error ) {
				setStatus( box, error && error.message ? error.message : 'Не удалось сохранить доставку.', 'error' );
			} )
			.finally( function () {
				activeSaveRequests.delete( box );
				setLoading( button, false );
				updateSaveButton( box );
			} );
	}

	function requestCourierAddressSuggestions( box, block, stage, query, context ) {
		const form = new FormData();
		form.append( 'action', config.addressSuggestAction || 'wdc_order_delivery_recalculate_address_suggest' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'order_id', orderId( box ) );
		form.append( 'selected_location', JSON.stringify( selectedLocations.get( box ) || {} ) );
		form.append( 'stage', stage );
		form.append( 'query', query );
		form.append( 'context', JSON.stringify( context || {} ) );
		return window.fetch( config.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Подсказки адреса недоступны.' );
				}
				return Array.isArray( payload.data && payload.data.items ) ? payload.data.items : [];
			} );
	}

	function runCourierAddressSuggest( box, block, stage, query, context ) {
		const status = block ? block.querySelector( '[data-wdc-courier-address-status]' ) : null;
		const result = block ? block.querySelector( '[data-wdc-courier-address-result]' ) : null;
		const manualButton = block ? block.querySelector( '[data-wdc-use-manual-courier-address]' ) : null;
		if ( ! box || ! block || String( query || '' ).trim() === '' ) {
			return;
		}
		normalizedShippingAddresses.delete( box );
		if ( result ) {
			result.textContent = '';
		}
		if ( status ) {
			status.textContent = 'Ищем подсказки адреса...';
		}
		requestCourierAddressSuggestions( box, block, stage, query, context )
			.then( function ( items ) {
				const state = courierAddressState( block );
				if ( state.awaitingFlatSelection ) {
					const lower = lowerLevelCourierItems( items );
					if ( lower.length ) {
						renderCourierAddressSuggestions( box, block, lower );
						showCourierFlatHint( block );
						return;
					}
					clearCourierAddressSuggestions( block );
					showCourierFlatHint( block );
					return;
				}
				renderCourierAddressSuggestions( box, block, items );
				if ( status ) {
					status.textContent = items.length ? 'Выберите адрес из подсказок.' : 'Адрес не удалось нормализовать. Можно использовать введенный адрес вручную.';
				}
				if ( manualButton ) {
					manualButton.disabled = items.length ? true : String( query || '' ).trim() === '';
				}
			} )
			.catch( function ( error ) {
				clearCourierAddressSuggestions( block );
				clearCourierAddressState( block );
				if ( status ) {
					status.textContent = error && error.message ? error.message : 'Адрес не удалось нормализовать. Можно использовать введенный адрес вручную.';
				}
				if ( manualButton ) {
					manualButton.disabled = String( query || '' ).trim() === '';
				}
				updateSaveButton( box );
			} );
	}

	function geocodeAddress( box, value ) {
		const form = new FormData();
		form.append( 'action', config.geocodeAddressAction || 'wdc_order_delivery_recalculate_geocode_address' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'order_id', orderId( box ) );
		form.append( 'selected_location', JSON.stringify( selectedLocations.get( box ) || {} ) );
		form.append( 'address_line', value );
		return window.fetch( config.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data || payload.data.lat === null || payload.data.lng === null ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Адрес не найден или геокодинг недоступен.' );
				}
			return {
				lat: parseFloat( payload.data.lat ),
					lng: parseFloat( payload.data.lng ),
					label: String( payload.data.formatted_address || payload.data.address || value )
				};
			} );
	}

	function useManualCourierAddress( button ) {
		const box = closestBox( button );
		const block = button ? button.closest( '[data-wdc-courier-address-block]' ) : null;
		const input = block ? block.querySelector( '[data-wdc-courier-address-line]' ) : null;
		const status = block ? block.querySelector( '[data-wdc-courier-address-status]' ) : null;
		const result = block ? block.querySelector( '[data-wdc-courier-address-result]' ) : null;
		const value = input ? String( input.value || '' ).trim() : '';
		if ( ! box || ! value ) {
			return;
		}
		const location = selectedLocations.get( box ) || {};
		const currentAddress = currentShippingAddress( box );
		const payload = {
			country: String( location.country_code || currentAddress.country || 'RU' ),
			region: String( location.region_name || location.state_value || currentAddress.region || '' ),
			city: String( location.city_value || location.city_name || location.display_name || currentAddress.city || '' ),
			postcode: String( location.postal_code || location.postcode || currentAddress.postcode || '' ),
			street: value,
			house: '',
			flat: '',
			address_1: value,
			address_2: '',
			full_address: value,
			fias_id: String( location.fias_id || '' ),
			gar_id: String( location.gar_object_id || location.gar_id || '' ),
			normalized: false,
			fallback: true,
			source: 'admin_manual',
			message: ''
		};
		normalizedShippingAddresses.set( box, payload );
		clearCourierAddressState( block );
		button.disabled = true;
		if ( status ) {
			status.textContent = 'Адрес будет сохранен без нормализации.';
		}
		if ( result ) {
			result.textContent = value;
		}
		updateSaveButton( box );
	}

	function normalizePickupPoint( point ) {
		point = point || {};
		const lat = point.lat !== null && point.lat !== undefined ? parseFloat( point.lat ) : null;
		const lng = point.lng !== null && point.lng !== undefined ? parseFloat( point.lng ) : null;
		const postcode = String( point.point_postcode || point.postcode || point.postal_code || '' );
		const address = String( point.point_address || point.address || '' );
		return {
			id: String( point.id || point.point_code || postcode || address || '' ),
			carrier_key: String( point.carrier_key || point.carrier || '' ),
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
			work_time: firstMeaningfulText( point.work_time ),
			description: firstMeaningfulText( point.description, point.point_comment, point.cdek_note ),
			storage_notice: meaningfulText( point.storage_notice ),
			raw_sanitized: point.raw_sanitized || point.raw || {},
			cdek_code: String( point.cdek_code || '' ),
			cdek_uuid: String( point.cdek_uuid || '' ),
			cdek_type: String( point.cdek_type || '' ),
			cdek_owner_code: String( point.cdek_owner_code || '' ),
			cdek_nearest_station: String( point.cdek_nearest_station || '' ),
			cdek_note: String( point.cdek_note || '' ),
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
		let previewPoint = selectedPickupPoints.get( box ) || null;
		let searchMarker = null;

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
			const rows = [
				'<div class="wdc-pickup-popup">',
				'<h3 class="wdc-pickup-popup__title">' + escapeHtml( [ pickupPointTitle( point ), pickupPointDisplayCode( point ) ].filter( Boolean ).join( ' ' ) ) + '</h3>',
				'<div class="wdc-pickup-popup__section"><strong>' + escapeHtml( isCdekPickupPoint( point ) ? 'Код:' : 'Индекс:' ) + '</strong><span>' + escapeHtml( pickupPointDisplayCode( point ) ) + '</span></div>',
				'<div class="wdc-pickup-popup__section"><strong>Адрес:</strong><span>' + escapeHtml( pickupPointLabel( point ) ) + '</span></div>'
			];
			if ( point.description ) {
				rows.push( '<div class="wdc-pickup-popup__section"><strong>Описание:</strong><span>' + escapeHtml( point.description ) + '</span></div>' );
			}
			if ( pickupPointStorageNotice( point ) ) {
				rows.push( '<div class="wdc-pickup-popup__storage">' + escapeHtml( pickupPointStorageNotice( point ) ) + '</div>' );
			}
			rows.push( '<button type="button" class="button button-primary wdc-pickup-popup__select" data-wdc-pickup-popup-select data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '">Выбрать этот ПВЗ</button>' );
			rows.push( '</div>' );
			return rows.join( '' );
		}

		function preview( point ) {
			previewPoint = point;
			if ( selected ) {
				selected.innerHTML = [
					'<div class="wdc-order-delivery-pickup-picker__selected-grid">',
					'<span><strong>' + escapeHtml( isCdekPickupPoint( point ) ? 'Код' : 'Индекс' ) + '</strong>' + escapeHtml( pickupPointDisplayCode( point ) ) + '</span>',
					'<span><strong>Тип</strong>' + escapeHtml( pickupPointTitle( point ) ) + '</span>',
					'<span><strong>Адрес</strong>' + escapeHtml( pickupPointLabel( point ) ) + '</span>',
					point.description ? '<span><strong>Описание</strong>' + escapeHtml( point.description ) + '</span>' : '',
					pickupPointStorageNotice( point ) ? '<span class="wdc-pickup-popup__storage"><strong>Срок хранения</strong>' + escapeHtml( pickupPointStorageNotice( point ) ) + '</span>' : '',
					'<button type="button" class="button button-primary" data-wdc-pickup-picker-choose data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '">Выбрать этот ПВЗ</button>',
					'</div>'
				].join( '' );
			}
			if ( provider && provider.setActivePoint ) {
				provider.setActivePoint( pointId( point ) );
			}
			if ( provider && provider.focusPoint ) {
				provider.focusPoint( point );
			}
			if ( provider && provider.openPointPopup ) {
				provider.openPointPopup( point, renderPopup( point ), { forceReopen: true } );
			}
			renderPickupPoints();
			scrollActivePickupRow();
		}

		function choosePoint( point ) {
			selectedPickupPoints.set( box, point );
			normalizedShippingAddresses.delete( box );
			updatePickupSelectors( box );
			close();
		}

		function runSearch( mode ) {
			mode = mode === 'location' ? 'location' : 'search';
			const value = mode === 'location' ? '' : String( query.value || '' ).trim();
			if ( 'search' === mode ) {
				if ( ! value ) {
					status.textContent = 'Введите адрес для поиска.';
					return;
				}
				if ( 'cdek' === String( rate.carrier_key || rate.service_key || '' ) ) {
					status.textContent = 'Ищем ПВЗ СДЭК...';
					searchMarker = null;
					loadPickupPointsForLocation( 'search', value )
						.then( function () {
							renderSearchResults( 'search', value, '' );
						} )
						.catch( function () {
							status.textContent = 'Не удалось загрузить пункты выдачи СДЭК. Попробуйте позже.';
						} );
					return;
				}
				status.textContent = 'Ищем адрес через DaData...';
				geocodeAddress( box, value )
					.then( function ( marker ) {
						searchMarker = marker;
						if ( points.length ) {
							renderSearchResults( 'address', value, 'Адрес найден через DaData. Показаны ПВЗ выбранного населенного пункта.' );
							return;
						}
						return loadPickupPointsForLocation().then( function () {
							renderSearchResults( 'address', value, 'Адрес найден через DaData. Показаны ПВЗ выбранного населенного пункта.' );
						} );
					} )
					.catch( function ( error ) {
						searchMarker = null;
						const message = error && error.message ? error.message : 'Адрес не найден или геокодинг недоступен.';
						if ( points.length ) {
							renderSearchResults( 'address', value, message );
							return;
						}
						loadPickupPointsForLocation()
							.then( function () {
								renderSearchResults( 'address', value, message );
							} )
							.catch( function () {
								status.textContent = message;
							} );
					} );
				return;
			}

			searchMarker = null;
			status.textContent = 'Загружаем ПВЗ выбранного населенного пункта...';
			list.innerHTML = '';
			loadPickupPointsForLocation()
				.then( function () {
					renderSearchResults( 'location', value, '' );
				} )
				.catch( function ( error ) {
					status.textContent = error && error.message ? error.message : 'Не удалось найти ПВЗ.';
				} );
		}

		function loadPickupPointsForLocation( modeOverride, queryOverride ) {
			const form = new FormData();
			form.append( 'action', config.pickupSearchAction || 'wdc_order_delivery_recalculate_pickup_search' );
			form.append( 'nonce', config.nonce || '' );
			form.append( 'order_id', orderId( box ) );
			form.append( 'selected_location', JSON.stringify( location ) );
			form.append( 'selected_rate', JSON.stringify( rate ) );
			form.append( 'mode', modeOverride || 'location' );
			form.append( 'query', queryOverride || '' );
			form.append( 'limit', '300' );
			return window.fetch( config.ajaxUrl || window.ajaxurl || '', {
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
					previewPoint = matchSelectedPickup( points, previewPoint || selectedPickupPoints.get( box ) );
				} );
		}

		function renderSearchResults( mode, value, geocodeMessage ) {
					const withCoordinates = points.filter( function ( point ) {
						return point.lat !== null && point.lng !== null;
					} ).length;
					if ( points.length ) {
						status.textContent = 'address' === mode && geocodeMessage
							? geocodeMessage + ' ПВЗ: ' + points.length + '.'
							: 'Найдено ПВЗ: ' + points.length + ( searchMarker ? '. Булавка показывает найденный адрес.' : '' );
						if ( withCoordinates < points.length ) {
							status.textContent += ' Часть ПВЗ без координат доступна только в списке.';
						}
					} else {
						status.textContent = 'ПВЗ для выбранного населенного пункта не найдены. Попробуйте другой населенный пункт.';
						if ( 'address' === mode && geocodeMessage ) {
							status.textContent = geocodeMessage + ' ' + status.textContent;
						}
					}
					if ( selected ) {
						selected.textContent = 'Выберите ПВЗ на карте или в списке.';
					}
					if ( provider && provider.renderMarkers ) {
						provider.renderMarkers( points, { activePointId: previewPoint ? pointId( previewPoint ) : null, searchMarker: searchMarker } );
						if ( searchMarker && provider.setCenter ) {
							provider.setCenter( searchMarker.lat, searchMarker.lng, 15 );
						} else if ( previewPoint && provider.focusPoint ) {
							provider.focusPoint( previewPoint );
						} else if ( provider.fitToMarkers ) {
							provider.fitToMarkers();
						}
					}
					renderPickupPoints();
					if ( previewPoint && ! searchMarker ) {
						preview( previewPoint );
					} else if ( 'search' === mode && value && ! searchMarker ) {
						status.textContent += ' ' + ( geocodeMessage || 'Геокодинг адреса недоступен, выберите ПВЗ из списка.' );
					}
		}

		function renderPickupPoints() {
			if ( ! points.length ) {
				list.innerHTML = '<p class="description">ПВЗ не найдены.</p>';
				return;
			}
			list.innerHTML = [
				'<table class="widefat striped wdc-order-delivery-pickup-picker__table"><thead><tr><th>Код/индекс</th><th>Тип</th><th>Адрес</th><th>Выбрать</th></tr></thead><tbody>',
				points.map( function ( point, index ) {
					const active = previewPoint && pointId( previewPoint ) === pointId( point ) ? ' class="is-active"' : '';
					return '<tr data-wdc-pickup-picker-row data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '" data-index="' + escapeAttribute( String( index ) ) + '"' + active + '><td>' + escapeHtml( pickupPointDisplayCode( point ) ) + '</td><td>' + escapeHtml( pickupPointTitle( point ) ) + ( pickupPointStorageNotice( point ) ? '<br><strong class="wdc-pickup-popup__storage">' + escapeHtml( pickupPointStorageNotice( point ) ) + '</strong>' : '' ) + '</td><td>' + escapeHtml( pickupPointLabel( point ) ) + ( point.description ? '<br><span class="description">' + escapeHtml( point.description ) + '</span>' : '' ) + '</td><td><button type="button" class="button" data-wdc-pickup-picker-choose data-wdc-point-id="' + escapeAttribute( pointId( point ) ) + '">Выбрать</button></td></tr>';
				} ).join( '' ),
				'</tbody></table>'
			].join( '' );
		}

		function matchSelectedPickup( list, pickup ) {
			const id = pointId( pickup );
			if ( ! id ) {
				return null;
			}
			return list.find( function ( point ) {
				return pointId( point ) === id || ( pickup.point_code && point.point_code === pickup.point_code );
			} ) || normalizePickupPoint( pickup );
		}

		function scrollActivePickupRow() {
			const active = list.querySelector( '.is-active[data-wdc-pickup-picker-row]' );
			if ( active && active.scrollIntoView ) {
				active.scrollIntoView( { block: 'nearest' } );
			}
		}

		root.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-wdc-pickup-picker-close]' ) ) {
				close();
				return;
			}
			if ( event.target.closest( '[data-wdc-pickup-picker-search]' ) ) {
				runSearch( 'search' );
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
				runSearch( 'search' );
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
		query.value = String( location.display_name || location.city_value || location.city_name || '' );
		query.focus();
		runSearch( 'location' );
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
				requestPreview( box, box.querySelector( '[data-wdc-order-delivery-modal-preview]' ) );
			}
			return;
		}

		const pickupButton = event.target && event.target.closest( '[data-wdc-open-pickup-picker]' );
		if ( pickupButton ) {
			event.preventDefault();
			openPickupPicker( closestBox( pickupButton ) );
			return;
		}

		const courierSuggestionButton = event.target && event.target.closest( '[data-wdc-courier-address-suggestion]' );
		if ( courierSuggestionButton ) {
			event.preventDefault();
			chooseCourierAddressSuggestion( courierSuggestionButton );
			return;
		}

		const courierHouseFinalizeButton = event.target && event.target.closest( '[data-wdc-courier-address-house-finalize]' );
		if ( courierHouseFinalizeButton ) {
			event.preventDefault();
			const box = closestBox( courierHouseFinalizeButton );
			const block = courierHouseFinalizeButton.closest( '[data-wdc-courier-address-block]' );
			const state = block ? courierAddressState( block ) : null;
			if ( box && block && state && state.selectedHouseItem ) {
				const input = block.querySelector( '[data-wdc-courier-address-line]' );
				if ( input ) {
					input.value = String( state.selectedHouseBaseQuery || state.selectedHouseDisplayBase || input.value || '' ).replace( /\s*,\s*$/g, '' ).trim();
				}
				finalizeCourierAddress( box, block, houseLevelCourierItem( state.selectedHouseItem ) );
			}
			return;
		}

		const manualCourierButton = event.target && event.target.closest( '[data-wdc-use-manual-courier-address]' );
		if ( manualCourierButton ) {
			event.preventDefault();
			useManualCourierAddress( manualCourierButton );
			return;
		}

		const saveButton = event.target && event.target.closest( '[data-wdc-order-delivery-save]' );
		if ( saveButton ) {
			event.preventDefault();
			saveDelivery( saveButton );
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
		if ( input && input.matches && input.matches( '[data-wdc-courier-address-line]' ) ) {
			const box = closestBox( input );
			if ( box ) {
				normalizedShippingAddresses.delete( box );
				const block = input.closest( '[data-wdc-courier-address-block]' );
				const status = block && block.querySelector( '[data-wdc-courier-address-status]' );
				const result = block && block.querySelector( '[data-wdc-courier-address-result]' );
				const manualButton = block && block.querySelector( '[data-wdc-use-manual-courier-address]' );
				let stage = 'address';
				let context = {};
				let query = String( input.value || '' );
				let state = block ? courierAddressState( block ) : null;
				if ( status ) {
					status.textContent = 'Проверьте адрес перед сохранением.';
				}
				if ( result ) {
					result.textContent = '';
				}
				clearCourierAddressSuggestions( block );
				if ( manualButton ) {
					manualButton.disabled = true;
				}
				if ( state && state.awaitingFlatSelection ) {
					if ( queryMatchesCourierHouseBase( query, state ) ) {
						stage = 'address_next';
						context = state.selectedHouseContext;
					} else {
						clearCourierAddressState( block );
						state = courierAddressState( block );
					}
				}
				if ( courierAddressTimers.has( input ) ) {
					window.clearTimeout( courierAddressTimers.get( input ) );
				}
				courierAddressTimers.set( input, window.setTimeout( function () {
					runCourierAddressSuggest( box, block, stage, query, context );
				}, 300 ) );
				updateSaveButton( box );
			}
			return;
		}
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
