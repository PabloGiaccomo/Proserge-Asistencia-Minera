@extends('layouts.app')

@section('title', 'Grupos - Man Power')

@php
$parada_id = request('parada_id', 'MP-202');
$canCreateManPowerGroup = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'man_power', 'crear');

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
            @if($canCreateManPowerGroup)
                <a href="{{ route('man-power.grupo-crear', ['parada_id' => $parada_id]) }}" class="btn-nuevo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Nuevo Grupo
                </a>
            @endif
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
