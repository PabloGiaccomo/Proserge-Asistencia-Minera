const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        for (const mode of ['opening', 'preview']) {
            const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-epp-inventory.php', mode], { encoding: 'utf8' });
            await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') }));
            await page.goto('http://127.0.0.1:8000/epps/inventario');
            await page.addStyleTag({ path: 'public/css/proserge-app.css' });
            await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
            await page.addScriptTag({ path: 'public/build/' + manifest['resources/js/app.js'].file });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            await page.addStyleTag({ content: '*{transition:none!important;animation:none!important}' });
            for (const width of [1440, 320, 375, 390, 430]) {
                await page.setViewportSize({ width, height: 1000 });
                fs.mkdirSync('storage/app/testing', { recursive: true });
                await page.screenshot({ path: `storage/app/testing/epp-inventory-${mode}-${width}.png`, fullPage: true });
                const overflow = await page.locator('.lgt-page').evaluate(element => ({ scroll: element.scrollWidth, width: element.clientWidth, offenders: [...element.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > element.getBoundingClientRect().right + 1).slice(0, 6).map(child => ({ tag: child.tagName, class: child.className })) }));
                assert.ok(overflow.scroll <= overflow.width + 1, `${mode} ${width}: ${JSON.stringify(overflow)}`);
                assert.ok(overflow.width >= Math.min(width - 40, 250), `${mode} ${width}: contenido oculto ${JSON.stringify(overflow)}`);
            }
            if (mode === 'opening') {
                assert.equal(await page.locator('input[name="lines[0][cantidad]"]').count(), 1);
                assert.equal(await page.locator('input[name="lines[1][cantidad]"]').count(), 1);
                await page.locator('select[name="lines[0][talla]"]').selectOption('M');
                await page.locator('input[name="lines[0][cantidad]"]').fill('8');
                await page.getByText('Registrar ajuste', { exact: true }).click();
                assert.equal(await page.locator('input[name="delta"]').isVisible(), true);
            } else {
                const form = page.locator('form[action$="/inventario/apertura"]');
                assert.equal(await form.evaluate(element => element.checkValidity()), false);
                await form.locator('input[name="confirmacion"]').check();
                assert.equal(await form.evaluate(element => element.checkValidity()), true);
                assert.equal(await page.getByRole('button', { name: 'Confirmar apertura' }).count(), 1);
            }
            await page.unrouteAll({ behavior: 'ignoreErrors' });
        }
        console.log('PASS inventory opening/preview, adjustment controls, confirmation and desktop/mobile widths 1440/320/375/390/430');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
