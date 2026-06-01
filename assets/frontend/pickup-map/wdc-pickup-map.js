(function (window) {
	'use strict';

	var LIST_LIMIT = 100;

	function debounce(fn, wait) {
		var timer = 0;
		return function () {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(null, args); }, wait);
		};
	}

	function createMap(element, card, confirmButton, labels, initialContext) {
		var config = window.wdcPickupCheckout || {};
		var providerName = normalizeProvider(config.mapProvider || 'leaflet');
		var providerFactory = window.WDCPickupMapProviders && window.WDCPickupMapProviders[providerName];
		var list = findList(element, card);
		var controller = null;
		var suppressNextMoveLoad = false;
		var context = initialContext || {};
		var initialSelectedPoint = normalizeInitialSelectedPoint(context.selectedPoint || context.selectedPickupPoint);
		var listSelectButton = createListSelectButton(list);
		var previewPoint = initialSelectedPoint;
		var committedPoint = initialSelectedPoint;
		var preloadedPoints = Array.isArray(context.preloadedPoints) ? context.preloadedPoints : [];
		var hasPreloadedPoints = preloadedPoints.length > 0;
		var initialLat = parseFloat(context.centerLat || context.lat || (preloadedPoints[0] && preloadedPoints[0].lat));
		var initialLng = parseFloat(context.centerLng || context.lng || (preloadedPoints[0] && preloadedPoints[0].lng));
		var hasInitialCoordinates = !isNaN(initialLat) && !isNaN(initialLng);
		var distanceOrigin = hasInitialCoordinates ? { lat: initialLat, lng: initialLng } : null;
		var searchAddress = null;
		var hasInitialQuery = !!(context.query && String(context.query).trim());
		var provider = null;
		var visiblePoints = [];
		var popupManuallyClosed = false;
		var userLocation = null;
		var originStatus = '';
		var originStatusType = '';

		if ('yandex' === providerName && !config.yandexApiKeyPresent) {
			card.textContent = (config.errors && config.errors.yandexApiKeyMissing) || 'Для Яндекс.Карт не задан API key. Выберите OpenStreetMap или укажите ключ в настройках.';
			return noopMap();
		}

		if (!providerFactory || typeof providerFactory.create !== 'function') {
			card.textContent = labels.error || 'Map provider is not available.';
			return noopMap();
		}

		function boundsChanged(bbox) {
			debouncedLoad(bbox);
		}

		provider = providerFactory.create(element, {
			center: hasInitialCoordinates ? { lat: initialLat, lng: initialLng, zoom: 13 } : { lat: 55.0302, lng: 82.9204, zoom: 11 },
			yandexApiKey: config.yandexApiKey || '',
			labels: labels,
			onBoundsChange: boundsChanged
		});
		provider.onPointClick(function (point) { preview(point, { focus: false, forcePopup: true, userAction: true }); });
		if (provider.onPopupSelect) {
			provider.onPopupSelect(function (point) { commit(point, { focus: false }); });
		}
		if (provider.onMapClick) {
			provider.onMapClick(function () { markPopupManuallyClosed('map_click'); });
		}
		if (provider.onPopupClose) {
			provider.onPopupClose(function () { markPopupManuallyClosed('popup_close'); });
		}
		if (listSelectButton) {
			listSelectButton.addEventListener('click', function () {
				if (previewPoint && !(committedPoint && pointId(previewPoint) === pointId(committedPoint))) {
					commit(previewPoint, { focus: false, ensureVisible: true });
				}
			});
		}

		function renderPointPopup(point, selected) {
			var rows = [];
			var title = ['Почта России', point.postal_code || point.postcode || point.point_code || ''].filter(Boolean).join(' ');
			if (title) {
				rows.push('<h3 class="wdc-pickup-popup__title">' + escapeHtml(title) + '</h3>');
			}
			rows.push('<div class="wdc-pickup-popup__type">' + escapeHtml(pointTypeLabel(point)) + '</div>');
			if (point.address) {
				rows.push('<div class="wdc-pickup-popup__section"><strong>Адрес:</strong><span>' + escapeHtml(point.address) + '</span></div>');
			}
			if (point.work_time) {
				rows.push('<div class="wdc-pickup-popup__section"><strong>График:</strong><span>' + escapeHtml(point.work_time) + '</span></div>');
			}
			if (cleanDescription(point.description)) {
				rows.push('<div class="wdc-pickup-popup__section"><strong>Описание:</strong><span>' + escapeHtml(cleanDescription(point.description)) + '</span></div>');
			}
			rows.push('<button type="button" class="button button-primary wdc-pickup-popup__select" data-wdc-pickup-popup-select data-wdc-point-id="' + escapeHtml(pointId(point)) + '"' + (selected ? ' disabled' : '') + '>' + escapeHtml(selected ? 'Выбран' : 'Выбрать этот пункт') + '</button>');
			return '<div class="wdc-pickup-popup">' + rows.join('') + '</div>';
		}

		function preview(point, options) {
			options = options || {};
			previewPoint = point;
			if (options.userAction || options.initial) {
				popupManuallyClosed = false;
			}
			card.textContent = committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.');
			confirmButton.disabled = !committedPoint;
			if (provider.setActivePoint) {
				provider.setActivePoint(pointId(point));
			}
			if (options.focus !== false && provider.focusPoint) {
				provider.focusPoint(point);
			}
			renderList(visiblePoints);
			updateListSelectButton();
			if (provider.openPointPopup && (!popupManuallyClosed || options.forcePopup)) {
				provider.openPointPopup(point, renderPointPopup(point, committedPoint && pointId(committedPoint) === pointId(point)), { ensureVisible: !!options.ensureVisible });
			}
			scrollListRowIntoView(point);
		}

		function commit(point, options) {
			options = options || {};
			committedPoint = point;
			previewPoint = point;
			popupManuallyClosed = false;
			card.textContent = selectedSummary(point);
			confirmButton.disabled = false;
			confirmButton.dispatchEvent(new CustomEvent('wdc:point-selected', { detail: point }));
			if (provider.setActivePoint) {
				provider.setActivePoint(pointId(point));
			}
			if (options.focus !== false && provider.focusPoint) {
				provider.focusPoint(point);
			}
			renderList(visiblePoints);
			updateListSelectButton();
			if (provider.openPointPopup) {
				provider.openPointPopup(point, renderPointPopup(point, true), { ensureVisible: !!options.ensureVisible });
			}
			scrollListRowIntoView(point);
		}

		function markPopupManuallyClosed(source) {
			popupManuallyClosed = true;
			if (source === 'map_click' && provider.closePopup) {
				provider.closePopup();
			}
			if (provider.setActivePoint) {
				provider.setActivePoint(previewPoint ? pointId(previewPoint) : null);
			}
			card.textContent = committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.');
			confirmButton.disabled = !committedPoint;
			renderList(visiblePoints);
			updateListSelectButton();
		}

		function renderMarkers(points, emptyText) {
			visiblePoints = sortPoints(enrichPoints(points || []));
			var previewLeftVisiblePoints = previewPoint && !pointInList(previewPoint, visiblePoints);
			if (previewPoint && !pointInList(previewPoint, visiblePoints)) {
				previewPoint = null;
			}
			if (previewLeftVisiblePoints && committedPoint && pointInList(committedPoint, visiblePoints)) {
				popupManuallyClosed = true;
			}
			if (!previewPoint && committedPoint) {
				previewPoint = matchingPoint(committedPoint, visiblePoints);
				if (previewPoint) {
					committedPoint = previewPoint;
				}
			}
			provider.renderMarkers(visiblePoints, {
				activePointId: previewPoint ? pointId(previewPoint) : null,
				searchMarker: activeOriginMarker()
			});
			renderList(visiblePoints);
			updateListSelectButton();
			if (!visiblePoints.length) {
				card.textContent = committedPoint ? selectedSummary(committedPoint) : (emptyText || labels.empty || '');
				confirmButton.disabled = !committedPoint;
				return;
			}
			if (previewPoint && pointInList(previewPoint, visiblePoints)) {
				card.textContent = committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.');
				confirmButton.disabled = !committedPoint;
				if (provider.setActivePoint) {
					provider.setActivePoint(pointId(previewPoint));
				}
				if (provider.openPointPopup && !popupManuallyClosed) {
					provider.openPointPopup(previewPoint, renderPointPopup(previewPoint, !!(committedPoint && pointId(previewPoint) === pointId(committedPoint))), { ensureVisible: true });
				}
				scrollListRowIntoView(previewPoint);
				return;
			}
			if (provider.setActivePoint) {
				provider.setActivePoint(null);
			}
			card.textContent = committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.');
			confirmButton.disabled = !committedPoint;
			updateListSelectButton();
		}

		function renderList(points) {
			if (!list) {
				return;
			}
			if (!points.length) {
				list.innerHTML = [
					originStatus ? '<div class="wdc-pickup-list__status' + (originStatusType === 'error' ? ' is-error' : '') + '">' + escapeHtml(originStatus) + '</div>' : '',
					'<div class="wdc-pickup-list__empty">' + escapeHtml(labels.empty || '') + '</div>'
				].join('');
				return;
			}
			var shown = points.slice(0, LIST_LIMIT);
			var nearest = shown[0] && shown[0].distanceText ? shown[0].distanceText : '';
			list.innerHTML = [
				originStatus ? '<div class="wdc-pickup-list__status' + (originStatusType === 'error' ? ' is-error' : '') + '">' + escapeHtml(originStatus) + '</div>' : '',
				searchAddress ? '<div class="wdc-pickup-list__found"><strong>Найден адрес:</strong><span>' + escapeHtml(searchAddress.value || '') + '</span>' + (nearest ? '<em>Ближайший ПВЗ: ' + escapeHtml(nearest) + '</em>' : '') + '</div>' : '',
				'<div class="wdc-pickup-list__meta">' + escapeHtml(listMeta(points.length, shown.length)) + '</div>',
				'<div class="wdc-pickup-list__items">',
				shown.map(renderListItem).join(''),
				'</div>'
			].join('');
		}

		function renderListItem(point, index) {
			var selected = committedPoint && pointId(committedPoint) === pointId(point);
			var previewed = previewPoint && pointId(previewPoint) === pointId(point);
			var active = previewed;
			return [
				'<div role="button" tabindex="0" class="wdc-pickup-list__item' + (active ? ' active' : '') + (selected ? ' selected' : '') + (previewed ? ' preview' : '') + '" data-wdc-point-id="' + escapeHtml(pointId(point)) + '">',
				'<span class="wdc-pickup-list__index">' + (index + 1) + '</span>',
				'<span class="wdc-pickup-list__content">',
				'<span class="wdc-pickup-list__headline"><strong>' + escapeHtml(pointTypeLabel(point)) + '</strong>' + (point.distanceText ? '<em>' + escapeHtml(point.distanceText) + '</em>' : '') + '</span>',
				point.address ? '<span class="wdc-pickup-list__address">' + escapeHtml(point.address) + '</span>' : '',
				point.work_time ? '<span class="wdc-pickup-list__time">' + escapeHtml(point.work_time) + '</span>' : '',
				'</span>',
				'</div>'
			].join('');
		}

		if (list) {
			list.addEventListener('click', function (event) {
				var row = event.target.closest('[data-wdc-point-id]');
				if (!row) {
					return;
				}
				var point = findPoint(row.getAttribute('data-wdc-point-id'));
				if (point) {
					preview(point, { focus: false, ensureVisible: true, userAction: true });
				}
			});
		}

		function loadBounds(bbox, options) {
			options = options || {};
			if (!bbox) {
				return;
			}
			if (!options.force && suppressNextMoveLoad) {
				suppressNextMoveLoad = false;
				return;
			}
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			card.textContent = labels.loading || 'Loading...';
			window.WDCPickupApi.points(bbox, controller.signal).then(function (points) {
				renderMarkers(points, labels.empty || '');
				if (options.previewNearest && visiblePoints[0]) {
					preview(visiblePoints[0], { focus: false, initial: true });
				}
			}).catch(function (error) {
				if (error.name !== 'AbortError') {
					card.textContent = labels.error || 'Error';
				}
			});
		}

		var debouncedLoad = debounce(function (bbox) {
			loadBounds(bbox);
		}, 250);

		if (hasPreloadedPoints) {
			renderMarkers(preloadedPoints, labels.empty || '');
		}

		function search(query) {
			return runSearch(query, false);
		}

		function initialSearch(query) {
			return runSearch(query, true);
		}

		function runSearch(query, initial) {
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			card.textContent = labels.loading || 'Loading...';
			if (initial) {
				var initialRequest = window.WDCPickupApi.searchInitial || window.WDCPickupApi.search;
				return initialRequest(query, controller.signal).then(function (points) {
					if (points[0] && points[0].lat !== null && points[0].lng !== null) {
						var point = enrichPoints([points[0]])[0];
						suppressNextMoveLoad = true;
						provider.setCenter(point.lat, point.lng, 15);
						preview(point, { focus: false, initial: true });
						loadBounds(bboxAround(point.lat, point.lng), { force: true });
						return;
					}
					card.textContent = labels.notFound || labels.empty || '';
				}).catch(function (error) {
					if (error.name !== 'AbortError') {
						card.textContent = labels.error || 'Error';
					}
				});
			}
			if (!window.WDCPickupApi.addressSearch) {
				return Promise.resolve();
			}
			return window.WDCPickupApi.addressSearch(query, context, controller.signal).then(function (result) {
				if (result && result.address_search_available === false) {
					setPostcodeOnlyMode();
					if (!result.address) {
						card.textContent = labels.postcodeOnly || 'Поиск доступен только по индексу';
						return;
					}
				}
				if (result && result.address && result.address.lat !== null && result.address.lng !== null) {
					applySearchResult(result);
					return;
				}
				card.textContent = result && result.error_code === 'dadata_api_failed' ? (labels.dadataError || 'Ошибка DaData') : (labels.addressNotFound || labels.notFound || '');
			}).catch(function (error) {
				if (error.name !== 'AbortError') {
					card.textContent = labels.dadataError || labels.error || 'Ошибка DaData';
				}
			});
		}

		function applySearchResult(result) {
			searchAddress = normalizeAddressMarker(result.address);
			userLocation = null;
			originStatus = '';
			originStatusType = '';
			distanceOrigin = { lat: parseFloat(searchAddress.lat), lng: parseFloat(searchAddress.lng) };
			suppressNextMoveLoad = true;
			provider.setCenter(searchAddress.lat, searchAddress.lng, 15);
			provider.renderMarkers(visiblePoints, {
				activePointId: previewPoint ? pointId(previewPoint) : null,
				searchMarker: activeOriginMarker()
			});
			loadBounds(bboxAround(searchAddress.lat, searchAddress.lng), {
				force: true,
				preserveSearchAddress: true
			});
		}

		setTimeout(function () {
			provider.invalidateSize();
			if (hasPreloadedPoints) {
				return;
			}
			if (hasInitialCoordinates) {
				loadBounds(bboxAround(initialLat, initialLng));
			} else if (hasInitialQuery) {
				initialSearch(String(context.query));
			} else {
				loadBounds(bboxAround(55.0302, 82.9204));
			}
		}, 50);

		return {
			selected: function () { return committedPoint; },
			search: search,
			setStatus: setStatus,
			useUserLocation: useUserLocation,
			destroy: function () {
				if (controller) {
					controller.abort();
				}
				provider.clearMarkers();
				if (provider.closePopup) {
					provider.closePopup();
				}
				provider.destroy();
			}
		};

		function enrichPoints(points) {
			return points.map(function (point, index) {
				var copy = Object.assign({}, point);
				copy._wdcOrder = index;
				copy._wdcTypeLabel = pointTypeLabel(copy);
				if (distanceOrigin && validPointCoordinates(copy)) {
					copy.distanceMeters = distanceMeters(distanceOrigin.lat, distanceOrigin.lng, parseFloat(copy.lat), parseFloat(copy.lng));
					copy.distanceText = formatDistance(copy.distanceMeters);
				}
				return copy;
			});
		}

		function sortPoints(points) {
			return points.slice().sort(function (a, b) {
				if (distanceOrigin) {
					var aDistance = typeof a.distanceMeters === 'number' ? a.distanceMeters : Infinity;
					var bDistance = typeof b.distanceMeters === 'number' ? b.distanceMeters : Infinity;
					if (aDistance !== bDistance) {
						return aDistance - bDistance;
					}
				}
				var aKey = String(a.postal_code || a.postcode || '') + '|' + String(a.address || '');
				var bKey = String(b.postal_code || b.postcode || '') + '|' + String(b.address || '');
				if (aKey < bKey) {
					return -1;
				}
				if (aKey > bKey) {
					return 1;
				}
				return a._wdcOrder - b._wdcOrder;
			});
		}

		function findPoint(id) {
			return visiblePoints.filter(function (point) { return pointId(point) === id; })[0] || null;
		}

		function matchingPoint(point, points) {
			var id = pointId(point);
			return points.filter(function (item) { return pointId(item) === id; })[0] || null;
		}

		function updateListSelectButton() {
			if (!listSelectButton) {
				return;
			}
			if (previewPoint && committedPoint && pointId(previewPoint) === pointId(committedPoint)) {
				listSelectButton.disabled = true;
				listSelectButton.textContent = 'Пункт выбран';
				return;
			}
			if (previewPoint) {
				listSelectButton.disabled = false;
				listSelectButton.textContent = 'Выбрать этот пункт';
				return;
			}
			listSelectButton.disabled = true;
			listSelectButton.textContent = committedPoint ? 'Пункт выбран' : 'Выберите пункт';
		}

		function scrollListRowIntoView(point) {
			var row = findListRow(pointId(point));
			if (!row || !list) {
				return;
			}
			var container = scrollContainerForList(list);
			if (!container) {
				return;
			}
			var rowRect = row.getBoundingClientRect();
			var containerRect = container.getBoundingClientRect();
			var rowTop = rowRect.top - containerRect.top + container.scrollTop;
			var rowBottom = rowRect.bottom - containerRect.top + container.scrollTop;
			var visibleTop = container.scrollTop;
			var visibleBottom = visibleTop + container.clientHeight;
			var nextTop = null;
			if (rowTop < visibleTop) {
				nextTop = Math.max(0, rowTop - 12);
			} else if (rowBottom > visibleBottom) {
				nextTop = Math.max(0, rowBottom - container.clientHeight + 12);
			}
			if (nextTop === null || Math.abs(nextTop - container.scrollTop) < 1) {
				return;
			}
			if (typeof container.scrollTo === 'function') {
				container.scrollTo({ top: nextTop, behavior: 'smooth' });
				return;
			}
			container.scrollTop = nextTop;
		}

		function findListRow(id) {
			if (!list) {
				return null;
			}
			var rows = list.querySelectorAll('[data-wdc-point-id]');
			for (var i = 0; i < rows.length; i++) {
				if (rows[i].getAttribute('data-wdc-point-id') === id) {
					return rows[i];
				}
			}
			return null;
		}

		function scrollContainerForList(start) {
			var fallback = null;
			var node = start;
			while (node && node !== document.body) {
				if (node.classList && (node.classList.contains('wdc-pickup-modal__list') || node.classList.contains('wdc-pickup-modal__side'))) {
					fallback = fallback || node;
				}
				if (node.scrollHeight > node.clientHeight) {
					return node;
				}
				node = node.parentNode;
			}
			return fallback || start;
		}

		function useUserLocation(lat, lng) {
			lat = parseFloat(lat);
			lng = parseFloat(lng);
			if (isNaN(lat) || isNaN(lng)) {
				setStatus('Не удалось определить местоположение. Используйте поиск адреса.', 'error');
				return;
			}
			searchAddress = null;
			userLocation = normalizeUserLocationMarker({ lat: lat, lng: lng });
			distanceOrigin = { lat: lat, lng: lng };
			originStatus = 'Показаны ближайшие пункты к вашему местоположению';
			originStatusType = '';
			suppressNextMoveLoad = true;
			provider.setCenter(lat, lng, 15);
			provider.renderMarkers(visiblePoints, {
				activePointId: previewPoint ? pointId(previewPoint) : null,
				searchMarker: activeOriginMarker()
			});
			renderList(visiblePoints);
			loadBounds(bboxAround(lat, lng), { force: true });
		}

		function setStatus(message, type) {
			originStatus = String(message || '');
			originStatusType = type === 'error' ? 'error' : '';
			card.textContent = originStatus || (committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.'));
			renderList(visiblePoints);
		}

		function activeOriginMarker() {
			return userLocation || searchAddress;
		}

	}

	function normalizeProvider(provider) {
		return 'yandex' === provider ? 'yandex' : 'leaflet';
	}

	function normalizeAddressMarker(address) {
		return {
			id: 'search-address',
			type: 'search',
			value: String(address.value || ''),
			lat: parseFloat(address.lat),
			lng: parseFloat(address.lng)
		};
	}

	function normalizeUserLocationMarker(location) {
		return {
			id: 'user-location',
			type: 'geolocation',
			value: '',
			lat: parseFloat(location.lat),
			lng: parseFloat(location.lng)
		};
	}

	function setPostcodeOnlyMode() {
		var input = document.querySelector('[data-wdc-search]');
		if (!input || input.dataset.wdcPostcodeOnly) {
			return;
		}
		input.dataset.wdcPostcodeOnly = '1';
		input.placeholder = 'Сейчас работает поиск только по почтовому индексу';
		input.inputMode = 'numeric';
		input.pattern = '\\d*';
		input.addEventListener('input', function () {
			input.value = input.value.replace(/\D+/g, '').slice(0, 6);
		});
	}

	function bboxAround(lat, lng) {
		var spread = 0.12;
		return [lng - spread, lat - spread, lng + spread, lat + spread].join(',');
	}

	function findList(element, card) {
		var root = element && element.closest ? element.closest('.wdc-pickup-modal__dialog') : null;
		return root ? root.querySelector('[data-wdc-list]') : (card.parentNode ? card.parentNode.querySelector('[data-wdc-list]') : null);
	}

	function createListSelectButton(list) {
		if (!list || !list.parentNode) {
			return null;
		}
		var footer = document.createElement('div');
		footer.className = 'wdc-pickup-list-footer';
		footer.innerHTML = '<button type="button" class="button button-primary wdc-pickup-list-footer__select" data-wdc-pickup-list-confirm disabled>Выберите пункт</button>';
		list.parentNode.insertBefore(footer, list.nextSibling);
		return footer.querySelector('[data-wdc-pickup-list-confirm]');
	}

	function pointId(point) {
		return String(point && (point.id || point.point_code || point.postcode || point.address) || '');
	}

	function pointInList(point, points) {
		var id = pointId(point);
		return points.some(function (item) { return pointId(item) === id; });
	}

	function normalizeInitialSelectedPoint(point) {
		if (!point || typeof point !== 'object') {
			return null;
		}
		var snapshot = point.snapshot && typeof point.snapshot === 'object' ? point.snapshot : {};
		var normalized = Object.assign({}, snapshot, point);
		normalized.id = normalized.id || snapshot.id;
		normalized.point_code = normalized.point_code || snapshot.point_code;
		normalized.point_type = normalized.point_type || snapshot.point_type;
		normalized.postcode = normalized.postcode || normalized.postal_code || snapshot.postcode;
		normalized.address = normalized.address || snapshot.address;
		normalized.lat = normalized.lat !== undefined && normalized.lat !== null ? normalized.lat : snapshot.lat;
		normalized.lng = normalized.lng !== undefined && normalized.lng !== null ? normalized.lng : snapshot.lng;
		normalized.work_time = normalized.work_time || snapshot.work_time;
		normalized.description = normalized.description || snapshot.description;
		return pointId(normalized) ? normalized : null;
	}

	function selectedSummary(point) {
		return 'Выбран: ' + [point.postal_code || point.postcode || '', point.address || ''].filter(Boolean).join(', ');
	}

	function validPointCoordinates(point) {
		var lat = parseFloat(point.lat);
		var lng = parseFloat(point.lng);
		return !isNaN(lat) && !isNaN(lng);
	}

	function distanceMeters(fromLat, fromLng, toLat, toLng) {
		var earth = 6371000;
		var dLat = radians(toLat - fromLat);
		var dLng = radians(toLng - fromLng);
		var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
			Math.cos(radians(fromLat)) * Math.cos(radians(toLat)) *
			Math.sin(dLng / 2) * Math.sin(dLng / 2);
		return Math.round(earth * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
	}

	function radians(value) {
		return value * Math.PI / 180;
	}

	function formatDistance(meters) {
		if (meters < 1000) {
			return Math.max(1, Math.round(meters)) + ' м';
		}
		return (Math.round(meters / 100) / 10).toFixed(1) + ' км';
	}

	function pointTypeLabel(point) {
		if (point && point._wdcTypeLabel) {
			return point._wdcTypeLabel;
		}
		var type = pickupPointType(point);
		var config = pickupPointTypeConfig(type);
		return config.label || defaultPointTypeConfig(type).label;
	}

	function pickupPointType(point) {
		var type = String(point.point_type || point.type || '').toUpperCase();
		return type === 'PVZ' || type === 'APS' ? type : 'OPS';
	}

	function pickupPointTypeConfig(type) {
		var types = window.wdcPickupCheckout && window.wdcPickupCheckout.pickupPointTypes;
		return types && types[type] ? types[type] : defaultPointTypeConfig(type);
	}

	function defaultPointTypeConfig(type) {
		if (type === 'PVZ') {
			return { enabled: true, label: 'Пункт выдачи' };
		}
		if (type === 'APS') {
			return { enabled: true, label: 'Почтомат' };
		}
		return { enabled: true, label: 'Отделение Почты России' };
	}

	function cleanDescription(value) {
		var text = String(value || '').trim();
		if (!text || text === '0.000000' || /^0(?:\.0+)?$/.test(text) || /^[\d.,\s-]+$/.test(text)) {
			return '';
		}
		return text;
	}

	function listMeta(total, shown) {
		if (total > shown) {
			return 'Показаны первые ' + shown + ' из ' + total;
		}
		return 'Пунктов: ' + total;
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
		});
	}

	function noopMap() {
		return {
			selected: function () { return null; },
			search: function () { return Promise.resolve(); },
			destroy: function () {}
		};
	}

	window.WDCPickupMap = { create: createMap };
})(window);
