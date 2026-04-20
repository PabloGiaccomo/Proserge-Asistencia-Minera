@extends('layouts.app')

@section('title', 'Dashboard Operativo')

@section('header_title')
    Dashboard Operativo
@endsection

@section('header_breadcrumb')
    <span class="header-breadcrumb-sep">/</span>
    <span>Inicio</span>
    <span class="header-breadcrumb-sep">/</span>
    <span class="text-primary">Dashboard</span>
@endsection

@section('content')
<div class="mb-4">
    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-row">
            <div class="filter-group" style="min-width: 180px;">
                <label class="filter-label">Token API</label>
                <input type="text" id="token" class="form-control" placeholder="Bearer token...">
            </div>
            <div class="filter-group">
                <label class="filter-label">Desde</label>
                <input type="date" id="fecha_desde" class="form-control">
            </div>
            <div class="filter-group">
                <label class="filter-label">Hasta</label>
                <input type="date" id="fecha_hasta" class="form-control">
            </div>
            <div class="filter-group">
                <label class="filter-label">Destino</label>
                <select id="destino_tipo" class="form-control">
                    <option value="">Todos</option>
                    <option>MINA</option>
                    <option>TALLER</option>
                    <option>OFICINA</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">ID Destino</label>
                <input type="text" id="destino_id" class="form-control" placeholder="UUID">
            </div>
            <div class="filter-group">
                <label class="filter-label">Mina</label>
                <input type="text" id="mina_id" class="form-control" placeholder="UUID">
            </div>
            <div class="filter-actions">
                <button id="applyBtn" class="btn btn-primary btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                    Aplicar
                </button>
                <button id="quickToday" class="btn btn-secondary btn-sm">Hoy</button>
                <button id="quickMonth" class="btn btn-secondary btn-sm">Mes</button>
                <button id="refreshBtn" class="btn btn-outline btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <polyline points="1 20 1 14 7 14"></polyline>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="mb-4">
    <h3 class="section-title">Resumen General</h3>
    <div class="kpi-grid" id="kpis">
        <!-- Loaded via JS -->
    </div>
</div>

<!-- Dashboard Sections Grid -->
<div class="dashboard-grid mb-4">
    <!-- RQ Mina -->
    <div class="dashboard-section">
        <div class="dashboard-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            RQ Mina
        </div>
        <div id="rqMina" class="mt-2"></div>
    </div>
    
    <!-- RQ Proserge -->
    <div class="dashboard-section">
        <div class="dashboard-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="12" y1="18" x2="12" y2="12"></line>
                <line x1="9" y1="15" x2="15" y2="15"></line>
            </svg>
            RQ Proserge
        </div>
        <div id="rqProserge" class="mt-2"></div>
    </div>
    
    <!-- Man Power -->
    <div class="dashboard-section">
        <div class="dashboard-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            Man Power
        </div>
        <div id="manPower" class="mt-2"></div>
    </div>
    
    <!-- Asistencia -->
    <div class="dashboard-section">
        <div class="dashboard-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Asistencia
        </div>
        <div id="asistencia" class="mt-2"></div>
    </div>
    
    <!-- Faltas -->
    <div class="dashboard-section">
        <div class="dashboard-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            Faltas
        </div>
        <div id="faltas" class="mt-2"></div>
    </div>
    
    <!-- Evaluaciones -->
    <div class="dashboard-section">
        <div class="dashboard-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            Evaluaciones
        </div>
        <div id="evaluaciones" class="mt-2"></div>
    </div>
</div>

<!-- Alertas -->
<div class="mb-4">
    <div class="dashboard-section">
        <div class="dashboard-section-title" style="color: var(--danger);">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            Alertas Operativas
        </div>
        <div id="alertas" class="alert-grid mt-3"></div>
    </div>
</div>

<!-- Loading State -->
<div id="loadingState" class="loading-container" style="display: none;">
    <div class="loading-spinner"></div>
    <div class="loading-text">Cargando datos del dashboard...</div>
</div>

<!-- Error State -->
<div id="errorState" class="alert alert-error" style="display: none;">
    <svg class="alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="15" y1="9" x2="9" y2="15"></line>
        <line x1="9" y1="9" x2="15" y2="15"></line>
    </svg>
    <div>
        <strong>Error al cargar datos</strong>
        <p id="errorMessage" style="margin: 4px 0 0;">Verifique su token de acceso</p>
    </div>
</div>
@endsection

@push('styles')
<style>
    /* Dashboard-specific overrides */
    .kpi-mini {
        display: inline-flex;
        flex-direction: column;
        padding: 8px 12px;
        background: var(--bg-hover);
        border-radius: var(--radius-md);
        margin-right: 8px;
        margin-bottom: 8px;
    }
    .kpi-mini .v { font-size: 18px; font-weight: 700; color: var(--text-primary); }
    .kpi-mini .l { font-size: 11px; color: var(--text-muted); }
</style>
@endpush

@push('scripts')
<script>
    const base = '/api/v1/dashboard';
    const ids = ['fecha_desde', 'fecha_hasta', 'destino_tipo', 'destino_id', 'mina_id', 'supervisor_id', 'trabajador_id'];
    const kpisEl = document.getElementById('kpis');
    const loadingEl = document.getElementById('loadingState');
    const errorEl = document.getElementById('errorState');
    const errorMsgEl = document.getElementById('errorMessage');

    function getQueryParams() {
        const p = new URLSearchParams();
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                const v = el.value.trim();
                if (v) p.append(id, v);
            }
        });
        return p.toString() ? '?' + p.toString() : '';
    }

    function getToken() {
        const el = document.getElementById('token');
        return el ? el.value.trim() : '';
    }

    async function apiCall(path) {
        const headers = { 'Accept': 'application/json' };
        const t = getToken();
        if (t) headers['Authorization'] = 'Bearer ' + t;
        
        const r = await fetch(base + path + getQueryParams(), { headers });
        return await r.json();
    }

    function renderKpi(label, value, colorClass = '') {
        return `<div class="kpi-card">
            <div class="kpi-label">${label}</div>
            <div class="kpi-value ${colorClass}">${value ?? 0}</div>
        </div>`;
    }

    function renderTable(rows, cols, emptyMsg = 'Sin datos') {
        if (!rows || rows.length === 0) {
            return `<div class="text-center text-muted p-3" style="font-size: 13px;">${emptyMsg}</div>`;
        }
        const h = cols.map(c => `<th>${c}</th>`).join('');
        const b = rows.map(r => `<tr>${cols.map(c => `<td>${r[c] ?? '-'}</td>`).join('')}</tr>`).join('');
        return `<div class="table-container"><table class="data-table"><thead><tr>${h}</tr></thead><tbody>${b}</tbody></table></div>`;
    }

    function renderMiniKpi(label, value) {
        return `<div class="kpi-mini"><div class="v">${value ?? 0}</div><div class="l">${label}</div></div>`;
    }

    function getEl(id) {
        return document.getElementById(id);
    }

    async function load() {
        if (loadingEl) loadingEl.style.display = 'flex';
        if (errorEl) errorEl.style.display = 'none';
        
        try {
            const [resumen, rqMina, rqProserge, manPower, asistencia, faltas, evaluaciones, alertas] = await Promise.all([
                apiCall('/resumen'),
                apiCall('/rq-mina'),
                apiCall('/rq-proserge'),
                apiCall('/man-power'),
                apiCall('/asistencia'),
                apiCall('/faltas'),
                apiCall('/evaluaciones'),
                apiCall('/alertas')
            ]);

            if (loadingEl) loadingEl.style.display = 'none';

            if (!resumen.ok) {
                if (errorEl) {
                    errorEl.style.display = 'flex';
                    errorMsgEl.textContent = `${resumen.message || 'Error'} [${resumen.code || ''}]`;
                }
                return;
            }

            // KPIs
            const r = resumen.data;
            if (kpisEl) {
                kpisEl.innerHTML = [
                    renderKpi('RQ Mina', r.rq_mina_total),
                    renderKpi('RQ Proserge', r.rq_proserge_total),
                    renderKpi('Grupos', r.grupos_total),
                    renderKpi('Asist. Cerradas', r.asistencias_cerradas),
                    renderKpi('Faltas Activas', r.faltas_activas, r.faltas_activas > 0 ? 'text-danger' : ''),
                    renderKpi('Eval. Desempeño', r.evaluaciones_desempeno_total),
                    renderKpi('Eval. Supervisor', r.evaluaciones_supervisor_total),
                    renderKpi('Eval. Residente', r.evaluaciones_residente_total)
                ].join('');
            }

            // RQ Mina
            const rqMinaEl = getEl('rqMina');
            if (rqMinaEl) {
                rqMinaEl.innerHTML = 
                    renderTable(rqMina.data?.totales_por_estado || [], ['estado', 'total'], 'Sin estados') +
                    '<div class="mt-2"></div>' +
                    renderTable(rqMina.data?.tendencia || [], ['fecha', 'total'], 'Sin tendencia');
            }

            // RQ Proserge
            const rqProsergeEl = getEl('rqProserge');
            const rqp = rqProserge.data || {};
            if (rqProsergeEl) {
                rqProsergeEl.innerHTML = 
                    renderMiniKpi('Total', rqp.requerimientos_total) +
                    renderMiniKpi('Pendientes', rqp.requerimientos_pendientes) +
                    renderMiniKpi('Personal', rqp.personal_asignado) +
                    '<div class="mt-2"></div>' +
                    renderTable(rqp.avance || [], ['rq_proserge_id', 'solicitado', 'atendido', 'avance_porcentaje'], 'Sin avances');
            }

            // Man Power
            const manPowerEl = getEl('manPower');
            const mp = manPower.data || {};
            if (manPowerEl) {
                manPowerEl.innerHTML = 
                    renderMiniKpi('Total', mp.grupos_total) +
                    renderMiniKpi('Activos Hoy', mp.grupos_activos_hoy) +
                    '<div class="mt-2"></div>' +
                    renderTable(mp.grupos_por_turno || [], ['turno', 'total'], 'Sin turnos');
            }

            // Asistencia
            const asistenciaEl = getEl('asistencia');
            const asis = asistencia.data || {};
            if (asistenciaEl) {
                asistenciaEl.innerHTML = 
                    renderMiniKpi('Iniciadas', asis.grupos_iniciados) +
                    renderMiniKpi('Cerradas', asis.grupos_cerradas) +
                    renderMiniKpi('% Asistencia', asis.porcentaje_asistencia + '%') +
                    '<div class="mt-2"></div>' +
                    renderTable(asis.asistencia_por_destino || [], ['destino_tipo', 'total'], 'Sin datos');
            }

            // Faltas
            const faltasEl = getEl('faltas');
            if (faltasEl) {
                faltasEl.innerHTML = 
                    renderTable(faltas.data?.faltas_por_estado || [], ['estado', 'total'], 'Sin estados') +
                    '<div class="mt-2"></div>' +
                    renderTable(faltas.data?.faltas_por_destino || [], ['destino_tipo', 'total'], 'Sin destinos');
            }

            // Evaluaciones
            const evaluacionesEl = getEl('evaluaciones');
            const ev = evaluaciones.data || {};
            if (evaluacionesEl) {
                evaluacionesEl.innerHTML = 
                    renderMiniKpi('Desempeño', ev.evaluaciones_desempeno_total) +
                    renderMiniKpi('Supervisor', ev.evaluaciones_supervisor_total) +
                    renderMiniKpi('Residente', ev.evaluaciones_residente_total) +
                    renderMiniKpi('Promedio', ev.promedio_general_desempeno) +
                    '<div class="mt-2"></div>' +
                    renderTable(ev.ranking_top || [], ['nombre_completo', 'promedio_total'], 'Sin rankings');
            }

            // Alertas
            const alertasEl = getEl('alertas');
            const al = alertas.data || {};
            if (alertasEl) {
                const alerts = [
                    ['RQ Mina pendientes', al.rq_mina_pendientes_envio, 'warning'],
                    ['Sin atención completa', al.requerimientos_sin_atencion_completa, 'danger'],
                    ['Grupos sin cerrar', al.grupos_sin_asistencia_cerrada, 'warning'],
                    ['Faltas activas', al.faltas_activas_pendientes, 'danger'],
                    ['Multiples faltas', al.trabajadores_multiples_faltas_periodo, 'warning'],
                    ['Eval. pendientes', al.evaluaciones_pendientes, 'info']
                ];
                
                alertasEl.innerHTML = alerts.map(a => 
                    `<div class="alert-item ${a[2]}">
                        <div class="alert-item-label">${a[0]}</div>
                        <div class="alert-item-value">${a[1] ?? 0}</div>
                    </div>`
                ).join('');
            }

        } catch (err) {
            if (loadingEl) loadingEl.style.display = 'none';
            if (errorEl) {
                errorEl.style.display = 'flex';
                errorMsgEl.textContent = 'Error de conexión: ' + err.message;
            }
        }
    }

    // Event listeners - safely check elements exist
    const applyBtn = getEl('applyBtn');
    const refreshBtn = getEl('refreshBtn');
    const quickToday = getEl('quickToday');
    const quickMonth = getEl('quickMonth');
    const fechaDesde = getEl('fecha_desde');
    const fechaHasta = getEl('fecha_hasta');
    
    if (applyBtn) applyBtn.onclick = load;
    if (refreshBtn) refreshBtn.onclick = load;
    
    if (quickToday && fechaDesde && fechaHasta) {
        quickToday.onclick = () => {
            const d = new Date().toISOString().slice(0,10);
            fechaDesde.value = d;
            fechaHasta.value = d;
            load();
        };
    }
    
    if (quickMonth && fechaDesde && fechaHasta) {
        quickMonth.onclick = () => {
            const now = new Date();
            const first = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0,10);
            const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0,10);
            fechaDesde.value = first;
            fechaHasta.value = last;
            load();
        };
    }

    // Initial load
    load();
</script>
@endpush