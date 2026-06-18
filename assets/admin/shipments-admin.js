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

  function nextCdekItemIndex(form) {
    const rows = shipmentItemRows(form);
    return rows.reduce((max, row) => {
      const value = parseInt(row.getAttribute('data-wdc-row-index') || '0', 10) || 0;
      return Math.max(max, value);
    }, 0) + 1;
  }

  function rewriteCdekItemNames(row, index) {
    if (!row) return;
    row.setAttribute('data-wdc-row-index', String(index));
    row.querySelectorAll('[name]').forEach((input) => {
      input.name = input.name.replace(/cdek_items\[[^\]]+\]/, 'cdek_items[' + index + ']');
    });
  }

  function cleanDecimalInput(input, precision) {
    const raw = String(input.value || '');
    const separatorMatch = raw.match(/[.,]/);
    const separator = separatorMatch ? separatorMatch[0] : '';
    const cleaned = raw.replace(/[^\d.,]+/g, '');
    const separatorIndex = separator ? cleaned.search(/[.,]/) : -1;
    if (separatorIndex === -1) {
      input.value = cleaned.replace(/[.,]/g, '');
      return;
    }
    const integer = cleaned.slice(0, separatorIndex).replace(/[.,]/g, '');
    const decimal = cleaned.slice(separatorIndex + 1).replace(/[.,]/g, '').slice(0, precision);
    input.value = integer + separator + decimal;
  }

  function parseDecimalValue(value) {
    return parseFloat(String(value || '0').replace(',', '.')) || 0;
  }

  function shipmentItemRows(form) {
    return form ? Array.from(form.querySelectorAll('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]')) : [];
  }

  function placeSelect(row) {
    return row ? row.querySelector('[data-wdc-shipment-place-select], [data-wdc-cdek-place-select]') : null;
  }

  function shipmentQtyInput(row) {
    return row ? row.querySelector('[data-wdc-shipment-item-qty], [data-wdc-cdek-qty]') : null;
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
        length: parseDecimalValue(length && length.value ? length.value : '0'),
        width: parseDecimalValue(width && width.value ? width.value : '0'),
        height: parseDecimalValue(height && height.value ? height.value : '0')
      };
    });
    places.forEach((row) => {
      const hint = row.querySelector('[data-wdc-weight-hint]');
      if (hint) hint.hidden = places.length !== 1;
    });
    form.querySelectorAll('[data-wdc-shipment-place-select], [data-wdc-cdek-place-select]').forEach((select) => {
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
    updateCdekSplitAvailability(form, options.length);
    updateCdekItemsSummary(form, options);
  }

  function updateCdekItemsSummary(form, places) {
    const summary = form && form.querySelector('[data-wdc-shipment-items-summary], [data-wdc-cdek-items-summary]');
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

  function rebalanceCdekGroup(form, groupKey, changedRow) {
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

  function mergeCdekSplitRows(form) {
    if (!form) return;
    shipmentItemRows(form).filter((row) => row.hasAttribute('data-wdc-split-row')).forEach((row) => {
      const groupKey = row.getAttribute('data-group-key') || '';
      row.remove();
      rebalanceCdekGroup(form, groupKey);
    });
    form.querySelectorAll('[data-wdc-shipment-place-select], [data-wdc-cdek-place-select]').forEach((select) => {
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
        rebalanceCdekGroup(form, groupKey);
        return;
      }
      select.value = '1';
    });
    affectedGroups.forEach((groupKey) => {
      const base = shipmentItemRows(form).find((row) => row.hasAttribute('data-wdc-base-row') && row.getAttribute('data-group-key') === groupKey);
      if (base) restoreOriginalBaseRow(base, false);
      rebalanceCdekGroup(form, groupKey);
    });
  }

  function updateCdekSplitAvailability(form, placeCount) {
    if (!form) return;
    if (placeCount <= 1) mergeCdekSplitRows(form);
    form.querySelectorAll('[data-wdc-shipment-item-split], [data-wdc-cdek-split]').forEach((button) => {
      const row = button.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]');
      const qty = parseInt(row && row.getAttribute('data-ordered-quantity') || '1', 10) || 1;
      button.hidden = placeCount <= 1 || qty <= 1;
      button.disabled = button.hidden;
    });
  }

  function splitCdekItemRow(button) {
    const row = button && button.closest ? button.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]') : null;
    const form = findShipmentForm(button);
    if (!row || !form) return;
    const rowQtyInput = shipmentQtyInput(row);
    const currentQty = parseInt(rowQtyInput && rowQtyInput.value ? rowQtyInput.value : '0', 10) || 0;
    if (currentQty <= 1) return;
    const clone = row.cloneNode(true);
    const groupKey = row.getAttribute('data-group-key') || row.getAttribute('data-item-key') || '';
    const index = nextCdekItemIndex(form);
    rewriteCdekItemNames(clone, index);
    clone.removeAttribute('data-wdc-base-row');
    clone.setAttribute('data-wdc-split-row', '1');
    clone.setAttribute('data-group-key', groupKey);
    clone.querySelectorAll('[data-wdc-shipment-item-split], [data-wdc-cdek-split]').forEach((splitButton) => {
      splitButton.removeAttribute('data-wdc-shipment-item-split');
      splitButton.removeAttribute('data-wdc-cdek-split');
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
    parent.name = 'cdek_items[' + index + '][split_parent]';
    parent.value = groupKey;
    clone.appendChild(parent);
    const actionCell = clone.querySelector('[data-wdc-shipment-item-actions], .wdc-cdek-item-actions') || clone.querySelector('td:last-child');
    if (actionCell) {
      actionCell.innerHTML = '<button type="button" class="wdc-icon-action wdc-icon-action--danger" data-wdc-remove-shipment-split data-wdc-remove-cdek-split title="Удалить строку" aria-label="Удалить строку">❌</button>';
    }
    row.after(clone);
    rebalanceCdekGroup(form, groupKey);
    updateCdekPlaceOptions(form);
    schedulePreview(form);
  }

  function addManualCdekItemRow(button) {
    const form = findShipmentForm(button);
    const table = form && form.querySelector('[data-wdc-shipment-items-table], [data-wdc-cdek-items-table]');
    const body = table && table.querySelector('tbody');
    if (!form || !body) return;
    const index = nextCdekItemIndex(form);
    const rowKey = 'manual-' + index;
    const row = document.createElement('tr');
    row.setAttribute('data-wdc-shipment-item-row', '1');
    row.setAttribute('data-wdc-cdek-item-row', '1');
    row.setAttribute('data-wdc-manual-row', '1');
    row.setAttribute('data-item-key', rowKey);
    row.setAttribute('data-group-key', rowKey);
    row.setAttribute('data-ordered-quantity', '999');
    row.setAttribute('data-wdc-row-index', String(index));
    row.innerHTML = [
      '<td class="wdc-cdek-item-product"><input type="text" name="cdek_items[' + index + '][name]" value="" placeholder="Товар"><input type="hidden" name="cdek_items[' + index + '][item_key]" value="' + rowKey + '"><input type="hidden" name="cdek_items[' + index + '][ordered_quantity]" value="999"></td>',
      '<td class="wdc-cdek-item-sku wdc-product-search-cell"><input type="text" name="cdek_items[' + index + '][ware_key]" value="" placeholder="Артикул" autocomplete="off" data-wdc-product-search-input><div class="wdc-product-search-results" data-wdc-product-search-results hidden></div></td>',
      '<td><input class="wdc-cdek-input-qty" type="number" min="1" max="999" step="1" name="cdek_items[' + index + '][amount]" value="1" data-wdc-shipment-item-qty data-wdc-cdek-qty data-wdc-integer-input></td>',
      '<td><input class="wdc-cdek-input-price" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[' + index + '][cost]" value="0" data-wdc-decimal-input="2"></td>',
      '<td><input class="wdc-cdek-input-weight" type="number" min="1" step="1" name="cdek_items[' + index + '][weight]" value="1" data-wdc-integer-input></td>',
      '<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[' + index + '][length_cm]" value="1" data-wdc-decimal-input="1"></td>',
      '<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[' + index + '][width_cm]" value="1" data-wdc-decimal-input="1"></td>',
      '<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[' + index + '][height_cm]" value="1" data-wdc-decimal-input="1"></td>',
      '<td><select name="cdek_items[' + index + '][place_number]" data-wdc-shipment-place-select data-wdc-cdek-place-select><option value="1">1</option></select></td>',
      '<td class="wdc-cdek-item-actions" data-wdc-shipment-item-actions><button type="button" class="wdc-icon-action wdc-icon-action--danger" data-wdc-remove-manual-shipment-item data-wdc-remove-manual-cdek-item title="Удалить строку" aria-label="Удалить строку">❌</button></td>'
    ].join('');
    body.appendChild(row);
    updateCdekPlaceOptions(form);
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
    const row = input.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]');
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
    fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then((response) => response.json())
      .then((payload) => {
        const items = payload && payload.success && payload.data && Array.isArray(payload.data.items) ? payload.data.items : [];
        renderProductSearchResults(input, items);
      })
      .catch(() => renderProductSearchResults(input, []));
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
          const previewWarnings = payload.data.preview && Array.isArray(payload.data.preview.warnings)
            ? payload.data.preview.warnings
            : [];
          errors.textContent = previewErrors.length ? previewErrors.join('; ') : previewWarnings.join('; ');
          if (previewErrors.length) {
            delete errors.dataset.previewWarning;
          } else if (previewWarnings.length) {
            errors.dataset.previewWarning = '1';
          } else {
            delete errors.dataset.previewWarning;
          }
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

  function pickupContext(form, override) {
    return Object.assign({
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
    }, override || {});
  }

  function selectedDeliveryMode(form) {
    const tariff = form.querySelector('[data-wdc-tariff-select]');
    const option = tariff && tariff.options[tariff.selectedIndex] ? tariff.options[tariff.selectedIndex] : null;
    const fromOption = option ? Number(option.dataset.deliveryMode || 0) : 0;
    if (fromOption) return fromOption;
    const item = selectedTariff(form);
    return item ? Number(item.delivery_mode || 0) : 0;
  }

  function updateCdekDeliveryModeUi(form) {
    const mode = selectedDeliveryMode(form);
    const commentRow = form.querySelector('[data-wdc-cdek-courier-comment-row]');
    if (commentRow) commentRow.hidden = ![1, 3].includes(mode);
    const senderDoor = form.querySelector('[data-wdc-cdek-sender-door]');
    const senderWarehouse = form.querySelector('[data-wdc-cdek-sender-warehouse]');
    if (senderDoor) senderDoor.hidden = ![1, 2].includes(mode);
    if (senderWarehouse) senderWarehouse.hidden = [1, 2].includes(mode);
  }

  function pickupUsesCodeDisplay(form) {
    return pickupContext(form).pickupFamily === 'cdek:pickup';
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
    return String(point && (point.point_title || point.card_title || point.point_type_label || point.display_title) || '').trim() || 'Отделение Почты России';
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
      canUpdate: !!status.can_update_status,
      canPrintBarcode: !!status.can_print_barcode
    });
    setTrackingDisplay(box, status.barcode || '');
    renderShipmentPrice(box, status);
  }

  function shipmentStatusFromResponse(data) {
    const payload = data || {};
    const status = Object.assign({}, payload.status || {});
    ['carrier_key', 'presentation', 'label_actions', 'has_shipment', 'can_update_status', 'can_cancel', 'can_remove_from_order'].forEach(function (key) {
      if (Object.prototype.hasOwnProperty.call(payload, key) && !Object.prototype.hasOwnProperty.call(status, key)) {
        status[key] = payload[key];
      }
    });
    if (Array.isArray(payload.label_actions) && !Object.prototype.hasOwnProperty.call(status, 'can_print_barcode')) {
      status.can_print_barcode = payload.label_actions.some(function (action) {
        return action && action.key === 'download_label' && !!action.visible;
      });
    }
    return status;
  }

  function setCdekPollingIndicator(box, visible) {
    const indicator = box && box.querySelector ? box.querySelector('[data-wdc-cdek-polling-indicator]') : null;
    if (!indicator) return;
    indicator.hidden = !visible;
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
      pollingTimeoutMessage: 'Автоматическая проверка завершена. Если статус еще не обновился, воспользуйтесь кнопкой «Обновить статус».',
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
    const canPrintBarcode = !!(state && state.canPrintBarcode);
    const openButton = box.querySelector('[data-wdc-open-shipment-modal]');
    const updateButton = box.querySelector('[data-wdc-update-shipment-status]');
    const manualButton = box.querySelector('[data-wdc-open-manual-tracking]');
    const cancelButton = box.querySelector('[data-wdc-cancel-shipment]');
    const removeButton = box.querySelector('[data-wdc-remove-shipment-from-order]');
    const barcodeDownload = box.querySelector('[data-wdc-cdek-barcode-download]');
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
    if (barcodeDownload) {
      setVisible(barcodeDownload, canPrintBarcode);
      if (canPrintBarcode) {
        barcodeDownload.removeAttribute('aria-disabled');
      } else {
        barcodeDownload.setAttribute('aria-disabled', 'true');
      }
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
    const message = box.querySelector('[data-wdc-shipment-status-message]');
    if (message) {
      message.textContent = '';
      message.dataset.status = '';
    }
    setCdekPollingIndicator(box, false);
    updateShipmentButtons(box, { hasShipment: false, canCancel: false, canRemove: false, canUpdate: false, canPrintBarcode: false });
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
        renderShipmentStatus(box, shipmentStatusFromResponse(payload.data));
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
    const maxAttempts = 14;
    const interval = 5000;
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    setCdekPollingIndicator(box, true);
    const tick = function () {
      attempts += 1;
      requestShipmentStatus(button, { auto: true })
        .then((payload) => {
          const data = payload && payload.data ? payload.data : {};
          const status = data.status || {};
          const state = String(status.carrier_operation_index || '').toUpperCase();
          const code = String(status.carrier_operation_address || '').toUpperCase();
          if (state === 'INVALID') {
            setCdekPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).registrationErrorToast, 'error', { append: true });
            return;
          }
          if (code === 'CREATED' || data.terminal) {
            setCdekPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).registrationSuccessToast, 'success', { append: true });
            return;
          }
          if (attempts >= maxAttempts) {
            setCdekPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).pollingTimeoutMessage, 'warning', { append: true });
            return;
          }
          window.setTimeout(tick, interval);
        })
        .catch(() => {
          if (attempts >= maxAttempts) {
            setCdekPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).pollingTimeoutMessage, 'warning', { append: true });
            return;
          }
          window.setTimeout(tick, interval);
        });
    };
    window.setTimeout(tick, interval);
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
        const statusPayload = shipmentStatusFromResponse(payload.data);
        renderShipmentStatus(box, statusPayload);
        renderShipmentTechnicalInfo(box, payload.data || {});
        setTrackingDisplay(box, payload.data.tracking_number || payload.data.status && payload.data.status.barcode || '');
        updateShipmentButtons(box, {
          hasShipment: !!statusPayload.has_shipment,
          canCancel: !!statusPayload.can_cancel,
          canRemove: !!statusPayload.can_remove_from_order,
          canUpdate: !!statusPayload.can_update_status,
          canPrintBarcode: !!statusPayload.can_print_barcode
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

  function currentPickupQuery(form, contextOverride) {
    const context = pickupContext(form, contextOverride);
    if (context.pickupFamily === 'cdek:pickup') {
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
    const warning = form.querySelector('[data-wdc-pickup-warning]');
    if (warning) warning.remove();
    updateCreateAvailability(form);
    requestPreview(form);
  }

  function updateSenderPickupDraft(form, point) {
    const code = pickupCode(point);
    const address = String(point && point.address || '');
    form.querySelectorAll('[name="shipment_point"], [name="sender_shipment_point"], [data-wdc-sender-shipment-point]').forEach((input) => {
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
      carrierKey: 'cdek',
      serviceKey: 'cdek',
      pickupFamily: 'cdek:pickup',
      city: fieldValue(form, '[data-wdc-sender-pickup-city]') || 'Новосибирск',
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
    const pickerTitle = settings.title || (codeDisplay ? 'Выбор ПВЗ СДЭК' : 'Выбор ПВЗ / ОПС');
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
            renderSearchResults(searchMarker ? 'Адрес найден.' : 'Адрес не найден.');
          })
          .catch((error) => {
            if (error.name === 'AbortError') return;
            searchMarker = null;
            renderSearchResults(error.message || 'Адрес не найден.');
          });
        return;
      }
      searchMarker = null;
      status.textContent = 'Поиск...';
      pickupSearchRequest(form, value, mode === 'location' ? 2000 : 100, controller.signal, mode, context)
        .then((found) => {
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

    query.value = currentPickupQuery(form, context);
    query.focus();
    if (query.value || context.city || context.postcode || context.address || context.locationId || context.fiasId || context.garId) runSearch('location');
  }

  const CDEK_BARCODE_POLL_INTERVAL_MS = 2000;
  const CDEK_BARCODE_TIMEOUT_MS = 300000;
  const CDEK_BARCODE_RESET_MS = 1500;

  function setCdekBarcodeButtonState(link, busy, label) {
    if (!link) return;
    const originalText = link.getAttribute('data-wdc-original-label') || link.textContent || 'Скачать этикетку';
    link.setAttribute('data-wdc-original-label', originalText);
    if (busy) {
      link.setAttribute('aria-disabled', 'true');
      link.classList.add('is-busy', 'wdc-cdek-barcode-download--busy');
      link.textContent = label || 'Формируем этикетку...';
    } else {
      link.classList.remove('is-busy', 'wdc-cdek-barcode-download--busy');
      link.removeAttribute('aria-disabled');
      link.textContent = originalText;
    }
  }

  function cdekBarcodeFilenameFromDisposition(disposition) {
    const fallback = 'cdek-barcode.pdf';
    if (!disposition) return fallback;
    const utfMatch = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
    if (utfMatch && utfMatch[1]) {
      try {
        return decodeURIComponent(utfMatch[1].replace(/["']/g, '')) || fallback;
      } catch (error) {
        return fallback;
      }
    }
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    return match && match[1] ? match[1] : fallback;
  }

  function triggerCdekBarcodeDownload(downloadUrl) {
    downloadUrl = String(downloadUrl || '').replace(/&amp;/g, '&');
    if (!downloadUrl) return Promise.reject(new Error('Не удалось скачать этикетку СДЭК.'));
    return fetch(downloadUrl, {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then((response) => {
        if (!response.ok) {
          return response.text().catch(function () { return ''; }).then(function () {
            throw new Error('Не удалось скачать этикетку СДЭК.');
          });
        }
        const contentType = response.headers.get('Content-Type') || response.headers.get('content-type') || '';
        if (contentType && contentType.toLowerCase().indexOf('application/pdf') === -1) {
          throw new Error('Сервер вернул не PDF-файл этикетки СДЭК.');
        }
        const filename = cdekBarcodeFilenameFromDisposition(response.headers.get('Content-Disposition') || response.headers.get('content-disposition') || '');
        return response.blob().then((blob) => ({ blob, filename }));
      })
      .then((download) => {
        if (!download.blob || download.blob.size <= 0) {
          throw new Error('Не удалось скачать этикетку СДЭК.');
        }
        const objectUrl = URL.createObjectURL(download.blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = download.filename || 'cdek-barcode.pdf';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(function () {
          URL.revokeObjectURL(objectUrl);
        }, 30000);
      });
  }

  function requestCdekBarcodeDownload(link) {
    if (!link || link.classList.contains('is-busy')) return;
    const box = link.closest('[data-wdc-shipments-metabox]');
    const startedAt = new Date().getTime();

    const poll = function () {
      if (new Date().getTime() - startedAt > CDEK_BARCODE_TIMEOUT_MS) {
        setCdekBarcodeButtonState(link, false);
        showShipmentToast(box, 'Этикетка СДЭК еще формируется. Повторите попытку позже.', 'warning');
        return;
      }

      const data = new FormData();
      data.append('action', link.dataset.prepareAction || (window.wdcShipmentsAdmin && window.wdcShipmentsAdmin.cdekBarcodePrepareAction) || 'wdc_cdek_barcode_prepare');
      data.append('nonce', (window.wdcShipmentsAdmin && window.wdcShipmentsAdmin.nonce) || '');
      data.append('order_id', link.dataset.orderId || '');
      fetch((window.wdcShipmentsAdmin && window.wdcShipmentsAdmin.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''), {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload || !payload.success) {
            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'СДЭК не смог сформировать этикетку.');
          }
          const result = payload.data || {};
          const status = String(result.status || '').toUpperCase();
          if (status === 'READY') {
            setCdekBarcodeButtonState(link, true, 'Скачиваем этикетку...');
            triggerCdekBarcodeDownload(result.download_url || link.dataset.downloadUrl || link.href || '')
              .then(function () {
                window.clearTimeout(link._wdcBarcodeResetTimer);
                link._wdcBarcodeResetTimer = window.setTimeout(function () {
                  setCdekBarcodeButtonState(link, false);
                }, CDEK_BARCODE_RESET_MS);
              })
              .catch(function (error) {
                setCdekBarcodeButtonState(link, false);
                showShipmentToast(box, error && error.message ? error.message : 'Не удалось скачать этикетку СДЭК.', 'error');
              });
            return;
          }
          if (status === 'ACCEPTED' || status === 'PROCESSING') {
            window.setTimeout(poll, CDEK_BARCODE_POLL_INTERVAL_MS);
            return;
          }

          throw new Error(result.message || 'СДЭК не смог сформировать этикетку.');
        })
        .catch((error) => {
          setCdekBarcodeButtonState(link, false);
          showShipmentToast(box, error && error.message ? error.message : 'СДЭК не смог сформировать этикетку.', 'error');
        });
    };

    setCdekBarcodeButtonState(link, true, 'Формируем этикетку...');
    poll();
  }

  document.addEventListener('click', function (event) {
    const cdekBarcodeDownload = event.target.closest('[data-wdc-cdek-barcode-download]');
    if (cdekBarcodeDownload) {
      event.preventDefault();
      requestCdekBarcodeDownload(cdekBarcodeDownload);
      return;
    }

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
      const removedNumber = row ? Array.from(container.querySelectorAll('[data-wdc-place]')).indexOf(row) + 1 : 0;
      const form = findShipmentForm(remove) || findShipmentForm(container);
      if (form && removedNumber > 0) mergeRowsFromRemovedPlace(form, removedNumber);
      if (row) row.remove();
      renumberPlaces(container);
      updateRemoveButtons(container);
      if (form) updateCdekPlaceOptions(form);
      if (form) requestPreview(form);
      return;
    }

    const tab = event.target.closest('[data-wdc-shipment-tab]');
    if (tab) {
      switchShipmentTab(findShipmentForm(tab), tab.getAttribute('data-wdc-shipment-tab') || 'main');
      return;
    }

    const split = event.target.closest('[data-wdc-shipment-item-split], [data-wdc-cdek-split]');
    if (split) {
      splitCdekItemRow(split);
      return;
    }

    const removeSplit = event.target.closest('[data-wdc-remove-shipment-split], [data-wdc-remove-cdek-split]');
    if (removeSplit) {
      const row = removeSplit.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]');
      const form = findShipmentForm(removeSplit);
      const groupKey = row && row.getAttribute('data-group-key');
      if (row) row.remove();
      if (form) rebalanceCdekGroup(form, groupKey);
      if (form) updateCdekPlaceOptions(form);
      if (form) schedulePreview(form);
      return;
    }

    const addManualItem = event.target.closest('[data-wdc-add-manual-shipment-item], [data-wdc-add-manual-cdek-item]');
    if (addManualItem) {
      addManualCdekItemRow(addManualItem);
      return;
    }

    const removeManualItem = event.target.closest('[data-wdc-remove-manual-shipment-item], [data-wdc-remove-manual-cdek-item]');
    if (removeManualItem) {
      const row = removeManualItem.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]');
      const form = findShipmentForm(removeManualItem);
      if (row) row.remove();
      if (form) updateCdekPlaceOptions(form);
      if (form) schedulePreview(form);
      return;
    }

    const productChoice = event.target.closest('[data-wdc-product-search-choice]');
    if (productChoice) {
      const row = productChoice.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]');
      const form = findShipmentForm(productChoice);
      let product = null;
      try {
        product = JSON.parse(productChoice.getAttribute('data-product') || '{}');
      } catch (error) {
        product = null;
      }
      applyProductToManualRow(row, product);
      const results = row && row.querySelector('[data-wdc-product-search-results]');
      if (results) results.hidden = true;
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

    const openSenderPickupPicker = event.target.closest('[data-wdc-open-sender-pickup-picker]');
    if (openSenderPickupPicker) {
      const form = findShipmentForm(openSenderPickupPicker);
      if (form) {
        const context = senderPickupContext(form);
        createPickupPicker(form, {
          sender: true,
          title: 'Выбор ПВЗ отправителя СДЭК',
          context: context,
          onChoose: function (point) {
            updateSenderPickupDraft(form, point);
          }
        });
      }
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
          const cityCode = snapshot && snapshot.fields ? String(snapshot.fields.cdek_city_code || '') : '';
          const cityCodeRow = form.querySelector('[data-wdc-cdek-city-code-row]');
          const cityCodeValue = form.querySelector('[data-wdc-cdek-city-code]');
          if (cityCodeValue) cityCodeValue.textContent = cityCode;
          if (cityCodeRow) cityCodeRow.hidden = !cityCode;
          if (status) {
            status.textContent = snapshot.success
              ? (cityCode ? '✅ Данные для СДЭК корректны' : 'Адрес обработан.')
              : (snapshot.message || 'Адрес не подтвержден, создание отправления заблокировано.');
          }
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

    const previewShipment = event.target.closest('[data-wdc-preview-shipment]');
    if (previewShipment) {
      const form = findShipmentForm(previewShipment);
      if (form) requestPreview(form);
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
          const statusPayload = shipmentStatusFromResponse(payload.data);
          if (box && statusPayload) {
            renderShipmentStatus(box, statusPayload);
          }
          renderShipmentTechnicalInfo(box, payload.data || {});
          const openButton = box && box.querySelector('[data-wdc-open-shipment-modal]');
          if (openButton) openButton.disabled = true;
          const updateButton = box && box.querySelector('[data-wdc-update-shipment-status]');
          if (updateButton) {
            updateButton.disabled = false;
          }
          const text = getPresentation(box);
          updateShipmentButtons(box, {
            hasShipment: !!statusPayload.has_shipment,
            canCancel: !!statusPayload.can_cancel,
            canRemove: !!statusPayload.can_remove_from_order,
            canUpdate: !!statusPayload.can_update_status,
            canPrintBarcode: !!statusPayload.can_print_barcode
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
      const row = event.target.closest('[data-wdc-shipment-item-row], [data-wdc-cdek-item-row]');
      if (integerForm && row && event.target.matches('[data-wdc-shipment-item-qty], [data-wdc-cdek-qty]')) {
        rebalanceCdekGroup(integerForm, row.getAttribute('data-group-key') || '', row);
      }
      if (integerForm) {
        updateScenarioSections(integerForm);
        updateCdekPlaceOptions(integerForm);
        schedulePreview(integerForm);
      }
      return;
    }
    if (event.target.matches('[data-wdc-decimal-input]')) {
      cleanDecimalInput(event.target, parseInt(event.target.getAttribute('data-wdc-decimal-input') || '2', 10) || 2);
      const decimalForm = findShipmentForm(event.target);
      if (decimalForm) {
        updateCdekPlaceOptions(decimalForm);
        schedulePreview(decimalForm);
      }
      return;
    }
    if (event.target.matches('[data-wdc-product-search-input]')) {
      window.clearTimeout(event.target._wdcProductSearchTimer);
      event.target._wdcProductSearchTimer = window.setTimeout(function () {
        searchProductsForManualItem(event.target);
      }, 250);
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

  document.addEventListener('focusout', function (event) {
    if (!event.target.matches('[data-wdc-product-search-input]')) return;
    window.clearTimeout(event.target._wdcProductSearchTimer);
    window.clearTimeout(event.target._wdcProductSearchBlurTimer);
    event.target._wdcProductSearchBlurTimer = window.setTimeout(function () {
      renderProductSearchResults(event.target, []);
    }, 160);
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
