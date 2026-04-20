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

<style>
.paradas-page { padding: 24px; max-width: 1200px; margin: 0 auto; }

.header-section { margin-bottom: 24px; }
.page-title { font-size: 28px; font-weight: 700; color: #1e293b; margin: 0; }
.page-subtitle { font-size: 14px; color: #64748b; margin: 4px 0 0; }

.tabs-nav { display: flex; gap: 8px; margin-bottom: 20px; background: white; padding: 8px; border-radius: 12px; width: fit-content; }
.tab { padding: 10px 20px; border-radius: 8px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 500; }
.tab:hover { background: #f1f5f9; }
.tab.active { background: #19d3c5; color: white; }

.filters-bar { background: white; border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.filters-grid { display: grid; grid-template-columns: 2fr 1fr auto; gap: 16px; align-items: end; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
.filter-input, .filter-select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #f8fafc; }
.filter-input:focus, .filter-select:focus { outline: none; border-color: #19d3c5; }
.btn-filter { padding: 10px 18px; background: #19d3c5; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }

.paradas-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
.parada-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.parada-header { display: flex; justify-content: space-between; margin-bottom: 16px; }
.parada-rq { font-size: 12px; font-weight: 600; color: #64748b; }
.parada-nombre { font-size: 16px; font-weight: 600; color: #1e293b; margin: 4px 0; }
.parada-fechas { font-size: 13px; color: #64748b; margin: 0; }
.estado-badge { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.estado-badge.borrador { background: rgba(245,158,11,0.15); color: #d97706; }
.estado-badge.enviado { background: rgba(59,130,246,0.15); color: #2563eb; }
.estado-badge.aprobado { background: rgba(16,185,129,0.15); color: #059669; }

.parada-stats { display: flex; gap: 24px; padding: 16px 0; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; margin-bottom: 16px; }
.stat { display: flex; flex-direction: column; }
.stat-value { font-size: 20px; font-weight: 700; color: #1e293b; }
.stat-label { font-size: 12px; color: #64748b; }

.parada-actions { display: flex; gap: 10px; }
.btn-detalle { flex: 1; padding: 10px; background: #19d3c5; color: white; text-align: center; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 600; }
.btn-detalle:hover { background: #14b5a8; }

@media (max-width: 768px) { .filters-grid { grid-template-columns: 1fr; } }
</style>
@endsection