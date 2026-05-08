@extends('layouts.app')

@section('title', 'Taller - Detalle')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canEdit = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'talleres', 'editar');
    $canDelete = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'talleres', 'eliminar');
@endphp
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Taller</h1>
            <p class="page-subtitle">{{ $item['nombre'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            @if($canEdit)
                <a href="{{ route('catalogos.talleres.edit', $item['id']) }}" class="btn btn-primary">Editar</a>
            @endif
            @if($canDelete)
                <form method="POST" action="{{ route('catalogos.talleres.destroy', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas eliminar este taller?');">
                    @csrf
                    <button type="submit" class="btn btn-outline" style="color:#B91C1C; border-color:#FCA5A5;">Eliminar</button>
                </form>
            @endif
            <a href="{{ route('catalogos.talleres.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h3 class="card-title">Informacion General</h3></div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">ID</span><span class="detail-value">{{ $item['id'] }}</span></div>
                <div class="detail-item"><span class="detail-label">Nombre</span><span class="detail-value">{{ $item['nombre'] ?? '-' }}</span></div>
                <div class="detail-item"><span class="detail-label">Ubicacion</span><span class="detail-value">{{ $item['ubicacion'] ?? '-' }}</span></div>
                <div class="detail-item"><span class="detail-label">Estado</span><span class="badge badge-{{ ($item['activo'] ?? true) ? 'success' : 'danger' }}">{{ ($item['activo'] ?? true) ? 'Activo' : 'Inactivo' }}</span></div>
            </div>
        </div>
    </div>
</div>
@endsection
