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
.notification-toggle { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #334155; }
.permission-screen-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.permission-screen-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fff; }
.permission-screen-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.permission-screen-title { margin: 0; color: #0f172a; font-size: 14px; font-weight: 700; }
.permission-screen-key { margin-top: 2px; color: #64748b; font-size: 11px; }
.permission-screen-count { flex: 0 0 auto; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 700; padding: 4px 8px; }
.permission-action-list { display: grid; gap: 8px; }
.permission-action-toggle { display: flex; align-items: center; gap: 8px; min-height: 38px; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 9px; background: #f8fafc; color: #334155; font-size: 13px; font-weight: 600; }
.permission-action-toggle input { margin: 0; }
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
                <p style="color:var(--color-text-secondary); margin:0;">Marca en cada pantalla las acciones que puede realizar el rol. Esta base alimenta sidebar, vistas y acciones administrativas.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">Permisos por pantalla</span></div>
        <div class="card-body">
            <div class="permission-screen-grid">
                @foreach($modules as $module => $label)
                    @php
                        $availableActions = $moduleActions[$module] ?? [];
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
                                <label class="permission-action-toggle">
                                    <input type="checkbox" name="permisos[{{ $module }}][{{ $action }}]" value="1" {{ old("permisos.$module.$action", $permissions[$module][$action] ?? false) ? 'checked' : '' }}>
                                    <span>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</span>
                                </label>
                            @empty
                                <div class="permission-action-empty">Sin acciones configuradas.</div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </div>

    @if(!empty($notificationModules))
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Permisos de notificaciones</span></div>
            <div class="card-body">
                <div class="permission-screen-grid">
                    @foreach($notificationModules as $module => $label)
                        @php
                            $availableActions = $notificationModuleActions[$module] ?? [];
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
                                    <label class="permission-action-toggle">
                                        <input type="checkbox" name="permisos[{{ $module }}][{{ $action }}]" value="1" {{ old("permisos.$module.$action", $permissions[$module][$action] ?? false) ? 'checked' : '' }}>
                                        <span>{{ \App\Support\Rbac\PermissionCatalog::actionLabel($action) }}</span>
                                    </label>
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

    @if($notificationTypes->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Tipos de notificaciones por rol</span></div>
            <div class="card-body">
                <div class="permission-screen-grid">
                    @foreach($notificationTypes->groupBy('module') as $module => $types)
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
                                        $rolePref = $notificationRolePreferences->get((string) $type->id);
                                        $enabled = $rolePref ? (bool) $rolePref->is_enabled : true;
                                    @endphp
                                    <div class="notification-type-item">
                                        <div class="notification-type-main">
                                            <div>
                                                <input type="hidden" name="notification_type_ids[]" value="{{ $type->id }}">
                                                <strong class="notification-type-title">{{ $type->default_title }}</strong>
                                                <span class="notification-type-code">{{ $type->code }}</span>
                                            </div>
                                            <label class="notification-toggle">
                                                <input type="checkbox" name="notificaciones[{{ $type->id }}]" value="1" {{ $enabled ? 'checked' : '' }}>
                                                <span>Recibir</span>
                                            </label>
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

    <div class="form-actions">
        <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar cambios' : 'Crear rol' }}</button>
    </div>
</form>
