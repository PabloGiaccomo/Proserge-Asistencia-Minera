@extends('layouts.app')

@section('title', 'Bienestar - Proserge')

@section('content')
<style>
    .bienestar-page {
        max-width: 100%;
        overflow-x: hidden;
    }

    .bienestar-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
        margin-bottom: 16px;
    }

    .bienestar-stat-card {
        box-shadow: none;
        border: 1px solid #e2e8f0;
    }

    .bienestar-stat-card .card-body {
        padding: 18px 20px;
    }

    .bienestar-stat-card strong {
        display: block;
        font-size: 24px;
        line-height: 1;
        margin-bottom: 8px;
    }

    .bienestar-filter-row {
        display: grid;
        grid-template-columns: minmax(220px, 2fr) minmax(150px, 1fr) minmax(150px, 1fr) auto;
        gap: 12px;
        align-items: end;
    }

    .bienestar-filter-row .filter-group {
        min-width: 0;
    }

    .bienestar-filter-row .filter-actions {
        display: flex;
        gap: 8px;
        margin-left: 0;
    }

    @media (max-width: 767px) {
        .bienestar-page {
            padding-bottom: 110px;
        }

        .bienestar-header-top {
            flex-direction: column;
            align-items: stretch !important;
            gap: 14px !important;
        }

        .bienestar-header-top .btn {
            width: 100%;
            min-height: 48px;
            justify-content: center;
        }

        .bienestar-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .bienestar-stat-card {
            border-radius: 16px;
        }

        .bienestar-stat-card .card-body {
            min-height: 82px;
            padding: 14px !important;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .bienestar-stat-card strong {
            font-size: 22px;
            margin-bottom: 6px;
        }

        .bienestar-stat-card .text-muted {
            font-size: 12px;
            line-height: 1.25;
        }

        .bienestar-filter-bar {
            padding: 16px !important;
        }

        .bienestar-filter-row {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px;
        }

        .bienestar-filter-row .filter-group:first-child {
            grid-column: 1 / -1;
        }

        .bienestar-filter-row .filter-actions {
            grid-column: 1 / -1;
            display: grid !important;
            grid-template-columns: 1fr 1fr;
        }

        .bienestar-filter-row .filter-actions .btn {
            width: 100%;
            min-height: 44px;
            justify-content: center;
        }

        .bienestar-table-card .card-header {
            flex-direction: column;
            align-items: flex-start !important;
        }

        .bienestar-table-card .card-body {
            padding: 16px;
        }

        .bienestar-table-card .data-table,
        .bienestar-table-card .data-table thead,
        .bienestar-table-card .data-table tbody,
        .bienestar-table-card .data-table tr,
        .bienestar-table-card .data-table td {
            display: block;
            width: 100%;
        }

        .bienestar-table-card .data-table thead {
            display: none;
        }

        .bienestar-table-card .data-table tr {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 12px;
            background: #fff;
        }

        .bienestar-table-card .data-table td {
            border: 0;
            padding: 8px 0;
        }

        .bienestar-table-card .data-table td::before {
            content: attr(data-label);
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .bienestar-table-card .data-table td:last-child::before {
            display: none;
        }

        .bienestar-table-card .data-table td:last-child .btn {
            width: 100%;
            justify-content: center;
            min-height: 42px;
        }
    }

    @media (max-width: 360px) {
        .bienestar-summary-grid {
            grid-template-columns: 1fr;
        }

        .bienestar-filter-row {
            grid-template-columns: 1fr !important;
        }

        .bienestar-filter-row .filter-group,
        .bienestar-filter-row .filter-actions {
            grid-column: 1 / -1;
        }
    }
</style>

<div class="module-page bienestar-page">
    <div class="page-header">
        <div class="page-header-top bienestar-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Bienestar</h1>
                <p class="page-subtitle">Bloqueos de disponibilidad y ocupación del personal</p>
            </div>
            <a href="{{ route('bienestar.bloqueos.create') }}" class="btn btn-primary btn-sm">Nuevo bloqueo</a>
        </div>
    </div>

    <div class="bienestar-summary-grid">
        <div class="card bienestar-stat-card"><div class="card-body"><strong>{{ $resumen['total_activos_hoy'] ?? 0 }}</strong><div class="text-muted">Bloqueos activos hoy</div></div></div>
        <div class="card bienestar-stat-card"><div class="card-body"><strong>{{ $resumen['descanso_medico_hoy'] ?? 0 }}</strong><div class="text-muted">Descanso médico hoy</div></div></div>
        <div class="card bienestar-stat-card"><div class="card-body"><strong>{{ $resumen['vacaciones_hoy'] ?? 0 }}</strong><div class="text-muted">Vacaciones hoy</div></div></div>
        <div class="card bienestar-stat-card"><div class="card-body"><strong>{{ $resumen['restriccion_hoy'] ?? 0 }}</strong><div class="text-muted">Restricción temporal hoy</div></div></div>
        <div class="card bienestar-stat-card"><div class="card-body"><strong>{{ $resumen['gestacion_hoy'] ?? 0 }}</strong><div class="text-muted">Gestacion hoy</div></div></div>
        <div class="card bienestar-stat-card"><div class="card-body"><strong>{{ $resumen['trabajadores_no_disponibles_periodo'] ?? 0 }}</strong><div class="text-muted">No disponibles en rango</div></div></div>
    </div>

    <form method="GET" action="{{ route('bienestar.index') }}" class="filter-bar bienestar-filter-bar" id="bienestarFilterForm" style="margin-bottom:16px;">
        <div class="filter-row bienestar-filter-row">
            <div class="filter-group">
                <label class="filter-label">Buscar trabajador</label>
                <input type="text" id="bienestarSearchInput" name="search" class="form-control" placeholder="Nombre o DNI..." value="{{ $filters['search'] ?? '' }}">
            </div>
            <div class="filter-group">
                <label class="filter-label">Desde</label>
                <input type="date" name="fecha_inicio" class="form-control" value="{{ $filters['fecha_inicio'] ?? '' }}">
            </div>
            <div class="filter-group">
                <label class="filter-label">Hasta</label>
                <input type="date" name="fecha_fin" class="form-control" value="{{ $filters['fecha_fin'] ?? '' }}">
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
                <a class="btn btn-outline btn-sm" href="{{ route('bienestar.index') }}">Limpiar</a>
            </div>
        </div>
    </form>

    <div class="card bienestar-table-card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <span class="card-title">Trabajadores no disponibles en el rango</span>
            <span class="text-muted" id="bienestarCountLabel">{{ ($trabajadoresBloqueados ?? collect())->count() }} trabajador(es)</span>
        </div>
        <div class="card-body">
            @if(($trabajadoresBloqueados ?? collect())->isEmpty())
                <x-ui.empty-state
                    icon="heart"
                    title="Sin bloqueos en el periodo"
                    description="No se encontraron trabajadores con indisponibilidad en el rango seleccionado."
                />
            @else
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Trabajador</th>
                                <th>DNI</th>
                                <th>Motivos</th>
                                <th>Registrado por</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="bienestarTableBody">
                            @foreach($trabajadoresBloqueados as $row)
                                @php
                                    $searchBag = collect($row['motivos'] ?? [])->map(function (array $item): string {
                                        return (($item['tipo'] ?? '') . ' ' . ($item['motivo'] ?? ''));
                                    })->implode(' ');
                                @endphp
                                <tr class="js-bienestar-row" data-search="{{ trim(($row['nombre'] ?? '') . ' ' . ($row['dni'] ?? '') . ' ' . $searchBag) }}">
                                    <td data-label="Trabajador">{{ $row['nombre'] }}</td>
                                    <td data-label="DNI">{{ $row['dni'] }}</td>
                                    <td data-label="Motivos">
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            @foreach($row['motivos'] as $motivo)
                                                <div>
                                                    <strong>{{ $motivo['tipo'] }}</strong>
                                                    <span class="text-muted">- {{ $motivo['motivo'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td data-label="Registrado por">{{ implode(', ', $row['registrado_por']) ?: '-' }}</td>
                                    <td data-label="Accion">
                                        <a href="{{ route('bienestar.show', $row['personal_id']) }}" class="btn btn-outline btn-xs">Ver cartilla</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('bienestarSearchInput');
    const rows = Array.from(document.querySelectorAll('.js-bienestar-row'));
    const countLabel = document.getElementById('bienestarCountLabel');

    if (!searchInput || rows.length === 0) {
        return;
    }

    const normalizeText = function (text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };

    const applyLocalSearch = function () {
        const query = normalizeText(searchInput.value);
        const tokens = query.split(' ').filter(function (token) { return token.length > 0; });
        let visibleCount = 0;

        rows.forEach(function (row) {
            const text = normalizeText(row.dataset.search || '');
            const match = tokens.length === 0 || tokens.every(function (token) {
                return text.includes(token);
            });

            row.style.display = match ? '' : 'none';
            if (match) {
                visibleCount++;
            }
        });

        if (countLabel) {
            countLabel.textContent = visibleCount + ' trabajador(es)';
        }
    };

    searchInput.addEventListener('input', applyLocalSearch);

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    applyLocalSearch();
});
</script>
@endpush
