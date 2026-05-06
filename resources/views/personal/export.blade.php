@extends('layouts.app')

@section('title', 'Exportar - Proserge')

@section('content')
@php
    $currentFilters = request()->only(['search', 'estado', 'tipo', 'mina', 'mina_estado', 'contrato', 'sort', 'order']);
    $selectedFichaColumns = collect(request()->input('ficha_columns', $recommendedFichaColumns))
        ->filter(fn ($value) => is_string($value) && array_key_exists($value, $availableFichaColumns))
        ->values()
        ->all();
    if (count($selectedFichaColumns) === 0) {
        $selectedFichaColumns = $recommendedFichaColumns;
    }
@endphp

<div class="module-page personal-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Exportar</h1>
                <p class="page-subtitle">Arma tu salida de personal o de fichas con el filtro actual.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index', $currentFilters) }}" class="btn btn-outline">Volver a Personal</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="card" style="border-color:#fecaca; background:#fff1f2; margin-bottom:12px;">
            <div class="card-body" style="padding:12px 14px; color:#991b1b;">{{ session('error') }}</div>
        </div>
    @endif

    <div class="card" style="margin-bottom:12px; border-color:#bfdbfe; background:#f8fbff;">
        <div class="card-body" style="padding:12px 14px; color:#1e3a8a;">
            Esta pantalla trabaja con el filtro actual de Personal. Si quieres otro grupo, primero filtra en <a href="{{ route('personal.index') }}">Personal</a> y luego vuelve a exportar.
        </div>
    </div>

    <!-- Botones principales para desplegar -->
    <div class="card" style="margin-bottom:12px;">
        <div class="card-body" style="padding:16px;">
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <button type="button" class="btn btn-primary" onclick="toggleSection('personalExportSection', this)" id="btnPersonalExport" style="display:inline-flex; align-items:center; gap:8px;">
                    <i class='fas fa-file-excel' style='margin-right:4px;'></i>
                    Exportar personal en Excel
                    <i class='fas fa-chevron-down' style='margin-left:8px; transition:transform 0.2s;' id="iconPersonal"></i>
                </button>
                <button type="button" class="btn btn-primary" onclick="toggleSection('fichasExportSection', this)" id="btnFichasExport" style="display:inline-flex; align-items:center; gap:8px;">
                    <i class='fas fa-file-alt' style='margin-right:4px;'></i>
                    Exportar fichas
                    <i class='fas fa-chevron-down' style='margin-left:8px; transition:transform 0.2s;' id="iconFichas"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Sección Personal (colapsable) -->
    <div id="personalExportSection" class="card" style="margin-bottom:12px; display:none;">
        <div class="card-header" style="cursor:pointer;" onclick="toggleSection('personalExportSection', document.getElementById('btnPersonalExport'))">
            <span class="card-title">Exportar personal en Excel</span>
            <i class='fas fa-chevron-up' style='float:right;'></i>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('personal.export.form') }}" id="exportConfigForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Alcance</label>
                        <select class="form-control" name="scope">
                            <option value="all" {{ $config->scope === 'all' ? 'selected' : '' }}>Todo el personal</option>
                            <option value="current" {{ $config->scope === 'current' ? 'selected' : '' }}>Solo personal filtrado actualmente</option>
                            <option value="active" {{ $config->scope === 'active' ? 'selected' : '' }}>Solo activos</option>
                            <option value="inactive" {{ $config->scope === 'inactive' ? 'selected' : '' }}>Solo inactivos</option>
                            <option value="supervisors" {{ $config->scope === 'supervisors' ? 'selected' : '' }}>Solo supervisores</option>
                            <option value="workers" {{ $config->scope === 'workers' ? 'selected' : '' }}>Solo trabajadores</option>
                            <option value="mine" {{ $config->scope === 'mine' ? 'selected' : '' }}>Solo una mina especifica</option>
                            <option value="mine_state" {{ $config->scope === 'mine_state' ? 'selected' : '' }}>Solo estado en mina</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Limite de registros</label>
                        <select class="form-control" name="limit" id="limitSelect">
                            <option value="100" {{ ($config->limit ?? 0) === 100 ? 'selected' : '' }}>Primeros 100</option>
                            <option value="250" {{ ($config->limit ?? 0) === 250 ? 'selected' : '' }}>Primeros 250</option>
                            <option value="500" {{ ($config->limit ?? 0) === 500 ? 'selected' : '' }}>Primeros 500</option>
                            <option value="1000" {{ ($config->limit ?? 0) === 1000 ? 'selected' : '' }}>Primeros 1000</option>
                            <option value="manual" {{ !in_array($config->limit, [null, 100, 250, 500, 1000], true) ? 'selected' : '' }}>Limite manual</option>
                            <option value="all" {{ $config->limit === null ? 'selected' : '' }}>Todos los resultados</option>
                        </select>
                    </div>
                    <div class="form-group" id="manualLimitGroup" style="display:none;">
                        <label class="form-label">Limite manual</label>
                        <input class="form-control" type="number" min="1" name="manual_limit" value="{{ !in_array($config->limit, [null, 100, 250, 500, 1000], true) ? $config->limit : '' }}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Busqueda</label>
                        <input class="form-control" name="search" value="{{ $config->search ?? '' }}" placeholder="Nombre, DNI, puesto...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <select class="form-control" name="estado">
                            <option value="">Todos</option>
                            <option value="activo" {{ $config->estado === 'activo' ? 'selected' : '' }}>Activos</option>
                            <option value="inactivo" {{ $config->estado === 'inactivo' ? 'selected' : '' }}>Inactivos</option>
                            <option value="cesado" {{ $config->estado === 'cesado' ? 'selected' : '' }}>Cesados</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipo</label>
                        <select class="form-control" name="tipo">
                            <option value="">Todos</option>
                            <option value="supervisor" {{ $config->tipo === 'supervisor' ? 'selected' : '' }}>Supervisores</option>
                            <option value="trabajador" {{ $config->tipo === 'trabajador' ? 'selected' : '' }}>Trabajadores</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Mina</label>
                        <select class="form-control" name="mina">
                            <option value="">Todas</option>
                            @foreach($minas as $mina)
                                <option value="{{ $mina['id'] }}" {{ $config->mina === $mina['id'] ? 'selected' : '' }}>{{ $mina['nombre'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado en mina</label>
                        <select class="form-control" name="mina_estado">
                            <option value="">Todos</option>
                            <option value="habilitado" {{ $config->minaEstado === 'habilitado' ? 'selected' : '' }}>Habilitado</option>
                            <option value="proceso" {{ $config->minaEstado === 'proceso' ? 'selected' : '' }}>En proceso</option>
                            <option value="no_habilitado" {{ $config->minaEstado === 'no_habilitado' ? 'selected' : '' }}>No habilitado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contrato</label>
                        <select class="form-control" name="contrato">
                            <option value="">Todos</option>
                            <option value="REG" {{ $config->contrato === 'REG' ? 'selected' : '' }}>Regimen</option>
                            <option value="FIJO" {{ $config->contrato === 'FIJO' ? 'selected' : '' }}>Fijo</option>
                            <option value="INTER" {{ $config->contrato === 'INTER' ? 'selected' : '' }}>Intermitente</option>
                            <option value="INDET" {{ $config->contrato === 'INDET' ? 'selected' : '' }}>Indeterminado</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Ordenar por</label>
                        <select class="form-control" name="sort">
                            <option value="nombre" {{ ($config->sort ?? 'nombre') === 'nombre' ? 'selected' : '' }}>Nombre</option>
                            <option value="dni" {{ $config->sort === 'dni' ? 'selected' : '' }}>DNI</option>
                            <option value="mina" {{ $config->sort === 'mina' ? 'selected' : '' }}>Mina</option>
                            <option value="contrato" {{ $config->sort === 'contrato' ? 'selected' : '' }}>Contrato</option>
                            <option value="fecha_ingreso" {{ $config->sort === 'fecha_ingreso' ? 'selected' : '' }}>Fecha ingreso</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Direccion</label>
                        <select class="form-control" name="order">
                            <option value="asc" {{ $config->order === 'asc' ? 'selected' : '' }}>Ascendente</option>
                            <option value="desc" {{ $config->order === 'desc' ? 'selected' : '' }}>Descendente</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-outline" style="width:100%; justify-content:center;">Actualizar resumen</button>
                    </div>
                </div>

                <div class="card" style="margin-top:14px; margin-bottom:0;">
                    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                        <span class="card-title">Columnas disponibles</span>
                        <div style="display:flex; gap:8px;">
                            <button type="button" id="selectAllCols" class="btn btn-outline btn-sm">Seleccionar todo</button>
                            <button type="button" id="resetRecommendedCols" class="btn btn-outline btn-sm">Restablecer recomendadas</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mines-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                            @foreach($availableColumns as $key => $label)
                                <label class="mine-selection-item" style="cursor:pointer;">
                                    <span class="mine-checkbox" style="width:100%;">
                                        <input type="checkbox" name="columns[]" value="{{ $key }}" {{ in_array($key, $config->columns, true) ? 'checked' : '' }}>
                                        <span class="mine-checkbox-label" style="width:100%;">
                                            <span class="checkbox-custom"></span>
                                            <span class="checkbox-text">{{ $label }}</span>
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <small id="columnWarning" style="display:none; color:#b91c1c; font-weight:600;">Selecciona al menos una columna para exportar.</small>
                    </div>
                </div>
            </form>

            <div class="card" style="margin-top:12px; margin-bottom:12px;">
                <div class="card-header">
                    <span class="card-title">Resumen previo</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Registros a exportar</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ number_format($preview['records']) }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Filtros aplicados</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ $preview['filtersLabel'] }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Columnas seleccionadas</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ $preview['columnsCount'] }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Orden</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ $preview['orderLabel'] }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('personal.export.download') }}" id="exportSubmitForm">
                @csrf
                <div class="form-actions" style="justify-content:flex-end;">
                    <button type="submit" id="exportBtn" class="btn btn-primary" onclick="this.disabled=true; this.innerHTML='<span class=\'spinner-border spinner-border-sm\' role=\'status\'></span> Exportando...';">
                        <i class='fas fa-file-excel' style='margin-right:6px;'></i>Exportar personal en Excel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sección Fichas (colapsable) -->
    <div id="fichasExportSection" class="card" style="margin-bottom:12px; display:none;">
        <div class="card-header" style="cursor:pointer;" onclick="toggleSection('fichasExportSection', document.getElementById('btnFichasExport'))">
            <span class="card-title">Exportar fichas</span>
            <i class='fas fa-chevron-up' style='float:right;'></i>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('personal.export.form') }}" id="fichaConfigForm">
                @foreach($currentFilters as $key => $value)
                    @if(filled($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Formato</label>
                        <select class="form-control" name="ficha_format" id="fichaFormat">
                            <option value="excel" {{ request('ficha_format', 'excel') === 'excel' ? 'selected' : '' }}>Excel</option>
                            <option value="pdf" {{ request('ficha_format') === 'pdf' ? 'selected' : '' }}>PDF masivo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Limite</label>
                        <select class="form-control" name="ficha_limit" id="fichaLimit">
                            <option value="25" {{ (string) request('ficha_limit', '100') === '25' ? 'selected' : '' }}>25</option>
                            <option value="50" {{ (string) request('ficha_limit') === '50' ? 'selected' : '' }}>50</option>
                            <option value="100" {{ (string) request('ficha_limit', '100') === '100' ? 'selected' : '' }}>100</option>
                            <option value="250" {{ (string) request('ficha_limit') === '250' ? 'selected' : '' }}>250</option>
                            <option value="500" {{ (string) request('ficha_limit') === '500' ? 'selected' : '' }}>500</option>
                            <option value="1000" {{ (string) request('ficha_limit') === '1000' ? 'selected' : '' }}>1000</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-outline" style="width:100%; justify-content:center;">Actualizar resumen</button>
                    </div>
                </div>

                <div id="fichaExcelColumnsWrap">
                    <div class="card" style="margin-top:14px; margin-bottom:0;">
                        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                            <span class="card-title">Columnas de ficha en Excel</span>
                            <div style="display:flex; gap:8px;">
                                <button type="button" id="selectAllFichaCols" class="btn btn-outline btn-sm">Seleccionar todo</button>
                                <button type="button" id="resetRecommendedFichaCols" class="btn btn-outline btn-sm">Restablecer recomendadas</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mines-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                                @foreach($availableFichaColumns as $key => $label)
                                    <label class="mine-selection-item" style="cursor:pointer;">
                                        <span class="mine-checkbox" style="width:100%;">
                                            <input type="checkbox" name="ficha_columns[]" value="{{ $key }}" {{ in_array($key, $selectedFichaColumns, true) ? 'checked' : '' }}>
                                            <span class="mine-checkbox-label" style="width:100%;">
                                                <span class="checkbox-custom"></span>
                                                <span class="checkbox-text">{{ $label }}</span>
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <small id="fichaColumnWarning" style="display:none; color:#b91c1c; font-weight:600;">Selecciona al menos una columna para exportar las fichas en Excel.</small>
                        </div>
                    </div>
                </div>
            </form>

            <div class="card" style="margin-top:12px; margin-bottom:12px; border-color:#fde68a; background:#fffbeb;">
                <div class="card-body" style="padding:12px 14px; color:#92400e;">
                    El PDF masivo trabaja por partes para no colgar la pagina. Limite maximo por corrida: {{ $fichaPreview['maxPdfRecords'] }} fichas. Cada avance procesa {{ $fichaPreview['chunkSize'] }} fichas.
                </div>
            </div>

            <div class="card" style="margin-bottom:12px;">
                <div class="card-header">
                    <span class="card-title">Resumen de fichas</span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fichas encontradas</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ number_format($fichaPreview['records']) }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Columnas seleccionadas</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ $fichaPreview['columnsCount'] }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Proceso PDF</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ $fichaPreview['chunkSize'] }} por bloque</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tope PDF</label>
                            <div class="form-control" style="display:flex; align-items:center;">{{ $fichaPreview['maxPdfRecords'] }} por corrida</div>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('personal.fichas.export.excel') }}" id="fichaExcelSubmitForm" style="display:none;">
                @csrf
            </form>

            <div class="form-actions" style="justify-content:flex-end; gap:8px;">
                <button type="button" id="fichaExportExcelBtn" class="btn btn-outline" onclick="this.disabled=true; this.innerHTML='<span class=\'spinner-border spinner-border-sm\'></span> Exportando...'; setTimeout(() => this.disabled = false, 3000);">
                    <i class='fas fa-file-excel' style='margin-right:6px;'></i>Exportar fichas en Excel
                </button>
                <button type="button" id="fichaExportPdfBtn" class="btn btn-primary" onclick="this.disabled=true; this.innerHTML='<span class=\'spinner-border spinner-border-sm\'></span> Procesando PDF...';">
                    <i class='fas fa-file-pdf' style='margin-right:6px;'></i>Exportar fichas en PDF
                </button>
            </div>

            <div id="fichaPdfProgressCard" class="card" style="display:none; margin-top:14px;">
                <div class="card-header">
                    <span class="card-title">Progreso de PDF</span>
                </div>
                <div class="card-body">
                    <div style="width:100%; height:14px; border-radius:999px; background:#e2e8f0; overflow:hidden;">
                        <div id="fichaPdfProgressBar" style="width:0%; height:100%; background:#0d9488; transition:width 0.2s ease;"></div>
                    </div>
                    <div id="fichaPdfProgressText" style="margin-top:10px; color:#475569;">Preparando exportacion...</div>
                    <div id="fichaPdfDownloadWrap" style="display:none; margin-top:12px;">
                        <a id="fichaPdfDownloadLink" href="#" class="btn btn-primary">Descargar ZIP de fichas</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Función global para desplegar/colapsar secciones
function toggleSection(sectionId, button) {
    const section = document.getElementById(sectionId);
    if (!section) return;
    const icon = button ? button.querySelector('i.fas.fa-chevron-down, i.fas.fa-chevron-up') : null;

    if (section.style.display === 'none' || section.style.display === '') {
        section.style.display = 'block';
        if (icon) {
            icon.style.transform = 'rotate(180deg)';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        }
    } else {
        section.style.display = 'none';
        if (icon) {
            icon.style.transform = 'rotate(0deg)';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const exportConfigForm = document.getElementById('exportConfigForm');
    const exportSubmitForm = document.getElementById('exportSubmitForm');
    const selectAllBtn = document.getElementById('selectAllCols');
    const resetRecommendedBtn = document.getElementById('resetRecommendedCols');
    const limitSelect = document.getElementById('limitSelect');
    const manualLimitGroup = document.getElementById('manualLimitGroup');
    const exportBtn = document.getElementById('exportBtn');
    const columnWarning = document.getElementById('columnWarning');

    const fichaConfigForm = document.getElementById('fichaConfigForm');
    const fichaFormat = document.getElementById('fichaFormat');
    const fichaExcelColumnsWrap = document.getElementById('fichaExcelColumnsWrap');
    const fichaColumnWarning = document.getElementById('fichaColumnWarning');
    const fichaExcelSubmitForm = document.getElementById('fichaExcelSubmitForm');
    const fichaExportExcelBtn = document.getElementById('fichaExportExcelBtn');
    const fichaExportPdfBtn = document.getElementById('fichaExportPdfBtn');
    const fichaPdfProgressCard = document.getElementById('fichaPdfProgressCard');
    const fichaPdfProgressBar = document.getElementById('fichaPdfProgressBar');
    const fichaPdfProgressText = document.getElementById('fichaPdfProgressText');
    const fichaPdfDownloadWrap = document.getElementById('fichaPdfDownloadWrap');
    const fichaPdfDownloadLink = document.getElementById('fichaPdfDownloadLink');

    const recommendedColumns = @json($recommendedColumns);
    const recommendedFichaColumns = @json($recommendedFichaColumns);
    const pdfStartUrl = @json(route('personal.fichas.export.pdf.start'));
    const pdfProcessUrlTemplate = @json(route('personal.fichas.export.pdf.process', '__JOB__'));

    function syncManualLimitVisibility() {
        if (!limitSelect || !manualLimitGroup) return;
        manualLimitGroup.style.display = limitSelect.value === 'manual' ? 'block' : 'none';
    }

    function selectedValues(form, selector) {
        return Array.from(form.querySelectorAll(selector)).filter(function(el) { return el.checked; }).map(function (el) {
            return el.value;
        });
    }

    function appendHiddenInput(form, inputName, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = inputName;
        input.value = value;
        form.appendChild(input);
    }

    function syncExportFormWithConfig() {
        if (!exportSubmitForm) return;
        exportSubmitForm.querySelectorAll('input[type="hidden"]:not([name="_token"])').forEach(function (el) { el.remove(); });

        const fields = new FormData(exportConfigForm);
        fields.forEach(function (value, key) {
            if (value === null || value === undefined || value === '') {
                return;
            }
            appendHiddenInput(exportSubmitForm, key, String(value));
        });

        const selected = selectedValues(exportConfigForm, 'input[name="columns[]"]');
        const hasColumns = selected.length > 0;
        if (columnWarning) {
            columnWarning.style.display = hasColumns ? 'none' : 'block';
        }
        if (exportBtn) {
            exportBtn.disabled = !hasColumns;
        }
    }

    function syncFichaFormatState() {
        const isExcel = !fichaFormat || fichaFormat.value === 'excel';
        if (fichaExcelColumnsWrap) {
            fichaExcelColumnsWrap.style.display = isExcel ? 'block' : 'none';
        }
    }

    function syncFichaButtons() {
        const selected = selectedValues(fichaConfigForm, 'input[name="ficha_columns[]"]');
        const hasColumns = selected.length > 0;
        if (fichaColumnWarning) {
            fichaColumnWarning.style.display = hasColumns || (fichaFormat && fichaFormat.value === 'pdf') ? 'none' : 'block';
        }
        if (fichaExportExcelBtn) {
            fichaExportExcelBtn.disabled = !hasColumns;
        }
    }

    function buildFichaPayload() {
        return new FormData(fichaConfigForm);
    }

    async function processPdfJob(jobId) {
        const url = pdfProcessUrlTemplate.replace('__JOB__', jobId);
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('No se pudo procesar el siguiente bloque de PDFs.');
        }

        return response.json();
    }

    function renderPdfProgress(payload) {
        if (!fichaPdfProgressCard) return;
        fichaPdfProgressCard.style.display = 'block';
        fichaPdfProgressBar.style.width = (payload.percent || 0) + '%';
        fichaPdfProgressText.textContent = 'Procesadas ' + (payload.processed || 0) + ' de ' + (payload.total || 0) + ' fichas (' + (payload.percent || 0) + '%).';

        if (payload.status === 'completed' && payload.download_url) {
            fichaPdfDownloadWrap.style.display = 'block';
            fichaPdfDownloadLink.href = payload.download_url;
            fichaPdfProgressText.textContent = 'Proceso terminado. Ya puedes descargar el ZIP de fichas.';
            if (fichaExportPdfBtn) fichaExportPdfBtn.disabled = false;
        }
    }

    async function runPdfExport() {
        if (fichaExportPdfBtn) fichaExportPdfBtn.disabled = true;
        if (fichaPdfDownloadWrap) fichaPdfDownloadWrap.style.display = 'none';
        if (fichaPdfProgressCard) fichaPdfProgressCard.style.display = 'block';
        if (fichaPdfProgressBar) fichaPdfProgressBar.style.width = '0%';
        if (fichaPdfProgressText) fichaPdfProgressText.textContent = 'Preparando exportacion...';

        const startResponse = await fetch(pdfStartUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: buildFichaPayload()
        });

        if (!startResponse.ok) {
            throw new Error('No se pudo iniciar la exportacion en PDF.');
        }

        let payload = await startResponse.json();
        renderPdfProgress(payload);

        while (payload.status !== 'completed') {
            payload = await processPdfJob(payload.job_id);
            renderPdfProgress(payload);
        }
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            exportConfigForm.querySelectorAll('input[name="columns[]"]').forEach(function (el) {
                el.checked = true;
            });
            syncExportFormWithConfig();
        });
    }

    if (resetRecommendedBtn) {
        resetRecommendedBtn.addEventListener('click', function () {
            exportConfigForm.querySelectorAll('input[name="columns[]"]').forEach(function (el) {
                el.checked = recommendedColumns.includes(el.value);
            });
            syncExportFormWithConfig();
        });
    }

    const selectAllFichaCols = document.getElementById('selectAllFichaCols');
    const resetRecommendedFichaCols = document.getElementById('resetRecommendedFichaCols');

    if (selectAllFichaCols) {
        selectAllFichaCols.addEventListener('click', function () {
            fichaConfigForm.querySelectorAll('input[name="ficha_columns[]"]').forEach(function (el) {
                el.checked = true;
            });
            syncFichaButtons();
        });
    }

    if (resetRecommendedFichaCols) {
        resetRecommendedFichaCols.addEventListener('click', function () {
            fichaConfigForm.querySelectorAll('input[name="ficha_columns[]"]').forEach(function (el) {
                el.checked = recommendedFichaColumns.includes(el.value);
            });
            syncFichaButtons();
        });
    }

    exportConfigForm.querySelectorAll('input[name="columns[]"]').forEach(function (el) {
        el.addEventListener('change', syncExportFormWithConfig);
    });

    fichaConfigForm.querySelectorAll('input[name="ficha_columns[]"]').forEach(function (el) {
        el.addEventListener('change', syncFichaButtons);
    });

    if (limitSelect) {
        limitSelect.addEventListener('change', syncManualLimitVisibility);
    }

    if (fichaFormat) {
        fichaFormat.addEventListener('change', function () {
            syncFichaFormatState();
            syncFichaButtons();
        });
    }

    if (exportSubmitForm) {
        exportSubmitForm.addEventListener('submit', function (event) {
            syncExportFormWithConfig();
            if (exportBtn && exportBtn.disabled) {
                event.preventDefault();
            }
        });
    }

    if (fichaExportExcelBtn) {
        fichaExportExcelBtn.addEventListener('click', function () {
            syncFichaButtons();
            if (fichaExportExcelBtn.disabled) {
                return;
            }

            fichaExcelSubmitForm.querySelectorAll('input[type="hidden"]:not([name="_token"])').forEach(function (el) { el.remove(); });
            const fields = new FormData(fichaConfigForm);
            fields.forEach(function (value, key) {
                if (value === null || value === undefined || value === '') {
                    return;
                }
                appendHiddenInput(fichaExcelSubmitForm, key, String(value));
            });

            fichaExcelSubmitForm.submit();
        });
    }

    if (fichaExportPdfBtn) {
        fichaExportPdfBtn.addEventListener('click', async function () {
            try {
                await runPdfExport();
            } catch (error) {
                if (fichaExportPdfBtn) fichaExportPdfBtn.disabled = false;
                if (fichaPdfProgressText) fichaPdfProgressText.textContent = error.message || 'No se pudo completar la exportacion PDF.';
            }
        });
    }

    syncManualLimitVisibility();
    syncExportFormWithConfig();
    syncFichaFormatState();
    syncFichaButtons();
});
</script>
@endpush
