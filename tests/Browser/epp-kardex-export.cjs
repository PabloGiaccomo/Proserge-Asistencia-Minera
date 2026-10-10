const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-epp-signed-delivery.php', 'kardex-export'], { encoding: 'utf8' });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        const downloads = [];
        const detailRequests = [];
        let failNextDetail = false;
        let holdNextDetail = false;
        let releaseHeldDetail;
        let notifyHeldDetail;
        const heldDetailStarted = new Promise(resolve => { notifyHeldDetail = resolve; });
        const worker = { id: '00000000-0000-0000-0000-000000000003', nombre: 'TRABAJADOR DE PRUEBA', documento: '00000001' };
        const emptyWorker = { id: '00000000-0000-0000-0000-000000000004', nombre: 'TRABAJADOR VACIO', documento: '00000002' };
        const kardex = {
            items: [{ key: 'casco', label: 'Casco' }, { key: 'chaleco', label: 'Chaleco' }],
            rows: [
                { number: 1, date: '09/10/2026', codes: { casco: 'N', chaleco: 'N' } },
                { number: 2, date: '09/10/2026', codes: { casco: 'N', chaleco: 'N' } },
                { number: 3, date: '09/10/2026', codes: { casco: 'N' } },
                { number: 4, date: '09/10/2026', codes: { casco: 'D', chaleco: 'D' } },
            ],
            has_observations: false,
        };
        while (kardex.rows.length < 125) {
            kardex.rows.push({ number: kardex.rows.length + 1, date: '09/10/2026', codes: { casco: 'N' } });
        }
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        await page.addInitScript({ path: 'public/build/' + manifest['resources/js/app.js'].file });
        page.on('pageerror', error => errors.push(error.message));
        // Every request is synthetic, including search, detail and download. No app authentication.
        await page.route('**/*', async route => {
            const url = route.request().url();
            if (url.includes('/verificable/descargar')) {
                downloads.push(url);
                return route.fulfill({ status: 204 });
            }
            if (route.request().resourceType() === 'document') {
                return route.fulfill({ contentType: 'text/html', body: html.replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') });
            }
            const params = new URL(url).searchParams;
            const perPage = Number(params.get('per_page') || 20);
            const currentPage = Number(params.get('page') || 1);
            if (params.has('personal_id')) detailRequests.push({ page: currentPage, per_page: perPage });
            if (params.has('personal_id') && failNextDetail) {
                failNextDetail = false;
                return route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
            }
            if (params.has('personal_id') && holdNextDetail) {
                holdNextDetail = false;
                notifyHeldDetail();
                await new Promise(resolve => { releaseHeldDetail = resolve; });
            }
            if (params.get('personal_id') === emptyWorker.id) {
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { kardex: { items: [], rows: [], has_observations: false, pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1, from: 0, to: 0 } } } }) });
            }
            const from = (currentPage - 1) * perPage;
            const rows = kardex.rows.slice(from, from + perPage);
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [params.get('q') === 'VACIO' ? emptyWorker : worker], data: { kardex: { ...kardex, rows, pagination: { current_page: currentPage, per_page: perPage, total: 125, last_page: Math.ceil(125 / perPage), from: from + 1, to: from + rows.length } }, operations: [{ type: 'TECHNICAL_ROW_MUST_NOT_RENDER', id: 'internal-id' }] } }) });
        });
        await page.goto('http://127.0.0.1:8000/synthetic-kardex');
        await page.addStyleTag({ path: 'public/proserge-app.css' });
        await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
        await page.locator('#kardexPersonalSearch').fill('PRUEBA');
        await page.locator('#kardexPersonalResults [data-index]').first().click();
        await page.waitForFunction(() => !document.querySelector('#kardexDownloadBtn').disabled);
        await page.locator('#kardexPageSummary').waitFor();
        assert.equal(await page.locator('#kardexTableBody tr').count(), 20);
        assert.deepEqual(await page.locator('#kardexTableBody tr').evaluateAll(rows => rows.slice(0, 4).map(row => Array.from(row.querySelectorAll('.lgt-kardex-code'), cell => cell.textContent))), [['N', 'N'], ['N', 'N'], ['N', ''], ['D', 'D']]);
        assert.equal(await page.locator('#kardexTableBody').textContent().then(text => text.includes('TECHNICAL_ROW')), false);
        assert.equal(await page.locator('#kardexPageSummary').textContent(), 'Mostrando 1–20 de 125');
        assert.equal(await page.locator('#kardexPreviousPage').isDisabled(), true);
        await page.locator('#kardexNextPage').click();
        await page.waitForFunction(() => document.querySelector('#kardexPageSummary').textContent === 'Mostrando 21–40 de 125');
        assert.equal(await page.locator('#kardexTableBody tr').first().locator('td').first().textContent(), '21');
        await page.locator('#kardexPreviousPage').click();
        await page.waitForFunction(() => document.querySelector('#kardexPageSummary').textContent === 'Mostrando 1–20 de 125');
        await page.locator('#kardexPerPage').selectOption('50');
        await page.waitForFunction(() => document.querySelectorAll('#kardexTableBody tr').length === 50);
        await page.locator('#kardexPerPage').selectOption('100');
        await page.waitForFunction(() => document.querySelectorAll('#kardexTableBody tr').length === 100);
        await page.locator('#kardexNextPage').click();
        await page.waitForFunction(() => document.querySelector('#kardexPageSummary').textContent === 'Mostrando 101–125 de 125');
        assert.equal(await page.locator('#kardexNextPage').isDisabled(), true);
        const now = new Date();
        const lima = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
        const [year, month] = lima.split('-').map(Number);
        const previousLast = new Date(Date.UTC(year, month - 1, 0));
        const last = previousLast.toISOString().slice(0, 10);
        assert.equal(await page.locator('#kardexFechaDesde').inputValue(), last.slice(0, 7) + '-01');
        assert.equal(await page.locator('#kardexFechaHasta').inputValue(), last);
        await page.locator('#kardexFechaDesde').fill('2026-10-01');
        await page.locator('#kardexFechaHasta').fill('2026-09-30');
        await page.locator('#kardexDownloadBtn').click();
        assert.equal(downloads.length, 0);
        assert.equal(await page.locator('#kardexFechaHasta').evaluate(el => el.validity.valid), false);
        await page.locator('#kardexFechaHasta').fill('2026-10-09');
        await page.locator('#kardexDownloadBtn').click();
        await page.waitForTimeout(200);
        const params = new URL(downloads[0]).searchParams;
        assert.equal(params.get('personal_id'), worker.id);
        assert.equal(params.get('fecha_desde'), '2026-10-01');
        assert.equal(params.get('fecha_hasta'), '2026-10-09');
        assert.equal(params.has('page'), false);
        assert.equal(params.has('per_page'), false);
        for (const width of [320, 375, 756, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(400);
            const dimensions = await page.locator('.lgt-kardex-export-range').evaluate(el => [el.scrollWidth, el.clientWidth]);
            assert.ok(dimensions[0] <= dimensions[1] + 1, `${width}: range overflows ${dimensions}`);
            assert.equal(await page.locator('#kardexTableBody .lgt-kardex-code').count(), 50);
            const pager = await page.locator('#kardexPagination').evaluate(el => [el.scrollWidth, el.clientWidth]);
            assert.ok(pager[0] <= pager[1] + 1, `${width}: pagination overflows ${pager}`);
            if (width === 375 || width === 1440) {
                await page.locator('#kardexPagination').scrollIntoViewIfNeeded();
                await page.screenshot({ path: `.superpowers/kardex-pager-${width}.png` });
            }
        }
        assert.deepEqual(detailRequests, [{ page: 1, per_page: 20 }, { page: 2, per_page: 20 }, { page: 1, per_page: 20 }, { page: 1, per_page: 50 }, { page: 1, per_page: 100 }, { page: 2, per_page: 100 }]);
        failNextDetail = true;
        await page.locator('#kardexPreviousPage').click();
        await page.locator('[data-kardex-retry]').click();
        await page.waitForFunction(() => document.querySelector('#kardexPageSummary').textContent === 'Mostrando 1–100 de 125');
        assert.deepEqual(detailRequests.slice(-2), [{ page: 1, per_page: 100 }, { page: 1, per_page: 100 }]);
        await page.locator('#kardexPerPage').selectOption('20');
        await page.waitForFunction(() => document.querySelector('#kardexPageSummary').textContent === 'Mostrando 1–20 de 125');
        // Deliberately disable abort so a late response must be rejected by request identity.
        await page.evaluate(() => { window.AbortController = undefined; });
        holdNextDetail = true;
        await page.locator('#kardexNextPage').click();
        await heldDetailStarted;
        await page.locator('#kardexPersonalSearch').fill('VACIO');
        await page.locator('#kardexPersonalResults [data-index]').first().click();
        await page.waitForFunction(() => document.querySelector('#kardexPageSummary').textContent === 'Mostrando 0–0 de 0');
        releaseHeldDetail();
        await page.waitForTimeout(300);
        assert.equal(await page.locator('#kardexPageSummary').textContent(), 'Mostrando 0–0 de 0');
        assert.equal(await page.locator('#kardexPerPage').inputValue(), '20');
        assert.equal(await page.locator('#kardexPreviousPage').isDisabled(), true);
        assert.equal(await page.locator('#kardexNextPage').isDisabled(), true);
        assert.match(await page.locator('#kardexTableBody').textContent(), /No tiene entregas/);
        assert.deepEqual(errors, []);
        console.log('PASS Kardex: server page requests 20/50/100, counters, compact rows, no technical rows, full-range download, retry, stale response rejected, empty worker, responsive controls.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
