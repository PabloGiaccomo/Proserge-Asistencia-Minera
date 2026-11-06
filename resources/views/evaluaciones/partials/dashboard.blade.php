<section id="evaluation-dashboard" class="ed-dashboard">
    <header class="ed-dashboard-heading">
        <div>
            <h2>Resumen de evaluaciones</h2>
            <p>Busca a una persona o combina periodo, mina y cargo para revisar sus resultados.</p>
        </div>
        <span>{{ $dashboard['activeFilterCount'] }} {{ $dashboard['activeFilterCount'] === 1 ? 'filtro activo' : 'filtros activos' }}</span>
    </header>
    @if(empty($dashboard['types']))
        <p class="alert alert-info">No tienes habilitada la consulta de tipos de evaluación. Solicita al administrador los permisos del tipo que necesitas consultar.</p>
    @endif
    <form action="{{ ($standaloneDashboard ?? false) ? route('inicio.dashboard.evaluaciones') : route('evaluaciones.index') }}" method="GET" id="ed-filters">
        <input type="hidden" name="tipo" value="dashboard">
        <div class="ed-search-field">
            <label for="ed-search">Buscar persona</label>
            <input id="ed-search" name="q" type="search" maxlength="191" placeholder="Nombre, apellidos o DNI" value="{{ $dashboardFilters['q'] ?? '' }}" aria-describedby="ed-search-help">
            <small id="ed-search-help">Puedes escribir los apellidos en cualquier orden.</small>
        </div>
        <div>
            <label for="ed-evaluation-type">Tipo de evaluación</label>
            <select id="ed-evaluation-type" name="evaluacion_tipo">
                <option value="">Todos los tipos</option>
                @foreach($dashboard['types'] as $key => $label)
                    <option value="{{ $key }}" @selected(($dashboardFilters['evaluacion_tipo'] ?? '') === $key)>{{ $key === 'desempeno' ? 'Evaluación diaria' : $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="ed-mine">Mina</label>
            <select id="ed-mine" name="mina">
                <option value="">Todas las minas</option>
                <option value="sin_mina" @selected($dashboardFilters['mina'] === 'sin_mina')>Sin mina</option>
                @foreach($mines as $mine)
                    <option value="{{ $mine->id }}" @selected($dashboardFilters['mina'] === $mine->id)>{{ $mine->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="ed-from">Desde</label><input id="ed-from" name="desde" type="date" required value="{{ $dashboardFilters['desde'] }}"></div>
        <div><label for="ed-to">Hasta</label><input id="ed-to" name="hasta" type="date" required value="{{ $dashboardFilters['hasta'] }}"></div>
        <div class="ed-job-field">
            <label for="ed-job">Cargo</label>
            <select id="ed-job" name="cargo">
                <option value="">Todos los cargos</option>
                @foreach($dashboard['cargos'] as $job)
                    <option @selected($dashboardFilters['cargo'] === $job)>{{ $job }}</option>
                @endforeach
            </select>
        </div>
        <details id="ed-more-filters" class="ed-more-filters" @if(!empty($dashboardFilters['origen']) || $dashboardFilters['desde'] === $dashboardFilters['hasta']) open @endif>
            <summary>Más filtros <span>Fecha exacta y origen</span></summary>
            <div class="ed-advanced-grid">
                <div><label for="ed-exact">Fecha exacta</label><input id="ed-exact" type="date" value="{{ $dashboardFilters['desde'] === $dashboardFilters['hasta'] ? $dashboardFilters['desde'] : '' }}"><small>Seleccionarla aplica ese día al rango.</small></div>
                <div>
                    <label for="ed-origin">Origen</label>
                    <select id="ed-origin" name="origen">
                        <option value="">Todos los orígenes</option>
                        @foreach(['ASISTENCIA' => 'Desde asistencia', 'DIRECTA' => 'Diaria directa', 'HISTORICA' => 'Carga histórica', 'ACTUAL' => 'Supervisor / residente actual', 'LEGADO' => 'Registro previo sin origen'] as $key => $label)
                            <option value="{{ $key }}" @selected(($dashboardFilters['origen'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </details>
        <div class="ed-filter-actions">
            <button id="ed-apply" type="submit" class="btn btn-primary">Buscar evaluaciones</button>
            <a id="ed-reset" class="btn btn-outline" href="{{ ($standaloneDashboard ?? false) ? route('inicio.dashboard.evaluaciones') : route('evaluaciones.index', ['tipo' => 'dashboard']) }}">Restablecer</a>
        </div>
    </form>
    <p id="ed-feedback" role="status" aria-live="polite"></p>
    <div class="ed-filter-context" aria-label="Contexto de los datos">
        <span>{{ \Illuminate\Support\Carbon::parse($dashboardFilters['desde'])->format('d/m/Y') }} al {{ \Illuminate\Support\Carbon::parse($dashboardFilters['hasta'])->format('d/m/Y') }}</span>
        <span>{{ $dashboardFilters['evaluacion_tipo'] ? ($dashboard['types'][$dashboardFilters['evaluacion_tipo']] ?? 'Tipo sin acceso') : 'Todos los tipos permitidos' }}</span>
        <span>{{ $dashboardFilters['mina'] ? ($dashboardFilters['mina'] === 'sin_mina' ? 'Sin mina' : ($mines->firstWhere('id', $dashboardFilters['mina'])?->nombre ?? 'Mina filtrada')) : 'Todas las minas permitidas' }}</span>
        <span>{{ $dashboardFilters['cargo'] ?: 'Todos los cargos' }}</span>
        @if(!empty($dashboardFilters['q']))<span>Persona: {{ $dashboardFilters['q'] }}</span>@endif
        @if(!empty($dashboardFilters['origen']))<span>{{ $dashboardFilters['origen'] === 'HISTORICA' ? 'Carga histórica' : $dashboardFilters['origen'] }}</span>@endif
    </div>
    <div class="ed-kpis"><div><span>Evaluaciones</span><strong>{{ $dashboard['total'] }}</strong><small>Registros del universo filtrado</small></div><div><span>Personas evaluadas</span><strong>{{ $dashboard['people'] }}</strong><small>Personas únicas en los resultados</small></div><div><span>Cobertura de puntaje</span><strong>{{ $dashboard['scoreCoverage'] }}%</strong><small>{{ $dashboard['scored'] }} con puntaje · {{ $dashboard['unscored'] }} pendientes o inválidos</small></div>
    @foreach($dashboard['summary'] as $item)<div><span>{{ $item['label'] }} · Promedio</span><strong>{{ $item['average'] !== null ? $item['average'].' / '.$item['max'] : 'Sin puntaje' }}</strong><small>{{ $item['count'] }} evaluaciones · {{ $item['scored'] }} con puntaje valido</small></div>@endforeach</div>
    @if(!$dashboard['total'])
        <div class="ed-empty">
            <strong>No hay evaluaciones con estos filtros.</strong>
            <p>Prueba ampliar el periodo, revisar el nombre o DNI, o restablecer los filtros. Solo se muestran los registros que tienes permiso para consultar.</p>
        </div>
    @else
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
    @endif
    <p class="ed-method">Los promedios se calculan por evaluación y por tipo; no son equivalentes entre formularios. Todos los KPI, gráficos y detalles parten exactamente de los mismos registros filtrados. El cargo corresponde al dato actual de Personal. Las evaluaciones de residentes no guardan mina y se muestran como “Sin mina”.</p>
    @if($dashboard['total'] && ($dashboard['details'] ?? null))
        @include('evaluaciones.partials.dashboard-details', ['detail' => $dashboard['details']])
    @endif
</section>
