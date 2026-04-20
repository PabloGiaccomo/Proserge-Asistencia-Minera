@extends('layouts.app')

@section('title', 'Evaluación de Desempeño - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Evaluación</h1>
            <p class="page-subtitle">Evaluación #{{ $item['id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('evaluaciones.desempeno.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
            <a href="{{ route('evaluaciones.desempeno.edit', $item['id']) }}" class="btn btn-primary">
                Editar
            </a>
        </div>
    </div>
</div>

@if($item)
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Información General</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">ID</span>
                    <span class="detail-value">{{ $item['id'] }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha</span>
                    <span class="detail-value">{{ $item['fecha_evaluacion'] ?? $item['created_at'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Tipo</span>
                    <span class="detail-value">{{ $item['tipo'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ $item['estado'] ?? 'secondary' }}">{{ $item['estado'] ?? 'pendiente' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Evaluado</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Nombre</span>
                    <span class="detail-value">{{ $item['evaluado']['nombre'] ?? $item['evaluado_nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Cargo</span>
                    <span class="detail-value">{{ $item['evaluado']['cargo'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

@if(isset($item['score']))
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Resultado</h3>
    </div>
    <div class="card-body">
        <div class="kpi-grid">
            <div class="kpi-card">
                <span class="kpi-value">{{ $item['score'] }}</span>
                <span class="kpi-label">Score Final</span>
            </div>
        </div>
    </div>
</div>
@endif
@else
<div class="card">
    <div class="card-body">
        @include('components.empty-state', [
            'message' => 'Evaluación no encontrada',
            'description' => 'La evaluación que buscas no existe'
        ])
    </div>
</div>
@endif
@endsection