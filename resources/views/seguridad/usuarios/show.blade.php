@extends('layouts.app')

@section('title', 'Ver Usuario - Proserge')

@section('content')
@php
    $estado = $hasEstadoColumn ? strtoupper((string) $usuario->estado) : 'ACTIVO';
    $areaRoles = $usuario->rolesAdicionales->filter(fn ($rol) => ($rol->pivot->tipo ?? null) === 'area');
    $cargoRoles = $usuario->rolesAdicionales->filter(fn ($rol) => ($rol->pivot->tipo ?? null) === 'cargo');
    $notificationsAllowed = !isset($notificationUserSetting) || $notificationUserSetting === null || (bool) $notificationUserSetting->in_app_enabled;
    $permissions = session('user.permissions', []);
    $canEditUser = \App\Support\Rbac\PermissionMatrix::allowsDirectAny($permissions, 'usuarios', ['editar', 'actualizar']);
    $canAssignRoles = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'usuarios', 'asignar');
    $canConfigureUser = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'usuarios', 'configurar');
    $canScopeUser = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'usuarios', 'scope');
    $canGoToEdit = $canEditUser || $canAssignRoles || $canConfigureUser;
    $isPrivilegedUserRole = in_array(strtoupper((string) optional($usuario->rol)->nombre), ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
@endphp
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Detalle de Usuario</h1>
                <p class="page-subtitle">{{ $usuario->personal?->nombre_completo ?? $usuario->email }}</p>
            </div>
            <div class="page-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="{{ route('usuarios.index') }}" class="btn btn-outline">Volver</a>
                @if($canGoToEdit)
                    <a href="{{ route('usuarios.edit', $usuario->id) }}" class="btn btn-primary">Editar Usuario</a>
                @endif
                @if($canScopeUser)
                    <a href="{{ route('usuarios.scope', $usuario->id) }}" class="btn btn-outline">Scope Mina</a>
                @endif
                @if(($isSuperAdmin ?? false) && (string) $usuario->id !== ($currentUserId ?? ''))
                    @allowedDirect('usuarios', 'eliminar')
                    <form method="POST" action="{{ route('usuarios.destroy', $usuario->id) }}" onsubmit="return confirm('¿Estás seguro de eliminar permanentemente a este usuario? Esta acción es irreversible y solo debe usarse para usuarios creados por error o confusión.');" style="display:inline;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline" style="color: #b91c1c; border-color: #fca5a5;">Eliminar Usuario</button>
                    </form>
                    @endallowedDirect
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 16px;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ session('error') }}</div>
    @endif

    <div class="grid grid-2" style="align-items: start; margin-bottom: 16px;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Datos del trabajador vinculado</span>
                <span class="badge badge-{{ $estado === 'ACTIVO' ? 'success' : 'danger' }}">{{ $estado }}</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">ID de Usuario</label>
                    <input type="text" class="form-control" value="{{ $usuario->id }}" readonly>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">DNI</label>
                        <input type="text" class="form-control" value="{{ $usuario->personal?->dni ?? '-' }}" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado laboral</label>
                        <input type="text" class="form-control" value="{{ $usuario->personal?->estado ?? '-' }}" readonly>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nombre completo</label>
                    <input type="text" class="form-control" value="{{ $usuario->personal?->nombre_completo ?? 'Sin trabajador vinculado' }}" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Puesto actual</label>
                    <input type="text" class="form-control" value="{{ $usuario->personal?->puesto ?? '-' }}" readonly>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Correo electrónico</label>
                        <input type="text" class="form-control" value="{{ $usuario->email }}" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha de registro</label>
                        <input type="text" class="form-control" value="{{ optional($usuario->created_at)->format('d/m/Y H:i') ?? '-' }}" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Roles y accesos asignados</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Rol principal</label>
                    <div>
                        <span class="badge badge-success" style="font-size: 14px; padding: 6px 12px;">{{ $usuario->rol?->nombre ?? 'Sin rol principal' }}</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Roles por área</label>
                    @if($areaRoles->isEmpty())
                        <p style="color: var(--color-text-secondary); margin: 0;">Sin accesos adicionales de área asignados.</p>
                    @else
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            @foreach($areaRoles as $rol)
                                <span class="badge badge-info">{{ $rol->nombre }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="form-group">
                    <label class="form-label">Roles por cargo</label>
                    @if($cargoRoles->isEmpty())
                        <p style="color: var(--color-text-secondary); margin: 0;">Sin accesos adicionales de cargo asignados.</p>
                    @else
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            @foreach($cargoRoles as $rol)
                                <span class="badge badge-warning">{{ $rol->nombre }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if($canGoToEdit)
                    <div style="margin-top: 16px;">
                        <a href="{{ route('usuarios.edit', $usuario->id) }}" class="btn btn-outline btn-sm">Gestionar roles y credenciales</a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Alcance Operativo (Scope Mina)</span>
                <span class="card-badge">{{ $usuario->scopesMina->count() }} mina(s)</span>
            </div>
            <div class="card-body">
                @if($usuario->scopesMina->isEmpty())
                    <div class="alert alert-info" style="margin-bottom: 12px;">
                        <strong>Scope Vacío:</strong> Este usuario no tiene minas registradas en su alcance multi-mina. Su visibilidad está restringida a su mina principal de adscripción.
                    </div>
                @else
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                        @foreach($usuario->scopesMina as $scope)
                            <span class="badge badge-success">{{ $scope->mina?->nombre ?? $scope->mina_id }}</span>
                        @endforeach
                    </div>
                @endif

                @if($isPrivilegedUserRole)
                    <p style="font-size: 13px; color: var(--color-text-secondary); margin-bottom: 12px;">
                        <em>* Por contar con rol corporativo ({{ $usuario->rol?->nombre }}), el usuario accede globalmente a todas las minas por política del sistema.</em>
                    </p>
                @endif

                @if($canScopeUser)
                    <a href="{{ route('usuarios.scope', $usuario->id) }}" class="btn btn-outline btn-sm">Configurar Scope Mina</a>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Notificaciones del Sistema</span>
            </div>
            <div class="card-body">
                <div class="form-row" style="margin-bottom: 12px;">
                    <div class="form-group">
                        <label class="form-label">Permiso general</label>
                        <div>
                            <span class="badge badge-{{ $notificationsAllowed ? 'success' : 'danger' }}">
                                {{ $notificationsAllowed ? 'Permitidas' : 'Denegadas' }}
                            </span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipos activos configurados</label>
                        <div>
                            <strong>{{ $notificationPreferencesCount ?? 0 }}</strong> canales activos
                        </div>
                    </div>
                </div>

                @if($canGoToEdit && $canConfigureUser)
                    <a href="{{ route('usuarios.edit', $usuario->id) }}#notificaciones" class="btn btn-outline btn-sm">Ajustar notificaciones</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
