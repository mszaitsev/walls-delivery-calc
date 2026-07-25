  function updateCdekDeliveryModeUi(form) {
    const mode = selectedDeliveryMode(form);
    const commentRow = form.querySelector('[data-wdc-cdek-courier-comment-row]');
    if (commentRow) commentRow.hidden = ![1, 3].includes(mode);
    const senderDoor = form.querySelector('[data-wdc-cdek-sender-door]');
    const senderWarehouse = form.querySelector('[data-wdc-cdek-sender-warehouse]');
    if (senderDoor) senderDoor.hidden = ![1, 2].includes(mode);
    if (senderWarehouse) senderWarehouse.hidden = [1, 2].includes(mode);
    updateCdekRecipientDocumentUi(form);
  }

  function cdekRecipientCountry(form) {
    return String(fieldValue(form, '[data-wdc-cdek-recipient-country]') || fieldValue(form, 'input[name="recipient_location_country"]') || 'RU').trim().toUpperCase();
  }

  function updateCdekRecipientDocumentUi(form) {
    if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'cdek') return true;
    const country = cdekRecipientCountry(form);
    const row = form.querySelector('[data-wdc-cdek-recipient-document-row]');
    const input = form.querySelector('[data-wdc-cdek-recipient-document]');
    const help = form.querySelector('[data-wdc-cdek-recipient-document-help]');
    const visible = ['AM', 'BY', 'KZ', 'KG'].includes(country);
    if (row) row.hidden = !visible;
    if (help) {
      const descriptions = {
        KZ: 'ИИН / IIN получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.',
        KG: 'ИИН получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.',
        AM: 'Номер паспорта получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.',
        BY: 'Номер паспорта получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.'
      };
      help.textContent = descriptions[country] || 'Значение передаётся только в СДЭК и не сохраняется.';
    }
    if (input) {
      input.required = false;
      input.disabled = !visible;
      if (!visible) input.value = '';
    }
    return true;
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
        .then(parseShipmentJsonResponse)
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

  function startCdekPolling(button) {
    let attempts = 0;
    const maxAttempts = 14;
    const interval = 5000;
    const box = button && button.closest ? button.closest('[data-wdc-shipments-metabox]') : null;
    setShipmentPollingIndicator(box, true);
    const tick = function () {
      attempts += 1;
      requestShipmentStatus(button, { auto: true })
        .then((payload) => {
          const data = payload && payload.data ? payload.data : {};
          const status = data.status || {};
          const state = String(status.carrier_operation_index || '').toUpperCase();
          const code = String(status.carrier_operation_address || '').toUpperCase();
          if (state === 'INVALID') {
            setShipmentPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).registrationErrorToast, 'error', { append: true });
            return;
          }
          if (code === 'CREATED' || data.terminal) {
            setShipmentPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).registrationSuccessToast, 'success', { append: true });
            return;
          }
          if (attempts >= maxAttempts) {
            setShipmentPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).pollingTimeoutMessage, 'warning', { append: true });
            return;
          }
          window.setTimeout(tick, interval);
        })
        .catch(() => {
          if (attempts >= maxAttempts) {
            setShipmentPollingIndicator(box, false);
            showShipmentToast(box, getPresentation(box).pollingTimeoutMessage, 'warning', { append: true });
            return;
          }
          window.setTimeout(tick, interval);
        });
    };
    window.setTimeout(tick, interval);
  }

  registerShipmentCarrierHooks({
    handleClick: function (event) {
      const addManualItemAlias = event.target.closest('[data-wdc-add-manual-cdek-item]');
      if (addManualItemAlias) {
        addManualShipmentItemRow(addManualItemAlias);
        return true;
      }

      const cdekBarcodeDownload = event.target.closest('[data-wdc-cdek-barcode-download]');
      if (!cdekBarcodeDownload) return false;
      event.preventDefault();
      requestCdekBarcodeDownload(cdekBarcodeDownload);
      return true;
    },
    handleSenderPickupClick: function (event, button) {
      const form = findShipmentForm(button);
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'cdek') return false;
      const context = senderPickupContext(form);
      createPickupPicker(form, {
        sender: true,
        title: 'Выбор ПВЗ отправителя СДЭК',
        context: context,
        onChoose: function (point) {
          updateSenderPickupDraft(form, point);
        }
      });
      return true;
    },
    handleInput: function (event) {
      const form = findShipmentForm(event.target);
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'cdek') return false;
      if (event.target.matches('[data-wdc-cdek-recipient-document]')) {
        updateCreateAvailability(form);
      }
      return false;
    },
    afterFormInitialized: function (form) {
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'cdek') return false;
      updateCdekRecipientDocumentUi(form);
      return false;
    },
    handleChange: function (event) {
      const form = findShipmentForm(event.target);
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'cdek') return false;
      updateCdekRecipientDocumentUi(form);
      return false;
    },
    createAvailability: function (form) {
      return updateCdekRecipientDocumentUi(form);
    },
    afterAddressNormalized: function (context) {
      const form = context && context.form;
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'cdek') return false;
      const snapshot = context.snapshot || {};
      const cityCode = snapshot && snapshot.fields ? String(snapshot.fields.cdek_city_code || '') : '';
      const cityCodeRow = form.querySelector('[data-wdc-cdek-city-code-row]');
      const cityCodeValue = form.querySelector('[data-wdc-cdek-city-code]');
      if (cityCodeValue) cityCodeValue.textContent = cityCode;
      if (cityCodeRow) cityCodeRow.hidden = !cityCode;
      if (context.status) {
        context.status.textContent = snapshot.success
          ? (cityCode ? '✅ Данные для СДЭК корректны' : 'Адрес обработан.')
          : (snapshot.message || 'Адрес не подтвержден, создание отправления заблокировано.');
      }
      return true;
    },
    renderStatus: function (context) {
      const box = context && context.box;
      const status = context && context.status ? context.status : {};
      if (!box) return false;
      if (status.planned_delivery_date) return false;
      const planned = String(status.cdek_planned_delivery_date || '').trim();
      const value = box.querySelector('[data-wdc-planned-delivery-date]');
      const row = box.querySelector('[data-wdc-planned-delivery-row]');
      if (value) value.textContent = planned;
      if (row) row.hidden = !planned;
      return false;
    },
    handleDefaultRegistrationPolling: function (context) {
      const button = context && context.button;
      if (!button || !button.dataset || button.dataset.shipmentKey !== 'cdek') return false;
      startCdekPolling(button);
      return true;
    }
  });
