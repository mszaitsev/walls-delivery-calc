const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const documentListeners = {};
const fakeDocument = {
  body: {},
  createElement: (tag) => ({ tagName: String(tag || '').toUpperCase(), value: '', textContent: '' }),
  addEventListener(type, listener) {
    if (!documentListeners[type]) documentListeners[type] = [];
    documentListeners[type].push(listener);
  },
  querySelectorAll() { return []; }
};
const context = {
  console,
  window: null,
  document: fakeDocument,
  FormData: class {
    constructor() { this.values = []; }
    append(name, value) { this.values.push([name, value]); }
  },
  WeakMap,
  Set,
  Array,
  Object,
  String,
  Number,
  Math,
  JSON,
  parseInt,
  parseFloat
};
context.window = context;
context.setTimeout = () => 1;
context.clearTimeout = () => {};
context.fetch = () => Promise.resolve({ text: () => Promise.resolve('{}') });
context.updateCreateAvailability = (form) => {
  if (!form || typeof context.carrierCreateAvailability !== 'function') return;
  form.submit.disabled = !context.carrierCreateAvailability(form, 'courier');
};
context.schedulePreviewCalls = 0;
context.scheduledPreviewPayloads = [];
context.schedulePreview = (form) => {
  context.schedulePreviewCalls += 1;
  context.scheduledPreviewPayloads.push(context.collectShipmentData(form));
};
vm.runInNewContext([
  fs.readFileSync('assets/admin/shipments/shipment-core.js', 'utf8'),
  fs.readFileSync('assets/admin/shipments/shipment-allocation.js', 'utf8'),
  fs.readFileSync('assets/admin/shipments/extensions/cdek.js', 'utf8')
].join('\n'), context);

class Input {
  constructor(name, value) {
    this.name = name;
    this.value = String(value);
    this.disabled = false;
    this.type = 'text';
    this.checked = true;
    this.row = null;
    this.form = null;
    this.options = [];
    this.attributes = {};
    if (name.endsWith('[weight]') || name.endsWith('[amount]') || name.endsWith('[weight_g]')) {
      this.attributes['data-wdc-integer-input'] = '';
      this.type = 'number';
    }
    if (name.endsWith('[cost]')) this.attributes['data-wdc-decimal-input'] = '2';
    if (/\[(length_cm|width_cm|height_cm)\]$/.test(name)) this.attributes['data-wdc-decimal-input'] = '1';
  }
  get innerHTML() { return ''; }
  set innerHTML(value) { if (value === '') this.options = []; }
  appendChild(option) { this.options.push(option); }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
  matches(selector) {
    if (selector === '[data-wdc-integer-input]') return Object.prototype.hasOwnProperty.call(this.attributes, 'data-wdc-integer-input');
    if (selector === '[data-wdc-decimal-input]') return Object.prototype.hasOwnProperty.call(this.attributes, 'data-wdc-decimal-input');
    if (selector === '[data-wdc-shipment-item-qty]') return this.name.endsWith('[amount]');
    return false;
  }
  closest(selector) {
    if (selector === '[data-wdc-shipment-item-row]') return this.row;
    if (selector === '[data-wdc-declared-value-field][hidden]') return null;
    if (selector.includes('[data-wdc-shipment-form]') || selector.includes('.wdc-shipment-form')) return this.form;
    return null;
  }
  dispatchEvent(event) {
    event.target = this;
    (documentListeners[event.type] || []).forEach((listener) => listener(event));
    return true;
  }
}

class ItemRow {
  constructor(index, placeNumber, quantity, weight, cost, dimensions = [30, 20, 10], options = {}) {
    this.index = index;
    this.place = new Input(`shipment_items[${index}][place_number]`, placeNumber);
    this.quantity = new Input(`shipment_items[${index}][amount]`, quantity);
    this.weight = new Input(`shipment_items[${index}][weight]`, weight);
    this.cost = new Input(`shipment_items[${index}][cost]`, cost);
    this.length = new Input(`shipment_items[${index}][length_cm]`, dimensions[0]);
    this.width = new Input(`shipment_items[${index}][width_cm]`, dimensions[1]);
    this.height = new Input(`shipment_items[${index}][height_cm]`, dimensions[2]);
    this.removed = false;
    this.attributes = {
      'data-wdc-row-index': String(index),
      'data-item-key': options.groupKey || `order-item-${index}`,
      'data-group-key': options.groupKey || `order-item-${index}`,
      'data-ordered-quantity': String(options.orderedQuantity || quantity),
      'data-wdc-original-item': JSON.stringify(options.original || {
        ordered_quantity: options.orderedQuantity || quantity,
        amount: options.orderedQuantity || quantity,
        cost,
        weight,
        length_cm: dimensions[0],
        width_cm: dimensions[1],
        height_cm: dimensions[2],
        place_number: 1
      })
    };
    this.attributes[options.split ? 'data-wdc-split-row' : 'data-wdc-base-row'] = '1';
    if (options.manual) {
      delete this.attributes['data-wdc-base-row'];
      this.attributes['data-wdc-manual-row'] = '1';
    }
    [this.place, this.quantity, this.weight, this.cost, this.length, this.width, this.height].forEach((input) => { input.row = this; });
  }
  querySelector(selector) {
    if (selector === '[data-wdc-shipment-place-select]') return this.place;
    if (selector === '[data-wdc-shipment-item-qty]') return this.quantity;
    if (selector === 'input[name$="[weight]"]') return this.weight;
    if (selector === 'input[name$="[cost]"]') return this.cost;
    if (selector === 'input[name$="[amount]"]') return this.quantity;
    if (selector === 'input[name$="[length_cm]"]') return this.length;
    if (selector === 'input[name$="[width_cm]"]') return this.width;
    if (selector === 'input[name$="[height_cm]"]') return this.height;
    return null;
  }
  hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); }
  getAttribute(name) { return this.hasAttribute(name) ? this.attributes[name] : null; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  removeAttribute(name) { delete this.attributes[name]; }
  remove() { this.removed = true; }
}

class PlaceRow {
  constructor(index, weight, length = 20, width = 15, height = 10) {
    this.inputs = {
      weight: new Input(`places[${index}][weight_g]`, weight),
      length: new Input(`places[${index}][length_cm]`, length),
      width: new Input(`places[${index}][width_cm]`, width),
      height: new Input(`places[${index}][height_cm]`, height)
    };
  }
  querySelector(selector) {
    if (selector.includes('[weight_g]')) return this.inputs.weight;
    if (selector.includes('[length_cm]')) return this.inputs.length;
    if (selector.includes('[width_cm]')) return this.inputs.width;
    if (selector.includes('[height_cm]')) return this.inputs.height;
    if (selector === '[data-wdc-weight-hint]') return null;
    return null;
  }
}

class Form {
  constructor(places, items, carrier = 'cdek') {
    this.places = places;
    this.items = items;
    this.summary = { innerHTML: '' };
    this.actions = { innerHTML: '' };
    this.carrier = new Input('carrier_key', carrier);
    this.draftErrors = { textContent: '', hidden: true };
    this.submit = { disabled: false };
    this.tabs = [new Tab(this, 'main'), new Tab(this, 'places')];
    this.panels = [new Panel('main'), new Panel('places')];
    this.items.forEach((item) => {
      item.form = this;
      [item.place, item.quantity, item.weight, item.cost, item.length, item.width, item.height].forEach((input) => { input.form = this; });
    });
    this.places.forEach((place) => Object.values(place.inputs).forEach((input) => { input.form = this; }));
    this.carrier.form = this;
  }
  querySelector(selector) {
    if (selector === '[data-wdc-shipment-items-summary]') return this.summary;
    if (selector === '[data-wdc-fit-item-weight-actions]') return this.actions;
    if (selector === '[data-wdc-split-row]') return this.items.find((item) => !item.removed && item.hasAttribute('data-wdc-split-row')) || null;
    if (selector === 'input[name="carrier_key"]') return this.carrier;
    if (selector === '[data-wdc-cdek-draft-errors]') return this.draftErrors;
    if (selector === '[data-wdc-create-shipment]') return this.submit;
    return null;
  }
  querySelectorAll(selector) {
    if (selector === '[data-wdc-place]') return this.places;
    if (selector === '[data-wdc-shipment-item-row]') return this.items.filter((item) => !item.removed);
    if (selector === '[data-wdc-shipment-place-select]') return this.items.filter((item) => !item.removed).map((item) => item.place);
    if (selector === '[data-wdc-shipment-item-split]') return [];
    if (selector === '[data-wdc-shipment-tab]') return this.tabs;
    if (selector === '[data-wdc-shipment-tab-panel]') return this.panels;
    if (selector === '[data-wdc-pickup-section]' || selector === '[data-wdc-courier-section]' || selector === '[data-wdc-dpd-courier-instructions-row]') return [];
    if (selector === 'input, select, textarea') {
      return this.places.flatMap((place) => Object.values(place.inputs))
        .concat(this.items.filter((item) => !item.removed).flatMap((item) => [item.place, item.quantity, item.weight, item.cost, item.length, item.width, item.height]))
        .concat([this.carrier]);
    }
    return [];
  }
}

class ClassList {
  constructor() { this.values = new Set(); }
  toggle(name, enabled) { enabled ? this.values.add(name) : this.values.delete(name); }
}

class Tab {
  constructor(form, name) { this.form = form; this.name = name; this.classList = new ClassList(); }
  getAttribute(name) { return name === 'data-wdc-shipment-tab' ? this.name : null; }
  closest(selector) {
    if (selector === '[data-wdc-shipment-tab]') return this;
    if (selector.includes('[data-wdc-shipment-form]') || selector.includes('.wdc-shipment-form')) return this.form;
    return null;
  }
  matches() { return false; }
  dispatchEvent(event) {
    event.target = this;
    (documentListeners[event.type] || []).forEach((listener) => listener(event));
    return true;
  }
}

class Panel {
  constructor(name) { this.name = name; this.hidden = name !== 'main'; }
  getAttribute(name) { return name === 'data-wdc-shipment-tab-panel' ? this.name : null; }
}

vm.runInNewContext(fs.readFileSync('assets/admin/shipments/shipment-events.js', 'utf8'), context);
context.initializeShipmentAdmin();

function dispatch(type, target) {
  target.dispatchEvent({ type, target, preventDefault() {} });
}

const package1a = new ItemRow(0, 1, 1, 600, 1000);
const package1b = new ItemRow(1, 1, 1, 600, 990);
const package2 = new ItemRow(2, 2, 1, 1500, 500);
const form = new Form([new PlaceRow(0, 1000), new PlaceRow(1, 2000)], [package1a, package1b, package2]);

context.refreshShipmentItemsSummary(form);
assert.match(form.summary.innerHTML, /Место 1:[\s\S]*вес товаров 1200 г,[\s\S]*стоимость 1990\.00 руб\./, 'Current item weight and price must drive the live package summary.');
assert.match(form.summary.innerHTML, /data-error="1"/, 'Current overweight package must be marked as a warning.');
assert.match(form.actions.innerHTML, /data-place-number="1"(?![^>]* disabled)/, 'Only overweight package 1 fit button must be enabled.');
assert.match(form.actions.innerHTML, /data-place-number="2"[^>]* disabled/, 'Package 2 fit button must remain disabled.');

package1a.weight.value = '450';
package1b.weight.value = '450';
context.refreshShipmentItemsSummary(form);
assert.doesNotMatch(form.summary.innerHTML.match(/<p[^>]*><strong>Место 1:[\s\S]*?<\/p>/)[0], /data-error/, 'Reducing the current item total below package weight must remove the warning without reload.');
package1a.weight.value = '600';
package1b.weight.value = '600';
context.refreshShipmentItemsSummary(form);
assert.match(form.summary.innerHTML.match(/<p[^>]*><strong>Место 1:[\s\S]*?<\/p>/)[0], /data-error="1"/, 'Increasing the current item total again must restore the warning without reload.');

package1b.cost.value = '600';
context.refreshShipmentItemsSummary(form);
assert.match(form.summary.innerHTML, /Место 1:[\s\S]*стоимость 1600\.00 руб\./, 'Edited price must update the summary without reload.');

assert.strictEqual(context.fitShipmentItemWeights(form, '1'), true, 'Eligible package must be fitted.');
context.refreshShipmentItemsSummary(form);
const fittedTotal = Number(package1a.weight.value) + Number(package1b.weight.value);
assert.strictEqual(fittedTotal, 950, 'Fit must reach package weight minus 50 g when integer allocation permits it.');
assert.strictEqual(package2.weight.value, '1500', 'Fitting package 1 must not mutate package 2.');
assert.doesNotMatch(form.summary.innerHTML.match(/<p[^>]*><strong>Место 1:[\s\S]*?<\/p>/)[0], /data-error/, 'Fit must immediately remove package 1 overweight warning.');
assert.match(form.actions.innerHTML, /data-place-number="1"[^>]* disabled/, 'Fit button must disable after the package is no longer overweight.');

const granularItem = new ItemRow(0, 1, 2, 600, 100);
const granular = new Form([new PlaceRow(0, 999)], [granularItem]);
assert.strictEqual(context.fitShipmentItemWeights(granular, '1'), true, 'Granular package must still fit to the nearest valid total.');
assert.strictEqual(Number(granularItem.weight.value) * 2, 948, 'Quantity granularity must choose the closest deterministic total below target 949 g.');

const incomplete = new Form([new PlaceRow(0, 1000), new PlaceRow(1, '')], [new ItemRow(0, 1, 1, 1200, 100), new ItemRow(1, 2, 1, 100, 100)]);
context.refreshShipmentItemsSummary(incomplete);
assert.strictEqual((incomplete.actions.innerHTML.match(/ disabled/g) || []).length, 2, 'Every fit button must be disabled while any package weight is incomplete.');
incomplete.places[1].inputs.weight.value = '2000';
context.refreshShipmentItemsSummary(incomplete);
assert.match(incomplete.actions.innerHTML, /data-place-number="1"(?![^>]* disabled)/, 'Filling the last package weight must enable the eligible package button.');

const livePayload = context.collectShipmentData(form);
assert.ok(livePayload.values.some(([name, value]) => name === 'shipment_items[1][cost]' && value === '600'), 'Create/preview collection must read the current edited price directly from the modal.');
assert.ok(livePayload.values.some(([name, value]) => name === 'shipment_items[0][weight]' && value === package1a.weight.value), 'Create/preview collection must read the current fitted weight directly from the modal.');

const metaboxSource = fs.readFileSync('src/Shipments/Admin/OrderShipmentsMetabox.php', 'utf8');
const costMarkup = metaboxSource.match(/<input class="wdc-cdek-input-price"[^>]*name="shipment_items\[[^\]]+\]\[cost\]"[^>]*>/);
assert.ok(costMarkup, 'Ordinary order item renderer must expose the shipment draft cost input.');
assert.doesNotMatch(costMarkup[0], /\b(?:readonly|disabled)\b/, 'Ordinary shipment draft cost must remain editable without changing the Woo order item.');

const onePlaceItem = new ItemRow(0, 1, 1, 1000, 1990, [30, 20, 10]);
const onePlace = new Form([new PlaceRow(0, 1000)], [onePlaceItem]);
context.updateShipmentPlaceOptions(onePlace);
assert.match(onePlace.actions.innerHTML, />Подогнать вес товаров<\/button>/, 'One-place fit label must not include a redundant place number.');
assert.strictEqual(onePlaceItem.weight.type, 'number', 'Item weight regression must use production type=number semantics.');
assert.strictEqual(onePlace.places[0].inputs.weight.type, 'number', 'Package weight regression must use production type=number semantics.');

onePlaceItem.weight.value = '450';
dispatch('input', onePlaceItem.weight);
onePlaceItem.cost.value = '777';
dispatch('input', onePlaceItem.cost);
onePlaceItem.length.value = '35';
dispatch('input', onePlaceItem.length);
onePlaceItem.width.value = '21';
dispatch('input', onePlaceItem.width);
onePlaceItem.height.value = '11';
dispatch('input', onePlaceItem.height);
assert.match(onePlace.summary.innerHTML, /вес товаров 450 г,[\s\S]*стоимость 777\.00 руб\./, 'Real input listeners must immediately summarize the current one-place draft.');

dispatch('click', onePlace.tabs[0]);
dispatch('click', onePlace.tabs[1]);
assert.strictEqual(onePlaceItem.weight.value, '450', 'One-place weight override must survive the real tab round-trip.');
assert.strictEqual(onePlaceItem.cost.value, '777', 'One-place price override must survive the real tab round-trip.');
assert.deepStrictEqual(
  [onePlaceItem.length.value, onePlaceItem.width.value, onePlaceItem.height.value],
  ['35', '21', '11'],
  'One-place dimension overrides must survive the real tab round-trip.'
);
assert.match(onePlace.summary.innerHTML, /вес товаров 450 г,[\s\S]*стоимость 777\.00 руб\./, 'Tab refresh must keep the summary derived from current one-place values.');

onePlaceItem.weight.value = '1100';
const previewCallsBeforeWarning = context.schedulePreviewCalls;
dispatch('input', onePlaceItem.weight);
assert.match(onePlace.summary.innerHTML, /data-error="1"/, 'Real one-place input event must show the overweight warning immediately.');
onePlaceItem.weight.value = '900';
dispatch('input', onePlaceItem.weight);
assert.doesNotMatch(onePlace.summary.innerHTML, /data-error="1"/, 'Real one-place input event must remove the overweight warning immediately.');
onePlaceItem.weight.value = '1200';
dispatch('input', onePlaceItem.weight);
assert.match(onePlace.summary.innerHTML, /data-error="1"/, 'Real one-place input event must restore the warning when current weight grows again.');
assert.ok(context.schedulePreviewCalls >= previewCallsBeforeWarning + 3, 'Every warning-changing input must schedule a fresh preview.');

assert.strictEqual(context.fitShipmentItemWeights(onePlace, '1'), true, 'One-place fit must use the current overweight draft.');
context.refreshShipmentItemsSummary(onePlace);
assert.strictEqual(onePlaceItem.weight.value, '950', 'One-place fit must reach package weight minus 50 g.');
dispatch('click', onePlace.tabs[0]);
dispatch('click', onePlace.tabs[1]);
onePlaceItem.cost.value = '778';
dispatch('input', onePlaceItem.cost);
assert.strictEqual(onePlaceItem.weight.value, '950', 'Fitted one-place weight must survive tabs, preview scheduling, and an unrelated edit.');

onePlaceItem.weight.value = '450';
onePlaceItem.cost.value = '777';
const onePlaceCreate = context.collectShipmentData(onePlace);
assert.ok(onePlaceCreate.values.some(([name, value]) => name === 'shipment_items[0][weight]' && value === '450'), 'Immediate Create collection must preserve manual one-place weight 450.');
assert.ok(onePlaceCreate.values.some(([name, value]) => name === 'shipment_items[0][cost]' && value === '777'), 'Immediate Create collection must preserve manual one-place cost 777.');

const liveIntegerItem = new ItemRow(0, 1, 1, 1100, 1990);
const liveInteger = new Form([new PlaceRow(0, 1000)], [liveIntegerItem]);
context.updateShipmentPlaceOptions(liveInteger);
assert.match(liveInteger.summary.innerHTML, /вес места 1000 г;[\s\S]*вес товаров 1100 г/);
liveIntegerItem.weight.value = '900';
dispatch('input', liveIntegerItem.weight);
assert.match(liveInteger.summary.innerHTML, /вес товаров 900 г/, 'Actual delegated type=number input must immediately render item weight 900 before blur/change/tab.');
assert.doesNotMatch(liveInteger.summary.innerHTML, /data-error="1"/, 'Item weight 900 must immediately clear local overweight state.');
liveIntegerItem.weight.value = '1200';
dispatch('input', liveIntegerItem.weight);
assert.match(liveInteger.summary.innerHTML, /вес товаров 1200 г/, 'Second delegated type=number input must immediately render item weight 1200.');
assert.match(liveInteger.summary.innerHTML, /data-error="1"/, 'Item weight 1200 must immediately restore local overweight state.');
liveIntegerItem.weight.value = '900';
dispatch('input', liveIntegerItem.weight);
liveInteger.places[0].inputs.weight.value = '800';
dispatch('input', liveInteger.places[0].inputs.weight);
assert.match(liveInteger.summary.innerHTML, /вес места 800 г;[\s\S]*вес товаров 900 г/, 'Package type=number input must immediately render place weight 800.');
assert.match(liveInteger.summary.innerHTML, /data-error="1"/, 'Package weight 800 must immediately activate local overweight state.');
liveInteger.places[0].inputs.weight.value = '1000';
dispatch('input', liveInteger.places[0].inputs.weight);
assert.match(liveInteger.summary.innerHTML, /вес места 1000 г;[\s\S]*вес товаров 900 г/, 'Package type=number input must immediately render place weight 1000.');
assert.doesNotMatch(liveInteger.summary.innerHTML, /data-error="1"/, 'Package weight 1000 must immediately clear local overweight state.');

const equalItem1 = new ItemRow(0, 1, 1, 200, 100);
const equalItem2 = new ItemRow(1, 2, 1, 200, 100);
const equalPackages = new Form([new PlaceRow(0, 100), new PlaceRow(1, 150)], [equalItem1, equalItem2]);
context.updateShipmentPlaceOptions(equalPackages);
equalItem1.weight.value = '100';
dispatch('input', equalItem1.weight);
equalItem2.weight.value = '150';
dispatch('input', equalItem2.weight);
assert.match(equalPackages.summary.innerHTML, /Место 1:[\s\S]*вес места 100 г;[\s\S]*вес товаров 100 г/);
assert.match(equalPackages.summary.innerHTML, /Место 2:[\s\S]*вес места 150 г;[\s\S]*вес товаров 150 г/);
assert.doesNotMatch(equalPackages.summary.innerHTML, /data-error="1"/, 'Equal two-package weights must clear every local warning without a tab switch.');
const latestEqualPreview = context.scheduledPreviewPayloads[context.scheduledPreviewPayloads.length - 1];
assert.strictEqual(context.carrierCreateAvailability(equalPackages, 'courier'), true, 'CDEK equality 100/100 and 150/150 must pass the local hard gate.');
assert.strictEqual(equalPackages.draftErrors.hidden, true, 'CDEK equality must show no local draft error.');
equalItem2.weight.value = '151';
dispatch('input', equalItem2.weight);
assert.strictEqual(equalPackages.submit.disabled, true, 'CDEK Create must disable immediately when place 2 becomes overweight.');
assert.match(equalPackages.draftErrors.textContent, /Вес грузоместа 2 меньше суммы весов товаров\./, 'CDEK local gate must explain the exact overweight place.');
equalItem2.weight.value = '150';
dispatch('input', equalItem2.weight);
assert.strictEqual(equalPackages.submit.disabled, false, 'CDEK Create must re-enable immediately after place 2 becomes consistent.');

const incompleteAllocationItem = new ItemRow(0, 1, 1, 50, 100, [30, 20, 10], { orderedQuantity: 2, groupKey: 'order-item-42' });
const incompleteAllocation = new Form([new PlaceRow(0, 100)], [incompleteAllocationItem]);
context.updateCreateAvailability(incompleteAllocation);
assert.strictEqual(incompleteAllocation.submit.disabled, true, 'CDEK Create must block when ordered quantity 2 has only one allocated unit.');
assert.match(incompleteAllocation.draftErrors.textContent, /Не все товары распределены по грузоместам\./);
incompleteAllocationItem.quantity.value = '2';
dispatch('input', incompleteAllocationItem.quantity);
assert.strictEqual(incompleteAllocation.submit.disabled, false, 'Real quantity input must immediately move CDEK allocation from incomplete to complete.');
assert.strictEqual(incompleteAllocation.draftErrors.hidden, true);

const fitGateItem = new ItemRow(0, 1, 1, 1200, 100);
const fitGate = new Form([new PlaceRow(0, 1000)], [fitGateItem]);
context.updateCreateAvailability(fitGate);
assert.strictEqual(fitGate.submit.disabled, true, 'CDEK Create must start blocked for items 1200 / package 1000.');
assert.strictEqual(context.fitShipmentItemWeights(fitGate, '1'), true);
context.refreshShipmentItemsSummary(fitGate);
context.dispatchShipmentCarrierHook('afterPlacesChanged', fitGate, { reason: 'item_weights_fitted' });
assert.strictEqual(fitGateItem.weight.value, '950');
assert.strictEqual(fitGate.submit.disabled, false, 'CDEK fit to 950 must immediately clear the local hard gate.');

const manualItem = new ItemRow(1, 1, 1, 10, 100, [1, 1, 1], { manual: true, orderedQuantity: 999, groupKey: 'manual-1' });
const manualDraft = new Form([new PlaceRow(0, 100)], [new ItemRow(0, 1, 1, 50, 100), manualItem]);
assert.strictEqual(context.carrierCreateAvailability(manualDraft, 'courier'), true, 'Manual-added row must require a valid place but not invent ordered quantity 999.');

const nonCdekOverweight = new Form([new PlaceRow(0, 100)], [new ItemRow(0, 1, 1, 101, 100)], 'pek');
assert.strictEqual(context.carrierCreateAvailability(nonCdekOverweight, 'courier'), true, 'CDEK hard gate must not change another carrier create availability.');
for (const [name, value] of [
  ['places[0][weight_g]', '100'],
  ['shipment_items[0][weight]', '100'],
  ['places[1][weight_g]', '150'],
  ['shipment_items[1][weight]', '150']
]) {
  assert.ok(latestEqualPreview.values.some((entry) => entry[0] === name && entry[1] === value), `Fresh scheduled preview must contain ${name}=${value}.`);
}

assert.match(form.actions.innerHTML, />Подогнать вес товаров 1<\/button>/, 'Multi-place fit label must identify place 1.');
assert.match(form.actions.innerHTML, />Подогнать вес товаров 2<\/button>/, 'Multi-place fit label must identify place 2.');

const collapseBase = new ItemRow(0, 1, 2, 450, 777, [35, 21, 11], {
  groupKey: 'order-item-42',
  orderedQuantity: 3,
  original: { ordered_quantity: 3, amount: 3, cost: 1990, weight: 1000, length_cm: 30, width_cm: 20, height_cm: 10, place_number: 1 }
});
const collapseSplit = new ItemRow(1, 2, 1, 450, 777, [35, 21, 11], {
  groupKey: 'order-item-42',
  orderedQuantity: 3,
  split: true
});
const collapsed = new Form([new PlaceRow(0, 1000)], [collapseBase, collapseSplit]);
context.updateShipmentPlaceOptions(collapsed);
assert.strictEqual(collapsed.querySelectorAll('[data-wdc-shipment-item-row]').length, 1, 'True multi-place split collapse must remove the split row.');
assert.strictEqual(collapseBase.quantity.value, '3', 'True split collapse must restore the valid ordered quantity.');
assert.strictEqual(collapseBase.place.value, '1', 'True split collapse must leave a valid single-place selector.');
assert.strictEqual(collapseBase.weight.value, '1000', 'True split collapse must retain the existing explicit restore semantics.');

async function assertPreviewRaceProtection() {
  const pending = [];
  class PreviewFormData {
    constructor() { this.values = []; }
    append(name, value) { this.values.push([name, String(value)]); }
  }
  const previewContext = {
    console,
    window: null,
    document: { body: {} },
    FormData: PreviewFormData,
    WeakMap,
    Array,
    Object,
    String,
    Number,
    JSON,
    parseInt,
    parseFloat,
    fetch(url, options) {
      return new Promise((resolve) => pending.push({ resolve, body: options.body }));
    }
  };
  previewContext.window = previewContext;
  previewContext.window.wdcShipmentsAdmin = { ajaxUrl: '/preview', previewAction: 'preview', nonce: 'nonce' };
  vm.runInNewContext([
    fs.readFileSync('assets/admin/shipments/shipment-core.js', 'utf8'),
    fs.readFileSync('assets/admin/shipments/shipment-preview.js', 'utf8')
  ].join('\n'), previewContext);

  const previewNode = { textContent: '' };
  const warningNode = { textContent: '', dataset: {} };
  const fields = [
    new Input('places[0][weight_g]', 100),
    new Input('shipment_items[0][weight]', 200),
    new Input('places[1][weight_g]', 150),
    new Input('shipment_items[1][weight]', 200)
  ];
  const previewForm = {
    dataset: {},
    querySelector(selector) {
      if (selector === '[data-wdc-shipment-preview]') return previewNode;
      if (selector === '[data-wdc-shipment-errors]') return warningNode;
      return null;
    },
    querySelectorAll(selector) { return selector === 'input, select, textarea' ? fields : []; }
  };

  const staleRequest = previewContext.requestPreview(previewForm);
  fields[1].value = '100';
  fields[3].value = '150';
  const freshRequest = previewContext.requestPreview(previewForm);
  assert.ok(pending[1].body.values.some(([name, value]) => name === 'shipment_items[0][weight]' && value === '100'));
  assert.ok(pending[1].body.values.some(([name, value]) => name === 'shipment_items[1][weight]' && value === '150'));

  pending[1].resolve({
    text: () => Promise.resolve(JSON.stringify({ success: true, data: { preview: { errors: [], warnings: [] } } }))
  });
  await freshRequest;
  assert.strictEqual(warningNode.textContent, '', 'Latest equal-weight preview must clear server warnings.');

  pending[0].resolve({
    text: () => Promise.resolve(JSON.stringify({
      success: true,
      data: { preview: { errors: [], warnings: ['Вес грузоместа 1 меньше суммы весов товаров.', 'Вес грузоместа 2 меньше суммы весов товаров.'] } }
    }))
  });
  await staleRequest;
  assert.strictEqual(warningNode.textContent, '', 'Older out-of-order preview must not restore stale server warnings.');
}

assertPreviewRaceProtection()
  .then(() => console.log('Shipment package editor JS smoke passed.'))
  .catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
