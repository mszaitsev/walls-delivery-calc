  function dpdContactHistory() {
    return Array.isArray(window.wdcShipmentsAdmin && window.wdcShipmentsAdmin.dpdCourierContactHistory)
      ? window.wdcShipmentsAdmin.dpdCourierContactHistory
      : [];
  }

  function setDpdContactHistory(values) {
    if (!window.wdcShipmentsAdmin) return;
    window.wdcShipmentsAdmin.dpdCourierContactHistory = Array.isArray(values) ? values : [];
    document.querySelectorAll('[data-wdc-dpd-contact-history]').forEach((list) => renderDpdContactHistory(list));
  }

  function renderDpdContactHistory(list) {
    if (!list) return;
    const values = dpdContactHistory();
    if (!values.length) {
      list.hidden = true;
      list.innerHTML = '';
      return;
    }
    list.innerHTML = values.map((value) => '<span class="wdc-dpd-contact-choice"><button type="button" data-wdc-dpd-contact-choice data-value="' + escapeHtml(value) + '">' + escapeHtml(value) + '</button><button type="button" class="wdc-icon-action wdc-icon-action--danger" data-wdc-dpd-contact-remove data-value="' + escapeHtml(value) + '" aria-label="Удалить">×</button></span>').join('');
    list.hidden = false;
  }

  function showDpdContactHistory(input) {
    const list = input && input.closest('label') && input.closest('label').querySelector('[data-wdc-dpd-contact-history]');
    renderDpdContactHistory(list);
  }

  function syncDpdAddressFields(form, snapshot) {
    const fields = snapshot && snapshot.fields ? snapshot.fields : {};
    const mapped = {
      countryName: 'Россия',
      index: fields.index || fields.postal_code || fields.cdek_postal_code || '',
      region: fields.region || fields.region_name || '',
      city: fields.city || fields.settlement || fields.cdek_city_name || '',
      street: fields.street || fields.street_name || '',
      streetAbbr: fields.street_type || fields.street_abbr || '',
      house: fields.house || fields.house_no || '',
      houseKorpus: fields.block || fields.houseKorpus || '',
      str: fields.structure || fields.str || '',
      vlad: fields.stead || fields.vlad || '',
      extraInfo: fields.extraInfo || fields.extra_info || '',
      office: fields.office || '',
      flat: fields.flat || fields.apartment || ''
    };
    Object.keys(mapped).forEach((key) => {
      const input = form.querySelector('[data-wdc-dpd-address-field="' + key + '"]');
      if (input) input.value = mapped[key] || '';
    });
  }

  registerShipmentCarrierHooks({
    createAvailability: function (form, deliveryType) {
      if (fieldValue(form, 'input[name="carrier_key"]') !== 'dpd') return true;
      const datePickup = fieldValue(form, '[data-wdc-dpd-date-pickup]');
      const dateReady = /^\d{4}-\d{2}-\d{2}$/.test(datePickup);
      const contactReady = !!fieldValue(form, '[data-wdc-dpd-contact-fio]');
      const senderTerminalReady = !!firstFieldValue(form, ['[name="pickup_terminal_code"]', '[name="sender_shipment_point"]', '[name="shipment_point"]', '[data-wdc-sender-shipment-point]']);
      const receiverTerminalReady = deliveryType !== 'pickup' || !!firstFieldValue(form, ['[name="pickup_point_code"]', '[name="delivery_point"]']);
      let courierReady = true;
      if (deliveryType === 'courier') {
        const normalizedJson = form.querySelector('[data-wdc-normalized-address-json]');
        courierReady = false;
        try {
          const snapshot = JSON.parse(normalizedJson && normalizedJson.value ? normalizedJson.value : '{}');
          courierReady = snapshot && snapshot.success === true;
        } catch (error) {
          courierReady = false;
        }
      }
      return dateReady && contactReady && senderTerminalReady && receiverTerminalReady && courierReady;
    },
    handleClick: function (event) {
      const dateInput = event.target.closest('[data-wdc-dpd-date-pickup]');
      if (dateInput) {
        openNativeDatePicker(dateInput);
        return false;
      }

      const dpdContactChoice = event.target.closest('[data-wdc-dpd-contact-choice]');
      if (dpdContactChoice) {
        event.preventDefault();
        const form = findShipmentForm(dpdContactChoice);
        const input = form && form.querySelector('[data-wdc-dpd-contact-fio]');
        if (input) {
          input.value = dpdContactChoice.dataset.value || '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const list = dpdContactChoice.closest('[data-wdc-dpd-contact-history]');
        if (list) list.hidden = true;
        return true;
      }

      const dpdContactRemove = event.target.closest('[data-wdc-dpd-contact-remove]');
      if (dpdContactRemove) {
        event.preventDefault();
        updateDpdContactHistory(dpdContactRemove.dataset.value || '', 'remove').catch(function () {});
        return true;
      }

      const dpdDocumentsDownload = event.target.closest('[data-wdc-dpd-documents-download]');
      if (dpdDocumentsDownload) {
        event.preventDefault();
        requestDpdDocumentsDownload(dpdDocumentsDownload);
        return true;
      }

      return false;
    },
    handleSenderPickupClick: function (event, button) {
      const form = findShipmentForm(button);
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'dpd') return false;
      const context = senderPickupContext(form);
      createPickupPicker(form, {
        sender: true,
        title: 'Выбор ПВЗ отправителя DPD',
        context: context,
        onChoose: function (point) {
          updateSenderPickupDraft(form, point);
        }
      });
      return true;
    },
    handleInput: function (event) {
      if (event.target.matches('[data-wdc-dpd-contact-fio]')) {
        const form = findShipmentForm(event.target);
        if (form) {
          updateCreateAvailability(form);
          schedulePreview(form);
        }
        return true;
      }
      if (event.target.matches('[data-wdc-dpd-courier-instructions]')) {
        if (event.target.value.length > 250) event.target.value = event.target.value.slice(0, 250);
        const form = findShipmentForm(event.target);
        if (form) schedulePreview(form);
        return true;
      }
      return false;
    },
    handlePointerDown: function (event) {
      if (!event.target.matches('[data-wdc-dpd-date-pickup]')) return false;
      openNativeDatePicker(event.target);
      return true;
    },
    handleFocus: function (event) {
      if (event.target.matches('[data-wdc-dpd-date-pickup]')) {
        openNativeDatePicker(event.target);
        return true;
      }
      if (event.target.matches('[data-wdc-dpd-contact-fio]')) {
        showDpdContactHistory(event.target);
        return true;
      }
      return false;
    },
    afterAddressNormalized: function (context) {
      const form = context && context.form;
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'dpd') return false;
      const snapshot = context.snapshot || {};
      syncDpdAddressFields(form, snapshot);
      if (context.status) {
        context.status.textContent = snapshot.success
          ? 'Данные для DPD корректны'
          : (snapshot.message || 'Адрес не подтвержден DPD, предпросмотр payload заблокирован.');
      }
      return true;
    },
    afterAddressReset: function (context) {
      const form = context && context.form;
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'dpd') return false;
      syncDpdAddressFields(form, {});
      return true;
    },
    handleCreateResponse: function (context) {
      const payload = context && context.payload ? context.payload : {};
      const statusPayload = context && context.statusPayload ? context.statusPayload : {};
      const text = context && context.presentation ? context.presentation : {};
      if (payload.data && payload.data.registration_attempt_id) {
        submitDpdRegistration(context.form, payload.data.registration_attempt_id, context.box, context.updateButton);
        return true;
      }
      if (text.autoPollRegistration === '1' && statusPayload.carrier_key === 'dpd' && statusPayload.polling_continue && context.updateButton && !context.updateButton.disabled) {
        startDpdRegistrationPolling(context.updateButton);
        return true;
      }
      return false;
    },
    renderStatus: function (context) {
      const box = context && context.box;
      const status = context && context.status ? context.status : {};
      if (!box) return false;
      const summary = box.querySelector('[data-wdc-dpd-places-summary]');
      const label = box.querySelector('[data-wdc-dpd-places-label]');
      const row = box.querySelector('[data-wdc-dpd-places-row]');
      if (summary) summary.textContent = status.dpd_places_summary || '';
      if (label) label.textContent = status.dpd_places_label || 'Грузоместа DPD';
      if (row) row.hidden = !String(status.dpd_places_summary || '').trim();
      return false;
    },
    resetStatusUi: function (context) {
      const box = context && context.box;
      if (!box) return false;
      const summary = box.querySelector('[data-wdc-dpd-places-summary]');
      const row = box.querySelector('[data-wdc-dpd-places-row]');
      if (summary) summary.textContent = '';
      if (row) row.hidden = true;
      return false;
    }
  });

  function updateDpdContactHistory(value, operation) {
    const data = new FormData();
    data.append('action', window.wdcShipmentsAdmin.dpdCourierContactHistoryAction || 'wdc_dpd_courier_contact_history');
    data.append('nonce', window.wdcShipmentsAdmin.nonce);
    data.append('operation', operation || 'add');
    data.append('value', value || '');
    return fetch(window.wdcShipmentsAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    }).then(parseShipmentJsonResponse).then((payload) => {
      if (payload && payload.success && payload.data && Array.isArray(payload.data.history)) {
        setDpdContactHistory(payload.data.history);
      }
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
    startShipmentRegistrationPolling(button, { interval: 10000, maxAttempts: 0, mode: 'registration', stopOnError: true });
  }

  function setDpdDocumentsButtonState(link, busy, label) {
    if (!link) return;
    const originalText = link.getAttribute('data-wdc-original-label') || link.textContent || 'Скачать документы';
    link.setAttribute('data-wdc-original-label', originalText);
    if (busy) {
      link.setAttribute('aria-disabled', 'true');
      link.classList.add('is-busy', 'wdc-cdek-barcode-download--busy');
      link.textContent = label || 'Формируем документы...';
    } else {
      link.classList.remove('is-busy', 'wdc-cdek-barcode-download--busy');
      link.removeAttribute('aria-disabled');
      link.textContent = originalText;
    }
  }

  function dpdDocumentsFilenameFromDisposition(disposition) {
    const fallback = 'dpd-documents.zip';
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

  function triggerDpdDocumentsDownload(downloadUrl) {
    downloadUrl = String(downloadUrl || '').replace(/&amp;/g, '&');
    if (!downloadUrl) return Promise.reject(new Error('Не удалось скачать документы DPD.'));
    return fetch(downloadUrl, {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then((response) => {
        if (!response.ok) {
          return response.text().catch(function () { return ''; }).then(function (text) {
            const message = String(text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(message || 'Не удалось скачать документы DPD.');
          });
        }
        const contentType = response.headers.get('Content-Type') || response.headers.get('content-type') || '';
        const normalizedType = contentType.toLowerCase();
        if (normalizedType && normalizedType.indexOf('application/zip') === -1 && normalizedType.indexOf('application/octet-stream') === -1) {
          throw new Error('Сервер вернул не ZIP-файл документов DPD.');
        }
        const filename = dpdDocumentsFilenameFromDisposition(response.headers.get('Content-Disposition') || response.headers.get('content-disposition') || '');
        return response.blob().then((blob) => ({ blob, filename }));
      })
      .then((download) => {
        if (!download.blob || download.blob.size <= 0) {
          throw new Error('Не удалось скачать документы DPD.');
        }
        const objectUrl = URL.createObjectURL(download.blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = download.filename || 'dpd-documents.zip';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(function () {
          URL.revokeObjectURL(objectUrl);
        }, 30000);
      });
  }

  function requestDpdDocumentsDownload(link) {
    if (!link || link.classList.contains('is-busy')) return;
    const box = link.closest('[data-wdc-shipments-metabox]');
    setDpdDocumentsButtonState(link, true, 'Формируем документы...');
    triggerDpdDocumentsDownload(link.dataset.downloadUrl || link.href || '')
      .then(function () {
        window.clearTimeout(link._wdcDocumentsResetTimer);
        link._wdcDocumentsResetTimer = window.setTimeout(function () {
          setDpdDocumentsButtonState(link, false);
        }, CDEK_BARCODE_RESET_MS);
      })
      .catch(function (error) {
        setDpdDocumentsButtonState(link, false);
        showShipmentToast(box, error && error.message ? error.message : 'Не удалось скачать документы DPD.', 'error');
      });
  }

