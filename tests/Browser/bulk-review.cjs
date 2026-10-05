const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
    const html = execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['tests/Browser/render-bulk-review.php'], { encoding: 'utf8' });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        await page.setContent(html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, ''));
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        const css = manifest['resources/css/app.css'];
        if (css) await page.addStyleTag({ path: `public/build/${css.file}` });
        await page.addStyleTag({ path: 'public/css/bulk-review.css' });
        await page.addStyleTag({ content: '* { transition: none !important; animation: none !important; }' });
        await page.addScriptTag({ path: 'public/js/bulk-review.js' });
        assert.equal(await page.locator('.bulk-row:visible').count(), 20);
        await page.locator('#bulk-next').click();
        assert.match(await page.locator('#bulk-count').innerText(), /21-40 de 45/);
        await page.locator('[data-bulk-category="examen"]').click();
        assert.equal(await page.locator('.bulk-row:visible').count(), 9);
        await page.locator('#bulk-search').fill('00000001');
        assert.equal(await page.locator('.bulk-row:visible').count(), 1);
        await page.locator('.bulk-row:visible summary').click();
        assert.equal(await page.locator('.bulk-row[open]').count(), 1);
        await page.locator('#bulk-search').fill('sin coincidencia imposible');
        assert.equal(await page.locator('.bulk-row:visible').count(), 0);
        assert(await page.locator('#bulk-filter').isVisible());
        assert(await page.locator('#bulk-filter-empty').isVisible());
        await page.locator('#bulk-reset').click();
        await page.locator('[data-bulk-category="changes"]').click();
        assert.equal(await page.locator('.bulk-row:visible').count(), 1);
        await page.locator('.bulk-row:visible summary').click();
        assert.match(await page.locator('.bulk-row:visible').innerText(), /3\/2/);
        assert.match(await page.locator('.bulk-row:visible').innerText(), /Excedido/);
        await page.locator('[data-bulk-category="unchanged"]').click();
        assert.equal(await page.locator('.bulk-row:visible').count(), 1);
        assert.equal(await page.locator('.bulk-confirm button').isDisabled(), true);
        await page.locator('#bulk-reset').click();
        fs.mkdirSync('storage/app/testing', { recursive: true });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.locator('.bulk-row:visible').first().evaluate(el => { el.open = true; });
            const overflow = await page.locator('.bulk-preview').evaluate(el => el.scrollWidth > el.clientWidth + 1);
            if (overflow) console.log(await page.locator('.bulk-preview').evaluate(el => Array.from(el.querySelectorAll('*')).filter(child => child.getBoundingClientRect().right > el.getBoundingClientRect().right + 1).map(child => ({ tag: child.tagName, class: child.className, width: child.getBoundingClientRect().width, text: child.textContent.slice(0, 60) }))));
            assert.equal(overflow, false, `Overflow at ${width}`);
            await page.screenshot({ path: `storage/app/testing/bulk-review-${width}.png`, fullPage: true });
            await page.evaluate(() => scrollTo(0,0));
            await page.screenshot({ path: `storage/app/testing/bulk-review-header-${width}.png` });
        }
        assert.deepEqual(errors, []);
        console.log('PASS: category, live search, pagination, details, empty state, desktop/mobile overflow, no JS errors.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
