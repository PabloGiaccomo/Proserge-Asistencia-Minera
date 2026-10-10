const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        for (const mode of ['catalog-review', 'catalog-return-review', 'form', 'return-form', 'review', 'return-review', 'witness', 'show', 'loss', 'verify', 'dashboard', 'personal-public', 'personal-ingreso']) {
            const page = await browser.newPage({ deviceScaleFactor: 2 });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
            const html = execFileSync(process.env.PROSERGE_PHP || 'C:/xampp/php/php.exe', ['tests/Browser/render-epp-signed-delivery.php', mode], { encoding: 'utf8' });
            await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: route.request().resourceType() === 'document' ? html.replace(/<script\b(?![^>]*application\/json)[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') : '' }));
            await page.goto('http://127.0.0.1:8000/epps/operaciones/prueba');
            await page.addStyleTag({ path: 'public/proserge-app.css' });
            await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
            await page.addScriptTag({ path: 'public/build/' + manifest['resources/js/app.js'].file });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            if (['catalog-review', 'review', 'witness'].includes(mode)) {
                const declaration = page.locator('[data-epp-delivery-declaration]');
                assert.equal(await declaration.count(), 1);
                assert.match(await declaration.textContent(), /DECLARO HABER RECIBIDO.*e\. Asimismo declara haberlos recibido/s);
                assert.ok(await declaration.evaluate(element => Boolean(element.compareDocumentPosition(document.querySelector('[data-signature-pad]')) & Node.DOCUMENT_POSITION_FOLLOWING)));
            } else if (['catalog-return-review', 'return-review'].includes(mode)) {
                assert.equal(await page.locator('[data-epp-delivery-declaration]').count(), 0);
            }
            for (const width of mode.startsWith('personal-') ? [] : [320, 375, 390, 430, 1440]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.waitForTimeout(400);
                if (width <= 430 && mode !== 'dashboard') {
                    const sidebarRight = await page.locator('#sidebar').evaluate(element => element.getBoundingClientRect().right);
                    assert.ok(sidebarRight <= 1, `${mode} ${width}: closed sidebar overlaps content (${sidebarRight})`);
                }
                const overflow = await page.locator(mode === 'dashboard' ? '[data-dashboard-preview]' : '.epp-operation').evaluate(element => ({ scroll: element.scrollWidth, width: element.clientWidth, page: document.documentElement.scrollWidth, viewport: window.innerWidth }));
                assert.ok(overflow.scroll <= overflow.width + 1 && overflow.page <= overflow.viewport + 1, `${mode} ${width}: ${JSON.stringify(overflow)}`);
                fs.mkdirSync('storage/app/testing', { recursive: true });
                await page.screenshot({ path: `storage/app/testing/epp-signed-${mode}-${width}.png`, fullPage: true });
            }
            if (mode === 'dashboard') {
                assert.equal(await page.locator('.lgtx-heat-head span').count(), 10);
                assert.equal(await page.locator('.lgtx-heat-row span').count(), 9);
                assert.match(await page.locator('.lgtx-kpis').textContent(), /Pendientes registrados en lotes/);
            } else if (mode === 'form') {
                assert.match(await page.locator('[data-line-availability]').textContent(), /Disponibles: 5/);
                await page.locator('[data-add-line]').click();
                assert.equal(await page.locator('[data-operation-line]').count(), 2);
                await page.locator('[data-operation-line]').last().locator('[data-remove-line]').click();
                await page.locator('[data-quantity-step="1"]').click();
                assert.equal(await page.locator('input[name="lines[0][cantidad_solicitada]"]').inputValue(), '11');
                await page.getByText('Sustitucion o motivo de nueva entrega', { exact: true }).click();
                await page.locator('[data-actual-product]').selectOption('00000000-0000-0000-0000-000000000005');
                assert.match(await page.locator('[data-line-availability]').textContent(), /Stock sin conciliar/);
            } else if (mode === 'return-form') {
                const rows = page.locator('[data-return-line]');
                assert.equal(await rows.count(), 2);
                assert.equal(await rows.first().locator('[data-return-quantity]').isDisabled(), true);
                await rows.first().locator('[data-return-select]').check();
                await rows.first().locator('[data-return-quantity]').fill('2');
                assert.match(await rows.first().locator('[data-return-remaining]').textContent(), /Saldo restante: 3/);
                await rows.first().locator('[data-return-condition]').selectOption('DANADO');
                assert.equal(await rows.first().locator('[data-return-destination]').inputValue(), 'CUARENTENA');
                assert.equal(await rows.first().locator('option[value="DISPONIBLE"]').evaluate(option => option.disabled), true);
                await rows.last().locator('[data-return-select]').check();
                await rows.last().locator('[data-return-quantity]').fill('2');
                assert.match(await rows.last().locator('[data-return-remaining]').textContent(), /Saldo restante: 0/);
                assert.equal(await page.locator('[data-return-form]').evaluate(form => new FormData(form).getAll('lines[0][cantidad_solicitada]')[0]), '2');
                await rows.first().locator('[data-return-select]').uncheck();
                assert.equal(await rows.first().locator('[data-return-quantity]').isDisabled(), true);
                assert.equal(await page.locator('[data-return-form]').evaluate(form => new FormData(form).has('lines[0][cantidad_solicitada]')), false);
            } else if (mode === 'catalog-review' || mode === 'catalog-return-review' || mode === 'review' || mode === 'return-review' || mode === 'witness' || mode.startsWith('personal-')) {
                if (mode === 'catalog-review' || mode === 'catalog-return-review') {
                    assert.equal(await page.locator('[data-epp-signing-item]').count(), 2);
                    assert.match(await page.locator('[data-epp-signing-item]').first().textContent(), /GUANTES DE REEMPLAZO.*Cantidad: 5.*Talla: M.*BLANCO/s);
                    assert.match(await page.locator('[data-epp-signing-item]').last().textContent(), /CASCO DE SEGURIDAD.*Cantidad: 2.*TIPO: I/s);
                }
                if (mode === 'catalog-return-review') {
                    assert.match(await page.locator('[name="confirmacion"]').locator('..').textContent(), /Confirmo haber devuelto/);
                    assert.match(await page.locator('[data-epp-signing-item]').first().textContent(), /Saldo restante: 1/);
                    assert.equal(await page.locator('input[type="number"]').count(), 0);
                }
                if (mode === 'return-review') {
                    assert.match(await page.locator('[name="confirmacion"]').locator('..').textContent(), /Confirmo haber devuelto/);
                    assert.match(await page.locator('.epp-operation').textContent(), /Saldo restante: 3/);
                    assert.equal(await page.locator('[data-return-quantity]').count(), 0);
                }
                await page.setViewportSize({ width: 375, height: 1000 });
                await page.waitForTimeout(400);
                const canvas = page.locator('[data-signature-pad]');
                await canvas.scrollIntoViewIfNeeded();
                await page.waitForTimeout(100);
                assert.equal(await canvas.evaluate(element => element.signaturePad.hasInk()), false);
                const rect = await canvas.boundingBox();
                assert.equal(await canvas.evaluate(element => {
                    const box = element.getBoundingClientRect();
                    return document.elementFromPoint(box.x + 20, box.y + 25) === element;
                }), true, `${mode}: signature pad must receive pointer input`);
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
        console.log('PASS signed EPP delivery/return form/review/witness/result, selected quantities/remaining balances/dispositions, Personal signatures, drawing/clear/resize, console and 320/375/390/430/1440 px');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
