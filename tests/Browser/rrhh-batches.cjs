const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const origin = 'http://127.0.0.1:8476';
const key = '09000000-0000-4000-8000-00000000000';
const lot = key + '1';
const config = { encoding: 'utf8', env: { ...process.env, APP_URL: origin } };
const render = name => execFileSync('php', ['tests/Browser/render-rrhh-batches.php', name], config);
const contracts = '/personal/contratos/firmados';
const contractLot = contracts + '/lotes/' + lot;
const ingresos = '/personal/ingresos/lotes';
const review = ingresos + '/' + lot;
const windows = [{width: 390, height: 844}, {width: 1440, height: 1000}];

(async () => {
    const pages = {
        contracts: render('contratos'),
        review: render('revision'),
        inbox: '<button id="review-ingreso-batch">Revisar seleccionadas</button>'
            + '<label><input type="checkbox" data-ingreso-batch="' + key + '4" data-nombre="Alta de prueba" data-proposito="ALTA">Alta</label>'
            + '<label><input type="checkbox" data-ingreso-batch="' + key + '5" data-nombre="Actualización de prueba" data-proposito="ACTUALIZACION">Actualización</label>'
            + render('bandeja'),
    };
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const viewport of windows) {
            const page = await browser.newPage({ viewport });
            const failures = [];
            page.on('pageerror', error => failures.push(error.message));
            const uploadRows = [];
            const reviewRows = [
                { id: key + '2', estado: 'LISTA', mensaje: null },
                { id: key + '3', estado: 'LISTA', mensaje: null },
            ];
            const candidate = { id: key + '6', trabajador: 'Persona de prueba', numero: 2, periodo: '04/11/2026 al 04/12/2026' };
            let requestLost = false;
            let activationDone = false;
            await page.route('**/*', async route => {
                const request = route.request();
                const path = new URL(request.url()).pathname;
                const method = request.method();
                if (path === contracts) return route.fulfill({ contentType: 'text/html', body: pages.contracts });
                if (path === contracts + '/lotes') return route.fulfill({ status: 201, json: { id: lot } });
                if (path === contractLot) return route.fulfill({ json: { revision: 'test', archivos: uploadRows } });
                if (path === contractLot + '/archivos' && method === 'POST') {
                    const id = key + (uploadRows.length + 2);
                    const name = request.postData().match(/filename="([^"]+)"/)[1];
                    uploadRows.push({ id, nombre: name, estado: 'PENDIENTE', listo: false });
                    return route.fulfill({ status: 201, json: { id } });
                }
                if (path === contracts + '/buscar') return route.fulfill({ json: { contratos: [candidate] } });
                if (path === contracts + '/listos') return route.fulfill({ json: {
                    contratos: activationDone ? [] : [{ ...candidate, revision: 'revision-de-prueba' }], mas: false,
                } });
                if (path === contracts + '/activar') {
                    assert.equal(request.postDataJSON().contratos[0].id, candidate.id);
                    activationDone = true;
                    return route.fulfill({ json: { resultados: [{id: candidate.id, estado: 'ACTIVO'}] } });
                }
                if (path.startsWith(contractLot + '/archivos/') && method === 'PATCH') {
                    const row = uploadRows.find(row => path.endsWith(row.id));
                    row.contrato = { ...candidate, id: key + (uploadRows.indexOf(row) + 6) };
                    row.listo = true;
                    return route.fulfill({ json: { ok: true } });
                }
                if (path === contractLot + '/confirmar') {
                    assert.deepEqual(request.postDataJSON().archivos, uploadRows.map(row => row.id));
                    for (const row of uploadRows) Object.assign(row, { estado: 'GUARDADO', listo: false, mensaje: 'PDF guardado.' });
                    return route.fulfill({ json: { resultados: uploadRows } });
                }
                if (path === '/personal/fichas/temporales') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: pages.inbox });
                if (path === ingresos && method === 'POST') {
                    const [alta, update] = request.postDataJSON().filas;
                    assert.equal(alta.fecha_inicio_contrato, '2026-11-04');
                    assert.equal(update.fecha_inicio_contrato, null);
                    return route.fulfill({ status: 201, json: { id: lot } });
                }
                if (path === review) {
                    return request.headers().accept === 'application/json'
                        ? route.fulfill({ json: { filas: reviewRows, vencido: false } })
                        : route.fulfill({ contentType: 'text/html', body: pages.review });
                }
                if (path.startsWith(review + '/filas/') && path.endsWith('/revision')) {
                    const isNewHire = path.includes('/' + key + '2/');
                    return route.fulfill({ json: {
                        proposito: isNewHire ? 'ALTA' : 'ACTUALIZACION',
                        campos: [{campo: 'Correo', antes: isNewHire ? null : 'actual@example.test', propuesto: 'nuevo@example.test'}],
                        familiares: [{mensaje: 'Agregar familiar', campos: [
                            {campo: 'Apellidos y nombres', propuesto: 'FAMILIAR DE PRUEBA'},
                            {campo: 'Parentesco', propuesto: 'Hijo'},
                            {campo: 'Contacto de emergencia', propuesto: 'No'},
                        ]}],
                    } });
                }
                if (path === review + '/confirmar') {
                    const result = reviewRows.filter(row => request.postDataJSON().filas.includes(row.id));
                    if (!requestLost) {
                        requestLost = true;
                        Object.assign(result[0], { estado: 'ACEPTADA', mensaje: 'Ficha agregada.' });
                        return route.abort();
                    }
                    for (const row of result) Object.assign(row, { estado: 'ACEPTADA', mensaje: 'Ficha aceptada.' });
                    return route.fulfill({ json: { resultados: result } });
                }
                if (path.startsWith('/build/')) {
                    const file = require('node:path').resolve('public', '.' + path);
                    if (require('node:fs').existsSync(file)) return route.fulfill({ path: file });
                }
                return route.fulfill({ status: 404 });
            });

            await page.goto(origin + contracts);
            await page.locator('#signed-files').setInputFiles(['uno.pdf', 'dos.pdf'].map(name => ({
                name, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 sintético'),
            })));
            await page.getByText('PDF recibidos: 2', { exact: false }).waitFor();
            for (const row of [0, 1]) {
                const entry = page.locator('.signed-row').nth(row);
                await entry.getByLabel('Buscar trabajador').fill('Persona');
                await entry.getByRole('button', { name: 'Buscar contrato' }).click();
                await entry.getByRole('button', { name: /Persona de prueba/ }).click();
                await entry.getByText('Listo para revisar y guardar.').waitFor();
            }
            await page.getByRole('button', { name: 'Marcar todos los listos' }).click();
            assert.equal(await page.getByRole('button', { name: /Guardar seleccionados/ }).isDisabled(), true);
            await page.getByLabel('Revisé el PDF y el periodo de cada contrato marcado.').check();
            await page.getByRole('button', { name: /Guardar seleccionados/ }).click();
            await page.getByText('PDF guardados: 2. Por revisar: 0.').waitFor();
            await page.getByText('Contratos firmados listos para activar', { exact: true }).click();
            await page.getByLabel('Revisé y quiero activar este contrato').check();
            await page.getByRole('button', { name: 'Activar seleccionados' }).click();
            await page.getByText('Contratos activados: 1. Por revisar: 0.').waitFor();
            await page.screenshot({path: require('node:path').join(require('node:os').tmpdir(), 'proserge-rrhh-contratos-' + viewport.width + '.png'), fullPage: true});

            await page.goto(origin + '/personal/fichas/temporales?estado=FICHA_RECIBIDA');
            await page.getByLabel('Alta', { exact: true }).check();
            await page.getByLabel('Actualización', { exact: true }).check();
            await page.getByRole('button', { name: 'Revisar seleccionadas (2)' }).click();
            await page.getByLabel('Fecha de inicio común').fill('2026-11-04');
            await page.getByRole('button', { name: 'Copiar fechas a las altas' }).click();
            await page.getByRole('button', { name: 'Ver fichas por confirmar' }).click();
            await page.waitForURL(origin + review + '?estado=FICHA_RECIBIDA');
            const checks = page.getByLabel('Revisé esta ficha');
            assert.equal(await checks.first().isDisabled(), true);
            for (const details of await page.locator('[data-fila-review]').all()) {
                await details.locator('summary').click();
                await details.getByText(/^(Propuesto · )?nuevo@example\.test$/).waitFor();
                await details.getByText('FAMILIAR DE PRUEBA', {exact: true}).waitFor();
            }
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.screenshot({path: require('node:path').join(require('node:os').tmpdir(), 'proserge-rrhh-comparacion-' + viewport.width + '.png'), fullPage: true});
            await checks.first().check();
            await checks.nth(1).check();
            await page.getByRole('button', { name: 'Aceptar 2 revisadas' }).click();
            await page.getByText(/La solicitud se interrumpió/).waitFor();
            await page.getByRole('button', { name: 'Consultar resultado' }).click();
            await page.getByText('Fichas aceptadas en este lote: 1.').waitFor();
            assert.equal(await checks.count(), 1);
            await page.getByRole('button', { name: 'Aceptar 1 revisadas' }).click();
            await page.getByText('Fichas aceptadas en este lote: 2.').waitFor();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.screenshot({path: require('node:path').join(require('node:os').tmpdir(), 'proserge-rrhh-ingresos-' + viewport.width + '.png'), fullPage: true});
            assert.deepEqual(failures, []);
            await page.close();
        }
        console.log('RRHH: contratos e ingresos verificados en Chrome 390 y 1440 px.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
