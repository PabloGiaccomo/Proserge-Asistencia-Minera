import assert from 'node:assert/strict';
import test from 'node:test';
import { createUsuarioWorkerSearchLoader } from '../../resources/js/modules/usuario-worker-search.js';

test('ignora una respuesta anterior aunque la solicitud no respete la cancelación', async () => {
    const requests = [];
    const render = [];
    const loader = createUsuarioWorkerSearchLoader({
        fetchWorkers(url, options) {
            return new Promise(resolve => requests.push({ url, options, resolve }));
        },
        onResults: workers => render.push(workers),
    });
    const old = loader.load('https://test.local/usuarios/buscar?search=Ana');
    loader.cancel();
    const recent = loader.load('https://test.local/usuarios/buscar?search=Rosa');

    assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(requests[1].options.headers.Accept, 'application/json');
    requests[1].resolve({ ok: true, json: async () => ({ trabajadores: [{ id: 'rosa' }] }) });
    await recent;
    requests[0].resolve({ ok: true, json: async () => ({ trabajadores: [{ id: 'ana' }] }) });
    await old;

    assert.deepEqual(render, [[{ id: 'rosa' }]]);
});

test('avisa cuando el endpoint devuelve un error o una sesión vencida', async () => {
    for (const response of [{ ok: false }, { ok: true, redirected: true }]) {
        const errors = [];
        const loader = createUsuarioWorkerSearchLoader({
            fetchWorkers: async () => response,
            onResults: () => assert.fail('Una respuesta inválida no debe mostrar trabajadores'),
            onError: () => errors.push('error'),
        });
        await loader.load('https://test.local/usuarios/buscar?search=Rosa');
        assert.deepEqual(errors, ['error']);
    }
});
