(function (window) {
	'use strict';

	function create(container, options) {
		var settings = options || {};
		var center = settings.center || { lat: 55.0302, lng: 82.9204, zoom: 11 };
		var markers = [];
		var markerById = {};
		var activePointId = null;
		var pointClickCallback = function () {};

		if (!window.L) {
			return unavailable('Leaflet is not available.');
		}

		var map = window.L.map(container, {
			attributionControl: false
		});
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
			focusPoint: function (point) {
				if (point && point.lat !== null && point.lng !== null) {
					map.setView([point.lat, point.lng], Math.max(map.getZoom(), 15));
				}
			},
			setActivePoint: function (pointId) {
				activePointId = pointId ? String(pointId) : null;
				updateActiveMarkers();
			},
			renderMarkers: function (points, options) {
				clearMarkers();
				activePointId = options && options.activePointId ? String(options.activePointId) : activePointId;
				renderClustered(points || []);
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

		function renderClustered(points) {
			var clusters = clusterPoints(points);
			clusters.forEach(function (cluster) {
				if (cluster.points.length > 1) {
					var clusterMarker = window.L.marker([cluster.lat, cluster.lng], {
						icon: window.L.divIcon({
							className: 'wdc-map-cluster-icon',
							html: '<span class="wdc-map-cluster">' + cluster.points.length + '</span>',
							iconSize: [38, 38],
							iconAnchor: [19, 19]
						})
					}).addTo(map);
					clusterMarker.on('click', function () {
						map.fitBounds(window.L.latLngBounds(cluster.points.map(function (point) {
							return [point.lat, point.lng];
						})), { padding: [32, 32], maxZoom: Math.max(map.getZoom() + 1, 15) });
					});
					markers.push(clusterMarker);
					return;
				}
				cluster.points.forEach(function (point) {
					if (point.lat === null || point.lng === null) {
						return;
					}
					var id = pointId(point);
					var marker = window.L.marker([point.lat, point.lng], {
						icon: pointIcon(point, activePointId === id)
					}).addTo(map);
					marker.bindPopup(escapeHtml(point.address || ''));
					marker.on('click', function () { pointClickCallback(point); });
					marker._wdcPointId = id;
					marker._wdcPoint = point;
					markers.push(marker);
					markerById[id] = marker;
				});
			});
		}

		function clearMarkers() {
			markers.forEach(function (marker) { marker.remove(); });
			markers = [];
			markerById = {};
		}

		function updateActiveMarkers() {
			Object.keys(markerById).forEach(function (id) {
				var marker = markerById[id];
				if (marker && marker.setIcon && marker._wdcPoint) {
					marker.setIcon(pointIcon(marker._wdcPoint, activePointId === id));
				}
			});
			markers.forEach(function (marker) {
				if (marker._wdcPointId && marker.setIcon) {
					marker.setIcon(pointIcon(marker._wdcPoint, activePointId === marker._wdcPointId));
				}
			});
		}

		function pointIcon(point, active) {
			var type = pointType(point);
			return window.L.divIcon({
				className: 'wdc-map-marker-icon',
				html: '<span class="wdc-map-marker wdc-map-marker--' + type.toLowerCase() + (active ? ' is-active' : '') + '">' + escapeHtml(pointMarkerLabel(point)) + '</span>',
				iconSize: [34, 34],
				iconAnchor: [17, 17],
				popupAnchor: [0, -18]
			});
		}

		function clusterPoints(points) {
			var cells = {};
			var size = 64;
			points.forEach(function (point) {
				if (point.lat === null || point.lng === null) {
					return;
				}
				var projected = map.latLngToLayerPoint([point.lat, point.lng]);
				var key = Math.floor(projected.x / size) + ':' + Math.floor(projected.y / size);
				if (!cells[key]) {
					cells[key] = [];
				}
				cells[key].push(point);
			});
			return Object.keys(cells).map(function (key) {
				var cellPoints = cells[key];
				var lat = 0;
				var lng = 0;
				cellPoints.forEach(function (point) {
					lat += parseFloat(point.lat);
					lng += parseFloat(point.lng);
				});
				return { points: cellPoints, lat: lat / cellPoints.length, lng: lng / cellPoints.length };
			});
		}
	}

	function unavailable(message) {
		return {
			setCenter: function () {},
			focusPoint: function () {},
			setActivePoint: function () {},
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

	function pointId(point) {
		return String(point && (point.id || point.point_code || point.postcode || point.address) || '');
	}

	function pointType(point) {
		var type = String(point.point_type || point.type || 'OPS').toUpperCase();
		return type === 'PVZ' || type === 'APS' ? type : 'OPS';
	}

	function pointMarkerLabel(point) {
		if (point && point._wdcMarkerLabel) {
			return point._wdcMarkerLabel;
		}
		var type = pointType(point);
		if (type === 'APS') {
			return 'Почтомат';
		}
		if (type === 'PVZ') {
			return 'ПВЗ';
		}
		return 'ОПС';
	}

	window.WDCPickupMapProviders = window.WDCPickupMapProviders || {};
	window.WDCPickupMapProviders.leaflet = { create: create };
})(window);
