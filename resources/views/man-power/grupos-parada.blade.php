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
