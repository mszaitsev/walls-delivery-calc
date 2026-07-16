  const timers = new WeakMap();
  const toastTimers = new WeakMap();
  const shipmentPollingTimers = new WeakMap();
  const shipmentPollingTokens = new WeakMap();
  const shipmentCarrierHooks = [];
  const formSelector = '[data-wdc-shipment-form], .wdc-shipment-form';

  function registerShipmentCarrierHooks(hooks) {
    if (hooks && typeof hooks === 'object') shipmentCarrierHooks.push(hooks);
  }

  function carrierCreateAvailability(form, deliveryType) {
    return shipmentCarrierHooks.reduce(function (ready, hooks) {
      if (!ready || typeof hooks.createAvailability !== 'function') return ready;
      return hooks.createAvailability(form, deliveryType) !== false;
    }, true);
  }

  function dispatchShipmentCarrierHook(name) {
    const args = Array.prototype.slice.call(arguments, 1);
    for (let i = 0; i < shipmentCarrierHooks.length; i += 1) {
      const hooks = shipmentCarrierHooks[i];
      if (!hooks || typeof hooks[name] !== 'function') continue;
      if (hooks[name].apply(hooks, args) === true) return true;
    }
    return false;
  }

  function findShipmentContainer(element) {
    if (!element || !element.closest) return null;
    const direct = element.closest(formSelector);
    if (direct) return direct;
    const modal = element.closest('[data-wdc-shipment-modal], .wdc-shipment-modal');
    if (modal) return modal.querySelector(formSelector);
    const box = element.closest('[data-wdc-shipments-metabox]');
    return box ? box.querySelector(formSelector) : null;
  }

  function findShipmentForm(element) {
    return findShipmentContainer(element);
  }

  function findPlacesContainer(element) {
    if (!element || !element.closest) return null;
    const direct = element.closest('[data-wdc-places]');
    if (direct) return direct;
    const box = element.closest('[data-wdc-shipments-metabox]');
    return box ? box.querySelector('[data-wdc-places]') : null;
  }

  function collectShipmentData(container) {
    const data = new FormData();
    container.querySelectorAll('input, select, textarea').forEach((field) => {
      if (!field.name || field.disabled) return;
      if (field.closest('[data-wdc-declared-value-field][hidden]')) return;
      if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
      data.append(field.name, field.value);
    });
    return data;
  }

  function cleanDecimalInput(input, precision) {
    const raw = String(input.value || '');
    const separatorMatch = raw.match(/[.,]/);
    const separator = separatorMatch ? separatorMatch[0] : '';
    const cleaned = raw.replace(/[^\d.,]+/g, '');
    const separatorIndex = separator ? cleaned.search(/[.,]/) : -1;
    if (separatorIndex === -1) {
      input.value = cleaned.replace(/[.,]/g, '');
      return;
    }
    const integer = cleaned.slice(0, separatorIndex).replace(/[.,]/g, '');
    const decimal = cleaned.slice(separatorIndex + 1).replace(/[.,]/g, '').slice(0, precision);
    input.value = integer + separator + decimal;
  }

  function parseDecimalValue(value) {
    return parseFloat(String(value || '0').replace(',', '.')) || 0;
  }

  function parseShipmentJsonResponse(response) {
    return response.text().then((text) => {
      try {
        return text ? JSON.parse(text) : null;
      } catch (error) {
        const controlled = new Error('Сервер вернул некорректный ответ при подготовке отправления. Проверьте журнал ошибок.');
        controlled.httpStatus = response && response.status ? response.status : 0;
        throw controlled;
      }
    });
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function fieldValue(form, selector) {
    const field = form && form.querySelector(selector);
    return field ? String(field.value || '').trim() : '';
  }

  function showShipmentToast(box, text, type, options) {
    const settings = Object.assign({ append: false, persist: false }, options || {});
    const host = box || document.body;
    let toast = host.querySelector ? host.querySelector('[data-wdc-shipment-toast]') : null;
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'wdc-shipment-toast';
      toast.setAttribute('data-wdc-shipment-toast', '1');
      host.appendChild(toast);
    }
    const previous = toastTimers.get(toast);
    if (previous) window.clearTimeout(previous);
    toast.dataset.status = type || 'success';
    if (settings.append && !toast.hidden && toast.textContent) {
      toast.textContent = toast.textContent + '\n' + text;
    } else {
      toast.textContent = text;
    }
    toast.hidden = false;
    if (settings.persist) {
      toastTimers.delete(toast);
    } else {
      toastTimers.set(toast, window.setTimeout(function () {
        toast.hidden = true;
      }, 10000));
    }
    return toast;
  }

  function copyText(text) {
    const value = String(text || '');
    if (!value) return Promise.reject(new Error('Нет номера для копирования.'));
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(value);
    }
    return new Promise((resolve, reject) => {
      const textarea = document.createElement('textarea');
      textarea.value = value;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.left = '-9999px';
      document.body.appendChild(textarea);
      textarea.select();
      try {
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        ok ? resolve() : reject(new Error('Не удалось скопировать номер.'));
      } catch (error) {
        document.body.removeChild(textarea);
        reject(error);
      }
    });
  }

