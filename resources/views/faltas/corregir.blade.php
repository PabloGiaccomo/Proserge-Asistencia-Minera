@extends('layouts.app')

@section('title', 'Faltas - Corregir')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Corregir Falta</h1>
            <p class="page-subtitle">Falta #{{ $item['id'] ?? '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('faltas.show', $item['id']) }}" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver
            </a>
        </div>
    </div>
</div>

@if($item)
<form action="{{ route('faltas.corregir-post', $item['id']) }}" method="POST" class="form">
    @csrf
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Datos de la Corrección</h3>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Trabajador</span>
                    <span class="detail-value">{{ $item['trabajador']['nombre'] ?? $item['trabajador_nombre'] ?? '-' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha Original</span>
                    <span class="detail-value">{{ $item['fecha'] ?? '-' }}</span>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 16px;">
                <label for="asistencia_tipo">Tipo de Corrección</label>
                <select name="asistencia_tipo" id="asistencia_tipo" class="form-control" required>
                    <option value="entrada">Marcar Entrada</option>
                    <option value="salida">Marcar Salida</option>
                    <option value="completa">Marcar Completa</option>
                </select>
            </div>
            <div class="form-group">
                <label for="hora">Hora de Asistencia</label>
                <input type="time" name="hora" id="hora" class="form-control" value="08:00">
            </div>
            <div class="form-group">
                <label for="justificacion">Justificación</label>
                <textarea name="justificacion" id="justificacion" class="form-control" rows="3" placeholder="Explicar la corrección..."></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('faltas.show', $item['id']) }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Aplicar Corrección</button>
    </div>
</form>
@endif
@endsection