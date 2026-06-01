@extends('layouts.app')

@section('title', 'Usuario - Proserge')

@section('content')
@php
    $estado = $hasEstadoColumn ? strtoupper((string) $usuario->estado) : 'ACTIVO';
    $areaRoleIds = $usuario->rolesAdicionales->filter(fn ($rol) => ($rol->pivot->tipo ?? null) === 'area')->pluck('id')->values()->all();
    $cargoRoleIds = $usuario->rolesAdicionales->filter(fn ($rol) => ($rol->pivot->tipo ?? null) === 'cargo')->pluck('id')->values()->all();
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

                    @php
                        $areaSelected = collect(old('area_role_ids', $areaRoleIds))->map(fn ($id) => (string) $id)->values();
                        $cargoSelected = collect(old('cargo_role_ids', $cargoRoleIds))->map(fn ($id) => (string) $id)->values();
                        $areas = ($roleBuckets['areas'] ?? collect());
                        $cargos = ($roleBuckets['cargos'] ?? collect());
                    @endphp

                    <div class="role-accesses" data-role-manager>
                        <h3 class="role-accesses-title">Roles del usuario</h3>

                        <div class="role-access-block" data-input-name="area_role_ids[]" data-chip-type="area">
                            <label class="form-label">Roles por area</label>
                            <div class="role-pick-row">
                                <select class="form-control" data-role-select>
                                    <option value="">Selecciona un rol de area</option>
                                    @foreach($areas as $rol)
                                        <option value="{{ $rol->id }}" data-role-label="{{ $rol->nombre }}">{{ $rol->nombre }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-outline" data-add-role>Agregar</button>
                            </div>
                            <div class="role-chip-grid" data-assigned>
                                @foreach($areas as $rol)
                                    @if($areaSelected->contains((string) $rol->id))
                                        <button type="button" class="role-chip role-chip-selected" data-role-id="{{ $rol->id }}" data-role-label="{{ $rol->nombre }}">
                                            <span>{{ $rol->nombre }}</span>
                                            <span class="role-chip-remove" aria-hidden="true">&times;</span>
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                            <p class="role-chip-empty" data-empty @if($areaSelected->isNotEmpty()) style="display:none;" @endif>Sin accesos adicionales asignados.</p>
                            <div data-hidden-inputs>
                                @foreach($areaSelected as $roleId)
                                    <input type="hidden" name="area_role_ids[]" value="{{ $roleId }}">
                                @endforeach
                            </div>
                        </div>

                        <div class="role-access-block" data-input-name="cargo_role_ids[]" data-chip-type="cargo">
                            <label class="form-label">Roles por cargo</label>
                            <div class="role-pick-row">
                                <select class="form-control" data-role-select>
                                    <option value="">Selecciona un rol de cargo</option>
                                    @foreach($cargos as $rol)
                                        <option value="{{ $rol->id }}" data-role-label="{{ $rol->nombre }}">{{ $rol->nombre }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-outline" data-add-role>Agregar</button>
                            </div>
                            <div class="role-chip-grid" data-assigned>
                                @foreach($cargos as $rol)
                                    @if($cargoSelected->contains((string) $rol->id))
                                        <button type="button" class="role-chip role-chip-selected role-chip-selected-cargo" data-role-id="{{ $rol->id }}" data-role-label="{{ $rol->nombre }}">
                                            <span>{{ $rol->nombre }}</span>
                                            <span class="role-chip-remove" aria-hidden="true">&times;</span>
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                            <p class="role-chip-empty" data-empty @if($cargoSelected->isNotEmpty()) style="display:none;" @endif>Sin accesos adicionales asignados.</p>
                            <div data-hidden-inputs>
                                @foreach($cargoSelected as $roleId)
                                    <input type="hidden" name="cargo_role_ids[]" value="{{ $roleId }}">
                                @endforeach
                            </div>
                        </div>
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

    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <span class="card-title">Notificaciones</span>
            <span class="card-badge">{{ ($notificationTypes ?? collect())->count() }} tipos</span>
        </div>
        <div class="card-body">
            @allowed('usuarios', 'administrar')
                <form method="POST" action="{{ route('usuarios.notificaciones', $usuario->id) }}">
                    @csrf
                    <div class="notification-screen-grid">
                        @foreach(($notificationTypes ?? collect())->groupBy('module') as $module => $types)
                            <section class="notification-screen-card">
                                <div class="notification-screen-head">
                                    <div>
                                        <h3 class="notification-screen-title">{{ \App\Support\Rbac\PermissionCatalog::moduleLabel((string) $module) }}</h3>
                                        <div class="notification-screen-key">{{ $module }}</div>
                                    </div>
                                    <span class="notification-screen-count">{{ $types->count() }} tipo{{ $types->count() === 1 ? '' : 's' }}</span>
                                </div>
                                <div class="notification-type-list">
                                    @foreach($types as $type)
                                        @php
                                            $preference = ($notificationPreferences ?? collect())->get((string) $type->id);
                                            $enabled = $preference ? (bool) $preference->in_app_enabled : true;
                                            $minimumPriority = $preference?->minimum_priority ?? 'low';
                                        @endphp
                                        <div class="notification-type-item">
                                            <div class="notification-type-main">
                                                <div>
                                                    <input type="hidden" name="notification_type_ids[]" value="{{ $type->id }}">
                                                    <strong class="notification-type-title">{{ $type->default_title }}</strong>
                                                    <span class="notification-type-code">{{ $type->code }}</span>
                                                </div>
                                                <label class="notification-toggle">
                                                    <input type="checkbox" name="preferences[{{ $type->id }}][in_app_enabled]" value="1" {{ $enabled ? 'checked' : '' }}>
                                                    <span>Activo</span>
                                                </label>
                                            </div>
                                            <div class="notification-type-meta">
                                                <span class="notification-type-pill">{{ $type->category }}</span>
                                                <span class="notification-type-pill {{ $type->is_active ? 'is-active' : 'is-inactive' }}">{{ $type->is_active ? 'Activo' : 'Inactivo' }}</span>
                                            </div>
                                            <div class="form-group" style="margin:0;">
                                                <label class="form-label">Prioridad minima</label>
                                                <select name="preferences[{{ $type->id }}][minimum_priority]" class="form-control">
                                                    @foreach(['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Critica'] as $priority => $label)
                                                        <option value="{{ $priority }}" {{ $minimumPriority === $priority ? 'selected' : '' }}>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Guardar preferencias</button>
                    </div>
                </form>
            @else
                <p style="color: var(--color-text-secondary);">No tienes permiso para administrar notificaciones de usuario.</p>
            @endallowed
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <span class="card-title">Roles organizados</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Rol base</label>
                    <div><span class="badge badge-success">{{ $usuario->rol?->nombre ?? '-' }}</span></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Areas</label>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        @forelse($usuario->rolesAdicionales->filter(fn ($rol) => ($rol->pivot->tipo ?? null) === 'area') as $rol)
                            <span class="badge badge-info">{{ $rol->nombre }}</span>
                        @empty
                            <span style="color: var(--color-text-secondary);">Sin areas adicionales</span>
                        @endforelse
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cargos</label>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        @forelse($usuario->rolesAdicionales->filter(fn ($rol) => ($rol->pivot->tipo ?? null) === 'cargo') as $rol)
                            <span class="badge badge-warning">{{ $rol->nombre }}</span>
                        @empty
                            <span style="color: var(--color-text-secondary);">Sin cargos adicionales</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.role-accesses { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; margin-top: 10px; }
.role-accesses-title { margin: 0 0 10px; font-size: 15px; font-weight: 700; color: #0f172a; }
.role-access-block { margin-top: 12px; }
.role-pick-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
.role-chip-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.role-chip { border: 1px solid #0d9488; background: #ccfbf1; color: #0f766e; border-radius: 999px; padding: 6px 11px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.role-chip-selected-cargo { border-color: #8b5cf6; background: #ede9fe; color: #5b21b6; }
.role-chip-remove { font-size: 13px; line-height: 1; opacity: .85; }
.role-chip-empty { margin: 6px 0 0; font-size: 12px; color: #94a3b8; }
.notification-toggle { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #334155; }
.notification-screen-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
.notification-screen-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fff; }
.notification-screen-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.notification-screen-title { margin: 0; color: #0f172a; font-size: 14px; font-weight: 700; }
.notification-screen-key { margin-top: 2px; color: #64748b; font-size: 11px; }
.notification-screen-count { flex: 0 0 auto; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 700; padding: 4px 8px; }
.notification-type-list { display: grid; gap: 10px; }
.notification-type-item { display: grid; gap: 8px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 9px; background: #f8fafc; }
.notification-type-main { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.notification-type-title { display: block; color: #0f172a; font-size: 13px; font-weight: 700; }
.notification-type-code { display: block; margin-top: 2px; color: #64748b; font-size: 11px; }
.notification-type-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.notification-type-pill { border-radius: 999px; background: #e2e8f0; color: #475569; font-size: 11px; font-weight: 700; padding: 3px 8px; }
.notification-type-pill.is-active { background: #dcfce7; color: #166534; }
.notification-type-pill.is-inactive { background: #fee2e2; color: #991b1b; }
@media (max-width: 768px) {
    .role-accesses { padding: 12px; }
    .role-pick-row { grid-template-columns: 1fr; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-role-manager]').forEach(function (manager) {
        manager.querySelectorAll('.role-access-block').forEach(function (block) {
            const select = block.querySelector('[data-role-select]');
            const addBtn = block.querySelector('[data-add-role]');
            const assigned = block.querySelector('[data-assigned]');
            const hidden = block.querySelector('[data-hidden-inputs]');
            const empty = block.querySelector('[data-empty]');
            const inputName = block.dataset.inputName;
            const isCargo = block.dataset.chipType === 'cargo';

            const updateEmpty = function () {
                if (!empty) return;
                empty.style.display = assigned.children.length === 0 ? '' : 'none';
            };

            const hasRole = function (roleId) {
                return Array.from(hidden.querySelectorAll('input')).some(function (input) {
                    return input.value === roleId;
                });
            };

            const addHidden = function (roleId) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = inputName;
                input.value = roleId;
                hidden.appendChild(input);
            };

            const removeHidden = function (roleId) {
                const input = Array.from(hidden.querySelectorAll('input')).find(function (node) {
                    return node.value === roleId;
                });
                if (input) input.remove();
            };

            const mountChip = function (roleId, roleLabel) {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'role-chip' + (isCargo ? ' role-chip-selected-cargo' : '');
                chip.dataset.roleId = roleId;
                chip.dataset.roleLabel = roleLabel;
                chip.innerHTML = '<span>' + roleLabel + '</span><span class="role-chip-remove" aria-hidden="true">&times;</span>';
                chip.addEventListener('click', function () {
                    chip.remove();
                    removeHidden(roleId);
                    updateEmpty();
                });
                assigned.appendChild(chip);
                updateEmpty();
            };

            addBtn.addEventListener('click', function () {
                const option = select.options[select.selectedIndex];
                if (!option || !option.value) {
                    return;
                }

                const roleId = option.value;
                const roleLabel = option.dataset.roleLabel || option.textContent.trim();
                if (hasRole(roleId)) {
                    return;
                }

                addHidden(roleId);
                mountChip(roleId, roleLabel);
                select.value = '';
            });

            assigned.querySelectorAll('[data-role-id]').forEach(function (chip) {
                const roleId = chip.dataset.roleId;
                chip.addEventListener('click', function () {
                    chip.remove();
                    removeHidden(roleId);
                    updateEmpty();
                });
            });

            updateEmpty();
        });
    });
});
</script>
@endsection
