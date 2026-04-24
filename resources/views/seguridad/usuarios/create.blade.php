@extends('layouts.app')

@section('title', 'Nuevo Usuario - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Nuevo Usuario</h1>
                <p class="page-subtitle">Selecciona primero un trabajador existente del módulo Personal.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom: 16px;">
            <strong>No se pudo crear el usuario.</strong>
            <p style="margin-top: 8px;">{{ $errors->first() }}</p>
        </div>
    @endif

    <div class="grid grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">1. Buscar trabajador en Personal</span>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('usuarios.create') }}">
                    <div class="form-group">
                        <label class="form-label">Buscar por nombre, DNI o puesto</label>
                        <input type="text" name="search" class="form-control" value="{{ $search }}" placeholder="Ej. Juan, 72345678, Supervisor">
                    </div>
                    <div class="form-actions" style="justify-content: flex-start; margin-top: 0;">
                        <button type="submit" class="btn btn-primary">Buscar trabajador</button>
                    </div>
                </form>

                @if($search !== '')
                    <div style="margin-top: 20px;">
                        @if($trabajadores->isEmpty())
                            <div class="empty-state" style="padding: 24px 0;">
                                <h3 class="empty-title">Sin resultados</h3>
                                <p class="empty-description">No existe un trabajador en Personal con ese criterio de búsqueda.</p>
                            </div>
                        @else
                            <div style="display: grid; gap: 12px;">
                                @foreach($trabajadores as $trabajador)
                                    <div class="card" style="border: 1px solid #e5e7eb; box-shadow: none;">
                                        <div class="card-body" style="padding: 16px; display: flex; justify-content: space-between; gap: 16px; align-items: center;">
                                            <div>
                                                <strong>{{ $trabajador->nombre_completo }}</strong><br>
                                                <span style="color: var(--color-text-secondary); font-size: 13px;">
                                                    DNI: {{ $trabajador->dni }} | {{ $trabajador->puesto ?: 'Sin puesto' }}
                                                </span>
                                            </div>
                                            <div>
                                                @if($trabajador->usuario)
                                                    <span class="badge badge-danger">Ya tiene usuario</span>
                                                @else
                                                    <a href="{{ route('usuarios.create', ['search' => $search, 'trabajador_id' => $trabajador->id]) }}" class="btn btn-sm btn-primary">Seleccionar</a>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">2. Credenciales y rol</span>
            </div>
            <div class="card-body">
                @if(!$trabajadorSeleccionado)
                    <div class="empty-state" style="padding: 24px 0;">
                        <h3 class="empty-title">Selecciona un trabajador</h3>
                        <p class="empty-description">No se puede crear un usuario libre o manual. Primero debes elegir un trabajador existente.</p>
                    </div>
                @elseif($trabajadorSeleccionado->usuario)
                    <div class="alert alert-error">
                        <strong>Este trabajador ya tiene un usuario registrado.</strong>
                        <p style="margin-top: 8px;">Puedes revisar su cuenta actual desde la ficha del usuario existente.</p>
                    </div>
                    <div class="form-actions" style="justify-content: flex-start;">
                        <a href="{{ route('usuarios.show', $trabajadorSeleccionado->usuario->id) }}" class="btn btn-outline">Ver usuario existente</a>
                    </div>
                @else
                    <form method="POST" action="{{ route('usuarios.store') }}">
                        @csrf
                        <input type="hidden" name="personal_id" value="{{ $trabajadorSeleccionado->id }}">

                        <div class="form-group">
                            <label class="form-label">Trabajador seleccionado</label>
                            <input type="text" class="form-control" value="{{ $trabajadorSeleccionado->nombre_completo }}" readonly>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">DNI</label>
                                <input type="text" class="form-control" value="{{ $trabajadorSeleccionado->dni }}" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Puesto</label>
                                <input type="text" class="form-control" value="{{ $trabajadorSeleccionado->puesto }}" readonly>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Correo electrónico</label>
                            <input type="email" name="email" class="form-control" value="{{ old('email', $trabajadorSeleccionado->correo) }}" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Contraseña</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Confirmar contraseña</label>
                                <input type="password" name="password_confirmation" class="form-control" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Rol asignado</label>
                                <select name="rol_id" class="form-control" required>
                                    <option value="">Selecciona un rol</option>
                                    @foreach($roles as $rol)
                                        <option value="{{ $rol->id }}" {{ old('rol_id') === $rol->id ? 'selected' : '' }}>{{ $rol->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($hasEstadoColumn)
                                <div class="form-group">
                                    <label class="form-label">Estado</label>
                                    <select name="estado" class="form-control">
                                        <option value="ACTIVO" {{ old('estado', 'ACTIVO') === 'ACTIVO' ? 'selected' : '' }}>Activo</option>
                                        <option value="INACTIVO" {{ old('estado') === 'INACTIVO' ? 'selected' : '' }}>Inactivo</option>
                                    </select>
                                </div>
                            @endif
                        </div>

                        <div class="form-actions">
                            <a href="{{ route('usuarios.index') }}" class="btn btn-outline">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Crear Usuario</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
