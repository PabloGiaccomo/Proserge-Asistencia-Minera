const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const html = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-supervisor-regularization.php'], { encoding: 'utf8' });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.fulfill({ contentType: 'text/html',
            body: html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '') }));
        await page.goto('http://127.0.0.1:8000/evaluaciones?tipo=supervisores');
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        await page.addStyleTag({ path: 'public/css/proserge-app.css' });
        await page.addStyleTag({ path: 'public/build/' + manifest['resources/css/app.css'].file });
        await page.addStyleTag({ path: 'public/css/evaluations-dashboard.css' });
        await page.addStyleTag({ content: '*{transition:none!important;animation:none!important}' });
        const script = [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].find(match => match[1].includes('data-supervisor-submit'));
        assert.ok(script);
        await page.addScriptTag({ content: script[1] });
        assert.equal(await page.locator('.eval-template select[required]').count(), 26);
        assert.equal(await page.locator('select[name="respuestas[A1]"]').inputValue(), '4');
        assert.equal(await page.locator('select[name="respuestas[A2]"]').inputValue(), '');
        assert.equal(await page.getByRole('link', { name: 'Descargar reporte' }).count(), 1);
        assert.equal(await page.getByText('Falta confirmar la nota A2', { exact: false }).count(), 1);
        await page.locator('[data-supervisor-submit]').evaluateAll(forms => forms.forEach(form => {
            form.addEventListener('submit', event => event.preventDefault());
        }));
        await page.getByRole('button', { name: 'Guardar regularización' }).click();
        assert.equal(await page.getByRole('button', { name: 'Guardar regularización' }).isEnabled(), true);
        await page.locator('select[name="respuestas[A2]"]').selectOption('3');
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(await page.locator('.evaluations-page').evaluate(e => e.scrollWidth > e.clientWidth + 1), false);
            fs.mkdirSync('storage/app/testing', { recursive: true });
            await page.screenshot({ path: `storage/app/testing/supervisor-regularization-${width}.png`, fullPage: true });
        }
        await page.getByRole('button', { name: 'Guardar regularización' }).click();
        assert.equal(await page.getByRole('button', { name: 'Guardando...' }).isDisabled(), true);
        await page.locator('textarea[name="motivo"]').fill('Pendiente de evidencia');
        await page.getByRole('button', { name: 'Guardar seguimiento' }).click();
        assert.equal(await page.getByRole('button', { name: 'Guardando...' }).count(), 2);
        assert.deepEqual(errors, []);
        console.log('PASS supervisor criteria, follow-up, report, submit lock and desktop/mobile rendering');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
