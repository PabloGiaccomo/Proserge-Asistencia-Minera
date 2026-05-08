@extends('layouts.app')

@section('title', 'Catalogos - Oficinas')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canCreate = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'oficinas', 'crear');
    $canEdit = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'oficinas', 'editar');
    $canDelete = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'oficinas', 'eliminar');
@endphp
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Oficinas</h1>
            <p class="page-subtitle">Catalogo de oficinas</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.index') }}" class="btn btn-outline">Volver</a>
            @if($canCreate)
                <a href="{{ route('catalogos.oficinas.create') }}" class="btn btn-primary">Nueva oficina</a>
            @endif
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Oficinas</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay oficinas registradas',
                'description' => 'Puedes crear nuevas oficinas desde este catalogo'
            ])
        @else
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Ubicacion</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data as $item)
                            <tr>
                                <td>{{ $item['id'] ?? '-' }}</td>
                                <td>{{ $item['nombre'] ?? '-' }}</td>
                                <td>{{ $item['ubicacion'] ?? '-' }}</td>
                                <td>
                                    <span class="badge badge-{{ ($item['activo'] ?? true) ? 'success' : 'danger' }}">
                                        {{ ($item['activo'] ?? true) ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('catalogos.oficinas.show', $item['id']) }}" class="btn btn-sm btn-outline">Ver</a>
                                    @if($canEdit)
                                        <a href="{{ route('catalogos.oficinas.edit', $item['id']) }}" class="btn btn-sm btn-outline">Editar</a>
                                    @endif
                                    @if($canDelete)
                                        <form method="POST" action="{{ route('catalogos.oficinas.destroy', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas eliminar esta oficina?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline" style="color:#B91C1C; border-color:#FCA5A5;">Eliminar</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
