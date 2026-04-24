@extends('layouts.app')

@section('title', 'Taller - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Taller</h1>
            <p class="page-subtitle">{{ $item['nombre'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.talleres.edit', $item['id']) }}" class="btn btn-primary">Editar</a>
            <form method="POST" action="{{ route('catalogos.talleres.destroy', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas eliminar este taller?');">
                @csrf
                <button type="submit" class="btn btn-outline" style="color:#B91C1C; border-color:#FCA5A5;">Eliminar</button>
            </form>
            <a href="{{ route('catalogos.talleres.index') }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
        </div>
    </div>
</div>

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
                    <span class="detail-value">{{ $item['nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Ubicación</span>
                    <span class="detail-value">{{ $item['ubicacion'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ ($item['activo'] ?? true) ? 'success' : 'danger' }}">
                        {{ ($item['activo'] ?? true) ? 'Activo' : 'Inactivo' }}
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
                    <span class="detail-value">{{ $item['codigo'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Descripción</span>
                    <span class="detail-value">{{ $item['descripcion'] ?? '-' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
