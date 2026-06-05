(function () {
  const timers = new WeakMap();
  const formSelector = '[data-wdc-shipment-form], .wdc-shipment-form';

  function findShipmentContainer(element) {
    if (!element || !element.closest) return null;
    const direct = element.closest(formSelector);
    if (direct) return direct;
    const modal = element.closest('[data-wdc-shipment-modal], .wdc-shipment-modal');
    if (modal) return modal.querySelector(formSelector);
    const box = element.closest('[data-wdc-shipments-metabox]');
    return box ? box.querySelector(formSelector) : null;
  }

  function findShipmentForm(element) {
    return findShipmentContainer(element);
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
      if (field.closest('[data-wdc-declared-value-field][hidden]')) return;
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
    updateDeclaredValueFields(form);
    updateCreateAvailability(form);
    if (!hasTariffs && window.console && typeof window.console.warn === 'function') {
      window.console.warn('WDC shipments: no enabled tariffs for selected service.', {
        service_key: serviceKey,
        tariffs: rawTariffs
      });
    }
  }

  function selectedDeliveryType(form) {
    const service = form.querySelector('[data-wdc-service-select]');
    const option = service && service.options[service.selectedIndex] ? service.options[service.selectedIndex] : null;
    return option ? option.dataset.deliveryType || '' : '';
  }

  function updateScenarioSections(form) {
    const deliveryType = selectedDeliveryType(form);
    const pickup = form.querySelector('[data-wdc-pickup-section]');
    const courier = form.querySelector('[data-wdc-courier-section]');
    if (pickup) pickup.hidden = deliveryType !== 'pickup';
    if (courier) courier.hidden = deliveryType !== 'courier';
    updateCreateAvailability(form);
  }

  function selectedTariff(form) {
    const service = form.querySelector('[data-wdc-service-select]');
    const tariff = form.querySelector('[data-wdc-tariff-select]');
    if (!service || !tariff) return null;
    const option = service.options[service.selectedIndex] || null;
    try {
      const tariffs = JSON.parse(option ? option.dataset.tariffs || '[]' : '[]');
      return tariffs.find((item) => String(item.object_code || '') === String(tariff.value || '')) || null;
    } catch (error) {
      return null;
    }
  }

  function updateDeclaredValueFields(form) {
    const tariff = selectedTariff(form);
    const visible = !!(tariff && tariff.has_declared_value);
    form.querySelectorAll('[data-wdc-declared-value-field]').forEach((field, index) => {
      field.hidden = !visible;
      field.querySelectorAll('input').forEach((input) => {
        input.disabled = !visible;
        if (!visible) {
          input.value = '';
          return;
        }
        if (index === 0 && !input.value && field.dataset.defaultDeclaredValueRub) {
          input.value = String(field.dataset.defaultDeclaredValueRub);
        }
      });
    });
  }

  function updateCreateAvailability(form) {
    const submit = form.querySelector('[data-wdc-create-shipment]');
    if (!submit) return;
    const tariff = form.querySelector('[data-wdc-tariff-select]');
    const hasTariffs = !!(tariff && !tariff.disabled && tariff.options.length);
    const deliveryType = selectedDeliveryType(form);
    const pickupMissing = deliveryType === 'pickup' && !!form.querySelector('[data-wdc-pickup-warning]');
    const normalizedJson = form.querySelector('[data-wdc-normalized-address-json]');
    let courierReady = true;
    if (deliveryType === 'courier') {
      courierReady = false;
      try {
        const snapshot = JSON.parse(normalizedJson && normalizedJson.value ? normalizedJson.value : '{}');
        courierReady = snapshot && snapshot.success === true;
      } catch (error) {
        courierReady = false;
      }
    }
    submit.disabled = !hasTariffs || pickupMissing || !courierReady;
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
      const title = row.querySelector('[data-wdc-place-title]');
      if (title) title.textContent = 'Место ' + (index + 1);
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
    updateScenarioSections(form);
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
        input.value = '';
      });
      container.appendChild(clone);
      renumberPlaces(container);
      updateRemoveButtons(container);
      if (form) updateDeclaredValueFields(form);
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

    const normalize = event.target.closest('[data-wdc-normalize-address]');
    if (normalize) {
      const form = findShipmentForm(normalize);
      if (!form) return;
      const status = form.querySelector('[data-wdc-normalized-status]');
      const display = form.querySelector('[data-wdc-normalized-address-display]');
      const snapshotInput = form.querySelector('[data-wdc-normalized-address-json]');
      const data = collectShipmentData(form);
      data.append('action', window.wdcShipmentsAdmin.normalizeAddressAction);
      data.append('nonce', window.wdcShipmentsAdmin.nonce);
      if (status) status.textContent = 'Обработка адреса...';
      fetch(window.wdcShipmentsAdmin.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload || !payload.success) {
            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось обработать адрес.');
          }
          const snapshot = payload.data.normalized_address || {};
          if (snapshotInput) snapshotInput.value = JSON.stringify(snapshot);
          if (display) display.value = snapshot.display || '';
          if (status) status.textContent = snapshot.success ? 'Адрес обработан Почтой России.' : 'Адрес не подтвержден Почтой России, создание отправления заблокировано.';
          updateCreateAvailability(form);
          requestPreview(form);
        })
        .catch((error) => {
          if (status) status.textContent = error.message;
          updateCreateAvailability(form);
        });
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
    }
  });

  document.addEventListener('input', function (event) {
    if (event.target.matches('[data-wdc-courier-original-address]')) {
      const form = findShipmentForm(event.target);
      if (form) {
        const snapshotInput = form.querySelector('[data-wdc-normalized-address-json]');
        const display = form.querySelector('[data-wdc-normalized-address-display]');
        const status = form.querySelector('[data-wdc-normalized-status]');
        if (snapshotInput) snapshotInput.value = '';
        if (display) display.value = '';
        if (status) status.textContent = 'Адрес изменен, нужно обработать адрес заново.';
        updateCreateAvailability(form);
        schedulePreview(form);
      }
      return;
    }
    if (event.target.matches('[data-wdc-integer-input]')) {
      cleanIntegerInput(event.target);
      const integerForm = findShipmentForm(event.target);
      if (integerForm) {
        updateScenarioSections(integerForm);
        schedulePreview(integerForm);
      }
      return;
    }
    const form = findShipmentForm(event.target);
    if (form) {
      updateScenarioSections(form);
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
      updateScenarioSections(form);
      schedulePreview(form);
    }
  });

  document.addEventListener('change', function (event) {
    const form = findShipmentForm(event.target);
    if (!form) return;
    if (event.target.matches('[data-wdc-service-select]')) {
      updateTariffOptions(form);
    }
    if (event.target.matches('[data-wdc-tariff-select]')) {
      updateDeclaredValueFields(form);
    }
    updateScenarioSections(form);
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

