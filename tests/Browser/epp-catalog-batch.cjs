const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        await page.addInitScript({ path: 'public/build/' + manifest['resources/js/app.js'].file });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-epp-signed-delivery.php', 'catalog-form'], { encoding: 'utf8' });
        await page.route('**/*', route => {
            const document = route.request().resourceType() === 'document';
            const body = document ? html.replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') : JSON.stringify({ data: null, items: [{ id: '00000000-0000-0000-0000-000000000003', label: 'TRABAJADOR DE PRUEBA MOVIL', nombre: 'TRABAJADOR DE PRUEBA MOVIL', documento: '00000001', puesto: 'OPERARIO', estado: 'ACTIVO' }] });
            return route.fulfill({ contentType: document ? 'text/html' : 'application/json', body });
        });
        await page.goto('http://127.0.0.1:8000/synthetic-epp-catalog');
        await page.addStyleTag({ path: 'public/proserge-app.css' });
        await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
        await page.addStyleTag({ path: 'resources/css/modules/epps.css' });
        await page.locator('[data-epp-open-modal="eppDeliveryModal"]').click();
        await page.locator('#eppPersonalSearch').fill('PRUEBA');
        await page.locator('#eppPersonalResults button').click();
        await page.locator('#eppSelect').selectOption('00000000-0000-0000-0000-000000000004');
        await page.locator('#eppDeliveryAttributes [name="talla"]').selectOption('M');
        assert.equal(await page.locator('[data-epp-add-to-batch]').count(), 1, 'Debe permitir acumular articulos sin enviar a firmar');
        await page.locator('[data-epp-add-to-batch]').click();
        assert.equal(await page.locator('[data-epp-batch-item]').count(), 1);
        assert.equal(await page.locator('#eppPersonalSearch').getAttribute('readonly'), '');
        assert.equal(await page.locator('#eppSelect').inputValue(), '');
        await page.locator('#eppSelect').selectOption('00000000-0000-0000-0000-000000000005');
        await page.locator('#eppDeliveryAttributes [name="talla"]').selectOption('L');
        await page.locator('#eppDeliveryModal [name="cantidad"]').fill('3');
        await page.locator('[data-epp-add-to-batch]').click();
        assert.equal(await page.locator('[data-epp-batch-item]').count(), 2);
        assert.match(await page.locator('[data-epp-batch-list]').textContent(), /GUANTES DE SEGURIDAD.*M.*GUANTES DE REEMPLAZO.*3.*L/s);
        for (const width of [320, 375, 756, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const sizes = await page.locator('#eppDeliveryModal .epp-modal-card').evaluate(element => [element.scrollWidth, element.clientWidth]);
            assert.ok(sizes[0] <= sizes[1] + 1, `${width}: modal overflow ${sizes}`);
            const positions = await page.locator('#eppDeliveryModal form').evaluate(form => {
                const editor = form.querySelector('.epp-delivery-editor').getBoundingClientRect();
                const history = form.querySelector('.epp-mini-kardex').getBoundingClientRect();
                return { separated: editor.right <= history.left + 1 || editor.bottom <= history.top + 1 || history.right <= editor.left + 1 };
            });
            assert.equal(positions.separated, true, `${width}: history panel must not overlap item fields`);
            await page.screenshot({ path: `storage/app/testing/epp-catalog-batch-${width}.png`, fullPage: true });
        }
        await page.locator('[data-epp-remove-from-batch]').first().click();
        assert.equal(await page.locator('[data-epp-batch-item]').count(), 1);
        assert.equal(await page.locator('[name="items[0][cantidad]"]').inputValue(), '3');
        assert.equal(await page.locator('[name="items[1][cantidad]"]').count(), 0);
        // Prevent navigation only after the production submit listener has prepared the payload.
        await page.locator('[data-epp-batch-form]').evaluate(form => form.addEventListener('submit', event => { event.preventDefault(); window.batchPosted = Object.fromEntries(new FormData(form)); }));
        await page.locator('[data-epp-batch-form] button[type="submit"]').click();
        const posted = await page.evaluate(() => window.batchPosted);
        assert.equal(posted['items[0][cantidad]'], '3');
        assert.equal(posted['items[0][talla]'], 'L');
        assert.equal(posted.personal_id, '00000000-0000-0000-0000-000000000003');
        assert.equal(posted.personal_label, 'TRABAJADOR DE PRUEBA MOVIL', 'El nombre visible debe sobrevivir al regreso por validacion');
        const recovered = await browser.newPage();
        const recoveredHtml = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-epp-signed-delivery.php', 'catalog-recovery'], { encoding: 'utf8' });
        await recovered.addInitScript({ path: 'public/build/' + manifest['resources/js/app.js'].file });
        await recovered.route('**/*', route => route.fulfill({ contentType: 'text/html', body: route.request().resourceType() === 'document' ? recoveredHtml.replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') : '' }));
        await recovered.goto('http://127.0.0.1:8000/synthetic-epp-recovery');
        assert.equal(await recovered.locator('[data-epp-batch-item]').count(), 1);
        assert.equal(await recovered.locator('#eppPersonalSearch').inputValue(), 'TRABAJADOR DE PRUEBA MOVIL');
        assert.equal(await recovered.locator('#eppPersonalSearch').getAttribute('readonly'), '');
        await recovered.close();
        assert.deepEqual(errors, []);
        console.log('PASS catalog batch: add two items, keep worker, remove/reindex, submit without extra item, responsive modal');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
