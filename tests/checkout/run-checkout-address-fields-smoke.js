const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'assets', 'frontend', 'checkout-address-fields.js'), 'utf8').replace(/\r\n/g, '\n');

assert(!source.includes('cdek'));
assert(!source.includes('yandex'));
assert(!source.includes('russian_post'));
assert(source.includes('data-wdc-delivery-type'));
assert(source.includes('data-wdc-requires-courier-address'));

function createHarness() {
  const elements = [];
  const byId = new Map();
  const handlers = [];

  class Element {
    constructor(tag, attrs = {}) {
      this.tag = tag;
      this.id = attrs.id || '';
      this.name = attrs.name || '';
      this.type = attrs.type || '';
      this.value = attrs.value || '';
      this.checked = !!attrs.checked;
      this.parent = attrs.parent || null;
      this.children = [];
      this.classes = new Set(String(attrs.className || '').split(/\s+/).filter(Boolean));
      this.attrs = Object.assign({}, attrs.attrs || {});
      this.props = {};
      this.hidden = false;
      if (this.parent) {
        this.parent.children.push(this);
      }
      elements.push(this);
      if (this.id) {
        byId.set(this.id, this);
      }
    }
  }

  const body = new Element('body');
  const billingWrap = new Element('p', { id: 'billing_address_1_field', parent: body });
  const billingLabel = new Element('label', { parent: billingWrap, attrs: { for: 'billing_address_1' } });
  const billingOptional = new Element('span', { parent: billingLabel, className: 'optional' });
  const billing = new Element('input', { id: 'billing_address_1', name: 'billing_address_1', parent: billingWrap });
  const shippingWrap = new Element('p', { id: 'shipping_address_1_field', parent: body });
  const shippingLabel = new Element('label', { parent: shippingWrap, attrs: { for: 'shipping_address_1' } });
  const shipping = new Element('input', { id: 'shipping_address_1', name: 'shipping_address_1', parent: shippingWrap });
  const shipToDifferent = new Element('input', { id: 'ship-to-different-address-checkbox', name: 'ship_to_different_address', type: 'checkbox', parent: body });
  const pickupLi = new Element('li', { parent: body });
  const pickupInput = new Element('input', { name: 'shipping_method[0]', type: 'radio', checked: true, parent: pickupLi });
  new Element('div', { parent: pickupLi, className: 'wdc-platform-rate-meta', attrs: { 'data-wdc-delivery-type': 'pickup', 'data-wdc-requires-courier-address': '0' } });
  const courierLi = new Element('li', { parent: body });
  const courierInput = new Element('input', { name: 'shipping_method[0]', type: 'radio', checked: false, parent: courierLi });
  new Element('div', { parent: courierLi, className: 'wdc-platform-rate-meta', attrs: { 'data-wdc-delivery-type': 'courier', 'data-wdc-requires-courier-address': '1' } });

  function descendants(rootElement) {
    const found = [];
    (rootElement.children || []).forEach((child) => {
      found.push(child, ...descendants(child));
    });
    return found;
  }

  function matches(element, selector) {
    if (!element) {
      return false;
    }
      if (selector.startsWith('#')) {
        return element.id === selector.slice(1);
      }
      if (/^\.[A-Za-z0-9_-]+$/.test(selector)) {
        return element.classes.has(selector.slice(1));
      }
      if (selector === '.wdc-platform-rate-meta') {
        return element.classes.has('wdc-platform-rate-meta');
      }
    if (selector === 'li') {
      return element.tag === 'li';
    }
    if (selector === 'input[name^="shipping_method"]:checked') {
      return element.tag === 'input' && element.name.startsWith('shipping_method') && element.checked;
    }
    if (selector === 'select[name^="shipping_method"]') {
      return element.tag === 'select' && element.name.startsWith('shipping_method');
    }
    if (selector === '#ship-to-different-address-checkbox' || selector === 'input[name="ship_to_different_address"]') {
      return element.id === 'ship-to-different-address-checkbox' || element.name === 'ship_to_different_address';
    }
    const labelMatch = selector.match(/^label\[for="([^"]+)"\]$/);
    if (labelMatch) {
      return element.tag === 'label' && element.attrs.for === labelMatch[1];
    }
    return false;
  }

  function select(selector, within = elements) {
    if (!selector) {
      return [];
    }
    return String(selector).split(',').flatMap((part) => {
      const trimmed = part.trim();
      return within.filter((element) => matches(element, trimmed));
    });
  }

  class Wrapper {
    constructor(items) {
      this.items = items.filter(Boolean);
      this.length = this.items.length;
      this.items.forEach((item, index) => { this[index] = item; });
    }
    first() { return new Wrapper(this.items.slice(0, 1)); }
    each(callback) { this.items.forEach((item, index) => callback.call(item, index, item)); return this; }
    is(selector) {
      const item = this.items[0];
      if (selector === ':checked') {
        return !!(item && item.checked);
      }
      return false;
    }
    attr(name, value) {
      if (value === undefined) {
        const item = this.items[0];
        return item ? (item.attrs[name] ?? item[name] ?? '') : '';
      }
      this.items.forEach((item) => { item.attrs[name] = String(value); });
      return this;
    }
    removeAttr(name) {
      this.items.forEach((item) => { delete item.attrs[name]; });
      return this;
    }
    prop(name, value) {
      if (value === undefined) {
        const item = this.items[0];
        return item ? item.props[name] : undefined;
      }
      this.items.forEach((item) => { item.props[name] = value; });
      return this;
    }
    addClass(name) { this.items.forEach((item) => name.split(/\s+/).filter(Boolean).forEach((part) => item.classes.add(part))); return this; }
    removeClass(name) { this.items.forEach((item) => name.split(/\s+/).forEach((part) => item.classes.delete(part))); return this; }
    hide() { this.items.forEach((item) => { item.hidden = true; }); return this; }
    show() { this.items.forEach((item) => { item.hidden = false; }); return this; }
    append(html) {
      if (String(html).includes('data-wdc-address-required-marker')) {
        this.items.forEach((item) => new Element('abbr', { parent: item, className: 'required', attrs: { 'data-wdc-address-required-marker': '' } }));
      }
      return this;
    }
    find(selector) {
      return new Wrapper(this.items.flatMap((item) => select(selector, descendants(item))));
    }
    remove() {
      this.items.forEach((item) => {
        if (item.parent) {
          item.parent.children = item.parent.children.filter((child) => child !== item);
        }
        const index = elements.indexOf(item);
        if (index >= 0) {
          elements.splice(index, 1);
        }
      });
      return this;
    }
    closest(selector) {
      return new Wrapper(this.items.map((item) => {
        let cursor = item.parent;
        while (cursor) {
          if (matches(cursor, selector)) {
            return cursor;
          }
          cursor = cursor.parent;
        }
        return null;
      }));
    }
    off(eventName, selector) { handlers.push({ eventName, selector, off: true }); return this; }
    on(eventName, selector, handler) { handlers.push({ eventName, selector, handler }); return this; }
  }

  function $(input) {
    if (typeof input === 'function') {
      input();
      return new Wrapper([]);
    }
    if (input === body || input instanceof Element) {
      return new Wrapper([input]);
    }
    return new Wrapper(select(input));
  }

  const context = { jQuery: $, window: {}, document: { body } };
  vm.createContext(context);
  vm.runInContext(source, context);

  return { context, billing, billingWrap, billingLabel, billingOptional, shipping, shippingWrap, shipToDifferent, pickupInput, courierInput };
}

const harness = createHarness();
const update = harness.context.window.WDCCheckoutAddressFields.update;

update();
assert.strictEqual(harness.billing.props.required, false, 'pickup starts with address optional.');
assert(!harness.billingWrap.classes.has('validate-required'), 'pickup must not mark billing address required.');

harness.pickupInput.checked = false;
harness.courierInput.checked = true;
update();
assert.strictEqual(harness.billing.props.required, true, 'courier must require active billing address.');
assert.strictEqual(harness.billing.attrs['aria-required'], 'true', 'courier must set aria-required.');
assert(harness.billingWrap.classes.has('validate-required'), 'courier must use Woo validate-required wrapper class.');
assert(harness.billingLabel.children.some((child) => child.classes.has('required')), 'courier must show a Woo-style required marker.');
assert.strictEqual(harness.billingOptional.hidden, true, 'courier must hide the optional marker.');

harness.shipToDifferent.checked = true;
update();
assert.strictEqual(harness.billing.props.required, false, 'shipping destination must clear billing required marker.');
assert.strictEqual(harness.shipping.props.required, true, 'shipping destination must require shipping address for courier.');
assert(harness.shippingWrap.classes.has('validate-required'), 'shipping destination must mark shipping wrapper required.');

harness.courierInput.checked = false;
harness.pickupInput.checked = true;
update();
assert.strictEqual(harness.shipping.props.required, false, 'switching back to pickup must make shipping address optional.');
assert(!harness.shippingWrap.classes.has('validate-required'), 'switching back to pickup must clear wrapper required class.');

console.log('Checkout address fields JS smoke passed.');
