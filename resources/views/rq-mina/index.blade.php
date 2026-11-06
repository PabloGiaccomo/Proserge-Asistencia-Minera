@extends('layouts.app')

@section('title', 'RQ Mina - Proserge')

@php
$items = $data['items'] ?? [];
$minaOptions = $data['minaOptions'] ?? [];
$estadoOptions = $data['estadoOptions'] ?? [];
$creadores = $data['creadores'] ?? [];
$activeFilters = $data['filters'] ?? [];
$pg = $data['pagination'] ?? ['current_page' => 1, 'total_pages' => 1, 'per_page' => 10, 'total' => count($items)];
$baseQuery = request()->except(['page', 'per_page']);
$baseUrl = route('rq-mina.index');
$lista = array_filter($activeFilters, static fn ($value): bool => is_scalar($value) && $value !== '');
if (request()->has('page')) {
    $lista['page'] = (int) $pg['current_page'];
}
if (request()->has('per_page')) {
    $lista['per_page'] = (int) $pg['per_page'];
}
$rqPermissions = session('user.permissions', []);
$canCreateRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'crear');
$canEditRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'editar');
$canDuplicateRq = $canCreateRq && \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'duplicar');
$canDeleteRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'eliminar');
$estadoLabels = [
    'borrador' => 'Borrador',
    'enviado' => 'Pedido registrado en RR.HH.',
    'cerrado' => 'Cerrado',
    'cancelado' => 'Cancelado',
    'en_atencion' => 'En atención',
    'completado' => 'Completado',
];

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

$gruposNoArchivados = static function (array $rq): \Illuminate\Support\Collection {
    $archivados = collect($rq['plans'] ?? [])->where('estado', 'ARCHIVADO')->pluck('id')->all();

    return collect($rq['plan_operativo'] ?? [])->reject(
        static fn (array $grupo): bool => in_array($grupo['rq_mina_plan_id'] ?? '', $archivados, true)
    );
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
        'label' => 'Registradas en RR.HH.',
        'value' => $visibleItems->filter(fn (array $rq): bool => strtoupper((string) ($rq['estado'] ?? '')) === 'ENVIADO')->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Con actividades',
        'value' => $visibleItems->filter(fn (array $rq): bool => $gruposNoArchivados($rq)->contains(fn (array $grupo): bool => !empty($grupo['actividades'])))->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Sin actividades',
        'value' => $visibleItems->reject(fn (array $rq): bool => $gruposNoArchivados($rq)->contains(fn (array $grupo): bool => !empty($grupo['actividades'])))->count(),
        'hint' => 'Pagina actual',
    ],
    [
        'label' => 'Con transporte solicitado',
        'value' => $visibleItems->filter(fn (array $rq): bool => $gruposNoArchivados($rq)->contains(fn (array $grupo): bool => !empty($grupo['transportes'])))->count(),
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
    $estadoFiltro = strtolower((string) $activeFilters['estado']);
    $filterChips[] = ['label' => 'Estado: '.($estadoLabels[$estadoFiltro] ?? $estadoFiltro), 'url' => $filterChipUrl('estado')];
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
            <a href="{{ route('rq-mina.create', ['lista' => $lista]) }}" class="btn-nuevoRQ">
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
                                    <option value="{{ $e }}" {{ ($activeFilters['estado'] ?? '') === $e ? 'selected' : '' }}>{{ $estadoLabels[$e] ?? ucfirst($e) }}</option>
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
                        <button type="button" class="btn-filter-outline !py-1.5 !px-3" data-status="enviado">Pedido registrado en RR.HH.</button>
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
                        <a href="{{ route('rq-mina.create', ['lista' => $lista]) }}" class="btn-row btn-send empty-state-action">Nueva parada</a>
                    @endif
                @endif
            </div>
        @else
            <div class="rq-card-list">
                @foreach($items as $rq)
                    @php
                        $detalle = $rq['detalle'] ?? [];
                        $plans = $rq['plans'] ?? [];
                        $planOperativo = $gruposNoArchivados($rq);
                        $planGrupos = $planOperativo->count();
                        $planActividades = $planOperativo->sum(fn ($g) => count($g['actividades'] ?? []));
                        $planCount = count($plans);
                        $planArchivado = collect($plans)->where('estado', 'ARCHIVADO')->count();
                        $solicitudesTransporte = $planOperativo->flatMap(fn (array $grupo): array => $grupo['transportes'] ?? []);
                        $cantidadSolicitudesTransporte = $solicitudesTransporte->count();
                        $unidadesSolicitadas = $solicitudesTransporte->sum(fn (array $transporte): int => max(0, (int) ($transporte['cantidad_unidades_requeridas'] ?? 0)));
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
                        $estadoKey = strtolower(str_replace(' ', '_', (string) ($rq['estado'] ?? 'borrador')));
                        $estadoCss = str_replace('_', '-', $estadoKey);
                        $estadoLabel = $estadoLabels[$estadoKey] ?? ucfirst(str_replace('_', ' ', $estadoKey));
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
                        $paradaRoute = ['id' => $rq['id'], 'lista' => $lista];
                    @endphp

                    <article class="rq-card" aria-labelledby="rq-title-{{ $rq['id'] }}">
                        <div class="rq-card-topline">
                            <div class="rq-card-badges">
                                <span class="estado-badge {{ $estadoCss }}">{{ $estadoLabel }}</span>
                                <span class="rq-location">{{ $destinoNombre }}</span>
                                @if($planArchivado)
                                    <span class="rq-soft-badge">{{ $planArchivado }} {{ $planArchivado === 1 ? 'plan archivado' : 'planes archivados' }}</span>
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
                                <div class="rq-summary {{ $planActividades > 0 ? '' : 'is-warning' }}">
                                    <span>Plan operativo</span>
                                    @if($planGrupos > 0 || $planCount > 0)
                                        <strong>{{ $planActividades > 0 ? $planActividades.' actividad(es)' : 'Sin actividades programadas' }}</strong>
                                        <small>{{ max(1, $planCount) }} {{ $planCount > 1 ? 'planes' : 'plan' }} · {{ $planGrupos }} grupo(s) sin archivar</small>
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

                                <div class="rq-summary {{ $cantidadSolicitudesTransporte || $transporteTotal > 0 ? '' : 'is-muted' }}">
                                    <span>Transporte solicitado en planes</span>
                                    @if($cantidadSolicitudesTransporte)
                                        <strong>{{ $cantidadSolicitudesTransporte }} {{ $cantidadSolicitudesTransporte === 1 ? 'solicitud de transporte' : 'solicitudes de transporte' }}</strong>
                                        <small>{{ $unidadesSolicitadas > 0 ? $unidadesSolicitadas.' '.($unidadesSolicitadas === 1 ? 'unidad solicitada' : 'unidades solicitadas') : 'Cantidad de unidades sin indicar' }}</small>
                                    @else
                                        <strong>Sin solicitudes de transporte</strong>
                                    @endif
                                    @if($transporteTotal > 0)
                                        <small>Registro anterior: {{ $transporteResumenTexto ?: $transporteTotal.' unidad(es)' }}{{ $transporteRestantes > 0 ? ' + '.$transporteRestantes.' más' : '' }}</small>
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
                                <a href="{{ route('rq-mina.show', $paradaRoute) }}" class="btn-row btn-send">Abrir parada</a>

                                @if($canEditRq || $canDuplicateRq || $canDeleteRq)
                                    <div class="rq-actions-menu">
                                        <button
                                            type="button"
                                            class="btn-row btn-row-outline rq-actions-toggle"
                                            data-rq-actions-toggle
                                            aria-expanded="false"
                                            aria-controls="{{ $menuId }}"
                                        >
                                            Acciones
                                        </button>
                                        <div class="rq-actions-dropdown" id="{{ $menuId }}" data-rq-actions-menu hidden>
                                            @if($canEditRq)
                                                <a href="{{ route('rq-mina.edit', $paradaRoute) }}" class="rq-actions-item">Editar datos de parada</a>
                                            @endif
                                            @if($canDuplicateRq)
                                                <a href="{{ route('rq-mina.create', ['copy_from' => $rq['id'], 'lista' => $lista]) }}" class="rq-actions-item">Copiar datos de parada</a>
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

<script>
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

