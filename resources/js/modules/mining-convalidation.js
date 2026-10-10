const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character]));
const scoreLabel = value => value === null || value === undefined ? 'Sin nota' : `Nota: ${value}`;

window.openMiningConvalidationRules = function (requirementId) {
    const rules = JSON.parse(document.getElementById('miningConvalidationRulesData')?.textContent || '[]');
    const rule = rules.find(item => String(item.id) === String(requirementId));
    if (!rule) return;
    const form = document.getElementById('miningConvalidationRulesForm');
    form.action = rule.url;
    document.getElementById('miningConvalidationTarget').textContent = `Destino: ${rule.examen} — ${rule.mina}`;
    document.getElementById('miningConvalidationAllowed').checked = rule.permitida;
    document.getElementById('miningConvalidationOtherMines').checked = rule.otras_minas;
    document.getElementById('miningConvalidationCatalogWarning').hidden = rule.catalogo_permite;
    document.getElementById('miningEquivalentSearch').value = '';
    form.querySelectorAll('[data-equivalent-choice]').forEach(choice => {
        const input = choice.querySelector('input');
        input.disabled = choice.dataset.examId === rule.examen_id;
        input.checked = !input.disabled && rule.equivalentes.includes(input.value);
        choice.hidden = input.disabled;
    });
    document.getElementById('modal-convalidation-rules').showModal();
};

document.addEventListener('input', event => {
    if (event.target.id !== 'miningEquivalentSearch') return;
    const search = event.target.value.trim().toLocaleLowerCase();
    document.querySelectorAll('[data-equivalent-choice]').forEach(choice => {
        choice.hidden = choice.querySelector('input').disabled || !choice.textContent.toLocaleLowerCase().includes(search);
    });
});

window.loadMiningConvalidationOptions = async function (card, panel, config) {
    if (panel.dataset.loading === 'true' || panel.dataset.loaded === 'true') return;
    panel.dataset.loading = 'true';
    panel.innerHTML = '<p class="mine-muted" role="status">Buscando resultados vigentes...</p>';
    const examId = encodeURIComponent(card.dataset.workerExamCard);
    try {
        const response = await fetch(config.optionsUrl.replace('__EXAM__', examId), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('No se pudieron consultar los resultados. Intenta nuevamente.');
        const payload = await response.json();
        if (!payload.available || !payload.options?.length) {
            panel.innerHTML = `<p class="mine-muted" role="status">${escapeHtml(payload.message || 'No hay resultados aptos para convalidar.')}</p>`;
            panel.dataset.loaded = 'true';
            return;
        }
        const destination = payload.destino;
        const destinationLabel = `${destination.examen} — ${destination.mina}`;
        const action = new URL(config.submitUrl.replace('__EXAM__', examId), window.location.href);
        action.search = window.location.search;
        const options = payload.options.map(origin => `<option value="${escapeHtml(origin.id)}">${escapeHtml([
            origin.examen, origin.mina, scoreLabel(origin.nota), `Realizado: ${origin.fecha_realizacion || '-'}`,
            `Vence: ${origin.fecha_vencimiento || 'Sin vencimiento'}`, origin.equivalente ? 'Equivalencia autorizada' : 'Mismo examen',
        ].join(' · '))}</option>`).join('');
        panel.innerHTML = `<form method="POST" action="${escapeHtml(action.toString())}" class="mine-form" data-loading-message="Convalidando examen...">
            <input type="hidden" name="_token" value="${escapeHtml(config.csrfToken)}">
            <div class="mine-helper-card is-full"><strong>${escapeHtml(destination.trabajador)}</strong><span>Destino: ${escapeHtml(destinationLabel)}</span>
                <span>${escapeHtml(destination.requiere_nota ? `Exige nota mínima: ${destination.nota_minima ?? 0}` : 'No exige nota. Se conserva si el origen tiene una.')}</span></div>
            <label class="is-full">Resultado aprobado de origen<select name="examen_origen_id" required><option value="">Selecciona examen y mina de origen</option>${options}</select></label>
            <p class="mine-helper-card is-full" data-convalidation-summary aria-live="polite">Selecciona el resultado que usarás para este destino.</p>
            <label class="is-full">Observación<input type="text" name="observacion" maxlength="5000"></label>
            <label class="mine-checkline is-full"><input type="checkbox" name="confirmar" value="1" required disabled><span>Confirmo el origen, el destino y la nota indicados.</span></label>
            <button type="submit" class="btn btn-primary btn-xs is-full" disabled>Confirmar convalidación</button>
        </form>`;
        const form = panel.querySelector('form');
        const select = form.querySelector('select');
        const confirmation = form.querySelector('[name="confirmar"]');
        const submit = form.querySelector('[type="submit"]');
        select.addEventListener('change', () => {
            const origin = payload.options.find(item => item.id === select.value);
            confirmation.checked = false;
            confirmation.disabled = !origin;
            submit.disabled = true;
            form.querySelector('[data-convalidation-summary]').textContent = origin
                ? `Origen: ${origin.examen} — ${origin.mina}. ${scoreLabel(origin.nota)}. Vence: ${origin.fecha_vencimiento || 'Sin vencimiento'}. → Destino: ${destinationLabel}.`
                : 'Selecciona el resultado que usarás para este destino.';
        });
        confirmation.addEventListener('change', () => { submit.disabled = !confirmation.checked; });
        panel.dataset.loaded = 'true';
    } catch (error) {
        panel.innerHTML = `<p class="mine-config-inline-error" role="alert">${escapeHtml(error.message)}</p>`;
    } finally {
        panel.dataset.loading = 'false';
    }
};
