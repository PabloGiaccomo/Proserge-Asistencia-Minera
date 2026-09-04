import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/views/epps/partials/workspace.blade.php', import.meta.url), 'utf8');
const start = source.indexOf('    let lastDeliveryRequest = 0;');
const end = source.indexOf('    modalRoot.querySelectorAll', start);
assert.ok(start >= 0 && end > start);

function setup() {
    const pending = [];
    const rendered = [];
    const hidden = { value: 'worker-a' };
    const context = vm.createContext({
        hidden, eppSelect: { value: 'epp-a' }, kardex: {}, kardexStatus: {},
        lastDeliveryUrl: '/epps/entregas/ultima',
        window: { location: { origin: 'https://example.test' } },
        URL, AbortController,
        setKardexLoading() {}, resetKardex() {},
        renderKardex: data => rendered.push(data),
        fetch: (url, options) => {
            assert.equal(options.cache, 'no-store');
            return new Promise(resolve => pending.push(data => resolve({ ok: true, json: async () => ({ data }) })));
        },
    });
    vm.runInContext(source.slice(start, end), context);
    return { context, hidden, pending, rendered };
}

test('an older response cannot replace the current worker history', async () => {
    const { context, hidden, pending, rendered } = setup();
    const old = vm.runInContext('refreshLastDelivery()', context);
    hidden.value = 'worker-b';
    const current = vm.runInContext('refreshLastDelivery()', context);
    pending[1]({ id: 'latest-b' });
    await current;
    pending[0]({ id: 'old-a' });
    await old;
    assert.deepEqual(rendered, [{ id: 'latest-b' }]);
});

test('clearing the worker invalidates an outstanding history response', async () => {
    const { context, hidden, pending, rendered } = setup();
    const request = vm.runInContext('refreshLastDelivery()', context);
    hidden.value = '';
    vm.runInContext('invalidateLastDelivery()', context);
    pending[0]({ id: 'old-a' });
    await request;
    assert.deepEqual(rendered, []);
});

test('selecting only the worker requests their latest delivery without an EPP filter', async () => {
    const { context, pending, rendered } = setup();
    vm.runInContext("eppSelect.value = ''", context);
    const request = vm.runInContext('refreshLastDelivery()', context);
    assert.equal(pending.length, 1);
    pending[0]({ id: 'last-returned', estado: 'DEVUELTO' });
    await request;
    assert.deepEqual(rendered, [{ id: 'last-returned', estado: 'DEVUELTO' }]);
});
