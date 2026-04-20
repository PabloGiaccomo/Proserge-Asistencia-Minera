@extends('layouts.app')

@section('title', 'Usuarios - Proserge')

@section('content')
<div class="module-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Usuarios</h1>
                <p class="page-subtitle">Gestión de usuarios del sistema</p>
            </div>
            <div class="page-actions">
                <a href="#" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Nuevo Usuario
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <div class="filter-row">
            <div class="filter-group" style="flex: 2;">
                <label class="filter-label">Buscar Usuario</label>
                <input type="text" class="form-control" placeholder="Nombre, email, documento...">
            </div>
            <div class="filter-group">
                <label class="filter-label">Rol</label>
                <select class="form-control">
                    <option value="">Todos los roles</option>
                    <option value="admin">Administrador</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="residente">Residente</option>
                    <option value="trabajador">Trabajador</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Estado</label>
                <select class="form-control">
                    <option value="">Todos</option>
                    <option value="activo">Activo</option>
                    <option value="inactivo">Inactivo</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary btn-sm">Buscar</button>
                <button class="btn btn-outline btn-sm">Limpiar</button>
            </div>
        </div>
    </div>

    <!-- Results -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Listado de Usuarios</span>
        </div>
        <div class="card-body">
            <x-ui.empty-state
                icon="user"
                title="Aún no hay usuarios registrados"
                description="Los usuarios del sistema aparecerán aquí una vez creados."
            >
                <x-slot:action>
                    <a href="#" class="btn btn-primary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Crear Usuario
                    </a>
                </x-slot:action>
            </x-ui.empty-state>
        </div>
    </div>
</div>
@endsection
