(function (window) {
	'use strict';

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
		var selected = null;
		var controller = null;
		var suppressNextMoveLoad = false;
		var context = initialContext || {};
		var preloadedPoints = Array.isArray(context.preloadedPoints) ? context.preloadedPoints : [];
		var hasPreloadedPoints = preloadedPoints.length > 0;
		var initialLat = parseFloat(context.centerLat || context.lat || (preloadedPoints[0] && preloadedPoints[0].lat));
		var initialLng = parseFloat(context.centerLng || context.lng || (preloadedPoints[0] && preloadedPoints[0].lng));
		var hasInitialCoordinates = !isNaN(initialLat) && !isNaN(initialLng);
		var hasInitialQuery = !!(context.query && String(context.query).trim());
		var provider = null;

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
		provider.onPointClick(function (point) { select(point); });

		function renderPoint(point) {
			var parts = [
				'<strong>' + escapeHtml(point.address || '') + '</strong>',
				'<span>' + escapeHtml(point.postal_code || point.postcode || '') + '</span>',
				'<span>' + escapeHtml(point.point_type || '') + '</span>',
				'<span>' + escapeHtml(point.work_time || '') + '</span>',
				'<span>' + escapeHtml(point.description || '') + '</span>'
			];
			return parts.join('');
		}

		function preview(point, allowConfirm) {
			selected = point;
			card.innerHTML = renderPoint(point);
			confirmButton.disabled = !allowConfirm;
			if (allowConfirm) {
				confirmButton.dispatchEvent(new CustomEvent('wdc:point-selected', { detail: point }));
			}
		}

		function select(point) {
			preview(point, true);
		}

		function renderMarkers(points, emptyText) {
			provider.renderMarkers(points);
			if (!points.length) {
				card.textContent = emptyText || labels.empty || '';
				return;
			}
			card.textContent = labels.selectPoint || 'Выберите пункт на карте.';
		}

		function loadBounds(bbox) {
			if (!bbox) {
				return;
			}
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			card.textContent = labels.loading || 'Loading...';
			window.WDCPickupApi.points(bbox, controller.signal).then(function (points) {
				renderMarkers(points, labels.empty || '');
			}).catch(function (error) {
				if (error.name !== 'AbortError') {
					card.textContent = labels.error || 'Error';
				}
			});
		}

		var debouncedLoad = debounce(function (bbox) {
			if (suppressNextMoveLoad) {
				suppressNextMoveLoad = false;
				return;
			}
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
			var request = initial && window.WDCPickupApi.searchInitial ? window.WDCPickupApi.searchInitial : window.WDCPickupApi.search;
			return request(query, controller.signal).then(function (points) {
				if (points[0] && points[0].lat !== null && points[0].lng !== null) {
					suppressNextMoveLoad = true;
					provider.setCenter(points[0].lat, points[0].lng, 15);
					preview(points[0], false);
					if (initial) {
						loadBounds(bboxAround(points[0].lat, points[0].lng));
					}
					return;
				}
				card.textContent = labels.notFound || labels.empty || '';
			}).catch(function (error) {
				if (error.name !== 'AbortError') {
					card.textContent = labels.error || 'Error';
				}
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
			selected: function () { return selected; },
			search: search,
			destroy: function () {
				if (controller) {
					controller.abort();
				}
				provider.clearMarkers();
				provider.destroy();
			}
		};
	}

	function normalizeProvider(provider) {
		return 'yandex' === provider ? 'yandex' : 'leaflet';
	}

	function bboxAround(lat, lng) {
		var spread = 0.12;
		return [lng - spread, lat - spread, lng + spread, lat + spread].join(',');
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
