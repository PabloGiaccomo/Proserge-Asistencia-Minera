@extends('layouts.app')

@section('title', 'Asistencia - Marcar')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Marcar Asistencia</h1>
            <p class="page-subtitle">{{ $grupo['nombre'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('asistencia.show', $grupo['id']) }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
        </div>
    </div>
</div>

@if($grupo)
<form action="{{ route('asistencia.marcar-post', $grupo['id']) }}" method="POST" class="form">
    @csrf
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Marcar Asistencia Individual</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="trabajador_id">Trabajador</label>
                <select name="trabajador_id" id="trabajador_id" class="form-control" required>
                    <option value="">Seleccionar trabajador</option>
                    @foreach($grupo['personal'] as $persona)
                    <option value="{{ $persona['id'] }}">{{ $persona['nombre'] ?? 'Sin nombre' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="tipo">Tipo de Marcación</label>
                <select name="tipo" id="tipo" class="form-control" required>
                    <option value="entrada">Entrada</option>
                    <option value="salida">Salida</option>
                </select>
            </div>
            <div class="form-group">
                <label for="hora">Hora</label>
                <input type="time" name="hora" id="hora" class="form-control" value="{{ date('H:i') }}">
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('asistencia.show', $grupo['id']) }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Marcar</button>
    </div>
</form>

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3 class="card-title">Acciones Rápidas</h3>
    </div>
    <div class="card-body">
        <a href="{{ route('asistencia.masivo', $grupo['id']) }}" class="btn btn-outline">
            Marcación Masiva
        </a>
    </div>
</div>
@endif
@endsection