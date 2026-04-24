@extends('layouts.app')

@section('title', 'Paradero - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Paradero</h1>
            <p class="page-subtitle">{{ $item['nombre'] ?? $item['name'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.paraderos.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
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
                    <span class="detail-label">Nombre</span>
                    <span class="detail-value">{{ $item['nombre'] ?? $item['name'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Ubicación</span>
                    <span class="detail-value">{{ $item['ubicacion'] ?? $item['location'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Mina</span>
                    <span class="detail-value">{{ $item['mina'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ ($item['activo'] ?? $item['active'] ?? true) ? 'success' : 'danger' }}">
                        {{ ($item['activo'] ?? $item['active'] ?? true) ? 'Activo' : 'Inactivo' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Información Adicional</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Código</span>
                    <span class="detail-value">{{ $item['codigo'] ?? $item['code'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Descripción</span>
                    <span class="detail-value">{{ $item['descripcion'] ?? $item['description'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@else
<div class="card">
    <div class="card-body">
        @include('components.empty-state', [
            'message' => 'Paradero no encontrado',
            'description' => 'El paradero que buscas no existe'
        ])
    </div>
</div>
@endif
@endsection