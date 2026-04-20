@extends('layouts.app')

@section('title', 'Asistencia - Marcación Masiva')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Marcación Masiva</h1>
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
<form action="{{ route('asistencia.marcar-masivo-post', $grupo['id']) }}" method="POST" class="form">
    @csrf
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Seleccionar Personal</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label>Tipo de Marcación</label>
                <select name="tipo" id="tipo" class="form-control" required>
                    <option value="entrada">Entrada</option>
                    <option value="salida">Salida</option>
                </select>
            </div>
            <div class="form-group">
                <label for="hora">Hora</label>
                <input type="time" name="hora" id="hora" class="form-control" value="{{ date('H:i') }}">
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="selectAll"> Seleccionar Todos
                </label>
            </div>
            
            <div class="personal-list">
                @foreach($grupo['personal'] as $persona)
                <div class="personal-item">
                    <label>
                        <input type="checkbox" name="trabajadores[]" value="{{ $persona['id'] }}">
                        {{ $persona['nombre'] ?? 'Sin nombre' }}
                    </label>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="{{ route('asistencia.show', $grupo['id']) }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Marcar Seleccionados</button>
    </div>
</form>

@push('scripts')
<script>
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('input[name="trabajadores[]"]');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
</script>
@endpush
@endif
@endsection