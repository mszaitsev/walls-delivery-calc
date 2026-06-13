(function () {
  const timers = new WeakMap();
  const toastTimers = new WeakMap();
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

  function switchShipmentTab(form, tabName) {
    if (!form) return;
    form.querySelectorAll('[data-wdc-shipment-tab]').forEach((button) => {
      button.classList.toggle('is-active', button.getAttribute('data-wdc-shipment-tab') === tabName);
    });
    form.querySelectorAll('[data-wdc-shipment-tab-panel]').forEach((panel) => {
      panel.hidden = panel.getAttribute('data-wdc-shipment-tab-panel') !== tabName;
    });
    if (tabName === 'places') updateCdekPlaceOptions(form);
  }

  function updateCdekPlaceOptions(form) {
    if (!form) return;
    const places = Array.from(form.querySelectorAll('[data-wdc-place]'));
    const options = places.map((row, index) => {
      const number = String(index + 1);
      const weight = row.querySelector('input[name*="[weight_g]"]');
      const length = row.querySelector('input[name*="[length_cm]"]');
      const width = row.querySelector('input[name*="[width_cm]"]');
      const height = row.querySelector('input[name*="[height_cm]"]');
      return {
        number,
        label: number,
        weight: parseInt(weight && weight.value ? weight.value : '0', 10) || 0,
        length: parseInt(length && length.value ? length.value : '0', 10) || 0,
        width: parseInt(width && width.value ? width.value : '0', 10) || 0,
        height: parseInt(height && height.value ? height.value : '0', 10) || 0
      };
    });
    form.querySelectorAll('[data-wdc-cdek-place-select]').forEach((select) => {
      const current = select.value || '1';
      select.innerHTML = '';
      options.forEach((option) => {
        const el = document.createElement('option');
        el.value = option.number;
        el.textContent = option.label;
        select.appendChild(el);
      });
      select.value = options.some((option) => option.number === current) ? current : '1';
    });
    updateCdekItemsSummary(form, options);
  }

  function updateCdekItemsSummary(form, places) {
    const summary = form && form.querySelector('[data-wdc-cdek-items-summary]');
    if (!summary) return;
    const totals = {};
    (places || []).forEach((place) => {
      totals[place.number] = { weight: 0, cost: 0, quantity: 0, place };
    });
    form.querySelectorAll('[data-wdc-cdek-item-row]').forEach((row) => {
      const place = row.querySelector('[data-wdc-cdek-place-select]');
      const qty = row.querySelector('[data-wdc-cdek-qty]');
      const weight = row.querySelector('input[name$="[weight]"]');
      const cost = row.querySelector('input[name$="[cost]"]');
      const placeNumber = place && place.value ? place.value : '1';
      if (!totals[placeNumber]) totals[placeNumber] = { weight: 0, cost: 0, quantity: 0, place: { number: placeNumber, weight: 0 } };
      const amount = parseInt(qty && qty.value ? qty.value : '0', 10) || 0;
      totals[placeNumber].quantity += amount;
      totals[placeNumber].weight += amount * (parseInt(weight && weight.value ? weight.value : '0', 10) || 0);
      totals[placeNumber].cost += amount * (parseFloat(cost && cost.value ? cost.value : '0') || 0);
    });
    summary.innerHTML = Object.keys(totals).sort().map((number) => {
      const row = totals[number];
      const error = row.place.weight > 0 && row.weight > row.place.weight ? ' data-error="1"' : '';
      return '<p' + error + '><strong>Место ' + number + ':</strong> товары ' + row.quantity + ', вес товаров ' + row.weight + ' г, стоимость ' + row.cost.toFixed(2) + '</p>';
    }).join('');
  }

  function splitCdekItemRow(button) {
    const row = button && button.closest ? button.closest('[data-wdc-cdek-item-row]') : null;
    const form = findShipmentForm(button);
    if (!row || !form) return;
    const qtyInput = row.querySelector('[data-wdc-cdek-qty]');
    const currentQty = parseInt(qtyInput && qtyInput.value ? qtyInput.value : '0', 10) || 0;
    if (currentQty <= 1) return;
    const clone = row.cloneNode(true);
    const cloneQty = clone.querySelector('[data-wdc-cdek-qty]');
    if (qtyInput) qtyInput.value = String(currentQty - 1);
    if (cloneQty) cloneQty.value = '1';
    clone.querySelectorAll('[name]').forEach((input) => {
      input.name = input.name.replace(/cdek_items\[(\d+)\]/, function (_, number) {
        return 'cdek_items[' + (Date.now() + parseInt(number, 10)) + ']';
      });
    });
    const actionCell = clone.querySelector('td:last-child');
    if (actionCell) {
      actionCell.innerHTML = '<button type="button" class="button" data-wdc-cdek-minus>-</button> <button type="button" class="button" data-wdc-cdek-plus>+</button>';
    }
    row.after(clone);
    updateCdekPlaceOptions(form);
    schedulePreview(form);
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
        if (errors) {
          const previewErrors = payload.data.preview && Array.isArray(payload.data.preview.errors)
            ? payload.data.preview.errors
            : [];
          errors.textContent = previewErrors.length ? previewErrors.join('; ') : '';
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
      if (item.selected_missing) option.dataset.selectedMissing = '1';
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
      field.style.display = visible ? '' : 'none';
      field.querySelectorAll('input').forEach((input) => {
        if (!visible) {
          input.disabled = true;
          input.value = '';
          return;
        }
        input.disabled = false;
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

  function pointId(point) {
    return String(point && (point.point_code || point.cdek_code || point.code || point.id || point.postcode || point.address) || '');
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function fieldValue(form, selector) {
    const field = form && form.querySelector(selector);
    return field ? String(field.value || '').trim() : '';
  }

  function pickupContext(form) {
    return {
      carrierKey: fieldValue(form, '[data-wdc-pickup-carrier-key]') || fieldValue(form, 'input[name="carrier_key"]'),
      serviceKey: fieldValue(form, '[data-wdc-pickup-service-key]') || fieldValue(form, 'input[name="service_key"]'),
      pickupFamily: fieldValue(form, '[data-wdc-pickup-family]'),
      city: fieldValue(form, '[data-wdc-pickup-location-city]'),
      region: fieldValue(form, '[data-wdc-pickup-location-region]'),
      postcode: fieldValue(form, '[data-wdc-pickup-location-postcode]'),
      address: fieldValue(form, '[data-wdc-pickup-location-address]'),
      fiasId: fieldValue(form, '[data-wdc-pickup-location-fias]'),
      garId: fieldValue(form, '[data-wdc-pickup-location-gar]'),
      locationId: fieldValue(form, '[data-wdc-pickup-location-id]'),
      lat: fieldValue(form, '[data-wdc-pickup-location-lat]'),
      lng: fieldValue(form, '[data-wdc-pickup-location-lng]')
    };
  }

  function pickupUsesCodeDisplay(form) {
    return pickupContext(form).pickupFamily === 'cdek:pickup';
  }

  function pickupCode(point) {
    return String(point && (point.point_code || point.cdek_code || point.code || point.display_code || '') || '');
  }

  function operationSummary(status) {
    return [
      status && status.carrier_operation_date,
      status && status.carrier_operation_address,
      status && status.carrier_operation_index
    ].filter(function (value) {
      return String(value || '').trim() !== '';
    }).join(', ') || '-';
  }

  function renderShipmentStatus(box, status) {
    if (!box || !status) return;
    applyPresentation(box, status.presentation || null);
    const fields = {
      '[data-wdc-shipment-summary-status]': status.shipment_status_label || status.universal_status_label || 'создано',
      '[data-wdc-status-carrier]': status.carrier_status_title || '-',
      '[data-wdc-status-operation]': operationSummary(status),
      '[data-wdc-status-checked]': status.tracking_checked_at || '-',
      '[data-wdc-planned-delivery-date]': status.cdek_planned_delivery_date || '',
      '[data-wdc-tracking-number]': status.barcode || ''
    };
    Object.keys(fields).forEach((selector) => {
      const element = box.querySelector(selector);
      if (element) element.textContent = fields[selector];
    });
    const plannedRow = box.querySelector('[data-wdc-planned-delivery-row]');
    if (plannedRow) plannedRow.hidden = !String(status.cdek_planned_delivery_date || '').trim();
    updateShipmentButtons(box, {
      hasShipment: !!status.has_shipment,
      canCancel: !!status.can_cancel,
      canRemove: !!status.can_remove_from_order,
      canUpdate: !!status.can_update_status
    });
    setTrackingDisplay(box, status.barcode || '');
    renderShipmentPrice(box, status);
  }

  function renderShipmentPrice(box, status) {
    if (!box) return;
    const row = box.querySelector('[data-wdc-shipment-price-row]');
    const label = box.querySelector('[data-wdc-shipment-price-label]');
    if (!row || !label) return;
    const price = String(status && status.actual_cost_label || '').trim();
    row.hidden = !price;
    label.textContent = price;
    row.classList.remove('wdc-shipment-price-ok', 'wdc-shipment-price-warning', 'wdc-shipment-price-neutral');
    const compare = String(status && status.actual_cost_compare_status || 'neutral');
    const className = compare === 'ok'
      ? 'wdc-shipment-price-ok'
      : (compare === 'warning' ? 'wdc-shipment-price-warning' : 'wdc-shipment-price-neutral');
    row.classList.add(className);
    row.title = String(status && status.actual_cost_compare_message || '');
  }

  function renderShipmentTechnicalInfo(box, data) {
    if (!box || !data) return;
    const backlogOrderId = String(data.backlog_order_id || '').trim();
    const value = box.querySelector('[data-wdc-backlog-order-id]');
    if (value) value.textContent = backlogOrderId;
  }

  function setTrackingDisplay(box, trackingNumber) {
    if (!box) return;
    const value = String(trackingNumber || '').trim();
    const row = box.querySelector('[data-wdc-tracking-row]');
    const number = box.querySelector('[data-wdc-tracking-number]');
    const copy = box.querySelector('[data-wdc-copy-tracking]');
    if (number) number.textContent = value;
    if (row) row.hidden = !value;
    if (copy) {
      copy.disabled = !value;
      copy.dataset.trackingNumber = value;
    }
  }

  function setVisible(element, visible) {
    if (!element) return;
    element.hidden = !visible;
    element.style.display = visible ? '' : 'none';
  }

  function presentationKey(key) {
    return String(key || '').replace(/_([a-z])/g, function (_match, letter) {
      return letter.toUpperCase();
    });
  }

  function getPresentation(box) {
    const defaults = {
      trackingLabel: 'Отслеживание',
      createButtonLabel: 'Подготовить отправление',
      manualAttachButtonLabel: 'Внести отслеживание вручную',
      cancelButtonLabel: 'Отменить отправление',
      removeButtonLabel: 'Удалить из заказа',
      updateStatusButtonLabel: 'Обновить статус',
      manualAttachPlaceholder: 'Номер отслеживания',
      manualAttachHelp: 'Введите номер отслеживания для поиска и привязки отправления.',
      createdToast: 'Отправление создано.',
      updatedToast: 'Статус отправления обновлен.',
      cancelSuccessToast: 'Отправление отменено.',
      removeSuccessToast: 'Данные отправления удалены из заказа.',
      errorFallbackMessage: 'Не удалось получить статус отправления.',
      pollingTimeoutMessage: 'Автоматическая проверка остановлена через 10 минут. Обновите статус вручную позже.',
      registrationErrorToast: 'Регистрация завершилась ошибкой.',
      registrationSuccessToast: 'Регистрация завершена успешно.',
      autoPollRegistration: '0'
    };
    if (!box || !box.dataset) return defaults;
    return Object.keys(defaults).reduce(function (result, key) {
      result[key] = box.dataset[key] || defaults[key];
      return result;
    }, {});
  }

  function applyPresentation(box, presentation) {
    if (!box || !presentation) return;
    Object.keys(presentation).forEach(function (key) {
      box.dataset[presentationKey(key)] = presentation[key];
    });
    applyPresentationLabels(box);
  }

  function applyPresentationLabels(box) {
    if (!box) return;
    const text = getPresentation(box);
    const pairs = [
      ['[data-wdc-tracking-label]', text.trackingLabel],
      ['[data-wdc-open-shipment-modal]', text.createButtonLabel],
      ['[data-wdc-open-manual-tracking]', text.manualAttachButtonLabel],
      ['[data-wdc-cancel-shipment]', text.cancelButtonLabel],
      ['[data-wdc-remove-shipment-from-order]', text.removeButtonLabel],
      ['[data-wdc-update-shipment-status]', text.updateStatusButtonLabel],
      ['[data-wdc-manual-attach-label]', text.manualAttachPlaceholder],
      ['[data-wdc-manual-attach-help]', text.manualAttachHelp]
    ];
    pairs.forEach(function (pair) {
      const element = box.querySelector(pair[0]);
      if (element) element.textContent = pair[1];
    });
    const input = box.querySelector('[data-wdc-manual-tracking-input]');
    if (input) input.placeholder = text.manualAttachPlaceholder;
  }

  function updateShipmentButtons(box, state) {
    if (!box) return;
    const hasShipment = !!(state && state.hasShipment);
    const canCancel = !!(state && state.canCancel);
    const canRemove = !!(state && state.canRemove);
    const canUpdate = !!(state && state.canUpdate);
    const openButton = box.querySelector('[data-wdc-open-shipment-modal]');
    const updateButton = box.querySelector('[data-wdc-update-shipment-status]');
    const manualButton = box.querySelector('[data-wdc-open-manual-tracking]');
    const cancelButton = box.querySelector('[data-wdc-cancel-shipment]');
    const removeButton = box.querySelector('[data-wdc-remove-shipment-from-order]');
    if (box.dataset) box.dataset.hasShipment = hasShipment ? '1' : '0';
    if (openButton) {
      setVisible(openButton, !hasShipment);
      openButton.disabled = hasShipment;
    }
    if (updateButton) {
      setVisible(updateButton, canUpdate);
      updateButton.disabled = !canUpdate;
    }
    if (manualButton) {
      setVisible(manualButton, !hasShipment);
      manualButton.disabled = hasShipment;
    }
    if (cancelButton) {
      setVisible(cancelButton, canCancel);
      cancelButton.disabled = !canCancel;
    }
    if (removeButton) {
      setVisible(removeButton, canRemove);
      removeButton.disabled = !canRemove;
    }
  }

  function resetShipmentUi(box) {
    if (!box) return;
    const fields = {
      '[data-wdc-shipment-summary-status]': 'не создано',
      '[data-wdc-status-carrier]': '-',
      '[data-wdc-status-operation]': '-',
      '[data-wdc-status-checked]': '-',
      '[data-wdc-planned-delivery-date]': '',
      '[data-wdc-updated-at]': '',
      '[data-wdc-backlog-order-id]': ''
    };
    Object.keys(fields).forEach((selector) => {
      const element = box.querySelector(selector);
      if (element) element.textContent = fields[selector];
    });
    setTrackingDisplay(box, '');
    renderShipmentPrice(box, {});
    const updatedRow = box.querySelector('[data-wdc-updated-row]');
    if (updatedRow) updatedRow.hidden = true;
    const plannedRow = box.querySelector('[data-wdc-planned-delivery-row]');
    if (plannedRow) plannedRow.hidden = true;
    updateShipmentButtons(box, { hasShipment: false, canCancel: false, canRemove: false, canUpdate: false });
    const manualForm = box.querySelector('[data-wdc-manual-tracking-form]');
    if (manualForm) manualForm.hidden = true;
  }

  function showShipmentToast(box, text, type, options) {
    const settings = Object.assign({ append: false }, options || {});
    const host = box || document.body;
    let toast = host.querySelector ? host.querySelector('[data-wdc-shipment-toast]') : null;
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'wdc-shipment-toast';
      toast.setAttribute('data-wdc-shipment-toast', '1');
      host.appendChild(toast);
    }
    const previous = toastTimers.get(toast);
    if (previous) window.clearTimeout(previous);
    toast.dataset.status = type || 'success';
    if (settings.append && !toast.hidden && toast.textContent) {
      toast.textContent = toast.textContent + '\n' + text;
    } else {
      toast.textContent = text;
    }
    toast.hidden = false;
    toastTimers.set(toast, window.setTimeout(function () {
      toast.hidden = true;
    }, 10000));
  }

  function requestShipmentStatus(button, options) {
    const settings = Object.assign({ auto: false }, options || {});
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const text = getPresentation(box);
    const message = box && box.querySelector('[data-wdc-shipment-status-message]');
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.updateStatusAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || 'russian_post_domestic' : 'russian_post_domestic');
    if (button) button.disabled = true;
    if (message) {
      message.dataset.status = '';
      message.textContent = settings.auto ? 'Первое обновление статуса...' : 'Обновление статуса...';
    }
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : text.errorFallbackMessage);
        }
        renderShipmentStatus(box, payload.data.status || {});
        if (message) {
          message.dataset.status = 'success';
          message.textContent = payload.data.message || text.updatedToast;
        }
        if (settings.auto) {
          showShipmentToast(box, payload.data.message || text.updatedToast, 'success', { append: true });
        }
        return payload;
      })
      .catch((error) => {
        if (message) {
          message.dataset.status = settings.auto ? 'warning' : 'error';
          message.textContent = settings.auto
            ? text.createdToast + ' Статус пока не обновлен: ' + error.message
            : error.message;
        }
        if (settings.auto) {
          showShipmentToast(box, text.createdToast + ' Статус пока не обновлен: ' + error.message, 'warning', { append: true });
          return null;
        }
        throw error;
      })
      .finally(() => {
        if (button) button.disabled = false;
      });
  }

  function startCdekPolling(button) {
    let attempts = 0;
    const maxAttempts = 40;
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const tick = function () {
      attempts += 1;
      requestShipmentStatus(button, { auto: true })
        .then((payload) => {
          const data = payload && payload.data ? payload.data : {};
          const status = data.status || {};
          const state = String(status.carrier_operation_index || '').toUpperCase();
          const code = String(status.carrier_operation_address || '').toUpperCase();
          if (state === 'INVALID') {
          showShipmentToast(box, getPresentation(box).registrationErrorToast, 'error', { append: true });
            return;
          }
          if (code === 'CREATED' || data.terminal) {
            showShipmentToast(box, getPresentation(box).registrationSuccessToast, 'success', { append: true });
            return;
          }
          if (attempts >= maxAttempts) {
            showShipmentToast(box, getPresentation(box).pollingTimeoutMessage, 'warning', { append: true });
            return;
          }
          window.setTimeout(tick, 15000);
        })
        .catch(() => {
          if (attempts >= maxAttempts) {
            showShipmentToast(box, getPresentation(box).pollingTimeoutMessage, 'warning', { append: true });
            return;
          }
          window.setTimeout(tick, 15000);
        });
    };
    window.setTimeout(tick, 15000);
  }

  function requestShipmentCancel(button) {
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.cancelAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || 'russian_post_domestic' : 'russian_post_domestic');
    if (button) button.disabled = true;
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось отменить отправление.');
        }
        resetShipmentUi(box);
        showShipmentToast(box, payload.data.message || getPresentation(box).cancelSuccessToast, 'success');
        return payload;
      })
      .catch((error) => {
        showShipmentToast(box, error.message, 'error');
        if (button) button.disabled = false;
        throw error;
      });
  }

  function requestShipmentRemoveFromOrder(button) {
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.removeFromOrderAction || 'wdc_remove_shipment_from_order');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || 'russian_post_domestic' : 'russian_post_domestic');
    if (button) button.disabled = true;
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось удалить данные отправления.');
        }
        resetShipmentUi(box);
        showShipmentToast(box, payload.data.message || getPresentation(box).removeSuccessToast, 'success');
        return payload;
      })
      .catch((error) => {
        showShipmentToast(box, error.message, 'error');
        if (button) button.disabled = false;
        throw error;
      });
  }

  function requestAttachTracking(button) {
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const form = box && box.querySelector('[data-wdc-manual-tracking-form]');
    const input = form && form.querySelector('[data-wdc-manual-tracking-input]');
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.attachTrackingAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || 'russian_post_domestic' : 'russian_post_domestic');
    data.append('barcode', input ? input.value || '' : '');
    if (button) button.disabled = true;
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось сохранить номер отслеживания.');
        }
        if (form) form.hidden = true;
        if (input) input.value = '';
        renderShipmentStatus(box, payload.data.status || {});
        renderShipmentTechnicalInfo(box, payload.data || {});
        setTrackingDisplay(box, payload.data.tracking_number || payload.data.status && payload.data.status.barcode || '');
        const statusPayload = payload.data.status || {};
        updateShipmentButtons(box, {
          hasShipment: !!statusPayload.has_shipment,
          canCancel: !!statusPayload.can_cancel,
          canRemove: !!statusPayload.can_remove_from_order,
          canUpdate: !!statusPayload.can_update_status
        });
        showShipmentToast(box, payload.data.warning || payload.data.message || 'Номер отслеживания сохранен.', payload.data.warning ? 'warning' : 'success');
        return payload;
      })
      .catch((error) => {
        showShipmentToast(box, error.message, 'error');
        throw error;
      })
      .finally(() => {
        if (button) button.disabled = false;
      });
  }

  function copyText(text) {
    const value = String(text || '');
    if (!value) return Promise.reject(new Error('Нет номера для копирования.'));
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(value);
    }
    return new Promise((resolve, reject) => {
      const textarea = document.createElement('textarea');
      textarea.value = value;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.left = '-9999px';
      document.body.appendChild(textarea);
      textarea.select();
      try {
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        ok ? resolve() : reject(new Error('Не удалось скопировать номер.'));
      } catch (error) {
        document.body.removeChild(textarea);
        reject(error);
      }
    });
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

  function pickupSearchRequest(form, query, limit, signal, mode) {
    const context = pickupContext(form);
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.searchPickupPointsAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
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
    data.append('lat', context.lat || '');
    data.append('lng', context.lng || '');
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data,
      signal: signal
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось найти ПВЗ.');
        }
        return Array.isArray(payload.data && payload.data.points) ? payload.data.points.map(normalizePickupPoint) : [];
      });
  }

  function currentPickupQuery(form) {
    const context = pickupContext(form);
    if (context.pickupFamily === 'cdek:pickup') {
      return [context.address, context.city, context.region, context.postcode].filter(Boolean).join(' ').trim();
    }
    const postcode = form.querySelector('[data-wdc-pickup-postcode-field]');
    const address = form.querySelector('[data-wdc-pickup-address-field]');
    return [postcode && postcode.value, address && address.value].filter(Boolean).join(' ').trim();
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
    const warning = form.querySelector('[data-wdc-pickup-warning]');
    if (warning) warning.remove();
    updateCreateAvailability(form);
    requestPreview(form);
  }

  function createPickupPicker(form) {
    const config = window.wdcShipmentsAdmin || {};
    const context = pickupContext(form);
    const codeDisplay = pickupUsesCodeDisplay(form);
    const codeLabel = codeDisplay ? 'Код ПВЗ' : 'Индекс';
    const pickerTitle = codeDisplay ? 'Выбор ПВЗ СДЭК' : 'Выбор ПВЗ / ОПС';
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
      '<div class="wdc-admin-pickup-picker__map" data-wdc-pickup-picker-map></div>',
      '<div class="wdc-admin-pickup-picker__selected" data-wdc-pickup-picker-selected>Выберите ПВЗ на карте или в списке.</div>',
      '<div class="wdc-admin-pickup-picker__list" data-wdc-pickup-picker-list></div>',
      '</div>'
    ].join('');
    document.body.appendChild(root);

    const query = root.querySelector('[data-wdc-pickup-picker-query]');
    const status = root.querySelector('[data-wdc-pickup-picker-status]');
    const mapElement = root.querySelector('[data-wdc-pickup-picker-map]');
    const selected = root.querySelector('[data-wdc-pickup-picker-selected]');
    const list = root.querySelector('[data-wdc-pickup-picker-list]');
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
        '<h3 class="wdc-pickup-popup__title">' + escapeHtml(point.display_title || displayCode || '') + '</h3>',
        '<div class="wdc-pickup-popup__section"><strong>' + escapeHtml(codeLabel) + ':</strong><span>' + escapeHtml(displayCode || '') + '</span></div>',
        '<div class="wdc-pickup-popup__section"><strong>Город:</strong><span>' + escapeHtml(point.city_name || point.city || '') + '</span></div>',
        '<div class="wdc-pickup-popup__section"><strong>Адрес:</strong><span>' + escapeHtml(point.address || '') + '</span></div>',
        '<button type="button" class="button button-primary wdc-pickup-popup__select" data-wdc-pickup-popup-select data-wdc-point-id="' + escapeHtml(pointId(point)) + '">Выбрать этот ПВЗ</button>',
        '</div>'
      ].join('');
    }

    function preview(point) {
      previewPoint = point;
      const displayCode = codeDisplay ? pickupCode(point) : (point.postcode || '');
      selected.innerHTML = [
        '<div class="wdc-admin-pickup-picker__selected-grid">',
        '<span><strong>' + escapeHtml(codeLabel) + '</strong>' + escapeHtml(displayCode || '') + '</span>',
        '<span><strong>Город</strong>' + escapeHtml(point.city_name || point.city || '') + '</span>',
        '<span><strong>Адрес</strong>' + escapeHtml(point.address || '') + '</span>',
        '<button type="button" class="button button-primary" data-wdc-pickup-picker-choose data-wdc-point-id="' + escapeHtml(pointId(point)) + '">Выбрать этот ПВЗ</button>',
        '</div>'
      ].join('');
      if (provider && provider.setActivePoint) provider.setActivePoint(pointId(point));
      if (provider && provider.openPointPopup) provider.openPointPopup(point, renderPopup(point), { forceReopen: true });
      renderList();
    }

    function choose(point) {
      updatePickupDraft(form, point);
      close();
    }

    function renderList() {
      if (!points.length) {
        list.innerHTML = '<p class="description">ПВЗ не найдены.</p>';
        return;
      }
      list.innerHTML = [
        '<table class="widefat striped wdc-admin-pickup-picker__table"><thead><tr><th>' + escapeHtml(codeLabel) + '</th><th>Город</th><th>Адрес</th><th>Выбрать</th></tr></thead><tbody>',
        points.map((point) => {
          const active = previewPoint && pointId(previewPoint) === pointId(point) ? ' class="is-active"' : '';
          const displayCode = codeDisplay ? pickupCode(point) : (point.postcode || '');
          return '<tr data-wdc-pickup-picker-row data-wdc-point-id="' + escapeHtml(pointId(point)) + '"' + active + '><td>' + escapeHtml(displayCode || '') + '</td><td>' + escapeHtml(point.city_name || point.city || '') + '</td><td>' + escapeHtml(point.address || '') + '</td><td><button type="button" class="button" data-wdc-pickup-picker-choose data-wdc-point-id="' + escapeHtml(pointId(point)) + '">Выбрать</button></td></tr>';
        }).join(''),
        '</tbody></table>'
      ].join('');
    }

    function findPoint(id) {
      return points.find((point) => pointId(point) === String(id)) || null;
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
      selected.textContent = 'Выберите ПВЗ на карте или в списке.';
      renderList();
    }

    function runSearch(mode) {
      const value = String(query.value || '').trim();
      if (!value) {
        status.textContent = 'Введите адрес или индекс.';
        return;
      }
      if (controller) controller.abort();
      controller = new AbortController();
      if ((mode || 'search') === 'search' && window.WDCPickupApi && typeof window.WDCPickupApi.addressSearch === 'function') {
        status.textContent = 'Ищем адрес через DaData...';
        window.WDCPickupApi.addressSearch(value, {
          carrier: context.carrierKey || '',
          carrier_key: context.carrierKey || '',
          service_key: context.serviceKey || '',
          pickup_family: context.pickupFamily || '',
          country_code: 'RU',
          location_id: context.locationId || ''
        }, controller.signal)
          .then((result) => {
            searchMarker = addressMarkerFromResult(result);
            renderSearchResults(searchMarker ? 'Адрес найден через DaData.' : 'Адрес не найден через DaData.');
          })
          .catch((error) => {
            if (error.name === 'AbortError') return;
            searchMarker = null;
            renderSearchResults(error.message || 'Адрес не найден или геокодинг недоступен.');
          });
        return;
      }
      searchMarker = null;
      status.textContent = 'Поиск...';
      pickupSearchRequest(form, value, mode === 'location' ? 1000 : 100, controller.signal, mode || 'search')
        .then((found) => {
          points = found;
          status.textContent = points.length ? 'Найдено: ' + points.length : 'ПВЗ не найдены.';
          if (provider && provider.renderMarkers) {
            provider.renderMarkers(points, { activePointId: previewPoint ? pointId(previewPoint) : null, searchMarker: searchMarker });
            if (provider.fitToMarkers) provider.fitToMarkers();
          }
          previewPoint = null;
          selected.textContent = 'Выберите ПВЗ на карте или в списке.';
          renderList();
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
      const chooseButton = event.target.closest('[data-wdc-pickup-picker-choose], [data-wdc-pickup-popup-select]');
      if (chooseButton) {
        const point = findPoint(chooseButton.getAttribute('data-wdc-point-id'));
        if (point) choose(point);
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
          zoom: 11
        },
        yandexApiKey: config.yandexApiKey || '',
        onBoundsChange: function () {}
      });
      provider.onPointClick(function (point) { preview(point); });
      if (provider.onPopupSelect) provider.onPopupSelect(function (point) { choose(point); });
      if (Number.isFinite(initialLat) && Number.isFinite(initialLng) && provider.setCenter) provider.setCenter(initialLat, initialLng, 11);
      window.setTimeout(function () {
        if (provider && provider.invalidateSize) provider.invalidateSize();
      }, 50);
    }

    query.value = currentPickupQuery(form);
    query.focus();
    if (query.value) runSearch('location');
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
      if (form) updateCdekPlaceOptions(form);
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
      if (form) updateCdekPlaceOptions(form);
      if (form) requestPreview(form);
      return;
    }

    const tab = event.target.closest('[data-wdc-shipment-tab]');
    if (tab) {
      switchShipmentTab(findShipmentForm(tab), tab.getAttribute('data-wdc-shipment-tab') || 'main');
      return;
    }

    const split = event.target.closest('[data-wdc-cdek-split]');
    if (split) {
      splitCdekItemRow(split);
      return;
    }

    const minus = event.target.closest('[data-wdc-cdek-minus], [data-wdc-cdek-plus]');
    if (minus) {
      const row = minus.closest('[data-wdc-cdek-item-row]');
      const form = findShipmentForm(minus);
      const input = row && row.querySelector('[data-wdc-cdek-qty]');
      if (input) {
        const delta = minus.matches('[data-wdc-cdek-plus]') ? 1 : -1;
        const max = parseInt(input.max || '999', 10) || 999;
        input.value = String(Math.max(1, Math.min(max, (parseInt(input.value || '1', 10) || 1) + delta)));
      }
      if (form) updateCdekPlaceOptions(form);
      if (form) schedulePreview(form);
      return;
    }

    const openPickupPicker = event.target.closest('[data-wdc-open-pickup-picker]');
    if (openPickupPicker) {
      const form = findShipmentForm(openPickupPicker);
      if (form) createPickupPicker(form);
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
          if (status) status.textContent = snapshot.success ? 'Адрес обработан.' : 'Адрес не подтвержден, создание отправления заблокировано.';
          updateCreateAvailability(form);
          requestPreview(form);
        })
        .catch((error) => {
          if (status) status.textContent = error.message;
          updateCreateAvailability(form);
        });
      return;
    }

    const updateStatus = event.target.closest('[data-wdc-update-shipment-status]');
    if (updateStatus) {
      requestShipmentStatus(updateStatus).catch(function () {});
      return;
    }

    const cancelShipment = event.target.closest('[data-wdc-cancel-shipment]');
    if (cancelShipment) {
      requestShipmentCancel(cancelShipment).catch(function () {});
      return;
    }

    const removeShipmentFromOrder = event.target.closest('[data-wdc-remove-shipment-from-order]');
    if (removeShipmentFromOrder) {
      requestShipmentRemoveFromOrder(removeShipmentFromOrder).catch(function () {});
      return;
    }

    const openManualTracking = event.target.closest('[data-wdc-open-manual-tracking]');
    if (openManualTracking) {
      const box = openManualTracking.closest('[data-wdc-shipments-metabox]');
      const form = box && box.querySelector('[data-wdc-manual-tracking-form]');
      const input = form && form.querySelector('[data-wdc-manual-tracking-input]');
      if (form) form.hidden = false;
      if (input) input.focus();
      return;
    }

    const closeManualTracking = event.target.closest('[data-wdc-cancel-manual-tracking]');
    if (closeManualTracking) {
      const form = closeManualTracking.closest('[data-wdc-manual-tracking-form]');
      if (form) form.hidden = true;
      return;
    }

    const attachTracking = event.target.closest('[data-wdc-attach-tracking]');
    if (attachTracking) {
      requestAttachTracking(attachTracking).catch(function () {});
      return;
    }

    const copyTracking = event.target.closest('[data-wdc-copy-tracking]');
    if (copyTracking) {
      const box = copyTracking.closest('[data-wdc-shipments-metabox]');
      const status = box && box.querySelector('[data-wdc-copy-tracking-status]');
      copyText(copyTracking.dataset.trackingNumber || '').then(() => {
        if (status) status.textContent = 'Скопировано';
        window.setTimeout(function () {
          if (status) status.textContent = '';
        }, 1500);
      }).catch((error) => {
        if (status) status.textContent = error.message;
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
          const box = create.closest('[data-wdc-shipments-metabox]');
          const modal = create.closest('[data-wdc-shipment-modal]');
          if (errors) {
            errors.textContent = '';
          }
          const preview = form.querySelector('[data-wdc-shipment-preview]');
          if (preview && payload.data.preview) {
            preview.textContent = JSON.stringify(payload.data.preview || {}, null, 2);
          }
          if (modal) modal.hidden = true;
          if (box && payload.data.status) {
            renderShipmentStatus(box, payload.data.status);
          }
          renderShipmentTechnicalInfo(box, payload.data || {});
          const openButton = box && box.querySelector('[data-wdc-open-shipment-modal]');
          if (openButton) openButton.disabled = true;
          const updateButton = box && box.querySelector('[data-wdc-update-shipment-status]');
          if (updateButton) {
            updateButton.disabled = false;
          }
          const text = getPresentation(box);
          const statusPayload = payload.data.status || {};
          updateShipmentButtons(box, {
            hasShipment: !!statusPayload.has_shipment,
            canCancel: !!statusPayload.can_cancel,
            canRemove: !!statusPayload.can_remove_from_order,
            canUpdate: !!statusPayload.can_update_status
          });
          showShipmentToast(box, payload.data.message || text.createdToast, 'success');
          if (updateButton && !updateButton.disabled) {
            if (text.autoPollRegistration === '1') {
              startCdekPolling(updateButton);
            } else {
              requestShipmentStatus(updateButton, { auto: true });
            }
          }
        })
        .catch((error) => {
          if (errors) errors.textContent = error.message;
          showShipmentToast(findShipmentForm(create), error.message, 'error');
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
        updateCdekPlaceOptions(integerForm);
        schedulePreview(integerForm);
      }
      return;
    }
    const form = findShipmentForm(event.target);
    if (form) {
      updateScenarioSections(form);
      updateCdekPlaceOptions(form);
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
    updateCdekPlaceOptions(form);
    schedulePreview(form);
  });

  const forms = new Set(document.querySelectorAll(formSelector));
  document.querySelectorAll('[data-wdc-shipments-metabox]').forEach((box) => {
    const form = findShipmentForm(box);
    if (form) forms.add(form);
  });
  forms.forEach((form) => {
    initializeForm(form, false);
    updateCdekPlaceOptions(form);
  });
})();
