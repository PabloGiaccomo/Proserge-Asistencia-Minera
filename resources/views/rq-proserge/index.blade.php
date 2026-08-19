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
    $canAssignRqProserge = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_proserge', 'asignar');
@endphp

<div
    class="rrhh-screen"
    id="rrhhScreen"
    data-rq='@json($rqMinaList)'
    data-worker-search-url="{{ route('rq-proserge.personal.buscar') }}"
    data-can-assign="{{ $canAssignRqProserge ? '1' : '0' }}"
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

<div class="rq-modal-backdrop hidden" id="rqActionModal" aria-hidden="true">
    <div class="rq-modal-panel" role="dialog" aria-modal="true" aria-labelledby="rqActionModalTitle">
        <div class="rq-modal-head">
            <div>
                <h3 id="rqActionModalTitle">Actualizar asignacion</h3>
                <p id="rqActionModalText">Completa los datos para registrar el cambio.</p>
            </div>
            <button type="button" class="rq-modal-close" data-rq-modal-close aria-label="Cerrar">X</button>
        </div>
        <div class="rq-modal-body">
            <div class="rq-modal-message" id="rqActionModalMessage"></div>

            <div class="rq-modal-section" data-rq-modal-section="edit">
                <div class="field-group">
                    <label for="rqModalComment">Comentario</label>
                    <textarea id="rqModalComment" rows="3" placeholder="Comentario de la asignacion"></textarea>
                </div>
            </div>

            <div class="rq-modal-section" data-rq-modal-section="replace">
                <div class="field-group">
                    <label for="rqModalWorkerSearch">Trabajador reemplazante</label>
                    <input id="rqModalWorkerSearch" type="search" autocomplete="off" placeholder="Buscar por nombre, DNI o puesto">
                    <input id="rqModalWorkerId" type="hidden">
                    <div class="rq-modal-results" id="rqModalWorkerResults"></div>
                </div>
            </div>

            <div class="rq-modal-section" data-rq-modal-section="motivo">
                <div class="field-group">
                    <label for="rqModalReason" id="rqModalReasonLabel">Motivo</label>
                    <textarea id="rqModalReason" rows="4" placeholder="Describe el motivo"></textarea>
                </div>
            </div>
        </div>
        <div class="rq-modal-footer">
            <button type="button" class="rq-modal-cancel" data-rq-modal-close>Cancelar</button>
            <button type="button" class="rq-modal-submit" id="rqActionModalSubmit">Guardar</button>
        </div>
    </div>
</div>

<style>
.rq-mine-status-flag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: max-content;
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
}
.rq-mine-status-flag.is-enabled {
    background: #dcfce7;
    color: #166534;
}
.rq-mine-status-flag.is-process {
    background: #fef3c7;
    color: #92400e;
}
.rq-coverage-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 8px;
    margin: 12px 0;
}
.rq-coverage-pill {
    border: 1px solid #dbe6f3;
    border-radius: 10px;
    padding: 8px 10px;
    background: #f8fafc;
    color: #10233f;
}
.rq-coverage-pill span {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}
.rq-assignment-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}
.rq-assignment-badge {
    display: inline-flex;
    border-radius: 999px;
    padding: 4px 8px;
    background: #e8f8f6;
    color: #0f766e;
    font-size: 11px;
    font-weight: 800;
}
.rq-assignment-badge.is-warning {
    background: #fef3c7;
    color: #92400e;
}
.rq-assignment-badge.is-muted {
    background: #e2e8f0;
    color: #475569;
}
.rq-assignment-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-end;
}
.rq-assignment-actions button {
    border: 1px solid #c9d8ea;
    border-radius: 8px;
    background: #fff;
    color: #10233f;
    font-weight: 800;
    padding: 7px 9px;
}
.rq-assignment-actions button.is-danger {
    border-color: #fecaca;
    color: #b91c1c;
}
.rq-mine-status-flag.is-not-enabled {
    background: #fee2e2;
    color: #991b1b;
}
.availability-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 4px;
}
.availability-title-row .availability-title {
    margin-bottom: 0;
}
.worker-result-mine {
    display: flex;
    justify-content: flex-end;
}
.worker-result {
    grid-template-columns: minmax(220px, 1.4fr) minmax(160px, 1fr) auto auto;
}
.asignacion-status {
    flex: 0 0 auto;
    margin-left: auto;
}
.rq-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(7, 18, 38, .42);
}
.rq-modal-backdrop.hidden {
    display: none;
}
.rq-modal-panel {
    width: min(560px, 100%);
    max-height: calc(100vh - 36px);
    overflow: auto;
    border: 1px solid #d9e5f3;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 60px rgba(7, 18, 38, .24);
    color: #10233f;
}
.rq-modal-head,
.rq-modal-footer {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 20px;
    border-bottom: 1px solid #e5edf6;
}
.rq-modal-footer {
    justify-content: flex-end;
    border-top: 1px solid #e5edf6;
    border-bottom: 0;
}
.rq-modal-head h3 {
    margin: 0;
    color: #071b3a;
    font-size: 20px;
    font-weight: 900;
}
.rq-modal-head p {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 13px;
}
.rq-modal-close {
    width: 38px;
    height: 38px;
    border: 1px solid #d7e2ef;
    border-radius: 50%;
    background: #fff;
    color: #10233f;
    font-weight: 900;
    cursor: pointer;
}
.rq-modal-body {
    display: grid;
    gap: 14px;
    padding: 18px 20px;
}
.rq-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.rq-modal-section {
    display: grid;
    gap: 12px;
}
.rq-modal-section.is-hidden {
    display: none;
}
.rq-modal-panel input,
.rq-modal-panel select,
.rq-modal-panel textarea {
    width: 100%;
    border: 1px solid #cddbef;
    border-radius: 12px;
    padding: 11px 13px;
    color: #10233f;
    background: #fff;
    font: inherit;
}
.rq-modal-panel textarea {
    resize: vertical;
}
.rq-modal-panel input:focus,
.rq-modal-panel select:focus,
.rq-modal-panel textarea:focus {
    outline: none;
    border-color: #18c7b5;
    box-shadow: 0 0 0 3px rgba(24, 199, 181, .14);
}
.rq-modal-submit,
.rq-modal-cancel {
    border-radius: 12px;
    padding: 11px 16px;
    font-weight: 900;
    cursor: pointer;
}
.rq-modal-submit {
    border: 1px solid #18c7b5;
    background: #18c7b5;
    color: #fff;
    box-shadow: 0 8px 18px rgba(24, 199, 181, .24);
}
.rq-modal-submit:disabled {
    opacity: .6;
    cursor: not-allowed;
}
.rq-modal-cancel {
    border: 1px solid #cddbef;
    background: #fff;
    color: #10233f;
}
.rq-modal-message:not(:empty) {
    border: 1px solid #fecaca;
    border-radius: 12px;
    background: #fff1f2;
    color: #b91c1c;
    padding: 11px 13px;
    font-weight: 800;
}
.rq-modal-results {
    display: grid;
    gap: 8px;
    margin-top: 8px;
    max-height: 250px;
    overflow: auto;
}
.rq-modal-worker {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 6px 12px;
    width: 100%;
    border: 1px solid #d9e5f3;
    border-radius: 12px;
    background: #f8fbff;
    padding: 10px 12px;
    text-align: left;
    color: #10233f;
    cursor: pointer;
}
.rq-modal-worker strong,
.rq-modal-worker small {
    display: block;
}
.rq-modal-worker small {
    color: #64748b;
    margin-top: 2px;
}
.rq-modal-worker .worker-result-status {
    align-self: center;
}
.rq-modal-worker.is-unavailable {
    opacity: .68;
    cursor: not-allowed;
}
@media (max-width: 700px) {
    .asignacion-item {
        align-items: flex-start;
        flex-direction: column;
    }
    .asignacion-status {
        margin-left: 0;
    }
    .rq-modal-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
(function () {
    const screen = document.getElementById('rrhhScreen');
    const rqData = JSON.parse(screen.dataset.rq || '[]');
    const workerSearchUrl = screen.dataset.workerSearchUrl || '';
    const canAssignRqProserge = screen.dataset.canAssign === '1';
    const csrfToken = screen.dataset.csrf || '';

    const searchInput = document.getElementById('rqSearchInput');
    const listContainer = document.getElementById('rqList');
    const counter = document.getElementById('rqCounter');
    const detailContainer = document.getElementById('rqDetailContent');
    const emptyState = document.getElementById('rqEmptyState');
    const workerSearchTimers = new WeakMap();
    const actionModal = document.getElementById('rqActionModal');
    const actionModalTitle = document.getElementById('rqActionModalTitle');
    const actionModalText = document.getElementById('rqActionModalText');
    const actionModalMessage = document.getElementById('rqActionModalMessage');
    const actionModalSubmit = document.getElementById('rqActionModalSubmit');
    const modalComment = document.getElementById('rqModalComment');
    const modalReason = document.getElementById('rqModalReason');
    const modalReasonLabel = document.getElementById('rqModalReasonLabel');
    const modalWorkerSearch = document.getElementById('rqModalWorkerSearch');
    const modalWorkerId = document.getElementById('rqModalWorkerId');
    const modalWorkerResults = document.getElementById('rqModalWorkerResults');

    let selectedId = null;
    let query = '';
    let assignedPersonnelQuery = '';
    let actionModalContext = null;
    let replacementSearchTimer = null;

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

    function mineStatusFlag(status) {
        if (!status || !status.label) {
            return '';
        }

        return `<span class="rq-mine-status-flag ${escapeHtml(status.class || '')}">${escapeHtml(status.label)}</span>`;
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

    function isPuestoComplete(puesto) {
        const required = Number(puesto?.requeridos || 0);
        const assigned = Number(puesto?.asignados || 0);

        return required <= 0 || assigned >= required || String(puesto?.estado_cobertura || '').toUpperCase() === 'COMPLETADO';
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
            if (item.finalizada) {
                cierreLabel = 'Parada finalizada';
                cierreClass = 'cerrado';
            } else if (item.estado_cierre === 'advertencia') {
                cierreLabel = 'A 1 semana de cerrar';
                cierreClass = 'advertencia';
            } else if (item.estado_cierre === 'cerrado') {
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
        const tipo = disponibilidad?.tipo || 'pendiente';
        const disponible = tipo === 'disponible';
        const pendiente = tipo === 'pendiente';
        const lines = Array.isArray(disponibilidad?.lineas) ? disponibilidad.lineas : [];
        const title = pendiente ? 'Pendiente de validar' : (disponible ? 'Disponible' : 'No disponible');
        const className = pendiente ? 'is-neutral' : (disponible ? 'is-ok' : 'is-bad');
        const mineStatus = mineStatusFlag(disponibilidad?.mina_estado || null);

        return `
            <div class="availability-box ${className}">
                <div class="availability-title-row">
                    <div class="availability-title">${title}</div>
                    ${mineStatus}
                </div>
                <div class="availability-lines">
                    ${lines.map((line) => `<p>${escapeHtml(line)}</p>`).join('')}
                </div>
            </div>
        `;
    }

    function pendingAvailability(message = 'Busca y selecciona un trabajador para validar su disponibilidad en esta mina y rango de fechas.') {
        return {
            tipo: 'pendiente',
            lineas: [message],
        };
    }

    function resultAvailability(item) {
        const available = Boolean(item.disponible);
        const lines = Array.isArray(item.lineas) && item.lineas.length
            ? item.lineas
            : [item.motivo || (available ? 'Disponible para asignar en este rango.' : 'No disponible para este rango.')];

        return {
            tipo: available ? 'disponible' : 'no_disponible',
            lineas: lines,
            motivo: item.motivo || '',
            codigo: item.motivo_codigo || null,
            mina_estado: item.mina_estado || null,
        };
    }

    function encodeAvailability(item) {
        return encodeURIComponent(JSON.stringify(resultAvailability(item)));
    }

    function parseAvailability(value) {
        try {
            return JSON.parse(decodeURIComponent(value || ''));
        } catch (error) {
            return pendingAvailability('Selecciona nuevamente al trabajador para validar disponibilidad.');
        }
    }

    function setAssignAvailability(card, available) {
        const assignButton = card?.querySelector('.js-rq-assign');
        if (!assignButton) {
            return;
        }

        const canAssign = Boolean(available) && card?.dataset.capacityFull !== '1';
        assignButton.disabled = !canAssign;
        assignButton.classList.toggle('disabled', !canAssign);
        card.dataset.workerAvailable = canAssign ? '1' : '0';
    }

    function updateAvailabilityBox(card, disponibilidad) {
        const box = card?.querySelector('.availability-box');
        if (box) {
            box.outerHTML = availabilityBox(disponibilidad);
        }

        const hidden = card?.querySelector('.js-rq-worker-id');
        setAssignAvailability(card, disponibilidad?.tipo === 'disponible' && Boolean(hidden?.value));
    }

    function resetWorkerSelection(card, message) {
        const hidden = card?.querySelector('.js-rq-worker-id');
        if (hidden) {
            hidden.value = '';
        }

        updateAvailabilityBox(card, pendingAvailability(message));
    }

    function activeAssignedRows(rows) {
        return (Array.isArray(rows) ? rows : []).filter((row) => {
            const status = String(row.estado || 'ASIGNADO').toUpperCase();
            return status === 'ASIGNADO';
        });
    }

    function matchesSearchInPuesto(puesto, personnelQuery) {
        if (!personnelQuery) return true;

        const assignedRows = activeAssignedRows(puesto.personal_asignado);
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
        const personalAsignado = activeAssignedRows(puesto.personal_asignado);
        const asignaciones = personalAsignado.map((row) => `${row.nombre || '-'} - ${row.comentario || '-'}`.trim());
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
                                    : '<tr><td colspan="4" class="table-empty">Sin asignaciones activas para este puesto.</td></tr>'}
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
                    ${canAssignRqProserge ? `<button type="button" class="btn-assign ${disableAssign ? 'disabled' : ''}" ${disableAssign ? 'disabled' : ''}>Asignar</button>` : ''}
                    ${canAssignRqProserge ? `<button type="button" class="btn-unassign ${disableAssign ? 'disabled' : ''}" ${disableAssign ? 'disabled' : ''}>Desasignar</button>` : ''}
                </div>

                <div class="asignaciones-zone">
                    ${asignaciones.length > 0
                        ? asignaciones.map((item) => `<div class="asignacion-item">${item}</div>`).join('')
                        : '<div class="asignacion-empty">Sin asignaciones activas aun</div>'}
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

    function assignmentBadges(row) {
        const badges = [];
        if (row.tipo_asignacion === 'ADICIONAL') {
            badges.push('<span class="rq-assignment-badge">Adicional</span>');
        }
        if (row.estado && row.estado !== 'ASIGNADO') {
            badges.push(`<span class="rq-assignment-badge is-muted">${escapeHtml(row.estado)}</span>`);
        }
        if (row.cuenta_como_titular) {
            badges.push('<span class="rq-assignment-badge">Cubre titular</span>');
        }

        return badges.length ? `<div class="rq-assignment-badges">${badges.join('')}</div>` : '';
    }

    function assignedRowsHtml(item, personalAsignado) {
        const activeRows = activeAssignedRows(personalAsignado);

        if (!activeRows.length) {
            return '<div class="asignacion-empty">Sin asignaciones activas aun</div>';
        }

        return activeRows.map((row) => `
            <div class="asignacion-item">
                <div class="asignacion-main">
                    <strong>${escapeHtml(row.nombre || '-')}</strong>
                    <span>${escapeHtml(row.comentario || '-')}</span>
                    <small>${escapeHtml(row.fecha_inicio || '-')} a ${escapeHtml(row.fecha_fin || '-')}</small>
                    ${assignmentBadges(row)}
                    ${row.motivo_retiro ? `<small>Motivo retiro: ${escapeHtml(row.motivo_retiro)}</small>` : ''}
                </div>
                <div class="asignacion-status">${mineStatusFlag(row.mina_estado || null)}</div>
                ${canAssignRqProserge && row.estado === 'ASIGNADO' ? `<div class="rq-assignment-actions">
                    <button type="button" class="js-rq-edit-assignment" data-rq-id="${escapeHtml(item.id)}" data-assignment-id="${escapeHtml(row.id || '')}" data-position="${escapeHtml(row.posicion_asignacion || 'TITULAR')}" data-type="${escapeHtml(row.tipo_asignacion || 'REGULAR')}" data-comment="${escapeHtml(row.comentario || '')}">Editar</button>
                    <button type="button" class="js-rq-replace-assignment" data-rq-id="${escapeHtml(item.id)}" data-assignment-id="${escapeHtml(row.id || '')}" data-position="${escapeHtml(row.posicion_asignacion || 'TITULAR')}" data-type="${escapeHtml(row.tipo_asignacion || 'REGULAR')}" data-date-start="${escapeHtml(row.fecha_inicio_iso || item.fecha_inicio_iso || '')}" data-date-end="${escapeHtml(row.fecha_fin_iso || item.fecha_fin_iso || '')}" data-comment="${escapeHtml(row.comentario || '')}">Reemplazar</button>
                    <button type="button" class="is-danger js-rq-retire-assignment" data-rq-id="${escapeHtml(item.id)}" data-assignment-id="${escapeHtml(row.id || '')}">Retirar</button>
                </div>` : ''}
            </div>
        `).join('');
    }

    function puestoCardEditable(puesto, isClosed, item) {
        const capacityFull = isPuestoComplete(puesto);
        const disableAssign = capacityFull || !isAvailable(puesto);
        const comentario = puesto.comentario || '';
        const personalAsignado = activeAssignedRows(puesto.personal_asignado);
        const suggestedPosition = puesto.clasificacion_sugerida?.posicion || 'TITULAR';
        const suggestedType = puesto.clasificacion_sugerida?.tipo || 'REGULAR';
        const disponibilidad = capacityFull
            ? pendingAvailability('Este puesto ya esta completo. Retira o reemplaza una asignacion antes de agregar otra.')
            : puesto.disponibilidad;
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
                                    : '<tr><td colspan="4" class="table-empty">Sin asignaciones activas para este puesto.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        return `
            <div class="puesto-card" data-puesto-id="${escapeHtml(puesto.id || '')}" data-worker-available="${disableAssign ? '0' : '1'}" data-capacity-full="${capacityFull ? '1' : '0'}" data-assignment-position="${escapeHtml(suggestedPosition)}" data-assignment-type="${escapeHtml(suggestedType)}">
                <div class="puesto-head">
                    <h4>${escapeHtml(puesto.nombre)}</h4>
                    <span class="puesto-counter">${escapeHtml(puesto.asignados || 0)}/${escapeHtml(puesto.requeridos || 0)}</span>
                </div>
                ${cambiosHtml}

                ${canAssignRqProserge ? `<div class="field-group worker-search-field">
                    <label>Trabajador</label>
                    <input
                        class="js-rq-worker-search"
                        type="search"
                        placeholder="Buscar y seleccionar trabajador por nombre, DNI o puesto"
                        autocomplete="off"
                        data-rq-id="${escapeHtml(item.id)}"
                        data-detalle-id="${escapeHtml(puesto.id || '')}"
                        ${capacityFull ? 'disabled' : ''}
                    >
                    <input type="hidden" class="js-rq-worker-id">
                    <div class="worker-search-results" data-worker-results></div>
                </div>` : ''}

                <div class="rq-coverage-grid">
                    <div class="rq-coverage-pill"><span>Titulares</span>${escapeHtml(puesto.titulares_asignados || 0)} / ${escapeHtml(puesto.titulares_requeridos || 0)}</div>
                    <div class="rq-coverage-pill"><span>Adicionales</span>${escapeHtml(puesto.adicionales || 0)}</div>
                    <div class="rq-coverage-pill"><span>Estado</span>${escapeHtml(puesto.estado_cobertura || 'PENDIENTE')}</div>
                </div>

                <div class="puesto-grid">
                    <div class="field-group">
                        <label>Comentario</label>
                        <textarea class="js-rq-comment" rows="2" placeholder="Comentario" ${capacityFull ? 'readonly' : ''}>${escapeHtml(comentario)}</textarea>
                    </div>
                    ${availabilityBox(disponibilidad)}
                </div>

                ${canAssignRqProserge ? `<div class="puesto-actions-row">
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
                </div>` : ''}

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
            const closedMessage = item.finalizada
                ? (item.bloqueo_edicion_mensaje || 'La parada ya finalizo. Las asignaciones y el seguimiento quedaron bloqueados.')
                : 'RQ cerrado: no se permiten modificaciones. Solo visualización del personal de parada.';
            cierreBanner = `<div class="cierre-banner cerrado">${escapeHtml(closedMessage)}</div>`;
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
            results.innerHTML = '<div class="worker-search-empty">No se encontro personal con ese texto.</div>';
            return;
        }

        results.innerHTML = items.map((item) => {
            const name = item.nombre_completo || '-';
            const document = item.documento || '';
            const label = `${name}${document ? ' - ' + document : ''}`;
            const puesto = item.puesto || 'Puesto no registrado';
            const disponibilidad = resultAvailability(item);
            const motivo = disponibilidad.lineas[0] || item.motivo || 'Disponible para asignar en este rango.';
            const statusText = item.disponible ? 'Disponible' : 'No disponible';
            const mineStatus = mineStatusFlag(item.mina_estado || null);

            return `
                <button
                    type="button"
                    class="worker-result ${item.disponible ? '' : 'is-unavailable'}"
                    data-worker-result
                    data-personal-id="${escapeHtml(item.personal_id)}"
                    data-label="${escapeHtml(label)}"
                    data-available="${item.disponible ? '1' : '0'}"
                    data-availability="${encodeAvailability(item)}"
                >
                    <span class="worker-result-main">
                        <strong>${escapeHtml(name)}</strong>
                        ${document ? `<small>DNI ${escapeHtml(document)}</small>` : ''}
                    </span>
                    <span class="worker-result-detail">${escapeHtml(puesto)}</span>
                    <span class="worker-result-status ${item.disponible ? 'is-ok' : 'is-bad'}">${escapeHtml(statusText)}</span>
                    <span class="worker-result-mine">${mineStatus}</span>
                    <span class="worker-result-reason">${escapeHtml(motivo)}</span>
                </button>
            `;
        }).join('');
    }

    async function searchWorkers(input) {
        const card = input.closest('.puesto-card');
        const results = card?.querySelector('[data-worker-results]');
        const queryValue = input.value.trim();
        const fechaInicio = card?.querySelector('.js-rq-date-start')?.value || '';
        const fechaFin = card?.querySelector('.js-rq-date-end')?.value || '';

        if (!results) {
            return;
        }

        resetWorkerSelection(card, 'Selecciona un trabajador de la lista para validar disponibilidad.');

        if (queryValue.length < 2) {
            results.innerHTML = '';
            return;
        }

        if (!fechaInicio || !fechaFin) {
            resetWorkerSelection(card, 'Primero indica fecha inicio y fecha fin.');
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

    async function requestForm(url, payload, method = 'POST') {
        const formData = new FormData();
        Object.entries(payload).forEach(([key, value]) => {
            formData.append(key, value ?? '');
        });

        const response = await fetch(url, {
            method,
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

    async function postForm(url, payload) {
        return requestForm(url, payload, 'POST');
    }

    function modalSection(name, visible) {
        actionModal?.querySelectorAll(`[data-rq-modal-section="${name}"]`).forEach((section) => {
            section.classList.toggle('is-hidden', !visible);
        });
    }

    function setModalMessage(message = '') {
        if (actionModalMessage) {
            actionModalMessage.textContent = message;
        }
    }

    function closeActionModal() {
        actionModal?.classList.add('hidden');
        actionModal?.setAttribute('aria-hidden', 'true');
        actionModalContext = null;
        setModalMessage('');
        if (actionModalSubmit) {
            actionModalSubmit.disabled = false;
            actionModalSubmit.textContent = 'Guardar';
        }
    }

    function openActionModal(mode, button, card) {
        actionModalContext = {
            mode,
            card,
            rqId: button.dataset.rqId || '',
            assignmentId: button.dataset.assignmentId || '',
            dateStart: button.dataset.dateStart || '',
            dateEnd: button.dataset.dateEnd || '',
            position: button.dataset.position || 'TITULAR',
            type: button.dataset.type || 'REGULAR',
            comment: button.dataset.comment || '',
        };

        setModalMessage('');
        modalSection('edit', mode === 'edit');
        modalSection('replace', mode === 'replace');
        modalSection('motivo', mode === 'retire' || mode === 'replace');

        if (mode === 'edit') {
            actionModalTitle.textContent = 'Editar comentario';
            actionModalText.textContent = 'Actualiza la nota visible de esta asignacion.';
            actionModalSubmit.textContent = 'Guardar cambios';
            modalComment.value = button.dataset.comment || '';
        }

        if (mode === 'retire') {
            actionModalTitle.textContent = 'Retirar asignacion';
            actionModalText.textContent = 'Registra el motivo para retirar al trabajador sin perder trazabilidad.';
            actionModalSubmit.textContent = 'Retirar';
            modalReasonLabel.textContent = 'Motivo del retiro';
            modalReason.value = '';
        }

        if (mode === 'replace') {
            actionModalTitle.textContent = 'Reemplazar trabajador';
            actionModalText.textContent = 'Busca el reemplazante y registra el motivo del cambio.';
            actionModalSubmit.textContent = 'Guardar reemplazo';
            modalReasonLabel.textContent = 'Motivo del reemplazo';
            modalWorkerSearch.value = '';
            modalWorkerId.value = '';
            modalWorkerResults.innerHTML = '';
            modalReason.value = '';
        }

        actionModal?.classList.remove('hidden');
        actionModal?.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => {
            if (mode === 'replace') {
                modalWorkerSearch?.focus();
            } else if (mode === 'retire') {
                modalReason?.focus();
            } else {
                modalComment?.focus();
            }
        }, 0);
    }

    async function searchReplacementWorkers() {
        if (!actionModalContext || actionModalContext.mode !== 'replace') {
            return;
        }

        const queryValue = modalWorkerSearch.value.trim();
        modalWorkerId.value = '';

        if (queryValue.length < 2) {
            modalWorkerResults.innerHTML = '';
            return;
        }

        if (!actionModalContext.dateStart || !actionModalContext.dateEnd) {
            modalWorkerResults.innerHTML = '<div class="worker-search-empty is-error">No se encontro el rango de fechas de la asignacion.</div>';
            return;
        }

        const params = new URLSearchParams({
            rq_id: actionModalContext.rqId,
            q: queryValue,
            fecha_inicio: actionModalContext.dateStart,
            fecha_fin: actionModalContext.dateEnd,
        });

        modalWorkerResults.innerHTML = '<div class="worker-search-empty">Buscando trabajador...</div>';

        try {
            const response = await fetch(`${workerSearchUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();

            if (!response.ok) {
                modalWorkerResults.innerHTML = `<div class="worker-search-empty is-error">${escapeHtml(data.error || 'No se pudo buscar personal.')}</div>`;
                return;
            }

            const items = Array.isArray(data.items) ? data.items : [];
            if (items.length === 0) {
                modalWorkerResults.innerHTML = '<div class="worker-search-empty">No se encontro personal con ese texto.</div>';
                return;
            }

            modalWorkerResults.innerHTML = items.map((item) => {
                const name = item.nombre_completo || '-';
                const document = item.documento || '';
                const label = `${name}${document ? ' - ' + document : ''}`;
                const statusText = item.disponible ? 'Disponible' : 'No disponible';
                const motivo = Array.isArray(item.lineas) && item.lineas.length ? item.lineas[0] : (item.motivo || '');

                return `
                    <button
                        type="button"
                        class="rq-modal-worker ${item.disponible ? '' : 'is-unavailable'}"
                        data-rq-replace-worker
                        data-personal-id="${escapeHtml(item.personal_id)}"
                        data-label="${escapeHtml(label)}"
                        ${item.disponible ? '' : 'disabled'}
                    >
                        <span>
                            <strong>${escapeHtml(name)}</strong>
                            <small>${document ? 'DNI ' + escapeHtml(document) + ' - ' : ''}${escapeHtml(item.puesto || 'Puesto no registrado')}</small>
                            ${motivo ? `<small>${escapeHtml(motivo)}</small>` : ''}
                        </span>
                        <span class="worker-result-status ${item.disponible ? 'is-ok' : 'is-bad'}">${escapeHtml(statusText)}</span>
                    </button>
                `;
            }).join('');
        } catch (error) {
            modalWorkerResults.innerHTML = '<div class="worker-search-empty is-error">No se pudo completar la busqueda.</div>';
        }
    }

    async function submitActionModal() {
        if (!actionModalContext) {
            return;
        }

        const context = actionModalContext;
        const card = context.card;
        setModalMessage('');
        actionModalSubmit.disabled = true;
        actionModalSubmit.textContent = 'Guardando...';

        try {
            let data;

            if (context.mode === 'edit') {
                data = await requestForm(`/rq-proserge/${encodeURIComponent(context.rqId)}/asignaciones/${encodeURIComponent(context.assignmentId)}`, {
                    posicion_asignacion: context.position || 'TITULAR',
                    tipo_asignacion: context.type || 'REGULAR',
                    comentario: modalComment.value || '',
                }, 'PATCH');
            }

            if (context.mode === 'retire') {
                const motivo = modalReason.value.trim();
                if (!motivo) {
                    throw new Error('El motivo de retiro es obligatorio.');
                }

                data = await postForm(`/rq-proserge/${encodeURIComponent(context.rqId)}/asignaciones/${encodeURIComponent(context.assignmentId)}/retirar`, {
                    motivo,
                });
            }

            if (context.mode === 'replace') {
                const personalId = modalWorkerId.value || '';
                const motivo = modalReason.value.trim();

                if (!personalId || !motivo) {
                    throw new Error('Selecciona el trabajador reemplazante e indica el motivo.');
                }

                data = await postForm(`/rq-proserge/${encodeURIComponent(context.rqId)}/asignaciones/${encodeURIComponent(context.assignmentId)}/reemplazar`, {
                    personal_id: personalId,
                    motivo,
                    comentario: context.comment || '',
                    fecha_inicio: context.dateStart || '',
                    fecha_fin: context.dateEnd || '',
                    posicion_asignacion: context.position || 'TITULAR',
                    tipo_asignacion: context.type || 'REGULAR',
                });
            }

            replaceRqItem(data.item);
            closeActionModal();
            renderList();
            renderDetail(getSelectedItem());
        } catch (error) {
            actionModalSubmit.disabled = false;
            actionModalSubmit.textContent = context.mode === 'retire'
                ? 'Retirar'
                : (context.mode === 'replace' ? 'Guardar reemplazo' : 'Guardar cambios');
            setModalMessage(error.message || 'No se pudo guardar el cambio.');
            if (card) {
                setCardMessage(card, error.message || 'No se pudo guardar el cambio.', 'error');
            }
        }
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
        const results = card?.querySelector('[data-worker-results]');

        if (card?.dataset.capacityFull === '1') {
            return;
        }

        resetWorkerSelection(card, 'Las fechas cambiaron. Vuelve a seleccionar al trabajador para validar disponibilidad.');

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
            if (card?.dataset.capacityFull === '1') {
                setCardMessage(card, 'Este puesto ya esta completo. Retira o reemplaza una asignacion antes de agregar otra.', 'error');
                return;
            }
            card.querySelector('.js-rq-worker-id').value = workerOption.dataset.personalId || '';
            card.querySelector('.js-rq-worker-search').value = workerOption.dataset.label || '';
            card.querySelector('[data-worker-results]').innerHTML = '';
            const disponibilidad = parseAvailability(workerOption.dataset.availability);
            updateAvailabilityBox(card, disponibilidad);
            setCardMessage(
                card,
                disponibilidad.tipo === 'disponible' ? '' : 'No se puede asignar hasta resolver la disponibilidad indicada.',
                disponibilidad.tipo === 'disponible' ? 'ok' : 'error'
            );
            return;
        }

        const assignButton = event.target.closest('.js-rq-assign');
        if (assignButton) {
            const card = assignButton.closest('.puesto-card');
            const personalId = card.querySelector('.js-rq-worker-id')?.value || '';
            const fechaInicio = card.querySelector('.js-rq-date-start')?.value || '';
            const fechaFin = card.querySelector('.js-rq-date-end')?.value || '';
            const comentario = card.querySelector('.js-rq-comment')?.value || '';
            const posicion = card.dataset.assignmentPosition || 'TITULAR';
            const tipoAsignacion = card.dataset.assignmentType || 'REGULAR';

            if (card.dataset.capacityFull === '1') {
                setCardMessage(card, 'Este puesto ya esta completo. Retira o reemplaza una asignacion antes de agregar otra.', 'error');
                return;
            }

            if (!personalId) {
                setCardMessage(card, 'Selecciona un trabajador de la lista antes de asignar.', 'error');
                return;
            }

            if (card.dataset.workerAvailable !== '1') {
                setCardMessage(card, 'El trabajador seleccionado no esta disponible para esta mina o rango de fechas.', 'error');
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
                    posicion_asignacion: posicion,
                    tipo_asignacion: tipoAsignacion,
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

        const editAssignmentButton = event.target.closest('.js-rq-edit-assignment');
        if (editAssignmentButton) {
            const card = editAssignmentButton.closest('.puesto-card');
            openActionModal('edit', editAssignmentButton, card);
            return;
        }

        const retireAssignmentButton = event.target.closest('.js-rq-retire-assignment');
        if (retireAssignmentButton) {
            const card = retireAssignmentButton.closest('.puesto-card');
            openActionModal('retire', retireAssignmentButton, card);
            return;
        }

        const replaceAssignmentButton = event.target.closest('.js-rq-replace-assignment');
        if (replaceAssignmentButton) {
            const card = replaceAssignmentButton.closest('.puesto-card');
            openActionModal('replace', replaceAssignmentButton, card);
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

    actionModal?.addEventListener('click', (event) => {
        if (event.target === actionModal || event.target.closest('[data-rq-modal-close]')) {
            closeActionModal();
            return;
        }

        const workerButton = event.target.closest('[data-rq-replace-worker]');
        if (workerButton) {
            modalWorkerId.value = workerButton.dataset.personalId || '';
            modalWorkerSearch.value = workerButton.dataset.label || '';
            modalWorkerResults.innerHTML = '';
            setModalMessage('');
        }
    });

    modalWorkerSearch?.addEventListener('input', () => {
        window.clearTimeout(replacementSearchTimer);
        replacementSearchTimer = window.setTimeout(searchReplacementWorkers, 280);
    });

    actionModalSubmit?.addEventListener('click', submitActionModal);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && actionModal && !actionModal.classList.contains('hidden')) {
            closeActionModal();
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
