const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const render = scenario => execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', ['tests/Browser/render-mining-emo-scheduling.php', scenario || ''], { encoding: 'utf8' });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let fixtureHtml = '';
        await page.route('http://emo-fixture.test/**', route => route.fulfill({ contentType: 'text/html', body: fixtureHtml }));
        const load = async scenario => {
            fixtureHtml = render(scenario);
            await page.goto('http://emo-fixture.test/personal/habilitacion-minera?vista=scheduled&scheduled_worker=Anterior&scheduled_limit=25');
            const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
            await page.addStyleTag({ path: `public/build/${manifest['resources/css/app.css'].file}` });
            await page.addScriptTag({ path: 'resources/js/modules/mining-scheduled-control.js' });
        };
        await load();
        for (const [state, expectedActions] of [
            ['PROGRAMADA', ['complete', 'cancel']],
            ['ANULADA', ['reprogram']],
            ['COMPLETADA', []],
        ]) {
            const actions = await page.evaluate(state => {
                const host = document.createElement('div');
                host.innerHTML = window.renderMiningEmoProgrammed({ categoria_operativa: 'EMO' }, {
                    id: 'attempt-fixture', emo_cita: {
                        id: 'worker-appointment', revision: 9, estado: state,
                        clinica_id: '00000000-0000-4000-8000-000000000002',
                        clinica_nombre: 'Clinica ficticia', fecha_programacion: '2026-10-08',
                    },
                });
                return [...host.querySelectorAll('[data-emo-action]')].map(button => ({ action: button.dataset.emoAction, revision: button.dataset.emoRevision }));
            }, state);
            assert.deepEqual(actions, expectedActions.map(action => ({ action, revision: '9' })), `Worker appointment actions ${state}`);
        }
        const visibleAttemptIds = await page.evaluate(() => window.miningProgrammedAttempts({ categoria_operativa: 'EMO', intentos: [
            { id: 'active', fecha_programacion: '08/10/2026', resultado: 'PENDIENTE', emo_cita: { estado: 'PROGRAMADA' } },
            { id: 'cancelled', fecha_programacion: '08/10/2026', resultado: 'ANULADO', emo_cita: { estado: 'ANULADA' } },
            { id: 'completed', fecha_programacion: '08/10/2026', resultado: 'APROBADO', emo_cita: { estado: 'COMPLETADA' } },
        ] }).map(attempt => attempt.id));
        assert.deepEqual(visibleAttemptIds, ['active', 'cancelled'], 'Cancelled appointment remains accessible to reprogram');
        const legacyNoAplica = await page.evaluate(() => {
            return window.miningExamCanSchedule?.({
                id: 'legacy-no-aplica', nombre: 'Curso historico', categoria_operativa: 'CURSO',
                estado: 'NO_APLICA', attempt_count: 0, max_intentos: 2,
                intentos: [{ id: 'legacy-pending', resultado: 'PENDIENTE', fecha_programacion: '08/10/2026' }],
            }, true) ?? false;
        });
        assert.equal(legacyNoAplica, true, 'NO_APLICA permits scheduling even with inherited pending attempt');
        const activeRow = page.locator('[data-control-row="00000000-0000-4000-8000-000000000001"]');
        assert.deepEqual(await activeRow.locator('[data-emo-action]').evaluateAll(buttons => buttons.map(button => button.dataset.emoAction)), ['complete', 'cancel']);
        assert.match(await activeRow.innerText(), /Anule la cita para reprogramarla/);
        const cancelledRow = page.locator('[data-control-row="cancelled-emo-fixture"]');
        assert.deepEqual(await cancelledRow.locator('[data-emo-action]').evaluateAll(buttons => buttons.map(button => button.dataset.emoAction)), ['reprogram']);
        assert.equal(await page.locator('[data-control-row="completed-emo-fixture"] [data-emo-action]').count(), 0);
        for (const [outcome, cssClass, label, detail] of [
            ['APTO', 'ok', 'Apto', ''],
            ['NO_APTO', 'danger', 'No apto', ''],
            ['APTO_CON_RESTRICCION', 'warn', 'Apto con restriccion', 'Restriccion: Evitar alturas'],
            ['APTO_CON_OBSERVACION', 'orange', 'Apto con observacion', 'Observacion: Control anual'],
        ]) {
            const badge = page.locator(`[data-emo-outcome="${outcome}"]`);
            assert.equal(await badge.innerText(), label, `${outcome} keeps an explicit non-color label`);
            assert.equal(await badge.evaluate((element, expected) => element.classList.contains(expected), cssClass), true, `${outcome} color`);
            assert.equal(await badge.getAttribute('title') || '', detail, `${outcome} native tooltip`);
            assert.equal(await badge.getAttribute('data-emo-tooltip') || '', detail, `${outcome} visible hover/focus tooltip`);
            assert.equal(await badge.getAttribute('aria-label'), detail ? `${label}. ${detail}` : label, `${outcome} accessible label`);
            assert.equal(await badge.getAttribute('tabindex') || '', detail ? '0' : '', `${outcome} focusable detail`);
            const resultCellText = await badge.locator('xpath=..').innerText();
            assert.match(resultCellText, new RegExp(label), `${outcome} label remains visible`);
            if (detail) assert.equal(resultCellText.includes(detail.split(': ')[1]), false, `${outcome} detail is not inline`);
            if (detail) {
                await badge.hover();
                await page.waitForTimeout(160);
                assert.equal(await badge.evaluate(element => getComputedStyle(element, '::after').opacity), '1', `${outcome} detail appears on hover`);
                await badge.focus();
                await page.waitForTimeout(160);
                assert.equal(await badge.evaluate(element => getComputedStyle(element, '::after').opacity), '1', `${outcome} detail appears on keyboard focus`);
            }
        }
        const workerOutcome = await page.evaluate(() => {
            const host = document.createElement('div');
            host.innerHTML = window.renderMiningEmoOutcomeBadge('APTO_CON_RESTRICCION', 'Evitar alturas');
            const badge = host.firstElementChild;
            return {
                text: badge.textContent,
                className: badge.className,
                title: badge.title,
                ariaLabel: badge.getAttribute('aria-label'),
                tabIndex: badge.getAttribute('tabindex'),
            };
        });
        assert.deepEqual(workerOutcome, {
            text: 'Apto con restriccion',
            className: 'mine-state-chip warn mining-emo-outcome',
            title: 'Restriccion: Evitar alturas',
            ariaLabel: 'Apto con restriccion. Restriccion: Evitar alturas',
            tabIndex: '0',
        }, 'Worker exam history uses the same non-inline accessible outcome badge');
        await page.evaluate(() => history.replaceState({}, '', '?vista=scheduled&scheduled_worker=Filtro%20actual&scheduled_limit=50&scheduled_page=2'));
        await cancelledRow.locator('[data-emo-action=reprogram]').click();
        assert.equal(await page.locator('#modal-emo-operation [name=revision]').inputValue(), '8');
        const commandUrl = new URL(await page.locator('#modal-emo-operation form').getAttribute('action'));
        assert.equal(commandUrl.searchParams.get('scheduled_worker'), 'Filtro actual');
        assert.equal(commandUrl.searchParams.get('scheduled_limit'), '50');
        assert.equal(commandUrl.searchParams.get('scheduled_page'), '2');
        assert.match(commandUrl.pathname, /cancelled-emo-fixture\/reprogramar$/);
        await page.locator('#modal-emo-operation [data-emo-close]').click();
        fs.mkdirSync('storage/app/testing', { recursive: true });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `Viewport overflow ${width}`);
            assert.equal(await page.locator('.mining-control-row-actions .btn').evaluateAll(buttons => buttons.every(button => {
                const bounds = button.getBoundingClientRect();
                const card = button.closest('.mining-control').getBoundingClientRect();
                return bounds.left >= card.left && bounds.right <= Math.min(card.right, innerWidth) + 1;
            })), true, `Every row action fits inside the visible control ${width}`);
            assert.equal(await page.locator('[name=scheduled_from]').isVisible(), true);
            assert.equal(await page.locator('[data-scheduled-export]').getAttribute('data-no-ajax'), 'true');
            assert.match(await page.locator('[data-testid=mine-scheduled-exams]').innerText(), /Mina ficticia A.*Mina ficticia B/s);
            await page.screenshot({ path: `storage/app/testing/mining-emo-control-${width}.png`, fullPage: true });
            await page.locator('[data-emo-action=complete]').first().click();
            const dialog = page.locator('#modal-emo-operation');
            assert.equal(await dialog.locator('[name=revision]').inputValue(), '7');
            assert.equal(await dialog.locator('[name=resultado_emo] option').count(), 4);
            await dialog.locator('[name=resultado_emo]').selectOption('APTO_CON_RESTRICCION');
            assert.equal(await dialog.locator('[name=detalle_resultado_emo]').evaluate(el => el.required), true);
            assert.equal(await dialog.evaluate(el => el.scrollWidth > el.clientWidth + 1), false, `Dialog overflow ${width}`);
            await page.screenshot({ path: `storage/app/testing/mining-emo-${width}.png` });
            await dialog.locator('[data-emo-close]').click();
            await page.locator('[data-emo-action=regularize]').first().click();
            assert.equal(await dialog.locator('[name=clinica_id]').evaluate(el => el.required), true);
            assert.match(await dialog.innerText(), /historico/i);
            await dialog.locator('[data-emo-close]').click();
        }
        const course = await page.evaluate(() => window.renderMiningEmoSchedule({ id: 'course', categoria_operativa: 'CURSO' }));
        assert.equal(course, null);
        await page.evaluate(() => {
            window.openMiningEmoOperation('schedule', { id: 'worker-exam', date: '2026-10-09', clinic: '00000000-0000-4000-8000-000000000002' });
        });
        assert.equal(await page.locator('#modal-emo-operation [name=clinica_id]').evaluate(el => el.required), true);
        await page.locator('#modal-emo-operation [data-emo-close]').click();
        await load('empty');
        assert.match(await page.locator('[data-testid=mine-scheduled-exams]').innerText(), /Sin registros/);
        assert.deepEqual(errors, []);
        console.log('PASS: desktop/mobile, active/cancelled/completed actions, current revision/query, cancelled worker access, aggregated mines, outcomes/detail, clinic, legacy, empty state, export and no overflow.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
