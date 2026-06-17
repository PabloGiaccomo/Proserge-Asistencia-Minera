@extends('layouts.app')

@section('title', 'Catalogos - Minas')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canCreate = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'minas', 'crear');
    $canEdit = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'minas', 'editar');
    $canDelete = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'minas', 'eliminar');
@endphp
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Minas</h1>
            <p class="page-subtitle">Catalogo de minas</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.index') }}" class="btn btn-outline">Volver</a>
            @if($canCreate)
                <a href="{{ route('catalogos.minas.create') }}" class="btn btn-primary">Nueva mina</a>
            @endif
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-danger" style="margin-bottom:16px;">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Listado de Minas</h3>
    </div>
    <div class="card-body">
        @if(empty($data))
            @include('components.empty-state', [
                'message' => 'No hay minas registradas',
                'description' => 'Las minas se crean desde la configuracion del sistema'
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
                                <td>{{ $item['nombre'] ?? $item['name'] ?? '-' }}</td>
                                <td>{{ $item['ubicacion'] ?? $item['location'] ?? '-' }}</td>
                                <td>
                                    <span class="badge badge-{{ ($item['activo'] ?? $item['active'] ?? true) ? 'success' : 'danger' }}">
                                        {{ ($item['activo'] ?? $item['active'] ?? true) ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('catalogos.minas.show', $item['id']) }}" class="btn btn-sm btn-outline">Ver</a>
                                    @if($canEdit)
                                        <a href="{{ route('catalogos.minas.edit', $item['id']) }}" class="btn btn-sm btn-outline">Editar</a>
                                    @endif
                                    @if($canDelete && ($item['activo'] ?? true))
                                        <form method="POST" action="{{ route('catalogos.minas.inactivate', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas inactivar esta mina y sus paraderos?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline" style="color:#B91C1C; border-color:#FCA5A5;">Inactivar</button>
                                        </form>
                                    @endif
                                    @if($canDelete)
                                        <form method="POST" action="{{ route('catalogos.minas.destroy', $item['id']) }}" style="display:inline-block;" onsubmit="return confirm('Deseas eliminar definitivamente esta mina? Solo se eliminara si no tiene movimientos asociados.');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline" style="color:#7F1D1D; border-color:#FCA5A5;">Eliminar</button>
                                        </form>
                                        @include('catalogos.minas._force_delete_form', ['item' => $item])
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
