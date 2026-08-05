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
    if (idField && warehouse.warehouseId) {
      idField.value = warehouse.warehouseId;
      idField.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (title) {
      title.textContent = warehouse.title || warehouse.divisionName || warehouse.branchName || warehouse.warehouseId || '';
    }
    root.dispatchEvent(new CustomEvent('wdc:shipment-carrier-field-change', {
      bubbles: true,
      detail: { carrier: 'pek', field: 'sender_warehouse' }
    }));
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
        root.dispatchEvent(new CustomEvent('wdc:shipment-pickup-search-open', {
          bubbles: true,
          detail: { carrier: 'pek', purpose: 'sender_warehouse' }
        }));
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
