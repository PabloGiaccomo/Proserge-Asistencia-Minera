'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/rq-mina/plan.blade.php'), 'utf8');
const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
const key = 'rq_mina_plan_draft:user:rq:plan';
const server = [{ puesto: 'Servidor', cantidad: 5 }];
const draft = { id: 'test-draft', rq_mina_id: 'rq', base: 'revision-1', detalle: [{ puesto: 'Copia', cantidad: 2 }], plan_operativo: [] };

function events(properties = {}) {
    const handlers = new Map();
    return {
        ...properties,
        addEventListener(type, callback) { handlers.set(type, callback); },
        fire(type, event = {}) { handlers.get(type)?.({ preventDefault() {}, ...event }); },
    };
}

function open({ base = 'revision-1', savedDraftId = '', copy = draft, recoveredInput = false, revision = 'revision-1', program = [], draftEnabled = true } = {}) {
    let rows = server;
    const storage = new Map(copy ? [[key, JSON.stringify(copy)]] : []);
    storage.set('another-plan', 'keep');
    const banner = { hidden: true };
    const recover = events({ hidden: false });
    const discard = events();
    const draftIdField = { value: '' };
    const revisionField = { value: revision };
    const form = events({
        dataset: { rqId: 'rq', draftKey: key, draftBase: base, savedDraftId, personnelEditorId: 'personnel', planEditorId: 'plan', recoveredInput: recoveredInput ? '1' : '0' },
        querySelector: selector => selector === '[name="plan_revision"]' ? revisionField : draftIdField,
        hasAttribute: name => name === 'data-rq-plan-draft' && draftEnabled,
    });
    const elements = {
        '#rqMinaPlanForm': form, '[data-rq-plan-draft]': draftEnabled ? form : null, '[data-rq-plan-draft-banner]': banner,
        '[data-rq-plan-draft-recover]': recover, '[data-rq-plan-draft-discard]': discard,
        '[data-rq-plan-draft-message]': {},
    };
    const ready = [];
    const document = { addEventListener: (_, callback) => ready.push(callback), querySelector: selector => elements[selector], querySelectorAll: () => [] };
    const window = events({
        rqMinaPersonnelEditors: { personnel: { getRows: () => rows, setRows: value => { rows = value; } } },
        rqMinaPlanEditors: { plan: { getPlan: () => program, setPlan() {} } },
        localStorage: { getItem: k => storage.get(k), setItem: (k, value) => storage.set(k, value), removeItem: k => storage.delete(k) },
        location: { reload: () => window.fire('beforeunload') },
        setTimeout, clearTimeout,
    });
    vm.runInNewContext(script, { window, document });
    ready.forEach(callback => callback());
    return { rows: () => rows, edit(value) { rows = value; form.fire('input'); }, form, window, banner, recover, discard, storage, draftIdField, revisionField };
}

test('shows server data until a compatible copy is explicitly recovered', () => {
    const page = open();
    assert.equal(page.rows()[0].puesto, 'Servidor');
    assert.equal(page.banner.hidden, false);
    page.recover.fire('click');
    assert.equal(page.rows()[0].puesto, 'Copia');
    assert.equal(page.window.rqMinaPlanHasUnsavedChanges(), true);
});

test('a copy from an older revision cannot overwrite newer server data', () => {
    const page = open({ base: 'revision-2' });
    assert.equal(page.recover.hidden, true);
    page.recover.fire('click');
    assert.equal(page.rows()[0].puesto, 'Servidor');
});

test('discard and subsequent unload do not recreate a copy', () => {
    const page = open();
    page.discard.fire('click');
    page.window.fire('beforeunload');
    assert.equal(page.storage.has(key), false);
});

test('confirmation removes only the submitted copy', () => {
    const page = open({ savedDraftId: 'test-draft' });
    page.window.fire('beforeunload');
    assert.equal(page.storage.has(key), false);
    assert.equal(page.storage.has('another-plan'), true);
    assert.equal(open({ savedDraftId: 'another-draft' }).storage.has(key), true);
});

test('an unconfirmed submission keeps its copy and sends its identifier', () => {
    const page = open({ copy: null });
    page.window.fire('beforeunload');
    assert.equal(page.storage.has(key), false);
    page.edit([{ puesto: 'Sin confirmar', cantidad: 4 }]);
    page.form.fire('submit');
    const submitted = JSON.parse(page.storage.get(key));
    assert.equal(submitted.detalle[0].puesto, 'Sin confirmar');
    assert.equal(submitted.id, page.draftIdField.value);
    assert.ok(submitted.id);
});

test('editing current server data keeps the new work on exit even with an older copy present', () => {
    const page = open({ base: 'revision-2' });
    page.edit([{ puesto: 'Cambios nuevos', cantidad: 8 }]);
    page.window.fire('beforeunload');
    const stored = JSON.parse(page.storage.get(key));
    assert.equal(stored.base, 'revision-2');
    assert.equal(stored.detalle[0].puesto, 'Cambios nuevos');
});

test('failed input remains pending and cannot acquire a newer revision through a browser copy', () => {
    const page = open({ copy: null, base: 'server-current', recoveredInput: true, revision: 'submitted-old' });
    assert.equal(page.window.rqMinaPlanHasUnsavedChanges(), true);
    page.form.fire('submit');
    const copy = JSON.parse(page.storage.get(key));
    assert.equal(copy.revision, 'submitted-old');

    const reopened = open({ copy, base: 'server-current', revision: 'server-current' });
    reopened.recover.fire('click');
    assert.equal(reopened.revisionField.value, 'submitted-old');
});

test('submits a large program even when draft recovery is unavailable', () => {
    const program = [{ nombre: 'Grupo de prueba', actividades: [{ sait: 'SAIT', turnos: [{ fecha: '2026-11-07', turno_a: '9' }] }] }];
    const page = open({ program, draftEnabled: false });
    const formData = new FormData();
    formData.set('plan_operativo_json', '');
    for (let i = 0; i < 1200; i++) formData.append(`plan_operativo[0][actividades][0][turnos][${i}][turno_a]`, '9');
    formData.set('detalle[0][puesto]', 'Mecánico');
    formData.set('plan_editor_complete', '1');

    page.form.fire('formdata', { formData });

    assert.deepEqual(JSON.parse(formData.get('plan_operativo_json')), program);
    assert.equal(formData.get('detalle[0][puesto]'), 'Mecánico');
    assert.equal(formData.get('plan_editor_complete'), 'json');
    assert.equal(Array.from(formData.keys()).length, 3);
});
