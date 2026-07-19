  function nextShipmentItemIndex(form) {
    const rows = shipmentItemRows(form);
    return rows.reduce((max, row) => {
      const value = parseInt(row.getAttribute('data-wdc-row-index') || '0', 10) || 0;
      return Math.max(max, value);
    }, 0) + 1;
  }

  function rewriteShipmentItemNames(row, index) {
    if (!row) return;
    row.setAttribute('data-wdc-row-index', String(index));
    row.querySelectorAll('[name]').forEach((input) => {
      input.name = input.name.replace(/shipment_items\[[^\]]+\]/, 'shipment_items[' + index + ']');
    });
  }

  function shipmentItemRows(form) {
    return form ? Array.from(form.querySelectorAll('[data-wdc-shipment-item-row]')) : [];
  }

  function placeSelect(row) {
    return row ? row.querySelector('[data-wdc-shipment-place-select]') : null;
  }

  function shipmentQtyInput(row) {
    return row ? row.querySelector('[data-wdc-shipment-item-qty]') : null;
  }

  function switchShipmentTab(form, tabName) {
    if (!form) return;
    form.querySelectorAll('[data-wdc-shipment-tab]').forEach((button) => {
      button.classList.toggle('is-active', button.getAttribute('data-wdc-shipment-tab') === tabName);
    });
    form.querySelectorAll('[data-wdc-shipment-tab-panel]').forEach((panel) => {
      panel.hidden = panel.getAttribute('data-wdc-shipment-tab-panel') !== tabName;
    });
    if (tabName === 'places') updateShipmentPlaceOptions(form);
  }

  function shipmentPlaceOptions(form) {
    if (!form) return [];
    return Array.from(form.querySelectorAll('[data-wdc-place]')).map((row, index) => {
      const number = String(index + 1);
      const weight = row.querySelector('input[name*="[weight_g]"]');
      const length = row.querySelector('input[name*="[length_cm]"]');
      const width = row.querySelector('input[name*="[width_cm]"]');
      const height = row.querySelector('input[name*="[height_cm]"]');
      return {
        number,
        label: number,
        weight: parseInt(weight && weight.value ? weight.value : '0', 10) || 0,
        length: parseDecimalValue(length && length.value ? length.value : '0'),
        width: parseDecimalValue(width && width.value ? width.value : '0'),
        height: parseDecimalValue(height && height.value ? height.value : '0')
      };
    });
  }

  function updateShipmentPlaceOptions(form) {
    if (!form) return;
    const places = Array.from(form.querySelectorAll('[data-wdc-place]'));
    const options = shipmentPlaceOptions(form);
    places.forEach((row) => {
      const hint = row.querySelector('[data-wdc-weight-hint]');
      if (hint) hint.hidden = places.length !== 1;
    });
    form.querySelectorAll('[data-wdc-shipment-place-select]').forEach((select) => {
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
    updateShipmentSplitAvailability(form, options.length);
    updateShipmentItemsSummary(form, options);
  }

  function refreshShipmentItemsSummary(form) {
    updateShipmentItemsSummary(form, shipmentPlaceOptions(form));
  }

  function updateShipmentItemsSummary(form, places) {
    const summary = form && form.querySelector('[data-wdc-shipment-items-summary]');
    if (!summary) return;
    const totals = {};
    (places || []).forEach((place) => {
      totals[place.number] = { weight: 0, cost: 0, quantity: 0, place };
    });
    shipmentItemRows(form).forEach((row) => {
      const place = placeSelect(row);
      const qty = shipmentQtyInput(row);
      const weight = row.querySelector('input[name$="[weight]"]');
      const cost = row.querySelector('input[name$="[cost]"]');
      const placeNumber = place && place.value ? place.value : '1';
      if (!totals[placeNumber]) totals[placeNumber] = { weight: 0, cost: 0, quantity: 0, place: { number: placeNumber, weight: 0 } };
      const amount = parseInt(qty && qty.value ? qty.value : '0', 10) || 0;
      totals[placeNumber].quantity += amount;
      totals[placeNumber].weight += amount * (parseInt(weight && weight.value ? weight.value : '0', 10) || 0);
      totals[placeNumber].cost += amount * parseDecimalValue(cost && cost.value ? cost.value : '0');
    });
    summary.innerHTML = Object.keys(totals).sort().map((number) => {
      const row = totals[number];
      const error = row.place.weight > 0 && row.weight > row.place.weight ? ' data-error="1"' : '';
      return '<p' + error + '><strong>Место ' + number + ':</strong> вес места ' + row.place.weight + ' г; заполнено: товары ' + row.quantity + ' шт, вес товаров ' + row.weight + ' г, стоимость ' + row.cost.toFixed(2) + ' руб.</p>';
    }).join('');
  }

  function normalizeQtyRows(rows, targetTotal, lockedRow) {
    const locked = lockedRow || null;
    const otherRows = rows.filter((row) => row !== locked);
    const lockedInput = locked ? shipmentQtyInput(locked) : null;
    const rowCount = rows.length;
    if (!rowCount) return;
    const maxPerRow = Math.max(1, targetTotal - rowCount + 1);
    rows.forEach((row) => {
      const input = shipmentQtyInput(row);
      if (!input) return;
      input.max = String(rowCount > 1 ? Math.max(1, targetTotal - 1) : Math.min(999, targetTotal));
    });
    if (lockedInput) {
      const lockedValue = Math.max(1, Math.min(maxPerRow, parseInt(lockedInput.value || '1', 10) || 1));
      lockedInput.value = String(lockedValue);
    }
    const lockedValue = lockedInput ? (parseInt(lockedInput.value || '1', 10) || 1) : 0;
    let otherTarget = targetTotal - lockedValue;
    if (!locked) otherTarget = targetTotal;
    if (otherRows.length === 0) return;
    const minOther = otherRows.length;
    otherTarget = Math.max(minOther, otherTarget);
    otherRows.forEach((row) => {
      const input = shipmentQtyInput(row);
      if (!input) return;
      input.value = String(Math.max(1, parseInt(input.value || '1', 10) || 1));
    });
    let sum = otherRows.reduce((total, row) => {
      const input = shipmentQtyInput(row);
      return total + (parseInt(input && input.value ? input.value : '1', 10) || 1);
    }, 0);
    while (sum > otherTarget) {
      let changed = false;
      for (let i = otherRows.length - 1; i >= 0 && sum > otherTarget; i--) {
        const input = shipmentQtyInput(otherRows[i]);
        const value = parseInt(input && input.value ? input.value : '1', 10) || 1;
        if (input && value > 1) {
          input.value = String(value - 1);
          sum--;
          changed = true;
        }
      }
      if (!changed) break;
    }
    while (sum < otherTarget) {
      const input = shipmentQtyInput(otherRows[0]);
      if (!input) break;
      input.value = String((parseInt(input.value || '1', 10) || 1) + 1);
      sum++;
    }
  }

  function rebalanceShipmentItemGroup(form, groupKey, changedRow) {
    if (!form || !groupKey) return;
    const rows = shipmentItemRows(form).filter((row) => row.getAttribute('data-group-key') === groupKey);
    const base = rows.find((row) => row.hasAttribute('data-wdc-base-row'));
    if (!base) return;
    const total = parseInt(base.getAttribute('data-ordered-quantity') || '1', 10) || 1;
    normalizeQtyRows(rows, total, rows.includes(changedRow) ? changedRow : null);
  }

  function originalItemData(row) {
    try {
      const data = JSON.parse(row && row.getAttribute('data-wdc-original-item') || '{}');
      return data && typeof data === 'object' ? data : {};
    } catch (error) {
      return {};
    }
  }

  function setRowInput(row, suffix, value) {
    const input = row && row.querySelector('input[name$="[' + suffix + ']"]');
    if (input) input.value = value !== null && value !== undefined ? String(value) : '';
  }

  function restoreOriginalBaseRow(row, resetQuantity) {
    const original = originalItemData(row);
    if (!row || !Object.keys(original).length) return;
    if (resetQuantity) setRowInput(row, 'amount', original.ordered_quantity || original.amount || 1);
    setRowInput(row, 'cost', original.cost || 0);
    setRowInput(row, 'weight', original.weight || 1);
    setRowInput(row, 'length_cm', original.length_cm || 1);
    setRowInput(row, 'width_cm', original.width_cm || 1);
    setRowInput(row, 'height_cm', original.height_cm || 1);
    const select = placeSelect(row);
    if (select) select.value = String(original.place_number || 1);
  }

  function mergeShipmentSplitRows(form) {
    if (!form) return;
    shipmentItemRows(form).filter((row) => row.hasAttribute('data-wdc-split-row')).forEach((row) => {
      const groupKey = row.getAttribute('data-group-key') || '';
      row.remove();
      rebalanceShipmentItemGroup(form, groupKey);
    });
    form.querySelectorAll('[data-wdc-shipment-place-select]').forEach((select) => {
      select.value = '1';
    });
    shipmentItemRows(form).filter((row) => row.hasAttribute('data-wdc-base-row')).forEach((row) => {
      restoreOriginalBaseRow(row, true);
    });
  }

  function mergeRowsFromRemovedPlace(form, removedNumber) {
    if (!form || !removedNumber) return;
    const affectedGroups = new Set();
    shipmentItemRows(form).forEach((row) => {
      const select = placeSelect(row);
      if (!select || String(select.value || '') !== String(removedNumber)) return;
      const groupKey = row.getAttribute('data-group-key') || '';
      if (groupKey) affectedGroups.add(groupKey);
      if (row.hasAttribute('data-wdc-split-row')) {
        row.remove();
        rebalanceShipmentItemGroup(form, groupKey);
        return;
      }
      select.value = '1';
    });
    affectedGroups.forEach((groupKey) => {
      const base = shipmentItemRows(form).find((row) => row.hasAttribute('data-wdc-base-row') && row.getAttribute('data-group-key') === groupKey);
      if (base) restoreOriginalBaseRow(base, false);
      rebalanceShipmentItemGroup(form, groupKey);
    });
  }

  function updateShipmentSplitAvailability(form, placeCount) {
    if (!form) return;
    if (placeCount <= 1) mergeShipmentSplitRows(form);
    form.querySelectorAll('[data-wdc-shipment-item-split]').forEach((button) => {
      const row = button.closest('[data-wdc-shipment-item-row]');
      const qty = parseInt(row && row.getAttribute('data-ordered-quantity') || '1', 10) || 1;
      button.hidden = placeCount <= 1 || qty <= 1;
      button.disabled = button.hidden;
    });
  }

  function splitShipmentItemRow(button) {
    const row = button && button.closest ? button.closest('[data-wdc-shipment-item-row]') : null;
    const form = findShipmentForm(button);
    if (!row || !form) return;
    const rowQtyInput = shipmentQtyInput(row);
    const currentQty = parseInt(rowQtyInput && rowQtyInput.value ? rowQtyInput.value : '0', 10) || 0;
    if (currentQty <= 1) return;
    const clone = row.cloneNode(true);
    const groupKey = row.getAttribute('data-group-key') || row.getAttribute('data-item-key') || '';
    const index = nextShipmentItemIndex(form);
    rewriteShipmentItemNames(clone, index);
    clone.removeAttribute('data-wdc-base-row');
    clone.setAttribute('data-wdc-split-row', '1');
    clone.setAttribute('data-group-key', groupKey);
    clone.querySelectorAll('[data-wdc-shipment-item-split]').forEach((splitButton) => {
      splitButton.removeAttribute('data-wdc-shipment-item-split');
      splitButton.classList.remove('wdc-icon-action--split');
      splitButton.remove();
    });
    const cloneQty = shipmentQtyInput(clone);
    if (rowQtyInput) rowQtyInput.value = String(currentQty - 1);
    if (cloneQty) cloneQty.value = '1';
    const itemKey = clone.querySelector('input[name$="[item_key]"]');
    const parent = clone.querySelector('input[name$="[split_parent]"]') || document.createElement('input');
    if (itemKey) itemKey.value = groupKey + ':split:' + index;
    parent.type = 'hidden';
    parent.name = 'shipment_items[' + index + '][split_parent]';
    parent.value = groupKey;
    clone.appendChild(parent);
    const actionCell = clone.querySelector('[data-wdc-shipment-item-actions], .wdc-cdek-item-actions') || clone.querySelector('td:last-child');
    if (actionCell) {
      actionCell.innerHTML = '<button type="button" class="wdc-icon-action wdc-icon-action--danger" data-wdc-remove-shipment-split title="Удалить строку" aria-label="Удалить строку">❌</button>';
    }
    row.after(clone);
    rebalanceShipmentItemGroup(form, groupKey);
    updateShipmentPlaceOptions(form);
    schedulePreview(form);
  }

  function addManualShipmentItemRow(button) {
    const form = findShipmentForm(button);
    const table = form && form.querySelector('[data-wdc-shipment-items-table]');
    const body = table && table.querySelector('tbody');
    if (!form || !body) return;
    const index = nextShipmentItemIndex(form);
    const rowKey = 'manual-' + index;
    const row = document.createElement('tr');
    row.setAttribute('data-wdc-shipment-item-row', '1');
    row.setAttribute('data-wdc-manual-row', '1');
    row.setAttribute('data-item-key', rowKey);
    row.setAttribute('data-group-key', rowKey);
    row.setAttribute('data-ordered-quantity', '999');
    row.setAttribute('data-wdc-row-index', String(index));
    row.innerHTML = [
      '<td class="wdc-cdek-item-product"><input type="text" name="shipment_items[' + index + '][name]" value="" placeholder="Товар"><input type="hidden" name="shipment_items[' + index + '][item_key]" value="' + rowKey + '"><input type="hidden" name="shipment_items[' + index + '][ordered_quantity]" value="999"></td>',
      '<td class="wdc-cdek-item-sku wdc-product-search-cell"><input type="text" name="shipment_items[' + index + '][ware_key]" value="" placeholder="Артикул" autocomplete="off" data-wdc-product-search-input><div class="wdc-product-search-results" data-wdc-product-search-results hidden></div></td>',
      '<td><input class="wdc-cdek-input-qty" type="number" min="1" max="999" step="1" name="shipment_items[' + index + '][amount]" value="1" data-wdc-shipment-item-qty data-wdc-integer-input></td>',
      '<td><input class="wdc-cdek-input-price" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[' + index + '][cost]" value="0" data-wdc-decimal-input="2"></td>',
      '<td><input class="wdc-cdek-input-weight" type="number" min="1" step="1" name="shipment_items[' + index + '][weight]" value="1" data-wdc-integer-input></td>',
      '<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[' + index + '][length_cm]" value="1" data-wdc-decimal-input="1"></td>',
      '<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[' + index + '][width_cm]" value="1" data-wdc-decimal-input="1"></td>',
      '<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[' + index + '][height_cm]" value="1" data-wdc-decimal-input="1"></td>',
      '<td><select name="shipment_items[' + index + '][place_number]" data-wdc-shipment-place-select><option value="1">1</option></select></td>',
      '<td class="wdc-cdek-item-actions" data-wdc-shipment-item-actions><button type="button" class="wdc-icon-action wdc-icon-action--danger" data-wdc-remove-manual-shipment-item title="Удалить строку" aria-label="Удалить строку">❌</button></td>'
    ].join('');
    body.appendChild(row);
    updateShipmentPlaceOptions(form);
    schedulePreview(form);
  }

  function applyProductToManualRow(row, product) {
    if (!row || !product) return;
    const set = function (suffix, value) {
      const input = row.querySelector('input[name$="[' + suffix + ']"]');
      if (input) input.value = value !== null && value !== undefined ? String(value) : '';
    };
    set('name', product.name || '');
    set('ware_key', product.sku || '');
    set('cost', product.price || 0);
    set('weight', product.weight_g || 1);
    set('length_cm', product.length_cm || 1);
    set('width_cm', product.width_cm || 1);
    set('height_cm', product.height_cm || 1);
    const place = placeSelect(row);
    if (place) place.value = '1';
  }

  function renderProductSearchResults(input, items) {
    const row = input.closest('[data-wdc-shipment-item-row]');
    const results = row && row.querySelector('[data-wdc-product-search-results]');
    if (!results) return;
    if (!items.length) {
      results.hidden = true;
      results.innerHTML = '';
      return;
    }
    results.innerHTML = items.map((item) => {
      return '<button type="button" data-wdc-product-search-choice data-product="' + escapeHtml(JSON.stringify(item)) + '"><strong>' + escapeHtml(item.name || '') + '</strong><span>' + escapeHtml(item.sku || '') + '</span></button>';
    }).join('');
    results.hidden = false;
  }

  function searchProductsForManualItem(input) {
    const query = String(input.value || '').trim();
    if (query.length < 2) {
      renderProductSearchResults(input, []);
      return;
    }
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.searchProductsAction || 'wdc_search_products_for_shipment_item');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('query', query);
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        const items = payload && payload.success && payload.data && Array.isArray(payload.data.items) ? payload.data.items : [];
        renderProductSearchResults(input, items);
      })
      .catch(() => renderProductSearchResults(input, []));
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
      if (item.delivery_mode) option.dataset.deliveryMode = String(item.delivery_mode);
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
    updateCdekDeliveryModeUi(form);
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
    form.querySelectorAll('[data-wdc-dpd-courier-instructions-row]').forEach((row) => {
      row.hidden = deliveryType !== 'courier';
    });
    updateCdekDeliveryModeUi(form);
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

  function firstFieldValue(form, selectors) {
    for (const selector of selectors) {
      const value = fieldValue(form, selector);
      if (value) return value;
    }
    return '';
  }

  function formatDateInputValue(date) {
    const year = String(date.getFullYear());
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
  }

  function dateFromInputValue(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return new Date();
    const parsed = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(parsed.getTime()) ? new Date() : parsed;
  }

  function findDateStepInput(button) {
    const row = button && button.closest('.wdc-dpd-date-row');
    if (row) {
      const rowInput = row.querySelector('input[type="date"]');
      if (rowInput) return rowInput;
    }
    const label = button && button.closest('label');
    return label ? label.querySelector('input[type="date"]') : null;
  }

  function stepDateInput(button) {
    const input = findDateStepInput(button);
    const step = Number(button && button.dataset ? button.dataset.wdcDateStep : 0);
    if (!input || !Number.isFinite(step) || 0 === step) return;
    const date = dateFromInputValue(input.value);
    date.setDate(date.getDate() + step);
    input.value = formatDateInputValue(date);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function openNativeDatePicker(input) {
    if (!input || input._wdcDatePickerOpening) return;
    input._wdcDatePickerOpening = true;
    window.setTimeout(function () {
      input._wdcDatePickerOpening = false;
    }, 180);
    try {
      input.focus();
      if (typeof input.showPicker === 'function') {
        input.showPicker();
      }
    } catch (error) {
      input.focus();
    }
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

