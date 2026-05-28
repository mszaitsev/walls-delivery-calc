(function (window, document) {
	'use strict';

	var apiPromise = null;

	function create(container, options) {
		var settings = options || {};
		var center = settings.center || { lat: 55.0302, lng: 82.9204, zoom: 11 };
		var map = null;
		var collection = null;
		var destroyed = false;
		var pendingPoints = [];
		var pointClickCallback = function () {};

		loadApi(settings.yandexApiKey || '').then(function (ymaps) {
			if (destroyed) {
				return;
			}
			map = new ymaps.Map(container, {
				center: [center.lat, center.lng],
				zoom: center.zoom || 11,
				controls: ['zoomControl']
			});
			collection = new ymaps.GeoObjectCollection();
			map.geoObjects.add(collection);
			map.events.add('boundschange', boundsChanged);
			if (pendingPoints.length) {
				renderMarkers(pendingPoints);
			}
		}).catch(function () {});

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

		function renderMarkers(points) {
			pendingPoints = points || [];
			if (!map || !collection || !window.ymaps) {
				return;
			}
			clearMarkers();
			pendingPoints.forEach(function (point) {
				if (point.lat === null || point.lng === null) {
					return;
				}
				var placemark = new window.ymaps.Placemark([point.lat, point.lng], {
					balloonContent: escapeHtml(point.address || '')
				}, {
					preset: 'islands#blueDeliveryIcon'
				});
				placemark.events.add('click', function () { pointClickCallback(point); });
				collection.add(placemark);
			});
		}

		function clearMarkers() {
			if (collection) {
				collection.removeAll();
			}
		}

		return {
			setCenter: function (lat, lng, zoom) {
				center = { lat: lat, lng: lng, zoom: zoom || center.zoom || 11 };
				if (map) {
					map.setCenter([lat, lng], zoom || map.getZoom());
				}
			},
			renderMarkers: renderMarkers,
			clearMarkers: function () {
				pendingPoints = [];
				clearMarkers();
			},
			fitToMarkers: function () {
				if (map && collection && collection.getLength()) {
					map.setBounds(collection.getBounds(), { checkZoomRange: true, zoomMargin: 24 });
				}
			},
			destroy: function () {
				destroyed = true;
				clearMarkers();
				if (map) {
					map.events.remove('boundschange', boundsChanged);
					map.destroy();
				}
				map = null;
				collection = null;
			},
			onPointClick: function (callback) {
				pointClickCallback = typeof callback === 'function' ? callback : function () {};
			},
			invalidateSize: function () {
				if (map && map.container && map.container.fitToViewport) {
					map.container.fitToViewport();
				}
			}
		};
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

	window.WDCPickupMapProviders = window.WDCPickupMapProviders || {};
	window.WDCPickupMapProviders.yandex = { create: create };
})(window, document);
