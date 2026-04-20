@extends('layouts.app')

@section('title', 'RQ Proserge - Proserge')

@section('content')
@php
    $rqMinaList = [
        [
            'id' => 'RQM-1024',
            'mina' => 'BOROO',
            'area' => 'Parada Planta Concentradora',
            'fecha_inicio' => '2026-04-17',
            'fecha_fin' => '2026-04-30',
            'estado' => 'ENVIADO',
            'estado_cierre' => 'advertencia',
            'solicitado' => 4,
            'atendido' => 1,
            'puestos' => [
                [
                    'nombre' => 'Técnico Electricista',
                    'requeridos' => 1,
                    'asignados' => 0,
                    'trabajador' => 'AGUILAR HUAMAN FRANCISCO (02302913)',
                    'comentario' => 'Validar ingreso a mina y EPP completo',
                    'disponibilidad' => [
                        'tipo' => 'no_disponible',
                        'motivo' => 'mina',
                        'lineas' => [
                            'Último turno: - (Sin turno)',
                            'No está asignado a la mina seleccionada',
                        ],
                    ],
                    'fecha_inicio' => '17/04/2026',
                    'fecha_fin' => '30/04/2026',
                    'asignaciones' => [],
                    'personal_asignado' => [],
                ],
                [
                    'nombre' => 'Auxiliar de Mina',
                    'requeridos' => 2,
                    'asignados' => 0,
                    'trabajador' => 'RODRIGUEZ QUISPE MARIANELA (78110291)',
                    'comentario' => 'Turno día - coordinación logística',
                    'disponibilidad' => [
                        'tipo' => 'no_disponible',
                        'motivo' => 'bienestar',
                        'lineas' => [
                            'Último turno: - (Sin turno)',
                            'No disponible por bienestar: descanso médico',
                        ],
                    ],
                    'fecha_inicio' => '17/04/2026',
                    'fecha_fin' => '30/04/2026',
                    'asignaciones' => [],
                    'personal_asignado' => [],
                ],
                [
                    'nombre' => 'Operador de Equipo Liviano',
                    'requeridos' => 1,
                    'asignados' => 1,
                    'trabajador' => 'VELASQUEZ CUADROS JOSÉ LUIS (70119811)',
                    'comentario' => 'Con experiencia en frente mina sur',
                    'disponibilidad' => [
                        'tipo' => 'disponible',
                        'lineas' => [
                            'Último turno: 12/04/2026 - Día',
                            'Disponible sin restricciones activas',
                        ],
                    ],
                    'fecha_inicio' => '17/04/2026',
                    'fecha_fin' => '30/04/2026',
                    'asignaciones' => [
                        'Asignado provisional para inicio de parada',
                    ],
                    'personal_asignado' => [
                        [
                            'nombre' => 'VELASQUEZ CUADROS JOSÉ LUIS (70119811)',
                            'comentario' => 'Con experiencia en frente mina sur',
                            'fecha_inicio' => '17/04/2026',
                            'fecha_fin' => '30/04/2026',
                        ],
                    ],
                ],
            ],
        ],
        [
            'id' => 'RQM-1025',
            'mina' => 'CERRO VERDE',
            'area' => 'C2 Mantenimiento Mecánico',
            'fecha_inicio' => '2026-04-20',
            'fecha_fin' => '2026-04-28',
            'estado' => 'ENVIADO',
            'estado_cierre' => 'abierto',
            'solicitado' => 3,
            'atendido' => 2,
            'puestos' => [
                [
                    'nombre' => 'Mecánico de Planta',
                    'requeridos' => 2,
                    'asignados' => 2,
                    'trabajador' => 'SILVA PAREDES LUIS (71928111)',
                    'comentario' => 'Confirmar pase para turno noche',
                    'disponibilidad' => [
                        'tipo' => 'disponible',
                        'lineas' => [
                            'Último turno: 14/04/2026 - Noche',
                            'Disponible sin registros de bienestar',
                        ],
                    ],
                    'fecha_inicio' => '20/04/2026',
                    'fecha_fin' => '28/04/2026',
                    'asignaciones' => [
                        'CUBA HUERTA RODRIGO - Técnico líder',
                        'RAMOS TORRES DAVID - Apoyo de planta',
                    ],
                    'personal_asignado' => [
                        [
                            'nombre' => 'CUBA HUERTA RODRIGO',
                            'comentario' => 'Técnico líder',
                            'fecha_inicio' => '20/04/2026',
                            'fecha_fin' => '28/04/2026',
                        ],
                        [
                            'nombre' => 'RAMOS TORRES DAVID',
                            'comentario' => 'Apoyo de planta',
                            'fecha_inicio' => '20/04/2026',
                            'fecha_fin' => '28/04/2026',
                        ],
                    ],
                ],
                [
                    'nombre' => 'Soldador',
                    'requeridos' => 1,
                    'asignados' => 0,
                    'trabajador' => '',
                    'comentario' => '',
                    'disponibilidad' => [
                        'tipo' => 'disponible',
                        'lineas' => [
                            'Último turno: - (Sin turno)',
                            'Disponible sin registros de turno previos',
                        ],
                    ],
                    'fecha_inicio' => '20/04/2026',
                    'fecha_fin' => '28/04/2026',
                    'asignaciones' => [],
                    'personal_asignado' => [],
                ],
            ],
        ],
        [
            'id' => 'RQM-1026',
            'mina' => 'MARCOBRE',
            'area' => 'Parada Sección Beta',
            'fecha_inicio' => '2026-04-22',
            'fecha_fin' => '2026-05-02',
            'estado' => 'BORRADOR',
            'estado_cierre' => 'cerrado',
            'solicitado' => 2,
            'atendido' => 0,
            'puestos' => [
                [
                    'nombre' => 'Geólogo Junior',
                    'requeridos' => 1,
                    'asignados' => 0,
                    'trabajador' => '',
                    'comentario' => '',
                    'disponibilidad' => [
                        'tipo' => 'no_disponible',
                        'motivo' => 'mina',
                        'lineas' => [
                            'Último turno: - (Sin turno)',
                            'Pendiente de envío de RQ Mina para validar disponibilidad',
                        ],
                    ],
                    'fecha_inicio' => '22/04/2026',
                    'fecha_fin' => '02/05/2026',
                    'asignaciones' => [],
                    'personal_asignado' => [
                        [
                            'nombre' => 'SALAZAR MENDOZA ELVIS (74518924)',
                            'comentario' => 'Participó en levantamiento geológico inicial',
                            'fecha_inicio' => '22/04/2026',
                            'fecha_fin' => '28/04/2026',
                        ],
                    ],
                ],
            ],
        ],
    ];
@endphp

<div class="rrhh-screen" id="rrhhScreen" data-rq='@json($rqMinaList)'>
    <header class="rrhh-header">
        <div>
            <h1 class="rrhh-title">RQ Proserge - Atención RRHH</h1>
            <p class="rrhh-subtitle">Asignación de personal para requerimientos de mina</p>
        </div>
    </header>

    <section class="rrhh-search-wrap">
        <div class="rrhh-search">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input id="rqSearchInput" type="text" placeholder="Buscar por mina, área o estado..." />
        </div>
    </section>

    <section class="rrhh-layout">
        <aside class="rq-list-panel">
            <div class="rq-list-head">
                <h2>Requerimientos RQ Mina</h2>
                <span id="rqCounter">0</span>
            </div>
            <div id="rqList" class="rq-list"></div>
        </aside>

        <article class="rq-detail-panel" id="rqDetailPanel">
            <div class="rq-empty" id="rqEmptyState">
                <h3>Selecciona un RQ Mina para atender</h3>
                <p>Revisa la lista de la izquierda y elige un requerimiento para iniciar la asignación de personal.</p>
            </div>

            <div id="rqDetailContent" class="rq-detail-content hidden"></div>
        </article>
    </section>
</div>

<script>
(function () {
    const screen = document.getElementById('rrhhScreen');
    const rqData = JSON.parse(screen.dataset.rq || '[]');

    const searchInput = document.getElementById('rqSearchInput');
    const listContainer = document.getElementById('rqList');
    const counter = document.getElementById('rqCounter');
    const detailContainer = document.getElementById('rqDetailContent');
    const emptyState = document.getElementById('rqEmptyState');

    let selectedId = null;
    let query = '';

    function normalize(text) {
        return String(text || '').toLowerCase();
    }

    function isAvailable(puesto) {
        return puesto?.disponibilidad?.tipo === 'disponible';
    }

    function getFilteredItems() {
        if (!query) return rqData;
        return rqData.filter((item) => {
            const haystack = [item.mina, item.area, item.estado].map(normalize).join(' ');
            return haystack.includes(query);
        });
    }

    function renderList() {
        const items = getFilteredItems();
        counter.textContent = items.length;

        if (items.length === 0) {
            listContainer.innerHTML = '<div class="rq-list-empty">No hay requerimientos para esta búsqueda.</div>';
            renderDetail(null);
            return;
        }

        if (!selectedId || !items.some((item) => item.id === selectedId)) {
            selectedId = null;
        }

        listContainer.innerHTML = items.map((item) => {
            const isActive = item.id === selectedId;
            let cierreLabel = 'Asignación abierta';
            let cierreClass = 'abierto';
            if (item.estado_cierre === 'advertencia') {
                cierreLabel = 'A 1 semana de cerrar';
                cierreClass = 'advertencia';
            }
            if (item.estado_cierre === 'cerrado') {
                cierreLabel = 'Asignación cerrada';
                cierreClass = 'cerrado';
            }
            return `
                <button type="button" class="rq-card ${isActive ? 'active' : ''}" data-id="${item.id}">
                    <div class="rq-card-title">${item.mina} - ${item.area}</div>
                    <div class="rq-card-date">${item.fecha_inicio} a ${item.fecha_fin}</div>
                    <div class="rq-card-chips">
                        <span class="chip chip-state ${normalize(item.estado)}">Estado: ${item.estado}</span>
                        <span class="chip">Solicitado: ${item.solicitado}</span>
                        <span class="chip">Atendido: ${item.atendido}</span>
                    </div>
                    <div class="rq-cierre-mini ${cierreClass}">${cierreLabel}</div>
                </button>
            `;
        }).join('');

        listContainer.querySelectorAll('.rq-card').forEach((card) => {
            card.addEventListener('click', () => {
                selectedId = card.dataset.id;
                renderList();
                renderDetail(rqData.find((item) => item.id === selectedId) || null);
            });
        });

        renderDetail(rqData.find((item) => item.id === selectedId) || null);
    }

    function availabilityBox(disponibilidad) {
        const disponible = disponibilidad?.tipo === 'disponible';
        const lines = Array.isArray(disponibilidad?.lineas) ? disponibilidad.lineas : [];
        return `
            <div class="availability-box ${disponible ? 'is-ok' : 'is-bad'}">
                <div class="availability-title">${disponible ? 'Disponible' : 'No disponible'}</div>
                <div class="availability-lines">
                    ${lines.map((line) => `<p>${line}</p>`).join('')}
                </div>
            </div>
        `;
    }

    function matchesSearchInPuesto(puesto, personnelQuery) {
        if (!personnelQuery) return true;

        const assignedRows = Array.isArray(puesto.personal_asignado) ? puesto.personal_asignado : [];
        if (assignedRows.some((row) => normalize(row.nombre).includes(personnelQuery))) {
            return true;
        }

        if (normalize(puesto.trabajador).includes(personnelQuery)) {
            return true;
        }

        return false;
    }

    function puestoCard(puesto, isClosed) {
        const disableAssign = !isAvailable(puesto);
        const trabajador = puesto.trabajador || '';
        const comentario = puesto.comentario || '';
        const asignaciones = Array.isArray(puesto.asignaciones) ? puesto.asignaciones : [];
        const personalAsignado = Array.isArray(puesto.personal_asignado) ? puesto.personal_asignado : [];

        if (isClosed) {
            return `
                <div class="puesto-card puesto-card-cerrado">
                    <div class="puesto-head">
                        <h4>${puesto.nombre}</h4>
                        <span class="puesto-counter">${puesto.asignados || 0}/${puesto.requeridos || 0}</span>
                    </div>
                    <div class="table-responsive-lite">
                        <table class="assigned-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Comentario</th>
                                    <th>Fecha inicio</th>
                                    <th>Fecha fin</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${personalAsignado.length > 0
                                    ? personalAsignado.map((row) => `
                                        <tr>
                                            <td>${row.nombre || '-'}</td>
                                            <td>${row.comentario || '-'}</td>
                                            <td>${row.fecha_inicio || '-'}</td>
                                            <td>${row.fecha_fin || '-'}</td>
                                        </tr>
                                    `).join('')
                                    : '<tr><td colspan="4" class="table-empty">Sin personal registrado para este puesto.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        return `
            <div class="puesto-card">
                <div class="puesto-head">
                    <h4>${puesto.nombre}</h4>
                    <span class="puesto-counter">${puesto.asignados || 0}/${puesto.requeridos || 0}</span>
                </div>

                <div class="field-group">
                    <label>Trabajador</label>
                    <input type="text" value="${trabajador}" placeholder="Buscar y seleccionar trabajador por nombre, DNI o puesto" readonly>
                </div>

                <div class="puesto-grid">
                    <div class="field-group">
                        <label>Comentario</label>
                        <textarea rows="2" placeholder="Comentario" readonly>${comentario}</textarea>
                    </div>
                    ${availabilityBox(puesto.disponibilidad)}
                </div>

                <div class="puesto-actions-row">
                    <div class="dates-inline">
                        <div class="date-field">
                            <label>Fecha inicio</label>
                            <div class="date-input-wrap">
                                <input type="text" value="${puesto.fecha_inicio || '-'}" readonly>
                                <span class="icon">📅</span>
                            </div>
                        </div>
                        <div class="date-field">
                            <label>Fecha fin</label>
                            <div class="date-input-wrap">
                                <input type="text" value="${puesto.fecha_fin || '-'}" readonly>
                                <span class="icon">📅</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-assign ${disableAssign ? 'disabled' : ''}" ${disableAssign ? 'disabled' : ''}>Asignar</button>
                    <button type="button" class="btn-unassign ${disableAssign ? 'disabled' : ''}" ${disableAssign ? 'disabled' : ''}>Desasignar</button>
                </div>

                <div class="asignaciones-zone">
                    ${asignaciones.length > 0
                        ? asignaciones.map((item) => `<div class="asignacion-item">${item}</div>`).join('')
                        : '<div class="asignacion-empty">Sin asignaciones aun</div>'}
                </div>
            </div>
        `;
    }

    function renderDetail(item) {
        if (!item) {
            detailContainer.classList.add('hidden');
            emptyState.classList.remove('hidden');
            detailContainer.innerHTML = '';
            return;
        }

        emptyState.classList.add('hidden');
        detailContainer.classList.remove('hidden');

        const puestos = Array.isArray(item.puestos) ? item.puestos : [];
        const isClosed = item.estado_cierre === 'cerrado';
        const isWarning = item.estado_cierre === 'advertencia';

        let cierreBanner = '';
        if (isWarning) {
            cierreBanner = '<div class="cierre-banner advertencia">A 1 semana de cerrar: en 2 semanas no se podrá asignar ni desasignar personal.</div>';
        }
        if (isClosed) {
            cierreBanner = '<div class="cierre-banner cerrado">RQ cerrado: no se permiten modificaciones. Solo visualización del personal de parada.</div>';
        }

        const searchId = `assignedSearch_${item.id}`;

        detailContainer.innerHTML = `
            <div class="rq-detail-head">
                <h3>${item.mina} - ${item.area}</h3>
                <p>Rango: ${item.fecha_inicio} a ${item.fecha_fin}</p>
            </div>
            ${cierreBanner}
            <div class="assigned-search-box">
                <input type="text" id="${searchId}" placeholder="Buscar personal asignado por nombre...">
            </div>
            <div class="puestos-wrap">
                ${puestos.map((puesto) => puestoCard(puesto, isClosed)).join('')}
            </div>
        `;

        const assignedSearch = document.getElementById(searchId);
        if (!assignedSearch) {
            return;
        }

        const renderPuestos = function (personnelQuery) {
            const filteredPuestos = puestos.filter((puesto) => matchesSearchInPuesto(puesto, personnelQuery));
            detailContainer.querySelector('.puestos-wrap').innerHTML = filteredPuestos.length > 0
                ? filteredPuestos.map((puesto) => puestoCard(puesto, isClosed)).join('')
                : '<div class="table-empty">No hay personal que coincida con la búsqueda.</div>';
        };

        assignedSearch.addEventListener('input', (ev) => {
            renderPuestos(normalize(ev.target.value));
        });
    }

    searchInput.addEventListener('input', (e) => {
        query = normalize(e.target.value);
        renderList();
    });

    renderList();
    renderDetail(null);
})();
</script>

<style>
.rrhh-screen {
    font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding: 20px;
    background: #f4f6fb;
    min-height: calc(100vh - 120px);
}

.rrhh-header {
    margin-bottom: 16px;
}

.rrhh-title {
    margin: 0;
    font-size: 28px;
    font-weight: 800;
    color: #132238;
}

.rrhh-subtitle {
    margin: 6px 0 0;
    color: #637186;
    font-size: 14px;
}

.rrhh-search-wrap {
    margin-bottom: 16px;
}

.rrhh-search {
    background: #ffffff;
    border: 1px solid #dbe3ee;
    border-radius: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    box-shadow: 0 6px 18px rgba(20, 34, 52, 0.05);
}

.rrhh-search svg {
    color: #7b8aa1;
    flex-shrink: 0;
}

.rrhh-search input {
    border: none;
    outline: none;
    width: 100%;
    font-size: 15px;
    color: #243247;
    background: transparent;
}

.rrhh-layout {
    display: grid;
    grid-template-columns: 45% 55%;
    gap: 16px;
}

.rq-list-panel,
.rq-detail-panel {
    background: #ffffff;
    border: 1px solid #dde5f0;
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
}

.rq-list-head {
    border-bottom: 1px solid #eef2f7;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.rq-list-head h2 {
    margin: 0;
    font-size: 15px;
    color: #243247;
}

.rq-list-head span {
    background: #eef4ff;
    color: #3658a5;
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 12px;
    font-weight: 700;
}

.rq-list {
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: calc(100vh - 290px);
    overflow: auto;
}

.rq-list-empty {
    color: #74839a;
    font-size: 14px;
    text-align: center;
    padding: 20px 10px;
}

.rq-card {
    text-align: left;
    width: 100%;
    border: 1px solid #e4eaf4;
    background: #ffffff;
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.rq-card:hover {
    border-color: #ffb679;
    transform: translateY(-1px);
    box-shadow: 0 8px 16px rgba(249, 115, 22, 0.12);
}

.rq-card.active {
    border-color: #f97316;
    box-shadow: 0 10px 18px rgba(249, 115, 22, 0.18);
    background: #fff9f4;
}

.rq-card-title {
    font-size: 14px;
    font-weight: 700;
    color: #182739;
}

.rq-card-date {
    font-size: 12px;
    color: #74839a;
    margin-top: 4px;
}

.rq-card-chips {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.chip {
    font-size: 11px;
    border-radius: 999px;
    background: #eff3fa;
    color: #4b5c74;
    padding: 4px 9px;
    font-weight: 600;
}

.chip-state.borrador {
    background: #fff3dd;
    color: #b06600;
}

.chip-state.enviado {
    background: #e6f2ff;
    color: #145ecb;
}

.rq-cierre-mini {
    margin-top: 8px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 8px;
    padding: 5px 8px;
    display: inline-block;
}

.rq-cierre-mini.abierto {
    background: #e8f7ed;
    color: #166a43;
}

.rq-cierre-mini.advertencia {
    background: #fff3dd;
    color: #a66107;
}

.rq-cierre-mini.cerrado {
    background: #ffe9e9;
    color: #b42318;
}

.rq-detail-panel {
    padding: 16px;
    min-height: 560px;
}

.rq-empty {
    min-height: 520px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
    color: #6e7f97;
    padding: 20px;
}

.rq-empty h3 {
    margin: 0;
    color: #2b3a51;
}

.rq-empty p {
    margin: 8px 0 0;
    max-width: 380px;
}

.rq-detail-content.hidden,
.rq-empty.hidden {
    display: none;
}

.rq-detail-head {
    margin-bottom: 14px;
    border-bottom: 1px solid #eef2f7;
    padding-bottom: 12px;
}

.rq-detail-head h3 {
    margin: 0;
    color: #1e2d42;
    font-size: 20px;
}

.rq-detail-head p {
    margin: 5px 0 0;
    color: #6e7f97;
    font-size: 13px;
}

.cierre-banner {
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 10px;
    font-size: 13px;
    font-weight: 600;
}

.cierre-banner.advertencia {
    background: #fff4e5;
    color: #975a16;
    border: 1px solid #ffd8a8;
}

.cierre-banner.cerrado {
    background: #ffeaea;
    color: #9f1239;
    border: 1px solid #fecdd3;
}

.assigned-search-box {
    margin-bottom: 12px;
}

.assigned-search-box input {
    width: 100%;
    border: 1px solid #d8e2ef;
    border-radius: 10px;
    background: #f9fbff;
    color: #223249;
    font-size: 13px;
    padding: 10px 12px;
    box-sizing: border-box;
}

.puestos-wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.puesto-card {
    border: 1px solid #e5ecf6;
    border-radius: 14px;
    padding: 14px;
    background: #ffffff;
}

.puesto-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.puesto-head h4 {
    margin: 0;
    color: #1f3047;
    font-size: 15px;
}

.puesto-counter {
    background: #eff3fa;
    color: #30425d;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 12px;
    font-weight: 700;
}

.field-group {
    margin-bottom: 10px;
}

.field-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #52647f;
    margin-bottom: 6px;
}

.field-group input,
.field-group textarea {
    width: 100%;
    border: 1px solid #d8e2ef;
    border-radius: 10px;
    background: #f9fbff;
    color: #223249;
    font-size: 13px;
    padding: 10px 12px;
    box-sizing: border-box;
}

.puesto-grid {
    display: grid;
    grid-template-columns: 1fr 260px;
    gap: 10px;
    align-items: start;
}

.availability-box {
    border-radius: 10px;
    border: 1px solid;
    padding: 10px;
}

.availability-box.is-ok {
    background: #ebfbf1;
    border-color: #b8ebcc;
}

.availability-box.is-bad {
    background: #fff0f0;
    border-color: #f6c1c1;
}

.availability-title {
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 4px;
}

.availability-box.is-ok .availability-title {
    color: #18794e;
}

.availability-box.is-bad .availability-title {
    color: #b42318;
}

.availability-lines p {
    margin: 0;
    font-size: 12px;
    color: #4e5f78;
    line-height: 1.35;
}

.puesto-actions-row {
    display: flex;
    gap: 10px;
    justify-content: space-between;
    align-items: end;
    margin-top: 2px;
}

.dates-inline {
    display: grid;
    grid-template-columns: repeat(2, minmax(140px, 1fr));
    gap: 10px;
    flex: 1;
}

.date-field label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #52647f;
    margin-bottom: 6px;
}

.date-input-wrap {
    position: relative;
}

.date-input-wrap input {
    width: 100%;
    border: 1px solid #d8e2ef;
    border-radius: 10px;
    background: #f9fbff;
    color: #223249;
    font-size: 13px;
    padding: 10px 34px 10px 12px;
    box-sizing: border-box;
}

.date-input-wrap .icon {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    opacity: 0.65;
}

.btn-assign {
    border: none;
    border-radius: 10px;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #f97316, #ea580c);
    cursor: pointer;
    min-width: 100px;
}

.btn-assign.disabled,
.btn-assign:disabled {
    background: #c9d2df;
    color: #60738e;
    cursor: not-allowed;
}

.btn-unassign {
    border: 1px solid #f97316;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 700;
    color: #c2410c;
    background: #fff7ed;
    cursor: pointer;
    min-width: 100px;
}

.btn-unassign.disabled,
.btn-unassign:disabled {
    background: #eef2f8;
    border-color: #d4deea;
    color: #8ca0bb;
    cursor: not-allowed;
}

.puesto-card-cerrado {
    border-style: dashed;
}

.table-responsive-lite {
    overflow-x: auto;
}

.assigned-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 620px;
}

.assigned-table th,
.assigned-table td {
    font-size: 12px;
    text-align: left;
    border-bottom: 1px solid #e8eef7;
    padding: 8px 8px;
    color: #32465f;
}

.assigned-table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: #60738e;
    background: #f8fbff;
}

.table-empty {
    color: #7b8aa1;
    font-style: italic;
    text-align: center;
    padding: 12px;
}

.asignaciones-zone {
    margin-top: 10px;
    border-top: 1px dashed #dce4ef;
    padding-top: 10px;
}

.asignacion-item {
    background: #f4f7fc;
    border-radius: 8px;
    font-size: 12px;
    color: #37485f;
    padding: 6px 9px;
    margin-bottom: 6px;
}

.asignacion-empty {
    color: #7b8aa1;
    font-size: 12px;
    font-style: italic;
}

@media (max-width: 1220px) {
    .rrhh-layout {
        grid-template-columns: 1fr;
    }

    .rq-list {
        max-height: none;
    }

    .rq-detail-panel {
        min-height: 0;
    }
}

@media (max-width: 768px) {
    .rrhh-screen {
        padding: 12px;
    }

    .puesto-grid {
        grid-template-columns: 1fr;
    }

    .puesto-actions-row {
        flex-direction: column;
        align-items: stretch;
    }

    .dates-inline {
        grid-template-columns: 1fr;
    }

    .btn-assign {
        width: 100%;
    }

    .rrhh-title {
        font-size: 22px;
    }
}
</style>
@endsection
