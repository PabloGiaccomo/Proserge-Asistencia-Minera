@php
    $isEdit = $mode === 'edit';
    $actionUrl = $isEdit ? route('seguridad.roles.update', $rol->id) : route('seguridad.roles.store');
    $permissions = old('permisos', $rol->permisos ?? []);
    $moduleActions = $moduleActions ?? [];
    $notificationModules = $notificationModules ?? [];
    $notificationActions = $notificationActions ?? [];
    $notificationModuleActions = $notificationModuleActions ?? [];
    $notificationTypes = $notificationTypes ?? collect();
    $notificationRolePreferences = $notificationRolePreferences ?? collect();
@endphp

@if($errors->any())
    <div class="alert alert-error" style="margin-bottom:16px;">{{ $errors->first() }}</div>
@endif

<style>
.notification-pref-table { width: 100%; min-width: 760px; border-collapse: collapse; }
.notification-pref-table th, .notification-pref-table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: middle; }
.notification-pref-table th { color: #64748b; font-size: 12px; text-transform: uppercase; background: #f8fafc; }
.notification-pref-table td strong { display: block; color: #0f172a; font-size: 13px; }
.notification-pref-table td span { color: #64748b; font-size: 12px; }
.notification-toggle { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #334155; }
</style>

<form method="POST" action="{{ $actionUrl }}">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-2" style="align-items:start; margin-bottom:16px;">
        <div class="card">
            <div class="card-header"><span class="card-title">Datos del rol</span></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label required">Nombre</label>
                    <input type="text" name="nombre" class="form-control" value="{{ old('nombre', $rol->nombre) }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Descripción</label>
                    <textarea name="descripcion" class="form-control" rows="3">{{ old('descripcion', $rol->descripcion) }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label required">Estado</label>
                    <select name="estado" class="form-control" required>
                        <option value="ACTIVO" {{ old('estado', $rol->estado) === 'ACTIVO' ? 'selected' : '' }}>Activo</option>
                        <option value="INACTIVO" {{ old('estado', $rol->estado) === 'INACTIVO' ? 'selected' : '' }}>Inactivo</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Ayuda</span></div>
            <div class="card-body">
                <p style="color:var(--color-text-secondary); margin:0;">Marca en la matriz qué módulos puede ver el rol y qué acciones puede ejecutar dentro de cada uno. Esta base alimenta sidebar, vistas y acciones administrativas.</p>
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
                            $availableActions = $moduleActions[$module] ?? $actions;
                        @endphp
                        <tr>
                            <td><strong>{{ $label }}</strong></td>
                            @foreach($actions as $action)
                                <td style="text-align:center;">
                                    @if(in_array($action, $availableActions, true))
                                        <input type="checkbox" name="permisos[{{ $module }}][{{ $action }}]" value="1" {{ old("permisos.$module.$action", $permissions[$module][$action] ?? false) ? 'checked' : '' }}>
                                    @else
                                        <span style="color:var(--color-text-secondary);">-</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if(!empty($notificationModules))
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Matriz de notificaciones</span></div>
            <div class="card-body" style="overflow:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Módulo</th>
                            @foreach($notificationActions as $action)
                                <th>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($notificationModules as $module => $label)
                            @php
                                $availableActions = $notificationModuleActions[$module] ?? $notificationActions;
                            @endphp
                            <tr>
                                <td><strong>{{ $label }}</strong></td>
                                @foreach($notificationActions as $action)
                                    <td style="text-align:center;">
                                        @if(in_array($action, $availableActions, true))
                                            <input type="checkbox" name="permisos[{{ $module }}][{{ $action }}]" value="1" {{ old("permisos.$module.$action", $permissions[$module][$action] ?? false) ? 'checked' : '' }}>
                                        @else
                                            <span style="color:var(--color-text-secondary);">-</span>
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

    @if($notificationTypes->isNotEmpty())
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
                            <th>Recibir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($notificationTypes as $type)
                            @php
                                $rolePref = $notificationRolePreferences->get((string) $type->id);
                                $enabled = $rolePref ? (bool) $rolePref->is_enabled : true;
                            @endphp
                            <tr>
                                <td>
                                    <input type="hidden" name="notification_type_ids[]" value="{{ $type->id }}">
                                    <strong>{{ $type->default_title }}</strong>
                                    <span>{{ $type->code }}</span>
                                </td>
                                <td>{{ $type->module }}</td>
                                <td>{{ $type->category }}</td>
                                <td>{{ ucfirst($type->default_priority) }}</td>
                                <td>{{ $type->is_active ? 'Si' : 'No' }}</td>
                                <td>
                                    <label class="notification-toggle">
                                        <input type="checkbox" name="notificaciones[{{ $type->id }}]" value="1" {{ $enabled ? 'checked' : '' }}>
                                        <span>Recibir</span>
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="form-actions">
        <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar cambios' : 'Crear rol' }}</button>
    </div>
</form>
