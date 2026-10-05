<section id="evaluation-dashboard" class="ed-dashboard">
    <header class="ed-dashboard-heading">
        <div>
            <h2>Lectura consolidada</h2>
            <p>Todos los indicadores usan el mismo periodo, alcance y población filtrada.</p>
        </div>
        <span>{{ $dashboard['activeFilterCount'] }} filtros activos</span>
    </header>
    <form action="{{ route('evaluaciones.index') }}" method="GET" id="ed-filters">
        <input type="hidden" name="tipo" value="dashboard">
        <div><label for="ed-evaluation-type">Tipo de evaluacion</label><select id="ed-evaluation-type" name="evaluacion_tipo"><option value="">Todas</option>@foreach($dashboard['types'] as $key => $label)<option value="{{ $key }}" @selected(($dashboardFilters['evaluacion_tipo'] ?? '') === $key)>{{ $key === 'desempeno' ? 'Evaluacion diaria' : $label }}</option>@endforeach</select></div>
        <div><label for="ed-exact">Fecha exacta</label><input id="ed-exact" type="date" value="{{ $dashboardFilters['desde'] === $dashboardFilters['hasta'] ? $dashboardFilters['desde'] : '' }}"></div>
        <div><label for="ed-from">Desde</label><input id="ed-from" name="desde" type="date" required value="{{ $dashboardFilters['desde'] }}"></div>
        <div><label for="ed-to">Hasta</label><input id="ed-to" name="hasta" type="date" required value="{{ $dashboardFilters['hasta'] }}"></div>
        <div><label for="ed-mine">Mina</label><select id="ed-mine" name="mina"><option value="">Todas</option><option value="sin_mina" @selected($dashboardFilters['mina'] === 'sin_mina')>Sin mina</option>@foreach($mines as $mine)<option value="{{ $mine->id }}" @selected($dashboardFilters['mina'] === $mine->id)>{{ $mine->nombre }}</option>@endforeach</select></div>
        <div><label for="ed-job">Cargo</label><select id="ed-job" name="cargo"><option value="">Todos</option>@foreach($dashboard['cargos'] as $job)<option @selected($dashboardFilters['cargo'] === $job)>{{ $job }}</option>@endforeach</select></div>
        <div><label for="ed-origin">Origen</label><select id="ed-origin" name="origen"><option value="">Todos</option>@foreach(['ASISTENCIA' => 'Desde asistencia', 'DIRECTA' => 'Diaria directa', 'HISTORICA' => 'Carga histórica', 'ACTUAL' => 'Supervisor / residente actual', 'LEGADO' => 'Registro previo sin origen'] as $key => $label)<option value="{{ $key }}" @selected(($dashboardFilters['origen'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <button type="submit" class="btn btn-primary">Aplicar</button>
        <a href="{{ route('evaluaciones.index', ['tipo' => 'dashboard']) }}">Restablecer</a>
    </form>
    <p id="ed-feedback" role="status" aria-live="polite"></p>
    <div class="ed-filter-context" aria-label="Contexto de los datos">
        <span>{{ \Illuminate\Support\Carbon::parse($dashboardFilters['desde'])->format('d/m/Y') }} al {{ \Illuminate\Support\Carbon::parse($dashboardFilters['hasta'])->format('d/m/Y') }}</span>
        <span>{{ $dashboardFilters['evaluacion_tipo'] ? ($dashboard['types'][$dashboardFilters['evaluacion_tipo']] ?? 'Tipo sin acceso') : 'Todos los tipos permitidos' }}</span>
        <span>{{ $dashboardFilters['mina'] ? ($dashboardFilters['mina'] === 'sin_mina' ? 'Sin mina' : ($mines->firstWhere('id', $dashboardFilters['mina'])?->nombre ?? 'Mina filtrada')) : 'Todas las minas permitidas' }}</span>
        <span>{{ $dashboardFilters['cargo'] ?: 'Todos los cargos' }}</span>
        @if(!empty($dashboardFilters['origen']))<span>{{ $dashboardFilters['origen'] === 'HISTORICA' ? 'Carga histórica' : $dashboardFilters['origen'] }}</span>@endif
    </div>
    <div class="ed-kpis"><div><span>Evaluaciones</span><strong>{{ $dashboard['total'] }}</strong><small>Registros del universo filtrado</small></div><div><span>Personas evaluadas</span><strong>{{ $dashboard['people'] }}</strong><small>Personas únicas en los resultados</small></div><div><span>Cobertura de puntaje</span><strong>{{ $dashboard['scoreCoverage'] }}%</strong><small>{{ $dashboard['scored'] }} con puntaje · {{ $dashboard['unscored'] }} pendientes o inválidos</small></div>
    @foreach($dashboard['summary'] as $item)<div><span>{{ $item['label'] }} · Promedio</span><strong>{{ $item['average'] !== null ? $item['average'].' / '.$item['max'] : 'Sin puntaje' }}</strong><small>{{ $item['count'] }} evaluaciones · {{ $item['scored'] }} con puntaje valido</small></div>@endforeach</div>
    @if(!$dashboard['total'])<p class="ed-empty">No hay evaluaciones dentro de estos filtros y permisos.</p>@endif
    <div class="ed-charts">
        <section><h2>Distribución por tipo</h2><div class="ed-pie-layout"><div class="ed-pie" role="img" aria-label="Distribución de {{ $dashboard['total'] }} evaluaciones por tipo" style="background:conic-gradient({{ $dashboard['pie'] }})"><span>{{ $dashboard['total'] }}</span></div><ul class="ed-legend">@foreach($dashboard['summary'] as $item)<li><i style="background:{{ $item['color'] }}"></i>{{ $item['label'] }} <strong>{{ $item['count'] }}</strong></li>@endforeach</ul></div></section>
        @foreach(['months' => 'Evolucion mensual', 'byMine' => 'Evaluaciones por mina', 'byCargo' => 'Evaluaciones por cargo'] as $key => $title)
        <section><h2>{{ $title }}</h2><div class="ed-bars">
            @if(empty($dashboard[$key]))<p class="ed-chart-empty">No hay resultados para esta dimensión.</p>@endif
            @foreach($dashboard[$key] as $group)
            <div class="ed-bar-row"><span>{{ $group['name'] }}</span><div class="ed-track">@foreach($group['types'] as $label => $segment)<span style="width:{{ $segment['count'] / max(1, max(array_column($dashboard[$key], 'count'))) * 100 }}%;background:{{ $segment['color'] }}" title="{{ $label }}: {{ $segment['count'] }}"></span>@endforeach</div><strong>{{ $group['count'] }}</strong></div>
            @endforeach
        </div></section>
        @endforeach
    </div>
    <p class="ed-method">Los promedios se calculan por evaluación y por tipo; no son equivalentes entre formularios. Todos los KPI, gráficos y detalles parten exactamente de los mismos registros filtrados. El cargo corresponde al dato actual de Personal. Las evaluaciones de residentes no guardan mina y se muestran como “Sin mina”.</p>
    @if($dashboard['details'] ?? null)
        @include('evaluaciones.partials.dashboard-details', ['detail' => $dashboard['details']])
    @endif
</section>
