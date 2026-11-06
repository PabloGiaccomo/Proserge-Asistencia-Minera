'use strict';

// Executes the actual plan-manager listener without a browser or a database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/rq-mina/plan.blade.php'), 'utf8');
const marker = /document\.addEventListener\('DOMContentLoaded', function \(\) \{\s+const manager = document\.querySelector\('\[data-rq-plan-manager\]'\);/;
const match = marker.exec(source);
assert.ok(match, 'The production plan-manager listener must be available.');
const script = source.slice(match.index, source.lastIndexOf('})();'));

function page(dirty) {
    const error = { hidden: true, textContent: '' };
    const submit = { disabled: false };
    const listeners = {};
    let changePlan;
    const fetches = [];
    const selector = {
        value: 'plan-1',
        addEventListener: (_, callback) => { changePlan = callback; },
    };
    const form = {
        dataset: { planForm: 'create' },
        addEventListener: (name, callback) => { listeners[name] = callback; },
        querySelector: (selector) => selector === '[type="submit"]' ? submit : error,
    };
    const manager = {
        dataset: {
            createUrl: 'https://example.test/rq-mina/rq-1/planes',
            planUrl: 'https://example.test/rq-mina/rq-1/plan?lista%5Bq%5D=prueba',
            selectedPlanId: 'plan-1',
        },
        querySelector: () => selector,
    };
    const document = {
        addEventListener: (_, callback) => { callback(); },
        querySelector: (selector) => selector === '[data-rq-plan-manager]'
            ? manager : { getAttribute: () => 'csrf-test' },
        querySelectorAll: (selector) => selector === '[data-plan-form]' ? [form] : [],
    };
    const window = {
        location: { origin: 'https://example.test', href: 'https://example.test/rq-mina/rq-1/plan' },
        rqMinaPlanHasUnsavedChanges: () => dirty,
    };
    vm.runInNewContext(script, {
        document,
        window,
        URL,
        FormData: class {
            forEach(callback) { callback('Plan de prueba', 'nombre'); }
        },
        fetch: async (...args) => {
            fetches.push(args);
            return {
                ok: true,
                json: async () => ({ ok: true, data: { redirect_url: 'https://example.test/rq-mina/rq-1/plan?plan_id=plan-2' } }),
            };
        },
    });
    return {
        error,
        submit,
        fetches,
        window,
        selector,
        selectPlan(value) {
            selector.value = value;
            changePlan();
        },
        async submitForm() {
            listeners.submit({ preventDefault() {} });
            await new Promise(resolve => setImmediate(resolve));
        },
    };
}

test('blocks plan administration before sending any request while the program has unsaved changes', async () => {
    const current = page(true);

    await current.submitForm();

    assert.equal(current.fetches.length, 0);
    assert.equal(current.error.hidden, false);
    assert.equal(current.error.textContent, 'Guarda los cambios del programa antes de administrar planes.');
    assert.equal(current.submit.disabled, false);
    assert.equal(current.window.location.href, 'https://example.test/rq-mina/rq-1/plan');
});

test('sends the plan action and preserves list filters after the saved program is clean', async () => {
    const current = page(false);

    await current.submitForm();

    assert.equal(current.fetches.length, 1);
    assert.equal(current.fetches[0][0], 'https://example.test/rq-mina/rq-1/planes');
    assert.equal(current.fetches[0][1].method, 'POST');
    assert.equal(current.fetches[0][1].headers['X-CSRF-TOKEN'], 'csrf-test');
    const destination = new URL(current.window.location.href);
    assert.equal(destination.searchParams.get('plan_id'), 'plan-2');
    assert.equal(destination.searchParams.get('lista[q]'), 'prueba');
    assert.equal(current.submit.disabled, false);
});

test('keeps the selector on the displayed plan while requesting navigation to another plan', () => {
    const current = page(true);

    current.selectPlan('plan-2');

    assert.equal(current.selector.value, 'plan-1', 'Cancelling the browser navigation must leave the selector consistent with the current form.');
    const destination = new URL(current.window.location.href);
    assert.equal(destination.searchParams.get('plan_id'), 'plan-2');
    assert.equal(destination.searchParams.get('lista[q]'), 'prueba');
});
