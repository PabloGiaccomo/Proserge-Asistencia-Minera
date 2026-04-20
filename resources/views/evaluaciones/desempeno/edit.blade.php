@extends('layouts.app')

@section('title', 'Evaluación de Desempeño - Editar')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Editar Evaluación</h1>
            <p class="page-subtitle">Evaluación #{{ $item['id'] ?? '' }}</p>
        </div>
    </div>
</div>

<form action="{{ route('evaluaciones.desempeno.update', $item['id'] ?? '') }}" method="POST" class="form">
    @csrf
    @method('PUT')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Datos de la Evaluación</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="estado">Estado</label>
                <select name="estado" id="estado" class="form-control">
                    <option value="pendiente" {{ ($item['estado'] ?? 'pendiente') == 'pendiente' ? 'selected' : '' }}>Pendiente</option>
                    <option value="completada" {{ ($item['estado'] ?? '') == 'completada' ? 'selected' : '' }}>Completada</option>
                </select>
            </div>
            <div class="form-group">
                <label for="observaciones">Observaciones</label>
                <textarea name="observaciones" id="observaciones" class="form-control" rows="4">{{ $item['observaciones'] ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('evaluaciones.desempeno.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
    </div>
</form>
@endsection