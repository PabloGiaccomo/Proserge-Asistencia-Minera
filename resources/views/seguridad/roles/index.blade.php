@extends('layouts.app')

@section('title', 'Roles - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Roles</h1>
                <p class="page-subtitle">Configura qué módulos puede ver cada rol y qué acciones puede realizar.</p>
            </div>
            <div class="page-actions">
                @allowed('roles', 'crear')
                    <a href="{{ route('seguridad.roles.create') }}" class="btn btn-primary">Nuevo Rol</a>
                @endallowed
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de roles</span>
            <span class="card-badge">{{ $roles->count() }} roles</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuarios</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roles as $rol)
                            <tr>
                                <td><strong>{{ $rol->nombre }}</strong></td>
                                <td>{{ $rol->usuarios_count ?? 0 }}</td>
                                <td>{{ $rol->descripcion ?: 'Sin descripción' }}</td>
                                <td>
                                    <span class="badge badge-{{ strtoupper((string) $rol->estado) === 'ACTIVO' ? 'success' : 'danger' }}">{{ strtoupper((string) $rol->estado) }}</span>
                                </td>
                                <td>
                                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                        <a href="{{ route('seguridad.roles.show', $rol->id) }}" class="btn btn-sm btn-outline">Ver</a>
                                        @allowed('roles', 'editar')
                                            <a href="{{ route('seguridad.roles.edit', $rol->id) }}" class="btn btn-sm btn-outline">Editar permisos</a>
                                        @endallowed
                                        @allowed('roles', 'crear')
                                            <form method="POST" action="{{ route('seguridad.roles.duplicate', $rol->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline">Duplicar</button>
                                            </form>
                                        @endallowed
                                        @allowed('roles', 'administrar')
                                            <form method="POST" action="{{ route('seguridad.roles.toggle', $rol->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline">{{ strtoupper((string) $rol->estado) === 'ACTIVO' ? 'Desactivar' : 'Activar' }}</button>
                                            </form>
                                        @endallowed
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
