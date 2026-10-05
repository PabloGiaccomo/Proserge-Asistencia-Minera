const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const mode of ['ready', 'missing']) {
            const html = execFileSync('php', ['tests/Browser/render-mining-catalog-organization.php', mode], { encoding: 'utf8' });
            const page = await browser.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => route.abort());
            await page.setContent(html);
            // Test the rendered feature with its actual build CSS, independent of sidebar bootstrapping.
            await page.evaluate(() => {
                const feature = document.querySelector('[data-mining-catalog]');
                document.body.replaceChildren(feature);
                document.body.className = '';
            });
            const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
            await page.addStyleTag({ path: `public/build/${manifest['resources/css/app.css'].file}` });
            await page.addScriptTag({ path: 'resources/js/modules/mining-catalog-organization.js' });
            assert.equal(await page.locator('[data-mining-catalog] script').count(), 0);
            await page.locator('[data-catalog-search]').fill('no coincide');
            assert.equal(await page.locator('[data-catalog-row]:visible').count(), 0);
            assert.equal(await page.locator('[data-catalog-empty]').isVisible(), true);
            await page.locator('[data-catalog-search]').fill('');
            await page.locator('[data-catalog-filter]').selectOption('similar');
            assert.equal(await page.locator('[data-catalog-row]:visible').count(), 2);
            await page.locator('[data-catalog-filter]').selectOption('history');
            assert.equal(await page.locator('[data-catalog-row]:visible').count(), 1);
            await page.locator('[data-catalog-filter]').selectOption('all');
            if (mode === 'ready') {
                await page.locator('[data-catalog-principal]').selectOption('exam-1');
                assert.equal(await page.locator('[value=exam-1][type=checkbox]').isDisabled(), true);
                await page.locator('[value=exam-2][type=checkbox]').check();
                assert.match(await page.locator('[data-catalog-selection]').innerText(), /1 variante/);
                await page.locator('[data-catalog-action]').selectOption('renombrar');
                assert.equal(await page.locator('[data-catalog-rename]').isVisible(), true);
                assert.equal(await page.locator('[value=exam-2][type=checkbox]').isChecked(), false);
                const confirm = page.locator('[data-catalog-confirm]');
                assert.equal(await confirm.locator('button').isDisabled(), true);
                await confirm.locator('[name=confirmar]').check();
                assert.equal(await confirm.locator('button').isEnabled(), true);
            } else {
                assert.match(await page.locator('[role=alert]').innerText(), /Falta instalar/);
                assert.equal(await page.locator('[data-catalog-form] button[type=submit]').isDisabled(), true);
            }
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                fs.mkdirSync('storage/app/testing', { recursive: true });
                await page.screenshot({ path: `storage/app/testing/mining-catalog-${mode}-${width}.png`, fullPage: true });
                const overflow = await page.locator('.mco-page').evaluate(el => {
                    const bounds = el.getBoundingClientRect();
                    return { bad: el.scrollWidth > el.clientWidth + 1, width: el.clientWidth, scroll: el.scrollWidth,
                        items: [...el.querySelectorAll('*')].filter(child => child.getBoundingClientRect().right > bounds.right + 1 && !child.closest('.mco-table-wrap'))
                            .slice(0, 12).map(child => ({ tag: child.tagName, class: child.className, width: child.getBoundingClientRect().width })) };
                });
                assert.equal(overflow.bad, false, `${mode}: overflow ${width}: ${JSON.stringify(overflow)}`);
            }
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: catalog search/filter, variant selection, rename, explicit confirmation, missing schema, HTML escaping, desktop/mobile.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
