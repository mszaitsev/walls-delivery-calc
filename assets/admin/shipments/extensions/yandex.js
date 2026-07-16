  function syncYandexAddressFields(form, snapshot) {
    const fields = snapshot && snapshot.fields ? snapshot.fields : {};
    const mapped = {
      postal_code: fields.postal_code || '',
      region: fields.region || '',
      locality: fields.locality || '',
      street: fields.street || '',
      house: fields.house || '',
      room: fields.room || ''
    };
    Object.keys(mapped).forEach((key) => {
      const input = form.querySelector('[data-wdc-yandex-address-field="' + key + '"]');
      if (input) input.value = mapped[key] || '';
    });
    const full = form.querySelector('[data-wdc-yandex-address-field="full_address"]');
    if (full) full.value = fields.full_address || snapshot.display || '';
  }

  function yandexSourceDropoffContext(form) {
    const locationId = fieldValue(form, '[data-wdc-yandex-source-location-id]');
    return {
      carrierKey: 'yandex_delivery',
      serviceKey: 'yandex_delivery',
      pickupFamily: 'yandex_delivery:source_dropoff',
      purpose: 'source_dropoff',
      sourceLocationId: locationId,
      sourcePlatformStationId: fieldValue(form, '[data-wdc-yandex-source-station-id]'),
      radiusKm: '',
      city: '',
      cityId: '',
      region: '',
      postcode: '',
      address: locationId ? '' : (fieldValue(form, '[data-wdc-yandex-source-dropoff-address-input]') || fieldValue(form, '[data-wdc-yandex-source-station-id]')),
      fiasId: '',
      garId: '',
      locationId: locationId,
      lat: fieldValue(form, '[data-wdc-yandex-source-lat]'),
      lng: fieldValue(form, '[data-wdc-yandex-source-lng]')
    };
  }

  function isYandexSourceDropoffContext(context) {
    return context && context.carrierKey === 'yandex_delivery' && context.purpose === 'source_dropoff';
  }

  function pickupAddressSearchContext(context) {
    const carrier = context.carrierKey || context.carrier || '';
    const result = {
      carrier: carrier,
      carrier_key: carrier,
      service_key: context.serviceKey || '',
      pickup_family: context.pickupFamily || '',
      purpose: context.purpose || '',
      country_code: 'RU'
    };
    if (!isYandexSourceDropoffContext(context)) {
      result.location_id = context.locationId || '';
    } else {
      result.include_points = false;
    }
    return result;
  }

  function setYandexSourceDropoffWarning(form, message) {
    const warning = form.querySelector('[data-wdc-yandex-source-dropoff-warning]');
    if (!warning) return;
    warning.textContent = message || '';
    warning.hidden = !message;
  }

  function updateYandexSourceDropoffDraft(form, point, overridden) {
    const code = pickupCode(point) || String(point && point.platform_station_id || '');
    const title = pickupPointTitle(point) || code;
    const address = String(point && point.address || '');
    const workTime = String(point && (point.work_time || point.schedule_text) || '');
    const idInput = form.querySelector('[data-wdc-yandex-source-station-id]');
    const overriddenInput = form.querySelector('[data-wdc-yandex-source-station-overridden]');
    const titleInput = form.querySelector('[data-wdc-yandex-source-dropoff-title-input]');
    const addressInput = form.querySelector('[data-wdc-yandex-source-dropoff-address-input]');
    const workTimeInput = form.querySelector('[data-wdc-yandex-source-dropoff-work-time-input]');
    const latInput = form.querySelector('[data-wdc-yandex-source-lat]');
    const lngInput = form.querySelector('[data-wdc-yandex-source-lng]');
    if (idInput) idInput.value = code;
    if (overriddenInput) overriddenInput.value = overridden ? '1' : '0';
    if (titleInput) titleInput.value = title;
    if (addressInput) addressInput.value = address;
    if (workTimeInput) workTimeInput.value = workTime;
    if (latInput) latInput.value = point && point.lat !== null && point.lat !== undefined ? String(point.lat) : '';
    if (lngInput) lngInput.value = point && point.lng !== null && point.lng !== undefined ? String(point.lng) : '';
    const titleDisplay = form.querySelector('[data-wdc-yandex-source-dropoff-title]');
    const addressDisplay = form.querySelector('[data-wdc-yandex-source-dropoff-address]');
    const workTimeDisplay = form.querySelector('[data-wdc-yandex-source-dropoff-work-time]');
    if (titleDisplay) titleDisplay.textContent = title || '-';
    if (addressDisplay) addressDisplay.textContent = address || code || '-';
    if (workTimeDisplay) {
      workTimeDisplay.textContent = workTime;
      workTimeDisplay.hidden = !workTime;
    }
    const reset = form.querySelector('[data-wdc-reset-yandex-source-dropoff]');
    if (reset) reset.hidden = !overridden;
    setYandexSourceDropoffWarning(form, '');
    requestPreview(form);
  }

  function resetYandexSourceDropoff(form) {
    const box = form.querySelector('[data-wdc-yandex-source-dropoff]');
    if (!box) return;
    updateYandexSourceDropoffDraft(form, {
      point_code: box.dataset.defaultId || '',
      platform_station_id: box.dataset.defaultId || '',
      display_title: box.dataset.defaultTitle || box.dataset.defaultId || '',
      address: box.dataset.defaultAddress || '',
      work_time: box.dataset.defaultWorkTime || '',
      lat: box.dataset.defaultLat || '',
      lng: box.dataset.defaultLng || ''
    }, false);
  }

  function setYandexLabelButtonState(link, busy, label) {
    if (!link) return;
    const originalText = link.getAttribute('data-wdc-original-label') || link.textContent || 'Скачать ярлык';
    link.setAttribute('data-wdc-original-label', originalText);
    if (busy) {
      link.setAttribute('aria-disabled', 'true');
      link.classList.add('is-busy', 'wdc-cdek-barcode-download--busy');
      link.textContent = label || 'Скачиваем ярлык...';
    } else {
      link.classList.remove('is-busy', 'wdc-cdek-barcode-download--busy');
      link.removeAttribute('aria-disabled');
      link.textContent = originalText;
    }
  }

  function yandexLabelFilenameFromDisposition(disposition) {
    const fallback = 'yandex-label.pdf';
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

  function triggerYandexLabelDownload(downloadUrl) {
    downloadUrl = String(downloadUrl || '').replace(/&amp;/g, '&');
    if (!downloadUrl) return Promise.reject(new Error('Не удалось получить ярлык Яндекс.Доставки.'));
    return fetch(downloadUrl, {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then((response) => {
        if (!response.ok) {
          return response.text().catch(function () { return ''; }).then(function (text) {
            const message = String(text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(message || 'Не удалось получить ярлык Яндекс.Доставки.');
          });
        }
        const contentType = response.headers.get('Content-Type') || response.headers.get('content-type') || '';
        if (contentType && contentType.toLowerCase().indexOf('application/pdf') === -1) {
          throw new Error('Яндекс.Доставка вернула ответ, который не является PDF-файлом.');
        }
        const filename = yandexLabelFilenameFromDisposition(response.headers.get('Content-Disposition') || response.headers.get('content-disposition') || '');
        return response.blob().then((blob) => ({ blob, filename }));
      })
      .then((download) => {
        if (!download.blob || download.blob.size <= 0) {
          throw new Error('Яндекс.Доставка вернула пустой файл ярлыка.');
        }
        const objectUrl = URL.createObjectURL(download.blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = download.filename || 'yandex-label.pdf';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(function () {
          URL.revokeObjectURL(objectUrl);
        }, 30000);
      });
  }

  function requestYandexLabelDownload(link) {
    if (!link || link.classList.contains('is-busy')) return;
    const box = link.closest('[data-wdc-shipments-metabox]');
    setYandexLabelButtonState(link, true, 'Скачиваем ярлык...');
    triggerYandexLabelDownload(link.dataset.downloadUrl || link.href || '')
      .then(function () {
        window.clearTimeout(link._wdcYandexLabelResetTimer);
        link._wdcYandexLabelResetTimer = window.setTimeout(function () {
          setYandexLabelButtonState(link, false);
        }, CDEK_BARCODE_RESET_MS);
      })
      .catch(function (error) {
        setYandexLabelButtonState(link, false);
        showShipmentToast(box, error && error.message ? error.message : 'Не удалось получить ярлык Яндекс.Доставки.', 'error');
      });
  }

  const cancellationPollingToasts = new WeakMap();

  function isCancellationPollingPurpose(value) {
    return String(value || '') === 'cancellation';
  }

  function cancellationPollingProgressMessage(attempt, maxAttempts) {
    const current = Math.max(0, parseInt(attempt, 10) || 0);
    const total = Math.max(1, parseInt(maxAttempts, 10) || 14);
    if (current <= 0) {
      return 'Запрос на отмену отправления Яндекс отправлен. Проведено: 0/' + total + ' проверок отмены.';
    }
    return 'Статус отмены пока не получен. Проведено: ' + current + '/' + total + ' проверок отмены.';
  }

  function cancellationPollingExhaustedMessage(attempt, maxAttempts) {
    const total = Math.max(1, parseInt(maxAttempts, 10) || parseInt(attempt, 10) || 14);
    return 'Статус отмены пока не получен. Проведено: ' + total + '/' + total + ' проверок отмены. Повторите обновление статуса позднее.';
  }

  function initCancellationPollingToast(box, token, maxAttempts) {
    if (!box || !token) return;
    const existing = cancellationPollingToasts.get(box);
    if (existing && existing.timer) {
      window.clearTimeout(existing.timer);
    }
    const toast = showShipmentToast(box, cancellationPollingProgressMessage(0, maxAttempts), 'warning', { append: true, persist: true });
    cancellationPollingToasts.set(box, {
      element: toast,
      token: token,
      timer: null
    });
  }

  function updateCancellationPollingToast(box, token, message, type, persist) {
    if (!box) return;
    const state = cancellationPollingToasts.get(box);
    if (!state || state.token !== token || !state.element) {
      if (persist === false) {
        showShipmentToast(box, message, type || 'warning', { append: true });
      }
      return;
    }
    state.element.textContent = message;
    state.element.dataset.status = type || 'warning';
    state.element.className = state.element.className.replace(/\s*wdc-shipment-toast--(success|warning|error|info)/g, '');
    state.element.classList.add('wdc-shipment-toast--' + (type || 'warning'));
    if (state.timer) {
      window.clearTimeout(state.timer);
      state.timer = null;
    }
    if (persist === false) {
      state.timer = window.setTimeout(function () {
        if (state.element && state.element.parentNode) {
          state.element.parentNode.removeChild(state.element);
        }
        cancellationPollingToasts.delete(box);
      }, 6000);
      cancellationPollingToasts.set(box, state);
    }
  }

  function clearCancellationPollingToast(box) {
    const state = box ? cancellationPollingToasts.get(box) : null;
    if (!state) return;
    if (state.timer) {
      window.clearTimeout(state.timer);
    }
    if (state.element && state.element.parentNode) {
      state.element.parentNode.removeChild(state.element);
    }
    cancellationPollingToasts.delete(box);
  }

  function renderYandexSelfPickupCode(box, status) {
    if (!box) return false;
    const row = box.querySelector('[data-wdc-yandex-self-pickup-code-row]');
    const value = box.querySelector('[data-wdc-yandex-self-pickup-code]');
    if (!row || !value) return false;
    const code = String(status && status.yandex_self_pickup_node_code || '').trim();
    value.textContent = code;
    row.hidden = !code;
    return false;
  }

  registerShipmentCarrierHooks({
    handleClick: function (event) {
      const yandexLabelDownload = event.target.closest('[data-wdc-yandex-label-download]');
      if (yandexLabelDownload) {
        event.preventDefault();
        requestYandexLabelDownload(yandexLabelDownload);
        return true;
      }

      const openYandexSourceDropoffPicker = event.target.closest('[data-wdc-open-yandex-source-dropoff-picker]');
      if (openYandexSourceDropoffPicker) {
        const form = findShipmentForm(openYandexSourceDropoffPicker);
        if (form) {
          const context = yandexSourceDropoffContext(form);
          createPickupPicker(form, {
            sender: true,
            title: 'Выбор ПВЗ отправления Яндекс',
            context: context,
            onChoose: function (point) {
              updateYandexSourceDropoffDraft(form, point, true);
            }
          });
        }
        return true;
      }

      const resetYandexSourceDropoffButton = event.target.closest('[data-wdc-reset-yandex-source-dropoff]');
      if (resetYandexSourceDropoffButton) {
        const form = findShipmentForm(resetYandexSourceDropoffButton);
        if (form) resetYandexSourceDropoff(form);
        return true;
      }

      return false;
    },
    afterAddressNormalized: function (context) {
      const form = context && context.form;
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'yandex_delivery') return false;
      const snapshot = context.snapshot || {};
      syncYandexAddressFields(form, snapshot);
      if (context.status) {
        context.status.textContent = snapshot.success
          ? 'Адрес обработан.'
          : (snapshot.message || 'Адрес не подтвержден, создание отправления заблокировано.');
      }
      return true;
    },
    afterAddressReset: function (context) {
      const form = context && context.form;
      if (!form || fieldValue(form, 'input[name="carrier_key"]') !== 'yandex_delivery') return false;
      syncYandexAddressFields(form, {});
      return true;
    },
    renderStatus: function (context) {
      const box = context && context.box;
      const status = context && context.status ? context.status : {};
      return renderYandexSelfPickupCode(box, status);
    },
    resetStatusUi: function (context) {
      const box = context && context.box;
      if (!box) return false;
      const row = box.querySelector('[data-wdc-yandex-self-pickup-code-row]');
      const value = box.querySelector('[data-wdc-yandex-self-pickup-code]');
      if (value) value.textContent = '';
      if (row) row.hidden = true;
      return false;
    },
    handlePollingStart: function (context) {
      const settings = context && context.settings ? context.settings : {};
      if (!isCancellationPollingPurpose(settings.purpose || settings.mode)) return false;
      initCancellationPollingToast(context.box, context.token, context.maxAttempts || 14);
      return true;
    },
    handlePollingStatus: function (context) {
      const statusPayload = context && context.statusPayload ? context.statusPayload : {};
      const settings = context && context.settings ? context.settings : {};
      if (statusPayload.carrier_key !== 'yandex_delivery' && !isCancellationPollingPurpose(settings.pollPurpose || settings.purpose || settings.mode)) {
        return false;
      }

      if (isCancellationPollingPurpose(settings.pollPurpose || settings.purpose || settings.mode)) {
        const rawStatus = String(statusPayload.yandex_status || statusPayload.carrier_status_title || '').trim();
        if (context.pending) {
          updateCancellationPollingToast(
            context.box,
            context.token,
            cancellationPollingProgressMessage(context.attempt, context.maxAttempts),
            'warning',
            true
          );
          return true;
        }
        if (rawStatus && rawStatus !== 'CANCELLED') {
          updateCancellationPollingToast(
            context.box,
            context.token,
            'Отмена не выполнена. Получен статус Яндекс: ' + rawStatus + '.',
            'warning',
            false
          );
          return true;
        }
        updateCancellationPollingToast(context.box, context.token, 'Отправление Яндекс отменено.', 'success', false);
        return true;
      }

      if (context.settings && context.settings.auto && !context.pending && statusPayload.carrier_status_title) {
        showShipmentToast(context.box, 'Статус отправления Яндекс получен: ' + statusPayload.carrier_status_title, 'success', { append: true });
        return true;
      }
      return false;
    },
    handlePollingError: function (context) {
      const settings = context && context.settings ? context.settings : {};
      if (!isCancellationPollingPurpose(settings.pollPurpose || settings.purpose || settings.mode)) return false;
      updateCancellationPollingToast(
        context.box,
        context.token,
        cancellationPollingProgressMessage(context.attempt, context.maxAttempts),
        'warning',
        true
      );
      return true;
    },
    handlePollingExhausted: function (context) {
      const button = context && context.button;
      if (!button || !button.dataset || !isCancellationPollingPurpose(button.dataset.pollPurpose || '')) return false;
      updateCancellationPollingToast(
        context.box,
        context.token,
        cancellationPollingExhaustedMessage(context.attempt, context.maxAttempts),
        'warning',
        false
      );
      return true;
    },
    cancelledAndRemoved: function (context) {
      const settings = context && context.settings ? context.settings : {};
      if (!isCancellationPollingPurpose(settings.pollPurpose || settings.purpose || settings.mode)) return false;
      updateCancellationPollingToast(context.box, context.token, 'Отправление Яндекс отменено.', 'success', false);
      return true;
    },
    handlePollingStop: function (context) {
      clearCancellationPollingToast(context && context.box);
      return false;
    }
  });
