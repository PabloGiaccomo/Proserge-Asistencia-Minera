@extends('layouts.app')

@section('title', 'Logística - Proserge')

@section('content')
@php
    $validTabs = [
        'dashboard',
        'entregas',
        'vencimientos',
        'herramientas',
        'servicios',
        'identificacion',
        'costos',
        'kardex',
        'cesados',
    ];

    $activeTab = $activeTab ?? request()->query('tab', 'dashboard');
    $activeTab = in_array($activeTab, $validTabs, true) ? $activeTab : 'dashboard';
    $activeTabIndex = array_search($activeTab, $validTabs, true);
    $activeTabIndex = $activeTabIndex === false ? 0 : $activeTabIndex;

    $tabs = [
        'dashboard' => 'Dashboard',
        'entregas' => 'Entregas y cambios de EPP',
        'vencimientos' => 'Próximos vencimientos de EPP',
        'herramientas' => 'Herramientas',
        'servicios' => 'Servicios y alquileres',
        'identificacion' => 'Identificación de ítems',
        'costos' => 'Costos y facturación',
        'kardex' => 'Kardex',
        'cesados' => 'Cesados por entregar',
    ];

    $permissions = session('user.permissions', []);
    $canViewEpps = (bool) ($canViewEpps ?? \App\Support\Rbac\PermissionMatrix::allows($permissions, 'epps', 'ver'));
    $canViewHerramientas = (bool) ($canViewHerramientas ?? ($canViewEpps || \App\Support\Rbac\PermissionMatrix::allows($permissions, 'herramientas', 'ver')));
    $logisticsTabActions = \App\Support\Rbac\PermissionCatalog::logisticsTabActions();
    $canViewLogisticsTab = static function (string $tab) use ($permissions, $logisticsTabActions): bool {
        $action = $logisticsTabActions[$tab] ?? null;

        return $action !== null
            && \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'logistica', $action);
    };
    $canViewTabs = [];
    foreach (array_keys($tabs) as $tabKey) {
        $canViewTabs[$tabKey] = $canViewLogisticsTab($tabKey);
    }
    $allTabs = $tabs;
    $tabs = [];
    if ($canViewTabs['dashboard'] ?? false) {
        $tabs['dashboard'] = $allTabs['dashboard'];
    }
    if ($canViewTabs['entregas'] ?? false) {
        $tabs['entregas'] = $allTabs['entregas'];
    }
    if ($canViewTabs['vencimientos'] ?? false) {
        $tabs['vencimientos'] = $allTabs['vencimientos'];
    }
    if ($canViewTabs['herramientas'] ?? false) {
        $tabs['herramientas'] = $allTabs['herramientas'];
    }
    if ($canViewTabs['servicios'] ?? false) {
        $tabs['servicios'] = $allTabs['servicios'];
    }
    if ($canViewTabs['identificacion'] ?? false) {
        $tabs['identificacion'] = $allTabs['identificacion'];
    }
    if ($canViewTabs['costos'] ?? false) {
        $tabs['costos'] = $allTabs['costos'];
    }
    if ($canViewTabs['kardex'] ?? false) {
        $tabs['kardex'] = $allTabs['kardex'];
    }
    if ($canViewTabs['cesados'] ?? false) {
        $tabs['cesados'] = $allTabs['cesados'];
    }
    $validTabs = array_keys($tabs);
    $fallbackTab = $validTabs[0] ?? 'herramientas';
    $activeTab = in_array($activeTab, $validTabs, true) ? $activeTab : $fallbackTab;
    $activeTabIndex = array_search($activeTab, $validTabs, true);
    $activeTabIndex = $activeTabIndex === false ? 0 : $activeTabIndex;

    $tabUrl = function (string $tab): string {
        $query = request()->except(['tab', 'page']);
        $query['tab'] = $tab;

        return url('/logistica') . '?' . http_build_query($query);
    };

    $asCollection = static function ($value) {
        if ($value instanceof \Illuminate\Pagination\AbstractPaginator) {
            return $value->getCollection();
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value;
        }

        return collect($value ?? []);
    };

    $metrics = $metrics ?? [];
    $permissions = session('user.permissions', []);
    $canUpdateTransportes = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'logistica', 'actualizar');

    $metric = static fn (string $key, int|float $default = 0) => data_get($metrics, $key, $default) ?? $default;

    $resolve = static function ($row, array $keys, string $fallback = '—'): string {
        foreach ($keys as $key) {
            $value = data_get($row, $key);

            if (! blank($value)) {
                return (string) $value;
            }
        }

        return $fallback;
    };

    $recentDeliveryRows = $asCollection($recentDeliveries ?? []);
    $allExpiringDeliveryRows = $asCollection($expiringDeliveries ?? []);
    $expiringDeliveryRows = $asCollection($filteredExpiringDeliveries ?? $expiringDeliveries ?? []);
    $toolPreviewRows = $asCollection($toolsRows ?? []);
    $servicePreviewRows = $asCollection($serviceRows ?? []);
    $identityRows = $asCollection($identityRows ?? []);
    $costRows = $asCollection($costRows ?? []);
    $sizeRows = $asCollection($sizeSummary ?? []);
    $mineRows = $asCollection($mineSummary ?? []);
    $requirementRows = $asCollection($requirements ?? []);
    $missingRows = $asCollection($missingWorkers ?? []);
    $ceasedRows = $asCollection($ceasedRows ?? []);
    $ceasedSummary = $ceasedSummary ?? ['trabajadores' => 0, 'pendientes' => 0, 'resueltos' => 0];

    $maxMineTotal = max(1, (int) $mineRows->max('total'));
    $maxRequirement = max(1, (int) $requirementRows->max('requerido'));

    $topChangedItem = $recentDeliveryRows
        ->groupBy(fn ($row) => $resolve($row, ['epp', 'item', 'epp_nombre', 'epp.nombre'], 'Sin item'))
        ->map(fn ($items, $name) => ['name' => $name, 'total' => $items->count()])
        ->sortByDesc('total')
        ->first();

    $topChangedName = data_get($topChangedItem, 'name', 'Sin datos');
    $topChangedTotal = (int) data_get($topChangedItem, 'total', 0);

    $totalEstimatedEpp = (int) $requirementRows->sum(fn ($row) => (int) data_get($row, 'requerido', 0));
    $pendingEstimatedEpp = (int) $requirementRows->sum(fn ($row) => (int) data_get($row, 'pendiente_entrega', 0));
    $vencFilters = data_get($filters ?? [], 'vencimientos', []);
    $vencTallas = collect(data_get($options ?? [], 'tallas', []))
        ->merge($allExpiringDeliveryRows->pluck('talla'))
        ->filter(fn ($value) => filled($value) && $value !== '—')
        ->unique()
        ->sort()
        ->values();
@endphp

<div class="lgt-page">
    <header class="lgt-header">
        <h1 class="lgt-title">Logística</h1>
        <p class="lgt-subtitle">Control de EPP, herramientas, servicios y costos</p>
    </header>

    <nav class="lgt-tabs" aria-label="Secciones de logística" data-logistics-tabs>
        @foreach($tabs as $key => $label)
            <a
                href="{{ $tabUrl($key) }}"
                class="lgt-tab {{ $activeTab === $key ? 'active' : '' }}"
                data-logistics-tab-link="{{ $key }}"
                aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
            >
                {{ $label }}
            </a>
        @endforeach
    </nav>

    @if(session('success'))
        <div class="lgt-alert lgt-alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="lgt-alert lgt-alert-error">{{ $errors->first() }}</div>
    @endif

    <section class="lgt-content" data-logistics-tab-viewport>
        <div class="lgt-tab-track lgt-tab-track--{{ $activeTabIndex }}" data-logistics-tab-track data-logistics-active-index="{{ $activeTabIndex }}">
            @if($canViewTabs['dashboard'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'dashboard' ? 'is-active' : '' }}" data-logistics-tab-panel="dashboard" aria-hidden="{{ $activeTab === 'dashboard' ? 'false' : 'true' }}">
    <section class="lgt2-dashboard">
        @include('logistica.partials.dashboard')
        <div class="lgt2-legacy-dashboard" hidden>
        <form class="lgt2-filters" method="GET" action="{{ url('/logistica') }}" data-logistics-dashboard-filters>
            <input type="hidden" name="tab" value="dashboard">

            <label>
                <span>Buscar</span>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Trabajador, DNI, cargo o EPP">
            </label>

            <label>
                <span>Mina</span>
                <select name="minas[]">
                    <option value="">Todas las minas</option>
                    @foreach($asCollection(data_get($options ?? [], 'minas', [])) as $mina)
                        <option value="{{ data_get($mina, 'id') }}" @selected(in_array((string) data_get($mina, 'id'), (array) request('minas', [])))>
                            {{ data_get($mina, 'nombre', 'Sin mina') }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Parada / RQ</span>
                <select name="parada_id">
                    <option value="">Todas las paradas</option>
                    @foreach($asCollection(data_get($options ?? [], 'paradas', [])) as $parada)
                        <option value="{{ data_get($parada, 'id') }}" @selected((string) request('parada_id') === (string) data_get($parada, 'id'))>
                            {{ data_get($parada, 'label', 'Sin parada') }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>EPP</span>
                <select name="epps[]">
                    <option value="">Todos los EPP</option>
                    @foreach($asCollection(data_get($options ?? [], 'epps', [])) as $epp)
                        <option value="{{ data_get($epp, 'id') }}" @selected(in_array((string) data_get($epp, 'id'), (array) request('epps', [])))>
                            {{ data_get($epp, 'nombre', 'Sin EPP') }}
                        </option>
                    @endforeach
                </select>
            </label>

            <a href="{{ url('/logistica') }}">Limpiar</a>
        </form>

        <div class="lgt2-kpis">
            <article>
                <span>Trabajadores en alcance</span>
                <strong>{{ number_format((float) $metric('workers'), 0) }}</strong>
                <small>{{ number_format((float) $metric('habilitados'), 0) }} habilitados según filtro</small>
            </article>

            <article>
                <span>EPP estimado</span>
                <strong>{{ number_format($totalEstimatedEpp) }}</strong>
                <small>{{ number_format($pendingEstimatedEpp) }} pendientes de cobertura</small>
            </article>

            <article>
                <span>Próximos vencimientos</span>
                <strong>{{ number_format($expiringDeliveryRows->count()) }}</strong>
                <small>{{ number_format((float) $metric('vencidos'), 0) }} vencidos</small>
            </article>

            <article>
                <span>Ítem con más cambios</span>
                <strong>{{ $topChangedTotal > 0 ? $topChangedName : '—' }}</strong>
                <small>{{ $topChangedTotal > 0 ? $topChangedTotal . ' movimientos' : 'Sin movimientos' }}</small>
            </article>
        </div>

        <div class="lgt2-grid">
            <section class="lgt2-card lgt2-wide">
                <header>
                    <h2>Trabajadores habilitados por mina</h2>
                    <p>Cantidad de trabajadores asociados y habilitados por unidad.</p>
                </header>

                <div class="lgt2-bars">
                    @forelse($mineRows->take(8) as $row)
                        @php
                            $label = $resolve($row, ['label', 'mina', 'nombre']);
                            $total = (int) data_get($row, 'total', 0);
                            $habilitados = (int) data_get($row, 'habilitados', 0);
                            $pct = min(100, round(($habilitados / $maxMineTotal) * 100));
                        @endphp

                        <div class="lgt2-bar-row" title="{{ $habilitados }} habilitados de {{ $total }} en {{ $label }}">
                            <div>
                                <strong>{{ $label }}</strong>
                                <small>{{ $habilitados }} habilitados / {{ $total }} total</small>
                            </div>
                            <div class="lgt2-bar"><i style="width: {{ $pct }}%"></i></div>
                            <b>{{ $habilitados }}</b>
                        </div>
                    @empty
                        <div class="lgt2-empty">No hay datos disponibles</div>
                    @endforelse
                </div>
            </section>

            <section class="lgt2-card">
                <header>
                    <h2>Tallas del personal</h2>
                    <p>Distribución desde la ficha del colaborador.</p>
                </header>

                <div class="lgt2-size-list">
                    @forelse($sizeRows as $group)
                        @php
                            $sizes = collect(data_get($group, 'tallas', []));
                            $maxSize = max(1, (int) $sizes->max('total'));
                        @endphp

                        <div class="lgt2-size-block">
                            <h3>{{ data_get($group, 'tipo', 'Sin tipo') }}</h3>

                            @forelse($sizes->take(8) as $size)
                                @php
                                    $count = (int) data_get($size, 'total', 0);
                                    $pct = min(100, round(($count / $maxSize) * 100));
                                @endphp

                                <div class="lgt2-size-row" title="{{ $count }} trabajadores con talla {{ data_get($size, 'talla') }}">
                                    <span>{{ data_get($size, 'talla', '—') }}</span>
                                    <div class="lgt2-bar"><i style="width: {{ $pct }}%"></i></div>
                                    <b>{{ $count }}</b>
                                </div>
                            @empty
                                <p>No hay tallas registradas.</p>
                            @endforelse
                        </div>
                    @empty
                        <div class="lgt2-empty">No hay datos disponibles</div>
                    @endforelse
                </div>
            </section>

            <section class="lgt2-card lgt2-wide">
                <header>
                    <h2>Estimación de EPP por personal filtrado</h2>
                    <p>Estimación según personal en alcance, entregas activas y stock.</p>
                </header>

                <div class="lgt2-requirements">
                    @forelse($requirementRows->take(10) as $row)
                        @php
                            $required = (int) data_get($row, 'requerido', 0);
                            $delivered = (int) data_get($row, 'entregado', 0);
                            $pending = (int) data_get($row, 'pendiente_entrega', 0);
                            $pct = min(100, round(($required > 0 ? $delivered / $required : 0) * 100));
                            $stockState = data_get($row, 'stock_estado', 'SIN STOCK');
                        @endphp

                        <div class="lgt2-req-row" title="{{ $delivered }} entregado de {{ $required }} requerido. Pendiente: {{ $pending }}">
                            <div>
                                <strong>{{ data_get($row, 'nombre', 'Sin EPP') }}</strong>
                                <small>Req. {{ $required }} · Ent. {{ $delivered }} · Pend. {{ $pending }}</small>
                            </div>
                            <div class="lgt2-bar"><i style="width: {{ $pct }}%"></i></div>
                            <span class="lgt2-pill {{ $stockState === 'FALTANTE' ? 'danger' : 'ok' }}">{{ $stockState }}</span>
                        </div>
                    @empty
                        <div class="lgt2-empty">No hay datos disponibles</div>
                    @endforelse
                </div>
            </section>

            <section class="lgt2-card" id="lgt-vencimientos">
                <header>
                    <h2>Próximos vencimientos</h2>
                    <p>Trabajador, EPP, vencimiento y días restantes.</p>
                </header>

                <div class="lgt2-list">
                    @forelse($expiringDeliveryRows->take(8) as $row)
                        @php
                            $state = data_get($row, 'estado_visual', 'VIGENTE');
                            $tone = $state === 'VENCIDO' ? 'danger' : ($state === 'POR_VENCER' ? 'warning' : 'ok');
                        @endphp

                        <div class="lgt2-list-row" title="Vence: {{ data_get($row, 'fecha_vencimiento', '—') }}">
                            <div>
                                <strong>{{ $resolve($row, ['trabajador']) }}</strong>
                                <small>{{ $resolve($row, ['epp', 'item']) }}</small>
                            </div>
                            <div>
                                <b>{{ data_get($row, 'dias', '—') }} días</b>
                                <span class="lgt2-pill {{ $tone }}">{{ $state }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="lgt2-empty">No hay datos disponibles</div>
                    @endforelse
                </div>
            </section>

            <section class="lgt2-card">
                <header>
                    <h2>Entregas recientes</h2>
                    <p>Últimos movimientos de EPP.</p>
                </header>

                <div class="lgt2-list">
                    @forelse($recentDeliveryRows->take(8) as $row)
                        <div class="lgt2-list-row" title="{{ $resolve($row, ['observacion'], 'Sin observación') }}">
                            <div>
                                <strong>{{ $resolve($row, ['trabajador']) }}</strong>
                                <small>{{ $resolve($row, ['epp', 'item']) }}</small>
                            </div>
                            <b>{{ $resolve($row, ['fecha_entrega']) }}</b>
                        </div>
                    @empty
                        <div class="lgt2-empty">No hay datos disponibles</div>
                    @endforelse
                </div>
            </section>
        </div>
        </div>
    </section>

            </section>
            @endif

            @if($canViewTabs['entregas'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'entregas' ? 'is-active' : '' }}" data-logistics-tab-panel="entregas" aria-hidden="{{ $activeTab === 'entregas' ? 'false' : 'true' }}">
                @include('epps.partials.workspace', array_merge($eppModule ?? [], ['embedded' => true]))
            </section>
            @endif

            @if($canViewTabs['vencimientos'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'vencimientos' ? 'is-active' : '' }}" data-logistics-tab-panel="vencimientos" aria-hidden="{{ $activeTab === 'vencimientos' ? 'false' : 'true' }}">
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <h2>Próximos vencimientos de EPP</h2>
                    <p>Elementos por vencer o por renovar.</p>
                </div>

                <div class="lgt-card-body">
                    <form method="GET" action="{{ route('logistica.index') }}" class="lgt-expiring-filters">
                        <input type="hidden" name="tab" value="vencimientos">

                        <label>
                            <span>Buscar</span>
                            <input type="search" name="venc_q" value="{{ data_get($vencFilters, 'q') }}" placeholder="Trabajador, DNI o EPP">
                        </label>

                        <label>
                            <span>Mina</span>
                            <select name="venc_mina_id">
                                <option value="">Todas las minas</option>
                                @foreach(data_get($options ?? [], 'minas', []) as $mina)
                                    <option value="{{ $mina->id }}" @selected(data_get($vencFilters, 'mina_id') === $mina->id)>{{ $mina->nombre }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>EPP / item</span>
                            <select name="venc_epp_id">
                                <option value="">Todos los EPP</option>
                                @foreach(data_get($options ?? [], 'epps', []) as $epp)
                                    <option value="{{ $epp->id }}" @selected(data_get($vencFilters, 'epp_id') === $epp->id)>{{ $epp->nombre }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>Talla</span>
                            <select name="venc_talla">
                                <option value="">Todas</option>
                                @foreach($vencTallas as $talla)
                                    <option value="{{ $talla }}" @selected(data_get($vencFilters, 'talla') === $talla)>{{ $talla }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>Estado</span>
                            <select name="venc_estado">
                                <option value="">Todos</option>
                                <option value="VIGENTE" @selected(data_get($vencFilters, 'estado') === 'VIGENTE')>Vigente</option>
                                <option value="POR_VENCER" @selected(data_get($vencFilters, 'estado') === 'POR_VENCER')>Por vencer</option>
                                <option value="VENCIDO" @selected(data_get($vencFilters, 'estado') === 'VENCIDO')>Vencido</option>
                            </select>
                        </label>

                        <label>
                            <span>Rango de vencimiento</span>
                            <select name="venc_rango">
                                <option value="" @selected(data_get($vencFilters, 'rango') === '')>Todos</option>
                                <option value="vencidos" @selected(data_get($vencFilters, 'rango') === 'vencidos')>Vencidos</option>
                                <option value="7" @selected(data_get($vencFilters, 'rango') === '7')>7 días</option>
                                <option value="15" @selected(data_get($vencFilters, 'rango') === '15')>15 días</option>
                                <option value="30" @selected(data_get($vencFilters, 'rango', '30') === '30')>30 días</option>
                            </select>
                        </label>

                        <label>
                            <span>Fecha desde</span>
                            <input type="date" name="venc_fecha_desde" value="{{ data_get($vencFilters, 'fecha_desde') }}">
                        </label>

                        <label>
                            <span>Fecha hasta</span>
                            <input type="date" name="venc_fecha_hasta" value="{{ data_get($vencFilters, 'fecha_hasta') }}">
                        </label>

                        <div class="lgt-expiring-actions">
                            <button type="submit">Filtrar</button>
                            <a href="{{ route('logistica.index', ['tab' => 'vencimientos']) }}">Limpiar</a>
                        </div>
                    </form>

                    <div class="lgt-table-wrap">
                        @includeIf('logistica.partials.delivery-table', [
                            'rows' => $expiringDeliveryRows,
                            'showDays' => true,
                        ])
                    </div>
                </div>
            </div>

            </section>
            @endif

            @if($canViewTabs['herramientas'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'herramientas' ? 'is-active' : '' }}" data-logistics-tab-panel="herramientas" aria-hidden="{{ $activeTab === 'herramientas' ? 'false' : 'true' }}">
                @include('parada-herramientas.partials.index-content', [
                    'items' => data_get($herramientasModule ?? [], 'items', []),
                    'filters' => data_get($herramientasModule ?? [], 'filters', []),
                    'deadlineAlerts' => data_get($herramientasModule ?? [], 'deadlineAlerts', []),
                    'toolsEmbedded' => true,
                    'toolsIndexAction' => route('logistica.index'),
                    'toolsIndexResetUrl' => route('logistica.index', ['tab' => 'herramientas']),
                    'toolsShowAlerts' => false,
                    'toolsShowDeadlineAlerts' => $activeTab === 'herramientas',
                ])
            @if(false)
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <h2>Herramientas</h2>
                    <p>Resumen de herramientas, consumibles y recepción.</p>
                </div>

                <div class="lgt-card-body">
                    @if($toolPreviewRows->isEmpty())
                        <div class="lgt-empty">No hay datos disponibles</div>
                    @else
                        <div class="lgt-list">
                            @foreach($toolPreviewRows as $row)
                                <div class="lgt-list-row">
                                    <strong>{{ $resolve($row, ['parada', 'nombre']) }}</strong>
                                    <span>{{ $resolve($row, ['estado', 'status'], 'Sin estado') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            @endif

            </section>
            @endif

            @if($canViewTabs['servicios'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'servicios' ? 'is-active' : '' }}" data-logistics-tab-panel="servicios" aria-hidden="{{ $activeTab === 'servicios' ? 'false' : 'true' }}">
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <h2>Servicios y alquileres</h2>
                    <p>Atención logística de transportes solicitados desde RQ Mina.</p>
                </div>

                <div class="lgt-card-body">
                    @php
                        $selectedParadaId = request('servicio_parada_id', '');
                    @endphp

                    {{-- Explorador visual de paradas --}}
                    <div class="lgt-parada-explorer">
                        <label class="lgt-parada-explorer-label">Seleccionar parada</label>
                        <div class="lgt-parada-explorer-tools">
                            <div class="lgt-parada-search-wrap">
                                <input type="text" class="lgt-parada-search" id="lgtParadaSearch" placeholder="Buscar parada por mina, area o fecha..." autocomplete="off">
                            </div>
                            <select class="lgt-parada-mina-filter" id="lgtParadaMinaFilter">
                                <option value="">Todas las minas</option>
                                @foreach($asCollection(data_get($options ?? [], 'minas', [])) as $mina)
                                    <option value="{{ strtolower(data_get($mina, 'nombre')) }}">{{ data_get($mina, 'nombre') }}</option>
                                @endforeach
                            </select>
                        </div>

                        @php
                            $paradasCol = $asCollection(data_get($options ?? [], 'paradas', []));
                        @endphp

                        <div class="lgt-parada-list" id="lgtParadaList">
                            @forelse($paradasCol as $parada)
                                @php
                                    $pEstado = data_get($parada, 'estado', 'SIN_FECHA');
                                    $pEstadoLabel = data_get($parada, 'estado_label', 'Sin fecha');
                                    $pTiempo = data_get($parada, 'tiempo_texto', '');
                                    $pId = data_get($parada, 'id');
                                    $pMina = strtolower(data_get($parada, 'mina_nombre', ''));
                                    $pArea = data_get($parada, 'area', '');
                                    $pInicio = data_get($parada, 'fecha_inicio_label', '');
                                    $pFin = data_get($parada, 'fecha_fin_label', '');
                                    $pLabel = data_get($parada, 'label', '');
                                    $isSelected = $selectedParadaId === (string) $pId;

                                    $estadoClass = match ($pEstado) {
                                        'EN_CURSO' => 'badge-en-curso',
                                        'POR_INICIAR' => 'badge-por-iniciar',
                                        'PROXIMA' => 'badge-proxima',
                                        'FINALIZADA' => 'badge-finalizada',
                                        default => 'badge-sin-fecha',
                                    };
                                    $estadoIcon = match ($pEstado) {
                                        'EN_CURSO' => '🟢',
                                        'POR_INICIAR' => '🟡',
                                        'PROXIMA' => '🔵',
                                        'FINALIZADA' => '⚪',
                                        default => '⚫',
                                    };
                                @endphp

                                <button type="button"
                                   class="lgt-parada-card {{ $isSelected ? 'is-selected' : '' }}"
                                   data-parada-id="{{ $pId }}"
                                   data-parada-search="{{ strtolower($pLabel . ' ' . $pMina . ' ' . $pArea) }}"
                                   data-parada-mina="{{ $pMina }}"
                                   aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
                                    <div class="lgt-parada-card-main">
                                        <div class="lgt-parada-card-title">
                                            <strong>{{ data_get($parada, 'mina_nombre') }}</strong>
                                            <span class="lgt-parada-card-area">{{ $pArea }}</span>
                                        </div>
                                        <div class="lgt-parada-card-dates">
                                            <span>{{ $pInicio }} al {{ $pFin }}</span>
                                            @if($pTiempo)
                                                <span class="lgt-parada-card-time">{{ $pTiempo }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="lgt-parada-card-badge {{ $estadoClass }}">
                                        <span>{{ $estadoIcon }} {{ $pEstadoLabel }}</span>
                                    </div>
                                    <div class="lgt-parada-card-check" aria-hidden="true">✓</div>
                                </button>
                            @empty
                                <div class="lgt-parada-empty">No hay paradas disponibles.</div>
                            @endforelse
                        </div>
                    </div>

                    @php
                        $filteredRows = $servicePreviewRows->filter(function ($row) use ($selectedParadaId) {
                            return $selectedParadaId !== '' && (string) data_get($row, 'rq_mina_id') === $selectedParadaId;
                        })->values();
                    @endphp

                    <div class="lgt-servicios-empty" id="lgtServiceEmptyPrompt" @if($selectedParadaId !== '') hidden @endif>
                            <span class="lgt-servicios-empty-icon">🚛</span>
                            <p>Selecciona una parada de la lista para ver sus requerimientos de transporte y gestionar la atención logística.</p>
                    </div>

                    <div class="lgt-empty" id="lgtServiceEmptyNoRows" @if($selectedParadaId === '' || $filteredRows->isNotEmpty()) hidden @endif>No hay transportes solicitados para esta parada.</div>

                    @if($servicePreviewRows->isNotEmpty())
                        <div class="lgt-service-grid" id="lgtServiceGrid" @if($selectedParadaId === '' || $filteredRows->isEmpty()) hidden @endif>
                            @foreach($servicePreviewRows as $row)
                                @php
                                    $isCompletado = data_get($row, 'origen') !== '' || data_get($row, 'estado') !== 'REQUERIDO' || data_get($row, 'fecha_inicio') !== '';
                                    $rowParadaId = (string) data_get($row, 'rq_mina_id');
                                    $selectedParada = $paradasCol->firstWhere('id', $rowParadaId);
                                    $paradaActiva = $selectedParada && data_get($selectedParada, 'estado') !== 'FINALIZADA';
                                    $tieneEntrega = data_get($row, 'recepcion_estado') !== 'PENDIENTE';
                                    $puedeHacerCambios = $tieneEntrega && $paradaActiva;
                                    $cardClass = $isCompletado ? 'is-completado' : 'is-pendiente';
                                    $rowVisible = $selectedParadaId !== '' && $rowParadaId === $selectedParadaId;
                                @endphp

                                <article class="lgt-service-card {{ $cardClass }}" data-rq-mina-id="{{ $rowParadaId }}" @if(!$rowVisible) hidden @endif>
                                    <header class="lgt-service-head">
                                        <div>
                                            <strong>{{ $resolve($row, ['parada']) }}</strong>
                                            <span>{{ $resolve($row, ['grupo']) }} · {{ $resolve($row, ['alcance']) }}</span>
                                        </div>
                                        <b class="lgt-status {{ $isCompletado ? 'is-ok' : 'is-warning' }}">
                                            {{ $isCompletado ? 'Atendido' : 'Pendiente' }}
                                        </b>
                                    </header>

                                    <div class="lgt-service-request">
                                        <div>
                                            <span>Unidad de carga</span>
                                            <strong>{{ $resolve($row, ['unidad_carga']) }}</strong>
                                        </div>
                                        <div>
                                            <span>Transporte solicitado</span>
                                            <strong>{{ $resolve($row, ['solicitado']) }}</strong>
                                        </div>
                                        <div>
                                            <span>Fechas solicitadas</span>
                                            <strong>{{ $resolve($row, ['fecha_inicio_label']) }} - {{ $resolve($row, ['fecha_fin_label']) }}</strong>
                                        </div>
                                    </div>

                                    @if($canUpdateTransportes)
                                        @if(!$isCompletado)
                                            <div class="lgt-service-completar-panel">
                                                <p class="lgt-service-completar-hint">Este requerimiento aún no ha sido atendido. Completa los datos del transporte asignado.</p>
                                                <button type="button" class="lgt-service-btn lgt-service-btn-primary" data-toggle-panel="completar-{{ $loop->index }}">
                                                    Completar requerimiento
                                                </button>

                                                <div class="lgt-service-form-panel" id="panel-completar-{{ $loop->index }}" hidden>
                                                    <form method="POST" action="{{ route('logistica.transportes.update', $row['id']) }}" class="lgt-service-form-inline">
                                                        @csrf
                                                        @method('PUT')
                                                        <div class="lgt-service-form-grid">
                                                            <label>
                                                                <span>Origen</span>
                                                                <select name="origen">
                                                                    <option value="">Sin definir</option>
                                                                    <option value="EMPRESA" @selected(data_get($row, 'origen') === 'EMPRESA')>Empresa</option>
                                                                    <option value="ALQUILADO" @selected(data_get($row, 'origen') === 'ALQUILADO')>Alquilado</option>
                                                                    <option value="OTRO" @selected(data_get($row, 'origen') === 'OTRO')>Otro</option>
                                                                </select>
                                                            </label>
                                                            <label class="wide">
                                                                <span>Placa o datos del transporte asignado</span>
                                                                <input type="text" name="placas_asignadas" value="{{ data_get($row, 'placas_asignadas') }}" placeholder="ABC-123; proveedor; chofer; teléfono">
                                                            </label>
                                                            <label>
                                                                <span>Fecha inicio</span>
                                                                <input type="date" name="fecha_inicio" value="{{ data_get($row, 'fecha_inicio') }}">
                                                            </label>
                                                            <label>
                                                                <span>Fecha fin</span>
                                                                <input type="date" name="fecha_fin" value="{{ data_get($row, 'fecha_fin') }}">
                                                            </label>
                                                            <label>
                                                                <span>Estado logístico</span>
                                                                <select name="estado_logistico">
                                                                    @foreach(['REQUERIDO' => 'Requerido', 'ASIGNADO' => 'Asignado', 'EN_USO' => 'En uso', 'RETIRADO' => 'Retirado', 'REEMPLAZADO' => 'Reemplazado', 'DEVUELTO' => 'Devuelto', 'INCIDENCIA' => 'Incidencia'] as $value => $label)
                                                                        <option value="{{ $value }}" @selected(data_get($row, 'estado') === $value)>{{ $label }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                            <label class="wide">
                                                                <span>Observación / indicaciones</span>
                                                                <textarea name="comentario_cambio" rows="2" placeholder="Detalle de coordinación, proveedor o datos pendientes">{{ data_get($row, 'comentario_cambio') }}</textarea>
                                                            </label>
                                                        </div>
                                                        <input type="hidden" name="recepcion_estado" value="{{ data_get($row, 'recepcion_estado') ?: 'PENDIENTE' }}">
                                                        <div class="lgt-service-form-actions">
                                                            <button type="button" class="lgt-service-btn lgt-service-btn-ghost" data-toggle-panel="completar-{{ $loop->index }}">Cancelar</button>
                                                            <button type="submit" class="lgt-service-btn lgt-service-btn-primary">Guardar atención</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>

                                        @else
                                            <div class="lgt-service-completado-actions">
                                                <div class="lgt-service-summary">
                                                    <div class="lgt-service-summary-item">
                                                        <span class="lgt-service-summary-label">Origen</span>
                                                        <span class="lgt-service-summary-value">{{ data_get($row, 'origen_label') ?: (data_get($row, 'origen') ?: 'Sin definir') }}</span>
                                                    </div>
                                                    <div class="lgt-service-summary-item">
                                                        <span class="lgt-service-summary-label">Placas asignadas</span>
                                                        <span class="lgt-service-summary-value">{{ data_get($row, 'placas_asignadas') ?: 'Sin asignar' }}</span>
                                                    </div>
                                                    <div class="lgt-service-summary-item">
                                                        <span class="lgt-service-summary-label">Estado</span>
                                                        <span class="lgt-service-summary-value">{{ data_get($row, 'estado_label') }}</span>
                                                    </div>
                                                    <div class="lgt-service-summary-item">
                                                        <span class="lgt-service-summary-label">Recepción</span>
                                                        <span class="lgt-service-summary-value">{{ data_get($row, 'recepcion_estado_label') ?: 'Pendiente' }}</span>
                                                    </div>
                                                </div>

                                                <div class="lgt-service-actions-group">
                                                    <button type="button" class="lgt-service-btn lgt-service-btn-primary" data-toggle-panel="entrega-{{ $loop->index }}">
                                                        📦 Registrar entrega
                                                    </button>
                                                    @if($puedeHacerCambios)
                                                    <button type="button" class="lgt-service-btn lgt-service-btn-outline" data-toggle-panel="cambio-{{ $loop->index }}">
                                                        ✏️ Hacer cambios
                                                    </button>
                                                    @endif
                                                </div>

                                                {{-- Panel registrar entrega --}}
                                                <div class="lgt-service-form-panel" id="panel-entrega-{{ $loop->index }}" hidden>
                                                    <form method="POST" action="{{ route('logistica.transportes.update', $row['id']) }}" class="lgt-service-form-inline" enctype="multipart/form-data">
                                                        @csrf
                                                        @method('PUT')
                                                        <div class="lgt-service-form-grid">
                                                            <div class="lgt-service-form-divider wide">
                                                                <span>Datos del vehículo</span>
                                                            </div>

                                                            <label>
                                                                <span>Tipo de vehículo</span>
                                                                <select name="origen">
                                                                    <option value="">Seleccionar</option>
                                                                    <option value="EMPRESA" @selected(data_get($row, 'origen') === 'EMPRESA')>🚛 De la empresa</option>
                                                                    <option value="ALQUILADO" @selected(data_get($row, 'origen') === 'ALQUILADO')>📋 Alquilado</option>
                                                                    <option value="OTRO" @selected(data_get($row, 'origen') === 'OTRO')>➕ Otro</option>
                                                                </select>
                                                            </label>
                                                            <label>
                                                                <span>Placa del camión</span>
                                                                <input type="text" name="placas_asignadas" value="{{ data_get($row, 'placas_asignadas') }}" placeholder="ABC-123">
                                                            </label>
                                                            <label>
                                                                <span>Capacidad del camión</span>
                                                                <input type="text" name="capacidad_camion" value="{{ data_get($row, 'capacidad_camion') }}" placeholder="Ej: 10 toneladas">
                                                            </label>

                                                            <div class="lgt-service-form-divider wide">
                                                                <span>Documentación</span>
                                                            </div>

                                                            {{-- Documentos existentes --}}
                                                            @php
                                                                $existingDocs = data_get($row, 'documentos', []);
                                                                $legacyDocs = [
                                                                    ['label' => 'Documentación del vehículo', 'field' => 'doc_vehiculo_path', 'name' => 'doc_vehiculo'],
                                                                    ['label' => 'Documentación interna Proserge', 'field' => 'doc_proserge_path', 'name' => 'doc_proserge'],
                                                                    ['label' => 'Plan de mantenimiento', 'field' => 'doc_mantenimiento_path', 'name' => 'doc_mantenimiento'],
                                                                    ['label' => 'Checklist de enseres', 'field' => 'doc_checklist_path', 'name' => 'doc_checklist'],
                                                                ];
                                                            @endphp

                                                            {{-- Legacy docs (backward compat) --}}
                                                            @foreach($legacyDocs as $legacy)
                                                                @if(data_get($row, $legacy['field']))
                                                                    <div class="lgt-file-info">
                                                                        <span class="lgt-file-info-label">{{ $legacy['label'] }}</span>
                                                                        <span class="lgt-file-loaded">✓ Documento cargado</span>
                                                                    </div>
                                                                @endif
                                                            @endforeach

                                                            {{-- Documentos dinámicos existentes --}}
                                                            @foreach($existingDocs as $doc)
                                                                <div class="lgt-file-info">
                                                                    <span class="lgt-file-info-label">{{ $doc['nombre'] ?? 'Documento' }}</span>
                                                                    <span class="lgt-file-loaded">✓ {{ $doc['path'] ? basename($doc['path']) : 'Documento cargado' }}</span>
                                                                </div>
                                                            @endforeach

                                                            {{-- Documentos legacy fields (mantener compatibilidad) --}}
                                                            <label class="lgt-file-upload-label">
                                                                <span>Documentación del vehículo (reemplazar)</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_vehiculo[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input" multiple data-lgt-multi-file>
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                            </label>
                                                            <label class="lgt-file-upload-label">
                                                                <span>Documentación interna Proserge (reemplazar)</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_proserge[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input" multiple data-lgt-multi-file>
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                            </label>
                                                            <label class="lgt-file-upload-label">
                                                                <span>Plan de mantenimiento (reemplazar)</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_mantenimiento[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input" multiple data-lgt-multi-file>
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                            </label>
                                                            <label class="lgt-file-upload-label">
                                                                <span>Checklist de enseres (reemplazar)</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_checklist[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input" multiple data-lgt-multi-file>
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                            </label>

                                                            {{-- Documentos dinámicos -- múltiples archivos --}}
                                                            <div class="lgt-service-form-divider wide">
                                                                <span>Agregar documentos adicionales</span>
                                                            </div>

                                                            <div class="lgt-docs-container wide" data-docs-container>
                                                                <div class="lgt-doc-row">
                                                                    <label class="lgt-doc-name">
                                                                        <span>Nombre del documento</span>
                                                                        <input type="text" name="documentos[0][nombre]" placeholder="Ej. SOAT, Seguro, Tarjeta prop...">
                                                                    </label>
                                                                    <label class="lgt-doc-file">
                                                                        <span>Archivo</span>
                                                                        <div class="lgt-file-upload-wrap">
                                                                            <input type="file" name="documentos[0][archivo]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input">
                                                                            <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <button type="button" class="lgt-service-btn lgt-service-btn-sm lgt-service-btn-ghost lgt-docs-add" data-docs-add>
                                                                + Agregar otro documento
                                                            </button>
                                                        </div>
                                                        <input type="hidden" name="estado_logistico" value="{{ data_get($row, 'estado') ?: 'REQUERIDO' }}">
                                                        <input type="hidden" name="recepcion_estado" value="{{ data_get($row, 'recepcion_estado') ?: 'PENDIENTE' }}">
                                                        <div class="lgt-service-form-actions">
                                                            <button type="button" class="lgt-service-btn lgt-service-btn-ghost" data-toggle-panel="entrega-{{ $loop->index }}">Cancelar</button>
                                                            <button type="submit" class="lgt-service-btn lgt-service-btn-primary">Guardar entrega</button>
                                                        </div>
                                                    </form>
                                                </div>

                                                {{-- Panel hacer cambios --}}
                                                <div class="lgt-service-form-panel" id="panel-cambio-{{ $loop->index }}" hidden>
                                                    <form method="POST" action="{{ route('logistica.transportes.update', $row['id']) }}" class="lgt-service-form-inline">
                                                        @csrf
                                                        @method('PUT')
                                                        <div class="lgt-service-form-grid">
                                                            <div class="lgt-motivo-cambio">
                                                                <label>
                                                                    <span>Motivo del cambio <strong class="lgt-required">*</strong></span>
                                                                    <textarea name="comentario_cambio" rows="2" placeholder="Indica el motivo del cambio" required>{{ data_get($row, 'comentario_cambio') }}</textarea>
                                                                </label>
                                                            </div>
                                                            <label>
                                                                <span>Origen</span>
                                                                <select name="origen">
                                                                    <option value="">Sin definir</option>
                                                                    <option value="EMPRESA" @selected(data_get($row, 'origen') === 'EMPRESA')>Empresa</option>
                                                                    <option value="ALQUILADO" @selected(data_get($row, 'origen') === 'ALQUILADO')>Alquilado</option>
                                                                    <option value="OTRO" @selected(data_get($row, 'origen') === 'OTRO')>Otro</option>
                                                                </select>
                                                            </label>
                                                            <label class="wide">
                                                                <span>Placa o datos del transporte asignado</span>
                                                                <input type="text" name="placas_asignadas" value="{{ data_get($row, 'placas_asignadas') }}" placeholder="ABC-123; proveedor; chofer; teléfono">
                                                            </label>
                                                            <label>
                                                                <span>Fecha inicio</span>
                                                                <input type="date" name="fecha_inicio" value="{{ data_get($row, 'fecha_inicio') }}">
                                                            </label>
                                                            <label>
                                                                <span>Fecha fin</span>
                                                                <input type="date" name="fecha_fin" value="{{ data_get($row, 'fecha_fin') }}">
                                                            </label>
                                                            <label>
                                                                <span>Estado logístico</span>
                                                                <select name="estado_logistico">
                                                                    @foreach(['REQUERIDO' => 'Requerido', 'ASIGNADO' => 'Asignado', 'EN_USO' => 'En uso', 'RETIRADO' => 'Retirado', 'REEMPLAZADO' => 'Reemplazado', 'DEVUELTO' => 'Devuelto', 'INCIDENCIA' => 'Incidencia'] as $value => $label)
                                                                        <option value="{{ $value }}" @selected(data_get($row, 'estado') === $value)>{{ $label }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                            <label class="wide">
                                                                <span>Incidencia operativa</span>
                                                                <textarea name="incidencia_operativa" rows="2" placeholder="Malogro, reemplazo, retraso, retiro o devolución">{{ data_get($row, 'incidencia_operativa') }}</textarea>
                                                            </label>
                                                        </div>
                                                        <input type="hidden" name="recepcion_estado" value="{{ data_get($row, 'recepcion_estado') ?: 'PENDIENTE' }}">
                                                        <div class="lgt-service-form-actions">
                                                            <button type="button" class="lgt-service-btn lgt-service-btn-ghost" data-toggle-panel="cambio-{{ $loop->index }}">Cancelar</button>
                                                            <button type="submit" class="lgt-service-btn lgt-service-btn-primary">Guardar cambios</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var serviciosPanel = document.querySelector('[data-logistics-tab-panel="servicios"]');
    if (!serviciosPanel) return;
    var selectedFilesByInput = new WeakMap();

    // Toggle panels (completar, entrega, cambios)
    serviciosPanel.addEventListener('click', function(e) {
        var toggle = e.target.closest('[data-toggle-panel]');
        if (toggle) {
            e.preventDefault();
            var panelId = toggle.getAttribute('data-toggle-panel');
            var target = serviciosPanel.querySelector('#panel-' + panelId);
            if (target) {
                target.hidden = target.hasAttribute('hidden') ? false : true;
            }
            return;
        }

        // Documentos dinámicos: agregar fila
        var addBtn = e.target.closest('[data-docs-add]');
        if (addBtn) {
            e.preventDefault();
            var form = addBtn.closest('form');
            var container = form ? form.querySelector('[data-docs-container]') : null;
            if (!container) return;
            var rows = container.querySelectorAll('.lgt-doc-row');
            var index = rows.length;
            var template = rows[0].cloneNode(true);
            template.querySelectorAll('input').forEach(function(field) {
                var name = field.getAttribute('name');
                if (name) {
                    field.setAttribute('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                    field.value = '';
                }
            });
            container.appendChild(template);
            return;
        }

        var removeFileBtn = e.target.closest('[data-lgt-remove-file]');
        if (removeFileBtn) {
            e.preventDefault();
            var list = removeFileBtn.closest('[data-lgt-file-list]');
            var input = list ? list.previousElementSibling && list.previousElementSibling.querySelector('[data-lgt-multi-file]') : null;
            var removeIndex = Number(removeFileBtn.getAttribute('data-lgt-remove-file'));
            if (!input || Number.isNaN(removeIndex) || !window.DataTransfer) return;

            var remainingFiles = selectedFiles(input).filter(function(file, index) {
                return index !== removeIndex;
            });
            setSelectedFiles(input, remainingFiles);
            renderSelectedFiles(input);
        }
    });

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
        });
    }

    function fileSizeLabel(bytes) {
        var size = Number(bytes || 0);
        if (size >= 1024 * 1024) {
            return (size / (1024 * 1024)).toFixed(1) + ' MB';
        }
        if (size >= 1024) {
            return Math.round(size / 1024) + ' KB';
        }
        return size + ' B';
    }

    function fileListFor(input) {
        var wrap = input.closest('.lgt-file-upload-wrap');
        var list = wrap ? wrap.nextElementSibling : null;
        if (list && list.hasAttribute('data-lgt-file-list')) {
            return list;
        }

        list = document.createElement('div');
        list.className = 'lgt-selected-file-list';
        list.setAttribute('data-lgt-file-list', '1');
        list.setAttribute('aria-live', 'polite');
        if (wrap) {
            wrap.insertAdjacentElement('afterend', list);
        }
        return list;
    }

    function fileKey(file) {
        return [file.name, file.size, file.lastModified].join('|');
    }

    function selectedFiles(input) {
        return selectedFilesByInput.get(input) || Array.prototype.slice.call(input.files || []);
    }

    function setSelectedFiles(input, files) {
        selectedFilesByInput.set(input, files);

        if (!window.DataTransfer) {
            return;
        }

        var transfer = new DataTransfer();
        files.forEach(function(file) {
            transfer.items.add(file);
        });
        input.files = transfer.files;
    }

    function appendSelectedFiles(input, files) {
        var next = selectedFiles(input).slice();
        var existing = {};

        next.forEach(function(file) {
            existing[fileKey(file)] = true;
        });

        files.forEach(function(file) {
            var key = fileKey(file);
            if (!existing[key]) {
                next.push(file);
                existing[key] = true;
            }
        });

        setSelectedFiles(input, next);
    }

    function renderSelectedFiles(input) {
        var list = fileListFor(input);
        var files = selectedFiles(input);

        if (!files.length) {
            list.innerHTML = '';
            list.hidden = true;
            return;
        }

        list.hidden = false;
        list.innerHTML = files.map(function(file, index) {
            return '<div class="lgt-selected-file-item">' +
                '<span><strong>' + escapeHtml(file.name) + '</strong><small>' + escapeHtml(fileSizeLabel(file.size)) + '</small></span>' +
                '<button type="button" data-lgt-remove-file="' + index + '">Quitar</button>' +
            '</div>';
        }).join('');
    }

    serviciosPanel.addEventListener('change', function(e) {
        var input = e.target.closest('[data-lgt-multi-file]');
        if (input) {
            appendSelectedFiles(input, Array.prototype.slice.call(input.files || []));
            renderSelectedFiles(input);
        }
    });

    serviciosPanel.addEventListener('click', function(e) {
        var input = e.target.closest('[data-lgt-multi-file]');
        if (input) {
            selectedFilesByInput.set(input, selectedFiles(input));
            input.value = '';
        }
    });

    // Parada search
    var searchInput = document.getElementById('lgtParadaSearch');
    var minaFilter = document.getElementById('lgtParadaMinaFilter');
    var paradaList = document.getElementById('lgtParadaList');
    var serviceGrid = document.getElementById('lgtServiceGrid');
    var serviceEmptyPrompt = document.getElementById('lgtServiceEmptyPrompt');
    var serviceEmptyNoRows = document.getElementById('lgtServiceEmptyNoRows');

    if (searchInput && paradaList) {
        function selectParada(paradaId, updateUrl) {
            var selectedId = String(paradaId || '');
            var cards = paradaList.querySelectorAll('.lgt-parada-card');
            var visibleRows = 0;

            cards.forEach(function(card) {
                var isSelected = card.getAttribute('data-parada-id') === selectedId;
                card.classList.toggle('is-selected', isSelected);
                card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            });

            if (serviceGrid) {
                serviceGrid.querySelectorAll('.lgt-service-card').forEach(function(row) {
                    var isVisible = row.getAttribute('data-rq-mina-id') === selectedId;
                    row.hidden = !isVisible;
                    if (isVisible) {
                        visibleRows++;
                    }
                });
                serviceGrid.hidden = visibleRows === 0;
            }

            if (serviceEmptyPrompt) {
                serviceEmptyPrompt.hidden = selectedId !== '';
            }

            if (serviceEmptyNoRows) {
                serviceEmptyNoRows.hidden = selectedId === '' || visibleRows > 0;
            }

            if (updateUrl && window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', 'servicios');
                if (selectedId) {
                    url.searchParams.set('servicio_parada_id', selectedId);
                } else {
                    url.searchParams.delete('servicio_parada_id');
                }
                window.history.replaceState({}, '', url.toString());
            }
        }

        paradaList.addEventListener('click', function(e) {
            var card = e.target.closest('.lgt-parada-card');
            if (!card || !paradaList.contains(card)) {
                return;
            }

            e.preventDefault();
            selectParada(card.getAttribute('data-parada-id'), true);
        });

        function filterParadas() {
            var searchVal = searchInput.value.toLowerCase().trim();
            var minaVal = minaFilter ? minaFilter.value : '';
            var cards = paradaList.querySelectorAll('.lgt-parada-card');
            var visibleCount = 0;

            cards.forEach(function(card) {
                var searchText = card.getAttribute('data-parada-search') || '';
                var cardMina = card.getAttribute('data-parada-mina') || '';
                var matchesSearch = searchVal === '' || searchText.indexOf(searchVal) !== -1;
                var matchesMina = minaVal === '' || cardMina.indexOf(minaVal) !== -1;
                var visible = matchesSearch && matchesMina;
                card.style.display = visible ? '' : 'none';
                if (visible) visibleCount++;
            });

            var emptyMsg = paradaList.querySelector('.lgt-parada-search-empty');
            if (visibleCount === 0) {
                if (!emptyMsg) {
                    emptyMsg = document.createElement('div');
                    emptyMsg.className = 'lgt-parada-empty lgt-parada-search-empty';
                    emptyMsg.textContent = 'No se encontraron paradas con ese filtro.';
                    paradaList.appendChild(emptyMsg);
                }
                emptyMsg.style.display = '';
            } else if (emptyMsg) {
                emptyMsg.style.display = 'none';
            }
        }

        searchInput.addEventListener('input', filterParadas);
        if (minaFilter) {
            minaFilter.addEventListener('change', filterParadas);
        }
    }
});
</script>

@push('styles')
<style>
.lgt-parada-card {
    width: 100%;
    min-height: 74px;
    padding: 12px 44px 12px 14px;
    text-align: left;
    cursor: pointer;
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
}

.lgt-parada-card.is-selected {
    box-shadow: inset 0 0 0 1px #0d9488, 0 2px 8px rgba(13, 148, 136, .12);
}

.lgt-parada-card-check {
    top: 8px;
    right: 8px;
    display: none;
}

.lgt-parada-card.is-selected .lgt-parada-card-check {
    display: flex;
}

.lgt-selected-file-list {
    display: grid;
    gap: 6px;
    margin-top: 7px;
}

.lgt-selected-file-list[hidden] {
    display: none;
}

.lgt-selected-file-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 7px 9px;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    background: #eff6ff;
    color: #1e3a8a;
    font-size: 12px;
}

.lgt-selected-file-item span {
    display: grid;
    gap: 2px;
    min-width: 0;
}

.lgt-selected-file-item strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.lgt-selected-file-item small {
    color: #64748b;
}

.lgt-selected-file-item button {
    flex: 0 0 auto;
    border: 0;
    background: transparent;
    color: #b91c1c;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}
</style>
@endpush

            </section>
            @endif

            @if($canViewTabs['identificacion'] ?? false)
            <section
                class="lgt-tab-panel {{ $activeTab === 'identificacion' ? 'is-active' : '' }}"
                data-logistics-tab-panel="identificacion"
                aria-hidden="{{ $activeTab === 'identificacion' ? 'false' : 'true' }}"
                @if(old('catalog_edit_id'))
                    data-ident-open-modal="identEditModal{{ old('catalog_edit_id') }}"
                @elseif($errors->any() && old('nombre'))
                    data-ident-open-modal="identCatalogModal"
                @endif
            >
            @php
                $canConfigureEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'configurar');
                $canEditEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'editar');
                $canDeleteEpps = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'epps', 'eliminar');
                            $identCategoria = strtoupper(trim((string) request()->query('ident_categoria', 'EPP')));
                if (!in_array($identCategoria, ['EPP', 'HERRAMIENTA', 'CONSUMIBLE'], true)) {
                    $identCategoria = 'EPP';
                }
                $categoriaUrl = function (string $cat) use ($tabUrl): string {
                    $query = request()->except(['tab', 'ident_categoria', 'page']);
                    $query['tab'] = 'identificacion';
                    $query['ident_categoria'] = $cat;
                    return url('/logistica') . '?' . http_build_query($query);
                };
                $catalogStoreRoute = $identCategoria === 'EPP'
                    ? route('epps.catalogo.store')
                    : route('logistica.catalogo-parada.store');
@endphp
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <div>
                        <h2>Identificación de ítems</h2>
                        <p>Catálogo base de códigos, vida útil, tallas, colores y atributos personalizados.</p>
                    </div>

                <div class="lgt-ident-category-tabs" role="tablist" aria-label="Categorias del catalogo">
                    <a href="{{ $categoriaUrl('EPP') }}" class="lgt-ident-category-tab {{ $identCategoria === 'EPP' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $identCategoria === 'EPP' ? 'true' : 'false' }}">
                        <span class="lgt-ident-category-icon">E</span>
                        <span class="lgt-ident-category-text"><strong>EPP</strong><small>Proteccion personal</small></span>
                    </a>
                    <a href="{{ $categoriaUrl('HERRAMIENTA') }}" class="lgt-ident-category-tab {{ $identCategoria === 'HERRAMIENTA' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $identCategoria === 'HERRAMIENTA' ? 'true' : 'false' }}">
                        <span class="lgt-ident-category-icon">H</span>
                        <span class="lgt-ident-category-text"><strong>Herramientas</strong><small>Equipos y utiles</small></span>
                    </a>
                    <a href="{{ $categoriaUrl('CONSUMIBLE') }}" class="lgt-ident-category-tab {{ $identCategoria === 'CONSUMIBLE' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $identCategoria === 'CONSUMIBLE' ? 'true' : 'false' }}">
                        <span class="lgt-ident-category-icon">C</span>
                        <span class="lgt-ident-category-text"><strong>Consumibles</strong><small>Material de uso</small></span>
                    </a>
                </div>

                    @if($canConfigureEpps)
                        <div class="lgt-ident-header-actions">
                            <form method="POST" action="{{ route('logistica.catalogo-parada.importar') }}" enctype="multipart/form-data" class="lgt-ident-import-form">
                                @csrf
                                <input type="hidden" name="tab" value="identificacion">
                                <input type="hidden" name="ident_categoria" value="{{ $identCategoria === 'EPP' ? 'HERRAMIENTA' : $identCategoria }}">
                                <label class="epp-btn epp-btn-light epp-btn-main lgt-ident-import-btn">
                                    <span aria-hidden="true">+</span>
                                    Subir Excel
                                    <input type="file" name="archivo" accept=".xlsx,.xls,.xlsm" data-ident-import-input hidden>
                                </label>
                            </form>
                            <button type="button" class="epp-btn epp-btn-primary epp-btn-main" data-epp-open-modal="identCatalogModal" aria-controls="identCatalogModal">
                                <span aria-hidden="true">+</span>
                                Agregar item
                            </button>
                        </div>
                    @endif
                </div>

                <div class="lgt-card-body">
                    @if($identityRows->isEmpty())
                        <div class="lgt-empty">No hay ítems registrados en el catálogo.</div>
                    @else
                        <div class="epp-table-wrap">
                            <table class="epp-table">
                                <thead>
                                    <tr>
                                        <th>Ítem</th>
                                        <th>Código</th>

                                        <th>Categoría</th><th>Vida útil</th>
                                        <th>Tallas</th>
                                        <th>Colores</th>
                                        <th>Otros atributos</th>
                                        <th>Estado</th>
                                        @if($canEditEpps || $canDeleteEpps)
                                            <th>Acción</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($identityRows as $row)
                                        @php
                                            $itemId = $resolve($row, ['id']);
                                            $itemNombre = $resolve($row, ['nombre', 'item']);
                                            $itemCodigo = $resolve($row, ['codigo'], 'Sin código');
                                            $itemVida = $resolve($row, ['vida_util'], '0');
                                            $itemTalla = $resolve($row, ['talla_label'], 'No requiere');
                                            $itemColor = $resolve($row, ['color_label'], 'No requiere');
                                            $itemEstado = $resolve($row, ['estado'], 'Sin estado');
                                            $itemOtros = $row['otros_atributos'] ?? [];
                                            $itemReadonly = (bool) ($row['readonly'] ?? false);
                                            $itemSource = (string) ($row['fuente'] ?? 'ITEM');
                                            $isToolCatalog = $itemSource === 'CATALOGO_PARADA';
                                            $toolCatalogId = (string) ($row['catalogo_id'] ?? '');
                                            $toolUnidad = (string) ($row['unidad'] ?? '');
                                            $editingThis = old('catalog_edit_id') === (string) $itemId;
                                            $itemEditNombre = $editingThis ? old('nombre', $itemNombre) : $itemNombre;
                                            $itemEditVida = $editingThis ? old('vida_util_dias', $itemVida) : $itemVida;
                                            $itemEditEstado = $editingThis ? old('estado', $itemEstado) : $itemEstado;
                                            $itemEditCategoria = $editingThis ? old('categoria', $row['categoria'] ?? 'EPP') : ($row['categoria'] ?? 'EPP');
                                            $itemEditRequiereTalla = $editingThis ? old('requiere_talla') === '1' : !empty($row['tallas']);
                                            $itemEditTallas = $editingThis ? old('tallas') : (is_array($row['tallas'] ?? null) ? implode(', ', $row['tallas']) : '');
                                            $itemEditRequiereColor = $editingThis ? old('requiere_color') === '1' : !empty($row['colores']);
                                            $itemEditColores = $editingThis ? old('colores') : (is_array($row['colores'] ?? null) ? implode(', ', $row['colores']) : '');
                                            $itemEditOtros = $editingThis && is_array(old('otros_atributos')) ? old('otros_atributos') : $itemOtros;
                                            $itemEditRequiereOtros = !empty($itemEditOtros);
                                        @endphp
                                        <tr>
                                            <td><strong>{{ $itemNombre }}</strong></td>
                                            <td><code>{{ $itemCodigo }}</code></td>

                                            <td><span class="epp-category-pill">{{ $resolve($row, ['categoria'], 'EPP') }}</span></td><td>{{ $itemVida }} días</td>
                                            <td>
                                                @if(!empty($row['tallas']))
                                                    <span class="epp-rule-pill">Si requiere</span>
                                                    <small>{{ $itemTalla }}</small>
                                                @else
                                                    <span class="epp-muted">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if(!empty($row['colores']))
                                                    <span class="epp-rule-pill">Si requiere</span>
                                                    <small>{{ $itemColor }}</small>
                                                @else
                                                    <span class="epp-muted">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if(!empty($itemOtros))
                                                    @foreach($itemOtros as $otro)
                                                        <div class="lgt-attr-tag">
                                                            <strong>{{ $otro['nombre'] ?? '' }}:</strong>
                                                            <span>{{ is_array($otro['valores'] ?? []) ? implode(', ', $otro['valores']) : ($otro['valores'] ?? '') }}</span>
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <span class="epp-muted">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="epp-status epp-status-{{ strtolower($itemEstado) }}">{{ ucwords(strtolower($itemEstado)) }}</span>
                                            </td>
                                        @if($canEditEpps || $canDeleteEpps)
                                            <td class="epp-actions-cell">
                                                @if($itemReadonly)
                                                    <div class="epp-row-actions">
                                                        <span class="epp-muted">Catalogo de parada</span>

                                                        @if($isToolCatalog && $canEditEpps)
                                                            <button type="button" class="epp-btn epp-btn-sm epp-btn-action" data-epp-open-modal="identToolEditModal{{ $toolCatalogId }}" aria-controls="identToolEditModal{{ $toolCatalogId }}">
                                                                Editar
                                                            </button>

                                                            <div id="identToolEditModal{{ $toolCatalogId }}" class="epp-modal" hidden>
                                                                <div class="epp-modal-card" role="dialog" aria-modal="true" aria-labelledby="identToolEditTitle{{ $toolCatalogId }}">
                                                                    <div class="epp-modal-header">
                                                                        <div>
                                                                            <h2 id="identToolEditTitle{{ $toolCatalogId }}">Editar item</h2>
                                                                            <p>{{ $itemNombre }}</p>
                                                                        </div>
                                                                        <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                                    </div>
                                                                    <div class="epp-modal-body">
                                                                        <form method="POST" action="{{ route('logistica.catalogo-parada.update', $toolCatalogId) }}" class="epp-form-grid">
                                                                            @csrf
                                                                            @method('PUT')
                                                                            <input type="hidden" name="tab" value="identificacion">
                                                                            <input type="hidden" name="ident_categoria" value="{{ $identCategoria }}">
                                                                            <input type="hidden" name="catalog_edit_id" value="{{ $itemId }}">

                                                                            <label class="epp-field-wide">
                                                                                Nombre del item
                                                                                <input type="text" name="nombre" value="{{ $editingThis ? old('nombre', $itemNombre) : $itemNombre }}" required data-epp-code-source>
                                                                                <span class="epp-code-preview">Codigo generado: <strong data-epp-code-preview>--</strong></span>
                                                                            </label>

                                                                            <label>
                                                                                Unidad
                                                                                <input type="text" name="unidad" value="{{ $editingThis ? old('unidad', $toolUnidad) : $toolUnidad }}" placeholder="UND, PAR, KG...">
                                                                            </label>

                                                                            <label>
                                                                                Estado
                                                                                <select name="estado">
                                                                                    <option value="ACTIVO" @selected(($editingThis ? old('estado', 'ACTIVO') : 'ACTIVO') === 'ACTIVO')>Activo</option>
                                                                                    <option value="INACTIVO" @selected(($editingThis ? old('estado') : '') === 'INACTIVO')>Inactivo</option>
                                                                                </select>
                                                                            </label>

                                                                            <label>
                                                                                Categoria
                                                                                <select name="categoria">
                                                                                    <option value="HERRAMIENTA" @selected(($editingThis ? old('categoria', $identCategoria) : $identCategoria) === 'HERRAMIENTA')>Herramienta</option>
                                                                                    <option value="CONSUMIBLE" @selected(($editingThis ? old('categoria', $identCategoria) : $identCategoria) === 'CONSUMIBLE')>Consumible</option>
                                                                                </select>
                                                                            </label>

                                                                            <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar cambios</button>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif

                                                        @if($isToolCatalog && $canDeleteEpps)
                                                            <button type="button" class="epp-btn epp-btn-sm epp-btn-danger-action" data-epp-open-modal="identToolDeleteModal{{ $toolCatalogId }}" aria-controls="identToolDeleteModal{{ $toolCatalogId }}">
                                                                Eliminar
                                                            </button>

                                                            <div id="identToolDeleteModal{{ $toolCatalogId }}" class="epp-modal" hidden>
                                                                <div class="epp-modal-card epp-modal-card-compact" role="dialog" aria-modal="true" aria-labelledby="identToolDeleteTitle{{ $toolCatalogId }}">
                                                                    <div class="epp-modal-header">
                                                                        <div>
                                                                            <h2 id="identToolDeleteTitle{{ $toolCatalogId }}" class="epp-delete-title">Eliminar item</h2>
                                                                            <p>El item quedara inactivo y conservara sus referencias historicas.</p>
                                                                        </div>
                                                                        <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                                    </div>
                                                                    <div class="epp-modal-body">
                                                                        <div class="epp-delete-warning">
                                                                            <span class="epp-delete-icon">!</span>
                                                                            <div>
                                                                                <strong>Eliminar "{{ $itemNombre }}"?</strong>
                                                                                <p class="epp-delete-hint">Se enviara al basurero del catalogo de parada marcandolo como inactivo.</p>
                                                                            </div>
                                                                        </div>
                                                                        <form method="POST" action="{{ route('logistica.catalogo-parada.destroy', $toolCatalogId) }}" class="epp-delete-form">
                                                                            @csrf
                                                                            <input type="hidden" name="tab" value="identificacion">
                                                                            <input type="hidden" name="ident_categoria" value="{{ $identCategoria }}">
                                                                            <input type="hidden" name="categoria" value="{{ $identCategoria }}">
                                                                            <div class="epp-delete-actions">
                                                                                <button type="button" class="epp-btn epp-btn-light" data-epp-close-modal>Cancelar</button>
                                                                                <button type="submit" class="epp-btn epp-btn-danger">Enviar al basurero</button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <div class="epp-row-actions">
                                                        @if($canEditEpps)
                                                            <button type="button" class="epp-btn epp-btn-sm epp-btn-action" data-epp-open-modal="identEditModal{{ $itemId }}" aria-controls="identEditModal{{ $itemId }}">
                                                                Editar
                                                            </button>

                                                            {{-- Modal editar ítem --}}
                                                            <div id="identEditModal{{ $itemId }}" class="epp-modal" hidden>
                                                                <div class="epp-modal-card" role="dialog" aria-modal="true" aria-labelledby="identEditTitle{{ $itemId }}">
                                                                    <div class="epp-modal-header">
                                                                        <div>
                                                                            <h2 id="identEditTitle{{ $itemId }}">Editar ítem</h2>
                                                                            <p>{{ $itemNombre }}</p>
                                                                        </div>
                                                                        <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                                    </div>
                                                                    <div class="epp-modal-body">
                                                                        <form method="POST" action="{{ route('epps.catalogo.update', $itemId) }}" class="epp-form-grid">
                                                                            @csrf
                                                                            @method('PUT')
                                                                            <input type="hidden" name="tab" value="identificacion">
                                                                            <input type="hidden" name="ident_categoria" value="{{ $identCategoria }}">
                                                                            <input type="hidden" name="catalog_edit_id" value="{{ $itemId }}">

                                                                            <label class="epp-field-wide">
                                                                                Nombre del ítem
                                                                                <input type="text" name="nombre" value="{{ $itemEditNombre }}" required data-epp-code-source>
                                                                                <span class="epp-code-preview">Código generado: <strong data-epp-code-preview>--</strong></span>
                                                                            </label>

                                                                            <label>
                                                                                Vida útil (días)
                                                                                <input type="number" name="vida_util_dias" min="1" value="{{ $itemEditVida }}" required>
                                                                            </label>

                                                                            <label>
                                                                                Estado
                                                                                <select name="estado">
                                                                                    <option value="ACTIVO" @selected($itemEditEstado === 'ACTIVO')>Activo</option>
                                                                                    <option value="INACTIVO" @selected($itemEditEstado === 'INACTIVO')>Inactivo</option>
                                                                                </select>
                                                                            </label>

                                                                            <label>
                                                                                Categoría
                                                                                <select name="categoria">
                                                                                    <option value="EPP" @selected($itemEditCategoria === 'EPP')>EPP</option>
                                                                                    <option value="HERRAMIENTA" @selected($itemEditCategoria === 'HERRAMIENTA')>Herramienta</option>
                                                                                    <option value="CONSUMIBLE" @selected($itemEditCategoria === 'CONSUMIBLE')>Consumible</option>
                                                                                </select>
                                                                            </label>


                                                                            <label class="epp-option-toggle">
                                                                                <input type="hidden" name="requiere_talla" value="0">
                                                                                <input type="checkbox" name="requiere_talla" value="1" @checked($itemEditRequiereTalla) data-epp-toggle-target="identEditTallas{{ $itemId }}">
                                                                                <span>Requiere talla</span>
                                                                            </label>
                                                                            <label id="identEditTallas{{ $itemId }}" class="epp-field-wide epp-option-list" @if(!$itemEditRequiereTalla) hidden @endif>
                                                                                Tallas disponibles
                                                                                <textarea name="tallas" rows="2" placeholder="Ej. S, M, L, XL o 38, 40, 42">{{ $itemEditTallas }}</textarea>
                                                                            </label>

                                                                            <label class="epp-option-toggle">
                                                                                <input type="hidden" name="requiere_color" value="0">
                                                                                <input type="checkbox" name="requiere_color" value="1" @checked($itemEditRequiereColor) data-epp-toggle-target="identEditColores{{ $itemId }}">
                                                                                <span>Requiere color</span>
                                                                            </label>
                                                                            <label id="identEditColores{{ $itemId }}" class="epp-field-wide epp-option-list" @if(!$itemEditRequiereColor) hidden @endif>
                                                                                Colores disponibles
                                                                                <textarea name="colores" rows="2" placeholder="Ej. Blanco, azul, negro">{{ $itemEditColores }}</textarea>
                                                                            </label>

                                                                            {{-- Otros atributos --}}
                                                                            <div class="epp-field-wide epp-otros-section">
                                                                                <label class="epp-option-toggle">
                                                                                    <input type="checkbox" class="epp-otros-toggle" @checked($itemEditRequiereOtros) data-epp-toggle-target="identEditOtros{{ $itemId }}">
                                                                                    <span>Requiere otros atributos</span>
                                                                                </label>
                                                                                <div id="identEditOtros{{ $itemId }}" class="epp-otros-list" @if(!$itemEditRequiereOtros) hidden @endif>
                                                                                    @if(!empty($itemEditOtros))
                                                                                        @foreach($itemEditOtros as $otroIndex => $otroAttr)
                                                                                            <div class="epp-otros-item">
                                                                                                <label>
                                                                                                    <span>Nombre del atributo</span>
                                                                                                    <input type="text" name="otros_atributos[{{ $otroIndex }}][nombre]" value="{{ $otroAttr['nombre'] ?? '' }}" placeholder="Ej. Material, Tipo, Marca">
                                                                                                </label>
                                                                                                <label>
                                                                                                    <span>Valores (separados por coma)</span>
                                                                                                    <textarea name="otros_atributos[{{ $otroIndex }}][valores]" rows="2" placeholder="Ej. Poliester, Algodon, Nylon">{{ is_array($otroAttr['valores'] ?? null) ? implode(', ', $otroAttr['valores']) : ($otroAttr['valores'] ?? '') }}</textarea>
                                                                                                </label>
                                                                                            </div>
                                                                                        @endforeach
                                                                                    @else
                                                                                        <div class="epp-otros-item">
                                                                                            <label>
                                                                                                <span>Nombre del atributo</span>
                                                                                                <input type="text" name="otros_atributos[0][nombre]" placeholder="Ej. Material, Tipo, Marca">
                                                                                            </label>
                                                                                            <label>
                                                                                                <span>Valores (separados por coma)</span>
                                                                                                <textarea name="otros_atributos[0][valores]" rows="2" placeholder="Ej. Poliester, Algodon, Nylon"></textarea>
                                                                                            </label>
                                                                                        </div>
                                                                                    @endif
                                                                                    <button type="button" class="epp-btn epp-btn-sm epp-btn-light epp-otros-add">+ Agregar otro atributo</button>
                                                                                </div>
                                                                            </div>

                                                                            <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar cambios</button>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif

                                                        @if($canDeleteEpps)
                                                            <button type="button" class="epp-btn epp-btn-sm epp-btn-danger-action" data-epp-open-modal="identDeleteModal{{ $itemId }}" aria-controls="identDeleteModal{{ $itemId }}">
                                                                Eliminar
                                                            </button>

                                                            {{-- Modal confirmar eliminación --}}
                                                            <div id="identDeleteModal{{ $itemId }}" class="epp-modal" hidden>
                                                                <div class="epp-modal-card epp-modal-card-compact" role="dialog" aria-modal="true" aria-labelledby="identDeleteTitle{{ $itemId }}">
                                                                    <div class="epp-modal-header">
                                                                        <div>
                                                                            <h2 id="identDeleteTitle{{ $itemId }}" class="epp-delete-title">Eliminar ítem</h2>
                                                                            <p>El item quedara inactivo y conservara sus referencias historicas.</p>
                                                                        </div>
                                                                        <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                                                                    </div>
                                                                    <div class="epp-modal-body">
                                                                        <div class="epp-delete-warning">
                                                                            <span class="epp-delete-icon">⚠️</span>
                                                                            <div>
                                                                                <strong>¿Eliminar «{{ $itemNombre }}»?</strong>
                                                                                <p class="epp-delete-hint">Se enviara al basurero del catalogo marcandolo como inactivo. Las entregas, kardex y referencias existentes se conservaran.</p>
                                                                            </div>
                                                                        </div>
                                                                        <form method="POST" action="{{ route('epps.catalogo.destroy', $itemId) }}" class="epp-delete-form">
                                                                            @csrf
                                                                            <input type="hidden" name="tab" value="identificacion">
                                                                            <input type="hidden" name="ident_categoria" value="{{ $identCategoria }}">
                                                                            <div class="epp-delete-actions">
                                                                                <button type="button" class="epp-btn epp-btn-light" data-epp-close-modal>Cancelar</button>
                                                                                <button type="submit" class="epp-btn epp-btn-danger">Enviar al basurero</button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                        @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Modal agregar ítem --}}
            @if($canConfigureEpps)
                <div id="identCatalogModal" class="epp-modal" hidden>
                    <div class="epp-modal-card" role="dialog" aria-modal="true" aria-labelledby="identCatalogTitle">
                        <div class="epp-modal-header">
                            <div>
                                <h2 id="identCatalogTitle">Agregar ítem al catálogo</h2>
                                <p>Registra el item y define sus atributos operativos.</p>
                            </div>
                            <button type="button" class="epp-modal-close" data-epp-close-modal aria-label="Cerrar">X</button>
                        </div>
                        <div class="epp-modal-body">
                            @if($identCategoria !== 'EPP')
                                <form method="POST" action="{{ route('logistica.catalogo-parada.store') }}" class="epp-form-grid">
                                    @csrf
                                    <input type="hidden" name="tab" value="identificacion">
                                    <input type="hidden" name="ident_categoria" value="{{ $identCategoria }}">
                                    <input type="hidden" name="categoria" value="{{ $identCategoria }}">

                                    <label class="epp-field-wide">
                                        Nombre del item
                                        <input type="text" name="nombre" value="{{ old('nombre') }}" placeholder="{{ $identCategoria === 'HERRAMIENTA' ? 'Llave, dado, adaptador...' : 'Silicona, pernos, cinta...' }}" required data-epp-code-source>
                                        <span class="epp-code-preview">Codigo generado: <strong data-epp-code-preview>--</strong></span>
                                    </label>

                                    <label>
                                        Unidad
                                        <input type="text" name="unidad" value="{{ old('unidad') }}" placeholder="UND, PAR, KG...">
                                    </label>

                                    <label>
                                        Estado
                                        <select name="estado">
                                            <option value="ACTIVO" @selected(old('estado', 'ACTIVO') === 'ACTIVO')>Activo</option>
                                            <option value="INACTIVO" @selected(old('estado') === 'INACTIVO')>Inactivo</option>
                                        </select>
                                    </label>

                                    <label>
                                        Categoria
                                        <input type="text" value="{{ $identCategoria === 'HERRAMIENTA' ? 'Herramienta' : 'Consumible' }}" readonly>
                                    </label>

                                    <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar item</button>
                                </form>
                            @else
                            <form method="POST" action="{{ $catalogStoreRoute }}" class="epp-form-grid">
                                @csrf
                                <input type="hidden" name="tab" value="identificacion">
                                <input type="hidden" name="ident_categoria" value="{{ $identCategoria }}">
                                <label class="epp-field-wide">
                                    Nombre del ítem
                                    <input type="text" name="nombre" value="{{ old('nombre') }}" placeholder="Casco, lentes, guantes..." required data-epp-code-source>
                                    <span class="epp-code-preview">Código generado: <strong data-epp-code-preview>--</strong></span>
                                </label>

                                <label>
                                    Vida útil (días)
                                    <input type="number" name="vida_util_dias" min="1" value="{{ old('vida_util_dias', 30) }}" required>
                                </label>

                                <label>
                                    Estado
                                    <select name="estado">
                                        <option value="ACTIVO" @selected(old('estado', 'ACTIVO') === 'ACTIVO')>Activo</option>
                                        <option value="INACTIVO" @selected(old('estado') === 'INACTIVO')>Inactivo</option>
                                    </select>
                                </label>

                                <label>
                                    Categoría
                                    <select name="categoria">
                                        <option value="EPP" @selected(old('categoria', $identCategoria) === 'EPP')>EPP</option>
                                        <option value="HERRAMIENTA" @selected(old('categoria', $identCategoria) === 'HERRAMIENTA')>Herramienta</option>
                                        <option value="CONSUMIBLE" @selected(old('categoria', $identCategoria) === 'CONSUMIBLE')>Consumible</option>
                                    </select>
                                </label>

                                @if($identCategoria !== 'EPP')
                                    <label>
                                        Unidad
                                        <input type="text" name="unidad" value="{{ old('unidad') }}" placeholder="UND, PAR, KG...">
                                    </label>
                                @endif

                                <label class="epp-option-toggle">
                                    <input type="hidden" name="requiere_talla" value="0">
                                    <input type="checkbox" name="requiere_talla" value="1" @checked(old('requiere_talla')) data-epp-toggle-target="identNewTallasField">
                                    <span>Requiere talla</span>
                                </label>
                                <label id="identNewTallasField" class="epp-field-wide epp-option-list" @if(!old('requiere_talla')) hidden @endif>
                                    Tallas disponibles
                                    <textarea name="tallas" rows="2" placeholder="Ej. S, M, L, XL o 38, 40, 42">{{ old('tallas') }}</textarea>
                                </label>

                                <label class="epp-option-toggle">
                                    <input type="hidden" name="requiere_color" value="0">
                                    <input type="checkbox" name="requiere_color" value="1" @checked(old('requiere_color')) data-epp-toggle-target="identNewColoresField">
                                    <span>Requiere color</span>
                                </label>
                                <label id="identNewColoresField" class="epp-field-wide epp-option-list" @if(!old('requiere_color')) hidden @endif>
                                    Colores disponibles
                                    <textarea name="colores" rows="2" placeholder="Ej. Blanco, azul, negro">{{ old('colores') }}</textarea>
                                </label>

                                {{-- Otros atributos --}}
                                <div class="epp-field-wide epp-otros-section">
                                    <label class="epp-option-toggle">
                                        <input type="checkbox" class="epp-otros-toggle" data-epp-toggle-target="identNewOtrosField">
                                        <span>Requiere otros atributos</span>
                                    </label>
                                    <div id="identNewOtrosField" class="epp-otros-list" hidden>
                                        <div class="epp-otros-item">
                                            <label>
                                                <span>Nombre del atributo</span>
                                                <input type="text" name="otros_atributos[0][nombre]" placeholder="Ej. Material, Tipo, Marca">
                                            </label>
                                            <label>
                                                <span>Valores (separados por coma)</span>
                                                <textarea name="otros_atributos[0][valores]" rows="2" placeholder="Ej. Poliester, Algodon, Nylon"></textarea>
                                            </label>
                                        </div>
                                        <button type="button" class="epp-btn epp-btn-sm epp-btn-light epp-otros-add">+ Agregar otro atributo</button>
                                    </div>
                                </div>

                                <button type="submit" class="epp-btn epp-btn-primary epp-form-submit">Guardar ítem</button>
                            </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <script>
            (function() {
                function identPanel() {
                    return document.querySelector('[data-logistics-tab-panel="identificacion"]');
                }

                function makeCode(value) {
                    return String(value || '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toUpperCase()
                        .replace(/[^A-Z0-9]+/g, '_')
                        .replace(/^_+|_+$/g, '')
                        .slice(0, 110) || '--';
                }

                function syncToggleTarget(checkbox) {
                    var targetId = checkbox ? checkbox.getAttribute('data-epp-toggle-target') : '';
                    var target = targetId ? document.getElementById(targetId) : null;
                    if (target) {
                        target.hidden = !checkbox.checked;
                    }
                }

                function syncCodePreview(source) {
                    var preview = source.closest('label') ? source.closest('label').querySelector('[data-epp-code-preview]') : null;
                    if (preview) {
                        preview.textContent = makeCode(source.value);
                    }
                }

                window.openEppModal = function(modalId) {
                    var modal = document.getElementById(modalId);
                    if (!modal) return false;

                    if (modal.parentElement !== document.body) {
                        document.body.appendChild(modal);
                    }

                    modal.hidden = false;
                    modal.classList.add('is-open');
                    document.body.classList.add('epp-modal-open');
                    document.body.style.overflow = 'hidden';

                    modal.querySelectorAll('[data-epp-toggle-target]').forEach(syncToggleTarget);
                    modal.querySelectorAll('[data-epp-code-source]').forEach(syncCodePreview);

                    window.requestAnimationFrame(function() {
                        var focusable = modal.querySelector('input:not([type="hidden"]), select, textarea, button');
                        if (focusable) {
                            focusable.focus({ preventScroll: true });
                        }
                    });

                    return false;
                };

                window.closeEppModal = function(modal) {
                    if (!modal) return false;
                    modal.hidden = true;
                    modal.classList.remove('is-open');

                    if (!document.querySelector('.epp-modal:not([hidden])')) {
                        document.body.style.overflow = '';
                        document.body.classList.remove('epp-modal-open');
                    }

                    return false;
                };

                function handleIdentCatalogClick(event) {
                    var panel = identPanel();
                    if (!panel || !panel.contains(event.target)) {
                        var modalTarget = event.target.closest ? event.target.closest('.epp-modal') : null;
                        if (!modalTarget) {
                            return;
                        }
                    }

                    var opener = event.target.closest('[data-epp-open-modal]');
                    if (opener) {
                        if (!panel.contains(opener)) {
                            return;
                        }
                        event.preventDefault();
                        window.openEppModal(opener.getAttribute('data-epp-open-modal'));
                        return;
                    }

                    var closeButton = event.target.closest('[data-epp-close-modal]');
                    if (closeButton) {
                        event.preventDefault();
                        window.closeEppModal(closeButton.closest('.epp-modal'));
                        return;
                    }

                    var modal = event.target.classList && event.target.classList.contains('epp-modal') ? event.target : null;
                    if (modal) {
                        event.preventDefault();
                        window.closeEppModal(modal);
                        return;
                    }

                    var addButton = event.target.closest('.epp-otros-add');
                    if (addButton) {
                        event.preventDefault();
                        var list = addButton.closest('.epp-otros-list');
                        var items = list ? list.querySelectorAll('.epp-otros-item') : [];
                        if (!list || items.length === 0) return;

                        var index = items.length;
                        var template = items[0].cloneNode(true);
                        template.querySelectorAll('input, textarea').forEach(function(field) {
                            var name = field.getAttribute('name');
                            if (name) {
                                field.setAttribute('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                            }
                            field.value = '';
                        });
                        list.insertBefore(template, addButton);
                    }
                }

                function handleIdentCatalogChange(event) {
                    var panel = identPanel();
                    var modal = event.target.closest ? event.target.closest('.epp-modal') : null;
                    if (!panel || (!panel.contains(event.target) && !modal)) {
                        return;
                    }

                    var importInput = event.target.closest('[data-ident-import-input]');
                    if (importInput && importInput.files && importInput.files.length > 0) {
                        var form = importInput.closest('form');
                        if (form) {
                            form.submit();
                        }
                        return;
                    }

                    var checkbox = event.target.closest('[data-epp-toggle-target]');
                    if (checkbox) {
                        syncToggleTarget(checkbox);
                    }
                }

                function handleIdentCatalogInput(event) {
                    var panel = identPanel();
                    var modal = event.target.closest ? event.target.closest('.epp-modal') : null;
                    if (!panel || (!panel.contains(event.target) && !modal)) {
                        return;
                    }

                    var source = event.target.closest('[data-epp-code-source]');
                    if (source) {
                        syncCodePreview(source);
                    }
                }

                function handleIdentCatalogKeydown(event) {
                    if (event.key === 'Escape') {
                        document.querySelectorAll('.epp-modal:not([hidden])').forEach(window.closeEppModal);
                    }
                }

                function initIdentPanel() {
                    var panel = identPanel();
                    if (!panel) return;
                    panel.querySelectorAll('[data-epp-toggle-target]').forEach(syncToggleTarget);
                    panel.querySelectorAll('[data-epp-code-source]').forEach(syncCodePreview);
                    if (panel.dataset.identOpenModal) {
                        window.openEppModal(panel.dataset.identOpenModal);
                    }
                }

                if (!window.__logisticaIdentCatalogReady) {
                    window.__logisticaIdentCatalogReady = true;
                    document.addEventListener('click', handleIdentCatalogClick);
                    document.addEventListener('change', handleIdentCatalogChange);
                    document.addEventListener('input', handleIdentCatalogInput);
                    document.addEventListener('keydown', handleIdentCatalogKeydown);
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initIdentPanel);
                } else {
                    initIdentPanel();
                }
            })();
            </script>

            @push('styles')
            <style>
            body.epp-modal-open {
                overflow: hidden;
            }
            .lgt-ident-category-tabs {
                display: inline-flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                padding: 0.35rem;
                background: var(--color-slate-50);
                border: 1px solid var(--color-slate-200);
                border-radius: var(--radius-lg);
            }
            .lgt-ident-category-tab {
                display: inline-flex;
                align-items: center;
                gap: 0.625rem;
                min-height: 44px;
                padding: 0.45rem 0.8rem;
                border: 1px solid transparent;
                border-radius: var(--radius-md);
                color: var(--color-text-secondary);
                background: transparent;
                text-decoration: none;
                transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            }
            .lgt-ident-category-tab:hover {
                color: var(--color-text);
                background: #fff;
                border-color: #dbeafe;
            }
            .lgt-ident-category-tab.is-active {
                color: #0f766e;
                background: #ecfeff;
                border-color: var(--color-primary);
                box-shadow: 0 6px 18px rgba(20, 184, 166, 0.14);
            }
            .lgt-ident-category-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                flex: 0 0 28px;
                border-radius: 999px;
                background: #e2e8f0;
                color: #334155;
                font-size: 0.75rem;
                font-weight: 800;
            }
            .lgt-ident-category-tab.is-active .lgt-ident-category-icon {
                color: #fff;
                background: var(--color-primary-dark);
            }
            .lgt-ident-category-text {
                display: flex;
                flex-direction: column;
                gap: 0.05rem;
                line-height: 1.1;
            }
            .lgt-ident-category-text strong {
                font-size: var(--text-sm);
            }
            .lgt-ident-category-text small {
                color: var(--color-text-muted);
                font-size: 0.68rem;
                font-weight: 600;
            }
            .lgt-ident-header-actions {
                display: inline-flex;
                align-items: center;
                justify-content: flex-end;
                gap: 0.65rem;
                flex-wrap: wrap;
            }
            .lgt-ident-import-form {
                margin: 0;
            }
            .lgt-ident-import-btn {
                min-width: 126px;
            }
            .epp-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.375rem;
                cursor: pointer;
                font-family: inherit;
                font-weight: var(--font-weight-medium);
                border-radius: var(--radius-md);
                transition: all 0.15s ease;
                text-decoration: none;
                line-height: 1.2;
                border: 1px solid transparent;
                min-height: 38px;
                padding: 0.55rem 0.9rem;
            }
            .epp-btn-sm {
                min-height: 34px;
                padding: 0.42rem 0.75rem;
                font-size: var(--text-xs);
            }
            .epp-btn-primary {
                color: #fff;
                background: var(--color-primary-dark);
                border-color: var(--color-primary-dark);
                box-shadow: 0 8px 18px rgba(20, 181, 168, 0.22);
            }
            .epp-btn-primary:hover {
                background: #0f9488;
                border-color: #0f9488;
            }
            .epp-btn-main {
                font-weight: 800;
                white-space: nowrap;
            }
            .epp-btn-action,
            .epp-btn-light {
                color: #334155;
                background: #fff;
                border-color: #dbe3ef;
            }
            .epp-btn-action:hover,
            .epp-btn-light:hover {
                color: #0f766e;
                background: var(--color-cyan-50);
                border-color: var(--color-primary);
            }
            .epp-btn-danger,
            .epp-btn-danger-action {
                color: var(--color-danger);
                background: #fff;
                border-color: #fecaca;
            }
            .epp-btn-danger {
                color: #fff;
                background: var(--color-danger);
                border-color: var(--color-danger);
            }
            .epp-btn-danger:hover,
            .epp-btn-danger-action:hover {
                color: #b91c1c;
                background: #fef2f2;
                border-color: #fecaca;
            }
            .epp-row-actions {
                display: flex;
                gap: 0.5rem;
                align-items: center;
            }
            .epp-actions-cell {
                white-space: nowrap;
            }
            .epp-modal {
                position: fixed;
                inset: 0;
                z-index: 5000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.25rem;
                background: rgba(15, 23, 42, 0.42);
            }
            .epp-modal[hidden] {
                display: none !important;
            }
            .epp-modal-card {
                width: min(760px, 100%);
                max-height: min(88vh, 820px);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                background: #fff;
                border: 1px solid #dbe3ef;
                border-radius: var(--radius-lg);
                box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
            }
            .epp-modal-card-compact {
                width: min(520px, 100%);
            }
            .epp-modal-header {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                padding: 1.1rem 1.25rem;
                border-bottom: 1px solid #e2e8f0;
                background: #f8fafc;
            }
            .epp-modal-header h2 {
                margin: 0;
                color: var(--color-text);
                font-size: 1.1rem;
                font-weight: 800;
            }
            .epp-modal-header p {
                margin: 0.25rem 0 0;
                color: var(--color-text-secondary);
                font-size: var(--text-sm);
            }
            .epp-modal-close {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                flex: 0 0 34px;
                border: 1px solid #dbe3ef;
                border-radius: 999px;
                background: #fff;
                color: #475569;
                cursor: pointer;
                font-weight: 800;
            }
            .epp-modal-close:hover {
                color: #0f766e;
                border-color: var(--color-primary);
            }
            .epp-modal-body {
                overflow: auto;
                padding: 1.25rem;
            }
            .epp-form-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 1rem;
            }
            .epp-form-grid label {
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
                color: var(--color-text-secondary);
                font-size: var(--text-sm);
                font-weight: 700;
            }
            .epp-form-grid input[type="text"],
            .epp-form-grid input[type="number"],
            .epp-form-grid select,
            .epp-form-grid textarea {
                width: 100%;
                color: var(--color-text);
                background: #fff;
                border: 1px solid #dbe3ef;
                border-radius: var(--radius-md);
                padding: 0.65rem 0.75rem;
                font-size: var(--text-sm);
            }
            .epp-form-grid input:focus,
            .epp-form-grid select:focus,
            .epp-form-grid textarea:focus {
                outline: none;
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.16);
            }
            .epp-field-wide,
            .epp-form-submit {
                grid-column: 1 / -1;
            }
            .epp-code-preview {
                color: var(--color-text-muted);
                font-size: var(--text-xs);
                font-weight: 600;
            }
            .epp-option-toggle {
                flex-direction: row !important;
                align-items: center;
                gap: 0.5rem !important;
                color: var(--color-text);
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: var(--radius-md);
                padding: 0.65rem 0.75rem;
            }
            .epp-option-toggle input[type="checkbox"] {
                width: 16px;
                height: 16px;
                accent-color: var(--color-primary-dark);
            }
            .epp-delete-warning {
                display: flex;
                gap: 0.75rem;
                padding: 1rem;
                border: 1px solid #fecaca;
                border-radius: var(--radius-md);
                background: #fef2f2;
                color: #7f1d1d;
            }
            .epp-delete-warning p {
                margin: 0.25rem 0 0;
                color: #991b1b;
                font-size: var(--text-sm);
            }
            .epp-delete-icon {
                font-size: 1.25rem;
                line-height: 1;
            }
            .epp-delete-actions {
                display: flex;
                justify-content: flex-end;
                gap: 0.75rem;
                margin-top: 1rem;
            }
            .epp-otros-section {
                grid-column: 1 / -1;
                border-top: 1px solid var(--color-slate-200);
                padding-top: 1rem;
                margin-top: 0.5rem;
            }
            .epp-otros-item {
                display: flex;
                gap: 1rem;
                align-items: flex-start;
                margin-bottom: 0.75rem;
                padding: 0.75rem;
                background: var(--color-gray-50);
                border-radius: var(--radius-md);
            }
            .epp-otros-item label {
                flex: 1;
            }
            .epp-otros-add {
                margin-top: 0.25rem;
            }
            .lgt-attr-tag {
                font-size: var(--text-xs);
                margin-bottom: 0.25rem;
            }
            .lgt-attr-tag strong {
                display: inline-block;
                margin-right: 0.25rem;
            }
            .epp-otros-list {
                margin-top: 0.75rem;
            }
            @media (max-width: 760px) {
                .lgt-card-header {
                    align-items: stretch;
                }
                .lgt-ident-category-tabs,
                .lgt-ident-category-tab,
                .lgt-ident-header-actions,
                .lgt-ident-import-form,
                .epp-btn-main {
                    width: 100%;
                }
                .lgt-ident-header-actions {
                    justify-content: stretch;
                }
                .lgt-ident-category-tab {
                    justify-content: flex-start;
                }
                .epp-form-grid {
                    grid-template-columns: 1fr;
                }
                .epp-row-actions {
                    flex-direction: column;
                    align-items: stretch;
                }
            }
            .lgt-docs-container {
                display: flex;
                flex-direction: column;
                gap: 1rem;
                margin-bottom: 0.75rem;
            }
            .lgt-doc-row {
                display: flex;
                gap: 1rem;
                align-items: flex-start;
                padding: 0.75rem;
                background: var(--color-gray-50);
                border-radius: var(--radius-md);
                border: 1px solid var(--color-slate-200);
            }
            .lgt-doc-name {
                flex: 0 0 280px;
            }
            .lgt-doc-file {
                flex: 1;
            }
            .lgt-doc-name span,
            .lgt-doc-file span {
                display: block;
                font-size: var(--text-xs);
                font-weight: var(--font-weight-medium);
                color: var(--color-text-secondary);
                margin-bottom: 0.25rem;
            }
            .lgt-doc-name input {
                width: 100%;
                padding: 0.5rem 0.75rem;
                border: 1px solid var(--color-slate-200);
                border-radius: var(--radius-md);
                font-size: var(--text-sm);
            }
            .lgt-doc-name input:focus {
                outline: none;
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.15);
            }
            .lgt-file-info {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                padding: 0.5rem 0.75rem;
                background: var(--color-green-100);
                border-radius: var(--radius-md);
                font-size: var(--text-sm);
            }
            .lgt-file-info-label {
                font-weight: var(--font-weight-medium);
                color: var(--color-green-800);
            }
            .lgt-service-btn-sm {
                padding: 0.4rem 0.75rem;
                font-size: var(--text-xs);
            }
            </style>
            @endpush



            </section>
            @endif

            @if($canViewTabs['costos'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'costos' ? 'is-active' : '' }}" data-logistics-tab-panel="costos" aria-hidden="{{ $activeTab === 'costos' ? 'false' : 'true' }}">
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <h2>Costos y facturación</h2>
                    <p>Precios, proveedores, OC, facturación y stock.</p>
                </div>

                <div class="lgt-card-body">
                    @if($costRows->isEmpty())
                        <div class="lgt-empty">No hay datos disponibles</div>
                    @else
                        <div class="lgt-list">
                            @foreach($costRows as $row)
                                <div class="lgt-list-row">
                                    <strong>{{ $resolve($row, ['nombre', 'epp']) }}</strong>
                                    <span>{{ $resolve($row, ['proveedor', 'precio_unitario'], 'Sin proveedor') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            </section>
            @endif

            @if($canViewTabs['kardex'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'kardex' ? 'is-active' : '' }}" data-logistics-tab-panel="kardex" aria-hidden="{{ $activeTab === 'kardex' ? 'false' : 'true' }}">
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <div>
                        <h2>Kardex de EPP</h2>
                        <p>Selecciona un trabajador para ver su historial de entregas y descargar el kardex en Excel.</p>
                    </div>
                </div>

                <div class="lgt-card-body">
                    {{-- Buscador de personal --}}
                    <div class="lgt-kardex-search">
                        <label class="lgt-kardex-search-label">
                            <span>Buscar trabajador</span>
                            <div class="lgt-kardex-search-wrap">
                                <input
                                    type="text"
                                    id="kardexPersonalSearch"
                                    class="lgt-kardex-search-input"
                                    data-personal-search-url="{{ route('epps.personal.buscar', [], false) }}"
                                    data-kardex-detail-url="{{ route('epps.kardex.show', [], false) }}"
                                    data-kardex-download-url="{{ route('epps.kardex.download', [], false) }}"
                                    autocomplete="off"
                                    placeholder="Nombre, DNI o puesto..."
                                >
                                <div class="lgt-kardex-results" id="kardexPersonalResults" hidden></div>
                            </div>
                            <small class="lgt-kardex-hint">Escribe al menos 2 caracteres para buscar.</small>
                        </label>
                    </div>

                    {{-- Vista previa del kardex --}}
                    <div class="lgt-kardex-preview" id="kardexPreview" hidden>
                        <div class="lgt-kardex-preview-header">
                            <div class="lgt-kardex-worker-info" id="kardexWorkerInfo">
                                <strong id="kardexWorkerName">—</strong>
                                <span id="kardexWorkerMeta">—</span>
                            </div>
                            <button type="button" class="lgt-btn lgt-btn-primary" id="kardexDownloadBtn" disabled>
                                ⬇️ Descargar Excel
                            </button>
                        </div>

                        <div class="lgt-kardex-preview-table-wrap">
                            <div class="lgt-kardex-preview-placeholder" id="kardexPreviewPlaceholder">
                                <span class="lgt-kardex-preview-icon">📋</span>
                                <p>Selecciona un trabajador para ver la vista previa del kardex.</p>
                            </div>

                            {{-- El usuario editará esta tabla para personalizar el formato --}}
                            <div class="lgt-kardex-preview-content" id="kardexPreviewContent" hidden>
                                <table class="lgt-table lgt-kardex-table">
                                    <thead id="kardexTableHead">
                                        <tr>
                                            <th>Nro</th>
                                            <th>Fecha</th>
                                            <th>EPP usado</th>
                                            <th>Movimiento</th>
                                        </tr>
                                    </thead>                                    <tbody id="kardexTableBody">
                                        {{-- Los datos se cargarán dinámicamente --}}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="lgt-kardex-empty" id="kardexEmpty">
                        <span class="lgt-kardex-empty-icon">📋</span>
                        <p>Busca y selecciona un trabajador para generar su kardex de EPP.</p>
                    </div>
                </div>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var searchInput = document.getElementById('kardexPersonalSearch');
                var resultsPanel = document.getElementById('kardexPersonalResults');
                var preview = document.getElementById('kardexPreview');
                var empty = document.getElementById('kardexEmpty');
                var workerName = document.getElementById('kardexWorkerName');
                var workerMeta = document.getElementById('kardexWorkerMeta');
                var tableHead = document.getElementById('kardexTableHead');
                var tableBody = document.getElementById('kardexTableBody');
                var previewPlaceholder = document.getElementById('kardexPreviewPlaceholder');
                var previewContent = document.getElementById('kardexPreviewContent');
                var downloadBtn = document.getElementById('kardexDownloadBtn');

                if (!searchInput || !resultsPanel) return;

                var searchUrl = searchInput.getAttribute('data-personal-search-url');
                var detailUrl = searchInput.getAttribute('data-kardex-detail-url');
                var downloadUrl = searchInput.getAttribute('data-kardex-download-url');
                var timer = null;
                var selectedWorker = null;
                var activeItems = [];
                var searchAbortController = null;
                var searchRequestId = 0;

                function escapeHtml(value) {
                    return String(value || '').replace(/[&<>"']/g, function(c) {
                        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
                    });
                }

                function closeResults() {
                    resultsPanel.hidden = true;
                    resultsPanel.innerHTML = '';
                }

                function renderSearchMessage(message) {
                    activeItems = [];
                    resultsPanel.innerHTML = '<div class="lgt-kardex-result-empty">' + escapeHtml(message) + '</div>';
                    positionResults();
                    resultsPanel.hidden = false;
                }

                function resetSelection() {
                    selectedWorker = null;
                    preview.hidden = true;
                    empty.hidden = false;
                    downloadBtn.disabled = true;
                }

                function positionResults() {
                    resultsPanel.style.width = '100%';
                }

                function renderResults(items) {
                    activeItems = Array.isArray(items) ? items : [];

                    if (activeItems.length === 0) {
                        resultsPanel.innerHTML = '<div class="lgt-kardex-result-empty">Sin coincidencias.</div>';
                    } else {
                        resultsPanel.innerHTML = activeItems.map(function(person, index) {
                            return '<button type="button" class="lgt-kardex-result-row" data-index="' + index + '">' +
                                '<strong>' + escapeHtml(person.nombre || '') + '</strong>' +
                                '<span>' + escapeHtml([person.documento, person.puesto].filter(Boolean).join(' | ')) + '</span>' +
                            '</button>';
                        }).join('');
                    }

                    positionResults();
                    resultsPanel.hidden = false;
                }

                async function doSearch(query) {
                    if (query.length < 2) {
                        closeResults();
                        return;
                    }

                    if (searchAbortController) {
                        searchAbortController.abort();
                    }

                    searchAbortController = window.AbortController ? new AbortController() : null;
                    var requestId = ++searchRequestId;
                    var params = new URLSearchParams({ q: query, limit: '10' });

                    try {
                        var response = await fetch(searchUrl + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            signal: searchAbortController ? searchAbortController.signal : undefined,
                        });

                        if (requestId !== searchRequestId || query !== searchInput.value.trim()) {
                            return;
                        }

                        if (!response.ok) {
                            renderSearchMessage('No se pudo consultar personal. Recarga la pagina e intenta otra vez.');
                            return;
                        }

                        var payload = await response.json();
                        var items = payload.items || payload.data || [];
                        renderResults(items);
                    } catch (e) {
                        if (e && e.name === 'AbortError') {
                            return;
                        }

                        renderSearchMessage('No se pudo consultar personal. Revisa tu conexion e intenta otra vez.');
                    }
                }

                function renderKardexMatrix(kardex) {
                    var items = kardex && Array.isArray(kardex.items) ? kardex.items : [];
                    var rows = kardex && Array.isArray(kardex.rows) ? kardex.rows : [];
                    var hasObservations = Boolean(kardex && kardex.has_observations);

                    if (rows.length === 0) {
                        tableHead.innerHTML = '<tr><th>Nro</th><th>Fecha</th><th>EPP usado</th><th>Movimiento</th></tr>';
                        tableBody.innerHTML = '<tr><td colspan="4" class="lgt-kardex-loading">No tiene entregas de EPP registradas.</td></tr>';
                        return;
                    }

                    tableHead.innerHTML = '<tr>' +
                        '<th>Nro</th>' +
                        '<th>Fecha</th>' +
                        (items.length ? items.map(function(item) {
                            return '<th class="lgt-kardex-item-head">' + escapeHtml(item.label || 'EPP') + '<small>' + escapeHtml(item.sheet === 'posterior' ? 'Posterior' : 'Anterior') + '</small></th>';
                        }).join('') : '<th>EPP usado</th>') +
                        (hasObservations ? '<th>Observacion</th>' : '') +
                    '</tr>';

                    tableBody.innerHTML = rows.map(function(row) {
                        var codes = row.codes || {};
                        return '<tr>' +
                            '<td>' + escapeHtml(row.number || '-') + '</td>' +
                            '<td>' + escapeHtml(row.date || '-') + '</td>' +
                            (items.length ? items.map(function(item) {
                                return '<td class="lgt-kardex-code">' + escapeHtml(codes[item.key] || '') + '</td>';
                            }).join('') : '<td>-</td>') +
                            (hasObservations ? '<td class="lgt-kardex-observation">' + escapeHtml(row.observation || '') + '</td>' : '') +
                        '</tr>';
                    }).join('');
                }
                async function applyPerson(person) {
                    selectedWorker = person;
                    searchInput.value = person.nombre || '';
                    closeResults();

                    // Update worker info
                    workerName.textContent = person.nombre || '-';
                    workerMeta.textContent = [person.documento, person.puesto].filter(Boolean).join(' | ') || '-';

                    // Show preview, hide empty state
                    empty.hidden = true;
                    preview.hidden = false;
                    previewPlaceholder.hidden = true;
                    previewContent.hidden = false;
                    downloadBtn.disabled = true;

                    tableHead.innerHTML = '<tr><th>Nro</th><th>Fecha</th><th>EPP usado</th><th>Movimiento</th></tr>';
                    tableBody.innerHTML = '<tr><td colspan="4" class="lgt-kardex-loading">' +
                        'Cargando historial de EPP del trabajador...' +
                        '</td></tr>';

                    try {
                        var params = new URLSearchParams({ personal_id: person.id });
                        var response = await fetch(detailUrl + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                        });

                        if (!response.ok) {
                            throw new Error('kardex');
                        }

                        var payload = await response.json();
                        renderKardexMatrix((payload.data && payload.data.kardex) || {});
                        downloadBtn.disabled = false;
                    } catch (e) {
                        tableBody.innerHTML = '<tr><td colspan="4" class="lgt-kardex-loading">No se pudo cargar el kardex del trabajador.</td></tr>';
                    }

                    searchInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                // Search input events
                searchInput.addEventListener('input', function() {
                    clearTimeout(timer);
                    var query = this.value.trim();
                    resetSelection();

                    if (query === '') {
                        closeResults();
                        return;
                    }

                    if (query.length < 2) {
                        renderSearchMessage('Escribe al menos 2 caracteres para buscar.');
                        return;
                    }

                    renderSearchMessage('Buscando coincidencias...');
                    timer = setTimeout(function() { doSearch(query); }, 180);
                });

                searchInput.addEventListener('focus', function() {
                    if (activeItems.length > 0) {
                        positionResults();
                        resultsPanel.hidden = false;
                        return;
                    }

                    var query = this.value.trim();
                    if (query.length >= 2) {
                        renderSearchMessage('Buscando coincidencias...');
                        clearTimeout(timer);
                        timer = setTimeout(function() { doSearch(query); }, 120);
                    }
                });

                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        closeResults();
                    }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (activeItems.length > 0) {
                            applyPerson(activeItems[0]);
                        }
                    }
                });

                // Results panel selection. Use mousedown so the row is applied before focus/outside handlers close the panel.
                resultsPanel.addEventListener('mousedown', function(e) {
                    var row = e.target.closest('[data-index]');
                    if (!row) return;
                    e.preventDefault();
                    var person = activeItems[Number(row.dataset.index)];
                    if (person) {
                        applyPerson(person);
                    }
                });

                downloadBtn.addEventListener('click', function() {
                    if (!selectedWorker || downloadBtn.disabled) {
                        return;
                    }

                    var params = new URLSearchParams({ personal_id: selectedWorker.id });
                    window.location.href = downloadUrl + '?' + params.toString();
                });

                // Close results on outside click
                document.addEventListener('mousedown', function(e) {
                    if (resultsPanel.contains(e.target) || e.target === searchInput) {
                        return;
                    }
                    closeResults();
                });

                // Reposition on scroll/resize
                function repositionHandler() {
                    if (!resultsPanel.hidden) {
                        positionResults();
                    }
                }
                window.addEventListener('scroll', repositionHandler, true);
                window.addEventListener('resize', repositionHandler);
            });
            </script>

            @push('styles')
            <style>
            .lgt-kardex-search {
                margin-bottom: 1.5rem;
            }
            .lgt-kardex-search-label {
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
                max-width: 420px;
            }
            .lgt-kardex-search-label > span {
                font-size: var(--text-sm);
                font-weight: var(--font-weight-medium);
                color: var(--color-text-secondary);
            }
            .lgt-kardex-search-wrap {
                position: relative;
            }
            .lgt-kardex-search-input {
                width: 100%;
                padding: 0.6rem 0.85rem;
                border: 1.5px solid var(--color-slate-200);
                border-radius: var(--radius-lg);
                font-size: var(--text-sm);
                background: #fff;
                transition: border-color 0.15s, box-shadow 0.15s;
            }
            .lgt-kardex-search-input:focus {
                outline: none;
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.12);
            }
            .lgt-kardex-hint {
                font-size: var(--text-xs);
                color: var(--color-text-muted);
            }
            .lgt-kardex-results {
                position: absolute;
                left: 0;
                right: 0;
                top: calc(100% + 6px);
                z-index: 1000;
                background: #fff;
                border: 1px solid var(--color-slate-200);
                border-radius: var(--radius-lg);
                box-shadow: 0 6px 24px rgba(0,0,0,0.1);
                max-height: 280px;
                min-width: 300px;
                overflow-y: auto;
                padding: 0.35rem 0;
            }
            .lgt-kardex-result-row {
                display: block;
                width: 100%;
                text-align: left;
                padding: 0.55rem 0.85rem;
                background: none;
                border: none;
                cursor: pointer;
                font-family: inherit;
                transition: background 0.1s;
            }
            .lgt-kardex-result-row:hover {
                background: var(--color-cyan-50);
            }
            .lgt-kardex-result-row strong {
                display: block;
                font-size: var(--text-sm);
                color: var(--color-text);
            }
            .lgt-kardex-result-row span {
                display: block;
                font-size: var(--text-xs);
                color: var(--color-text-muted);
                margin-top: 0.15rem;
            }
            .lgt-kardex-result-empty {
                padding: 1rem 0.85rem;
                font-size: var(--text-sm);
                color: var(--color-text-muted);
                text-align: center;
            }
            .lgt-kardex-empty {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.5rem;
                padding: 3rem 1rem;
                text-align: center;
            }
            .lgt-kardex-empty-icon {
                font-size: 2.5rem;
                opacity: 0.4;
            }
            .lgt-kardex-empty p {
                color: var(--color-text-muted);
                font-size: var(--text-sm);
                max-width: 320px;
            }
            .lgt-kardex-preview-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding-bottom: 1rem;
                margin-bottom: 1rem;
                border-bottom: 1px solid var(--color-slate-200);
            }
            .lgt-kardex-worker-info {
                display: flex;
                flex-direction: column;
                gap: 0.15rem;
            }
            .lgt-kardex-worker-info strong {
                font-size: var(--text-xl);
                color: var(--color-text);
            }
            .lgt-kardex-worker-info span {
                font-size: var(--text-sm);
                color: var(--color-text-secondary);
            }
            .lgt-btn-primary {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                padding: 0.55rem 1rem;
                background: var(--color-primary);
                color: #fff;
                border: none;
                border-radius: var(--radius-lg);
                font-size: var(--text-sm);
                font-weight: var(--font-weight-medium);
                cursor: pointer;
                transition: background 0.15s;
            }
            .lgt-btn-primary:hover:not(:disabled) {
                background: var(--color-primary-dark);
            }
            .lgt-btn-primary:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .lgt-kardex-preview-placeholder {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.5rem;
                padding: 2rem;
                text-align: center;
            }
            .lgt-kardex-preview-icon {
                font-size: 2rem;
                opacity: 0.35;
            }
            .lgt-kardex-preview-placeholder p {
                color: var(--color-text-muted);
                font-size: var(--text-sm);
            }
            .lgt-kardex-table {
                width: 100%;
                font-size: var(--text-sm);
            }
            .lgt-kardex-table th {
                text-align: left;
                padding: 0.55rem 0.65rem;
                font-weight: var(--font-weight-semibold);
                color: var(--color-text-secondary);
                border-bottom: 2px solid var(--color-slate-200);
                white-space: nowrap;
                font-size: var(--text-xs);
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .lgt-kardex-table td {
                padding: 0.5rem 0.65rem;
                border-bottom: 1px solid var(--color-slate-200);
            }
            .lgt-kardex-loading {
                text-align: center;
                color: var(--color-text-muted);
                padding: 2rem !important;
            }
            .lgt-kardex-card {
                overflow: visible;
            }
            .lgt-kardex-header {
                align-items: center;
            }
            .lgt-kardex-header .lgt-btn-primary {
                border-radius: 8px;
                background: #0f766e;
                min-width: 150px;
                justify-content: center;
            }
            .lgt-kardex-preview {
                border: 1px solid #dbeafe;
                border-radius: 8px;
                overflow: hidden;
                background: #fff;
            }
            .lgt-kardex-preview-header {
                margin: 0;
                padding: 1rem 1.1rem;
                background: #f8fafc;
            }
            .lgt-kardex-preview-table-wrap {
                overflow-x: auto;
            }
            .lgt-kardex-table {
                border-collapse: collapse;
                min-width: 840px;
            }
            .lgt-kardex-table th {
                background: #f8fafc;
            }
            .lgt-kardex-item-head {
                min-width: 78px;
                max-width: 110px;
                white-space: normal !important;
                text-align: center !important;
                vertical-align: bottom;
            }
            .lgt-kardex-item-head small {
                color: #64748b;
                display: block;
                font-size: 10px;
                font-weight: 700;
                margin-top: .15rem;
                text-transform: none;
            }
            .lgt-kardex-code {
                color: #0f766e;
                font-size: 15px;
                font-weight: 900;
                text-align: center;
            }
            .lgt-kardex-observation {
                min-width: 220px;
                white-space: normal;
            }
            .lgt-kardex-table td strong {
                display: block;
                color: #0f172a;
            }
            .lgt-kardex-table td small {
                display: block;
                margin-top: .2rem;
                color: #64748b;
            }
            .lgt-kardex-status {
                display: inline-flex;
                border-radius: 999px;
                padding: .25rem .55rem;
                font-size: var(--text-xs);
                font-weight: 700;
                background: #e2e8f0;
                color: #334155;
            }
            .lgt-kardex-status.is-entregado {
                background: #dcfce7;
                color: #166534;
            }
            .lgt-kardex-status.is-cambiado {
                background: #dbeafe;
                color: #1d4ed8;
            }
            .lgt-kardex-status.is-devuelto {
                background: #f1f5f9;
                color: #475569;
            }
            .lgt-kardex-status.is-uso_incorrecto {
                background: #fed7aa;
                color: #9a3412;
            }
            .lgt-kardex-status.is-perdida_olvido {
                background: #fee2e2;
                color: #991b1b;
            }
            .lgt-kardex-usage {
                width: 88px;
                height: 6px;
                border-radius: 999px;
                overflow: hidden;
                background: #e2e8f0;
                margin-bottom: .25rem;
            }
            .lgt-kardex-usage span {
                display: block;
                height: 100%;
                max-width: 100%;
                background: #0d9488;
            }
            @media (max-width: 760px) {
                .lgt-kardex-preview-header {
                    flex-direction: column;
                    align-items: stretch;
                }
                .lgt-kardex-header .lgt-btn-primary {
                    width: 100%;
                }
            }
            </style>
            @endpush

            </section>
            @endif

            @if($canViewTabs['cesados'] ?? false)
            <section class="lgt-tab-panel {{ $activeTab === 'cesados' ? 'is-active' : '' }}" data-logistics-tab-panel="cesados" aria-hidden="{{ $activeTab === 'cesados' ? 'false' : 'true' }}">
                <div class="lgt-card lgt-ceased-card">
                    <div class="lgt-card-header">
                        <div>
                            <h2>Cesados por entregar</h2>
                            <p>Trabajadores cesados con EPP pendiente o ya resuelto por devolucion, perdida u observacion.</p>
                        </div>
                    </div>

                    <div class="lgt-ceased-summary">
                        <article>
                            <span>Trabajadores</span>
                            <strong>{{ number_format((int) data_get($ceasedSummary, 'trabajadores', 0)) }}</strong>
                        </article>
                        <article class="is-pending">
                            <span>Pendiente de entrega</span>
                            <strong>{{ number_format((int) data_get($ceasedSummary, 'pendientes', 0)) }}</strong>
                        </article>
                        <article class="is-resolved">
                            <span>Resuelto</span>
                            <strong>{{ number_format((int) data_get($ceasedSummary, 'resueltos', 0)) }}</strong>
                        </article>
                    </div>

                    <div class="lgt-card-body">
                        <form method="GET" action="{{ route('logistica.index') }}" class="lgt-ceased-filters">
                            <input type="hidden" name="tab" value="cesados">
                            <label>
                                <span>Buscar cesado</span>
                                <input type="search" name="q" value="{{ request('q') }}" placeholder="Nombre, DNI o puesto">
                            </label>
                            <div class="lgt-ceased-actions">
                                <button type="submit">Filtrar</button>
                                <a href="{{ route('logistica.index', ['tab' => 'cesados']) }}">Limpiar</a>
                            </div>
                        </form>

                        @if($ceasedRows->isEmpty())
                            <div class="lgt-empty">No hay trabajadores cesados con EPP registrado para regularizar.</div>
                        @else
                            <div class="lgt-ceased-list">
                                @foreach($ceasedRows as $row)
                                    @php
                                        $isPending = (int) data_get($row, 'pendientes', 0) > 0;
                                        $items = collect(data_get($row, 'items', []));
                                    @endphp
                                    <details class="lgt-ceased-worker {{ $isPending ? 'has-pending' : 'is-resolved' }}">
                                        <summary class="lgt-ceased-worker-head">
                                            <div>
                                                <h3>{{ data_get($row, 'trabajador', 'Sin trabajador') }}</h3>
                                                <p>{{ data_get($row, 'documento', '-') }} &middot; {{ data_get($row, 'puesto', 'Por definir') }} &middot; {{ data_get($row, 'mina', 'Sin mina') ?: 'Sin mina' }}</p>
                                            </div>
                                            <div class="lgt-ceased-worker-status">
                                                <span class="lgt-ceased-count">{{ data_get($row, 'pendientes', 0) }} pendiente(s)</span>
                                                <span class="lgt-ceased-state {{ $isPending ? 'is-pending' : 'is-resolved' }}">
                                                    {{ $isPending ? 'Pendiente de entrega' : 'Resuelto' }}
                                                </span>
                                            </div>
                                        </summary>

                                        <div class="lgt-ceased-worker-body">
                                            <div class="lgt-ceased-meta">
                                                <span><strong>Cese</strong>{{ data_get($row, 'fecha_cese', '-') }}</span>
                                                <span><strong>Contrato</strong>{{ data_get($row, 'contrato_estado', 'CESADO') }}</span>
                                                <span><strong>Motivo</strong>{{ data_get($row, 'motivo_cese', '-') }}</span>
                                                <span><strong>Resumen</strong>{{ data_get($row, 'pendientes', 0) }} pendiente(s), {{ data_get($row, 'resueltos', 0) }} resuelto(s)</span>
                                            </div>

                                            <div class="lgt-ceased-items">
                                                @foreach($items as $item)
                                                    @php
                                                        $resolved = (bool) data_get($item, 'resuelto', false);
                                                        $state = strtolower((string) data_get($item, 'estado', ''));
                                                    @endphp
                                                    <div class="lgt-ceased-item {{ $resolved ? 'is-resolved' : 'is-pending' }}">
                                                        <div>
                                                            <strong>{{ data_get($item, 'epp', 'EPP') }}</strong>
                                                            <small>{{ data_get($item, 'codigo', '-') }} &middot; Entrega: {{ data_get($item, 'fecha_entrega', '-') }} &middot; Cantidad: {{ data_get($item, 'cantidad', 1) }}</small>
                                                            @if(filled(data_get($item, 'observacion')))
                                                                <small>{{ data_get($item, 'observacion') }}</small>
                                                            @endif
                                                        </div>
                                                        <div class="lgt-ceased-item-status">
                                                            <span class="lgt-ceased-pill {{ $resolved ? 'is-resolved' : 'is-pending' }}">{{ data_get($item, 'estado_resolucion', '-') }}</span>
                                                            <span class="lgt-ceased-pill is-state is-{{ $state }}">{{ data_get($item, 'estado_label', data_get($item, 'estado', '-')) }}</span>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>

                                            <a class="lgt-ceased-link" href="{{ route('logistica.index', ['tab' => 'entregas', 'trabajador' => data_get($row, 'personal_id')]) }}">
                                                Ver movimientos de EPP
                                            </a>
                                        </div>
                                    </details>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </section>
            @endif
        </div>
    </section>
</div>
@endsection


