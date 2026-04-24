@php
    $isEdit = !empty($item['id']);
    $action = $isEdit ? route('catalogos.minas.update', $item['id']) : route('catalogos.minas.store');
    $title = $isEdit ? 'Editar Mina' : 'Nueva Mina';
    $button = $isEdit ? 'Guardar cambios' : 'Crear mina';
    $paraderos = old('paraderos', $item['paraderos'] ?? []);
@endphp

<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">{{ $title }}</h1>
            <p class="page-subtitle">Gestiona los datos de la mina y sus paraderos asociados</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('catalogos.minas.index') }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ $action }}" id="minaForm">
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
                    <label class="form-label">Unidad Minera</label>
                    <input type="text" name="unidad_minera" class="form-control" value="{{ old('unidad_minera', $item['unidad_minera'] ?? '') }}">
                </div>
                <div>
                    <label class="form-label">Ubicación</label>
                    <input type="text" name="ubicacion" class="form-control" value="{{ old('ubicacion', $item['ubicacion'] ?? '') }}">
                </div>
                <div>
                    <label class="form-label">Link de Ubicación</label>
                    <input type="text" name="link_ubicacion" class="form-control" value="{{ old('link_ubicacion', $item['link_ubicacion'] ?? '') }}">
                </div>
                <div>
                    <label class="form-label">Color</label>
                    <input type="text" name="color" class="form-control" value="{{ old('color', $item['color'] ?? '') }}" placeholder="#19D3C5">
                </div>
                <div>
                    <label class="form-label">Estado</label>
                    <select name="estado" class="form-control">
                        @php $estado = old('estado', $item['estado'] ?? 'ACTIVO'); @endphp
                        <option value="ACTIVO" {{ strtoupper($estado) === 'ACTIVO' ? 'selected' : '' }}>ACTIVO</option>
                        <option value="INACTIVO" {{ strtoupper($estado) === 'INACTIVO' ? 'selected' : '' }}>INACTIVO</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:18px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <h3 style="margin:0; font-size:16px;">Paraderos de la Mina</h3>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addParaderoRow()">Agregar paradero</button>
                </div>

                <div class="table-responsive">
                    <table class="data-table" id="paraderosTable">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Ubicación</th>
                                <th>Link</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($paraderos as $index => $paradero)
                            <tr>
                                <td>
                                    <input type="hidden" name="paraderos[{{ $index }}][id]" value="{{ $paradero['id'] ?? '' }}">
                                    <input type="text" name="paraderos[{{ $index }}][nombre]" class="form-control" value="{{ $paradero['nombre'] ?? '' }}">
                                </td>
                                <td><input type="text" name="paraderos[{{ $index }}][ubicacion]" class="form-control" value="{{ $paradero['ubicacion'] ?? '' }}"></td>
                                <td><input type="text" name="paraderos[{{ $index }}][link_ubicacion]" class="form-control" value="{{ $paradero['link_ubicacion'] ?? '' }}"></td>
                                <td>
                                    @php $estadoParadero = strtoupper($paradero['estado'] ?? 'ACTIVO'); @endphp
                                    <select name="paraderos[{{ $index }}][estado]" class="form-control">
                                        <option value="ACTIVO" {{ $estadoParadero === 'ACTIVO' ? 'selected' : '' }}>ACTIVO</option>
                                        <option value="INACTIVO" {{ $estadoParadero === 'INACTIVO' ? 'selected' : '' }}>INACTIVO</option>
                                    </select>
                                </td>
                                <td><button type="button" class="btn btn-sm btn-outline" onclick="removeParaderoRow(this)">Quitar</button></td>
                            </tr>
                            @empty
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top:18px;">
                <button type="submit" class="btn btn-primary">{{ $button }}</button>
                <a href="{{ route('catalogos.minas.index') }}" class="btn btn-outline">Cancelar</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function addParaderoRow() {
    const tbody = document.querySelector('#paraderosTable tbody');
    const index = tbody.querySelectorAll('tr').length;

    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <input type="hidden" name="paraderos[${index}][id]" value="">
            <input type="text" name="paraderos[${index}][nombre]" class="form-control">
        </td>
        <td><input type="text" name="paraderos[${index}][ubicacion]" class="form-control"></td>
        <td><input type="text" name="paraderos[${index}][link_ubicacion]" class="form-control"></td>
        <td>
            <select name="paraderos[${index}][estado]" class="form-control">
                <option value="ACTIVO" selected>ACTIVO</option>
                <option value="INACTIVO">INACTIVO</option>
            </select>
        </td>
        <td><button type="button" class="btn btn-sm btn-outline" onclick="removeParaderoRow(this)">Quitar</button></td>
    `;

    tbody.appendChild(row);
}

function removeParaderoRow(button) {
    const row = button.closest('tr');
    if (!row) return;
    row.remove();
}
</script>
@endpush
