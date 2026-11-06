@extends('layouts.app')

@section('title', 'Carga de contratos firmados - Proserge')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canViewExpiring = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'vencimientos', 'ver');
    $canViewPersonal = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'personal', 'ver');
    $urls = [
        'start' => route('personal.contratos.firmados.start'),
        'search' => route('personal.contratos.firmados.search'),
        'show' => route('personal.contratos.firmados.show', 'LOTE'),
        'ready' => route('personal.contratos.firmados.ready'),
        'activate' => route('personal.contratos.firmados.activate'),
    ];
@endphp
<div class="module-page signed-batch">
    <div class="page-header">
        <h1 class="page-title">Carga de contratos firmados</h1>
        <p class="page-subtitle">Selecciona los PDF, elige el contrato de cada trabajador y confirma los que ya revisaste.</p>
        <div class="signed-actions">
            <a class="btn btn-outline" href="{{ route($canViewExpiring ? 'personal.contratos.expiring' : ($canViewPersonal ? 'personal.index' : 'home')) }}">Volver</a>
            @if($lote)
                <a class="btn btn-outline" href="{{ route('personal.contratos.firmados.index', ['nuevo' => 1]) }}">Abrir otro lote</a>
            @endif
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <label for="signed-files"><strong>1. Selecciona los PDF firmados</strong></label>
            <input id="signed-files" class="form-input" type="file" multiple accept=".pdf,application/pdf"
                   aria-describedby="signed-help" @disabled($maxBytes < 1)>
            <p id="signed-help">Un PDF por contrato. Hasta {{ number_format($maxBytes / 1048576, 1) }} MB por archivo. Tienes dos horas para revisar cada lote.</p>
            <div id="signed-status" role="status" tabindex="-1"></div>
            <ul id="signed-upload-errors" aria-live="polite"></ul>
        </div>
    </div>
    <div id="signed-review" class="card" hidden>
        <div class="card-body">
            <h2>2. Revisa los archivos y sus contratos</h2>
            <p>Busca por nombre o documento. Compara el trabajador, el número y el periodo con el PDF.</p>
            <div id="signed-rows"></div>
            <div id="signed-confirm-step">
            <h2>3. Guarda los contratos seleccionados</h2>
            <div class="signed-actions">
                <button class="btn btn-outline" type="button" id="signed-select">Marcar todos los listos</button>
                <label><input type="checkbox" id="signed-reviewed"> Revisé el PDF y el periodo de cada contrato marcado.</label>
                <button class="btn btn-primary" type="button" id="signed-confirm" disabled>Guardar seleccionados</button>
            </div>
            </div>
        </div>
    </div>
    <details class="card" id="signed-ready">
        <summary class="card-body"><strong>Contratos firmados listos para activar</strong></summary>
        <div class="card-body">
            <p>Solo aparecen los contratos firmados cuyo periodo ya empezó. Marca los que revisaste para activar su vigencia.</p>
            <div id="signed-ready-list"></div>
            <div class="signed-actions">
                <button class="btn btn-outline" type="button" id="signed-ready-prev" disabled>Anteriores</button>
                <button class="btn btn-outline" type="button" id="signed-ready-next" disabled>Siguientes</button>
                <button class="btn btn-primary" type="button" id="signed-activate" disabled>Activar seleccionados</button>
            </div>
            <p id="signed-activation-status" role="status"></p>
        </div>
    </details>
</div>
<style>
    .signed-batch [hidden] { display: none; }
    .signed-actions, .signed-row-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin: .5rem 0; }
    .signed-batch .card { margin-bottom: 1rem; }
    .signed-batch h2 { font-size: 1.1rem; font-weight: 650; }
    .signed-row { padding: .75rem 0; border-bottom: 1px solid var(--border-color, #d8e0e8); overflow-wrap: anywhere; }
    .signed-row input[type=search], #signed-files { display: block; max-width: 100%; margin: .5rem 0; }
    .signed-candidates { display: grid; justify-items: start; gap: .5rem; }
    .signed-candidates button { white-space: normal; text-align: start; }
    .signed-batch button:disabled { opacity: .45; cursor: default; }
</style>
<script>
(() => {
    const urls = @json($urls);
    const csrf = @json(csrf_token());
    const canPreview = @json($canPreviewPdf);
    const maxBytes = @json($maxBytes);
    let lot = @json($lote);
    let batch = null;
    let busy = false;
    const selected = new Set();
    const terminal = new Set(['GUARDADO', 'FIRMADO_FUTURO']);
    const files = document.getElementById('signed-files');
    const status = document.getElementById('signed-status');
    const errors = document.getElementById('signed-upload-errors');
    const review = document.getElementById('signed-review');
    const rows = document.getElementById('signed-rows');
    const select = document.getElementById('signed-select');
    const reviewed = document.getElementById('signed-reviewed');
    const confirm = document.getElementById('signed-confirm');
    const base = () => urls.show.replace('LOTE', lot);

    function element(tag, label, className) {
        const node = document.createElement(tag);
        if (label) node.textContent = label;
        if (className) node.className = className;
        return node;
    }

    function button(label) {
        const node = element('button', label, 'btn btn-outline btn-sm');
        node.type = 'button';
        return node;
    }

    async function request(url, method = 'GET', data) {
        const headers = {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf};
        let body = data;
        if (data && !(data instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(data);
        }
        const response = await fetch(url, {method, headers, body, cache: 'no-store'});
        if ([401, 403, 419].includes(response.status)) {
            throw new Error('Revisa tu sesión o tus permisos antes de continuar.');
        }
        if (response.status === 410) throw new Error('El lote venció. Abre otro lote y selecciona los PDF pendientes.');
        const result = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) {
            const message = Object.values(result.errors || {}).flat()[0];
            throw new Error(message || (response.status === 409 ? result.message : null) || 'No se pudo completar la acción. Actualiza la página para comprobar el resultado.');
        }
        return result;
    }

    function updateActions() {
        const ready = batch?.archivos.filter(row => row.listo) || [];
        files.disabled = busy || maxBytes < 1;
        select.disabled = busy || ready.length === 0;
        reviewed.disabled = busy || selected.size === 0;
        confirm.disabled = busy || !reviewed.checked || selected.size === 0;
        confirm.textContent = selected.size ? 'Guardar seleccionados (' + selected.size + ')' : 'Guardar seleccionados';
        rows.inert = busy;
    }

    function describe(contract) {
        return contract.trabajador + ' · Contrato ' + (contract.numero ?? 'sin número') + ' · ' + contract.periodo;
    }

    function searchControls(row, rowUrl) {
        const group = element('div');
        const label = element('label', 'Buscar trabajador');
        const input = element('input', '', 'form-input');
        input.id = 'buscar-' + row.id;
        input.type = 'search';
        input.maxLength = 100;
        input.autocomplete = 'off';
        label.htmlFor = input.id;
        const search = button('Buscar contrato');
        const matches = element('div', '', 'signed-candidates');
        matches.setAttribute('aria-live', 'polite');
        search.addEventListener('click', async () => {
            const q = input.value.trim();
            if (q.length < 2) {
                matches.textContent = 'Escribe al menos dos caracteres.';
                return;
            }
            search.disabled = true;
            matches.textContent = 'Buscando contratos…';
            try {
                const {contratos} = await request(urls.search, 'POST', {q});
                matches.textContent = contratos.length ? '' : 'No hay contratos en preparación para esta búsqueda.';
                contratos.forEach(contract => {
                    const choice = button(describe(contract));
                    choice.addEventListener('click', () => action(async () => {
                        await request(rowUrl, 'PATCH', {contrato_id: contract.id});
                        selected.delete(row.id);
                        await refresh();
                        status.textContent = 'Contrato asociado. Revísalo y márcalo para guardar.';
                        status.focus();
                    }));
                    matches.append(choice);
                });
            } catch (error) {
                matches.textContent = error.message;
            } finally {
                search.disabled = false;
            }
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                search.click();
            }
        });
        group.append(label, input, search, matches);
        return group;
    }

    async function refresh() {
        batch = await request(base());
        const eligible = new Set(batch.archivos.filter(row => row.listo).map(row => row.id));
        for (const id of selected) if (!eligible.has(id)) selected.delete(id);
        reviewed.checked = false;
        review.hidden = batch.archivos.length === 0;
        document.getElementById('signed-confirm-step').hidden = !batch.archivos.some(row => !terminal.has(row.estado));
        rows.replaceChildren();
        batch.archivos.forEach(row => {
            const section = element('section', '', 'signed-row');
            const title = element('strong', row.nombre);
            const controls = element('div', '', 'signed-row-actions');
            const rowUrl = base() + '/archivos/' + row.id;
            const message = row.mensaje || (row.listo ? 'Listo para revisar y guardar.' :
                row.contrato ? 'Revisa nuevamente el periodo del contrato.' : 'Falta elegir el contrato.');
            section.append(title, element('p', row.contrato ? describe(row.contrato) : null), element('p', message), controls);
            if (canPreview) {
                const link = element('a', 'Ver PDF', 'btn btn-outline btn-sm');
                link.href = rowUrl + '/pdf';
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                controls.append(link);
            }
            if (terminal.has(row.estado)) {
                rows.append(section);
                return;
            }
            if (row.listo) {
                const label = element('label');
                const check = element('input');
                check.id = 'marcar-' + row.id;
                check.type = 'checkbox';
                check.checked = selected.has(row.id);
                check.addEventListener('change', () => {
                    if (check.checked) selected.add(row.id);
                    else selected.delete(row.id);
                    reviewed.checked = false;
                    updateActions();
                });
                label.append(check, ' Marcar para guardar');
                controls.append(label);
            }
            const discard = button('Retirar del lote');
            discard.addEventListener('click', () => action(async () => {
                await request(rowUrl, 'DELETE');
                selected.delete(row.id);
                await refresh();
                status.textContent = 'PDF retirado del lote.';
                status.focus();
            }));
            controls.append(discard);
            const edit = element('details');
            edit.open = !row.listo;
            edit.append(element('summary', row.contrato ? 'Cambiar contrato' : 'Elegir contrato'), searchControls(row, rowUrl));
            section.append(edit);
            rows.append(section);
        });
        updateActions();
    }

    async function action(run) {
        if (busy) return;
        busy = true;
        updateActions();
        try {
            await run();
        } catch (error) {
            status.textContent = error.message;
            status.focus();
        } finally {
            busy = false;
            updateActions();
        }
    }

    async function upload(file, operation, errorRow) {
        status.textContent = 'Cargando ' + file.name + '…';
        try {
            const data = new FormData();
            data.append('archivo', file);
            data.append('operacion_id', operation);
            await request(base() + '/archivos', 'POST', data);
            errorRow?.remove();
            return true;
        } catch (error) {
            const notice = errorRow || element('li');
            const retry = button('Reintentar PDF');
            retry.addEventListener('click', () => action(async () => {
                const saved = await upload(file, operation, notice);
                await refresh();
                status.textContent = saved ? 'PDF recibido. Elige su contrato.' : 'La carga sigue pendiente.';
            }));
            notice.replaceChildren(document.createTextNode(file.name + ' · ' + error.message + ' '), retry);
            if (!errorRow) errors.append(notice);
            return false;
        }
    }

    files.addEventListener('change', () => action(async () => {
        const chosen = [...files.files];
        if (!chosen.length) return;
        if (!lot) lot = (await request(urls.start, 'POST')).id;
        files.value = '';
        let received = 0;
        for (const file of chosen) {
            if (file.size > maxBytes || !file.name.toLowerCase().endsWith('.pdf')) {
                errors.append(element('li', file.name + ' · Usa un PDF dentro del tamaño permitido.'));
                continue;
            }
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 15) | 64;
            bytes[8] = (bytes[8] & 63) | 128;
            const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            const operation = [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
            if (await upload(file, operation)) received++;
        }
        await refresh();
        status.textContent = received ? 'PDF recibidos: ' + received + '. Elige su contrato para continuar.' : 'Revisa los mensajes de carga.';
    }));

    select.addEventListener('click', () => {
        batch.archivos.filter(row => row.listo).forEach(row => {
            selected.add(row.id);
            document.getElementById('marcar-' + row.id).checked = true;
        });
        reviewed.checked = false;
        updateActions();
    });

    reviewed.addEventListener('change', updateActions);
    confirm.addEventListener('click', () => action(async () => {
        const ids = [...selected];
        let saved = 0;
        let needsReview = 0;
        status.textContent = 'Guardando los contratos seleccionados…';
        try {
            for (let offset = 0; offset < ids.length; offset += 20) {
                const {resultados} = await request(base() + '/confirmar', 'POST', {
                    revision: batch.revision, archivos: ids.slice(offset, offset + 20), revisado: true,
                });
                saved += resultados.filter(row => terminal.has(row.estado)).length;
                needsReview += resultados.filter(row => !terminal.has(row.estado)).length;
            }
        } finally {
            await refresh();
        }
        status.textContent = 'PDF guardados: ' + saved + '. Por revisar: ' + needsReview + '.';
        status.focus();
    }));

    if (lot) action(refresh);

    const ready = document.getElementById('signed-ready');
    const readyList = document.getElementById('signed-ready-list');
    const previous = document.getElementById('signed-ready-prev');
    const next = document.getElementById('signed-ready-next');
    const activate = document.getElementById('signed-activate');
    const activationStatus = document.getElementById('signed-activation-status');
    const activationSelected = new Map();
    let readyPage = 1;
    let more = false;
    let activating = false;

    function activationActions() {
        previous.disabled = activating || readyPage === 1;
        next.disabled = activating || !more;
        activate.disabled = activating || activationSelected.size === 0;
        readyList.inert = activating;
    }

    async function showReady(page) {
        const {contratos, mas} = await request(urls.ready + '?page=' + page);
        readyPage = page;
        more = mas;
        activationSelected.clear();
        readyList.replaceChildren();
        if (contratos.length === 0) readyList.append(element('p', 'No hay contratos listos en esta página.'));
        for (const contract of contratos) {
            const row = element('div', '', 'signed-row');
            row.dataset.id = contract.id;
            const label = element('label');
            const check = element('input');
            check.type = 'checkbox';
            check.addEventListener('change', () => {
                if (check.checked) activationSelected.set(contract.id, {id: contract.id, revision: contract.revision});
                else activationSelected.delete(contract.id);
                activationActions();
            });
            label.append(check, ' Revisé y quiero activar este contrato');
            row.append(element('strong', describe(contract)));
            const controls = element('div', '', 'signed-row-actions');
            if (contract.pdf_url) {
                const link = element('a', 'Ver PDF', 'btn btn-outline btn-sm');
                link.href = contract.pdf_url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                controls.append(link);
            }
            controls.append(label);
            row.append(controls);
            readyList.append(row);
        }
    }

    async function activationAction(run) {
        if (activating) return;
        activating = true;
        activationStatus.textContent = 'Consultando contratos…';
        activationActions();
        try {
            await run();
        } catch (error) {
            activationStatus.textContent = error.message;
        } finally {
            activating = false;
            activationActions();
        }
    }

    function showReadyPage(page) {
        activationAction(async () => {
            await showReady(page);
            activationStatus.textContent = '';
        });
    }

    ready.addEventListener('toggle', () => {
        if (ready.open) showReadyPage(1);
    });
    previous.addEventListener('click', () => showReadyPage(readyPage - 1));
    next.addEventListener('click', () => showReadyPage(readyPage + 1));
    activate.addEventListener('click', () => activationAction(async () => {
        const contracts = [...activationSelected.values()];
        const needsReview = [];
        let activated = 0;
        for (let offset = 0; offset < contracts.length; offset += 20) {
            const {resultados} = await request(urls.activate, 'POST', {contratos: contracts.slice(offset, offset + 20), revisado: true});
            for (const result of resultados) {
                if (result.estado === 'ACTIVO') activated++;
                else needsReview.push(result);
            }
        }
        await showReady(1);
        activationStatus.textContent = 'Contratos activados: ' + activated + '. Por revisar: ' + needsReview.length + '.';
        for (const result of needsReview) {
            const row = [...readyList.children].find(node => node.dataset.id === result.id);
            (row || readyList).append(element('p', result.mensaje));
        }
    }));
})();
</script>
@endsection
