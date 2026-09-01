(function () {
  'use strict';

  var register = window.wdcRegisterShipmentCarrierHooks || window.registerShipmentCarrierHooks;
  if (typeof register !== 'function') {
    return;
  }

  var invalidClass = 'wdc-ozon-place--invalid';

  function isOzonForm(form) {
    return !!form && fieldValue(form, 'input[name="carrier_key"]') === 'ozon_delivery';
  }

  function positiveInt(value) {
    var parsed = parseInt(String(value || '').replace(/\D+/g, ''), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
  }

  function positiveDecimal(value) {
    var parsed = parseFloat(String(value || '').replace(',', '.'));
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
  }

  function formatKg(grams) {
    var value = Math.round(Number(grams || 0)) / 1000;
    return String(value.toFixed(3)).replace(/\.?0+$/, '').replace('.', ',') + ' кг';
  }

  function formatCmFromMm(mm) {
    var value = Math.ceil(Number(mm || 0) / 10);
    return String(value) + ' см';
  }

  function formatCmList(values) {
    return values.map(function (value) {
      var rounded = Math.round(Number(value || 0) * 100) / 100;
      return String(rounded).replace(/\.?0+$/, '').replace('.', ',');
    }).join(' × ') + ' см';
  }

  function limitContainer(form) {
    return form ? form.querySelector('[data-wdc-ozon-place-limits]') : null;
  }

  function warningContainer(form) {
    return form ? form.querySelector('[data-wdc-ozon-place-limit-warning]') : null;
  }

  function limitsFromForm(form) {
    var container = limitContainer(form);
    if (!container) return null;
    return {
      pointFound: container.dataset.pointFound === '1',
      minWeightG: positiveInt(container.dataset.minWeightG || ''),
      maxWeightG: positiveInt(container.dataset.maxWeightG || ''),
      maxLengthMm: positiveInt(container.dataset.maxLengthMm || ''),
      maxWidthMm: positiveInt(container.dataset.maxWidthMm || ''),
      maxHeightMm: positiveInt(container.dataset.maxHeightMm || '')
    };
  }

  function placeInput(row, suffix) {
    return row ? row.querySelector('input[name$="[' + suffix + ']"]') : null;
  }

  function readPlaces(form) {
    return Array.from(form.querySelectorAll('[data-wdc-place]')).map(function (row, index) {
      var weight = placeInput(row, 'weight_g');
      var length = placeInput(row, 'length_cm');
      var width = placeInput(row, 'width_cm');
      var height = placeInput(row, 'height_cm');
      return {
        row: row,
        number: index + 1,
        weightG: positiveInt(weight && weight.value),
        lengthCm: positiveDecimal(length && length.value),
        widthCm: positiveDecimal(width && width.value),
        heightCm: positiveDecimal(height && height.value),
        inputs: [weight, length, width, height].filter(Boolean)
      };
    });
  }

  function sortedPositive(values) {
    return values
      .map(function (value) { return Number(value || 0); })
      .filter(function (value) { return value > 0; })
      .sort(function (a, b) { return b - a; });
  }

  function dimensionsFit(place, limits) {
    var parcel = sortedPositive([
      Math.ceil(place.lengthCm * 10),
      Math.ceil(place.widthCm * 10),
      Math.ceil(place.heightCm * 10)
    ]);
    var max = sortedPositive([limits.maxLengthMm, limits.maxWidthMm, limits.maxHeightMm]);
    if (parcel.length !== 3 || max.length !== 3) return true;
    return parcel.every(function (dimension, index) {
      return dimension <= max[index];
    });
  }

  function collectViolations(limits, places) {
    if (!limits) return [];
    if (!limits.pointFound) {
      return [{
        type: 'point',
        message: 'Выбранный ПВЗ Ozon недоступен для создания отправления.',
        rows: []
      }];
    }
    var dimensionLimits = sortedPositive([limits.maxLengthMm, limits.maxWidthMm, limits.maxHeightMm]);
    return places.reduce(function (violations, place) {
      if (limits.minWeightG > 0 && place.weightG > 0 && place.weightG < limits.minWeightG) {
        violations.push({
          type: 'min_weight',
          place: place,
          message: 'Грузоместо ' + place.number + ' меньше минимального веса ПВЗ Ozon: ' + formatKg(place.weightG) + ' при минимуме ' + formatKg(limits.minWeightG) + '.'
        });
      }
      if (limits.maxWeightG > 0 && place.weightG > limits.maxWeightG) {
        violations.push({
          type: 'max_weight',
          place: place,
          message: 'Грузоместо ' + place.number + ' превышает максимальный вес ПВЗ Ozon: ' + formatKg(place.weightG) + ' при допустимых ' + formatKg(limits.maxWeightG) + '.'
        });
      }
      if (!dimensionsFit(place, limits)) {
        violations.push({
          type: 'dimensions',
          place: place,
          message: 'Грузоместо ' + place.number + ' превышает допустимые размеры ПВЗ Ozon: ' + formatCmList(sortedPositive([place.lengthCm, place.widthCm, place.heightCm])) + ' при максимуме ' + formatCmList(dimensionLimits.map(function (mm) { return mm / 10; })) + '.'
        });
      }
      return violations;
    }, []);
  }

  function clearInvalidMarks(form) {
    form.querySelectorAll('.' + invalidClass).forEach(function (row) {
      row.classList.remove(invalidClass);
      row.removeAttribute('aria-invalid');
      row.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
        input.removeAttribute('aria-invalid');
      });
    });
  }

  function renderWarning(form, violations) {
    var warning = warningContainer(form);
    if (!warning) return;
    warning.textContent = '';
    if (!violations.length) {
      warning.hidden = true;
      return;
    }
    var list = document.createElement('ul');
    violations.forEach(function (violation) {
      var item = document.createElement('li');
      item.textContent = violation.message || '';
      list.appendChild(item);
    });
    warning.appendChild(list);
    warning.hidden = false;
  }

  function markInvalidRows(violations) {
    violations.forEach(function (violation) {
      var row = violation.place && violation.place.row;
      if (!row) return;
      row.classList.add(invalidClass);
      row.setAttribute('aria-invalid', 'true');
      (violation.place.inputs || []).forEach(function (input) {
        input.setAttribute('aria-invalid', 'true');
      });
    });
  }

  function validateOzonPlaceLimits(form) {
    if (!isOzonForm(form)) return true;
    var limits = limitsFromForm(form);
    if (!limits) return true;
    clearInvalidMarks(form);
    var violations = collectViolations(limits, readPlaces(form));
    markInvalidRows(violations);
    renderWarning(form, violations);
    return violations.length === 0;
  }

  function refresh(form) {
    if (!isOzonForm(form)) return false;
    validateOzonPlaceLimits(form);
    updateCreateAvailability(form);
    return false;
  }

  register({
    afterFormInitialized: function (form) {
      return refresh(form);
    },
    afterPlacesChanged: function (form) {
      return refresh(form);
    },
    handleChange: function (event) {
      var form = findShipmentForm(event.target);
      if (!form || !event.target.matches('input[name$="[weight_g]"], input[name$="[length_cm]"], input[name$="[width_cm]"], input[name$="[height_cm]"]')) {
        return false;
      }
      return refresh(form);
    },
    createAvailability: function (form) {
      return validateOzonPlaceLimits(form);
    }
  });

  window.wdcOzonDeliveryShipmentLimits = {
    collectViolations: collectViolations,
    dimensionsFit: dimensionsFit
  };
})();
