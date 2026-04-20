@extends('layouts.app')

@section('title', 'Asistencia - Resumen')

@php
$hoy = date('Y-m-d');
$turnoActual = 'Turno Dia';
$contextoActual = 'Parada Mina 1';

$kpis = [
    'total' => 142,
    'presentes' => 128,
    'ausentes' => 14,
    'faltas' => 8,
    'grupos_abiertos' => 3,
    'porcentaje' => 90.1,
];

$porDestino = [
    ['nombre' => 'Mina 1', 'programado' => 45, 'presente' => 42, 'ausente' => 3, 'porcentaje' => 93.3],
    ['nombre' => 'Mina 2', 'programado' => 38, 'presente' => 35, 'ausente' => 3, 'porcentaje' => 92.1],
    ['nombre' => 'Mina 3', 'programado' => 28, 'presente' => 24, 'ausente' => 4, 'porcentaje' => 85.7],
    ['nombre' => 'Taller Mecanico', 'programado' => 18, 'presente' => 17, 'ausente' => 1, 'porcentaje' => 94.4],
    ['nombre' => 'Oficina Central', 'programado' => 13, 'presente' => 10, 'ausente' => 3, 'porcentaje' => 76.9],
];

$gruposHoy = [
    ['nombre' => 'Grupo A2', 'supervisor' => 'Maria Garcia', 'mina' => 'Mina 1', 'turno' => 'Dia', 'programado' => 10, 'presente' => 9, 'estado' => 'abierto'],
    ['nombre' => 'Grupo B2', 'supervisor' => 'Ana Torres', 'mina' => 'Mina 2', 'turno' => 'Noche', 'programado' => 8, 'presente' => 8, 'estado' => 'abierto'],
    ['nombre' => 'Grupo C1', 'supervisor' => 'Carlos Lopez', 'mina' => 'Taller Mecanico', 'turno' => 'Dia', 'programado' => 6, 'presente' => 5, 'estado' => 'abierto'],
    ['nombre' => 'Grupo A1', 'supervisor' => 'Juan Perez', 'mina' => 'Mina 1', 'turno' => 'Dia', 'programado' => 12, 'presente' => 12, 'estado' => 'cerrado'],
];

$ultimasFaltas = [
    ['trabajador' => 'Carlos Mendoza', 'dni' => '74856231', 'mina' => 'Mina 1', 'tipo' => 'Inasistencia', 'cantidad' => 4],
    ['trabajador' => 'Ana Lopez', 'dni' => '82365412', 'mina' => 'Mina 2', 'tipo' => 'Tardanza', 'cantidad' => 3],
    ['trabajador' => 'Pedro Asto', 'dni' => '70125489', 'mina' => 'Mina 1', 'tipo' => 'Inasistencia', 'cantidad' => 3],
    ['trabajador' => 'Diana Flores', 'dni' => '56782345', 'mina' => 'Mina 3', 'tipo' => 'Inasistencia', 'cantidad' => 2],
];

$topSupervisores = [
    ['nombre' => 'Juan Perez', 'mina' => 'Mina 1', 'rendimiento' => 96.2],
    ['nombre' => 'Maria Garcia', 'mina' => 'Mina 1', 'rendimiento' => 94.8],
    ['nombre' => 'Luis Condor', 'mina' => 'Mina 2', 'rendimiento' => 93.9],
    ['nombre' => 'Ana Torres', 'mina' => 'Mina 2', 'rendimiento' => 92.6],
];

$mejorTrabajador = [
    'nombre' => 'Jose Huaman',
    'dni' => '45678912',
    'mina' => 'Mina 1',
    'asistencias' => '30/30',
    'puntaje' => 98.7,
];

$minaCritica = collect($porDestino)->sortBy('porcentaje')->first();
$grupoPendiente = collect($gruposHoy)->firstWhere('estado', 'abierto');
$supervisorTop = collect($topSupervisores)->sortByDesc('rendimiento')->first();
$faltasSinJustificar = $kpis['faltas'];

$currentSection = 'resumen';
$tabs = [
    'resumen' => ['label' => 'Resumen', 'icon' => '<path d="M3 3v18h18"/><path d="M18.5 18.5c-2.5 0-4.5-2-4.5-4.5s2-4.5 4.5-4.5 4.5 2 4.5 4.5-2 4.5-4.5 4.5z"/>', 'route' => route('asistencia.resumen')],
    'mina' => ['label' => 'Por Mina', 'icon' => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>', 'route' => route('asistencia.mina')],
    'parada' => ['label' => 'Por Parada', 'icon' => '<path d="M12 22s8-4 8-10V5l-8-4-8 4v7c0 6 8 10 8 10z"/>', 'route' => route('asistencia.parada')],
    'supervisor' => ['label' => 'Por Supervisor', 'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', 'route' => route('asistencia.supervisor')],
    'personal' => ['label' => 'Personal', 'icon' => '<circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>', 'route' => route('asistencia.personal')],
    'alertas' => ['label' => 'Alertas', 'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>', 'route' => route('asistencia.alertas')],
];
@endphp

@section('content')
<div class="module-page ops-center-page">
    <div class="module-tabs">
        @foreach($tabs as $key => $tab)
        <a href="{{ $tab['route'] }}" class="module-tab {{ $currentSection === $key ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $tab['icon'] !!}</svg>
            <span>{{ $tab['label'] }}</span>
        </a>
        @endforeach
    </div>

    <section class="ops-hero">
        <div class="ops-hero-left">
            <p class="ops-hero-kicker">Control Diario de Asistencia</p>
            <h1 class="ops-hero-title">Centro de Control Operativo Minero</h1>
            <div class="ops-hero-context">
                <span class="ops-chip">{{ $turnoActual }}</span>
                <span class="ops-chip">{{ $contextoActual }}</span>
                <span class="ops-chip">{{ $hoy }}</span>
            </div>
            <div class="ops-hero-stats">
                <div><span class="ops-stat-label">Programados</span><strong>{{ $kpis['total'] }}</strong></div>
                <div><span class="ops-stat-label">Presentes</span><strong>{{ $kpis['presentes'] }}</strong></div>
                <div><span class="ops-stat-label">Ausentes</span><strong>{{ $kpis['ausentes'] }}</strong></div>
                <div><span class="ops-stat-label">Asistencia</span><strong>{{ $kpis['porcentaje'] }}%</strong></div>
            </div>
        </div>
        <div class="ops-hero-actions">
            <button class="btn btn-outline btn-sm" onclick="window.location.reload()">Actualizar</button>
            <button class="btn btn-outline btn-sm">Exportar</button>
            <button class="btn btn-primary btn-sm">Filtrar</button>
        </div>
    </section>

    <section class="ops-kpi-grid">
        <article class="ops-kpi-card info">
            <div class="ops-kpi-icon">%</div>
            <div class="ops-kpi-content">
                <p class="ops-kpi-value">{{ $kpis['porcentaje'] }}%</p>
                <p class="ops-kpi-title">Asistencia General</p>
                <p class="ops-kpi-note">Meta diaria &gt; 92%</p>
            </div>
        </article>

        <article class="ops-kpi-card success">
            <div class="ops-kpi-icon">P</div>
            <div class="ops-kpi-content">
                <p class="ops-kpi-value">{{ $kpis['presentes'] }}</p>
                <p class="ops-kpi-title">Presentes</p>
                <p class="ops-kpi-note">Sobre {{ $kpis['total'] }} programados</p>
            </div>
        </article>

        <article class="ops-kpi-card danger">
            <div class="ops-kpi-icon">!</div>
            <div class="ops-kpi-content">
                <p class="ops-kpi-value">{{ $kpis['faltas'] }}</p>
                <p class="ops-kpi-title">Faltas / Tardanzas</p>
                <p class="ops-kpi-note">Requiere revision inmediata</p>
            </div>
        </article>

        <article class="ops-kpi-card warning">
            <div class="ops-kpi-icon">G</div>
            <div class="ops-kpi-content">
                <p class="ops-kpi-value">{{ $kpis['grupos_abiertos'] }}</p>
                <p class="ops-kpi-title">Grupos pendientes</p>
                <p class="ops-kpi-note">Pendientes por cerrar</p>
            </div>
        </article>
    </section>

    <section class="ops-row-2">
        <article class="card ops-panel areas-panel">
            <div class="card-header">
                <span class="card-title">Estado por Mina / Area</span>
            </div>
            <div class="card-body area-list">
                @foreach($porDestino as $destino)
                @php
                    $pct = $destino['porcentaje'];
                    $tone = $pct > 90 ? 'ok' : ($pct >= 80 ? 'warn' : 'bad');
                @endphp
                <div class="area-item {{ $tone }}">
                    <div class="area-head">
                        <strong>{{ $destino['nombre'] }}</strong>
                        <span class="area-percent">{{ $pct }}%</span>
                    </div>
                    <div class="area-metrics">
                        <span>Prog. {{ $destino['programado'] }}</span>
                        <span class="text-success">Pres. {{ $destino['presente'] }}</span>
                        <span class="text-danger">Aus. {{ $destino['ausente'] }}</span>
                    </div>
                    <div class="area-progress"><span style="width: {{ $pct }}%"></span></div>
                </div>
                @endforeach
            </div>
        </article>

        <article class="card ops-panel alerts-panel">
            <div class="card-header">
                <span class="card-title">Alertas del Dia</span>
            </div>
            <div class="card-body alert-cards">
                <div class="alert-card bad">
                    <div class="alert-icon">M</div>
                    <div>
                        <p class="alert-title">Mina con menor asistencia</p>
                        <p class="alert-value">{{ $minaCritica['nombre'] }} ({{ $minaCritica['porcentaje'] }}%)</p>
                    </div>
                </div>
                <div class="alert-card warn">
                    <div class="alert-icon">G</div>
                    <div>
                        <p class="alert-title">Grupo sin cierre</p>
                        <p class="alert-value">{{ $grupoPendiente['nombre'] ?? 'Sin datos' }}</p>
                    </div>
                </div>
                <div class="alert-card ok">
                    <div class="alert-icon">S</div>
                    <div>
                        <p class="alert-title">Supervisor top</p>
                        <p class="alert-value">{{ $supervisorTop['nombre'] ?? '-' }} ({{ $supervisorTop['rendimiento'] ?? '-' }}%)</p>
                    </div>
                </div>
                <div class="alert-card info">
                    <div class="alert-icon">F</div>
                    <div>
                        <p class="alert-title">Faltas sin justificar</p>
                        <p class="alert-value">{{ $faltasSinJustificar }}</p>
                    </div>
                </div>
            </div>
        </article>
    </section>

    <section class="ops-row-3">
        <article class="card rank-panel">
            <div class="card-header"><span class="card-title">Top Supervisores</span></div>
            <div class="card-body rank-list">
                @foreach($topSupervisores as $i => $sup)
                <div class="rank-item">
                    <span class="rank-pos">#{{ $i + 1 }}</span>
                    <div class="rank-main">
                        <strong>{{ $sup['nombre'] }}</strong>
                        <small>{{ $sup['mina'] }}</small>
                    </div>
                    <span class="rank-score">{{ $sup['rendimiento'] }}%</span>
                </div>
                @endforeach
            </div>
        </article>

        <article class="card rank-panel">
            <div class="card-header"><span class="card-title">Personal con mas faltas</span></div>
            <div class="card-body rank-list">
                @foreach($ultimasFaltas as $item)
                <div class="rank-item">
                    <span class="rank-pos">{{ $item['cantidad'] }}</span>
                    <div class="rank-main">
                        <strong>{{ $item['trabajador'] }}</strong>
                        <small>{{ $item['tipo'] }} · {{ $item['mina'] }}</small>
                    </div>
                    <span class="rank-score danger">{{ $item['dni'] }}</span>
                </div>
                @endforeach
            </div>
        </article>

        <article class="card rank-panel best-worker">
            <div class="card-header"><span class="card-title">Mejor trabajador de la semana</span></div>
            <div class="card-body">
                <div class="best-worker-block">
                    <div class="best-avatar">{{ strtoupper(substr($mejorTrabajador['nombre'], 0, 2)) }}</div>
                    <h3>{{ $mejorTrabajador['nombre'] }}</h3>
                    <p>{{ $mejorTrabajador['mina'] }} · DNI {{ $mejorTrabajador['dni'] }}</p>
                    <div class="best-metrics">
                        <div><span>Asistencias</span><strong>{{ $mejorTrabajador['asistencias'] }}</strong></div>
                        <div><span>Puntaje</span><strong>{{ $mejorTrabajador['puntaje'] }}</strong></div>
                    </div>
                </div>
            </div>
        </article>
    </section>
</div>
@endsection

@push('styles')
<style>
.ops-center-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
    background: #F4F7FB;
}

.ops-hero {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    border-radius: 24px;
    padding: 24px;
    background: linear-gradient(140deg, #071B34 0%, #0B2A4A 60%, #15406E 100%);
    box-shadow: 0 20px 35px rgba(7, 27, 52, 0.22);
}

.ops-hero-kicker {
    color: #93C5FD;
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
    font-weight: 700;
}

.ops-hero-title {
    margin-top: 6px;
    color: #F8FAFC;
    font-size: 30px;
    line-height: 1.1;
}

.ops-hero-context {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.ops-chip {
    display: inline-flex;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    color: #E2E8F0;
    background: rgba(148, 163, 184, 0.2);
    border: 1px solid rgba(203, 213, 225, 0.25);
}

.ops-hero-stats {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.ops-hero-stats div {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.16);
    border-radius: 12px;
    padding: 10px;
}

.ops-stat-label {
    display: block;
    font-size: 11px;
    color: #BFDBFE;
}

.ops-hero-stats strong {
    color: #F8FAFC;
    font-size: 20px;
}

.ops-hero-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.ops-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.ops-kpi-card {
    background: #FFFFFF;
    border-radius: 18px;
    padding: 18px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
    border: 1px solid #E2E8F0;
    display: flex;
    gap: 12px;
    align-items: center;
}

.ops-kpi-card.success { border-left: 5px solid #10B981; }
.ops-kpi-card.warning { border-left: 5px solid #F59E0B; }
.ops-kpi-card.danger { border-left: 5px solid #EF4444; }
.ops-kpi-card.info { border-left: 5px solid #3B82F6; }

.ops-kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #0F172A;
    background: #EEF2FF;
}

.ops-kpi-value {
    font-size: 30px;
    line-height: 1;
    font-weight: 800;
    color: #0F172A;
}

.ops-kpi-title {
    margin-top: 4px;
    font-weight: 700;
    color: #1E293B;
}

.ops-kpi-note {
    margin-top: 2px;
    font-size: 12px;
    color: #64748B;
}

.ops-row-2 {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
}

.ops-panel {
    border-radius: 18px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.area-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.area-item {
    border: 1px solid #E2E8F0;
    border-radius: 14px;
    padding: 12px;
    background: #FCFDFE;
}

.area-item.ok { border-left: 4px solid #10B981; }
.area-item.warn { border-left: 4px solid #F59E0B; }
.area-item.bad { border-left: 4px solid #EF4444; }

.area-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.area-percent {
    font-weight: 700;
}

.area-metrics {
    margin-top: 6px;
    display: flex;
    gap: 12px;
    font-size: 12px;
    color: #64748B;
}

.area-progress {
    margin-top: 8px;
    width: 100%;
    height: 9px;
    border-radius: 999px;
    background: #E2E8F0;
    overflow: hidden;
}

.area-progress span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #22C55E 0%, #06B6D4 100%);
}

.alert-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.alert-card {
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 12px;
    padding: 10px;
    border: 1px solid #E2E8F0;
    background: #F8FAFC;
}

.alert-card.ok { border-left: 4px solid #10B981; }
.alert-card.warn { border-left: 4px solid #F59E0B; }
.alert-card.bad { border-left: 4px solid #EF4444; }
.alert-card.info { border-left: 4px solid #3B82F6; }

.alert-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #E2E8F0;
    font-weight: 700;
}

.alert-title {
    font-size: 12px;
    color: #64748B;
}

.alert-value {
    font-size: 14px;
    font-weight: 700;
    color: #0F172A;
}

.ops-row-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.rank-panel {
    border-radius: 18px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
}

.rank-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rank-item {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 10px;
    background: #FCFDFE;
}

.rank-pos {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #E2E8F0;
    font-size: 12px;
    font-weight: 700;
}

.rank-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.rank-main strong {
    font-size: 14px;
    color: #0F172A;
}

.rank-main small {
    font-size: 12px;
    color: #64748B;
}

.rank-score {
    font-size: 13px;
    font-weight: 700;
    color: #059669;
}

.rank-score.danger {
    color: #DC2626;
}

.best-worker .card-body {
    display: flex;
    align-items: center;
    justify-content: center;
}

.best-worker-block {
    text-align: center;
}

.best-avatar {
    width: 56px;
    height: 56px;
    margin: 0 auto;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #0F172A;
    background: linear-gradient(135deg, rgba(25, 211, 197, 0.2), rgba(79, 140, 255, 0.2));
}

.best-worker-block h3 {
    margin-top: 10px;
    font-size: 18px;
    color: #0F172A;
}

.best-worker-block p {
    margin-top: 4px;
    color: #64748B;
    font-size: 13px;
}

.best-metrics {
    margin-top: 12px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.best-metrics div {
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 8px;
    background: #F8FAFC;
}

.best-metrics span {
    display: block;
    font-size: 11px;
    color: #64748B;
}

.best-metrics strong {
    display: block;
    font-size: 18px;
    color: #0F172A;
}

@media (max-width: 1199px) {
    .ops-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ops-row-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 991px) {
    .ops-row-2 {
        grid-template-columns: 1fr;
    }

    .ops-hero {
        flex-direction: column;
    }

    .ops-hero-actions {
        width: 100%;
    }
}

@media (max-width: 767px) {
    .ops-kpi-grid,
    .ops-row-3,
    .ops-hero-stats {
        grid-template-columns: 1fr;
    }

    .area-metrics {
        flex-wrap: wrap;
    }

    .ops-hero-title {
        font-size: 24px;
    }

    .ops-hero {
        padding: 18px;
    }

    .ops-hero-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>
@endpush
