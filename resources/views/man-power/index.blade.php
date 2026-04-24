@extends('layouts.app')

@section('title', 'Man Power - Proserge')

@section('content')
@php
    $today = date('Y-m-d');
    $paradas = [
        [
            'id' => 'MP-201',
            'mina' => 'BOROO',
            'parada' => 'Operación Planta',
            'fecha_inicio' => '2026-04-17',
            'fecha_fin' => '2026-04-30',
            'solicitado' => 65,
            'atendido' => 0,
            'turnos' => ['DIA', 'NOCHE'],
            'grupos_creados' => 0,
            'ultimo_grupo' => null,
        ],
        [
            'id' => 'MP-202',
            'mina' => 'CERRO VERDE',
            'parada' => 'C2',
            'fecha_inicio' => '2026-04-20',
            'fecha_fin' => '2026-04-28',
            'solicitado' => 18,
            'atendido' => 10,
            'turnos' => ['DIA'],
            'grupos_creados' => 2,
            'ultimo_grupo' => 'Grupo Noche - 16/04/2026',
        ],
        [
            'id' => 'MP-203',
            'mina' => 'CHINALCO',
            'parada' => 'Mantenimiento Faja 03',
            'fecha_inicio' => '2026-04-16',
            'fecha_fin' => '2026-04-24',
            'solicitado' => 24,
            'atendido' => 24,
            'turnos' => ['NOCHE'],
            'grupos_creados' => 4,
            'ultimo_grupo' => 'Grupo Día - 17/04/2026',
        ],
        [
            'id' => 'MP-204',
            'mina' => 'MARCOBRE',
            'parada' => 'Sección Beta',
            'fecha_inicio' => '2026-04-22',
            'fecha_fin' => '2026-05-02',
            'solicitado' => 10,
            'atendido' => 3,
            'turnos' => ['DIA', 'NOCHE'],
            'grupos_creados' => 1,
            'ultimo_grupo' => 'Grupo Día - 17/04/2026',
        ],
        [
            'id' => 'MP-205',
            'mina' => 'TALLER',
            'parada' => 'Mantenimiento Camiones',
            'fecha_inicio' => '2026-04-17',
            'fecha_fin' => '2026-04-19',
            'solicitado' => 8,
            'atendido' => 6,
            'turnos' => ['DIA'],
            'grupos_creados' => 2,
            'ultimo_grupo' => 'Grupo Día - 17/04/2026',
        ],
    ];
@endphp

<div class="manpower-screen" id="manPowerScreen" data-paradas='@json($paradas)' data-today="{{ $today }}">
    <header class="mp-header">
        <div>
            <h1 class="mp-title">Man Power</h1>
            <p class="mp-subtitle">Paradas atendidas desde RQ Proserge</p>
        </div>
    </header>

    <div class="mp-search-standalone">
        <div class="mp-search-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input id="mpSearch" type="text" placeholder="Buscar por mina, parada o estado...">
        </div>
    </div>

    <section class="mp-filter-panel">
        <div class="mp-filter-head">
            <span>Filtros de búsqueda</span>
            <button type="button" id="mpToggleFilters" class="btn-filter-toggle" aria-expanded="true" aria-label="Ocultar filtros" title="Ocultar filtros">
                <span id="mpToggleArrow" aria-hidden="true">▲</span>
            </button>
        </div>

        <div id="mpFiltersBody">
            <div class="mp-filters-grid">
                <div class="filter-item">
                    <label>Mina</label>
                    <select id="filterMina">
                        <option value="">Todas las minas</option>
                        <option value="CERRO VERDE">Cerro Verde</option>
                        <option value="BOROO">Boroo</option>
                        <option value="CHINALCO">Chinalco</option>
                        <option value="MARCOBRE">Marcobre</option>
                        <option value="TALLER">Taller</option>
                        <option value="OFICINA">Oficina</option>
                    </select>
                </div>

                <div class="filter-item">
                    <label>Estado de cobertura</label>
                    <select id="filterCobertura">
                        <option value="">Todos</option>
                        <option value="PENDIENTE">Pendiente</option>
                        <option value="PARCIAL">Parcial</option>
                        <option value="COMPLETO">Completo</option>
                    </select>
                </div>

                <div class="filter-item">
                    <label>Turno</label>
                    <select id="filterTurno">
                        <option value="">Todos</option>
                        <option value="DIA">Día</option>
                        <option value="NOCHE">Noche</option>
                    </select>
                </div>

                <div class="filter-item">
                    <label>Fecha</label>
                    <select id="filterFechaModo">
                        <option value="HOY">Hoy</option>
                        <option value="ESPECIFICA">Fecha específica</option>
                        <option value="RANGO">Rango</option>
                    </select>
                </div>

                <div class="filter-item" id="fechaEspecificaWrap" style="display:none;">
                    <label>Fecha específica</label>
                    <input id="filterFechaEspecifica" type="date" value="{{ $today }}">
                </div>

                <div class="filter-item" id="fechaInicioWrap" style="display:none;">
                    <label>Fecha inicio</label>
                    <input id="filterFechaInicio" type="date" value="{{ $today }}">
                </div>

                <div class="filter-item" id="fechaFinWrap" style="display:none;">
                    <label>Fecha fin</label>
                    <input id="filterFechaFin" type="date" value="{{ $today }}">
                </div>

                <div class="filter-item">
                    <label>Estado de grupo</label>
                    <select id="filterEstadoGrupo">
                        <option value="">Todos</option>
                        <option value="CON">Con grupos creados</option>
                        <option value="SIN">Sin grupos creados</option>
                    </select>
                </div>
            </div>

            <div class="mp-filter-actions">
                <button type="button" id="mpApplyFilters" class="btn-filter-main">Filtrar</button>
                <button type="button" id="mpResetFilters" class="btn-filter-light">Limpiar</button>
            </div>
        </div>
    </section>

    <section class="mp-results-head">
        <div class="head-left">
            <h2>Paradas activas</h2>
            <p id="mpResultLabel">Cargando datos...</p>
        </div>
    </section>

    <section id="mpCardsGrid" class="mp-cards-grid"></section>

    <section id="mpEmptyState" class="mp-empty-state" style="display:none;">
        <h3>No hay paradas activas para mostrar</h3>
        <p id="mpEmptyDescription">No se encontraron resultados con esos filtros.</p>
    </section>
</div>

<script>
(function () {
    const screen = document.getElementById('manPowerScreen');
    const rawParadas = JSON.parse(screen.dataset.paradas || '[]');
    const today = String(screen.dataset.today || '');

    const searchInput = document.getElementById('mpSearch');
    const filterMina = document.getElementById('filterMina');
    const filterCobertura = document.getElementById('filterCobertura');
    const filterTurno = document.getElementById('filterTurno');
    const filterFechaModo = document.getElementById('filterFechaModo');
    const filterFechaEspecifica = document.getElementById('filterFechaEspecifica');
    const filterFechaInicio = document.getElementById('filterFechaInicio');
    const filterFechaFin = document.getElementById('filterFechaFin');
    const filterEstadoGrupo = document.getElementById('filterEstadoGrupo');

    const fechaEspecificaWrap = document.getElementById('fechaEspecificaWrap');
    const fechaInicioWrap = document.getElementById('fechaInicioWrap');
    const fechaFinWrap = document.getElementById('fechaFinWrap');

    const applyBtn = document.getElementById('mpApplyFilters');
    const resetBtn = document.getElementById('mpResetFilters');

    const cardsGrid = document.getElementById('mpCardsGrid');
    const emptyState = document.getElementById('mpEmptyState');
    const emptyDescription = document.getElementById('mpEmptyDescription');
    const resultLabel = document.getElementById('mpResultLabel');

    function normalize(text) {
        return String(text || '').toLowerCase().trim();
    }

    function toDate(str) {
        if (!str) return null;
        const d = new Date(str + 'T00:00:00');
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function coverageStatus(item) {
        const solicitado = Number(item.solicitado || 0);
        const atendido = Number(item.atendido || 0);
        const pendiente = Math.max(solicitado - atendido, 0);
        if (pendiente === solicitado) return 'PENDIENTE';
        if (pendiente === 0 && solicitado > 0) return 'COMPLETO';
        return 'PARCIAL';
    }

    function coverageAccent(status) {
        if (status === 'PENDIENTE') return 'accent-pendiente';
        if (status === 'COMPLETO') return 'accent-completo';
        return 'accent-parcial';
    }

    function updateFechaModeUI() {
        const mode = filterFechaModo.value;
        fechaEspecificaWrap.style.display = mode === 'ESPECIFICA' ? 'flex' : 'none';
        fechaInicioWrap.style.display = mode === 'RANGO' ? 'flex' : 'none';
        fechaFinWrap.style.display = mode === 'RANGO' ? 'flex' : 'none';
    }

    function passesFecha(item) {
        const mode = filterFechaModo.value;
        const start = toDate(item.fecha_inicio);
        const end = toDate(item.fecha_fin);
        if (!start || !end) return false;

        if (mode === 'HOY') {
            const t = toDate(today);
            if (!t) return true;
            return start <= t && end >= t;
        }

        if (mode === 'ESPECIFICA') {
            const selected = toDate(filterFechaEspecifica.value);
            if (!selected) return true;
            return start <= selected && end >= selected;
        }

        if (mode === 'RANGO') {
            const from = toDate(filterFechaInicio.value);
            const to = toDate(filterFechaFin.value);
            if (!from || !to) return true;
            return start <= to && end >= from;
        }

        return true;
    }

    function applyFilters() {
        const text = normalize(searchInput.value);

        const results = rawParadas.filter((item) => {
            const status = coverageStatus(item);
            const pendientes = Math.max(Number(item.solicitado || 0) - Number(item.atendido || 0), 0);

            const haystack = [
                item.mina,
                item.parada,
                status,
            ].map(normalize).join(' ');

            if (text && !haystack.includes(text)) return false;

            if (filterMina.value && item.mina !== filterMina.value) return false;

            if (filterCobertura.value && status !== filterCobertura.value) return false;

            if (filterTurno.value && !(item.turnos || []).includes(filterTurno.value)) return false;

            if (filterEstadoGrupo.value === 'CON' && Number(item.grupos_creados || 0) === 0) return false;
            if (filterEstadoGrupo.value === 'SIN' && Number(item.grupos_creados || 0) > 0) return false;

            if (!passesFecha(item)) return false;

            item._ui = { status, pendientes };
            return true;
        });

        renderCards(results);
    }

    function cardTemplate(item) {
        const solicitado = Number(item.solicitado || 0);
        const atendido = Number(item.atendido || 0);
        const pendiente = Math.max(solicitado - atendido, 0);
        const status = item._ui?.status || coverageStatus(item);
        const accent = coverageAccent(status);
        const hasGroups = Number(item.grupos_creados || 0) > 0;

        return `
            <article class="mp-card ${accent}">
                <div class="mp-card-head">
                    <div>
                        <h3>${item.mina} - ${item.parada}</h3>
                        <p>${item.fecha_inicio} a ${item.fecha_fin}</p>
                    </div>
                    <span class="coverage-chip coverage-${status.toLowerCase()}">${status}</span>
                </div>

                <div class="mp-stats">
                    <span class="stat solicitado">Solicitado: ${solicitado}</span>
                    <span class="stat atendido">Atendido: ${atendido}</span>
                    <span class="stat pendiente">Pendiente: ${pendiente}</span>
                </div>

                <div class="mp-extra-row">
                    <span class="extra-pill">Turnos: ${(item.turnos || []).join(' / ')}</span>
                    <span class="extra-pill">Grupos creados: ${item.grupos_creados || 0}</span>
                </div>

                <div class="mp-last-group">${item.ultimo_grupo ? 'Último grupo: ' + item.ultimo_grupo : 'Sin grupos creados'}</div>

                <div class="mp-actions">
                    <a href="{{ route('man-power.grupos') }}?parada_id=${item.id}" class="btn-view ${hasGroups ? 'is-priority' : ''}">Ver grupos</a>
                    <a href="{{ route('man-power.grupo-crear') }}?parada_id=${item.id}" class="btn-create ${!hasGroups ? 'is-priority' : ''}">Crear grupo</a>
                </div>
            </article>
        `;
    }

    function renderCards(items) {
        if (rawParadas.length === 0) {
            cardsGrid.innerHTML = '';
            emptyState.style.display = 'block';
            emptyDescription.textContent = 'No hay paradas activas para mostrar.';
            resultLabel.textContent = 'Sin datos de paradas.';
            return;
        }

        resultLabel.textContent = `${items.length} parada(s) encontrada(s).`;

        if (items.length === 0) {
            cardsGrid.innerHTML = '';
            emptyState.style.display = 'block';
            emptyDescription.textContent = 'No se encontraron resultados con esos filtros.';
            return;
        }

        emptyState.style.display = 'none';
        cardsGrid.innerHTML = items.map(cardTemplate).join('');
    }

    function resetFilters() {
        searchInput.value = '';
        filterMina.value = '';
        filterCobertura.value = '';
        filterTurno.value = '';
        filterFechaModo.value = 'HOY';
        filterFechaEspecifica.value = today;
        filterFechaInicio.value = today;
        filterFechaFin.value = today;
        filterEstadoGrupo.value = '';
        updateFechaModeUI();
        applyFilters();
    }

    updateFechaModeUI();
    applyFilters();

    filterFechaModo.addEventListener('change', () => {
        updateFechaModeUI();
        applyFilters();
    });

    searchInput.addEventListener('input', applyFilters);
    [
        filterMina,
        filterCobertura,
        filterTurno,
        filterFechaEspecifica,
        filterFechaInicio,
        filterFechaFin,
        filterEstadoGrupo,
    ].forEach((el) => el.addEventListener('change', applyFilters));

    applyBtn.addEventListener('click', applyFilters);
    resetBtn.addEventListener('click', resetFilters);

    const initFilterToggleFallback = () => {
        const toggleButton = document.getElementById('mpToggleFilters');
        const panelBody = document.getElementById('mpFiltersBody');
        const iconElement = document.getElementById('mpToggleArrow');

        if (!toggleButton || !panelBody) {
            return;
        }

        const setExpanded = (isExpanded) => {
            panelBody.style.display = isExpanded ? 'block' : 'none';
            if (iconElement) {
                iconElement.textContent = isExpanded ? '▲' : '▼';
            }
            toggleButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            toggleButton.setAttribute('aria-label', isExpanded ? 'Ocultar filtros' : 'Mostrar filtros');
            toggleButton.setAttribute('title', isExpanded ? 'Ocultar filtros' : 'Mostrar filtros');
        };

        setExpanded(panelBody.style.display !== 'none');
        toggleButton.addEventListener('click', () => {
            const isExpanded = panelBody.style.display !== 'none';
            setExpanded(!isExpanded);
        });
    };

    if (window.ProsergeUI && typeof window.ProsergeUI.initCollapsiblePanel === 'function') {
        window.ProsergeUI.initCollapsiblePanel({
            toggleButtonId: 'mpToggleFilters',
            panelBodyId: 'mpFiltersBody',
            iconElementId: 'mpToggleArrow',
            expandedIcon: '▲',
            collapsedIcon: '▼',
            expandedLabel: 'Ocultar filtros',
            collapsedLabel: 'Mostrar filtros',
        });
    } else {
        initFilterToggleFallback();
    }
})();
</script>

@endsection
