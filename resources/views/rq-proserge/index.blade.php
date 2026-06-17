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
    $rqMinaList = $data['data'] ?? [];
@endphp

<div
    class="rrhh-screen"
    id="rrhhScreen"
    data-rq='@json($rqMinaList)'
    data-worker-search-url="{{ route('rq-proserge.personal.buscar') }}"
    data-csrf="{{ csrf_token() }}"
>
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
    const workerSearchUrl = screen.dataset.workerSearchUrl || '';
    const csrfToken = screen.dataset.csrf || '';

    const searchInput = document.getElementById('rqSearchInput');
    const listContainer = document.getElementById('rqList');
    const counter = document.getElementById('rqCounter');
    const detailContainer = document.getElementById('rqDetailContent');
    const emptyState = document.getElementById('rqEmptyState');
    const workerSearchTimers = new WeakMap();

    let selectedId = null;
    let query = '';
    let assignedPersonnelQuery = '';

    function normalize(text) {
        return String(text || '').toLowerCase();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSelectedItem() {
        return rqData.find((item) => item.id === selectedId) || null;
    }

    function replaceRqItem(item) {
        if (!item || !item.id) {
            return;
        }

        const index = rqData.findIndex((row) => row.id === item.id);
        if (index >= 0) {
            rqData[index] = item;
            return;
        }

        rqData.unshift(item);
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
                        ${(item.cambios_pendientes || 0) > 0 ? `<span class="chip chip-change">Cambios: ${item.cambios_pendientes}</span>` : ''}
                    </div>
                    <div class="rq-cierre-mini ${cierreClass}">${cierreLabel}</div>
                </button>
            `;
        }).join('');

        listContainer.querySelectorAll('.rq-card').forEach((card) => {
            card.addEventListener('click', () => {
                selectedId = card.dataset.id;
                assignedPersonnelQuery = '';
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
        const cambios = Array.isArray(puesto.cambios) ? puesto.cambios : [];
        const cambiosHtml = cambios.length > 0
            ? `<div class="rq-change-box">${cambios.map((cambio) => `<p>${cambio.mensaje || 'Cambio pendiente de RQ Mina'}</p>`).join('')}</div>`
            : '';

        if (isClosed) {
            return `
                <div class="puesto-card puesto-card-cerrado">
                    <div class="puesto-head">
                        <h4>${puesto.nombre}</h4>
                        <span class="puesto-counter">${puesto.asignados || 0}/${puesto.requeridos || 0}</span>
                    </div>
                    ${cambiosHtml}
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
                ${cambiosHtml}

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

    function messageBox(message, type = 'ok') {
        return `<div class="rq-inline-message ${type === 'error' ? 'is-error' : 'is-ok'}">${escapeHtml(message)}</div>`;
    }

    function setCardMessage(card, message, type = 'ok') {
        const holder = card.querySelector('[data-card-message]');
        if (holder) {
            holder.innerHTML = message ? messageBox(message, type) : '';
        }
    }

    function assignedRowsHtml(item, personalAsignado) {
        if (!personalAsignado.length) {
            return '<div class="asignacion-empty">Sin asignaciones aun</div>';
        }

        return personalAsignado.map((row) => `
            <div class="asignacion-item">
                <div class="asignacion-main">
                    <strong>${escapeHtml(row.nombre || '-')}</strong>
                    <span>${escapeHtml(row.comentario || '-')}</span>
                    <small>${escapeHtml(row.fecha_inicio || '-')} a ${escapeHtml(row.fecha_fin || '-')}</small>
                </div>
                <button
                    type="button"
                    class="btn-unassign btn-unassign-small js-rq-unassign"
                    data-rq-id="${escapeHtml(item.id)}"
                    data-assignment-id="${escapeHtml(row.id || '')}"
                    ${row.id ? '' : 'disabled'}
                >Desasignar</button>
            </div>
        `).join('');
    }

    function puestoCardEditable(puesto, isClosed, item) {
        const disableAssign = !isAvailable(puesto);
        const comentario = puesto.comentario || '';
        const personalAsignado = Array.isArray(puesto.personal_asignado) ? puesto.personal_asignado : [];
        const cambios = Array.isArray(puesto.cambios) ? puesto.cambios : [];
        const cambiosHtml = cambios.length > 0
            ? `<div class="rq-change-box">${cambios.map((cambio) => `<p>${escapeHtml(cambio.mensaje || 'Cambio pendiente de RQ Mina')}</p>`).join('')}</div>`
            : '';

        if (isClosed) {
            return `
                <div class="puesto-card puesto-card-cerrado">
                    <div class="puesto-head">
                        <h4>${escapeHtml(puesto.nombre)}</h4>
                        <span class="puesto-counter">${escapeHtml(puesto.asignados || 0)}/${escapeHtml(puesto.requeridos || 0)}</span>
                    </div>
                    ${cambiosHtml}
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
                                            <td>${escapeHtml(row.nombre || '-')}</td>
                                            <td>${escapeHtml(row.comentario || '-')}</td>
                                            <td>${escapeHtml(row.fecha_inicio || '-')}</td>
                                            <td>${escapeHtml(row.fecha_fin || '-')}</td>
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
            <div class="puesto-card" data-puesto-id="${escapeHtml(puesto.id || '')}">
                <div class="puesto-head">
                    <h4>${escapeHtml(puesto.nombre)}</h4>
                    <span class="puesto-counter">${escapeHtml(puesto.asignados || 0)}/${escapeHtml(puesto.requeridos || 0)}</span>
                </div>
                ${cambiosHtml}

                <div class="field-group worker-search-field">
                    <label>Trabajador</label>
                    <input
                        class="js-rq-worker-search"
                        type="search"
                        placeholder="Buscar y seleccionar trabajador por nombre, DNI o puesto"
                        autocomplete="off"
                        data-rq-id="${escapeHtml(item.id)}"
                        data-detalle-id="${escapeHtml(puesto.id || '')}"
                    >
                    <input type="hidden" class="js-rq-worker-id">
                    <div class="worker-search-results" data-worker-results></div>
                </div>

                <div class="puesto-grid">
                    <div class="field-group">
                        <label>Comentario</label>
                        <textarea class="js-rq-comment" rows="2" placeholder="Comentario">${escapeHtml(comentario)}</textarea>
                    </div>
                    ${availabilityBox(puesto.disponibilidad)}
                </div>

                <div class="puesto-actions-row">
                    <div class="dates-inline">
                        <div class="date-field">
                            <label>Fecha inicio</label>
                            <input class="js-rq-date-start" type="date" value="${escapeHtml(puesto.fecha_inicio_iso || item.fecha_inicio_iso || '')}">
                        </div>
                        <div class="date-field">
                            <label>Fecha fin</label>
                            <input class="js-rq-date-end" type="date" value="${escapeHtml(puesto.fecha_fin_iso || item.fecha_fin_iso || '')}">
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn-assign js-rq-assign ${disableAssign ? 'disabled' : ''}"
                        data-rq-id="${escapeHtml(item.id)}"
                        data-detalle-id="${escapeHtml(puesto.id || '')}"
                        data-puesto="${escapeHtml(puesto.nombre || '')}"
                        ${disableAssign ? 'disabled' : ''}
                    >Asignar</button>
                </div>

                <div data-card-message></div>
                <div class="asignaciones-zone">
                    ${assignedRowsHtml(item, personalAsignado)}
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

        const cambios = Array.isArray(item.cambios) ? item.cambios : [];
        const cambiosBanner = cambios.length > 0
            ? `<div class="cierre-banner advertencia"><strong>Cambios desde RQ Mina</strong>${cambios.map((cambio) => `<p>${cambio.mensaje || 'Cambio pendiente'} ${cambio.fecha ? '(' + cambio.fecha + ')' : ''}</p>`).join('')}</div>`
            : '';

        const searchId = `assignedSearch_${item.id}`;
        const filteredPuestos = puestos.filter((puesto) => matchesSearchInPuesto(puesto, assignedPersonnelQuery));

        detailContainer.innerHTML = `
            <div class="rq-detail-head">
                <h3>${item.mina} - ${item.area}</h3>
                <p>Rango: ${item.fecha_inicio} a ${item.fecha_fin}</p>
            </div>
            ${cierreBanner}
            ${cambiosBanner}
            <div class="assigned-search-box">
                <input type="text" id="${searchId}" value="${escapeHtml(assignedPersonnelQuery)}" placeholder="Buscar personal asignado por nombre...">
            </div>
            <div class="puestos-wrap">
                ${filteredPuestos.length > 0
                    ? filteredPuestos.map((puesto) => puestoCardEditable(puesto, isClosed, item)).join('')
                    : '<div class="table-empty">No hay personal que coincida con la busqueda.</div>'}
            </div>
        `;

        const assignedSearch = document.getElementById(searchId);
        if (!assignedSearch) {
            return;
        }

        const renderPuestos = function (personnelQuery) {
            const filteredPuestos = puestos.filter((puesto) => matchesSearchInPuesto(puesto, personnelQuery));
            detailContainer.querySelector('.puestos-wrap').innerHTML = filteredPuestos.length > 0
                ? filteredPuestos.map((puesto) => puestoCardEditable(puesto, isClosed, item)).join('')
                : '<div class="table-empty">No hay personal que coincida con la búsqueda.</div>';
        };

        assignedSearch.addEventListener('input', (ev) => {
            assignedPersonnelQuery = normalize(ev.target.value);
            renderPuestos(assignedPersonnelQuery);
        });
    }

    function renderWorkerResults(input, items) {
        const card = input.closest('.puesto-card');
        const results = card?.querySelector('[data-worker-results]');
        if (!results) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            results.innerHTML = '<div class="worker-search-empty">Sin coincidencias para la mina y fechas seleccionadas.</div>';
            return;
        }

        results.innerHTML = items.map((item) => {
            const label = `${item.nombre_completo || '-'}${item.documento ? ' - ' + item.documento : ''}`;
            const subline = [item.puesto, item.motivo].filter(Boolean).join(' - ');
            const disabled = item.disponible ? '' : 'disabled';

            return `
                <button
                    type="button"
                    class="worker-result ${item.disponible ? '' : 'is-disabled'}"
                    data-worker-result
                    data-personal-id="${escapeHtml(item.personal_id)}"
                    data-label="${escapeHtml(label)}"
                    ${disabled}
                >
                    <strong>${escapeHtml(label)}</strong>
                    ${subline ? `<span>${escapeHtml(subline)}</span>` : ''}
                </button>
            `;
        }).join('');
    }

    async function searchWorkers(input) {
        const card = input.closest('.puesto-card');
        const results = card?.querySelector('[data-worker-results]');
        const hidden = card?.querySelector('.js-rq-worker-id');
        const queryValue = input.value.trim();
        const fechaInicio = card?.querySelector('.js-rq-date-start')?.value || '';
        const fechaFin = card?.querySelector('.js-rq-date-end')?.value || '';

        if (hidden) {
            hidden.value = '';
        }

        if (!results) {
            return;
        }

        if (queryValue.length < 2) {
            results.innerHTML = '';
            return;
        }

        if (!fechaInicio || !fechaFin) {
            results.innerHTML = '<div class="worker-search-empty">Primero indica fecha inicio y fecha fin.</div>';
            return;
        }

        const params = new URLSearchParams({
            rq_id: input.dataset.rqId || '',
            q: queryValue,
            fecha_inicio: fechaInicio,
            fecha_fin: fechaFin,
        });

        results.innerHTML = '<div class="worker-search-empty">Buscando personal...</div>';

        try {
            const response = await fetch(`${workerSearchUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();

            if (!response.ok) {
                results.innerHTML = `<div class="worker-search-empty is-error">${escapeHtml(data.error || 'No se pudo buscar personal.')}</div>`;
                return;
            }

            renderWorkerResults(input, data.items || []);
        } catch (error) {
            results.innerHTML = '<div class="worker-search-empty is-error">No se pudo completar la busqueda.</div>';
        }
    }

    async function postForm(url, payload) {
        const formData = new FormData();
        Object.entries(payload).forEach(([key, value]) => {
            formData.append(key, value ?? '');
        });

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: formData,
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || data.message || 'No se pudo guardar el cambio.');
        }

        return data;
    }

    detailContainer.addEventListener('input', (event) => {
        const input = event.target.closest('.js-rq-worker-search');
        if (!input) {
            return;
        }

        const existing = workerSearchTimers.get(input);
        if (existing) {
            clearTimeout(existing);
        }

        workerSearchTimers.set(input, setTimeout(() => searchWorkers(input), 280));
    });

    detailContainer.addEventListener('change', (event) => {
        const dateInput = event.target.closest('.js-rq-date-start, .js-rq-date-end');
        if (!dateInput) {
            return;
        }

        const card = dateInput.closest('.puesto-card');
        const workerInput = card?.querySelector('.js-rq-worker-search');
        const workerHidden = card?.querySelector('.js-rq-worker-id');
        const results = card?.querySelector('[data-worker-results]');
        if (workerHidden) {
            workerHidden.value = '';
        }
        if (results) {
            results.innerHTML = '';
        }
        if (workerInput && workerInput.value.trim().length >= 2) {
            searchWorkers(workerInput);
        }
    });

    detailContainer.addEventListener('click', async (event) => {
        const workerOption = event.target.closest('[data-worker-result]');
        if (workerOption) {
            const card = workerOption.closest('.puesto-card');
            card.querySelector('.js-rq-worker-id').value = workerOption.dataset.personalId || '';
            card.querySelector('.js-rq-worker-search').value = workerOption.dataset.label || '';
            card.querySelector('[data-worker-results]').innerHTML = '';
            setCardMessage(card, '', 'ok');
            return;
        }

        const assignButton = event.target.closest('.js-rq-assign');
        if (assignButton) {
            const card = assignButton.closest('.puesto-card');
            const personalId = card.querySelector('.js-rq-worker-id')?.value || '';
            const fechaInicio = card.querySelector('.js-rq-date-start')?.value || '';
            const fechaFin = card.querySelector('.js-rq-date-end')?.value || '';
            const comentario = card.querySelector('.js-rq-comment')?.value || '';

            if (!personalId) {
                setCardMessage(card, 'Selecciona un trabajador de la lista antes de asignar.', 'error');
                return;
            }

            if (!fechaInicio || !fechaFin) {
                setCardMessage(card, 'Indica fecha inicio y fecha fin para la asignacion.', 'error');
                return;
            }

            assignButton.disabled = true;
            assignButton.textContent = 'Asignando...';
            setCardMessage(card, '', 'ok');

            try {
                const data = await postForm(`/rq-proserge/${encodeURIComponent(assignButton.dataset.rqId)}/asignar`, {
                    rq_mina_detalle_id: assignButton.dataset.detalleId,
                    personal_id: personalId,
                    puesto_asignado: assignButton.dataset.puesto,
                    fecha_inicio: fechaInicio,
                    fecha_fin: fechaFin,
                    comentario: comentario,
                });
                replaceRqItem(data.item);
                renderList();
                renderDetail(getSelectedItem());
            } catch (error) {
                assignButton.disabled = false;
                assignButton.textContent = 'Asignar';
                setCardMessage(card, error.message, 'error');
            }
            return;
        }

        const unassignButton = event.target.closest('.js-rq-unassign');
        if (unassignButton) {
            const card = unassignButton.closest('.puesto-card');
            unassignButton.disabled = true;
            unassignButton.textContent = 'Quitando...';
            setCardMessage(card, '', 'ok');

            try {
                const data = await postForm(`/rq-proserge/${encodeURIComponent(unassignButton.dataset.rqId)}/desasignar`, {
                    rq_proserge_detalle_id: unassignButton.dataset.assignmentId,
                });
                replaceRqItem(data.item);
                renderList();
                renderDetail(getSelectedItem());
            } catch (error) {
                unassignButton.disabled = false;
                unassignButton.textContent = 'Desasignar';
                setCardMessage(card, error.message, 'error');
            }
        }
    });

    searchInput.addEventListener('input', (e) => {
        query = normalize(e.target.value);
        renderList();
    });

    renderList();
    renderDetail(null);
})();
</script>

@endsection
