const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const fixtures = JSON.parse(execFileSync('php', ['tests/Browser/personal-live-search-fixture.php'], { encoding: 'utf8', maxBuffer: 15 * 1024 * 1024 }));
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            let navigations = 0;
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => { if (request.resourceType() === 'document') navigations += 1; });
            await page.addInitScript(() => { window.personalSkipBootLoading = true; });
            await page.route('**/*', async route => {
                const request = route.request();
                const url = new URL(request.url());
                if (url.pathname.startsWith('/build/')) {
                    const file = path.join('public', url.pathname);
                    return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(file) });
                }
                if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixtures.initial.html });
                if (url.pathname === '/personal') {
                    assert.equal(url.searchParams.get('puestos[]'), 'OPERARIO');
                    const q = url.searchParams.get('q') || '';
                    const result = q === '77012755' ? fixtures.hit : q === '77012755 77012756' ? fixtures.pair : q ? fixtures.empty : url.searchParams.get('page') === '2' ? fixtures.page2 : fixtures.all;
                    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(result) });
                }
                return route.fulfill({ contentType: 'application/json', body: '{}' });
            });
            await page.goto('http://synthetic.local/personal?puestos%5B%5D=OPERARIO');
            const search = page.locator('#personal-search');
            await search.fill('77012755');
            await page.waitForFunction(() => document.querySelector('#personalCount').textContent === '1 trabajadores');
            assert.equal(await search.evaluate(el => document.activeElement === el && el.selectionStart === el.value.length), true);
            assert.equal(await page.locator('.js-person-row:visible').count(), 1);
            await search.fill('77012755 77012756');
            await page.waitForFunction(() => document.querySelector('#personalCount').textContent === '2 trabajadores');
            assert.equal(new URL(await page.locator('#personalExportExcelAction').getAttribute('href')).searchParams.get('q'), '77012755 77012756');
            await search.fill('NO EXISTE');
            await page.waitForFunction(() => document.querySelector('#personalCount').textContent === '0 trabajadores');
            assert.equal(await page.locator('#personalEmptyResults').isVisible(), true);
            await search.fill('');
            await page.waitForFunction(() => document.querySelector('#personalCount').textContent === '26 trabajadores');
            assert.equal(await page.locator('.js-person-row:visible').count(), 25);
            await page.locator('#personalPagination button[data-page="2"]').first().click();
            await page.waitForFunction(() => document.querySelector('#personalPaginationInfo').textContent.includes('26-26'));
            assert.equal(await page.locator('.js-person-row:visible').count(), 1);
            assert.equal(navigations, 1, 'Searching and pagination must not reload the document');
            assert.deepEqual(errors, []);
            fs.mkdirSync('.codex-tmp', { recursive: true });
            await page.screenshot({ path: `.codex-tmp/personal-live-search-${width}.png`, fullPage: true });
            console.log(`PASS ${width}px: search, DNI lists, empty results, clear, focus, filters and pagination without reload.`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
