const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    // Fixtures are rendered through the real HTTP routes against the isolated
    // transactional test database. Browser requests never reach operational data.
    const output = execFileSync(process.env.PHP_BINARY || 'php', [
        'artisan', 'test', '--compact', 'tests/Feature/HomeDashboardsTest.php', 'tests/Feature/HomeSummaryDashboardServiceTest.php',
        '--filter', 'all_cards_open_readonly|parada_dashboard_reuses|man_power_dashboard_forces',
    ], { encoding: 'utf8', env: { ...process.env, HOME_DASHBOARD_QA: '1' } });
    assert.match(output, /3 passed/);
    const fixtureRoot = path.resolve('storage/app/testing/home-dashboards');
    const modules = ['personal', 'mi_asistencia', 'man_power', 'rq_mina', 'rq_proserge', 'bienestar', 'evaluaciones', 'asistencias', 'faltas', 'catalogos', 'logistica'];
    const fixtures = Object.fromEntries(['inicio', 'parada', ...modules].map(module => [module, fs.readFileSync(path.join(fixtureRoot, module + '.html'), 'utf8')]));
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/inicio' || url.pathname.startsWith('/inicio/dashboards/')) {
                const module = url.pathname === '/inicio' ? 'inicio' : url.pathname.split('/').length > 4 ? 'parada' : url.pathname.split('/')[3];
                return route.fulfill({ contentType: 'text/html', body: fixtures[module] || '' });
            }
            if (url.pathname.startsWith('/build/')) {
                const asset = path.resolve('public', url.pathname.slice(1));
                assert.ok(asset.startsWith(path.resolve('public/build') + path.sep));
                return route.fulfill({ path: asset, contentType: asset.endsWith('.css') ? 'text/css' : 'application/javascript' });
            }
            if (url.pathname === '/proserge-app.css') {
                return route.fulfill({ path: path.resolve('public/css/proserge-app.css'), contentType: 'text/css' });
            }
            return route.fulfill({ status: 204, body: '' });
        });
        for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
            await page.setViewportSize(viewport);
            await page.goto('http://127.0.0.1:8000/inicio');
            await page.locator('.home-dashboard-card').last().waitFor();
            assert.equal(await page.locator('.home-dashboard-card').count(), 11);
            await page.screenshot({ path: path.join(fixtureRoot, `inicio-${viewport.width}.png`), fullPage: true });
            for (const module of modules) {
                await page.locator(`.home-dashboard-card[href$="/inicio/dashboards/${module}"]`).click();
                const back = page.getByRole('link', { name: 'Volver a Inicio', exact: false });
                await back.waitFor();
                assert.equal(new URL(page.url()).pathname, '/inicio/dashboards/' + module);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${module}: page must fit ${viewport.width}px`);
                const filter = page.locator('main form[method="GET"]').first();
                if (await filter.count()) {
                    assert.equal(new URL(await filter.getAttribute('action')).pathname, '/inicio/dashboards/' + module);
                }
                await page.screenshot({ path: path.join(fixtureRoot, `${module}-${viewport.width}.png`), fullPage: true });
                if (module === 'bienestar') {
                    await page.locator('#dashboard-from').fill('2026-10-01');
                    await page.locator('#dashboard-to').fill('2026-10-20');
                    await page.getByRole('button', { name: 'Aplicar filtros' }).click();
                    assert.equal(new URL(page.url()).pathname, '/inicio/dashboards/bienestar');
                    assert.equal(new URL(page.url()).searchParams.get('fecha_hasta'), '2026-10-20');
                }
                if (module === 'evaluaciones') {
                    await page.locator('#ed-search').fill('PERSONA DE PRUEBA');
                    await page.locator('#ed-apply').click();
                    await page.getByText('Resultados actualizados.', { exact: true }).waitFor();
                    assert.equal(new URL(page.url()).pathname, '/inicio/dashboards/evaluaciones');
                    await page.locator('#ed-reset').click();
                    await page.getByText('Resultados actualizados.', { exact: true }).waitFor();
                }
                if (module === 'man_power') {
                    assert.equal(await page.locator('main form[method="POST"]').count(), 0);
                    assert.equal(await page.locator('input[name="fecha"]').getAttribute('min'), null);
                    assert.equal(await page.locator('input[name="fecha"]').getAttribute('max'), null);
                }
                await page.getByRole('link', { name: 'Volver a Inicio', exact: false }).click();
                await page.locator('.home-dashboard-card').last().waitFor();
            }
            const nestedUrl = fixtures.parada.match(/<form method="GET" action="([^"]+)" class="pd-filters"/)[1];
            await page.goto(nestedUrl);
            await page.getByRole('link', { name: 'Volver al resumen' }).waitFor();
            assert.equal(await page.locator('main form[method="POST"]').count(), 0);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'parada dashboard must fit');
            await page.screenshot({ path: path.join(fixtureRoot, `parada-${viewport.width}.png`), fullPage: true });
            await page.getByRole('link', { name: 'Volver al resumen' }).click();
            assert.equal(new URL(page.url()).pathname, '/inicio/dashboards/rq_mina');
        }
        assert.deepEqual(errors, []);
        console.log('PASS: 11 dashboard cards, parada KPIs, populated Man Power, return navigation, filter URLs, evaluation refresh, desktop/mobile, no JavaScript errors.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
