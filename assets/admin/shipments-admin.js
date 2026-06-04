(function () {
  const timers = new WeakMap();
  const formSelector = '[data-wdc-shipment-form], .wdc-shipment-form';

  function findShipmentForm(element) {
    if (!element || !element.closest) return null;
    const direct = element.closest(formSelector);
    if (direct) return direct;
    const modal = element.closest('[data-wdc-shipment-modal], .wdc-shipment-modal');
    if (modal) return modal.querySelector(formSelector) || modal.querySelector('form');
    const box = element.closest('[data-wdc-shipments-metabox]');
    if (box) return box.querySelector(formSelector) || box.querySelector('form');
    return element.closest('form');
  }

  function findPlacesContainer(element) {
    if (!element || !element.closest) return null;
    const direct = element.closest('[data-wdc-places]');
    if (direct) return direct;
    const box = element.closest('[data-wdc-shipments-metabox]');
    return box ? box.querySelector('[data-wdc-places]') : null;
  }

  function collectShipmentData(container) {
    const data = new FormData();
    container.querySelectorAll('input, select, textarea').forEach((field) => {
      if (!field.name || field.disabled) return;
      if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
      data.append(field.name, field.value);
    });
    return data;
  }

  function requestPreview(form) {
    const preview = form.querySelector('[data-wdc-shipment-preview]');
    const errors = form.querySelector('[data-wdc-shipment-errors]');
    const data = collectShipmentData(form);
    data.append('action', window.wdcShipmentsAdmin.previewAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось обновить предпросмотр.');
        }
        if (preview) {
          preview.textContent = JSON.stringify(payload.data.preview || {}, null, 2);
        }
        if (errors && payload.data.preview && payload.data.preview.errors && payload.data.preview.errors.length) {
          errors.textContent = payload.data.preview.errors.join('; ');
        } else if (errors && errors.dataset.previewWarning === '1') {
          errors.textContent = '';
          delete errors.dataset.previewWarning;
        }
      })
      .catch((error) => {
        if (errors) {
          errors.dataset.previewWarning = '1';
          errors.textContent = 'Предпросмотр временно не обновлен: ' + error.message;
        }
      });
  }

  function updateTariffOptions(form) {
    const service = form.querySelector('[data-wdc-service-select]');
    const tariff = form.querySelector('[data-wdc-tariff-select]');
    const message = form.querySelector('[data-wdc-tariff-message]');
    const submit = form.querySelector('[data-wdc-create-shipment]');
    if (!service || !tariff) return;
    const selectedOption = service.options[service.selectedIndex];
    let tariffs = [];
    const serviceKey = selectedOption ? selectedOption.value : '';
    const rawTariffs = selectedOption ? selectedOption.dataset.tariffs || '[]' : '[]';
    try {
      tariffs = JSON.parse(rawTariffs);
    } catch (error) {
      tariffs = [];
    }
    const previous = tariff.value || tariff.dataset.selectedTariff || '';
    tariff.innerHTML = '';
    tariffs.forEach((item) => {
      const option = document.createElement('option');
      option.value = String(item.object_code || '');
      option.textContent = (item.title || item.object_code || '').toString();
      if (option.value === previous) option.selected = true;
      tariff.appendChild(option);
    });
    if (!tariff.value && tariff.options.length) {
      tariff.options[0].selected = true;
    }
    tariff.dataset.selectedTariff = tariff.value;
    const hasTariffs = tariff.options.length > 0;
    tariff.disabled = !hasTariffs;
    if (message) message.hidden = hasTariffs;
    if (submit) submit.disabled = !hasTariffs;
    if (!hasTariffs && window.console && typeof window.console.warn === 'function') {
      window.console.warn('WDC shipments: no enabled tariffs for selected service.', {
        service_key: serviceKey,
        tariffs: rawTariffs
      });
    }
  }

  function updateDemandAddress(form) {
    const service = form.querySelector('[data-wdc-service-select]');
    const tariff = form.querySelector('[data-wdc-tariff-select]');
    const address = form.querySelector('[data-wdc-raw-address]');
    const pickupCode = form.querySelector('[data-wdc-pickup-code]');
    const postcode = form.querySelector('[data-wdc-postcode]');
    const region = form.querySelector('[data-wdc-region]');
    const city = form.querySelector('[data-wdc-city]');
    if (!service || !tariff || !address) return;
    const deliveryType = service.options[service.selectedIndex] ? service.options[service.selectedIndex].dataset.deliveryType : '';
    let isEcom = false;
    try {
      const tariffs = JSON.parse(service.options[service.selectedIndex] ? service.options[service.selectedIndex].dataset.tariffs || '[]' : '[]');
      const current = tariffs.find((item) => String(item.object_code || '') === String(tariff.value || ''));
      isEcom = !!(current && current.is_ecom);
    } catch (error) {
      isEcom = false;
    }
    if (deliveryType !== 'pickup' || isEcom) return;
    const pickupIndex = normalizePickupDestinationIndex(pickupCode && pickupCode.value ? pickupCode.value : '');
    const parts = [
      pickupIndex || (postcode && postcode.value ? normalizePickupDestinationIndex(postcode.value) : ''),
      region ? region.value : '',
      city ? city.value : '',
      'до востребования'
    ].filter((value) => String(value || '').trim() !== '');
    address.value = parts.join(', ');
  }

  function normalizePickupDestinationIndex(value) {
    const match = String(value || '').trim().match(/^(\d{6})/);
    return match ? match[1] : '';
  }

  function schedulePreview(form) {
    const previous = timers.get(form);
    if (previous) {
      window.clearTimeout(previous);
    }
    timers.set(form, window.setTimeout(function () {
      requestPreview(form);
    }, 400));
  }

  function renumberPlaces(container) {
    container.querySelectorAll('[data-wdc-place]').forEach((row, index) => {
      row.querySelectorAll('input').forEach((input) => {
        input.name = input.name.replace(/places\[\d+\]/, 'places[' + index + ']');
      });
    });
  }

  function updateRemoveButtons(container) {
    const rows = container.querySelectorAll('[data-wdc-place]');
    rows.forEach((row) => {
      const button = row.querySelector('[data-wdc-remove-place]');
      if (button) button.disabled = rows.length <= 1;
    });
  }

  function cleanIntegerInput(input) {
    input.value = String(input.value || '').replace(/\D+/g, '');
  }

  function initializeForm(form, refreshPreview) {
    if (!form) return;
    updateTariffOptions(form);
    updateDemandAddress(form);
    const container = form.querySelector('[data-wdc-places]');
    if (container) {
      renumberPlaces(container);
      updateRemoveButtons(container);
    }
    if (refreshPreview) {
      requestPreview(form);
    }
  }

  document.addEventListener('click', function (event) {
    const open = event.target.closest('[data-wdc-open-shipment-modal]');
    if (open) {
      const box = open.closest('[data-wdc-shipments-metabox]');
      const modal = box && box.querySelector('[data-wdc-shipment-modal]');
      if (modal) {
        modal.hidden = false;
        initializeForm(findShipmentForm(modal), true);
      }
      return;
    }

    const close = event.target.closest('[data-wdc-close-shipment-modal]');
    if (close) {
      const modal = close.closest('[data-wdc-shipment-modal]');
      if (modal) modal.hidden = true;
      return;
    }

    const add = event.target.closest('[data-wdc-add-place]');
    if (add) {
      const form = findShipmentForm(add);
      const container = findPlacesContainer(add);
      if (!container) return;
      const first = container.querySelector('[data-wdc-place]');
      if (!first) return;
      const clone = first.cloneNode(true);
      clone.querySelectorAll('input').forEach((input) => {
        if (input.name.includes('declared_value')) input.value = '0';
      });
      container.appendChild(clone);
      renumberPlaces(container);
      updateRemoveButtons(container);
      if (form) schedulePreview(form);
      return;
    }

    const remove = event.target.closest('[data-wdc-remove-place]');
    if (remove) {
      const container = findPlacesContainer(remove);
      if (!container) return;
      const rows = container.querySelectorAll('[data-wdc-place]');
      if (rows.length <= 1) {
        updateRemoveButtons(container);
        return;
      }
      const row = remove.closest('[data-wdc-place]');
      if (row) row.remove();
      renumberPlaces(container);
      updateRemoveButtons(container);
      const form = findShipmentForm(remove) || findShipmentForm(container);
      if (form) requestPreview(form);
      return;
    }

    const pickupMap = event.target.closest('[data-wdc-admin-pickup-map]');
    if (pickupMap) {
      const box = pickupMap.closest('section');
      const message = box && box.querySelector('[data-wdc-admin-pickup-map-message]');
      if (message) message.hidden = false;
      return;
    }

    const create = event.target.closest('[data-wdc-create-shipment]');
    if (create) {
      const form = findShipmentForm(create);
      if (!form) return;
      const errors = form.querySelector('[data-wdc-shipment-errors]');
      if (errors) errors.textContent = '';
      const data = collectShipmentData(form);
      data.append('action', window.wdcShipmentsAdmin.createAction);
      data.append('nonce', window.wdcShipmentsAdmin.nonce);
      fetch(window.wdcShipmentsAdmin.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload || !payload.success) {
            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'РќРµ СѓРґР°Р»РѕСЃСЊ СЃРѕР·РґР°С‚СЊ РѕС‚РїСЂР°РІР»РµРЅРёРµ.');
          }
          if (errors) {
            errors.textContent = payload.data.message + ' Barcode: ' + (payload.data.tracking_number || '-') + '. Result ID: ' + (payload.data.external_id || '-');
          }
          const preview = form.querySelector('[data-wdc-shipment-preview]');
          if (preview && payload.data.preview) {
            preview.textContent = JSON.stringify(payload.data.preview || {}, null, 2);
          }
        })
        .catch((error) => {
          if (errors) errors.textContent = error.message;
        });
    }
  });

  document.addEventListener('input', function (event) {
    if (event.target.matches('[data-wdc-integer-input]')) {
      cleanIntegerInput(event.target);
      const integerForm = findShipmentForm(event.target);
      if (integerForm) {
        updateDemandAddress(integerForm);
        schedulePreview(integerForm);
      }
      return;
    }
    const form = findShipmentForm(event.target);
    if (form) {
      updateDemandAddress(form);
      schedulePreview(form);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (!event.target.matches('[data-wdc-integer-input]')) return;
    if (['.', ',', '-', '+', 'e', 'E', ' '].includes(event.key)) {
      event.preventDefault();
    }
  });

  document.addEventListener('paste', function (event) {
    if (!event.target.matches('[data-wdc-integer-input]')) return;
    event.preventDefault();
    const clipboard = event.clipboardData || window.clipboardData;
    const text = clipboard && clipboard.getData ? clipboard.getData('text') : '';
    event.target.value = String(text || '').replace(/\D+/g, '');
    const form = findShipmentForm(event.target);
    if (form) {
      updateDemandAddress(form);
      schedulePreview(form);
    }
  });

  document.addEventListener('change', function (event) {
    const form = findShipmentForm(event.target);
    if (!form) return;
    if (event.target.matches('[data-wdc-service-select]')) {
      updateTariffOptions(form);
    }
    updateDemandAddress(form);
    schedulePreview(form);
  });

  const forms = new Set(document.querySelectorAll(formSelector));
  document.querySelectorAll('[data-wdc-shipments-metabox]').forEach((box) => {
    const form = findShipmentForm(box);
    if (form) forms.add(form);
  });
  forms.forEach((form) => {
    initializeForm(form, false);
  });
})();
