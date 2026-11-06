@extends('layouts.app')

@section('title', 'Revisión de fichas seleccionadas - Proserge')

@section('content')
@php
    $filters = collect(request()->only(['estado', 'q']))->filter(fn ($value) => is_string($value) && strlen($value) <= 200)->all();
    $canUploadContracts = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'personal', 'subir_contrato_firmado');
@endphp
<div class="module-page ingreso-lote">
    <div class="page-header">
        <h1 class="page-title">Revisión de fichas seleccionadas</h1>
        <p class="page-subtitle">Abre cada ficha, confirma que la revisaste y acepta las que estén listas.</p>
        <div class="ingreso-lote-actions">
            <a class="btn btn-outline" href="{{ route('personal.fichas.temporales', $filters) }}">Volver a Ingresos</a>
            @if($canUploadContracts)
                <a class="btn btn-outline" href="{{ route('personal.contratos.firmados.index') }}">Cargar PDF de contratos</a>
            @endif
        </div>
    </div>
    @if($batch['vencido'])
        <div class="alert alert-warning">La revisión venció. Puedes consultar sus resultados y preparar otro lote desde Ingresos.</div>
    @endif
    <div class="card">
        <div class="card-body">
            @foreach($batch['filas'] as $row)
                <section class="ingreso-lote-row" id="fila-{{ $row['id'] }}">
                    <strong>{{ $row['trabajador'] }} · {{ $row['proposito'] === 'ACTUALIZACION' ? 'Actualización' : 'Alta' }}</strong>
                    @if($row['fecha_inicio_contrato'])
                        <div>Periodo del contrato {{ $row['fecha_inicio_contrato'] }} a {{ $row['fecha_fin_contrato'] ?: 'fecha abierta' }}</div>
                    @endif
                    <p data-fila-status>{{ $row['mensaje'] ?: 'Pendiente de confirmación.' }}</p>
                    @if(!$batch['vencido'] && $row['estado'] === 'LISTA')
                        <details data-fila-review="{{ $row['id'] }}">
                            <summary>Revisar datos aquí</summary>
                            <div data-fila-details></div>
                        </details>
                    @endif
                    <div class="ingreso-lote-actions">
                        <a class="btn btn-outline btn-sm" href="{{ route('personal.ingresos.show', $row['ingreso_id']) }}" target="_blank" rel="noopener noreferrer">Abrir ficha en otra pestaña</a>
                        @if(!$batch['vencido'] && $row['estado'] === 'LISTA')
                            <label><input type="checkbox" data-fila-confirmar="{{ $row['id'] }}" disabled> Revisé esta ficha</label>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>
    @if(!$batch['vencido'])
        <div class="ingreso-lote-actions">
            <button class="btn btn-primary" type="button" id="ingreso-lote-confirm" disabled>Aceptar revisadas</button>
            <button class="btn btn-outline" type="button" id="ingreso-lote-refresh">Consultar resultado</button>
        </div>
    @endif
    <p id="ingreso-lote-status" role="status" tabindex="-1"></p>
</div>
<style>
    .ingreso-lote-row { padding: .75rem 0; border-bottom: 1px solid var(--border-color, #d8e0e8); overflow-wrap: anywhere; }
    .ingreso-lote-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin: .6rem 0; }
    .ingreso-lote button:disabled { opacity: .45; cursor: default; }
    .ingreso-lote-review-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); gap: .75rem; margin: .75rem 0; }
    .ingreso-lote-review-grid div { overflow-wrap: anywhere; }
    .ingreso-lote-review-grid dt { font-weight: 650; }
    .ingreso-lote-review-grid dd { margin: 0; }
</style>
@if(!$batch['vencido'])
<script>
(() => {
    const confirm = document.getElementById('ingreso-lote-confirm');
    const refresh = document.getElementById('ingreso-lote-refresh');
    const status = document.getElementById('ingreso-lote-status');
    const checks = [...document.querySelectorAll('[data-fila-confirmar]')];
    const selected = () => checks.filter(check => check.checked && check.isConnected);
    let busy = false;
    function update() {
        const count = selected().length;
        confirm.textContent = count ? 'Aceptar ' + count + ' revisadas' : 'Aceptar revisadas';
        confirm.disabled = busy || !count;
        confirm.hidden = !checks.some(check => check.isConnected);
        refresh.disabled = busy;
        for (const check of checks) check.disabled = busy || !check.dataset.reviewLoaded;
    }
    async function request(url, data = null) {
        const response = await fetch(url, {
            method: data ? 'POST' : 'GET', cache: 'no-store',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
            body: data ? JSON.stringify(data) : undefined,
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) throw new Error(result.message || 'Comprueba tu sesión o conexión.');
        return result;
    }
    function display(row) {
        const section = document.getElementById('fila-' + row.id);
        if (row.mensaje) section.querySelector('[data-fila-status]').textContent = row.mensaje;
        if (row.estado !== 'LISTA') section.querySelector('[data-fila-confirmar]')?.closest('label').remove();
    }
    for (const details of document.querySelectorAll('[data-fila-review]')) {
        const holder = details.querySelector('[data-fila-details]');
        let loading = false;
        async function load() {
            if (loading || details.dataset.loaded) return;
            loading = true;
            holder.textContent = 'Cargando los datos para revisar…';
            try {
                const url = @json(route('personal.ingresos.lotes.details', ['lote' => $batch['id'], 'fila' => 'FILA']))
                    .replace('FILA', details.dataset.filaReview);
                const {campos, proposito, familiares = []} = await request(url);
                holder.replaceChildren();
                const grid = document.createElement('dl');
                grid.className = 'ingreso-lote-review-grid';
                for (const field of campos) {
                    const item = document.createElement('div');
                    const title = document.createElement('dt');
                    title.textContent = field.campo;
                    item.append(title);
                    if (proposito === 'ACTUALIZACION') {
                        const previous = document.createElement('dd');
                        previous.textContent = 'Actual · ' + (field.antes || 'Sin dato');
                        item.append(previous);
                    }
                    const proposed = document.createElement('dd');
                    proposed.textContent = (proposito === 'ACTUALIZACION' ? 'Propuesto · ' : '') + (field.propuesto || 'Vacío');
                    item.append(proposed);
                    grid.append(item);
                }
                holder.append(grid);
                if (!campos.length && !familiares.length) holder.textContent = 'La propuesta conserva los datos revisados.';
                for (const family of familiares) {
                    const section = document.createElement('section');
                    const title = document.createElement('h3');
                    title.textContent = 'Familiar propuesto · ' + family.mensaje;
                    section.append(title);
                    const fields = document.createElement('dl');
                    fields.className = 'ingreso-lote-review-grid';
                    for (const field of family.campos) {
                        const item = document.createElement('div');
                        const label = document.createElement('dt');
                        label.textContent = field.campo;
                        const value = document.createElement('dd');
                        value.textContent = field.propuesto || 'Sin dato';
                        item.append(label, value);
                        fields.append(item);
                    }
                    section.append(fields);
                    holder.append(section);
                }
                details.dataset.loaded = '1';
                const check = checks.find(check => check.dataset.filaConfirmar === details.dataset.filaReview);
                check.dataset.reviewLoaded = '1';
                update();
            } catch (error) {
                holder.textContent = error.message + ' ';
                const retry = document.createElement('button');
                retry.className = 'btn btn-outline btn-sm';
                retry.type = 'button';
                retry.textContent = 'Volver a consultar';
                retry.addEventListener('click', load);
                holder.append(retry);
            } finally {
                loading = false;
            }
        }
        details.addEventListener('toggle', () => { if (details.open) load(); });
    }
    async function readResults() {
        const {filas, vencido} = await request(@json(route('personal.ingresos.lotes.show', $batch['id'])));
        if (vencido) location.reload();
        filas.forEach(display);
        return filas.filter(row => row.estado === 'ACEPTADA').length;
    }
    for (const check of checks) check.addEventListener('change', update);
    confirm.addEventListener('click', async () => {
        if (busy) return;
        const ids = selected().map(check => check.dataset.filaConfirmar);
        busy = true;
        update();
        status.textContent = 'Aceptando fichas…';
        try {
            for (let offset = 0; offset < ids.length; offset += 10) {
                const {resultados} = await request(@json(route('personal.ingresos.lotes.confirm', $batch['id'])), {
                    revision: @json($batch['revision']), filas: ids.slice(offset, offset + 10), revisado: true,
                });
                resultados.forEach(display);
            }
            status.textContent = 'Fichas aceptadas en este lote: ' + await readResults() + '.';
        } catch (error) {
            status.textContent = 'La solicitud se interrumpió. Consulta el resultado antes de reintentar. ' + error.message;
        } finally {
            busy = false;
            update();
            status.focus();
        }
    });
    refresh.addEventListener('click', async () => {
        busy = true;
        update();
        try {
            status.textContent = 'Fichas aceptadas en este lote: ' + await readResults() + '.';
        } catch (error) {
            status.textContent = 'No se pudo consultar el resultado. ' + error.message;
        } finally {
            busy = false;
            update();
            status.focus();
        }
    });
})();
</script>
@endif
@endsection
