@extends('layouts.app')

@section('title', 'RQ Mina - Detalle')

@section('content')
@php
    $detalle = $item['detalle'] ?? [];
    $personalParada = $item['personal_parada'] ?? [];
    $totalSolicitado = array_sum(array_map(static fn ($d) => (int) ($d['cantidad'] ?? 0), $detalle));
    $totalAtendido = array_sum(array_map(static fn ($d) => (int) ($d['cantidad_atendida'] ?? 0), $detalle));
    $totalPendiente = max(0, $totalSolicitado - $totalAtendido);
    $puestosCount = count($detalle);
    $cobertura = $totalSolicitado > 0 ? round(($totalAtendido / $totalSolicitado) * 100, 1) : 0;
    $fechaInicio = !empty($item['fecha_inicio']) ? \Carbon\Carbon::parse($item['fecha_inicio']) : null;
    $fechaFin = !empty($item['fecha_fin']) ? \Carbon\Carbon::parse($item['fecha_fin']) : null;
    $diasParada = ($fechaInicio && $fechaFin) ? max(1, $fechaInicio->diffInDays($fechaFin) + 1) : null;
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
.rqm-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
.rqm-meta-item { border:1px solid #f1f5f9; border-radius:10px; padding:10px; }
.rqm-meta-label { display:block; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.rqm-meta-value { font-size:14px; color:#0f172a; font-weight:600; margin-top:4px; }
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
            <a href="{{ route('rq-mina.edit', $item['id']) }}" class="btn btn-primary">
                Editar
            </a>
        </div>
    </div>
</div>

@if($item)
<div class="rqm-kpis">
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Puestos requeridos</div>
        <div class="rqm-kpi-value">{{ $puestosCount }}</div>
        <div class="rqm-kpi-sub">roles distintos</div>
    </div>
    <div class="rqm-kpi">
        <div class="rqm-kpi-label">Total solicitado</div>
        <div class="rqm-kpi-value">{{ $totalSolicitado }}</div>
        <div class="rqm-kpi-sub">personas</div>
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
                    <span class="rqm-meta-label">Mina</span>
                    <span class="rqm-meta-value">{{ $item['mina'] ?? '-' }}</span>
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
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Detalle del RQ Mina (Puestos Solicitados)</h3>
    </div>
    <div class="card-body">
        @if(!empty($detalle))
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Puesto</th>
                            <th>Cantidad Solicitada</th>
                            <th>Cantidad Atendida</th>
                            <th>Pendiente</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($detalle as $linea)
                        @php
                            $solicitada = (int) ($linea['cantidad'] ?? 0);
                            $atendida = (int) ($linea['cantidad_atendida'] ?? 0);
                            $pendiente = max(0, $solicitada - $atendida);
                        @endphp
                        <tr>
                            <td>{{ $linea['puesto'] ?? '-' }}</td>
                            <td>{{ $solicitada }}</td>
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
        @if(($item['estado'] ?? '') !== 'enviado')
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
@endsection
