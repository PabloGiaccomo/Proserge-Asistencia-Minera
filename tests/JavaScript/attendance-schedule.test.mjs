import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/mi-asistencia/show.blade.php', import.meta.url), 'utf8');
const start = blade.indexOf('    var schedule = JSON.parse');
const end = blade.indexOf('    /* ---------- Lector de DNI', start);
assert.ok(start > 0 && end > start);
const script = blade.slice(start, end).replace('@json((bool) $canRegister)', 'true');

function setup() {
    let elapsed = 0;
    const button = { disabled: false };
    const markAll = { disabled: false };
    const notice = { textContent: '', hidden: true };
    const worker = { dataset: { detailId: 'worker', currentState: 'PENDIENTE' }, querySelector: () => badge };
    const badge = { textContent: '', hidden: true };
    const schedule = { servidor: '2026-08-19T06:59:59-05:00', limite: '2026-08-19T07:00:00-05:00', permite_presente: true, vencido: false, mensaje: '' };
    const context = vm.createContext({
        performance: { now: () => elapsed },
        root: {
            dataset: { schedule: JSON.stringify(schedule), statusUrl: '/estado', closed: '0' },
            querySelector: () => null,
            querySelectorAll: selector => selector === '[data-worker]' ? [worker] : [button],
        },
        document: { hidden: false, getElementById: () => notice, addEventListener() {} },
        window: { setInterval() {}, clearInterval() {}, addEventListener() {} },
        markAll, processingCode: false, attendanceRevision: 0, refreshCounts() {},
        fetch: async () => ({ ok: true, json: async () => ({ horario: { ...schedule, vencido: true, permite_presente: false }, cerrado: false, integrantes: [{ id: 'worker', estado: 'AUSENTE', hora: '07:00:01' }] }) }),
    });
    vm.runInContext(script, context);
    return { context, button, markAll, notice, worker, badge, advance: ms => { elapsed = ms; } };
}

test('present and all are disabled at the cutoff using elapsed server time', () => {
    const app = setup();
    assert.equal(app.button.disabled, false);
    app.advance(1001);
    app.context.updateScheduleControls();
    assert.equal(app.button.disabled, true);
    assert.equal(app.markAll.disabled, true);
    assert.equal(app.notice.hidden, false);
    assert.match(app.notice.textContent, /Horario vencido/);
});

test('polling displays automatic absence without reloading or closing the camera', async () => {
    const app = setup();
    await app.context.refreshAttendance();
    assert.equal(app.worker.dataset.currentState, 'AUSENTE');
    assert.equal(app.badge.textContent, '07:00');
    assert.equal(app.badge.hidden, false);
    assert.equal(app.button.disabled, true);
});

test('stale polling cannot overwrite a newer manual registration', async () => {
    const app = setup();
    const originalFetch = app.context.fetch;
    app.context.fetch = async () => {
        app.context.attendanceRevision++;
        app.worker.dataset.currentState = 'TARDANZA';
        return originalFetch();
    };
    await app.context.refreshAttendance();
    assert.equal(app.worker.dataset.currentState, 'TARDANZA');
});

test('a disconnected network keeps the last state and permits another refresh', async () => {
    const app = setup();
    app.context.fetch = async () => { throw new Error('offline'); };
    await app.context.refreshAttendance();
    assert.equal(app.worker.dataset.currentState, 'PENDIENTE');
    assert.equal(app.context.refreshingAttendance, false);
});
