@extends('layouts.app')

@section('title', 'Rol - Proserge')

@section('content')
@php
    $rolePermissions = session('user.permissions', []);
    $canEditRolePermissions = \App\Support\Rbac\PermissionMatrix::allowsDirectAny($rolePermissions, 'roles', ['editar', 'actualizar']);
@endphp
<style>
.permission-screen-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.permission-screen-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fff; }
.permission-screen-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.permission-screen-title { margin: 0; color: #0f172a; font-size: 14px; font-weight: 700; }
.permission-screen-key { margin-top: 2px; color: #64748b; font-size: 11px; }
.permission-screen-count { flex: 0 0 auto; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 700; padding: 4px 8px; }
.permission-action-list { display: grid; gap: 8px; }
.permission-action-state { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 38px; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 9px; background: #f8fafc; color: #334155; font-size: 13px; font-weight: 600; }
.permission-action-state.is-enabled { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }
.permission-action-badge { flex: 0 0 auto; border-radius: 999px; background: #e2e8f0; color: #475569; font-size: 11px; font-weight: 700; padding: 3px 8px; }
.permission-action-state.is-enabled .permission-action-badge { background: #dcfce7; color: #166534; }
.permission-action-empty { padding: 8px 0; color: #64748b; font-size: 12px; }
.notification-type-list { display: grid; gap: 10px; }
.notification-type-item { display: grid; gap: 8px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 9px; background: #f8fafc; }
.notification-type-main { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.notification-type-title { display: block; color: #0f172a; font-size: 13px; font-weight: 700; }
.notification-type-code { display: block; margin-top: 2px; color: #64748b; font-size: 11px; }
.notification-type-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.notification-type-pill { border-radius: 999px; background: #e2e8f0; color: #475569; font-size: 11px; font-weight: 700; padding: 3px 8px; }
.notification-type-pill.is-active { background: #dcfce7; color: #166534; }
.notification-type-pill.is-inactive { background: #fee2e2; color: #991b1b; }
</style>

<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Rol</h1>
                <p class="page-subtitle">{{ $rol->nombre }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Volver</a>
                @if($canEditRolePermissions)
                    <a href="{{ route('seguridad.roles.edit', $rol->id) }}" class="btn btn-primary">Editar permisos</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error" style="margin-bottom:16px;">{{ session('error') }}</div>
    @endif

    <div class="grid grid-2" style="align-items:start; margin-bottom:16px;">
        <div class="card">
            <div class="card-header"><span class="card-title">Información general</span></div>
            <div class="card-body">
                <div class="form-group"><label class="form-label">Nombre</label><input class="form-control" value="{{ $rol->nombre }}" readonly></div>
                <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-control" rows="3" readonly>{{ $rol->descripcion }}</textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Estado</label><input class="form-control" value="{{ strtoupper((string) $rol->estado) }}" readonly></div>
                    <div class="form-group"><label class="form-label">Usuarios asignados</label><input class="form-control" value="{{ $rol->usuarios_count ?? $rol->usuarios->count() }}" readonly></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Usuarios con este rol</span></div>
            <div class="card-body">
                @forelse($rol->usuarios as $usuario)
                    <div style="padding:10px 0; border-bottom:1px solid #e5e7eb;">{{ $usuario->email }}</div>
                @empty
                    <p style="color:var(--color-text-secondary);">No hay usuarios asignados a este rol.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">Permisos por pantalla</span></div>
        <div class="card-body">
            <div class="permission-screen-grid">
                @foreach($modules as $module => $label)
                    @php
                        $availableActions = ($moduleActions[$module] ?? []);
                    @endphp
                    <section class="permission-screen-card">
                        <div class="permission-screen-head">
                            <div>
                                <h3 class="permission-screen-title">{{ $label }}</h3>
                                <div class="permission-screen-key">{{ $module }}</div>
                            </div>
                            <span class="permission-screen-count">{{ count($availableActions) }} accion{{ count($availableActions) === 1 ? '' : 'es' }}</span>
                        </div>
                        <div class="permission-action-list">
                            @forelse($availableActions as $action)
                                @php
                                    $enabled = (($rol->permisos[$module][$action] ?? false) === true);
                                @endphp
                                <div class="permission-action-state {{ $enabled ? 'is-enabled' : '' }}">
                                    <span>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</span>
                                    <span class="permission-action-badge">{{ $enabled ? 'Si' : 'No' }}</span>
                                </div>
                            @empty
                                <div class="permission-action-empty">Sin acciones configuradas.</div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </div>

    @if(!empty($notificationModules ?? []))
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Permisos de notificaciones</span></div>
            <div class="card-body">
                <div class="permission-screen-grid">
                    @foreach(($notificationModules ?? []) as $module => $label)
                        @php
                            $availableActions = ($notificationModuleActions[$module] ?? []);
                        @endphp
                        <section class="permission-screen-card">
                            <div class="permission-screen-head">
                                <div>
                                    <h3 class="permission-screen-title">{{ $label }}</h3>
                                    <div class="permission-screen-key">{{ $module }}</div>
                                </div>
                                <span class="permission-screen-count">{{ count($availableActions) }} accion{{ count($availableActions) === 1 ? '' : 'es' }}</span>
                            </div>
                            <div class="permission-action-list">
                                @forelse($availableActions as $action)
                                    @php
                                        $enabled = (($rol->permisos[$module][$action] ?? false) === true);
                                    @endphp
                                    <div class="permission-action-state {{ $enabled ? 'is-enabled' : '' }}">
                                        <span>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</span>
                                        <span class="permission-action-badge">{{ $enabled ? 'Si' : 'No' }}</span>
                                    </div>
                                @empty
                                    <div class="permission-action-empty">Sin acciones configuradas.</div>
                                @endforelse
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if(($notificationTypes ?? collect())->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Tipos de notificaciones por rol</span></div>
            <div class="card-body">
                <div class="permission-screen-grid">
                    @foreach(($notificationTypes ?? collect())->groupBy('module') as $module => $types)
                        <section class="permission-screen-card">
                            <div class="permission-screen-head">
                                <div>
                                    <h3 class="permission-screen-title">{{ \App\Support\Rbac\PermissionCatalog::moduleLabel((string) $module) }}</h3>
                                    <div class="permission-screen-key">{{ $module }}</div>
                                </div>
                                <span class="permission-screen-count">{{ $types->count() }} tipo{{ $types->count() === 1 ? '' : 's' }}</span>
                            </div>
                            <div class="notification-type-list">
                                @foreach($types as $type)
                                    @php
                                        $rolePref = ($notificationRolePreferences ?? collect())->get((string) $type->id);
                                        $enabled = $rolePref ? (bool) $rolePref->is_enabled : true;
                                    @endphp
                                    <div class="notification-type-item">
                                        <div class="notification-type-main">
                                            <div>
                                                <strong class="notification-type-title">{{ $type->default_title }}</strong>
                                                <span class="notification-type-code">{{ $type->code }}</span>
                                            </div>
                                            <span class="permission-action-badge">{{ $enabled ? 'Recibe' : 'No recibe' }}</span>
                                        </div>
                                        <div class="notification-type-meta">
                                            <span class="notification-type-pill">{{ $type->category }}</span>
                                            <span class="notification-type-pill">{{ ucfirst($type->default_priority) }}</span>
                                            <span class="notification-type-pill {{ $type->is_active ? 'is-active' : 'is-inactive' }}">{{ $type->is_active ? 'Activo' : 'Inactivo' }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
