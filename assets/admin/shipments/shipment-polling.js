  function isCancellationPollingPurpose(value) {
    return String(value || '') === 'cancellation';
  }

  function cancellationPollingProgressMessage(attempt, maxAttempts) {
    return 'Запрос на отмену отправления Яндекс отправлен.\nПроведено: ' + String(attempt || 0) + '/' + String(maxAttempts || 14) + ' проверок отмены';
  }

  function cancellationPollingExhaustedMessage(attempt, maxAttempts) {
    return 'Статус отмены пока не получен.\nПроведено: ' + String(attempt || maxAttempts || 14) + '/' + String(maxAttempts || 14) + ' проверок отмены.\nПовторите обновление статуса позднее.';
  }

  function initCancellationPollingToast(box, token, maxAttempts) {
    if (!box || !token) return;
    const toast = showShipmentToast(box, cancellationPollingProgressMessage(0, maxAttempts), 'success', { persist: true });
    cancellationPollingToasts.set(box, {
      token: token,
      maxAttempts: maxAttempts || 14,
      toast: toast
    });
  }

  function updateCancellationPollingToast(box, token, text, type, persist) {
    const state = box && cancellationPollingToasts.get(box);
    if (!state || state.token !== token || shipmentPollingTokens.get(box) !== token) return;
    state.toast = showShipmentToast(box, text, type || 'success', { persist: persist !== false });
    cancellationPollingToasts.set(box, state);
  }

  function clearCancellationPollingToast(box) {
    const state = box && cancellationPollingToasts.get(box);
    if (state && state.toast) {
      const previous = toastTimers.get(state.toast);
      if (previous) window.clearTimeout(previous);
      state.toast.hidden = true;
      toastTimers.delete(state.toast);
    }
    if (box) cancellationPollingToasts.delete(box);
  }

  function requestShipmentStatus(button, options) {
    const settings = Object.assign({ auto: false }, options || {});
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const text = getPresentation(box);
    const isCancellationPolling = settings.auto && isCancellationPollingPurpose(settings.pollPurpose);
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
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : text.errorFallbackMessage);
        }
        if (settings.pollingToken && shipmentPollingTokens.get(box) !== settings.pollingToken) {
          return null;
        }
        if (payload.data && payload.data.cancelled_and_removed) {
          if (isCancellationPolling) {
            updateCancellationPollingToast(box, settings.pollingToken, payload.data.message || 'Отправление Яндекс отменено.', 'success', false);
          }
          stopShipmentRegistrationPolling(box);
          resetShipmentUi(box);
          if (message) {
            message.dataset.status = 'success';
            message.textContent = payload.data.message || 'Отправление Яндекс отменено.';
          }
          if (!isCancellationPolling) {
            showShipmentToast(box, payload.data.message || 'Отправление Яндекс отменено.', 'success', { append: true });
          }
          return payload;
        }
        const statusPayload = shipmentStatusFromResponse(payload.data);
        const isPending = !!(payload.data && payload.data.pending);
        renderShipmentStatus(box, statusPayload);
        if (message) {
          message.dataset.status = isPending ? 'warning' : 'success';
          message.textContent = payload.data.message || text.updatedToast;
        }
        if (isCancellationPolling) {
          const terminal = !!(statusPayload && (statusPayload.registration_terminal || !statusPayload.polling_continue));
          if (terminal) {
            const rawStatus = String(statusPayload && statusPayload.carrier_status_title || statusPayload && statusPayload.yandex_status || '').trim();
            const terminalMessage = rawStatus ? 'Отмена не выполнена. Получен статус Яндекс: ' + rawStatus + '.' : (payload.data.message || text.updatedToast);
            updateCancellationPollingToast(box, settings.pollingToken, terminalMessage, 'warning', false);
          } else {
            updateCancellationPollingToast(box, settings.pollingToken, cancellationPollingProgressMessage(settings.attempt || 0, settings.maxAttempts || 14), 'success', true);
          }
        } else if (settings.auto && !isPending) {
          const autoMessage = statusPayload && statusPayload.carrier_key === 'yandex_delivery' && statusPayload.carrier_status_title
            ? 'Статус отправления Яндекс получен: ' + statusPayload.carrier_status_title + '.'
            : (payload.data.message || text.updatedToast);
          showShipmentToast(box, autoMessage, 'success', { append: true });
        }
        return payload;
      })
      .catch((error) => {
        if (settings.pollingToken && shipmentPollingTokens.get(box) !== settings.pollingToken) {
          return null;
        }
        if (message) {
          message.dataset.status = settings.auto ? 'warning' : 'error';
          message.textContent = settings.auto
            ? text.createdToast + ' Статус пока не обновлен: ' + error.message
            : error.message;
        }
        if (isCancellationPolling) {
          updateCancellationPollingToast(box, settings.pollingToken, cancellationPollingProgressMessage(settings.attempt || 0, settings.maxAttempts || 14), 'warning', true);
        }
        if (settings.auto) {
          if (settings.pollingToken) {
            throw error;
          }
          showShipmentToast(box, text.createdToast + ' Статус пока не обновлен: ' + error.message, 'warning', { append: true });
          return null;
        }
        throw error;
      })
      .finally(() => {
        if (button) button.disabled = false;
      });
  }

  function submitDpdRegistration(form, attemptId, box, updateButton) {
    const data = collectShipmentData(form);
    data.append('action', window.wdcShipmentsAdmin.createAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('dpd_registration_stage', 'submit');
    data.append('registration_attempt_id', attemptId || '');
    setShipmentPollingIndicator(box, true);
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось отправить заявку DPD.');
        }
        renderShipmentStatus(box, shipmentStatusFromResponse(payload.data));
        renderShipmentTechnicalInfo(box, payload.data || {});
        if (updateButton) updateButton.disabled = false;
        if (payload.data && payload.data.polling_continue) {
          startDpdRegistrationPolling(updateButton);
        } else {
          setShipmentPollingIndicator(box, false);
        }
        return payload;
      })
      .catch((error) => {
        setShipmentPollingIndicator(box, false);
        showShipmentToast(box, error.message, 'error', { append: true });
      });
  }

  function startDpdRegistrationPolling(button) {
    startShipmentRegistrationPolling(button, { interval: 10000, maxAttempts: 0, mode: 'dpd' });
  }

  function stopShipmentRegistrationPolling(box) {
    if (!box) return;
    const timer = shipmentPollingTimers.get(box);
    if (timer) window.clearTimeout(timer);
    shipmentPollingTimers.delete(box);
    shipmentPollingTokens.delete(box);
    setShipmentPollingIndicator(box, false);
  }

  function markShipmentPollingExhausted(button, attempts, token) {
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const text = getPresentation(box);
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.markPollExhaustedAction || 'wdc_mark_shipment_poll_exhausted');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || 'russian_post_domestic' : 'russian_post_domestic');
    data.append('attempts', String(attempts || 0));
    data.append('purpose', button && button.dataset ? button.dataset.pollPurpose || 'registration' : 'registration');
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (token && shipmentPollingTokens.get(box) !== token) {
          return null;
        }
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : text.pollingTimeoutMessage);
        }
        renderShipmentStatus(box, shipmentStatusFromResponse(payload.data));
        renderShipmentTechnicalInfo(box, payload.data || {});
        const message = box && box.querySelector('[data-wdc-shipment-status-message]');
        if (message) {
          message.dataset.status = 'warning';
          message.textContent = payload.data.message || text.pollingTimeoutMessage;
        }
        if ('cancellation' === (button && button.dataset ? button.dataset.pollPurpose || '' : '')) {
          updateCancellationPollingToast(box, token, cancellationPollingExhaustedMessage(attempts, attempts || 14), 'warning', false);
        } else {
          showShipmentToast(box, payload.data.message || text.pollingTimeoutMessage, 'warning');
        }
        return payload;
      })
      .catch((error) => {
        if (token && shipmentPollingTokens.get(box) !== token) {
          return null;
        }
        if (window.console && window.console.warn) {
          window.console.warn('Не удалось сохранить состояние автоматической проверки отправления.', error);
        }
        updateShipmentButtons(box, { hasShipment: true, canCreate: false, canAttachManual: false, canCancel: false, canRemove: true, canUpdate: true, canPrintBarcode: false, canDownloadDpdDocuments: false, canDownloadYandexLabel: false });
        const message = box && box.querySelector('[data-wdc-shipment-status-message]');
        if (message) {
          message.dataset.status = 'warning';
          message.textContent = text.pollingTimeoutMessage;
        }
        if ('cancellation' === (button && button.dataset ? button.dataset.pollPurpose || '' : '')) {
          updateCancellationPollingToast(box, token, cancellationPollingExhaustedMessage(attempts, attempts || 14), 'warning', false);
        } else {
          showShipmentToast(box, text.pollingTimeoutMessage, 'warning');
        }
        return null;
      });
  }

  function startShipmentRegistrationPolling(button, options) {
    const settings = Object.assign({ interval: 5000, maxAttempts: 14, mode: 'generic' }, options || {});
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    if (!box || shipmentPollingTimers.has(box)) return;
    const text = getPresentation(box);
    const token = {};
    let attempts = 0;
    const interval = Math.max(1000, parseInt(settings.interval, 10) || 5000);
    const maxAttempts = Math.max(0, parseInt(settings.maxAttempts, 10) || 0);
    if (button && button.dataset) button.dataset.pollPurpose = settings.purpose || settings.mode || 'registration';
    const stop = function () {
      stopShipmentRegistrationPolling(box);
    };
    const exhausted = function () {
      const timer = shipmentPollingTimers.get(box);
      if (timer) window.clearTimeout(timer);
      shipmentPollingTimers.delete(box);
      setShipmentPollingIndicator(box, false);
      markShipmentPollingExhausted(button, attempts, token).finally(function () {
        if (shipmentPollingTokens.get(box) === token) {
          shipmentPollingTokens.delete(box);
        }
      });
    };
    setShipmentPollingIndicator(box, true);
    shipmentPollingTokens.set(box, token);
    if (isCancellationPollingPurpose(settings.purpose || settings.mode)) {
      initCancellationPollingToast(box, token, maxAttempts || 14);
    }
    const tick = function () {
      attempts += 1;
      requestShipmentStatus(button, { auto: true, pollingToken: token, pollPurpose: settings.purpose || settings.mode || 'registration', attempt: attempts, maxAttempts: maxAttempts || 14 })
        .then((payload) => {
          if (shipmentPollingTokens.get(box) !== token) return;
          const status = payload && payload.data && payload.data.status ? payload.data.status : {};
          if (status.registration_terminal || status.registration_success || status.registration_error || !status.polling_continue) {
            stop();
            return;
          }
          if (maxAttempts > 0 && attempts >= maxAttempts) {
            exhausted();
            return;
          }
          shipmentPollingTimers.set(box, window.setTimeout(tick, interval));
        })
        .catch(() => {
          if (shipmentPollingTokens.get(box) !== token) return;
          if (maxAttempts > 0 && attempts >= maxAttempts) {
            exhausted();
            return;
          }
          if (settings.mode === 'dpd') {
            stop();
            return;
          }
          shipmentPollingTimers.set(box, window.setTimeout(tick, interval));
        });
    };
    shipmentPollingTimers.set(box, window.setTimeout(tick, interval));
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
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          if (payload && payload.data && payload.data.temporary_can_remove) {
            updateShipmentButtons(box, { hasShipment: true, canCancel: false, canRemove: true, canUpdate: true, canPrintBarcode: false, canDownloadDpdDocuments: false, canDownloadYandexLabel: false });
          }
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось отменить отправление.');
        }
        if (payload.data && payload.data.cancelled_and_removed) {
          stopShipmentRegistrationPolling(box);
          resetShipmentUi(box);
          showShipmentToast(box, payload.data.message || getPresentation(box).cancelSuccessToast, 'success');
          return payload;
        }
        renderShipmentStatus(box, shipmentStatusFromResponse(payload.data));
        renderShipmentTechnicalInfo(box, payload.data || {});
        if (payload.data && payload.data.auto_poll) {
          startShipmentRegistrationPolling(button, {
            interval: payload.data.poll_interval_ms || 5000,
            maxAttempts: payload.data.poll_max_attempts || 14,
            mode: 'cancellation',
            purpose: payload.data.poll_purpose || 'cancellation'
          });
        } else {
          showShipmentToast(box, payload.data.message || 'Запрос на отмену отправления отправлен.', 'success');
        }
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
    const confirmation = getPresentation(box).removeConfirmationMessage;
    if (confirmation && !window.confirm(confirmation)) {
      return Promise.resolve(null);
    }
    clearCancellationPollingToast(box);
    stopShipmentRegistrationPolling(box);
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
      .then(parseShipmentJsonResponse)
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
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось сохранить номер отслеживания.');
        }
        if (form) form.hidden = true;
        if (input) input.value = '';
        const statusPayload = shipmentStatusFromResponse(payload.data);
        renderShipmentStatus(box, statusPayload);
        renderShipmentTechnicalInfo(box, payload.data || {});
        setTrackingDisplay(box, trackingPresentation(statusPayload));
        updateShipmentButtons(box, {
          hasShipment: !!statusPayload.has_shipment,
          canCreate: Object.prototype.hasOwnProperty.call(statusPayload, 'can_create') ? !!statusPayload.can_create : undefined,
          canAttachManual: Object.prototype.hasOwnProperty.call(statusPayload, 'can_attach_manual') ? !!statusPayload.can_attach_manual : undefined,
          canCancel: !!statusPayload.can_cancel,
          canRemove: !!statusPayload.can_remove_from_order,
          canUpdate: !!statusPayload.can_update_status,
          canPrintBarcode: !!statusPayload.can_print_barcode,
          canDownloadDpdDocuments: !!statusPayload.can_download_dpd_documents,
          canDownloadYandexLabel: !!statusPayload.can_download_yandex_label
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

