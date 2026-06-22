@extends('layouts.app')

@section('title', 'Exportar en Excel - Proserge')

@section('content')
@php
    $selectedWorkerCount = count($selectedWorkers ?? []);
    $previewTablePayload = $previewTable ?? ['headers' => [], 'rows' => [], 'records' => 0, 'has_more' => false];
@endphp

<style>
.export-page {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.export-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.export-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid #e2e8f0;
}

.export-card-title {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
    font-weight: 800;
}

.export-card-subtitle {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 13px;
}

.export-card-body {
    padding: 18px;
}

.export-columns-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 10px;
}

.export-column-option {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    color: #334155;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.export-column-option input {
    width: 16px;
    height: 16px;
}

.export-column-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.export-search-wrap {
    position: relative;
}

.export-search-input {
    width: 100%;
    min-height: 46px;
    padding: 10px 13px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #0f172a;
    font: inherit;
}

.export-search-input:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.export-search-results {
    display: none;
    position: absolute;
    z-index: 40;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    max-height: 280px;
    overflow: auto;
    padding: 6px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.14);
}

.export-search-results.is-open {
    display: block;
}

.export-worker-result {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #0f172a;
    text-align: left;
    cursor: pointer;
    font: inherit;
}

.export-worker-result:hover {
    background: #f0fdfa;
}

.export-worker-name {
    display: block;
    font-size: 13px;
    font-weight: 800;
}

.export-worker-meta {
    display: block;
    margin-top: 3px;
    color: #64748b;
    font-size: 12px;
}

.export-selected-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.export-selected-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    max-width: 100%;
    padding: 8px 10px;
    border: 1px solid #99f6e4;
    border-radius: 999px;
    background: #f0fdfa;
    color: #0f766e;
    font-size: 12px;
    font-weight: 800;
}

.export-selected-chip button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border: 0;
    border-radius: 999px;
    background: #ccfbf1;
    color: #0f766e;
    cursor: pointer;
    font-weight: 900;
}

.export-preview-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.export-preview-count {
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
}

.export-preview-table-wrap {
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}

.export-preview-table {
    width: 100%;
    min-width: 860px;
    border-collapse: collapse;
}

.export-preview-table th,
.export-preview-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
    font-size: 12px;
    text-align: left;
    vertical-align: top;
}

.export-preview-table th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}

.export-preview-action-col {
    width: 54px;
    min-width: 54px;
    text-align: center !important;
}

.export-preview-table th.export-preview-action-col,
.export-preview-table td.export-preview-action-col {
    position: sticky;
    right: 0;
    z-index: 2;
    background: #ffffff;
    box-shadow: -8px 0 14px rgba(15, 23, 42, 0.04);
}

.export-preview-table th.export-preview-action-col {
    z-index: 3;
    background: #f8fafc;
}

.export-preview-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1px solid #fecaca;
    border-radius: 999px;
    background: #fff1f2;
    color: #b91c1c;
    cursor: pointer;
    font-size: 14px;
    font-weight: 900;
    line-height: 1;
}

.export-preview-remove:hover {
    background: #fee2e2;
}

.export-preview-cell-mine-ok {
    background: #dcfce7;
    color: #166534;
    font-weight: 800;
}

.export-preview-cell-mine-warn {
    background: #fef3c7;
    color: #92400e;
    font-weight: 800;
}

.export-preview-cell-mine-neutral {
    background: #e5e7eb;
    color: #374151;
    font-weight: 800;
}

.export-empty-state {
    padding: 18px;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
    color: #64748b;
    font-size: 13px;
}

.export-loading-overlay {
    position: fixed;
    inset: 0;
    z-index: 5000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(15, 23, 42, 0.48);
    backdrop-filter: blur(3px);
}

.export-loading-overlay.is-open {
    display: flex;
}

.export-loading-panel {
    width: min(460px, 100%);
    border: 1px solid #ccfbf1;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
    padding: 24px;
    text-align: center;
}

.export-loading-spinner {
    width: 52px;
    height: 52px;
    margin: 0 auto 16px;
    border: 5px solid #ccfbf1;
    border-top-color: #0d9488;
    border-radius: 999px;
    animation: export-spin 0.9s linear infinite;
}

.export-loading-title {
    margin: 0;
    color: #0f172a;
    font-size: 20px;
    font-weight: 900;
}

.export-loading-message {
    margin: 8px 0 0;
    color: #475569;
    font-size: 14px;
    line-height: 1.45;
}

.export-loading-bar {
    position: relative;
    height: 8px;
    margin-top: 18px;
    overflow: hidden;
    border-radius: 999px;
    background: #e2e8f0;
}

.export-loading-bar::after {
    content: "";
    position: absolute;
    inset: 0;
    width: 42%;
    border-radius: inherit;
    background: linear-gradient(90deg, #0d9488, #2dd4bf);
    animation: export-loading-bar 1.25s ease-in-out infinite;
}

.export-loading-meta {
    margin: 12px 0 0;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
}

@keyframes export-spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes export-loading-bar {
    0% {
        transform: translateX(-110%);
    }

    50% {
        transform: translateX(80%);
    }

    100% {
        transform: translateX(240%);
    }
}

@media (max-width: 760px) {
    .export-card-header,
    .export-preview-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .export-column-actions,
    .export-preview-toolbar .btn {
        width: 100%;
    }
}
</style>

<div class="module-page personal-page export-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Exportar en Excel</h1>
                <p class="page-subtitle">Escoge columnas, selecciona trabajadores y revisa la tabla antes de descargar.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="card" style="border-color:#fecaca; background:#fff1f2;">
            <div class="card-body" style="padding:12px 14px; color:#991b1b;">{{ session('error') }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('personal.export.download') }}" id="excelExportForm">
        @csrf

        <section class="export-card">
            <div class="export-card-header">
                <div>
                    <h2 class="export-card-title">Columnas disponibles</h2>
                    <p class="export-card-subtitle">Marca las columnas que quieres ver en el Excel.</p>
                </div>
                <div class="export-column-actions">
                    <button type="button" id="selectAllColumns" class="btn btn-outline btn-sm">Seleccionar todo</button>
                    <button type="button" id="resetRecommendedColumns" class="btn btn-outline btn-sm">Restablecer recomendadas</button>
                </div>
            </div>
            <div class="export-card-body">
                <div class="export-columns-grid">
                    @foreach($availableColumns as $key => $label)
                        <label class="export-column-option">
                            <input type="checkbox" name="columns[]" value="{{ $key }}" @checked(in_array($key, $config->columns, true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="export-card" style="margin-top:14px;">
            <div class="export-card-header">
                <div>
                    <h2 class="export-card-title">Seleccionar personal</h2>
                    <p class="export-card-subtitle">Busca por nombre, DNI o puesto y agrega trabajadores al resumen previo.</p>
                </div>
            </div>
            <div class="export-card-body">
                <div class="export-search-wrap">
                    <input id="exportWorkerSearch" class="export-search-input" type="search" autocomplete="off" placeholder="Buscar trabajador por nombre, DNI o puesto">
                    <div id="exportWorkerResults" class="export-search-results"></div>
                </div>
                <div id="exportSelectedWorkers" class="export-selected-list"></div>
            </div>
        </section>
    </form>

    <section class="export-card">
        <div class="export-card-header">
            <div>
                <h2 class="export-card-title">Resumen previo</h2>
                <p class="export-card-subtitle">Esta tabla muestra como se descargara el Excel con los trabajadores seleccionados.</p>
            </div>
        </div>
        <div class="export-card-body">
            <div class="export-preview-toolbar">
                <div id="exportPreviewCount" class="export-preview-count">{{ $selectedWorkerCount }} trabajador(es) seleccionado(s)</div>
                <button type="submit" form="excelExportForm" id="exportExcelButton" class="btn btn-primary">Exportar en Excel</button>
            </div>
            <div id="exportPreviewContent"></div>
        </div>
    </section>
</div>

<div id="exportLoadingOverlay" class="export-loading-overlay" role="status" aria-live="polite" aria-hidden="true">
    <div class="export-loading-panel">
        <div class="export-loading-spinner" aria-hidden="true"></div>
        <h2 id="exportLoadingTitle" class="export-loading-title">Preparando Excel</h2>
        <p id="exportLoadingMessage" class="export-loading-message">Validando columnas y trabajadores seleccionados.</p>
        <div class="export-loading-bar" aria-hidden="true"></div>
        <p id="exportLoadingMeta" class="export-loading-meta">No cierres esta pestaña hasta que empiece la descarga.</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const endpoints = {
        workers: @json(route('personal.export.workers')),
        preview: @json(route('personal.export.preview')),
    };
    const csrfToken = @json(csrf_token());
    const recommendedColumns = @json($recommendedColumns);
    const selectedWorkersInitial = @json($selectedWorkers ?? []);
    const previewInitial = @json($previewTablePayload);

    const form = document.getElementById('excelExportForm');
    const workerSearch = document.getElementById('exportWorkerSearch');
    const workerResults = document.getElementById('exportWorkerResults');
    const selectedWorkersBox = document.getElementById('exportSelectedWorkers');
    const previewContent = document.getElementById('exportPreviewContent');
    const previewCount = document.getElementById('exportPreviewCount');
    const exportButton = document.getElementById('exportExcelButton');
    const selectAllColumns = document.getElementById('selectAllColumns');
    const resetRecommendedColumns = document.getElementById('resetRecommendedColumns');
    const loadingOverlay = document.getElementById('exportLoadingOverlay');
    const loadingTitle = document.getElementById('exportLoadingTitle');
    const loadingMessage = document.getElementById('exportLoadingMessage');
    const loadingMeta = document.getElementById('exportLoadingMeta');
    const selectedWorkers = new Map();
    let searchTimer = null;
    let previewTimer = null;
    let loadingTimer = null;
    let loadingStep = 0;
    let exportState = 'idle';

    selectedWorkersInitial.forEach(function (worker) {
        selectedWorkers.set(worker.id, worker);
    });

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function selectedColumnInputs() {
        return Array.from(form.querySelectorAll('input[name="columns[]"]')).filter(function (input) {
            return input.checked;
        });
    }

    function selectedColumnValues() {
        return selectedColumnInputs().map(function (input) {
            return input.value;
        });
    }

    function hasValidExportSelection() {
        return selectedWorkers.size > 0 && selectedColumnValues().length > 0;
    }

    function updateExportButtonState() {
        const canExport = hasValidExportSelection();
        exportButton.disabled = exportState === 'exporting' || !canExport;

        if (exportState === 'exporting') {
            exportButton.textContent = 'Exportando...';
            return;
        }

        if (exportState === 'exported' && canExport) {
            exportButton.textContent = 'Exportado';
            return;
        }

        exportButton.textContent = 'Exportar en Excel';
    }

    function setExportState(state) {
        exportState = state;
        updateExportButtonState();
    }

    function updateLoadingCopy(title, message, meta) {
        if (loadingTitle) {
            loadingTitle.textContent = title;
        }
        if (loadingMessage) {
            loadingMessage.textContent = message;
        }
        if (loadingMeta) {
            loadingMeta.textContent = meta;
        }
    }

    function showExportLoading() {
        if (!loadingOverlay) {
            return;
        }

        loadingStep = 0;
        updateLoadingCopy(
            'Preparando Excel',
            'Validando columnas y trabajadores seleccionados.',
            'No cierres esta pestaña hasta que empiece la descarga.'
        );
        loadingOverlay.classList.add('is-open');
        loadingOverlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        window.clearInterval(loadingTimer);
        loadingTimer = window.setInterval(function () {
            loadingStep += 1;

            if (loadingStep === 1) {
                updateLoadingCopy(
                    'Armando el archivo',
                    'Estamos generando el Excel con la informacion seleccionada.',
                    'Si elegiste muchos trabajadores, puede tardar un poco mas.'
                );
                return;
            }

            if (loadingStep === 2) {
                updateLoadingCopy(
                    'Procesando datos',
                    'La descarga sigue en curso. Evita volver a presionar el boton.',
                    'El archivo se descargara automaticamente cuando termine.'
                );
                return;
            }

            updateLoadingCopy(
                'Casi listo',
                'Seguimos preparando el archivo. Gracias por esperar.',
                'Mantente en esta pantalla para recibir la descarga.'
            );
        }, 5500);
    }

    function hideExportLoading() {
        window.clearInterval(loadingTimer);
        loadingTimer = null;

        if (!loadingOverlay) {
            return;
        }

        loadingOverlay.classList.remove('is-open');
        loadingOverlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function markExportDirty() {
        if (exportState !== 'idle') {
            setExportState('idle');
        } else {
            updateExportButtonState();
        }
    }

    function filenameFromDisposition(disposition) {
        const header = String(disposition || '');
        const encoded = header.match(/filename\*=UTF-8''([^;]+)/i);
        if (encoded && encoded[1]) {
            return decodeURIComponent(encoded[1].replace(/"/g, '').trim());
        }

        const plain = header.match(/filename="?([^";]+)"?/i);
        if (plain && plain[1]) {
            return plain[1].trim();
        }

        return 'personal_export.xlsx';
    }

    function triggerExcelDownload(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || 'personal_export.xlsx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () {
            window.URL.revokeObjectURL(url);
        }, 1000);
    }

    function renderSelectedWorkers() {
        const workers = Array.from(selectedWorkers.values());
        previewCount.textContent = workers.length + ' trabajador(es) seleccionado(s)';

        if (workers.length === 0) {
            selectedWorkersBox.innerHTML = '<div class="export-empty-state" style="width:100%;">Aun no seleccionaste trabajadores.</div>';
            return;
        }

        selectedWorkersBox.innerHTML = workers.map(function (worker) {
            return '<span class="export-selected-chip">'
                + '<span>' + escapeHtml(worker.nombre) + ' - ' + escapeHtml(worker.documento || worker.dni || '') + '</span>'
                + '<button type="button" data-remove-worker="' + escapeHtml(worker.id) + '">x</button>'
                + '</span>';
        }).join('');
    }

    function renderSearchResults(workers, message) {
        workerResults.classList.add('is-open');

        if (message) {
            workerResults.innerHTML = '<div class="export-empty-state">' + escapeHtml(message) + '</div>';
            return;
        }

        if (!workers.length) {
            workerResults.innerHTML = '<div class="export-empty-state">No se encontraron trabajadores.</div>';
            return;
        }

        workerResults.innerHTML = workers.map(function (worker) {
            const already = selectedWorkers.has(worker.id);
            return '<button type="button" class="export-worker-result" data-add-worker="' + escapeHtml(worker.id) + '" data-worker=\'' + escapeHtml(JSON.stringify(worker)) + '\'>'
                + '<span>'
                + '<span class="export-worker-name">' + escapeHtml(worker.nombre) + '</span>'
                + '<span class="export-worker-meta">' + escapeHtml(worker.documento || worker.dni || '-') + ' | ' + escapeHtml(worker.puesto || 'Sin puesto') + '</span>'
                + '</span>'
                + '<span class="export-worker-meta">' + (already ? 'Seleccionado' : 'Agregar') + '</span>'
                + '</button>';
        }).join('');
    }

    async function searchWorkers() {
        const value = workerSearch.value.trim();
        if (value.length < 2) {
            renderSearchResults([], 'Escribe al menos 2 caracteres para buscar.');
            return;
        }

        renderSearchResults([], 'Buscando...');
        const response = await fetch(endpoints.workers + '?q=' + encodeURIComponent(value), {
            headers: {'Accept': 'application/json'}
        });

        if (!response.ok) {
            renderSearchResults([], 'No se pudo buscar personal en este momento.');
            return;
        }

        const payload = await response.json();
        renderSearchResults(Array.isArray(payload.workers) ? payload.workers : []);
    }

    function renderPreview(payload) {
        const headers = Array.isArray(payload.headers) ? payload.headers : [];
        const rows = Array.isArray(payload.rows) ? payload.rows : [];
        const rowIds = Array.isArray(payload.row_ids) ? payload.row_ids : [];
        const cellStyles = Array.isArray(payload.cell_styles) ? payload.cell_styles : [];
        const workerIds = Array.from(selectedWorkers.keys());
        const workersCount = selectedWorkers.size;
        const columnsCount = selectedColumnValues().length;

        updateExportButtonState();

        if (columnsCount === 0) {
            previewContent.innerHTML = '<div class="export-empty-state">Selecciona al menos una columna para armar la vista previa.</div>';
            return;
        }

        if (workersCount === 0) {
            previewContent.innerHTML = '<div class="export-empty-state">Busca y selecciona trabajadores para ver el resumen previo.</div>';
            return;
        }

        if (rows.length === 0) {
            previewContent.innerHTML = '<div class="export-empty-state">No hay datos disponibles para los trabajadores seleccionados.</div>';
            return;
        }

        let html = '<div class="export-preview-table-wrap"><table class="export-preview-table"><thead><tr>';
        html += headers.map(function (header) {
            return '<th>' + escapeHtml(header) + '</th>';
        }).join('');
        html += '<th class="export-preview-action-col" aria-label="Quitar trabajador"></th>';
        html += '</tr></thead><tbody>';
        html += rows.map(function (row, index) {
            const workerId = rowIds[index] || workerIds[index] || '';
            const styles = Array.isArray(cellStyles[index]) ? cellStyles[index] : [];
            return '<tr>' + row.map(function (cell, cellIndex) {
                const style = styles[cellIndex] ? ' export-preview-cell-' + escapeHtml(styles[cellIndex]) : '';
                return '<td class="' + style.trim() + '">' + escapeHtml(cell || '-') + '</td>';
            }).join('')
            + '<td class="export-preview-action-col">'
            + '<button type="button" class="export-preview-remove" data-preview-remove-worker="' + escapeHtml(workerId) + '" aria-label="Quitar trabajador de la exportacion" title="Quitar trabajador">X</button>'
            + '</td></tr>';
        }).join('');
        html += '</tbody></table></div>';

        if (payload.has_more) {
            html += '<p class="export-card-subtitle" style="margin-top:10px;">La vista previa muestra las primeras filas. El Excel incluira todos los trabajadores seleccionados.</p>';
        }

        previewContent.innerHTML = html;
    }

    function previewPayload() {
        const body = new FormData();
        selectedColumnValues().forEach(function (column) {
            body.append('columns[]', column);
        });
        selectedWorkers.forEach(function (worker, id) {
            body.append('personal_ids[]', id);
        });
        return body;
    }

    async function refreshPreview() {
        const workersCount = selectedWorkers.size;
        const columnsCount = selectedColumnValues().length;
        previewCount.textContent = workersCount + ' trabajador(es) seleccionado(s)';

        if (workersCount === 0 || columnsCount === 0) {
            renderPreview({headers: [], rows: []});
            return;
        }

        previewContent.innerHTML = '<div class="export-empty-state">Actualizando resumen previo...</div>';
        const response = await fetch(endpoints.preview, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: previewPayload()
        });

        if (!response.ok) {
            previewContent.innerHTML = '<div class="export-empty-state">No se pudo actualizar el resumen previo.</div>';
            return;
        }

        renderPreview(await response.json());
    }

    function schedulePreview() {
        markExportDirty();
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 220);
    }

    function syncSubmitInputs() {
        form.querySelectorAll('[data-export-dynamic="1"]').forEach(function (input) {
            input.remove();
        });

        selectedWorkers.forEach(function (worker, id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'personal_ids[]';
            input.value = id;
            input.dataset.exportDynamic = '1';
            form.appendChild(input);
        });
    }

    workerSearch.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(searchWorkers, 250);
    });

    workerSearch.addEventListener('focus', function () {
        if (workerSearch.value.trim().length >= 2) {
            searchWorkers();
        }
    });

    document.addEventListener('click', function (event) {
        if (!workerResults.contains(event.target) && event.target !== workerSearch) {
            workerResults.classList.remove('is-open');
        }
    });

    workerResults.addEventListener('click', function (event) {
        const button = event.target.closest('[data-add-worker]');
        if (!button) {
            return;
        }

        const worker = JSON.parse(button.dataset.worker || '{}');
        if (worker.id) {
            selectedWorkers.set(worker.id, worker);
            renderSelectedWorkers();
            schedulePreview();
        }
    });

    selectedWorkersBox.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-worker]');
        if (!button) {
            return;
        }

        selectedWorkers.delete(button.dataset.removeWorker || '');
        renderSelectedWorkers();
        schedulePreview();
    });

    previewContent.addEventListener('click', function (event) {
        const button = event.target.closest('[data-preview-remove-worker]');
        if (!button) {
            return;
        }

        selectedWorkers.delete(button.dataset.previewRemoveWorker || '');
        renderSelectedWorkers();
        schedulePreview();
    });

    form.querySelectorAll('input[name="columns[]"]').forEach(function (input) {
        input.addEventListener('change', schedulePreview);
    });

    selectAllColumns.addEventListener('click', function () {
        form.querySelectorAll('input[name="columns[]"]').forEach(function (input) {
            input.checked = true;
        });
        schedulePreview();
    });

    resetRecommendedColumns.addEventListener('click', function () {
        form.querySelectorAll('input[name="columns[]"]').forEach(function (input) {
            input.checked = recommendedColumns.includes(input.value);
        });
        schedulePreview();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        syncSubmitInputs();

        if (selectedWorkers.size === 0 || selectedColumnValues().length === 0) {
            renderPreview({headers: [], rows: []});
            return;
        }

        setExportState('exporting');
        showExportLoading();

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,*/*',
                },
                body: new FormData(form),
            });

            if (!response.ok) {
                throw new Error('No se pudo exportar el Excel en este momento.');
            }

            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('text/html')) {
                throw new Error('No se pudo exportar el Excel. Vuelve a iniciar sesion si el problema continua.');
            }

            const blob = await response.blob();
            updateLoadingCopy(
                'Excel listo',
                'La descarga esta por iniciar en tu navegador.',
                'Puedes continuar trabajando cuando aparezca el archivo.'
            );
            triggerExcelDownload(blob, filenameFromDisposition(response.headers.get('content-disposition')));
            setExportState('exported');
            window.setTimeout(hideExportLoading, 900);
        } catch (error) {
            hideExportLoading();
            previewContent.innerHTML = '<div class="export-empty-state">' + escapeHtml(error.message || 'No se pudo exportar el Excel.') + '</div>';
            setExportState('idle');
        }
    });

    renderSelectedWorkers();
    renderPreview(previewInitial);
    updateExportButtonState();
});
</script>
@endpush
