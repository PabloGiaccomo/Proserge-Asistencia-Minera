import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/personal/index.blade.php', import.meta.url), 'utf8');
const names = ['normalizeText', 'openActivateWorker', 'openActivateWorkerFromRow'];
const script = names.map(name => {
    const match = blade.match(new RegExp(`^function ${name}\\([^]*?^}`, 'm'));
    assert.ok(match, `${name} must be available outside the filter callback`);
    return match[0];
}).join('\n');

function setup() {
    const fields = new Map();
    for (const match of blade.matchAll(/id="(activate\w+)"/g)) {
        fields.set(match[1], { value: '', textContent: '', options: [], dispatchEvent() {}, focus() {} });
    }
    fields.set('activatePuesto', { value: '', dispatchEvent() {} });
    const options = {
        activateTipoContrato: ['Indeterminado'],
        activateBanco: ['BCP', 'Interbank', 'Otro'],
        activateSistemaPensionario: ['Sistema Nacional de Pensiones'],
    };
    for (const [id, values] of Object.entries(options)) {
        fields.get(id).options = values.map(value => ({ value, textContent: value }));
    }
    fields.get('activateWorkerForm').reset = () => fields.forEach(field => { field.value = ''; });
    const opened = [];
    const toasts = [];
    const context = vm.createContext({
        document: { getElementById: id => fields.get(id) },
        Event,
        window: { setTimeout: callback => callback() },
        todayForActivation: '2026-08-31',
        openModal: id => opened.push(id),
        showToast: (...args) => toasts.push(args),
        fetch: () => assert.fail('Opening the modal must not submit a reactivation'),
    });
    vm.runInContext(script, context);
    return { context, fields, opened, toasts };
}

test('the row action opens the form and prefills the next contract without submitting', () => {
    const { context, fields, opened, toasts } = setup();
    context.openActivateWorkerFromRow({ dataset: { worker: JSON.stringify({
        id: 'worker-test', nombre: 'TRABAJADOR DE PRUEBA',
        reactivacion_datos: { tipo_contrato: 'INDETERMINADO', banco: 'interbank', puesto: 'Puesto de prueba' },
    }) } });
    assert.deepEqual(opened, ['activateWorkerModal']);
    assert.deepEqual(toasts, []);
    assert.equal(fields.get('activateWorkerForm').action, '/personal/worker-test/activar');
    assert.equal(fields.get('activateTipoContrato').value, 'Indeterminado');
    assert.equal(fields.get('activateBanco').value, 'Interbank');
    assert.equal(fields.get('activatePuesto').value, 'Puesto de prueba');
    assert.equal(fields.get('activateFechaInicio').value, '2026-08-31');
});

test('opening another worker clears previous values and supports another bank', () => {
    const { context, fields } = setup();
    context.openActivateWorker({ id: 'first', contrato: 'Indeterminado' });
    context.openActivateWorker({ id: 'second', contrato: 'Unknown', reactivacion_datos: { banco: 'Banco de prueba' } });
    assert.equal(fields.get('activateTipoContrato').value, '');
    assert.equal(fields.get('activateBanco').value, 'Otro');
    assert.equal(fields.get('activateBancoOtro').value, 'Banco de prueba');
});

test('invalid row data produces a visible error', () => {
    const { context, opened, toasts } = setup();
    context.openActivateWorkerFromRow({ dataset: { worker: '{invalid' } });
    assert.equal(opened.length, 0);
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0][1], 'error');
});

test('the shared normalizer preserves filter behavior for accents and whitespace', () => {
    const { context } = setup();
    assert.equal(context.normalizeText('  T\u00c9CNICO / MEC\u00c1NICO  '), 'tecnico mecanico');
    assert.equal(context.normalizeText(null), '');
});
