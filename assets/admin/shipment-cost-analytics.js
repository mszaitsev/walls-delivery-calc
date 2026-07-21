(function () {
  'use strict';

  function rangesFromForm(form) {
    if (!form || !form.dataset) return {};
    try {
      const ranges = JSON.parse(form.dataset.wdcAnalyticsRanges || '{}');
      return ranges && typeof ranges === 'object' ? ranges : {};
    } catch (error) {
      return {};
    }
  }

  function applyFixedRange(form) {
    const period = form.querySelector('[data-wdc-analytics-period]');
    const dateFrom = form.querySelector('[data-wdc-analytics-date-from]');
    const dateTo = form.querySelector('[data-wdc-analytics-date-to]');
    if (!period || !dateFrom || !dateTo) return;
    if (String(period.value || '') === 'custom') return;
    const range = rangesFromForm(form)[period.value];
    if (!range) return;
    if (range.date_from) dateFrom.value = String(range.date_from);
    if (range.date_to) dateTo.value = String(range.date_to);
  }

  function bindForm(form) {
    const period = form.querySelector('[data-wdc-analytics-period]');
    if (!period) return;
    period.addEventListener('change', function () {
      applyFixedRange(form);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-wdc-shipment-cost-filters]').forEach(bindForm);
  });
})();
