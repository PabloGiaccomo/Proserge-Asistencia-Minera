@extends('layouts.app')

@section('title', 'Exportar Personal - Proserge')

@section('content')
<div class="module-page personal-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Exportar Personal</h1>
                <p class="page-subtitle">Configura qué información deseas incluir en el archivo Excel</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="card" style="border-color:#fecaca; background:#fff1f2; margin-bottom:12px;">
            <div class="card-body" style="padding:12px 14px; color:#991b1b;">{{ session('error') }}</div>
        </div>
    @endif

    <form method="GET" action="{{ route('personal.export.form') }}" id="exportConfigForm">
        <div class="card" style="margin-bottom:12px;">
            <div class="card-header">
                <span class="card-title">Configuración de Exportación</span>
            </div>
            <div class="card-body">
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
                            <option value="mine" {{ $config->scope === 'mine' ? 'selected' : '' }}>Solo una mina específica</option>
                            <option value="mine_state" {{ $config->scope === 'mine_state' ? 'selected' : '' }}>Solo estado en mina</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Límite de registros</label>
                        <select class="form-control" name="limit" id="limitSelect">
                            <option value="100" {{ ($config->limit ?? 0) === 100 ? 'selected' : '' }}>Primeros 100</option>
                            <option value="250" {{ ($config->limit ?? 0) === 250 ? 'selected' : '' }}>Primeros 250</option>
                            <option value="500" {{ ($config->limit ?? 0) === 500 ? 'selected' : '' }}>Primeros 500</option>
                            <option value="1000" {{ ($config->limit ?? 0) === 1000 ? 'selected' : '' }}>Primeros 1000</option>
                            <option value="manual" {{ !in_array($config->limit, [null,100,250,500,1000], true) ? 'selected' : '' }}>Límite manual</option>
                            <option value="all" {{ $config->limit === null ? 'selected' : '' }}>Todos los resultados</option>
                        </select>
                    </div>
                    <div class="form-group" id="manualLimitGroup" style="display:none;">
                        <label class="form-label">Límite manual</label>
                        <input class="form-control" type="number" min="1" name="manual_limit" value="{{ !in_array($config->limit, [null,100,250,500,1000], true) ? $config->limit : '' }}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Búsqueda</label>
                        <input class="form-control" name="search" value="{{ $config->search ?? '' }}" placeholder="Nombre, DNI, puesto...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <select class="form-control" name="estado">
                            <option value="">Todos</option>
                            <option value="activo" {{ $config->estado === 'activo' ? 'selected' : '' }}>Activos</option>
                            <option value="inactivo" {{ $config->estado === 'inactivo' ? 'selected' : '' }}>Inactivos</option>
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
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contrato</label>
                        <select class="form-control" name="contrato">
                            <option value="">Todos</option>
                            <option value="REG" {{ $config->contrato === 'REG' ? 'selected' : '' }}>Régimen</option>
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
                        <label class="form-label">Dirección</label>
                        <select class="form-control" name="order">
                            <option value="asc" {{ $config->order === 'asc' ? 'selected' : '' }}>Ascendente</option>
                            <option value="desc" {{ $config->order === 'desc' ? 'selected' : '' }}>Descendente</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-outline" style="width:100%; justify-content:center;">Actualizar resumen</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:12px;">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <span class="card-title">Columnas a exportar</span>
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

    <div class="card" style="margin-bottom:12px;">
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

            @if($preview['records'] === 0)
                <div style="margin-top:10px; padding:10px 12px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; border-radius:10px;">
                    No hay resultados para exportar con los filtros elegidos.
                </div>
            @elseif($preview['records'] > 1000)
                <div style="margin-top:10px; padding:10px 12px; border:1px solid #fecaca; background:#fff1f2; color:#991b1b; border-radius:10px;">
                    Advertencia: estás por exportar una cantidad alta de registros ({{ number_format($preview['records']) }}).
                </div>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('personal.export.download') }}" id="exportSubmitForm">
        @csrf

        <div class="form-actions" style="justify-content:space-between;">
            <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" id="exportBtn" class="btn btn-primary">Exportar Excel</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const configForm = document.getElementById('exportConfigForm');
    const submitForm = document.getElementById('exportSubmitForm');
    const selectAllBtn = document.getElementById('selectAllCols');
    const resetRecommendedBtn = document.getElementById('resetRecommendedCols');
    const limitSelect = document.getElementById('limitSelect');
    const manualLimitGroup = document.getElementById('manualLimitGroup');
    const exportBtn = document.getElementById('exportBtn');
    const columnWarning = document.getElementById('columnWarning');

    const recommendedColumns = @json($recommendedColumns);

    function syncManualLimitVisibility() {
        if (!limitSelect || !manualLimitGroup) return;
        manualLimitGroup.style.display = limitSelect.value === 'manual' ? 'block' : 'none';
    }

    function getConfigColumns() {
        return Array.from(configForm.querySelectorAll('input[name="columns[]"]:checked')).map(function (el) {
            return el.value;
        });
    }

    function appendHiddenInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        submitForm.appendChild(input);
    }

    function syncExportFormWithConfig() {
        submitForm.querySelectorAll('input[type="hidden"]:not([name="_token"])').forEach(function (el) { el.remove(); });

        const fields = new FormData(configForm);
        fields.forEach(function (value, key) {
            if (value === null || value === undefined || value === '') {
                return;
            }

            appendHiddenInput(key, String(value));
        });

        const selected = getConfigColumns();

        const hasColumns = selected.length > 0;
        if (columnWarning) {
            columnWarning.style.display = hasColumns ? 'none' : 'block';
        }
        if (exportBtn) {
            exportBtn.disabled = !hasColumns;
        }
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            configForm.querySelectorAll('input[name="columns[]"]').forEach(function (el) {
                el.checked = true;
            });
            syncExportFormWithConfig();
        });
    }

    if (resetRecommendedBtn) {
        resetRecommendedBtn.addEventListener('click', function () {
            configForm.querySelectorAll('input[name="columns[]"]').forEach(function (el) {
                el.checked = recommendedColumns.includes(el.value);
            });
            syncExportFormWithConfig();
        });
    }

    configForm.querySelectorAll('input[name="columns[]"]').forEach(function (el) {
        el.addEventListener('change', syncExportFormWithConfig);
    });

    if (limitSelect) {
        limitSelect.addEventListener('change', syncManualLimitVisibility);
    }

    submitForm.addEventListener('submit', function (event) {
        syncExportFormWithConfig();
        if (exportBtn && exportBtn.disabled) {
            event.preventDefault();
        }
    });

    syncManualLimitVisibility();
    syncExportFormWithConfig();
});
</script>
@endpush
