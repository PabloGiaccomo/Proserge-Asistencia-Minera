@extends('layouts.app')

@section('title', 'Grupos por Parada - Man Power')

@php
$parada = $parada ?? [
    'id' => 1,
    'nombre' => 'BOROO - Operación Planta',
    'mina' => 'Boroo',
    'area' => 'Operación Planta',
    'fecha_inicio' => '2026-04-15',
    'fecha_fin' => '2026-04-30',
    'estado' => 'enviado',
];

$grupos = $grupos ?? [
    [
        'id' => 1,
        'nombre' => 'Operación Planta - Operación Planta',
        'supervisor' => 'Olarte Cespedes Franklin Richard',
        'estado' => 'confirmado',
        'personal_count' => 1,
        'turno' => 'Día',
        'fecha' => '14/04/2026',
        'personal' => [
            ['nombre' => 'Carlos Alberto López Mamani', 'dni' => '70125489', 'puesto' => 'Operador PC', 'comentario' => 'Experiencia en maquinaria pesada', 'ultimo_turno' => null, 'libre_ayer' => true],
        ]
    ],
    [
        'id' => 2,
        'nombre' => 'Operación Planta - Noche',
        'supervisor' => 'María Elena García López',
        'estado' => 'pendiente',
        'personal_count' => 3,
        'turno' => 'Noche',
        'fecha' => '14/04/2026',
        'personal' => [
            ['nombre' => 'Pedro Asto Yupanqui', 'dni' => '82365412', 'puesto' => 'Mecánico', 'comentario' => 'Disponible solo turno noche', 'ultimo_turno' => 'Día', 'libre_ayer' => false],
            ['nombre' => 'Ana Lucía Quispe Mamani', 'dni' => '61234567', 'puesto' => 'Técnico', 'comentario' => '', 'ultimo_turno' => null, 'libre_ayer' => true],
            ['nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '55678901', 'puesto' => 'Soldador', 'comentario' => 'Requiere equipo especial', 'ultimo_turno' => 'Noche', 'libre_ayer' => false],
        ]
    ],
    [
        'id' => 3,
        'nombre' => 'Operación Planta - Día',
        'supervisor' => 'Olarte Cespedes Franklin Richard',
        'estado' => 'confirmado',
        'personal_count' => 2,
        'turno' => 'Día',
        'fecha' => '13/04/2026',
        'personal' => [
            ['nombre' => 'Jorge Eduardo Tito Flores', 'dni' => '47890123', 'puesto' => 'Operador', 'comentario' => 'Sin comentarios', 'ultimo_turno' => 'Noche', 'libre_ayer' => false],
            ['nombre' => 'Carmen Rosa Torres Flores', 'dni' => '89012345', 'puesto' => 'Auxiliar', 'comentario' => 'Primera vez en esta mina', 'ultimo_turno' => null, 'libre_ayer' => true],
        ]
    ],
];

$supervisores = ['Olarte Cespedes Franklin Richard', 'María Elena García López', 'Juan Pérez', 'Luis Cóndor'];
$areas = ['Operación Planta', 'Mantenimiento', 'Seguridad', 'Logística'];
$servicios = ['Transporte', 'Acomodo', 'Limpieza', 'Soporte'];
@endphp

@section('content')
<div class="grupos-parada-page">
    <!-- Header -->
    <div class="header-section">
        <div class="header-top">
            <a href="{{ route('man-power.paradas') }}" class="btn-volver">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Volver a paradas
            </a>
        </div>
        <div class="header-main">
            <h1 class="page-title">Grupos por parada</h1>
        </div>
        <div class="parada-info">
            <span class="parada-nombre">{{ $parada['nombre'] }}</span>
            <span class="parada-fechas">{{ $parada['fecha_inicio'] }} a {{ $parada['fecha_fin'] }}</span>
            <span class="estado-badge {{ $parada['estado'] }}">{{ strtoupper($parada['estado']) }}</span>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <div class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">Supervisor</label>
                <input type="text" class="filter-input" placeholder="Buscar supervisor...">
            </div>
            <div class="filter-group">
                <label class="filter-label">Área</label>
                <select class="filter-select">
                    <option value="">Todas las áreas</option>
                    @foreach($areas as $a)<option>{{ $a }}</option>@endforeach
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Servicio</label>
                <select class="filter-select">
                    <option value="">Todos los servicios</option>
                    @foreach($servicios as $s)<option>{{ $s }}</option>@endforeach
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Personal</label>
                <input type="text" class="filter-input" placeholder="Buscar personal...">
            </div>
        </div>
    </div>

    <!-- Grupos List -->
    <div class="grupos-list">
        <div class="grupos-grid">
            @foreach($grupos as $grupo)
            <div class="grupo-card" onclick="toggleDetalle({{ $grupo['id'] }})">
                <div class="grupo-card-header">
                    <div class="grupo-info">
                        <h3 class="grupo-nombre">{{ $grupo['nombre'] }}</h3>
                        <p class="grupo-supervisor">Supervisor: {{ $grupo['supervisor'] }}</p>
                    </div>
                    <div class="grupo-badges">
                        <span class="estado-grupo {{ $grupo['estado'] }}">{{ ucfirst($grupo['estado']) }}</span>
                    </div>
                </div>
                <div class="grupo-stats">
                    <div class="stat-item">
                        <span class="stat-value">{{ $grupo['personal_count'] }}</span>
                        <span class="stat-label">Personal</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value turno-{{ strtolower($grupo['turno']) }}">{{ $grupo['turno'] }}</span>
                        <span class="stat-label">Turno</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value">{{ $grupo['fecha'] }}</span>
                        <span class="stat-label">Fecha</span>
                    </div>
                </div>
                
                <!-- Detalle expandible -->
                <div class="grupo-detalle" id="detalle-{{ $grupo['id'] }}">
                    <div class="detalle-divider"></div>
                    <h4 class="detalle-title">Personal asignado</h4>
                    <div class="personal-list">
                        @foreach($grupo['personal'] as $persona)
                        <div class="persona-card">
                            <div class="persona-main">
                                <span class="persona-nombre">{{ $persona['nombre'] }}</span>
                                <span class="persona-dni">DNI: {{ $persona['dni'] }}</span>
                            </div>
                            <div class="persona-details">
                                <span class="persona-puesto">{{ $persona['puesto'] }}</span>
                                @if($persona['comentario'])
                                <span class="persona-comentario">{{ $persona['comentario'] }}</span>
                                @endif
                                <div class="persona-status">
                                    @if($persona['ultimo_turno'])
                                    <span class="status-tag ultimo-turno">Último turno: {{ $persona['ultimo_turno'] }}</span>
                                    @endif
                                    @if($persona['libre_ayer'])
                                    <span class="status-tag libre">Libre el día anterior</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

<style>
.grupos-parada-page { padding: 24px; max-width: 1200px; margin: 0 auto; }

.header-section { margin-bottom: 24px; }
.header-top { margin-bottom: 16px; }
.btn-volver { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 500; }
.btn-volver:hover { color: #19d3c5; }
.page-title { font-size: 28px; font-weight: 700; color: #1e293b; margin: 0 0 12px; }
.parada-info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.parada-nombre { font-size: 16px; font-weight: 600; color: #1e293b; }
.parada-fechas { font-size: 14px; color: #64748b; }
.estado-badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
.estado-badge.enviado { background: rgba(59,130,246,0.15); color: #2563eb; }
.estado-badge.aprobado { background: rgba(16,185,129,0.15); color: #059669; }
.estado-badge.borrador { background: rgba(245,158,11,0.15); color: #d97706; }

.filters-section { background: white; border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.filters-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.filter-input, .filter-select { padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #f8fafc; color: #1e293b; }
.filter-input:focus, .filter-select:focus { outline: none; border-color: #19d3c5; background: white; }

.grupos-grid { display: flex; flex-direction: column; gap: 16px; }
.grupo-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); cursor: pointer; transition: all 0.2s; }
.grupo-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); transform: translateY(-1px); }
.grupo-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.grupo-info { flex: 1; }
.grupo-nombre { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 4px; }
.grupo-supervisor { font-size: 13px; color: #64748b; margin: 0; }
.estado-grupo { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.estado-grupo.confirmado { background: rgba(16,185,129,0.15); color: #059669; }
.estado-grupo.pendiente { background: rgba(245,158,11,0.15); color: #d97706; }
.estado-grupo.cancelado { background: rgba(239,68,68,0.15); color: #dc2626; }

.grupo-stats { display: flex; gap: 32px; }
.stat-item { display: flex; flex-direction: column; }
.stat-value { font-size: 18px; font-weight: 700; color: #1e293b; }
.stat-value.turno-día { color: #f59e0b; }
.stat-value.turno-noche { color: #6366f1; }
.stat-label { font-size: 12px; color: #64748b; }

.grupo-detalle { display: none; margin-top: 16px; }
.grupo-card.expanded .grupo-detalle { display: block; }
.detalle-divider { height: 1px; background: #f1f5f9; margin-bottom: 16px; }
.detalle-title { font-size: 14px; font-weight: 600; color: #1e293b; margin: 0 0 12px; }
.personal-list { display: flex; flex-direction: column; gap: 10px; }
.persona-card { background: #f8fafc; border-radius: 12px; padding: 14px; display: flex; gap: 16px; }
.persona-main { display: flex; flex-direction: column; min-width: 180px; }
.persona-nombre { font-size: 14px; font-weight: 600; color: #1e293b; }
.persona-dni { font-size: 12px; color: #64748b; }
.persona-details { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.persona-puesto { font-size: 13px; font-weight: 500; color: #475569; }
.persona-comentario { font-size: 12px; color: #64748b; font-style: italic; }
.persona-status { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.status-tag { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; }
.status-tag.libre { background: rgba(16,185,129,0.15); color: #059669; }
.status-tag.ultimo-turno { background: rgba(245,158,11,0.15); color: #d97706; }

@media (max-width: 1024px) { .filters-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { 
    .filters-grid { grid-template-columns: 1fr; }
    .grupo-stats { flex-wrap: wrap; gap: 16px; }
    .persona-card { flex-direction: column; gap: 10px; }
}
</style>

<script>
function toggleDetalle(id) {
    const card = document.querySelector('.grupo-card');
    const detalle = document.getElementById('detalle-' + id);
    if (detalle.style.display === 'block') {
        detalle.style.display = 'none';
    } else {
        detalle.style.display = 'block';
    }
}
</script>
@endsection
