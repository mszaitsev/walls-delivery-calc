(function () {
    'use strict';
    const form = document.getElementById('wdc-incremental-update-form');
    if (!form) return;
    const start = document.getElementById('wdc-incremental-update-start');
    const resume = document.getElementById('wdc-incremental-update-resume');
    const cancel = document.getElementById('wdc-incremental-update-cancel');
    const box = document.getElementById('wdc-incremental-update-progress');
    const summary = box.querySelector('.wdc-incremental-update-analysis');
    let job = {phase: 'idle'}, timer = null, busy = false, stopping = false;
    const terminal = phase => ['idle', 'finished', 'failed', 'canceled', 'cancelled'].includes(phase);
    const waiting = phase => ['waiting_dadata_limit', 'waiting_cache_clear'].includes(phase);

    async function request(action, data) {
        data = data || new FormData();
        data.set('action', 'wdc_locations_incremental_update_' + action);
        data.set('wdc_locations_nonce', form.querySelector('[name="wdc_locations_nonce"]').value);
        if (action !== 'start' && job.job_id) data.set('job_id', job.job_id);
        const response = await fetch(window.ajaxurl, {method: 'POST', credentials: 'same-origin', body: data});
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.errors || ['Не удалось выполнить запрос.']).join(' '));
        return payload.data;
    }

    function render(value) {
        job = value;
        box.hidden = job.phase === 'idle';
        start.disabled = busy || !terminal(job.phase);
        resume.hidden = !waiting(job.phase);
        cancel.hidden = terminal(job.phase) || Boolean(job.applied_at);
        resume.disabled = busy;
        cancel.disabled = busy || stopping;
        const progress = box.querySelector('progress');
        progress.max = 100;
        progress.value = Number(job.overall_percent || 0);
        summary.textContent = (job.phase === 'finished' ? 'Обновление базы успешно завершено.' : (job.stage_label || '')) +
            '\n' + Number(job.stage_processed || 0) + ' / ' + Number(job.stage_total || 0) +
            '\nБыло: ' + Number(job.current_count || 0) + '; Стало: ' + Number(job.candidate_count || 0) +
            '; Добавлено: ' + Number(job.new_count || 0) + '; Удалено: ' + Number(job.removed_count || 0) + '; Изменено: ' + Number(job.changed_count || 0);
        const lines = [];
        [['postcode', 'Почтовые индексы'], ['coordinates', 'Координаты'], ['russianpost', 'Курьерские индексы']].forEach(([key, label]) => {
            lines.push(label + ': обработано ' + Number(job[key + '_processed'] || 0) + ', обновлено ' + Number(job[key + '_updated'] || 0) +
                ', пропущено/без индекса ' + (Number(job[key + '_skipped'] || 0) + Number(job[key + '_no_index'] || 0)) + ', ошибок ' + Number(job[key + '_errors'] || 0));
        });
        lines.push('Изменения по полям: ' + JSON.stringify(job.changed_by_field || {}));
        if (job.failed_stage) lines.push('Стадия ошибки: ' + (job.failed_stage_label || job.failed_stage));
        if (job.last_diagnostic) lines.push(job.last_diagnostic);
        (job.errors || []).forEach(error => lines.push(String(error)));
        box.querySelector('pre').textContent = lines.join('\n');
    }

    function schedule() {
        clearTimeout(timer);
        if (!stopping && !terminal(job.phase) && !waiting(job.phase)) timer = setTimeout(() => run('step'), 250);
    }

    async function run(action, data) {
        if (busy) return;
        clearTimeout(timer);
        busy = true;
        render(job);
        try {
            const result = await request(action, data);
            busy = false;
            render(result);
            schedule();
        } catch (error) {
            busy = false;
            box.hidden = false;
            box.querySelector('pre').textContent = error.message + '\nОбновите страницу для проверки сохранённой задачи.';
            start.disabled = true;
            cancel.disabled = false;
        }
    }

    start.addEventListener('click', () => {
        if (!window.confirm('Новый GAR CSV будет проанализирован и после успешных проверок автоматически заменит российскую часть базы населенных пунктов. Продолжить?')) return;
        stopping = false;
        run('start', new FormData(form));
    });
    resume.addEventListener('click', () => { stopping = false; run('resume'); });
    cancel.addEventListener('click', () => { stopping = true; run('cancel'); });
    // Restore the saved job after reload without restarting a paused API stage.
    run('status');
}());
