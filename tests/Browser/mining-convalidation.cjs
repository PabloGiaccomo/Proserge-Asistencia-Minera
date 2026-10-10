const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const html = execFileSync(process.env.PHP_BINARY || 'php', ['tests/Browser/render-mining-convalidation.php'], { encoding: 'utf8' });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(html);
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        await page.addStyleTag({ path: `public/build/${manifest['resources/css/app.css'].file}` });
        await page.addScriptTag({ path: 'resources/js/modules/mining-convalidation.js' });
        await page.evaluate(() => window.closeDialog = element => element.closest('dialog').close());
        const ruleId = '00000000-0000-4000-8000-000000000004';
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.evaluate(id => window.openMiningConvalidationRules(id), ruleId);
            assert.match(await page.locator('#miningConvalidationTarget').innerText(), /Examen destino.*Mina destino/);
            assert.equal(await page.locator('[data-equivalent-choice]:visible').count(), 1);
            assert.equal(await page.locator('[data-equivalent-choice]:visible input').isChecked(), true);
            await page.locator('#miningEquivalentSearch').fill('no existe');
            assert.equal(await page.locator('[data-equivalent-choice]:visible').count(), 0);
            await page.locator('#miningEquivalentSearch').fill('origen');
            assert.equal(await page.locator('[data-equivalent-choice]:visible').count(), 1);
            assert.equal(await page.locator('#modal-convalidation-rules').evaluate(el => el.scrollWidth > el.clientWidth + 1), false, `Rules overflow at ${width}`);
            fs.mkdirSync('storage/app/testing', { recursive: true });
            await page.screenshot({ path: `storage/app/testing/mining-rules-${width}.png` });
            await page.evaluate(() => document.getElementById('modal-convalidation-rules').close());
        }
        const payload = {
            available: true,
            destino: { trabajador: 'Trabajador de prueba', mina: 'Mina destino', examen: 'Examen destino', requiere_nota: false, nota_minima: null },
            options: [
                { id: 'origin-no-score', examen: 'Curso equivalente sin nota', mina: 'Mina origen', nota: null, equivalente: true, fecha_realizacion: '05/10/2026', fecha_vencimiento: null },
                { id: 'origin-score', examen: 'Curso equivalente con nota', mina: 'Otra mina', nota: '16.00', equivalente: true, fecha_realizacion: '05/10/2026', fecha_vencimiento: '05/10/2027' },
            ],
        };
        await page.route('**/options/worker-exam', route => route.fulfill({ json: payload }));
        await page.evaluate(async () => {
            document.getElementById('workerDialog').showModal();
            await window.loadMiningConvalidationOptions(document.querySelector('[data-worker-exam-card]'), document.getElementById('convalidationPanel'), {
                optionsUrl: 'http://fixture.test/options/__EXAM__', submitUrl: 'http://fixture.test/confirm/__EXAM__', csrfToken: 'test-token',
            });
        });
        const panel = page.locator('#convalidationPanel');
        assert.equal(await panel.locator('button[type=submit]').isDisabled(), true);
        await panel.locator('select').selectOption('origin-no-score');
        assert.match(await panel.locator('[data-convalidation-summary]').innerText(), /Sin nota.*Destino: Examen destino.*Mina destino/);
        await panel.locator('[name=confirmar]').check();
        assert.equal(await panel.locator('button[type=submit]').isEnabled(), true);
        await panel.locator('select').selectOption('origin-score');
        assert.equal(await panel.locator('[name=confirmar]').isChecked(), false);
        assert.equal(await panel.locator('button[type=submit]').isDisabled(), true);
        assert.match(await panel.locator('[data-convalidation-summary]').innerText(), /Nota: 16.00/);
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(await page.locator('#workerDialog').evaluate(el => el.scrollWidth > el.clientWidth + 1), false, `Confirmation overflow at ${width}`);
            await page.screenshot({ path: `storage/app/testing/mining-convalidation-${width}.png` });
        }
        assert.deepEqual(errors, []);
        console.log('PASS: equivalence rules, search, explicit origin/destination, optional score, confirmation reset, desktop/mobile, no JS errors.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
