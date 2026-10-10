const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const render = status => execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-historical-import.php', status], { encoding: 'utf8' });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const status of ['blocked', 'ready']) {
            const html = render(status);
            await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') }));
            await page.goto(`http://127.0.0.1:8000/evaluaciones/historico/importar?status=${status}`);
            const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
            await page.addStyleTag({ path: 'public/css/proserge-app.css' });
            await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
            await page.addStyleTag({ path: 'public/css/evaluations-dashboard.css' });
            await page.addStyleTag({ content: '*{transition:none!important;animation:none!important}' });
            const script = [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].find(match => match[1].includes('data-historical-submit'));
            assert.ok(script);
            await page.addScriptTag({ content: script[1] });
            assert.equal(await page.getByRole('button', { name: /Aplicar carga/ }).isDisabled(), status === 'blocked');
            assert.equal(await page.locator('input[name="confirmacion"]').isDisabled(), status === 'blocked');
            assert.equal(await page.getByText('Referencia original:', { exact: false }).count(), 1);
            assert.equal(await page.getByText('Coincidencia por nombre, sin asignar:', { exact: false }).count(), status === 'blocked' ? 1 : 0);
            await page.locator('#hist-q').fill('12345678');
            await page.locator('#hist-status').selectOption(status === 'blocked' ? 'blocked' : 'proposed');
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                fs.mkdirSync('storage/app/testing', { recursive: true });
                await page.screenshot({ path: `storage/app/testing/historical-import-${status}-${width}.png`, fullPage: true });
                const overflow = await page.locator('.evaluations-page').evaluate(element => {
                    const bounds = element.getBoundingClientRect();
                    return { exceeds: element.scrollWidth > element.clientWidth + 1, width: bounds.width,
                        elements: [...element.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > bounds.right + 1)
                            .slice(0, 8).map(child => ({ tag: child.tagName, class: child.className, width: child.getBoundingClientRect().width })) };
                });
                assert.equal(overflow.exceeds, false, JSON.stringify(overflow));
            }
            if (status === 'ready') {
                await page.locator('input[name="confirmacion"]').check();
                await page.locator('form[action$="/historico/confirmar"]').evaluate(form => {
                    form.addEventListener('submit', event => event.preventDefault());
                });
                await page.getByRole('button', { name: /Aplicar carga/ }).click();
                assert.equal(await page.getByRole('button', { name: 'Procesando…' }).isDisabled(), true);
            }
            await page.unrouteAll({ behavior: 'ignoreErrors' });
        }
        assert.deepEqual(errors, []);
        console.log('PASS historical review, blocked confirmation, submit lock and desktop/mobile rendering');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
