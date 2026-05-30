(function (window, document) {
	'use strict';

	var labels = (window.wdcPickupCheckout && window.wdcPickupCheckout.labels) || {};
	var activeMethod = '';
	var currentContext = (window.wdcPickupCheckout && window.wdcPickupCheckout.currentContext) || {};
	var prefetchTimer = 0;
	var prefetchController = null;
	var prefetchCache = null;
	var suppressNextDestinationReset = false;

	function init(container) {
		if (container.dataset.wdcPickupReady) {
			return;
		}
		container.dataset.wdcPickupReady = '1';
		var method = container.getAttribute('data-shipping-method-id') || (window.wdcPickupCheckout && window.wdcPickupCheckout.shippingMethodId) || '';
		var openButton = container.querySelector('[data-wdc-pickup-open]');
		activeMethod = currentShippingMethod() || method;
		toggleForMethod(container);
		if (openButton) {
			openButton.addEventListener('click', function () { openModal(container, activeMethod || method); });
		}
	}

	function openModal(container, method) {
		var baseContext = initialContext();
		var contextPromise = baseContext.query && !validCoordinate(baseContext.lat, baseContext.lng)
			? refreshCheckoutContextOnce(700).then(function (freshContext) { return freshContext || baseContext; })
			: Promise.resolve(baseContext);

		contextPromise.then(function (resolvedContext) {
			var modal = window.WDCPickupModal.create(labels);
			var confirmButton = modal.root.querySelector('[data-wdc-confirm]');
			var search = modal.root.querySelector('[data-wdc-search]');
			var searchSubmit = modal.root.querySelector('[data-wdc-search-submit]');
			var context = withPrefetch(resolvedContext);
			debug('openModal context', context);
			var map = window.WDCPickupMap.create(modal.root.querySelector('[data-wdc-map]'), modal.root.querySelector('[data-wdc-card]'), confirmButton, labels, context);
			var savingPoint = false;

			function close() {
				map.destroy();
				modal.destroy();
			}

			function commitPoint(point) {
				if (!point || savingPoint) {
					return Promise.resolve(false);
				}
				savingPoint = true;
				confirmButton.disabled = true;
				return window.WDCPickupApi.save(point.id, method).then(function (response) {
					applySelection(container, response.pickup_point || {});
					close();
					triggerCheckoutUpdate();
					return true;
				}).catch(function () {
					savingPoint = false;
					confirmButton.disabled = false;
					return false;
				});
			}

			function savePoint(point) {
				point = point || map.selected();
				if (!point || savingPoint) {
					return;
				}
				var checkoutContext = contextFromFields();
				if (pointMatchesDestinationQuick(point, checkoutContext) || !window.WDCPickupApi.resolveLocation) {
					commitPoint(point);
					return;
				}
				savingPoint = true;
				confirmButton.disabled = true;
				window.WDCPickupApi.resolveLocation(pointPayload(point), checkoutContext).then(function (response) {
					if (!response || !response.requires_location_change || !response.location) {
						savingPoint = false;
						confirmButton.disabled = false;
						commitPoint(point);
						return;
					}
					showLocationChangeConfirm(modal.root, response.location).then(function (confirmed) {
						if (!confirmed) {
							savingPoint = false;
							confirmButton.disabled = false;
							return;
						}
						applyConfirmedPickupLocationChange(response.location);
						savingPoint = false;
						commitPoint(point);
					});
				}).catch(function () {
					savingPoint = false;
					confirmButton.disabled = false;
					commitPoint(point);
				});
			}

			modal.root.addEventListener('wdc:close', close);
			function runAddressSearch() {
				if (search.value.trim()) {
					map.search(search.value.trim());
				}
			}
			search.addEventListener('change', runAddressSearch);
			search.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					runAddressSearch();
				}
			});
			search.addEventListener('input', function () {
				if (search.dataset.wdcPostcodeOnly) {
					search.value = search.value.replace(/\D+/g, '').slice(0, 6);
				}
			});
			if (searchSubmit) {
				searchSubmit.addEventListener('click', runAddressSearch);
			}
			confirmButton.addEventListener('wdc:point-selected', function (event) {
				savePoint(event.detail || map.selected());
			});
			confirmButton.addEventListener('click', function () {
				savePoint(map.selected());
			});
		});
	}

	function applySelection(container, point) {
		var snapshot = point.snapshot || {};
		var selectedPoint = normalizeSelectedPoint(point);
		container.querySelector('[data-wdc-pickup-point-id]').value = point.id || '';
		container.querySelector('[data-wdc-pickup-point-code]').value = point.point_code || '';
		container.querySelector('[data-wdc-pickup-address]').textContent = point.address || '';
		container.querySelector('[data-wdc-pickup-postcode]').textContent = point.postcode || '';
		container.querySelector('[data-wdc-pickup-work-time]').textContent = snapshot.work_time || '';
		container.querySelector('[data-wdc-pickup-selection]').hidden = !point.point_code;
		container.querySelector('[data-wdc-pickup-open]').textContent = point.point_code ? labels.change : labels.choose;
		if (!window.wdcPickupCheckout) {
			window.wdcPickupCheckout = {};
		}
		window.wdcPickupCheckout.selectedPickupPoint = selectedPoint;
		if (window.wdcPickupCheckout.initialContext) {
			window.wdcPickupCheckout.initialContext.selectedPoint = selectedPoint;
		}
	}

	function resetSelection() {
		invalidatePrefetch();
		resetPickupSelectionOnServer();
		clearPickupSelectionUi();
	}

	function clearPickupSelectionUi() {
		document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(function (container) {
			applySelection(container, {});
		});
	}

	function resetPickupSelectionOnServer() {
		return window.WDCPickupApi.reset().catch(function () {});
	}

	function currentShippingMethod() {
		var checked = document.querySelector('input[name^="shipping_method"]:checked');
		return checked ? checked.value.replace(/^wdc_platform:/, '') : '';
	}

	function initialContext() {
		var config = (window.wdcPickupCheckout && window.wdcPickupCheckout.initialContext) || {};
		var configContext = {
			lat: config.lat || '',
			lng: config.lng || '',
			query: config.query || '',
			selectedPoint: config.selectedPoint || (window.wdcPickupCheckout && window.wdcPickupCheckout.selectedPickupPoint) || null
		};
		var fieldContext = contextFromFields();
		debug('contextFromFields', fieldContext);
		debug('currentContext', currentContext);
		if (fieldContext.countryBlocked) {
			return {};
		}
		var runtimeContext = sameDestination(fieldContext, currentContext) ? currentContext : {};
		var localizedContext = sameDestination(fieldContext, configContext) ? configContext : {};
		var latSource = validCoordinate(fieldContext.lat, fieldContext.lng) ? 'fields' : (validCoordinate(runtimeContext.lat, runtimeContext.lng) ? 'currentContext' : (validCoordinate(localizedContext.lat, localizedContext.lng) ? 'localized' : 'none'));
		var result = {
			lat: fieldContext.lat || runtimeContext.lat || localizedContext.lat,
			lng: fieldContext.lng || runtimeContext.lng || localizedContext.lng,
			query: fieldContext.query || runtimeContext.query || localizedContext.query,
			postcode: fieldContext.postcode || runtimeContext.postcode || localizedContext.postcode || '',
			display_name: fieldContext.display_name || runtimeContext.display_name || localizedContext.display_name || '',
			location_id: fieldContext.location_id || runtimeContext.location_id || localizedContext.location_id || '',
			fias_id: fieldContext.fias_id || runtimeContext.fias_id || localizedContext.fias_id || '',
			city_name: fieldContext.city_name || runtimeContext.city_name || localizedContext.city_name || '',
			region_name: fieldContext.region_name || runtimeContext.region_name || localizedContext.region_name || '',
			country_code: fieldContext.country_code || runtimeContext.country_code || localizedContext.country_code || 'RU',
			selectedPoint: localizedContext.selectedPoint || runtimeContext.selectedPoint || null
		};
		debug('sameDestination field/current', sameDestination(fieldContext, currentContext));
		debug('chosen lat/lng source', latSource);
		debug('initialContext selected source', fieldContext.query ? 'fields' : (runtimeContext.query ? 'current' : (localizedContext.query ? 'localized' : 'fallback')), result);
		return result;
	}

	function contextFromFields() {
		var country = fieldValue('shipping_country') || fieldValue('billing_country');
		if (country && country.toUpperCase() !== 'RU') {
			return { countryBlocked: true };
		}
		var hiddenLat = fieldValue('wdc_platform_location_lat');
		var hiddenLng = fieldValue('wdc_platform_location_lng');
		var hiddenPostcode = fieldValue('wdc_platform_location_postcode');
		var hiddenDisplay = fieldValue('wdc_platform_location_display_name');
		var hiddenRegion = fieldValue('wdc_platform_location_region_name');
		var hiddenLocationId = fieldValue('wdc_platform_location_id');
		var hiddenFiasId = fieldValue('wdc_platform_location_fias_id');
		var hiddenCity = fieldValue('wdc_platform_location_city_name') || fieldValue('wdc_platform_location_place_name');
		var visiblePostcode = fieldValue('shipping_postcode') || fieldValue('billing_postcode');
		var visibleCity = fieldValue('shipping_city') || fieldValue('billing_city');
		var visibleDestinationChanged = !!(visibleCity && hiddenDisplay && normalizeText(visibleCity) !== normalizeText(hiddenDisplay));
		var postcode = visibleDestinationChanged ? (visiblePostcode || hiddenPostcode) : (hiddenPostcode || visiblePostcode);
		var city = visibleDestinationChanged ? visibleCity : (hiddenDisplay || visibleCity);
		var query = [postcode, city || hiddenRegion].filter(Boolean).join(' ').trim();
		var context = query ? { query: query } : {};
		context.postcode = postcode;
		context.display_name = city || hiddenRegion;
		context.country_code = country || 'RU';
		context.location_id = hiddenLocationId;
		context.fias_id = hiddenFiasId;
		context.city_name = visibleDestinationChanged ? visibleCity : (hiddenCity || hiddenDisplay || visibleCity);
		context.region_name = hiddenRegion;
		if (!visibleDestinationChanged && validCoordinate(hiddenLat, hiddenLng)) {
			context.lat = hiddenLat;
			context.lng = hiddenLng;
		}
		return context;
	}

	function debug() {
		if (window.wdcPickupCheckout && window.wdcPickupCheckout.debug && window.console && window.console.log) {
			window.console.log.apply(window.console, ['wdc pickup:'].concat(Array.prototype.slice.call(arguments)));
		}
	}

	function fieldValue(name) {
		var field = document.querySelector('[name="' + name + '"]');
		return field ? String(field.value || '').trim() : '';
	}

	function validCoordinate(lat, lng) {
		var parsedLat = parseFloat(lat);
		var parsedLng = parseFloat(lng);
		if (isNaN(parsedLat) || isNaN(parsedLng)) {
			return false;
		}
		if (Math.abs(parsedLat) < 0.000001 && Math.abs(parsedLng) < 0.000001) {
			return false;
		}
		return parsedLat >= -90 && parsedLat <= 90 && parsedLng >= -180 && parsedLng <= 180;
	}

	function contextMatches(fieldContext, cachedContext) {
		return sameDestination(fieldContext, cachedContext);
	}

	function sameDestination(a, b) {
		if (!a || !b) {
			return false;
		}
		var aFias = normalizeGuid(a.fias_id || '');
		var bFias = normalizeGuid(b.fias_id || '');
		if (aFias && bFias) {
			return aFias === bFias;
		}
		var aPostcode = normalizeText(a.postcode || '');
		var bPostcode = normalizeText(b.postcode || '');
		if (aPostcode && bPostcode && aPostcode === bPostcode) {
			return true;
		}
		var aName = normalizedDestinationName(a);
		var bName = normalizedDestinationName(b);
		if (!aName || !bName) {
			return false;
		}
		return aName === bName || containsDestinationName(aName, bName) || containsDestinationName(bName, aName);
	}

	function normalizedDestinationName(context) {
		return normalizeText(context.display_name || context.query || '');
	}

	function containsDestinationName(haystack, needle) {
		return !!(needle && haystack && (' ' + haystack.replace(/[,;]/g, ' ') + ' ').indexOf(' ' + needle + ' ') !== -1);
	}

	function destinationFingerprint(context) {
		if (!context) {
			return '';
		}
		return [
			context.postcode || '',
			context.display_name || '',
			context.location_id || '',
			context.query || ''
		].map(normalizeText).filter(Boolean).join('|');
	}

	function cacheKey(context) {
		if (!context || context.countryBlocked) {
			return '';
		}
		return [
			coordinateKey(context.lat),
			coordinateKey(context.lng),
			normalizeText(context.postcode || ''),
			normalizeText(context.display_name || ''),
			normalizeText(context.location_id || ''),
			normalizeText(context.query || '')
		].join('|');
	}

	function coordinateKey(value) {
		var parsed = parseFloat(value);
		return isNaN(parsed) ? '' : parsed.toFixed(5);
	}

	function normalizeText(value) {
		return String(value || '').trim().toLowerCase();
	}

	function updateCurrentContext(context) {
		currentContext = Object.assign({}, context || {});
		if (!window.wdcPickupCheckout) {
			window.wdcPickupCheckout = {};
		}
		window.wdcPickupCheckout.currentContext = currentContext;
	}

	function applyContextToHidden(context) {
		if (!context) {
			return;
		}
		setHiddenValue('wdc_platform_location_lat', context.lat || '');
		setHiddenValue('wdc_platform_location_lng', context.lng || '');
		setHiddenValue('wdc_platform_location_postcode', context.postcode || '');
		setHiddenValue('wdc_platform_location_display_name', context.display_name || '');
		setHiddenValue('wdc_platform_location_region_name', context.region_name || '');
		setHiddenValue('wdc_platform_location_id', context.location_id || '');
		setHiddenValue('wdc_platform_location_fias_id', context.fias_id || '');
		setHiddenValue('wdc_platform_location_city_name', context.city_name || context.display_name || '');
	}

	function setHiddenValue(name, value) {
		var field = document.querySelector('[name="' + name + '"]');
		if (!field) {
			field = document.createElement('input');
			field.type = 'hidden';
			field.name = name;
			(document.querySelector('form.checkout') || document.body).appendChild(field);
		}
		field.value = String(value || '');
	}

	function refreshCheckoutContext() {
		refreshCheckoutContextOnce().then(function () {
			schedulePrefetch();
		}).catch(function () {
			schedulePrefetch();
		});
	}

	function refreshCheckoutContextOnce(timeout) {
		if (!window.WDCPickupApi || !window.WDCPickupApi.state) {
			return Promise.resolve(null);
		}
		var stateRequest = window.WDCPickupApi.state().then(function (state) {
			var context = contextFromState(state && state.city_context);
			debug('refreshCheckoutContextOnce result', context);
			if ((context.query || validCoordinate(context.lat, context.lng)) && stateContextMatchesCurrentDestination(context)) {
				updateCurrentContext(context);
				applyContextToHidden(context);
				return context;
			}
			return null;
		}).catch(function () { return null; });
		if (!timeout) {
			return stateRequest;
		}
		return Promise.race([
			stateRequest,
			new Promise(function (resolve) {
				window.setTimeout(function () { resolve(null); }, timeout);
			})
		]);
	}

	function contextFromState(context) {
		context = context || {};
		var postcode = String(context.postcode || context.postal_code || '').trim();
		var displayName = String(context.display_name || context.city_name || context.settlement_name || '').trim();
		var query = [postcode, displayName].filter(Boolean).join(' ').trim();
		return {
			lat: context.lat || context.latitude || '',
			lng: context.lng || context.longitude || '',
			postcode: postcode,
			display_name: displayName,
			region_name: context.region_name || '',
			query: query,
			country_code: context.country_code || 'RU',
			location_id: context.location_id || '',
			fias_id: context.fias_id || '',
			city_name: context.city_name || displayName
		};
	}

	function normalizeSelectedPoint(point) {
		point = point || {};
		var snapshot = point.snapshot || {};
		return {
			id: point.id || snapshot.id || '',
			point_code: point.point_code || snapshot.point_code || '',
			point_type: point.point_type || snapshot.point_type || '',
			postcode: point.postcode || snapshot.postcode || '',
			address: point.address || snapshot.address || '',
			lat: point.lat !== undefined && point.lat !== null ? point.lat : snapshot.lat,
			lng: point.lng !== undefined && point.lng !== null ? point.lng : snapshot.lng,
			work_time: point.work_time || snapshot.work_time || '',
			description: point.description || snapshot.description || '',
			snapshot: snapshot
		};
	}

	function contextFromLocationDetail(detail) {
		detail = detail || {};
		var postcode = String(detail.postcode || '').trim();
		var displayName = String(detail.display_name || '').trim();
		var query = [postcode, displayName].filter(Boolean).join(' ').trim();
		return {
			lat: detail.lat || '',
			lng: detail.lng || '',
			postcode: postcode,
			display_name: displayName,
			region_name: detail.region_name || '',
			query: query,
			country_code: detail.country_code || 'RU',
			location_id: detail.location_id || detail.id || '',
			fias_id: detail.fias_id || '',
			city_name: detail.city_name || displayName
		};
	}

	function pointPayload(point) {
		point = point || {};
		return {
			id: point.id || '',
			location_id: point.location_id || '',
			postal_code: point.postal_code || point.postcode || '',
			postcode: point.postcode || point.postal_code || '',
			city: point.city || point.city_name || '',
			region: point.region || point.region_name || '',
			address: point.address || '',
			fias_location_guid: point.fias_location_guid || point.fias_id || '',
			lat: point.lat || '',
			lng: point.lng || ''
		};
	}

	function pointMatchesDestinationQuick(point, checkoutContext) {
		var checkoutFias = normalizeGuid(checkoutContext && checkoutContext.fias_id);
		var pointFias = normalizeGuid(point && (point.fias_location_guid || point.fias_id));
		if (checkoutFias && pointFias) {
			return checkoutFias === pointFias;
		}
		var checkoutRegion = normalizeText(checkoutContext && checkoutContext.region_name);
		var checkoutCity = normalizeText((checkoutContext && (checkoutContext.city_name || checkoutContext.display_name)) || '');
		var pointRegion = normalizeText(point && (point.region || point.region_name));
		var pointCity = normalizeText(point && (point.city || point.city_name));
		if (checkoutRegion && checkoutCity && pointRegion && pointCity) {
			return checkoutRegion === pointRegion && (checkoutCity === pointCity || containsDestinationName(checkoutCity, pointCity) || containsDestinationName(pointCity, checkoutCity));
		}
		var checkoutPostcode = normalizeText(checkoutContext && checkoutContext.postcode);
		var pointPostcode = normalizeText(point && (point.postal_code || point.postcode));
		return !!(checkoutPostcode && pointPostcode && checkoutPostcode === pointPostcode);
	}

	function normalizeGuid(value) {
		return String(value || '').replace(/[^a-f0-9]/gi, '').toLowerCase();
	}

	function showLocationChangeConfirm(root, location) {
		return new Promise(function (resolve) {
			var city = location.city_name || location.display_name || location.postal_code || '';
			var dialog = document.createElement('div');
			dialog.className = 'wdc-pickup-location-confirm';
			dialog.innerHTML = [
				'<div class="wdc-pickup-location-confirm__panel" role="alertdialog" aria-modal="true">',
				'<h3>Пункт выдачи в другом населенном пункте</h3>',
				'<p>Вы выбрали пункт выдачи не в текущем населенном пункте оформления заказа. Мы изменим населенный пункт на «' + escapeHtml(city) + '» и пересчитаем стоимость доставки.</p>',
				'<div class="wdc-pickup-location-confirm__actions">',
				'<button type="button" class="button button-primary" data-wdc-location-confirm>Пересчитать и выбрать</button>',
				'<button type="button" class="button" data-wdc-location-cancel>Отмена</button>',
				'</div>',
				'</div>'
			].join('');
			root.appendChild(dialog);
			function finish(value) {
				dialog.remove();
				resolve(value);
			}
			dialog.querySelector('[data-wdc-location-confirm]').addEventListener('click', function () { finish(true); });
			dialog.querySelector('[data-wdc-location-cancel]').addEventListener('click', function () { finish(false); });
			dialog.querySelector('[data-wdc-location-cancel]').focus();
		});
	}

	function applyConfirmedPickupLocationChange(location) {
		var context = contextFromResolvedLocation(location);
		suppressNextDestinationReset = true;
		updateCurrentContext(context);
		applyContextToHidden(context);
		setCheckoutFieldValue('shipping_country', context.country_code || 'RU');
		setCheckoutFieldValue('billing_country', context.country_code || 'RU');
		setCheckoutFieldValue(fieldExists('shipping_city') ? 'shipping_city' : 'billing_city', context.city_name || context.display_name || '');
		setCheckoutFieldValue(fieldExists('shipping_postcode') ? 'shipping_postcode' : 'billing_postcode', context.postcode || '');
		if (context.region_name) {
			setCheckoutFieldValue(fieldExists('shipping_state') ? 'shipping_state' : 'billing_state', context.region_name);
		}
		window.setTimeout(function () { suppressNextDestinationReset = false; }, 0);
	}

	function contextFromResolvedLocation(location) {
		location = location || {};
		var postcode = String(location.postal_code || location.postcode || '').trim();
		var city = String(location.city_name || location.display_name || '').trim();
		var query = [postcode, city].filter(Boolean).join(' ').trim();
		return {
			lat: location.lat || location.latitude || '',
			lng: location.lng || location.longitude || '',
			postcode: postcode,
			display_name: location.display_name || city,
			city_name: city,
			region_name: location.region_name || '',
			query: query,
			country_code: location.country_code || 'RU',
			location_id: location.location_id || location.id || '',
			fias_id: location.fias_id || ''
		};
	}

	function fieldExists(name) {
		return !!document.querySelector('[name="' + name + '"]');
	}

	function setCheckoutFieldValue(name, value) {
		var field = document.querySelector('[name="' + name + '"]');
		if (!field || field.value === String(value || '')) {
			return;
		}
		field.value = String(value || '');
		field.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
		});
	}

	function stateContextMatchesCurrentDestination(context) {
		var fieldContext = contextFromFields();
		return contextMatches(fieldContext, context) || contextMatches(currentContext, context);
	}

	function schedulePrefetch() {
		clearTimeout(prefetchTimer);
		prefetchTimer = setTimeout(prefetchInitialPoints, 400);
	}

	function prefetchInitialPoints() {
		if (!hasPickupBlock() || !isPickupMethodActive()) {
			return;
		}
		var context = initialContext();
		if (!context.query && !validCoordinate(context.lat, context.lng)) {
			return;
		}
		var key = cacheKey(context);
		if (!key || (prefetchCache && prefetchCache.key === key)) {
			return;
		}
		if (prefetchController) {
			prefetchController.abort();
		}
		prefetchController = new AbortController();
		if (validCoordinate(context.lat, context.lng)) {
			prefetchBounds(context, parseFloat(context.lat), parseFloat(context.lng), key, prefetchController.signal);
			return;
		}
		window.WDCPickupApi.searchInitial(context.query, prefetchController.signal).then(function (points) {
			if (!points[0] || points[0].lat === null || points[0].lng === null) {
				prefetchCache = { key: key, points: [], context: context };
				return;
			}
			prefetchBounds(context, parseFloat(points[0].lat), parseFloat(points[0].lng), key, prefetchController.signal);
		}).catch(function () {});
	}

	function prefetchBounds(context, lat, lng, key, signal) {
		window.WDCPickupApi.points(bboxAround(lat, lng), signal).then(function (points) {
			prefetchCache = {
				key: key,
				points: points,
				centerLat: lat,
				centerLng: lng,
				context: context
			};
		}).catch(function () {});
	}

	function bboxAround(lat, lng) {
		var delta = 0.08;
		return [lng - delta, lat - delta, lng + delta, lat + delta].join(',');
	}

	function withPrefetch(context) {
		var key = cacheKey(context);
		debug('prefetch cache key', key);
		if (prefetchCache && prefetchCache.key === key && Array.isArray(prefetchCache.points) && prefetchCache.points.length) {
			return Object.assign({}, context, {
				preloadedPoints: prefetchCache.points,
				centerLat: prefetchCache.centerLat,
				centerLng: prefetchCache.centerLng
			});
		}
		return context;
	}

	function invalidatePrefetch() {
		prefetchCache = null;
		if (prefetchController) {
			prefetchController.abort();
			prefetchController = null;
		}
	}

	function hasPickupBlock() {
		return !!document.querySelector('[data-wdc-pickup-checkout]');
	}

	function isPickupMethodActive() {
		var method = currentShippingMethod();
		return method && method.indexOf('russian_post_domestic_pickup') === 0;
	}

	function toggleForMethod(container) {
		var method = currentShippingMethod();
		activeMethod = method || activeMethod;
		var visible = !method || method.indexOf('russian_post_domestic_pickup') === 0;
		container.hidden = !visible;
	}

	function triggerCheckoutUpdate() {
		if (window.jQuery) {
			window.jQuery(document.body).trigger('update_checkout');
		}
	}

	function boot() {
		document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(init);
	}

	document.addEventListener('change', function (event) {
		if (event.target.matches('#billing_city, #shipping_city, #billing_country, #shipping_country, #billing_postcode, #shipping_postcode, [name="billing_city"], [name="shipping_city"], [name="billing_country"], [name="shipping_country"], [name="billing_postcode"], [name="shipping_postcode"]')) {
			invalidatePrefetch();
			if (suppressNextDestinationReset) {
				document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
				return;
			}
			resetSelection();
			document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
			return;
		}
		if (event.target.matches('input[name^="shipping_method"]')) {
			document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
		}
	});
	document.body.addEventListener('wdc:location-selected', function (event) {
		var context = contextFromLocationDetail(event.detail || {});
		debug('wdc:location-selected detail', event.detail || {});
		invalidatePrefetch();
		updateCurrentContext(context);
		applyContextToHidden(context);
		if (suppressNextDestinationReset) {
			schedulePrefetch();
			return;
		}
		resetPickupSelectionOnServer();
		clearPickupSelectionUi();
		schedulePrefetch();
	});
	document.addEventListener('DOMContentLoaded', boot);
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', function () {
			boot();
			refreshCheckoutContext();
		});
	}
})(window, document);
