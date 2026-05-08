@extends('layouts.app')

@section('title', 'Mina - Detalle')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canEdit = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'minas', 'editar');
    $canDelete = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'minas', 'eliminar');
@endphp
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Detalle de Mina</h1>
            <p class="page-subtitle">{{ $item['nombre'] ?? $item['name'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            @if($canEdit)
                <a href="{{ route('catalogos.minas.edit', $item['id']) }}" class="btn btn-primary">Editar</a>
            @endif
            @if($canDelete && ($item['activo'] ?? true))
                <form method="POST" action="{{ route('catalogos.minas.inactivate', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas inactivar esta mina y sus paraderos?');">
                    @csrf
                    <button type="submit" class="btn btn-outline" style="color:#B91C1C; border-color:#FCA5A5;">Inactivar</button>
                </form>
            @endif
            <a href="{{ route('catalogos.minas.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if($item)
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Informacion General</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">ID</span><span class="detail-value">{{ $item['id'] }}</span></div>
                <div class="detail-item"><span class="detail-label">Nombre</span><span class="detail-value">{{ $item['nombre'] ?? $item['name'] ?? '-' }}</span></div>
                <div class="detail-item"><span class="detail-label">Ubicacion</span><span class="detail-value">{{ $item['ubicacion'] ?? $item['location'] ?? '-' }}</span></div>
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
            <h3 class="card-title">Informacion Adicional</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Codigo</span><span class="detail-value">{{ $item['codigo'] ?? $item['code'] ?? '-' }}</span></div>
                <div class="detail-item"><span class="detail-label">Descripcion</span><span class="detail-value">{{ $item['descripcion'] ?? $item['description'] ?? '-' }}</span></div>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
