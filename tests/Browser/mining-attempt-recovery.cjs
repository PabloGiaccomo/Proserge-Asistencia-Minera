const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({channel: 'chrome', headless: true});
    try {
        for (const viewport of [{width: 390, height: 844}, {width: 1440, height: 1000}]) {
            for (const type of ['performed', 'scheduled']) {
                const page = await browser.newPage({viewport});
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                const html = execFileSync('php', ['tests/Browser/render-mining-attempt-recovery.php', type], {encoding: 'utf8'});
                await page.setContent(html);
                const form = page.locator('[data-exam-action-panel="' + type + '"] form');
                await form.getByLabel('Observación').waitFor();
                assert.equal(await form.getByLabel('Observación').inputValue(), 'Texto de prueba <seguro>');
                assert.equal(await form.getByLabel('Realización').inputValue(), '2026-11-03');
                const score = form.locator('[name="nota"]');
                assert.equal(await score.getAttribute('required'), '');
                assert.equal(await score.getAttribute('min'), '14');
                assert.equal(await score.getAttribute('aria-invalid'), 'true');
                await form.getByLabel('Resultado').selectOption('NO_APLICA');
                assert.equal(await score.getAttribute('required'), null);
                assert.equal(await form.getByRole('alert').textContent(), 'Corrige los campos indicados. Vuelve a seleccionar el archivo.');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        console.log('Recuperación de intento minero verificada en 390 y 1440 px, realizado y programado.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
