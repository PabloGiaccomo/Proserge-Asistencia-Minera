@php
    $isEdit = $mode === 'edit';
    $actionUrl = $isEdit ? route('seguridad.roles.update', $rol->id) : route('seguridad.roles.store');
    $permissions = old('permisos', $rol->permisos ?? []);
@endphp

@if($errors->any())
    <div class="alert alert-error" style="margin-bottom:16px;">{{ $errors->first() }}</div>
@endif

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
                        <tr>
                            <td><strong>{{ $label }}</strong></td>
                            @foreach($actions as $action)
                                <td style="text-align:center;">
                                    <input type="checkbox" name="permisos[{{ $module }}][{{ $action }}]" value="1" {{ old("permisos.$module.$action", $permissions[$module][$action] ?? false) ? 'checked' : '' }}>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('seguridad.roles.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar cambios' : 'Crear rol' }}</button>
    </div>
</form>
