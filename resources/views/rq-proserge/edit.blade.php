@extends('layouts.app')

@section('title', 'RQ Proserge - Editar')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Editar Solicitud</h1>
            <p class="page-subtitle">RQ Proserge #{{ $item['id'] ?? '' }}</p>
        </div>
    </div>
</div>

<form action="{{ route('rq-proserge.update', $item['id'] ?? '') }}" method="POST" class="form">
    @csrf
    @method('PUT')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Datos de la Solicitud</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group flex-2">
                    <label for="origen_tipo">Tipo Origen</label>
                    <select name="origen_tipo" id="origen_tipo" class="form-control" required>
                        <option value="taller" {{ ($item['origen_tipo'] ?? '') == 'taller' ? 'selected' : '' }}>Taller</option>
                        <option value="oficina" {{ ($item['origen_tipo'] ?? '') == 'oficina' ? 'selected' : '' }}>Oficina</option>
                        <option value="mina" {{ ($item['origen_tipo'] ?? '') == 'mina' ? 'selected' : '' }}>Mina</option>
                    </select>
                </div>
                <div class="form-group flex-2">
                    <label for="origen_id">Origen ID</label>
                    <input type="number" name="origen_id" id="origen_id" class="form-control" value="{{ $item['origen_id'] ?? '' }}" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group flex-2">
                    <label for="destino_tipo">Tipo Destino</label>
                    <select name="destino_tipo" id="destino_tipo" class="form-control" required>
                        <option value="taller" {{ ($item['destino_tipo'] ?? '') == 'taller' ? 'selected' : '' }}>Taller</option>
                        <option value="oficina" {{ ($item['destino_tipo'] ?? '') == 'oficina' ? 'selected' : '' }}>Oficina</option>
                        <option value="mina" {{ ($item['destino_tipo'] ?? '') == 'mina' ? 'selected' : '' }}>Mina</option>
                    </select>
                </div>
                <div class="form-group flex-2">
                    <label for="destino_id">Destino ID</label>
                    <input type="number" name="destino_id" id="destino_id" class="form-control" value="{{ $item['destino_id'] ?? '' }}" required>
                </div>
            </div>
            <div class="form-group">
                <label for="estado">Estado</label>
                <select name="estado" id="estado" class="form-control">
                    <option value="pendiente" {{ ($item['estado'] ?? 'pendiente') == 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                    <option value="en_proceso" {{ ($item['estado'] ?? '') == 'en_proceso' ? 'selected' : '' }}>En Proceso</option>
                    <option value="completado" {{ ($item['estado'] ?? '') == 'completado' ? 'selected' : '' }}>Completado</option>
                    <option value="cancelado" {{ ($item['estado'] ?? '') == 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                </select>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('rq-proserge.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
    </div>
</form>
@endsection