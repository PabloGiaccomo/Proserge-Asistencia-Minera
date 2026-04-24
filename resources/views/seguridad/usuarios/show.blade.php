@extends('layouts.app')

@section('title', 'Usuario - Proserge')

@section('content')
@php
    $estado = $hasEstadoColumn ? strtoupper((string) $usuario->estado) : 'ACTIVO';
@endphp
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Usuario</h1>
                <p class="page-subtitle">{{ $usuario->personal?->nombre_completo ?? $usuario->email }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.index') }}" class="btn btn-outline">Volver</a>
                @allowed('usuarios', 'administrar')
                    <a href="{{ route('usuarios.scope', $usuario->id) }}" class="btn btn-outline">Scope Mina</a>
                @endallowed
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 16px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Datos base</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">ID</label>
                    <input type="text" class="form-control" value="{{ $usuario->id }}" readonly>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">DNI</label>
                        <input type="text" class="form-control" value="{{ $usuario->personal?->dni ?? '-' }}" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <input type="text" class="form-control" value="{{ $estado }}" readonly>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nombre completo</label>
                    <input type="text" class="form-control" value="{{ $usuario->personal?->nombre_completo ?? '-' }}" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Puesto</label>
                    <input type="text" class="form-control" value="{{ $usuario->personal?->puesto ?? '-' }}" readonly>
                </div>
            </div>
        </div>

        <div class="card" id="edicion">
            <div class="card-header">
                <span class="card-title">Editar credenciales y permisos base</span>
            </div>
            <div class="card-body">
                @allowed('usuarios', 'editar')
                <form method="POST" action="{{ route('usuarios.update', $usuario->id) }}">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label class="form-label required">Correo</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email', $usuario->email) }}" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Rol</label>
                            <select name="rol_id" class="form-control" required>
                                <option value="">Selecciona un rol</option>
                                @foreach($roles as $rol)
                                    <option value="{{ $rol->id }}" {{ old('rol_id', $usuario->rol_id) === $rol->id ? 'selected' : '' }}>{{ $rol->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($hasEstadoColumn)
                            <div class="form-group">
                                <label class="form-label required">Estado</label>
                                <select name="estado" class="form-control" required>
                                    <option value="ACTIVO" {{ old('estado', $estado) === 'ACTIVO' ? 'selected' : '' }}>Activo</option>
                                    <option value="INACTIVO" {{ old('estado', $estado) === 'INACTIVO' ? 'selected' : '' }}>Inactivo</option>
                                </select>
                            </div>
                        @endif
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Guardar cambios</button>
                    </div>
                </form>
                @else
                    <p style="color: var(--color-text-secondary);">No tienes permiso para editar este usuario.</p>
                @endallowed
            </div>
        </div>
    </div>

    <div class="grid grid-2" style="align-items: start; margin-top: 16px;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Cambiar contraseña</span>
            </div>
            <div class="card-body">
                @allowed('usuarios', 'administrar')
                <form method="POST" action="{{ route('usuarios.password', $usuario->id) }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Nueva contraseña</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">Confirmar contraseña</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
                    </div>
                </form>
                @else
                    <p style="color: var(--color-text-secondary);">No tienes permiso para cambiar contraseñas.</p>
                @endallowed
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Scope Mina</span>
                <span class="card-badge">{{ $usuario->scopesMina->count() }} asignadas</span>
            </div>
            <div class="card-body">
                @if($usuario->scopesMina->isEmpty())
                    <p style="color: var(--color-text-secondary); margin-bottom: 16px;">Este usuario no tiene minas asignadas.</p>
                @else
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px;">
                        @foreach($usuario->scopesMina as $scope)
                            <span class="badge badge-success">{{ $scope->mina?->nombre ?? $scope->mina_id }}</span>
                        @endforeach
                    </div>
                @endif

                @allowed('usuarios', 'administrar')
                    <a href="{{ route('usuarios.scope', $usuario->id) }}" class="btn btn-outline">Configurar Scope Mina</a>
                @endallowed
            </div>
        </div>
    </div>
</div>
@endsection
