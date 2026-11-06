const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const fs = require('node:fs');

const origin = 'http://synthetic.local';
const path = '/ficha-colaborador/actualizar';
const token = 'ab'.repeat(32);
const sessionHandle = 'cd'.repeat(32);
const html = execFileSync('php', ['tests/Browser/render-personal-update.php'], { encoding: 'utf8' });

async function verifyUpdate(browser, width, height) {
    const page = await browser.newPage({ viewport: { width, height } });
    const errors = [];
    const requests = [];
    let revision = 1;
    let fields = {};
    let submitted = false;
    let lostResponse = true;
    let valid = true;
    const definitions = { correo: { label: 'Correo electronico', type: 'email', required: true } };
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route(origin + '/**', async (route) => {
        const request = route.request();
        requests.push(request.url());
        const pathname = new URL(request.url()).pathname;
        if (request.method() === 'GET' && pathname === path) {
            return route.fulfill({ contentType: 'text/html', body: html });
        }
        const body = request.postDataJSON();
        if (pathname.endsWith('/canje')) {
            if (body.token) assert.equal(body.token, token);
            else assert.equal(body.session_handle, sessionHandle);
            return route.fulfill({ status: valid ? 200 : 403, json: valid
                ? (submitted ? { submitted: true, message: 'Actualización recibida · Completa para revisión' } : { session_handle: sessionHandle, revision, fields, definitions })
                : { message: 'Este enlace ya no está disponible.' } });
        }
        if (pathname.endsWith('/borrador')) {
            assert.equal(body.session_handle, sessionHandle);
            assert.equal(body.revision, revision);
            fields = { ...fields, ...body.fields };
            revision += 1;
            return route.fulfill({ json: { session_handle: sessionHandle, revision, fields, definitions } });
        }
        if (pathname.endsWith('/enviar')) {
            assert.equal(body.session_handle, sessionHandle);
            assert.equal(body.revision, revision);
            assert.match(body.submission_uuid, /^[0-9a-f-]{36}$/);
            submitted = true;
            if (lostResponse) {
                lostResponse = false;
                return route.abort();
            }
            return route.fulfill({ json: { message: 'Actualización recibida · Completa para revisión' } });
        }
        return route.fulfill({ status: 404 });
    });
    try {
        await page.goto(origin + path + '#' + token);
        const email = page.getByLabel('Correo electronico');
        await email.waitFor();
        assert.equal(page.url(), origin + path);
        assert.equal(await email.inputValue(), '');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await email.fill('propuesta@test.local');
        await page.getByRole('button', { name: 'Guardar borrador' }).click();
        await page.getByText('Borrador guardado.').waitFor();
        await page.reload();
        await email.waitFor();
        assert.equal(await email.inputValue(), 'propuesta@test.local');
        await page.getByRole('button', { name: 'Enviar actualización' }).click();
        await page.waitForFunction(() => /fetch|network/i.test(document.getElementById('status').textContent));
        await page.getByRole('button', { name: 'Enviar actualización' }).click();
        await page.getByText('Actualización recibida · Completa para revisión').waitFor();
        assert.equal(await email.count(), 0);
        await page.reload();
        await page.getByText('Actualización recibida · Completa para revisión').waitFor();
        assert.equal(await email.count(), 0);

        valid = false;
        await page.goto(origin + path + '#' + token);
        await page.getByText('Este enlace ya no está disponible.').waitFor();
        assert.equal(await email.count(), 0);
        assert.equal(requests.some((url) => url.includes(token)), false);
        assert.deepEqual(errors, []);
        console.log(`PASS ${width}×${height}: fragmento, borrador, reintento, comprobante y enlace inválido.`);
    } finally {
        await page.close();
    }
}

async function verifyGeneralUpdate(browser, width, height) {
    const page = await browser.newPage({ viewport: { width, height } });
    const errors = [];
    const definitions = {
        nombres: { section: 'Datos personales', label: 'Nombres', type: 'text', required: true },
        estado_civil: { section: 'Datos personales', label: 'Estado civil', type: 'select', options: { Soltero: 'Soltero', Otro: 'Otro' } },
        estado_civil_otro: { section: 'Datos personales', label: 'Otro estado civil', type: 'text' },
        fecha_nacimiento: { section: 'Datos de nacimiento', label: 'Fecha de nacimiento', type: 'date', required: true },
        grado_instruccion: { section: 'Datos academicos', label: 'Grado de instruccion', type: 'select', options: { Bachiller: 'Bachiller' } },
        institucion: { section: 'Datos academicos', label: 'Institucion', type: 'text' },
        talla_polo: { section: 'Tallas / EPP', label: 'Camisa / chaleco', type: 'select', options: { L: 'L', XL: 'XL' } },
    };
    let revision = 1;
    let fields = {};
    let families = [];
    let submitted = false;
    page.on('pageerror', (error) => errors.push(error.message));
    await page.addInitScript(() => { Object.defineProperty(crypto, 'randomUUID', { value: undefined }); });
    await page.route(origin + '/**', async (route) => {
        const request = route.request();
        const pathname = new URL(request.url()).pathname;
        if (request.method() === 'GET') return route.fulfill({ contentType: 'text/html', body: html });
        const body = request.postDataJSON();
        assert.equal(request.url().includes(token), false);
        if (pathname.endsWith('/borrador')) {
            assert.equal(body.session_handle, sessionHandle);
            assert.equal(body.revision, revision);
            fields = { ...fields, ...body.fields };
            if (Object.hasOwn(body, 'familiares')) families = body.familiares;
            revision += 1;
        }
        if (pathname.endsWith('/enviar')) {
            assert.equal(body.revision, revision);
            assert.equal(families.length, 1);
            assert.match(families[0].key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
            submitted = true;
        }
        return route.fulfill({ json: submitted
            ? { submitted: true, message: 'Actualización recibida · Completa para revisión' }
            : { session_handle: sessionHandle, revision, fields, definitions, families_enabled: true, familiares: families } });
    });
    try {
        await page.goto(origin + path + '#' + token);
        await page.getByLabel('Nombres').fill('NOMBRE DE PRUEBA');
        await page.getByLabel('Estado civil', { exact: true }).selectOption('Otro');
        await page.getByLabel('Otro estado civil').fill('Situación declarada');
        await page.locator('#fecha_nacimiento').fill('1994-01-02');
        assert.equal(await page.locator('#fecha_nacimiento').getAttribute('type'), 'date');
        await page.getByLabel('Grado de instruccion').selectOption('Bachiller');
        await page.getByLabel('Camisa / chaleco').selectOption('XL');
        await page.getByRole('button', { name: 'Agregar familiar' }).click();
        await page.locator('[data-family-key]').waitFor({ timeout: 3000 });
        await page.getByLabel('Apellidos y nombres', { exact: true }).fill('FAMILIAR PROPUESTO');
        await page.getByLabel('Parentesco', { exact: true }).fill('Contacto');
        await page.getByRole('button', { name: 'Guardar borrador' }).click();
        await page.getByText('Borrador guardado.').waitFor();
        await page.reload();
        await page.getByLabel('Apellidos y nombres', { exact: true }).waitFor();
        assert.equal(await page.getByLabel('Apellidos y nombres', { exact: true }).inputValue(), 'FAMILIAR PROPUESTO');
        assert.equal(await page.locator('#nombres').inputValue(), 'NOMBRE DE PRUEBA');
        await page.getByLabel('Institucion').fill('INSTITUCIÓN DE PRUEBA');
        await page.getByRole('button', { name: 'Guardar borrador' }).click();
        await page.getByText('Borrador guardado.').waitFor();
        assert.equal(families.length, 1);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        fs.mkdirSync('.codex-tmp', { recursive: true });
        await page.screenshot({ path: `.codex-tmp/personal-general-update-${width}.png`, fullPage: true });
        await page.getByRole('button', { name: 'Enviar actualización' }).click();
        await page.getByText('Actualización recibida · Completa para revisión').waitFor();
        assert.equal(await page.locator('[data-family-key]').count(), 0);
        assert.deepEqual(errors, []);
        console.log(`PASS ${width}×${height}: datos personales, fecha, estudios, tallas y familiar con UUID alternativo.`);
    } finally {
        await page.close();
    }
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        await verifyUpdate(browser, 1440, 1000);
        await verifyUpdate(browser, 390, 844);
        await verifyGeneralUpdate(browser, 1440, 1000);
        await verifyGeneralUpdate(browser, 390, 844);
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
