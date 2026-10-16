import assert from 'node:assert/strict';
import test from 'node:test';
import { createPersonalLiveResults } from '../../resources/js/modules/personal-live-results.js';

function pendingRequest() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

test('late results cannot overwrite the newest search, even if abort is ignored', async () => {
    const requests = [];
    const results = [];
    const loader = createPersonalLiveResults({
        fetchResults(url, options) {
            const request = { ...pendingRequest(), url, options };
            requests.push(request);
            return request.promise;
        },
        onResults: result => results.push(result.html),
    });
    const old = loader.load(new URL('http://localhost/personal?q=old'));
    const latest = loader.load(new URL('http://localhost/personal?q=new'));
    assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(requests[1].options.headers.Accept, 'application/json');
    requests[1].resolve({ ok: true, json: async () => ({ html: 'NEW', pagination: { total: 1 } }) });
    await latest;
    requests[0].resolve({ ok: true, json: async () => ({ html: 'OLD', pagination: { total: 2 } }) });
    await old;
    assert.deepEqual(results, ['NEW']);
});

test('typing cancels pending work before the next debounce request', async () => {
    const request = pendingRequest();
    const results = [];
    const loader = createPersonalLiveResults({ fetchResults: () => request.promise, onResults: value => results.push(value) });
    const loading = loader.load('http://localhost/personal?q=first');
    loader.cancel();
    request.resolve({ ok: true, json: async () => ({ html: 'OLD', pagination: {} }) });
    await loading;
    assert.deepEqual(results, []);
});

test('failed requests leave results untouched and report the error after loading ends', async () => {
    const events = [];
    const loader = createPersonalLiveResults({
        fetchResults: async () => ({ ok: false }),
        onResults: () => assert.fail('Failed request must not replace results'),
        onBusy: busy => events.push(busy ? 'loading' : 'idle'),
        onError: message => events.push(message),
    });
    await loader.load('http://localhost/personal');
    assert.equal(events.at(-2), 'idle');
    assert.match(events.at(-1), /No se pudo actualizar/);
});
