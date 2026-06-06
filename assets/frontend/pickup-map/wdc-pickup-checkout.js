(function (window, document) {
	'use strict';

	var checkoutConfig = window.wdcPickupCheckout || {};
	var labels = checkoutConfig.labels || {};
	var activeMethod = '';
	var currentContext = checkoutConfig.currentContext || checkoutConfig.initialContext || {};
	var prefetchTimer = 0;
	var prefetchController = null;
	var prefetchCache = null;
	var suppressNextDestinationReset = false;
	var suppressDestinationResetTimer = 0;
	var suppressPickupResetOnNextLocationSelected = false;
	var suppressPickupResetOnNextLocationSelectedTimer = 0;
	var isPlacingOrder = false;
	var placeOrderGuardTimer = 0;
	var placeOrderResetGuardUntil = 0;
	var russianPostPickupFamily = 'russian_post_domestic:pickup';
	var lastDestinationFingerprint = destinationFingerprint(contextFromFields());

	function init(container) {
		if (container.dataset.wdcPickupReady) {
			return;
		}
		container.dataset.wdcPickupReady = '1';
		var method = container.getAttribute('data-shipping-method-id') || (window.wdcPickupCheckout && window.wdcPickupCheckout.shippingMethodId) || '';
		activeMethod = currentShippingMethod() || method;
		rememberDestinationFingerprint();
		toggleForMethod(container);
		container.querySelectorAll('[data-wdc-pickup-open]').forEach(function (openButton) {
			openButton.addEventListener('click', function () { openModal(container, activeMethod || method); });
		});
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
			var geolocationButton = modal.root.querySelector('[data-wdc-geolocation]');
			var context = withPrefetch(resolvedContext);
			debugDeep('openModal context', context);
			var map = window.WDCPickupMap.create(modal.root.querySelector('[data-wdc-map]'), modal.root.querySelector('[data-wdc-card]'), confirmButton, labels, context);
			var savingPoint = false;
			var loadingText = '';

			function close() {
				map.destroy();
				modal.destroy();
			}

			function setLoading(message) {
				savingPoint = true;
				loadingText = message || 'Сохраняем пункт выдачи...';
				confirmButton.disabled = true;
				setModalLoading(modal.root, loadingText);
				setModalSelectButtonsDisabled(modal.root, true, loadingText);
			}

			function clearLoading() {
				savingPoint = false;
				loadingText = '';
				confirmButton.disabled = false;
				clearModalLoading(modal.root);
				setModalSelectButtonsDisabled(modal.root, false, '');
			}

			function commitPoint(point, shippingMethodId, options) {
				options = options || {};
				if (!point) {
					return Promise.resolve(false);
				}
				if (!savingPoint) {
					setLoading(options.message || 'Сохраняем пункт выдачи...');
				}
				return window.WDCPickupApi.save(point.id, shippingMethodId || method).then(function (response) {
					applySelection(container, response.pickup_point || {});
					close();
					if (true === options.updateCheckoutAfterSave) {
						triggerCheckoutUpdate();
					}
					return true;
				}).catch(function () {
					clearLoading();
					showModalNotice(modal.root, 'Не удалось сохранить пункт выдачи. Попробуйте еще раз.');
					return false;
				});
			}

			function savePoint(point) {
				point = point || map.selected();
				if (!point || savingPoint) {
					return;
				}
				setLoading('Сохраняем пункт выдачи...');
				var checkoutContext = contextFromFields();
				if (pointMatchesDestinationQuick(point, checkoutContext) || !window.WDCPickupApi.resolveLocation) {
					commitPoint(point, method, { updateCheckoutAfterSave: false });
					return;
				}
				setLoading('Проверяем населенный пункт...');
				window.WDCPickupApi.resolveLocation(pointPayload(point), checkoutContext).then(function (response) {
					if (!response || !response.requires_location_change || !response.location) {
						clearLoading();
						commitPoint(point, method, { updateCheckoutAfterSave: false });
						return;
					}
					clearLoading();
					showLocationChangeConfirm(modal.root, response.location).then(function (confirmed) {
						if (!confirmed) {
							clearLoading();
							return;
						}
						runCrossLocationSelection(point, response.location);
					});
				}).catch(function () {
					clearLoading();
					commitPoint(point, method, { updateCheckoutAfterSave: false });
				});
			}

			function runCrossLocationSelection(point, location) {
				enableDestinationResetSuppression(60000);
				applyConfirmedPickupLocationChange(location);
				close();
				var updatedCheckout = waitForUpdatedCheckout(60000);
				triggerCheckoutUpdate();
				updatedCheckout.then(function () {
					boot();
					var currentMethod = currentShippingMethod();
					if (!isPickupRateValue(currentMethod)) {
						resetPickupSelectionOnServer('cross_location_method_unavailable');
						showCheckoutNotice('После пересчета выбранный способ доставки стал недоступен. Выберите другой способ доставки.');
						disableDestinationResetSuppression();
						return;
					}
					window.WDCPickupApi.save(point.id, currentMethod).then(function (response) {
						boot();
						var actualContainer = document.querySelector('[data-wdc-pickup-checkout]');
						var savedPoint = response.pickup_point || {};
						if (actualContainer) {
							applySelection(actualContainer, savedPoint);
						}
						syncPickupContextAfterLocationChange(location, savedPoint);
						disableDestinationResetSuppression();
					}).catch(function () {
						showCheckoutNotice('Не удалось сохранить пункт выдачи. Выберите пункт выдачи еще раз.');
						disableDestinationResetSuppression();
					});
				}).catch(function () {
					showCheckoutNotice('Не удалось дождаться пересчета доставки. Выберите пункт выдачи еще раз.');
					disableDestinationResetSuppression();
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
			setupGeolocationButton();
			confirmButton.addEventListener('wdc:point-selected', function (event) {
				savePoint(event.detail || map.selected());
			});
			confirmButton.addEventListener('click', function () {
				savePoint(map.selected());
			});

			function setupGeolocationButton() {
				if (!geolocationButton) {
					return;
				}
				if (!window.navigator || !window.navigator.geolocation) {
					geolocationButton.hidden = true;
					geolocationButton.disabled = true;
					geolocationButton.title = 'Геолокация не поддерживается браузером.';
					return;
				}
				geolocationButton.hidden = false;
				resetGeolocationButton();
				geolocationButton.addEventListener('click', function () {
					geolocationButton.disabled = true;
					geolocationButton.classList.add('is-loading');
					geolocationButton.title = 'Определяем местоположение...';
					geolocationButton.innerHTML = '<span aria-hidden="true">...</span>';
					if (map.setStatus) {
						map.setStatus('Определяем местоположение...');
					}
					window.navigator.geolocation.getCurrentPosition(function (position) {
						resetGeolocationButton();
						if (map.useUserLocation) {
							map.useUserLocation(position.coords.latitude, position.coords.longitude);
						}
					}, function (error) {
						resetGeolocationButton();
						if (map.setStatus) {
							map.setStatus(geolocationErrorMessage(error), 'error');
						}
					}, {
						enableHighAccuracy: true,
						timeout: 10000,
						maximumAge: 300000
					});
				});
			}

			function resetGeolocationButton() {
				geolocationButton.disabled = false;
				geolocationButton.classList.remove('is-loading');
				geolocationButton.title = 'Определить моё местоположение';
				geolocationButton.innerHTML = '<span aria-hidden="true" class="wdc-pickup-map__locate-icon"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 11.4 20.2 3.8 12.6 20l-2.1-7.1L4 11.4Z"></path></svg></span>';
			}
		});
	}

	function applySelection(container, point) {
		var snapshot = point.snapshot || {};
		var selectedPoint = normalizeSelectedPoint(point);
		container.querySelector('[data-wdc-pickup-point-id]').value = point.id || '';
		container.querySelector('[data-wdc-pickup-point-code]').value = point.point_code || '';
		setText(container, '[data-wdc-pickup-title-text]', selectedPointTitle(point));
		setText(container, '[data-wdc-pickup-address]', selectedPointAddress(point));
		setText(container, '[data-wdc-pickup-work-time]', point.point_work_time || point.work_time || snapshot.work_time || '');
		var workTimeBlock = container.querySelector('[data-wdc-pickup-work-time-block]');
		if (workTimeBlock) {
			setHidden(workTimeBlock, !(point.point_work_time || point.work_time || snapshot.work_time));
		}
		container.querySelectorAll('[data-wdc-pickup-card]').forEach(function (card) {
			setHidden(card, !point.point_code);
		});
		container.querySelectorAll('[data-wdc-pickup-empty-open]').forEach(function (button) {
			setHidden(button, !!point.point_code);
		});
		if (!window.wdcPickupCheckout) {
			window.wdcPickupCheckout = {};
		}
		window.wdcPickupCheckout.selectedPickupPoint = selectedPoint;
		if (window.wdcPickupCheckout.initialContext) {
			window.wdcPickupCheckout.initialContext.selectedPoint = selectedPoint;
		}
		if (selectedPoint && selectedPoint.point_code) {
			rememberDestinationFingerprint();
		}
	}

	function setHidden(element, hidden) {
		element.hidden = hidden;
		element.classList.toggle('wdc-is-hidden', hidden);
		element.setAttribute('aria-hidden', hidden ? 'true' : 'false');
		if (hidden) {
			element.style.display = 'none';
		} else {
			element.style.removeProperty('display');
		}
	}

	function geolocationErrorMessage(error) {
		if (error && error.code === 1) {
			return 'Браузер не дал доступ к местоположению. Разрешите доступ или используйте поиск адреса.';
		}
		if (error && error.code === 2) {
			return 'Не удалось определить местоположение. Используйте поиск адреса.';
		}
		if (error && error.code === 3) {
			return 'Не удалось определить местоположение за отведенное время. Используйте поиск адреса.';
		}
		return 'Не удалось определить местоположение. Используйте поиск адреса.';
	}

	function setText(container, selector, value) {
		var element = container.querySelector(selector);
		if (element) {
			element.textContent = value || '';
		}
	}

	function selectedPointTitle(point) {
		var config = window.wdcPickupCheckout || {};
		var carrier = String(point.carrier || point.carrier_key || config.carrier || '').trim();
		var rateId = String(point.rate_id || point.shipping_method_id || config.shippingMethodId || '').trim();
		if (carrier === 'russian_post' || carrier === 'russian_post_domestic' || rateId.indexOf(russianPostPickupFamily) === 0) {
			return 'Отделение Почты России';
		}
		return 'Пункт выдачи';
	}

	function selectedPointAddress(point) {
		var snapshot = point.snapshot || {};
		var address = String(point.address || point.point_address || snapshot.address || '').trim();
		if (address) {
			return address;
		}
		var postcode = String(point.postcode || point.point_postcode || snapshot.postcode || '').trim();
		var city = cityWithType(String(point.city || point.city_name || snapshot.city || snapshot.city_name || '').trim());
		if (postcode && city) {
			return postcode + ', ' + city;
		}
		return postcode || city;
	}

	function cityWithType(city) {
		if (!city || /^(г|город|п|пос|с|д|рп|пгт)\.?\s+/i.test(city)) {
			return city;
		}
		return 'г ' + city;
	}

	function resetSelection(reason) {
		debug('resetSelection called', { reason: reason || '', isPlacingOrder: isPlacingOrder, guardActive: placeOrderResetGuardActive() });
		if (isPickupResetGuarded()) {
			return;
		}
		invalidatePrefetch();
		resetPickupSelectionOnServer(reason || 'reset_selection');
		clearPickupSelectionUi(reason || 'reset_selection');
	}

	function clearPickupSelectionUi(reason) {
		debug('clearPickupSelectionUi called', { reason: reason || '', isPlacingOrder: isPlacingOrder, guardActive: placeOrderResetGuardActive() });
		if (isPickupResetGuarded()) {
			return;
		}
		document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(function (container) {
			applySelection(container, {});
		});
	}

	function resetPickupSelectionOnServer(reason) {
		debug('resetPickupSelectionOnServer called', { reason: reason || '', isPlacingOrder: isPlacingOrder, guardActive: placeOrderResetGuardActive() });
		if (isPickupResetGuarded()) {
			return Promise.resolve(false);
		}
		return window.WDCPickupApi.reset().catch(function () {});
	}

	function currentShippingMethod() {
		var checked = document.querySelector('input[name^="shipping_method"]:checked');
		return checked ? normalizeShippingMethod(checked.value) : '';
	}

	function normalizeShippingMethod(value) {
		return String(value || '').replace(/^wdc_platform:/, '');
	}

	function shippingMethodFamily(value) {
		var method = normalizeShippingMethod(value);
		if (method.indexOf(russianPostPickupFamily) === 0) {
			return russianPostPickupFamily;
		}

		return method;
	}

	function isSamePickupMethodFamily(oldMethod, newMethod) {
		return shippingMethodFamily(oldMethod) === russianPostPickupFamily
			&& shippingMethodFamily(newMethod) === russianPostPickupFamily;
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
		debugDeep('contextFromFields', fieldContext);
		debugDeep('currentContext', currentContext);
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
		debugDeep('sameDestination field/current', sameDestination(fieldContext, currentContext));
		debugDeep('chosen lat/lng source', latSource);
		debugDeep('initialContext selected source', fieldContext.query ? 'fields' : (runtimeContext.query ? 'current' : (localizedContext.query ? 'localized' : 'fallback')), result);
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
		var visibleDestinationChanged = !!(visibleCity && hiddenDisplay && !destinationTextMatches(visibleCity, hiddenDisplay) && !destinationTextMatches(visibleCity, hiddenCity));
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
		context.region_code = fieldValue('wdc_platform_location_region_code');
		context.region_type = fieldValue('wdc_platform_location_region_type');
		context.district_name = fieldValue('wdc_platform_location_district_name');
		context.district_type = fieldValue('wdc_platform_location_district_type');
		context.city_type = fieldValue('wdc_platform_location_city_type');
		context.place_name = fieldValue('wdc_platform_location_place_name');
		context.place_type = fieldValue('wdc_platform_location_place_type');
		context.gar_object_id = fieldValue('wdc_platform_location_gar_object_id');
		context.kladr_id = fieldValue('wdc_platform_location_kladr_id');
		if (!visibleDestinationChanged && validCoordinate(hiddenLat, hiddenLng)) {
			context.lat = hiddenLat;
			context.lng = hiddenLng;
		}
		return context;
	}

	function destinationTextMatches(a, b) {
		var aName = normalizeText(a || '');
		var bName = normalizeText(b || '');
		if (!aName || !bName) {
			return false;
		}
		return aName === bName || containsDestinationName(aName, bName) || containsDestinationName(bName, aName);
	}

	function debug() {
		if (window.wdcPickupCheckout && window.wdcPickupCheckout.debug && window.console && window.console.log) {
			window.console.log.apply(window.console, ['wdc pickup:'].concat(Array.prototype.slice.call(arguments)));
		}
	}

	function debugDeep() {
		if (window.wdcPickupCheckout && window.wdcPickupCheckout.deepDebug) {
			debug.apply(null, arguments);
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

	function sameLocationContext(oldContext, newContext) {
		if (!oldContext || !newContext) {
			return false;
		}
		var oldLocationId = normalizeText(oldContext.location_id || '');
		var newLocationId = normalizeText(newContext.location_id || '');
		if (oldLocationId && newLocationId) {
			return oldLocationId === newLocationId;
		}
		var oldFias = normalizeGuid(oldContext.fias_id || '');
		var newFias = normalizeGuid(newContext.fias_id || '');
		if (oldFias && newFias) {
			return oldFias === newFias;
		}
		var oldPostcode = normalizeText(oldContext.postcode || '');
		var newPostcode = normalizeText(newContext.postcode || '');
		var oldName = normalizeText(oldContext.display_name || oldContext.city_name || oldContext.query || '');
		var newName = normalizeText(newContext.display_name || newContext.city_name || newContext.query || '');
		if (oldPostcode && newPostcode && oldPostcode === newPostcode && oldName && newName && oldName === newName) {
			return true;
		}

		return destinationFingerprint(oldContext) === destinationFingerprint(newContext);
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
			context.country_code || '',
			context.postcode || '',
			context.display_name || '',
			context.city_name || '',
			context.location_id || '',
			context.fias_id || '',
			context.query || ''
		].map(normalizeText).filter(Boolean).join('|');
	}

	function rememberDestinationFingerprint(context) {
		lastDestinationFingerprint = destinationFingerprint(context || contextFromFields());
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
			normalizeGuid(context.fias_id || ''),
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

	function syncPickupContextAfterLocationChange(location, savedPoint) {
		var resolvedContext = contextFromResolvedLocation(location);
		var fieldContext = contextFromFields();
		if (fieldContext.countryBlocked) {
			return;
		}
		var selectedPoint = normalizeSelectedPoint(savedPoint || {});
		var context = Object.assign({}, resolvedContext, fieldContext);
		context.selectedPoint = selectedPoint;
		updateCurrentContext(context);
		if (!window.wdcPickupCheckout) {
			window.wdcPickupCheckout = {};
		}
		window.wdcPickupCheckout.currentContext = context;
		window.wdcPickupCheckout.initialContext = Object.assign({}, window.wdcPickupCheckout.initialContext || {}, context);
		window.wdcPickupCheckout.selectedPickupPoint = selectedPoint;
		rememberDestinationFingerprint(context);
		invalidatePrefetch();
		schedulePrefetch();
		debugDeep('syncPickupContextAfterLocationChange', context);
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
		setHiddenValue('wdc_platform_location_region_code', context.region_code || '');
		setHiddenValue('wdc_platform_location_region_type', context.region_type || '');
		setHiddenValue('wdc_platform_location_district_name', context.district_name || '');
		setHiddenValue('wdc_platform_location_district_type', context.district_type || '');
		setHiddenValue('wdc_platform_location_id', context.location_id || '');
		setHiddenValue('wdc_platform_location_fias_id', context.fias_id || '');
		setHiddenValue('wdc_platform_location_gar_object_id', context.gar_object_id || context.gar_id || '');
		setHiddenValue('wdc_platform_location_kladr_id', context.kladr_id || '');
		setHiddenValue('wdc_platform_location_city_name', context.city_name || context.display_name || '');
		setHiddenValue('wdc_platform_location_city_type', context.city_type || '');
		setHiddenValue('wdc_platform_location_place_name', context.place_name || context.settlement_name || '');
		setHiddenValue('wdc_platform_location_place_type', context.place_type || '');
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
			debugDeep('refreshCheckoutContextOnce result', context);
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
			region_code: context.region_code || '',
			region_type: context.region_type || '',
			district_name: context.district_name || '',
			district_type: context.district_type || '',
			query: query,
			country_code: context.country_code || 'RU',
			location_id: context.location_id || '',
			fias_id: context.fias_id || '',
			gar_object_id: context.gar_object_id || context.gar_id || '',
			kladr_id: context.kladr_id || '',
			city_name: context.city_name || context.city_value || displayName,
			city_type: context.city_type || '',
			place_name: context.place_name || context.settlement_name || '',
			place_type: context.place_type || '',
			city_value: context.city_value || context.settlement_name || context.city_name || displayName,
			state_value: context.state_value || context.region_name || ''
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
			gar_object_id: detail.gar_object_id || detail.gar_id || '',
			kladr_id: detail.kladr_id || '',
			region_code: detail.region_code || '',
			region_type: detail.region_type || '',
			district_name: detail.district_name || '',
			district_type: detail.district_type || '',
			city_name: detail.city_name || detail.city_value || displayName,
			city_type: detail.city_type || '',
			place_name: detail.place_name || detail.settlement_name || '',
			place_type: detail.place_type || '',
			city_value: detail.city_value || detail.settlement_name || detail.city_name || displayName,
			state_value: detail.state_value || detail.region_name || ''
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
			debug('pointMatchesDestinationQuick', {
				checkoutFias: checkoutFias,
				pointFias: pointFias,
				quickMatchReason: checkoutFias === pointFias ? 'same_fias' : 'different_fias'
			});
			return checkoutFias === pointFias;
		}
		var checkoutRegion = normalizeText(checkoutContext && checkoutContext.region_name);
		var checkoutCity = normalizeText((checkoutContext && (checkoutContext.city_name || checkoutContext.display_name)) || '');
		var pointRegion = normalizeText(point && (point.region || point.region_name));
		var pointCity = normalizeText(point && (point.city || point.city_name));
		if (checkoutRegion && checkoutCity && pointRegion && pointCity) {
			var sameCityRegion = checkoutRegion === pointRegion && (checkoutCity === pointCity || containsDestinationName(checkoutCity, pointCity) || containsDestinationName(pointCity, checkoutCity));
			debug('pointMatchesDestinationQuick', {
				checkoutFias: checkoutFias,
				pointFias: pointFias,
				quickMatchReason: sameCityRegion ? 'same_region_city' : 'different_region_city'
			});
			return sameCityRegion;
		}
		var checkoutPostcode = normalizeText(checkoutContext && checkoutContext.postcode);
		var pointPostcode = normalizeText(point && (point.postal_code || point.postcode));
		var samePostcode = !!(checkoutPostcode && pointPostcode && checkoutPostcode === pointPostcode);
		debug('pointMatchesDestinationQuick', {
			checkoutFias: checkoutFias,
			pointFias: pointFias,
			quickMatchReason: samePostcode ? 'same_postcode' : 'no_match'
		});
		return samePostcode;
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
			var confirm = dialog.querySelector('[data-wdc-location-confirm]');
			var cancel = dialog.querySelector('[data-wdc-location-cancel]');
			function finish(value) {
				if (value && confirm && cancel) {
					confirm.disabled = true;
					cancel.disabled = true;
					confirm.textContent = 'Пересчитываем...';
				}
				dialog.remove();
				resolve(value);
			}
			confirm.addEventListener('click', function () { finish(true); });
			cancel.addEventListener('click', function () { finish(false); });
			cancel.focus();
		});
	}

	function applyConfirmedPickupLocationChange(location) {
		var context = contextFromResolvedLocation(location);
		updateCurrentContext(context);
		if (window.WDCCheckoutCitySelector && typeof window.WDCCheckoutCitySelector.applyLocation === 'function') {
			beginControlledLocationChange();
			window.WDCCheckoutCitySelector.applyLocation(location, { updateCheckout: false, explicit: true, source: 'pickup', updateFields: true });
			updateCurrentContext(contextFromFields());
			return;
		}
		applyContextToHidden(context);
		setCheckoutFieldValue('shipping_country', context.country_code || 'RU');
		setCheckoutFieldValue('billing_country', context.country_code || 'RU');
		setCheckoutFieldValue(fieldExists('shipping_city') ? 'shipping_city' : 'billing_city', context.city_value || context.settlement_name || context.city_name || context.display_name || '');
		setCheckoutFieldValue(fieldExists('shipping_postcode') ? 'shipping_postcode' : 'billing_postcode', context.postcode || '');
		if (context.state_value || context.region_name || context.region_code) {
			setCheckoutStateField(fieldExists('shipping_state') ? 'shipping_state' : 'billing_state', context);
		}
	}

	function contextFromResolvedLocation(location) {
		location = location || {};
		var postcode = String(location.postal_code || location.postcode || '').trim();
		var city = String(location.city_value || location.settlement_name || location.city_name || location.display_name || '').trim();
		var query = [postcode, city].filter(Boolean).join(' ').trim();
		return {
			lat: location.lat || location.latitude || '',
			lng: location.lng || location.longitude || '',
			postcode: postcode,
			display_name: location.display_name || city,
			city_name: location.city_name || city,
			city_value: city,
			state_value: location.state_value || location.region_name || '',
			region_name: location.region_name || '',
			region_code: location.region_code || '',
			region_type: location.region_type || '',
			district_name: location.district_name || '',
			district_type: location.district_type || '',
			city_type: location.city_type || '',
			place_name: location.place_name || location.settlement_name || '',
			place_type: location.place_type || '',
			gar_object_id: location.gar_object_id || location.gar_id || '',
			gar_id: location.gar_id || location.gar_object_id || '',
			kladr_id: location.kladr_id || '',
			settlement_name: location.settlement_name || '',
			query: query,
			country_code: location.country_code || 'RU',
			location_id: location.location_id || location.id || '',
			fias_id: location.fias_id || ''
		};
	}

	function setCheckoutStateField(name, context) {
		var field = document.querySelector('[name="' + name + '"]');
		var regionCode = context.region_code || '';
		var regionName = context.state_value || context.region_name || '';
		if (!field) {
			return;
		}
		if (field.tagName && field.tagName.toLowerCase() === 'select') {
			if (regionCode && field.querySelector('option[value="' + cssEscape(regionCode) + '"]')) {
				setCheckoutFieldValue(name, regionCode);
				return;
			}
			if (regionName && field.querySelector('option[value="' + cssEscape(regionName) + '"]')) {
				setCheckoutFieldValue(name, regionName);
			}
			return;
		}
		setCheckoutFieldValue(name, regionName || regionCode);
	}

	function cssEscape(value) {
		if (window.CSS && typeof window.CSS.escape === 'function') {
			return window.CSS.escape(String(value || ''));
		}
		return String(value || '').replace(/"/g, '\\"');
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

	function setModalLoading(root, message) {
		var overlay = root.querySelector('[data-wdc-pickup-loading]');
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.className = 'wdc-pickup-modal-loading';
			overlay.setAttribute('data-wdc-pickup-loading', '1');
			overlay.innerHTML = '<div class="wdc-pickup-modal-loading__panel" role="status" aria-live="polite"><span class="wdc-pickup-modal-loading__spinner" aria-hidden="true"></span><span data-wdc-pickup-loading-text></span></div>';
			root.appendChild(overlay);
		}
		overlay.querySelector('[data-wdc-pickup-loading-text]').textContent = message || 'Сохраняем пункт выдачи...';
	}

	function clearModalLoading(root) {
		var overlay = root.querySelector('[data-wdc-pickup-loading]');
		if (overlay) {
			overlay.remove();
		}
	}

	function setModalSelectButtonsDisabled(root, disabled, text) {
		root.querySelectorAll('[data-wdc-pickup-popup-select], [data-wdc-pickup-list-confirm], [data-wdc-confirm]').forEach(function (button) {
			if (!button.dataset.wdcOriginalText) {
				button.dataset.wdcOriginalText = button.textContent || '';
			}
			button.disabled = !!disabled;
			if (disabled && text) {
				button.textContent = text;
			} else if (!disabled && button.dataset.wdcOriginalText) {
				button.textContent = button.dataset.wdcOriginalText;
				delete button.dataset.wdcOriginalText;
			}
		});
	}

	function showModalNotice(root, message) {
		var notice = root.querySelector('[data-wdc-pickup-notice]');
		if (!notice) {
			notice = document.createElement('div');
			notice.className = 'wdc-pickup-modal-notice';
			notice.setAttribute('data-wdc-pickup-notice', '1');
			notice.setAttribute('role', 'alert');
			var dialog = root.querySelector('.wdc-pickup-modal__dialog') || root;
			dialog.appendChild(notice);
		}
		notice.textContent = message || '';
	}

	function showCheckoutNotice(message) {
		var notice = document.createElement('div');
		notice.className = 'woocommerce-error wdc-pickup-checkout-notice';
		notice.setAttribute('role', 'alert');
		notice.textContent = message || '';
		var wrapper = document.querySelector('.woocommerce-NoticeGroup-checkout') || document.querySelector('.woocommerce-notices-wrapper');
		if (wrapper) {
			wrapper.innerHTML = '';
			wrapper.appendChild(notice);
			return;
		}
		var checkoutForm = document.querySelector('form.checkout');
		if (checkoutForm && checkoutForm.parentNode) {
			checkoutForm.parentNode.insertBefore(notice, checkoutForm);
			return;
		}
		if (document.body) {
			document.body.insertBefore(notice, document.body.firstChild);
		}
	}

	function waitForUpdatedCheckout(timeout) {
		return new Promise(function (resolve, reject) {
			var done = false;
			var timer = window.setTimeout(function () {
				if (done) {
					return;
				}
				done = true;
				if (window.jQuery) {
					window.jQuery(document.body).off('updated_checkout.wdcPickupPending', handler);
				}
				reject(new Error('updated_checkout timeout'));
			}, timeout || 12000);
			function handler() {
				if (done) {
					return;
				}
				done = true;
				window.clearTimeout(timer);
				if (window.jQuery) {
					window.jQuery(document.body).off('updated_checkout.wdcPickupPending', handler);
				}
				resolve();
			}
			if (window.jQuery) {
				window.jQuery(document.body).one('updated_checkout.wdcPickupPending', handler);
			} else {
				window.setTimeout(handler, 800);
			}
		});
	}

	function isPickupRateValue(value) {
		value = String(value || '');
		return value.indexOf(russianPostPickupFamily) !== -1;
	}

	function normalizeShippingMethodValue(value) {
		return String(value || '').replace(/^wdc_platform:/, '');
	}

	function enableDestinationResetSuppression(timeout) {
		suppressNextDestinationReset = true;
		window.clearTimeout(suppressDestinationResetTimer);
		suppressDestinationResetTimer = window.setTimeout(disableDestinationResetSuppression, timeout || 15000);
	}

	function disableDestinationResetSuppression() {
		suppressNextDestinationReset = false;
		window.clearTimeout(suppressDestinationResetTimer);
		suppressDestinationResetTimer = 0;
	}

	function beginControlledLocationChange() {
		suppressPickupResetOnNextLocationSelected = true;
		window.clearTimeout(suppressPickupResetOnNextLocationSelectedTimer);
		suppressPickupResetOnNextLocationSelectedTimer = window.setTimeout(consumeControlledLocationChange, 5000);
	}

	function consumeControlledLocationChange() {
		suppressPickupResetOnNextLocationSelected = false;
		window.clearTimeout(suppressPickupResetOnNextLocationSelectedTimer);
		suppressPickupResetOnNextLocationSelectedTimer = 0;
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
		debugDeep('prefetch cache key', key);
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
		return shippingMethodFamily(method) === russianPostPickupFamily;
	}

	function toggleForMethod(container) {
		var method = currentShippingMethod();
		activeMethod = method || activeMethod;
		var visible = !method || shippingMethodFamily(method) === russianPostPickupFamily;
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

	function selectedPickupPointId() {
		var selected = window.wdcPickupCheckout && window.wdcPickupCheckout.selectedPickupPoint;
		if (selected && selected.id) {
			return selected.id;
		}
		var field = document.querySelector('[data-wdc-pickup-point-id]');
		return field ? field.value : '';
	}

	function syncSelectedPickupRate(method) {
		var pointId = selectedPickupPointId();
		if (!pointId || !window.WDCPickupApi || !window.WDCPickupApi.save) {
			return;
		}
		window.WDCPickupApi.save(pointId, method).catch(function () {});
	}

	function beginPlaceOrder() {
		isPlacingOrder = true;
		window.clearTimeout(placeOrderGuardTimer);
		placeOrderResetGuardUntil = Date.now() + 3000;
		debug('place order guard active');
	}

	function endPlaceOrder() {
		isPlacingOrder = false;
		debug('place order guard released');
		rememberDestinationFingerprint();
	}

	function releasePlaceOrderGuardSoon() {
		window.clearTimeout(placeOrderGuardTimer);
		placeOrderResetGuardUntil = Date.now() + 2000;
		placeOrderGuardTimer = window.setTimeout(function () {
			isPlacingOrder = false;
			placeOrderResetGuardUntil = 0;
			rememberDestinationFingerprint();
			debug('place order guard released');
		}, 2000);
	}

	function placeOrderResetGuardActive() {
		return Date.now() < placeOrderResetGuardUntil;
	}

	function isPickupResetGuarded() {
		return isPlacingOrder || placeOrderResetGuardActive();
	}

	function restoreSelectedPickupUi() {
		var selected = window.wdcPickupCheckout && window.wdcPickupCheckout.selectedPickupPoint;
		if (!selected || !selected.point_code || shippingMethodFamily(currentShippingMethod()) !== russianPostPickupFamily) {
			return;
		}
		document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(function (container) {
			applySelection(container, selected);
		});
	}

	document.addEventListener('change', function (event) {
		if (event.target.matches('#billing_city, #shipping_city, #billing_country, #shipping_country, #billing_postcode, #shipping_postcode, [name="billing_city"], [name="shipping_city"], [name="billing_country"], [name="shipping_country"], [name="billing_postcode"], [name="shipping_postcode"]')) {
			invalidatePrefetch();
			var newFingerprint = destinationFingerprint(contextFromFields());
			if (isPickupResetGuarded() || suppressNextDestinationReset || newFingerprint === lastDestinationFingerprint) {
				document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
				return;
			}
			lastDestinationFingerprint = newFingerprint;
			resetSelection('destination_changed');
			document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
			return;
		}
		if (event.target.matches('input[name^="shipping_method"]')) {
			var previousMethod = activeMethod;
			var nextMethod = currentShippingMethod() || normalizeShippingMethod(event.target.value);
			var previousFamily = shippingMethodFamily(previousMethod);
			var nextFamily = shippingMethodFamily(nextMethod);
			debug('shipping method change', {
				previousMethod: previousMethod,
				nextMethod: nextMethod,
				previousFamily: previousFamily,
				nextFamily: nextFamily,
				isPlacingOrder: isPlacingOrder
			});
			if (isSamePickupMethodFamily(previousMethod, nextMethod)) {
				activeMethod = nextMethod;
				syncSelectedPickupRate(nextMethod);
				document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
				return;
			}
			activeMethod = nextMethod;
			if (!isPickupResetGuarded() && previousFamily === russianPostPickupFamily && nextFamily !== russianPostPickupFamily) {
				resetSelection('method_family_changed');
			}
			document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
		}
	});
	document.body.addEventListener('wdc:location-selected', function (event) {
		var context = contextFromLocationDetail(event.detail || {});
		debugDeep('wdc:location-selected detail', event.detail || {});
		invalidatePrefetch();
		var previousContext = Object.assign({}, currentContext || {});
		var fieldContext = contextFromFields();
		var previousHasIdentity = !!destinationFingerprint(previousContext);
		var sameLocation = sameLocationContext(previousContext, context) || (!previousHasIdentity && !selectedPickupPointId() && sameLocationContext(fieldContext, context));
		updateCurrentContext(context);
		applyContextToHidden(context);
		var newFingerprint = destinationFingerprint(context);
		if (suppressPickupResetOnNextLocationSelected) {
			consumeControlledLocationChange();
			rememberDestinationFingerprint(context);
			schedulePrefetch();
			return;
		}
		if (isPickupResetGuarded() || sameLocation || newFingerprint === lastDestinationFingerprint) {
			rememberDestinationFingerprint(context);
			schedulePrefetch();
			return;
		}
		lastDestinationFingerprint = newFingerprint;
		resetPickupSelectionOnServer('location_changed');
		clearPickupSelectionUi('location_changed');
		schedulePrefetch();
	});
	document.addEventListener('DOMContentLoaded', boot);
	document.addEventListener('click', function (event) {
		if (event.target && event.target.matches('#place_order, [name="woocommerce_checkout_place_order"]')) {
			beginPlaceOrder();
		}
	});
	document.addEventListener('submit', function (event) {
		if (event.target && event.target.matches('form.checkout')) {
			beginPlaceOrder();
		}
	}, true);
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_place_order', beginPlaceOrder);
		window.jQuery(document.body).on('checkout_error', releasePlaceOrderGuardSoon);
		window.jQuery(document.body).on('updated_checkout', function () {
			boot();
			restoreSelectedPickupUi();
			if (isPlacingOrder) {
				debug('updated_checkout skipped context refresh during place order');
				releasePlaceOrderGuardSoon();
				return;
			}
			refreshCheckoutContext();
		});
	}
})(window, document);
