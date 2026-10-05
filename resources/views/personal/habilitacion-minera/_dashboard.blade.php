@php
    $dashboardStates = collect($miningDashboard['states'] ?? []);
    $dashboardTotal = max(0, (int) ($miningDashboard['total'] ?? 0));
    $stateMeta = [
        'HABILITADO' => ['label' => 'Habilitados', 'color' => '#16856b'],
        'EN_PROCESO' => ['label' => 'En proceso', 'color' => '#d58b12'],
    ];
    $pieStops = [];
    $pieCursor = 0;
    foreach ($stateMeta as $state => $meta) {
        $value = (int) $dashboardStates->get($state, 0);
        if ($dashboardTotal < 1 || $value < 1) continue;
        $end = $pieCursor + (($value / $dashboardTotal) * 100);
        $pieStops[] = $meta['color'].' '.$pieCursor.'% '.$end.'%';
        $pieCursor = $end;
    }
    $pieBackground = $pieStops ? 'conic-gradient('.implode(', ', $pieStops).')' : '#dce4ee';
    $dashboardExamStates = collect([
        '' => 'Todos',
        'PENDIENTE' => 'Pendientes',
        'PROGRAMADO' => 'Programados',
        'POR_VENCER' => 'Por vencer',
        'VENCIDO' => 'Vencidos',
        'OBSERVADO' => 'Observados',
        'DESAPROBADO' => 'Desaprobados',
    ]);
@endphp

<section @class(['mine-dashboard', 'is-hidden' => $activeMineView !== 'dashboard']) data-mine-view-panel="dashboard">
    <form method="GET" action="{{ route('personal.habilitacion-minera.index') }}" class="mine-dashboard-filters">
        <input type="hidden" name="vista" value="dashboard">
        <label><span>Mina</span><select name="dashboard_mina_id">
            <option value="">Todas las minas</option>
            @foreach($mines as $mine)<option value="{{ $mine->id }}" @selected(($dashboardFilters['mina_id'] ?? '') === $mine->id)>{{ $mine->nombre }}</option>@endforeach
        </select></label>
        <label><span>Estado de habilitación</span><select name="dashboard_estado_habilitacion">
            <option value="">Todos los estados</option>
            @foreach(collect($stateOptions)->only(['HABILITADO', 'EN_PROCESO']) as $value => $label)<option value="{{ $value }}" @selected(($dashboardFilters['estado_habilitacion'] ?? '') === $value)>{{ $label }}</option>@endforeach
        </select></label>
        <button type="submit" class="btn btn-primary">Aplicar filtros</button>
        <a href="{{ route('personal.habilitacion-minera.index', ['vista' => 'dashboard']) }}" class="btn btn-outline">Limpiar</a>
    </form>

    <div class="mine-dashboard-kpis">
        @foreach($stateMeta as $state => $meta)
            <article style="--status-color: {{ $meta['color'] }}"><span>{{ $meta['label'] }}</span><strong>{{ number_format((int) $dashboardStates->get($state, 0)) }}</strong></article>
        @endforeach
    </div>

    @php
        $examKpis = $miningDashboard['exam_kpis'] ?? [];
        $examKpiMeta = [
            'PROGRAMADO' => ['label' => 'Exámenes programados', 'copy' => 'Con fecha pendiente de realización', 'color' => '#356eb8'],
            'VENCIDO' => ['label' => 'Exámenes vencidos', 'copy' => 'Requieren regularización', 'color' => '#d14d58'],
            'POR_VENCER' => ['label' => 'Exámenes por vencer', 'copy' => 'Próximos a perder vigencia', 'color' => '#d58b12'],
            'SIN_EXAMENES' => ['label' => 'Personas sin exámenes', 'copy' => 'Asignadas sin ningún examen generado', 'color' => '#68758a'],
        ];
    @endphp
    <section class="mine-exam-kpis" aria-label="Indicadores de examenes">
        @foreach($examKpiMeta as $key => $meta)
            <article style="--status-color: {{ $meta['color'] }}">
                <div><span>{{ $meta['label'] }}</span><small>{{ $meta['copy'] }}</small></div>
                <strong>{{ number_format((int) ($examKpis[$key] ?? 0)) }}</strong>
            </article>
        @endforeach
    </section>

    @if($dashboardTotal === 0)
        <div class="mine-dashboard-empty"><strong>No hay registros para los filtros seleccionados.</strong><span>Prueba otra mina o limpia los filtros.</span></div>
    @else
        <div class="mine-dashboard-grid">
            <section class="mine-dashboard-section mine-dashboard-pie-section">
                <header><div><h2>Estado general</h2><p>Distribución de trabajadores por habilitación.</p></div><strong>{{ number_format($dashboardTotal) }}</strong></header>
                <div class="mine-pie-layout">
                    <div class="mine-pie" style="--pie-background: {{ $pieBackground }}" role="img" aria-label="Distribución de {{ $dashboardTotal }} habilitaciones"><span>{{ $dashboardTotal }}<small>Total</small></span></div>
                    <div class="mine-pie-legend">
                        @foreach($stateMeta as $state => $meta)
                            @php $count = (int) $dashboardStates->get($state, 0); @endphp
                            <div><i style="--legend-color: {{ $meta['color'] }}"></i><span>{{ $meta['label'] }}</span><strong>{{ $count }} · {{ $dashboardTotal ? round(($count / $dashboardTotal) * 100) : 0 }}%</strong></div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="mine-dashboard-section mine-dashboard-wide">
                <header><div><h2>Habilitación por mina</h2><p>Compara el avance y abre la matriz para revisar el detalle.</p></div></header>
                <div class="mine-bar-chart">
                    @foreach($miningDashboard['by_mine'] as $mineRow)
                        @php $mineStates = collect($mineRow['states']); @endphp
                        <div class="mine-bar-row">
                            <a href="{{ route('personal.habilitacion-minera.index', ['vista' => 'matrix', 'mina_id' => $mineRow['id']]) }}">{{ $mineRow['nombre'] }}</a>
                            <div class="mine-stacked-track">
                                <div class="mine-stacked-bar" style="width: {{ ($mineRow['total'] / $miningDashboard['max_mine_total']) * 100 }}%" aria-label="{{ $mineRow['total'] }} trabajadores">
                                    @foreach($stateMeta as $state => $meta)
                                        @php $count = (int) $mineStates->get($state, 0); @endphp
                                        @if($count > 0)<span style="width: {{ ($count / max(1, $mineRow['total'])) * 100 }}%; --bar-color: {{ $meta['color'] }}" title="{{ $meta['label'] }}: {{ $count }}"></span>@endif
                                    @endforeach
                                </div>
                            </div>
                            <strong>{{ $mineRow['total'] }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>

            <section
                class="mine-dashboard-section mine-dashboard-wide"
                data-mine-exam-dashboard
                data-workers-url="{{ route('personal.habilitacion-minera.dashboard.exam-workers') }}"
                data-mine-id="{{ $dashboardFilters['mina_id'] ?? '' }}"
                data-habilitation-state="{{ $dashboardFilters['estado_habilitacion'] ?? '' }}"
            >
                <header><div><h2>Principales faltantes por examen</h2><p>Selecciona un examen para ver a los trabajadores relacionados.</p></div></header>
                <nav class="mine-exam-state-filter" aria-label="Filtrar faltantes por estado">
                    @foreach($dashboardExamStates as $state => $label)
                        <button type="button" data-mine-exam-state="{{ $state }}" @class(['is-active' => ($dashboardFilters['estado_examen'] ?? '') === $state])>{{ $label }}</button>
                    @endforeach
                </nav>
                @forelse($miningDashboard['missing_exams'] as $examRow)
                    <div class="mine-exam-bar-row">
                        <button type="button" class="mine-exam-link" data-mine-exam-name="{{ $examRow['nombre'] }}" data-mine-exam-row-state="{{ $examRow['estado'] }}">
                            <strong>{{ $examRow['nombre'] }}</strong><span>{{ $examStateLabel($examRow['estado']) }}</span>
                        </button>
                        <div><span style="width: {{ ($examRow['total'] / $miningDashboard['max_exam_total']) * 100 }}%"></span></div>
                        <strong>{{ $examRow['total'] }}</strong>
                    </div>
                @empty
                    <div class="mine-dashboard-empty is-compact"><strong>No hay exámenes faltantes.</strong></div>
                @endforelse
                <div class="mine-dashboard-empty is-compact" data-mine-exam-no-results hidden><strong>No hay exámenes para este estado.</strong></div>
            </section>

                <section class="mine-dashboard-section mine-dashboard-workers" data-mine-exam-workers hidden>
                    <header>
                        <div><h2>Trabajadores: <span data-mine-exam-workers-title></span></h2><p data-mine-exam-workers-summary></p></div>
                        <button type="button" class="btn btn-outline btn-sm" data-mine-exam-workers-close>Cerrar detalle</button>
                    </header>
                    <div class="mine-dashboard-empty is-compact" data-mine-exam-workers-loading><strong>Consultando trabajadores...</strong></div>
                    <div class="mine-dashboard-table-wrap">
                        <table>
                            <thead><tr><th>Trabajador</th><th>Documento</th><th>Mina</th><th>Estado</th><th>Programado</th><th>Realizado</th><th>Vencimiento</th></tr></thead>
                            <tbody data-mine-exam-workers-body></tbody>
                        </table>
                    </div>
                    <div class="mine-dashboard-pagination" data-mine-exam-workers-pagination></div>
                </section>
        </div>
    @endif
</section>
