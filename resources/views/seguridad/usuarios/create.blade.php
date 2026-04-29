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

                        @php
                            $selectedAreas = collect(old('area_role_ids', []))->map(fn ($id) => (string) $id)->values();
                            $selectedCargos = collect(old('cargo_role_ids', []))->map(fn ($id) => (string) $id)->values();
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
                                        @if($selectedAreas->contains((string) $rol->id))
                                            <button type="button" class="role-chip role-chip-selected" data-role-id="{{ $rol->id }}" data-role-label="{{ $rol->nombre }}">
                                                <span>{{ $rol->nombre }}</span>
                                                <span class="role-chip-remove" aria-hidden="true">&times;</span>
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                                <p class="role-chip-empty" data-empty @if($selectedAreas->isNotEmpty()) style="display:none;" @endif>Sin accesos adicionales asignados.</p>
                                <div data-hidden-inputs>
                                    @foreach($selectedAreas as $roleId)
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
                                        @if($selectedCargos->contains((string) $rol->id))
                                            <button type="button" class="role-chip role-chip-selected role-chip-selected-cargo" data-role-id="{{ $rol->id }}" data-role-label="{{ $rol->nombre }}">
                                                <span>{{ $rol->nombre }}</span>
                                                <span class="role-chip-remove" aria-hidden="true">&times;</span>
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                                <p class="role-chip-empty" data-empty @if($selectedCargos->isNotEmpty()) style="display:none;" @endif>Sin accesos adicionales asignados.</p>
                                <div data-hidden-inputs>
                                    @foreach($selectedCargos as $roleId)
                                        <input type="hidden" name="cargo_role_ids[]" value="{{ $roleId }}">
                                    @endforeach
                                </div>
                            </div>
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
