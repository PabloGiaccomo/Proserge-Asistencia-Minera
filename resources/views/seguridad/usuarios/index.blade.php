@extends('layouts.app')

@section('title', 'Usuarios - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Usuarios</h1>
                <p class="page-subtitle">Cada usuario debe estar vinculado a un trabajador real del módulo Personal.</p>
            </div>
            <div class="page-actions">
                @allowed('usuarios', 'crear')
                    <a href="{{ route('usuarios.create') }}" class="btn btn-primary">Nuevo Usuario</a>
                @endallowed
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 16px;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ session('error') }}</div>
    @endif

    <div class="card" style="margin-bottom: 16px;">
        <div class="card-body">
            <form method="GET" action="{{ route('usuarios.index') }}" class="form-row" style="align-items: end; gap: 12px;">
                <div class="form-group" style="flex: 1 1 320px;">
                    <label class="form-label">Buscar</label>
                    <input type="text" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="Nombre, DNI, puesto, correo o rol">
                </div>
                @if($hasEstadoColumn)
                    <div class="form-group" style="min-width: 180px;">
                        <label class="form-label">Estado</label>
                        <select name="estado" class="form-control">
                            <option value="">Todos</option>
                            <option value="ACTIVO" {{ ($filters['estado'] ?? '') === 'ACTIVO' ? 'selected' : '' }}>Activos</option>
                            <option value="INACTIVO" {{ ($filters['estado'] ?? '') === 'INACTIVO' ? 'selected' : '' }}>Inactivos</option>
                        </select>
                    </div>
                @endif
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de usuarios</span>
            <span class="card-badge">{{ $usuarios->count() }} registros</span>
        </div>
        <div class="card-body">
            @if($usuarios->isEmpty())
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <h3 class="empty-title">No hay usuarios registrados</h3>
                    <p class="empty-description">Usa el botón Nuevo Usuario para crear cuentas solo desde trabajadores ya registrados en Personal.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Trabajador</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($usuarios as $usuario)
                                @php
                                    $estado = $hasEstadoColumn ? strtoupper((string) $usuario->estado) : 'ACTIVO';
                                    $rolesExtra = $usuario->rolesAdicionales ?? collect();
                                @endphp
                                <tr>
                                    <td><code>{{ $usuario->id }}</code></td>
                                    <td>
                                        <strong>{{ $usuario->personal?->nombre_completo ?? 'Sin trabajador' }}</strong><br>
                                        <span style="color: var(--color-text-secondary); font-size: 13px;">
                                            DNI: {{ $usuario->personal?->dni ?? '-' }}
                                            @if($usuario->personal?->puesto)
                                                | {{ $usuario->personal->puesto }}
                                            @endif
                                        </span>
                                    </td>
                                    <td>{{ $usuario->email }}</td>
                                    <td>
                                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <span class="badge badge-success">{{ $usuario->rol?->nombre ?? '-' }}</span>
                                            @foreach($rolesExtra as $rol)
                                                <span class="badge badge-info">{{ $rol->nombre }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-{{ $estado === 'ACTIVO' ? 'success' : 'danger' }}">{{ $estado }}</span>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                            <a href="{{ route('usuarios.show', $usuario->id) }}" class="btn btn-sm btn-outline">Ver</a>
                                            @allowed('usuarios', 'editar')
                                                <a href="{{ route('usuarios.show', $usuario->id) }}#edicion" class="btn btn-sm btn-outline">Editar</a>
                                            @endallowed
                                            @allowed('usuarios', 'administrar')
                                                <a href="{{ route('usuarios.scope', $usuario->id) }}" class="btn btn-sm btn-outline">Scope Mina</a>
                                            @endallowed
                                            @if($hasEstadoColumn)
                                                @allowed('usuarios', 'administrar')
                                                <form method="POST" action="{{ route('usuarios.toggle-estado', $usuario->id) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline">{{ $estado === 'ACTIVO' ? 'Desactivar' : 'Activar' }}</button>
                                                </form>
                                                @endallowed
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
