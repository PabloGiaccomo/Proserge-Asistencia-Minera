@extends('layouts.app')

@section('title', 'Asistencia - Por Supervisor')

@php
$supervisores = [
    [
        'id' => 'sup-001',
        'nombre' => 'Juan Pérez',
        'dni' => '23456789',
        'mina' => 'Mina 1',
        'grupos' => 2,
        'personal' => 24,
        'asistencia_hoy' => 23,
        'faltas_acumuladas' => 1,
        'porcentaje' => 95.8,
        'promedio_eval' => 4.5,
    ],
    [
        'id' => 'sup-002',
        'nombre' => 'María García',
        'dni' => '34567890',
        'mina' => 'Mina 1',
        'grupos' => 2,
        'personal' => 21,
        'asistencia_hoy' => 19,
        'faltas_acumuladas' => 2,
        'porcentaje' => 90.5,
        'promedio_eval' => 4.2,
    ],
    [
        'id' => 'sup-003',
        'nombre' => 'Luis Cóndor',
        'dni' => '45678231',
        'mina' => 'Mina 2',
        'grupos' => 2,
        'personal' => 23,
        'asistencia_hoy' => 21,
        'faltas_acumuladas' => 2,
        'porcentaje' => 91.3,
        'promedio_eval' => 4.3,
    ],
    [
        'id' => 'sup-004',
        'nombre' => 'Ana Torres',
        'dni' => '56789012',
        'mina' => 'Mina 2',
        'grupos' => 1,
        'personal' => 15,
        'asistencia_hoy' => 14,
        'faltas_acumuladas' => 1,
        'porcentaje' => 93.3,
        'promedio_eval' => 4.6,
    ],
];
@endphp

@section('content')
<div class="asistencia-dashboard">
    <!-- Supervisor KPI Cards -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Desempeño por Supervisor</span>
        </div>
        <div class="card-body">
            <div class="supervisor-grid">
                @foreach($supervisores as $sup)
                <div class="supervisor-card">
                    <div class="supervisor-header">
                        <div class="supervisor-avatar">{{ strtoupper(substr($sup['nombre'], 0, 2)) }}</div>
                        <div class="supervisor-info">
                            <div class="supervisor-nombre">{{ $sup['nombre'] }}</div>
                            <div class="supervisor-meta">{{ $sup['mina'] }}</div>
                        </div>
                        <span class="person-badge mine">{{ $sup['mina'] }}</span>
                    </div>
                    <div class="supervisor-stats">
                        <div class="supervisor-stat">
                            <span class="stat-value">{{ $sup['grupos'] }}</span>
                            <span class="stat-label">Grupos</span>
                        </div>
                        <div class="supervisor-stat">
                            <span class="stat-value">{{ $sup['personal'] }}</span>
                            <span class="stat-label">Personal</span>
                        </div>
                        <div class="supervisor-stat">
                            <span class="stat-value {{ $sup['porcentaje'] >= 90 ? 'texto-success' : ($sup['porcentaje'] >= 80 ? 'texto-warning' : 'texto-danger') }}">{{ $sup['porcentaje'] }}%</span>
                            <span class="stat-label">Asistencia</span>
                        </div>
                        <div class="supervisor-stat">
                            <span class="stat-value">{{ $sup['faltas_acumuladas'] }}</span>
                            <span class="stat-label">Faltas</span>
                        </div>
                    </div>
                    <div class="supervisor-eval">
                        <span class="eval-label">Promedio Evaluación</span>
                        <div class="eval-stars">
                            @for($i = 1; $i <= 5; $i++)
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="{{ $i <= round($sup['promedio_eval']) ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" class="{{ $i <= round($sup['promedio_eval']) ? 'star-filled' : '' }}">
                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                            </svg>
                            @endfor
                            <span class="eval-value">{{ number_format($sup['promedio_eval'], 1) }}</span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Tabla comparativa -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Comparativa de Supervisores</span>
        </div>
        <div class="card-body">
            <div class="asistencia-table">
                <div class="asistencia-table-header">
                    <span>Supervisor</span>
                    <span>Mina</span>
                    <span>Grupos</span>
                    <span>Personal</span>
                    <span>Asistencia</span>
                    <span>Faltas</span>
                    <span>Evaluación</span>
                </div>
                @foreach($supervisores as $sup)
                <div class="asistencia-table-row">
                    <span class="destino-nombre">{{ $sup['nombre'] }}</span>
                    <span>{{ $sup['mina'] }}</span>
                    <span>{{ $sup['grupos'] }}</span>
                    <span>{{ $sup['personal'] }}</span>
                    <span class="porcentaje {{ $sup['porcentaje'] >= 90 ? 'porcentaje-alto' : ($sup['porcentaje'] >= 80 ? 'porcentaje-medio' : 'porcentaje-bajo') }}">{{ $sup['porcentaje'] }}%</span>
                    <span class="texto-ausente">{{ $sup['faltas_acumuladas'] }}</span>
                    <span class="texto-success">{{ number_format($sup['promedio_eval'], 1) }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.supervisor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.supervisor-card {
    background: #F8FAFC;
    border-radius: 16px;
    padding: 20px;
    border: 1px solid #E2E8F0;
}

.supervisor-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.supervisor-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--color-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
}

.supervisor-info {
    flex: 1;
}

.supervisor-nombre {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text);
}

.supervisor-meta {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.supervisor-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding-bottom: 16px;
    border-bottom: 1px solid #E2E8F0;
    margin-bottom: 12px;
}

.supervisor-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.stat-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--color-text);
}

.stat-label {
    font-size: 11px;
    color: var(--color-text-secondary);
}

.supervisor-eval {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.eval-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-secondary);
}

.eval-stars {
    display: flex;
    align-items: center;
    gap: 2px;
}

.eval-stars svg {
    color: #FBBF24;
}

.star-filled {
    fill: currentColor;
}

.eval-value {
    margin-left: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text);
}
</style>
@endpush