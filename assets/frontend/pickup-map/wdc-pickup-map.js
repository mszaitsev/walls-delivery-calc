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
		var context = initialContext || {};
		var initialLat = parseFloat(context.lat);
		var initialLng = parseFloat(context.lng);
		var hasInitialCoordinates = !isNaN(initialLat) && !isNaN(initialLng);
		var map = window.L.map(element).setView(hasInitialCoordinates ? [initialLat, initialLng] : [55.0302, 82.9204], hasInitialCoordinates ? 13 : 11);
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

		function loadBounds() {
			var bounds = map.getBounds();
			var bbox = [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(',');
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			card.textContent = labels.loading || 'Loading...';
			window.WDCPickupApi.points(bbox, controller.signal).then(function (points) {
				clearMarkers();
				if (!points.length) {
					card.textContent = labels.empty || '';
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
				card.textContent = labels.empty || '';
			}).catch(function (error) {
				if (error.name !== 'AbortError') {
					card.textContent = labels.error || 'Error';
				}
			});
		}

		var debouncedLoad = debounce(loadBounds, 250);
		map.on('moveend zoomend', debouncedLoad);

		function search(query) {
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			window.WDCPickupApi.search(query, controller.signal).then(function (points) {
				if (points[0] && points[0].lat !== null && points[0].lng !== null) {
					map.setView([points[0].lat, points[0].lng], 15);
					preview(points[0], false);
				}
			}).catch(function () {});
		}

		setTimeout(function () {
			map.invalidateSize();
			loadBounds();
			if (!hasInitialCoordinates && context.query) {
				search(String(context.query));
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
