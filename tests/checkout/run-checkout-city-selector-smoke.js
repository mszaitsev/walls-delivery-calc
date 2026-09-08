const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const sourcePath = path.join(root, 'assets', 'frontend', 'checkout-city-selector.js');
const source = fs.readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');
const cssPath = path.join(root, 'assets', 'frontend', 'checkout-city-selector.css');
const css = fs.readFileSync(cssPath, 'utf8').replace(/\r\n/g, '\n');

assert(!source.includes('setInterval('), 'checkout city selector must not use setInterval.');
assert(source.includes('wdc_platform_location_country_code'), 'city selector must persist selected location country_code.');
assert(source.includes('clearDestinationFieldsForCountryChange'), 'city selector must clear destination fields on real country change.');
assert(source.includes('wdc:location-cleared'), 'city selector must dispatch location-cleared on country change.');

function createHarness(initial) {
  let nextTimerId = 1;
  const timers = [];
  const updateCheckoutEvents = [];
  const dispatchedEvents = [];
  const ajaxRequests = [];
  const ajaxResponses = Array.isArray(initial.ajaxResponses) ? initial.ajaxResponses.slice() : [];
  const elements = [];
  const byId = new Map();
  const byName = new Map();
  const bodyHandlers = [];
  const documentHandlers = [];

  class Element {
    constructor(tag, attrs = {}) {
      this.tag = tag;
      this.id = attrs.id || '';
      this.name = attrs.name || '';
      this.type = attrs.type || '';
      this.value = attrs.value || '';
      this.checked = !!attrs.checked;
      this.visible = false !== attrs.visible;
      this.disabled = !!attrs.disabled;
      this.classes = new Set(attrs.className ? String(attrs.className).split(/\s+/) : []);
      this.parent = attrs.parent || null;
      this.textContent = '';
      this.htmlContent = '';
      this.children = [];
      this.dataStore = {};
    }
  }

  function addElement(tag, attrs) {
    const element = new Element(tag, attrs);
    elements.push(element);
    if (element.id) {
      byId.set(element.id, element);
    }
    if (element.name) {
      if (!byName.has(element.name)) {
        byName.set(element.name, []);
      }
      byName.get(element.name).push(element);
    }
    if (element.parent) {
      element.parent.children.push(element);
    }
    return element;
  }

  const body = {
    addEventListener(type, handler) {
      bodyHandlers.push({ type, handler });
    },
    dispatchEvent(event) {
      dispatchedEvents.push(event);
      bodyHandlers.filter((item) => item.type === event.type).forEach((item) => item.handler(event));
      return true;
    }
  };
  const documentObject = {
    body,
    addEventListener(type, handler) {
      documentHandlers.push({ type, handler });
    }
  };
  const form = addElement('form', { className: 'checkout', visible: true });

  function addField(id, name, value = '', attrs = {}) {
    return addElement(attrs.tag || 'input', Object.assign({ id, name, value, parent: form, visible: true }, attrs));
  }

  addField('billing_country', 'billing_country', initial.billing_country || 'RU', { tag: 'select' });
  addField('billing_city', 'billing_city', initial.billing_city || '');
  addField('billing_state', 'billing_state', initial.billing_state || '');
  addField('billing_postcode', 'billing_postcode', initial.billing_postcode || '');
  if (initial.includeShipping) {
    addField('shipping_country', 'shipping_country', initial.shipping_country || 'RU', { tag: 'select' });
    addField('shipping_city', 'shipping_city', initial.shipping_city || '');
    addField('shipping_state', 'shipping_state', initial.shipping_state || '');
    addField('shipping_postcode', 'shipping_postcode', initial.shipping_postcode || '');
    addField('ship-to-different-address-checkbox', 'ship_to_different_address', '1', { checked: !!initial.shippingActive });
  }

  class Wrapper {
    constructor(items) {
      this.items = items.filter(Boolean);
      this.length = this.items.length;
      this.items.forEach((item, index) => {
        this[index] = item;
      });
    }

    first() {
      return new Wrapper(this.items.slice(0, 1));
    }

    is(selector) {
      const element = this.items[0];
      if (!element) {
        return false;
      }
      if (':visible' === selector) {
        return element.visible;
      }
      if (':disabled' === selector) {
        return element.disabled;
      }
      if (':checked' === selector) {
        return element.checked;
      }
      if ('select' === selector) {
        return 'select' === element.tag;
      }
      return false;
    }

    attr(name, value) {
      const element = this.items[0];
      if (undefined === value) {
        return element ? element[name] || '' : '';
      }
      this.items.forEach((item) => {
        item[name] = String(value);
      });
      return this;
    }

    val(value) {
      if (undefined === value) {
        return this.items[0] ? this.items[0].value : '';
      }
      this.items.forEach((element) => {
        element.value = String(value);
      });
      return this;
    }

    prop(name, value) {
      if (undefined === value) {
        const element = this.items[0];
        return element ? element[name] : undefined;
      }
      this.items.forEach((element) => {
        element[name] = value;
      });
      return this;
    }

    data(name, value) {
      const element = this.items[0];
      if (undefined === value) {
        return element ? element.dataStore[name] : undefined;
      }
      this.items.forEach((item) => {
        item.dataStore[name] = value;
      });
      return this;
    }

    trigger(eventName) {
      if ('update_checkout' === eventName && this.items.includes(body)) {
        updateCheckoutEvents.push(eventName);
      }
      return this;
    }

    closest(selector) {
      if ('form.checkout' !== selector) {
        return new Wrapper([]);
      }
      const element = this.items[0];
      return new Wrapper(element && element.parent ? [element.parent] : []);
    }

    find(selector) {
      const found = [];
      this.items.forEach((element) => {
        found.push(...select(selector, element.children || []));
      });
      return new Wrapper(found);
    }

    append(html) {
      const match = String(html).match(/name="([^"]+)"/);
      if (match) {
        addElement('input', { type: 'hidden', name: match[1], value: '', parent: this.items[0] || form, visible: false });
      }
      return this;
    }

    siblings(selector) {
      if ('.wdc-city-selector-selected' === selector) {
        return new Wrapper(elements.filter((element) => element.classes.has('wdc-city-selector-selected')));
      }
      return new Wrapper([]);
    }

    after(html) {
      if (String(html).includes('wdc-city-selector-selected')) {
        addElement('div', { className: 'wdc-city-selector-selected', parent: form, visible: true });
      }
      return this;
    }

    remove() {
      this.items.forEach((element) => {
        const index = elements.indexOf(element);
        if (index >= 0) {
          elements.splice(index, 1);
        }
      });
      return this;
    }

    html(value) {
      if (undefined === value) {
        return this.items[0] ? this.items[0].htmlContent : '';
      }
      this.items.forEach((element) => {
        element.htmlContent = String(value);
      });
      return this;
    }

    text(value) {
      if (undefined === value) {
        return this.items[0] ? this.items[0].textContent : '';
      }
      this.items.forEach((element) => {
        element.textContent = String(value);
      });
      return this;
    }

    addClass(className) {
      this.items.forEach((element) => String(className || '').split(/\s+/).filter(Boolean).forEach((name) => element.classes.add(name)));
      return this;
    }
    removeClass(className) {
      this.items.forEach((element) => String(className || '').split(/\s+/).filter(Boolean).forEach((name) => element.classes.delete(name)));
      return this;
    }
    hasClass(className) {
      return !!(this.items[0] && this.items[0].classes.has(className));
    }
    removeAttr(name) {
      this.items.forEach((element) => {
        delete element[name];
      });
      return this;
    }
    removeData(name) {
      this.items.forEach((element) => {
        delete element.dataStore[name];
      });
      return this;
    }
    toggleClass() { return this; }
    empty() { return this.html(''); }
    on(events, selector, handler) {
      if (this.items.includes(body) && 'string' === typeof events) {
        const callback = 'function' === typeof selector ? selector : handler;
        String(events).split(/\s+/).filter(Boolean).forEach((eventName) => {
          bodyHandlers.push({ type: eventName.split('.')[0], selector: 'function' === typeof selector ? '' : selector, handler: callback });
        });
      }
      return this;
    }
    off() { return this; }
  }

  function select(selector, scope = elements) {
    return String(selector).split(',').flatMap((part) => {
      part = part.trim();
      if ('form.checkout' === part) {
        return scope.includes(form) ? [form] : [];
      }
      if (part.startsWith('#')) {
        const id = part.slice(1);
        const element = byId.get(id);
        return element && scope.includes(element) ? [element] : [];
      }
      const nameMatch = part.match(/(?:input|select)?\[name="([^"]+)"\]/);
      if (nameMatch) {
        return (byName.get(nameMatch[1]) || []).filter((element) => scope.includes(element));
      }
      if (part.startsWith('.')) {
        const className = part.slice(1);
        return scope.filter((element) => element.classes && element.classes.has(className));
      }
      return [];
    });
  }

  function $(selector) {
    if ('function' === typeof selector) {
      selector();
      return new Wrapper([]);
    }
    if (selector === body || selector === documentObject.body) {
      return new Wrapper([body]);
    }
    if (selector === documentObject) {
      return new Wrapper([documentObject]);
    }
    if (selector instanceof Element || selector === form) {
      return new Wrapper([selector]);
    }
    return new Wrapper(select(selector));
  }
  $.trim = (value) => String(value || '').trim();
  $.ajax = (request) => {
    ajaxRequests.push(request || {});
    const response = ajaxResponses.length ? ajaxResponses.shift() : null;
    return {
      done(callback) {
        if (!response || 'fail' !== response.type) {
          callback(response && Object.prototype.hasOwnProperty.call(response, 'body') ? response.body : response);
        }
        return this;
      },
      fail(callback) {
        if (response && 'fail' === response.type) {
          callback(response.xhr || {});
        }
        return this;
      }
    };
  };

  const context = {
    window: {
      wdcPlatformCitySelector: {
        ajax_url: '/ajax',
        nonce: 'nonce',
        supported_location_countries: ['RU', 'AM', 'BY', 'KZ', 'KG'],
        min_chars: 3,
        strings: { start: 'start', searching: 'searching', error: 'error', not_found: 'not_found' },
        manual_city_context: initial.manual_city_context || {}
      },
      setTimeout(callback) {
        const id = nextTimerId++;
        timers.push({ id, callback });
        return id;
      },
      clearTimeout(id) {
        const index = timers.findIndex((timer) => timer.id === id);
        if (index >= 0) {
          timers.splice(index, 1);
        }
      },
      console
    },
    document: documentObject,
    CustomEvent: function CustomEvent(type, options) {
      this.type = type;
      this.detail = options && options.detail ? options.detail : {};
    },
    jQuery: $,
    $,
    console
  };
  context.window.jQuery = $;
  context.window.CustomEvent = context.CustomEvent;

  const instrumented = source.replace(
    "$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, afterCheckoutUpdated );\n}( jQuery ) );",
    "window.__wdcCitySelectorTest = { applySelectedLocation: applySelectedLocation, applyManualFallbackCity: applyManualFallbackCity, handleCountryAvailabilityChanged: handleCountryAvailabilityChanged, currentCountryCode: currentCountryCode, setPickerState: setPickerState, scheduleAutoResolve: scheduleAutoResolve, autoResolve: autoResolve, afterCheckoutUpdated: afterCheckoutUpdated, hasManualLocationSelection: hasManualLocationSelection };\n$( document.body ).on( 'updated_checkout' + namespace + ' wc_fragments_refreshed' + namespace, afterCheckoutUpdated );\n}( jQuery ) );"
  );
  vm.runInNewContext(instrumented, context, { filename: sourcePath });

  function runTimers() {
    while (timers.length) {
      const timer = timers.shift();
      timer.callback();
    }
  }

  return {
    context,
    field(name) {
      const field = (byName.get(name) || [])[0];
      return field ? field.value : '';
    },
    setField(name, value) {
      const field = (byName.get(name) || [])[0];
      if (field) {
        field.value = value;
      }
    },
    triggerStateEvent(eventName = 'change') {
      const field = (byName.get('billing_state') || [])[0];
      bodyHandlers
        .filter((item) => item.type === eventName && 'function' === typeof item.handler)
        .forEach((item) => item.handler({ target: field, type: eventName }));
    },
    hidden(name) {
      const field = (byName.get(name) || [])[0];
      return field ? field.value : '';
    },
    element(name) {
      return (byName.get(name) || [])[0] || null;
    },
    updates() {
      return updateCheckoutEvents.length;
    },
    ajaxRequests() {
      return ajaxRequests.slice();
    },
    noticeText() {
      const notice = elements.find((element) => element.classes && element.classes.has('wdc-city-selector-selected'));
      return notice ? notice.textContent || notice.htmlContent : '';
    },
    noticeClasses() {
      const notice = elements.find((element) => element.classes && element.classes.has('wdc-city-selector-selected'));
      return notice ? Array.from(notice.classes) : [];
    },
    clearedEvents() {
      return dispatchedEvents.filter((event) => event.type === 'wdc:location-cleared');
    },
    runTimers
  };
}

const minskPayload = {
  id: 210003,
  country_code: 'BY',
  option_label: 'г Минск - Минский р-н, Минская область',
  display_name: 'Минская обл., Минский р-н, г Минск',
  city_value: 'г Минск',
  state_value: 'Минская область',
  postal_code: '220000',
  region_name: 'Минская',
  region_type: 'обл.',
  district_name: 'Минский',
  district_type: 'р-н',
  city_name: 'Минск',
  city_type: 'г',
  place_name: 'Минск',
  place_type: 'г'
};

const sakhaPayload = {
  id: 920001,
  country_code: 'RU',
  display_name: 'респ Саха (Якутия), г Якутск',
  city_value: 'г Якутск',
  state_value: 'респ Саха (Якутия)',
  postal_code: '677000',
  region_name: 'Саха (Якутия)',
  region_type: 'респ',
  city_name: 'Якутск',
  city_type: 'г',
  place_name: 'Якутск',
  place_type: 'г',
  fias_id: 'fias-yakutsk'
};

{
  const harness = createHarness({ billing_country: 'BY', billing_city: '', billing_state: '', billing_postcode: '' });
  harness.context.window.WDCCheckoutCitySelector.applyLocation(minskPayload, { updateCheckout: true, explicit: true, source: 'modal', updateFields: true });
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'г Минск', 'selected BY Minsk must write typed own city to visible city field.');
  assert.strictEqual(harness.field('billing_state'), 'Минская область', 'selected BY Minsk must write region to visible state field.');
  assert.strictEqual(harness.field('billing_postcode'), '220000', 'selected BY Minsk must write payload postcode.');
  assert.strictEqual(harness.hidden('wdc_platform_location_country_code'), 'BY', 'selected location country_code hidden field must be set.');
  assert.strictEqual(harness.hidden('wdc_platform_location_city_name'), 'Минск', 'canonical city_name hidden field must be set.');
  assert.strictEqual(harness.hidden('wdc_platform_location_place_name'), 'Минск', 'canonical place_name hidden field must be set.');
  assert.strictEqual(harness.hidden('wdc_platform_location_district_name'), 'Минский', 'canonical district_name hidden field must be set.');
  assert.strictEqual(harness.hidden('wdc_platform_location_region_name'), 'Минская', 'canonical region_name hidden field must be set.');
  assert(harness.element('billing_state').classes.has('wdc-location-state-locked'), 'canonical DB selection must lock region field without disabling its POST value.');
  assert.strictEqual(harness.element('billing_state').disabled, false, 'locked region field must not be disabled.');
  assert.strictEqual(harness.updates(), 1, 'explicit location selection must schedule one checkout recalculation.');
}

{
  const harness = createHarness({
    billing_country: 'RU',
    billing_city: 'якутск',
    billing_state: 'саха /Якутия/ республика',
    billing_postcode: '',
    ajaxResponses: [{
      success: true,
      data: {
        local_database_available: true,
        status: 'resolved',
        selected: sakhaPayload
      }
    }]
  });
  harness.setField('wdc_platform_location_fias_id', 'fias-yakutsk');
  harness.setField('wdc_platform_location_display_name', 'респ Саха (Якутия), г Якутск');
  harness.setField('wdc_platform_location_place_type', 'г');
  harness.setField('wdc_platform_location_place_name', 'Якутск');
  harness.setField('wdc_platform_location_region_type', 'респ');
  harness.setField('wdc_platform_location_region_name', 'Саха (Якутия)');
  harness.setField('wdc_platform_location_postcode', '677000');
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'г Якутск', 'same canonical identity with stale visible city must repair city on first resolve.');
  assert.strictEqual(harness.field('billing_state'), 'респ Саха (Якутия)', 'same canonical identity with stale visible state must repair state on first resolve.');
  assert.strictEqual(harness.field('billing_postcode'), '677000', 'same canonical identity with stale visible postcode must repair postcode on first resolve.');
  assert(harness.noticeClasses().includes('is-selected'), 'repaired canonical identity must keep selected status.');
  assert.strictEqual(harness.updates(), 1, 'repaired canonical identity must trigger exactly one checkout recalculation.');
  assert.strictEqual(harness.ajaxRequests().length, 1, 'stale visible canonical fields must still perform one resolve request.');

  harness.context.window.__wdcCitySelectorTest.scheduleAutoResolve();
  harness.runTimers();
  assert.strictEqual(harness.ajaxRequests().length, 1, 'already canonical visible fields must not schedule another resolve.');
  assert.strictEqual(harness.updates(), 1, 'already canonical visible fields must not trigger another checkout update.');
}

{
  const harness = createHarness({
    billing_country: 'RU',
    billing_city: 'Якутск',
    billing_state: 'Саха /Якутия/ республика',
    billing_postcode: '',
    ajaxResponses: [{
      success: true,
      data: {
        local_database_available: true,
        status: 'resolved',
        selected: sakhaPayload
      }
    }]
  });
  assert(harness.noticeText().includes('Проверяем...'), 'initial auto resolve must show checking status before AJAX completes.');
  assert(harness.noticeClasses().includes('is-checking'), 'checking status must use is-checking class.');
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'г Якутск', 'successful auto resolve must canonicalize city.');
  assert.strictEqual(harness.field('billing_state'), 'респ Саха (Якутия)', 'successful auto resolve must canonicalize state.');
  assert.strictEqual(harness.field('billing_postcode'), '677000', 'successful auto resolve must canonicalize postcode.');
  assert.strictEqual(harness.hidden('wdc_platform_location_fias_id'), 'fias-yakutsk', 'successful auto resolve must set canonical hidden metadata.');
  assert(harness.noticeText().includes('Выбран: респ Саха (Якутия), г Якутск, 677000'), 'successful auto resolve must show selected status text.');
  assert(harness.noticeClasses().includes('is-selected'), 'selected status must use is-selected class.');
  assert.strictEqual(harness.updates(), 1, 'successful auto resolve must trigger exactly one checkout recalculation after all fields are set.');
  assert.strictEqual(harness.ajaxRequests().length, 1, 'successful auto resolve must use one resolve request.');
  harness.context.window.__wdcCitySelectorTest.afterCheckoutUpdated();
  harness.runTimers();
  assert.strictEqual(harness.ajaxRequests().length, 1, 'updated_checkout after canonical selection must not start recursive auto resolve.');
}

{
  const harness = createHarness({
    billing_country: 'RU',
    billing_city: 'Непонятный город',
    billing_state: 'Старая область',
    billing_postcode: '630000',
    ajaxResponses: [{
      success: true,
      data: {
        local_database_available: true,
        status: 'not_found'
      }
    }]
  });
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), '', 'unresolved initial profile must clear city.');
  assert(harness.noticeText().includes('Просим проверить название и внести верный населенный пункт'), 'unresolved initial profile must keep existing invalid notice text.');
  assert(harness.noticeClasses().includes('is-invalid'), 'unresolved initial profile must use invalid status class.');
}

{
  const harness = createHarness({ billing_country: 'RU', billing_city: '', billing_state: '', billing_postcode: '630000' });
  harness.context.window.__wdcCitySelectorTest.applyManualFallbackCity('Ручной город');
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), '', 'manual fallback must be unavailable before a successful empty search state.');
  assert.strictEqual(harness.hidden('wdc_platform_location_selected_source'), '', 'manual source must not be set outside empty state.');
}

{
  const harness = createHarness({ billing_country: 'RU', billing_city: '', billing_state: 'Старая область', billing_postcode: '630000' });
  harness.context.window.__wdcCitySelectorTest.setPickerState('empty');
  harness.context.window.__wdcCitySelectorTest.applyManualFallbackCity('Ручной город');
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'Ручной город', 'manual fallback must write exact trimmed city text.');
  assert.strictEqual(harness.field('billing_state'), '', 'manual fallback must clear stale region.');
  assert.strictEqual(harness.field('billing_postcode'), '', 'manual fallback must clear stale postcode.');
  assert.strictEqual(harness.hidden('wdc_platform_location_id'), '', 'manual fallback must clear canonical location_id.');
  assert.strictEqual(harness.hidden('wdc_platform_location_selected_source'), 'manual', 'manual fallback must persist manual selected_source.');
  assert.strictEqual(harness.hidden('wdc_platform_location_display_name'), 'Ручной город', 'manual fallback display value must survive checkout refresh.');
  assert(!harness.element('billing_state').classes.has('wdc-location-state-locked'), 'manual fallback must unlock region editing.');
  assert(harness.noticeText().includes('Указан неизвестный населенный пункт. Доставка может не рассчитаться'), 'manual fallback must show red unknown-settlement warning.');
  assert(harness.noticeClasses().includes('is-manual-warning'), 'manual fallback status must use is-manual-warning class.');
  harness.context.window.__wdcCitySelectorTest.afterCheckoutUpdated();
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'Ручной город', 'manual fallback city must survive updated_checkout reinitialization.');
  assert.strictEqual(harness.ajaxRequests().length, 0, 'manual fallback must not auto-resolve again in the same checkout session.');
}

{
  const harness = createHarness({
    billing_country: 'RU',
    billing_city: 'Ручной город',
    billing_state: 'Ручная область',
    billing_postcode: '',
    manual_city_context: {
      source: 'manual',
      country_code: 'RU',
      city_name: 'Ручной город',
      region_name: 'Ручная область'
    }
  });
  harness.runTimers();
  assert.strictEqual(harness.hidden('wdc_platform_location_selected_source'), 'manual', 'session-backed reload must restore manual selected_source.');
  assert(harness.noticeClasses().includes('is-manual-warning'), 'session-backed reload must restore manual warning state.');
  assert.strictEqual(harness.ajaxRequests().length, 0, 'session-backed manual reload must not run DB reconciliation.');
  harness.context.window.WDCCheckoutCitySelector.applyLocation(sakhaPayload, { updateCheckout: true, explicit: true, source: 'modal', updateFields: true });
  harness.runTimers();
  assert.strictEqual(harness.hidden('wdc_platform_location_selected_source'), 'modal', 'canonical selection must replace manual source.');
  assert(harness.element('billing_state').classes.has('wdc-location-state-locked'), 'canonical selection after manual reload must lock region again.');
}

{
  const harness = createHarness({ billing_country: 'RU', billing_city: '', billing_state: 'Старая область', billing_postcode: '630000' });
  harness.context.window.__wdcCitySelectorTest.setPickerState('empty');
  harness.context.window.__wdcCitySelectorTest.applyManualFallbackCity('Тестоград');
  harness.runTimers();
  harness.triggerStateEvent('blur');
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'Тестоград', 'manual city must survive editable region focus/blur without changes.');
  assert.strictEqual(harness.hidden('wdc_platform_location_selected_source'), 'manual', 'manual source must survive editable region blur.');
  assert.strictEqual(harness.ajaxRequests().length, 0, 'manual region blur must not trigger profile reconciliation.');

  harness.setField('billing_state', 'Тестовая область');
  harness.triggerStateEvent('change');
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'Тестоград', 'manual city must survive manual region edit.');
  assert.strictEqual(harness.field('billing_state'), 'Тестовая область', 'manual region edit must keep entered state value.');
  assert.strictEqual(harness.hidden('wdc_platform_location_selected_source'), 'manual', 'manual source must survive manual region edit.');
  assert.strictEqual(harness.ajaxRequests().length, 0, 'manual region edit must not run DB reconciliation.');
  harness.context.window.__wdcCitySelectorTest.afterCheckoutUpdated();
  harness.runTimers();
  assert.strictEqual(harness.field('billing_city'), 'Тестоград', 'manual city must survive checkout validation/update refresh.');
  assert(harness.noticeClasses().includes('is-manual-warning'), 'manual warning must survive checkout validation/update refresh.');
  assert.strictEqual(harness.ajaxRequests().length, 0, 'manual validation/update refresh must not run DB reconciliation.');
}

{
  const harness = createHarness({ billing_country: 'RU', billing_city: 'г Новосибирск', billing_state: 'Новосибирская область', billing_postcode: '630005' });
  assert.strictEqual(harness.field('billing_city'), 'г Новосибирск', 'initial load must not clear city.');
  assert.strictEqual(harness.updates(), 0, 'initial load must not trigger update_checkout.');
}

{
  const harness = createHarness({ billing_country: 'RU', billing_city: 'г Новосибирск', billing_state: 'Новосибирская область', billing_postcode: '630005' });
  harness.context.window.__wdcCitySelectorTest.handleCountryAvailabilityChanged();
  assert.strictEqual(harness.field('billing_city'), 'г Новосибирск', 'same-country availability event must not clear city.');
  assert.strictEqual(harness.updates(), 0, 'same-country event must not trigger update_checkout.');
}

{
  const harness = createHarness({ billing_country: 'RU', billing_city: 'г Новосибирск', billing_state: 'Новосибирская область', billing_postcode: '630005' });
  harness.setField('wdc_platform_location_id', '123');
  harness.setField('wdc_platform_location_city_name', 'Новосибирск');
  harness.setField('billing_country', 'BY');
  harness.context.window.__wdcCitySelectorTest.handleCountryAvailabilityChanged();
  assert.strictEqual(harness.field('billing_city'), '', 'country change must clear active billing city.');
  assert.strictEqual(harness.field('billing_state'), '', 'country change must clear active billing state.');
  assert.strictEqual(harness.field('billing_postcode'), '', 'country change must clear active billing postcode.');
  assert.strictEqual(harness.hidden('wdc_platform_location_id'), '', 'country change must clear hidden location_id.');
  assert.strictEqual(harness.hidden('wdc_platform_location_city_name'), '', 'country change must clear hidden city_name.');
  assert.strictEqual(harness.updates(), 1, 'country change must trigger one checkout recalculation.');
  assert.strictEqual(harness.clearedEvents().length, 1, 'country change must dispatch one location-cleared event.');
}

{
  const harness = createHarness({
    billing_country: 'RU',
    billing_city: 'billing-city',
    billing_state: 'billing-state',
    billing_postcode: '100000',
    includeShipping: true,
    shippingActive: true,
    shipping_country: 'RU',
    shipping_city: 'shipping-city',
    shipping_state: 'shipping-state',
    shipping_postcode: '200000'
  });
  harness.setField('shipping_country', 'BY');
  harness.context.window.__wdcCitySelectorTest.handleCountryAvailabilityChanged();
  assert.strictEqual(harness.field('shipping_city'), '', 'country change must clear active shipping city.');
  assert.strictEqual(harness.field('shipping_state'), '', 'country change must clear active shipping state.');
  assert.strictEqual(harness.field('shipping_postcode'), '', 'country change must clear active shipping postcode.');
  assert.strictEqual(harness.field('billing_city'), 'billing-city', 'country change must not clear inactive billing city.');
}

{
  const harness = createHarness({
    billing_country: 'RU',
    billing_city: 'billing-city',
    billing_state: 'billing-state',
    billing_postcode: '100000',
    includeShipping: true,
    shippingActive: false,
    shipping_country: 'RU',
    shipping_city: 'shipping-city',
    shipping_state: 'shipping-state',
    shipping_postcode: '200000'
  });
  harness.setField('billing_country', 'BY');
  harness.context.window.__wdcCitySelectorTest.handleCountryAvailabilityChanged();
  assert.strictEqual(harness.field('billing_city'), '', 'country change must clear active billing city when shipping address is inactive.');
  assert.strictEqual(harness.field('shipping_city'), 'shipping-city', 'country change must not clear inactive shipping city.');
}

assert(source.includes('isClearingCountryFields'), 'country-change clearing must have a reentrancy guard.');
assert(source.includes("$( document.body ).trigger( 'update_checkout' );"), 'country-change clearing must request checkout recalculation.');
assert(source.includes('function renderCityStatus') && source.includes('wdc-city-status-dot'), 'city selector must render one status element with a CSS dot.');
assert(source.includes('Проверяем...') && source.includes('is-checking'), 'auto resolve must expose checking status.');
assert(source.includes('Указан неизвестный населенный пункт. Доставка может не рассчитаться') && source.includes('is-manual-warning'), 'manual fallback must expose warning status.');
assert(source.includes('setFieldValueSilently') && source.includes('triggerCheckoutUpdate'), 'canonical location apply must batch field writes before a single checkout update.');
assert(source.includes('visibleFieldsMatchCanonicalLocation') && source.includes('hasCompleteVisibleCanonicalSelection'), 'canonical identity shortcut must verify visible city/state/postcode before skipping field repair.');
assert(source.includes('hasManualLocationSelection') && source.includes('manual_city_context'), 'manual selection must survive updated_checkout and session-backed reload without DB re-resolve.');
assert(css.includes('.wdc-city-selector-selected.is-checking') && css.includes('.wdc-city-selector-selected.is-selected') && css.includes('.wdc-city-selector-selected.is-manual-warning'), 'city status CSS must define checking/selected/manual states.');
assert(css.includes('.wdc-location-state-locked') && css.includes('background-color: #f2f2f2') && css.includes('cursor: not-allowed'), 'locked region field must look readonly without using disabled.');

console.log('Checkout city selector smoke passed.');
