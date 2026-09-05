  function operationSummary(status) {
    return [
      status && status.carrier_operation_date,
      status && (status.carrier_operation_code || status.carrier_operation_address),
      status && (status.carrier_operation_marker || status.carrier_operation_index)
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
      '[data-wdc-status-updated]': status.updated_at || '',
      '[data-wdc-planned-delivery-date]': status.planned_delivery_date || ''
    };
    Object.keys(fields).forEach((selector) => {
      const element = box.querySelector(selector);
      if (element) element.textContent = fields[selector];
    });
    const plannedRow = box.querySelector('[data-wdc-planned-delivery-row]');
    if (plannedRow) plannedRow.hidden = !String(status.planned_delivery_date || '').trim();
    const updatedRow = box.querySelector('[data-wdc-status-updated-row]');
    if (updatedRow) updatedRow.hidden = !String(status.updated_at || '').trim();
    updateShipmentButtons(box, shipmentButtonStateFromStatus(status));
    setTrackingDisplay(box, trackingPresentation(status));
    setReturnTrackingDisplay(box, trackingPresentation(status, 'return_tracking_presentation'));
    renderShipmentPrice(box, status);
    renderActualCostControl(box, status);
    dispatchShipmentCarrierHook('renderStatus', { box: box, status: status });
  }

  function shipmentStatusFromResponse(data) {
    const payload = data || {};
    const status = Object.assign({}, payload.status || {});
    ['carrier_key', 'presentation', 'document_actions', 'has_shipment', 'can_create', 'can_attach_manual', 'can_update_status', 'can_cancel', 'can_remove_from_order'].forEach(function (key) {
      if (Object.prototype.hasOwnProperty.call(payload, key) && !Object.prototype.hasOwnProperty.call(status, key)) {
        status[key] = payload[key];
      }
    });
    return status;
  }

  function setShipmentPollingIndicator(box, visible) {
    const indicator = box && box.querySelector ? box.querySelector('[data-wdc-shipment-polling-indicator]') : null;
    if (!indicator) return;
    indicator.hidden = !visible;
    indicator.classList.toggle('is-active', !!visible);
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

  function renderActualCostControl(box, status) {
    const control = box && box.querySelector ? box.querySelector('[data-wdc-shipment-actual-cost-control]') : null;
    if (!control) return;
    const inputWrap = control.querySelector('[data-wdc-actual-cost-input-wrap]');
    const input = control.querySelector('[data-wdc-actual-cost-input]');
    const save = control.querySelector('[data-wdc-save-actual-cost]');
    const clear = control.querySelector('[data-wdc-clear-actual-cost]');
    const hasShipment = Boolean(status && status.has_shipment);
    const hasActualCost = Object.prototype.hasOwnProperty.call(status || {}, 'has_actual_cost')
      ? Boolean(status.has_actual_cost)
      : (Number(status && status.actual_cost_kopecks || 0) > 0 || String(status && status.actual_cost_label || '').trim() !== '');
    setVisible(control, hasShipment);
    setVisible(inputWrap, hasShipment && !hasActualCost);
    setVisible(save, hasShipment && !hasActualCost);
    setVisible(clear, hasShipment && hasActualCost);
    if (input && hasActualCost) {
      input.value = '';
    }
  }
  function renderShipmentTechnicalInfo(box, data) {
    if (!box || !data) return;
    const backlogOrderId = String(data.backlog_order_id || '').trim();
    const value = box.querySelector('[data-wdc-backlog-order-id]');
    if (value) value.textContent = backlogOrderId;
  }

  function trackingPresentation(status, key) {
    const payloadKey = key || 'tracking_presentation';
    const raw = status && status[payloadKey] && typeof status[payloadKey] === 'object'
      ? status[payloadKey]
      : null;
    if (!raw && payloadKey !== 'tracking_presentation') {
      return { label: '', displayText: '', copyValue: '', url: '', items: [] };
    }
    if (!raw) {
      const value = String(status && status.barcode || '').trim();
      return { displayText: value, copyValue: value, url: '' };
    }
    const url = safeTrackingUrl(raw.url || '');
    const displayText = String(raw.display_text || raw.displayText || (url ? 'ссылка' : '')).trim();
    const items = Array.isArray(raw.items)
      ? raw.items.map(function (item) {
        const itemUrl = safeTrackingUrl(item && item.url || '');
        const itemDisplayText = String(item && (item.display_text || item.displayText) || (itemUrl ? 'ссылка' : '')).trim();
        return {
          label: String(item && item.label || '').trim(),
          displayText: itemDisplayText,
          copyValue: String(item && (item.copy_value || item.copyValue) || itemUrl || itemDisplayText).trim(),
          url: itemUrl
        };
      }).filter(function (item) { return item.displayText || item.copyValue; })
      : [];
    return {
      label: String(raw.label || '').trim(),
      displayText: displayText,
      copyValue: String(raw.copy_value || raw.copyValue || url || displayText).trim(),
      url: url,
      items: items
    };
  }

  function setTrackingDisplay(box, trackingNumber) {
    if (!box) return;
    const tracking = trackingNumber && typeof trackingNumber === 'object'
      ? trackingNumber
      : { displayText: String(trackingNumber || '').trim(), copyValue: String(trackingNumber || '').trim(), url: '' };
    const value = String(tracking.displayText || tracking.display_text || tracking.copyValue || tracking.copy_value || '').trim();
    const copyValue = String(tracking.copyValue || tracking.copy_value || value || '').trim();
    const url = safeTrackingUrl(tracking.url || '');
    const items = Array.isArray(tracking.items) ? tracking.items : [];
    const row = box.querySelector('[data-wdc-tracking-row]');
    const label = box.querySelector('[data-wdc-tracking-label]');
    const number = box.querySelector('[data-wdc-tracking-number]');
    const copy = box.querySelector('[data-wdc-copy-tracking]');
    if (label && tracking.label) label.textContent = String(tracking.label);
    if (number) {
      number.textContent = '';
      if (items.length) {
        items.forEach(function (item, index) {
          if (index > 0) number.appendChild(document.createElement('br'));
          if (item.label) {
            const prefix = document.createElement('span');
            prefix.className = 'description';
            prefix.textContent = item.label + ': ';
            number.appendChild(prefix);
          }
          if (item.url) {
            const link = document.createElement('a');
            link.href = item.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = item.displayText || 'ссылка';
            number.appendChild(link);
          } else {
            number.appendChild(document.createTextNode(item.displayText || item.copyValue || ''));
          }
          const itemCopy = document.createElement('button');
          itemCopy.type = 'button';
          itemCopy.className = 'wdc-copy-tracking-icon';
          itemCopy.dataset.wdcCopyTracking = '1';
          itemCopy.dataset.trackingNumber = item.copyValue || item.displayText || '';
          itemCopy.disabled = !itemCopy.dataset.trackingNumber;
          itemCopy.setAttribute('aria-label', 'Копировать номер отслеживания');
          itemCopy.title = 'Копировать';
          itemCopy.textContent = '🗐';
          number.appendChild(document.createTextNode(' '));
          number.appendChild(itemCopy);
        });
      } else if (url) {
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = value || 'ссылка';
        number.appendChild(link);
      } else {
        number.textContent = value;
      }
    }
    if (row) row.hidden = !items.length && !value && !copyValue;
    if (copy) {
      copy.hidden = !!items.length;
      copy.disabled = !!items.length || !copyValue;
      copy.dataset.trackingNumber = copyValue;
    }
  }

  function setReturnTrackingDisplay(box, trackingNumber) {
    if (!box) return;
    const tracking = trackingNumber && typeof trackingNumber === 'object'
      ? trackingNumber
      : { displayText: String(trackingNumber || '').trim(), copyValue: String(trackingNumber || '').trim(), url: '' };
    const value = String(tracking.displayText || tracking.display_text || tracking.copyValue || tracking.copy_value || '').trim();
    const copyValue = String(tracking.copyValue || tracking.copy_value || value || '').trim();
    const items = Array.isArray(tracking.items) ? tracking.items : [];
    const row = box.querySelector('[data-wdc-return-tracking-row]');
    const label = box.querySelector('[data-wdc-return-tracking-label]');
    const number = box.querySelector('[data-wdc-return-tracking-number]');
    const copy = box.querySelector('[data-wdc-return-copy-tracking]');
    if (!row || !label || !number) return;
    if (label && tracking.label) label.textContent = String(tracking.label);
    number.textContent = '';
    if (items.length) {
      items.forEach(function (item, index) {
        if (index > 0) number.appendChild(document.createElement('br'));
        if (item.label) {
          const prefix = document.createElement('span');
          prefix.className = 'description';
          prefix.textContent = item.label + ': ';
          number.appendChild(prefix);
        }
        number.appendChild(document.createTextNode(item.displayText || item.copyValue || ''));
        const itemCopy = document.createElement('button');
        itemCopy.type = 'button';
        itemCopy.className = 'wdc-copy-tracking-icon';
        itemCopy.dataset.wdcCopyTracking = '1';
        itemCopy.dataset.trackingNumber = item.copyValue || item.displayText || '';
        itemCopy.disabled = !itemCopy.dataset.trackingNumber;
        itemCopy.setAttribute('aria-label', 'Копировать номер возврата');
        itemCopy.title = 'Копировать';
        itemCopy.textContent = '🗐';
        number.appendChild(document.createTextNode(' '));
        number.appendChild(itemCopy);
      });
    } else {
      number.textContent = value;
    }
    row.hidden = !items.length && !value && !copyValue;
    if (copy) {
      copy.hidden = !!items.length;
      copy.disabled = !!items.length || !copyValue;
      copy.dataset.trackingNumber = copyValue;
    }
  }

  function safeTrackingUrl(url) {
    const value = String(url || '').trim();
    if (!value) return '';
    try {
      const parsed = new URL(value, window.location.href);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? value : '';
    } catch (error) {
      return '';
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
      manualAttachFieldLabel: 'Номер отслеживания',
      manualAttachPlaceholder: 'Номер отслеживания',
      manualAttachHelp: 'Введите номер отслеживания для поиска и привязки отправления.',
      manualAttachActualCostEnabled: '0',
      manualAttachActualCostLabel: 'Фактическая стоимость, ₽',
      manualAttachActualCostPlaceholder: 'Например: 550.50',
      createdToast: 'Отправление создано.',
      updatedToast: 'Статус отправления обновлен.',
      cancelSuccessToast: 'Отправление отменено.',
      removeSuccessToast: 'Данные отправления удалены из заказа.',
      errorFallbackMessage: 'Не удалось получить статус отправления.',
      pollingTimeoutMessage: 'Автоматическая проверка завершена. Если статус еще не обновился, воспользуйтесь кнопкой «Обновить статус».',
      removeConfirmationMessage: '',
      registrationErrorToast: 'Регистрация завершилась ошибкой.',
      registrationSuccessToast: 'Регистрация завершена успешно.',
      autoUpdateStatusAfterManualAttach: '0',
      autoPollRegistration: '0',
      registrationPollIntervalMs: '5000',
      registrationPollMaxAttempts: '14'
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
      ['[data-wdc-manual-attach-label]', text.manualAttachFieldLabel],
      ['[data-wdc-manual-attach-actual-cost-label]', text.manualAttachActualCostLabel],
      ['[data-wdc-manual-attach-help]', text.manualAttachHelp]
    ];
    pairs.forEach(function (pair) {
      const element = box.querySelector(pair[0]);
      if (element) element.textContent = pair[1];
    });
    const input = box.querySelector('[data-wdc-manual-tracking-input]');
    if (input) input.placeholder = text.manualAttachPlaceholder;
    const costWrap = box.querySelector('[data-wdc-manual-attach-actual-cost-wrap]');
    setVisible(costWrap, text.manualAttachActualCostEnabled === '1');
    const costInput = box.querySelector('[data-wdc-manual-attach-actual-cost-input]');
    if (costInput) costInput.placeholder = text.manualAttachActualCostPlaceholder;
  }

  function updateShipmentButtons(box, state) {
    if (!box) return;
    const hasShipment = !!(state && state.hasShipment);
    const canCreate = state && Object.prototype.hasOwnProperty.call(state, 'canCreate') ? !!state.canCreate : !hasShipment;
    const canAttachManual = state && Object.prototype.hasOwnProperty.call(state, 'canAttachManual') ? !!state.canAttachManual : !hasShipment;
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
      setVisible(openButton, canCreate);
      openButton.disabled = !canCreate;
    }
    if (updateButton) {
      setVisible(updateButton, canUpdate);
      updateButton.disabled = !canUpdate;
    }
    if (manualButton) {
      setVisible(manualButton, canAttachManual);
      manualButton.disabled = !canAttachManual;
    }
    if (cancelButton) {
      setVisible(cancelButton, canCancel);
      cancelButton.disabled = !canCancel;
    }
    if (removeButton) {
      setVisible(removeButton, canRemove);
      removeButton.disabled = !canRemove;
    }
    updateDocumentActions(box, state && Array.isArray(state.documentActions) ? state.documentActions : []);
    dispatchShipmentCarrierHook('updateButtons', { box: box, state: state || {} });
  }

  function updateDocumentActions(box, actions) {
    if (!box) return;
    const visible = new Map();
    (Array.isArray(actions) ? actions : []).forEach(function (action) {
      if (!action) return;
      const key = String(action.key || '');
      if (!key) return;
      visible.set(key, !!action.visible);
      let link = box.querySelector('[data-wdc-shipment-document-download][data-action-key="' + cssEscape(key) + '"]');
      if (!link && action.visible && action.download_url) {
        link = document.createElement('a');
        link.className = 'button';
        link.dataset.wdcShipmentDocumentDownload = '1';
        link.dataset.actionKey = key;
        const updateButton = box.querySelector('[data-wdc-update-shipment-status]');
        const orderId = String(action.order_id || (updateButton && updateButton.dataset ? updateButton.dataset.orderId : '') || '');
        if (orderId) link.dataset.orderId = orderId;
        const actionsContainer = box.querySelector('.wdc-shipments-actions');
        const manualButton = box.querySelector('[data-wdc-open-manual-tracking]');
        if (actionsContainer && manualButton && manualButton.parentNode === actionsContainer) {
          actionsContainer.insertBefore(link, manualButton);
        } else if (actionsContainer) {
          actionsContainer.appendChild(link);
        }
      }
      if (link) {
        const url = String(action.download_url || action.downloadUrl || '').trim();
        if (url) {
          link.href = url;
          link.dataset.downloadUrl = url;
        }
        link.textContent = String(action.label || link.textContent || key);
      }
    });
    box.querySelectorAll('[data-wdc-shipment-document-download]').forEach(function (link) {
      const key = link.dataset ? String(link.dataset.actionKey || '') : '';
      const enabled = visible.get(key) === true;
      setVisible(link, enabled);
      if (enabled) {
        link.removeAttribute('aria-disabled');
      } else {
        link.setAttribute('aria-disabled', 'true');
      }
    });
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/["\\]/g, '\\$&');
  }

  function shipmentButtonStateFromStatus(statusPayload) {
    return {
      hasShipment: !!statusPayload.has_shipment,
      canCreate: Object.prototype.hasOwnProperty.call(statusPayload, 'can_create') ? !!statusPayload.can_create : undefined,
      canAttachManual: Object.prototype.hasOwnProperty.call(statusPayload, 'can_attach_manual') ? !!statusPayload.can_attach_manual : undefined,
      canCancel: !!statusPayload.can_cancel,
      canRemove: !!statusPayload.can_remove_from_order,
      canUpdate: !!statusPayload.can_update_status,
      documentActions: Array.isArray(statusPayload.document_actions) ? statusPayload.document_actions : []
    };
  }

  function resetShipmentUi(box, statusPayload) {
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
    setReturnTrackingDisplay(box, '');
    renderShipmentPrice(box, {});
    renderActualCostControl(box, { has_shipment: false, has_actual_cost: false });
    const updatedRow = box.querySelector('[data-wdc-updated-row]');
    if (updatedRow) updatedRow.hidden = true;
    const plannedRow = box.querySelector('[data-wdc-planned-delivery-row]');
    if (plannedRow) plannedRow.hidden = true;
    const message = box.querySelector('[data-wdc-shipment-status-message]');
    if (message) {
      message.textContent = '';
      message.dataset.status = '';
    }
    setShipmentPollingIndicator(box, false);
    updateShipmentButtons(
      box,
      statusPayload
        ? shipmentButtonStateFromStatus(statusPayload)
        : { hasShipment: false, canCancel: false, canRemove: false, canUpdate: false, documentActions: [] }
    );
    dispatchShipmentCarrierHook('resetStatusUi', { box: box });
    const manualForm = box.querySelector('[data-wdc-manual-tracking-form]');
    if (manualForm) manualForm.hidden = true;
  }

