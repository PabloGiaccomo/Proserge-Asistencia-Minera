@extends('layouts.app')

@section('title', 'Puestos - Personal')

@section('content')
@php
    $puestoPermissions = session('user.permissions', []);
    $canCreatePuesto = \App\Support\Rbac\PermissionMatrix::allowsDirect($puestoPermissions, 'personal_puestos', 'crear')
        || \App\Support\Rbac\PermissionMatrix::allowsDirect($puestoPermissions, 'personal', 'gestionar_puestos');
    $canEditPuesto = \App\Support\Rbac\PermissionMatrix::allowsDirectAny($puestoPermissions, 'personal_puestos', ['editar', 'actualizar'])
        || \App\Support\Rbac\PermissionMatrix::allowsDirect($puestoPermissions, 'personal', 'gestionar_puestos');
    $canDeletePuesto = \App\Support\Rbac\PermissionMatrix::allowsDirect($puestoPermissions, 'personal_puestos', 'eliminar')
        || \App\Support\Rbac\PermissionMatrix::allowsDirect($puestoPermissions, 'personal', 'gestionar_puestos');
@endphp

<div class="module-page puestos-page">
    <div class="page-header puestos-header">
        <div>
            <h1 class="page-title">Puestos y funciones</h1>
            <p class="page-subtitle">Cada puesto tiene un ID estable. Editar el puesto actualiza el catalogo sin desligar a los trabajadores asociados.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver a Personal</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="puestos-grid">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Listado de puestos</span>
                <span class="card-badge" id="puestosCount">{{ $puestos->count() }} puestos</span>
            </div>
            <div class="card-body">
                @if($puestos->isEmpty())
                    <div class="empty-state">
                        <h3 class="empty-title">Aun no hay puestos registrados</h3>
                        <p class="empty-description">Crea el primer puesto para asociar funciones al cargo de los trabajadores.</p>
                    </div>
                @else
                    <div class="puestos-list-tools">
                        <input id="puestosSearch" type="search" class="form-control puestos-search" placeholder="Buscar por puesto, ID o funcion">
                        <label class="puestos-page-size">
                            Mostrar
                            <select id="puestosPageSizeTop" class="puestos-page-size-select"></select>
                            puestos
                        </label>
                    </div>

                    <div class="puestos-card-list" id="puestosList">
                        @foreach($puestos as $puesto)
                            <div
                                class="puesto-edit-card"
                                data-puesto-card
                                data-search="{{ mb_strtolower($puesto->nombre . ' ' . $puesto->id . ' ' . ($puesto->funciones ?? '')) }}"
                            >
                                <div class="puesto-edit-head">
                                    <div>
                                        <strong class="puesto-title">{{ $puesto->nombre }}</strong>
                                        <div class="puesto-id">ID: {{ $puesto->id }}</div>
                                        <div class="puesto-meta">
                                            <span class="puesto-pill">{{ $puesto->trabajadores_count }} trabajador(es)</span>
                                            <span class="puesto-pill">{{ $puesto->activo ? 'Activo' : 'Inactivo' }}</span>
                                        </div>
                                    </div>
                                </div>

                                @if($canEditPuesto)
                                    <form method="POST" action="{{ route('personal.puestos.update', $puesto->id) }}" class="puesto-row-grid">
                                        @csrf
                                        @method('PUT')
                                        <label class="form-label">
                                            Puesto
                                            <input type="text" name="nombre" class="form-control" value="{{ old('nombre_' . $puesto->id, $puesto->nombre) }}" required maxlength="191">
                                        </label>
                                        <label class="form-label">
                                            Funciones
                                            <textarea name="funciones" class="form-control" maxlength="5000">{{ old('funciones_' . $puesto->id, $puesto->funciones) }}</textarea>
                                        </label>
                                        <div style="display:grid; gap:8px;">
                                            <label class="form-label">
                                                Estado
                                                <select name="activo" class="form-control">
                                                    <option value="1" {{ $puesto->activo ? 'selected' : '' }}>Activo</option>
                                                    <option value="0" {{ !$puesto->activo ? 'selected' : '' }}>Inactivo</option>
                                                </select>
                                            </label>
                                            <button type="submit" class="btn btn-primary btn-sm">Actualizar</button>
                                        </div>
                                    </form>
                                    @if($canDeletePuesto)
                                        <form method="POST" action="{{ route('personal.puestos.destroy', $puesto->id) }}" class="puesto-delete-form" onsubmit="return confirm('Eliminar este puesto? Esta accion solo procede si no tiene trabajadores asociados.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline btn-sm" {{ $puesto->trabajadores_count > 0 ? 'disabled' : '' }} title="{{ $puesto->trabajadores_count > 0 ? 'No se puede eliminar porque tiene trabajadores asociados.' : 'Eliminar puesto' }}">
                                                Eliminar
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    <p class="empty-description" style="margin:10px 0 0;">{{ $puesto->funciones ?: 'Sin funciones registradas.' }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="empty-state" id="puestosFilteredEmpty" style="display:none;">
                        <h3 class="empty-title">No hay puestos para mostrar</h3>
                        <p class="empty-description">Prueba con otro texto de busqueda.</p>
                    </div>

                    <div class="puestos-list-footer">
                        <label class="puestos-page-size">
                            Mostrar
                            <select id="puestosPageSizeBottom" class="puestos-page-size-select"></select>
                            puestos
                        </label>
                        <div class="puestos-list-info" id="puestosListInfo"></div>
                        <div class="puestos-pagination" id="puestosPagination"></div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card puestos-create-card">
            <div class="card-header">
                <span class="card-title">Nuevo puesto</span>
            </div>
            <div class="card-body">
                @if($canCreatePuesto)
                    <form method="POST" action="{{ route('personal.puestos.store') }}" class="puestos-form">
                        @csrf
                        <label class="form-label">
                            Nombre del puesto
                            <input type="text" name="nombre" class="form-control" value="{{ old('nombre') }}" required maxlength="191">
                        </label>
                        <label class="form-label">
                            Funciones
                            <textarea name="funciones" class="form-control" rows="8" maxlength="5000">{{ old('funciones') }}</textarea>
                        </label>
                        <button type="submit" class="btn btn-primary">Guardar puesto</button>
                    </form>
                @else
                    <p class="empty-description">No tienes permiso para crear o editar puestos.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cards = Array.from(document.querySelectorAll('[data-puesto-card]'));
    if (cards.length === 0) return;

    const searchInput = document.getElementById('puestosSearch');
    const pageSizeTop = document.getElementById('puestosPageSizeTop');
    const pageSizeBottom = document.getElementById('puestosPageSizeBottom');
    const pageSizeSelects = [pageSizeTop, pageSizeBottom].filter(Boolean);
    const pagination = document.getElementById('puestosPagination');
    const info = document.getElementById('puestosListInfo');
    const count = document.getElementById('puestosCount');
    const empty = document.getElementById('puestosFilteredEmpty');
    let currentPage = 1;
    let pageSize = 10;

    const normalizeText = function (value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const buildPageSizes = function () {
        const values = Array.from(new Set([10, 20, 50, 100, cards.length].filter(function (value) {
            return value > 0 && value <= cards.length;
        }))).sort(function (a, b) { return a - b; });
        const html = values.map(function (value) {
            return '<option value="' + value + '">' + value + '</option>';
        }).join('');
        pageSizeSelects.forEach(function (select) {
            select.innerHTML = html;
            select.value = String(values.indexOf(10) !== -1 ? 10 : values[0]);
        });
        pageSize = Number(pageSizeSelects[0]?.value || 10);
    };

    const filteredCards = function () {
        const tokens = normalizeText(searchInput?.value || '').split(' ').filter(Boolean);
        if (tokens.length === 0) return cards;

        return cards.filter(function (card) {
            const text = normalizeText(card.dataset.search || '');
            return tokens.every(function (token) {
                return text.includes(token);
            });
        });
    };

    const renderPagination = function (totalPages) {
        if (!pagination) return;
        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }

        const pages = [];
        if (totalPages <= 7) {
            for (let page = 1; page <= totalPages; page++) pages.push(page);
        } else {
            const raw = new Set([1, totalPages, currentPage, currentPage - 1, currentPage + 1]);
            Array.from(raw)
                .filter(function (page) { return page >= 1 && page <= totalPages; })
                .sort(function (a, b) { return a - b; })
                .forEach(function (page, index, ordered) {
                    if (index > 0 && page - ordered[index - 1] > 1) pages.push('ellipsis');
                    pages.push(page);
                });
        }

        let html = '<button type="button" class="puestos-pager-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + '>&lsaquo;</button>';
        pages.forEach(function (page) {
            if (page === 'ellipsis') {
                html += '<span class="puestos-pager-ellipsis">...</span>';
                return;
            }
            html += '<button type="button" class="puestos-pager-btn ' + (page === currentPage ? 'is-active' : '') + '" data-page="' + page + '">' + page + '</button>';
        });
        html += '<button type="button" class="puestos-pager-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage === totalPages ? 'disabled' : '') + '>&rsaquo;</button>';
        pagination.innerHTML = html;
    };

    const render = function (resetPage) {
        if (resetPage) currentPage = 1;

        const filtered = filteredCards();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = Math.max(1, Math.min(currentPage, totalPages));
        const start = (currentPage - 1) * pageSize;
        const visible = filtered.slice(start, start + pageSize);
        const visibleSet = new Set(visible);

        cards.forEach(function (card) {
            card.style.display = visibleSet.has(card) ? '' : 'none';
        });

        if (empty) {
            empty.style.display = total === 0 ? '' : 'none';
        }
        if (count) {
            count.textContent = total + ' puesto(s)';
        }
        if (info) {
            info.textContent = total === 0
                ? '0 resultados'
                : 'Mostrando ' + (start + 1) + '-' + (start + visible.length) + ' de ' + total;
        }

        renderPagination(totalPages);
    };

    buildPageSizes();

    searchInput?.addEventListener('input', function () {
        render(true);
    });

    pageSizeSelects.forEach(function (select) {
        select.addEventListener('change', function () {
            pageSize = Number(select.value || 10);
            pageSizeSelects.forEach(function (other) {
                other.value = String(pageSize);
            });
            render(true);
        });
    });

    pagination?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-page]');
        if (!button || button.disabled) return;
        currentPage = Number(button.dataset.page || 1);
        render(false);
    });

    render(true);
});
</script>
@endpush
