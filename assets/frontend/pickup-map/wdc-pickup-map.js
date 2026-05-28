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
		if (!window.L) {
			card.textContent = 'Leaflet is not available.';
			return { destroy: function () {} };
		}

		var selected = null;
		var markers = [];
		var controller = null;
		var suppressNextMoveLoad = false;
		var context = initialContext || {};
		var preloadedPoints = Array.isArray(context.preloadedPoints) ? context.preloadedPoints : [];
		var initialLat = parseFloat(context.centerLat || context.lat || (preloadedPoints[0] && preloadedPoints[0].lat));
		var initialLng = parseFloat(context.centerLng || context.lng || (preloadedPoints[0] && preloadedPoints[0].lng));
		var hasInitialCoordinates = !isNaN(initialLat) && !isNaN(initialLng);
		var hasInitialQuery = !!(context.query && String(context.query).trim());
		var map = window.L.map(element);
		if (hasInitialCoordinates) {
			map.setView([initialLat, initialLng], 13);
		} else if (!hasInitialQuery) {
			map.setView([55.0302, 82.9204], 11);
		}
		window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap contributors',
			maxZoom: 19
		}).addTo(map);

		function clearMarkers() {
			markers.forEach(function (marker) { marker.remove(); });
			markers = [];
		}

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
			clearMarkers();
			if (!points.length) {
				card.textContent = emptyText || labels.empty || '';
				return;
			}
			points.forEach(function (point) {
				if (point.lat === null || point.lng === null) {
					return;
				}
				var marker = window.L.marker([point.lat, point.lng]).addTo(map);
				marker.bindPopup(escapeHtml(point.address || ''));
				marker.on('click', function () { select(point); });
				markers.push(marker);
			});
			card.textContent = labels.selectPoint || 'Выберите пункт на карте.';
		}

		function loadBounds() {
			var bounds = map.getBounds();
			var bbox = [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(',');
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

		var debouncedLoad = debounce(function () {
			if (suppressNextMoveLoad) {
				suppressNextMoveLoad = false;
				return;
			}
			loadBounds();
		}, 250);
		map.on('moveend zoomend', debouncedLoad);

		if (preloadedPoints.length) {
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
					map.setView([points[0].lat, points[0].lng], 15);
					preview(points[0], false);
					if (initial) {
						loadBounds();
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
			map.invalidateSize();
			if (hasInitialCoordinates) {
				loadBounds();
			} else if (hasInitialQuery) {
				initialSearch(String(context.query));
			} else {
				loadBounds();
			}
		}, 50);

		return {
			map: map,
			selected: function () { return selected; },
			search: search,
			destroy: function () {
				if (controller) {
					controller.abort();
				}
				clearMarkers();
				map.off('moveend zoomend', debouncedLoad);
				map.remove();
			}
		};
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
		});
	}

	window.WDCPickupMap = { create: createMap };
})(window);
