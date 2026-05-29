(function (window, document) {
	'use strict';

	var apiPromise = null;

	function create(container, options) {
		var settings = options || {};
		var pendingCenter = normalizeCenter(settings.center || { lat: 55.0302, lng: 82.9204, zoom: 11 });
		var pendingCenterChanged = false;
		var map = null;
		var collection = null;
		var destroyed = false;
		var pendingPoints = [];
		var pointClickCallback = function () {};
		var popupSelectCallback = function () {};
		var mapClickCallback = function () {};
		var ymapsApi = null;
		var activePointId = null;
		var placemarkById = {};
		var pointById = {};
		var markerLayout = null;
		var maxClusterZoom = 17;

		loadApi(settings.yandexApiKey || '').then(function (ymaps) {
			if (destroyed) {
				return;
			}
			ymapsApi = ymaps;
			markerLayout = ymaps.templateLayoutFactory.createClass(
				'<div class="wdc-map-marker-pin wdc-map-marker-pin--$[properties.wdcType] $[properties.wdcActive]"><span class="wdc-map-marker-pin__inner"></span><span class="wdc-map-marker-pin__tail"></span></div>'
			);
			map = new ymaps.Map(container, {
				center: [pendingCenter.lat, pendingCenter.lng],
				zoom: pendingCenter.zoom || 11,
				controls: ['zoomControl']
			});
			collection = new ymaps.Clusterer({
				clusterIconLayout: ymaps.templateLayoutFactory.createClass('<div class="wdc-map-cluster"><span class="wdc-map-cluster__inner">$[properties.geoObjects.length]</span></div>'),
				clusterIconShape: { type: 'Circle', coordinates: [23, 23], radius: 23 },
				clusterIcons: [{ href: '', size: [46, 46], offset: [-23, -23] }],
				groupByCoordinates: false
			});
			map.geoObjects.add(collection);
			map.events.add('boundschange', boundsChanged);
			map.events.add('click', mapClicked);
			document.addEventListener('click', onPopupClick);
			fitToViewport();
			if (pendingPoints.length) {
				renderMarkers(pendingPoints);
			}
			if (pendingCenterChanged) {
				map.setCenter([pendingCenter.lat, pendingCenter.lng], pendingCenter.zoom || map.getZoom());
			}
			boundsChanged();
		}).catch(function (error) {
			if (debugEnabled() && window.console && typeof window.console.warn === 'function') {
				window.console.warn('WDC pickup Yandex map failed to load.', error);
			}
		});

		function boundsChanged() {
			if (!map || typeof settings.onBoundsChange !== 'function') {
				return;
			}
			var bounds = map.getBounds();
			if (!bounds || !bounds[0] || !bounds[1]) {
				return;
			}
			settings.onBoundsChange([bounds[0][1], bounds[0][0], bounds[1][1], bounds[1][0]].join(','));
		}

		function renderMarkers(points, options) {
			pendingPoints = points || [];
			activePointId = options && options.activePointId ? String(options.activePointId) : activePointId;
			if (!map || !collection || !ymapsApi) {
				return;
			}
			clearMarkers();
			var useClusterer = map.getZoom() < maxClusterZoom;
			pendingPoints.forEach(function (point) {
				if (point.lat === null || point.lng === null) {
					return;
				}
				var id = pointId(point);
				var placemark = new ymapsApi.Placemark([point.lat, point.lng], {
					balloonContent: escapeHtml(point.address || ''),
					wdcActive: activePointId === id ? 'is-active' : '',
					wdcType: pointType(point).toLowerCase()
				}, {
					balloonAutoPan: true,
					balloonAutoPanMargin: 24,
					iconLayout: markerLayout,
					iconOffset: [-21, -59],
					iconShape: { type: 'Circle', coordinates: [21, 21], radius: 21 }
				});
				placemark.events.add('click', function () { pointClickCallback(point); });
				if (useClusterer) {
					collection.add(placemark);
				} else {
					map.geoObjects.add(placemark);
				}
				placemarkById[id] = placemark;
				pointById[id] = point;
			});
		}

		function clearMarkers() {
			if (collection) {
				collection.removeAll();
			}
			if (map) {
				Object.keys(placemarkById).forEach(function (id) {
					map.geoObjects.remove(placemarkById[id]);
				});
			}
			placemarkById = {};
			pointById = {};
		}

		return {
			setCenter: function (lat, lng, zoom) {
				pendingCenter = normalizeCenter({ lat: lat, lng: lng, zoom: zoom || pendingCenter.zoom || 11 });
				if (map) {
					map.setCenter([pendingCenter.lat, pendingCenter.lng], pendingCenter.zoom || map.getZoom());
					boundsChanged();
				} else {
					pendingCenterChanged = true;
				}
			},
			focusPoint: function (point) {
				if (!point || point.lat === null || point.lng === null) {
					return;
				}
				pendingCenter = normalizeCenter({ lat: point.lat, lng: point.lng, zoom: Math.max(pendingCenter.zoom || 11, 15) });
				if (map) {
					map.setCenter([pendingCenter.lat, pendingCenter.lng], pendingCenter.zoom);
					boundsChanged();
				} else {
					pendingCenterChanged = true;
				}
			},
			setActivePoint: function (pointId) {
				activePointId = pointId ? String(pointId) : null;
				updateActivePlacemarks();
			},
			renderMarkers: renderMarkers,
			clearMarkers: function () {
				pendingPoints = [];
				clearMarkers();
			},
			fitToMarkers: function () {
				if (map && collection && Object.keys(placemarkById).length) {
					map.setBounds(collection.getBounds(), { checkZoomRange: true, zoomMargin: 24 });
				}
			},
			openPointPopup: function (point, html) {
				var id = pointId(point);
				var placemark = placemarkById[id];
				if (!placemark || !placemark.properties || !placemark.balloon) {
					return;
				}
				placemark.properties.set('balloonContent', html);
				placemark.balloon.open();
			},
			closePopup: function () {
				if (map && map.balloon) {
					map.balloon.close();
				}
			},
			destroy: function () {
				destroyed = true;
				clearMarkers();
				if (map) {
					map.events.remove('boundschange', boundsChanged);
					map.events.remove('click', mapClicked);
					map.destroy();
				}
				map = null;
				collection = null;
				document.removeEventListener('click', onPopupClick);
			},
			onPointClick: function (callback) {
				pointClickCallback = typeof callback === 'function' ? callback : function () {};
			},
			onPopupSelect: function (callback) {
				popupSelectCallback = typeof callback === 'function' ? callback : function () {};
			},
			onMapClick: function (callback) {
				mapClickCallback = typeof callback === 'function' ? callback : function () {};
			},
			invalidateSize: function () {
				fitToViewport();
			}
		};

		function fitToViewport() {
			if (map && map.container && map.container.fitToViewport) {
				map.container.fitToViewport();
			}
		}

		function updateActivePlacemarks() {
			Object.keys(placemarkById).forEach(function (id) {
				var placemark = placemarkById[id];
				if (placemark && placemark.properties) {
					placemark.properties.set('wdcActive', activePointId === id ? 'is-active' : '');
				}
			});
		}

		function onPopupClick(event) {
			var button = event.target && event.target.closest ? event.target.closest('[data-wdc-pickup-popup-select]') : null;
			if (!button) {
				return;
			}
			var id = button.getAttribute('data-wdc-point-id');
			var point = pointById[id];
			if (point) {
				popupSelectCallback(point);
			}
		}

		function mapClicked() {
			mapClickCallback();
		}
	}

	function loadApi(apiKey) {
		if (window.ymaps && typeof window.ymaps.ready === 'function') {
			return new Promise(function (resolve) {
				window.ymaps.ready(function () { resolve(window.ymaps); });
			});
		}
		if (apiPromise) {
			return apiPromise;
		}
		apiPromise = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = 'https://api-maps.yandex.ru/2.1/?apikey=' + encodeURIComponent(apiKey) + '&lang=ru_RU';
			script.async = true;
			script.onload = function () {
				if (!window.ymaps || typeof window.ymaps.ready !== 'function') {
					reject(new Error('Yandex Maps API is not available.'));
					return;
				}
				window.ymaps.ready(function () { resolve(window.ymaps); });
			};
			script.onerror = function () { reject(new Error('Unable to load Yandex Maps API.')); };
			document.head.appendChild(script);
		});
		return apiPromise;
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
		});
	}

	function normalizeCenter(center) {
		var lat = parseFloat(center.lat);
		var lng = parseFloat(center.lng);
		return {
			lat: isNaN(lat) ? 55.0302 : lat,
			lng: isNaN(lng) ? 82.9204 : lng,
			zoom: parseInt(center.zoom || 11, 10) || 11
		};
	}

	function pointId(point) {
		return String(point && (point.id || point.point_code || point.postcode || point.address) || '');
	}

	function pointType(point) {
		var type = String(point.point_type || point.type || 'OPS').toUpperCase();
		return type === 'PVZ' || type === 'APS' ? type : 'OPS';
	}

	function debugEnabled() {
		return !!(window.wdcPickupCheckout && window.wdcPickupCheckout.debug);
	}

	window.WDCPickupMapProviders = window.WDCPickupMapProviders || {};
	window.WDCPickupMapProviders.yandex = { create: create };
})(window, document);
