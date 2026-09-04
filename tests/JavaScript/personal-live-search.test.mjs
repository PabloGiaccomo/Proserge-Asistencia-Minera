import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/personal/index.blade.php', import.meta.url), 'utf8');
const start = blade.indexOf("    const simpleSearchInput = document.getElementById('personal-search');");
const end = blade.indexOf('    [sortNombre, sortDni].forEach', start);
assert.ok(start >= 0 && end > start);
const script = blade.slice(start, end);

function setup() {
    const events = {};
    const timers = new Map();
    const searches = [];
    let nextTimer = 0;
    const input = {
        value: '', removeAttribute() {}, focus() {}, setSelectionRange() {},
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    const clear = { style: {}, addEventListener() {} };
    vm.runInNewContext(script, {
        document: { getElementById: () => input, querySelector: () => clear },
        hasServerPagination: true,
        navigateServerSearch: value => searches.push(value),
        renderGrid: () => assert.fail('Server search must not filter only the current page'),
        window: {
            sessionStorage: { getItem: () => null },
            clearTimeout: id => timers.delete(id),
            setTimeout: (callback, delay) => {
                assert.equal(delay, 350);
                timers.set(++nextTimer, callback);
                return nextTimer;
            },
        },
    });
    return { input, events, timers, searches };
}

test('typing searches automatically and cancels earlier keystrokes', () => {
    const { input, events, timers, searches } = setup();
    for (const value of ['p', 'pa', 'pablo']) {
        input.value = value;
        events.input({ isComposing: false });
    }
    assert.equal(timers.size, 1);
    assert.deepEqual(searches, []);
    [...timers.values()][0]();
    assert.deepEqual(searches, ['pablo']);
});

test('deleting the query also searches without Enter', () => {
    const { input, events, timers, searches } = setup();
    input.value = '';
    events.input({ isComposing: false });
    [...timers.values()][0]();
    assert.deepEqual(searches, ['']);
});

test('Enter immediately searches and cancels the pending timer', () => {
    const { input, events, timers, searches } = setup();
    input.value = '77012755';
    events.input({ isComposing: false });
    events.keydown({ key: 'Enter', preventDefault() {} });
    assert.equal(timers.size, 0);
    assert.deepEqual(searches, ['77012755']);
});
