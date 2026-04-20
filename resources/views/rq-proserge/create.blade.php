@extends('layouts.app')

@section('title', 'RQ Proserge - Crear')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Nueva Solicitud</h1>
            <p class="page-subtitle">Crear solicitud de trabajo de Proserge</p>
        </div>
    </div>
</div>

<form action="{{ route('rq-proserge.store') }}" method="POST" class="form">
    @csrf
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Datos de la Solicitud</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group flex-2">
                    <label for="origen_tipo">Tipo Origen</label>
                    <select name="origen_tipo" id="origen_tipo" class="form-control" required>
                        <option value="">Seleccionar</option>
                        <option value="taller">Taller</option>
                        <option value="oficina">Oficina</option>
                        <option value="mina">Mina</option>
                    </select>
                </div>
                <div class="form-group flex-2">
                    <label for="origen_id">Origen</label>
                    <input type="number" name="origen_id" id="origen_id" class="form-control" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group flex-2">
                    <label for="destino_tipo">Tipo Destino</label>
                    <select name="destino_tipo" id="destino_tipo" class="form-control" required>
                        <option value="">Seleccionar</option>
                        <option value="taller">Taller</option>
                        <option value="oficina">Oficina</option>
                        <option value="mina">Mina</option>
                    </select>
                </div>
                <div class="form-group flex-2">
                    <label for="destino_id">Destino</label>
                    <input type="number" name="destino_id" id="destino_id" class="form-control" required>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Trabajos</h3>
        </div>
        <div class="card-body">
            <div id="trabajos-container">
                <div class="trabajo-item">
                    <div class="form-row">
                        <div class="form-group flex-2">
                            <label>Descripción del Trabajo</label>
                            <input type="text" name="trabajos[0][descripcion]" class="form-control" placeholder="Ej: Reparación de equipo">
                        </div>
                        <div class="form-group">
                            <label>Cantidad</label>
                            <input type="number" name="trabajos[0][cantidad]" class="form-control" value="1" min="1">
                        </div>
                        <div class="form-group">
                            <label>Unidad</label>
                            <select name="trabajos[0][unidad]" class="form-control">
                                <option value="und">Unidad</option>
                                <option value="hr">Horas</option>
                                <option value="dias">Días</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="agregarTrabajo()">
                + Agregar Trabajo
            </button>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('rq-proserge.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Crear Solicitud</button>
    </div>
</form>

@push('scripts')
<script>
let trabajoCount = 1;

function agregarTrabajo() {
    const container = document.getElementById('trabajos-container');
    const html = `
        <div class="trabajo-item">
            <div class="form-row">
                <div class="form-group flex-2">
                    <label>Descripción del Trabajo</label>
                    <input type="text" name="trabajos[${trabajoCount}][descripcion]" class="form-control" placeholder="Ej: Reparación de equipo">
                </div>
                <div class="form-group">
                    <label>Cantidad</label>
                    <input type="number" name="trabajos[${trabajoCount}][cantidad]" class="form-control" value="1" min="1">
                </div>
                <div class="form-group">
                    <label>Unidad</label>
                    <select name="trabajos[${trabajoCount}][unidad]" class="form-control">
                        <option value="und">Unidad</option>
                        <option value="hr">Horas</option>
                        <option value="dias">Días</option>
                    </select>
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