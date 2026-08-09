  function markPreviewPending(form) {
    if (!form) return;
    form.dataset.wdcPreviewLoaded = '';
    form.dataset.wdcPreviewHasErrors = '1';
    updateCreateAvailability(form);
  }

  function requestPreview(form) {
    markPreviewPending(form);
    const preview = form.querySelector('[data-wdc-shipment-preview]');
    const errors = form.querySelector('[data-wdc-shipment-errors]');
    const data = collectShipmentData(form);
    data.append('action', window.wdcShipmentsAdmin.previewAction);
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(parseShipmentJsonResponse)
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось обновить предпросмотр.');
        }
        if (preview) {
          preview.textContent = JSON.stringify(visiblePreviewPayload(payload.data.preview || {}), null, 2);
        }
        const previewPayload = payload.data.preview || {};
        const previewErrors = previewPayload && Array.isArray(previewPayload.errors)
          ? previewPayload.errors
          : [];
        const previewWarnings = previewPayload && Array.isArray(previewPayload.warnings)
          ? previewPayload.warnings
          : [];
        if (errors) {
          errors.textContent = previewErrors.length ? previewErrors.join('; ') : previewWarnings.join('; ');
          form.dataset.wdcPreviewLoaded = '1';
          form.dataset.wdcPreviewHasErrors = previewErrors.length ? '1' : '0';
          if (previewErrors.length) {
            delete errors.dataset.previewWarning;
          } else if (previewWarnings.length) {
            errors.dataset.previewWarning = '1';
          } else {
            delete errors.dataset.previewWarning;
          }
        } else {
          form.dataset.wdcPreviewLoaded = '1';
          form.dataset.wdcPreviewHasErrors = '0';
        }
        dispatchShipmentCarrierHook('afterPreviewUpdated', {
          form: form,
          preview: previewPayload,
          errors: previewErrors,
          warnings: previewWarnings
        });
        updateCreateAvailability(form);
      })
      .catch((error) => {
        form.dataset.wdcPreviewLoaded = '';
        form.dataset.wdcPreviewHasErrors = '1';
        if (errors) {
          errors.dataset.previewWarning = '1';
          errors.textContent = 'Предпросмотр временно не обновлен: ' + error.message;
        }
        updateCreateAvailability(form);
      });
  }

  function visiblePreviewPayload(payload) {
    const clone = Object.assign({}, payload || {});
    delete clone.dry_run;
    delete clone.live_api_call;
    return clone;
  }

  function updateCreateAvailability(form) {
    const submit = form.querySelector('[data-wdc-create-shipment]');
    if (!submit) return;
    const tariff = form.querySelector('[data-wdc-tariff-select]');
    const requiresTariff = form.dataset.wdcRequiresTariff !== '0';
    const requiresSuccessfulPreview = form.dataset.wdcRequiresSuccessfulPreview === '1';
    const hasTariffs = !!(tariff && !tariff.disabled && tariff.options.length);
    const deliveryType = selectedDeliveryType(form);
    const pickupMissing = deliveryType === 'pickup' && !!form.querySelector('[data-wdc-pickup-warning]');
    const latestPreviewReady = !requiresSuccessfulPreview || (form.dataset.wdcPreviewLoaded === '1' && form.dataset.wdcPreviewHasErrors !== '1');
    const placesReady = Array.from(form.querySelectorAll('[data-wdc-place]')).some((row) => {
      const weight = row.querySelector('input[name$="[weight_g]"]');
      const length = row.querySelector('input[name$="[length_cm]"]');
      const width = row.querySelector('input[name$="[width_cm]"]');
      const height = row.querySelector('input[name$="[height_cm]"]');
      return (parseInt(weight && weight.value ? weight.value : '0', 10) || 0) > 0
        && parseDecimalValue(length && length.value ? length.value : '0') > 0
        && parseDecimalValue(width && width.value ? width.value : '0') > 0
        && parseDecimalValue(height && height.value ? height.value : '0') > 0;
    });
    submit.disabled = (requiresTariff && !hasTariffs) || !latestPreviewReady || pickupMissing || !carrierCreateAvailability(form, deliveryType) || !placesReady;
  }
  function schedulePreview(form) {
    markPreviewPending(form);
    const previous = timers.get(form);
    if (previous) {
      window.clearTimeout(previous);
    }
    timers.set(form, window.setTimeout(function () {
      requestPreview(form);
    }, 400));
  }

