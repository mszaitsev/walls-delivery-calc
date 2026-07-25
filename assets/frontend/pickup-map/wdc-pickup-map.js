(function (window) {
	'use strict';

	function debounce(fn, wait) {
		var timer = 0;
		function debounced() {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(null, args); }, wait);
		}
		debounced.cancel = function () {
			clearTimeout(timer);
			timer = 0;
		};
		return debounced;
	}

	function createMap(element, card, confirmButton, labels, initialContext) {
		var config = window.wdcPickupCheckout || {};
		var providerName = normalizeProvider(config.mapProvider || 'leaflet');
		var providerFactory = window.WDCPickupMapProviders && window.WDCPickupMapProviders[providerName];
		var list = findList(element, card);
		var controller = null;
		var programmaticBoundsSuppressed = false;
		var programmaticBoundsReleaseTimer = 0;
		var userViewportInteracted = false;
		var destroyed = false;
		var context = initialContext || {};
		var initialSelectedPoint = normalizeInitialSelectedPoint(context.selectedPoint || context.selectedPickupPoint);
		var listSelectButton = createListSelectButton(list);
		var previewPoint = initialSelectedPoint;
		var committedPoint = initialSelectedPoint;
		var preloadedPoints = Array.isArray(context.preloadedPoints) ? context.preloadedPoints : [];
		var hasPreloadedPoints = preloadedPoints.length > 0;
		var selectedPointHasCoordinates = !!(initialSelectedPoint && validPointCoordinates(initialSelectedPoint));
		var selectedPointCoordinates = selectedPointHasCoordinates ? coordinatePair(initialSelectedPoint.lat, initialSelectedPoint.lng) : null;
		var canonicalDestinationCoordinates = coordinatePair(context.lat, context.lng);
		var explicitlyTrustedCenter = truthy(context.centerTrusted) ? coordinatePair(context.centerLat, context.centerLng) : null;
		var trustedInitialCoordinates = firstValidCoordinatePair([
			selectedPointCoordinates,
			canonicalDestinationCoordinates,
			explicitlyTrustedCenter
		]);
		var derivedPointCoordinates = trustedInitialCoordinates ? null : firstValidCoordinatePair([
			{ lat: context.centerLat, lng: context.centerLng },
			firstValidCoordinatePair(preloadedPoints)
		]);
		var initialCoordinates = trustedInitialCoordinates || derivedPointCoordinates;
		var initialLat = initialCoordinates ? initialCoordinates.lat : NaN;
		var initialLng = initialCoordinates ? initialCoordinates.lng : NaN;
		var hasTrustedInitialCoordinates = !!trustedInitialCoordinates;
		var hasInitialCoordinates = !!initialCoordinates;
		var initialPointsViewportApplied = hasTrustedInitialCoordinates || selectedPointHasCoordinates;
		var distanceOrigin = selectedPointCoordinates || canonicalDestinationCoordinates || explicitlyTrustedCenter || null;
		var searchAddress = null;
		var hasInitialQuery = !!(context.query && String(context.query).trim());
		var provider = null;
		var visiblePoints = [];
		var lastBbox = '';
		var yandexCityListMode = isYandexDeliveryContext(context);
		var popupManuallyClosed = false;
		var suppressNextMapClick = false;
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
			lastBbox = bbox || lastBbox;
			if (programmaticBoundsSuppressed) {
				scheduleProgrammaticBoundsRelease();
				return;
			}
			if (yandexCityListMode && visiblePoints.length) {
				renderCurrentList();
				updateListSelectButton();
				return;
			}
			debouncedLoad(bbox);
		}

		provider = providerFactory.create(element, {
			center: hasInitialCoordinates ? { lat: initialLat, lng: initialLng, zoom: 13 } : { lat: 55.0302, lng: 82.9204, zoom: 11 },
			yandexApiKey: config.yandexApiKey || '',
			labels: labels,
			onBoundsChange: boundsChanged
		});
		provider.onPointClick(function (point) {
			suppressNextMapClick = true;
			openPointPreviewFromMarker(point);
			window.setTimeout(function () { suppressNextMapClick = false; }, 0);
		});
		if (provider.onPopupSelect) {
			provider.onPopupSelect(function (point) { commit(point, { focus: false }); });
		}
		if (provider.onMapClick) {
			provider.onMapClick(function () {
				markUserViewportInteraction();
				if (suppressNextMapClick) {
					suppressNextMapClick = false;
					return;
				}
				markPopupManuallyClosed('map_click');
			});
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
		attachUserViewportListeners();

		function renderPointPopup(point, selected) {
			var rows = [];
			var title = pointDisplayTitle(point);
			var workTime = meaningfulText(point.work_time);
			var description = cleanDescription(point.description);
			var titleComment = presentationComment(point);
			if (title) {
				rows.push('<h3 class="wdc-pickup-popup__title">' + escapeHtml(title) + '</h3>');
			}
			if (titleComment) {
				rows.push('<div class="wdc-pickup-popup__title-comment">' + escapeHtml(titleComment) + '</div>');
			}
			rows.push('<div class="wdc-pickup-popup__type">' + escapeHtml(pointTypeLabel(point)) + '</div>');
			if (storageNotice(point)) {
				rows.push('<div class="wdc-pickup-popup__storage">' + escapeHtml(storageNotice(point)) + '</div>');
			}
			if (point.address) {
				rows.push('<div class="wdc-pickup-popup__section"><strong>Адрес:</strong><span>' + escapeHtml(point.address) + '</span></div>');
			}
			if (workTime) {
				rows.push('<div class="wdc-pickup-popup__section"><strong>График:</strong><span>' + escapeHtml(workTime) + '</span></div>');
			}
			if (description) {
				rows.push('<div class="wdc-pickup-popup__section"><strong>Описание:</strong><span>' + escapeHtml(description) + '</span></div>');
			}
			rows.push('<button type="button" class="button button-primary wdc-pickup-popup__select" data-wdc-pickup-popup-select data-wdc-point-id="' + escapeHtml(pointId(point)) + '"' + (selected ? ' disabled' : '') + '>' + escapeHtml(selected ? 'Выбран' : 'Выбрать этот пункт') + '</button>');
			return '<div class="wdc-pickup-popup">' + rows.join('') + '</div>';
		}

		function openPointPreviewFromMarker(point) {
			var selected = committedPoint && pointId(committedPoint) === pointId(point);
			popupManuallyClosed = false;
			previewPoint = point;
			card.textContent = committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Р’С‹Р±РµСЂРёС‚Рµ РїСѓРЅРєС‚ РЅР° РєР°СЂС‚Рµ РёР»Рё РІ СЃРїРёСЃРєРµ.');
			confirmButton.disabled = !committedPoint;
			if (provider.setActivePoint) {
				provider.setActivePoint(pointId(point));
			}
			renderCurrentList();
			updateListSelectButton();
			if (provider.openPointPopup) {
				provider.openPointPopup(point, renderPointPopup(point, selected), { ensureVisible: false, forceReopen: true });
			}
			scrollListRowIntoView(point);
		}

		function preview(point, options) {
			options = options || {};
			previewPoint = point;
			if (options.userAction || options.initial) {
				popupManuallyClosed = false;
			}
			if (options.forcePopup) {
				popupManuallyClosed = false;
			}
			card.textContent = committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.');
			confirmButton.disabled = !committedPoint;
			if (provider.setActivePoint) {
				provider.setActivePoint(pointId(point));
			}
			if (options.focus !== false && provider.focusPoint) {
				cancelPendingProviderFit();
				beginProgrammaticBoundsSuppression();
				provider.focusPoint(point);
			}
			renderCurrentList();
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
				cancelPendingProviderFit();
				beginProgrammaticBoundsSuppression();
				provider.focusPoint(point);
			}
			renderCurrentList();
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
			renderCurrentList();
			updateListSelectButton();
		}

		function renderMarkers(points, emptyText) {
			visiblePoints = sortPoints(enrichPoints(points || []));
			var matchingPreviewPoint = previewPoint ? matchingPoint(previewPoint, visiblePoints) : null;
			var previewLeftVisiblePoints = previewPoint && !matchingPreviewPoint;
			if (previewPoint && matchingPreviewPoint) {
				previewPoint = matchingPreviewPoint;
				if (committedPoint) {
					committedPoint = matchingPoint(committedPoint, visiblePoints) || committedPoint;
				}
			} else if (previewPoint) {
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
			renderCurrentList();
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

		function renderList(points, totalCount) {
			totalCount = typeof totalCount === 'number' ? totalCount : points.length;
			if (!list) {
				return;
			}
			if (!points.length) {
				list.innerHTML = [
					originStatus ? '<div class="wdc-pickup-list__status' + (originStatusType === 'error' ? ' is-error' : '') + '">' + escapeHtml(originStatus) + '</div>' : '',
					totalCount ? '<div class="wdc-pickup-list__meta">' + escapeHtml(listMeta(totalCount, 0)) + '</div>' : '',
					'<div class="wdc-pickup-list__empty">' + escapeHtml(labels.empty || '') + '</div>'
				].join('');
				return;
			}
			var nearest = points[0] && points[0].distanceText ? points[0].distanceText : '';
			list.innerHTML = [
				originStatus ? '<div class="wdc-pickup-list__status' + (originStatusType === 'error' ? ' is-error' : '') + '">' + escapeHtml(originStatus) + '</div>' : '',
				searchAddress ? '<div class="wdc-pickup-list__found"><strong>Найден адрес:</strong><span>' + escapeHtml(searchAddress.value || '') + '</span>' + (nearest ? '<em>Ближайший ПВЗ: ' + escapeHtml(nearest) + '</em>' : '') + '</div>' : '',
				'<div class="wdc-pickup-list__meta">' + escapeHtml(listMeta(totalCount, points.length)) + '</div>',
				'<div class="wdc-pickup-list__items">',
				points.map(renderListItem).join(''),
				'</div>'
			].join('');
		}

		function renderListItem(point, index) {
			var selected = committedPoint && pointId(committedPoint) === pointId(point);
			var previewed = previewPoint && pointId(previewPoint) === pointId(point);
			var active = previewed;
			var titleComment = presentationComment(point);
			return [
				'<div role="button" tabindex="0" class="wdc-pickup-list__item' + (active ? ' active' : '') + (selected ? ' selected' : '') + (previewed ? ' preview' : '') + '" data-wdc-point-id="' + escapeHtml(pointId(point)) + '">',
				'<span class="wdc-pickup-list__index">' + (index + 1) + '</span>',
				'<span class="wdc-pickup-list__content">',
				'<span class="wdc-pickup-list__headline"><strong>' + escapeHtml(pointDisplayTitle(point)) + '</strong>' + (point.distanceText ? '<em>' + escapeHtml(point.distanceText) + '</em>' : '') + '</span>',
				titleComment ? '<span class="wdc-pickup-list__title-comment">' + escapeHtml(titleComment) + '</span>' : '',
				point.address ? '<span class="wdc-pickup-list__address">' + escapeHtml(point.address) + '</span>' : '',
				point.work_time ? '<span class="wdc-pickup-list__time">' + escapeHtml(point.work_time) + '</span>' : '',
				storageNotice(point) ? '<span class="wdc-pickup-list__storage">' + escapeHtml(storageNotice(point)) + '</span>' : '',
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
					openPointPreviewFromMarker(point);
				}
			});
		}

		function loadBounds(bbox, options) {
			options = options || {};
			lastBbox = bbox || lastBbox;
			if (!bbox) {
				return;
			}
			if (yandexCityListMode && visiblePoints.length) {
				renderCurrentList();
				updateListSelectButton();
				return;
			}
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			card.textContent = labels.loading || 'Loading...';
			window.WDCPickupApi.points(bbox, controller.signal, context).then(function (points) {
				if (destroyed) {
					return;
				}
				renderMarkers(points, labels.empty || '');
				applyInitialPointsViewport(visiblePoints);
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

		function renderCurrentList() {
			renderList(listPointsForCurrentBounds(), visiblePoints.length);
		}

		function listPointsForCurrentBounds() {
			if (!yandexCityListMode || !lastBbox) {
				return visiblePoints;
			}
			return visiblePoints.filter(function (point) {
				return pointInsideBounds(point, lastBbox);
			});
		}

		if (hasPreloadedPoints) {
			renderMarkers(preloadedPoints, labels.empty || '');
			applyInitialPointsViewport(visiblePoints);
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
				return initialRequest(query, controller.signal, context).then(function (points) {
					if (destroyed) {
						return;
					}
					if (points[0] && validPointCoordinates(points[0])) {
						var point = enrichPoints([points[0]])[0];
						cancelPendingProviderFit();
						beginProgrammaticBoundsSuppression();
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
				return window.WDCPickupApi.search(query, controller.signal, context).then(function (points) {
					if (destroyed) {
						return;
					}
					renderMarkers(points, labels.empty || '');
					applyInitialPointsViewport(visiblePoints);
				});
			}
			card.textContent = labels.searchingAddress || 'Ищем адрес...';
			return window.WDCPickupApi.addressSearch(query, context, controller.signal).then(function (result) {
				if (result && result.address_search_available === false) {
					setPostcodeOnlyMode();
					if (!result.address) {
						card.textContent = labels.postcodeOnly || 'Поиск доступен только по индексу';
						return;
					}
				}
				if (destroyed) {
					return;
				}
				if (result && result.address && validPointCoordinates(result.address)) {
					applySearchResult(result);
					return;
				}
				card.textContent = result && result.error_code === 'dadata_api_failed' ? (labels.dadataError || 'Адрес не найден.') : (labels.addressNotFound || labels.notFound || 'Адрес не найден.');
			}).catch(function (error) {
				if (error.name !== 'AbortError') {
					card.textContent = labels.dadataError || labels.error || 'Адрес не найден.';
				}
			});
		}

		function applySearchResult(result) {
			searchAddress = normalizeAddressMarker(result.address);
			userLocation = null;
			originStatus = '';
			originStatusType = '';
			distanceOrigin = { lat: parseFloat(searchAddress.lat), lng: parseFloat(searchAddress.lng) };
			cancelPendingProviderFit();
			beginProgrammaticBoundsSuppression();
			provider.setCenter(searchAddress.lat, searchAddress.lng, 15);
			refreshDistancesFromOrigin();
			card.textContent = labels.addressFound || 'Адрес найден.';
		}

		setTimeout(function () {
			if (destroyed) {
				return;
			}
			provider.invalidateSize();
			if (hasPreloadedPoints) {
				return;
			}
			if (hasInitialCoordinates) {
				loadBounds(bboxAround(initialLat, initialLng));
			} else if (isCdekContext(context) && (context.city_code || context.cdek_city_code)) {
				loadBounds('city-code', { force: true });
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
				destroyed = true;
				if (controller) {
					controller.abort();
				}
				if (debouncedLoad.cancel) {
					debouncedLoad.cancel();
				}
				endProgrammaticBoundsSuppression();
				detachUserViewportListeners();
				cancelPendingProviderFit();
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

		function refreshDistancesFromOrigin() {
			visiblePoints = sortPoints(enrichPoints(visiblePoints));
			previewPoint = previewPoint ? matchingPoint(previewPoint, visiblePoints) : null;
			committedPoint = committedPoint ? matchingPoint(committedPoint, visiblePoints) || committedPoint : null;
			provider.renderMarkers(visiblePoints, {
				activePointId: previewPoint ? pointId(previewPoint) : null,
				searchMarker: activeOriginMarker()
			});
			renderCurrentList();
			updateListSelectButton();
		}

		function findPoint(id) {
			return visiblePoints.filter(function (point) { return pointId(point) === id; })[0] || null;
		}

		function matchingPoint(point, points) {
			var keys = pointMatchKeys(point);
			return points.filter(function (item) { return hasSharedPointKey(keys, pointMatchKeys(item)); })[0] || null;
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
			if (!validCoordinatePair(lat, lng)) {
				setStatus('Не удалось определить местоположение. Используйте поиск адреса.', 'error');
				return;
			}
			searchAddress = null;
			userLocation = normalizeUserLocationMarker({ lat: lat, lng: lng });
			distanceOrigin = { lat: lat, lng: lng };
			originStatus = 'Показаны ближайшие пункты к вашему местоположению';
			originStatusType = '';
			cancelPendingProviderFit();
			beginProgrammaticBoundsSuppression();
			provider.setCenter(lat, lng, 15);
			refreshDistancesFromOrigin();
			loadBounds(bboxAround(lat, lng), { force: true });
		}

		function setStatus(message, type) {
			originStatus = String(message || '');
			originStatusType = type === 'error' ? 'error' : '';
			card.textContent = originStatus || (committedPoint ? selectedSummary(committedPoint) : (labels.selectPoint || 'Выберите пункт на карте или в списке.'));
			renderCurrentList();
		}

		function activeOriginMarker() {
			return userLocation || searchAddress;
		}

		function applyInitialPointsViewport(points) {
			if (initialPointsViewportApplied || hasTrustedInitialCoordinates || selectedPointHasCoordinates || userViewportInteracted) {
				return;
			}
			var validPoints = (Array.isArray(points) ? points : []).filter(validPointCoordinates);
			if (!validPoints.length) {
				return;
			}
			initialPointsViewportApplied = true;
			cancelPendingProviderFit();
			beginProgrammaticBoundsSuppression();
			if (1 === validPoints.length) {
				provider.setCenter(validPoints[0].lat, validPoints[0].lng, 15);
				return;
			}
			if (provider.fitToMarkers) {
				provider.fitToMarkers({ padding: 32, maxZoom: 14 });
			}
		}

		function beginProgrammaticBoundsSuppression() {
			programmaticBoundsSuppressed = true;
			window.clearTimeout(programmaticBoundsReleaseTimer);
			programmaticBoundsReleaseTimer = 0;
		}

		function scheduleProgrammaticBoundsRelease() {
			window.clearTimeout(programmaticBoundsReleaseTimer);
			programmaticBoundsReleaseTimer = window.setTimeout(endProgrammaticBoundsSuppression, 120);
		}

		function endProgrammaticBoundsSuppression() {
			programmaticBoundsSuppressed = false;
			window.clearTimeout(programmaticBoundsReleaseTimer);
			programmaticBoundsReleaseTimer = 0;
		}

		function markUserViewportInteraction() {
			userViewportInteracted = true;
			endProgrammaticBoundsSuppression();
			cancelPendingProviderFit();
		}

		function cancelPendingProviderFit() {
			if (provider && typeof provider.cancelPendingFit === 'function') {
				provider.cancelPendingFit();
			}
		}

		function attachUserViewportListeners() {
			if (!element || !element.addEventListener) {
				return;
			}
			element.addEventListener('pointerdown', markUserViewportInteraction);
			element.addEventListener('touchstart', markUserViewportInteraction, supportsPassiveListeners() ? { passive: true } : false);
			element.addEventListener('wheel', markUserViewportInteraction, supportsPassiveListeners() ? { passive: true } : false);
			element.addEventListener('keydown', markUserViewportInteraction);
		}

		function detachUserViewportListeners() {
			if (!element || !element.removeEventListener) {
				return;
			}
			element.removeEventListener('pointerdown', markUserViewportInteraction);
			element.removeEventListener('touchstart', markUserViewportInteraction);
			element.removeEventListener('wheel', markUserViewportInteraction);
			element.removeEventListener('keydown', markUserViewportInteraction);
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

	function isYandexDeliveryContext(context) {
		context = context || {};
		return String(context.carrier || context.carrier_key || '').trim() === 'yandex_delivery'
			|| String(context.pickup_family || '').trim() === 'yandex_delivery:pickup';
	}

	function isCdekContext(context) {
		context = context || {};
		return String(context.carrier || context.carrier_key || '').trim() === 'cdek'
			|| String(context.pickup_family || '').trim() === 'cdek:pickup';
	}

	function pointInsideBounds(point, bbox) {
		var bounds = parseBounds(bbox);
		if (!bounds || !validPointCoordinates(point || {})) {
			return false;
		}
		var lat = parseFloat(point.lat);
		var lng = parseFloat(point.lng);
		return lng >= bounds.west && lng <= bounds.east && lat >= bounds.south && lat <= bounds.north;
	}

	function parseBounds(bbox) {
		var values = Array.isArray(bbox) ? bbox : String(bbox || '').split(',');
		if (values.length < 4) {
			return null;
		}
		var west = parseFloat(values[0]);
		var south = parseFloat(values[1]);
		var east = parseFloat(values[2]);
		var north = parseFloat(values[3]);
		if ([west, south, east, north].some(function (value) { return !isFiniteNumber(value); })) {
			return null;
		}
		return { west: west, south: south, east: east, north: north };
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
		var keys = pointMatchKeys(point);
		return points.some(function (item) { return hasSharedPointKey(keys, pointMatchKeys(item)); });
	}

	function pointMatchKeys(point) {
		var snapshot = pointSnapshot(point);
		var values = [
			point && point.id,
			point && point.point_id,
			point && point.point_code,
			point && point.cdek_code,
			point && point.delivery_point,
			point && point.postcode,
			point && point.postal_code,
			point && point.point_postcode,
			point && point.display_code,
			snapshot.id,
			snapshot.point_id,
			snapshot.point_code,
			snapshot.cdek_code,
			snapshot.delivery_point,
			snapshot.postcode,
			snapshot.postal_code,
			snapshot.point_postcode,
			snapshot.display_code
		];
		var keys = [];
		values.forEach(function (value) {
			var key = String(value || '').trim();
			if (key && keys.indexOf(key) === -1) {
				keys.push(key);
			}
		});
		return keys;
	}

	function hasSharedPointKey(left, right) {
		if (!left.length || !right.length) {
			return false;
		}
		return left.some(function (key) { return right.indexOf(key) !== -1; });
	}

	function normalizeInitialSelectedPoint(point) {
		if (!point || typeof point !== 'object') {
			return null;
		}
		var snapshot = pointSnapshot(point);
		var normalized = Object.assign({}, snapshot, point);
		normalized.id = normalized.id || snapshot.id;
		normalized.point_id = normalized.point_id || snapshot.point_id;
		normalized.point_code = normalized.point_code || snapshot.point_code;
		normalized.delivery_point = normalized.delivery_point || snapshot.delivery_point;
		normalized.display_code = normalized.display_code || snapshot.display_code;
		normalized.point_type = normalized.point_type || snapshot.point_type;
		normalized.postcode = normalized.postcode || normalized.postal_code || normalized.point_postcode || normalized.display_code || snapshot.postcode || snapshot.postal_code || snapshot.point_postcode || snapshot.display_code;
		normalized.postal_code = normalized.postal_code || normalized.postcode || snapshot.postal_code || snapshot.display_code;
		normalized.point_postcode = normalized.point_postcode || normalized.postcode || snapshot.point_postcode || snapshot.display_code;
		normalized.address = normalized.address || snapshot.address;
		normalized.lat = normalized.lat !== undefined && normalized.lat !== null ? normalized.lat : snapshot.lat;
		normalized.lng = normalized.lng !== undefined && normalized.lng !== null ? normalized.lng : snapshot.lng;
		normalized.work_time = firstMeaningfulText(normalized.work_time, snapshot.work_time);
		normalized.description = firstCleanDescription(normalized.description, snapshot.description);
		normalized.storage_notice = firstCleanDescription(normalized.storage_notice, snapshot.storage_notice);
		normalized.marker_type = normalized.marker_type || snapshot.marker_type;
		normalized.point_title = normalized.point_title || normalized.card_title || snapshot.point_title || snapshot.card_title;
		normalized.point_type_label = normalized.point_type_label || snapshot.point_type_label;
		normalized.cdek_code = normalized.cdek_code || snapshot.cdek_code;
		return pointId(normalized) ? normalized : null;
	}

	function pointSnapshot(point) {
		var snapshot = point && point.snapshot;
		if (snapshot && typeof snapshot === 'object') {
			return snapshot;
		}
		if (typeof snapshot === 'string' && snapshot.trim()) {
			try {
				var parsed = JSON.parse(snapshot);
				return parsed && typeof parsed === 'object' ? parsed : {};
			} catch (error) {
				return {};
			}
		}
		return {};
	}

	function selectedSummary(point) {
		return 'Выбран: ' + [pointDisplayCode(point), point.address || ''].filter(Boolean).join(', ');
	}

	function presentationComment(point) {
		var snapshot = pointSnapshot(point);
		return meaningfulText(point && point.presentation_comment) || meaningfulText(snapshot.presentation_comment);
	}

	function carrierTitle(point) {
		return String((point && (point.point_title || point.card_title || point.point_type_label)) || '').trim() || 'Пункт выдачи';
	}

	function pointDisplayTitle(point) {
		var cdekTitle = cdekDisplayTitle(point);
		if (cdekTitle) {
			return cdekTitle;
		}
		return String((point && (point.display_title || (point.snapshot && point.snapshot.display_title))) || '').trim()
			|| [carrierTitle(point), pointDisplayCode(point)].filter(Boolean).join(' ');
	}

	function cdekDisplayTitle(point) {
		var carrier = String(point && (point.carrier_key || point.carrier || (point.snapshot && (point.snapshot.carrier_key || point.snapshot.carrier))) || '').trim();
		if (carrier !== 'cdek') {
			return '';
		}
		var type = String(point.point_type || point.type || point.cdek_type || point.marker_type || (point.snapshot && (point.snapshot.point_type || point.snapshot.type || point.snapshot.cdek_type || point.snapshot.marker_type)) || '').toLowerCase();
		var label = type === 'postamat' || type === 'postomat' || type === 'locker' ? 'Постамат СДЭК' : 'ПВЗ СДЭК';
		return [label, pointDisplayCode(point)].filter(Boolean).join(' ');
	}

	function pointDisplayCode(point) {
		if (!point) {
			return '';
		}
		if (point.display_code || (point.snapshot && point.snapshot.display_code)) {
			return String(point.display_code || (point.snapshot && point.snapshot.display_code) || '').trim();
		}
		var carrier = String(point.carrier_key || point.carrier || (point.snapshot && (point.snapshot.carrier_key || point.snapshot.carrier)) || '').trim();
		if (carrier === 'yandex_delivery') {
			return '';
		}
		if (carrier === 'russian_post_domestic' || carrier === 'russian_post') {
			return String(point.postcode || point.postal_code || (point.snapshot && point.snapshot.postcode) || '').trim();
		}
		if (carrier === 'cdek') {
			return String(point.cdek_code || point.point_code || (point.snapshot && (point.snapshot.cdek_code || point.snapshot.point_code)) || '').trim();
		}
		return String(point.point_code || point.postal_code || point.postcode || '').trim();
	}

	function validPointCoordinates(point) {
		var lat = parseFloat(point && point.lat);
		var lng = parseFloat(point && point.lng);
		return validCoordinatePair(lat, lng);
	}

	function validCoordinatePair(lat, lng) {
		lat = parseFloat(lat);
		lng = parseFloat(lng);
		if (!isFiniteNumber(lat) || !isFiniteNumber(lng)) {
			return false;
		}
		if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
			return false;
		}
		return !(Math.abs(lat) < 0.000001 && Math.abs(lng) < 0.000001);
	}

	function coordinatePair(lat, lng) {
		if (!validCoordinatePair(lat, lng)) {
			return null;
		}
		return { lat: parseFloat(lat), lng: parseFloat(lng) };
	}

	function isFiniteNumber(value) {
		return typeof Number.isFinite === 'function' ? Number.isFinite(value) : isFinite(value);
	}

	function truthy(value) {
		return value === true || value === 1 || value === '1' || value === 'true';
	}

	function supportsPassiveListeners() {
		return false;
	}

	function firstValidCoordinatePair(items) {
		var list = Array.isArray(items) ? items : [];
		for (var i = 0; i < list.length; i++) {
			if (!list[i] || !validPointCoordinates(list[i])) {
				continue;
			}
			return { lat: parseFloat(list[i].lat), lng: parseFloat(list[i].lng) };
		}
		return null;
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
		if (point && point.point_type_label) {
			return point.point_type_label;
		}
		if (point && point._wdcTypeLabel) {
			return point._wdcTypeLabel;
		}
		var type = pickupPointType(point);
		var config = pickupPointTypeConfig(type);
		return config.label || defaultPointTypeConfig(type).label;
	}

	function pickupPointType(point) {
		var markerType = String((point && point.marker_type) || '').toLowerCase();
		if (markerType === 'postamat' || markerType === 'postomat' || markerType === 'locker') {
			return 'POSTAMAT';
		}
		if (markerType === 'pickup') {
			return 'PVZ';
		}
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
		if (type === 'POSTAMAT') {
			return { enabled: true, label: 'Постамат' };
		}
		return { enabled: true, label: 'Пункт выдачи' };
	}

	function storageNotice(point) {
		if (!point) {
			return '';
		}
		var notice = cleanDescription(point.storage_notice);
		if (notice) {
			return notice;
		}
		return '';
	}

	function cleanDescription(value) {
		var text = String(value || '').trim();
		if (!text || text === '0.000000' || /^0(?:\.0+)?$/.test(text) || /^[\d.,\s-]+$/.test(text)) {
			return '';
		}
		return text;
	}

	function meaningfulText(value) {
		if (value === null || value === undefined || Array.isArray(value) || typeof value === 'object') {
			return '';
		}
		var text = String(value).trim();
		if (!text) {
			return '';
		}
		var normalized = text.replace(',', '.');
		return !isNaN(normalized) && parseFloat(normalized) === 0 ? '' : text;
	}

	function firstMeaningfulText() {
		for (var i = 0; i < arguments.length; i++) {
			var text = meaningfulText(arguments[i]);
			if (text) {
				return text;
			}
		}
		return '';
	}

	function firstCleanDescription() {
		for (var i = 0; i < arguments.length; i++) {
			var text = cleanDescription(arguments[i]);
			if (text) {
				return text;
			}
		}
		return '';
	}

	function listMeta(total, shown) {
		if (total !== shown) {
			return 'Показано ' + shown + ' из ' + total;
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
			setStatus: function () {},
			useUserLocation: function () {},
			destroy: function () {}
		};
	}

	window.WDCPickupMap = { create: createMap };
})(window);
