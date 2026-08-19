@extends('layouts.app')

@section('title', 'RQ Mina - Proserge')

@php
$items = $data['items'] ?? [];
$minaOptions = $data['minaOptions'] ?? [];
$lugarOptions = $data['lugarOptions'] ?? [];
$estadoOptions = $data['estadoOptions'] ?? [];
$creadores = $data['creadores'] ?? [];
$activeFilters = $data['filters'] ?? [];
$pg = $data['pagination'] ?? ['current_page' => 1, 'total_pages' => 1, 'per_page' => 10, 'total' => count($items)];
$baseQuery = request()->except(['page', 'per_page']);
$baseUrl = route('rq-mina.index');
$rqPermissions = session('user.permissions', []);
$canCreateRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'crear');
$canEditRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'editar');
$canDuplicateRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'duplicar');
$canSendRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'enviar');
$canDeleteRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'eliminar');

$calcPuestos = static function (array $detalle): int {
    return count($detalle);
};

$calcTotal = static function (array $detalle): int {
    return (int) array_sum(array_column($detalle, 'cantidad'));
};

$calcBackup = static function (array $detalle): int {
    return (int) array_sum(array_column($detalle, 'cantidad_backup'));
};

$calcTotalSolicitado = static function (array $detalle): int {
    $total = (int) array_sum(array_column($detalle, 'cantidad_total'));

    return $total > 0 ? $total : (int) array_sum(array_map(
        static fn (array $linea): int => (int) ($linea['cantidad'] ?? 0) + (int) ($linea['cantidad_backup'] ?? 0),
        $detalle
    ));
};

$calcTransporteTotal = static function (array $transporte): int {
    return (int) array_sum(array_column($transporte, 'cantidad'));
};

$visibleItems = collect($items);
$totalRegistros = (int) ($pg['total'] ?? count($items));
$rqMetrics = [
    [
        'label' => 'Total de paradas',
        'value' => $totalRegistros,
        'hint' => 'Segun filtros',
    ],
    [
        'label' => 'Borradores visibles',
        'value' => $visibleItems->filter(fn (array $rq): bool => strtoupper((string) ($rq['estado'] ?? '')) === 'BORRADOR')->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Enviadas visibles',
        'value' => $visibleItems->filter(fn (array $rq): bool => strtoupper((string) ($rq['estado'] ?? '')) === 'ENVIADO')->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Con plan operativo',
        'value' => $visibleItems->filter(fn (array $rq): bool => count($rq['plan_operativo'] ?? []) > 0 || count($rq['plans'] ?? []) > 0)->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Sin plan operativo',
        'value' => $visibleItems->filter(fn (array $rq): bool => count($rq['plan_operativo'] ?? []) === 0 && count($rq['plans'] ?? []) === 0)->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Con transporte',
        'value' => $visibleItems->filter(fn (array $rq): bool => $calcTransporteTotal($rq['transporte'] ?? []) > 0)->count(),
        'hint' => 'Pagina actual',
    ],
];

$hasActiveFilters = collect($activeFilters)->filter(fn ($value): bool => trim((string) $value) !== '')->isNotEmpty();

$filterChipUrl = static function (string $key) use ($baseUrl, $baseQuery): string {
    $query = $baseQuery;
    unset($query[$key], $query['page']);
    $query['page'] = 1;

    return $baseUrl . '?' . http_build_query(array_filter($query, fn ($value) => $value !== null && $value !== ''));
};

$filterChips = [];
if (!empty($activeFilters['q'])) {
    $filterChips[] = ['label' => 'Buscar: '.$activeFilters['q'], 'url' => $filterChipUrl('q')];
}
if (!empty($activeFilters['mina_id'])) {
    $minaLabel = collect($minaOptions)->firstWhere('id', $activeFilters['mina_id'])['nombre'] ?? $activeFilters['mina_id'];
    $filterChips[] = ['label' => 'Mina: '.$minaLabel, 'url' => $filterChipUrl('mina_id')];
}
if (!empty($activeFilters['estado'])) {
    $filterChips[] = ['label' => 'Estado: '.ucfirst((string) $activeFilters['estado']), 'url' => $filterChipUrl('estado')];
}
if (!empty($activeFilters['created_by_usuario_id'])) {
    $creatorLabel = collect($creadores)->firstWhere('id', $activeFilters['created_by_usuario_id'])['nombre'] ?? $activeFilters['created_by_usuario_id'];
    $filterChips[] = ['label' => 'Creador: '.$creatorLabel, 'url' => $filterChipUrl('created_by_usuario_id')];
}
foreach (['fecha_inicio_desde' => 'Inicio desde', 'fecha_inicio_hasta' => 'Inicio hasta', 'fecha_fin_desde' => 'Fin desde', 'fecha_fin_hasta' => 'Fin hasta'] as $filterKey => $filterLabel) {
    if (!empty($activeFilters[$filterKey])) {
        $filterChips[] = ['label' => $filterLabel.': '.$activeFilters[$filterKey], 'url' => $filterChipUrl($filterKey)];
    }
}
@endphp

@section('content')
<div class="rq-mina-page">
    <div class="page-header-custom">
        <div>
            <h1 class="page-title">RQ Mina</h1>
            <p class="page-subtitle">Requerimientos de personal y planificacion de paradas.</p>
        </div>
        @if($canCreateRq)
            <a href="{{ route('rq-mina.create') }}" class="btn-nuevoRQ">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Nueva Parada
            </a>
        @endif
    </div>

    <section class="rq-mina-mobile-lock" aria-labelledby="rqMinaMobileTitle">
        <div class="rq-mina-mobile-lock__icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="4" y="4" width="16" height="12" rx="2"></rect>
                <path d="M8 20h8"></path>
                <path d="M12 16v4"></path>
            </svg>
        </div>
        <div class="rq-mina-mobile-lock__content">
            <span class="rq-mina-mobile-lock__eyebrow">Pantalla operativa</span>
            <h2 id="rqMinaMobileTitle">RQ Mina requiere una pantalla grande</h2>
            <p>
                Esta vista contiene plan operativo, pedidos de personal, transporte, acciones y tablas amplias.
                Para evitar errores de carga o envio, usala desde una laptop, PC o tablet en horizontal.
            </p>
            <div class="rq-mina-mobile-lock__tips">
                <span>En celular no se habilitan acciones de creacion, edicion, envio ni eliminacion.</span>
                <span>Si necesitas revisar una parada, abre esta misma direccion desde un dispositivo amplio.</span>
            </div>
        </div>
    </section>

    <div class="rq-mina-desktop-workspace">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="filters-bar">
        <form method="GET" action="{{ route('rq-mina.index') }}" class="filters-form" id="rqMinaFiltersForm">
            <input type="hidden" name="page" value="{{ (int) request('page', 1) }}" data-reset-page>
            <input type="hidden" name="per_page" value="{{ (int) request('per_page', ($pg['per_page'] ?? 10)) }}">

                    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                        <div class="w-full md:max-w-3xl">
                            <label class="filter-label">Buscador general</label>
                            <input
                                type="text"
                                name="q"
                                id="rqMinaSearch"
                                class="filter-input"
                                value="{{ $activeFilters['q'] ?? '' }}"
                                placeholder="Buscar por lugar, parada, área, creador o estado..."
                                autocomplete="off"
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('rq-mina.index') }}" class="btn-filter-outline whitespace-nowrap">Limpiar</a>
                            <button type="button" id="rqMinaToggleFilters" class="btn-filter-toggle" aria-expanded="false" aria-label="Mostrar filtros" title="Mostrar filtros">
                                <span id="rqMinaToggleArrow" aria-hidden="true">&#9660;</span>
                            </button>
                        </div>
                    </div>

            <div class="filters-head">
                <span>Filtros de búsqueda</span>
            </div>

            <div id="rqMinaFiltersBody" style="display:none;">
                <div class="flex flex-col gap-3">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="filter-group">
                            <label class="filter-label">Mina</label>
                            <select name="mina_id" class="filter-select" data-auto-submit>
                                <option value="">Todas las minas</option>
                                @foreach($minaOptions as $m)
                                    <option value="{{ $m['id'] ?? '' }}" {{ ($activeFilters['mina_id'] ?? '') === ($m['id'] ?? '') ? 'selected' : '' }}>{{ $m['nombre'] ?? '-' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Estado</label>
                            <select name="estado" class="filter-select" data-auto-submit>
                                <option value="">Todos los estados</option>
                                @foreach($estadoOptions as $e)
                                    <option value="{{ $e }}" {{ ($activeFilters['estado'] ?? '') === $e ? 'selected' : '' }}>{{ ucfirst($e) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Creador</label>
                            <select name="created_by_usuario_id" class="filter-select" data-auto-submit>
                                <option value="">Todos los creadores</option>
                                @foreach($creadores as $c)
                                    <option value="{{ $c['id'] ?? '' }}" {{ ($activeFilters['created_by_usuario_id'] ?? '') === ($c['id'] ?? '') ? 'selected' : '' }}>{{ $c['nombre'] ?? '-' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha inicio desde</label>
                            <input type="date" name="fecha_inicio_desde" class="filter-input" value="{{ $activeFilters['fecha_inicio_desde'] ?? '' }}" data-auto-submit>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha inicio hasta</label>
                            <input type="date" name="fecha_inicio_hasta" class="filter-input" value="{{ $activeFilters['fecha_inicio_hasta'] ?? '' }}" data-auto-submit>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha fin desde</label>
                            <input type="date" name="fecha_fin_desde" class="filter-input" value="{{ $activeFilters['fecha_fin_desde'] ?? '' }}" data-auto-submit>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Fecha fin hasta</label>
                            <input type="date" name="fecha_fin_hasta" class="filter-input" value="{{ $activeFilters['fecha_fin_hasta'] ?? '' }}" data-auto-submit>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rango rápido:</span>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="today">Hoy</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="week">Esta semana</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="month">Este mes</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="last30">Últimos 30 días</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-range="clear">Limpiar fecha</button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estado rápido:</span>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="">Todos</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="borrador">Borrador</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="enviado">Enviado</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="cerrado">Cerrado</button>
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="cancelado">Cancelado</button>
                    </div>
                </div>
            </div>

            @if(!empty($filterChips))
                <div class="rq-filter-chips" aria-label="Filtros activos">
                    @foreach($filterChips as $chip)
                        <a href="{{ $chip['url'] }}" class="rq-filter-chip">
                            <span>{{ $chip['label'] }}</span>
                            <span aria-hidden="true">&times;</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </form>
    </div>

    <section class="rq-metrics" aria-label="Indicadores rapidos de RQ Mina">
        @foreach($rqMetrics as $metric)
            <div class="rq-metric">
                <span class="rq-metric-label">{{ $metric['label'] }}</span>
                <strong>{{ number_format((int) $metric['value']) }}</strong>
                <small>{{ $metric['hint'] }}</small>
            </div>
        @endforeach
    </section>

    <div class="card">
        <div class="card-header-list">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;flex-direction:column;">
                    <h2 class="card-title-list">Listado de RQ Mina</h2>
                    <span class="card-badge">{{ (int) ($pg['total'] ?? count($items)) }} registros</span>
                </div>

            </div>
        </div>

        @if(empty($items))
            <div class="empty-state">
                @if($hasActiveFilters)
                    <h3>No encontramos resultados</h3>
                    <p>Prueba cambiando o limpiando los filtros seleccionados.</p>
                    <a href="{{ route('rq-mina.index') }}" class="btn-row btn-row-outline empty-state-action">Limpiar filtros</a>
                @else
                    <h3>No hay paradas registradas</h3>
                    <p>Registra una nueva parada para comenzar el requerimiento de personal y el plan operativo.</p>
                    @if($canCreateRq)
                        <a href="{{ route('rq-mina.create') }}" class="btn-row btn-send empty-state-action">Nueva parada</a>
                    @endif
                @endif
            </div>
        @else
            <div class="rq-card-list">
                @foreach($items as $rq)
                    @php
                        $detalle = $rq['detalle'] ?? [];
                        $planOperativo = $rq['plan_operativo'] ?? [];
                        $plans = $rq['plans'] ?? [];
                        $planGrupos = count($planOperativo);
                        $planActividades = collect($planOperativo)->sum(fn ($g) => count($g['actividades'] ?? []));
                        $planCount = count($plans);
                        $planArchivado = collect($plans)->contains(fn (array $plan): bool => strtoupper((string) ($plan['estado'] ?? '')) === 'ARCHIVADO');
                        $transporteDetalle = $rq['transporte'] ?? [];
                        $transporteTotal = $calcTransporteTotal($transporteDetalle);
                        $transporteResumen = collect($transporteDetalle)
                            ->map(static function (array $linea): ?string {
                                $nombre = trim((string) ($linea['transporte'] ?? ''));
                                $cantidad = (int) ($linea['cantidad'] ?? 0);

                                return $nombre !== '' && $cantidad > 0 ? $cantidad.' x '.$nombre : null;
                            })
                            ->filter()
                            ->values();
                        $transporteResumenTexto = $transporteResumen->take(3)->implode(' / ');
                        $transporteRestantes = max(0, $transporteResumen->count() - 3);
                        $isBorrador = strtoupper((string) ($rq['estado'] ?? '')) === 'BORRADOR';
                        $estadoCss = strtolower(str_replace([' ', '_'], '-', (string) ($rq['estado'] ?? 'borrador')));
                        $estadoLabel = ucfirst(strtolower(str_replace('_', ' ', (string) ($rq['estado'] ?? 'borrador'))));
                        $fechaInicioRaw = (string) ($rq['fecha_inicio'] ?? '');
                        $fechaFinRaw = (string) ($rq['fecha_fin'] ?? '');
                        $fechaInicioFmt = $fechaInicioRaw !== ''
                            ? strtoupper(\Carbon\Carbon::parse($fechaInicioRaw)->locale('es')->translatedFormat('d M Y'))
                            : '-';
                        $fechaFinFmt = $fechaFinRaw !== ''
                            ? strtoupper(\Carbon\Carbon::parse($fechaFinRaw)->locale('es')->translatedFormat('d M Y'))
                            : '-';
                        $semanaInicio = $fechaInicioRaw !== ''
                            ? \Carbon\Carbon::parse($fechaInicioRaw)->isoWeek()
                            : null;
                        $semanaFin = $fechaFinRaw !== ''
                            ? \Carbon\Carbon::parse($fechaFinRaw)->isoWeek()
                            : null;
                        $semanaLabel = $semanaInicio
                            ? ($semanaFin && $semanaFin !== $semanaInicio ? 'Sem. '.$semanaInicio.'-'.$semanaFin : 'Sem. '.$semanaInicio)
                            : '-';
                        $destinoNombre = $rq['lugar'] ?? $rq['destino_nombre'] ?? $rq['mina'] ?? '-';
                        $tituloParada = trim((string) $destinoNombre.' - '.(string) ($rq['area'] ?? ''));
                        $totalTitular = $calcTotal($detalle);
                        $totalBackup = $calcBackup($detalle);
                        $totalSolicitado = $calcTotalSolicitado($detalle);
                        $menuId = 'rq-actions-'.$rq['id'];
                        $hasOverflowActions = $canDuplicateRq || ($isBorrador && $canSendRq) || $canDeleteRq;
                    @endphp

                    <article class="rq-card" aria-labelledby="rq-title-{{ $rq['id'] }}">
                        <div class="rq-card-topline">
                            <div class="rq-card-badges">
                                <span class="estado-badge {{ $estadoCss }}">{{ $estadoLabel }}</span>
                                <span class="rq-location">{{ $destinoNombre }}</span>
                                @if($planArchivado)
                                    <span class="rq-soft-badge">Plan archivado</span>
                                @endif
                            </div>
                            <span class="rq-week-badge">{{ $semanaLabel }}</span>
                        </div>

                        <div class="rq-card-main">
                            <div class="rq-card-identity">
                                <h3 id="rq-title-{{ $rq['id'] }}">{{ $tituloParada !== '-' ? $tituloParada : 'Parada sin nombre' }}</h3>
                                <p>{{ $rq['area'] ?? 'Area no definida' }}</p>
                                <div class="rq-date-range" aria-label="Rango de fechas">
                                    <span>Inicio {{ $fechaInicioFmt }}</span>
                                    <span>Fin {{ $fechaFinFmt }}</span>
                                </div>
                            </div>

                            <div class="rq-card-summaries">
                                <div class="rq-summary {{ $planGrupos > 0 || $planCount > 0 ? '' : 'is-warning' }}">
                                    <span>Plan operativo</span>
                                    @if($planGrupos > 0 || $planCount > 0)
                                        <strong>{{ max(1, $planCount) }} plan · {{ $planGrupos }} grupo(s)</strong>
                                        <small>{{ $planActividades }} actividad(es)</small>
                                    @else
                                        <strong>Sin plan operativo</strong>
                                        <small>Esta parada todavia no tiene un plan operativo.</small>
                                    @endif
                                </div>

                                <div class="rq-summary">
                                    <span>Personal solicitado</span>
                                    <strong>{{ $calcPuestos($detalle) }} cargo(s) · {{ $totalSolicitado }} persona(s)</strong>
                                    <small>{{ $totalTitular }} titular(es){{ $totalBackup > 0 ? ' · '.$totalBackup.' backup' : '' }}</small>
                                </div>

                                <div class="rq-summary {{ $transporteTotal > 0 ? '' : 'is-muted' }}">
                                    <span>Transporte</span>
                                    @if($transporteTotal > 0)
                                        <strong>{{ $transporteTotal }} unidad(es)</strong>
                                        <small>{{ $transporteResumenTexto }}{{ $transporteRestantes > 0 ? ' + '.$transporteRestantes.' mas' : '' }}</small>
                                    @else
                                        <strong>Sin transporte</strong>
                                        <small>Transporte no planificado.</small>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="rq-card-footer">
                            <div class="rq-responsibles">
                                <span><strong>Creado por:</strong> {{ $rq['creador'] ?? '-' }}</span>
                                <span><strong>Supervisor:</strong> {{ $rq['supervisor']['nombre'] ?? 'Supervisor pendiente' }}</span>
                            </div>

                            <div class="row-actions">
                                @if($canEditRq)
                                    <a href="{{ route('rq-mina.plan', $rq['id']) }}" class="btn-row btn-send">Abrir plan</a>
                                @endif
                                <a href="{{ route('rq-mina.dashboard', $rq['id']) }}" class="btn-row btn-row-outline">Dashboard</a>
                                <a href="{{ route('rq-mina.show', $rq['id']) }}" class="btn-row btn-row-outline">Ver</a>
                                @if($canEditRq)
                                    <a href="{{ route('rq-mina.edit', $rq['id']) }}" class="btn-row btn-row-outline">Editar</a>
                                @endif

                                @if($hasOverflowActions)
                                    <div class="rq-actions-menu">
                                        <button
                                            type="button"
                                            class="btn-row btn-row-outline rq-actions-toggle"
                                            data-rq-actions-toggle
                                            aria-expanded="false"
                                            aria-controls="{{ $menuId }}"
                                        >
                                            Mas
                                        </button>
                                        <div class="rq-actions-dropdown" id="{{ $menuId }}" data-rq-actions-menu hidden>
                                            @if($canDuplicateRq)
                                                <a href="{{ route('rq-mina.create', ['copy_from' => $rq['id']]) }}" class="rq-actions-item">Copiar</a>
                                            @endif

                                            @if($isBorrador && $canSendRq)
                                                <form method="POST" action="{{ route('rq-mina.enviar', $rq['id']) }}" onsubmit="return confirm('¿Enviar este RQ?');">
                                                    @csrf
                                                    <button type="submit" class="rq-actions-item">Enviar</button>
                                                </form>
                                            @endif

                                            @if($canDeleteRq)
                                                <form method="POST" action="{{ route('rq-mina.destroy', $rq['id']) }}" onsubmit="return confirm('¿Eliminar definitivamente este RQ Mina? Se borraran su RQ Proserge, Man Power, herramientas, asistencia, supervisores y registros operativos relacionados. No se borraran trabajadores, minas ni catalogos maestros. Esta accion no se puede deshacer.');">
                                                    @csrf
                                                    <button type="submit" class="rq-actions-item is-danger">Eliminar</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            @php
                $totalPages = (int) ($pg['total_pages'] ?? 1);
                $currentPage = (int) ($pg['current_page'] ?? 1);
                $perPage = (int) ($pg['per_page'] ?? 10);
                $total = (int) ($pg['total'] ?? count($items));
                $startItem = $total > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
                $endItem = min($currentPage * $perPage, $total);
                $maxVis = 7;
                $startPage = max(2, $currentPage - floor(($maxVis - 3) / 2));
                $endPage = min($totalPages - 1, $currentPage + floor(($maxVis - 3) / 2));
            @endphp

            <div class="table-footer">
                <div class="table-footer-top">
                    <div class="page-size">
                        <span>Mostrar</span>
                        <select id="rqMinaPageSize" onchange="changeRqMinaPageSize(this.value)">
                            @foreach([10, 25, 50, 100] as $opt)
                                <option value="{{ $opt }}" {{ $perPage === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                            @endforeach
                        </select>
                        <span>registros</span>
                    </div>

                    <div class="page-summary">
                        {{ $total === 0 ? '0 resultados' : 'Mostrando ' . $startItem . ' - ' . $endItem . ' de ' . $total }}
                    </div>

                </div>

                <div class="pagination-row">
                @if($totalPages > 1)
                    <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => max(1, $currentPage - 1), 'per_page' => $perPage])) }}" @if($currentPage === 1) aria-disabled="true" style="pointer-events:none;opacity:.5;" @endif>&lsaquo;</a>
                    <a class="{{ $currentPage === 1 ? 'active' : '' }}" href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => 1, 'per_page' => $perPage])) }}">1</a>

                    @if($startPage > 2)
                        <span>...</span>
                    @endif

                    @for($i = $startPage; $i <= $endPage; $i++)
                        <a class="{{ $currentPage === $i ? 'active' : '' }}" href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => $i, 'per_page' => $perPage])) }}">{{ $i }}</a>
                    @endfor

                    @if($endPage < $totalPages - 1)
                        <span>...</span>
                    @endif

                    @if($totalPages > 1)
                        <a class="{{ $currentPage === $totalPages ? 'active' : '' }}" href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => $totalPages, 'per_page' => $perPage])) }}">{{ $totalPages }}</a>
                    @endif
                    <a href="{{ $baseUrl }}?{{ http_build_query(array_merge($baseQuery, ['page' => min($totalPages, $currentPage + 1), 'per_page' => $perPage])) }}" @if($currentPage === $totalPages) aria-disabled="true" style="pointer-events:none;opacity:.5;" @endif>&rsaquo;</a>
                @endif
                </div>
            </div>
        @endif
    </div>
    </div>
</div>

<!-- Modal Nuevo RQ Mina -->
<div class="rq-mina-desktop-workspace">
<div class="modalrq-overlay" id="modalRQ" style="display: none;">
    <div class="modalrq-container">
        <div class="modalrq-header">
            <h2 class="modalrq-title" id="modalRQTitle">Nuevo RQ Mina</h2>
            <button type="button" class="modalrq-close" onclick="closeModalRQ()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form id="formRQ" class="modalrq-body" method="POST" action="{{ route('rq-mina.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethodOverride" value="POST">
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Lugar *</label>
                        <select name="destino_id" id="inputDestino" class="form-select" required>
                            <option value="">Seleccionar lugar</option>
                            @foreach($lugarOptions as $lugar)
                            @php $value = ($lugar['tipo'] ?? '') . '|' . ($lugar['id'] ?? ''); @endphp
                            <option value="{{ $value }}">{{ $lugar['label'] ?? (($lugar['tipo'] ?? 'Lugar') . ' - ' . ($lugar['nombre'] ?? '-')) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Área *</label>
                        <input type="text" name="area" id="inputArea" class="form-input" placeholder="Ej: C2, Operación Planta" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Fecha inicio *</label>
                        <input type="date" name="fecha_inicio" id="inputFechaInicio" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha fin *</label>
                        <input type="date" name="fecha_fin" id="inputFechaFin" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" id="inputObs" class="form-textarea" rows="3" placeholder="Descripción del requerimiento..."></textarea>
                </div>
            </div>
            <div class="form-section">
                @include('rq-mina.partials.supervisor-selector', [
                    'selectorId' => 'rqModalSupervisorSelector',
                    'selectedSupervisor' => null,
                    'title' => 'Supervisor a cargo de herramientas',
                ])
                <div style="margin-top: 14px;">
                    @include('rq-mina.partials.supervisor-selector', [
                        'selectorId' => 'rqModalSupervisorPetsSelector',
                        'selectedSupervisor' => null,
                        'title' => 'Supervisor a cargo de PETS',
                        'fieldName' => 'supervisor_pets_id',
                        'emptyText' => 'Sin supervisor PETS seleccionado.',
                    ])
                </div>
            </div>
            <div class="form-section">
                @include('rq-mina.partials.plan-operativo-editor', [
                    'editorId' => 'rqModalPlanOperativoEditor',
                    'planOperativo' => [],
                ])
            </div>
            <div class="detalle-section">
                <div class="detalle-header">
                    <h3 class="detalle-title">Detalle por puesto / cantidad</h3>
                    <button type="button" class="btn-addfila" onclick="addFila()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                       Agregar fila
                    </button>
                </div>
                <div class="detalle-lista" id="listaDetalle">
                    <div class="fila-detalle">
                        <input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto">
                        <input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="1">
                        <button type="button" class="btn-removefila" onclick="this.closest('.fila-detalle').remove()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="detalle-section detalle-section-transporte">
                <div class="detalle-header">
                    <h3 class="detalle-title">Detalle de transporte</h3>
                    <button type="button" class="btn-addfila" onclick="addFilaTransporte()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                       Agregar fila
                    </button>
                </div>
                <div class="detalle-lista" id="listaTransporte">
                    <div class="fila-detalle">
                        <input type="text" name="transporte[0][transporte]" class="input-puesto" placeholder="Transporte">
                        <input type="number" name="transporte[0][cantidad]" class="input-cantidad" placeholder="Cantidad" min="1" value="1">
                        <button type="button" class="btn-removefila" onclick="this.closest('.fila-detalle').remove()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <div class="modalrq-footer">
            <button type="button" class="btn-cancelarrq" onclick="closeModalRQ()">Cancelar</button>
            <button type="submit" form="formRQ" class="btn-crear" id="modalRQSubmit">Crear BORRADOR</button>
        </div>
    </div>
</div>
</div>

<script>
const rqData = @json($items);
const rqStoreUrl = @json(route('rq-mina.store'));
const rqUpdateUrlTemplate = @json(route('rq-mina.update', ['id' => '__ID__']));
let transporteRowCount = 1;

function createDetalleRow(puesto, cantidad) {
    return '<div class="fila-detalle"><input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto" value="' + (puesto || '') + '"><input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="' + (cantidad || 1) + '"><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>';
}

function createTransporteRow(transporte, cantidad) {
    const index = transporteRowCount++;
    return '<div class="fila-detalle"><input type="text" name="transporte[' + index + '][transporte]" class="input-puesto" placeholder="Transporte" value="' + (transporte || '') + '"><input type="number" name="transporte[' + index + '][cantidad]" class="input-cantidad" placeholder="Cantidad" min="1" value="' + (cantidad || 1) + '"><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>';
}

function destinoValue(rq) {
    if (rq && rq.destino_tipo && rq.destino_id) {
        return String(rq.destino_tipo) + '|' + String(rq.destino_id);
    }

    return rq && rq.mina_id ? 'MINA|' + String(rq.mina_id) : '';
}

function openModalRQ(mode = 'create', rqId = null) {
    document.getElementById('modalRQ').style.display = 'flex';
    const form = document.getElementById('formRQ');
    const title = document.getElementById('modalRQTitle');
    const submit = document.getElementById('modalRQSubmit');
    const methodOverride = document.getElementById('formMethodOverride');
    const detalle = document.getElementById('listaDetalle');
    const transporte = document.getElementById('listaTransporte');

    form.reset();
    detalle.innerHTML = createDetalleRow('', 1);
    transporteRowCount = 0;
    transporte.innerHTML = createTransporteRow('', 1);
    if (window.rqMinaSupervisorSelectorSet) {
        window.rqMinaSupervisorSelectorSet('rqModalSupervisorSelector', null);
        window.rqMinaSupervisorSelectorSet('rqModalSupervisorPetsSelector', null);
    }
    if (window.rqMinaPlanEditorSet) {
        window.rqMinaPlanEditorSet('rqModalPlanOperativoEditor', []);
    }

    if (mode === 'edit' && rqId) {
        const rq = rqData.find(item => String(item.id) === String(rqId));
        if (!rq) return;

        title.textContent = 'Editar RQ Mina';
        submit.textContent = 'Guardar Cambios';
        form.action = rqUpdateUrlTemplate.replace('__ID__', String(rq.id));
        methodOverride.value = 'PUT';

        document.getElementById('inputDestino').value = destinoValue(rq);
        document.getElementById('inputArea').value = rq.area || '';
        document.getElementById('inputFechaInicio').value = rq.fecha_inicio || '';
        document.getElementById('inputFechaFin').value = rq.fecha_fin || '';
        document.getElementById('inputObs').value = rq.observaciones || '';

        detalle.innerHTML = '';
        const rqDetalle = Array.isArray(rq.detalle) && rq.detalle.length > 0 ? rq.detalle : [{ puesto: '', cantidad: 1 }];
        rqDetalle.forEach(item => {
            detalle.insertAdjacentHTML('beforeend', createDetalleRow(item.puesto, item.cantidad));
        });

        transporte.innerHTML = '';
        transporteRowCount = 0;
        const rqTransporte = Array.isArray(rq.transporte) && rq.transporte.length > 0 ? rq.transporte : [{ transporte: '', cantidad: 1 }];
        rqTransporte.forEach(item => {
            transporte.insertAdjacentHTML('beforeend', createTransporteRow(item.transporte, item.cantidad));
        });
        if (window.rqMinaSupervisorSelectorSet) {
            window.rqMinaSupervisorSelectorSet('rqModalSupervisorSelector', rq.supervisor || null);
            window.rqMinaSupervisorSelectorSet('rqModalSupervisorPetsSelector', rq.supervisor_pets || null);
        }
        if (window.rqMinaPlanEditorSet) {
            window.rqMinaPlanEditorSet('rqModalPlanOperativoEditor', rq.plan_operativo || []);
        }
    } else if (mode === 'copy' && rqId) {
        const rq = rqData.find(item => String(item.id) === String(rqId));
        if (!rq) return;

        title.textContent = 'Copiar RQ Mina';
        submit.textContent = 'Crear BORRADOR';
        form.action = rqStoreUrl;
        methodOverride.value = 'POST';

        document.getElementById('inputDestino').value = destinoValue(rq);
        document.getElementById('inputArea').value = rq.area || '';
        document.getElementById('inputFechaInicio').value = rq.fecha_inicio || '';
        document.getElementById('inputFechaFin').value = rq.fecha_fin || '';
        document.getElementById('inputObs').value = rq.observaciones || '';

        detalle.innerHTML = '';
        const rqDetalle = Array.isArray(rq.detalle) && rq.detalle.length > 0 ? rq.detalle : [{ puesto: '', cantidad: 1 }];
        rqDetalle.forEach(item => {
            detalle.insertAdjacentHTML('beforeend', createDetalleRow(item.puesto, item.cantidad));
        });

        transporte.innerHTML = '';
        transporteRowCount = 0;
        const rqTransporte = Array.isArray(rq.transporte) && rq.transporte.length > 0 ? rq.transporte : [{ transporte: '', cantidad: 1 }];
        rqTransporte.forEach(item => {
            transporte.insertAdjacentHTML('beforeend', createTransporteRow(item.transporte, item.cantidad));
        });
        if (window.rqMinaSupervisorSelectorSet) {
            window.rqMinaSupervisorSelectorSet('rqModalSupervisorSelector', rq.supervisor || null);
            window.rqMinaSupervisorSelectorSet('rqModalSupervisorPetsSelector', rq.supervisor_pets || null);
        }
        if (window.rqMinaPlanEditorSet) {
            window.rqMinaPlanEditorSet('rqModalPlanOperativoEditor', rq.plan_operativo || []);
        }
    } else {
        title.textContent = 'Nuevo RQ Mina';
        submit.textContent = 'Crear BORRADOR';
        form.action = rqStoreUrl;
        methodOverride.value = 'POST';
    }
}
function closeModalRQ() {
    document.getElementById('modalRQ').style.display = 'none';
}
function addFila() {
    const container = document.getElementById('listaDetalle');
    const row = document.createElement('div');
    row.className = 'fila-detalle';
    row.innerHTML = '<input type="text" name="puesto[]" class="input-puesto" placeholder="Puesto"><input type="number" name="cantidad[]" class="input-cantidad" placeholder="Cantidad" min="1" value="1"><button type="button" class="btn-removefila" onclick="this.closest(\'.fila-detalle\').remove()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    container.appendChild(row);
}

function addFilaTransporte() {
    const container = document.getElementById('listaTransporte');
    container.insertAdjacentHTML('beforeend', createTransporteRow('', 1));
}
function saveRQ(e) {
    // Reserved for future custom validation hooks.
}

function initializeRQMinaAutoFilters() {
    const form = document.getElementById('rqMinaFiltersForm');
    if (!form) return;

    const pageInput = form.querySelector('[data-reset-page]');
    const searchInput = document.getElementById('rqMinaSearch');
    const autoSubmitInputs = form.querySelectorAll('[data-auto-submit]');
    const rangeButtons = form.querySelectorAll('[data-range]');
    const statusButtons = form.querySelectorAll('[data-status]');
    const estadoSelect = form.querySelector('select[name="estado"]');
    const fechaInicioDesde = form.querySelector('input[name="fecha_inicio_desde"]');
    const fechaInicioHasta = form.querySelector('input[name="fecha_inicio_hasta"]');
    const fechaFinDesde = form.querySelector('input[name="fecha_fin_desde"]');
    const fechaFinHasta = form.querySelector('input[name="fecha_fin_hasta"]');

    const resetPage = () => {
        if (pageInput) {
            pageInput.value = '1';
        }
    };

    form.addEventListener('submit', () => {
        resetPage();
    });

    const submitNow = () => {
        form.submit();
    };

    let searchDebounceTimer = null;
    const submitDebounced = () => {
        if (searchDebounceTimer) {
            clearTimeout(searchDebounceTimer);
        }
        searchDebounceTimer = setTimeout(() => {
            submitNow();
        }, 300);
    };

    const formatDateInput = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            resetPage();
            submitDebounced();
        });
    }

    autoSubmitInputs.forEach((input) => {
        input.addEventListener('change', () => {
            resetPage();
            submitNow();
        });
    });

    rangeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const now = new Date();
            const today = formatDateInput(now);

            if (button.dataset.range === 'today') {
                if (fechaInicioDesde) fechaInicioDesde.value = today;
                if (fechaInicioHasta) fechaInicioHasta.value = today;
                if (fechaFinDesde) fechaFinDesde.value = today;
                if (fechaFinHasta) fechaFinHasta.value = today;
            }

            if (button.dataset.range === 'week') {
                const start = new Date(now);
                const day = start.getDay();
                const diff = day === 0 ? -6 : 1 - day;
                start.setDate(start.getDate() + diff);

                const end = new Date(start);
                end.setDate(start.getDate() + 6);

                if (fechaInicioDesde) fechaInicioDesde.value = formatDateInput(start);
                if (fechaInicioHasta) fechaInicioHasta.value = formatDateInput(end);
                if (fechaFinDesde) fechaFinDesde.value = formatDateInput(start);
                if (fechaFinHasta) fechaFinHasta.value = formatDateInput(end);
            }

            if (button.dataset.range === 'month') {
                const start = new Date(now.getFullYear(), now.getMonth(), 1);
                const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);

                if (fechaInicioDesde) fechaInicioDesde.value = formatDateInput(start);
                if (fechaInicioHasta) fechaInicioHasta.value = formatDateInput(end);
                if (fechaFinDesde) fechaFinDesde.value = formatDateInput(start);
                if (fechaFinHasta) fechaFinHasta.value = formatDateInput(end);
            }

            if (button.dataset.range === 'last30') {
                const start = new Date(now);
                start.setDate(start.getDate() - 29);

                if (fechaInicioDesde) fechaInicioDesde.value = formatDateInput(start);
                if (fechaInicioHasta) fechaInicioHasta.value = today;
                if (fechaFinDesde) fechaFinDesde.value = formatDateInput(start);
                if (fechaFinHasta) fechaFinHasta.value = today;
            }

            if (button.dataset.range === 'clear') {
                if (fechaInicioDesde) fechaInicioDesde.value = '';
                if (fechaInicioHasta) fechaInicioHasta.value = '';
                if (fechaFinDesde) fechaFinDesde.value = '';
                if (fechaFinHasta) fechaFinHasta.value = '';
            }

            resetPage();
            submitNow();
        });
    });

    statusButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (estadoSelect) {
                estadoSelect.value = button.dataset.status || '';
            }

            resetPage();
            submitNow();
        });
    });
}

function initializeRQMinaActionMenus() {
    const toggles = document.querySelectorAll('[data-rq-actions-toggle]');
    const closeMenus = () => {
        document.querySelectorAll('[data-rq-actions-menu]').forEach((menu) => {
            menu.hidden = true;
        });
        toggles.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', 'false');
        });
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const menuId = toggle.getAttribute('aria-controls');
            const menu = menuId ? document.getElementById(menuId) : null;
            if (!menu) return;

            const willOpen = menu.hidden;
            closeMenus();
            menu.hidden = !willOpen;
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

            if (willOpen) {
                const firstAction = menu.querySelector('a, button');
                if (firstAction) {
                    firstAction.focus({ preventScroll: true });
                }
            }
        });
    });

    document.querySelectorAll('[data-rq-actions-menu]').forEach((menu) => {
        menu.addEventListener('click', () => {
            closeMenus();
        });
    });

    document.addEventListener('click', closeMenus);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenus();
        }
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModalRQ(); });
document.getElementById('modalRQ').addEventListener('click', e => { if (e.target.id === 'modalRQ') closeModalRQ(); });

document.addEventListener('DOMContentLoaded', function () {
    initializeRQMinaAutoFilters();
    initializeRQMinaActionMenus();

    if (window.ProsergeUI) {
        window.ProsergeUI.initCollapsiblePanel({
            toggleButtonId: 'rqMinaToggleFilters',
            panelBodyId: 'rqMinaFiltersBody',
            iconElementId: 'rqMinaToggleArrow',
            expandedIcon: '\u25B2',
            collapsedIcon: '\u25BC',
            expandedLabel: 'Ocultar filtros',
            collapsedLabel: 'Mostrar filtros',
            storageKey: 'rq_mina_filters_state',
        });
    }
});

function goToRqMinaPage() {
    const input = document.getElementById('rqMinaPageJump');
    if (!input) return;

    const totalPages = Number(input.max || 1);
    const page = Math.max(1, Math.min(totalPages, Number(input.value || 1)));
    const params = new URLSearchParams(@json($baseQuery));
    params.set('page', String(page));
    params.set('per_page', String({{ (int) ($pg['per_page'] ?? 10) }}));
    window.location.href = '{{ $baseUrl }}?' + params.toString();
}

function changeRqMinaPageSize(size) {
    const params = new URLSearchParams(@json($baseQuery));
    params.set('page', '1');
    params.set('per_page', String(size || 10));
    window.location.href = '{{ $baseUrl }}?' + params.toString();
}

</script>

@endsection

