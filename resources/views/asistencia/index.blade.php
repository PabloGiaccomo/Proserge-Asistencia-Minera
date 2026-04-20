@extends('layouts.app')

@section('title', 'Asistencia - Proserge')

@php
$activeTab = request()->query('tab', 'resumen');
$tabs = [
    'resumen' => 'Resumen',
    'dia' => 'Por día',
    'semana' => 'Por semana',
    'parada' => 'Por parada',
    'mina' => 'Por mina',
    'supervisor' => 'Por supervisor',
];

$kpis = [
    'programados' => 142,
    'presentes' => 128,
    'ausentes' => 14,
    'faltas' => 8,
    'porcentaje' => 90.1,
    'grupos_pendientes' => 5,
];

$porMina = [
    ['nombre' => 'Mina 1', 'programado' => 45, 'presente' => 42, 'ausente' => 3, 'porcentaje' => 93.3],
    ['nombre' => 'Mina 2', 'programado' => 38, 'presente' => 35, 'ausente' => 3, 'porcentaje' => 92.1],
    ['nombre' => 'Mina 3', 'programado' => 28, 'presente' => 24, 'ausente' => 4, 'porcentaje' => 85.7],
    ['nombre' => 'Taller', 'programado' => 18, 'presente' => 17, 'ausente' => 1, 'porcentaje' => 94.4],
    ['nombre' => 'Oficina', 'programado' => 13, 'presente' => 10, 'ausente' => 3, 'porcentaje' => 76.9],
];

$alertas = [
    ['tipo' => 'mina_baja', 'titulo' => 'Mina con baja asistencia', 'valor' => 'Oficina 76.9%', 'gravedad' => 'danger'],
    ['tipo' => 'grupo_abierto', 'titulo' => 'Grupos sin cerrar', 'valor' => '5 grupos', 'gravedad' => 'warning'],
    ['tipo' => 'mejor_supervisor', 'titulo' => 'Mejor supervisor', 'valor' => 'Juan Pérez 98%', 'gravedad' => 'success'],
    ['tipo' => 'faltas_sin_justificar', 'titulo' => 'Faltas sin justificar', 'valor' => '3 workers', 'gravedad' => 'warning'],
];

$supervisores = [
    ['nombre' => 'Juan Pérez', 'asistencia' => 98, 'personal' => 15, 'faltas' => 0],
    ['nombre' => 'María García', 'asistencia' => 95, 'personal' => 12, 'faltas' => 1],
    ['nombre' => 'Luis Cóndor', 'asistencia' => 91, 'personal' => 18, 'faltas' => 2],
    ['nombre' => 'Ana Torres', 'asistencia' => 88, 'personal' => 10, 'faltas' => 2],
];

$trabajadoresProblema = [
    ['nombre' => 'Pedro Asto Yupanqui', 'dni' => '70125489', 'faltas' => 6, 'asistencia' => 75],
    ['nombre' => 'Ana María Lucero', 'dni' => '82365412', 'faltas' => 5, 'asistencia' => 78],
];

$mejorTrabajador = ['nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '45678231', 'asistencia' => 100, 'eval' => 4.9];

$porDia = [
    [
        'fecha' => '2026-04-15',
        'grupos' => [
            ['mina' => 'Mina 1', 'parada' => 'Parada 001', 'supervisor' => 'Juan Pérez', 'programados' => 45, 'presentes' => 42, 'ausentes' => 3, 'porcentaje' => 93.3],
            ['mina' => 'Mina 1', 'parada' => 'Parada 001', 'supervisor' => 'María García', 'programados' => 12, 'presentes' => 11, 'ausentes' => 1, 'porcentaje' => 91.7],
            ['mina' => 'Mina 2', 'parada' => 'Parada 002', 'supervisor' => 'Luis Cóndor', 'programados' => 38, 'presentes' => 35, 'ausentes' => 3, 'porcentaje' => 92.1],
            ['mina' => 'Mina 3', 'parada' => 'Parada 002', 'supervisor' => 'Ana Torres', 'programados' => 28, 'presentes' => 24, 'ausentes' => 4, 'porcentaje' => 85.7],
            ['mina' => 'Taller', 'parada' => 'Parada 003', 'supervisor' => 'Jorge Mamani', 'programados' => 18, 'presentes' => 17, 'ausentes' => 1, 'porcentaje' => 94.4],
        ]
    ],
    [
        'fecha' => '2026-04-14',
        'grupos' => [
            ['mina' => 'Mina 1', 'parada' => 'Parada 001', 'supervisor' => 'Juan Pérez', 'programados' => 42, 'presentes' => 38, 'ausentes' => 4, 'porcentaje' => 90.5],
            ['mina' => 'Mina 2', 'parada' => 'Parada 002', 'supervisor' => 'Luis Cóndor', 'programados' => 35, 'presentes' => 32, 'ausentes' => 3, 'porcentaje' => 91.4],
            ['mina' => 'Mina 3', 'parada' => 'Parada 003', 'supervisor' => 'Ana Torres', 'programados' => 25, 'presentes' => 22, 'ausentes' => 3, 'porcentaje' => 88.0],
            ['mina' => 'Taller', 'parada' => 'Parada 003', 'supervisor' => 'Jorge Mamani', 'programados' => 16, 'presentes' => 15, 'ausentes' => 1, 'porcentaje' => 93.8],
        ]
    ],
    [
        'fecha' => '2026-04-13',
        'grupos' => [
            ['mina' => 'Mina 1', 'parada' => 'Parada 001', 'supervisor' => 'Juan Pérez', 'programados' => 48, 'presentes' => 44, 'ausentes' => 4, 'porcentaje' => 91.7],
            ['mina' => 'Mina 2', 'parada' => 'Parada 002', 'supervisor' => 'María García', 'programados' => 36, 'presentes' => 33, 'ausentes' => 3, 'porcentaje' => 91.7],
            ['mina' => 'Taller', 'parada' => 'Parada 003', 'supervisor' => 'Jorge Mamani', 'programados' => 17, 'presentes' => 15, 'ausentes' => 2, 'porcentaje' => 88.2],
        ]
    ],
];

$diaDetail = [
    'fecha' => '2026-04-15',
    'mina' => 'Mina 1',
    'parada' => 'Parada 001',
    'supervisor' => ['nombre' => 'Juan Pérez', 'dni' => '45678231', 'telefono' => '987654321'],
    'personal' => [
        ['nombre' => 'Carlos Alberto López Mamani', 'dni' => '70125489', 'estado' => 'presente'],
        ['nombre' => 'Pedro Asto Yupanqui', 'dni' => '82365412', 'estado' => 'presente'],
        ['nombre' => 'María Elena García', 'dni' => '61234567', 'estado' => 'presente'],
        ['nombre' => 'Jorge Eduardo Tito Flores', 'dni' => '55678901', 'estado' => 'presente'],
        ['nombre' => 'Ana Lucía Quispe Mamani', 'dni' => '47890123', 'estado' => 'presente'],
        ['nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '89012345', 'estado' => 'ausente'],
        ['nombre' => 'Carmen Rosa Torres Flores', 'dni' => '73456789', 'estado' => 'presente'],
        ['nombre' => 'Roberto Carlos Huanca Lima', 'dni' => '61237890', 'estado' => 'presente'],
    ],
    'presentes' => 42,
    'ausentes' => 3,
    'programados' => 45,
];

$porSemana = [
    ['semana' => 'Sem 15', 'inicio' => '14 Abr', 'fin' => '20 Abr', 'programados' => 710, 'presentes' => 638, 'ausentes' => 72, 'porcentaje' => 89.9],
    ['semana' => 'Sem 14', 'inicio' => '07 Abr', 'fin' => '13 Abr', 'programados' => 695, 'presentes' => 625, 'ausentes' => 70, 'porcentaje' => 89.9],
    ['semana' => 'Sem 13', 'inicio' => '31 Mar', 'fin' => '06 Abr', 'programados' => 700, 'presentes' => 620, 'ausentes' => 80, 'porcentaje' => 88.6],
    ['semana' => 'Sem 12', 'inicio' => '24 Mar', 'fin' => '30 Mar', 'programados' => 688, 'presentes' => 615, 'ausentes' => 73, 'porcentaje' => 89.4],
];

$paradas = [
    ['nombre' => 'Parada 001', 'fecha' => '15 Abr', 'programados' => 85, 'presentes' => 78, 'ausentes' => 7, 'porcentaje' => 91.8, 'estado' => 'activa'],
    ['nombre' => 'Parada 002', 'fecha' => '10 Abr', 'programados' => 92, 'presentes' => 85, 'ausentes' => 7, 'porcentaje' => 92.4, 'estado' => 'cerrada'],
    ['nombre' => 'Parada 003', 'fecha' => '05 Abr', 'programados' => 78, 'presentes' => 70, 'ausentes' => 8, 'porcentaje' => 89.7, 'estado' => 'cerrada'],
];

$minaDetail = [
    ['nombre' => 'Mina 1', 'supervisor' => 'Juan Pérez', 'programados' => 45, 'presentes' => 42, 'ausentes' => 3, 'porcentaje' => 93.3, 'faltas' => 2],
    ['nombre' => 'Mina 2', 'supervisor' => 'María García', 'programados' => 38, 'presentes' => 35, 'ausentes' => 3, 'porcentaje' => 92.1, 'faltas' => 1],
    ['nombre' => 'Mina 3', 'supervisor' => 'Luis Cóndor', 'programados' => 28, 'presentes' => 24, 'ausentes' => 4, 'porcentaje' => 85.7, 'faltas' => 3],
];
@endphp

@section('content')
<div class="asistencia-module">
    <!-- Header -->
    <div class="module-header">
        <div class="header-left">
            <h1 class="module-title">Módulo de Asistencia</h1>
            <p class="module-subtitle">Análisis operativo y control de asistencia</p>
        </div>
    </div>

    <!-- Tabs de Navegación -->
    <div class="tabs-container">
        <div class="tabs-nav">
            @foreach($tabs as $key => $label)
            <a href="?tab={{ $key }}" class="tab-item {{ $activeTab === $key ? 'active' : '' }}">
                <span class="tab-label">{{ $label }}</span>
                @if($key === 'resumen' && $kpis['grupos_pendientes'] > 0)
                <span class="tab-badge">{{ $kpis['grupos_pendientes'] }}</span>
                @endif
            </a>
            @endforeach
        </div>
    </div>

    <!-- Filtros -->
    <div class="filters-bar">
        <div class="filter-group">
            <label class="filter-label">Fecha</label>
            <input type="date" class="filter-input" value="{{ date('Y-m-d') }}">
        </div>
        @if($activeTab === 'semana')
        <div class="filter-group">
            <label class="filter-label">Semana</label>
            <select class="filter-select">
                <option value="15">Semana 15 (14-20 Abr)</option>
                <option value="14">Semana 14 (07-13 Abr)</option>
                <option value="13">Semana 13 (31 Mar-06 Abr)</option>
            </select>
        </div>
        @endif
        @if(in_array($activeTab, ['parada', 'mina', 'supervisor']))
        <div class="filter-group">
            <label class="filter-label">{{ $activeTab === 'parada' ? 'Parada' : ($activeTab === 'mina' ? 'Mina' : 'Supervisor') }}</label>
            <select class="filter-select">
                <option value="">Todos</option>
                @if($activeTab === 'parada')
                @foreach($paradas as $p)<option value="{{ $p['nombre'] }}">{{ $p['nombre'] }}</option>@endforeach
                @elseif($activeTab === 'mina')
                @foreach($porMina as $m)<option value="{{ $m['nombre'] }}">{{ $m['nombre'] }}</option>@endforeach
                @else
                @foreach($supervisores as $s)<option value="{{ $s['nombre'] }}">{{ $s['nombre'] }}</option>@endforeach
                @endif
            </select>
        </div>
        @endif
        <div class="filter-group">
            <label class="filter-label">Turno</label>
            <select class="filter-select">
                <option value="">Todos</option>
                <option value="dia">Día</option>
                <option value="noche">Noche</option>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn-filter">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Aplicar
            </button>
            <button class="btn-filter-outline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exportar
            </button>
        </div>
    </div>

    <!-- Contenido por Pestaña -->
    <div class="tabs-content">
        @if($activeTab === 'resumen')
        <!-- RESUMEN -->
        <div class="content-section">
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-icon present"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <div class="kpi-data"><span class="kpi-value">{{ $kpis['presentes'] }}</span><span class="kpi-label">Presentes</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon absent"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
                    <div class="kpi-data"><span class="kpi-value">{{ $kpis['ausentes'] }}</span><span class="kpi-label">Ausentes</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-programado"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div class="kpi-data"><span class="kpi-value">{{ $kpis['programados'] }}</span><span class="kpi-label">Programados</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon {{ $kpis['faltas'] > 5 ? 'danger' : 'warning' }}"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                    <div class="kpi-data"><span class="kpi-value">{{ $kpis['faltas'] }}</span><span class="kpi-label">Faltas</span></div>
                </div>
                <div class="kpi-card kpi-pct">
                    <div class="kpi-icon success"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-4-8 4v7c0 6 8 10 8 10z"/></svg></div>
                    <div class="kpi-data"><span class="kpi-value kpi-gradient">{{ $kpis['porcentaje'] }}%</span><span class="kpi-label">Asistencia</span></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="panel section-left">
                    <div class="panel-head"><h3 class="panel-title">Estado por Mina</h3></div>
                    <div class="panel-body">
                        @foreach($porMina as $mina)
                        @php $color = $mina['porcentaje'] >= 90 ? 'emerald' : ($mina['porcentaje'] >= 80 ? 'amber' : 'red'); @endphp
                        <div class="miner-item">
                            <div class="miner-info"><span class="miner-name">{{ $mina['nombre'] }}</span><span class="miner-stats">{{ $mina['presente'] }}/{{ $mina['programado'] }}</span></div>
                            <div class="miner-bar"><div class="miner-fill bg-{{ $color }}-500" style="width: {{ $mina['porcentaje'] }}%"></div></div>
                            <span class="miner-pct text-{{ $color }}-600">{{ $mina['porcentaje'] }}%</span>
                        </div>
                        @endforeach
                    </div>
                </div>

                <div class="panel section-right">
                    <div class="panel-head"><h3 class="panel-title">Alertas</h3></div>
                    <div class="panel-body">
                        @foreach($alertas as $alert)
                        <div class="alert-card alert-{{ $alert['gravedad'] }}">
                            <div class="alert-icon">@if($alert['gravedad'] === 'success')<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>@elseif($alert['gravedad'] === 'warning')<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>@else<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>@endif</div>
                            <div class="alert-text"><span class="alert-title">{{ $alert['titulo'] }}</span><span class="alert-value">{{ $alert['valor'] }}</span></div>
                        </div>
                        @endforeach
                    </div>
                </div>

                <div class="panel bottom-panel">
                    <div class="panel-head"><h3 class="panel-title">Top Supervisores</h3><span class="badge">HOY</span></div>
                    <div class="panel-body">
                        @foreach($supervisores as $i => $s)
                        <div class="rank-row"><span class="rank-num">#{{ $i+1 }}</span><div class="rank-avatar">{{ strtoupper(substr($s['nombre'], 0, 2)) }}</div><div class="rank-info"><span class="rank-name">{{ $s['nombre'] }}</span><span class="rank-meta">{{ $s['personal'] }} workers</span></div><span class="rank-pct text-{{ $s['asistencia'] >= 90 ? 'emerald' : ($s['asistencia'] >= 80 ? 'amber' : 'red') }}-600">{{ $s['asistencia'] }}%</span></div>
                        @endforeach
                    </div>
                </div>

                <div class="panel bottom-panel">
                    <div class="panel-head"><h3 class="panel-title">Trabajadores con Faltas</h3><span class="badge-danger">Atención</span></div>
                    <div class="panel-body">
                        @foreach($trabajadoresProblema as $t)
                        <div class="fault-row"><div class="fault-avatar">{{ strtoupper(substr($t['nombre'], 0, 2)) }}</div><div class="fault-info"><span class="fault-name">{{ $t['nombre'] }}</span><span class="fault-dni">DNI: {{ $t['dni'] }}</span></div><div class="fault-stats"><span class="fault-count">{{ $t['faltas'] }}</span><span class="fault-pct text-{{ $t['asistencia'] < 80 ? 'red' : 'gray' }}-600">{{ $t['asistencia'] }}%</span></div></div>
                        @endforeach
                    </div>
                </div>

                <div class="panel bottom-panel">
                    <div class="panel-head"><h3 class="panel-title">Mejor Trabajador</h3><span class="badge-success">HOY</span></div>
                    <div class="panel-body">
                        <div class="best-worker"><div class="best-avatar">{{ strtoupper(substr($mejorTrabajador['nombre'], 0, 2)) }}</div><div class="best-info"><span class="best-name">{{ $mejorTrabajador['nombre'] }}</span><span class="best-dni">DNI: {{ $mejorTrabajador['dni'] }}</span></div></div>
                        <div class="best-stats"><div class="best-stat"><span class="best-val text-emerald-600">{{ $mejorTrabajador['asistencia'] }}%</span><span class="best-lbl">Asistencia</span></div><div class="best-stat"><span class="best-val">{{ $mejorTrabajador['eval'] }}</span><span class="best-lbl">Evaluación</span></div></div>
                    </div>
                </div>
            </div>
        </div>

        @elseif($activeTab === 'dia')
        <?php
        $selectedFecha = request()->query('fecha');
        $showDetail = !empty($selectedFecha);
        $diaData = null;
        if ($showDetail) {
            foreach ($porDia as $d) {
                if ($d['fecha'] === $selectedFecha) {
                    $diaData = $d;
                    break;
                }
            }
        }
        ?>
        <div class="content-section">
            @if($showDetail && $diaData)
            <!-- Vista de Detalle -->
            <div class="detail-header">
                <a href="?tab=dia" class="back-link">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    Volver al listado
                </a>
            </div>

            <div class="detail-info">
                <div class="detail-card">
                    <div class="detail-label">Fecha</div>
                    <div class="detail-value">{{ $diaData['fecha'] }}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Mina</div>
                    <div class="detail-value">{{ $diaDetail['mina'] }}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Parada</div>
                    <div class="detail-value">{{ $diaDetail['parada'] }}</div>
                </div>
            </div>

            <div class="detail-supervisor">
                <div class="supervisor-avatar">{{ strtoupper(substr($diaDetail['supervisor']['nombre'], 0, 2)) }}</div>
                <div class="supervisor-info">
                    <div class="supervisor-name">{{ $diaDetail['supervisor']['nombre'] }}</div>
                    <div class="supervisor-meta">DNI: {{ $diaDetail['supervisor']['dni'] }} | Telf: {{ $diaDetail['supervisor']['telefono'] }}</div>
                </div>
            </div>

            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value">{{ $diaDetail['programados'] }}</span><span class="kpi-label">Programados</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-emerald-600">{{ $diaDetail['presentes'] }}</span><span class="kpi-label">Presentes</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-red-600">{{ $diaDetail['ausentes'] }}</span><span class="kpi-label">Ausentes</span></div></div>
                <div class="kpi-card kpi-pct"><div class="kpi-data"><span class="kpi-value">{{ round($diaDetail['presentes'] / $diaDetail['programados'] * 100, 1) }}%</span><span class="kpi-label">% Asistencia</span></div></div>
            </div>

            <div class="panel full-width">
                <div class="panel-head"><h3 class="panel-title">Personal</h3></div>
                <div class="panel-body">
                    <table class="data-table">
                        <thead><tr><th>#</th><th>Trabajador</th><th>DNI</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach($diaDetail['personal'] as $i => $p)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td><span class="worker-name-cell">{{ $p['nombre'] }}</span></td>
                                <td>{{ $p['dni'] }}</td>
                                <td><span class="estado-badge {{ $p['estado'] }}">{{ ucfirst($p['estado']) }}</span></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @else
            <!-- Vista de Listado -->
            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value">{{ $kpis['programados'] }}</span><span class="kpi-label">Programados</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-emerald-600">{{ $kpis['presentes'] }}</span><span class="kpi-label">Presentes</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-red-600">{{ $kpis['ausentes'] }}</span><span class="kpi-label">Ausentes</span></div></div>
                <div class="kpi-card kpi-pct"><div class="kpi-data"><span class="kpi-value">{{ $kpis['porcentaje'] }}%</span><span class="kpi-label">% Asistencia</span></div></div>
            </div>

            <div class="panel full-width">
                <div class="panel-head"><h3 class="panel-title">Historial por Día</h3></div>
                <div class="panel-body">
                    <table class="data-table">
                        <thead><tr><th>Fecha</th><th>Mina</th><th>Parada</th><th>Supervisor</th><th>Prog.</th><th>Pres.</th><th>Aus.</th><th>%</th><th>Detalle</th></tr></thead>
                        <tbody>
                            @foreach($porDia as $d)
                            @foreach($d['grupos'] as $g)
                            <tr>
                                <td><span class="date-cell">{{ $d['fecha'] }}</span></td>
                                <td><span class="mina-tag">{{ $g['mina'] }}</span></td>
                                <td><span class="parada-tag">{{ $g['parada'] }}</span></td>
                                <td>{{ $g['supervisor'] }}</td>
                                <td>{{ $g['programados'] }}</td>
                                <td class="text-emerald-600 font-semibold">{{ $g['presentes'] }}</td>
                                <td class="text-red-600 font-semibold">{{ $g['ausentes'] }}</td>
                                <td><span class="pct-cell {{ $g['porcentaje'] >= 90 ? 'emerald' : ($g['porcentaje'] >= 80 ? 'amber' : 'red') }}">{{ $g['porcentaje'] }}%</span></td>
                                <td><a href="?tab=dia&fecha={{ $d['fecha'] }}" class="link-btn">Ver</a></td>
                            </tr>
                            @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>

        @elseif($activeTab === 'semana')
        <!-- POR SEMANA -->
        <div class="content-section">
            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value">710</span><span class="kpi-label">Total Programados</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-emerald-600">638</span><span class="kpi-label">Total Presentes</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-red-600">72</span><span class="kpi-label">Total Ausentes</span></div></div>
                <div class="kpi-card kpi-pct"><div class="kpi-data"><span class="kpi-value">89.9%</span><span class="kpi-label">% Semana</span></div></div>
            </div>

            <div class="panel full-width">
                <div class="panel-head"><h3 class="panel-title">Historial Semanal</h3></div>
                <div class="panel-body">
                    <table class="data-table">
                        <thead><tr><th>Semana</th><th>Inicio</th><th>Fin</th><th>Programados</th><th>Presentes</th><th>Ausentes</th><th>% Asistencia</th></tr></thead>
                        <tbody>
                            @foreach($porSemana as $s)
                            <tr>
                                <td><span class="week-cell">{{ $s['semana'] }}</span></td>
                                <td>{{ $s['inicio'] }}</td>
                                <td>{{ $s['fin'] }}</td>
                                <td>{{ $s['programados'] }}</td>
                                <td class="text-emerald-600">{{ $s['presentes'] }}</td>
                                <td class="text-red-600">{{ $s['ausentes'] }}</td>
                                <td><span class="pct-cell {{ $s['porcentaje'] >= 90 ? 'emerald' : ($s['porcentaje'] >= 80 ? 'amber' : 'red') }}">{{ $s['porcentaje'] }}%</span></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @elseif($activeTab === 'parada')
        <!-- POR PARADA -->
        <div class="content-section">
            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value">3</span><span class="kpi-label">Total Paradas</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-emerald-600">233</span><span class="kpi-label">Total Presentes</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-red-600">22</span><span class="kpi-label">Total Ausentes</span></div></div>
                <div class="kpi-card kpi-pct"><div class="kpi-data"><span class="kpi-value">91.3%</span><span class="kpi-label">% Promedio</span></div></div>
            </div>

            <div class="panel full-width">
                <div class="panel-head"><h3 class="panel-title">Resumen por Parada</h3></div>
                <div class="panel-body">
                    <table class="data-table">
                        <thead><tr><th>Parada</th><th>Fecha</th><th>Programados</th><th>Presentes</th><th>Ausentes</th><th>% Asistencia</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach($paradas as $p)
                            <tr>
                                <td><span class="parada-cell">{{ $p['nombre'] }}</span></td>
                                <td>{{ $p['fecha'] }}</td>
                                <td>{{ $p['programados'] }}</td>
                                <td class="text-emerald-600">{{ $p['presentes'] }}</td>
                                <td class="text-red-600">{{ $p['ausentes'] }}</td>
                                <td><span class="pct-cell {{ $p['porcentaje'] >= 90 ? 'emerald' : ($p['porcentaje'] >= 80 ? 'amber' : 'red') }}">{{ $p['porcentaje'] }}%</span></td>
                                <td><span class="status-badge {{ $p['estado'] }}">{{ $p['estado'] }}</span></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @elseif($activeTab === 'mina')
        <!-- POR MINA -->
        <div class="content-section">
            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value">5</span><span class="kpi-label">Total Minas</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-emerald-600">128</span><span class="kpi-label">Total Presentes</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-red-600">14</span><span class="kpi-label">Total Ausentes</span></div></div>
                <div class="kpi-card kpi-pct"><div class="kpi-data"><span class="kpi-value">{{ $kpis['porcentaje'] }}%</span><span class="kpi-label">% Promedio</span></div></div>
            </div>

            <div class="panel full-width">
                <div class="panel-head"><h3 class="panel-title">Comparativo por Mina</h3></div>
                <div class="panel-body">
                    <table class="data-table">
                        <thead><tr><th>Mina</th><th>Supervisor</th><th>Programados</th><th>Presentes</th><th>Ausentes</th><th>Faltas</th><th>% Asistencia</th></tr></thead>
                        <tbody>
                            @foreach($minaDetail as $m)
                            <tr>
                                <td><span class="mina-cell">{{ $m['nombre'] }}</span></td>
                                <td>{{ $m['supervisor'] }}</td>
                                <td>{{ $m['programados'] }}</td>
                                <td class="text-emerald-600">{{ $m['presentes'] }}</td>
                                <td class="text-red-600">{{ $m['ausentes'] }}</td>
                                <td class="text-amber-600">{{ $m['faltas'] }}</td>
                                <td><span class="pct-cell {{ $m['porcentaje'] >= 90 ? 'emerald' : ($m['porcentaje'] >= 80 ? 'amber' : 'red') }}">{{ $m['porcentaje'] }}%</span></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @elseif($activeTab === 'supervisor')
        <!-- POR SUPERVISOR -->
        <div class="content-section">
            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value">4</span><span class="kpi-label">Total Supervisores</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-emerald-600">55</span><span class="kpi-label">Personal a Cargo</span></div></div>
                <div class="kpi-card"><div class="kpi-data"><span class="kpi-value text-red-600">5</span><span class="kpi-label">Total Faltas</span></div></div>
                <div class="kpi-card kpi-pct"><div class="kpi-data"><span class="kpi-value">93%</span><span class="kpi-label">% Promedio</span></div></div>
            </div>

            <div class="panel full-width">
                <div class="panel-head"><h3 class="panel-title">Rendimiento por Supervisor</h3></div>
                <div class="panel-body">
                    <table class="data-table">
                        <thead><tr><th>#</th><th>Supervisor</th><th>Personal</th><th>Faltas</th><th>% Asistencia</th><th>Ranking</th></tr></thead>
                        <tbody>
                            @foreach($supervisores as $i => $s)
                            <tr>
                                <td><span class="rank-num">#{{ $i+1 }}</span></td>
                                <td><span class="sup-cell">{{ $s['nombre'] }}</span></td>
                                <td>{{ $s['personal'] }}</td>
                                <td class="text-amber-600">{{ $s['faltas'] }}</td>
                                <td><span class="pct-cell {{ $s['asistencia'] >= 90 ? 'emerald' : ($s['asistencia'] >= 80 ? 'amber' : 'red') }}">{{ $s['asistencia'] }}%</span></td>
                                <td>@if($i === 0)<span class="badge-success">Mejor</span>@endif</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

<style>
.asistencia-module { padding: 24px; }
.module-header { margin-bottom: 24px; }
.module-title { font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
.module-subtitle { font-size: 14px; color: #64748b; margin: 0; }

.tabs-container { background: white; border-radius: 16px; padding: 8px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.tabs-nav { display: flex; gap: 4px; }
.tab-item { display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 10px; text-decoration: none; color: #64748b; font-size: 14px; font-weight: 500; transition: all 0.2s; }
.tab-item:hover { background: #f1f5f9; color: #19d3c5; }
.tab-item.active { background: #19d3c5; color: white; }
.tab-label { white-space: nowrap; }
.tab-badge { background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 10px; font-size: 12px; }

.filters-bar { display: flex; align-items: flex-end; gap: 16px; padding: 20px; background: white; border-radius: 16px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); flex-wrap: wrap; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.filter-input, .filter-select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; color: #1e293b; background: #f8fafc; min-width: 160px; transition: all 0.2s; }
.filter-input:focus, .filter-select:focus { outline: none; border-color: #19d3c5; background: white; }
.filter-actions { display: flex; gap: 8px; margin-left: auto; }
.btn-filter { display: flex; align-items: center; gap: 6px; padding: 10px 18px; background: #19d3c5; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-filter:hover { background: #14b5a8; }
.btn-filter-outline { display: flex; align-items: center; gap: 6px; padding: 10px 18px; background: white; color: #64748b; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-filter-outline:hover { border-color: #19d3c5; color: #19d3c5; }

.kpi-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px; }
.kpi-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 16px; }
.kpi-card.kpi-pct { background: #19d3c5; color: #0a223d; }
.kpi-card.kpi-pct .kpi-value { font-size: 32px; }
.kpi-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.kpi-icon.present { background: rgba(16,185,129,0.1); color: #10b981; }
.kpi-icon.absent { background: rgba(239,68,68,0.1); color: #ef4444; }
.kpi-icon-programado { background: rgba(79,140,255,0.1); color: #4f8cff; }
.kpi-icon.warning { background: rgba(245,158,11,0.1); color: #f59e0b; }
.kpi-icon.danger { background: rgba(239,68,68,0.1); color: #ef4444; }
.kpi-icon.success { background: rgba(16,185,129,0.15); color: #10b981; }
.kpi-data { display: flex; flex-direction: column; }
.kpi-value { font-size: 28px; font-weight: 700; line-height: 1; }
.kpi-label { font-size: 13px; color: #64748b; margin-top: 4px; }
.kpi-pct .kpi-label { color: rgba(255,255,255,0.8); }

.dashboard-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 20px; }
.panel { background: white; border-radius: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden; }
.full-width { grid-column: span 12; }
.section-left { grid-column: span 8; }
.section-right { grid-column: span 4; }
.bottom-panel { grid-column: span 4; }
.panel-head { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #f1f5f9; }
.panel-title { font-size: 16px; font-weight: 600; margin: 0; }
.panel-body { padding: 20px 24px; }
.badge { padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; background: rgba(25,211,197,0.1); color: #19d3c5; }
.badge-danger { background: rgba(239,68,68,0.1); color: #ef4444; }
.badge-success { background: rgba(16,185,129,0.1); color: #10b981; }

.miner-item { display: grid; grid-template-columns: 1fr 2fr 50px; gap: 12px; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
.miner-item:last-child { border-bottom: none; }
.miner-info { display: flex; justify-content: space-between; }
.miner-name { font-weight: 600; font-size: 14px; }
.miner-stats { font-size: 13px; color: #64748b; }
.miner-bar { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
.miner-fill { height: 100%; border-radius: 4px; }
.miner-pct { font-size: 14px; font-weight: 600; text-align: right; }

.alert-card { display: flex; align-items: center; gap: 12px; padding: 14px; border-radius: 12px; margin-bottom: 12px; }
.alert-card:last-child { margin-bottom: 0; }
.alert-success { background: rgba(16,185,129,0.08); }
.alert-success .alert-icon { background: rgba(16,185,129,0.15); color: #10b981; }
.alert-warning { background: rgba(245,158,11,0.08); }
.alert-warning .alert-icon { background: rgba(245,158,11,0.15); color: #f59e0b; }
.alert-danger { background: rgba(239,68,68,0.08); }
.alert-danger .alert-icon { background: rgba(239,68,68,0.15); color: #ef4444; }
.alert-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.alert-text { display: flex; flex-direction: column; }
.alert-title { font-size: 12px; font-weight: 500; color: #64748b; }
.alert-value { font-size: 14px; font-weight: 600; }

.rank-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
.rank-row:last-child { border-bottom: none; }
.rank-num { width: 24px; font-weight: 700; color: #94a3b8; font-size: 13px; }
.rank-avatar { width: 36px; height: 36px; border-radius: 10px; background: #19d3c5; color: white; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
.rank-info { flex: 1; display: flex; flex-direction: column; }
.rank-name { font-size: 14px; font-weight: 500; }
.rank-meta { font-size: 12px; color: #64748b; }
.rank-pct { font-size: 16px; font-weight: 700; }

.fault-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
.fault-row:last-child { border-bottom: none; }
.fault-avatar { width: 40px; height: 40px; border-radius: 10px; background: rgba(239,68,68,0.15); color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; }
.fault-info { flex: 1; display: flex; flex-direction: column; }
.fault-name { font-size: 14px; font-weight: 500; }
.fault-dni { font-size: 12px; color: #64748b; }
.fault-stats { display: flex; flex-direction: column; align-items: flex-end; }
.fault-count { font-size: 14px; font-weight: 600; color: #ef4444; }
.fault-pct { font-size: 12px; }

.best-worker { display: flex; align-items: center; gap: 16px; padding: 16px; background: linear-gradient(135deg, #f0fdf4, #ecfdf5); border-radius: 12px; margin-bottom: 16px; }
.best-avatar { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #19d3c5, #14b5a8); color: white; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; }
.best-info { display: flex; flex-direction: column; }
.best-name { font-size: 14px; font-weight: 600; }
.best-dni { font-size: 12px; color: #64748b; }
.best-stats { display: flex; justify-content: space-around; }
.best-stat { display: flex; flex-direction: column; align-items: center; }
.best-val { font-size: 20px; font-weight: 700; }
.best-lbl { font-size: 12px; color: #64748b; }

.data-table { width: 100%; border-collapse: collapse; }
.data-table th { text-align: left; padding: 14px 16px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.data-table td { padding: 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
.data-table tr:hover { background: #f8fafc; }
.date-cell, .week-cell, .parada-cell, .mina-cell, .sup-cell { font-weight: 600; }
.pct-cell { font-weight: 700; padding: 4px 10px; border-radius: 6px; }
.pct-cell.emerald { background: rgba(16,185,129,0.1); color: #10b981; }
.pct-cell.amber { background: rgba(245,158,11,0.1); color: #f59e0b; }
.pct-cell.red { background: rgba(239,68,68,0.1); color: #ef4444; }
.status-badge { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
.status-badge.activa { background: rgba(16,185,129,0.1); color: #10b981; }
.status-badge.cerrada { background: rgba(100,116,139,0.1); color: #64748b; }
.link-btn { color: #19d3c5; text-decoration: none; font-weight: 600; }
.link-btn:hover { text-decoration: underline; }
.detail-header { margin-bottom: 20px; }
.back-link { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 500; }
.back-link:hover { color: #19d3c5; }
.detail-info { display: flex; gap: 16px; margin-bottom: 20px; }
.detail-card { background: white; border-radius: 12px; padding: 16px 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.detail-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.detail-value { font-size: 16px; font-weight: 600; color: #1e293b; margin-top: 4px; }
.detail-supervisor { display: flex; align-items: center; gap: 16px; background: linear-gradient(135deg, #0a223d, #1a3a5c); border-radius: 16px; padding: 20px; margin-bottom: 24px; color: white; }
.supervisor-avatar { width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; }
.supervisor-name { font-size: 18px; font-weight: 600; }
.supervisor-meta { font-size: 14px; opacity: 0.8; margin-top: 4px; }
.mina-tag { display: inline-block; padding: 4px 10px; background: rgba(79,140,255,0.1); color: #4f8cff; border-radius: 6px; font-size: 12px; font-weight: 600; }
.parada-tag { display: inline-block; padding: 4px 10px; background: rgba(139,92,246,0.1); color: #8b5cf6; border-radius: 6px; font-size: 12px; font-weight: 600; }
.worker-name-cell { font-weight: 500; }
.estado-badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
.estado-badge.presente { background: rgba(16,185,129,0.1); color: #10b981; }
.estado-badge.ausente { background: rgba(239,68,68,0.1); color: #ef4444; }
.estado-badge.tardanza { background: rgba(245,158,11,0.1); color: #f59e0b; }

@media (max-width: 1280px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 1024px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .section-left, .section-right, .bottom-panel { grid-column: span 12; } }
@media (max-width: 768px) { .kpi-row { grid-template-columns: 1fr; } .filters-bar { flex-direction: column; align-items: stretch; } .filter-actions { margin-left: 0; margin-top: 12px; } }
</style>
@endsection