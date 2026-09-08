const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../../assets/frontend/checkout-address-suggestions.js'), 'utf8');

function harness() {
  const nodes = [], handlers = [], timers = new Map(), requests = [];
  let timer = 0, updates = 0;
  function node(attrs = {}) {
    const n = { attrs, value: attrs.value || '', classes: new Set((attrs.class || '').split(' ')), children: [], parent: null };
    n.setSelectionRange = (a, b) => { n.caret = [a, b]; };
    nodes.push(n); return n;
  }
  const body = node(), document = { body }, window = {
    wdcPlatformAddressSuggestions: { enabled: true, min_chars: 3 },
    setTimeout(fn) { timers.set(++timer, fn); return timer; }, clearTimeout(id) { timers.delete(id); }
  };
  const row = node(), address = node({ id: 'billing_address_1', name: 'billing_address_1' });
  address.parent = row; row.children.push(address);
  const values = { billing_country: 'RU', billing_city: 'Москва', billing_state: 'Москва', billing_postcode: '123456',
    wdc_platform_location_id: '1', wdc_platform_location_fias_id: 'city-fias', wdc_platform_location_selected_source: 'database' };
  Object.entries(values).forEach(([name, value]) => node({ id: name, name, value }));
  function matches(n, selector) {
    return selector.split(',').some(s => {
      s = s.trim();
      if (s.startsWith('#')) return n.attrs.id === s.slice(1);
      if (s.startsWith('.')) return n.classes.has(s.slice(1));
      const m = s.match(/\[name="([^"]+)"\]/); return m && n.attrs.name === m[1];
    });
  }
  function event(target, name, extra = {}) {
    if (name === 'update_checkout') updates++;
    if (name === 'focus') document.activeElement = target;
    if (name === 'blur' && document.activeElement === target) document.activeElement = null;
    const e = { target, preventDefault() { this.prevented=true; }, stopPropagation() { this.stopped=true; }, ...extra };
    handlers.slice().filter(h => h.name.split('.')[0] === name && (!h.selector || matches(target, h.selector)))
      .forEach(h => h.fn.call(target, e));
    return e;
  }
  class Q {
    constructor(items) { this.items = items; this.length = items.length; items.forEach((n, i) => { this[i] = n; }); }
    first() { return new Q(this.items.slice(0, 1)); }
    filter() { return this; }
    val(v) { if (v === undefined) return this[0]?.value; this.items.forEach(n => { n.value = v; }); return this; }
    attr(k, v) { if (typeof k === 'object') { Object.entries(k).forEach(([a,b]) => this.attr(a,b)); return this; }
      if (v === undefined) return this[0]?.attrs[k]; this.items.forEach(n => { n.attrs[k] = v; }); return this; }
    parent() { return new Q(this.items.map(n => n.parent).filter(Boolean)); }
    children(s) { return new Q(this.items.flatMap(n => n.children).filter(n => matches(n,s))); }
    closest(s) { let n = this[0]; while (n && !matches(n,s)) n = n.parent; return new Q(n ? [n] : []); }
    addClass(s) { this.items.forEach(n => s.split(' ').forEach(c => n.classes.add(c))); return this; }
    removeClass(s) { this.items.forEach(n => s.split(' ').forEach(c => n.classes.delete(c))); return this; }
    hasClass(s) { return !!this[0]?.classes.has(s); }
    appendTo(q) { this.items.forEach(n => { n.parent=q[0]; q[0].children.push(n); }); return this; }
    insertAfter(q) { return this.appendTo(q.parent()); }
    empty() { this.items.forEach(n => { n.children=[]; n.html=''; }); return this; }
    html(s) { this.empty(); this.items.forEach(n => { n.html=s;
      for (const match of s.matchAll(/<button ([^>]+)>/g)) {
        const attrs = {}; for (const a of match[1].matchAll(/([\w-]+)="([^"]*)"/g)) attrs[a[1]]=a[2];
        const child=node(attrs); child.parent=n; n.children.push(child);
      }
    }); return this; }
    find(s) { return this.children(s); }
    eq(i) { return new Q(this.items[i] ? [this.items[i]] : []); }
    on(names, selector, fn) { if (typeof selector === 'function') { fn=selector; selector=null; }
      names.split(' ').forEach(name => handlers.push({ name, selector, fn, owner: this[0] })); return this; }
    off(ns) { for(let i=handlers.length-1;i>=0;i--) if(handlers[i].owner===this[0] && handlers[i].name.endsWith(ns)) handlers.splice(i,1); return this; }
    trigger(name) { this.items.forEach(n => event(n,name)); return this; }
  }
  function $(s, attrs) {
    if (typeof s === 'function') { s(); return new Q([]); }
    if (typeof s === 'string') return s.startsWith('<') ? new Q([node(attrs)]) : new Q(nodes.filter(n => matches(n,s)));
    return new Q(s ? [s] : []);
  }
  $.post = (url, data) => {
    const r = { data, done(fn) { this.success=fn; return this; }, fail(fn) { this.failure=fn; return this; },
      always(fn) { this.complete=fn; return this; }, abort() { this.aborted=true; },
      resolve(items) { this.success?.({ success:true, items }); this.complete?.(); } };
    requests.push(r); return r;
  };
  vm.runInNewContext(source, { jQuery:$, window, document });
  return { $, address, requests, event, body, document, values, get updates() { return updates; },
    flush() { const pending=[...timers.values()]; timers.clear(); pending.forEach(fn => fn()); },
    type(value) { address.value=value; event(address,'input'); },
    box() { return $('.wdc-address-autocomplete'); },
    choose(index=0) { event($('.wdc-address-autocomplete-option')[index], 'mousedown'); }
  };
}
const street = { input_value:'ул Ленина', display_label:'ул Ленина', level:'street', is_final:false, data:{} };
const house = { ...street, input_value:'ул Ленина, д 10', level:'house', is_final:true, selection_token:'opaque', data:{ house:'10' } };
let h=harness();
assert.equal(h.requests.length,0);
h.type('Ле'); h.flush(); assert.equal(h.requests.length,0);
h.type('Лени'); h.flush(); const a=h.requests[0];
h.type('Ленина'); a.resolve([street]); assert(!h.box().hasClass('is-open'), 'late response ignored during debounce');
h.flush(); h.requests[1].resolve([street]);
h.event(h.address,'keydown',{key:'ArrowDown'}); h.event(h.address,'keydown',{key:'Enter'});
assert.equal(h.address.value,street.input_value); assert.equal(h.updates,0);
h.flush(); h.type('ул Ленина 10'); h.flush(); h.requests.at(-1).resolve([house]); h.choose();
assert.equal(h.address.value,house.input_value+', ');
assert(h.box()[0].html.includes('Уточните квартиру'));
h.requests.at(-1).resolve([]); assert.equal(h.updates,1);
h.flush(); const count=h.requests.length;
h.type(house.input_value+', кв 25'); h.flush(); assert.equal(h.requests.length,count);
h.type(house.input_value+', кв 26'); h.flush(); assert.equal(h.requests.length,count);
h.event(h.address,'keydown',{key:'Tab'}); assert(!h.box().hasClass('is-open'));
Object.entries(h.values).forEach(([key,value])=>assert.equal(h.$('#'+key).val(),value,'geography immutable: '+key));
h.type('ул Ленина, д 1'); h.flush(); assert.equal(h.requests.length,count+1);
h.requests.at(-1).resolve([street,house]);
h.event(h.address,'keydown',{key:'ArrowUp'}); assert.equal(h.address.attrs['aria-activedescendant'],'wdc-address-option-1');
h.event(h.address,'keydown',{key:'Escape'}); assert(!h.box().hasClass('is-open'));
h.type('Свободный адрес'); h.event(h.address,'blur'); h.flush(); assert.equal(h.address.value,'Свободный адрес');
for (const [field,value] of [['billing_country','BY'],['billing_country','KZ'],['wdc_platform_location_selected_source','manual']]) {
  h=harness(); h.$('#'+field).val(value); h.type('Ленина'); h.flush(); assert.equal(h.requests.length,0);
}
h=harness(); h.$('#wdc_platform_location_id').val(''); h.$('#wdc_platform_location_fias_id').val(''); h.type('Ленина'); h.flush(); assert.equal(h.requests.length,0);
h=harness(); h.type('Ленина'); h.flush(); h.$('#wdc_platform_location_selected_source').val('manual'); h.event(h.body,'wdc:location-selected'); h.requests[0].resolve([house]); assert(!h.box().hasClass('is-open'));
h=harness(); for(let i=0;i<3;i++) h.event(h.body,'updated_checkout'); h.type('Ленина'); h.flush(); assert.equal(h.requests.length,1,'one handler after refresh');
h.requests[0].resolve([house]); h.choose(); h.event(h.$('.wdc-address-autocomplete-helper')[0],'mousedown'); assert.equal(h.address.value,house.input_value); assert(!h.box().hasClass('is-open'));
assert(!source.includes('wdc-address-picker'));
h=harness(); h.type('Ленина'); h.flush(); h.requests[0].resolve([]); assert(h.box()[0].html.includes('Подходящих адресов не найдено'));
h.type('Ленина 10'); h.flush(); h.requests[1].failure({},'error'); assert(h.box()[0].html.includes('Не удалось загрузить подсказки'));
h.type(''); h.flush(); assert(!h.box().hasClass('is-open'));
h=harness(); h.type('Ленина'); h.flush(); h.$('#wdc_platform_location_id').val('2'); h.event(h.body,'wdc:location-selected'); h.requests[0].resolve([street]); assert(!h.box().hasClass('is-open')); assert.equal(h.address.value,'');
for (const alias of ['кв','кв.','квартира','офис','оф.','пом','пом.','помещение','п','п.','каб','кабинет','ап','апарт','апартамент','апартаменты']) {
  for (const room of ['2','2А','12-Н','4/1']) {
    h=harness(); h.type('Красный проспект 13 '+alias+' '+room); h.flush();
    assert.equal(h.requests[0].data.query,'Красный проспект 13',alias+' '+room);
    assert.equal(h.address.value,'Красный проспект 13 '+alias+' '+room);
    h.requests[0].resolve([house]); h.choose(); assert.equal(h.address.value,house.input_value+', '+alias+' '+room);
    const count=h.requests.length; h.type(house.input_value+', '+alias+' 44'); h.flush(); assert.equal(h.requests.length,count);
  }
}
for (const query of ['Красный п проезд 13','Красный 13 кв 2 далее','Красный 13 пятый','проспект 13']) {
  h=harness(); h.type(query); h.flush(); assert.equal(h.requests[0].data.query,query);
}
h=harness(); h.type('Красный 13 Офис 22'); h.flush(); h.requests[0].resolve([house]); h.choose(); assert.equal(h.address.value,house.input_value+', Офис 22');
h=harness(); h.type('Красный 13 кв 2'); h.flush(); h.requests[0].resolve([street]); h.choose(); assert.equal(h.address.value,street.input_value); assert.equal(h.$('#billing_dadata_status').val(),'street_selected');
for (const action of ['Enter','Tab','outside','blur','helper']) {
  h=harness(); h.type('Ленина'); h.flush();
  assert(h.box()[0].html.includes('wdc-address-autocomplete-spinner'));
  h.requests[0].resolve([house]); assert(!h.box()[0].html.includes('spinner')); h.choose();
  let event;
  if (action==='outside') h.event(h.body,'mousedown');
  else if (action==='helper') h.event(h.$('.wdc-address-autocomplete-helper')[0],'mousedown');
  else event=h.event(h.address,action==='blur'?'blur':'keydown',{key:action});
  assert.equal(h.address.value,house.input_value,action);
  assert(!h.box().hasClass('is-open'));
  if(action==='Enter') assert(event.prevented && event.stopped);
  if(action==='Tab') assert(!event.prevented);
  if(!['Tab','blur'].includes(action)) assert.notEqual(h.document.activeElement,h.address);
}
h=harness(); h.type('Ленина'); h.flush(); h.address.value='ул Ленина, д 10, '; h.event(h.address,'focus');
const enter=h.event(h.address,'keydown',{key:'Enter'}); assert(enter.prevented && enter.stopped); assert(h.requests[0].aborted);
h.requests[0].resolve([house]); assert(!h.box().hasClass('is-open')); assert.equal(h.address.value,house.input_value);
h=harness(); h.address.value=house.input_value+', кв 2'; h.event(h.body,'mousedown'); assert.equal(h.address.value,house.input_value+', кв 2');
for (const [field,value] of [['billing_country','KZ'],['wdc_platform_location_selected_source','manual'],['wdc_platform_location_id','22']]) {
  h=harness(); h.type('Ленина'); h.flush(); h.$('#'+field).val(value); h.event(h.body,'updated_checkout'); assert.equal(h.address.value,''); assert(h.requests[0].aborted); assert.equal(h.$('#billing_dadata_house').val(),'');
}
h=harness(); h.address.value='Красный проспект 13'; h.event(h.body,'updated_checkout'); assert.equal(h.address.value,'Красный проспект 13');
console.log('Checkout address autocomplete smoke passed.');
