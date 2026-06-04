(function () {
  function serialize(form) {
    return new FormData(form);
  }

  function updatePreview(form) {
    const data = {};
    new FormData(form).forEach((value, key) => {
      data[key] = value;
    });
    const preview = form.querySelector('[data-wdc-shipment-preview]');
    if (preview) {
      preview.textContent = JSON.stringify(data, null, 2);
    }
  }

  function renumberPlaces(container) {
    container.querySelectorAll('[data-wdc-place]').forEach((row, index) => {
      row.querySelectorAll('input').forEach((input) => {
        input.name = input.name.replace(/places\[\d+\]/, 'places[' + index + ']');
      });
    });
  }

  document.addEventListener('click', function (event) {
    const open = event.target.closest('[data-wdc-open-shipment-modal]');
    if (open) {
      const box = open.closest('[data-wdc-shipments-metabox]');
      const modal = box && box.querySelector('[data-wdc-shipment-modal]');
      if (modal) modal.hidden = false;
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
      const form = add.closest('form');
      const container = form.querySelector('[data-wdc-places]');
      const first = container.querySelector('[data-wdc-place]');
      if (!first) return;
      const clone = first.cloneNode(true);
      clone.querySelectorAll('input').forEach((input) => {
        if (input.name.includes('declared_value_kopecks')) input.value = '0';
      });
      container.appendChild(clone);
      renumberPlaces(container);
      updatePreview(form);
      return;
    }

    const remove = event.target.closest('[data-wdc-remove-place]');
    if (remove) {
      const container = remove.closest('[data-wdc-places]');
      if (container.querySelectorAll('[data-wdc-place]').length > 1) {
        remove.closest('[data-wdc-place]').remove();
        renumberPlaces(container);
      }
      updatePreview(remove.closest('form'));
      return;
    }

    const pickupMap = event.target.closest('[data-wdc-admin-pickup-map]');
    if (pickupMap) {
      alert('Выбор ПВЗ на карте в админке будет подключен к общей карте отдельным этапом. Сейчас код ПВЗ можно скорректировать вручную.');
    }
  });

  document.addEventListener('input', function (event) {
    const form = event.target.closest('[data-wdc-shipment-form]');
    if (form) updatePreview(form);
  });

  document.addEventListener('submit', function (event) {
    const form = event.target.closest('[data-wdc-shipment-form]');
    if (!form) return;
    event.preventDefault();
    const errors = form.querySelector('[data-wdc-shipment-errors]');
    if (errors) errors.textContent = '';
    const data = serialize(form);
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
        if (errors) errors.textContent = payload.data.message + ' Barcode: ' + (payload.data.tracking_number || '-');
        window.setTimeout(function () {
          window.location.reload();
        }, 800);
      })
      .catch((error) => {
        if (errors) errors.textContent = error.message;
      });
  });
})();
