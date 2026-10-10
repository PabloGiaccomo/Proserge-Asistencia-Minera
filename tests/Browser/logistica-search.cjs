const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const source = fs.readFileSync('resources/views/logistica/index.blade.php', 'utf8');
    const filter = source.match(/function filterParadas\(\) \{[\s\S]*?\n        \}\n\n        searchInput/)[0].replace(/\n\n        searchInput$/, '');
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        await page.route('**/*', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<meta charset="utf-8"><input id="search"><select id="mine"><option value="">Todas</option><option value="a">A</option><option value="b">B</option></select><div id="list"><article class="lgt-parada-card" data-parada-search="CÉRRO VERDE Operación 09/10/2026" data-parada-mina="a">Parada A</article><article class="lgt-parada-card" data-parada-search="OTRA MINA" data-parada-mina="b">Parada B</article></div>' }));
        await page.goto('http://synthetic.local/logistica');
        await page.addScriptTag({ content: `var searchInput = document.querySelector('#search'), minaFilter = document.querySelector('#mine'), paradaList = document.querySelector('#list'); ${filter}; searchInput.addEventListener('input', filterParadas); minaFilter.addEventListener('change', filterParadas);` });
        for (const term of ['cerro verde', 'VERDE CERRO', '  verd   cerr  ', 'operacion cerro']) {
            await page.locator('#search').fill(term);
            assert.equal(await page.locator('.lgt-parada-card:visible').count(), 1, term);
        }
        await page.locator('#mine').selectOption('b');
        assert.equal(await page.locator('.lgt-parada-card:visible').count(), 0);
        await page.locator('#search').fill('');
        assert.equal(await page.locator('.lgt-parada-card:visible').count(), 1);
        console.log('PASS Logistica stop search: reordered terms, partials, accents, spaces and mine filter retained.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
