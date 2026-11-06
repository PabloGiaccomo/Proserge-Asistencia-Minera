'use strict';

// Run with: node --test tests/Browser/rq-mina-plan-calendar.cjs
// Executes the actual Blade HTML generator without Laravel, a database or a browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/rq-mina/partials/plan-operativo-editor.blade.php'), 'utf8');
const start = source.indexOf('    function escapeHtml(value) {');
const end = source.indexOf('    function getPlanSaits() {', start);
assert.ok(start >= 0 && end > start, 'The actual activity template must be available.');

function render(turnos, readOnly = false, activity = {}, range = { inicio: '2026-11-02', fin: '2026-11-09' }) {
    const generator = new Function('readOnly', 'collapsedActivities', 'root', source.slice(start, end)
        + '\nconst calendar = calendarDays(paradaFechas());\nreturn activityTemplate;')(
        readOnly, new Set(), { dataset: { fechaInicio: range.inicio, fechaFin: range.fin } },
    );
    return generator(0, 0, { id: 'activity-1', client_key: 'client-1', ...activity, turnos });
}

function decode(value) {
    return value.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

function schedule(html) {
    const table = html.match(/<div class="rq-plan-schedule"><table>[\s\S]*?<tbody>([\s\S]*?)<\/tbody>/);
    assert.ok(table, 'The generated activity must expose its schedule.');
    return Array.from(table[1].matchAll(/<tr([^>]*)>([\s\S]*?)<\/tr>/g), ([, attributes, row]) => {
        const fields = {};
        for (const [input] of row.matchAll(/<input\b[^>]*>/g)) {
            const name = input.match(/\bname="([^"]*)"/);
            const value = input.match(/\bvalue="([^"]*)"/);
            assert.ok(name && value, 'Schedule controls must have a name and value.');
            fields[name[1].match(/\[([^\]]+)\]$/)[1]] = decode(value[1]);
        }
        const heading = row.match(/<th(?:\s[^>]*)?>([\s\S]*?)<input/)[1].replace(/<\/?time\b[^>]*>/g, '');
        return {
            fields, heading: decode(heading), html: row,
            dataset: {
                saved: attributes.match(/data-saved="([^"]*)"/)[1],
                initialA: decode(attributes.match(/data-initial-a="([^"]*)"/)[1]),
                initialB: decode(attributes.match(/data-initial-b="([^"]*)"/)[1]),
            },
        };
    });
}

function values(html) {
    return schedule(html).map(row => row.fields);
}

function submittedTurns(html, changes = {}) {
    const preservedTurnos = decode(html.match(/data-preserved-turnos="([^"]*)"/)[1]);
    const rows = schedule(html).map(({ fields, dataset }) => ({
        dataset,
        querySelector: selector => ({ value: (changes[fields.fecha]?.[selector.slice(9, -3)] ?? fields[selector.slice(9, -3)]) }),
    }));
    const scope = { dataset: { preservedTurnos }, querySelectorAll: () => rows };
    const collectStart = source.indexOf('    function fieldValue(');
    const collectEnd = source.indexOf('    function syncFromDom()', collectStart);
    return new Function('activityEl', source.slice(collectStart, collectEnd) + '\nreturn collectTurnos(activityEl);')(scope);
}

test('preserves eight dated shifts, including two different Mondays and their actual values', () => {
    const input = [
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '5', real_turno_a: '4', turno_b: '3', real_turno_b: '2' },
        { fecha: '2026-11-03', dia_label: 'Mar', turno_a: '6', real_turno_a: '5', turno_b: '4', real_turno_b: '3' },
        { fecha: '2026-11-04', dia_label: 'Mie', turno_a: '7', real_turno_a: '6', turno_b: '5', real_turno_b: '4' },
        { fecha: '2026-11-05', dia_label: 'Jue', turno_a: '8', real_turno_a: '7', turno_b: '6', real_turno_b: '5' },
        { fecha: '2026-11-06', dia_label: 'Vie', turno_a: '9', real_turno_a: '8', turno_b: '7', real_turno_b: '6' },
        { fecha: '2026-11-07', dia_label: 'Sab', turno_a: '10', real_turno_a: '9', turno_b: '8', real_turno_b: '7' },
        { fecha: '2026-11-08', dia_label: 'Dom', turno_a: '11', real_turno_a: '10', turno_b: '9', real_turno_b: '8' },
        { fecha: '2026-11-09', dia_label: 'Lun', turno_a: '12', real_turno_a: '11', turno_b: '10', real_turno_b: '9' },
    ];

    const generated = values(render(input));
    assert.deepEqual(generated, input);
    assert.deepEqual(values(render(generated)), input, 'Rendering the submitted values again must preserve the same calendar.');
});

test('does not relabel a second dated Monday as Tuesday when no other weekdays exist', () => {
    const rows = values(render([
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '5' },
        { fecha: '2026-11-09', dia_label: 'Lun', turno_a: '12' },
    ]));

    assert.equal(rows.length, 8);
    assert.deepEqual(rows.filter(row => row.turno_a !== '').map(row => [row.fecha, row.dia_label, row.turno_a]), [
        ['2026-11-02', 'Lun', '5'],
        ['2026-11-09', 'Lun', '12'],
    ]);
});

test('starts on the Saturday of the parada and ends on its last day', () => {
    const rows = schedule(render([], false, {}, { inicio: '2026-11-07', fin: '2026-11-10' }));
    assert.deepEqual(rows.map(row => [row.fields.fecha, row.heading]), [
        ['2026-11-07', '7/11 sáb'], ['2026-11-08', '8/11 dom'], ['2026-11-09', '9/11 lun'], ['2026-11-10', '10/11 mar'],
    ]);
    assert.ok(rows.every(row => row.fields.turno_a === '' && row.fields.turno_b === ''));
    assert.deepEqual(submittedTurns(render([])), [], 'The empty calendar is only a guide for entering shifts.');
});

test('crosses the year boundary and includes a single-day plan', () => {
    const rows = schedule(render([], false, {}, { inicio: '2026-12-30', fin: '2027-01-02' }));
    assert.deepEqual(rows.map(row => row.heading), ['30/12 mié', '31/12 jue', '1/1 vie', '2/1 sáb']);
    assert.deepEqual(schedule(render([], false, {}, { inicio: '2026-11-07', fin: '2026-11-07' }))
        .map(row => row.heading), ['7/11 sáb']);
});

test('shows recurring planned quantities on matching dates and preserves their original rows', () => {
    const original = [
        { fecha: '', dia_label: 'Lun', turno_a: '5', real_turno_a: '4' },
        { fecha: null, dia_label: 'Miércoles', turno_b: '3', real: '2' },
    ];
    const html = render(original);
    const rows = values(html);
    assert.deepEqual(rows.filter(row => row.turno_a || row.turno_b).map(row => [row.fecha, row.turno_a, row.turno_b]), [
        ['2026-11-02', '5', ''], ['2026-11-04', '', '3'], ['2026-11-09', '5', ''],
    ]);
    assert.ok(rows.every(row => row.real_turno_a === '' && row.real_turno_b === ''));
    assert.deepEqual(submittedTurns(html), original);
});

test('prefers dated exceptions and saves edits on one date with the other shift intact', () => {
    const input = [
        { fecha: '', dia_label: 'Lun', turno_a: '5', real_turno_a: '', turno_b: '3', real_turno_b: '' },
        { fecha: '2026-11-09', dia_label: 'Lun', turno_a: '12', real_turno_a: '11', turno_b: '8', real_turno_b: '7' },
        { fecha: '2026-11-13', dia_label: 'Vie', turno_a: '4', real_turno_a: '3', turno_b: '2', real_turno_b: '1' },
    ];

    const html = render(input);
    assert.equal(values(html).find(row => row.fecha === '2026-11-09').turno_a, '12');
    const submitted = submittedTurns(html, { '2026-11-02': { turno_a: '6' } });
    assert.deepEqual(submitted, [
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '6', real_turno_a: '', turno_b: '3', real_turno_b: '', real: '', _nuevo: true },
        { ...input[1], real: '7' }, input[0], input[2],
    ]);
    assert.equal(values(render(submitted)).find(row => row.fecha === '2026-11-02').turno_a, '6');
});

test('preserves unrecognized rows and zero quantities and supports clearing an inherited day', () => {
    const legacy = { fecha: '', dia_label: '', turno_a: '9' };
    const weekly = { fecha: null, dia_label: 'Lun', turno_a: '5' };
    const html = render([
        { fecha: '2026-11-09', dia_label: '', turno_a: 0, real_turno_a: 0, turno_b: '2', real_turno_b: 0 },
        legacy, weekly,
    ]);

    const submitted = submittedTurns(html, { '2026-11-02': { turno_a: '' } });
    assert.deepEqual(submitted, [
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '', real_turno_a: '', turno_b: '', real_turno_b: '', real: '', _nuevo: true },
        { fecha: '2026-11-09', dia_label: '', turno_a: '0', real_turno_a: '0', turno_b: '2', real_turno_b: '0', real: '0' },
        legacy, weekly,
    ]);
    assert.equal(values(render(submitted))[0].turno_a, '');
});

test('retains overlapping and out-of-range legacy rows without extending the visible dates', () => {
    const stored = [
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '5' },
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '9' },
        { fecha: '2026-11-16', dia_label: 'Lun', turno_a: '7' },
    ];
    const html = render(stored);
    assert.equal(values(html).length, 8);
    assert.equal(values(html)[0].turno_a, '5');
    assert.deepEqual(submittedTurns(html).map(row => [row.fecha, row.turno_a]), stored.map(row => [row.fecha, row.turno_a]));
});

test('removes a new day that is cleared after redrawing the editor', () => {
    const draft = submittedTurns(render([]), { '2026-11-02': { turno_a: '8' } });
    const redrawn = render(draft);
    assert.equal(values(redrawn)[0].turno_a, '8');
    assert.deepEqual(submittedTurns(redrawn, { '2026-11-02': { turno_a: '' } }), []);
});

test('derives the weekday from the date even if an older label is different', () => {
    const rows = schedule(render([{ fecha: '2026-11-09', dia_label: 'Vie <especial>', turno_a: '5' }]));
    const row = rows.find(row => row.fields.fecha === '2026-11-09');

    assert.equal(row.heading, '9/11 lun');
    assert.ok(!row.html.includes('<especial>'));
    assert.equal(row.fields.dia_label, 'Vie <especial>');
});

test('keeps readonly protection, activity identity and every dated shift', () => {
    const html = render([
        { fecha: '2026-11-02', dia_label: 'Lun', turno_a: '5', real_turno_a: '4' },
        { fecha: '2026-11-09', dia_label: 'Lun', turno_a: '12', real_turno_a: '11' },
    ], true, { id: 'activity<&1', client_key: 'client"1' });

    assert.equal(submittedTurns(html).length, 2);
    for (const row of schedule(html)) {
        const controls = Array.from(row.html.matchAll(/<input\b[^>]*>/g), ([input]) => input);
        assert.ok(controls.filter(input => /\[(turno_a|turno_b)\]"/.test(input)).every(input => /\sdisabled\b/.test(input)));
        assert.ok(controls.filter(input => /\[real_turno_[ab]\]"/.test(input)).every(input => /\sreadonly\b/.test(input)));
    }
    assert.ok(!html.includes('data-remove-activity'));
    assert.ok(html.includes('[id]" value="activity&lt;&amp;1"'));
    assert.ok(html.includes('[client_key]" value="client&quot;1"'));
});

test('collecting an edited plan for local recovery keeps row identities and logistics status', () => {
    const syncStart = source.indexOf('    function fieldValue(');
    const syncEnd = source.indexOf("    root.addEventListener('click'", syncStart);
    const element = (fields, children = {}) => ({
        dataset: {},
        querySelector: selector => ({ value: fields[selector.slice(8, -2)] ?? '' }),
        querySelectorAll: selector => children[selector] || [],
    });
    const transport = element({ '[id]': 'transport-1', '[unidad_carga]': 'Bus actualizado', '[fecha]': '2026-11-09', '[actividad_key]': 'activity-1' });
    const group = element({ '[id]': 'group-1' }, {
        '.rq-plan-activity': [element({ '[id]': 'activity-1' })],
        '.rq-plan-transport-row': [transport],
    });
    const body = element({}, { '.rq-plan-group': [group] });
    const priorPlan = [{ id: 'group-1', transportes: [{ id: 'transport-1', atencion: 'ASIGNADO', placas_asignadas: 'DEMO-01' }] }];
    const [updated] = new Function('body', 'plan', source.slice(syncStart, syncEnd) + '\nreturn syncFromDom();')(body, priorPlan);
    assert.equal(updated.id, 'group-1');
    assert.equal(updated.actividades[0].id, 'activity-1');
    assert.equal(updated.transportes[0].id, 'transport-1');
    assert.equal(updated.transportes[0].fecha, '2026-11-09');
    assert.equal(updated.transportes[0].actividad_key, 'activity-1');
    assert.equal(updated.transportes[0].unidad_carga, 'Bus actualizado');
    assert.equal(updated.transportes[0].atencion, 'ASIGNADO');
    assert.equal(updated.transportes[0].placas_asignadas, 'DEMO-01');
});

test('keeps an attended transport in the editor and explains that Logistics must review its removal', () => {
    const removeStart = source.indexOf("        if (event.target.matches('[data-remove-transport]')");
    const removeEnd = source.indexOf('\n    });', removeStart);
    const handleRemove = new Function('event', 'groupIndex', 'groupEl', 'readOnly', 'window', 'plan', 'render', source.slice(removeStart, removeEnd));
    const messages = [];
    const target = { matches: () => true, closest: () => ({ dataset: { atencionLogistica: '1' } }) };
    const rows = [{ id: 'transport-1', placas_asignadas: 'DEMO-01' }];
    let renders = 0;

    handleRemove({ target }, 0, { querySelectorAll: () => [target] }, false, {
        confirm: () => true, alert: message => messages.push(message),
    }, [{ transportes: rows }], () => { renders++; });

    assert.equal(rows.length, 1);
    assert.equal(renders, 0);
    assert.match(messages[0], /Log[ií]stica/);
});
