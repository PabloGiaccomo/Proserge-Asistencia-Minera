@extends('layouts.app')

@section('title', 'RQ Mina - Detalle')

@section('content')
@php
    $detalle = $item['detalle'] ?? [];
    $transporte = $item['transporte'] ?? [];
    $cambiosPedido = $item['cambios_pedido'] ?? [];
    $supervisor = $item['supervisor'] ?? null;
    $supervisorPets = $item['supervisor_pets'] ?? null;
    $planOperativo = $item['plan_operativo'] ?? [];
    $personalParada = $item['personal_parada'] ?? [];
    $plans = collect($item['plans'] ?? [])->keyBy('id');
    $plansNoArchivados = $plans->reject(static fn (array $plan): bool => ($plan['estado'] ?? '') === 'ARCHIVADO');
    $planInicial = $plansNoArchivados->get($item['plan_inicial_id'] ?? '') ?? $plansNoArchivados->first();
    $solicitudesTransporte = collect($planOperativo)
        ->reject(static fn (array $grupo): bool => ($plans->get($grupo['rq_mina_plan_id'] ?? '')['estado'] ?? '') === 'ARCHIVADO')
        ->flatMap(static fn (array $grupo): array => $grupo['transportes'] ?? []);
    $cantidadSolicitudesTransporte = $solicitudesTransporte->count();
    $unidadesSolicitadas = $solicitudesTransporte->sum(static fn (array $fila): int => max(0, (int) ($fila['cantidad_unidades_requeridas'] ?? 0)));
    $lista = array_filter(\Illuminate\Support\Arr::only((array) request()->query('lista', []), [
        'q', 'mina_id', 'estado', 'created_by_usuario_id', 'fecha_inicio_desde', 'fecha_inicio_hasta',
        'fecha_fin_desde', 'fecha_fin_hasta', 'page', 'per_page',
    ]), static fn ($value): bool => is_scalar($value) && $value !== '');
    $estadoKey = strtolower(str_replace(' ', '_', (string) ($item['estado'] ?? 'borrador')));
    $estadoLabel = match ($estadoKey) {
        'enviado' => 'Pedido registrado en RR.HH.',
        'en_atencion' => 'En atención',
        default => ucfirst(str_replace('_', ' ', $estadoKey)),
    };
    $detalleConTotales = array_map(static function (array $linea): array {
        $cantidad = max(0, (int) ($linea['cantidad'] ?? 0));
        $backup = array_key_exists('cantidad_backup', $linea)
            ? max(0, (int) $linea['cantidad_backup'])
            : (int) round($cantidad * 0.2);
        $total = array_key_exists('cantidad_total', $linea)
            ? max(0, (int) $linea['cantidad_total'])
            : $cantidad + $backup;

        $linea['cantidad'] = $cantidad;
        $linea['cantidad_backup'] = $backup;
        $linea['cantidad_total'] = $total;
        $linea['cantidad_atendida'] = max(0, (int) ($linea['cantidad_atendida'] ?? 0));

        return $linea;
    }, $detalle);
    $totalSolicitado = array_sum(array_map(static fn ($d) => (int) ($d['cantidad'] ?? 0), $detalleConTotales));
    $totalBackup = array_sum(array_map(static fn ($d) => (int) ($d['cantidad_backup'] ?? 0), $detalleConTotales));
    $totalSolicitadoConBackup = array_sum(array_map(static fn ($d) => (int) ($d['cantidad_total'] ?? 0), $detalleConTotales));
    $totalAtendido = array_sum(array_map(static fn ($d) => (int) ($d['cantidad_atendida'] ?? 0), $detalleConTotales));
    $totalPendiente = max(0, $totalSolicitadoConBackup - $totalAtendido);
    $totalTransporte = array_sum(array_map(static fn ($d) => (int) ($d['cantidad'] ?? 0), $transporte));
    $puestosCount = count($detalle);
    $transportesCount = count($transporte);
    $cobertura = $totalSolicitadoConBackup > 0 ? round(($totalAtendido / $totalSolicitadoConBackup) * 100, 1) : 0;
    $fechaInicio = !empty($item['fecha_inicio']) ? \Carbon\Carbon::parse($item['fecha_inicio']) : null;
    $fechaFin = !empty($item['fecha_fin']) ? \Carbon\Carbon::parse($item['fecha_fin']) : null;
    $diasParada = ($fechaInicio && $fechaFin) ? max(1, $fechaInicio->diffInDays($fechaFin) + 1) : null;
    $resumenTransporte = collect($transporte)
        ->map(static function (array $linea): ?string {
            $nombre = trim((string) ($linea['transporte'] ?? ''));
            $cantidad = (int) ($linea['cantidad'] ?? 0);

            return $nombre !== '' && $cantidad > 0 ? $cantidad.' x '.$nombre : null;
        })
        ->filter()
        ->values();
    $transportOriginLabels = [
        'EMPRESA' => 'Empresa',
        'ALQUILADO' => 'Alquilado',
        'OTRO' => 'Otro',
    ];
    $transportStateLabels = [
        'REQUERIDO' => 'Requerido',
        'ASIGNADO' => 'Asignado',
        'EN_USO' => 'En uso',
        'RETIRADO' => 'Retirado',
        'REEMPLAZADO' => 'Reemplazado',
        'DEVUELTO' => 'Devuelto',
        'INCIDENCIA' => 'Incidencia',
    ];
    $transportReceptionLabels = [
        'PENDIENTE' => 'Pendiente',
        'RECIBIDO' => 'Recibido',
        'INCOMPLETO' => 'Incompleto',
        'NO_LLEGO' => 'No llego',
        'CON_OBSERVACION' => 'Con observacion',
    ];
    $rqPermissions = session('user.permissions', []);
    $canEditRq = \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'editar');
    $canSavePlan = $canEditRq
        && \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'actualizar')
        && ($item['puede_actualizar'] ?? false);
    $cargosParaRevision = array_values(array_filter($detalleConTotales, static fn (array $fila): bool =>
        trim((string) ($fila['puesto'] ?? '')) !== '' && $fila['cantidad'] > 0
    ));
    $canRegisterSavedPersonnel = ($item['puede_registrar_rrhh'] ?? false)
        && $estadoKey === 'borrador'
        && \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'ver')
        && \App\Support\Rbac\PermissionMatrix::allowsDirect($rqPermissions, 'rq_mina', 'enviar')
        && !$canSavePlan
        && $cargosParaRevision !== [];
@endphp

<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">{{ $item['area'] ?? 'Detalle de parada' }}</h1>
            <p class="page-subtitle">RQ Mina · {{ $item['lugar'] ?? $item['mina'] ?? 'Lugar sin indicar' }}</p>
        </div>
        <div class="page-actions">
            <span class="rqm-chip {{ $estadoKey }}">{{ $estadoLabel }}</span>
            <a href="{{ route('rq-mina.index', $lista) }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver a paradas
            </a>
            @if($canEditRq)
                <a href="{{ route('rq-mina.plan', ['id' => $item['id'], 'plan_id' => $planInicial['id'] ?? null, 'lista' => $lista]) }}" class="btn btn-primary">
                    {{ $planInicial ? 'Abrir plan operativo' : 'Preparar plan operativo' }}
                </a>
            @endif
            <a href="{{ route('rq-mina.dashboard', ['rqMina' => $item['id'], 'lista' => $lista]) }}" class="btn btn-outline">
                Ver indicadores
            </a>
            @if($canEditRq)
                <a href="{{ route('rq-mina.edit', ['id' => $item['id'], 'lista' => $lista]) }}" class="btn btn-outline">
                    Editar datos de parada
                </a>
            @endif
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-error" role="alert">{{ session('error') }}</div>
@endif

@if($canRegisterSavedPersonnel)
    <details class="card" id="rqRegisterSavedPersonnel">
        <summary class="card-header" style="cursor:pointer;"><span class="btn btn-outline">Registrar pedido en RR.HH. <span aria-hidden="true">&#9662;</span></span></summary>
        <div class="card-body">
            <p>Revisa los cargos ya guardados para <strong>{{ $item['area'] }}</strong>. Al confirmar, RR.HH. podrá atender este pedido en RQ Proserge.</p>
            <ul>
                @foreach($cargosParaRevision as $cargo)
                    <li><strong>{{ $cargo['puesto'] }}</strong> · {{ $cargo['cantidad'] }} base + {{ $cargo['cantidad_backup'] }} back up = {{ $cargo['cantidad_total'] }}</li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('rq-mina.enviar', ['id' => $item['id'], 'lista' => $lista]) }}">
                @csrf
                <button type="submit" class="btn btn-primary">Confirmar registro en RR.HH.</button>
            </form>
        </div>
    </details>
@endif

@if($item)
@if(!empty($cambiosPedido))
<div class="rqm-change-alert">
    <h3>Cambios del pedido para RRHH</h3>
    <ul>
        @foreach($cambiosPedido as $cambio)
            <li>
                {{ $cambio['mensaje'] ?? 'Cambio pendiente en el pedido de personal.' }}
                @if(!empty($cambio['fecha']))
                    <span>({{ $cambio['fecha'] }})</span>
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endif

<div class="rqm-kpis">
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Puestos requeridos</div>
        <div class="rqm-kpi-value">{{ $puestosCount }}</div>
        <div class="rqm-kpi-sub">roles distintos</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Cantidad RQ</div>
        <div class="rqm-kpi-value">{{ $totalSolicitado }}</div>
        <div class="rqm-kpi-sub">personas base</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Back up 20%</div>
        <div class="rqm-kpi-value">{{ $totalBackup }}</div>
        <div class="rqm-kpi-sub">sin decimales</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Total con back up</div>
        <div class="rqm-kpi-value">{{ $totalSolicitadoConBackup }}</div>
        <div class="rqm-kpi-sub">personal requerido</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Total atendido</div>
        <div class="rqm-kpi-value">{{ $totalAtendido }}</div>
        <div class="rqm-kpi-sub">pendiente {{ $totalPendiente }}</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Cobertura</div>
        <div class="rqm-kpi-value">{{ $cobertura }}%</div>
        <div class="rqm-progress" style="margin-top:8px;"><span style="width: {{ min(100, max(0, $cobertura)) }}%"></span></div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Solicitudes de transporte en planes</div>
        <div class="rqm-kpi-value">{{ $cantidadSolicitudesTransporte }}</div>
        <div class="rqm-kpi-sub">{{ $unidadesSolicitadas > 0 ? $unidadesSolicitadas.' unidad(es) solicitada(s)' : ($cantidadSolicitudesTransporte ? 'Cantidad de unidades sin indicar' : 'Sin solicitudes registradas') }}</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Duración</div>
        <div class="rqm-kpi-value">{{ $diasParada ?? '-' }}</div>
        <div class="rqm-kpi-sub">días programados</div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Información General</h3>
        </div>
        <div class="card-body">
            <div class="rqm-meta-grid">
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">ID Solicitud</span>
                    <span class="rqm-meta-value">{{ $item['id'] }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Lugar</span>
                    <span class="rqm-meta-value">{{ $item['lugar'] ?? $item['mina'] ?? '-' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Tipo de lugar</span>
                    <span class="rqm-meta-value">{{ ucfirst(strtolower($item['destino_tipo'] ?? 'MINA')) }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Área / Parada</span>
                    <span class="rqm-meta-value">{{ $item['area'] ?? '-' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Creador</span>
                    <span class="rqm-meta-value">{{ $item['creador'] ?? '-' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Supervisor herramientas</span>
                    <span class="rqm-meta-value">{{ $supervisor['nombre'] ?? '-' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Supervisor PETS</span>
                    <span class="rqm-meta-value">{{ $supervisorPets['nombre'] ?? '-' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Fecha Inicio</span>
                    <span class="rqm-meta-value">{{ $item['fecha_inicio'] ?? '-' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Fecha Fin</span>
                    <span class="rqm-meta-value">{{ $item['fecha_fin'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Contexto de la Solicitud</h3>
        </div>
        <div class="card-body">
            <div class="rqm-meta-grid">
                <div class="rqm-meta-item" style="grid-column:1/-1;">
                    <span class="rqm-meta-label">Observaciones</span>
                    <span class="rqm-meta-value" style="font-weight:500;">{{ $item['observaciones'] ?: 'Sin observaciones registradas.' }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Estado actual</span>
                    <span class="rqm-meta-value">{{ $estadoLabel }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Personal en parada</span>
                    <span class="rqm-meta-value">{{ count($personalParada) }} persona(s)</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Transporte registrado antes</span>
                    <span class="rqm-meta-value">
                        {{ $totalTransporte > 0 ? $totalTransporte.' unidad(es)' : 'Sin registros anteriores' }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Actividades por plan operativo</h3>
    </div>
    <div class="card-body">
        @if($cantidadSolicitudesTransporte)
            <p class="text-muted">Aquí se muestran las solicitudes de cada plan. Los servicios y pasajeros se gestionan en Transporte operativo.</p>
        @endif
        @if(!empty($planOperativo))
            @foreach($planOperativo as $grupo)
                @php
                    $planDelGrupo = $plans->get($grupo['rq_mina_plan_id'] ?? '');
                @endphp
                <div class="rqm-plan-group">
                    <div class="rqm-plan-group-head">
                        <div>
                            <strong>{{ $grupo['nombre'] ?? 'Grupo' }}</strong>
                            <span>
                                {{ $grupo['area_operativa'] ?? '-' }}
                                @if(!empty($grupo['modulo'])) / {{ $grupo['modulo'] }} @endif
                            </span>
                            <small class="rqm-plan-note">
                                {{ $planDelGrupo ? $planDelGrupo['codigo'].' · '.$planDelGrupo['nombre'] : 'Plan inicial · Registro anterior' }}
                                @if(($planDelGrupo['estado'] ?? '') === 'ARCHIVADO') · Archivado @endif
                            </small>
                        </div>
                        <span>{{ count($grupo['actividades'] ?? []) }} actividad(es)</span>
                    </div>
                    @if(!empty($grupo['actividades']))
                        <div class="table-responsive">
                            <table class="rqm-plan-table">
                                <thead>
                                    <tr>
                                        <th>SAIT</th>
                                        <th>Sector / Area</th>
                                        <th>AIT trabajo</th>
                                        <th>Trabajos relevantes</th>
                                        <th>Supervisores</th>
                                        <th>Semana / turnos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($grupo['actividades'] as $actividad)
                                        <tr>
                                            <td>{{ $actividad['sait'] ?? '-' }}</td>
                                            <td>{{ trim(($actividad['sector'] ?? '').' / '.($actividad['area'] ?? ''), ' /') ?: '-' }}</td>
                                            <td>{{ $actividad['ait_trabajo'] ?? '-' }}</td>
                                            <td>{{ $actividad['detalle_trabajos_relevantes'] ?? '-' }}</td>
                                            <td>
                                                <div class="rqm-plan-turnos">
                                                    <span>Campo dia: {{ $actividad['supervisor_campo_dia'] ?? '-' }}</span>
                                                    <span>Campo noche: {{ $actividad['supervisor_campo_noche'] ?? '-' }}</span>
                                                    <span>Seg. dia: {{ $actividad['supervisor_seguridad_dia'] ?? '-' }}</span>
                                                    <span>Seg. noche: {{ $actividad['supervisor_seguridad_noche'] ?? '-' }}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="rqm-plan-turnos">
                                                    @forelse(($actividad['turnos'] ?? []) as $turno)
                                                        @php
                                                            $parts = array_filter([
                                                                !empty($turno['turno_a']) ? 'A: '.$turno['turno_a'] : null,
                                                                !empty($turno['real_turno_a']) ? 'Real A: '.$turno['real_turno_a'] : null,
                                                                !empty($turno['turno_b']) ? 'B: '.$turno['turno_b'] : null,
                                                                !empty($turno['real_turno_b'] ?? $turno['real'] ?? null) ? 'Real B: '.($turno['real_turno_b'] ?? $turno['real']) : null,
                                                            ]);
                                                        @endphp
                                                        <span>{{ $turno['dia_label'] ?: ($turno['fecha'] ?? '-') }} {{ $parts ? '- '.implode(' | ', $parts) : '' }}</span>
                                                    @empty
                                                        <span>Sin turnos</span>
                                                    @endforelse
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if(!empty($grupo['transportes']))
                        <div class="rqm-plan-transport">
                            <h4>Solicitudes de carga y transporte del plan</h4>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Alcance</th>
                                            <th>Unidad / origen</th>
                                            <th>Transporte y placas</th>
                                            <th>Uso</th>
                                            <th>Estado</th>
                                            <th>Retorno</th>
                                            <th>Notas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($grupo['transportes'] as $row)
                                            @php
                                                $estadoLogistico = (string) ($row['estado_logistico'] ?? 'REQUERIDO');
                                                $estadoRecepcion = (string) ($row['recepcion_estado'] ?? 'PENDIENTE');
                                                $estadoClass = in_array($estadoLogistico, ['DEVUELTO'], true) ? 'success' : (in_array($estadoLogistico, ['RETIRADO', 'REEMPLAZADO', 'INCIDENCIA'], true) ? 'warning' : '');
                                                $recepcionClass = in_array($estadoRecepcion, ['RECIBIDO'], true) ? 'success' : ($estadoRecepcion !== 'PENDIENTE' ? 'warning' : '');
                                            @endphp
                                            <tr>
                                                <td>{{ $row['alcance'] ?? '-' }}</td>
                                                <td>
                                                    <strong>{{ $row['unidad_carga'] ?? '-' }}</strong>
                                                    <span class="rqm-plan-note">{{ $transportOriginLabels[$row['origen'] ?? ''] ?? 'Origen no indicado' }}</span>
                                                </td>
                                                <td>
                                                    {{ $row['unidades_transporte'] ?? '-' }}
                                                    @if(!empty($row['placas_asignadas']))
                                                        <span class="rqm-plan-note">Placas: {{ $row['placas_asignadas'] }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    {{ $row['fecha_inicio'] ?? '-' }} al {{ $row['fecha_fin'] ?? '-' }}
                                                    @if(!empty($row['dias_uso']))
                                                        <span class="rqm-plan-note">{{ $row['dias_uso'] }} dia(s) de uso</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="rqm-plan-badge {{ $estadoClass }}">{{ $transportStateLabels[$estadoLogistico] ?? $estadoLogistico }}</span>
                                                </td>
                                                <td>
                                                    <span class="rqm-plan-badge {{ $recepcionClass }}">{{ $transportReceptionLabels[$estadoRecepcion] ?? $estadoRecepcion }}</span>
                                                    @if(!empty($row['recepcion_fecha']))
                                                        <span class="rqm-plan-note">Fecha: {{ $row['recepcion_fecha'] }}</span>
                                                    @endif
                                                    @if(!empty($row['recepcion_observacion']))
                                                        <span class="rqm-plan-note">{{ $row['recepcion_observacion'] }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(!empty($row['comentario_cambio']))
                                                        <span class="rqm-plan-note"><strong>Cambio:</strong> {{ $row['comentario_cambio'] }}</span>
                                                    @endif
                                                    @if(!empty($row['incidencia_operativa']))
                                                        <span class="rqm-plan-note"><strong>Incidencia:</strong> {{ $row['incidencia_operativa'] }}</span>
                                                    @endif
                                                    @if(empty($row['comentario_cambio']) && empty($row['incidencia_operativa']))
                                                        -
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        @else
            <p class="text-muted">Esta parada todavía no tiene actividades programadas.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Registros anteriores de transporte</h3>
    </div>
    <div class="card-body">
        @if(!empty($transporte))
            <div class="rqm-transport-summary">
                <div class="rqm-transport-summary-item">
                    <span class="rqm-meta-label">Unidades solicitadas</span>
                    <strong>{{ $totalTransporte }}</strong>
                </div>
                <div class="rqm-transport-summary-item">
                    <span class="rqm-meta-label">Tipos de transporte</span>
                    <strong>{{ $transportesCount }}</strong>
                </div>
                <div class="rqm-transport-summary-item rqm-transport-summary-wide">
                    <span class="rqm-meta-label">Resumen</span>
                    <div class="rqm-transport-chips">
                        @foreach($resumenTransporte as $resumen)
                            <span class="rqm-transport-chip">{{ $resumen }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transporte</th>
                            <th>Cantidad Solicitada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transporte as $linea)
                        <tr>
                            <td>{{ $linea['transporte'] ?? '-' }}</td>
                            <td>{{ (int) ($linea['cantidad'] ?? 0) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted">No hay registros anteriores. Las solicitudes actuales se muestran en cada plan. Los servicios se gestionan en Transporte operativo.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Pedido de personal para RR.HH.</h3>
    </div>
    <div class="card-body">
        @if(!empty($detalleConTotales))
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Puesto</th>
                            <th>Cantidad RQ</th>
                            <th>Back up 20%</th>
                            <th>Total con back up</th>
                            <th>Entregado por RRHH</th>
                            <th>Pendiente</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($detalleConTotales as $linea)
                        @php
                            $solicitada = (int) ($linea['cantidad'] ?? 0);
                            $backup = (int) ($linea['cantidad_backup'] ?? round($solicitada * 0.2));
                            $totalLinea = (int) ($linea['cantidad_total'] ?? ($solicitada + $backup));
                            $atendida = (int) ($linea['cantidad_atendida'] ?? 0);
                            $pendiente = max(0, $totalLinea - $atendida);
                        @endphp
                        <tr>
                            <td>{{ $linea['puesto'] ?? '-' }}</td>
                            <td>{{ $solicitada }}</td>
                            <td>{{ $backup }}</td>
                            <td>{{ $totalLinea }}</td>
                            <td>{{ $atendida }}</td>
                            <td>{{ $pendiente }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted">No hay puestos registrados para este RQ Mina.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Personal Seleccionado para la Parada</h3>
    </div>
    <div class="card-body">
        @if(!empty($personalParada))
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Puesto</th>
                            <th>Cargo en la Parada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($personalParada as $persona)
                        <tr>
                            <td>{{ $persona['nombre'] ?? '-' }}</td>
                            <td>{{ $persona['puesto'] ?? '-' }}</td>
                            <td>{{ $persona['cargo_parada'] ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif($estadoKey === 'borrador')
            <p class="text-muted">El pedido de personal está pendiente de registrar en RR.HH.</p>
        @else
            <p class="text-muted">Todavía no hay personal seleccionado en RQ Proserge para esta parada.</p>
        @endif
    </div>
</div>
@else
<div class="card">
    <div class="card-body">
        @include('components.empty-state', [
            'message' => 'Solicitud no encontrada',
            'description' => 'La solicitud que buscas no existe'
        ])
    </div>
</div>
@endif

@endsection
