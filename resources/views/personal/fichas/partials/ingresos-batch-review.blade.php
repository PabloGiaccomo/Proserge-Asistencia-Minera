<dialog class="ingreso-contract-dialog ingreso-batch-dialog" id="ingresoBatchDialog" aria-labelledby="ingresoBatchTitle">
    <form class="ingreso-contract-body" id="ingresoBatchForm">
        <h2 id="ingresoBatchTitle">Preparar revisión de fichas</h2>
        <p class="page-subtitle">Las altas necesitan un periodo para preparar el contrato. Puedes copiar las mismas fechas a todas y ajustar cualquier excepción.</p>
        <div class="ingreso-contract-grid" id="ingresoBatchDates">
            <label>Fecha de inicio común <input class="form-control" type="date" id="ingresoBatchStart"></label>
            <label>Fecha de fin común <input class="form-control" type="date" id="ingresoBatchEnd"></label>
            <button class="btn btn-outline" type="button" id="ingresoBatchFill">Copiar fechas a las altas</button>
        </div>
        <div id="ingresoBatchRows"></div>
        <p id="ingresoBatchStatus" role="alert"></p>
        <div class="ficha-actions-bar">
            <button type="button" class="btn btn-outline" id="ingresoBatchCancel">Volver a la selección</button>
            <button type="submit" class="btn btn-primary" id="ingresoBatchPreview">Ver fichas por confirmar</button>
        </div>
    </form>
</dialog>
<style>
    .ingresos-batch-select { display: block; margin-bottom: .3rem; }
    .ingreso-batch-dialog { max-height: 88vh; max-width: min(720px, 94vw); }
    .ingreso-batch-dialog .ingreso-contract-grid { margin: .5rem 0; }
    .ingreso-batch-dialog [hidden] { display: none; }
    .ingreso-batch-item { padding: .75rem 0; border-bottom: 1px solid var(--border-color, #d8e0e8); overflow-wrap: anywhere; }
</style>
<script>
(() => {
    const checks = [...document.querySelectorAll('[data-ingreso-batch]')];
    const open = document.getElementById('review-ingreso-batch');
    const dialog = document.getElementById('ingresoBatchDialog');
    const list = document.getElementById('ingresoBatchRows');
    const status = document.getElementById('ingresoBatchStatus');
    const dates = document.getElementById('ingresoBatchDates');
    const cancel = document.getElementById('ingresoBatchCancel');
    const preview = document.getElementById('ingresoBatchPreview');
    const selected = () => checks.filter(check => check.checked);
    const update = () => {
        const count = selected().length;
        open.disabled = count < 1;
        open.textContent = count ? 'Revisar seleccionadas (' + count + ')' : 'Revisar seleccionadas';
        for (const check of checks) check.disabled = !check.checked && count >= 50;
    };
    function dateInput(label, property, required) {
        const field = document.createElement('label');
        const input = document.createElement('input');
        input.type = 'date';
        input.className = 'form-control';
        input.dataset[property] = '1';
        input.required = required;
        field.append(label, input);
        return field;
    }
    for (const check of checks) check.addEventListener('change', update);
    open.addEventListener('click', () => {
        status.textContent = '';
        list.replaceChildren();
        for (const check of selected()) {
            const row = document.createElement('div');
            row.className = 'ingreso-batch-item';
            row.dataset.id = check.dataset.ingresoBatch;
            const name = document.createElement('strong');
            const isUpdate = check.dataset.proposito === 'ACTUALIZACION';
            name.textContent = (check.dataset.nombre || 'Ficha sin nombre') + (isUpdate ? ' · Actualización' : ' · Alta');
            row.append(name);
            if (!isUpdate) {
                const fields = document.createElement('div');
                fields.className = 'ingreso-contract-grid';
                fields.append(dateInput('Inicio de contrato', 'start', true), dateInput('Fin de contrato', 'end', false));
                row.append(fields);
            }
            list.append(row);
        }
        dates.hidden = list.querySelector('[data-start]') === null;
        dialog.showModal();
    });
    document.getElementById('ingresoBatchFill').addEventListener('click', () => {
        for (const input of list.querySelectorAll('[data-start]')) input.value = document.getElementById('ingresoBatchStart').value;
        for (const input of list.querySelectorAll('[data-end]')) input.value = document.getElementById('ingresoBatchEnd').value;
    });
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', event => {
        if (preview.disabled) event.preventDefault();
    });
    document.getElementById('ingresoBatchForm').addEventListener('submit', async event => {
        event.preventDefault();
        if (preview.disabled) return;
        const filas = [...list.children].map(row => ({
            ingreso_id: row.dataset.id,
            fecha_inicio_contrato: row.querySelector('[data-start]')?.value || null,
            fecha_fin_contrato: row.querySelector('[data-end]')?.value || null,
        }));
        preview.disabled = cancel.disabled = true;
        status.textContent = 'Preparando la revisión…';
        try {
            const response = await fetch(@json(route('personal.ingresos.lotes.preview')), {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
                body: JSON.stringify({filas}),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.id) throw new Error(Object.values(result.errors || {}).flat()[0] || 'No se pudo preparar la revisión. Comprueba tu sesión.');
            const url = @json(route('personal.ingresos.lotes.show', 'LOTE')).replace('LOTE', result.id);
            const filters = new URLSearchParams(location.search);
            const returnParams = new URLSearchParams();
            for (const key of ['estado', 'q']) if (filters.get(key)) returnParams.set(key, filters.get(key));
            location.assign(url + (returnParams.size ? '?' + returnParams : ''));
        } catch (error) {
            status.textContent = error.message;
        } finally {
            preview.disabled = cancel.disabled = false;
        }
    });
    update();
})();
</script>
