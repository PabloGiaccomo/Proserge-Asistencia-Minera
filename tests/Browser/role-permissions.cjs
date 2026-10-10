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
            await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: html.replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') }));
            await page.goto('http://127.0.0.1:8000/synthetic-role');
            await page.addStyleTag({ path: 'public/proserge-app.css' });
            await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
            const checkbox = action => page.locator(`input[type="checkbox"][name="permisos[evaluaciones][${action}]"]`);
            await checkbox('dashboards').uncheck();
            assert.equal(await checkbox('evaluar_desempeno').isChecked(), true);
            await checkbox('ver').uncheck();
            assert.equal(await checkbox('ver').isChecked(), false);
            assert.equal(await checkbox('evaluar_desempeno').isEnabled(), true);
            await checkbox('crear').check();
            assert.equal(await checkbox('ver').isChecked(), false);
            assert.equal(await checkbox('dashboards').isChecked(), false);
            const submitted = await page.locator('form[action*="roles"]').evaluate(form => Array.from(new FormData(form).keys()));
            assert.equal(submitted.includes('permisos[evaluaciones][dashboards]'), false);
            assert.equal(submitted.includes('permisos[evaluaciones][crear]'), true);
            await page.locator('[data-permission-search]').fill('evaluaciones');
            await page.locator('[data-permission-select-all]').click();
            assert.equal(await checkbox('dashboards').isChecked(), true);
            await page.locator('[data-permission-clear-all]').click();
            assert.equal(await checkbox('dashboards').isChecked(), false);
            assert.equal(await checkbox('crear').isChecked(), false);
            assert.equal(await checkbox('crear').isEnabled(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            assert.deepEqual(errors, []);
            await page.close();
        }
        for (const mode of ['notification-read', 'notification-write']) {
            const header = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-role-permissions.php', mode], { encoding: 'utf8' });
            const page = await browser.newPage();
            await page.route('**/*', route => {
                if (route.request().resourceType() === 'document') {
                    return route.fulfill({ contentType: 'text/html', body: header });
                }
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, count: 1, items: [{
                    id: 'synthetic', title: 'Notificacion sintetica', status: 'UNREAD', mark_read_url: '/synthetic/read', archive_url: '/synthetic/archive', action_url: '/synthetic/open',
                }] }) });
            });
            await page.goto('http://127.0.0.1:8000/synthetic-notifications');
            await page.locator('.header-notif-item-title').filter({ hasText: 'Notificacion sintetica' }).waitFor({ state: 'attached' });
            const count = await page.locator('.header-notif-panel form').count();
            assert.equal(count, mode === 'notification-write' ? 3 : 0, 'Polling must preserve notification action permissions');
            await page.close();
        }
        console.log('Role permission checkboxes: desktop/mobile, independent checks and form submission passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
