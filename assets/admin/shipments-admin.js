(function () {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeShipmentAdmin);
  } else {
    initializeShipmentAdmin();
  }
})();
