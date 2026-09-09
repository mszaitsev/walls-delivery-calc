'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function element() {
    return {hidden: false, disabled: false, textContent: '', handlers: {},
        addEventListener(event, callback) { this.handlers[event] = callback; }};
}
const ids = {};
for (const name of ['form', 'start', 'resume', 'cancel', 'progress']) ids['wdc-incremental-update-' + name] = element();
const summary = element(), pre = element(), progress = element();
ids['wdc-incremental-update-form'].querySelector = () => ({value: 'nonce'});
ids['wdc-incremental-update-progress'].querySelector = selector => selector === 'pre' ? pre : selector === 'progress' ? progress : summary;
const replies = [{phase: 'idle'}], requests = [], timers = new Map();
let timerId = 0;
const context = {
    document: {getElementById: id => ids[id]},
    window: {ajaxurl: '/wp-admin/admin-ajax.php', confirm: () => true},
    FormData: class extends Map {},
    setTimeout(fn) { timers.set(++timerId, fn); return timerId; },
    clearTimeout(id) { timers.delete(id); },
    async fetch(url, options) {
        assert.equal(url, '/wp-admin/admin-ajax.php');
        const action = options.body.get('action').replace('wdc_locations_incremental_update_', '');
        requests.push(action);
        assert.equal(options.body.get('wdc_locations_nonce'), 'nonce');
        if (['step', 'resume', 'cancel'].includes(action)) assert.equal(options.body.get('job_id'), 'job1');
        assert.ok(replies.length, 'Unexpected extra AJAX request');
        return {ok: true, json: async () => ({success: true, data: replies.shift()})};
    }
};
// Browser FormData accepts a form, unlike Map.
context.FormData = class { constructor() { this.values = new Map(); } set(k, v) { this.values.set(k, v); } get(k) { return this.values.get(k); } };
const settle = () => new Promise(resolve => setImmediate(resolve));
const state = phase => ({job_id: 'job1', phase, stage_label: 'Получение координат', stage_processed: 1, stage_total: 2, overall_percent: 60});
async function tick(reply) {
    replies.push(reply);
    const [id, fn] = timers.entries().next().value;
    timers.delete(id); fn(); await settle();
}
(async () => {
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/admin/locations-incremental-update.js'), 'utf8'), context);
    await settle();
    assert.deepEqual(requests, ['status']);
    replies.push(state('staging'));
    ids['wdc-incremental-update-start'].handlers.click(); await settle();
    assert.equal(timers.size, 1);
    await tick(state('enrich_coordinates'));
    assert.match(summary.textContent, /Получение координат/);
    assert.equal(progress.value, 60);
    await tick(state('waiting_dadata_limit'));
    assert.equal(timers.size, 0, 'Limit stops polling');
    assert.equal(ids['wdc-incremental-update-resume'].hidden, false);
    replies.push(state('enrich_coordinates'));
    ids['wdc-incremental-update-resume'].handlers.click(); await settle();
    await tick({...state('finished'), overall_percent: 100, applied_at: 'now'});
    assert.equal(timers.size, 0);
    assert.match(summary.textContent, /успешно завершено/);
    assert.equal(ids['wdc-incremental-update-cancel'].hidden, true);
    assert.deepEqual(requests, ['status', 'start', 'step', 'step', 'resume', 'step']);
    replies.push(state('staging'));
    ids['wdc-incremental-update-start'].handlers.click(); await settle();
    replies.push(state('canceled'));
    ids['wdc-incremental-update-cancel'].handlers.click(); await settle();
    assert.equal(timers.size, 0, 'Cancel stops polling');
    assert.equal(ids['wdc-incremental-update-start'].disabled, false);
    replies.push(state('staging'));
    ids['wdc-incremental-update-start'].handlers.click(); await settle();
    await tick({...state('failed'), failed_stage_label: 'Проверка новой базы', failed_stage: 'candidate_validate', errors: ['invalid']});
    assert.equal(timers.size, 0, 'Failure stops polling');
    assert.match(pre.textContent, /Проверка новой базы/);
    console.log('Locations one-click UI smoke passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
