@extends('layouts.app')

@section('title', 'Personal temporal y links - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <style>
        .temporal-action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .temporal-action-buttons form {
            margin: 0;
        }

        .temporal-icon-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .temporal-icon-btn svg {
            width: 16px;
            height: 16px;
        }

        .dg-head-cell {
            display: inline-flex;
            align-items: center;
            gap: 4px;
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

        .dg-filter-icon.is-active {
            background: #07142a;
            color: #fff;
        }

        .dg-filter-popover {
            position: fixed;
            top: 0;
            left: 0;
            min-width: 210px;
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

        .dg-popover-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 8px;
        }

        .filter-compact-select {
            width: 100%;
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

        .temporales-toolbar-search {
            margin-bottom: 14px;
        }

        .temporales-toolbar-search .simple-search-input {
            max-width: 460px;
        }

        .temporales-pagination {
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .temporales-pagination-meta {
            color: #64748b;
            font-size: 13px;
        }

        .temporales-pagination-controls {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .temporales-page-size {
            color: #64748b;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .temporales-page-size strong {
            color: #0f172a;
        }

        .temporales-page-jump {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .temporales-page-jump input {
            width: 76px;
        }

        .personal-pagination {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .personal-pager-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid #d7e0ea;
            border-radius: 8px;
            background: #fff;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .personal-pager-btn:hover:not(:disabled) {
            border-color: #19D3C5;
            color: #0f172a;
            background: #f8fafc;
        }

        .personal-pager-btn.active {
            background: #07142a;
            border-color: #07142a;
            color: #fff;
        }

        .personal-pager-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .personal-pager-ellipsis {
            color: #94a3b8;
            font-weight: 700;
            padding: 0 2px;
        }
    </style>
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Personal temporal y links</h1>
                <p class="page-subtitle">Trabajadores generados desde macro pendientes de completar, validar o activar.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.fichas.import') }}" class="btn btn-primary">Importar macro</a>
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    @if(session('regularization_link'))
        <div class="ficha-alert">
            Link temporal habilitado:
            <a href="{{ session('regularization_link') }}" target="_blank" rel="noopener">{{ session('regularization_link') }}</a>
        </div>
    @endif

    @if(count(session('warning_lines', [])) > 0)
        <div class="ficha-alert ficha-alert-warning">
            @foreach(session('warning_lines', []) as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title"><span id="temporalesCount">{{ $rowsTotal ?? $rows->count() }}</span> registros temporales</h2>
                <p class="ficha-card-subtitle">Los trabajadores con ficha pendiente aparecen aqui, pero el link solo se habilita cuando se presiona el boton correspondiente.</p>
            </div>
        </div>
        <div class="ficha-card-body">
            <div class="temporales-toolbar-search">
                @include('components.ui.simple-search', [
                    'searchId' => 'temporales-search',
                    'placeholder' => 'Buscar por nombre, documento, puesto o contrato...',
                    'showClear' => true,
                ])
            </div>
            <div class="ficha-batch-table-wrap">
                <table class="ficha-batch-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Documento</th>
                            <th>
                                <div class="dg-head-cell">
                                    <span>Estado</span>
                                    <button
                                        type="button"
                                        class="dg-filter-icon js-dg-filter-trigger {{ filled($estadoFilter ?? '') ? 'is-active' : '' }}"
                                        data-target="temporalesEstadoPopover"
                                        title="Filtrar Estado"
                                        aria-label="Filtrar Estado">≡</button>
                                    <div id="temporalesEstadoPopover" class="dg-filter-popover" onclick="event.stopPropagation()">
                                        <label class="dg-popover-label" for="temporalesEstadoSelect">Estado</label>
                                        <select id="temporalesEstadoSelect" class="filter-compact-select">
                                            @foreach(($estadoOptions ?? []) as $estadoKey => $estadoLabel)
                                                <option value="{{ $estadoKey }}" {{ ($estadoFilter ?? '') === (string) $estadoKey ? 'selected' : '' }}>
                                                    {{ $estadoLabel }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </th>
                            <th>Vence</th>
                            <th>Link</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $index => $row)
                            @php
                                $ficha = $row['ficha'];
                                $personal = $row['personal'];
                                $link = $row['link'];
                                $correo = $row['correo'] ?? null;
                                $emailSentAt = $row['email_sent_at'] ?? null;
                                $missingFields = $row['missing_fields'] ?? [];
                                $missingDocuments = $row['missing_documents'] ?? [];
                                $statusClass = match($row['estado_key'] ?? $ficha->estado) {
                                    'LINK_ENVIADO_PENDIENTE' => 'ficha-status-sent',
                                    'LINK_ENVIADO_VENCIDO' => 'ficha-status-expired',
                                    'FICHA_ENVIADA' => 'ficha-status-sent',
                                    'APROBADO' => 'ficha-status-approved',
                                    'LINK_VENCIDO', 'VENCIDO', 'RECHAZADO' => 'ficha-status-expired',
                                    default => 'ficha-status-pending',
                                };
                            @endphp
                            <tr
                                class="js-person-card"
                                data-nombre="{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}"
                                data-dni="{{ trim(($ficha->tipo_documento ?? '') . ' ' . ($ficha->numero_documento ?? '')) }}"
                                data-puesto="{{ $personal?->puesto ?: 'Puesto pendiente' }}"
                                data-contrato="{{ $ficha->macro_tipo_contrato ?: ($personal?->contrato ?: '') }}"
                                data-estado="{{ $row['estado_label'] }}"
                                data-correo="{{ $correo ?? '' }}"
                                data-celular="{{ $personal?->telefono ?: ($ficha->datos_json['telefono'] ?? '') }}">
                                <td>
                                    <strong>{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}</strong>
                                    <div class="ficha-card-subtitle">{{ $personal?->puesto ?: 'Puesto pendiente' }}</div>
                                    @if($correo)
                                        <div class="ficha-card-subtitle">{{ $correo }}</div>
                                    @endif
                                    @if($emailSentAt)
                                        <div class="ficha-card-subtitle" style="color:#2563eb; margin-top:4px;">
                                            Correo enviado: {{ optional($emailSentAt)->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                    @if(count($missingFields) > 0 || count($missingDocuments) > 0)
                                        <div class="ficha-card-subtitle" style="color:#b45309; margin-top:4px;">
                                            Celular: {{ $personal?->telefono ?: ($ficha->datos_json['telefono'] ?? '-') }}
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}</td>
                                <td><span class="ficha-status {{ $statusClass }}">{{ $row['estado_label'] }}</span></td>
                                <td>{{ optional($link?->expires_at)->format('d/m/Y H:i') ?: '-' }}</td>
                                <td>
                                    @if($row['url'])
                                        <div class="ficha-link-box">
                                            <input id="temporalLink{{ $index }}" class="ficha-input" type="text" value="{{ $row['url'] }}" readonly>
                                            <button type="button" class="btn btn-primary js-copy-ficha-link" data-target="temporalLink{{ $index }}">Copiar</button>
                                        </div>
                                    @elseif(!empty($row['can_regularize']))
                                        <span class="ficha-card-subtitle">Link no habilitado todavia. Presiona "Habilitar link temporal".</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="temporal-action-buttons">
                                        <a
                                            href="{{ route('personal.fichas.review', $ficha->id) }}"
                                            class="btn {{ $ficha->estado === 'FICHA_ENVIADA' ? 'btn-primary' : 'btn-outline' }} btn-xs temporal-icon-btn"
                                            title="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}"
                                            aria-label="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                <path d="M14 2v6h6"/>
                                                <path d="M16 13H8"/>
                                                <path d="M16 17H8"/>
                                                <path d="M10 9H8"/>
                                            </svg>
                                        </a>
                                        @if($correo && $row['url'])
                                            <button type="button"
                                                class="btn btn-outline btn-xs js-send-email temporal-icon-btn"
                                                data-send-url="{{ route('personal.fichas.send-email', $ficha->id) }}"
                                                data-idle-title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                                                title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                                                aria-label="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="m22 6-10 7L2 6"/>
                                                </svg>
                                            </button>
                                        @else
                                            <button type="button" class="btn btn-outline btn-xs temporal-icon-btn" disabled title="{{ $correo ? 'No se encontró un link recuperable o aun no fue habilitado' : 'No se encontró correo' }}" aria-label="{{ $correo ? 'No se encontró un link recuperable o aun no fue habilitado' : 'No se encontró correo' }}" style="opacity:.55; cursor:not-allowed;">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M4 4h16v16H4z"/>
                                                    <path d="m22 6-10 7L2 6"/>
                                                </svg>
                                            </button>
                                        @endif
                                        @allowed('personal', 'eliminar')
                                            @if($row['url'] && $link && !$ficha->submitted_at)
                                                <form method="POST" action="{{ route('personal.fichas.extend', $ficha->id) }}" onsubmit="return confirm('Se ampliara el link temporal por 1 dia mas.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs temporal-icon-btn" title="Ampliar 1 día" aria-label="Ampliar 1 día">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="12" cy="12" r="10"/>
                                                            <path d="M12 6v6l4 2"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            @if(!empty($row['can_regularize']))
                                                <form method="POST" action="{{ route('personal.fichas.regularize-link', $ficha->id) }}" onsubmit="return confirm('Se habilitara un link temporal para regularizar la ficha del trabajador.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-xs temporal-icon-btn" title="Habilitar link temporal" aria-label="Habilitar link temporal">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="7" cy="15" r="4"/>
                                                            <path d="M7 13v4"/>
                                                            <path d="M5 15h4"/>
                                                            <path d="M14 7h7"/>
                                                            <path d="M14 12h5"/>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('personal.fichas.destroy', $ficha->id) }}" onsubmit="return confirm('Se eliminara este registro de Temporales y links, pero el trabajador seguira en Personal.');">
                                                @csrf
                                                <button type="submit" class="btn btn-danger btn-xs temporal-icon-btn" title="Quitar de Temporales y links" aria-label="Quitar de Temporales y links">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6h18"/>
                                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                                        <path d="M10 11v6"/>
                                                        <path d="M14 11v6"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        @endallowed
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="ficha-alert">No hay trabajadores temporales por ahora.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="temporales-pagination">
                <div class="temporales-pagination-controls">
                    <div class="temporales-page-size">Mostrar <strong>10</strong> registros</div>
                    <div class="temporales-pagination-meta" id="temporalesPaginationMeta"></div>
                </div>
                <div class="temporales-page-jump">
                    <label for="temporalesPageInput" class="ficha-card-subtitle" style="margin:0;">Ir a pagina</label>
                    <input
                        id="temporalesPageInput"
                        class="ficha-input"
                        type="number"
                        min="1"
                        value="1">
                    <button type="button" id="temporalesPageGo" class="btn btn-outline btn-sm">Ir</button>
                </div>
                <div class="personal-pagination" id="temporalesPaginationWrap"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = Array.from(document.querySelectorAll('.js-person-card'));
    const searchInput = document.getElementById('temporales-search');
    const searchClear = searchInput?.closest('.simple-search-wrapper')?.querySelector('[data-simple-search-clear]');
    const paginationMeta = document.getElementById('temporalesPaginationMeta');
    const paginationWrap = document.getElementById('temporalesPaginationWrap');
    const countBadge = document.getElementById('temporalesCount');
    const pageInput = document.getElementById('temporalesPageInput');
    const pageGo = document.getElementById('temporalesPageGo');
    const estadoSelect = document.getElementById('temporalesEstadoSelect');
    const filterTriggers = Array.from(document.querySelectorAll('.js-dg-filter-trigger'));
    const filterPopovers = Array.from(document.querySelectorAll('.dg-filter-popover'));
    const pageSize = 10;
    let currentPage = 1;

    function closeAllPopovers() {
        filterPopovers.forEach(function (popover) {
            popover.classList.remove('is-open');
        });
    }

    function positionPopover(trigger, popover) {
        const rect = trigger.getBoundingClientRect();
        const popoverWidth = popover.offsetWidth || 210;
        const viewportWidth = window.innerWidth;
        const left = Math.min(
            Math.max(12, rect.right - popoverWidth),
            Math.max(12, viewportWidth - popoverWidth - 12)
        );

        popover.style.top = `${rect.bottom + 8}px`;
        popover.style.left = `${left}px`;
    }

    filterTriggers.forEach(function (trigger) {
        const targetId = trigger.dataset.target;
        const popover = targetId ? document.getElementById(targetId) : null;
        if (!popover) return;

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = !popover.classList.contains('is-open');
            closeAllPopovers();
            if (willOpen) {
                popover.classList.add('is-open');
                positionPopover(trigger, popover);
            }
        });
    });

    document.addEventListener('click', function () {
        closeAllPopovers();
    });

    window.addEventListener('resize', function () {
        filterTriggers.forEach(function (trigger) {
            const targetId = trigger.dataset.target;
            const popover = targetId ? document.getElementById(targetId) : null;
            if (popover && popover.classList.contains('is-open')) {
                positionPopover(trigger, popover);
            }
        });
    });

    if (estadoSelect) {
        estadoSelect.addEventListener('change', function () {
            const hasValue = (estadoSelect.value || '').trim().length > 0;
            filterTriggers.forEach(function (trigger) {
                trigger.classList.toggle('is-active', hasValue);
            });
            renderGrid(true);
        });
    }

    function normalizeText(value) {
        return (value || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function applyFilters() {
        const search = normalizeText(searchInput?.value || '');
        const searchTokens = search.split(' ').filter(Boolean);
        const estado = normalizeText(estadoSelect?.value || '');

        return rows.filter(function (row) {
            const searchable = normalizeText([
                row.dataset.nombre,
                row.dataset.dni,
                row.dataset.puesto,
                row.dataset.contrato,
                row.dataset.estado,
                row.dataset.correo,
                row.dataset.celular,
            ].join(' '));

            if (searchTokens.length && !searchTokens.every(function (token) {
                return searchable.includes(token);
            })) {
                return false;
            }

            if (estado && normalizeText(row.dataset.estado || '') !== estado) {
                return false;
            }

            return true;
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

        const maxVisible = 7;
        const visiblePages = [];

        if (totalPages <= maxVisible) {
            for (let page = 1; page <= totalPages; page += 1) {
                visiblePages.push(page);
            }
        } else {
            const pages = new Set([1, totalPages]);
            const around = Math.max(1, Math.floor((maxVisible - 3) / 2));
            const start = Math.max(2, currentPage - around);
            const end = Math.min(totalPages - 1, currentPage + around);

            for (let page = start; page <= end; page += 1) {
                pages.add(page);
            }

            const ordered = Array.from(pages).sort(function (a, b) {
                return a - b;
            });

            ordered.forEach(function (page, index) {
                if (index > 0 && page - ordered[index - 1] > 1) {
                    visiblePages.push('ellipsis');
                }
                visiblePages.push(page);
            });
        }

        let html = '';
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage - 1) + '"' + (currentPage === 1 ? ' disabled' : '') + '>&lsaquo;</button>';
        visiblePages.forEach(function (page) {
            if (page === 'ellipsis') {
                html += '<span class="personal-pager-ellipsis">...</span>';
                return;
            }

            html += '<button type="button" class="personal-pager-btn ' + (page === currentPage ? 'active' : '') + '" data-page="' + page + '">' + page + '</button>';
        });
        html += '<button type="button" class="personal-pager-btn" data-page="' + (currentPage + 1) + '"' + (currentPage === totalPages ? ' disabled' : '') + '>&rsaquo;</button>';
        paginationWrap.innerHTML = html;
    }

    function clampPage(page, totalPages) {
        if (Number.isNaN(page) || page < 1) {
            return 1;
        }

        if (page > totalPages) {
            return totalPages;
        }

        return page;
    }

    function renderGrid(resetPage) {
        if (resetPage) {
            currentPage = 1;
        }

        const filtered = applyFilters();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = clampPage(currentPage, totalPages);

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;

        rows.forEach(function (row) {
            row.style.display = 'none';
        });

        filtered.slice(start, end).forEach(function (row) {
            row.style.display = 'table-row';
        });

        if (paginationMeta) {
            paginationMeta.textContent = total === 0
                ? '0 resultados'
                : 'Mostrando ' + (start + 1) + '-' + Math.min(end, total) + ' de ' + total + ' registros';
        }

        if (countBadge) {
            countBadge.textContent = String(total);
        }

        if (pageInput) {
            pageInput.max = String(totalPages);
            pageInput.value = String(currentPage);
        }

        renderPagination(totalPages);
    }

    if (searchInput) {
        const syncSearchClear = function () {
            if (searchClear) {
                searchClear.style.display = searchInput.value.trim().length > 0 ? 'flex' : 'none';
            }
        };

        searchInput.addEventListener('input', function () {
            syncSearchClear();
            renderGrid(true);
        });

        syncSearchClear();
    }

    if (searchInput && searchClear) {
        searchClear.addEventListener('click', function () {
            searchInput.value = '';
            renderGrid(true);
            searchClear.style.display = 'none';
            searchInput.focus();
        });
    }

    if (paginationWrap) {
        paginationWrap.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-page]');
            if (!button || button.hasAttribute('disabled')) {
                return;
            }

            currentPage = Number(button.dataset.page || 1);
            renderGrid(false);
        });
    }

    if (pageGo && pageInput) {
        pageGo.addEventListener('click', function () {
            currentPage = Number(pageInput.value || 1);
            renderGrid(false);
        });
        pageInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                currentPage = Number(pageInput.value || 1);
                renderGrid(false);
            }
        });
    }

    document.querySelectorAll('.js-copy-ficha-link').forEach(function (button) {
        button.addEventListener('click', async function () {
            const input = document.getElementById(button.dataset.target);
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                await navigator.clipboard.writeText(input.value);
                button.textContent = 'Copiado';
                setTimeout(() => button.textContent = 'Copiar', 1800);
            } catch (error) {
                document.execCommand('copy');
            }
        });
    });

    document.querySelectorAll('.js-send-email').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) return;

            const originalTitle = button.getAttribute('title') || button.dataset.idleTitle || 'Enviar al correo';
            button.disabled = true;
            button.setAttribute('title', 'Enviando correo...');
            button.setAttribute('aria-label', 'Enviando correo...');

            fetch(button.dataset.sendUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                },
            }).then(function (response) {
                if (response.ok) {
                    return response.json().then(function (data) {
                        button.dataset.idleTitle = 'Volver a enviar correo';
                        button.setAttribute('title', 'Volver a enviar correo');
                        button.setAttribute('aria-label', 'Volver a enviar correo');

                        if (data.mailto_url) {
                            window.location.href = data.mailto_url;
                        }
                    });
                } else {
                    return response.json().then(function (data) {
                        alert(data.error || 'Error al enviar el correo');
                        button.setAttribute('title', originalTitle);
                        button.setAttribute('aria-label', originalTitle);
                    });
                }
            }).catch(function () {
                alert('Error de conexion al enviar el correo');
                button.setAttribute('title', originalTitle);
                button.setAttribute('aria-label', originalTitle);
            }).finally(function () {
                button.disabled = false;
            });
        });
    });

    renderGrid(true);
});
</script>
@endpush
