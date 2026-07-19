  function pointId(point) {
    return String(point && (point.point_code || point.cdek_code || point.code || point.id || point.postcode || point.address) || '');
  }

  function pickupContext(form, contextOverride) {
    if (contextOverride && typeof contextOverride === 'object') return contextOverride;
    const carrier = fieldValue(form, '[data-wdc-pickup-carrier-key]');
    return {
      carrierKey: carrier,
      serviceKey: fieldValue(form, '[data-wdc-pickup-service-key]') || carrier,
      pickupFamily: fieldValue(form, '[data-wdc-pickup-family]') || (carrier ? carrier + ':pickup' : ''),
      city: fieldValue(form, '[data-wdc-pickup-location-city]') || fieldValue(form, '[data-wdc-pickup-city-field]'),
      cityId: fieldValue(form, '[data-wdc-pickup-location-city-id]'),
      region: fieldValue(form, '[data-wdc-pickup-location-region]') || fieldValue(form, '[data-wdc-pickup-region-field]'),
      postcode: fieldValue(form, '[data-wdc-pickup-location-postcode]') || fieldValue(form, '[data-wdc-pickup-postcode-field]'),
      address: fieldValue(form, '[data-wdc-pickup-location-address]') || fieldValue(form, '[data-wdc-pickup-address-field]'),
      fiasId: fieldValue(form, '[data-wdc-pickup-location-fias]'),
      garId: fieldValue(form, '[data-wdc-pickup-location-gar]'),
      locationId: fieldValue(form, '[data-wdc-pickup-location-id]'),
      lat: fieldValue(form, '[data-wdc-pickup-location-lat]') || fieldValue(form, '[data-wdc-pickup-lat-field]'),
      lng: fieldValue(form, '[data-wdc-pickup-location-lng]') || fieldValue(form, '[data-wdc-pickup-lng-field]')
    };
  }

  function pickupUsesCodeDisplay(form) {
    const context = pickupContext(form);
    return context.pickupFamily === 'cdek:pickup' || context.pickupFamily === 'dpd:pickup';
  }

  function pickupCode(point) {
    return String(point && (point.point_code || point.cdek_code || point.code || point.display_code || '') || '');
  }

  function pickupPointTitle(point) {
    const carrier = String(point && (point.carrier_key || point.carrier) || '');
    if (carrier === 'cdek') {
      const type = String(point.marker_type || point.point_type || point.cdek_type || point.type || '').toLowerCase();
      return (type === 'postamat' || type === 'postomat' || type === 'locker') ? 'Постамат СДЭК' : 'ПВЗ СДЭК';
    }
    if (carrier === 'dpd') return 'ПВЗ DPD';
    return String(point && (point.point_title || point.card_title || point.point_type_label || point.display_title) || '').trim() || 'Отделение Почты России';
  }
  function normalizePickupPoint(point) {
    const lat = point && point.lat !== null && point.lat !== undefined ? parseFloat(point.lat) : null;
    const lng = point && point.lng !== null && point.lng !== undefined ? parseFloat(point.lng) : null;
    const code = pickupCode(point);
    const postcode = String(point && (point.postcode || point.postal_code || point.point_postcode) || '');
    return Object.assign({}, point || {}, {
      id: pointId(point),
      point_code: code || pointId(point),
      cdek_code: String(point && point.cdek_code || code || ''),
      point_type: String(point && point.point_type || point && point.type || 'OPS'),
      postal_code: postcode,
      postcode: postcode,
      region_name: String(point && (point.region_name || point.region) || ''),
      city_name: String(point && (point.city_name || point.city) || ''),
      city: String(point && (point.city || point.city_name) || ''),
      address: String(point && (point.address || point.point_address) || ''),
      lat: Number.isFinite(lat) ? lat : null,
      lng: Number.isFinite(lng) ? lng : null
    });
  }

  function pickupSearchRequest(form, query, limit, signal, mode, contextOverride) {
    const context = pickupContext(form, contextOverride);
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.searchPickupPointsAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', fieldValue(form, 'input[name="order_id"]') || '');
    data.append('query', query);
    data.append('limit', String(limit || 50));
    data.append('mode', mode || 'search');
    data.append('carrier_key', context.carrierKey || '');
    data.append('service_key', context.serviceKey || '');
    data.append('pickup_family', context.pickupFamily || '');
    data.append('city', context.city || '');
    data.append('region', context.region || '');
    data.append('postcode', context.postcode || '');
    data.append('address', context.address || '');
    data.append('fias_id', context.fiasId || '');
    data.append('gar_id', context.garId || '');
    data.append('location_id', context.locationId || '');
    data.append('city_id', context.cityId || '');
    data.append('lat', context.lat || '');
    data.append('lng', context.lng || '');
    data.append('purpose', context.purpose || '');
    data.append('source_location_id', context.sourceLocationId || '');
    data.append('source_platform_station_id', context.sourcePlatformStationId || '');
    data.append('latitude', context.latitude || context.lat || '');
    data.append('longitude', context.longitude || context.lng || '');
    data.append('radius_km', context.radiusKm || '');
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data,
      signal: signal
    })
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось найти ПВЗ.');
        }
        const result = Array.isArray(payload.data && payload.data.points) ? payload.data.points.map(normalizePickupPoint) : [];
        result.wdcContext = payload.data && payload.data.context ? payload.data.context : {};
        result.wdcMessage = payload.data && payload.data.message ? String(payload.data.message) : '';
        return result;
      });
  }

  function currentPickupQuery(form, contextOverride) {
    const context = pickupContext(form, contextOverride);
    if (context.pickupFamily === 'cdek:pickup' || context.pickupFamily === 'dpd:pickup') {
      return [context.address, context.city, context.region, context.postcode].filter(Boolean).join(' ').trim();
    }
    const postcode = form.querySelector('[data-wdc-pickup-postcode-field]');
    const address = form.querySelector('[data-wdc-pickup-address-field]');
    return [postcode && postcode.value, address && address.value, context.address, context.city, context.region, context.postcode].filter(Boolean).join(' ').trim();
  }

  function updatePickupDraft(form, point) {
    const code = pickupCode(point);
    const fields = {
      pickup_point_code: code || '',
      delivery_point: code || '',
      pickup_point_postcode: point.postcode || point.postal_code || '',
      pickup_point_address: point.address || '',
      pickup_point_city: point.city_name || point.city || '',
      pickup_point_region: point.region_name || '',
      pickup_point_type: point.point_type || point.type || '',
      pickup_point_title: point.display_title || point.point_title || point.point_name || '',
      pickup_point_lat: point.lat !== null && point.lat !== undefined ? String(point.lat) : '',
      pickup_point_lng: point.lng !== null && point.lng !== undefined ? String(point.lng) : ''
    };
    Object.keys(fields).forEach((name) => {
      const input = form.querySelector('[name="' + name + '"]');
      if (input) input.value = fields[name];
    });
    const index = form.querySelector('[data-wdc-pickup-index]');
    const address = form.querySelector('[data-wdc-pickup-address]');
    if (index) index.textContent = (pickupUsesCodeDisplay(form) ? fields.pickup_point_code : fields.pickup_point_postcode) || '-';
    if (address) address.textContent = fields.pickup_point_address || '-';
    const typeLabel = form.querySelector('[data-wdc-pickup-type-label]');
    if (typeLabel) typeLabel.textContent = fields.pickup_point_type || '-';
    const warning = form.querySelector('[data-wdc-pickup-warning]');
    if (warning) warning.remove();
    updateCreateAvailability(form);
    requestPreview(form);
  }

  function updateSenderPickupDraft(form, point) {
    const code = pickupCode(point);
    const address = String(point && point.address || '');
    form.querySelectorAll('[name="shipment_point"], [name="sender_shipment_point"], [name="pickup_terminal_code"], [data-wdc-sender-shipment-point]').forEach((input) => {
      input.value = code || '';
    });
    form.querySelectorAll('[name="shipment_point_address"], [name="sender_shipment_point_address"], [data-wdc-sender-shipment-point-address]').forEach((input) => {
      input.value = address;
    });
    const display = form.querySelector('[data-wdc-sender-shipment-point-display]');
    if (display) display.textContent = [code, address].filter(Boolean).join(', ') || '-';
    requestPreview(form);
  }

  function senderPickupContext(form) {
    return {
      carrierKey: fieldValue(form, '[data-wdc-pickup-carrier-key]') === 'dpd' ? 'dpd' : 'cdek',
      serviceKey: fieldValue(form, '[data-wdc-pickup-carrier-key]') === 'dpd' ? 'dpd' : 'cdek',
      pickupFamily: fieldValue(form, '[data-wdc-pickup-carrier-key]') === 'dpd' ? 'dpd:pickup' : 'cdek:pickup',
      city: fieldValue(form, '[data-wdc-sender-pickup-city]') || 'Новосибирск',
      cityId: fieldValue(form, '[data-wdc-sender-pickup-city-id]'),
      region: 'Новосибирская область',
      postcode: '',
      address: fieldValue(form, '[data-wdc-sender-shipment-point-address]'),
      fiasId: '',
      garId: '',
      locationId: '',
      lat: '',
      lng: ''
    };
  }

  function createPickupPicker(form, options) {
    const settings = Object.assign({ sender: false }, options || {});
    const config = window.wdcShipmentsAdmin || {};
    const context = settings.context || pickupContext(form);
    const codeDisplay = settings.sender || context.pickupFamily === 'cdek:pickup';
    const codeLabel = codeDisplay ? 'Код ПВЗ' : 'Индекс';
    const pickerTitle = settings.title || (context.pickupFamily === 'dpd:pickup' ? 'Выбор ПВЗ DPD' : (codeDisplay ? 'Выбор ПВЗ СДЭК' : 'Выбор ПВЗ / ОПС'));
    window.wdcPickupCheckout = Object.assign({}, window.wdcPickupCheckout || {}, {
      mapProvider: config.mapProvider || 'leaflet',
      yandexApiKeyPresent: !!config.yandexApiKeyPresent,
      yandexApiKey: config.yandexApiKey || '',
      restUrl: config.restUrl || (window.wdcPickupCheckout && window.wdcPickupCheckout.restUrl) || '/wp-json/wdc/v1/',
      nonce: config.restNonce || (window.wdcPickupCheckout && window.wdcPickupCheckout.nonce) || '',
      pickupPointTypes: config.pickupPointTypes || {},
      carrierKey: context.carrierKey || '',
      serviceKey: context.serviceKey || '',
      activePickupFamily: context.pickupFamily || ''
    });
    const providerName = config.mapProvider === 'yandex' ? 'yandex' : 'leaflet';
    const providerFactory = window.WDCPickupMapProviders && window.WDCPickupMapProviders[providerName];
    const root = document.createElement('div');
    root.className = 'wdc-admin-pickup-picker';
    root.innerHTML = [
      '<div class="wdc-admin-pickup-picker__overlay" data-wdc-pickup-picker-close></div>',
      '<div class="wdc-admin-pickup-picker__dialog" role="dialog" aria-modal="true" aria-label="' + escapeHtml(pickerTitle) + '">',
      '<button type="button" class="wdc-admin-pickup-picker__close" data-wdc-pickup-picker-close aria-label="Закрыть">×</button>',
      '<h2>' + escapeHtml(pickerTitle) + '</h2>',
      '<div class="wdc-admin-pickup-picker__search"><input type="search" data-wdc-pickup-picker-query placeholder="Поиск адреса, города или кода"><button type="button" class="button" data-wdc-pickup-picker-search>Найти</button></div>',
      '<div class="wdc-admin-pickup-picker__status" data-wdc-pickup-picker-status></div>',
      '<div class="wdc-admin-pickup-picker__layout">',
      '<div class="wdc-admin-pickup-picker__map" data-wdc-pickup-picker-map></div>',
      '<div class="wdc-admin-pickup-picker__side">',
      '<div class="wdc-admin-pickup-picker__list" data-wdc-pickup-picker-list></div>',
      '<div class="wdc-admin-pickup-picker__footer"><button type="button" class="button button-primary" data-wdc-pickup-picker-confirm disabled>Выбрать этот ПВЗ</button></div>',
      '</div>',
      '</div>',
      '</div>'
    ].join('');
    document.body.appendChild(root);

    const query = root.querySelector('[data-wdc-pickup-picker-query]');
    const status = root.querySelector('[data-wdc-pickup-picker-status]');
    const mapElement = root.querySelector('[data-wdc-pickup-picker-map]');
    const list = root.querySelector('[data-wdc-pickup-picker-list]');
    const confirmButton = root.querySelector('[data-wdc-pickup-picker-confirm]');
    let provider = null;
    let controller = null;
    let points = [];
    let previewPoint = null;
    let searchMarker = null;

    function close() {
      if (controller) controller.abort();
      if (provider && provider.destroy) provider.destroy();
      root.remove();
    }

    function renderPopup(point) {
      const displayCode = codeDisplay ? pickupCode(point) : (point.postcode || '');
      return [
        '<div class="wdc-pickup-popup">',
        '<h3 class="wdc-pickup-popup__title">' + escapeHtml([pickupPointTitle(point), displayCode].filter(Boolean).join(' ')) + '</h3>',
        '<div class="wdc-pickup-popup__section"><strong>' + escapeHtml(codeLabel) + ':</strong><span>' + escapeHtml(displayCode || '') + '</span></div>',
        '<div class="wdc-pickup-popup__section"><strong>Город:</strong><span>' + escapeHtml(point.city_name || point.city || '') + '</span></div>',
        '<div class="wdc-pickup-popup__section"><strong>Адрес:</strong><span>' + escapeHtml(point.address || '') + '</span></div>',
        point.drop_off || point.available_for_dropoff ? '<div class="wdc-pickup-popup__section"><strong>Приём отправлений:</strong><span>да</span></div>' : '',
        '</div>'
      ].join('');
    }

    function preview(point) {
      previewPoint = point;
      updateConfirmButton();
      if (provider && provider.setActivePoint) provider.setActivePoint(pointId(point));
      if (provider && provider.focusPoint) provider.focusPoint(point);
      if (provider && provider.openPointPopup) provider.openPointPopup(point, renderPopup(point), { forceReopen: true });
      renderList();
      scrollActivePickupRow();
    }

    function updateConfirmButton() {
      if (!confirmButton) return;
      confirmButton.disabled = !previewPoint;
      confirmButton.textContent = previewPoint ? 'Выбрать этот ПВЗ' : 'Выберите ПВЗ';
    }

    function choose(point) {
      if (typeof settings.onChoose === 'function') {
        settings.onChoose(point);
      } else {
        updatePickupDraft(form, point);
      }
      close();
    }

    function renderList() {
      if (!points.length) {
        list.innerHTML = '<p class="description">ПВЗ не найдены.</p>';
        updateConfirmButton();
        return;
      }
      list.innerHTML = [
        '<div class="wdc-admin-pickup-picker__items">',
        points.map((point) => {
          const active = previewPoint && pointId(previewPoint) === pointId(point) ? ' class="is-active"' : '';
          const displayCode = codeDisplay ? pickupCode(point) : (point.postcode || '');
          return '<button type="button" data-wdc-pickup-picker-row data-wdc-point-id="' + escapeHtml(pointId(point)) + '"' + active + '><span><strong>' + escapeHtml([pickupPointTitle(point), displayCode].filter(Boolean).join(' ')) + '</strong></span><span>' + escapeHtml(point.address || '') + '</span></button>';
        }).join(''),
        '</div>'
      ].join('');
      updateConfirmButton();
    }

    function findPoint(id) {
      return points.find((point) => pointId(point) === String(id)) || null;
    }

    function scrollActivePickupRow() {
      const active = list && list.querySelector('.is-active[data-wdc-pickup-picker-row]');
      if (active && active.scrollIntoView) {
        active.scrollIntoView({ block: 'nearest' });
      }
    }

    function addressMarkerFromResult(result) {
      const address = result && result.address ? result.address : null;
      const lat = address && address.lat !== null && address.lat !== undefined ? parseFloat(address.lat) : null;
      const lng = address && address.lng !== null && address.lng !== undefined ? parseFloat(address.lng) : null;
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
      return {
        id: 'address-search',
        lat: lat,
        lng: lng,
        marker_type: 'search',
        point_type: 'search',
        title: address.value || '',
        address: address.value || ''
      };
    }

    function renderSearchResults(message) {
      status.textContent = points.length ? message + ' Найдено: ' + points.length : message + ' ПВЗ не найдены.';
      if (provider && provider.renderMarkers) {
        provider.renderMarkers(points, { activePointId: previewPoint ? pointId(previewPoint) : null, searchMarker: searchMarker });
        if (searchMarker && provider.setCenter) {
          provider.setCenter(searchMarker.lat, searchMarker.lng, 15);
        } else if (provider.fitToMarkers) {
          provider.fitToMarkers();
        }
      }
      previewPoint = null;
      renderList();
    }

    function yandexResponseCenter(found) {
      const center = found && found.wdcContext && found.wdcContext.center ? found.wdcContext.center : null;
      const lat = center && center.lat !== null && center.lat !== undefined ? parseFloat(center.lat) : null;
      const lng = center && center.lng !== null && center.lng !== undefined ? parseFloat(center.lng) : null;
      return Number.isFinite(lat) && Number.isFinite(lng) ? { lat: lat, lng: lng } : null;
    }

    function renderYandexSourcePoints(found, message, center) {
      points = found || [];
      status.textContent = points.length ? message + ' Найдено: ' + points.length : (points.wdcMessage || message + ' ПВЗ не найдены.');
      if (provider && provider.renderMarkers) {
        provider.renderMarkers(points, { activePointId: previewPoint ? pointId(previewPoint) : null, searchMarker: searchMarker });
        if (center && provider.setCenter) {
          provider.setCenter(center.lat, center.lng, 14);
        }
      }
      previewPoint = null;
      renderList();
      updateConfirmButton();
    }

    function loadYandexSourceNearby(marker, radii, index) {
      const radius = radii[index] || 10;
      status.textContent = 'Ищем ПВЗ Яндекс рядом с найденным адресом...';
      return pickupSearchRequest(form, '', 100, controller.signal, 'nearby', Object.assign({}, context, {
        latitude: marker.lat,
        longitude: marker.lng,
        lat: '',
        lng: '',
        radiusKm: radius
      }))
        .then((found) => {
          if (!found.length && index < radii.length - 1) {
            return loadYandexSourceNearby(marker, radii, index + 1);
          }
          renderYandexSourcePoints(found, found.length ? 'Адрес найден. Радиус ' + radius + ' км.' : 'Рядом с найденным адресом нет ПВЗ Яндекс, принимающих отправления.', { lat: marker.lat, lng: marker.lng });
          return found;
        });
    }

    function runSearch(mode) {
      mode = mode || 'search';
      const value = String(query.value || '').trim();
      if (mode !== 'location' && !value) {
        status.textContent = 'Введите адрес или индекс.';
        return;
      }
      if (controller) controller.abort();
      controller = new AbortController();
      if ((mode || 'search') === 'search' && window.WDCPickupApi && typeof window.WDCPickupApi.addressSearch === 'function') {
        status.textContent = 'Ищем адрес...';
        window.WDCPickupApi.addressSearch(value, pickupAddressSearchContext(context), controller.signal)
          .then((result) => {
            searchMarker = addressMarkerFromResult(result);
            if (isYandexSourceDropoffContext(context)) {
              if (searchMarker) {
                return loadYandexSourceNearby(searchMarker, [10, 25, 50], 0);
              }
              points = [];
              renderSearchResults('Адрес не найден.');
              return null;
            }
            renderSearchResults(searchMarker ? 'Адрес найден.' : 'Адрес не найден.');
          })
          .catch((error) => {
            if (error.name === 'AbortError') return;
            searchMarker = null;
            if (isYandexSourceDropoffContext(context)) {
              points = [];
            }
            renderSearchResults(error.message || 'Адрес не найден.');
          });
        return;
      }
      searchMarker = null;
      status.textContent = 'Поиск...';
      pickupSearchRequest(form, value, mode === 'location' ? 2000 : 100, controller.signal, mode, context)
        .then((found) => {
          if (isYandexSourceDropoffContext(context)) {
            renderYandexSourcePoints(found, found.length ? 'ПВЗ Яндекс загружены.' : 'В выбранном городе не найдены ПВЗ Яндекс, принимающие отправления.', yandexResponseCenter(found));
            return;
          }
          points = found;
          status.textContent = points.length ? 'Найдено: ' + points.length : 'ПВЗ не найдены.';
          if (provider && provider.renderMarkers) {
            provider.renderMarkers(points, { activePointId: previewPoint ? pointId(previewPoint) : null, searchMarker: searchMarker });
            if (provider.fitToMarkers) provider.fitToMarkers();
          }
          previewPoint = null;
          renderList();
          updateConfirmButton();
        })
        .catch((error) => {
          if (error.name === 'AbortError') return;
          status.textContent = error.message;
        });
    }

    root.addEventListener('click', function (event) {
      if (event.target.closest('[data-wdc-pickup-picker-close]')) {
        close();
        return;
      }
      if (event.target.closest('[data-wdc-pickup-picker-search]')) {
        runSearch('search');
        return;
      }
      const chooseButton = event.target.closest('[data-wdc-pickup-picker-confirm]');
      if (chooseButton) {
        if (previewPoint) choose(previewPoint);
        return;
      }
      const row = event.target.closest('[data-wdc-pickup-picker-row]');
      if (row) {
        const point = findPoint(row.getAttribute('data-wdc-point-id'));
        if (point) preview(point);
      }
    });
    query.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        runSearch('search');
      }
    });

    if (!providerFactory || typeof providerFactory.create !== 'function') {
      status.textContent = 'Карта недоступна.';
    } else if (providerName === 'yandex' && !config.yandexApiKeyPresent) {
      status.textContent = 'Для Яндекс.Карт не задан API key.';
    } else {
      const initialLat = parseFloat(context.lat || fieldValue(form, '[data-wdc-pickup-lat-field]'));
      const initialLng = parseFloat(context.lng || fieldValue(form, '[data-wdc-pickup-lng-field]'));
      provider = providerFactory.create(mapElement, {
        center: {
          lat: Number.isFinite(initialLat) ? initialLat : 55.751244,
          lng: Number.isFinite(initialLng) ? initialLng : 37.618423,
          zoom: isYandexSourceDropoffContext(context) ? 14 : 11
        },
        yandexApiKey: config.yandexApiKey || '',
        onBoundsChange: function () {}
      });
      provider.onPointClick(function (point) { preview(point); });
      if (provider.onPopupSelect) provider.onPopupSelect(function (point) { choose(point); });
      if (Number.isFinite(initialLat) && Number.isFinite(initialLng) && provider.setCenter) provider.setCenter(initialLat, initialLng, isYandexSourceDropoffContext(context) ? 14 : 11);
      window.setTimeout(function () {
        if (provider && provider.invalidateSize) provider.invalidateSize();
      }, 50);
    }

    query.value = currentPickupQuery(form, context);
    query.focus();
    if (query.value || context.city || context.postcode || context.address || context.locationId || context.fiasId || context.garId) runSearch('location');
  }

