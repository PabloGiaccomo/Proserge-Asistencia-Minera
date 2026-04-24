@extends('layouts.app')

@section('title', 'Personal - Proserge')

@section('content')
@php
    $activeFilterCount = collect([
        request('estado'),
        request('tipo'),
        request('mina'),
        request('mina_estado'),
        request('contrato'),
        request('sort') && request('sort') !== 'nombre' ? request('sort') : null,
    ])->filter(fn ($value) => filled($value))->count();
@endphp
<style>
.acciones-dropdown a.accion-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    color: #334155;
    text-decoration: none;
    font-size: 14px;
    transition: background-color 0.15s ease;
}
.acciones-dropdown a.accion-item:hover {
    background-color: #f1f5f9;
    color: #0d9488;
}
.acciones-dropdown .accion-divider {
    height: 1px;
    background-color: #e2e8f0;
    margin: 6px 0;
}
/* Filter Panel Compact */
.filter-panel-compact {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 12px;
}
.filter-panel-compact-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-compact-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 120px;
}
.filter-compact-group.filter-compact-actions {
    min-width: auto;
    margin-left: auto;
}
.filter-compact-label {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.filter-compact-select {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    color: #334155;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.filter-compact-select:hover {
    border-color: #cbd5e1;
}
.filter-compact-select:focus {
    outline: none;
    border-color: #19D3C5;
    box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.1);
}
.filter-chips-compact {
    display: flex;
    gap: 4px;
}
.chip-compact {
    padding: 5px 9px;
    font-size: 11px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    font-family: inherit;
}
.chip-compact:hover {
    background: #e2e8f0;
    color: #334155;
}
.chip-compact.active {
    background: #07142A;
    color: #fff;
    border-color: #07142A;
}
/* Chips con color - Estado */
.chip-compact.chip-activo.active {
    background: #10b981;
    border-color: #10b981;
    color: #fff;
}
.chip-compact.chip-inactivo.active {
    background: #ef4444;
    border-color: #ef4444;
    color: #fff;
}
/* Chips con color - Tipo */
.chip-compact.chip-supervisor.active {
    background: #8b5cf6;
    border-color: #8b5cf6;
    color: #fff;
}
.chip-compact.chip-trabajador.active {
    background: #0ea5e9;
    border-color: #0ea5e9;
    color: #fff;
}
/* Chips con color - Estado Mina */
.chip-compact.chip-habilitado.active {
    background: #22c55e;
    border-color: #22c55e;
    color: #fff;
}
.chip-compact.chip-proceso.active {
    background: #f59e0b;
    border-color: #f59e0b;
    color: #fff;
}
/* Labels con icono */
.filter-compact-label {
    display: flex;
    align-items: center;
    gap: 4px;
}
.btn-limpiar {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    font-size: 12px;
    color: #64748b;
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.15s ease;
}
.btn-limpiar:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #dc2626;
}

/* Personal index refinements */
.personal-page .page-header {
    margin-bottom: 8px;
}

.personal-page .page-header-top {
    align-items: center;
    gap: 10px;
}

.personal-page .page-title {
    margin-bottom: 0;
    font-size: 24px;
    line-height: 1.1;
}

.personal-page .page-subtitle {
    display: none;
}

.personal-page .page-actions {
    gap: 6px;
}

.personal-page .toolbar-search {
    margin-top: 8px;
    margin-bottom: 10px;
}

.personal-page .toolbar-search .simple-search-input {
    height: 40px;
    padding-top: 8px;
    padding-bottom: 8px;
    border-radius: 10px;
}

.personal-page .card-header {
    padding-top: 14px;
    padding-bottom: 14px;
}

.personal-page .card-body {
    padding-top: 14px;
}

.personal-page .person-card {
    padding: 14px;
    border-radius: 14px;
}

.personal-page .person-badges {
    gap: 4px;
    margin-bottom: 0;
}

.personal-page .person-badge {
    padding: 3px 8px;
    font-size: 10px;
}

.personal-page .person-badge.mine-extra-count {
    background: #f8fafc;
    color: #334155;
    border: 1px dashed #cbd5e1;
}

.personal-page .person-actions {
    margin-left: 8px;
}

.personal-page .personal-pagination-controls {
    margin-top: 10px;
}

@media (max-width: 768px) {
    .filter-panel-compact-row {
        flex-direction: column;
    }
    .filter-compact-group {
        width: 100%;
    }
    .filter-compact-group.filter-compact-actions {
        margin-left: 0;
    }
    .filter-chips-compact {
        flex-wrap: wrap;
    }

    .personal-page .page-title {
        font-size: 20px;
    }

    .personal-page .toolbar-search {
        margin-top: 6px;
    }
}
</style>
<div class="module-page personal-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Personal</h1>
                <p class="page-subtitle">Gestión y búsqueda de trabajadores</p>
            </div>
            <div class="page-actions" style="gap: 8px;">
                <!-- Dropdown acciones principales -->
                <div class="dropdown-container" style="position: relative;">
                    <button type="button" id="accionesBtn" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="1"/>
                            <circle cx="19" cy="12" r="1"/>
                            <circle cx="5" cy="12" r="1"/>
                        </svg>
                        Acciones
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div id="accionesMenu" class="acciones-dropdown" style="display: none; position: absolute; top: calc(100% + 8px); right: 0; min-width: 220px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); padding: 8px; z-index: 1000;">
                        <a class="accion-item" href="{{ route('personal.importar') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Importar Excel
                        </a>
                        <a class="accion-item" href="{{ route('personal.export.form', request()->query()) }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Exportar Excel
                        </a>
                        <div class="accion-divider"></div>
                        <a class="accion-item" href="{{ route('personal.create') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Nuevo trabajador
                        </a>
                    </div>
                </div>
                
                <!-- Botón filtros -->
                <button type="button" id="filterToggle" class="btn btn-outline d-flex align-items-center gap-2" aria-expanded="false" aria-label="Mostrar filtros" title="Mostrar filtros" style="display: inline-flex;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="22 3 2 3 10 12.69 10 21 14 21 14 12.69 22 3"/>
                    </svg>
                    <span>Filtros</span>
                    <span id="filterBadge" class="badge bg-primary text-white {{ $activeFilterCount > 0 ? '' : 'hidden' }}" style="font-size: 11px; padding: 2px 6px;">{{ $activeFilterCount }}</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Simple Search -->
    <div class="toolbar-search">
        @include('components.ui.simple-search', [
            'searchId' => 'personal-search',
            'placeholder' => 'Buscar por nombre, DNI, mina, puesto...',
            'showClear' => true
        ])
    </div>

<!-- Filter Panel -->
    <form method="GET" action="{{ route('personal.index') }}" class="filter-panel-compact" id="filterPanel" style="display: {{ $activeFilterCount > 0 ? 'block' : 'none' }};">
        <div class="filter-panel-compact-row">
            <!-- Ordenar por -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="4" y1="9" x2="20" y2="9"/>
                        <line x1="4" y1="15" x2="14" y2="15"/>
                        <line x1="4" y1="21" x2="8" y2="21"/>
                    </svg>
                    Ordenar
                </label>
                <select class="filter-compact-select" name="sort" data-filter-change>
                    <option value="nombre" {{ request('sort') == 'nombre' ? 'selected' : '' }}>Nombre</option>
                    <option value="puesto" {{ request('sort') == 'puesto' ? 'selected' : '' }}>Puesto</option>
                    <option value="contrato" {{ request('sort') == 'contrato' ? 'selected' : '' }}>Contrato</option>
                    <option value="estado" {{ request('sort') == 'estado' ? 'selected' : '' }}>Estado</option>
                    <option value="dni" {{ request('sort') == 'dni' ? 'selected' : '' }}>DNI</option>
                </select>
            </div>

            <!-- Estado -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    Estado
                </label>
                <div class="filter-chips-compact">
                    <button type="button" class="chip-compact {{ request('estado', '') == '' ? 'active' : '' }}" data-filter-chip="estado" data-value="">Todos</button>
                    <button type="button" class="chip-compact chip-activo {{ request('estado') == 'activo' ? 'active' : '' }}" data-filter-chip="estado" data-value="activo">Activos</button>
                    <button type="button" class="chip-compact chip-inactivo {{ request('estado') == 'inactivo' ? 'active' : '' }}" data-filter-chip="estado" data-value="inactivo">Inactivos</button>
                </div>
            </div>

            <!-- Tipo -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Tipo
                </label>
                <div class="filter-chips-compact">
                    <button type="button" class="chip-compact {{ request('tipo', '') == '' ? 'active' : '' }}" data-filter-chip="tipo" data-value="">Todos</button>
                    <button type="button" class="chip-compact chip-supervisor {{ request('tipo') == 'supervisor' ? 'active' : '' }}" data-filter-chip="tipo" data-value="supervisor">Superv.</button>
                    <button type="button" class="chip-compact chip-trabajador {{ request('tipo') == 'trabajador' ? 'active' : '' }}" data-filter-chip="tipo" data-value="trabajador">Trab.</button>
                </div>
            </div>

            <!-- Mina -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M2 22h20"/>
                        <path d="M6 19V9a3 3 0 0 1 3-3h0"/>
                        <path d="M10 19V5a3 3 0 0 1 3-3h0"/>
                        <path d="M14 19v-6a3 3 0 0 1 3-3h0"/>
                        <path d="M18 19v-2a3 3 0 0 1 3-3h0"/>
                    </svg>
                    Mina
                </label>
                <select class="filter-compact-select" name="mina" data-filter-change id="filterMina">
                    <option value="">Todas</option>
                    @foreach(\App\Models\Mina::where('estado', 'ACTIVO')->orderBy('nombre')->get() as $mina)
                        <option value="{{ $mina->id }}" {{ request('mina') == $mina->id ? 'selected' : '' }}>{{ $mina->nombre }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Estado Mina (solo visible cuando hay mina seleccionada) -->
            <div class="filter-compact-group" id="filterEstadoMinaGroup" style="display: none;">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    Estado Mina
                </label>
                <div class="filter-chips-compact">
                    <button type="button" class="chip-compact {{ request('mina_estado', '') == '' ? 'active' : '' }}" data-filter-chip="mina_estado" data-value="">Todos</button>
                    <button type="button" class="chip-compact chip-habilitado {{ request('mina_estado') == 'habilitado' ? 'active' : '' }}" data-filter-chip="mina_estado" data-value="habilitado">Habil.</button>
                    <button type="button" class="chip-compact chip-proceso {{ request('mina_estado') == 'proceso' ? 'active' : '' }}" data-filter-chip="mina_estado" data-value="proceso">Proceso</button>
                </div>
            </div>

            <!-- Contrato -->
            <div class="filter-compact-group">
                <label class="filter-compact-label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    Contrato
                </label>
                <select class="filter-compact-select" name="contrato" data-filter-change>
                    <option value="">Todos</option>
                    <option value="REG" {{ request('contrato') == 'REG' ? 'selected' : '' }}>Régimen</option>
                    <option value="FIJO" {{ request('contrato') == 'FIJO' ? 'selected' : '' }}>Fijo</option>
                    <option value="INTER" {{ request('contrato') == 'INTER' ? 'selected' : '' }}>Intermitente</option>
                    <option value="INDET" {{ request('contrato') == 'INDET' ? 'selected' : '' }}>Indeterminado</option>
                </select>
            </div>

            <!-- Limpiar -->
            <div class="filter-compact-group filter-compact-actions">
                <a href="{{ route('personal.index') }}" class="btn-limpiar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18"/>
                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                    </svg>
                    Limpiar
                </a>
            </div>
        </div>
    </form>

    <!-- Results -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de Personal</span>
            <span class="card-badge" id="personalCount">{{ count($trabajadores ?? []) }} trabajadores</span>
        </div>
        <div class="card-body">
            @if(empty($trabajadores))
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <h3 class="empty-title">Aún no hay personal registrado</h3>
                    <p class="empty-description">Una vez que se integren trabajadores al sistema, aparecerán aquí.</p>
                    <div class="empty-action">
                        <a href="{{ route('personal.create') }}" class="btn btn-primary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Agregar Trabajador
                        </a>
                    </div>
                </div>
            @else
                <div class="person-cards-grid">
                    @foreach($trabajadores as $trabajador)
                        <div class="person-card js-person-card {{ !$trabajador['activo'] ? 'inactive' : '' }}"
                            data-worker='@json($trabajador)'
                            data-nombre="{{ $trabajador['nombre'] ?? '' }}"
                            data-dni="{{ $trabajador['dni'] ?? '' }}"
                            data-puesto="{{ $trabajador['puesto'] ?? '' }}"
                            data-telefono="{{ $trabajador['telefono'] ?? '' }}"
                            data-telefono-1="{{ $trabajador['telefono_1'] ?? '' }}"
                            data-telefono-2="{{ $trabajador['telefono_2'] ?? '' }}"
                            data-fecha-ingreso="{{ $trabajador['fecha_ingreso'] ?? '' }}"
                            data-contrato="{{ $trabajador['tipo_contrato'] ?? '' }}"
                            data-minas="{{ implode(' ', $trabajador['minas'] ?? []) }}"
                            onclick="showWorkerDetail(this)">
                            <div class="person-avatar {{ !empty($trabajador['supervisor']) ? 'is-supervisor' : '' }} {{ !($trabajador['activo'] ?? true) ? 'is-inactive' : '' }}">
                                {{ strtoupper(substr($trabajador['nombre'] ?? 'U', 0, 2)) }}
                            </div>
                            <div class="person-info">
                                <div class="person-name">{{ $trabajador['nombre'] ?? 'Sin nombre' }}</div>
                                <div class="person-meta">
                                    <span class="person-role">{{ $trabajador['puesto'] ?? 'Sin puesto' }}</span>
                                    <span class="person-dni">DNI: {{ $trabajador['dni'] ?? '---' }}</span>
                                </div>
                                <div class="person-badges">
                                    <span class="person-badge contract">{{ $trabajador['tipo_contrato'] ?? 'Sin contrato' }}</span>
                                    @php
                                        $mineLabels = $trabajador['minas'] ?? [];
                                        $visibleMines = array_slice($mineLabels, 0, 2);
                                        $hiddenMineCount = max(count($mineLabels) - count($visibleMines), 0);
                                    @endphp
                                    @foreach($visibleMines as $mina)
                                        @php
                                        $minaLower = strtolower($mina ?? '');
                                        $isCentro = str_contains($minaLower, 'taller') || str_contains($minaLower, 'oficina');
                                        $isMina = !$isCentro;
                                        $isProceso = $isMina && (($trabajador['minas_estado'] ?? [])[$mina] ?? '') === 'proceso';
                                        @endphp
                                        <span class="person-badge mine {{ $isProceso ? 'mine-proceso' : '' }}">{{ $mina }}</span>
                                    @endforeach
                                    @if($hiddenMineCount > 0)
                                        <span class="person-badge mine-extra-count">+{{ $hiddenMineCount }} minas</span>
                                    @endif
                                </div>
                            </div>
                            <div class="person-actions" onclick="event.stopPropagation()">
                                <a href="{{ route('personal.edit', $trabajador['id'] ?? '') }}" class="person-action-btn" title="Editar">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="personal-pagination-controls">
                    <div class="personal-page-size">
                        Mostrar
                        <select id="personalPageSize" class="personal-page-size-select">
                            <option value="9" selected>9</option>
                            <option value="12">12</option>
                            <option value="18">18</option>
                            <option value="24">24</option>
                        </select>
                        trabajadores
                    </div>
                    <div class="personal-pagination-info" id="personalPaginationInfo"></div>
                </div>

                <div class="personal-pagination" id="personalPagination"></div>
            @endif
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function showWorkerDetail(card) {
    const worker = JSON.parse(card.dataset.worker || '{}');
    const modal = document.getElementById('workerDetailModal');
    if (!modal || !worker.nombre) return;

    const telefonoAttr = card.getAttribute('data-telefono') || '';
    const telefono1Attr = card.getAttribute('data-telefono-1') || '';
    const telefono2Attr = card.getAttribute('data-telefono-2') || '';
    const fechaIngresoAttr = card.getAttribute('data-fecha-ingreso') || '';

    const telefonoRaw = worker.telefono
        || telefonoAttr
        || [worker.telefono_1, worker.telefono_2, telefono1Attr, telefono2Attr].filter(Boolean).join(' / ')
        || '-';

    const fechaIngresoRaw = worker.fecha_ingreso || fechaIngresoAttr || null;

    const isCentroTrabajo = function(ubicacion) {
        const value = String(ubicacion || '').toLowerCase();
        return value.includes('taller') || value.includes('oficina');
    };

    let estadoClass = '';
    let estadoLabel = '';
    switch (worker.estado_actual) {
        case 'trabajando': estadoClass = 'status-active'; estadoLabel = 'Trabajando'; break;
        case 'vacaciones': estadoClass = 'status-vacaciones'; estadoLabel = 'En Vacaciones'; break;
        case 'enfermo': estadoClass = 'status-enfermo'; estadoLabel = 'Enfermo'; break;
        case 'parada': estadoClass = 'status-parada'; estadoLabel = 'En Parada'; break;
        case 'inactivo': estadoClass = 'status-inactive'; estadoLabel = 'Inactivo'; break;
        default: estadoClass = 'status-active'; estadoLabel = 'Activo';
    }

    const fechas = worker.fechas || {};
    const ingresoRaw = fechaIngresoRaw || fechas.ingreso || null;
    const ingreso = ingresoRaw ? new Date(ingresoRaw).toLocaleDateString('es-PE') : '-';
    const vac = fechas.vacaciones || {};
    const vacStr = vac.inicio ? `${new Date(vac.inicio).toLocaleDateString('es-PE')} - ${new Date(vac.fin).toLocaleDateString('es-PE')}` : '-';
    const enf = fechas.enfermo || {};
    const enfStr = enf.inicio ? `${new Date(enf.inicio).toLocaleDateString('es-PE')} - ${new Date(enf.fin).toLocaleDateString('es-PE')}` : '-';
    const par = fechas.parada || {};
    const parStr = par.inicio ? `${new Date(par.inicio).toLocaleDateString('es-PE')} - ${new Date(par.fin).toLocaleDateString('es-PE')}` : '-';
    const telefono = telefonoRaw;

    let minasHtml = '';
    let centrosHtml = '';
    if (worker.minas && worker.minas.length > 0) {
        worker.minas.forEach(function(mina) {
            const isMina = !isCentroTrabajo(mina);
            if (isMina) {
                const estado = (worker.minas_estado || {})[mina] || 'habilitado';
                const estadoMina = estado === 'proceso'
                    ? '<span class="badge badge-warning ml-2">En proceso</span>'
                    : (estado === 'no_habilitado'
                        ? '<span class="badge badge-danger ml-2">No habilitado</span>'
                        : '<span class="badge badge-success ml-2">Habilitado</span>');
                minasHtml += `<div class="detail-mina-item"><span>${mina}</span>${estadoMina}</div>`;
            } else {
                const etiqueta = String(mina || '').toLowerCase().includes('oficina') ? 'En oficina' : 'En taller';
                centrosHtml += `<div class="detail-mina-item"><span>${mina}</span><span class="badge badge-info ml-2">${etiqueta}</span></div>`;
            }
        });
    }

    const detailContent = `
        <div class="worker-detail-modal">
            <div class="detail-header">
                <div class="detail-avatar">${(worker.nombre || 'U').substring(0, 2).toUpperCase()}</div>
                <div class="detail-header-info">
                    <h2 class="detail-name">${worker.nombre || '-'}</h2>
                    <p class="detail-puesto">${worker.puesto || '-'}</p>
                    <span class="person-badge ${estadoClass}">${estadoLabel}</span>
                </div>
            </div>
            <div class="detail-body">
                <div class="detail-section">
                    <h3 class="detail-section-title">Información Personal</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">DNI</span>
                            <span class="detail-value">${worker.dni || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Teléfono</span>
                            <span class="detail-value">${telefono}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Fecha de Ingreso</span>
                            <span class="detail-value">${ingreso}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Tipo de Contrato</span>
                            <span class="detail-value">${worker.tipo_contrato || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Supervisor</span>
                            <span class="detail-value">${worker.supervisor ? 'Sí' : 'No'}</span>
                        </div>
                    </div>
                </div>
                <div class="detail-section">
                    <h3 class="detail-section-title">Ubicación en Minas</h3>
                    <div class="detail-minas">${minasHtml || '<p class="text-muted">Sin minas asignadas</p>'}</div>
                </div>
                ${centrosHtml ? `
                <div class="detail-section">
                    <h3 class="detail-section-title">Oficina / Taller</h3>
                    <div class="detail-minas">${centrosHtml}</div>
                </div>` : ''}
                <div class="detail-section">
                    <h3 class="detail-section-title">Estado y Fechas</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Estado Actual</span>
                            <span class="detail-value"><span class="person-badge ${estadoClass}">${estadoLabel}</span></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Vacaciones</span>
                            <span class="detail-value">${vacStr}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Enfermedad</span>
                            <span class="detail-value">${enfStr}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Parada</span>
                            <span class="detail-value">${parStr}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="detail-footer">
                <a href="/personal/${worker.id}/editar" class="btn btn-primary">Editar Trabajador</a>
                <button class="btn btn-outline" onclick="closeModal('workerDetailModal')">Cerrar</button>
            </div>
        </div>
    `;

    modal.querySelector('.modal-content').innerHTML = detailContent;
    openModal('workerDetailModal');
}

document.addEventListener('search:select', function(e) {
    const { item } = e.detail;
    console.log('Selected worker:', item);
    const searchInput = document.getElementById('personal-search') || document.querySelector('[data-search-input]');
    if (searchInput) {
        searchInput.value = item.nombre;
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
});

document.addEventListener('DOMContentLoaded', function () {
    function initPersonalPaginationFallback() {
        const items = Array.from(document.querySelectorAll('.js-person-card'));
        const pageSizeSelect = document.getElementById('personalPageSize');
        const paginationInfo = document.getElementById('personalPaginationInfo');
        const paginationWrap = document.getElementById('personalPagination');
        const countBadge = document.getElementById('personalCount');

        if (items.length === 0) {
            return;
        }

        let currentPage = 1;
        let pageSize = Number(pageSizeSelect?.value || 9);

        function render() {
            const totalItems = items.length;
            const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
            const safePage = Math.min(currentPage, totalPages);
            const start = (safePage - 1) * pageSize;
            const end = start + pageSize;

            items.forEach(function(item, index) {
                item.style.display = index >= start && index < end ? '' : 'none';
            });

            if (paginationInfo) {
                paginationInfo.textContent = totalItems === 0
                    ? '0 resultados'
                    : 'Mostrando ' + (start + 1) + '-' + Math.min(end, totalItems) + ' de ' + totalItems;
            }

            if (countBadge) {
                countBadge.textContent = totalItems + ' trabajadores';
            }

            if (paginationWrap) {
                let html = '';
                for (let page = 1; page <= totalPages; page++) {
                    html += '<button type="button" class="personal-pager-btn ' + (page === safePage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
                }
                paginationWrap.innerHTML = html;
            }

            currentPage = safePage;
        }

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', function() {
                pageSize = Number(pageSizeSelect.value || 9);
                currentPage = 1;
                render();
            });
        }

        if (paginationWrap) {
            paginationWrap.addEventListener('click', function(event) {
                const button = event.target.closest('button[data-page]');
                if (!button) {
                    return;
                }

                currentPage = Number(button.dataset.page || 1);
                render();
            });
        }

        render();
    }

    if (window.ProsergeUI) {
        try {
            window.ProsergeUI.initCollapsiblePanel({
                toggleButtonId: 'filterToggle',
                panelBodyId: 'filterPanel',
                expandedLabel: 'Ocultar filtros',
                collapsedLabel: 'Mostrar filtros',
            });

            window.ProsergeUI.initClientPagination({
                itemSelector: '.js-person-card',
                searchInputSelector: '#personal-search, [data-search-input]',
                pageSizeSelectId: 'personalPageSize',
                paginationInfoId: 'personalPaginationInfo',
                paginationWrapId: 'personalPagination',
                countBadgeId: 'personalCount',
                defaultPageSize: 9,
                maxVisiblePages: 7,
                paginationButtonClass: 'personal-pager-btn',
            });
        } catch (error) {
            console.error('Fallback pagination activated for personal index', error);
            initPersonalPaginationFallback();
        }
    } else {
        initPersonalPaginationFallback();
    }
    
    // Dropdown Acciones - mostrar/ocultar sin mover contenido
    const accionesBtn = document.getElementById('accionesBtn');
    const accionesMenu = document.getElementById('accionesMenu');
    
    if (accionesBtn && accionesMenu) {
        accionesBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isVisible = accionesMenu.style.display === 'block';
            accionesMenu.style.display = isVisible ? 'none' : 'block';
        });
        
        document.addEventListener('click', function(e) {
            if (!accionesMenu.contains(e.target) && e.target !== accionesBtn) {
                accionesMenu.style.display = 'none';
            }
        });
    }
    
    // Filtros con JavaScript - panel siempre visible
    // El panel de filtros permanece siempre abierto
    
    // Manejar chips de filtros
    document.querySelectorAll('[data-filter-chip]').forEach(function(chip) {
        chip.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const param = this.dataset.filterChip;
            const value = this.dataset.value;
            
            // Construir URL con todos los filtros actuales
            const url = new URL(window.location.href);
            
            // Actualizar solo el parámetro del chipclickeado
            if (value === '') {
                url.searchParams.delete(param);
            } else {
                url.searchParams.set(param, value);
            }
            
            // Mantener otros filtros
            window.location.href = url.toString();
        });
    });
    
    // Manejar selects de filtros
    document.querySelectorAll('[data-filter-change]').forEach(function(select) {
        select.addEventListener('change', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const url = new URL(window.location.href);
            
            // Si es el filtro de mina y se pone "Todas", también resetear mina_estado
            if (this.name === 'mina' && this.value === '') {
                url.searchParams.delete('mina');
                url.searchParams.delete('mina_estado');
            } else {
                if (this.value === '') {
                    url.searchParams.delete(this.name);
                } else {
                    url.searchParams.set(this.name, this.value);
                }
            }
            
            window.location.href = url.toString();
        });
    });
    
    // Mostrar/ocultar filtro Estado Mina según selección de Mina
    const filterMina = document.getElementById('filterMina');
    const filterEstadoMinaGroup = document.getElementById('filterEstadoMinaGroup');
    
    if (filterMina && filterEstadoMinaGroup) {
        // Mostrar inicialmente si hay una mina seleccionada
        if (filterMina.value) {
            filterEstadoMinaGroup.style.display = 'flex';
        }
        
        filterMina.addEventListener('change', function() {
            if (this.value) {
                filterEstadoMinaGroup.style.display = 'flex';
            } else {
                filterEstadoMinaGroup.style.display = 'none';
            }
        });
    }
});
</script>
@endpush
