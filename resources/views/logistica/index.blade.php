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
    ];

    $permissions = session('user.permissions', []);
    $canViewEpps = (bool) ($canViewEpps ?? \App\Support\Rbac\PermissionMatrix::allows($permissions, 'epps', 'ver'));
    $canViewHerramientas = (bool) ($canViewHerramientas ?? ($canViewEpps || \App\Support\Rbac\PermissionMatrix::allows($permissions, 'herramientas', 'ver')));
    $allTabs = $tabs;
    $tabs = [];
    if ($canViewEpps) {
        $tabs['dashboard'] = $allTabs['dashboard'];
        $tabs['entregas'] = $allTabs['entregas'];
        $tabs['vencimientos'] = $allTabs['vencimientos'];
    }
    if ($canViewHerramientas) {
        $tabs['herramientas'] = $allTabs['herramientas'];
    }
    if ($canViewEpps) {
        $tabs['servicios'] = $allTabs['servicios'];
        $tabs['identificacion'] = $allTabs['identificacion'];
        $tabs['costos'] = $allTabs['costos'];
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
            @if($canViewEpps)
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

            <section class="lgt-tab-panel {{ $activeTab === 'entregas' ? 'is-active' : '' }}" data-logistics-tab-panel="entregas" aria-hidden="{{ $activeTab === 'entregas' ? 'false' : 'true' }}">
                @include('epps.partials.workspace', array_merge($eppModule ?? [], ['embedded' => true]))
            </section>

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

            @if($canViewEpps)
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

                                <a href="{{ url('/logistica') . '?' . http_build_query(['tab' => 'servicios', 'servicio_parada_id' => $pId]) }}"
                                   class="lgt-parada-card {{ $isSelected ? 'is-selected' : '' }}"
                                   data-parada-search="{{ strtolower($pLabel . ' ' . $pMina . ' ' . $pArea) }}"
                                   data-parada-mina="{{ $pMina }}">
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
                                    @if($isSelected)
                                        <div class="lgt-parada-card-check">✓</div>
                                    @endif
                                </a>
                            @empty
                                <div class="lgt-parada-empty">No hay paradas disponibles.</div>
                            @endforelse
                        </div>
                    </div>

                    @php
                        $filteredRows = $servicePreviewRows->filter(function ($row) use ($selectedParadaId) {
                            return $selectedParadaId === '' || (string) data_get($row, 'rq_mina_id') === $selectedParadaId;
                        })->values();
                    @endphp

                    @if($selectedParadaId === '')
                        <div class="lgt-servicios-empty">
                            <span class="lgt-servicios-empty-icon">🚛</span>
                            <p>Selecciona una parada de la lista para ver sus requerimientos de transporte y gestionar la atención logística.</p>
                        </div>

                    @elseif($filteredRows->isEmpty())
                        <div class="lgt-empty">No hay transportes solicitados para esta parada.</div>

                    @else
                        <div class="lgt-service-grid">
                            @foreach($filteredRows as $row)
                                @php
                                    $isCompletado = data_get($row, 'origen') !== '' || data_get($row, 'estado') !== 'REQUERIDO' || data_get($row, 'fecha_inicio') !== '';
                                    $selectedParada = $paradasCol->firstWhere('id', $selectedParadaId);
                                    $paradaActiva = $selectedParada && data_get($selectedParada, 'estado') !== 'FINALIZADA';
                                    $tieneEntrega = data_get($row, 'recepcion_estado') !== 'PENDIENTE';
                                    $puedeHacerCambios = $tieneEntrega && $paradaActiva;
                                    $cardClass = $isCompletado ? 'is-completado' : 'is-pendiente';
                                @endphp

                                <article class="lgt-service-card {{ $cardClass }}" data-rq-mina-id="{{ data_get($row, 'rq_mina_id') }}">
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

                                                            <label class="lgt-file-upload-label">
                                                                <span>Documentación del vehículo</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_vehiculo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input">
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                                @if(data_get($row, 'doc_vehiculo_path'))
                                                                    <span class="lgt-file-loaded">✓ Documento cargado</span>
                                                                @endif
                                                            </label>
                                                            <label class="lgt-file-upload-label">
                                                                <span>Documentación interna Proserge</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_proserge" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input">
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                                @if(data_get($row, 'doc_proserge_path'))
                                                                    <span class="lgt-file-loaded">✓ Documento cargado</span>
                                                                @endif
                                                            </label>
                                                            <label class="lgt-file-upload-label">
                                                                <span>Plan de mantenimiento</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_mantenimiento" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input">
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                                @if(data_get($row, 'doc_mantenimiento_path'))
                                                                    <span class="lgt-file-loaded">✓ Documento cargado</span>
                                                                @endif
                                                            </label>
                                                            <label class="lgt-file-upload-label">
                                                                <span>Checklist de enseres</span>
                                                                <div class="lgt-file-upload-wrap">
                                                                    <input type="file" name="doc_checklist" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="lgt-file-input">
                                                                    <span class="lgt-file-hint">PDF, JPG o PNG · Máx 10 MB</span>
                                                                </div>
                                                                @if(data_get($row, 'doc_checklist_path'))
                                                                    <span class="lgt-file-loaded">✓ Documento cargado</span>
                                                                @endif
                                                            </label>
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

            <style>
/* --- Explorador visual de paradas --- */
.lgt-parada-explorer { margin-bottom:24px; padding:16px 18px; background:#fff; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.lgt-parada-explorer-label { display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:10px; text-transform:uppercase; letter-spacing:.03em; }
.lgt-parada-explorer-tools { display:flex; gap:10px; margin-bottom:14px; }
.lgt-parada-search-wrap { flex:1; position:relative; }
.lgt-parada-search-wrap::before { content:'🔍'; position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:13px; opacity:.5; pointer-events:none; }
.lgt-parada-search { width:100%; border:1.5px solid #dbe4ef; border-radius:8px; padding:10px 12px 10px 36px; font-size:13px; color:#0f172a; background:#fff; transition:border-color .15s; box-sizing:border-box; }
.lgt-parada-search:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 3px rgba(13,148,136,.1); }
.lgt-parada-search::placeholder { color:#94a3b8; }
.lgt-parada-mina-filter { min-width:180px; border:1.5px solid #dbe4ef; border-radius:8px; padding:10px 12px; font-size:13px; color:#0f172a; background:#fff; cursor:pointer; transition:border-color .15s; }
.lgt-parada-mina-filter:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 3px rgba(13,148,136,.1); }
.lgt-parada-list { display:flex; flex-direction:column; gap:8px; max-height:256px; overflow-y:auto; padding-right:4px; }
.lgt-parada-list::-webkit-scrollbar { width:5px; }
.lgt-parada-list::-webkit-scrollbar-track { background:#f1f5f9; border-radius:10px; }
.lgt-parada-list::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; }
.lgt-parada-card { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1.5px solid #e2e8f0; border-radius:10px; background:#fcfdff; text-decoration:none; color:inherit; transition:all .15s ease; position:relative; }
.lgt-parada-card:hover { border-color:#0d9488; background:#f0fdfa; box-shadow:0 2px 8px rgba(13,148,136,.08); transform:translateY(-1px); }
.lgt-parada-card.is-selected { border-color:#0d9488; background:linear-gradient(135deg,#ecfeff,#f0fdfa); box-shadow:0 0 0 2px #0d9488,0 2px 8px rgba(13,148,136,.12); }
.lgt-parada-card-main { display:flex; flex-direction:column; gap:4px; min-width:0; flex:1; }
.lgt-parada-card-title { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.lgt-parada-card-title strong { font-size:14px; color:#0f172a; font-weight:700; }
.lgt-parada-card-area { font-size:12px; color:#64748b; background:#f1f5f9; padding:2px 8px; border-radius:4px; font-weight:600; }
.lgt-parada-card-dates { display:flex; align-items:center; gap:10px; font-size:12px; color:#64748b; flex-wrap:wrap; }
.lgt-parada-card-time { font-size:11px; color:#0f766e; font-weight:700; background:#ecfeff; padding:2px 8px; border-radius:4px; }
.lgt-parada-card-badge { flex-shrink:0; }
.lgt-parada-card-badge span { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.badge-en-curso span { background:#d1fae5; color:#065f46; }
.badge-por-iniciar span { background:#fef3c7; color:#92400e; }
.badge-proxima span { background:#dbeafe; color:#1e40af; }
.badge-finalizada span { background:#f1f5f9; color:#64748b; }
.badge-sin-fecha span { background:#f1f5f9; color:#94a3b8; }
.lgt-parada-card-check { position:absolute; top:-6px; right:-6px; width:22px; height:22px; background:#0d9488; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; box-shadow:0 2px 4px rgba(0,0,0,.15); }
.lgt-parada-empty { text-align:center; padding:24px; color:#94a3b8; font-size:13px; }
.lgt-servicios-empty { text-align:center; padding:48px 20px; border:2px dashed #e2e8f0; border-radius:12px; background:#fafbfc; }
.lgt-servicios-empty-icon { font-size:36px; display:block; margin-bottom:8px; }
.lgt-servicios-empty p { margin:0; color:#94a3b8; font-size:13px; line-height:1.6; }
.lgt-service-card.is-pendiente { border-left:3px solid #f59e0b; }
.lgt-service-card.is-completado { border-left:3px solid #10b981; }
.lgt-status.is-ok { background:#d1fae5; color:#065f46; }
.lgt-status.is-warning { background:#fef3c7; color:#92400e; }
.lgt-service-completar-panel { margin-top:12px; padding:14px; border-radius:10px; background:#fffbeb; border:1px solid #fde68a; }
.lgt-service-completar-hint { margin:0 0 10px; font-size:12px; color:#92400e; line-height:1.5; }
.lgt-service-completado-actions { margin-top:12px; }
.lgt-service-summary { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; margin-bottom:12px; padding:10px 12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; }
.lgt-service-summary-item { display:flex; flex-direction:column; gap:2px; }
.lgt-service-summary-label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; }
.lgt-service-summary-value { font-size:13px; color:#0f172a; font-weight:600; }
.lgt-service-actions-group { display:flex; gap:8px; flex-wrap:wrap; }
.lgt-service-btn { border:1.5px solid #dbe4ef; background:#fff; color:#0f172a; border-radius:8px; padding:9px 16px; font-size:12px; font-weight:700; cursor:pointer; transition:all .15s ease; display:inline-flex; align-items:center; gap:5px; font-family:inherit; line-height:1; }
.lgt-service-btn:hover { background:#f8fafc; }
.lgt-service-btn-primary { border-color:#0f766e; background:#0f766e; color:#fff; }
.lgt-service-btn-primary:hover { background:#115e59; border-color:#115e59; }
.lgt-service-btn-outline { border-color:#0f766e; color:#0f766e; background:#fff; }
.lgt-service-btn-outline:hover { background:#ecfeff; }
.lgt-service-btn-ghost { border-color:transparent; background:transparent; color:#64748b; }
.lgt-service-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
.lgt-service-form-panel { margin-top:12px; padding:14px; background:#fff; border-radius:10px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.lgt-service-form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:12px; }
.lgt-service-form-grid .wide { grid-column:1 / -1; }
.lgt-service-form-grid label { display:flex; flex-direction:column; gap:4px; font-size:11px; font-weight:700; color:#475569; }
.lgt-service-form-grid label input,.lgt-service-form-grid label select,.lgt-service-form-grid label textarea { border:1.5px solid #dbe4ef; border-radius:6px; padding:8px 10px; font-size:13px; color:#0f172a; background:#fff; font-family:inherit; transition:border-color .15s; }
.lgt-service-form-grid label input:focus,.lgt-service-form-grid label select:focus,.lgt-service-form-grid label textarea:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 3px rgba(13,148,136,.1); }
.lgt-service-form-grid label textarea { resize:vertical; min-height:54px; }
.lgt-motivo-cambio { grid-column:1 / -1; padding:10px; background:#fef2f2; border-radius:8px; border:1px solid #fecaca; }
.lgt-motivo-cambio label { color:#b91c1c; }
.lgt-motivo-cambio label textarea { border-color:#fecaca; }
.lgt-motivo-cambio label textarea:focus { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.1); }
.lgt-required { color:#dc2626; }
.lgt-service-form-actions { display:flex; gap:8px; margin-top:12px; justify-content:flex-end; }
.lgt-service-form-divider { display:flex; align-items:center; gap:12px; margin:4px 0 2px; }
.lgt-service-form-divider span { font-size:11px; font-weight:800; color:#0f766e; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
.lgt-service-form-divider::after { content:''; flex:1; height:1px; background:linear-gradient(90deg,#ccfbf1,transparent); }
.lgt-file-upload-label { display:flex; flex-direction:column; gap:4px; font-size:11px; font-weight:700; color:#475569; }
.lgt-file-upload-wrap { position:relative; border:1.5px dashed #dbe4ef; border-radius:8px; padding:8px; background:#fafbfc; transition:all .15s; }
.lgt-file-upload-wrap:hover { border-color:#0d9488; background:#f0fdfa; }
.lgt-file-input { width:100%; font-size:12px; color:#0f172a; cursor:pointer; }
.lgt-file-input::file-selector-button { padding:5px 12px; border:1px solid #dbe4ef; border-radius:6px; background:#fff; color:#0f172a; font-size:11px; font-weight:700; cursor:pointer; transition:all .12s; margin-right:8px; }
.lgt-file-input::file-selector-button:hover { background:#f1f5f9; border-color:#94a3b8; }
.lgt-file-hint { display:block; font-size:10px; color:#94a3b8; font-weight:400; margin-top:2px; }
.lgt-file-loaded { display:inline-flex; align-items:center; gap:4px; margin-top:3px; font-size:11px; font-weight:600; color:#0f766e; background:#ecfeff; padding:2px 8px; border-radius:4px; }
.lgt-service-form-inline { margin:0; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var serviciosPanel = document.querySelector('[data-logistics-tab-panel="servicios"]');
    if (!serviciosPanel) return;

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
    });

    // Parada search
    var searchInput = document.getElementById('lgtParadaSearch');
    var minaFilter = document.getElementById('lgtParadaMinaFilter');
    var paradaList = document.getElementById('lgtParadaList');

    if (searchInput && paradaList) {
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

            </section>

            <section class="lgt-tab-panel {{ $activeTab === 'identificacion' ? 'is-active' : '' }}" data-logistics-tab-panel="identificacion" aria-hidden="{{ $activeTab === 'identificacion' ? 'false' : 'true' }}">
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <h2>Identificación de ítems</h2>
                    <p>Catálogo base de EPP, códigos, vida útil, tallas y colores.</p>
                </div>

                <div class="lgt-card-body">
                    @if($identityRows->isEmpty())
                        <div class="lgt-empty">No hay datos disponibles</div>
                    @else
                        <div class="lgt-list">
                            @foreach($identityRows as $row)
                                <div class="lgt-list-row">
                                    <strong>{{ $resolve($row, ['nombre', 'item']) }}</strong>
                                    <span>{{ $resolve($row, ['codigo', 'estado'], 'Sin código') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            </section>

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
        </div>
    </section>
</div>
@endsection
