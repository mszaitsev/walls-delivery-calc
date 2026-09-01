<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$script = <<<'JS'
const fs = require('fs');
const assert = require('assert');

const source = fs.readFileSync('assets/admin/shipments/extensions/ozon-delivery.js', 'utf8');
let hooks = null;

class ClassList {
  constructor() { this.values = new Set(); }
  add(value) { this.values.add(value); }
  remove(value) { this.values.delete(value); }
  contains(value) { return this.values.has(value); }
}

class FakeInput {
  constructor(value, form) {
    this.value = String(value || '');
    this.form = form;
    this.attrs = {};
    this.dataset = {};
  }
  setAttribute(name, value) { this.attrs[name] = String(value); }
  removeAttribute(name) { delete this.attrs[name]; }
  matches(selector) {
    return selector.split(',').some((part) => {
      const match = part.trim().match(/input\[name\$="\[(.+)\]"\]/);
      return match ? this.name.endsWith('[' + match[1] + ']') : false;
    });
  }
}

class FakeRow {
  constructor(number, values, form) {
    this.number = number;
    this.classList = new ClassList();
    this.attrs = {};
    this.inputs = {
      weight_g: new FakeInput(values.weightG, form),
      length_cm: new FakeInput(values.lengthCm, form),
      width_cm: new FakeInput(values.widthCm, form),
      height_cm: new FakeInput(values.heightCm, form)
    };
    Object.keys(this.inputs).forEach((key) => { this.inputs[key].name = 'places[' + (number - 1) + '][' + key + ']'; });
  }
  querySelector(selector) {
    const match = selector.match(/input\[name\$="\[(.+)\]"\]/);
    return match ? this.inputs[match[1]] || null : null;
  }
  querySelectorAll(selector) {
    if (selector === '[aria-invalid="true"]') {
      return Object.values(this.inputs).filter((input) => input.attrs['aria-invalid'] === 'true');
    }
    return [];
  }
  setAttribute(name, value) { this.attrs[name] = String(value); }
  removeAttribute(name) { delete this.attrs[name]; }
}

class FakeNode {
  constructor() {
    this.children = [];
    this.dataset = {};
    this.hidden = true;
    this._text = '';
  }
  set textContent(value) {
    this._text = String(value || '');
    this.children = [];
  }
  get textContent() {
    return this._text + this.children.map((child) => child.textContent).join('');
  }
  appendChild(child) { this.children.push(child); return child; }
}

function makeForm(rows, limits) {
  const form = {
    carrierKey: 'ozon_delivery',
    deliveryType: 'pickup',
    availabilityUpdates: 0,
    warning: new FakeNode(),
    normalizedInput: new FakeInput('', null),
    displayInput: new FakeInput('', null),
    statusNode: new FakeNode(),
    courierFields: {},
    limits: { dataset: Object.assign({
      pointFound: '1',
      minWeightG: '0',
      maxWeightG: '10000',
      maxLengthMm: '500',
      maxWidthMm: '500',
      maxHeightMm: '300'
    }, limits || {}) },
    querySelector(selector) {
      if (selector === 'input[name="carrier_key"]') return { value: this.carrierKey };
      if (selector === '[data-wdc-service-select]') {
        return { selectedIndex: 0, options: [{ value: this.deliveryType, dataset: { deliveryType: this.deliveryType } }] };
      }
      if (selector === '[data-wdc-ozon-place-limits]') return this.limits;
      if (selector === '[data-wdc-ozon-place-limit-warning]') return this.warning;
      if (selector === '[data-wdc-normalized-address-json]') return this.normalizedInput;
      if (selector === '[data-wdc-normalized-address-display]') return this.displayInput;
      if (selector === '[data-wdc-normalized-status]') return this.statusNode;
      const courierMatch = selector.match(/\[data-wdc-ozon-courier-field="(.+)"\]/);
      if (courierMatch) return this.courierFields[courierMatch[1]] || null;
      return null;
    },
    querySelectorAll(selector) {
      if (selector === '[data-wdc-place]') return this.rows;
      if (selector === '.wdc-ozon-place--invalid') return this.rows.filter((row) => row.classList.contains('wdc-ozon-place--invalid'));
      return [];
    }
  };
  form.rows = rows.map((row, index) => new FakeRow(index + 1, row, form));
  form.normalizedInput.form = form;
  form.displayInput.form = form;
  ['postcode', 'country', 'region', 'city', 'street', 'house', 'flat'].forEach((key) => {
    form.courierFields[key] = new FakeInput('', form);
  });
  return form;
}

function makeCourierForm(snapshot) {
  const form = makeForm([{ weightG: 9000, lengthCm: 50, widthCm: 30, heightCm: 20 }]);
  form.deliveryType = 'courier';
  form.normalizedInput.value = snapshot ? JSON.stringify(snapshot) : '';
  return form;
}

function validCourierSnapshot(overrides) {
  return Object.assign({
    success: true,
    service_key: 'ozon_delivery',
    fields: {
      postcode: '630099',
      country: 'Россия',
      region: 'Новосибирская область',
      city: 'г Новосибирск',
      street: 'улица Ленина',
      house: '10',
      stead: '',
      flat: '12',
      geo_lat: '55.0415',
      geo_lon: '82.9346',
      normalized_address: '630099, Новосибирская область, г Новосибирск, улица Ленина, 10'
    },
    display: '630099, Новосибирская область, г Новосибирск, улица Ленина, 10'
  }, overrides || {});
}

global.window = global;
global.document = { createElement: () => new FakeNode() };
global.registerShipmentCarrierHooks = (registered) => { hooks = registered; };
global.fieldValue = (form, selector) => selector === 'input[name="carrier_key"]' ? form.carrierKey : '';
global.findShipmentForm = (target) => target.form || null;
global.updateCreateAvailability = (form) => { form.availabilityUpdates += 1; };

eval(source);
assert.ok(hooks && typeof hooks.createAvailability === 'function', 'Ozon extension must register createAvailability hook.');

let form = makeForm([{ weightG: 9000, lengthCm: 50, widthCm: 30, heightCm: 20 }]);
assert.strictEqual(hooks.createAvailability(form), true, '9 kg and 50x30x20 must be valid.');
assert.strictEqual(form.warning.hidden, true, 'Valid parcel must hide warning.');

form = makeForm([{ weightG: 11000, lengthCm: 50, widthCm: 30, heightCm: 20 }]);
assert.strictEqual(hooks.createAvailability(form), false, '11 kg must be invalid for 10 kg max.');
assert.match(form.warning.textContent, /Грузоместо 1/);
assert.match(form.warning.textContent, /11 кг/);
assert.match(form.warning.textContent, /10 кг/);
assert.strictEqual(form.rows[0].classList.contains('wdc-ozon-place--invalid'), true);

form = makeForm([{ weightG: 9000, lengthCm: 30, widthCm: 50, heightCm: 50 }]);
assert.strictEqual(hooks.createAvailability(form), true, '30x50x50 must pass rotation-aware 50x50x30 limits.');

form = makeForm([{ weightG: 9000, lengthCm: 51, widthCm: 30, heightCm: 20 }]);
assert.strictEqual(hooks.createAvailability(form), false, '51x30x20 must exceed the longest Ozon limit.');
assert.match(form.warning.textContent, /51 × 30 × 20 см/, 'Warning must preserve integer dimension digits in the actual parcel size.');
assert.match(form.warning.textContent, /50 × 50 × 30 см/, 'Warning must preserve integer dimension digits in the selected point limits.');
assert.doesNotMatch(form.warning.textContent, /5 × 5 × 3 см/, 'Warning must not strip trailing zeroes from integer dimensions.');

form = makeForm([{ weightG: 9000, lengthCm: 40, widthCm: 40, heightCm: 40 }]);
assert.strictEqual(hooks.createAvailability(form), false, '40x40x40 must fail rotated 50x50x30 limits.');

form = makeForm([{ weightG: 9000, lengthCm: 51, widthCm: 12, heightCm: 12 }]);
assert.strictEqual(hooks.createAvailability(form), false, '51x12x12 must exceed the longest Ozon limit.');
assert.match(form.warning.textContent, /51 × 12 × 12 см/, 'Live-like 51x12x12 warning must show 51, not a truncated value.');
assert.match(form.warning.textContent, /50 × 50 × 30 см/, 'Live-like 51x12x12 warning must show the full selected point limit.');

form = makeForm([{ weightG: 9000, lengthCm: 50, widthCm: 52, heightCm: 12 }]);
assert.strictEqual(hooks.createAvailability(form), false, '50x52x12 must exceed the longest Ozon limit after sorting.');
assert.match(form.warning.textContent, /52 × 50 × 12 см/, 'Sorted warning must preserve 50 as 50.');
assert.doesNotMatch(form.warning.textContent, /52 × 5 × 12 см/, 'Sorted warning must not show 50 as 5.');

form = makeForm([{ weightG: 9000, lengthCm: 100, widthCm: 12.5, heightCm: 12.25 }], {
  maxLengthMm: '900',
  maxWidthMm: '300',
  maxHeightMm: '300'
});
assert.strictEqual(hooks.createAvailability(form), false, '100x12.5x12.25 must exceed a 90 cm longest limit.');
assert.match(form.warning.textContent, /100 × 12,5 × 12,25 см/, 'Formatter must preserve 100 and decimal significant digits.');
assert.match(form.warning.textContent, /90 × 30 × 30 см/, 'Formatter must preserve 30 in selected point limits.');
assert.doesNotMatch(form.warning.textContent, /12,50/, 'Formatter must not add artificial trailing decimal zeroes.');

form = makeForm([
  { weightG: 9000, lengthCm: 50, widthCm: 30, heightCm: 20 },
  { weightG: 12000, lengthCm: 50, widthCm: 30, heightCm: 20 },
  { weightG: 9000, lengthCm: 40, widthCm: 40, heightCm: 40 }
]);
assert.strictEqual(hooks.createAvailability(form), false, 'Any invalid Ozon place must block create.');
assert.match(form.warning.textContent, /Грузоместо 2/);
assert.match(form.warning.textContent, /Грузоместо 3/);
assert.strictEqual(form.querySelectorAll('.wdc-ozon-place--invalid').length, 2, 'Every invalid place row must be marked.');

form.rows[1].inputs.weight_g.value = '9000';
assert.strictEqual(hooks.afterPlacesChanged(form, { reason: 'input' }), false);
assert.strictEqual(form.querySelectorAll('.wdc-ozon-place--invalid').length, 1, 'Fixing one place must keep remaining violations.');
form.rows[2].inputs.length_cm.value = '30';
form.rows[2].inputs.width_cm.value = '20';
form.rows[2].inputs.height_cm.value = '10';
assert.strictEqual(hooks.afterPlacesChanged(form, { reason: 'input' }), false);
assert.strictEqual(form.warning.hidden, true, 'Fixing all violations must clear the warning.');
assert.ok(form.availabilityUpdates >= 2, 'Dynamic validation must delegate back to generic availability updates.');

form.rows.push(new FakeRow(4, { weightG: 15000, lengthCm: 10, widthCm: 10, heightCm: 10 }, form));
assert.strictEqual(hooks.createAvailability(form), false, 'Added oversized place must block create.');
form.rows.pop();
assert.strictEqual(hooks.createAvailability(form), true, 'Removing invalid place must clear create block.');

form = makeForm([{ weightG: 15000, lengthCm: 100, widthCm: 100, heightCm: 100 }]);
form.carrierKey = 'cdek';
assert.strictEqual(hooks.createAvailability(form), true, 'Non-Ozon modal must be a no-op.');
assert.strictEqual(form.warning.hidden, true, 'Non-Ozon modal must not show Ozon warnings.');

form = makeCourierForm(null);
assert.strictEqual(hooks.createAvailability(form), false, 'Ozon courier raw/legacy address must block create until normalization succeeds.');

form = makeCourierForm(validCourierSnapshot());
assert.strictEqual(hooks.createAvailability(form), true, 'Ozon courier valid trusted snapshot may enable create immediately.');

form = makeCourierForm(validCourierSnapshot({ fields: Object.assign({}, validCourierSnapshot().fields, { house: '', stead: '' }) }));
assert.strictEqual(hooks.createAvailability(form), false, 'Ozon courier DaData result without house or stead must keep create disabled.');

form = makeCourierForm(validCourierSnapshot({ success: false, message: 'DaData не вернула дом.' }));
assert.strictEqual(hooks.createAvailability(form), false, 'Ozon courier failed address normalization must keep create disabled.');

form = makeCourierForm(validCourierSnapshot());
assert.strictEqual(hooks.createAvailability(form), true, 'Ozon courier address starts valid before manager edit.');
form.normalizedInput.value = '';
assert.strictEqual(hooks.createAvailability(form), false, 'Ozon courier manager semantic edit reset must make previous normalization stale and disable create.');

form = makeCourierForm(validCourierSnapshot({ service_key: 'cdek', fields: validCourierSnapshot().fields }));
assert.strictEqual(hooks.createAvailability(form), false, 'Ozon courier create availability must require Ozon-owned normalized address state.');

console.log('Ozon Delivery modal limits JS smoke passed.');
JS;

$tmp = tempnam( sys_get_temp_dir(), 'wdc-ozon-modal-limits-' );
if ( false === $tmp ) {
	throw new RuntimeException( 'Unable to create temporary Node smoke file.' );
}
$js_file = $tmp . '.js';
rename( $tmp, $js_file );
file_put_contents( $js_file, $script );
$output = array();
$code = 0;
exec( 'node ' . escapeshellarg( $js_file ) . ' 2>&1', $output, $code );
@unlink( $js_file );
if ( 0 !== $code ) {
	throw new RuntimeException( "Ozon Delivery modal limits JS smoke failed:\n" . implode( "\n", $output ) );
}

echo implode( "\n", $output ) . "\n";
