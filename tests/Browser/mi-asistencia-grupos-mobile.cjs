const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const origin = 'http://127.0.0.1:8489';
const day = '09000000-0000-4000-8000-000000000001';
const own = '09000000-0000-4000-8000-000000000003';
const initial = origin + '/mi-asistencia-mobile?fecha=2026-08-19';
const selected = initial + '&turno=&grupo_id=' + day;
const render = (group) => execFileSync(process.env.PHP_BINARY || 'php', ['tests/Browser/render-mi-asistencia-grupos-mobile.php', group], {
    encoding: 'utf8',
    env: { ...process.env, APP_URL: origin },
}).replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>|<link\b[^>]*>/gi, '');

(async () => {
    const documents = { picker: render(''), group: render(day) };
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const viewport of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
            const page = await browser.newPage({ viewport });
            const errors = [];
            let scenario;
            let posts;
            let checks;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', async route => {
                const request = route.request();
                const url = new URL(request.url());
                if (request.method() === 'POST' && url.pathname.endsWith('/marcar')) {
                    posts++;
                    assert.deepEqual(request.postDataJSON(), { grupo_trabajo_detalle_id: own, estado: 'PRESENTE' });
                    if (scenario === 'validation') return route.fulfill({ status: 422, json: { errors: { estado: ['Revisa el horario del grupo.'] } } });
                    if (scenario === 'session') return route.fulfill({ status: 401, json: { ok: false } });
                    if (scenario === 'denied') return route.fulfill({ status: 403, json: { ok: false } });
                    if (scenario === 'closed') return route.fulfill({ status: 409, json: { ok: false, message: 'Asistencia cerrada' } });
                    if (scenario === 'network') return route.abort();
                    if (scenario === 'unknown') return route.fulfill({ status: 500, json: { ok: false } });
                    return route.fulfill({ json: { ok: true, estado: 'PRESENTE', hora_marcado: '06:57:00' } });
                }
                if (url.pathname.endsWith('/estado')) {
                    checks++;
                    assert.equal(url.searchParams.get('propio'), '1');
                    if (scenario === 'denied') return route.fulfill({ status: 403, json: { ok: false } });
                    if (scenario === 'unknown' && checks === 1) return route.abort();
                    if (scenario === 'closed') return route.fulfill({ json: {
                        propio: { id: own, estado: 'AUSENTE', hora: null }, puede_marcar: false, mensaje: 'Asistencia cerrada. Puedes consultar tu registro.',
                    } });
                    return route.fulfill({ json: {
                        propio: { id: own, estado: scenario === 'network' ? 'TARDANZA' : 'PENDIENTE', hora: null }, puede_marcar: true, mensaje: null,
                    } });
                }
                if (url.pathname === '/mi-asistencia-mobile') {
                    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: url.searchParams.has('grupo_id') ? documents.group : documents.picker });
                }
                return route.fulfill({ status: 404 });
            });

            await page.goto(initial);
            assert.equal(await page.locator('#mobMarkBtn').count(), 0);
            await page.getByLabel('Elige un grupo').selectOption(day);
            await page.getByRole('button', { name: 'Abrir grupo' }).click();
            await page.waitForURL(selected);
            assert.equal(await page.getByLabel('Fecha').inputValue(), '2026-08-19');
            assert.equal(await page.getByLabel('Turno', { exact: true }).inputValue(), '');
            const detailLink = new URL(await page.getByRole('link', { name: 'Tomar asistencia del grupo' }).getAttribute('href'));
            assert.equal(detailLink.pathname, '/mi-asistencia/' + day);
            assert.equal(detailLink.searchParams.get('volver_movil'), '1');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);

            const button = page.getByRole('button', { name: 'Marcar mi asistencia' });
            const feedback = page.locator('#mobMarkFeedback');
            for (const [mode, expected] of [
                ['validation', 'Revisa el horario del grupo.'],
                ['session', 'Inicia sesión y recarga esta jornada'],
                ['denied', 'El grupo ya no está disponible'],
                ['closed', 'Asistencia cerrada. Puedes consultar tu registro.'],
                ['network', 'Marca confirmada. Tu registro figura como tardanza.'],
                ['unknown', 'Consulta su estado antes de volver a marcar.'],
                ['double', 'Asistencia registrada.'],
            ]) {
                scenario = mode;
                posts = checks = 0;
                await page.goto(selected);
                if (mode === 'double') {
                    await button.evaluate(element => { element.click(); element.click(); });
                } else {
                    await button.click();
                }
                await feedback.getByText(expected, { exact: false }).waitFor();
                const feedbackBox = await feedback.boundingBox();
                assert.ok(feedbackBox.y >= 0 && feedbackBox.y + feedbackBox.height <= viewport.height);
                assert.equal(posts, 1);
                assert.equal(checks, ['denied', 'closed', 'network', 'unknown'].includes(mode) ? 1 : 0);
                assert.equal(page.url(), selected);
                if (mode === 'validation') assert.ok(await button.isEnabled());
                if (mode === 'session') assert.ok(await page.getByRole('link', { name: 'Iniciar sesión' }).isVisible());
                if (mode === 'unknown') {
                    assert.equal(await button.isEnabled(), false);
                    await page.getByRole('button', { name: 'Consultar estado' }).click();
                    await feedback.getByText('La marca no figura registrada.', { exact: false }).waitFor();
                    assert.ok(await button.isEnabled());
                    assert.equal(checks, 2);
                }
            }
            assert.deepEqual(errors, []);
            assert.equal(await page.evaluate(() => localStorage.length), 0);
            await page.close();
        }
        console.log('PASS Mi Marca selection, layout, session, validation, conflict, connection and duplicate clicks');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
