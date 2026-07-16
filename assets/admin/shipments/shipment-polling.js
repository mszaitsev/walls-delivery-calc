  function requestShipmentStatus(button, options) {
    const settings = Object.assign({ auto: false }, options || {});
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const text = getPresentation(box);
    const message = box && box.querySelector('[data-wdc-shipment-status-message]');
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.updateStatusAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || '' : '');
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
          stopShipmentRegistrationPolling(box);
          resetShipmentUi(box);
          const handled = dispatchShipmentCarrierHook('cancelledAndRemoved', {
            box: box,
            button: button,
            payload: payload,
            messageElement: message,
            settings: settings,
            token: settings.pollingToken
          });
          if (message) {
            message.dataset.status = 'success';
            message.textContent = payload.data.message || text.cancelSuccessToast;
          }
          if (!handled) {
            showShipmentToast(box, payload.data.message || text.cancelSuccessToast, 'success', { append: true });
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
        const handled = dispatchShipmentCarrierHook('handlePollingStatus', {
          box: box,
          button: button,
          payload: payload,
          statusPayload: statusPayload,
          messageElement: message,
          settings: settings,
          token: settings.pollingToken,
          attempt: settings.attempt || 0,
          maxAttempts: settings.maxAttempts || 14,
          pending: isPending
        });
        if (!handled && settings.auto && !isPending) {
          showShipmentToast(box, payload.data.message || text.updatedToast, 'success', { append: true });
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
        const handled = dispatchShipmentCarrierHook('handlePollingError', {
          box: box,
          button: button,
          error: error,
          messageElement: message,
          settings: settings,
          token: settings.pollingToken,
          attempt: settings.attempt || 0,
          maxAttempts: settings.maxAttempts || 14
        });
        if (settings.auto) {
          if (settings.pollingToken) {
            throw error;
          }
          if (!handled) {
            showShipmentToast(box, text.createdToast + ' Статус пока не обновлен: ' + error.message, 'warning', { append: true });
          }
          return null;
        }
        throw error;
      })
      .finally(() => {
        if (button) button.disabled = false;
      });
  }

  function stopShipmentRegistrationPolling(box) {
    if (!box) return;
    const timer = shipmentPollingTimers.get(box);
    if (timer) window.clearTimeout(timer);
    shipmentPollingTimers.delete(box);
    shipmentPollingTokens.delete(box);
    setShipmentPollingIndicator(box, false);
  }

  function normalizeShipmentLifecycle(value) {
    const source = value && typeof value === 'object' ? value : {};
    const phase = String(source.phase || 'completed');
    return {
      phase: phase,
      accepted: source.accepted !== false,
      submitRequired: source.submit_required === true || source.submitRequired === true,
      pollRequired: source.poll_required === true || source.pollRequired === true,
      attemptId: String(source.attempt_id || source.attemptId || ''),
      message: String(source.message || ''),
      pollIntervalMs: Math.max(0, parseInt(source.poll_interval_ms || source.pollIntervalMs || 5000, 10) || 0),
      pollMaxAttempts: Math.max(0, parseInt(source.poll_max_attempts || source.pollMaxAttempts || 14, 10) || 0),
      pollPurpose: String(source.poll_purpose || source.pollPurpose || 'registration'),
      stopOnError: source.stop_on_error === true || source.stopOnError === true
    };
  }

  function continueShipmentLifecycle(context) {
    const source = context || {};
    const lifecycle = normalizeShipmentLifecycle(source.lifecycle);
    const button = source.updateButton || source.button || null;
    const box = source.box || (button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null);
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.continueLifecycleAction || 'wdc_continue_shipment_lifecycle');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('carrier_key', button && button.dataset ? button.dataset.shipmentKey || '' : '');
    data.append('attempt_id', lifecycle.attemptId);
    if (button) button.disabled = true;
    setShipmentPollingIndicator(box, true);
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось продолжить регистрацию отправления.');
        }
        const statusPayload = shipmentStatusFromResponse(payload.data);
        renderShipmentStatus(box, statusPayload);
        renderShipmentTechnicalInfo(box, payload.data || {});
        if (button) button.disabled = false;
        setShipmentPollingIndicator(box, false);
        handleShipmentLifecycleResult({
          payload: payload,
          lifecycle: payload.data ? payload.data.lifecycle : null,
          box: box,
          updateButton: button,
          statusPayload: statusPayload
        });
        return payload;
      })
      .catch((error) => {
        setShipmentPollingIndicator(box, false);
        if (button) button.disabled = false;
        showShipmentToast(box, error.message, 'error', { append: true });
        return null;
      });
  }

  function handleShipmentLifecycleResult(context) {
    const source = context || {};
    const lifecycle = normalizeShipmentLifecycle(source.lifecycle || (source.payload && source.payload.data ? source.payload.data.lifecycle : null));
    const button = source.updateButton || source.button || null;
    if (lifecycle.submitRequired) {
      continueShipmentLifecycle(Object.assign({}, source, { lifecycle: lifecycle }));
      return true;
    }
    if (lifecycle.pollRequired && button && !button.disabled) {
      startShipmentRegistrationPolling(button, {
        interval: lifecycle.pollIntervalMs || 5000,
        maxAttempts: lifecycle.pollMaxAttempts,
        purpose: lifecycle.pollPurpose || 'registration',
        stopOnError: lifecycle.stopOnError
      });
      return true;
    }
    return false;
  }

  function markShipmentPollingExhausted(button, attempts, token) {
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const text = getPresentation(box);
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.markPollExhaustedAction || 'wdc_mark_shipment_poll_exhausted');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || '' : '');
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
        const handled = dispatchShipmentCarrierHook('handlePollingExhausted', {
          box: box,
          button: button,
          payload: payload,
          messageElement: message,
          token: token,
          attempt: attempts,
          maxAttempts: attempts || 14
        });
        if (!handled) {
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
        updateShipmentButtons(box, { hasShipment: true, canCreate: false, canAttachManual: false, canCancel: false, canRemove: true, canUpdate: true, labelActions: [] });
        const message = box && box.querySelector('[data-wdc-shipment-status-message]');
        if (message) {
          message.dataset.status = 'warning';
          message.textContent = text.pollingTimeoutMessage;
        }
        const handled = dispatchShipmentCarrierHook('handlePollingExhausted', {
          box: box,
          button: button,
          error: error,
          messageElement: message,
          token: token,
          attempt: attempts,
          maxAttempts: attempts || 14
        });
        if (!handled) {
          showShipmentToast(box, text.pollingTimeoutMessage, 'warning');
        }
        return null;
      });
  }

  function startShipmentRegistrationPolling(button, options) {
    const settings = Object.assign({ interval: 5000, maxAttempts: 14, purpose: 'registration' }, options || {});
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    if (!box || shipmentPollingTimers.has(box)) return;
    const text = getPresentation(box);
    const token = {};
    let attempts = 0;
    const interval = Math.max(1000, parseInt(settings.interval, 10) || 5000);
    const maxAttempts = Math.max(0, parseInt(settings.maxAttempts, 10) || 0);
    if (button && button.dataset) button.dataset.pollPurpose = settings.purpose || 'registration';
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
    dispatchShipmentCarrierHook('handlePollingStart', {
      box: box,
      button: button,
      settings: settings,
      token: token,
      maxAttempts: maxAttempts || 14
    });
    const tick = function () {
      attempts += 1;
      requestShipmentStatus(button, { auto: true, pollingToken: token, pollPurpose: settings.purpose || 'registration', attempt: attempts, maxAttempts: maxAttempts || 14 })
        .then((payload) => {
          if (shipmentPollingTokens.get(box) !== token) return;
          const status = payload && payload.data && payload.data.status ? payload.data.status : {};
          const lifecycleSource = status.lifecycle || (payload && payload.data ? payload.data.lifecycle : null);
          const hasLifecycle = lifecycleSource && typeof lifecycleSource === 'object';
          const lifecycle = normalizeShipmentLifecycle(lifecycleSource);
          if (hasLifecycle && !lifecycle.pollRequired) {
            stop();
            return;
          }
          if (!hasLifecycle && (status.registration_terminal || status.registration_success || status.registration_error || !status.polling_continue)) {
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
          if (settings.stopOnError) {
            stop();
            return;
          }
          shipmentPollingTimers.set(box, window.setTimeout(tick, interval));
        });
    };
    shipmentPollingTimers.set(box, window.setTimeout(tick, interval));
  }

  function startDefaultRegistrationPolling(button) {
    const handled = dispatchShipmentCarrierHook('handleDefaultRegistrationPolling', {
      button: button,
      box: button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null
    });
    if (!handled) {
      requestShipmentStatus(button, { auto: true });
    }
  }

  function requestShipmentCancel(button) {
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.cancelAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || '' : '');
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
            updateShipmentButtons(box, { hasShipment: true, canCancel: false, canRemove: true, canUpdate: true, labelActions: [] });
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
    dispatchShipmentCarrierHook('handlePollingStop', {
      box: box
    });
    stopShipmentRegistrationPolling(box);
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.removeFromOrderAction || 'wdc_remove_shipment_from_order');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('order_id', button && button.dataset ? button.dataset.orderId || '' : '');
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || '' : '');
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
    data.append('shipment_key', button && button.dataset ? button.dataset.shipmentKey || '' : '');
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
        updateShipmentButtons(box, shipmentButtonStateFromStatus(statusPayload));
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

