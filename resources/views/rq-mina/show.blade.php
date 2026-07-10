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
    $canEditRq = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'editar');
@endphp

<style>
.rqm-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; margin-bottom:14px; }
.rqm-kpi { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fff; }
.rqm-kpi-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; }
.rqm-kpi-value { font-size:22px; font-weight:700; color:#0f172a; line-height:1.1; }
.rqm-kpi-sub { font-size:12px; color:#64748b; margin-top:5px; }
.rqm-chip { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:600; }
.rqm-chip.borrador { background:#fff7ed; color:#9a3412; }
.rqm-chip.enviado { background:#dcfce7; color:#166534; }
.rqm-chip.cerrado { background:#dbeafe; color:#1d4ed8; }
.rqm-chip.cancelado { background:#fee2e2; color:#991b1b; }
.rqm-progress { width:100%; height:8px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
.rqm-progress > span { display:block; height:100%; background:linear-gradient(90deg,#14b8a6,#0ea5e9); }
.rqm-change-alert { border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:12px; padding:12px 14px; margin-bottom:14px; }
.rqm-change-alert h3 { margin:0 0 8px; color:#9a3412; font-size:15px; }
.rqm-change-alert ul { margin:0; padding-left:18px; display:grid; gap:5px; }
.rqm-change-alert li { font-size:13px; font-weight:600; }
.rqm-change-alert span { color:#c2410c; font-weight:500; }
.rqm-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
.rqm-meta-item { border:1px solid #f1f5f9; border-radius:10px; padding:10px; }
.rqm-meta-label { display:block; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.rqm-meta-value { font-size:14px; color:#0f172a; font-weight:600; margin-top:4px; }
.rqm-transport-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:14px; }
.rqm-transport-summary-item { border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#f8fafc; }
.rqm-transport-summary-item strong { display:block; margin-top:4px; color:#0f172a; font-size:20px; line-height:1.1; }
.rqm-transport-summary-wide { grid-column:1/-1; }
.rqm-transport-chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.rqm-transport-chip { display:inline-flex; align-items:center; border-radius:999px; padding:5px 10px; background:#ecfeff; color:#0e7490; font-size:12px; font-weight:700; }
.rqm-plan-group { border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:14px; }
.rqm-plan-group-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:12px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
.rqm-plan-group-head strong { color:#0f172a; }
.rqm-plan-group-head span { color:#64748b; font-size:12px; }
.rqm-plan-table { width:100%; border-collapse:collapse; min-width:980px; }
.rqm-plan-table th, .rqm-plan-table td { border-bottom:1px solid #eef2f7; padding:8px; font-size:12px; vertical-align:top; text-align:left; }
.rqm-plan-table th { background:#fff; color:#475569; font-size:11px; text-transform:uppercase; }
.rqm-plan-turnos { display:flex; flex-direction:column; gap:3px; min-width:150px; }
.rqm-plan-turnos span { border-radius:6px; background:#f8fafc; padding:3px 5px; color:#475569; }
.rqm-plan-transport { margin:12px; padding:10px; border:1px solid #e2e8f0; border-radius:10px; background:#fcfdff; }
.rqm-plan-transport h4 { margin:0 0 8px; font-size:13px; color:#0f172a; }
.rqm-plan-note { display:block; margin-top:4px; color:#64748b; font-size:11px; line-height:1.35; }
.rqm-plan-badge { display:inline-flex; align-items:center; width:max-content; border-radius:999px; padding:4px 8px; background:#eef2ff; color:#3730a3; font-size:11px; font-weight:700; }
.rqm-plan-badge.warning { background:#fef3c7; color:#92400e; }
.rqm-plan-badge.success { background:#dcfce7; color:#166534; }
</style>

<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Solicitud</h1>
            <p class="page-subtitle">RQ Mina #{{ $item['id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <span class="rqm-chip {{ strtolower((string) ($item['estado'] ?? 'borrador')) }}">{{ ucfirst($item['estado'] ?? 'borrador') }}</span>
            <a href="{{ route('rq-mina.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
            <a href="{{ route('rq-mina.plan', $item['id']) }}" class="btn btn-outline">
                Plan operativo
            </a>
            @if($canEditRq)
                <a href="{{ route('rq-mina.edit', $item['id']) }}" class="btn btn-primary">
                    Editar
                </a>
            @endif
        </div>
    </div>
</div>

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
        <div class="rqm-kpi-label">Transporte requerido</div>
        <div class="rqm-kpi-value">{{ $totalTransporte > 0 ? $totalTransporte : '-' }}</div>
        <div class="rqm-kpi-sub">{{ $transportesCount }} tipo(s) registrados</div>
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
                    <span class="rqm-meta-value">{{ ucfirst($item['estado'] ?? 'borrador') }}</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Personal en parada</span>
                    <span class="rqm-meta-value">{{ count($personalParada) }} persona(s)</span>
                </div>
                <div class="rqm-meta-item">
                    <span class="rqm-meta-label">Transporte solicitado</span>
                    <span class="rqm-meta-value">
                        {{ $totalTransporte > 0 ? $totalTransporte.' unidad(es)' : 'Sin transporte' }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Plan Operativo Semanal</h3>
    </div>
    <div class="card-body">
        @if(!empty($planOperativo))
            @foreach($planOperativo as $grupo)
                <div class="rqm-plan-group">
                    <div class="rqm-plan-group-head">
                        <div>
                            <strong>{{ $grupo['nombre'] ?? 'Grupo' }}</strong>
                            <span>
                                {{ $grupo['area_operativa'] ?? '-' }}
                                @if(!empty($grupo['modulo'])) / {{ $grupo['modulo'] }} @endif
                            </span>
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
                            <h4>Unidades de carga y transporte</h4>
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
            <p class="text-muted">No hay plan operativo semanal registrado para este RQ Mina.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Detalle de Transporte</h3>
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
            <p class="text-muted">No hay transporte registrado para este RQ Mina.</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Detalle del RQ Mina (Puestos Solicitados)</h3>
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
        @if(strtoupper((string) ($item['estado'] ?? '')) !== 'ENVIADO')
            <p class="text-muted">El RQ Mina aun no fue enviado. Cuando se envie y exista seleccion desde RQ Proserge, el personal aparecera aqui.</p>
        @elseif(!empty($personalParada))
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
        @else
            <p class="text-muted">El RQ Mina fue enviado, pero aun no hay personal seleccionado en RQ Proserge para esta parada.</p>
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

@if(session('clear_rq_mina_plan_draft'))
<script>
(function () {
    try {
        window.localStorage.removeItem('rq_mina_plan_draft:' + @json(session('clear_rq_mina_plan_draft')));
    } catch (error) {
        // No bloquea la vista si el navegador no permite almacenamiento local.
    }
})();
</script>
@endif
@endsection
