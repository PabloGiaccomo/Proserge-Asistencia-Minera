@extends('layouts.app')

@section('title', 'Personal - Proserge')

@section('content')
<div class="module-page personal-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Personal</h1>
                <p class="page-subtitle">Gestión y búsqueda de trabajadores</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.importar') }}" class="btn btn-outline btn-icon-only" title="Importar personal desde Excel" aria-label="Importar personal desde Excel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </a>
                <a href="{{ route('personal.index', array_merge(request()->query(), ['export' => 'excel'])) }}" class="btn btn-outline btn-icon-only" title="Exportar personal a Excel" aria-label="Exportar personal a Excel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                </a>
                <a href="{{ route('personal.create') }}" class="btn btn-primary" title="Nuevo trabajador" aria-label="Nuevo trabajador">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Nuevo trabajador
                </a>
            </div>
        </div>
    </div>

    <!-- Filter Panel Toggle + Global Search -->
    <div class="flex flex-col md:flex-row gap-4 mb-4">
        <button type="button" id="filterToggle" class="btn btn-outline flex items-center gap-2" onclick="toggleFilters()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="22 3 2 3 10 12.69 10 21 14 21 14 12.69 22 3"/>
            </svg>
            <span>Filtros</span>
            <span id="filterBadge" class="hidden badge bg-primary text-white">0</span>
        </button>
        
        @include('components.ui.global-search', [
            'searchId' => 'personal-search',
            'placeholder' => 'Buscar por nombre, DNI, mina o contrato...',
            'showClear' => true,
            'minChars' => 2
        ])
    </div>

    <!-- Filter Panel -->
    <form method="GET" action="{{ route('personal.index') }}" class="filter-panel" id="filterPanel" style="display: none;">
        <div class="filter-panel-head">
            <div class="filter-panel-title-wrap">
                <h2 class="filter-panel-title">Filtros de Personal</h2>
                <p class="filter-panel-subtitle">Refina el listado por estado, tipo y ubicación.</p>
            </div>
            <div class="filter-panel-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">Limpiar</a>
            </div>
        </div>

        <div class="filter-panel-row">
            <!-- Ordenar por -->
            <div class="filter-group">
                <label class="filter-label">Ordenar por</label>
                <select class="form-control form-control-sm" name="sort">
                    <option value="nombre" {{ request('sort') == 'nombre' ? 'selected' : '' }}>Nombre</option>
                    <option value="puesto" {{ request('sort') == 'puesto' ? 'selected' : '' }}>Puesto</option>
                    <option value="contrato" {{ request('sort') == 'contrato' ? 'selected' : '' }}>Contrato</option>
                    <option value="estado" {{ request('sort') == 'estado' ? 'selected' : '' }}>Estado</option>
                    <option value="dni" {{ request('sort') == 'dni' ? 'selected' : '' }}>DNI</option>
                </select>
            </div>

            <!-- Estado -->
            <div class="filter-group">
                <label class="filter-label">Estado</label>
                <div class="filter-chips-inline">
                    <a href="{{ request()->fullUrlWithQuery(['estado' => '']) }}" class="chip chip-sm {{ request('estado', '') == '' ? 'active' : '' }}">Todos</a>
                    <a href="{{ request()->fullUrlWithQuery(['estado' => 'activo']) }}" class="chip chip-sm {{ request('estado') == 'activo' ? 'active' : '' }}">Activos</a>
                    <a href="{{ request()->fullUrlWithQuery(['estado' => 'inactivo']) }}" class="chip chip-sm {{ request('estado') == 'inactivo' ? 'active' : '' }}">Inactivos</a>
                </div>
            </div>

            <!-- Tipo -->
            <div class="filter-group">
                <label class="filter-label">Tipo</label>
                <div class="filter-chips-inline">
                    <a href="{{ request()->fullUrlWithQuery(['tipo' => '']) }}" class="chip chip-sm {{ request('tipo', '') == '' ? 'active' : '' }}">Todos</a>
                    <a href="{{ request()->fullUrlWithQuery(['tipo' => 'supervisor']) }}" class="chip chip-sm {{ request('tipo') == 'supervisor' ? 'active' : '' }}">Supervisores</a>
                    <a href="{{ request()->fullUrlWithQuery(['tipo' => 'trabajador']) }}" class="chip chip-sm {{ request('tipo') == 'trabajador' ? 'active' : '' }}">Trabajadores</a>
                </div>
            </div>
        </div>

        <div class="filter-panel-row">
            <!-- Mina -->
            <div class="filter-group">
                <label class="filter-label">Mina</label>
                <select class="form-control form-control-sm" name="mina">
                    <option value="">Todas las minas</option>
                    <option value="mina1" {{ request('mina') == 'mina1' ? 'selected' : '' }}>Mina 1</option>
                    <option value="mina2" {{ request('mina') == 'mina2' ? 'selected' : '' }}>Mina 2</option>
                    <option value="mina3" {{ request('mina') == 'mina3' ? 'selected' : '' }}>Mina 3</option>
                    <option value="taller" {{ request('mina') == 'taller' ? 'selected' : '' }}>Taller</option>
                    <option value="oficina" {{ request('mina') == 'oficina' ? 'selected' : '' }}>Oficina</option>
                </select>
            </div>

            <!-- Estado Mina -->
            <div class="filter-group">
                <label class="filter-label">Estado en Mina</label>
                <div class="filter-chips-inline">
                    <a href="{{ request()->fullUrlWithQuery(['mina_estado' => '']) }}" class="chip chip-sm {{ request('mina_estado', '') == '' ? 'active' : '' }}">Todos</a>
                    <a href="{{ request()->fullUrlWithQuery(['mina_estado' => 'habilitado']) }}" class="chip chip-sm {{ request('mina_estado') == 'habilitado' ? 'active' : '' }}">Habilitado</a>
                    <a href="{{ request()->fullUrlWithQuery(['mina_estado' => 'proceso']) }}" class="chip chip-sm {{ request('mina_estado') == 'proceso' ? 'active' : '' }}">En Proceso</a>
                </div>
            </div>

            <!-- Contrato -->
            <div class="filter-group">
                <label class="filter-label">Contrato</label>
                <select class="form-control form-control-sm" name="contrato">
                    <option value="">Todos</option>
                    <option value="regimen" {{ request('contrato') == 'regimen' ? 'selected' : '' }}>Régimen</option>
                    <option value="fijo" {{ request('contrato') == 'fijo' ? 'selected' : '' }}>Fijo</option>
                    <option value="intermitente" {{ request('contrato') == 'intermitente' ? 'selected' : '' }}>Intermitente</option>
                    <option value="indeterminado" {{ request('contrato') == 'indeterminado' ? 'selected' : '' }}>Indeterminado</option>
                </select>
            </div>
        </div>
        
        <div class="filter-panel-footer">
            <button type="submit" class="btn btn-primary">Aplicar filtros</button>
        </div>
    </form>

    <!-- Results -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de Personal</span>
            <span class="card-badge">{{ count($trabajadores ?? []) }} trabajadores</span>
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
                        <div class="person-card js-person-card {{ !$trabajador['activo'] ? 'inactive' : '' }}" data-worker='@json($trabajador)' onclick="showWorkerDetail(this)">
                            <div class="person-avatar">
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
                                    @if(!empty($trabajador['supervisor']))
                                        <span class="person-badge supervisor">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                                <circle cx="12" cy="7" r="4"/>
                                            </svg>
                                            Supervisor
                                        </span>
                                    @endif
                                    @foreach($trabajador['minas'] ?? [] as $mina)
                                        @php
                                        $minaLower = strtolower($mina ?? '');
                                        $isCentro = str_contains($minaLower, 'taller') || str_contains($minaLower, 'oficina');
                                        $isMina = !$isCentro;
                                        $isProceso = $isMina && (($trabajador['minas_estado'] ?? [])[$mina] ?? '') === 'proceso';
                                        @endphp
                                        <span class="person-badge mine {{ $isProceso ? 'mine-proceso' : '' }}">{{ $mina }}</span>
                                    @endforeach
                                    <span class="person-badge {{ ($trabajador['activo'] ?? true) ? 'status-active' : 'status-inactive' }}">
                                        {{ ($trabajador['activo'] ?? true) ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </div>
                            </div>
                            <div class="person-actions" onclick="event.stopPropagation()">
                                <a href="{{ route('personal.edit', $trabajador['id'] ?? '') }}" class="person-action-btn" title="Editar">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                </a>
                                <button class="person-action-btn danger" title="Eliminar">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M3 6h18"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    </svg>
                                </button>
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

<style>
.personal-pagination-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
}

.page-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.personal-page-size {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #475569;
}

.personal-page-size-select {
    border: 1px solid #d7e0ed;
    border-radius: 8px;
    padding: 6px 8px;
    background: #fff;
    color: #334155;
    font-size: 13px;
}

.personal-pagination-info {
    font-size: 12px;
    color: #64748b;
}

.personal-pagination {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.personal-pager-btn {
    border: 1px solid #d7e0ed;
    background: #fff;
    color: #475569;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.personal-pager-btn:hover {
    background: #f8fbff;
}

.personal-pager-btn.active {
    border-color: #19d3c5;
    background: rgba(25, 211, 197, 0.12);
    color: #0f766e;
}

.personal-pager-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.btn-icon-only {
    width: 42px;
    height: 42px;
    padding: 0px 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 8px;
    border-width: 1.5px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.btn-outline.btn-icon-only {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #334155;
}

.btn-outline.btn-icon-only:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}
</style>
@endsection

@push('scripts')
<script>
function toggleFilters() {
    const panel = document.getElementById('filterPanel');
    const btn = document.getElementById('filterToggle');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        btn.classList.add('active');
    } else {
        panel.style.display = 'none';
        btn.classList.remove('active');
    }
}

function showWorkerDetail(card) {
    const worker = JSON.parse(card.dataset.worker || '{}');
    const modal = document.getElementById('workerDetailModal');
    if (!modal || !worker.nombre) return;

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
    const ingreso = fechas.ingreso ? new Date(fechas.ingreso).toLocaleDateString('es-PE') : '-';
    const vac = fechas.vacaciones || {};
    const vacStr = vac.inicio ? `${new Date(vac.inicio).toLocaleDateString('es-PE')} - ${new Date(vac.fin).toLocaleDateString('es-PE')}` : '-';
    const enf = fechas.enfermo || {};
    const enfStr = enf.inicio ? `${new Date(enf.inicio).toLocaleDateString('es-PE')} - ${new Date(enf.fin).toLocaleDateString('es-PE')}` : '-';
    const par = fechas.parada || {};
    const parStr = par.inicio ? `${new Date(par.inicio).toLocaleDateString('es-PE')} - ${new Date(par.fin).toLocaleDateString('es-PE')}` : '-';

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
                            <span class="detail-value">${worker.telefono || '-'}</span>
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
    const cards = Array.from(document.querySelectorAll('.js-person-card'));
    if (cards.length === 0) {
        return;
    }

    const searchInput = document.getElementById('personal-search') || document.querySelector('[data-search-input]');
    const pageSizeSelect = document.getElementById('personalPageSize');
    const paginationInfo = document.getElementById('personalPaginationInfo');
    const paginationWrap = document.getElementById('personalPagination');

    let currentPage = 1;
    let pageSize = 9;

    function getFilteredCards() {
        const term = (searchInput ? searchInput.value : '').trim().toLowerCase();
        if (!term) {
            return cards;
        }

        return cards.filter(function (card) {
            return card.innerText.toLowerCase().includes(term);
        });
    }

    function renderPagination(totalPages) {
        if (!paginationWrap) {
            return;
        }

        if (totalPages <= 1) {
            paginationWrap.innerHTML = '';
            return;
        }

        let html = '';
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + '>Anterior</button>';

        for (let page = 1; page <= totalPages; page++) {
            html += '<button type="button" class="personal-pager-btn ' + (page === currentPage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
        }

        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage === totalPages ? 'disabled' : '') + '>Siguiente</button>';
        paginationWrap.innerHTML = html;
    }

    function renderPersonalPage() {
        const filteredCards = getFilteredCards();
        const totalItems = filteredCards.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));

        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        cards.forEach(function (card) {
            card.style.display = 'none';
        });

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;

        filteredCards.slice(start, end).forEach(function (card) {
            card.style.display = '';
        });

        if (paginationInfo) {
            if (totalItems === 0) {
                paginationInfo.textContent = '0 resultados';
            } else {
                paginationInfo.textContent = 'Mostrando ' + (start + 1) + '-' + Math.min(end, totalItems) + ' de ' + totalItems;
            }
        }

        renderPagination(totalPages);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            currentPage = 1;
            renderPersonalPage();
        });
    }

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            const value = parseInt(pageSizeSelect.value, 10);
            pageSize = Number.isNaN(value) ? 9 : value;
            currentPage = 1;
            renderPersonalPage();
        });
    }

    if (paginationWrap) {
        paginationWrap.addEventListener('click', function (event) {
            const btn = event.target.closest('button[data-page]');
            if (!btn || btn.disabled) {
                return;
            }

            const nextPage = parseInt(btn.dataset.page, 10);
            if (Number.isNaN(nextPage) || nextPage < 1) {
                return;
            }

            currentPage = nextPage;
            renderPersonalPage();
        });
    }

    renderPersonalPage();
});
</script>
@endpush
