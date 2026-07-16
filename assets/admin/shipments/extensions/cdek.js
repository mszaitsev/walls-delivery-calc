  function updateCdekDeliveryModeUi(form) {
    const mode = selectedDeliveryMode(form);
    const commentRow = form.querySelector('[data-wdc-cdek-courier-comment-row]');
    if (commentRow) commentRow.hidden = ![1, 3].includes(mode);
    const senderDoor = form.querySelector('[data-wdc-cdek-sender-door]');
    const senderWarehouse = form.querySelector('[data-wdc-cdek-sender-warehouse]');
    if (senderDoor) senderDoor.hidden = ![1, 2].includes(mode);
    if (senderWarehouse) senderWarehouse.hidden = [1, 2].includes(mode);
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

