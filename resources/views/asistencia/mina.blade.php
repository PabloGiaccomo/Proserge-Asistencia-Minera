@extends('layouts.app')

@section('title', 'Asistencia - Por Mina')

@php
$minas = [
    [
        'id' => 'mina-001',
        'nombre' => 'Mina 1',
        'total_programado' => 45,
        'total_presente' => 42,
        'total_ausente' => 3,
        'faltas_dia' => 3,
        'grupos' => 4,
        'grupos_cerrados' => 3,
        'supervisores' => 2,
        'porcentaje' => 93.3,
        'turnos' => [
            ['nombre' => 'Día', 'programado' => 28, 'presente' => 27, 'ausente' => 1],
            ['nombre' => 'Noche', 'programado' => 17, 'presente' => 15, 'ausente' => 2],
        ]
    ],
    [
        'id' => 'mina-002',
        'nombre' => 'Mina 2',
        'total_programado' => 38,
        'total_presente' => 35,
        'total_ausente' => 3,
        'faltas_dia' => 2,
        'grupos' => 3,
        'grupos_cerrados' => 2,
        'supervisores' => 1,
        'porcentaje' => 92.1,
        'turnos' => [
            ['nombre' => 'Día', 'programado' => 22, 'presente' => 21, 'ausente' => 1],
            ['nombre' => 'Noche', 'programado' => 16, 'presente' => 14, 'ausente' => 2],
        ]
    ],
    [
        'id' => 'mina-003',
        'nombre' => 'Mina 3',
        'total_programado' => 28,
        'total_presente' => 24,
        'total_ausente' => 4,
        'faltas_dia' => 2,
        'grupos' => 2,
        'grupos_cerrados' => 1,
        'supervisores' => 1,
        'porcentaje' => 85.7,
        'turnos' => [
            ['nombre' => 'Día', 'programado' => 18, 'presente' => 16, 'ausente' => 2],
            ['nombre' => 'Noche', 'programado' => 10, 'presente' => 8, 'ausente' => 2],
        ]
    ],
];

$comparativo = [
    ['mina' => 'Mina 1', 'hoy' => 93.3, 'ayer' => 91.5, 'semana' => 92.0],
    ['mina' => 'Mina 2', 'hoy' => 92.1, 'ayer' => 94.2, 'semana' => 93.0],
    ['mina' => 'Mina 3', 'hoy' => 85.7, 'ayer' => 88.9, 'semana' => 87.5],
];
@endphp

@section('content')
<div class="asistencia-dashboard">
    <!-- Mina Tabs -->
    <div class="mina-tabs">
        @foreach($minas as $index => $mina)
        <button class="mina-tab {{ $index === 0 ? 'active' : '' }}" onclick="selectMina('{{ $mina['id'] }}')">
            <span class="mina-tab-nombre">{{ $mina['nombre'] }}</span>
            <span class="mina-tab-porcentaje {{ $mina['porcentaje'] >= 90 ? 'texto-success' : ($mina['porcentaje'] >= 80 ? 'texto-warning' : 'texto-danger') }}">
                {{ $mina['porcentaje'] }}%
            </span>
        </button>
        @endforeach
    </div>

    <!-- KPIs for Selected Mina -->
    @foreach($minas as $index => $mina)
    <div class="mina-detail {{ $index === 0 ? '' : 'hidden' }}" id="mina-detail-{{ $mina['id'] }}">
        <div class="kpi-row">
            <div class="kpi-card kpi-highlight">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $mina['total_programado'] }}</span>
                    <span class="kpi-label">Total Programado</span>
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
                    <span class="kpi-value">{{ $mina['total_presente'] }}</span>
                    <span class="kpi-label">Presentes</span>
                </div>
            </div>
            
            <div class="kpi-card kpi-danger">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $mina['total_ausente'] }}</span>
                    <span class="kpi-label">Ausentes</span>
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
                    <span class="kpi-value">{{ $mina['faltas_dia'] }}</span>
                    <span class="kpi-label">Faltas del Día</span>
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
                    <span class="kpi-value">{{ $mina['grupos'] }}/{{ $mina['grupos_cerrados'] }}</span>
                    <span class="kpi-label">Grupos (Abiertos/Cerrados)</span>
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
                <div class="kpi-content">
                    <span class="kpi-value">{{ $mina['supervisores'] }}</span>
                    <span class="kpi-label">Supervisores</span>
                </div>
            </div>
        </div>

        <!-- Turnos Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Asistencia por Turno - {{ $mina['nombre'] }}</span>
            </div>
            <div class="card-body">
                <div class="asistencia-table">
                    <div class="asistencia-table-header">
                        <span>Turno</span>
                        <span>Programado</span>
                        <span>Presente</span>
                        <span>Ausente</span>
                        <span>% Asistencia</span>
                    </div>
                    @foreach($mina['turnos'] as $turno)
                    <div class="asistencia-table-row">
                        <span class="destino-nombre">{{ $turno['nombre'] }}</span>
                        <span>{{ $turno['programado'] }}</span>
                        <span class="texto-presente">{{ $turno['presente'] }}</span>
                        <span class="texto-ausente">{{ $turno['ausente'] }}</span>
                        <span class="porcentaje {{ (($turno['presente'] / $turno['programado'] * 100) >= 90 ? 'porcentaje-alto' : (($turno['presente'] / $turno['programado'] * 100) >= 80 ? 'porcentaje-medio' : 'porcentaje-bajo') }}">
                            {{ round($turno['presente'] / $turno['programado'] * 100, 1) }}%
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endforeach

    <!-- Comparativo entre Minas -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Comparativo entre Minas</span>
        </div>
        <div class="card-body">
            <div class="asistencia-table">
                <div class="asistencia-table-header">
                    <span>Mina</span>
                    <span>Hoy</span>
                    <span>Ayer</span>
                    <span>Prom. Semana</span>
                    <span>Tendencia</span>
                </div>
                @foreach($comparativo as $comp)
                <div class="asistencia-table-row">
                    <span class="destino-nombre">{{ $comp['mina'] }}</span>
                    <span class="porcentaje {{ $comp['hoy'] >= 90 ? 'porcentaje-alto' : ($comp['hoy'] >= 80 ? 'porcentaje-medio' : 'porcentaje-bajo') }}">{{ $comp['hoy'] }}%</span>
                    <span>{{ $comp['ayer'] }}%</span>
                    <span>{{ $comp['semana'] }}%</span>
                    <span class="tendencia {{ $comp['hoy'] >= $comp['ayer'] ? 'tendencia-alza' : 'tendencia-baja' }}">
                        @if($comp['hoy'] >= $comp['ayer'])
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="18 15 12 9 6 15"/>
                        </svg>
                        @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                        @endif
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let minaActiva = 'mina-001';

function selectMina(minaId) {
    document.querySelectorAll('.mina-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.mina-detail').forEach(d => d.classList.add('hidden'));
    
    event.target.closest('.mina-tab').classList.add('active');
    document.getElementById('mina-detail-' + minaId).classList.remove('hidden');
    minaActiva = minaId;
}
</script>
@endpush