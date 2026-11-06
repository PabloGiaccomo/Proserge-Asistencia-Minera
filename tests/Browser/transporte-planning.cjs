const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const home = 'http://transporte.test';
const service = '4d29f854-95aa-4296-8d60-c9aad665bc02';
const context = '/transporte/planificacion?fecha=2026-11-04&turno=A&lista%5Bq%5D=chancado';
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'proserge-transporte-'));
const renderCache = new Map();

function render(search) {
    if (!renderCache.has(search)) {
        renderCache.set(search, execFileSync('php', ['tests/Browser/render-transporte-planning.php', search.slice(1)], {
            encoding: 'utf8',
            maxBuffer: 4 * 1024 * 1024,
            env: { ...process.env, APP_ENV: 'testing', APP_CONFIG_CACHE: path.join(output, 'absent-config.php') },
        }).replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, ''));
    }
    return renderCache.get(search);
}

async function exercise(browser, width, height) {
    const page = await browser.newPage({ viewport: { width, height }, reducedMotion: 'reduce' });
    const errors = [];
    const submissions = [];
    let reply = route => route.fulfill({ status: 422, json: { errors: { placa: ['La placa ya está asignada.'] } } });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin !== home) return route.abort();
        if (request.method() === 'POST' && url.pathname.startsWith('/transporte/servicios')) {
            submissions.push({ pathname: url.pathname, body: request.postData() });
            return reply(route);
        }
        if (request.resourceType() === 'document') {
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: render(url.search) });
        }
        const candidate = path.resolve('public', '.' + url.pathname);
        if (candidate.startsWith(path.resolve('public') + path.sep) && fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
            return route.fulfill({ path: candidate });
        }
        return route.abort();
    });

    await page.goto(home + context);
    await page.getByRole('link', { name: 'Editar servicio', exact: true }).click();
    await page.waitForURL(url => url.searchParams.get('editar') === service);
    const edit = page.locator('#transporte-editar form');
    const add = page.locator('#transporte-nuevo form');
    const alert = edit.locator('[data-transport-error]');
    const driver = edit.getByLabel('Conductor');
    await driver.focus();
    await page.keyboard.press('Home');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Tab');
    const driverId = await driver.inputValue();
    assert.ok(driverId);
    await edit.getByLabel('Placa').fill('SIN-GUARDAR');
    await edit.getByLabel('Capacidad').fill('20');
    await edit.getByLabel('Alcance Man Power 1').selectOption({ index: 1 });

    const viewportFits = () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
    assert.equal(await viewportFits(), true, `Horizontal overflow at ${width}px`);
    for (const [response, message] of [
        [route => route.fulfill({ status: 422, json: { errors: { placa: ['La placa ya está asignada.'] } } }), /placa ya está/],
        [route => route.fulfill({ status: 401 }), /sesión venció/],
        [route => route.fulfill({ status: 403 }), /no tiene permiso/],
        [route => route.fulfill({ status: 419 }), /sesión venció/],
        [route => route.fulfill({ status: 500, json: { ok: false } }), /No se pudo comprobar/],
        [route => route.fulfill({ status: 200, contentType: 'text/html', body: '<form>Iniciar sesión</form>' }), /No se pudo comprobar/],
        [route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":' }), /No se pudo comprobar/],
        [route => route.abort('internetdisconnected'), /No se pudo comprobar/],
    ]) {
        reply = response;
        const before = submissions.length;
        await edit.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
        await alert.getByText(message).waitFor({ state: 'visible' });
        assert.equal(submissions.length, before + 1);
        assert.equal(await alert.evaluate(element => document.activeElement === element), true);
        assert.equal(await edit.getByLabel('Placa').inputValue(), 'SIN-GUARDAR');
        assert.equal(await edit.getByLabel('Capacidad').inputValue(), '20');
        assert.equal(await driver.inputValue(), driverId);
        assert.match(submissions.at(-1).body, /name="_method"\r?\n\r?\nPATCH/);
        assert.match(submissions.at(-1).body, /name="contexto\[fecha\]"\r?\n\r?\n2026-11-04/);
        assert.match(submissions.at(-1).body, /name="alcances\[0\]\[grupo_trabajo_id\]"\r?\n\r?\n12d64158/);
    }

    let release;
    const gate = new Promise(resolve => { release = resolve; });
    reply = async route => {
        if (new URL(route.request().url()).pathname.endsWith(service)) {
            await gate;
            return route.fulfill({ json: { ok: true, redirect: context } });
        }
        return route.fulfill({ status: 422, json: { message: 'Completa el otro formulario.' } });
    };
    const before = submissions.length;
    await edit.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
    await edit.dispatchEvent('submit');
    await add.getByLabel('Placa').fill('OTRO-BORRADOR');
    await add.getByRole('button', { name: 'Crear servicio', exact: true }).click();
    await add.getByText('Completa el otro formulario.').waitFor({ state: 'visible' });
    release();
    await edit.getByText(/Guardado\. Puedes consultar/).waitFor({ state: 'visible' });
    assert.equal(submissions.length, before + 2);
    assert.equal(await add.getByLabel('Placa').inputValue(), 'OTRO-BORRADOR');
    assert.ok(new URL(page.url()).searchParams.has('editar'));
    assert.equal(await viewportFits(), true);
    await page.screenshot({ path: path.join(output, `transporte-${width}.png`), fullPage: true });

    await page.goto(home + context);
    reply = route => route.fulfill({ json: { ok: true, redirect: context + '&stage=confirmed' } });
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await page.waitForURL(url => url.searchParams.get('stage') === 'confirmed');
    await page.getByText(/CONFIRMADO · 2026-11-04/).waitFor({ state: 'visible' });
    if (width === 1440) {
        await page.setViewportSize({ width: width / 2, height: height / 2 });
        assert.equal(await viewportFits(), true, 'The layout must reflow at the CSS viewport of 200% desktop zoom.');
    }
    assert.deepEqual(errors, []);
    await page.close();
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        await exercise(browser, 1440, 1000);
        await exercise(browser, 390, 844);
        console.log(`PASS Transporte at 1440x1000 and 390x844. Screenshots: ${output}`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
