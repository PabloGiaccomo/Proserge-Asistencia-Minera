import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/personal/index.blade.php', import.meta.url), 'utf8');
const start = blade.indexOf("    const simpleSearchInput = document.getElementById('personal-search');");
const end = blade.indexOf('    [sortNombre, sortDni].forEach', start);
assert.ok(start >= 0 && end > start);
const script = blade.slice(start, end);

test('server search loads results without navigating the document', () => {
    const start = blade.indexOf('    const navigateServerSearch = function (query) {');
    const end = blade.indexOf('    let serverGridFilterTimer', start);
    const loads = [];
    const location = { href: 'http://localhost/personal?puestos%5B%5D=OPERARIO&page=3' };
    const originalUrl = location.href;
    const context = {
        URL, pageSize: 25, serverPagination: { perPage: 25 },
        clearViewState() {}, markInternalPersonalNavigation() {},
        liveResults: { load: url => loads.push(url.toString()) },
        window: { location, sessionStorage: { setItem() {} } },
    };
    vm.runInNewContext(blade.slice(start, end) + '\nnavigateServerSearch("77012755 45678923");', context);
    assert.equal(location.href, originalUrl);
    assert.equal(loads.length, 1);
    const url = new URL(loads[0]);
    assert.equal(url.searchParams.get('q'), '77012755 45678923');
    assert.equal(url.searchParams.get('puestos[]'), 'OPERARIO');
    assert.equal(url.searchParams.get('page'), '1');
});

test('pagination during a pending query uses current input and resets to its first page', () => {
    const start = blade.indexOf('    const navigateServerPage = function (page, nextPageSize) {');
    const end = blade.indexOf('    const navigateServerSearch', start);
    const loads = [];
    vm.runInNewContext(blade.slice(start, end) + '\nnavigateServerPage(2, 50);', {
        URL, pageSize: 25, serverPagination: { perPage: 25 }, simpleSearchTimer: 1,
        searchInput: { value: '77012755 45678923' },
        markInternalPersonalNavigation() {},
        liveResults: { load: url => loads.push(url.toString()) },
        window: { location: { href: 'http://localhost/personal?q=old' }, clearTimeout() {} },
    });
    const url = new URL(loads[0]);
    assert.equal(url.searchParams.get('q'), '77012755 45678923');
    assert.equal(url.searchParams.get('page'), '1');
    assert.equal(url.searchParams.get('per_page'), '50');
});

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
        liveResults: { cancel() {} },
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
