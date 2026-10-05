@extends('layouts.app')

@section('title', 'Detalle de asistencia - Proserge')

@php
    $permissions = session('user.permissions', []);
    $canRegister = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'asistencias', 'registrar');
    $canClose = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'asistencias', 'cerrar');
    $canReopen = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'asistencias', 'reabrir');
    $attendance = $grupo['asistencia'] ?? [];
    $metrics = $attendance['metricas'] ?? [];
    $closed = ($attendance['estado'] ?? 'PENDIENTE') === 'CERRADO';
    $stateClasses = ['PRESENTE' => 'success', 'TARDANZA' => 'warning', 'AUSENTE' => 'danger', 'JUSTIFICADO' => 'neutral', 'NO_CORRESPONDE' => 'neutral', 'PENDIENTE' => 'neutral'];
@endphp

@section('content')
<div class="attendance-ops-page">
    @if($grupo)
        <header class="ops-page-header">
            <div>
                <p class="ops-eyebrow">{{ $grupo['mina_nombre'] ?: ($grupo['destino']['nombre'] ?? 'Asistencia') }}</p>
                <h1>{{ $grupo['servicio'] ?: 'Grupo operativo' }}</h1>
                <p>{{ \Carbon\Carbon::parse($grupo['fecha'])->format('d/m/Y') }} · Turno {{ ucfirst(strtolower($grupo['turno'])) }} · Responsable: {{ $grupo['supervisor']['nombre_completo'] ?: 'Sin asignar' }}</p>
            </div>
            <div class="ops-page-actions">
                <a href="{{ route('asistencia.index') }}" class="ops-btn secondary"><svg viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Volver</a>
                @if(!$closed && $canRegister)
                    <a href="{{ route('asistencia.marcar', $grupo['grupo_id']) }}" class="ops-btn primary"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Registrar marcas</a>
                @endif
            </div>
        </header>

        <section class="ops-kpi-strip four">
            <article class="ops-kpi"><span class="ops-kpi-label">Programados</span><strong>{{ $metrics['programado'] ?? count($grupo['integrantes'] ?? []) }}</strong><span>Integrantes activos</span></article>
            <article class="ops-kpi success"><span class="ops-kpi-label">Presentes</span><strong>{{ $metrics['presentes'] ?? 0 }}</strong><span>{{ $metrics['tardanzas'] ?? 0 }} tardanzas</span></article>
            <article class="ops-kpi danger"><span class="ops-kpi-label">Ausentes</span><strong>{{ $metrics['ausentes'] ?? 0 }}</strong><span>{{ $metrics['justificados'] ?? 0 }} justificados</span></article>
            <article class="ops-kpi"><span class="ops-kpi-label">Cobertura</span><strong>{{ number_format($metrics['cobertura'] ?? 0, 1) }}%</strong><span>{{ $closed ? 'Asistencia cerrada' : 'Registro en curso' }}</span></article>
        </section>

        <section class="ops-panel ops-table-panel">
            <div class="ops-panel-head table-head">
                <div><h2>Personal del grupo</h2><p>Estado, hora y responsable de cada marcación.</p></div>
                <div class="ops-page-actions">
                    @if($closed && $canReopen)
                        <form action="{{ route('asistencia.reabrir', $grupo['grupo_id']) }}" method="POST">@csrf<button class="ops-btn secondary" type="submit">Reabrir asistencia</button></form>
                    @elseif(!$closed && $canClose)
                        <form action="{{ route('asistencia.cerrar', $grupo['grupo_id']) }}" method="POST" onsubmit="return confirm('¿Cerrar la asistencia? Las ausencias generarán faltas activas.')">@csrf<button class="ops-btn secondary" type="submit">Cerrar asistencia</button></form>
                    @endif
                </div>
            </div>
            <div class="ops-table-wrap">
                <table class="ops-data-table">
                    <thead><tr><th>Trabajador</th><th>Puesto</th><th>Asignación</th><th>Estado</th><th>Hora</th><th>Observación</th></tr></thead>
                    <tbody>
                        @forelse($grupo['integrantes'] as $person)
                            <tr>
                                <td><strong>{{ $person['nombre_completo'] ?: 'Sin nombre' }}</strong><span>{{ $person['dni'] ?: 'Sin documento' }}</span></td>
                                <td><strong>{{ $person['puesto'] ?: 'Sin puesto' }}</strong></td>
                                <td><strong>{{ $person['posicion_asignacion'] ?: 'Sin clasificar' }}</strong><span>{{ $person['tipo_asignacion'] ?: '' }}</span></td>
                                <td><span class="ops-status {{ $stateClasses[$person['estado_asistencia']] ?? 'neutral' }}">{{ ucfirst(strtolower(str_replace('_', ' ', $person['estado_asistencia']))) }}</span></td>
                                <td><strong>{{ $person['hora_marcado'] ? substr($person['hora_marcado'], 0, 5) : '-' }}</strong></td>
                                <td><span>{{ $person['motivo_estado'] ?: $person['observaciones'] ?: '-' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="ops-empty">Este grupo no tiene integrantes activos.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <section class="ops-panel"><div class="ops-empty">El grupo no existe o no se encuentra dentro de tu alcance.</div></section>
    @endif
</div>
@endsection
