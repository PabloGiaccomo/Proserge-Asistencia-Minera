@extends('layouts.app')

@section('title', 'Rol - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Rol</h1>
            <p class="page-subtitle">{{ $item['nombre'] ?? $item['name'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">
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
            <h3 class="card-title">Información del Rol</h3>
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
                    <span class="detail-label">Descripción</span>
                    <span class="detail-value">{{ $item['descripcion'] ?? $item['description'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Usuarios con este Rol</h3>
        </div>
        <div class="card-body">
            {{ count($item['usuarios'] ?? $item['users'] ?? []) }} usuarios
        </div>
    </div>
</div>

@if(!empty($item['permisos'] ?? $item['permissions']))
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Permisos Asignados</h3>
    </div>
    <div class="card-body">
        <div class="permisos-list">
            @foreach($item['permisos'] ?? $item['permissions'] as $permiso)
                <span class="badge badge-info">{{ $permiso['nombre'] ?? $permiso['name'] ?? '' }}</span>
            @endforeach
        </div>
    </div>
</div>
@endif
@else
<div class="card">
    <div class="card-body">
        @include('components.empty-state', [
            'message' => 'Rol no encontrado',
            'description' => 'El rol que buscas no existe'
        ])
    </div>
</div>
@endif
@endsection