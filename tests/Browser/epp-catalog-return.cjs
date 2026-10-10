const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        await page.addInitScript({ path: 'public/build/' + manifest['resources/js/app.js'].file });
        const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-epp-signed-delivery.php', 'catalog-return'], { encoding: 'utf8' });
        // Synthetic harness: ALL network requests intercepted; never authenticates to the local app.
        await page.route('**/*', route => route.fulfill({ contentType: route.request().resourceType() === 'document' ? 'text/html' : 'application/json', body: route.request().resourceType() === 'document' ? html.replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') : JSON.stringify({ items: [{ id: '00000000-0000-0000-0000-000000000003', nombre: 'TRABAJADOR DE PRUEBA MOVIL', documento: '00000001' }] }) }));
        await page.goto('http://127.0.0.1:8000/synthetic-catalog-return');
        await page.addStyleTag({ path: 'public/proserge-app.css' });
        await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
        const form = page.locator('[data-return-form]');
        const rows = page.locator('[data-return-line]');
        assert.equal(await page.locator('[name="almacen_id"]').count(), 0);
        assert.equal(await rows.count(), 2);
        assert.equal(await rows.first().locator('[data-return-quantity]').isDisabled(), true);
        await form.locator('button[type="submit"]').click();
        assert.equal(await page.locator('[data-return-error]').isVisible(), true);
        await rows.first().locator('[data-return-select]').check();
        await rows.first().locator('[data-return-quantity]').fill('2');
        assert.match(await rows.first().locator('[data-return-remaining]').textContent(), /Saldo restante: 3/);
        await rows.last().locator('[data-return-select]').check();
        assert.match(await rows.last().locator('[data-return-remaining]').textContent(), /Saldo restante: 0/);
        await form.evaluate(element => element.addEventListener('submit', event => { event.preventDefault(); window.returnPosted = Object.fromEntries(new FormData(element)); }));
        await form.locator('button[type="submit"]').click();
        const posted = await page.evaluate(() => window.returnPosted);
        assert.equal(posted['items[0][cantidad]'], '2');
        assert.equal(posted['items[1][cantidad]'], '2');
        assert.equal(posted['items[0][entrega_id]'], '00000000-0000-0000-0000-000000000011');
        assert.equal(posted.personal_id, '00000000-0000-0000-0000-000000000003');
        await rows.last().locator('[data-return-select]').uncheck();
        assert.equal(await form.evaluate(element => new FormData(element).has('items[1][cantidad]')), false);
        for (const width of [320, 375, 756, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(400);
            const dimensions = await page.locator('.epp-operation').evaluate(element => [element.scrollWidth, element.clientWidth, document.documentElement.scrollWidth, window.innerWidth]);
            assert.ok(dimensions[0] <= dimensions[1] + 1 && dimensions[2] <= dimensions[3] + 1, `${width}: overflow ${dimensions}`);
            fs.mkdirSync('storage/app/testing', { recursive: true });
            await page.screenshot({ path: `storage/app/testing/epp-catalog-return-${width}.png`, fullPage: true });
        }
        await page.locator('[data-worker-search]').fill('PRUEBA');
        assert.equal(await form.evaluate(element => element.inert), true, 'Changing worker must disable old balances');
        await page.locator('[data-worker-results] button').click();
        assert.equal(await page.locator('[data-worker-search]').inputValue(), 'TRABAJADOR DE PRUEBA MOVIL');
        assert.match(await page.locator('[data-worker-selected]').textContent(), /00000001/);
        assert.deepEqual(errors, []);
        console.log('PASS catalog return: checklist, partial quantities, exact payload, no warehouse, worker change invalidates balances, responsive');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
