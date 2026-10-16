const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const fixtures = JSON.parse(execFileSync('php', ['tests/Browser/personal-live-search-fixture.php'], { encoding: 'utf8', maxBuffer: 15 * 1024 * 1024 }));
    const workers = [
        { id: 'first', dni: '42419346', documento: '42419346', nombre: 'PERSONA SINTETICA UNO', puesto: 'OPERARIO' },
        { id: 'second', dni: '77012755', documento: '77012755', nombre: 'PERSONA SINTETICA DOS', puesto: 'OPERARIO' },
    ];
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            let downloads = 0;
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('download', () => { downloads += 1; });
            await page.route('**/*', route => {
                const request = route.request();
                const url = new URL(request.url());
                if (url.pathname.startsWith('/build/')) {
                    const file = path.join('public', url.pathname);
                    return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
                }
                if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixtures.export });
                if (url.pathname.endsWith('/personal')) {
                    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ workers }) });
                }
                if (request.method() === 'POST') {
                    if (url.pathname === '/personal/exportar') { downloads += 1; return route.abort(); }
                    const ids = [...request.postData().matchAll(/name="personal_ids\[\]"\r?\n\r?\n([^\r\n]+)/g)].map(match => match[1]);
                    const selected = workers.filter(worker => ids.includes(worker.id));
                    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({
                        headers: ['DNI', 'Nombre'], rows: selected.map(worker => [worker.dni, worker.nombre]), row_ids: selected.map(worker => worker.id),
                    }) });
                }
                return route.fulfill({ contentType: 'application/json', body: '{}' });
            });
            await page.goto('http://synthetic.local/personal/exportar');
            const input = page.locator('#exportWorkerSearch');
            await input.fill('42419346 7701275');
            await input.press('Enter');
            await page.waitForFunction(() => document.querySelectorAll('#exportSelectedWorkers [data-remove-worker]').length === 1);
            await input.fill('42419346 77012755');
            await input.press('Enter');
            await page.waitForFunction(() => document.querySelectorAll('#exportSelectedWorkers [data-remove-worker]').length === 2);
            await input.press('Enter');
            await page.waitForTimeout(500);
            assert.equal(await page.locator('#exportSelectedWorkers [data-remove-worker]').count(), 2);
            await page.locator('[data-remove-worker="second"]').click();
            await input.fill('PERSONA SINTETICA');
            await input.press('Enter');
            await page.waitForTimeout(500);
            assert.equal(await page.locator('#exportSelectedWorkers [data-remove-worker]').count(), 1);
            await input.fill('42419346 77012755');
            await input.press('Enter');
            await page.waitForFunction(() => document.querySelectorAll('#exportSelectedWorkers [data-remove-worker]').length === 2);
            await page.waitForFunction(() => document.querySelectorAll('#exportPreviewContent tbody tr').length === 2);
            assert.equal(downloads, 0);
            assert.deepEqual(errors, []);
            await page.screenshot({ path: `.codex-tmp/personal-export-enter-${width}.png`, fullPage: true });
            console.log(`PASS ${width}px: Enter adds exact DNIs, ignores incomplete numbers, preserves and dedupes selection, names remain manual, no download.`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
