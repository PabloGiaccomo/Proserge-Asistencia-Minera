@extends('layouts.app')

@section('title', 'Asistencia operativa - Proserge')

@php
    $summary = $dashboard['summary'];
    $filters = $dashboard['filters'];
    $pagination = $dashboard['pagination'];
    $maxTrend = max(1, collect($dashboard['trend'])->max('scheduled') ?? 1);
    $maxMine = max(1, collect($dashboard['by_mine'])->max('scheduled') ?? 1);
    $maxState = max(1, collect($dashboard['group_states'])->max('total') ?? 1);
    $stateLabels = ['PENDIENTE' => 'Sin iniciar', 'REGISTRADO' => 'En registro', 'CERRADO' => 'Cerrado'];
    $stateClasses = ['PENDIENTE' => 'neutral', 'REGISTRADO' => 'warning', 'CERRADO' => 'success'];
@endphp

@section('content')
<div class="attendance-ops-page">
    <header class="ops-page-header">
        <div>
            <p class="ops-eyebrow">Operaciones</p>
            <h1>Asistencia operativa</h1>
            <p>Seguimiento de grupos, marcaciones y ausencias con información registrada en el sistema.</p>
        </div>
        <div class="ops-page-actions">
            @allowed('mi_asistencia', 'ver')
                <a class="ops-btn secondary" href="{{ route('mi-asistencia.index') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    Mi asistencia
                </a>
            @endallowed
            @allowed('faltas', 'ver')
                <a class="ops-btn primary" href="{{ route('faltas.index') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 19h18.4L12 3Z"/><path d="M12 9v4M12 17h.01"/></svg>
                    Revisar faltas
                </a>
            @endallowed
        </div>
    </header>

    <form method="GET" action="{{ route('asistencia.index') }}" class="ops-filter-band" aria-label="Filtros de asistencia">
        <div class="ops-field search-field">
            <label for="attendance-search">Buscar grupo</label>
            <div class="ops-input-with-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input id="attendance-search" name="buscar" type="search" value="{{ $filters['buscar'] ?? '' }}" placeholder="Servicio, grupo, mina o responsable">
            </div>
        </div>
        <div class="ops-field">
            <label for="attendance-from">Desde</label>
            <input id="attendance-from" name="fecha_desde" type="date" value="{{ $filters['fecha_desde'] }}">
        </div>
        <div class="ops-field">
            <label for="attendance-to">Hasta</label>
            <input id="attendance-to" name="fecha_hasta" type="date" value="{{ $filters['fecha_hasta'] }}">
        </div>
        <div class="ops-field">
            <label for="attendance-shift">Turno</label>
            <select id="attendance-shift" name="turno">
                <option value="">Todos</option>
                <option value="DIA" @selected(($filters['turno'] ?? '') === 'DIA')>Día</option>
                <option value="NOCHE" @selected(($filters['turno'] ?? '') === 'NOCHE')>Noche</option>
            </select>
        </div>
        <div class="ops-field">
            <label for="attendance-state">Estado</label>
            <select id="attendance-state" name="estado_asistencia">
                <option value="">Todos</option>
                @foreach($stateLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['estado_asistencia'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="ops-filter-actions">
            <a class="ops-icon-btn" href="{{ route('asistencia.index') }}" title="Limpiar filtros" aria-label="Limpiar filtros">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/></svg>
            </a>
            <button class="ops-btn primary" type="submit">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/></svg>
                Aplicar
            </button>
        </div>
    </form>

    <section class="ops-kpi-strip" aria-label="Resumen de asistencia">
        <article class="ops-kpi">
            <span class="ops-kpi-label">Cobertura</span>
            <strong>{{ number_format($summary['coverage'], 1) }}%</strong>
            <span>{{ $summary['present'] }} de {{ $summary['scheduled'] }} programados</span>
        </article>
        <article class="ops-kpi success">
            <span class="ops-kpi-label">Presentes</span>
            <strong>{{ number_format($summary['present']) }}</strong>
            <span>{{ $summary['late'] }} con tardanza</span>
        </article>
        <article class="ops-kpi danger">
            <span class="ops-kpi-label">Ausentes</span>
            <strong>{{ number_format($summary['absent']) }}</strong>
            <span>{{ $summary['active_absences'] }} faltas activas</span>
        </article>
        <article class="ops-kpi warning">
            <span class="ops-kpi-label">Por marcar</span>
            <strong>{{ number_format($summary['pending_marks']) }}</strong>
            <span>En grupos del periodo</span>
        </article>
        <article class="ops-kpi">
            <span class="ops-kpi-label">Grupos cerrados</span>
            <strong>{{ number_format($summary['closed_groups']) }}/{{ number_format($summary['groups']) }}</strong>
            <span>{{ $summary['open_groups'] }} en registro</span>
        </article>
    </section>

    <div class="ops-analytics-grid">
        <section class="ops-panel trend-panel">
            <div class="ops-panel-head">
                <div>
                    <h2>Evolución diaria</h2>
                    <p>Programados, presentes y ausentes por fecha.</p>
                </div>
            </div>
            @if(empty($dashboard['trend']))
                <div class="ops-empty">No hay asistencia registrada en el periodo seleccionado.</div>
            @else
                <div class="ops-trend-chart" role="img" aria-label="Evolución diaria de asistencia">
                    @foreach($dashboard['trend'] as $day)
                        <div class="ops-trend-column" title="{{ $day['date'] }}: {{ $day['present'] }} presentes de {{ $day['scheduled'] }} programados">
                            <div class="ops-trend-bars">
                                <span class="scheduled" style="height: {{ max(4, round(($day['scheduled'] / $maxTrend) * 100)) }}%"></span>
                                <span class="present" style="height: {{ max(4, round(($day['present'] / $maxTrend) * 100)) }}%"></span>
                            </div>
                            <strong>{{ $day['coverage'] }}%</strong>
                            <small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
                <div class="ops-chart-legend"><span><i class="scheduled"></i>Programados</span><span><i class="present"></i>Presentes</span></div>
            @endif
        </section>

        <section class="ops-panel">
            <div class="ops-panel-head">
                <div>
                    <h2>Estado de grupos</h2>
                    <p>Control del cierre del periodo.</p>
                </div>
            </div>
            <div class="ops-state-list">
                @forelse($dashboard['group_states'] as $state)
                    @php($stateName = $stateLabels[$state['state']] ?? ucfirst(strtolower($state['state'])))
                    <div class="ops-state-row">
                        <div><span class="ops-status {{ $stateClasses[$state['state']] ?? 'neutral' }}">{{ $stateName }}</span><strong>{{ $state['total'] }}</strong></div>
                        <div class="ops-progress"><span class="{{ $stateClasses[$state['state']] ?? 'neutral' }}" style="width: {{ round(($state['total'] / $maxState) * 100) }}%"></span></div>
                    </div>
                @empty
                    <div class="ops-empty compact">Sin grupos para resumir.</div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="ops-analytics-grid">
        <section class="ops-panel">
            <div class="ops-panel-head">
                <div>
                    <h2>Cobertura por mina</h2>
                    <p>Comparación de personal programado y presente.</p>
                </div>
            </div>
            <div class="ops-ranking-list">
                @forelse($dashboard['by_mine'] as $mine)
                    <div class="ops-ranking-row">
                        <div class="ops-ranking-copy">
                            <strong>{{ $mine['name'] }}</strong>
                            <span>{{ $mine['present'] }}/{{ $mine['scheduled'] }} presentes · {{ $mine['pending'] }} pendientes</span>
                        </div>
                        <div class="ops-ranking-meter"><span style="width: {{ min(100, $mine['coverage']) }}%"></span></div>
                        <b class="{{ $mine['coverage'] < 85 ? 'danger-text' : '' }}">{{ $mine['coverage'] }}%</b>
                    </div>
                @empty
                    <div class="ops-empty compact">No hay datos por mina en este periodo.</div>
                @endforelse
            </div>
        </section>

        <section class="ops-panel">
            <div class="ops-panel-head">
                <div>
                    <h2>Atención prioritaria</h2>
                    <p>Grupos abiertos, incompletos o con baja cobertura.</p>
                </div>
            </div>
            <div class="ops-priority-list">
                @forelse($dashboard['priority_groups'] as $group)
                    <a href="{{ route('asistencia.show', $group['id']) }}" class="ops-priority-item">
                        <span class="ops-priority-date">{{ \Carbon\Carbon::parse($group['fecha'])->format('d/m') }}</span>
                        <span><strong>{{ $group['codigo'] }}</strong><small>{{ $group['mina'] }} · {{ $group['supervisor'] }}</small></span>
                        <span class="ops-priority-metric"><strong>{{ $group['pendientes'] }}</strong><small>pendientes</small></span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                @empty
                    <div class="ops-empty compact">No hay grupos que requieran atención con estos filtros.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="ops-panel ops-table-panel">
        <div class="ops-panel-head table-head">
            <div>
                <h2>Grupos de asistencia</h2>
                <p>Mostrando {{ $pagination['from'] }}-{{ $pagination['to'] }} de {{ $pagination['total'] }} grupos.</p>
            </div>
            <form method="GET" class="ops-page-size">
                @foreach(request()->except(['per_page', 'page']) as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <label for="attendance-page-size">Filas</label>
                <select id="attendance-page-size" name="per_page" onchange="this.form.submit()">
                    @foreach([10, 25, 50] as $size)<option value="{{ $size }}" @selected($pagination['per_page'] === $size)>{{ $size }}</option>@endforeach
                </select>
            </form>
        </div>
        <div class="ops-table-wrap">
            <table class="ops-data-table">
                <thead><tr><th>Fecha / turno</th><th>Grupo y destino</th><th>Responsable</th><th>Marcación</th><th>Cobertura</th><th>Estado</th><th aria-label="Acción"></th></tr></thead>
                <tbody>
                    @forelse($dashboard['groups'] as $group)
                        <tr>
                            <td><strong>{{ \Carbon\Carbon::parse($group['fecha'])->format('d/m/Y') }}</strong><span>{{ ucfirst(strtolower($group['turno'])) }}</span></td>
                            <td><strong>{{ $group['codigo'] }}</strong><span>{{ $group['mina'] }} · {{ $group['servicio'] }}</span></td>
                            <td><strong>{{ $group['supervisor'] }}</strong><span>{{ $group['programados'] }} programados</span></td>
                            <td><strong>{{ $group['presentes'] }} presentes</strong><span>{{ $group['ausentes'] }} ausentes · {{ $group['pendientes'] }} pendientes</span></td>
                            <td><div class="ops-inline-progress"><span style="width: {{ min(100, $group['cobertura']) }}%"></span></div><strong>{{ $group['cobertura'] }}%</strong></td>
                            <td><span class="ops-status {{ $stateClasses[$group['estado_asistencia']] ?? 'neutral' }}">{{ $stateLabels[$group['estado_asistencia']] ?? $group['estado_asistencia'] }}</span></td>
                            <td><a class="ops-icon-btn" href="{{ route('asistencia.show', $group['id']) }}" title="Abrir grupo" aria-label="Abrir grupo"><svg viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="ops-empty">No se encontraron grupos con los filtros seleccionados.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($pagination['last_page'] > 1)
            <nav class="ops-pagination" aria-label="Paginación de grupos">
                <a class="ops-icon-btn {{ $pagination['page'] === 1 ? 'disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => max(1, $pagination['page'] - 1)]) }}" aria-label="Página anterior"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></a>
                <span>Página {{ $pagination['page'] }} de {{ $pagination['last_page'] }}</span>
                <a class="ops-icon-btn {{ $pagination['page'] === $pagination['last_page'] ? 'disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => min($pagination['last_page'], $pagination['page'] + 1)]) }}" aria-label="Página siguiente"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></a>
            </nav>
        @endif
    </section>
</div>
@endsection
