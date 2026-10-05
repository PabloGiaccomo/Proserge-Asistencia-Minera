const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
  const fixtures = Object.fromEntries(['attendance', 'absence'].map(type => [
    type,
    execFileSync('C:/xampp/php/php.exe', ['tests/Browser/render-attendance-dashboards.php', type], { encoding: 'utf8' })
      .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
      .replace(/<link\b[^>]*>/gi, ''),
  ]));
  const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  fs.mkdirSync('storage/app/testing', { recursive: true });

  try {
    for (const type of Object.keys(fixtures)) {
      const page = await browser.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: fixtures[type] }));
      await page.goto(`http://127.0.0.1:8000/${type}`);
      await page.addStyleTag({ path: `public/build/${manifest['resources/css/app.css'].file}` });
      await page.addStyleTag({ content: '*{transition:none!important;animation:none!important}' });

      for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(viewport);
        const root = page.locator('.attendance-ops-page');
        assert.equal(await root.evaluate(element => element.scrollWidth > element.clientWidth + 1), false);
        await page.screenshot({ path: `storage/app/testing/${type}-dashboard-${viewport.width}.png`, fullPage: true });
      }

      assert.deepEqual(errors, []);
      await page.close();
    }
    console.log('PASS attendance and absence dashboards desktop/mobile rendering');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
