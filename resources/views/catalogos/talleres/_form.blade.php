@php
    $isEdit = !empty($item['id']);
    $action = $isEdit ? route('catalogos.talleres.update', $item['id']) : route('catalogos.talleres.store');
    $title = $isEdit ? 'Editar Taller' : 'Nuevo Taller';
    $button = $isEdit ? 'Guardar cambios' : 'Crear taller';
@endphp

<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">{{ $title }}</h1>
            <p class="page-subtitle">Gestiona los datos del catálogo de talleres</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.talleres.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-error" style="margin-bottom:16px;">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ $action }}">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <div class="grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px;">
                <div>
                    <label class="form-label">Nombre</label>
                    <input type="text" name="nombre" class="form-control" value="{{ old('nombre', $item['nombre'] ?? '') }}" required>
                </div>
                <div>
                    <label class="form-label">Ubicación</label>
                    <input type="text" name="ubicacion" class="form-control" value="{{ old('ubicacion', $item['ubicacion'] ?? '') }}">
                </div>
                <div>
                    <label class="form-label">Estado</label>
                    @php $estado = old('estado', $item['estado'] ?? 'ACTIVO'); @endphp
                    <select name="estado" class="form-control">
                        <option value="ACTIVO" {{ strtoupper($estado) === 'ACTIVO' ? 'selected' : '' }}>ACTIVO</option>
                        <option value="INACTIVO" {{ strtoupper($estado) === 'INACTIVO' ? 'selected' : '' }}>INACTIVO</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top:18px;">
                <button type="submit" class="btn btn-primary">{{ $button }}</button>
                <a href="{{ route('catalogos.talleres.index') }}" class="btn btn-outline">Cancelar</a>
            </div>
        </form>
    </div>
</div>
