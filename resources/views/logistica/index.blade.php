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

    $activeTab = request()->query('tab', 'dashboard');
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
    $expiringDeliveryRows = $asCollection($expiringDeliveries ?? []);
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

    <section class="lgt-content" data-logistics-tab-viewport>
        <div class="lgt-tab-track lgt-tab-track--{{ $activeTabIndex }}" data-logistics-tab-track data-logistics-active-index="{{ $activeTabIndex }}">
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
                    <div class="lgt-table-wrap">
                        @includeIf('logistica.partials.delivery-table', [
                            'rows' => $expiringDeliveryRows,
                            'showDays' => true,
                        ])
                    </div>
                </div>
            </div>

            </section>

            <section class="lgt-tab-panel {{ $activeTab === 'herramientas' ? 'is-active' : '' }}" data-logistics-tab-panel="herramientas" aria-hidden="{{ $activeTab === 'herramientas' ? 'false' : 'true' }}">
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

            </section>

            <section class="lgt-tab-panel {{ $activeTab === 'servicios' ? 'is-active' : '' }}" data-logistics-tab-panel="servicios" aria-hidden="{{ $activeTab === 'servicios' ? 'false' : 'true' }}">
            <div class="lgt-card">
                <div class="lgt-card-header">
                    <h2>Servicios y alquileres</h2>
                    <p>Transporte, alquileres y servicios asociados a paradas.</p>
                </div>

                <div class="lgt-card-body">
                    @if($servicePreviewRows->isEmpty())
                        <div class="lgt-empty">No hay datos disponibles</div>
                    @else
                        <div class="lgt-list">
                            @foreach($servicePreviewRows as $row)
                                <div class="lgt-list-row">
                                    <strong>{{ $resolve($row, ['servicio', 'nombre', 'parada']) }}</strong>
                                    <span>{{ $resolve($row, ['estado', 'status'], 'Sin estado') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

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
        </div>
    </section>
</div>
@endsection
