@extends('layouts.app')

@section('title', 'RQ Mina - Crear')

@php
$copyData = $copyData ?? null;
$detalle = $copyData['detalle'] ?? [['puesto' => '', 'cantidad' => 1]];
$formMode = $formMode ?? 'create';
$formAction = $formAction ?? route('rq-mina.store');
$formMethod = $formMethod ?? 'POST';
$submitLabel = $submitLabel ?? 'Guardar como Borrador';
$isEdit = $formMode === 'edit';
@endphp

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">{{ $isEdit ? 'Editar Solicitud' : 'Nueva Solicitud' }}</h1>
            <p class="page-subtitle">{{ $isEdit ? 'Editar datos del requerimiento de mina' : 'Crear solicitud de personal para mina' }}</p>
        </div>
    </div>
</div>

@if($copyData)
<div class="card" style="margin-bottom: 16px;">
    <div class="card-body">
        <strong>{{ $isEdit ? 'Editando RQ #' : 'Copiando RQ #' }}{{ $copyData['id'] }}</strong>
        <p style="margin: 6px 0 0; color: #64748b;">{{ $isEdit ? 'Puedes actualizar los datos y guardar los cambios.' : 'Se cargaron los mismos datos para que registres uno nuevo.' }}</p>
    </div>
</div>
@endif

<form action="{{ $formAction }}" method="POST" class="form">
    @csrf
    @if($formMethod !== 'POST')
        @method($formMethod)
    @endif
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos Generales</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="mina">Mina</label>
                    <select name="mina" id="mina" class="form-control" required>
                        <option value="">Seleccionar mina</option>
                        @foreach($minas as $mina)
                        <option value="{{ $mina }}" {{ ($copyData['mina'] ?? '') === $mina ? 'selected' : '' }}>{{ $mina }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="area">Area</label>
                    <input type="text" name="area" id="area" class="form-control" value="{{ $copyData['area'] ?? '' }}" required>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Fechas</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="fecha_inicio">Fecha inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" value="{{ $copyData['fecha_inicio'] ?? '' }}" required>
                </div>
                <div class="form-group">
                    <label for="fecha_fin">Fecha fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" value="{{ $copyData['fecha_fin'] ?? '' }}" required>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <h3 class="card-title">Observaciones</h3>
        </div>
        <div class="card-body">
            <textarea name="observaciones" class="form-control" rows="3" placeholder="Observaciones del requerimiento">{{ $copyData['observaciones'] ?? '' }}</textarea>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-header">
            <h3 class="card-title">Detalle por puesto</h3>
        </div>
        <div class="card-body">
            <div id="trabajos-container">
                @foreach($detalle as $idx => $line)
                    <div class="trabajo-item">
                        <div class="form-row">
                            <div class="form-group flex-2">
                                <label>Puesto</label>
                                <input type="text" name="detalle[{{ $idx }}][puesto]" class="form-control" value="{{ $line['puesto'] ?? '' }}" placeholder="Ej: Operador" required>
                            </div>
                            <div class="form-group">
                                <label>Cantidad</label>
                                <input type="number" name="detalle[{{ $idx }}][cantidad]" class="form-control" value="{{ $line['cantidad'] ?? 1 }}" min="1" required>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="agregarTrabajo()">
                + Agregar Puesto
            </button>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('rq-mina.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
    </div>
</form>

@push('scripts')
<script>
let trabajoCount = {{ count($detalle) }};

function agregarTrabajo() {
    const container = document.getElementById('trabajos-container');
    const html = `
        <div class="trabajo-item">
            <div class="form-row">
                <div class="form-group flex-2">
                    <label>Puesto</label>
                    <input type="text" name="detalle[${trabajoCount}][puesto]" class="form-control" placeholder="Ej: Operador" required>
                </div>
                <div class="form-group">
                    <label>Cantidad</label>
                    <input type="number" name="detalle[${trabajoCount}][cantidad]" class="form-control" value="1" min="1" required>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    trabajoCount++;
}
</script>
@endpush
@endsection