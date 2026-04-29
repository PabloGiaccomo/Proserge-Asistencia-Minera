@extends('layouts.app')

@section('title', 'RQ Mina - Proserge')

@php
$items = $data['items'] ?? [];
$minaOptions = $data['minaOptions'] ?? [];
$estadoOptions = $data['estadoOptions'] ?? [];
$creadores = $data['creadores'] ?? [];
$activeFilters = $data['filters'] ?? [];
$pg = $data['pagination'] ?? ['current_page' => 1, 'total_pages' => 1, 'per_page' => 10, 'total' => count($items)];
$baseQuery = request()->except(['page', 'per_page']);
$baseUrl = route('rq-mina.index');

$calcPuestos = static function (array $detalle): int {
    return count($detalle);
};

$calcTotal = static function (array $detalle): int {
    return (int) array_sum(array_column($detalle, 'cantidad'));
};
@endphp

@section('content')
<div class="rq-mina-page">
    <div class="page-header-custom">
        <div>
            <h1 class="page-title">RQ Mina</h1>
            <p class="page-subtitle">Requerimientos de personal de mina</p>
        </div>
        <button type="button" class="btn-nuevoRQ" onclick="openModalRQ('create')">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Nuevo RQ
        </button>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="filters-bar">
        <form method="GET" action="{{ route('rq-mina.index') }}" class="filters-form" id="rqMinaFiltersForm">
            <input type="hidden" name="page" value="{{ (int) request('page', 1) }}" data-reset-page>
            <input type="hidden" name="per_page" value="{{ (int) request('per_page', ($pg['per_page'] ?? 10)) }}">

            <div class="filters-head">
                <span>Filtros de búsqueda</span>
                <button type="button" id="rqMinaToggleFilters" class="btn-filter-toggle" aria-expanded="true" aria-label="Ocultar filtros" title="Ocultar filtros">
                    <span id="rqMinaToggleArrow" aria-hidden="true">▲</span>
                </button>
            </div>

            <div id="rqMinaFiltersBody">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                        <div class="w-full md:max-w-3xl">
                            <label class="filter-label">Buscador general</label>
                            <input
                                type="text"
                                name="q"
                                id="rqMinaSearch"
                                class="filter-input"
                                value="{{ $activeFilters['q'] ?? '' }}"
                                placeholder="Buscar por mina, área, creador o estado..."
                                autocomplete="off"
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('rq-mina.index') }}" class="btn-filter-outline whitespace-nowrap">Limpiar</a>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="filter-group">
                            <label class="filter-label">Mina</label>
                            <select name="mina_id" class="filter-select" data-auto-submit>
                                <option value="">Todas las minas</option>
                                @foreach($minaOptions as $m)
                                    <option value="{{ $m['id'] ?? '' }}" {{ ($activeFilters['mina_id'] ?? '') === ($m['id'] ?? '') ? 'selected' : '' }}>{{ $m['nombre'] ?? '-' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Estado</label>
                            <select name="estado" class="filter-select" data-auto-submit>
                                <option value="">Todos los estados</option>
                                @foreach($estadoOptions as $e)
                                    <option value="{{ $e }}" {{ ($activeFilters['estado'] ?? '') === $e ? 'selected' : '' }}>{{ ucfirst($e) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Creador</label>
                            <select name="created_by_usuario_id" class="filter-select" data-auto-submit>
                                <option value="">Todos los creadores</option>
                                @foreach($creadores as $c)
                                    <option value="{{ $c['id'] ?? '' }}" {{ ($activeFilters['created_by_usuario_id'] ?? '') === ($c['id'] ?? '') ? 'selected' : '' }}>{{ $c['nombre'] ?? '-' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha inicio desde</label>
                            <input type="date" name="fecha_inicio_desde" class="filter-input" value="{{ $activeFilters['fecha_inicio_desde'] ?? '' }}" data-auto-submit>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha inicio hasta</label>
                            <input type="date" name="fecha_inicio_hasta" class="filter-input" value="{{ $activeFilters['fecha_inicio_hasta'] ?? '' }}" data-auto-submit>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha fin desde</label>
                            <input type="date" name="fecha_fin_desde" class="filter-input" value="{{ $activeFilters['fecha_fin_desde'] ?? '' }}" data-auto-submit>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha fin hasta</label>
                            <input type="date" name="fecha_fin_hasta" class="filter-input" value="{{ $activeFilters['fecha_fin_hasta'] ?? '' }}" data-auto-submit>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rango rápido:</span>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="today">Hoy</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="week">Esta semana</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="month">Este mes</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="last30">Últimos 30 días</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="clear">Limpiar fecha</button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estado rápido:</span>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="">Todos</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="borrador">Borrador</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="enviado">Enviado</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="cerrado">Cerrado</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="cancelado">Cancelado</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header-list">
            <h2 class="card-title-list">Listado de RQ Mina</h2>
            <span class="card-badge">{{ (int) ($pg['total'] ?? count($items)) }} registros</span>
        </div>

        @if(empty($items))
            <div class="empty-state">
                <h3>Sin resultados para los filtros aplicados</h3>
                <p>Ajusta los filtros o usa Limpiar para ver nuevamente todo el listado.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="rq-table">
                    <thead>
                        <tr>
                            <th>Mina</th>
                            <th>Área</th>
                            <th>Fechas</th>
                            <th>Creador</th>
                            <th>Puestos</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $rq)
                            @php
                                $detalle = $rq['detalle'] ?? [];
                                $isBorrador = strtoupper((string) ($rq['estado'] ?? '')) === 'BORRADOR';
                                $fechaInicioRaw = (string) ($rq['fecha_inicio'] ?? '');
                                $fechaFinRaw = (string) ($rq['fecha_fin'] ?? '');
                                $fechaInicioFmt = $fechaInicioRaw !== ''
                                    ? \Carbon\Carbon::parse($fechaInicioRaw)->locale('es')->translatedFormat('d M Y')
                                    : '-';
                                $fechaFinFmt = $fechaFinRaw !== ''
                                    ? \Carbon\Carbon::parse($fechaFinRaw)->locale('es')->translatedFormat('d M Y')
                                    : '-';
                            @endphp
                            <tr>
                                <td>{{ $rq['mina'] ?? '-' }}</td>
                                <td>{{ $rq['area'] ?? '-' }}</td>
                                <td>
                                    <div class="inline-flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 min-w-[180px]">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mt-0.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                        </svg>
                                        <div class="leading-tight">
                                            <div class="text-[11px] uppercase tracking-wide text-slate-500">Inicio <span class="font-semibold text-slate-700">{{ $fechaInicioFmt }}</span></div>
                                            <div class="text-[11px] uppercase tracking-wide text-slate-500 mt-1">Fin <span class="font-semibold text-slate-700">{{ $fechaFinFmt }}</span></div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $rq['creador'] ?? '-' }}</td>
                                <td>{{ $calcPuestos($detalle) }}</td>
                                <td>{{ $calcTotal($detalle) }}</td>
                                <td>
                                    <span class="estado-badge {{ $rq['estado'] ?? 'borrador' }}">{{ ucfirst($rq['estado'] ?? 'borrador') }}</span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a href="{{ route('rq-mina.show', $rq['id']) }}" class="btn-row btn-row-outline">Ver</a>
                                        <button type="button" class="btn-row btn-row-outline" onclick="openModalRQ('edit', '{{ $rq['id'] }}')">Editar</button>
                                        <button type="button" class="btn-row btn-copy" onclick="openModalRQ('copy', '{{ $rq['id'] }}')">Copiar RQ</button>

                                        @if($isBorrador)
                                            <form method="POST" action="{{ route('rq-mina.enviar', $rq['id']) }}" onsubmit="return confirm('¿Enviar este RQ?');">
                                                @csrf
                                                <button type="submit" class="btn-row btn-send">Enviar</button>
                                            </form>

                                            <form method="POST" action="{{ route('rq-mina.destroy', $rq['id']) }}" onsubmit="return confirm('¿Eliminar este RQ? Esta acción no se puede deshacer.');">
                                                @csrf
                                                <button type="submit" class="btn-row btn-danger">Eliminar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @php
                $totalPages = (int) ($pg['total_pages'] ?? 1);
                $currentPage = (int) ($pg['current_page'] ?? 1);
                $perPage = (int) ($pg['per_page'] ?? 10);
                $total = (int) ($pg['total'] ?? count($items));
                $startItem = $total > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
                $endItem = min($currentPage * $perPage, $total);
                $maxVis = 7;
                $startPage = max(2, $currentPage - floor(($maxVis - 3) / 2));
                $endPage = min($totalPages - 1, $currentPage + floor(($maxVis - 3) / 2));
            @endphp

            <div class="table-footer">
                <div class="table-footer-top">
                    <div class="page-size">
                        <span>Mostrar</span>
                        <select id="rqMinaPageSize" onchange="changeRqMinaPageSize(this.value)">
                            @foreach([10, 25, 50, 100] as $opt)
                                <option value="{{ $opt }}" {{ $perPage === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                            @endforeach
                        </select>
                        <span>registros</span>
                    </div>

                    <div class="page-summary">
                        {{ $total === 0 ? '0 resultados' : 'Mostrando ' . $startItem . ' - ' . $endItem . ' de ' . $total }}
                    </div>

                </div>

                <div class="pagination-row">
                @if($totalPages > 1)
                    <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => max(1, $currentPage - 1), 'per_page' => $perPage])) }}" @if($currentPage === 1) aria-disabled="true" style="pointer-events:none;opacity:.5;" @endif>&lsaquo;</a>
                    <a class="{{ $currentPage === 1 ? 'active' : '' }}" href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => 1, 'per_page' => $perPage])) }}">1</a>

                    @if($startPage > 2)
                        <span>...</span>
                    @endif

                    @for($i = $startPage; $i <= $endPage; $i++)
                        <a class="{{ $currentPage === $i ? 'active' : '' }}" href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => $i, 'per_page' => $perPage])) }}">{{ $i }}</a>
                    @endfor

                    @if($endPage < $totalPages - 1)
                        <span>...</span>
                    @endif

                    @if($totalPages > 1)
                        <a class="{{ $currentPage === $totalPages ? 'active' : '' }}" href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => $totalPages, 'per_page' => $perPage])) }}">{{ $totalPages }}</a>
                    @endif
                    <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => min($totalPages, $currentPage + 1), 'per_page' => $perPage])) }}" @if($currentPage === $totalPages) aria-disabled="true" style="pointer-events:none;opacity:.5;" @endif>&rsaquo;</a>
                @endif
                </div>
            </div>
        @endif
    </div>
</div>

<!-- Modal Nuevo RQ Mina -->
<div class="modalrq-overlay" id="modalRQ" style="display: none;">
    <div class="modalrq-container">
        <div class="modalrq-header">
            <h2 class="modalrq-title" id="modalRQTitle">Nuevo RQ Mina</h2>
            <button type="button" class="modalrq-close" onclick="closeModalRQ()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form id="formRQ" class="modalrq-body" method="POST" action="{{ route('rq-mina.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethodOverride" value="POST">
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Mina *</label>
                        <select name="mina" id="inputMina" class="form-select" required>
                            <option value="">Seleccionar mina</option>
                            @foreach($minaOptions as $m)
                            <option value="{{ $m['nombre'] ?? '' }}">{{ $m['nombre'] ?? '-' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Área *</label>
                        <input type="text" name="area" id="inputArea" class="form-input" placeholder="Ej: C2, Operación Planta" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Fecha inicio *</label>
                        <input type="date" name="fecha_inicio" id="inputFechaInicio" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha fin *</label>
                        <input type="date" name="fecha_fin" id="inputFechaFin" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" id="inputObs" class="form-textarea" rows="3" placeholder="Descripción del requerimiento..."></textarea>
                </div>
            </div>
            <div class="detalle-section">
                <div class="detalle-header">
                    <h3 class="detalle-title">Detalle por puesto / cantidad</h3>
                    <button type="button" class="btn-addfila" onclick="addFila()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                       Agregar fila
                    </button>
                </div>
                <div class="detalle-lista" id="listaDetalle">
                    <div class="fila-detalle">
                        <input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" required>
                        <input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="1" required>
                        <button type="button" class="btn-removefila" onclick="this.closest('.fila-detalle').remove()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <div class="modalrq-footer">
            <button type="button" class="btn-cancelarrq" onclick="closeModalRQ()">Cancelar</button>
            <button type="submit" form="formRQ" class="btn-crear" id="modalRQSubmit">Crear BORRADOR</button>
        </div>
    </div>
</div>

<script>
const rqData = @json($items);
const rqStoreUrl = @json(route('rq-mina.store'));
const rqUpdateUrlTemplate = @json(route('rq-mina.update', ['id' => '__ID__']));

function createDetalleRow(puesto, cantidad) {
    return '<div class="fila-detalle"><input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" value="' + (puesto || '') + '" required><input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="' + (cantidad || 1) + '" required><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>';
}

function openModalRQ(mode = 'create', rqId = null) {
    document.getElementById('modalRQ').style.display = 'flex';
    const form = document.getElementById('formRQ');
    const title = document.getElementById('modalRQTitle');
    const submit = document.getElementById('modalRQSubmit');
    const methodOverride = document.getElementById('formMethodOverride');
    const detalle = document.getElementById('listaDetalle');

    form.reset();
    detalle.innerHTML = createDetalleRow('', 1);

    if (mode === 'edit' && rqId) {
        const rq = rqData.find(item => String(item.id) === String(rqId));
        if (!rq) return;

        title.textContent = 'Editar RQ Mina';
        submit.textContent = 'Guardar Cambios';
        form.action = rqUpdateUrlTemplate.replace('__ID__', String(rq.id));
        methodOverride.value = 'PUT';

        document.getElementById('inputMina').value = rq.mina || '';
        document.getElementById('inputArea').value = rq.area || '';
        document.getElementById('inputFechaInicio').value = rq.fecha_inicio || '';
        document.getElementById('inputFechaFin').value = rq.fecha_fin || '';
        document.getElementById('inputObs').value = rq.observaciones || '';

        detalle.innerHTML = '';
        const rqDetalle = Array.isArray(rq.detalle) && rq.detalle.length > 0 ? rq.detalle : [{ puesto: '', cantidad: 1 }];
        rqDetalle.forEach(item => {
            detalle.insertAdjacentHTML('beforeend', createDetalleRow(item.puesto, item.cantidad));
        });
    } else if (mode === 'copy' && rqId) {
        const rq = rqData.find(item => String(item.id) === String(rqId));
        if (!rq) return;

        title.textContent = 'Copiar RQ Mina';
        submit.textContent = 'Crear BORRADOR';
        form.action = rqStoreUrl;
        methodOverride.value = 'POST';

        document.getElementById('inputMina').value = rq.mina || '';
        document.getElementById('inputArea').value = rq.area || '';
        document.getElementById('inputFechaInicio').value = rq.fecha_inicio || '';
        document.getElementById('inputFechaFin').value = rq.fecha_fin || '';
        document.getElementById('inputObs').value = rq.observaciones || '';

        detalle.innerHTML = '';
        const rqDetalle = Array.isArray(rq.detalle) && rq.detalle.length > 0 ? rq.detalle : [{ puesto: '', cantidad: 1 }];
        rqDetalle.forEach(item => {
            detalle.insertAdjacentHTML('beforeend', createDetalleRow(item.puesto, item.cantidad));
        });
    } else {
        title.textContent = 'Nuevo RQ Mina';
        submit.textContent = 'Crear BORRADOR';
        form.action = rqStoreUrl;
        methodOverride.value = 'POST';
    }
}
function closeModalRQ() {
    document.getElementById('modalRQ').style.display = 'none';
}
function addFila() {
    const container = document.getElementById('listaDetalle');
    const row = document.createElement('div');
    row.className = 'fila-detalle';
    row.innerHTML = '<input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" required><input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="1" required><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    container.appendChild(row);
}
function saveRQ(e) {
    // Reserved for future custom validation hooks.
}

function initializeRQMinaAutoFilters() {
    const form = document.getElementById('rqMinaFiltersForm');
    if (!form) return;

    const pageInput = form.querySelector('[data-reset-page]');
    const searchInput = document.getElementById('rqMinaSearch');
    const autoSubmitInputs = form.querySelectorAll('[data-auto-submit]');
    const rangeButtons = form.querySelectorAll('[data-range]');
    const statusButtons = form.querySelectorAll('[data-status]');
    const estadoSelect = form.querySelector('select[name="estado"]');
    const fechaInicioDesde = form.querySelector('input[name="fecha_inicio_desde"]');
    const fechaInicioHasta = form.querySelector('input[name="fecha_inicio_hasta"]');
    const fechaFinDesde = form.querySelector('input[name="fecha_fin_desde"]');
    const fechaFinHasta = form.querySelector('input[name="fecha_fin_hasta"]');

    const resetPage = () => {
        if (pageInput) {
            pageInput.value = '1';
        }
    };

    form.addEventListener('submit', () => {
        resetPage();
    });

    const submitNow = () => {
        form.submit();
    };

    let searchDebounceTimer = null;
    const submitDebounced = () => {
        if (searchDebounceTimer) {
            clearTimeout(searchDebounceTimer);
        }
        searchDebounceTimer = setTimeout(() => {
            submitNow();
        }, 300);
    };

    const formatDateInput = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            resetPage();
            submitDebounced();
        });
    }

    autoSubmitInputs.forEach((input) => {
        input.addEventListener('change', () => {
            resetPage();
            submitNow();
        });
    });

    rangeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const now = new Date();
            const today = formatDateInput(now);

            if (button.dataset.range === 'today') {
                if (fechaInicioDesde) fechaInicioDesde.value = today;
                if (fechaInicioHasta) fechaInicioHasta.value = today;
                if (fechaFinDesde) fechaFinDesde.value = today;
                if (fechaFinHasta) fechaFinHasta.value = today;
            }

            if (button.dataset.range === 'week') {
                const start = new Date(now);
                const day = start.getDay();
                const diff = day === 0 ? -6 : 1 - day;
                start.setDate(start.getDate() + diff);

                const end = new Date(start);
                end.setDate(start.getDate() + 6);

                if (fechaInicioDesde) fechaInicioDesde.value = formatDateInput(start);
                if (fechaInicioHasta) fechaInicioHasta.value = formatDateInput(end);
                if (fechaFinDesde) fechaFinDesde.value = formatDateInput(start);
                if (fechaFinHasta) fechaFinHasta.value = formatDateInput(end);
            }

            if (button.dataset.range === 'month') {
                const start = new Date(now.getFullYear(), now.getMonth(), 1);
                const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);

                if (fechaInicioDesde) fechaInicioDesde.value = formatDateInput(start);
                if (fechaInicioHasta) fechaInicioHasta.value = formatDateInput(end);
                if (fechaFinDesde) fechaFinDesde.value = formatDateInput(start);
                if (fechaFinHasta) fechaFinHasta.value = formatDateInput(end);
            }

            if (button.dataset.range === 'last30') {
                const start = new Date(now);
                start.setDate(start.getDate() - 29);

                if (fechaInicioDesde) fechaInicioDesde.value = formatDateInput(start);
                if (fechaInicioHasta) fechaInicioHasta.value = today;
                if (fechaFinDesde) fechaFinDesde.value = formatDateInput(start);
                if (fechaFinHasta) fechaFinHasta.value = today;
            }

            if (button.dataset.range === 'clear') {
                if (fechaInicioDesde) fechaInicioDesde.value = '';
                if (fechaInicioHasta) fechaInicioHasta.value = '';
                if (fechaFinDesde) fechaFinDesde.value = '';
                if (fechaFinHasta) fechaFinHasta.value = '';
            }

            resetPage();
            submitNow();
        });
    });

    statusButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (estadoSelect) {
                estadoSelect.value = button.dataset.status || '';
            }

            resetPage();
            submitNow();
        });
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModalRQ(); });
document.getElementById('modalRQ').addEventListener('click', e => { if (e.target.id === 'modalRQ') closeModalRQ(); });

document.addEventListener('DOMContentLoaded', function () {
    initializeRQMinaAutoFilters();

    if (window.ProsergeUI) {
        window.ProsergeUI.initCollapsiblePanel({
            toggleButtonId: 'rqMinaToggleFilters',
            panelBodyId: 'rqMinaFiltersBody',
            iconElementId: 'rqMinaToggleArrow',
            expandedIcon: '▲',
            collapsedIcon: '▼',
            expandedLabel: 'Ocultar filtros',
            collapsedLabel: 'Mostrar filtros',
        });
    }
});

function goToRqMinaPage() {
    const input = document.getElementById('rqMinaPageJump');
    if (!input) return;

    const totalPages = Number(input.max || 1);
    const page = Math.max(1, Math.min(totalPages, Number(input.value || 1)));
    const params = new URLSearchParams(@json($baseQuery));
    params.set('page', String(page));
    params.set('per_page', String({{ (int) ($pg['per_page'] ?? 10) }}));
    window.location.href = '{{ $baseUrl }}?' + params.toString();
}

function changeRqMinaPageSize(size) {
    const params = new URLSearchParams(@json($baseQuery));
    params.set('page', '1');
    params.set('per_page', String(size || 10));
    window.location.href = '{{ $baseUrl }}?' + params.toString();
}
</script>

@endsection
