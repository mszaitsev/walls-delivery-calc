(function () {
  'use strict';

  var register = window.wdcRegisterShipmentCarrierHooks || window.registerShipmentCarrierHooks;
  if (typeof register !== 'function') {
    return;
  }

  function updateWarehouseCard(root, warehouse) {
    if (!root || !warehouse) {
      return;
    }
    var idField = root.querySelector('[data-wdc-pek-sender-warehouse-id]');
    var sourceField = root.querySelector('[data-wdc-pek-sender-warehouse-source]');
    var context = root.querySelector('[data-wdc-pek-sender-warehouse-context]');
    var title = root.querySelector('[data-wdc-pek-sender-warehouse-title]');
    var address = root.querySelector('[data-wdc-pek-sender-warehouse-address]');
    if (!isCanonicalWarehouseId(warehouse.warehouseId || '')) {
      return;
    }
    if (sourceField) {
      sourceField.value = 'shipment_modal_override';
    }
    if (idField) {
      idField.value = String(warehouse.warehouseId).trim().toLowerCase();
    }
    if (title) {
      title.textContent = warehouse.title || warehouse.divisionName || warehouse.branchName || warehouse.warehouseId || '';
    }
    if (address) {
      address.textContent = warehouse.address || '';
    }
    if (context) {
      context.setAttribute('data-warehouse-id', warehouse.warehouseId || '');
      context.setAttribute('data-branch-title', warehouse.branchName || '');
      context.setAttribute('data-division-title', warehouse.divisionName || warehouse.title || '');
      context.setAttribute('data-address', warehouse.address || '');
      context.setAttribute('data-latitude', warehouse.latitude || warehouse.lat || '');
      context.setAttribute('data-longitude', warehouse.longitude || warehouse.lng || '');
    }
    if (idField) {
      idField.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function senderWarehouseContext(form, root) {
    var holder = root && root.querySelector ? root.querySelector('[data-wdc-pek-sender-warehouse-context]') : null;
    var data = holder ? holder.dataset : {};
    var city = data.divisionTitle || data.branchTitle || '';
    var address = data.address || '';
    return {
      carrierKey: 'pek',
      serviceKey: 'pek',
      pickupFamily: 'pek:sender_warehouse',
      countryCode: 'RU',
      purpose: 'sender_warehouse',
      city: city,
      address: address,
      lat: data.latitude || '',
      lng: data.longitude || '',
      warehouseId: data.warehouseId || ''
    };
  }

  function isCanonicalWarehouseId(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(String(value || '').trim().toLowerCase());
  }

  function smsStageLabel(stage) {
    var labels = {
      sms_geography: 'Проверка доступности услуги по направлению',
      sms_private_token: 'Получение приватного токена',
      sms_connected_services: 'Проверка подключённых услуг контрагента',
      sms_service_contract: 'Проверка ответа об услуге СМС',
      sms_limit_contract: 'Проверка лимита выдачи по СМС',
      sms_business_unavailable: 'Услуга недоступна по условиям ПЭК',
      completed: 'Проверка выполнена'
    };
    return labels[String(stage || '')] || 'Неизвестный этап';
  }

  function dash(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    return String(value);
  }

  function diagnosticEndpoint(diagnostic) {
    var method = String(diagnostic.method || '').trim();
    var endpoint = String(diagnostic.endpoint || '').trim();
    if (!method && !endpoint) {
      return '—';
    }
    return (method ? method + ' ' : '') + (endpoint || '—');
  }

  function appendDiagnosticRow(list, label, value) {
    var term = document.createElement('dt');
    var description = document.createElement('dd');
    term.textContent = label;
    description.textContent = dash(value);
    list.appendChild(term);
    list.appendChild(description);
  }

  function appendFieldErrors(section, fieldErrors) {
    if (!Array.isArray(fieldErrors) || !fieldErrors.length) {
      return;
    }
    var title = document.createElement('div');
    var list = document.createElement('ul');
    title.className = 'wdc-shipment-technical-title';
    title.textContent = 'Ошибки полей ПЭК';
    fieldErrors.forEach(function (item) {
      if (!item || typeof item !== 'object') {
        return;
      }
      var messages = Array.isArray(item.messages) ? item.messages : [];
      if (!messages.length) {
        return;
      }
      var row = document.createElement('li');
      row.textContent = dash(item.field) + ': ' + messages.map(dash).join('; ');
      list.appendChild(row);
    });
    if (list.childNodes.length) {
      section.appendChild(title);
      section.appendChild(list);
    }
  }

  function appendResponseShape(section, responseShape) {
    if (!responseShape || typeof responseShape !== 'object' || Array.isArray(responseShape)) {
      return;
    }
    var keys = Object.keys(responseShape);
    if (!keys.length) {
      return;
    }
    var title = document.createElement('div');
    var pre = document.createElement('pre');
    title.className = 'wdc-shipment-technical-title';
    title.textContent = 'Response shape';
    pre.textContent = JSON.stringify(responseShape, null, 2);
    section.appendChild(title);
    section.appendChild(pre);
  }

  function renderSmsDiagnostic(context) {
    if (!context || !context.form || !context.preview || !context.preview.body) {
      return false;
    }
    var errors = context.form.querySelector('[data-wdc-shipment-errors]');
    var diagnostic = context.preview.body.sms_diagnostic;
    if (!errors || !diagnostic || typeof diagnostic !== 'object' || Array.isArray(diagnostic)) {
      return false;
    }
    var status = String(diagnostic.status || '');
    if (status !== 'error' && status !== 'unavailable') {
      return false;
    }
    var section = document.createElement('div');
    var heading = document.createElement('div');
    var list = document.createElement('dl');
    section.className = 'wdc-shipment-technical-diagnostic wdc-shipment-pek-sms-diagnostic';
    heading.className = 'wdc-shipment-technical-title';
    heading.textContent = 'Проверка выдачи по СМС';
    section.appendChild(heading);
    appendDiagnosticRow(list, 'Этап', smsStageLabel(diagnostic.stage));
    appendDiagnosticRow(list, 'Код ошибки', diagnostic.error_code || '');
    appendDiagnosticRow(list, 'Endpoint', diagnosticEndpoint(diagnostic));
    appendDiagnosticRow(list, 'HTTP status', diagnostic.http_status === null ? '' : diagnostic.http_status);
    appendDiagnosticRow(list, 'Ошибка ПЭК', diagnostic.api_error_message || '');
    section.appendChild(list);
    appendFieldErrors(section, diagnostic.field_errors);
    appendResponseShape(section, diagnostic.response_shape);
    errors.appendChild(section);
    return true;
  }

  function openSenderWarehousePicker(root, button) {
    var picker = window.wdcShipmentPickupPicker;
    var form = (button && button.closest && button.closest('form')) || (root && root.querySelector && root.querySelector('form')) || root || document;
    if (!picker || typeof picker.open !== 'function') {
      return true;
    }
    picker.open(form, {
      sender: true,
      title: 'Выбор склада самопривоза ПЭК',
      entitySingular: 'склад',
      entityPlural: 'Склады ПЭК',
      confirmText: 'Выбрать этот склад',
      selectText: 'Выберите склад',
      emptyText: 'Склады ПЭК не найдены',
      codeLabel: 'Warehouse ID',
      context: senderWarehouseContext(form, root),
      onChoose: function (point) {
        var warehouseId = point.warehouseId || '';
        if (!isCanonicalWarehouseId(warehouseId)) {
          window.alert('ПЭК не вернул корректный warehouse ID для выбранного склада.');
          return false;
        }
        updateWarehouseCard(root, {
          warehouseId: String(warehouseId).trim().toLowerCase(),
          title: point.display_title || point.point_title || point.address || '',
          branchName: point.branchName || point.branch_title || '',
          divisionName: point.divisionName || point.division_title || '',
          address: point.address || '',
          latitude: point.latitude || point.lat || '',
          longitude: point.longitude || point.lng || ''
        });
        return true;
      }
    });
    return true;
  }

  register({
    carrierKey: 'pek',
    handleClick: function (event) {
      var button = event && event.target && event.target.closest ? event.target.closest('[data-wdc-pek-open-sender-warehouse-picker]') : null;
      if (!button) {
        return false;
      }
      event.preventDefault();
      return openSenderWarehousePicker(button.closest('[data-wdc-shipment-modal]') || button.closest('form') || document, button);
    },
    onCarrierData: function (context) {
      if (!context || context.carrier !== 'pek') {
        return;
      }
      updateWarehouseCard(context.root || document, context.senderWarehouse || context.warehouse);
    },
    afterPreviewUpdated: function (context) {
      return renderSmsDiagnostic(context);
    }
  });
}());
