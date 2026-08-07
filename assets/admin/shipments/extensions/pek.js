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
    if (idField && warehouse.warehouseId) {
      idField.value = warehouse.warehouseId;
      idField.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (sourceField && warehouse.warehouseId) {
      sourceField.value = 'shipment_modal_override';
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
    root.dispatchEvent(new CustomEvent('wdc:shipment-carrier-field-change', {
      bubbles: true,
      detail: { carrier: 'pek', field: 'sender_warehouse' }
    }));
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
    }
  });
}());
