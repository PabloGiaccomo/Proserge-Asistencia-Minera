@extends('layouts.app')

@section('title', 'Bienestar - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Bienestar</h1>
                <p class="page-subtitle">Bloqueos de disponibilidad y ocupación del personal</p>
            </div>
            <a href="{{ route('bienestar.bloqueos.create') }}" class="btn btn-primary btn-sm">Nuevo bloqueo</a>
        </div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:16px;">
        <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $resumen['total_activos_hoy'] ?? 0 }}</strong><div class="text-muted">Bloqueos activos hoy</div></div></div>
        <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $resumen['descanso_medico_hoy'] ?? 0 }}</strong><div class="text-muted">Descanso médico hoy</div></div></div>
        <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $resumen['vacaciones_hoy'] ?? 0 }}</strong><div class="text-muted">Vacaciones hoy</div></div></div>
        <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $resumen['restriccion_hoy'] ?? 0 }}</strong><div class="text-muted">Restricción temporal hoy</div></div></div>
        <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $resumen['trabajadores_no_disponibles_periodo'] ?? 0 }}</strong><div class="text-muted">No disponibles en rango</div></div></div>
    </div>

    <form method="GET" action="{{ route('bienestar.index') }}" class="filter-bar" id="bienestarFilterForm" style="margin-bottom:16px;">
        <div class="filter-row" style="display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:12px; align-items:end;">
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
            <div class="filter-actions" style="display:flex; gap:8px;">
                <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
                <a class="btn btn-outline btn-sm" href="{{ route('bienestar.index') }}">Limpiar</a>
            </div>
        </div>
    </form>

    <div class="card">
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
                                    <td>{{ $row['nombre'] }}</td>
                                    <td>{{ $row['dni'] }}</td>
                                    <td>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            @foreach($row['motivos'] as $motivo)
                                                <div>
                                                    <strong>{{ $motivo['tipo'] }}</strong>
                                                    <span class="text-muted">- {{ $motivo['motivo'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>{{ implode(', ', $row['registrado_por']) ?: '-' }}</td>
                                    <td>
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
