const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
    const css = fs.readFileSync('public/proserge-app.css', 'utf8') + '\n' + fs.readFileSync('public/build/' + manifest['resources/css/app.css'].file, 'utf8');
    const cache = new Map();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 1000 } });
            const errors = [];
            let documentLoads = 0;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => {
                if (route.request().resourceType() === 'document') documentLoads++;
                const query = new URL(route.request().url()).search.slice(1);
                if (!cache.has(query)) {
                    const rendered = execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-logistics-item-search.php', query], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
                    cache.set(query, rendered.replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '').replace('</head>', '<style>' + css + '</style></head>'));
                }
                return route.fulfill({ contentType: 'text/html; charset=utf-8', body: cache.get(query) });
            });
            await page.goto('http://127.0.0.1:8000/logistica?tab=identificacion');
            const panel = page.locator('[data-logistics-tab-panel="identificacion"]');
            const search = page.getByRole('searchbox', { name: 'Buscar ítem', exact: true });
            const rows = panel.locator('.epp-table tbody > tr:visible');
            const waitForRows = count => page.waitForFunction(expected => Array.from(document.querySelectorAll('[data-logistics-tab-panel="identificacion"] .epp-table tbody > tr')).filter(row => row.getClientRects().length > 0).length === expected, count, { timeout: 2000 });
            assert.equal(await rows.count(), 3);
            await search.fill('anticorte GUANT');
            await waitForRows(1);
            assert.equal(documentLoads, 1, 'Typing must not reload the page');
            assert.equal(await search.evaluate(el => el === document.activeElement), true, 'Typing must keep focus');
            assert.equal(await rows.first().innerText().then(text => text.includes('GUANTES ANTICÓRTE')), true);
            assert.equal(await search.inputValue(), 'anticorte GUANT');
            assert.equal(await panel.getByText('1 ítem encontrado', { exact: true }).count(), 1);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            const inputBox = await search.boundingBox();
            const buttonBox = await panel.getByRole('button', { name: 'Buscar', exact: true }).boundingBox();
            assert.equal(inputBox.y + inputBox.height <= buttonBox.y + 1 || inputBox.x + inputBox.width <= buttonBox.x + 1, true, 'Search field and button must not overlap');
            fs.mkdirSync('outputs/logistics-item-search', { recursive: true });
            await page.screenshot({ path: `outputs/logistics-item-search/buscador-${width}.png`, fullPage: false });

            await search.fill('EPP-BOT-02');
            await waitForRows(1);
            assert.equal((await rows.innerText()).includes('BOTAS DE SEGURIDAD'), true);
            await rows.getByRole('button', { name: 'Editar', exact: true }).click();
            await page.getByRole('dialog', { name: /Editar/ }).waitFor({ state: 'visible' });
            await page.getByRole('dialog', { name: /Editar/ }).locator('[data-epp-close-modal]').first().click();
            await search.fill('');
            await waitForRows(3);
            await search.pressSequentially('ca');
            await waitForRows(1);
            assert.equal((await rows.innerText()).includes('CASCO DE SEGURIDAD'), true);
            await search.fill('EPP-BOT-02');
            await page.reload();
            await waitForRows(1);
            await search.fill('casco');
            await waitForRows(1);
            assert.equal((await rows.innerText()).includes('CASCO DE SEGURIDAD'), true, 'A filtered initial page must support a different query');
            await search.fill('EPP-BOT-02');
            await Promise.all([page.waitForURL(url => url.searchParams.get('ident_categoria') === 'HERRAMIENTA'), panel.getByRole('tab', { name: /Herramientas/ }).click()]);
            assert.equal(await search.inputValue(), 'EPP-BOT-02');
            assert.equal(await rows.count(), 0);
            assert.equal(await panel.getByText('Revisa otra categoría o limpia la búsqueda.', { exact: false }).isVisible(), true);
            await search.fill('impacto DADO');
            await waitForRows(1);
            assert.equal((await rows.innerText()).includes('DADO DE IMPÁCTO'), true);
            await Promise.all([page.waitForURL(url => !url.searchParams.has('ident_q')), panel.getByRole('link', { name: 'Limpiar', exact: true }).click()]);
            assert.equal(new URL(page.url()).searchParams.get('ident_categoria'), 'HERRAMIENTA');
            assert.equal(await rows.count(), 2);
            await Promise.all([page.waitForURL(url => url.searchParams.get('ident_categoria') === 'CONSUMIBLE'), panel.getByRole('tab', { name: /Consumibles/ }).click()]);
            await search.fill('ZINC perno');
            await waitForRows(1);
            assert.equal((await rows.innerText()).includes('PERNO ZINCADO'), true);
            const loadsBeforeSubmit = documentLoads;
            await search.press('Enter');
            await panel.getByRole('button', { name: 'Buscar', exact: true }).click();
            assert.equal(documentLoads, loadsBeforeSubmit, 'Enter and Buscar must also filter without reload');
            await search.fill('NO EXISTE');
            await waitForRows(0);
            await panel.locator('[data-ident-empty]').waitFor({ state: 'visible' });
            await panel.getByRole('link', { name: 'Limpiar', exact: true }).click();
            await waitForRows(1);
            assert.equal(await search.inputValue(), '');
            assert.equal(await search.evaluate(el => el === document.activeElement), true);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS Live item search: typing without reload, focus, widening after reload, edit modals, categories, empty results and clear on desktop/mobile.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
