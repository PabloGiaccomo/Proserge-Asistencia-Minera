const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        for (const mode of ['form', 'review', 'witness', 'show', 'personal-public', 'personal-ingreso']) {
            const page = await browser.newPage({ deviceScaleFactor: 2 });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
            const html = execFileSync(process.env.PROSERGE_PHP || 'C:/xampp/php/php.exe', ['tests/Browser/render-epp-signed-delivery.php', mode], { encoding: 'utf8' });
            await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: route.request().resourceType() === 'document' ? html.replace(/<script\b(?![^>]*application\/json)[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') : '' }));
            await page.goto('http://127.0.0.1:8000/epps/operaciones/prueba');
            await page.addStyleTag({ path: 'public/css/proserge-app.css' });
            await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
            await page.addScriptTag({ path: 'public/build/' + manifest['resources/js/app.js'].file });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            for (const width of mode.startsWith('personal-') ? [] : [320, 375, 390, 430, 1440]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.waitForTimeout(80);
                const overflow = await page.locator('.epp-operation').evaluate(element => ({ scroll: element.scrollWidth, width: element.clientWidth, page: document.documentElement.scrollWidth, viewport: window.innerWidth }));
                assert.ok(overflow.scroll <= overflow.width + 1 && overflow.page <= overflow.viewport + 1, `${mode} ${width}: ${JSON.stringify(overflow)}`);
                fs.mkdirSync('storage/app/testing', { recursive: true });
                await page.screenshot({ path: `storage/app/testing/epp-signed-${mode}-${width}.png`, fullPage: true });
            }
            if (mode === 'form') {
                assert.match(await page.locator('[data-line-availability]').textContent(), /Disponibles: 5/);
                await page.locator('[data-add-line]').click();
                assert.equal(await page.locator('[data-operation-line]').count(), 2);
                await page.locator('[data-operation-line]').last().locator('[data-remove-line]').click();
                await page.locator('[data-quantity-step="1"]').click();
                assert.equal(await page.locator('input[name="lines[0][cantidad_solicitada]"]').inputValue(), '11');
                await page.getByText('Sustitucion o motivo de nueva entrega', { exact: true }).click();
                await page.locator('[data-actual-product]').selectOption('00000000-0000-0000-0000-000000000005');
                assert.match(await page.locator('[data-line-availability]').textContent(), /Stock sin conciliar/);
            } else if (mode === 'review' || mode === 'witness' || mode.startsWith('personal-')) {
                await page.setViewportSize({ width: 375, height: 1000 });
                const canvas = page.locator('[data-signature-pad]');
                await canvas.scrollIntoViewIfNeeded();
                assert.equal(await canvas.evaluate(element => element.signaturePad.hasInk()), false);
                const rect = await canvas.boundingBox();
                await page.mouse.move(rect.x + 20, rect.y + 25);
                await page.mouse.down();
                for (let i = 1; i <= 20; i++) await page.mouse.move(rect.x + 20 + i * 8, rect.y + 25 + (i % 4) * 20);
                await page.mouse.up();
                const before = await canvas.evaluate(element => element.signaturePad.toPayload());
                assert.ok(before.ink.point_count >= 10);
                if (!mode.startsWith('personal-')) assert.ok(await canvas.evaluate(element => element.width >= 300 && element.height >= 300));
                else assert.ok((await page.locator('input[name="firma_base64"]').inputValue()).startsWith('data:image/png;base64,'));
                await page.setViewportSize({ width: 430, height: 1000 });
                await page.waitForTimeout(80);
                assert.equal(await canvas.evaluate(element => element.signaturePad.hasInk()), true);
                await page.locator('[data-clear-signature]').click();
                assert.equal(await canvas.evaluate(element => element.signaturePad.hasInk()), false);
                assert.equal(await page.locator(`input[name="${mode.startsWith('personal-') ? 'firma_base64' : 'firma_data_url'}"]`).inputValue(), '');
                assert.equal(await page.locator('[data-operation-line]').count(), 0);
                if (mode.startsWith('personal-')) {
                    await canvas.evaluate((element, dataUrl) => { element.signaturePad.importImage(dataUrl); element.signaturePad.clear(); }, before.firma_data_url);
                    await page.waitForTimeout(80);
                    assert.equal(await canvas.evaluate(element => element.signaturePad.hasInk()), false, 'Una imagen pendiente no debe restaurar la firma borrada');
                    await canvas.evaluate((element, dataUrl) => element.signaturePad.importImage(dataUrl), before.firma_data_url);
                    await page.waitForFunction(() => document.querySelector('[data-signature-pad]').signaturePad.hasInk());
                    await page.setViewportSize({ width: 390, height: 1000 });
                    await page.waitForTimeout(80);
                    assert.equal(await canvas.evaluate(element => element.signaturePad.hasInk()), true, 'Conservar firma restaurada al cambiar el ancho');
                }
            }
            assert.deepEqual(errors, [], mode + ' console');
            await page.close();
        }
        console.log('PASS signed EPP form/review/witness/result, Personal public/ingreso signature, drawing/clear/resize, availability, controls, console and 320/375/390/430/1440 px');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
