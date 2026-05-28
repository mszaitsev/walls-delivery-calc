(function (window) {
	'use strict';

	function create(container, options) {
		var settings = options || {};
		var center = settings.center || { lat: 55.0302, lng: 82.9204, zoom: 11 };
		var markers = [];
		var pointClickCallback = function () {};

		if (!window.L) {
			return unavailable('Leaflet is not available.');
		}

		var map = window.L.map(container);
		map.setView([center.lat, center.lng], center.zoom || 11);
		window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap contributors',
			maxZoom: 19
		}).addTo(map);

		function boundsChanged() {
			if (typeof settings.onBoundsChange !== 'function') {
				return;
			}
			var bounds = map.getBounds();
			settings.onBoundsChange([bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(','));
		}

		map.on('moveend zoomend', boundsChanged);

		return {
			create: create,
			setCenter: function (lat, lng, zoom) {
				map.setView([lat, lng], zoom || map.getZoom());
			},
			renderMarkers: function (points) {
				clearMarkers();
				(points || []).forEach(function (point) {
					if (point.lat === null || point.lng === null) {
						return;
					}
					var marker = window.L.marker([point.lat, point.lng]).addTo(map);
					marker.bindPopup(escapeHtml(point.address || ''));
					marker.on('click', function () { pointClickCallback(point); });
					markers.push(marker);
				});
			},
			clearMarkers: clearMarkers,
			fitToMarkers: function () {
				if (!markers.length) {
					return;
				}
				var group = window.L.featureGroup(markers);
				map.fitBounds(group.getBounds(), { padding: [24, 24] });
			},
			destroy: function () {
				clearMarkers();
				map.off('moveend zoomend', boundsChanged);
				map.remove();
			},
			onPointClick: function (callback) {
				pointClickCallback = typeof callback === 'function' ? callback : function () {};
			},
			invalidateSize: function () {
				map.invalidateSize();
			}
		};

		function clearMarkers() {
			markers.forEach(function (marker) { marker.remove(); });
			markers = [];
		}
	}

	function unavailable(message) {
		return {
			setCenter: function () {},
			renderMarkers: function () {},
			clearMarkers: function () {},
			fitToMarkers: function () {},
			destroy: function () {},
			onPointClick: function () {},
			invalidateSize: function () {}
		};
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
		});
	}

	window.WDCPickupMapProviders = window.WDCPickupMapProviders || {};
	window.WDCPickupMapProviders.leaflet = { create: create };
})(window);
