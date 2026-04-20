@extends('layouts.app')

@section('title', 'Evaluación de Desempeño - Crear')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Nueva Evaluación</h1>
            <p class="page-subtitle">Crear evaluación de desempeño</p>
        </div>
    </div>
</div>

<form action="{{ route('evaluaciones.desempeno.store') }}" method="POST" class="form">
    @csrf
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Datos de la Evaluación</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group flex-2">
                    <label for="evaluado_id">Evaluado</label>
                    <select name="evaluado_id" id="evaluado_id" class="form-control" required>
                        <option value="">Seleccionar trabajador</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tipo">Tipo de Evaluación</label>
                    <select name="tipo" id="tipo" class="form-control" required>
                        <option value="supervisor">Evaluación por Supervisor</option>
                        <option value="residente">Evaluación por Residente</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="periodo">Período</label>
                <select name="periodo" id="periodo" class="form-control" required>
                    <option value="mensual">Mensual</option>
                    <option value="trimestral">Trimestral</option>
                    <option value="semestral">Semestral</option>
                    <option value="anual">Anual</option>
                </select>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Criterios de Evaluación</h3>
        </div>
        <div class="card-body">
            <div class="criterios-container">
                @foreach(['Trabajo en equipo', 'Productividad', 'Calidad', 'Puntualidad', 'Responsabilidad'] as $index => $criterio)
                <div class="form-group">
                    <label>{{ $criterio }} (1-10)</label>
                    <input type="number" name="criterios[{{ $index }}][puntaje]" class="form-control" min="1" max="10" value="5">
                    <input type="hidden" name="criterios[{{ $index }}][nombre]" value="{{ $criterio }}">
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('evaluaciones.desempeno.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Crear Evaluación</button>
    </div>
</form>
@endsection