const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

// Exercise the links emitted by Blade, as deployed: no injected module CSS/JS.
(async () => {
    const buildPublic = process.env.EVALUATION_BUILD_PUBLIC || path.resolve('public');
    const render = (type = '', search = '', empty = false, query = '') => execFileSync(process.env.PHP_BINARY || 'php',
        ['tests/Browser/render-evaluation-dashboard.php', type, search, empty ? 'empty' : '', query],
        { encoding: 'utf8', env: { ...process.env, APP_ENV: 'production', APP_URL: 'http://127.0.0.1:8000' } });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        let requests = 0;
        let documents = 0;
        let mode = 'ok';
        let releaseDelayed;
        let notifyDelayed;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/evaluaciones') {
                if (route.request().headers()['x-evaluation-dashboard']) requests++;
                else documents++;
                if (mode === 'error') return route.fulfill({ status: 422, json: { errors: { hasta: ['Revisa el rango de fechas.'] } } });
                if (mode === 'delayed') {
                    await new Promise(resolve => { releaseDelayed = resolve; notifyDelayed(); });
                }
                return route.fulfill({ contentType: 'text/html', body: render(url.searchParams.get('evaluacion_tipo') || '', url.searchParams.get('q') || '', mode === 'empty', url.searchParams.toString()) });
            }
            if (url.pathname.startsWith('/build/')) {
                const file = path.resolve(buildPublic, url.pathname.slice(1));
                assert.ok(file.startsWith(path.resolve(buildPublic, 'build') + path.sep));
                return route.fulfill({ path: file, contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript' });
            }
            if (url.pathname === '/proserge-app.css') return route.fulfill({ path: 'public/css/proserge-app.css', contentType: 'text/css' });
            return route.fulfill({ status: 404, body: '' });
        });
        await page.goto('http://127.0.0.1:8000/evaluaciones?tipo=dashboard');
        assert.equal(await page.locator('#ed-filters').evaluate(el => getComputedStyle(el).display), 'grid', 'Dashboard styles must come from the production build');
        await page.locator('#ed-search').fill('ANA PRUEBA');
        await page.locator('#ed-search').press('Enter');
        await page.waitForFunction(() => new URL(location.href).searchParams.get('q') === 'ANA PRUEBA');
        assert.equal(await page.locator('#ed-search').inputValue(), 'ANA PRUEBA');
        assert.equal(documents, 1, 'Filtering must not reload the document');
        await page.locator('#ed-job').selectOption('SUPERVISOR');
        await page.waitForFunction(() => location.search.includes('cargo=SUPERVISOR'));
        assert.equal(requests, 2);
        await page.locator('#ed-more-filters').evaluate(el => { el.open = true; });
        await page.locator('#ed-exact').fill('2026-09-10');
        await page.locator('#ed-exact').dispatchEvent('change');
        await page.waitForFunction(() => location.search.includes('desde=2026-09-10') && location.search.includes('hasta=2026-09-10'));
        assert.equal(await page.locator('#ed-more-filters').evaluate(el => el.open), true);
        // A range can be edited completely before applying it.
        const beforeDates = requests;
        await page.locator('#ed-from').fill('2026-09-01');
        await page.locator('#ed-to').fill('2026-09-30');
        await page.locator('#ed-apply').click();
        await page.waitForFunction(() => location.search.includes('hasta=2026-09-30'));
        assert.equal(requests, beforeDates + 1);
        assert.equal(await page.locator('#ed-from').inputValue(), '2026-09-01');
        assert.equal(await page.locator('#ed-to').inputValue(), '2026-09-30');
        // Typing during a slow response must preserve the new, unsent edits.
        mode = 'delayed';
        const delayedStarted = new Promise(resolve => { notifyDelayed = resolve; });
        await page.locator('#ed-job').selectOption('');
        await delayedStarted;
        const cancelled = page.waitForEvent('requestfailed', {
            predicate: request => Boolean(request.headers()['x-evaluation-dashboard']), timeout: 5000,
        });
        await page.locator('#ed-search').fill('NUEVA BUSQUEDA');
        await cancelled;
        assert.equal(await page.locator('#ed-search').inputValue(), 'NUEVA BUSQUEDA');
        assert.equal(await page.locator('#evaluation-dashboard').getAttribute('aria-busy'), null);
        releaseDelayed();
        mode = 'ok';
        await page.locator('#ed-search').fill('ANA PRUEBA');
        await page.locator('#ed-apply').click();
        await page.waitForFunction(() => !document.getElementById('evaluation-dashboard').hasAttribute('aria-busy'));
        fs.mkdirSync('storage/app/testing', { recursive: true });
        for (const type of ['', 'desempeno', 'supervisores', 'residentes']) {
            await page.locator('#ed-evaluation-type').selectOption(type);
            await page.waitForFunction(type => new URL(location.href).searchParams.get('evaluacion_tipo') === type, type);
            if (type) await page.locator(`[data-detail-type="${type}"]`).waitFor();
            for (const width of [1440, 768, 390, 320]) {
                await page.setViewportSize({ width, height: 1000 });
                assert.equal(await page.locator('#evaluation-dashboard').evaluate(el => el.scrollWidth > el.clientWidth + 1), false, `Overflow at ${width}px`);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
                await page.screenshot({ path: `storage/app/testing/evaluation-dashboard-build-${type || 'all'}-${width}.png`, fullPage: true });
            }
        }
        mode = 'error';
        await page.locator('#ed-apply').click();
        await page.getByRole('status').filter({ hasText: 'Revisa el rango de fechas.' }).waitFor();
        assert.equal(await page.locator('#evaluation-dashboard').getAttribute('aria-busy'), null);
        mode = 'empty';
        await page.locator('#ed-apply').click();
        await page.locator('.ed-empty').waitFor();
        assert.equal(await page.locator('.ed-charts').count(), 0, 'Show a useful empty state instead of empty charts');
        await page.screenshot({ path: 'storage/app/testing/evaluation-dashboard-build-empty-320.png', fullPage: true });
        mode = 'ok';
        await page.locator('#ed-from').fill('');
        await page.locator('#ed-reset').click();
        await page.waitForFunction(() => !new URL(location.href).searchParams.has('q'));
        assert.equal(documents, 1);
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.locator('#evaluation-dashboard').screenshot({ path: `storage/app/testing/evaluation-dashboard-final-${width}.png` });
        }
        assert.deepEqual(errors, []);
        console.log('PASS production build, search, dates, AJAX cancellation, errors, reset, empty state and 320/390/768/1440px layouts');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
