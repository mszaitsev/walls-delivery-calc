(function (window) {
	'use strict';

	function create(container, options) {
		var settings = options || {};
		var center = settings.center || { lat: 55.0302, lng: 82.9204, zoom: 11 };
		var markers = [];
		var searchMarker = null;
		var markerById = {};
		var pointById = {};
		var activePointId = null;
		var lastPoints = [];
		var lastSearchMarker = null;
		var popupState = null;
		var pointClickCallback = function () {};
		var popupSelectCallback = function () {};
		var popupCloseCallback = function () {};
		var mapClickCallback = function () {};
		var maxClusterZoom = 18;
		var clusterCellSize = 128;
		var suppressPopupClose = false;

		if (!window.L) {
			return unavailable('Leaflet is not available.');
		}

		var map = window.L.map(container, {
			attributionControl: false
		});
		map.createPane('wdcSearchMarkerPane');
		map.getPane('wdcSearchMarkerPane').style.zIndex = 550;
		map.setView([center.lat, center.lng], center.zoom || 11);
		window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap contributors',
			maxZoom: 19
		}).addTo(map);

		function boundsChanged() {
			if (typeof settings.onBoundsChange !== 'function') {
				return;
			}
			var bounds = currentBoundsValue();
			if (!bounds) {
				return;
			}
			settings.onBoundsChange(bounds);
		}

		function currentBoundsValue() {
			if (!map || typeof map.getBounds !== 'function') {
				return null;
			}
			var bounds = map.getBounds();
			if (!bounds) {
				return null;
			}
			return [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()].join(',');
		}

		map.on('zoomend', rebuildClusters);
		map.on('moveend zoomend', boundsChanged);
		map.on('click', mapClicked);
		map.on('popupclose', popupClosed);
		map.getContainer().addEventListener('click', onPopupClick);

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
				suppressPopupClose = true;
				popupState = null;
				clearRenderedMarkers();
				lastPoints = Array.isArray(points) ? points.slice() : [];
				if (options && Object.prototype.hasOwnProperty.call(options, 'searchMarker')) {
					lastSearchMarker = options.searchMarker || null;
				}
				activePointId = options && Object.prototype.hasOwnProperty.call(options, 'activePointId') ? (options.activePointId ? String(options.activePointId) : null) : activePointId;
				renderClustered(lastPoints);
				renderSearchMarker(lastSearchMarker);
				suppressPopupClose = false;
			},
			openPointPopup: function (point, html, options) {
				var id = pointId(point);
				popupState = { pointId: id, html: html, options: options || {} };
				openStoredPopup();
			},
			closePopup: function () {
				popupState = null;
				map.closePopup();
			},
			clearMarkers: clearMarkers,
			fitToMarkers: function (options) {
				options = options || {};
				var points = lastPoints.filter(validPointCoordinates);
				if (!points.length) {
					return;
				}
				var padding = Number(options.padding || 32);
				var maxZoom = Number(options.maxZoom || 14);
				if (1 === points.length) {
					map.setView([points[0].lat, points[0].lng], Number(options.zoom || 15));
					return;
				}
				var bounds = window.L.latLngBounds(points.map(function (point) {
					return [point.lat, point.lng];
				}));
				map.fitBounds(bounds, { padding: [padding, padding], maxZoom: maxZoom });
			},
			getBounds: currentBoundsValue,
			destroy: function () {
				suppressPopupClose = true;
				clearMarkers();
				map.off('zoomend', rebuildClusters);
				map.off('moveend zoomend', boundsChanged);
				map.off('click', mapClicked);
				map.off('popupclose', popupClosed);
				map.getContainer().removeEventListener('click', onPopupClick);
				map.remove();
			},
			onPointClick: function (callback) {
				pointClickCallback = typeof callback === 'function' ? callback : function () {};
			},
			onPopupSelect: function (callback) {
				popupSelectCallback = typeof callback === 'function' ? callback : function () {};
			},
			onPopupClose: function (callback) {
				popupCloseCallback = typeof callback === 'function' ? callback : function () {};
			},
			onMapClick: function (callback) {
				mapClickCallback = typeof callback === 'function' ? callback : function () {};
			},
			invalidateSize: function () {
				map.invalidateSize();
			}
		};

		function rebuildClusters() {
			suppressPopupClose = true;
			clearRenderedMarkers();
			renderClustered(lastPoints);
			renderSearchMarker(lastSearchMarker);
			updateActiveMarkers();
			openStoredPopup();
			window.setTimeout(function () { suppressPopupClose = false; }, 0);
		}

		function renderClustered(points) {
			var clusters = clusterPoints(points);
			clusters.forEach(function (cluster) {
				if (cluster.points.length > 1) {
					var clusterMarker = window.L.marker([cluster.lat, cluster.lng], {
						icon: window.L.divIcon({
							className: 'wdc-map-cluster-icon',
							html: '<span class="wdc-map-cluster"><span class="wdc-map-cluster__inner">' + cluster.points.length + '</span></span>',
							iconSize: [46, 46],
							iconAnchor: [23, 23]
						})
					}).addTo(map);
					clusterMarker.on('click', function (event) {
						if (event && event.originalEvent && window.L.DomEvent) {
							window.L.DomEvent.stop(event.originalEvent);
						}
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
					marker.on('click', function (event) {
						if (event && event.originalEvent && window.L.DomEvent) {
							window.L.DomEvent.preventDefault(event.originalEvent);
							window.L.DomEvent.stopPropagation(event.originalEvent);
							window.L.DomEvent.stop(event.originalEvent);
						}
						debugLog('leaflet marker click');
						pointClickCallback(point);
					});
					marker._wdcPointId = id;
					marker._wdcPoint = point;
					markers.push(marker);
					markerById[id] = marker;
					pointById[id] = point;
				});
			});
		}

		function clearRenderedMarkers() {
			markers.forEach(function (marker) { marker.remove(); });
			if (searchMarker) {
				searchMarker.remove();
			}
			markers = [];
			searchMarker = null;
			markerById = {};
			pointById = {};
		}

		function clearMarkers() {
			lastPoints = [];
			lastSearchMarker = null;
			popupState = null;
			clearRenderedMarkers();
		}

		function openStoredPopup() {
			if (!popupState) {
				return;
			}
			var marker = markerById[popupState.pointId];
			if (!marker || !marker.bindPopup) {
				return;
			}
			activePointId = popupState.pointId;
			updateActiveMarkers();
			suppressPopupClose = true;
			if (popupState.options && popupState.options.forceReopen) {
				debugLog('leaflet popup force reopen');
			}
			if (marker.closePopup) {
				marker.closePopup();
			}
			if (marker.unbindPopup) {
				marker.unbindPopup();
				debugLog('leaflet marker popup unbound/rebound');
			}
			marker.bindPopup(popupState.html, {
				autoPan: true,
				autoPanPadding: [24, 24],
				className: 'wdc-pickup-map-popup',
				keepInView: true,
				maxWidth: 280,
				offset: window.L.point(0, -5)
			});
			marker.openPopup();
			window.setTimeout(function () { suppressPopupClose = false; }, 0);
		}
		function renderSearchMarker(marker) {
			if (!marker || marker.lat === null || marker.lng === null) {
				return;
			}
			var shift = searchMarkerShift(marker);
			searchMarker = window.L.marker([marker.lat, marker.lng], {
				pane: 'wdcSearchMarkerPane',
				icon: window.L.divIcon({
					className: 'wdc-map-search-icon',
					html: '<span class="wdc-map-search-pin wdc-map-search-pin--push' + shift.className + '"><span class="wdc-map-search-pin__head"></span><span class="wdc-map-search-pin__needle"></span></span>',
					iconSize: [38, 58],
					iconAnchor: [19, 58],
					popupAnchor: [0, -58]
				}),
				interactive: false,
				zIndexOffset: -100
			}).addTo(map);
		}

		function searchMarkerShift(marker) {
			var nearest = nearestScreenPoint(marker);
			if (!nearest || nearest.distance >= 34) {
				return { className: '', offset: 0 };
			}
			var direction = nearest.searchPoint.x < nearest.point.x ? 'left' : 'right';
			return {
				className: ' is-overlapping is-shift-' + direction
			};
		}

		function nearestScreenPoint(marker) {
			var searchPoint = map.latLngToContainerPoint([marker.lat, marker.lng]);
			var nearest = null;
			Object.keys(pointById).forEach(function (id) {
				var point = pointById[id];
				if (!point || point.lat === null || point.lng === null) {
					return;
				}
				var pointScreen = map.latLngToContainerPoint([point.lat, point.lng]);
				var dx = searchPoint.x - pointScreen.x;
				var dy = searchPoint.y - pointScreen.y;
				var distance = Math.sqrt(dx * dx + dy * dy);
				if (!nearest || distance < nearest.distance) {
					nearest = { distance: distance, searchPoint: searchPoint, point: pointScreen };
				}
			});
			return nearest;
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
				html: '<span class="wdc-map-marker-pin wdc-map-marker-pin--' + type.toLowerCase() + (active ? ' is-active' : '') + '"><span class="wdc-map-marker-pin__inner"></span><span class="wdc-map-marker-pin__tail"></span></span>',
				iconSize: [38, 53],
				iconAnchor: [19, 53],
				popupAnchor: [0, -58]
			});
		}

		function mapClicked() {
			mapClickCallback();
		}

		function popupClosed() {
			debugLog('leaflet popup close');
			if (!suppressPopupClose) {
				popupState = null;
				popupCloseCallback();
				refreshActiveMarkerAfterPopupClose();
			}
		}

		function refreshActiveMarkerAfterPopupClose() {
			var id = activePointId;
			var marker = id ? markerById[id] : null;
			if (!marker) {
				return;
			}
			window.setTimeout(function () {
				if (markerById[id] !== marker) {
					return;
				}
				if (marker.unbindPopup) {
					marker.unbindPopup();
					debugLog('leaflet marker popup unbound/rebound');
				}
				updateActiveMarkers();
			}, 0);
		}

		function onPopupClick(event) {
			var button = event.target && event.target.closest ? event.target.closest('[data-wdc-pickup-popup-select]') : null;
			if (!button) {
				return;
			}
			var point = pointById[String(button.getAttribute('data-wdc-point-id') || '')];
			if (point) {
				popupSelectCallback(point);
			}
		}

		function clusterPoints(points) {
			if (map.getZoom() >= maxClusterZoom) {
				return points.filter(function (point) {
					return point.lat !== null && point.lng !== null;
				}).map(function (point) {
					return { points: [point], lat: parseFloat(point.lat), lng: parseFloat(point.lng) };
				});
			}
			var cells = {};
			points.forEach(function (point) {
				if (point.lat === null || point.lng === null) {
					return;
				}
				var projected = map.latLngToLayerPoint([point.lat, point.lng]);
				var key = Math.floor(projected.x / clusterCellSize) + ':' + Math.floor(projected.y / clusterCellSize);
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
			openPointPopup: function () {},
			closePopup: function () {},
			renderMarkers: function () {},
			clearMarkers: function () {},
			fitToMarkers: function () {},
			destroy: function () {},
			onPointClick: function () {},
			onPopupSelect: function () {},
			onPopupClose: function () {},
			onMapClick: function () {},
			invalidateSize: function () {}
		};
	}

	function pointId(point) {
		return String(point && (point.id || point.point_code || point.postcode || point.address) || '');
	}

	function pointType(point) {
		var markerType = String(point.marker_type || '').toLowerCase();
		if (markerType === 'postamat') {
			return 'POSTAMAT';
		}
		if (markerType === 'pickup') {
			return 'PVZ';
		}
		var type = String(point.point_type || point.type || 'OPS').toUpperCase();
		return type === 'PVZ' || type === 'APS' || type === 'POSTAMAT' ? type : 'OPS';
	}

	function validPointCoordinates(point) {
		var lat = parseFloat(point && point.lat);
		var lng = parseFloat(point && point.lng);
		return !isNaN(lat) && !isNaN(lng);
	}

	function debugEnabled() {
		return !!(window.wdcPickupCheckout && window.wdcPickupCheckout.debug);
	}

	function debugLog(message) {
		if (debugEnabled() && window.console && typeof window.console.debug === 'function') {
			window.console.debug('wdc pickup: ' + message);
		}
	}

	window.WDCPickupMapProviders = window.WDCPickupMapProviders || {};
	window.WDCPickupMapProviders.leaflet = { create: create };
})(window);
