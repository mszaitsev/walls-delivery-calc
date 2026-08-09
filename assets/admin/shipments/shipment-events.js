function initializeShipmentAdmin() {
  document.addEventListener('click', function (event) {
    if (dispatchShipmentCarrierHook('handleClick', event)) return;

    const dateStep = event.target.closest('[data-wdc-date-step]');
    if (dateStep) {
      event.preventDefault();
      stepDateInput(dateStep);
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
      if (form) updateShipmentPlaceOptions(form);
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
      if (form) updateShipmentPlaceOptions(form);
      if (form) requestPreview(form);
      return;
    }

    const tab = event.target.closest('[data-wdc-shipment-tab]');
    if (tab) {
      switchShipmentTab(findShipmentForm(tab), tab.getAttribute('data-wdc-shipment-tab') || 'main');
      return;
    }

    const split = event.target.closest('[data-wdc-shipment-item-split]');
    if (split) {
      splitShipmentItemRow(split);
      return;
    }

    const removeSplit = event.target.closest('[data-wdc-remove-shipment-split]');
    if (removeSplit) {
      const row = removeSplit.closest('[data-wdc-shipment-item-row]');
      const form = findShipmentForm(removeSplit);
      const groupKey = row && row.getAttribute('data-group-key');
      if (row) row.remove();
      if (form) rebalanceShipmentItemGroup(form, groupKey);
      if (form) updateShipmentPlaceOptions(form);
      if (form) schedulePreview(form);
      return;
    }

    const addManualItem = event.target.closest('[data-wdc-add-manual-shipment-item]');
    if (addManualItem) {
      addManualShipmentItemRow(addManualItem);
      return;
    }

    const removeManualItem = event.target.closest('[data-wdc-remove-manual-shipment-item]');
    if (removeManualItem) {
      const row = removeManualItem.closest('[data-wdc-shipment-item-row]');
      const form = findShipmentForm(removeManualItem);
      if (row) row.remove();
      if (form) updateShipmentPlaceOptions(form);
      if (form) schedulePreview(form);
      return;
    }

    const productChoice = event.target.closest('[data-wdc-product-search-choice]');
    if (productChoice) {
      const row = productChoice.closest('[data-wdc-shipment-item-row]');
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
      if (form) updateShipmentPlaceOptions(form);
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
      dispatchShipmentCarrierHook('handleSenderPickupClick', event, openSenderPickupPicker);
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
        .then(parseShipmentJsonResponse)
        .then((payload) => {
          if (!payload || !payload.success) {
            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось обработать адрес.');
          }
          const snapshot = payload.data.normalized_address || {};
          if (snapshotInput) snapshotInput.value = JSON.stringify(snapshot);
          if (display) display.value = snapshot.display || '';
          const handled = dispatchShipmentCarrierHook('afterAddressNormalized', {
            form: form,
            snapshot: snapshot,
            status: status,
            display: display
          });
          if (!handled && status) {
            status.textContent = snapshot.success
              ? 'Адрес обработан.'
              : (snapshot.message || 'Адрес не подтвержден.');
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

    const saveActualCost = event.target.closest('[data-wdc-save-actual-cost]');
    if (saveActualCost) {
      requestShipmentActualCost(saveActualCost, 'save').catch(function () {});
      return;
    }

    const clearActualCost = event.target.closest('[data-wdc-clear-actual-cost]');
    if (clearActualCost) {
      requestShipmentActualCost(clearActualCost, 'clear').catch(function () {});
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
        .then(parseShipmentJsonResponse)
        .then((payload) => {
          if (!payload || !payload.success) {
            const controlled = new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось создать отправление.');
            controlled.payload = payload;
            throw controlled;
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
          updateShipmentButtons(box, shipmentButtonStateFromStatus(statusPayload));
          showShipmentToast(box, payload.data.message || text.createdToast, 'success');
          if (handleShipmentLifecycleResult({
            form: form,
            payload: payload,
            lifecycle: payload.data ? payload.data.lifecycle : null,
            box: box,
            updateButton: updateButton,
            statusPayload: statusPayload,
            presentation: text
          })) {
            return;
          }
          if (updateButton && !updateButton.disabled) {
            if (text.autoPollRegistration === '1') {
              startDefaultRegistrationPolling(updateButton);
            } else {
              requestShipmentStatus(updateButton, { auto: true });
            }
          }
        })
        .catch((error) => {
          const payload = error && error.payload && error.payload.data ? error.payload.data : null;
          const preview = form.querySelector('[data-wdc-shipment-preview]');
          if (preview && payload && payload.diagnostic) {
            preview.textContent = JSON.stringify(Object.assign({}, payload.preview || {}, { create_diagnostic: payload.diagnostic }), null, 2);
          }
          if (errors) errors.textContent = error.message;
          showShipmentToast(findShipmentForm(create), error.message, 'error');
  });
}

function requestShipmentActualCost(button, operation) {
  const box = button.closest('[data-wdc-shipments-metabox]');
  const control = button.closest('[data-wdc-shipment-actual-cost]');
  const input = control && control.querySelector('[data-wdc-actual-cost-input]');
  const data = new FormData();
  data.append('action', operation === 'clear' ? window.wdcShipmentsAdmin.clearActualCostAction : window.wdcShipmentsAdmin.saveActualCostAction);
  data.append('nonce', window.wdcShipmentsAdmin.nonce);
  data.append('order_id', control && control.dataset ? String(control.dataset.orderId || '') : '');
  data.append('shipment_key', control && control.dataset ? String(control.dataset.shipmentKey || '') : '');
  if (operation !== 'clear') {
    data.append('actual_cost', input ? input.value : '');
  }
  button.disabled = true;
  return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    body: data
  })
    .then(parseShipmentJsonResponse)
    .then((payload) => {
      if (!payload || !payload.success) {
        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось сохранить фактическую стоимость.');
      }
      const status = shipmentStatusFromResponse(payload.data || {});
      renderShipmentStatus(box, status);
      if (input && operation !== 'clear') input.value = '';
      showShipmentToast(box, payload.data && payload.data.message ? payload.data.message : 'Фактическая стоимость обновлена.', 'success');
    })
    .catch((error) => {
      showShipmentToast(box, error.message || 'Не удалось сохранить фактическую стоимость.', 'error');
    })
    .finally(() => {
      button.disabled = false;
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
        dispatchShipmentCarrierHook('afterAddressReset', {
          form: form,
          status: status,
          display: display
        });
        if (status) status.textContent = 'Адрес изменен, нужно обработать адрес заново.';
        updateCreateAvailability(form);
        schedulePreview(form);
      }
      return;
    }
    if (dispatchShipmentCarrierHook('handleInput', event)) return;
    if (event.target.matches('[data-wdc-integer-input]')) {
      cleanIntegerInput(event.target);
      const integerForm = findShipmentForm(event.target);
      const row = event.target.closest('[data-wdc-shipment-item-row]');
      if (integerForm && row && event.target.matches('[data-wdc-shipment-item-qty]')) {
        rebalanceShipmentItemGroup(integerForm, row.getAttribute('data-group-key') || '', row);
      }
      if (integerForm) {
        updateScenarioSections(integerForm);
        updateShipmentPlaceOptions(integerForm);
        schedulePreview(integerForm);
      }
      return;
    }
    if (event.target.matches('[data-wdc-decimal-input]')) {
      cleanDecimalInput(event.target, parseInt(event.target.getAttribute('data-wdc-decimal-input') || '2', 10) || 2);
      const decimalForm = findShipmentForm(event.target);
      if (decimalForm) {
        updateShipmentPlaceOptions(decimalForm);
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
      updateShipmentPlaceOptions(form);
      schedulePreview(form);
    }
  });

  document.addEventListener('pointerdown', function (event) {
    dispatchShipmentCarrierHook('handlePointerDown', event);
  });

  document.addEventListener('focus', function (event) {
    dispatchShipmentCarrierHook('handleFocus', event);
  }, true);

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
    if (event.target.matches('[data-wdc-shipment-place-select]')) {
      const allocationForm = findShipmentForm(event.target);
      if (allocationForm) {
        refreshShipmentItemsSummary(allocationForm);
        schedulePreview(allocationForm);
      }
      return;
    }
    if (dispatchShipmentCarrierHook('handleChange', event)) return;
    const form = findShipmentForm(event.target);
    if (!form) return;
    if (event.target.matches('[data-wdc-service-select]')) {
      updateTariffOptions(form);
    }
    if (event.target.matches('[data-wdc-tariff-select]')) {
      updateDeclaredValueFields(form);
    }
    updateScenarioSections(form);
    updateShipmentPlaceOptions(form);
    schedulePreview(form);
  });

  const forms = new Set(document.querySelectorAll(formSelector));
  document.querySelectorAll('[data-wdc-shipments-metabox]').forEach((box) => {
    const form = findShipmentForm(box);
    if (form) forms.add(form);
  });
  forms.forEach((form) => {
    initializeForm(form, false);
    updateShipmentPlaceOptions(form);
  });

}

