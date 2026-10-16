import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { findExportDniMatches } from '../../resources/js/modules/personal-export-selection.js';

const blade = readFileSync(new URL('../../resources/views/personal/export.blade.php', import.meta.url), 'utf8');
const start = blade.indexOf('    async function searchWorkers(');
const end = blade.indexOf('    function renderPreview(', start);
const source = blade.slice(start, end);

function setup(query, workers, fetchOverride) {
    const selected = new Map([['previous', { id: 'previous', dni: '00112233' }]]);
    const input = { value: query };
    let previews = 0;
    const context = {
        workerSearch: input, selectedWorkers: selected,
        workerResults: { classList: { remove() {} } },
        workerSearchRevision: 0, workerSearchAbortController: null,
        AbortController, endpoints: { workers: '/workers' },
        window: { findExportDniMatches },
        fetch: fetchOverride || (async () => ({ ok: true, json: async () => ({ workers }) })),
        renderSearchResults() {}, renderSelectedWorkers() {},
        schedulePreview: () => { previews += 1; },
    };
    vm.runInNewContext(source + '\nthis.searchWorkers = searchWorkers;', context);
    return { context, input, selected, previews: () => previews };
}

test('Enter selects all exact DNI matches, preserving earlier workers without duplicates', async () => {
    const fixture = setup('42419346 77012755 42419346 00112233', [
        { id: 'first', dni: '42419346', documento: '42419346' },
        { id: 'second', dni: '77012755', documento: '77012755' },
        { id: 'previous', dni: '00112233' },
        { id: 'other', dni: '99999999' },
    ]);
    await fixture.context.searchWorkers(true);
    assert.deepEqual([...fixture.selected.keys()], ['previous', 'first', 'second']);
    assert.equal(fixture.previews(), 1);
    await fixture.context.searchWorkers(true);
    assert.equal(fixture.selected.size, 3);
});

test('valid DNI is selected even when another number is incomplete', async () => {
    const fixture = setup('42419346 7701275', [
        { id: 'valid', dni: '42419346' }, { id: 'partial', dni: '77012755' },
    ]);
    await fixture.context.searchWorkers(true);
    assert.deepEqual([...fixture.selected.keys()], ['previous', 'valid']);
});

test('names and typing keep manual selection', async () => {
    for (const [query, select] of [['ELIDA MAMANI', true], ['42419346 77012755', false]]) {
        const fixture = setup(query, [{ id: 'found', dni: '42419346' }]);
        await fixture.context.searchWorkers(select);
        assert.deepEqual([...fixture.selected.keys()], ['previous']);
        assert.equal(fixture.previews(), 0);
    }
});

test('an old Enter response cannot select workers after the query changes', async () => {
    let respond;
    const pending = new Promise(resolve => { respond = resolve; });
    const fixture = setup('42419346 77012755', [], () => pending);
    const search = fixture.context.searchWorkers(true);
    fixture.input.value = 'OTHER NAME';
    respond({ ok: true, json: async () => ({ workers: [{ id: 'old', dni: '42419346' }] }) });
    await search;
    assert.deepEqual([...fixture.selected.keys()], ['previous']);
});

test('Enter cancels the debounce and requests selection without submitting the form', () => {
    const start = blade.indexOf("    workerSearch.addEventListener('keydown'");
    const end = blade.indexOf("    workerSearch.addEventListener('focus'", start);
    let keydown;
    const calls = [];
    vm.runInNewContext(blade.slice(start, end), {
        workerSearch: { addEventListener: (name, handler) => { keydown = handler; } },
        window: { clearTimeout: timer => calls.push(['cancel', timer]) },
        searchTimer: 7, searchWorkers: select => calls.push(['select', select]),
    });
    keydown({ key: 'Enter', preventDefault: () => calls.push(['prevent']) });
    assert.deepEqual(calls, [['prevent'], ['cancel', 7], ['select', true]]);
});
