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
    var title = root.querySelector('[data-wdc-pek-sender-warehouse-title]');
    var address = root.querySelector('[data-wdc-pek-sender-warehouse-address]');
    if (idField && warehouse.warehouseId) {
      idField.value = warehouse.warehouseId;
      idField.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (title) {
      title.textContent = warehouse.title || warehouse.divisionName || warehouse.branchName || warehouse.warehouseId || '';
    }
    if (address) {
      address.textContent = warehouse.address || '';
    }
    root.dispatchEvent(new CustomEvent('wdc:shipment-carrier-field-change', {
      bubbles: true,
      detail: { carrier: 'pek', field: 'sender_warehouse' }
    }));
  }

  function senderWarehouseContext(form) {
    return {
      carrierKey: 'pek',
      serviceKey: 'pek',
      pickupFamily: 'pek:sender_warehouse',
      countryCode: 'RU',
      address: '',
      purpose: 'sender_warehouse'
    };
  }

  register({
    carrierKey: 'pek',
    onModalReady: function (context) {
      var root = context && context.root ? context.root : document;
      var button = root.querySelector('[data-wdc-pek-open-sender-warehouse-picker]');
      if (!button) {
        return;
      }
      button.addEventListener('click', function () {
        var picker = window.wdcShipmentPickupPicker;
        var form = button.closest('form') || root.querySelector('form') || root;
        if (!picker || typeof picker.open !== 'function') {
          return;
        }
        picker.open(form, {
          sender: true,
          title: 'Выбор склада самопривоза ПЭК',
          context: senderWarehouseContext(form),
          onChoose: function (point) {
            updateWarehouseCard(root, {
              warehouseId: point.warehouseId || point.point_code || point.code || '',
              title: point.display_title || point.point_title || point.address || '',
              address: point.address || ''
            });
          }
        });
      });
    },
    onCarrierData: function (context) {
      if (!context || context.carrier !== 'pek') {
        return;
      }
      updateWarehouseCard(context.root || document, context.senderWarehouse || context.warehouse);
    }
  });
}());
