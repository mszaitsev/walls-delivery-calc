(function () {
  const timers = new WeakMap();

  function requestPreview(form) {
    const preview = form.querySelector('[data-wdc-shipment-preview]');
    const errors = form.querySelector('[data-wdc-shipment-errors]');
    const data = new FormData(form);
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
    if (!service || !tariff) return;
    const selectedOption = service.options[service.selectedIndex];
    let tariffs = [];
    try {
      tariffs = JSON.parse(selectedOption ? selectedOption.dataset.tariffs || '[]' : '[]');
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

  document.addEventListener('click', function (event) {
    const open = event.target.closest('[data-wdc-open-shipment-modal]');
    if (open) {
      const box = open.closest('[data-wdc-shipments-metabox]');
      const modal = box && box.querySelector('[data-wdc-shipment-modal]');
      if (modal) modal.hidden = false;
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
      const form = add.closest('form');
      const container = form.querySelector('[data-wdc-places]');
      const first = container.querySelector('[data-wdc-place]');
      if (!first) return;
      const clone = first.cloneNode(true);
      clone.querySelectorAll('input').forEach((input) => {
        if (input.name.includes('declared_value')) input.value = '0';
      });
      container.appendChild(clone);
      renumberPlaces(container);
      schedulePreview(form);
      return;
    }

    const remove = event.target.closest('[data-wdc-remove-place]');
    if (remove) {
      const container = remove.closest('[data-wdc-places]');
      if (container.querySelectorAll('[data-wdc-place]').length > 1) {
        remove.closest('[data-wdc-place]').remove();
        renumberPlaces(container);
      }
      requestPreview(remove.closest('form'));
      return;
    }

    const pickupMap = event.target.closest('[data-wdc-admin-pickup-map]');
    if (pickupMap) {
      const box = pickupMap.closest('section');
      const message = box && box.querySelector('[data-wdc-admin-pickup-map-message]');
      if (message) message.hidden = false;
    }
  });

  document.addEventListener('input', function (event) {
    const form = event.target.closest('[data-wdc-shipment-form]');
    if (form) {
      if (event.target.matches('input[type="number"]')) {
        event.target.value = event.target.value.replace(/[^\d]/g, '');
      }
      updateDemandAddress(form);
      schedulePreview(form);
    }
  });

  document.addEventListener('change', function (event) {
    const form = event.target.closest('[data-wdc-shipment-form]');
    if (!form) return;
    if (event.target.matches('[data-wdc-service-select]')) {
      updateTariffOptions(form);
    }
    updateDemandAddress(form);
    schedulePreview(form);
  });

  document.addEventListener('submit', function (event) {
    const form = event.target.closest('[data-wdc-shipment-form]');
    if (!form) return;
    event.preventDefault();
    const errors = form.querySelector('[data-wdc-shipment-errors]');
    if (errors) errors.textContent = '';
    const data = new FormData(form);
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
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось создать отправление.');
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
  });

  document.querySelectorAll('[data-wdc-shipment-form]').forEach((form) => {
    updateTariffOptions(form);
    updateDemandAddress(form);
  });
})();
