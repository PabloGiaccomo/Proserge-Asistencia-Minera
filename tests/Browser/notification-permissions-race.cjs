const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const postStatus of [200, 403]) {
            const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-role-permissions.php', 'notification-write'], {
                encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing', DB_DATABASE: 'proserge_app_test', DB_URL: '' },
            });
            const page = await browser.newPage();
            page.on('dialog', dialog => dialog.dismiss());
            let calls = 0;
            let completed = false;
            let release = null;
            const result = () => JSON.stringify({ ok: true, count: completed && postStatus === 200 ? 0 : 1,
                can_update: !completed || postStatus === 200, items: [{
                    id: 'synthetic', title: 'Aviso concurrente', status: completed && postStatus === 200 ? 'READ' : 'UNREAD',
                    mark_read_url: '/synthetic/read', archive_url: '/synthetic/archive', action_url: '/synthetic/open',
                }],
            });
            await page.route('**/*', async route => {
                if (route.request().resourceType() === 'document') {
                    return route.fulfill({ contentType: 'text/html', body: html });
                }
                if (route.request().url().includes('/notificaciones/poll')) {
                    calls++;
                    const snapshot = result();
                    if (calls === 2) {
                        await new Promise(resolve => { release = resolve; });
                    }
                    return route.fulfill({ contentType: 'application/json', body: snapshot });
                }
                if (route.request().method() === 'POST') {
                    completed = true;
                    return route.fulfill({ status: postStatus, contentType: 'application/json', body: JSON.stringify({ ok: postStatus === 200, message: 'Resultado sintetico' }) });
                }
                return route.fulfill({ status: 204, body: '' });
            });
            await page.goto('http://127.0.0.1:8000/synthetic-notification-race');
            await page.locator('.header-notif-item-title').waitFor({ state: 'attached' });
            await page.waitForRequest(request => request.url().includes('/notificaciones/poll'), { timeout: 8000 });
            await page.waitForFunction(() => document.querySelector('form[action$="/synthetic/read"]') !== null);
            assert.equal(typeof release, 'function');
            const post = page.waitForResponse(response => response.request().method() === 'POST');
            await page.locator('form[action$="/synthetic/read"] button').dispatchEvent('click');
            await post;
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const refresh = page.waitForResponse(response => response.url().includes('/notificaciones/poll') && calls >= 3, { timeout: 2000 });
            release();
            await refresh;
            await page.waitForFunction(status => status === 200
                ? !document.querySelector('.header-notif-item.is-unread')
                : !document.querySelector('.header-notif-panel form'), postStatus);
            assert.equal(calls, 3);
            console.log('PASS: POST ' + postStatus + ' during delayed poll invalidates stale response and refreshes immediately.');
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
