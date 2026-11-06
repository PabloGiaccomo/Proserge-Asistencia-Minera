const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-role-permissions.php'], { encoding: 'utf8' });
    const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: html
                .replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '')
                .replace(/<link\b[^>]*>/gi, '') }));
            await page.goto('http://127.0.0.1:8000/synthetic-role');
            await page.addStyleTag({ path: 'public/proserge-app.css' });
            await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
            const search = page.locator('[data-permission-search]');
            const epps = page.locator('#permission-module-epps');
            const registration = page.locator('input[name="permisos[epps][registrar]"]');
            const fields = () => page.locator('form[action*="roles"]').evaluate(form => Array.from(new FormData(form).keys()));
            const before = await fields();

            await search.fill('logística');
            assert.equal(await epps.isVisible(), true, 'Searching Logistica must also reveal its EPP operation permissions');
            await page.locator('[data-permission-target="epps"]').click();
            assert.equal(await epps.isVisible(), true);
            assert.equal(await registration.isChecked(), false);
            assert.deepEqual(await fields(), before, 'Navigation must never grant permissions');
            assert.equal(await page.locator('#permission-module-epps .permission-screen-title').evaluate(el => el === document.activeElement), true);

            await search.fill('Registrar devolución');
            assert.equal(await epps.isVisible(), true, 'The button wording must find its actual permission');
            await registration.check();
            assert.equal(await page.locator('input[name="permisos[epps][confirmar_entrega]"]').isChecked(), false);
            assert.equal(await page.locator('input[name="permisos[epps][confirmar_devolucion]"]').isChecked(), false);
            assert.equal(await page.locator('input[name="permisos[epps][ver]"]').isChecked(), false);
            assert.equal((await fields()).includes('permisos[epps][registrar]'), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.deepEqual(errors, []);
            fs.mkdirSync('outputs/logistics-role-permissions', { recursive: true });
            await page.screenshot({ path: `outputs/logistics-role-permissions/roles-${width}.png`, fullPage: true });
            await page.close();
        }
        console.log('Logistics EPP permissions: desktop/mobile discovery, navigation and independent grants passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
