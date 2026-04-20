@extends('layouts.app')

@section('title', 'Asistencia - Alertas')

@php
$alertas = [
    [
        'tipo' => 'grupo_sin_cerrar',
        'titulo' => 'Grupos sin cerrar',
        'descripcion' => 'Hay 3 grupos abiertos que aún no han sido cerrados',
        'cantidad' => 3,
        'gravedad' => 'warning',
        'icon' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    ],
    [
        'tipo' => 'personal_sin_marcar',
        'titulo' => 'Personal sin marcar',
        'descripcion' => '5 trabajadores no han marcado asistencia hoy',
        'cantidad' => 5,
        'gravedad' => 'danger',
        'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="11" x2="23" y2="11"/>',
    ],
    [
        'tipo' => 'faltas_nuevas',
        'titulo' => 'Faltas generadas hoy',
        'descripcion' => 'Se han generado 8 nuevas faltas en el día',
        'cantidad' => 8,
        'gravedad' => 'danger',
        'icon' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    ],
    [
        'tipo' => 'supervisor_baja_cobertura',
        'titulo' => 'Supervisor con baja cobertura',
        'descripcion' => 'Juan Pérez tiene solo 85% de cobertura en sus grupos',
        'cantidad' => 1,
        'gravedad' => 'warning',
        'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    ],
    [
        'tipo' => 'mina_alta_inasistencia',
        'titulo' => 'Mina con alta inasistencia',
        'descripcion' => 'Mina 3 tiene 15% de inasistencia hoy',
        'cantidad' => 1,
        'gravedad' => 'danger',
        'icon' => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>',
    ],
    [
        'tipo' => 'personal_reincidente',
        'titulo' => 'Personal reincidente',
        'descripcion' => '2 trabajadores con más de 3 faltas esta semana',
        'cantidad' => 2,
        'gravedad' => 'warning',
        'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    ],
];
@endphp

@section('content')
<div class="asistencia-dashboard">
    <!-- Resumen de alertas -->
    <div class="kpi-row">
        <div class="kpi-card kpi-highlight">
            <div class="kpi-content">
                <span class="kpi-value">{{ count($alertas) }}</span>
                <span class="kpi-label">Total Alertas</span>
            </div>
        </div>
        <div class="kpi-card kpi-danger">
            <div class="kpi-content">
                <span class="kpi-value">{{ collect($alertas)->where('gravedad', 'danger')->count() }}</span>
                <span class="kpi-label">Críticas</span>
            </div>
        </div>
        <div class="kpi-card kpi-warning">
            <div class="kpi-content">
                <span class="kpi-value">{{ collect($alertas)->where('gravedad', 'warning')->count() }}</span>
                <span class="kpi-label">Advertencias</span>
            </div>
        </div>
    </div>

    <!-- Lista de alertas -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Alertas del Día</span>
        </div>
        <div class="card-body">
            <div class="alertas-list">
                @foreach($alertas as $alerta)
                <div class="alerta-item alerta-{{ $alerta['gravedad'] }}">
                    <div class="alerta-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            {!! $alerta['icon'] !!}
                        </svg>
                    </div>
                    <div class="alerta-content">
                        <div class="alerta-header">
                            <span class="alerta-titulo">{{ $alerta['titulo'] }}</span>
                            <span class="alerta-cantidad">{{ $alerta['cantidad'] }}</span>
                        </div>
                        <p class="alerta-descripcion">{{ $alerta['descripcion'] }}</p>
                    </div>
                    <div class="alerta-actions">
                        <button class="btn btn-sm btn-outline">Ver detalle</button>
                        <button class="btn btn-sm btn-primary">Acción</button>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.alertas-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.alerta-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px;
    border-radius: 16px;
    border: 1px solid;
}

.alerta-warning {
    background: rgba(245, 158, 11, 0.05);
    border-color: rgba(245, 158, 11, 0.2);
}

.alerta-warning .alerta-icon {
    background: rgba(245, 158, 11, 0.1);
    color: var(--color-warning);
}

.alerta-danger {
    background: rgba(239, 68, 68, 0.05);
    border-color: rgba(239, 68, 68, 0.2);
}

.alerta-danger .alerta-icon {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-danger);
}

.alerta-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.alerta-icon svg {
    width: 24px;
    height: 24px;
}

.alerta-content {
    flex: 1;
}

.alerta-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 4px;
}

.alerta-titulo {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text);
}

.alerta-cantidad {
    padding: 2px 10px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
}

.alerta-warning .alerta-cantidad {
    background: rgba(245, 158, 11, 0.1);
    color: var(--color-warning);
}

.alerta-danger .alerta-cantidad {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-danger);
}

.alerta-descripcion {
    font-size: 13px;
    color: var(--color-text-secondary);
    margin: 0;
}

.alerta-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
</style>
@endpush