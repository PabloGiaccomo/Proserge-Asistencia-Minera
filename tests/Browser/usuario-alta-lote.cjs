'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const origin = 'http://127.0.0.1:8477';
const lotId = '70000000-0000-4000-8000-000000000002';
const renderer = path.join(__dirname, 'render-usuario-alta-lote.php');
const fixtures = Object.fromEntries(['individual', 'lote', 'resultado'].map(name => [name,
    execFileSync('php', [renderer, name], { encoding: 'utf8', env: { ...process.env, APP_URL: origin } }),
]));
const workers = [
    { id: '70000000-0000-4000-8000-000000000003', nombre: 'TRABAJADOR DE PRUEBA UNO', dni: '10000001', puesto: 'Operario', correo_sugerido: 'uno@example.test', tiene_usuario: false, seleccionar_url: origin + '/usuarios/crear?trabajador_id=uno' },
    { id: '70000000-0000-4000-8000-000000000004', nombre: 'TRABAJADOR DE PRUEBA DOS', dni: '10000002', puesto: 'Supervisor', correo_sugerido: 'dos@example.test', tiene_usuario: false, seleccionar_url: origin + '/usuarios/crear?trabajador_id=dos' },
];

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            let submitted;
            let downloadRequested = false;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', async route => {
                const request = route.request();
                const url = new URL(request.url());
                if (url.origin !== origin) return route.abort();
                if (url.pathname === '/usuarios/buscar-trabajadores') {
                    const search = url.searchParams.get('search');
                    return route.fulfill({ json: { trabajadores: workers.filter(worker => worker.nombre.toLowerCase().includes(search.toLowerCase())) } });
                }
                if (url.pathname === '/usuarios/lotes' && request.method() === 'POST') {
                    submitted = new URLSearchParams(request.postData());
                    return route.fulfill({ status: 302, headers: { location: '/usuarios/lotes/' + lotId } });
                }
                if (url.pathname.endsWith('/excel')) {
                    assert.equal(request.method(), 'POST');
                    assert.ok(request.postData().includes('_token='));
                    downloadRequested = true;
                    return route.fulfill({ body: 'archivo sintético', headers: { 'Content-Disposition': 'attachment; filename="credenciales-prueba.xlsx"' } });
                }
                if (request.resourceType() === 'document') {
                    const name = url.pathname === '/usuarios/crear/varios' ? 'lote'
                        : url.pathname === '/usuarios/lotes/' + lotId ? 'resultado' : 'individual';
                    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixtures[name] });
                }
                const asset = url.pathname === '/proserge-app.css' ? '/css/proserge-app.css' : url.pathname;
                const file = path.resolve('public', '.' + asset);
                if (file.startsWith(path.resolve('public') + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                    return route.fulfill({ path: file });
                }
                return route.abort();
            });

            await page.goto(origin + '/usuarios/crear');
            const search = page.locator('[data-user-worker-search-input]');
            await search.fill('prueba');
            await page.getByRole('link', { name: 'Seleccionar a TRABAJADOR DE PRUEBA UNO', exact: true }).waitFor();
            assert.equal(await page.getByRole('button', { name: 'Buscar trabajador', exact: true }).count(), 0);
            await page.getByRole('link', { name: 'Crear varios', exact: true }).click();
            await search.fill('uno');
            await page.getByRole('button', { name: 'Agregar a TRABAJADOR DE PRUEBA UNO', exact: true }).click();
            await page.locator('[data-lote-email]').first().fill('corregido@example.test');
            await search.fill('dos');
            await page.getByRole('button', { name: 'Agregar a TRABAJADOR DE PRUEBA DOS', exact: true }).click();
            assert.equal(await page.locator('[data-lote-row]').count(), 2);
            assert.equal(await page.locator('[data-lote-email]').first().inputValue(), 'corregido@example.test');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
            await page.getByLabel('Rol inicial para todas las cuentas').selectOption('70000000-0000-4000-8000-000000000001');
            await page.getByRole('button', { name: 'Crear 2 usuarios', exact: true }).click();
            await page.getByRole('heading', { name: 'Resultado del alta múltiple', exact: true }).waitFor();
            assert.equal(submitted.get('filas[0][email]'), 'corregido@example.test');
            assert.equal(submitted.get('filas[1][personal_id]'), workers[1].id);
            assert.equal(await page.locator('table tbody tr').count(), 2);
            assert.equal(await page.locator('code').first().textContent(), 'Clave-sintetica-uno');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
            const download = page.waitForEvent('download');
            await page.getByRole('button', { name: 'Descargar credenciales en Excel', exact: true }).click();
            await download;
            assert.equal(downloadRequested, true);
            assert.deepEqual(errors, []);
            await page.close();
            console.log(`PASS Chrome ${width}px: búsqueda, selección, revisión, resultado y descarga.`);
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
