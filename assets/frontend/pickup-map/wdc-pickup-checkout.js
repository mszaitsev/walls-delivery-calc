(function (window, document) {
	'use strict';

	var labels = (window.wdcPickupCheckout && window.wdcPickupCheckout.labels) || {};
	var activeMethod = '';
	var currentContext = (window.wdcPickupCheckout && window.wdcPickupCheckout.currentContext) || {};
	var prefetchTimer = 0;
	var prefetchController = null;
	var prefetchCache = null;

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
			var context = withPrefetch(resolvedContext);
			debug('openModal context', context);
			var map = window.WDCPickupMap.create(modal.root.querySelector('[data-wdc-map]'), modal.root.querySelector('[data-wdc-card]'), confirmButton, labels, context);

			function close() {
				map.destroy();
				modal.destroy();
			}

			modal.root.addEventListener('wdc:close', close);
			search.addEventListener('change', function () {
				if (search.value.trim()) {
					map.search(search.value.trim());
				}
			});
			confirmButton.addEventListener('click', function () {
				var point = map.selected();
				if (!point) {
					return;
				}
				confirmButton.disabled = true;
				window.WDCPickupApi.save(point.id, method).then(function (response) {
					applySelection(container, response.pickup_point || {});
					close();
					triggerCheckoutUpdate();
				}).catch(function () {
					confirmButton.disabled = false;
				});
			});
		});
	}

	function applySelection(container, point) {
		var snapshot = point.snapshot || {};
		container.querySelector('[data-wdc-pickup-point-id]').value = point.id || '';
		container.querySelector('[data-wdc-pickup-point-code]').value = point.point_code || '';
		container.querySelector('[data-wdc-pickup-address]').textContent = point.address || '';
		container.querySelector('[data-wdc-pickup-postcode]').textContent = point.postcode || '';
		container.querySelector('[data-wdc-pickup-work-time]').textContent = snapshot.work_time || '';
		container.querySelector('[data-wdc-pickup-selection]').hidden = !point.point_code;
		container.querySelector('[data-wdc-pickup-open]').textContent = point.point_code ? labels.change : labels.choose;
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
			query: config.query || ''
		};
		var fieldContext = contextFromFields();
		debug('contextFromFields', fieldContext);
		debug('currentContext', currentContext);
		if (fieldContext.countryBlocked) {
			return {};
		}
		var runtimeContext = contextMatches(fieldContext, currentContext) ? currentContext : {};
		var localizedContext = contextMatches(fieldContext, configContext) ? configContext : {};
		var result = {
			lat: fieldContext.lat || runtimeContext.lat || localizedContext.lat,
			lng: fieldContext.lng || runtimeContext.lng || localizedContext.lng,
			query: fieldContext.query || runtimeContext.query || localizedContext.query,
			postcode: fieldContext.postcode || runtimeContext.postcode || localizedContext.postcode || '',
			display_name: fieldContext.display_name || runtimeContext.display_name || localizedContext.display_name || ''
		};
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
		var visiblePostcode = fieldValue('shipping_postcode') || fieldValue('billing_postcode');
		var visibleCity = fieldValue('shipping_city') || fieldValue('billing_city');
		var postcode = hiddenPostcode || visiblePostcode;
		var city = hiddenDisplay || visibleCity;
		var query = [postcode, city || hiddenRegion].filter(Boolean).join(' ').trim();
		var context = query ? { query: query } : {};
		context.postcode = postcode;
		context.display_name = city || hiddenRegion;
		if (validCoordinate(hiddenLat, hiddenLng)) {
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
		if (!cachedContext || !Object.keys(cachedContext).length) {
			return false;
		}
		var fieldFingerprint = destinationFingerprint(fieldContext);
		if (!fieldFingerprint) {
			return true;
		}
		return fieldFingerprint === destinationFingerprint(cachedContext);
	}

	function destinationFingerprint(context) {
		if (!context) {
			return '';
		}
		return [
			context.postcode || '',
			context.display_name || '',
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
			country_code: context.country_code || 'RU'
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
			location_id: detail.location_id || ''
		};
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
