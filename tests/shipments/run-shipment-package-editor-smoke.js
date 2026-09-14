const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const context = {
  console,
  window: null,
  document: { body: {}, createElement: () => ({}) },
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
vm.runInNewContext([
  fs.readFileSync('assets/admin/shipments/shipment-core.js', 'utf8'),
  fs.readFileSync('assets/admin/shipments/shipment-allocation.js', 'utf8')
].join('\n'), context);

class Input {
  constructor(name, value) {
    this.name = name;
    this.value = String(value);
    this.disabled = false;
    this.type = 'text';
    this.checked = true;
  }
  closest() { return null; }
}

class ItemRow {
  constructor(index, placeNumber, quantity, weight, cost) {
    this.index = index;
    this.place = new Input(`shipment_items[${index}][place_number]`, placeNumber);
    this.quantity = new Input(`shipment_items[${index}][amount]`, quantity);
    this.weight = new Input(`shipment_items[${index}][weight]`, weight);
    this.cost = new Input(`shipment_items[${index}][cost]`, cost);
  }
  querySelector(selector) {
    if (selector === '[data-wdc-shipment-place-select]') return this.place;
    if (selector === '[data-wdc-shipment-item-qty]') return this.quantity;
    if (selector === 'input[name$="[weight]"]') return this.weight;
    if (selector === 'input[name$="[cost]"]') return this.cost;
    return null;
  }
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
  constructor(places, items) {
    this.places = places;
    this.items = items;
    this.summary = { innerHTML: '' };
    this.actions = { innerHTML: '' };
  }
  querySelector(selector) {
    if (selector === '[data-wdc-shipment-items-summary]') return this.summary;
    if (selector === '[data-wdc-fit-item-weight-actions]') return this.actions;
    return null;
  }
  querySelectorAll(selector) {
    if (selector === '[data-wdc-place]') return this.places;
    if (selector === '[data-wdc-shipment-item-row]') return this.items;
    if (selector === '[data-wdc-shipment-place-select]') return this.items.map((item) => item.place);
    if (selector === 'input, select, textarea') {
      return this.places.flatMap((place) => Object.values(place.inputs))
        .concat(this.items.flatMap((item) => [item.place, item.quantity, item.weight, item.cost]));
    }
    return [];
  }
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

console.log('Shipment package editor JS smoke passed.');
