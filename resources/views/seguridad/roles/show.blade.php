@extends('layouts.app')

@section('title', 'Rol - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Rol</h1>
                <p class="page-subtitle">{{ $rol->nombre }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Volver</a>
                @allowed('roles', 'editar')
                    <a href="{{ route('seguridad.roles.edit', $rol->id) }}" class="btn btn-primary">Editar permisos</a>
                @endallowed
            </div>
        </div>
    </div>

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
        <div class="card-header"><span class="card-title">Matriz de permisos</span></div>
        <div class="card-body" style="overflow:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Módulo</th>
                        @foreach($actions as $action)
                            <th>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($modules as $module => $label)
                        @php
                            $availableActions = ($moduleActions[$module] ?? $actions);
                        @endphp
                        <tr>
                            <td><strong>{{ $label }}</strong></td>
                            @foreach($actions as $action)
                                <td style="text-align:center;">
                                    @if(in_array($action, $availableActions, true))
                                        {{ (($rol->permisos[$module][$action] ?? false) === true) ? 'Si' : 'No' }}
                                    @else
                                        -
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if(!empty($notificationModules ?? []))
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Matriz de notificaciones</span></div>
            <div class="card-body" style="overflow:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Módulo</th>
                            @foreach(($notificationActions ?? []) as $action)
                                <th>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($notificationModules ?? []) as $module => $label)
                            @php
                                $availableActions = ($notificationModuleActions[$module] ?? ($notificationActions ?? []));
                            @endphp
                            <tr>
                                <td><strong>{{ $label }}</strong></td>
                                @foreach(($notificationActions ?? []) as $action)
                                    <td style="text-align:center;">
                                        @if(in_array($action, $availableActions, true))
                                            {{ (($rol->permisos[$module][$action] ?? false) === true) ? 'Si' : 'No' }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if(($notificationTypes ?? collect())->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Tipos de notificaciones por rol</span></div>
            <div class="card-body" style="overflow:auto;">
                <table class="notification-pref-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Modulo</th>
                            <th>Categoria</th>
                            <th>Prioridad</th>
                            <th>Activo</th>
                            <th>Recibe</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($notificationTypes ?? collect()) as $type)
                            @php
                                $rolePref = ($notificationRolePreferences ?? collect())->get((string) $type->id);
                                $enabled = $rolePref ? (bool) $rolePref->is_enabled : true;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $type->default_title }}</strong>
                                    <span>{{ $type->code }}</span>
                                </td>
                                <td>{{ $type->module }}</td>
                                <td>{{ $type->category }}</td>
                                <td>{{ ucfirst($type->default_priority) }}</td>
                                <td>{{ $type->is_active ? 'Si' : 'No' }}</td>
                                <td>{{ $enabled ? 'Si' : 'No' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
