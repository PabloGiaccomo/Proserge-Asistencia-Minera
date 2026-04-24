@extends('layouts.app')

@section('title', 'Mina - Detalle')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Mina</h1>
            <p class="page-subtitle">{{ $item['nombre'] ?? $item['name'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.minas.edit', $item['id']) }}" class="btn btn-primary">Editar</a>
            @if(($item['activo'] ?? true))
                <form method="POST" action="{{ route('catalogos.minas.inactivate', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas inactivar esta mina y sus paraderos?');">
                    @csrf
                    <button type="submit" class="btn btn-outline" style="color:#B91C1C; border-color:#FCA5A5;">Inactivar</button>
                </form>
            @endif
            <a href="{{ route('catalogos.minas.index') }}" class="btn btn-outline">
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
                    <span class="detail-label">Estado</span>
                    <span class="badge badge-{{ ($item['activo'] ?? $item['active'] ?? true) ? 'success' : 'danger' }}">
                        {{ ($item['activo'] ?? $item['active'] ?? true) ? 'Activa' : 'Inactiva' }}
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

<div class="card" style="margin-top:16px;">
    <div class="card-header">
        <h3 class="card-title">Paraderos Asociados</h3>
    </div>
    <div class="card-body">
        @if(empty($item['paraderos']))
            <p class="text-muted">No hay paraderos asociados.</p>
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Ubicación</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($item['paraderos'] as $paradero)
                            <tr>
                                <td>{{ $paradero['nombre'] ?? '-' }}</td>
                                <td>{{ $paradero['ubicacion'] ?? '-' }}</td>
                                <td>
                                    <span class="badge badge-{{ strtoupper((string) ($paradero['estado'] ?? 'ACTIVO')) === 'ACTIVO' ? 'success' : 'danger' }}">
                                        {{ strtoupper((string) ($paradero['estado'] ?? 'ACTIVO')) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@else
<div class="card">
    <div class="card-body">
        @include('components.empty-state', [
            'message' => 'Mina no encontrada',
            'description' => 'La mina que buscas no existe'
        ])
    </div>
</div>
@endif
@endsection