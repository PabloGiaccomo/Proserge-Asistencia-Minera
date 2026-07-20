@extends('layouts.app')

@section('title', 'Man Power - Paradas')

@php
$paradas = [
    [
        'id' => 1,
        'rq_mina_id' => 'MP-202',
        'nombre' => 'BOROO - Operación Planta',
        'mina' => 'Boroo',
        'area' => 'Operación Planta',
        'fecha_inicio' => '2026-04-15',
        'fecha_fin' => '2026-04-30',
        'tipo' => 'Operación',
        'personal_asignado' => 8,
        'estado' => 'enviado',
        'grupos_count' => 3,
    ],
    [
        'id' => 2,
        'rq_mina_id' => 'MP-203',
        'nombre' => 'Cerro Verde - C2',
        'mina' => 'Cerro Verde',
        'area' => 'C2',
        'fecha_inicio' => '2026-04-18',
        'fecha_fin' => '2026-04-25',
        'tipo' => 'Mantenimiento',
        'personal_asignado' => 5,
        'estado' => 'aprobado',
        'grupos_count' => 2,
    ],
    [
        'id' => 3,
        'rq_mina_id' => 'MP-204',
        'nombre' => 'Chinalco - Tienda',
        'mina' => 'Chinalco',
        'area' => 'Tienda',
        'fecha_inicio' => '2026-04-20',
        'fecha_fin' => '2026-04-28',
        'tipo' => 'Operación',
        'personal_asignado' => 12,
        'estado' => 'borrador',
        'grupos_count' => 0,
    ],
];
@endphp

@section('content')
<div class="paradas-page">
    <!-- Header -->
    <div class="header-section">
        <div>
            <h1 class="page-title">Man Power</h1>
            <p class="page-subtitle">Gestión de paradas y grupos de trabajo</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs-nav">
        <a href="{{ route('man-power.index') }}" class="tab active">Man Power</a>
        <a href="{{ route('man-power.grupos') }}" class="tab">Grupos</a>
    </div>

    <!-- filtros -->
    <div class="filters-bar">
        <div class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">Buscar</label>
                <input type="text" class="filter-input" placeholder="Buscar por RQ o mina...">
            </div>
            <div class="filter-group">
                <label class="filter-label">Estado</label>
                <select class="filter-select">
                    <option value="">Todos</option>
                    <option>Borrador</option>
                    <option>Enviado</option>
                    <option>Aprobado</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn-filter">Filtrar</button>
            </div>
        </div>
    </div>

    <!-- paradas grid -->
    <div class="paradas-container">
        @foreach($paradas as $parada)
        <div class="parada-card">
            <div class="parada-header">
                <div class="parada-info">
                    <span class="parada-rq">#{{ $parada['rq_mina_id'] }}</span>
                    <h3 class="parada-nombre">{{ $parada['nombre'] }}</h3>
                    <p class="parada-fechas">{{ $parada['fecha_inicio'] }} al {{ $parada['fecha_fin'] }}</p>
                </div>
                <span class="estado-badge {{ $parada['estado'] }}">{{ strtoupper($parada['estado']) }}</span>
            </div>
            
            <div class="parada-stats">
                <div class="stat">
                    <span class="stat-value">{{ $parada['personal_asignado'] }}</span>
                    <span class="stat-label">Personal</span>
                </div>
                <div class="stat">
                    <span class="stat-value">{{ $parada['grupos_count'] }}</span>
                    <span class="stat-label">Grupos</span>
                </div>
                <div class="stat">
                    <span class="stat-value">{{ $parada['tipo'] }}</span>
                    <span class="stat-label">Tipo</span>
                </div>
            </div>

            <div class="parada-actions">
                <a href="{{ route('man-power.grupos', ['parada_id' => $parada['rq_mina_id']]) }}" class="btn-detalle">Ver Grupos</a>
            </div>
        </div>
        @endforeach
    </div>
</div>

@endsection