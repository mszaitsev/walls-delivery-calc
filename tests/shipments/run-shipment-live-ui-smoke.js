const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

function element() {
  return {
    dataset: {},
    style: {},
    hidden: false,
    textContent: '',
    classList: { add() {}, remove() {}, toggle() {} },
    setAttribute(name, value) { this[name] = String(value); },
    removeAttribute(name) { delete this[name]; }
  };
}

const message = element();
const toast = element();
toast.hidden = true;
const summary = element();
const carrier = element();
const documents = new Map();
const actionsContainer = element();
actionsContainer.appendChild = function (link) { documents.set(link.dataset.actionKey, link); };
actionsContainer.insertBefore = actionsContainer.appendChild;

const box = {
  dataset: {},
  querySelector(selector) {
    if (selector === '[data-wdc-shipment-status-message]') return message;
    if (selector === '[data-wdc-shipment-toast]') return toast;
    if (selector === '[data-wdc-shipment-summary-status]') return summary;
    if (selector === '[data-wdc-status-carrier]') return carrier;
    if (selector === '.wdc-shipments-actions') return actionsContainer;
    const action = selector.match(/data-action-key="([^"]+)"/);
    return action ? documents.get(action[1]) || null : null;
  },
  querySelectorAll(selector) {
    return selector === '[data-wdc-shipment-document-download]' ? Array.from(documents.values()) : [];
  },
  appendChild() {}
};

const fakeDocument = {
  body: { appendChild() {} },
  addEventListener() {},
  createElement(tag) {
    const node = element();
    node.tagName = String(tag).toUpperCase();
    node.click = function () { node.clicked = true; };
    node.remove = function () {};
    return node;
  },
  createTextNode(value) { return { textContent: String(value) }; }
};

const context = {
  console,
  document: fakeDocument,
  window: null,
  navigator: {},
  WeakMap,
  Map,
  Set,
  URL,
  FormData: class { append() {} },
  fetch: null,
  setTimeout: () => 1,
  clearTimeout: () => {},
  Blob
};
context.window = context;
context.window.CSS = { escape: (value) => String(value) };
context.window.URL = {
  createObjectURL() { return 'blob:test'; },
  revokeObjectURL() {}
};
context.window.wdcShipmentsAdmin = {};

vm.runInNewContext([
  fs.readFileSync('assets/admin/shipments/shipment-core.js', 'utf8'),
  fs.readFileSync('assets/admin/shipments/shipment-status.js', 'utf8'),
  fs.readFileSync('assets/admin/shipments/shipment-events.js', 'utf8')
].join('\n'), context);

async function run() {
  context.showShipmentError(box, 'Старая ошибка');
  assert.strictEqual(message.dataset.status, 'error');
  assert.strictEqual(toast.hidden, false);
  context.renderShipmentStatus(box, {
    shipment_status_label: 'доставлен',
    carrier_status_title: 'DELIVERED',
    has_shipment: true,
    can_update_status: true
  });
  assert.strictEqual(summary.textContent, 'доставлен', 'Fresh successful status render must update universal shipment status.');
  assert.strictEqual(message.textContent, '', 'Fresh successful operation must clear stale shipment error immediately.');
  assert.strictEqual(toast.hidden, true, 'Fresh successful operation must clear stale error toast without reload.');

  context.updateShipmentButtons(box, {
    hasShipment: true,
    canUpdate: true,
    documentActions: [{ key: 'ozon_label_1', label: 'Скачать этикетку', visible: true, download_url: '/label' }]
  });
  assert.ok(documents.has('ozon_label_1') && documents.get('ozon_label_1').hidden === false, 'Fresh status payload must add newly available document action live.');
  context.updateShipmentButtons(box, { hasShipment: true, canUpdate: true, documentActions: [] });
  assert.strictEqual(documents.get('ozon_label_1').hidden, true, 'Fresh status payload must hide document action that is no longer available.');

  const link = documents.get('ozon_label_1');
  link.dataset.downloadUrl = '/label';
  link.closest = () => box;
  context.fetch = () => Promise.resolve({
    ok: false,
    headers: { get: (name) => name === 'content-type' ? 'application/json' : '' },
    text: () => Promise.resolve(JSON.stringify({ success: false, data: { message: 'Не удалось скачать этикетку Ozon.' } }))
  });
  await context.requestShipmentDocument(link);
  assert.strictEqual(message.textContent, 'Не удалось скачать этикетку Ozon.', 'Document failure must stay inside the shipment block as a safe error.');

  context.fetch = () => Promise.resolve({
    ok: true,
    headers: { get: (name) => name === 'content-type' ? 'application/pdf' : (name === 'content-disposition' ? 'attachment; filename="ozon-82388.pdf"' : '') },
    blob: () => Promise.resolve(new Blob(['pdf'], { type: 'application/pdf' }))
  });
  await context.requestShipmentDocument(link);
  assert.strictEqual(message.textContent, '', 'Successful document operation must clear the previous document error.');
}

run()
  .then(() => console.log('Shipment live UI smoke passed.'))
  .catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
