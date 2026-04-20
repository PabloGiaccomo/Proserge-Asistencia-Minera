@extends('layouts.app')

@section('title', 'Asistencia - Por Parada')

@php
$paradas = [
    [
        'id' => 'parada-001',
        'nombre' => 'Parada Mina 1 - Mantenimiento',
        'mina' => 'Mina 1',
        'inicio' => '2025-04-10',
        'dias' => 5,
        'estado' => 'activa',
        'total_solicitado' => 45,
        'total_atendido' => 42,
        'grupos' => 4,
        'faltas_dia' => 3,
        'faltas_acumuladas' => 8,
        'porcentaje' => 93.3,
    ],
    [
        'id' => 'parada-002',
        'nombre' => 'Parada Mina 2 - Avería',
        'mina' => 'Mina 2',
        'inicio' => '2025-04-12',
        'dias' => 3,
        'estado' => 'activa',
        'total_solicitado' => 38,
        'total_atendido' => 35,
        'grupos' => 3,
        'faltas_dia' => 2,
        'faltas_acumuladas' => 5,
        'porcentaje' => 92.1,
    ],
    [
        'id' => 'parada-003',
        'nombre' => 'Parada Mina 3 - Inspección',
        'mina' => 'Mina 3',
        'inicio' => '2025-04-08',
        'dias' => 7,
        'estado' => 'cerrada',
        'total_solicitado' => 28,
        'total_atendido' => 24,
        'grupos' => 2,
        'faltas_dia' => 0,
        'faltas_acumuladas' => 4,
        'porcentaje' => 85.7,
    ],
];

$evolucion = [
    ['dia' => 'Día 1', 'asistencia' => 95],
    ['dia' => 'Día 2', 'asistencia' => 92],
    ['dia' => 'Día 3', 'asistencia' => 94],
    ['dia' => 'Día 4', 'asistencia' => 91],
    ['dia' => 'Día 5', 'asistencia' => 93],
];
@endphp

@section('content')
<div class="asistencia-dashboard">
    <!-- Parada Tabs -->
    <div class="mina-tabs">
        @foreach($paradas as $index => $parada)
        <button class="mina-tab {{ $index === 0 ? 'active' : '' }}" onclick="selectParada('{{ $parada['id'] }}')">
            <span class="mina-tab-nombre">{{ $parada['nombre'] }}</span>
            <span class="mina-tab-porcentaje {{ $parada['porcentaje'] >= 90 ? 'texto-success' : ($parada['porcentaje'] >= 80 ? 'texto-warning' : 'texto-danger') }}">
                {{ $parada['porcentaje'] }}%
            </span>
        </button>
        @endforeach
    </div>

    <!-- KPIs for Selected Parada -->
    @foreach($paradas as $index => $parada)
    <div class="mina-detail {{ $index === 0 ? '' : 'hidden' }}" id="parada-detail-{{ $parada['id'] }}">
        <div class="kpi-row">
            <div class="kpi-card kpi-highlight">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $parada['total_solicitado'] }}</span>
                    <span class="kpi-label">Personal Solicitado</span>
                </div>
            </div>
            
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $parada['total_atendido'] }}</span>
                    <span class="kpi-label">Personal Atendido</span>
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $parada['grupos'] }}</span>
                    <span class="kpi-label">Grupos Asignados</span>
                </div>
            </div>
            
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $parada['faltas_dia'] }}</span>
                    <span class="kpi-label">Faltas Hoy</span>
                </div>
            </div>
            
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $parada['faltas_acumuladas'] }}</span>
                    <span class="kpi-label">Faltas Acumuladas</span>
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $parada['dias'] }}</span>
                    <span class="kpi-label">Días Activa</span>
                </div>
            </div>
        </div>

        <!-- Evolución Chart Placeholder -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Evolución de Asistencia - {{ $parada['nombre'] }}</span>
            </div>
            <div class="card-body">
                <div class="evolucion-chart">
                    @foreach($evolucion as $e)
                    <div class="evolucion-bar-container">
                        <div class="evolucion-bar" style="height: {{ $e['asistencia'] }}%;"></div>
                        <span class="evolucion-label">{{ $e['dia'] }}</span>
                        <span class="evolucion-value">{{ $e['asistencia'] }}%</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endsection

@push('scripts')
<script>
function selectParada(paradaId) {
    document.querySelectorAll('.mina-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.mina-detail').forEach(d => d.classList.add('hidden'));
    
    event.target.closest('.mina-tab').classList.add('active');
    document.getElementById('parada-detail-' + paradaId).classList.remove('hidden');
}
</script>
@endpush