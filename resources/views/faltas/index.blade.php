@extends('layouts.app')

@section('title', 'Faltas e inasistencias - Proserge')

@php
    $summary = $dashboard['summary'];
    $maxTrend = max(1, collect($dashboard['trend'])->max('total') ?? 1);
    $maxWorker = max(1, collect($dashboard['top_workers'])->max('total') ?? 1);
    $stateLabels = ['ACTIVA' => 'Pendiente de revisar', 'CORREGIDA' => 'Corregida', 'ANULADA' => 'Anulada'];
    $stateClasses = ['ACTIVA' => 'danger', 'CORREGIDA' => 'success', 'ANULADA' => 'neutral'];
@endphp

@section('content')
<div class="attendance-ops-page absence-ops-page">
    <header class="ops-page-header">
        <div>
            <p class="ops-eyebrow">Control y regularización</p>
            <h1>Faltas e inasistencias</h1>
            <p>Revisa ausencias generadas al cerrar asistencia y conserva la trazabilidad de cada corrección.</p>
        </div>
        <div class="ops-page-actions">
            @allowed('asistencias', 'ver')
                <a class="ops-btn secondary" href="{{ route('asistencia.index') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    Ir a asistencia
                </a>
            @endallowed
        </div>
    </header>

    <form method="GET" action="{{ route('faltas.index') }}" class="ops-filter-band" aria-label="Filtros de faltas">
        <div class="ops-field search-field">
            <label for="absence-search">Buscar trabajador</label>
            <div class="ops-input-with-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input id="absence-search" name="buscar" type="search" value="{{ $filters['buscar'] ?? '' }}" placeholder="Nombre, DNI u observación">
            </div>
        </div>
        <div class="ops-field"><label for="absence-from">Desde</label><input id="absence-from" name="fecha_desde" type="date" value="{{ $filters['fecha_desde'] }}"></div>
        <div class="ops-field"><label for="absence-to">Hasta</label><input id="absence-to" name="fecha_hasta" type="date" value="{{ $filters['fecha_hasta'] }}"></div>
        <div class="ops-field">
            <label for="absence-state">Estado</label>
            <select id="absence-state" name="estado">
                <option value="">Todos</option>
                @foreach($stateLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['estado'] ?? '') === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="ops-field">
            <label for="absence-destination">Destino</label>
            <select id="absence-destination" name="destino_tipo">
                <option value="">Todos</option>
                <option value="MINA" @selected(($filters['destino_tipo'] ?? '') === 'MINA')>Mina</option>
                <option value="TALLER" @selected(($filters['destino_tipo'] ?? '') === 'TALLER')>Taller</option>
                <option value="OFICINA" @selected(($filters['destino_tipo'] ?? '') === 'OFICINA')>Oficina</option>
            </select>
        </div>
        <div class="ops-filter-actions">
            <a class="ops-icon-btn" href="{{ route('faltas.index') }}" title="Limpiar filtros" aria-label="Limpiar filtros"><svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/></svg></a>
            <button class="ops-btn primary" type="submit"><svg viewBox="0 0 24 24"><path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/></svg>Aplicar</button>
        </div>
    </form>

    <section class="ops-kpi-strip four" aria-label="Resumen de faltas">
        <article class="ops-kpi danger"><span class="ops-kpi-label">Pendientes</span><strong>{{ number_format($summary['active']) }}</strong><span>Requieren revisión</span></article>
        <article class="ops-kpi"><span class="ops-kpi-label">Trabajadores</span><strong>{{ number_format($summary['workers']) }}</strong><span>Con faltas activas</span></article>
        <article class="ops-kpi success"><span class="ops-kpi-label">Corregidas</span><strong>{{ number_format($summary['corrected']) }}</strong><span>Asistencia regularizada</span></article>
        <article class="ops-kpi"><span class="ops-kpi-label">Total del periodo</span><strong>{{ number_format($summary['total']) }}</strong><span>{{ $summary['annulled'] }} anuladas</span></article>
    </section>

    <div class="ops-analytics-grid">
        <section class="ops-panel">
            <div class="ops-panel-head"><div><h2>Evolución de faltas</h2><p>Registros generados por fecha.</p></div></div>
            @if(empty($dashboard['trend']))
                <div class="ops-empty">No hay faltas en el periodo seleccionado.</div>
            @else
                <div class="ops-trend-chart single" role="img" aria-label="Faltas por fecha">
                    @foreach($dashboard['trend'] as $day)
                        <div class="ops-trend-column" title="{{ $day['date'] }}: {{ $day['total'] }} faltas">
                            <div class="ops-trend-bars"><span class="absence" style="height: {{ max(4, round(($day['total'] / $maxTrend) * 100)) }}%"></span></div>
                            <strong>{{ $day['total'] }}</strong><small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="ops-panel">
            <div class="ops-panel-head"><div><h2>Reincidencias activas</h2><p>Trabajadores con más faltas pendientes.</p></div></div>
            <div class="ops-ranking-list">
                @forelse($dashboard['top_workers'] as $worker)
                    <div class="ops-ranking-row">
                        <div class="ops-ranking-copy"><strong>{{ $worker['name'] }}</strong><span>{{ $worker['document'] ?: 'Sin documento' }}</span></div>
                        <div class="ops-ranking-meter danger"><span style="width: {{ round(($worker['total'] / $maxWorker) * 100) }}%"></span></div>
                        <b class="danger-text">{{ $worker['total'] }}</b>
                    </div>
                @empty
                    <div class="ops-empty compact">No hay reincidencias activas.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="ops-panel ops-table-panel">
        <div class="ops-panel-head table-head">
            <div><h2>Detalle de faltas</h2><p>Mostrando {{ $pagination['from'] }}-{{ $pagination['to'] }} de {{ $pagination['total'] }} registros.</p></div>
            <form method="GET" class="ops-page-size">
                @foreach(request()->except(['per_page', 'page']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <label for="absence-page-size">Filas</label>
                <select id="absence-page-size" name="per_page" onchange="this.form.submit()">@foreach([10,25,50] as $size)<option value="{{ $size }}" @selected($pagination['per_page'] === $size)>{{ $size }}</option>@endforeach</select>
            </form>
        </div>
        <div class="ops-table-wrap">
            <table class="ops-data-table">
                <thead><tr><th>Fecha</th><th>Trabajador</th><th>Destino</th><th>Motivo</th><th>Origen</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse($data as $item)
                        @php($state = strtoupper((string) ($item->estado ?: 'ACTIVA')))
                        <tr>
                            <td><strong>{{ \Carbon\Carbon::parse($item->fecha)->format('d/m/Y') }}</strong></td>
                            <td><strong>{{ $item->trabajador_nombre ?: 'Sin nombre' }}</strong><span>{{ $item->trabajador_documento ?: 'Sin documento' }} · {{ $item->trabajador_puesto ?: 'Sin puesto' }}</span></td>
                            <td><strong>{{ $item->destino_nombre ?: 'Sin destino identificado' }}</strong><span>{{ ucfirst(strtolower((string) ($item->destino_tipo ?: 'Sin tipo'))) }}</span></td>
                            <td><strong>{{ ucfirst(strtolower((string) ($item->motivo ?: 'Ausencia'))) }}</strong><span>{{ \Illuminate\Support\Str::limit($item->descripcion ?: $item->observaciones ?: 'Sin detalle adicional', 65) }}</span></td>
                            <td><span class="ops-origin {{ $item->asistencia_detalle_id ? 'system' : 'manual' }}">{{ $item->asistencia_detalle_id ? 'Asistencia' : 'Manual' }}</span></td>
                            <td><span class="ops-status {{ $stateClasses[$state] ?? 'neutral' }}">{{ $stateLabels[$state] ?? ucfirst(strtolower($state)) }}</span></td>
                            <td>
                                <div class="ops-row-actions">
                                    <a class="ops-icon-btn" href="{{ route('faltas.show', $item->id) }}" title="Ver detalle" aria-label="Ver detalle"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></a>
                                    @if($state === 'ACTIVA')
                                        @allowed('faltas', 'corregir')<a class="ops-icon-btn accent" href="{{ route('faltas.corregir', $item->id) }}" title="Corregir asistencia" aria-label="Corregir asistencia"><svg viewBox="0 0 24 24"><path d="m4 16-1 5 5-1L19 9l-4-4L4 16Z"/><path d="m13 7 4 4"/></svg></a>@endallowed
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="ops-empty">No se encontraron faltas con los filtros seleccionados.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($pagination['last_page'] > 1)
            <nav class="ops-pagination" aria-label="Paginación de faltas">
                <a class="ops-icon-btn {{ $pagination['page'] === 1 ? 'disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => max(1, $pagination['page'] - 1)]) }}" aria-label="Página anterior"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></a>
                <span>Página {{ $pagination['page'] }} de {{ $pagination['last_page'] }}</span>
                <a class="ops-icon-btn {{ $pagination['page'] === $pagination['last_page'] ? 'disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => min($pagination['last_page'], $pagination['page'] + 1)]) }}" aria-label="Página siguiente"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></a>
            </nav>
        @endif
    </section>
</div>
@endsection
