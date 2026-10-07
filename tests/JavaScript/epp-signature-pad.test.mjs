import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { SignaturePadController } from '../../resources/js/modules/signature-pad.js';
import { invalidateOperationSignature } from '../../resources/js/modules/epp-operation.js';

function fixture() {
    let rect = { width: 300, height: 220, left: 0, top: 0 };
    const canvas = new EventTarget();
    canvas.style = {};
    canvas.getBoundingClientRect = () => rect;
    canvas.getContext = () => ({ setTransform() {}, clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, stroke() {} });
    canvas.toDataURL = () => 'data:image/png;base64,ink';
    canvas.setPointerCapture = () => {};
    canvas.releasePointerCapture = () => {};
    const pad = new SignaturePadController(canvas, { ratio: () => 2 });
    const pointer = (type, x, y, id = 1) => {
        const event = new Event(type, { cancelable: true });
        Object.assign(event, { clientX: x, clientY: y, pointerId: id, button: 0 });
        canvas.dispatchEvent(event);
    };
    const draw = () => {
        pointer('pointerdown', 10, 10);
        for (let i = 1; i <= 15; i++) pointer('pointermove', 10 + i * 5, 10 + i * 3);
        pointer('pointerup', 85, 55);
    };
    return { canvas, pad, pointer, draw, resize: () => { rect = { ...rect, width: 600 }; pad.resize(); } };
}

test('pointer drawing exports ink in image pixels at DPR and emits change', () => {
    const f = fixture(); let changes = 0;
    f.canvas.addEventListener('signature:changed', () => changes++);
    f.draw();
    assert.equal(f.canvas.width, 600);
    assert.equal(f.canvas.height, 440);
    assert.equal(f.canvas.style.touchAction, 'none');
    assert.equal(f.pad.hasInk(), true);
    assert.ok(f.pad.toPayload().ink.point_count >= 16);
    assert.deepEqual(f.pad.toPayload().ink.bounds, { min_x: 20, min_y: 20, max_x: 170, max_y: 110 });
    assert.ok(changes > 0);
});

test('empty signature, single tap and cleared signature cannot be exported', () => {
    const f = fixture();
    assert.throws(() => f.pad.toPayload());
    f.pointer('pointerdown', 10, 10); f.pointer('pointerup', 10, 10);
    assert.equal(f.pad.hasInk(), false);
    assert.throws(() => f.pad.toPayload());
    f.draw(); f.pad.clear();
    assert.equal(f.pad.hasInk(), false);
    assert.throws(() => f.pad.toPayload());
});

test('resize preserves drawing and normalized ink bounds', () => {
    const f = fixture(); f.draw(); f.resize();
    assert.equal(f.pad.hasInk(), true);
    assert.deepEqual(f.pad.toPayload().ink.bounds, { min_x: 40, min_y: 20, max_x: 340, max_y: 110 });
});

test('changing operative lines clears the signature and reviewed identity', () => {
    const f = fixture(); f.draw();
    const root = new EventTarget();
    const field = { value: 'old-hash' };
    root.querySelectorAll = () => [field];
    invalidateOperationSignature(root, [f.pad]);
    root.dispatchEvent(new Event('operation:lines-changed'));
    assert.equal(f.pad.hasInk(), false);
    assert.equal(field.value, '');
});

test('a second pointer cannot mix strokes into the first signature', () => {
    const f = fixture();
    f.pointer('pointerdown', 10, 10, 1);
    f.pointer('pointerdown', 250, 200, 2);
    f.pointer('pointermove', 290, 210, 2);
    f.pointer('pointerup', 290, 210, 2);
    f.pointer('pointermove', 40, 30, 1);
    f.pointer('pointerup', 40, 30, 1);
    assert.equal(f.pad.pointCount, 2);
});

test('Personal public submission stops before backup or busy state when signature validation fails', async () => {
    const source = readFileSync(new URL('../../resources/views/ficha-public/show.blade.php', import.meta.url), 'utf8');
    const start = source.indexOf("        form?.addEventListener('submit', async function (event) {");
    const end = source.indexOf('    } else if (@json', start);
    let submit, saved = 0; const busy = [];
    const context = vm.createContext({
        form: { _validateSignature: () => false, addEventListener: (name, callback) => { submit = callback; } },
        allowFinalSubmit: false, draftSubmitting: false, autosaveTimer: null, serverAutosaveTimer: null,
        window: { clearTimeout() {} }, setSubmitBusy: value => busy.push(value), showSubmitNotice() {},
        syncEmployers() {}, writeDraft() {}, lastServerAutosave: Promise.resolve(false),
        saveDraftData: async () => { saved++; return false; },
    });
    vm.runInContext(source.slice(start, end), context);
    let prevented = false;
    await submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(saved, 0);
    assert.deepEqual(busy, []);
});
