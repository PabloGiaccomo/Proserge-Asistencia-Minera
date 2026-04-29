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

.dg-filter-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 4px;
    background: #e2e8f0;
    color: #475569;
    font-size: 10px;
    margin-left: 4px;
    line-height: 1;
    border: 0;
    cursor: pointer;
}

.dg-head-cell {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.dg-filter-popover {
    position: fixed;
    top: 0;
    left: 0;
    min-width: 190px;
    max-width: min(260px, calc(100vw - 24px));
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(2, 6, 23, 0.14);
    padding: 10px;
    z-index: 1200;
    display: none;
}

.dg-filter-popover.is-open {
    display: block;
}

.dg-pop-left {
    min-width: 210px;
}

.dg-pop-center {
    min-width: 210px;
}

.dg-pop-wide {
    min-width: 310px;
    max-width: min(390px, calc(100vw - 24px));
}

.dg-filter-popover .filter-compact-select,
.dg-filter-popover input.filter-compact-select {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.personal-page .table-responsive {
    overflow-x: auto;
    overflow-y: visible;
}

.dg-popover-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 4px;
}

.dg-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.1;
    border: 1px solid transparent;
    white-space: nowrap;
}

.dg-pill-neutral {
    background: #f1f5f9;
    color: #475569;
    border-color: #e2e8f0;
}

.dg-pill-estado-activo {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
}

.dg-pill-estado-inactivo,
.dg-pill-estado-cesado {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fecaca;
}

.dg-pill-contrato-indefinido {
    background: #dbeafe;
    color: #1d4ed8;
    border-color: #93c5fd;
}

.dg-pill-contrato-temporal {
    background: #ffedd5;
    color: #9a3412;
    border-color: #fdba74;
}

.dg-pill-contrato-servicio {
    background: #e0f2fe;
    color: #0c4a6e;
    border-color: #7dd3fc;
}

.dg-pill-contrato-practicante {
    background: #ede9fe;
    color: #5b21b6;
    border-color: #c4b5fd;
}

.dg-pill-situacion-activo {
    background: #dcfce7;
    color: #166534;
    border-color: #86efac;
}

.dg-pill-situacion-vacaciones {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.dg-pill-situacion-descanso {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fca5a5;
}

.dg-pill-situacion-parada {
    background: #ffe4e6;
    color: #9f1239;
    border-color: #fda4af;
}

.dg-pill-situacion-bloqueo {
    background: #e0e7ff;
    color: #3730a3;
    border-color: #a5b4fc;
}

.dg-pill-situacion-inactivo {
    background: #e5e7eb;
    color: #374151;
    border-color: #d1d5db;
}

.dg-ocupacion-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.dg-pill-ocup-mina {
    background: #cffafe;
    color: #155e75;
    border-color: #67e8f9;
}

.dg-pill-ocup-oficina {
    background: #d1fae5;
    color: #065f46;
    border-color: #6ee7b7;
}

.dg-pill-ocup-taller {
    background: #fef3c7;
    color: #92400e;
    border-color: #fcd34d;
}

.dg-pill-ocup-more {
    background: #f8fafc;
    color: #334155;
    border-color: #cbd5e1;
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
                    <div id="accionesMenu" class="acciones-dropdown" style="display: none; position: absolute; top: calc(100% + 8px); right: 0; min-width: 260px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); padding: 8px; z-index: 1000;">
                        <a class="accion-item" href="{{ route('personal.create') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Añadir manualmente
                        </a>
                        <a class="accion-item" href="{{ route('personal.fichas.import') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="12" y1="18" x2="12" y2="12"/>
                                <polyline points="9 15 12 18 15 15"/>
                            </svg>
                            Importar macro / contrato
                        </a>
                        <a class="accion-item" href="{{ route('personal.fichas.temporales') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                            </svg>
                            Temporales y links
                        </a>
                        <div class="accion-divider"></div>
                        <a class="accion-item" href="{{ route('personal.importar') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Importar Master General
                        </a>
                        <a class="accion-item" href="{{ route('personal.export.form', request()->query()) }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #0d9488;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Exportar Excel
                        </a>
                    </div>
                </div>
                
                <!-- Botón filtros -->
                <button type="button" id="filterToggle" class="btn btn-outline d-flex align-items-center gap-2" aria-expanded="false" aria-label="Mostrar filtros" title="Mostrar filtros" style="display: none;">
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
            'placeholder' => 'Buscar por nombre, documento, mina, puesto...',
            'showClear' => true
        ])
    </div>

<!-- Filter Panel -->
    <form method="GET" action="{{ route('personal.index') }}" class="filter-panel-compact" id="filterPanel" style="display: none;">
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
                    <option value="dni" {{ request('sort') == 'dni' ? 'selected' : '' }}>Documento</option>
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
                <div class="table-responsive">
                    <table class="data-table" id="personalDataGrid">
                        <thead>
                            <tr>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Trabajador</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterNombre" title="Filtrar Trabajador">≡</button>
                                        <div id="dgFilterNombre" class="dg-filter-popover dg-pop-left" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Orden</label>
                                            <select id="dgSortNombre" class="filter-compact-select">
                                                <option value="">Sin orden</option>
                                                <option value="asc">A-Z</option>
                                                <option value="desc">Z-A</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Documento</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterDni" title="Filtrar documento">≡</button>
                                        <div id="dgFilterDni" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Orden</label>
                                            <select id="dgSortDni" class="filter-compact-select">
                                                <option value="">Sin orden</option>
                                                <option value="asc">Asc</option>
                                                <option value="desc">Desc</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Puesto</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterPuesto" title="Filtrar Puesto">≡</button>
                                        <div id="dgFilterPuesto" class="dg-filter-popover dg-pop-center dg-pop-wide" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Puesto</label>
                                            <select id="dgPuesto" class="filter-compact-select"><option value="">Todos</option></select>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Contrato</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterContrato" title="Filtrar Contrato">≡</button>
                                        <div id="dgFilterContrato" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Contrato</label>
                                            <select id="dgContrato" class="filter-compact-select"><option value="">Todos</option></select>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Estado</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterEstado" title="Filtrar Estado">≡</button>
                                        <div id="dgFilterEstado" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Estado</label>
                                            <select id="dgEstado" class="filter-compact-select">
                                                <option value="">Todos</option>
                                                <option value="activo">Activo</option>
                                                <option value="inactivo">Inactivo</option>
                                                <option value="cesado">Cesado</option>
                                                <option value="pendiente ficha">Pendiente ficha</option>
                                                <option value="ficha enviada">Ficha enviada</option>
                                                <option value="link vencido">Link vencido</option>
                                                <option value="observado">Observado</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Situación</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterBienestar" title="Filtrar Bienestar">≡</button>
                                        <div id="dgFilterBienestar" class="dg-filter-popover dg-pop-center" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Situación</label>
                                            <select id="dgBienestar" class="filter-compact-select">
                                                <option value="">Todos</option>
                                                <option value="activo">Activo</option>
                                                <option value="vacaciones">Vacaciones</option>
                                                <option value="descanso_medico">Descanso médico</option>
                                                <option value="parada">Parada</option>
                                                <option value="inhabilitado">Inhabilitado</option>
                                                <option value="pendiente_ficha">Pendiente ficha</option>
                                                <option value="ficha_enviada">Ficha enviada</option>
                                                <option value="link_vencido">Link vencido</option>
                                                <option value="restriccion_temporal">Restricción temporal</option>
                                                <option value="otro">Otro</option>
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    <div class="dg-head-cell">
                                        <span>Ocupación</span>
                                        <button type="button" class="dg-filter-icon js-dg-filter-trigger" data-target="dgFilterOcupacion" title="Filtrar Ocupación">≡</button>
                                        <div id="dgFilterOcupacion" class="dg-filter-popover dg-pop-center dg-pop-wide" onclick="event.stopPropagation()">
                                            <label class="dg-popover-label">Mina</label>
                                            <select id="dgOcupMina" class="filter-compact-select">
                                                <option value="">Todas</option>
                                                @foreach(($catalogMinas ?? []) as $catalogMina)
                                                    <option value="{{ $catalogMina }}">{{ $catalogMina }}</option>
                                                @endforeach
                                            </select>
                                            <label class="dg-popover-label" style="margin-top:8px;">Oficina</label>
                                            <select id="dgOcupOficina" class="filter-compact-select">
                                                <option value="">Todas</option>
                                                @foreach(($catalogOficinas ?? []) as $catalogOficina)
                                                    <option value="{{ $catalogOficina }}">{{ $catalogOficina }}</option>
                                                @endforeach
                                            </select>
                                            <label class="dg-popover-label" style="margin-top:8px;">Taller</label>
                                            <select id="dgOcupTaller" class="filter-compact-select">
                                                <option value="">Todos</option>
                                                @foreach(($catalogTalleres ?? []) as $catalogTaller)
                                                    <option value="{{ $catalogTaller }}">{{ $catalogTaller }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($trabajadores as $trabajador)
                                @php
                                    $estadoRaw = strtoupper((string) ($trabajador['estado'] ?? 'ACTIVO'));
                                    $estadoText = match ($estadoRaw) {
                                        'CESADO' => 'Cesado',
                                        'INACTIVO' => 'Inactivo',
                                        'PENDIENTE_COMPLETAR_FICHA' => 'Pendiente ficha',
                                        'FICHA_ENVIADA' => 'Ficha enviada',
                                        'LINK_VENCIDO' => 'Link vencido',
                                        'OBSERVADO' => 'Observado',
                                        'RECHAZADO' => 'Rechazado',
                                        default => $trabajador['estado_label'] ?? 'Activo',
                                    };
                                    $estadoActual = strtolower((string) ($trabajador['estado_actual'] ?? 'trabajando'));
                                    $situacionKey = match ($estadoActual) {
                                        'trabajando' => 'activo',
                                        'vacaciones' => 'vacaciones',
                                        'enfermo' => 'descanso_medico',
                                        'parada' => 'parada',
                                        'bloqueado_bienestar' => strtolower((string) ($trabajador['bloqueo_bienestar']['tipo'] ?? 'otro')),
                                        'inactivo' => 'inactivo',
                                        'pendiente_completar_ficha' => 'pendiente_ficha',
                                        'ficha_enviada' => 'ficha_enviada',
                                        'link_vencido' => 'link_vencido',
                                        default => 'otro',
                                    };

                                    if (!in_array($situacionKey, ['activo', 'vacaciones', 'descanso_medico', 'parada', 'inhabilitado', 'restriccion_temporal', 'inactivo', 'pendiente_ficha', 'ficha_enviada', 'link_vencido'], true)) {
                                        $situacionKey = 'otro';
                                    }

                                    $situacionLabel = match ($situacionKey) {
                                        'activo' => 'Activo',
                                        'vacaciones' => 'Vacaciones',
                                        'descanso_medico' => 'Descanso médico',
                                        'parada' => 'Parada',
                                        'inhabilitado' => 'Inhabilitado',
                                        'restriccion_temporal' => 'Restricción',
                                        'inactivo' => 'Inactivo',
                                        'pendiente_ficha' => 'Pendiente ficha',
                                        'ficha_enviada' => 'Ficha enviada',
                                        'link_vencido' => 'Link vencido',
                                        default => 'Otro',
                                    };
                                    $estadoClass = match (mb_strtolower($estadoText)) {
                                        'activo' => 'dg-pill-estado-activo',
                                        'inactivo' => 'dg-pill-estado-inactivo',
                                        'cesado' => 'dg-pill-estado-cesado',
                                        'pendiente ficha' => 'dg-pill-situacion-vacaciones',
                                        'ficha enviada' => 'dg-pill-contrato-indefinido',
                                        'link vencido', 'rechazado' => 'dg-pill-estado-inactivo',
                                        'observado' => 'dg-pill-situacion-bloqueo',
                                        default => 'dg-pill-neutral',
                                    };

                                    $contratoText = trim((string) ($trabajador['tipo_contrato'] ?? '-'));
                                    $contratoLower = mb_strtolower($contratoText);
                                    $contratoClass = 'dg-pill-neutral';
                                    if ($contratoText !== '-' && $contratoText !== '') {
                                        if (str_contains($contratoLower, 'indef') || str_contains($contratoLower, 'planilla') || str_contains($contratoLower, 'fijo')) {
                                            $contratoClass = 'dg-pill-contrato-indefinido';
                                        } elseif (str_contains($contratoLower, 'temporal') || str_contains($contratoLower, 'plazo') || str_contains($contratoLower, 'eventual')) {
                                            $contratoClass = 'dg-pill-contrato-temporal';
                                        } elseif (str_contains($contratoLower, 'servicio') || str_contains($contratoLower, 'locaci') || str_contains($contratoLower, 'tercer')) {
                                            $contratoClass = 'dg-pill-contrato-servicio';
                                        } elseif (str_contains($contratoLower, 'practic') || str_contains($contratoLower, 'intern')) {
                                            $contratoClass = 'dg-pill-contrato-practicante';
                                        }
                                    }

                                    $situacionClass = match ($situacionKey) {
                                        'activo' => 'dg-pill-situacion-activo',
                                        'vacaciones' => 'dg-pill-situacion-vacaciones',
                                        'descanso_medico' => 'dg-pill-situacion-descanso',
                                        'parada' => 'dg-pill-situacion-parada',
                                        'pendiente_ficha' => 'dg-pill-situacion-vacaciones',
                                        'ficha_enviada' => 'dg-pill-contrato-indefinido',
                                        'link_vencido' => 'dg-pill-estado-inactivo',
                                        'inhabilitado', 'restriccion_temporal', 'otro' => 'dg-pill-situacion-bloqueo',
                                        'inactivo' => 'dg-pill-situacion-inactivo',
                                        default => 'dg-pill-neutral',
                                    };

                                    $ocupAll = $trabajador['minas'] ?? [];
                                    $ocupMinas = array_values(array_filter($ocupAll, function ($item) {
                                        $lower = mb_strtolower((string) $item);
                                        return !str_contains($lower, 'oficina') && !str_contains($lower, 'taller');
                                    }));
                                    $ocupOficinas = array_values(array_filter($ocupAll, fn ($item) => str_contains(mb_strtolower((string) $item), 'oficina')));
                                    $ocupTalleres = array_values(array_filter($ocupAll, fn ($item) => str_contains(mb_strtolower((string) $item), 'taller')));

                                    $ocupVisible = array_slice($ocupAll, 0, 2);
                                    $ocupExtraCount = max(count($ocupAll) - count($ocupVisible), 0);
                                    $documentDisplay = trim((string) (($trabajador['tipo_documento'] ?? 'DNI') . ' ' . ($trabajador['numero_documento'] ?? $trabajador['dni'] ?? '')));
                                @endphp
                                <tr class="js-person-row {{ !$trabajador['activo'] ? 'inactive' : '' }}"
                                    style="cursor:pointer;"
                                    data-worker='@json($trabajador)'
                                    data-nombre="{{ $trabajador['nombre'] ?? '' }}"
                                    data-dni="{{ $trabajador['numero_documento'] ?? $trabajador['dni'] ?? '' }}"
                                    data-puesto="{{ $trabajador['puesto'] ?? '' }}"
                                    data-telefono="{{ $trabajador['telefono'] ?? '' }}"
                                    data-telefono-1="{{ $trabajador['telefono_1'] ?? '' }}"
                                    data-telefono-2="{{ $trabajador['telefono_2'] ?? '' }}"
                                    data-fecha-ingreso="{{ $trabajador['fecha_ingreso'] ?? '' }}"
                                    data-contrato="{{ $trabajador['tipo_contrato'] ?? '' }}"
                                    data-minas="{{ implode(' ', $trabajador['minas'] ?? []) }}"
                                    data-estado="{{ mb_strtolower($estadoText) }}"
                                    data-bienestar="{{ $situacionKey }}"
                                    data-ocup-minas="{{ implode(' ', $ocupMinas) }}"
                                    data-ocup-oficina="{{ implode(' ', $ocupOficinas) }}"
                                    data-ocup-taller="{{ implode(' ', $ocupTalleres) }}"
                                    onclick="showWorkerDetail(this)">
                                    <td>{{ $trabajador['nombre'] ?? 'Sin nombre' }}</td>
                                    <td>{{ $documentDisplay !== '' ? $documentDisplay : '-' }}</td>
                                    <td>{{ $trabajador['puesto'] ?? '-' }}</td>
                                    <td><span class="dg-pill {{ $contratoClass }}">{{ $contratoText !== '' ? $contratoText : '-' }}</span></td>
                                    <td><span class="dg-pill {{ $estadoClass }}">{{ $estadoText }}</span></td>
                                    <td>
                                        <span class="dg-pill {{ $situacionClass }}">{{ $situacionLabel }}</span>
                                    </td>
                                    <td>
                                        @if(count($ocupVisible) > 0)
                                            <div class="dg-ocupacion-list">
                                                @foreach($ocupVisible as $ocup)
                                                    @php
                                                        $ocupLower = mb_strtolower((string) $ocup);
                                                        $ocupClass = str_contains($ocupLower, 'oficina')
                                                            ? 'dg-pill-ocup-oficina'
                                                            : (str_contains($ocupLower, 'taller') ? 'dg-pill-ocup-taller' : 'dg-pill-ocup-mina');
                                                    @endphp
                                                    <span class="dg-pill {{ $ocupClass }}">{{ $ocup }}</span>
                                                @endforeach
                                                @if($ocupExtraCount > 0)
                                                    <span class="dg-pill dg-pill-ocup-more">+{{ $ocupExtraCount }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="dg-pill dg-pill-neutral">-</span>
                                        @endif
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <a href="{{ route('personal.edit', $trabajador['id'] ?? '') }}" class="btn btn-outline btn-xs" style="padding:4px 8px;">Editar</a>
                                            @if(!empty($trabajador['ficha_id']))
                                                <a href="{{ route('personal.fichas.review', $trabajador['ficha_id']) }}" class="btn {{ $situacionKey === 'ficha_enviada' ? 'btn-primary' : 'btn-outline' }} btn-xs" style="padding:4px 8px;">
                                                    {{ $situacionKey === 'ficha_enviada' ? 'Validar / activar' : 'Ficha' }}
                                                </a>
                                            @endif
                                            <button type="button" class="btn btn-outline btn-xs" style="padding:4px 8px;" onclick="event.stopPropagation(); showWorkerDetail(this.closest('tr'))">Mostrar</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
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

    <div id="workerDetailModal" class="modal" style="display:none;" onclick="if (event.target === this) closeWorkerDetailModal()">
        <div class="modal-backdrop" onclick="closeWorkerDetailModal()"></div>
        <div class="modal-content"></div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function showWorkerDetail(card) {
    document.querySelectorAll('.js-person-row.is-selected').forEach(function (node) {
        node.classList.remove('is-selected');
    });
    card.classList.add('is-selected');

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
        case 'trabajando': estadoClass = 'status-active'; estadoLabel = 'Activo en la empresa'; break;
        case 'vacaciones': estadoClass = 'status-vacaciones'; estadoLabel = 'En Vacaciones'; break;
        case 'enfermo': estadoClass = 'status-enfermo'; estadoLabel = 'Descanso médico'; break;
        case 'parada': estadoClass = 'status-parada'; estadoLabel = 'En Parada'; break;
        case 'bloqueado_bienestar': estadoClass = 'status-parada'; estadoLabel = 'Bloqueado por Bienestar'; break;
        case 'inactivo': estadoClass = 'status-inactive'; estadoLabel = 'Inactivo'; break;
        case 'pendiente_completar_ficha': estadoClass = 'status-vacaciones'; estadoLabel = 'Pendiente completar ficha'; break;
        case 'ficha_enviada': estadoClass = 'status-active'; estadoLabel = 'Ficha enviada'; break;
        case 'link_vencido': estadoClass = 'status-inactive'; estadoLabel = 'Link vencido'; break;
        default: estadoClass = 'status-active'; estadoLabel = 'Activo';
    }

    const fechas = worker.fechas || {};
    const resumenBienestar = worker.resumen_bienestar || {};
    const ingresoRaw = fechaIngresoRaw || fechas.ingreso || null;
    const ingreso = ingresoRaw ? new Date(ingresoRaw).toLocaleDateString('es-PE') : '-';
    const vacStr = resumenBienestar.vacaciones || 'Sin vacaciones próximas en los siguientes 2 meses. Disponible por ahora.';
    const descansoStr = resumenBienestar.descanso_medico || 'Sin descanso médico vigente. Estado de salud operativo.';
    const parStr = resumenBienestar.parada || 'Sin parada vigente en este momento.';
    const telefono = telefonoRaw;
    const documento = [worker.tipo_documento || 'DNI', worker.numero_documento || worker.dni || '-'].join(' ').trim();

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
                            <span class="detail-label">Documento</span>
                            <span class="detail-value">${documento || '-'}</span>
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
                            <span class="detail-label">Descanso médico</span>
                            <span class="detail-value">${descansoStr}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Parada</span>
                            <span class="detail-value">${parStr}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="detail-footer">
                ${worker.ficha_id ? `<a href="/personal/fichas/${worker.ficha_id}/revisar" class="btn ${worker.estado_actual === 'ficha_enviada' ? 'btn-primary' : 'btn-outline'}">${worker.estado_actual === 'ficha_enviada' ? 'Validar y activar' : 'Ficha Colaborador'}</a>` : ''}
                <a href="/bienestar/${worker.id}?solo_calendario=1" class="btn btn-outline">Cartilla Ocupación</a>
                <a href="/personal/${worker.id}/editar" class="btn btn-primary">Editar Trabajador</a>
                <button class="btn btn-outline" onclick="closeWorkerDetailModal()">Cerrar</button>
            </div>
        </div>
    `;

    modal.querySelector('.modal-content').innerHTML = detailContent;
    openModal('workerDetailModal');
}

function closeWorkerDetailModal() {
    closeModal('workerDetailModal');
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
    const rows = Array.from(document.querySelectorAll('.js-person-row'));
    const pageSizeSelect = document.getElementById('personalPageSize');
    const paginationInfo = document.getElementById('personalPaginationInfo');
    const paginationWrap = document.getElementById('personalPagination');
    const countBadge = document.getElementById('personalCount');
    const searchInput = document.getElementById('personal-search');
    const sortNombre = document.getElementById('dgSortNombre');
    const sortDni = document.getElementById('dgSortDni');
    const puestoFilter = document.getElementById('dgPuesto');
    const contratoFilter = document.getElementById('dgContrato');
    const estadoFilter = document.getElementById('dgEstado');
    const bienestarFilter = document.getElementById('dgBienestar');
    const ocupMinaFilter = document.getElementById('dgOcupMina');
    const ocupOficinaFilter = document.getElementById('dgOcupOficina');
    const ocupTallerFilter = document.getElementById('dgOcupTaller');

    const filterTriggers = Array.from(document.querySelectorAll('.js-dg-filter-trigger'));
    const filterPopovers = Array.from(document.querySelectorAll('.dg-filter-popover'));

    if (rows.length === 0) {
        return;
    }

    const closeAllPopovers = function () {
        filterPopovers.forEach(function (panel) {
            panel.classList.remove('is-open');
            panel._triggerEl = null;
        });
    };

    const positionPopover = function (panel, triggerEl) {
        if (!panel || !triggerEl) return;

        const triggerRect = triggerEl.getBoundingClientRect();
        const gap = 8;
        const viewportMargin = 12;

        panel.style.top = (triggerRect.bottom + gap) + 'px';
        panel.style.left = '0px';

        const panelRect = panel.getBoundingClientRect();
        let left = triggerRect.right - panelRect.width;

        if (panel.classList.contains('dg-pop-left')) {
            left = triggerRect.left;
        } else if (panel.classList.contains('dg-pop-center')) {
            left = triggerRect.left + (triggerRect.width / 2) - (panelRect.width / 2);
        }

        const maxLeft = window.innerWidth - panelRect.width - viewportMargin;
        left = Math.max(viewportMargin, Math.min(left, maxLeft));

        panel.style.left = left + 'px';

        const finalRect = panel.getBoundingClientRect();
        if (finalRect.bottom > (window.innerHeight - viewportMargin)) {
            panel.style.top = Math.max(viewportMargin, triggerRect.top - panelRect.height - gap) + 'px';
        }
    };

    const repositionOpenPopover = function () {
        const active = document.querySelector('.dg-filter-popover.is-open');
        if (!active || !active._triggerEl) return;
        positionPopover(active, active._triggerEl);
    };

    const fitPopoverWithinViewport = function (panel) {
        const triggerEl = panel && panel._triggerEl;
        if (!panel || !triggerEl) return;
        positionPopover(panel, triggerEl);

        const rect = panel.getBoundingClientRect();
        const margin = 12;
        if (rect.top < margin) {
            panel.style.top = margin + 'px';
        }
    };

    filterTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const targetId = trigger.dataset.target;
            const panel = targetId ? document.getElementById(targetId) : null;
            if (!panel) return;
            const willOpen = !panel.classList.contains('is-open');
            closeAllPopovers();
            if (willOpen) {
                panel.classList.add('is-open');
                panel._triggerEl = trigger;
                fitPopoverWithinViewport(panel);
            }
        });
    });

    window.addEventListener('resize', function () {
        repositionOpenPopover();
    });

    document.addEventListener('scroll', repositionOpenPopover, true);

    document.addEventListener('click', function (event) {
        const inPopover = event.target.closest('.dg-filter-popover');
        const inTrigger = event.target.closest('.js-dg-filter-trigger');
        if (!inPopover && !inTrigger) {
            closeAllPopovers();
        }
    });

    const normalizeText = function(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const populateSelect = function(selectEl, values) {
        if (!selectEl) return;
        const current = selectEl.value;
        const options = Array.from(new Set(values.filter(Boolean))).sort();
        options.forEach(function(value) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            selectEl.appendChild(opt);
        });
        if (current) selectEl.value = current;
    };

    populateSelect(puestoFilter, rows.map(r => r.dataset.puesto || ''));
    populateSelect(contratoFilter, rows.map(r => r.dataset.contrato || ''));

    let currentPage = 1;
    let pageSize = Number(pageSizeSelect?.value || 9);

    const applyFiltersAndSort = function() {
        const search = normalizeText(searchInput?.value || '');
        const searchTokens = search.split(' ').filter(Boolean);
        const puesto = normalizeText(puestoFilter?.value || '');
        const contrato = normalizeText(contratoFilter?.value || '');
        const estado = normalizeText(estadoFilter?.value || '');
        const bienestar = normalizeText(bienestarFilter?.value || '');
        const ocupMina = normalizeText(ocupMinaFilter?.value || '');
        const ocupOficina = normalizeText(ocupOficinaFilter?.value || '');
        const ocupTaller = normalizeText(ocupTallerFilter?.value || '');

        let filtered = rows.filter(function(row) {
            const searchable = normalizeText([
                row.dataset.nombre,
                row.dataset.dni,
                row.dataset.puesto,
                row.dataset.contrato,
                row.dataset.minas,
            ].join(' '));

            if (searchTokens.length && !searchTokens.every(t => searchable.includes(t))) return false;
            if (puesto && normalizeText(row.dataset.puesto).indexOf(puesto) === -1) return false;
            if (contrato && normalizeText(row.dataset.contrato).indexOf(contrato) === -1) return false;
            if (estado && normalizeText(row.dataset.estado) !== estado) return false;
            if (bienestar && normalizeText(row.dataset.bienestar) !== bienestar) return false;
            if (ocupMina && normalizeText(row.dataset.occupMinas || row.dataset.ocupMinas).indexOf(ocupMina) === -1) return false;
            if (ocupOficina && normalizeText(row.dataset.ocupOficina).indexOf(ocupOficina) === -1) return false;
            if (ocupTaller && normalizeText(row.dataset.ocupTaller).indexOf(ocupTaller) === -1) return false;
            return true;
        });

        const compareText = function(a, b) {
            return a.localeCompare(b, 'es', { sensitivity: 'base' });
        };

        filtered.sort(function(a, b) {
            const nSort = sortNombre?.value || '';
            if (nSort) {
                const cmp = compareText(String(a.dataset.nombre || ''), String(b.dataset.nombre || ''));
                if (cmp !== 0) return nSort === 'asc' ? cmp : -cmp;
            }
            const dSort = sortDni?.value || '';
            if (dSort) {
                const cmp = compareText(String(a.dataset.dni || ''), String(b.dataset.dni || ''));
                if (cmp !== 0) return dSort === 'asc' ? cmp : -cmp;
            }
            return 0;
        });

        return filtered;
    };

    const renderPagination = function(totalPages) {
        if (!paginationWrap) return;
        if (totalPages <= 1) {
            paginationWrap.innerHTML = '';
            return;
        }
        const maxVisible = 7;
        const visiblePages = [];

        if (totalPages <= maxVisible) {
            for (let p = 1; p <= totalPages; p++) {
                visiblePages.push(p);
            }
        } else {
            const pages = new Set([1, totalPages]);
            const around = Math.max(1, Math.floor((maxVisible - 3) / 2));
            const start = Math.max(2, currentPage - around);
            const end = Math.min(totalPages - 1, currentPage + around);

            for (let page = start; page <= end; page++) {
                pages.add(page);
            }

            const ordered = Array.from(pages).sort((a, b) => a - b);
            ordered.forEach(function (page, index) {
                if (index > 0 && page - ordered[index - 1] > 1) {
                    visiblePages.push('ellipsis');
                }
                visiblePages.push(page);
            });
        }

        let html = '';
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + '>&lsaquo;</button>';
        visiblePages.forEach(function (page) {
            if (page === 'ellipsis') {
                html += '<span class="personal-pager-ellipsis">...</span>';
                return;
            }
            html += '<button type="button" class="personal-pager-btn ' + (page === currentPage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
        });
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage === totalPages ? 'disabled' : '') + '>&rsaquo;</button>';

        paginationWrap.innerHTML = html;
    };

    const clampPage = function(page, totalPages) {
        if (Number.isNaN(page) || page < 1) return 1;
        if (page > totalPages) return totalPages;
        return page;
    };

    const renderGrid = function(resetPage) {
        if (resetPage) currentPage = 1;
        const filtered = applyFiltersAndSort();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = clampPage(currentPage, totalPages);
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;

        rows.forEach(r => r.style.display = 'none');
        filtered.slice(start, end).forEach(r => r.style.display = 'table-row');

        if (paginationInfo) {
            paginationInfo.textContent = total === 0 ? '0 resultados' : 'Mostrando ' + (start + 1) + '-' + Math.min(end, total) + ' de ' + total;
        }
        if (countBadge) {
            countBadge.textContent = total + ' trabajadores';
        }
        renderPagination(totalPages);
    };

    const simpleSearchInput = document.getElementById('personal-search');
    const simpleSearchClear = document.querySelector('[data-simple-search-clear]');
    if (simpleSearchInput && simpleSearchClear) {
        const syncSearchClear = function () {
            simpleSearchClear.style.display = simpleSearchInput.value.trim().length > 0 ? 'flex' : 'none';
        };

        simpleSearchInput.addEventListener('input', function () {
            syncSearchClear();
            renderGrid(true);
        });
        simpleSearchClear.addEventListener('click', function () {
            simpleSearchInput.value = '';
            simpleSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
            syncSearchClear();
            simpleSearchInput.focus();
        });

        syncSearchClear();
    }

    [sortNombre, sortDni, puestoFilter, contratoFilter, estadoFilter, bienestarFilter].forEach(function(el) {
        if (!el) return;
        el.addEventListener('change', function () { renderGrid(true); });
    });
    [ocupMinaFilter, ocupOficinaFilter, ocupTallerFilter].forEach(function(el) {
        if (!el) return;
        el.addEventListener('change', function () { renderGrid(true); });
    });

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            pageSize = Number(pageSizeSelect.value || 9);
            renderGrid(true);
        });
    }

    if (paginationWrap) {
        paginationWrap.addEventListener('click', function (event) {
            const btn = event.target.closest('button[data-page]');
            if (!btn || btn.hasAttribute('disabled')) return;
            currentPage = Number(btn.dataset.page || 1);
            renderGrid(false);
        });
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
    
    const filterToggle = document.getElementById('filterToggle');
    const filterPanel = document.getElementById('filterPanel');
    if (filterToggle) filterToggle.style.display = 'none';
    if (filterPanel) filterPanel.style.display = 'none';

    renderGrid(true);
});
</script>
@endpush
