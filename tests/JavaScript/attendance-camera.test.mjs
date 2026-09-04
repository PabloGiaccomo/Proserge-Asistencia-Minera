import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/mi-asistencia/show.blade.php', import.meta.url), 'utf8');
const marker = '/* ---------- Camara para escanear codigo de barras ---------- */';
assert.ok(blade.includes(marker));
const script = blade.slice(blade.indexOf(marker), blade.lastIndexOf('})();'));
const flush = () => new Promise(resolve => setImmediate(resolve));

function element() {
    return {
        style: {}, attributes: {}, events: {}, children: [], readyState: 2,
        classList: { add() {}, remove() {} },
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name]; },
        addEventListener(name, handler) { this.events[name] = handler; },
        appendChild(child) { this.children.push(child); },
        querySelector() { return this.children[0] || element(); },
        async play() {},
    };
}

function setup({ native = false, handle = async () => true, detect } = {}) {
    const ids = [...script.matchAll(/getElementById\('([^']+)'\)/g)].map(match => match[1]);
    const fields = new Map(ids.map(id => [id, element()]));
    const calls = [];
    const frames = new Map();
    let nextFrame = 0;
    let trackStops = 0;
    let decoderStops = 0;
    let callback;
    const stream = { getTracks: () => [{ stop: () => trackStops++ }] };
    class Reader {
        async decodeFromStream(_stream, _video, onResult) {
            callback = onResult;
            return { stop: () => decoderStops++ };
        }
    }
    class Detector {
        static async getSupportedFormats() { return ['pdf417']; }
        async detect() { return detect ? detect() : []; }
    }
    const window = { isSecureContext: true, events: {}, addEventListener(name, handler) { this.events[name] = handler; } };
    if (native) window.BarcodeDetector = Detector;
    else window.ProsergeBarcodeScanner = { BrowserMultiFormatReader: Reader, BarcodeFormat: {} };
    const context = vm.createContext({
        window, console, BarcodeDetector: Detector,
        document: { ...element(), body: element(), getElementById: id => fields.get(id), createElement: element },
        navigator: { mediaDevices: { getUserMedia: async () => stream } },
        processingCode: false,
        processDni: async value => {
            context.processingCode = true;
            calls.push(value);
            try { return await handle(value); }
            finally { context.processingCode = false; }
        },
        showToast: () => assert.fail('Unexpected camera error'),
        requestAnimationFrame: handler => { frames.set(++nextFrame, handler); return nextFrame; },
        cancelAnimationFrame: id => frames.delete(id),
    });
    vm.runInContext(script, context);
    return {
        context, fields, calls, frames,
        stops: () => ({ tracks: trackStops, decoder: decoderStops }),
        scan: async value => { callback({ getText: () => value }); await flush(); },
        nextFrame: async () => {
            const [id, handler] = frames.entries().next().value;
            frames.delete(id);
            await handler();
        },
    };
}

test('ZXing scans multiple DNIs, ignores repeats, and stops only when closed', async () => {
    const app = setup();
    await app.context.startCamera();
    await app.scan('99000001');
    await app.scan('99000001');
    await app.scan('99000002');
    assert.deepEqual(app.calls, ['99000001', '99000002']);
    assert.equal(app.fields.get('miaCameraModal').getAttribute('aria-hidden'), 'false');
    assert.deepEqual(app.stops(), { tracks: 0, decoder: 0 });
    assert.equal(app.fields.get('miaCameraModal').events.click, undefined);
    app.fields.get('miaCloseCamera').events.click();
    assert.equal(app.fields.get('miaCameraModal').getAttribute('aria-hidden'), 'true');
    assert.deepEqual(app.stops(), { tracks: 1, decoder: 1 });
    await app.scan('99000003');
    assert.equal(app.calls.length, 2);
});

test('failed registrations can be retried and reopening starts a new scanning session', async () => {
    let success = false;
    const app = setup({ handle: async () => success });
    await app.context.startCamera();
    await app.scan('99000001');
    success = true;
    await app.scan('99000001');
    await app.scan('99000001');
    assert.equal(app.calls.length, 2);
    app.context.stopCamera();
    await app.context.startCamera();
    await app.scan('99000001');
    assert.equal(app.calls.length, 3);
});

test('native detector continues its frame loop after each distinct DNI', async () => {
    const codes = ['99000001', '99000001', '99000002'];
    const app = setup({ native: true, detect: async () => [{ rawValue: codes.shift() }] });
    await app.context.startCamera();
    await flush();
    await app.nextFrame();
    await app.nextFrame();
    assert.deepEqual(app.calls, ['99000001', '99000002']);
    assert.equal(app.frames.size, 1);
    assert.equal(app.fields.get('miaCameraModal').getAttribute('aria-hidden'), 'false');
    assert.equal(app.stops().tracks, 0);
    app.context.stopCamera();
    assert.equal(app.frames.size, 0);
    assert.equal(app.stops().tracks, 1);
});

test('closing during native detection ignores the late result and does not restart the loop', async () => {
    let finish;
    const app = setup({ native: true, detect: () => new Promise(resolve => { finish = resolve; }) });
    await app.context.startCamera();
    app.context.stopCamera();
    finish([{ rawValue: '99000001' }]);
    await flush();
    assert.deepEqual(app.calls, []);
    assert.equal(app.frames.size, 0);
});

test('a pending registration prevents concurrent camera submissions', async () => {
    let finish;
    const app = setup({ handle: () => new Promise(resolve => { finish = resolve; }) });
    await app.context.startCamera();
    await app.scan('99000001');
    await app.scan('99000002');
    assert.deepEqual(app.calls, ['99000001']);
    finish(true);
    await flush();
    await app.scan('99000001');
    assert.equal(app.calls.length, 1);
});

test('Escape and leaving the page release the camera', async () => {
    const app = setup();
    await app.context.startCamera();
    app.context.document.events.keydown({ key: 'Escape' });
    assert.equal(app.stops().tracks, 1);
    await app.context.startCamera();
    app.context.window.events.pagehide();
    assert.equal(app.stops().tracks, 2);
});
