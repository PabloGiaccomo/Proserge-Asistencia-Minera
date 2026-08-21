@extends('layouts.app')

@section('title', 'Man Power - Planificacion')

@php
    use App\Support\Rbac\PermissionMatrix;

    $permissions = session('user.permissions', []);
    $canCreate = PermissionMatrix::allowsDirect($permissions, 'man_power', 'crear');
    $canAssign = PermissionMatrix::allowsDirect($permissions, 'man_power', 'asignar');
    $canUpdate = PermissionMatrix::allowsDirect($permissions, 'man_power', 'actualizar');
    $canDuplicate = PermissionMatrix::allowsDirect($permissions, 'man_power', 'duplicar');
    $contextsByTurn = $contextsByTurn ?? [];
    $day = $contextsByTurn['DIA'] ?? $context ?? [];
    $night = $contextsByTurn['NOCHE'] ?? $context ?? [];
    $turnContexts = ['DIA' => $day, 'NOCHE' => $night];
    $selected = $context['selected'] ?? [];
    $parada = $context['rq_mina'] ?? null;
    $plan = $context['plan'] ?? null;
    $plans = collect($context['plans'] ?? []);
    $activities = collect($context['actividades'] ?? []);
    $activity = $context['actividad'] ?? null;
    $fecha = $selected['fecha'] ?? now()->addDay()->toDateString();
    $selectedRqMinaId = $selected['rq_mina_id'] ?? '';
    $selectedPlanId = $selected['plan_id'] ?? '';
    $selectedActivityId = $selected['actividad_id'] ?? '';
    $selectedActivityLabel = collect([$activity['sait'] ?? null, $activity['area'] ?? null, $activity['sector'] ?? null])
        ->filter(fn ($value) => filled($value))
        ->implode(' - ') ?: 'Sin SAIT seleccionado';
    $activeView = $activeView ?? 'dashboard';
    $dashboardMode = $dashboardMode ?? 'resumen';
    $dashboardModes = [
        'resumen' => ['label' => 'Resumen', 'description' => 'Lectura general'],
        'turnos' => ['label' => 'Turnos', 'description' => 'Dia y noche'],
        'cargos' => ['label' => 'Cargos', 'description' => 'Cobertura RR.HH.'],
        'cobertura' => ['label' => 'Cobertura SAIT', 'description' => 'Referencia operativa'],
    ];
    $allAssignments = collect($day['asignaciones'] ?? [])
        ->merge($night['asignaciones'] ?? [])
        ->unique('rq_proserge_detalle_id')
        ->values();
    $assignmentMap = $allAssignments->keyBy('rq_proserge_detalle_id');
    $dayAssignmentMap = collect($day['asignaciones'] ?? [])->keyBy('rq_proserge_detalle_id');
    $nightAssignmentMap = collect($night['asignaciones'] ?? [])->keyBy('rq_proserge_detalle_id');
    $cargoGroups = $allAssignments->groupBy(function (array $item): string {
        return (string) ($item['rq_mina_detalle_id'] ?? ('cargo:'.mb_strtolower(trim((string) ($item['cargo_solicitado'] ?? 'Sin cargo')))));
    });
    $allGroups = collect($day['grupos_man_power'] ?? [])
        ->merge($night['grupos_man_power'] ?? [])
        ->sortBy([['turno', 'asc'], ['area', 'asc']])
        ->values();
    $operationalGroups = collect($day['grupos_operativos'] ?? [])
        ->merge($night['grupos_operativos'] ?? [])
        ->unique('id')
        ->values();
    $summaryDay = $day['resumen'] ?? [];
    $summaryNight = $night['resumen'] ?? [];
    $totalRequired = (int) ($summaryDay['requeridos_por_plan'] ?? 0) + (int) ($summaryNight['requeridos_por_plan'] ?? 0);
    $totalDistributed = (int) ($summaryDay['total_distribuido'] ?? 0) + (int) ($summaryNight['total_distribuido'] ?? 0);
    $referenceDifference = $totalDistributed - $totalRequired;
    $positionRows = collect($day['puestos'] ?? [])->map(function (array $position) use ($night): array {
        $nightPosition = collect($night['puestos'] ?? [])->firstWhere('id', $position['id']) ?? [];
        $position['distribuidos_dia'] = (int) ($position['distribuidos_turno'] ?? 0);
        $position['distribuidos_noche'] = (int) ($nightPosition['distribuidos_turno'] ?? 0);
        $position['distribuidos_total'] = $position['distribuidos_dia'] + $position['distribuidos_noche'];

        return $position;
    });
    $baseQuery = [
        'rq_mina_id' => $selectedRqMinaId,
        'plan_id' => $selectedPlanId,
        'actividad_id' => $selectedActivityId,
        'fecha' => $fecha,
        'dashboard_modo' => $dashboardMode,
    ];
    $defaultDestinoTipo = $parada['destino_tipo'] ?? 'MINA';
    $defaultDestinoId = $parada['destino_id'] ?? $parada['mina_id'] ?? '';
    $dateMin = $plan['fecha_inicio'] ?? $parada['fecha_inicio'] ?? null;
    $dateMax = $plan['fecha_fin'] ?? $parada['fecha_fin'] ?? null;
    $periodSummary = $periodSummary ?? [];
    $isPeriodSummary = $activeView === 'dashboard' && $dashboardMode === 'resumen';
    $periodStart = $periodSummary['fecha_inicio'] ?? $dateMin ?? $fecha;
    $periodEnd = $periodSummary['fecha_fin'] ?? $dateMax ?? $fecha;
@endphp

@section('content')
<div class="mpw-page" id="manPowerWorkspace">
    <header class="mpw-page-head">
        <div>
            <a class="mpw-back" href="{{ route('man-power.index', ['fecha' => $fecha]) }}">Volver a paradas</a>
            <h1>{{ $parada['destino_nombre'] ?? $parada['mina_nombre'] ?? 'Man Power' }}</h1>
            @if($isPeriodSummary)
                <p>{{ $parada['mina_nombre'] ?? 'Sin mina' }} - {{ $parada['area'] ?? 'Sin area' }} - Periodo {{ \Illuminate\Support\Carbon::parse($periodStart)->format('d/m/Y') }} al {{ \Illuminate\Support\Carbon::parse($periodEnd)->format('d/m/Y') }}</p>
            @else
                <p>{{ $parada['mina_nombre'] ?? 'Sin mina' }} - {{ $parada['area'] ?? 'Sin area' }} - {{ \Illuminate\Support\Carbon::parse($fecha)->translatedFormat('l d/m/Y') }}</p>
            @endif
        </div>
        @if($plan)
            <span class="mpw-status {{ ($plan['archivado'] ?? false) ? 'is-warning' : 'is-ok' }}">{{ $plan['codigo'] }} · {{ $plan['estado'] }}</span>
        @endif
    </header>

    @if(session('success'))
        <div class="mpw-server-message is-success" data-toast-message>{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="mpw-server-message is-error" data-toast-message>{{ $errors->first() }}</div>
    @endif

    <section class="mpw-toolbar">
        <form method="GET" action="{{ route('man-power.grupos') }}" class="mpw-context-form" id="mpwContextForm">
            <input type="hidden" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
            <input type="hidden" name="vista" value="{{ $activeView }}">
            <input type="hidden" name="dashboard_modo" value="{{ $dashboardMode }}">
            <label>
                <span>Plan operativo</span>
                <select name="plan_id" data-auto-submit>
                    <option value="">Sin plan / historico</option>
                    @foreach($plans as $item)
                        <option value="{{ $item['id'] }}" @selected($selectedPlanId === $item['id'])>{{ $item['codigo'] }} - {{ $item['nombre'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="mpw-activity-field">
                <span>SAIT / punto</span>
                <select name="actividad_id" data-auto-submit @disabled($activities->isEmpty())>
                    @forelse($activities as $item)
                        <option value="{{ $item['id'] }}" @selected($selectedActivityId === $item['id'])>{{ $item['sait'] ?: 'Sin SAIT' }} · {{ $item['area'] ?: 'Sin area' }} · {{ $item['sector'] ?: 'Sin sector' }}</option>
                    @empty
                        <option value="">Sin actividades configuradas</option>
                    @endforelse
                </select>
            </label>
            @if($isPeriodSummary)
                <input name="fecha" type="hidden" value="{{ $fecha }}">
            @else
                <label>
                    <span>Dia de trabajo</span>
                    <input name="fecha" type="date" value="{{ $fecha }}" @if($dateMin) min="{{ $dateMin }}" @endif @if($dateMax) max="{{ $dateMax }}" @endif data-auto-submit>
                </label>
            @endif
        </form>

        <nav class="mpw-tabs" aria-label="Vistas de Man Power">
            @foreach(['dashboard' => 'Dashboard', 'seleccion' => 'Seleccion', 'grupos' => 'Grupos'] as $viewKey => $viewLabel)
                <a href="{{ route('man-power.grupos', array_merge($baseQuery, ['vista' => $viewKey])) }}" class="{{ $activeView === $viewKey ? 'is-active' : '' }}" @if($activeView === $viewKey) aria-current="page" @endif>{{ $viewLabel }}</a>
            @endforeach
        </nav>
    </section>

    @if(!$parada)
        <section class="mpw-empty">No hay una parada activa disponible para este alcance.</section>
    @elseif($activeView === 'dashboard')
        <section class="mpw-dashboard-modes" aria-labelledby="mpwDashboardModeTitle">
            <div>
                <strong id="mpwDashboardModeTitle">Forma de visualizacion</strong>
                <span>Elige la perspectiva que necesitas revisar.</span>
            </div>
            <nav aria-label="Formas de visualizar el dashboard">
                @foreach($dashboardModes as $modeKey => $mode)
                    <a href="{{ route('man-power.grupos', array_merge($baseQuery, ['vista' => 'dashboard', 'dashboard_modo' => $modeKey])) }}"
                       class="{{ $dashboardMode === $modeKey ? 'is-active' : '' }}"
                       @if($dashboardMode === $modeKey) aria-current="page" @endif>
                        <strong>{{ $mode['label'] }}</strong>
                        <span>{{ $mode['description'] }}</span>
                    </a>
                @endforeach
            </nav>
        </section>

        @if($dashboardMode === 'resumen')
            @php
                $periodReference = (int) ($periodSummary['referencia_total'] ?? 0);
                $periodDistributed = (int) ($periodSummary['distribuciones_total'] ?? 0);
                $periodDifference = (int) ($periodSummary['diferencia'] ?? 0);
                $periodCoverage = $periodSummary['cobertura_porcentaje'] ?? null;
            @endphp
            <section class="mpw-kpis">
                <article><span>Personal aprobado</span><strong>{{ $periodSummary['personal_aprobado'] ?? 0 }}</strong><small>Disponible durante el periodo</small></article>
                <article class="is-info"><span>Jornadas requeridas</span><strong>{{ $periodReference }}</strong><small>Referencia acumulada del SAIT</small></article>
                <article><span>Jornadas distribuidas</span><strong>{{ $periodDistributed }}</strong><small>{{ $periodSummary['grupos_total'] ?? 0 }} grupo(s) preparados</small></article>
                <article class="{{ $periodCoverage === null ? 'is-info' : ($periodDifference < 0 ? 'is-warning' : 'is-ok') }}">
                    <span>Cobertura del periodo</span>
                    <strong>{{ $periodCoverage === null ? '-' : rtrim(rtrim(number_format((float) $periodCoverage, 1, '.', ''), '0'), '.').'%' }}</strong>
                    <small>{{ $periodCoverage === null ? 'Sin referencia configurada' : ($periodDifference < 0 ? abs($periodDifference).' jornadas por cubrir' : ($periodDifference > 0 ? $periodDifference.' jornadas adicionales' : 'Cobertura exacta')) }}</small>
                </article>
            </section>

            <section class="mpw-panel">
                <div class="mpw-panel-head">
                    <div><h2>Resumen del periodo</h2><p>Acumulado del plan y SAIT seleccionados, sin depender del dia de trabajo.</p></div>
                    <span>{{ \Illuminate\Support\Carbon::parse($periodStart)->format('d/m/Y') }} - {{ \Illuminate\Support\Carbon::parse($periodEnd)->format('d/m/Y') }}</span>
                </div>
                <div class="mpw-period-overview">
                    <dl>
                        <div><dt>Dias del periodo</dt><dd>{{ $periodSummary['dias_periodo'] ?? 0 }}</dd></div>
                        <div><dt>Dias con referencia</dt><dd>{{ $periodSummary['dias_con_referencia'] ?? 0 }}</dd></div>
                        <div><dt>Dias con grupos</dt><dd>{{ $periodSummary['dias_con_grupos'] ?? 0 }}</dd></div>
                        <div><dt>Personal unico utilizado</dt><dd>{{ $periodSummary['personal_unico_distribuido'] ?? 0 }}</dd></div>
                    </dl>
                    <div class="mpw-period-turns">
                        @foreach(['DIA' => 'Turno dia', 'NOCHE' => 'Turno noche'] as $turnKey => $turnLabel)
                            @php
                                $periodTurn = $periodSummary['turnos'][$turnKey] ?? [];
                                $turnReference = (int) ($periodTurn['referencia'] ?? 0);
                                $turnDistributed = (int) ($periodTurn['distribuciones'] ?? 0);
                                $turnDifference = (int) ($periodTurn['diferencia'] ?? 0);
                            @endphp
                            <article>
                                <div><span>{{ $turnLabel }}</span><strong>{{ $turnDistributed }} / ref. {{ $turnReference }}</strong></div>
                                <small>{{ $periodTurn['grupos'] ?? 0 }} grupo(s) - {{ $turnReference === 0 ? 'Sin referencia' : (($turnDifference > 0 ? '+' : '').$turnDifference.' de variacion') }}</small>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @else
            <section class="mpw-kpis">
                <article><span>Personal aprobado</span><strong>{{ $allAssignments->count() }}</strong><small>RQ Proserge vigente</small></article>
                <article class="is-info"><span>Referencia del SAIT</span><strong>{{ $totalRequired }}</strong><small>{{ $activity['sait'] ?? 'Sin actividad seleccionada' }}, dia y noche</small></article>
                <article><span>Distribuidos</span><strong>{{ $totalDistributed }}</strong><small>{{ $allGroups->count() }} grupo(s)</small></article>
                <article class="{{ $totalRequired === 0 ? 'is-info' : ($referenceDifference < 0 ? 'is-warning' : 'is-ok') }}">
                    <span>Diferencia referencial</span>
                    <strong>{{ $totalRequired === 0 ? '-' : ($referenceDifference > 0 ? '+' : '').$referenceDifference }}</strong>
                    <small>{{ $totalRequired === 0 ? 'Sin referencia para el dia' : ($referenceDifference < 0 ? abs($referenceDifference).' por debajo' : ($referenceDifference > 0 ? $referenceDifference.' por encima' : 'Igual a lo solicitado')) }}</small>
                </article>
            </section>
        @endif

        @if($dashboardMode === 'turnos')
        <section class="mpw-panel">
            <div class="mpw-panel-head">
                <div><h2>Estado por turno</h2><p>Lectura rapida de cobertura para el dia seleccionado.</p></div>
            </div>
            <div class="mpw-shift-dashboard">
                @foreach($turnContexts as $turnKey => $turnContext)
                    @php
                        $turnSummary = $turnContext['resumen'] ?? [];
                        $turnGroups = collect($turnContext['grupos_man_power'] ?? []);
                        $required = (int) ($turnSummary['requeridos_por_plan'] ?? 0);
                        $assigned = (int) ($turnSummary['total_distribuido'] ?? 0);
                        $difference = $assigned - $required;
                        $percent = $required > 0 ? min(100, (int) round(($assigned / $required) * 100)) : 0;
                    @endphp
                    <article class="mpw-shift-card">
                        <div class="mpw-shift-head">
                            <div><span>Turno</span><h3>{{ $turnKey === 'DIA' ? 'Dia' : 'Noche' }}</h3></div>
                            <strong>{{ $assigned }} / ref. {{ $required }}</strong>
                        </div>
                        <div class="mpw-progress"><span style="width:{{ $percent }}%"></span></div>
                        <dl>
                            <div><dt>Grupos</dt><dd>{{ $turnGroups->count() }}</dd></div>
                            <div><dt>Disponibles</dt><dd>{{ $turnSummary['total_disponible'] ?? 0 }}</dd></div>
                            <div><dt>Variacion</dt><dd>{{ $required === 0 ? '-' : (($difference > 0 ? '+' : '').$difference) }}</dd></div>
                        </dl>
                        <a href="{{ route('man-power.grupos', array_merge($baseQuery, ['vista' => 'seleccion', 'turno' => $turnKey])) }}">Organizar turno</a>
                    </article>
                @endforeach
            </div>
        </section>
        @endif

        @if($dashboardMode === 'cobertura')
        <section class="mpw-panel">
            <div class="mpw-panel-head">
                <div><h2>Referencia del SAIT seleccionado</h2><p>Compara el pedido diario de esta actividad con el personal distribuido, sin limitar la seleccion.</p></div>
                <span>{{ $operationalGroups->count() }} grupo(s)</span>
            </div>
            <div class="mpw-coverage-grid">
                @forelse($operationalGroups as $opGroup)
                    @php
                        $dayOperational = collect($day['grupos_operativos'] ?? [])->firstWhere('id', $opGroup['id']) ?? [];
                        $nightOperational = collect($night['grupos_operativos'] ?? [])->firstWhere('id', $opGroup['id']) ?? [];
                        $dayAssigned = (int) ($dayOperational['asignado'] ?? 0);
                        $nightAssigned = (int) ($nightOperational['asignado'] ?? 0);
                        $dayRequired = (int) ($dayOperational['requerido'] ?? 0);
                        $nightRequired = (int) ($nightOperational['requerido'] ?? 0);
                    @endphp
                    <article>
                        @php $opActivity = collect($opGroup['actividades'] ?? [])->first() ?? []; @endphp
                        <div><h3>{{ $opActivity['sait'] ?? $opGroup['nombre'] }}</h3><p>{{ $opActivity['area'] ?: 'Sin area' }} · {{ $opActivity['sector'] ?: 'Sin sector' }} · {{ $opGroup['nombre'] }}</p></div>
                        <div class="mpw-coverage-values">
                            <span>Dia <strong>{{ $dayAssigned }} / ref. {{ $dayRequired }}</strong></span>
                            <span>Noche <strong>{{ $nightAssigned }} / ref. {{ $nightRequired }}</strong></span>
                        </div>
                    </article>
                @empty
                    <div class="mpw-empty">El plan seleccionado no tiene grupos operativos configurados.</div>
                @endforelse
            </div>
        </section>
        @endif

        @if($dashboardMode === 'cargos')
        <section class="mpw-panel">
            <div class="mpw-panel-head">
                <div><h2>Cobertura por puesto</h2><p>Pedido de personal de RQ Mina y distribucion efectiva del dia seleccionado.</p></div>
                <span>{{ $positionRows->count() }} puesto(s)</span>
            </div>
            @if($positionRows->isEmpty())
                <div class="mpw-empty">RQ Mina no tiene puestos registrados en el pedido de personal.</div>
            @else
                <div class="mpw-position-table-wrap">
                    <table class="mpw-position-table">
                        <thead>
                            <tr>
                                <th>Puesto</th>
                                <th>Cantidad RQ</th>
                                <th>Back up</th>
                                <th>Total RQ</th>
                                <th>Entregado RRHH</th>
                                <th>Dia</th>
                                <th>Noche</th>
                                <th>Distribuido</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($positionRows as $position)
                                <tr>
                                    <td><strong>{{ $position['puesto'] ?: 'Sin puesto' }}</strong></td>
                                    <td>{{ $position['cantidad_rq'] }}</td>
                                    <td>{{ $position['cantidad_backup'] }}</td>
                                    <td>{{ $position['cantidad_total'] }}</td>
                                    <td>{{ $position['cantidad_atendida'] }}</td>
                                    <td>{{ $position['distribuidos_dia'] }}</td>
                                    <td>{{ $position['distribuidos_noche'] }}</td>
                                    <td><strong>{{ $position['distribuidos_total'] }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mpw-reference-note">Estas cantidades son referenciales. Puedes distribuir mas o menos personal segun la operacion del dia.</p>
            @endif
        </section>
        @endif
    @elseif($activeView === 'seleccion')
        <section class="mpw-panel">
            <div class="mpw-panel-head">
                <div><h2>Seleccion diaria por cargo</h2><p>{{ $activity ? ($activity['sait'].' · '.$activity['area'].' · '.$activity['sector']) : 'Selecciona una actividad SAIT' }}. Distribuye el personal entre Dia y Noche.</p></div>
                <div class="mpw-panel-actions">
                    <span>{{ $cargoGroups->count() }} cargo(s)</span>
                    @if($canDuplicate)
                        <div class="mpw-range-copy-actions" role="group" aria-label="Copiar grupos a dias futuros">
                            <button type="button" class="mpw-range-copy-button" data-copy-range="SEMANA" title="Copiar al resto de la semana" @disabled($allGroups->isEmpty())>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
                                <span>Semana</span>
                            </button>
                            <button type="button" class="mpw-range-copy-button" data-copy-range="PARADA" title="Copiar al resto de la parada" @disabled($allGroups->isEmpty())>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="17" rx="2"/><path d="m9 16 2 2 4-4"/></svg>
                                <span>Parada</span>
                            </button>
                        </div>
                    @endif
                    @if($canDuplicate || $canUpdate)
                        <div class="mpw-clipboard-actions" role="group" aria-label="Acciones de los grupos del dia">
                            @if($canDuplicate)
                                <button type="button" class="mpw-clipboard-button" data-copy-groups title="Copiar grupos de este dia" aria-label="Copiar grupos de este dia" @disabled($allGroups->isEmpty())>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                </button>
                                <button type="button" class="mpw-clipboard-button is-paste" data-paste-groups title="Pegar grupos en este dia" aria-label="Pegar grupos en este dia" disabled>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 14h10"/><path d="m17 10 4 4-4 4"/><path d="M16 4h2a2 2 0 0 1 2 2v1.5"/><path d="M4 13V6a2 2 0 0 1 2-2h2"/><path d="M4 13v7a2 2 0 0 0 2 2h8"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                                </button>
                            @endif
                            @if($canUpdate)
                                <button type="button" class="mpw-clipboard-button is-delete" data-cancel-day title="Eliminar grupos de este dia" aria-label="Eliminar grupos de este dia" @disabled($allGroups->isEmpty())>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
            @if($cargoGroups->isEmpty())
                <div class="mpw-empty">RQ Proserge todavia no tiene personal activo para esta fecha.</div>
            @else
                <div class="mpw-selection-board" data-selection-board>
                    <aside class="mpw-cargo-sidebar" data-remove-zone>
                        <div class="mpw-cargo-picker">
                            <span>Cargo</span>
                            <select data-cargo-selector aria-label="Seleccionar cargo">
                                @foreach($cargoGroups as $cargoKey => $cargoAssignments)
                                    @php
                                        $cargo = $cargoAssignments->first();
                                        $cargoName = $cargo['cargo_solicitado'] ?: $cargo['puesto_asignado'] ?: 'Sin cargo definido';
                                    @endphp
                                    <option value="{{ $cargoKey }}">{{ $cargoName }} ({{ $cargoAssignments->count() }})</option>
                                @endforeach
                            </select>
                        </div>

                        @foreach($cargoGroups as $cargoKey => $cargoAssignments)
                            @php
                                $cargo = $cargoAssignments->first();
                                $cargoName = $cargo['cargo_solicitado'] ?: $cargo['puesto_asignado'] ?: 'Sin cargo definido';
                                $cargoId = $cargo['rq_mina_detalle_id'] ?? null;
                                $shareable = $cargoAssignments->contains(fn (array $item): bool => (bool) ($item['cargo_compartible'] ?? false));
                            @endphp
                            <div class="mpw-cargo-pool" data-cargo-panel="{{ $cargoKey }}" @if(!$loop->first) hidden @endif>
                                <div class="mpw-cargo-pool-head">
                                    <div><strong>{{ $cargoName }}</strong><small>Arrastra una persona hacia Dia o Noche.</small></div>
                                    @if($cargoId && $canUpdate)
                                        <form method="POST" action="{{ route('man-power.cargos.compartir', $cargoId) }}" data-preserve-workspace>
                                            @csrf
                                            <input type="hidden" name="compartible" value="{{ $shareable ? 0 : 1 }}">
                                            <button type="submit" class="mpw-share-toggle {{ $shareable ? 'is-active' : '' }}" title="Permitir que este cargo participe en mas de un grupo del mismo turno">{{ $shareable ? 'Compartido' : 'Un grupo' }}</button>
                                        </form>
                                    @elseif($shareable)
                                        <span class="mpw-share-toggle is-active">Compartido</span>
                                    @endif
                                </div>
                                <div class="mpw-worker-list">
                                    @foreach($cargoAssignments as $assignment)
                                        @php
                                            $assignmentId = $assignment['rq_proserge_detalle_id'];
                                            $availableDay = (bool) ($dayAssignmentMap->get($assignmentId)['disponible'] ?? false);
                                            $availableNight = (bool) ($nightAssignmentMap->get($assignmentId)['disponible'] ?? false);
                                            $canDragWorker = $canAssign && ($availableDay || $availableNight);
                                        @endphp
                                        <article class="mpw-worker {{ ($availableDay || $availableNight) ? '' : 'is-used' }}"
                                            draggable="{{ $canDragWorker ? 'true' : 'false' }}"
                                            data-worker-source="available"
                                            data-assignment-id="{{ $assignmentId }}"
                                            data-worker-name="{{ $assignment['trabajador'] }}"
                                            data-available-day="{{ $availableDay ? '1' : '0' }}"
                                            data-available-night="{{ $availableNight ? '1' : '0' }}"
                                            data-shareable="{{ ($assignment['cargo_compartible'] ?? false) ? '1' : '0' }}">
                                            <strong>{{ $assignment['trabajador'] }}</strong>
                                            <span>{{ $assignment['puesto_asignado'] ?: 'Sin puesto' }}</span>
                                            <small>{{ ($availableDay || $availableNight) ? 'Disponible' : 'Ya distribuido' }}</small>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                        <p class="mpw-remove-hint">Para retirar a alguien, arrastralo de regreso a esta columna.</p>
                    </aside>

                    @foreach(['DIA' => 'Dia', 'NOCHE' => 'Noche'] as $turnKey => $turnLabel)
                        @php
                            $turnContext = $turnContexts[$turnKey];
                            $turnGroups = collect($turnContext['grupos_man_power'] ?? []);
                            $turnAssignments = collect($turnContext['asignaciones'] ?? []);
                        @endphp
                        <section class="mpw-turn-column" aria-label="Turno {{ strtolower($turnLabel) }}">
                            <div class="mpw-turn-title">
                                <div><span>Turno</span><strong>{{ $turnLabel }}</strong></div>
                                <div class="mpw-turn-reference">
                                    <small>{{ $turnGroups->first()['horario_salida'] ?? ($turnKey === 'DIA' ? '07:00' : '19:00') }}</small>
                                    <span>Referencia RQ Mina <strong>{{ (int) ($turnContext['resumen']['requeridos_por_plan'] ?? 0) }}</strong></span>
                                </div>
                            </div>
                            @forelse($turnGroups as $group)
                                @php
                                    $members = collect($group['detalle'] ?? [])->filter(fn (array $detail): bool => ($detail['estado_distribucion'] ?? 'ASIGNADO') === 'ASIGNADO');
                                    $memberAssignmentIds = $members->pluck('rq_proserge_detalle_id')->filter()->all();
                                    $options = $turnAssignments->filter(fn (array $item): bool => !in_array($item['rq_proserge_detalle_id'], $memberAssignmentIds, true) && (bool) ($item['disponible'] ?? false));
                                    $groupReference = (int) ($group['requerido'] ?? 0);
                                    if ($groupReference === 0 && $turnGroups->count() === 1) {
                                        $groupReference = (int) ($turnContext['resumen']['requeridos_por_plan'] ?? 0);
                                    }
                                    $responsableId = (string) ($group['supervisor']['id'] ?? '');
                                    $responsableIsActive = $responsableId !== '' && $members->contains(
                                        fn (array $detail): bool => (string) ($detail['personal']['id'] ?? '') === $responsableId
                                    );
                                @endphp
                                <article class="mpw-group-slot" data-group-drop data-turn="{{ $turnKey }}" data-group-id="{{ $group['id'] }}" data-add-url="{{ route('man-power.agregar-personal', $group['id']) }}" data-responsable-id="{{ $responsableIsActive ? $responsableId : '' }}">
                                    <header>
                                        <div><strong>{{ $group['nombre_snapshot'] ?: ($group['servicio'] ?? 'Grupo') }}</strong><small>{{ $group['area'] ?: 'Sin area' }} · {{ $group['horario_salida'] ?: '-' }}</small></div>
                                        <span>{{ $group['asignado'] }}{{ $groupReference > 0 ? ' / ref. '.$groupReference : '' }}</span>
                                    </header>
                                    @if($canAssign && $options->isNotEmpty())
                                        <select class="mpw-add-select" data-add-select data-add-url="{{ route('man-power.agregar-personal', $group['id']) }}">
                                            <option value="">Agregar personal del cargo seleccionado...</option>
                                            @foreach($options as $option)
                                                @php
                                                    $optionCargoKey = (string) ($option['rq_mina_detalle_id'] ?? ('cargo:'.mb_strtolower(trim((string) ($option['cargo_solicitado'] ?? 'Sin cargo')))));
                                                @endphp
                                                <option value="{{ $option['rq_proserge_detalle_id'] }}" data-cargo-key="{{ $optionCargoKey }}">{{ $option['trabajador'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <div class="mpw-slot-members">
                                        @forelse($members as $member)
                                            @php
                                                $person = $member['personal'] ?? [];
                                                $memberAssignment = $assignmentMap->get($member['rq_proserge_detalle_id']);
                                                $memberCargo = $memberAssignment['cargo_solicitado'] ?? $member['puesto_asignado_snapshot'] ?? $person['puesto'] ?? 'Sin cargo';
                                                $isResponsable = $responsableIsActive && (string) ($person['id'] ?? '') === $responsableId;
                                            @endphp
                                            <div class="mpw-member{{ $isResponsable ? ' is-responsable' : ($responsableIsActive ? ' is-responsable-muted' : '') }}" draggable="{{ $canAssign ? 'true' : 'false' }}"
                                                data-worker-source="member"
                                                data-detail-id="{{ $member['id'] }}"
                                                data-personal-id="{{ $person['id'] ?? '' }}"
                                                data-assignment-id="{{ $member['rq_proserge_detalle_id'] }}"
                                                data-worker-name="{{ $person['nombre_completo'] ?? 'Trabajador' }}"
                                                data-source-group="{{ $group['id'] }}"
                                                data-source-turn="{{ $turnKey }}"
                                                data-shareable="{{ ($memberAssignment['cargo_compartible'] ?? false) ? '1' : '0' }}"
                                                data-reubicar-url="{{ route('man-power.reubicar-personal', [$group['id'], $member['id']]) }}"
                                                data-retirar-url="{{ route('man-power.retirar-personal', [$group['id'], $member['id']]) }}">
                                                <div class="mpw-member-copy">
                                                    <span>{{ $person['nombre_completo'] ?? 'Trabajador' }}</span>
                                                    <small>{{ $memberCargo }}</small>
                                                </div>
                                                @if($canAssign)
                                                    <button type="button" class="mpw-responsable-button" data-responsable-button
                                                        data-responsable-url="{{ route('man-power.asignar-responsable', [$group['id'], $member['id']]) }}"
                                                        aria-label="{{ $isResponsable ? 'Responsable actual del grupo' : 'Designar como responsable del grupo' }}"
                                                        aria-pressed="{{ $isResponsable ? 'true' : 'false' }}"
                                                        title="{{ $isResponsable ? 'Responsable actual' : 'Designar responsable' }}">
                                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                                            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path>
                                                            <path d="M4.5 20a7.5 7.5 0 0 1 12.2-5.84"></path>
                                                            <path d="m17 18 1.5 1.5L22 16"></path>
                                                        </svg>
                                                    </button>
                                                @endif
                                            </div>
                                        @empty
                                            <div class="mpw-slot-empty">Arrastra aqui el personal de {{ strtolower($turnLabel) }}.</div>
                                        @endforelse
                                    </div>
                                </article>
                            @empty
                                <div class="mpw-no-group">
                                    <p>No hay grupo diario para este turno.</p>
                                    @if($canCreate && !($plan['archivado'] ?? false))
                                        <button type="button" data-open-group-modal data-turn="{{ $turnKey }}" data-op-group="{{ $activity['grupo_id'] ?? '' }}" data-activity="{{ $selectedActivityId }}" data-service="{{ $activity['sait'] ?? ($parada['area'] ?? '') }}" data-area="{{ $activity['area'] ?? ($parada['area'] ?? '') }}" @disabled(!$activity)>Preparar grupo</button>
                                    @endif
                                </div>
                            @endforelse
                        </section>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <section class="mpw-panel">
            <div class="mpw-panel-head">
                <div><h2>Grupos preparados</h2><p>Revisa los integrantes, el responsable y el horario de cada turno.</p></div>
                <span>{{ $allGroups->count() }} grupo(s)</span>
            </div>
            <div class="mpw-groups-grid">
                @forelse($allGroups as $group)
                    @php $activeMembers = collect($group['detalle'] ?? [])->where('estado_distribucion', 'ASIGNADO'); @endphp
                    <article class="mpw-group-card">
                        <header>
                            <div><span>{{ $group['turno'] === 'NOCHE' ? 'Turno noche' : 'Turno dia' }}</span><h3>{{ $group['nombre_snapshot'] ?: ($group['servicio'] ?? 'Grupo de trabajo') }}</h3><p>{{ $group['area'] ?: 'Sin area' }} · {{ $group['horario_salida'] ?: '-' }}</p></div>
                            <strong class="{{ ($group['brecha'] ?? 0) > 0 ? 'is-warning' : 'is-ok' }}">{{ $group['asignado'] }}/{{ $group['requerido'] }}</strong>
                        </header>
                        <div class="mpw-group-meta"><span>Responsable</span><strong>{{ $group['supervisor']['nombre_completo'] ?? 'Sin responsable' }}</strong></div>
                        <div class="mpw-group-people">
                            @forelse($activeMembers as $member)
                                <span>{{ $member['personal']['nombre_completo'] ?? 'Trabajador' }}</span>
                            @empty
                                <small>Grupo sin integrantes.</small>
                            @endforelse
                        </div>
                        <footer>
                            <a href="{{ route('man-power.grupo-detalle', $group['id']) }}">Ver detalle</a>
                        </footer>
                    </article>
                @empty
                    <div class="mpw-empty">Todavia no se han preparado grupos para este dia.</div>
                @endforelse
            </div>
        </section>
    @endif
</div>

<div class="mpw-modal is-hidden" id="mpwCopyDayModal" aria-hidden="true">
    <div class="mpw-modal-panel mpw-copy-day-modal" role="dialog" aria-modal="true" aria-labelledby="mpwCopyDayTitle">
        <header>
            <div><h2 id="mpwCopyDayTitle">Pegar grupos en este dia</h2><p>Confirma el dia de origen antes de crear los grupos.</p></div>
            <button type="button" data-close-modal aria-label="Cerrar">×</button>
        </header>
        <form method="POST" action="{{ route('man-power.copiar-grupos-dia') }}" data-copy-day-form>
            @csrf
            <input type="hidden" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
            <input type="hidden" name="rq_mina_plan_id" value="{{ $selectedPlanId }}">
            <input type="hidden" name="rq_mina_actividad_origen_id" value="" data-paste-source-activity-id>
            <input type="hidden" name="rq_mina_actividad_destino_id" value="{{ $selectedActivityId }}">
            <input type="hidden" name="fecha_origen" value="" data-paste-source-date>
            <input type="hidden" name="fecha_destino" value="{{ $fecha }}">
            <input type="hidden" name="copiar_integrantes" value="1">
            <input type="hidden" name="sobrescribir_destino" value="1">
            <div class="mpw-copy-day-content">
                <div class="mpw-copy-day-source"><span>Copiado desde</span><strong data-paste-source-label>-</strong><small data-paste-source-activity>SAIT de origen</small></div>
                <div class="mpw-copy-day-source is-destination"><span>Pegar en</span><strong>{{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</strong><small>{{ $selectedActivityLabel }}</small></div>
            </div>
            <p class="mpw-copy-day-note">Los grupos actuales del SAIT destino seran reemplazados. Los demas SAIT del dia permaneceran intactos.</p>
            <footer><button type="button" data-close-modal>Cancelar</button><button type="submit" class="is-primary">Pegar grupos</button></footer>
        </form>
    </div>
</div>

<div class="mpw-modal is-hidden" id="mpwCopyRangeModal" aria-hidden="true">
    <div class="mpw-modal-panel mpw-copy-day-modal" role="dialog" aria-modal="true" aria-labelledby="mpwCopyRangeTitle">
        <header>
            <div><h2 id="mpwCopyRangeTitle" data-copy-range-title>Copiar grupos futuros</h2><p data-copy-range-subtitle>Confirma el periodo que se reemplazara.</p></div>
            <button type="button" data-close-modal aria-label="Cerrar">×</button>
        </header>
        <form method="POST" action="{{ route('man-power.copiar-grupos-rango') }}" data-copy-range-form>
            @csrf
            <input type="hidden" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
            <input type="hidden" name="rq_mina_plan_id" value="{{ $selectedPlanId }}">
            <input type="hidden" name="rq_mina_actividad_id" value="{{ $selectedActivityId }}">
            <input type="hidden" name="fecha_origen" value="{{ $fecha }}">
            <input type="hidden" name="alcance" value="" data-copy-range-scope>
            <input type="hidden" name="copiar_integrantes" value="1">
            <input type="hidden" name="sobrescribir_destino" value="1">
            <div class="mpw-copy-range-content">
                <div class="mpw-copy-day-source"><span>Grupo base</span><strong>{{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</strong><small>{{ $selectedActivityLabel }}</small></div>
                <div class="mpw-copy-range-summary">
                    <strong data-copy-range-summary>Se copiaran los grupos a los dias futuros.</strong>
                    <p>Solo se reemplazaran los grupos de este SAIT. Los dias anteriores a hoy y los demas SAIT permaneceran intactos.</p>
                </div>
            </div>
            <footer><button type="button" data-close-modal>Cancelar</button><button type="submit" class="is-primary" data-copy-range-submit>Confirmar copia</button></footer>
        </form>
    </div>
</div>

<div class="mpw-modal is-hidden" id="mpwCancelDayModal" aria-hidden="true">
    <div class="mpw-modal-panel mpw-copy-day-modal" role="dialog" aria-modal="true" aria-labelledby="mpwCancelDayTitle">
        <header>
            <div><h2 id="mpwCancelDayTitle">Eliminar grupos de este dia</h2><p>Confirma antes de retirar esta seleccion de Man Power.</p></div>
            <button type="button" data-close-modal aria-label="Cerrar">×</button>
        </header>
        <form method="POST" action="{{ route('man-power.cancelar-grupos-dia') }}" data-cancel-day-form>
            @csrf
            <input type="hidden" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
            <input type="hidden" name="rq_mina_plan_id" value="{{ $selectedPlanId }}">
            <input type="hidden" name="rq_mina_actividad_id" value="{{ $selectedActivityId }}">
            <input type="hidden" name="fecha" value="{{ $fecha }}">
            <div class="mpw-cancel-day-content">
                <strong>{{ $selectedActivityLabel }}</strong>
                <span>{{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</span>
                <p>Se retiraran los integrantes y se cancelaran los grupos Dia y Noche de este SAIT. La parada, el plan, los otros SAIT y el historial no se eliminaran.</p>
            </div>
            <footer><button type="button" data-close-modal>Cancelar</button><button type="submit" class="is-danger" data-cancel-day-submit>Eliminar grupos</button></footer>
        </form>
    </div>
</div>

<div class="mpw-modal is-hidden" id="mpwGroupModal" aria-hidden="true">
    <div class="mpw-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mpwModalTitle">
        <header><div><h2 id="mpwModalTitle">Preparar grupo diario</h2><p>Define los datos operativos del turno.</p></div><button type="button" data-close-modal aria-label="Cerrar">×</button></header>
        <form method="POST" action="{{ route('man-power.guardar-grupo') }}" id="mpwCreateForm">
            @csrf
            <input type="hidden" name="rq_mina_id" value="{{ $selectedRqMinaId }}">
            <input type="hidden" name="rq_mina_plan_id" value="{{ $selectedPlanId }}">
            <input type="hidden" name="rq_mina_actividad_grupo_id" value="{{ $activity['grupo_id'] ?? '' }}" data-modal-op-group>
            <input type="hidden" name="actividad_ids[]" value="{{ $selectedActivityId }}" data-modal-activity>
            <input type="hidden" name="fecha" value="{{ $fecha }}">
            <input type="hidden" name="turno" value="DIA" data-modal-turn>
            <input type="hidden" name="destino_tipo" value="{{ $defaultDestinoTipo }}">
            <input type="hidden" name="destino_id" value="{{ $defaultDestinoId }}">
            <input type="hidden" name="estado" value="BORRADOR">
            <div class="mpw-modal-grid">
                <label><span>Nombre del servicio</span><input name="servicio" required data-modal-service></label>
                <label><span>Area</span><input name="area" required data-modal-area></label>
                <label><span>Horario</span><input name="horario_salida" type="time" value="07:00" required data-modal-hour></label>
                <label class="is-wide"><span>Observaciones</span><textarea name="observaciones" placeholder="Punto de encuentro, movilidad o indicacion operativa"></textarea></label>
            </div>
            <footer><button type="button" data-close-modal>Cancelar</button><button type="submit" class="is-primary">Crear grupo</button></footer>
        </form>
    </div>
</div>
<div class="mpw-toast-stack" id="mpwToastStack" aria-live="polite"></div>
@endsection

@push('scripts')
<script>
(function () {
    const root = document.getElementById('manPowerWorkspace');
    if (!root) return;

    const csrf = @json(csrf_token());
    const modal = document.getElementById('mpwGroupModal');
    const copyDayModal = document.getElementById('mpwCopyDayModal');
    const copyRangeModal = document.getElementById('mpwCopyRangeModal');
    const cancelDayModal = document.getElementById('mpwCancelDayModal');
    const createForm = document.getElementById('mpwCreateForm');
    const toastStack = document.getElementById('mpwToastStack');
    const cargoSelector = root.querySelector('[data-cargo-selector]');
    const workspaceStateKey = `man-power-selection:${@json($selectedRqMinaId)}:${@json($selectedPlanId)}:${@json($selectedActivityId)}:${@json($fecha)}`;
    const dayClipboardKey = `man-power-day-clipboard:${@json($selectedRqMinaId)}:${@json($selectedPlanId)}`;
    const currentDay = @json($fecha);
    const currentActivityId = @json($selectedActivityId);
    const currentActivityLabel = @json($selectedActivityLabel);
    const currentGroupCount = @json($allGroups->count());
    let dragged = null;

    function readDayClipboard() {
        try { return JSON.parse(sessionStorage.getItem(dayClipboardKey) || 'null'); } catch (_) { return null; }
    }

    function formatDay(value) {
        const parts = String(value || '').split('-');
        return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : value;
    }

    function updateClipboardButtons() {
        const clipboard = readDayClipboard();
        const copyButton = root.querySelector('[data-copy-groups]');
        const pasteButton = root.querySelector('[data-paste-groups]');
        copyButton?.classList.toggle('is-active', clipboard?.fecha === currentDay && clipboard?.actividad_id === currentActivityId);
        if (!pasteButton) return;
        const canPaste = clipboard?.actividad_id && clipboard.fecha !== currentDay;
        pasteButton.disabled = !canPaste;
        pasteButton.title = canPaste
            ? `Pegar ${clipboard.actividad_label || 'SAIT copiado'} del ${formatDay(clipboard.fecha)} en ${currentActivityLabel}`
            : 'Copia grupos de otro dia antes de pegar';
    }

    function rememberWorkspaceState() {
        if (!cargoSelector) return;
        sessionStorage.setItem(workspaceStateKey, JSON.stringify({ cargo: cargoSelector.value, scrollY: window.scrollY }));
    }

    function applyCargoSelection() {
        if (!cargoSelector) return;
        const selectedCargo = cargoSelector.value;
        root.querySelectorAll('[data-cargo-panel]').forEach(panel => {
            panel.hidden = panel.dataset.cargoPanel !== selectedCargo;
        });
        root.querySelectorAll('[data-add-select]').forEach(select => {
            let visibleOptions = 0;
            Array.from(select.options).forEach((option, index) => {
                if (index === 0) return;
                const visible = option.dataset.cargoKey === selectedCargo;
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible) visibleOptions += 1;
            });
            select.value = '';
            select.hidden = visibleOptions === 0;
        });
    }

    function restoreWorkspaceState() {
        if (!cargoSelector) return;
        let state = {};
        try { state = JSON.parse(sessionStorage.getItem(workspaceStateKey) || '{}'); } catch (_) { state = {}; }
        if (state.cargo && Array.from(cargoSelector.options).some(option => option.value === state.cargo)) {
            cargoSelector.value = state.cargo;
        }
        applyCargoSelection();
        if (Number.isFinite(state.scrollY)) {
            requestAnimationFrame(() => window.scrollTo({ top: state.scrollY, behavior: 'instant' }));
        }
    }

    function reloadWorkspace() {
        rememberWorkspaceState();
        root.classList.add('is-updating');
        setTimeout(() => location.reload(), 180);
    }

    function toast(message, type) {
        if (!toastStack || !message) return;
        const item = document.createElement('div');
        item.className = `mpw-toast is-${type || 'info'}`;
        item.textContent = message;
        toastStack.appendChild(item);
        requestAnimationFrame(() => item.classList.add('is-visible'));
        setTimeout(() => { item.classList.remove('is-visible'); setTimeout(() => item.remove(), 180); }, type === 'error' ? 4200 : 2400);
    }

    async function post(url, fields) {
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok === false) throw new Error(data.message || 'No se pudo completar la accion.');
        return data;
    }

    document.querySelectorAll('[data-auto-submit]').forEach(field => field.addEventListener('change', () => document.getElementById('mpwContextForm')?.submit()));
    cargoSelector?.addEventListener('change', () => {
        applyCargoSelection();
        rememberWorkspaceState();
    });
    document.querySelectorAll('[data-preserve-workspace]').forEach(form => form.addEventListener('submit', rememberWorkspaceState));

    document.querySelectorAll('[data-add-select]').forEach(select => select.addEventListener('change', async () => {
        if (!select.value) return;
        select.disabled = true;
        try {
            await post(select.dataset.addUrl, { rq_proserge_detalle_id: select.value });
            toast('Personal agregado al grupo.', 'success');
            reloadWorkspace();
        } catch (error) {
            toast(error.message, 'error');
            select.disabled = false;
            select.value = '';
        }
    }));

    document.querySelectorAll('[data-responsable-button]').forEach(button => button.addEventListener('click', async event => {
        event.preventDefault();
        event.stopPropagation();
        const member = button.closest('.mpw-member');
        const group = button.closest('[data-group-drop]');
        if (!member || !group || button.getAttribute('aria-pressed') === 'true') return;

        const groupButtons = Array.from(group.querySelectorAll('[data-responsable-button]'));
        groupButtons.forEach(item => { item.disabled = true; });

        try {
            const result = await post(button.dataset.responsableUrl, {});
            const responsableId = String(result.responsable_id || member.dataset.personalId || '');
            group.dataset.responsableId = responsableId;

            group.querySelectorAll('.mpw-member').forEach(item => {
                const selected = item.dataset.personalId === responsableId;
                item.classList.toggle('is-responsable', selected);
                item.classList.toggle('is-responsable-muted', !selected);
                const action = item.querySelector('[data-responsable-button]');
                if (!action) return;
                action.setAttribute('aria-pressed', selected ? 'true' : 'false');
                action.setAttribute('aria-label', selected ? 'Responsable actual del grupo' : 'Designar como responsable del grupo');
                action.title = selected ? 'Responsable actual' : 'Designar responsable';
            });

            toast(`${member.dataset.workerName} es ahora responsable del grupo.`, 'success');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            groupButtons.forEach(item => { item.disabled = false; });
        }
    }));

    document.querySelectorAll('[draggable="true"][data-assignment-id]').forEach(card => {
        card.addEventListener('dragstart', event => {
            dragged = card;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = card.dataset.shareable === '1' ? 'copy' : 'move';
            event.dataTransfer.setData('text/plain', card.dataset.assignmentId || '');
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('is-dragging');
            dragged = null;
            document.querySelectorAll('.is-drop-ready').forEach(item => item.classList.remove('is-drop-ready'));
        });
    });

    document.querySelectorAll('[data-group-drop]').forEach(zone => {
        zone.addEventListener('dragover', event => { if (dragged) { event.preventDefault(); zone.classList.add('is-drop-ready'); } });
        zone.addEventListener('dragleave', () => zone.classList.remove('is-drop-ready'));
        zone.addEventListener('drop', async event => {
            event.preventDefault();
            zone.classList.remove('is-drop-ready');
            const draggedCard = dragged;
            if (!draggedCard || draggedCard.dataset.sourceGroup === zone.dataset.groupId) return;
            const targetTurn = zone.dataset.turn || 'DIA';
            const availabilityKey = targetTurn === 'NOCHE' ? 'availableNight' : 'availableDay';
            if (draggedCard.dataset.workerSource === 'available' && draggedCard.dataset[availabilityKey] !== '1') {
                toast(`El trabajador ya esta distribuido en el turno ${targetTurn === 'NOCHE' ? 'noche' : 'dia'}.`, 'error');
                return;
            }
            try {
                if (draggedCard.dataset.workerSource === 'member' && draggedCard.dataset.shareable !== '1') {
                    await post(draggedCard.dataset.reubicarUrl, { grupo_destino_id: zone.dataset.groupId, motivo: 'Reubicacion operativa por arrastre en Man Power' });
                    toast('Personal movido al nuevo grupo.', 'success');
                } else {
                    await post(zone.dataset.addUrl, { rq_proserge_detalle_id: draggedCard.dataset.assignmentId });
                    toast(draggedCard.dataset.shareable === '1' ? 'Personal compartido con el grupo.' : 'Personal agregado al grupo.', 'success');
                }
                reloadWorkspace();
            } catch (error) { toast(error.message, 'error'); }
        });
    });

    document.querySelectorAll('[data-remove-zone]').forEach(zone => {
        zone.addEventListener('dragover', event => { if (dragged?.dataset.workerSource === 'member') { event.preventDefault(); zone.classList.add('is-drop-ready'); } });
        zone.addEventListener('dragleave', () => zone.classList.remove('is-drop-ready'));
        zone.addEventListener('drop', async event => {
            event.preventDefault();
            zone.classList.remove('is-drop-ready');
            const draggedCard = dragged;
            if (!draggedCard?.dataset.retirarUrl) return;
            try {
                await post(draggedCard.dataset.retirarUrl, { motivo: 'Retiro operativo por arrastre en Man Power' });
                toast('Personal retirado del grupo; el historial se conserva.', 'success');
                reloadWorkspace();
            } catch (error) { toast(error.message, 'error'); }
        });
    });

    function hideModal(target) { target?.classList.add('is-hidden'); target?.setAttribute('aria-hidden', 'true'); }
    function openModal(button) {
        if (!modal || !createForm) return;
        const turn = button.dataset.turn || 'DIA';
        createForm.querySelector('[data-modal-turn]').value = turn;
        createForm.querySelector('[data-modal-op-group]').value = button.dataset.opGroup || '';
        createForm.querySelector('[data-modal-activity]').value = button.dataset.activity || '';
        createForm.querySelector('[data-modal-service]').value = button.dataset.service || '';
        createForm.querySelector('[data-modal-area]').value = button.dataset.area || '';
        createForm.querySelector('[data-modal-hour]').value = turn === 'NOCHE' ? '19:00' : '07:00';
        modal.classList.remove('is-hidden');
        modal.setAttribute('aria-hidden', 'false');
        createForm.querySelector('[data-modal-service]').focus();
    }

    document.querySelectorAll('[data-open-group-modal]').forEach(button => button.addEventListener('click', () => openModal(button)));
    root.querySelector('[data-copy-groups]')?.addEventListener('click', event => {
        if (event.currentTarget.disabled || currentGroupCount < 1) return;
        sessionStorage.setItem(dayClipboardKey, JSON.stringify({
            fecha: currentDay,
            grupos: currentGroupCount,
            actividad_id: currentActivityId,
            actividad_label: currentActivityLabel,
        }));
        updateClipboardButtons();
        toast(`${currentActivityLabel} del ${formatDay(currentDay)} copiado. Selecciona otro dia y SAIT para pegar.`, 'success');
    });
    root.querySelector('[data-paste-groups]')?.addEventListener('click', event => {
        const clipboard = readDayClipboard();
        if (event.currentTarget.disabled || !clipboard) {
            toast('Primero copia los grupos de otro dia.', 'error');
            return;
        }
        if (clipboard.fecha === currentDay) {
            toast('Abre un dia diferente para pegar los grupos.', 'error');
            return;
        }
        if (!copyDayModal) return;
        copyDayModal.querySelector('[data-paste-source-date]').value = clipboard.fecha;
        copyDayModal.querySelector('[data-paste-source-activity-id]').value = clipboard.actividad_id || '';
        copyDayModal.querySelector('[data-paste-source-label]').textContent = formatDay(clipboard.fecha);
        copyDayModal.querySelector('[data-paste-source-activity]').textContent = clipboard.actividad_label || 'SAIT de origen';
        copyDayModal.classList.remove('is-hidden');
        copyDayModal.setAttribute('aria-hidden', 'false');
        copyDayModal.querySelector('button[type="submit"]')?.focus();
    });
    root.querySelectorAll('[data-copy-range]').forEach(button => button.addEventListener('click', event => {
        if (event.currentTarget.disabled || currentGroupCount < 1 || !copyRangeModal) return;
        const scope = event.currentTarget.dataset.copyRange;
        const isWeek = scope === 'SEMANA';
        copyRangeModal.querySelector('[data-copy-range-scope]').value = scope;
        copyRangeModal.querySelector('[data-copy-range-title]').textContent = isWeek
            ? 'Copiar al resto de la semana'
            : 'Copiar al resto de la parada';
        copyRangeModal.querySelector('[data-copy-range-subtitle]').textContent = isWeek
            ? 'Se usaran los grupos de este dia hasta el domingo.'
            : 'Se usaran los grupos de este dia hasta el final de la parada.';
        copyRangeModal.querySelector('[data-copy-range-summary]').textContent = isWeek
            ? 'Los grupos se copiaran desde el siguiente dia hasta el domingo.'
            : 'Los grupos se copiaran desde el siguiente dia hasta el fin de la parada.';
        copyRangeModal.classList.remove('is-hidden');
        copyRangeModal.setAttribute('aria-hidden', 'false');
        copyRangeModal.querySelector('[data-copy-range-submit]')?.focus();
    }));
    root.querySelector('[data-cancel-day]')?.addEventListener('click', event => {
        if (event.currentTarget.disabled || currentGroupCount < 1 || !cancelDayModal) return;
        cancelDayModal.classList.remove('is-hidden');
        cancelDayModal.setAttribute('aria-hidden', 'false');
        cancelDayModal.querySelector('[data-cancel-day-submit]')?.focus();
    });
    document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => hideModal(button.closest('.mpw-modal'))));
    [modal, copyDayModal, copyRangeModal, cancelDayModal].forEach(target => target?.addEventListener('click', event => { if (event.target === target) hideModal(target); }));
    document.querySelectorAll('[data-toast-message]').forEach(message => { toast(message.textContent.trim(), message.classList.contains('is-error') ? 'error' : 'success'); message.remove(); });
    createForm?.addEventListener('submit', rememberWorkspaceState);
    document.querySelector('[data-copy-day-form]')?.addEventListener('submit', rememberWorkspaceState);
    document.querySelector('[data-copy-range-form]')?.addEventListener('submit', event => {
        rememberWorkspaceState();
        const submit = event.currentTarget.querySelector('[data-copy-range-submit]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Copiando...';
        }
    });
    document.querySelector('[data-cancel-day-form]')?.addEventListener('submit', event => {
        rememberWorkspaceState();
        const submit = event.currentTarget.querySelector('[data-cancel-day-submit]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Eliminando...';
        }
    });
    restoreWorkspaceState();
    updateClipboardButtons();
})();
</script>
@endpush
