const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const results = [];
    const fs = require('node:fs');
    try {
        for (const width of [1440, 390]) {
            for (const test of [
                { name: 'Revocar Actualizar en pestana abierta', initial: 'notification-write', canUpdate: false, denied: false },
                { name: 'Revocar Ver limpia contenido de campana', initial: 'notification-write', canUpdate: false, denied: true },
                { name: 'Conceder Actualizar en pestana abierta', initial: 'notification-read', canUpdate: true, denied: false },
                { name: 'Sesion vencida limpia campana', initial: 'notification-write', canUpdate: false, denied: true, status: 401 },
                { name: 'Respuesta sin capacidad no concede escritura', initial: 'notification-write', canUpdate: false, denied: false, omitCapability: true },
            ]) {
                const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-role-permissions.php', test.initial], {
                    encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing', DB_PORT: process.env.DB_PORT || '3306', DB_DATABASE: 'proserge_app_test', DB_URL: '' },
                });
                const page = await browser.newPage({ viewport: { width, height: 900 } });
                let changed = false;
                let requests = 0;
                await page.route('**/*', route => {
                    if (route.request().resourceType() === 'document') {
                        return route.fulfill({ contentType: 'text/html', body: html });
                    }
                    if (route.request().url().includes('/notificaciones/poll')) {
                        requests++;
                        if (changed && test.denied) {
                            return route.fulfill({ status: test.status || 403, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'PERMISSION_DENIED' }) });
                        }
                        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, count: 1,
                            can_update: changed && test.omitCapability ? undefined : (changed ? test.canUpdate : test.initial === 'notification-write'), items: [{
                                id: 'synthetic', title: 'Aviso sintetico para auditoria', message: 'Contenido sintetico protegido',
                                status: 'UNREAD', mark_read_url: '/synthetic/read', archive_url: '/synthetic/archive', action_url: '/synthetic/open',
                            }],
                        }) });
                    }
                    return route.fulfill({ status: 204, body: '' });
                });
                await page.goto('http://127.0.0.1:8000/synthetic-notification-permission-audit');
                await page.addStyleTag({ path: 'public/proserge-app.css' });
                await page.locator('.header-notif-item-title').filter({ hasText: 'Aviso sintetico para auditoria' }).waitFor({ state: 'attached' });
                const initialForms = await page.locator('.header-notif-panel form').count();
                changed = true;
                await page.waitForResponse(response => response.url().includes('/notificaciones/poll'), { timeout: 8000 });
                await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                const forms = await page.locator('.header-notif-panel form').count();
                const protectedText = await page.evaluate(() => document.querySelector('.header-notif-list')?.textContent || '');
                try {
                    if (test.denied) assert.equal(protectedText.includes('Contenido sintetico protegido'), false);
                    else assert.equal(forms, test.canUpdate ? 3 : 0);
                    results.push({ test: test.name, width, result: 'PASS', initialForms, forms, requests });
                } catch (error) {
                    results.push({ test: test.name, width, result: 'FAIL', initialForms, forms, requests, protectedContentRemains: protectedText.includes('Contenido sintetico protegido') });
                }
                if (test.denied) {
                    assert.equal(await page.locator('#headerNotifMenu, #headerNotifPanel, #headerNotifBackdrop').count(), 0);
                    const stoppedRequests = requests;
                    await page.waitForResponse(response => response.url().includes('/notificaciones/poll'), { timeout: 5500 })
                        .then(() => assert.fail('Polling must stop after 401/403'), error => assert.equal(error.name, 'TimeoutError'));
                    assert.equal(requests, stoppedRequests);
                }
                if (!test.denied && test.canUpdate) {
                    await page.locator('#headerNotifToggle').click();
                    await page.locator('#headerNotifPanel').evaluate(async panel => {
                        panel.classList.add('is-open');
                        panel.setAttribute('aria-hidden', 'false');
                        await Promise.all(panel.getAnimations().map(animation => animation.finished));
                    });
                    fs.mkdirSync('storage/codex_outputs/notification-permissions', { recursive: true });
                    await page.screenshot({ path: 'storage/codex_outputs/notification-permissions/header-' + width + '.png' });
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                    const bounds = await page.locator('#headerNotifPanel').boundingBox();
                    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1);
                }
                await page.close();
            }
        }
        console.log(JSON.stringify(results, null, 2));
        if (results.some(result => result.result === 'FAIL')) process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
