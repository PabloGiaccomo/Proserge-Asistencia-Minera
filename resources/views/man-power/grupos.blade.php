@extends('layouts.app')

@section('title', 'Grupos - Man Power')

@php
$parada_id = request('parada_id', 'MP-202');

$grupos = [
    [
        'id' => 1,
        'nombre' => 'Operación Planta - Día',
        'supervisor' => 'Olarte Cespedes Franklin Richard',
        'estado' => 'confirmado',
        'personal_count' => 4,
        'turno' => 'Día',
        'fecha' => '14/04/2026',
        'personal' => [
            ['nombre' => 'Carlos Alberto López Mamani', 'dni' => '70125489', 'puesto' => 'Operador PC', 'comentario' => 'Experiencia en maquinaria pesada', 'ultimo_turno' => 'Noche'],
            ['nombre' => 'Ana Lucía Quispe Mamani', 'dni' => '61234567', 'puesto' => 'Técnico', 'comentario' => '', 'ultimo_turno' => 'Día'],
            ['nombre' => 'Jorge Eduardo Tito Flores', 'dni' => '47890123', 'puesto' => 'Operador', 'comentario' => 'Sin comentarios', 'ultimo_turno' => null],
            ['nombre' => 'Carmen Rosa Torres Flores', 'dni' => '89012345', 'puesto' => 'Auxiliar', 'comentario' => 'Primera vez en esta mina', 'ultimo_turno' => 'Noche'],
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
            ['nombre' => 'Pedro Asto Yupanqui', 'dni' => '82365412', 'puesto' => 'Mecánico', 'comentario' => 'Disponible solo turno noche', 'ultimo_turno' => 'Día'],
            ['nombre' => 'Luis Fernando Cóndor Huanca', 'dni' => '55678901', 'puesto' => 'Soldador', 'comentario' => 'Requiere equipo especial', 'ultimo_turno' => 'Noche'],
            ['nombre' => 'Roberto Carlos Huanca Lima', 'dni' => '61237890', 'puesto' => 'Técnico Electricista', 'comentario' => '', 'ultimo_turno' => 'Día'],
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
            ['nombre' => 'Sofia Elizabeth Quispe Mamani', 'dni' => '73456789', 'puesto' => 'Administrativo', 'comentario' => 'Solo oficina', 'ultimo_turno' => null],
            ['nombre' => 'Pedro Asto Yupanqui', 'dni' => '82365412', 'puesto' => 'Mecánico', 'comentario' => '', 'ultimo_turno' => 'Noche'],
        ]
    ],
];
@endphp

@section('content')
<div class="grupos-page">
    <!-- Header -->
    <div class="header-section">
        <div class="header-top">
            <a href="{{ route('man-power.index') }}" class="btn-volver">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Volver a Man Power
            </a>
            <a href="{{ route('man-power.grupo-crear', ['parada_id' => $parada_id]) }}" class="btn-nuevo">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Nuevo Grupo
            </a>
        </div>
        <div class="header-main">
            <h1 class="page-title">Grupos de Trabajo</h1>
            <p class="page-subtitle">Parada: {{ $parada_id }}</p>
        </div>
    </div>

    <!-- filtros -->
    <div class="filters-bar">
        <div class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">Fecha</label>
                <input type="date" class="filter-input">
            </div>
            <div class="filter-group">
                <label class="filter-label">Turno</label>
                <select class="filter-select">
                    <option value="">Todos</option>
                    <option>Día</option>
                    <option>Noche</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Estado</label>
                <select class="filter-select">
                    <option value="">Todos</option>
                    <option>Confirmado</option>
                    <option>Pendiente</option>
                    <option>Cancelado</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn-filter">Filtrar</button>
                <button class="btn-filter-outline">Limpiar</button>
            </div>
        </div>
    </div>

    <!-- grupos grid -->
    <div class="grupos-container">
        @foreach($grupos as $grupo)
        <div class="grupo-card" onclick="toggleGrupo(this)">
            <div class="grupo-header">
                <div class="grupo-info">
                    <h3 class="grupo-nombre">{{ $grupo['nombre'] }}</h3>
                    <p class="grupo-supervisor">Supervisor: {{ $grupo['supervisor'] }}</p>
                </div>
                <div class="grupo-badges">
                    <span class="badge badge-{{ $grupo['estado'] }}">{{ ucfirst($grupo['estado']) }}</span>
                </div>
            </div>
            
            <div class="grupo-meta">
                <div class="meta-item">
                    <span class="meta-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                    </span>
                    <span>{{ $grupo['fecha'] }}</span>
                </div>
                <div class="meta-item turno-{{ strtolower($grupo['turno']) }}">
                    <span class="meta-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>
                    </span>
                    <span>{{ $grupo['turno'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </span>
                    <span>{{ $grupo['personal_count'] }} personas</span>
                </div>
            </div>

            <!-- Personal展开ido -->
            <div class="grupo-personal">
                <div class="personal-divider">
                    <span>Personal asignado</span>
                    <span class="person-count">{{ count($grupo['personal']) }}</span>
                </div>
                <div class="personal-list">
                    @foreach($grupo['personal'] as $persona)
                    <div class="persona-item">
                        <div class="persona-avatar">{{ strtoupper(substr($persona['nombre'], 0, 2)) }}</div>
                        <div class="persona-details">
                            <span class="persona-nombre">{{ $persona['nombre'] }}</span>
                            <span class="persona-info">{{ $persona['puesto'] }} • DNI: {{ $persona['dni'] }}</span>
                            @if(isset($persona['ultimo_turno']) && $persona['ultimo_turno'])
                            <span class="persona-turno">Último turno: {{ $persona['ultimo_turno'] }}</span>
                            @else
                            <span class="persona-turno libre">Libre el día anterior</span>
                            @endif
                            @if($persona['comentario'])
                            <span class="persona-comentario">{{ $persona['comentario'] }}</span>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

<style>
.grupos-page { padding: 24px; max-width: 1200px; margin: 0 auto; }

.header-section { margin-bottom: 24px; }
.header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.btn-volver { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 500; }
.btn-volver:hover { color: #19d3c5; }
.btn-nuevo { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; background: #f97316; color: white; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 600; }
.btn-nuevo:hover { background: #ea580c; }
.page-title { font-size: 28px; font-weight: 700; color: #1e293b; margin: 0; }
.tabs-nav { display: flex; gap: 8px; margin-bottom: 20px; background: white; padding: 8px; border-radius: 12px; width: fit-content; }
.tab { padding: 10px 20px; border-radius: 8px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 500; }
.tab:hover { background: #f1f5f9; }
.tab.active { background: #19d3c5; color: white; }
.page-subtitle { font-size: 14px; color: #64748b; margin: 4px 0 0; }

.filters-bar { background: white; border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.filters-grid { display: grid; grid-template-columns: repeat(4, 1fr) auto; gap: 16px; align-items: end; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.filter-input, .filter-select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #f8fafc; }
.filter-input:focus, .filter-select:focus { outline: none; border-color: #19d3c5; background: white; }
.filter-actions { display: flex; gap: 8px; }
.btn-filter { padding: 10px 18px; background: #19d3c5; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-filter-outline { padding: 10px 18px; background: white; color: #64748b; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }

.grupos-container { display: flex; flex-direction: column; gap: 16px; }
.grupo-card { background: white; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden; transition: all 0.2s; }
.grupo-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.grupo-card.expanded .grupo-personal { display: block; }

.grupo-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px; }
.grupo-info { flex: 1; }
.grupo-nombre { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 4px; }
.grupo-supervisor { font-size: 13px; color: #64748b; margin: 0; }
.badge { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.badge.confirmado { background: rgba(16,185,129,0.15); color: #059669; }
.badge.pendiente { background: rgba(245,158,11,0.15); color: #d97706; }
.badge.cancelado { background: rgba(239,68,68,0.15); color: #dc2626; }

.grupo-meta { display: flex; gap: 24px; padding: 0 20px 16px; border-bottom: 1px solid #f1f5f9; margin-bottom: 0; }
.meta-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #64748b; }
.meta-item.turno-día { color: #f59e0b; }
.meta-item.turno-noche { color: #6366f1; }
.meta-icon { display: flex; }

.grupo-personal { display: none; background: #fafafa; padding: 16px 20px; }
.personal-divider { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13px; font-weight: 600; color: #475569; }
.person-count { background: #e2e8f0; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
.personal-list { display: flex; flex-direction: column; gap: 10px; }
.persona-item { display: flex; gap: 12px; padding: 12px; background: white; border-radius: 10px; }
.persona-avatar { width: 40px; height: 40px; border-radius: 10px; background: #19d3c5; color: white; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; flex-shrink: 0; }
.persona-details { display: flex; flex-direction: column; gap: 2px; }
.persona-nombre { font-size: 14px; font-weight: 600; color: #1e293b; }
.persona-info { font-size: 12px; color: #64748b; }
.persona-comentario { font-size: 12px; color: #64748b; font-style: italic; }
.persona-turno { font-size: 11px; font-weight: 600; padding: 3px 8px; background: rgba(245,158,11,0.15); color: #d97706; border-radius: 6px; display: inline-block; margin-top: 4px; }
.persona-turno.libre { background: rgba(16,185,129,0.15); color: #059669; }

@media (max-width: 768px) {
    .filters-grid { grid-template-columns: 1fr 1fr; }
    .grupo-meta { flex-wrap: wrap; gap: 12px; }
}
</style>

<script>
function toggleGrupo(card) {
    card.classList.toggle('expanded');
    const personal = card.querySelector('.grupo-personal');
    if (personal.style.display === 'block') {
        personal.style.display = 'none';
    } else {
        personal.style.display = 'block';
    }
}
</script>
@endsection