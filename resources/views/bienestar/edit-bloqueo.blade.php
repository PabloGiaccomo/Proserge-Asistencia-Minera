@extends('layouts.app')

@section('title', 'Editar Bloqueo - Bienestar')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Editar bloqueo</h1>
                <p class="page-subtitle">{{ $trabajador->nombre_completo ?? 'Trabajador' }} - DNI {{ $trabajador->dni ?? '-' }}</p>
            </div>
            <a href="{{ route('bienestar.index') }}" class="btn btn-outline btn-sm">Volver</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">Datos del bloqueo</span></div>
        <div class="card-body">
            <form method="POST" action="{{ route('bienestar.bloqueos.update', $bloqueo->id) }}" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; align-items:end;">
                @csrf
                @method('PUT')

                @php
                    $known = ['vacaciones', 'descanso_medico', 'inhabilitado', 'restriccion_temporal'];
                    $tipoActual = old('tipo', $bloqueo->tipo);
                    $isOther = !in_array($tipoActual, $known, true);
                @endphp

                <div>
                    <label class="filter-label">Tipo</label>
                    <select name="tipo" id="tipoBloqueo" class="form-control" required>
                        <option value="vacaciones" {{ $tipoActual === 'vacaciones' ? 'selected' : '' }}>Vacaciones</option>
                        <option value="descanso_medico" {{ $tipoActual === 'descanso_medico' ? 'selected' : '' }}>Descanso médico</option>
                        <option value="inhabilitado" {{ $tipoActual === 'inhabilitado' ? 'selected' : '' }}>Inhabilitado</option>
                        <option value="restriccion_temporal" {{ $tipoActual === 'restriccion_temporal' ? 'selected' : '' }}>Restricción temporal</option>
                        <option value="otro" {{ $isOther ? 'selected' : '' }}>Otro</option>
                    </select>
                </div>
                <div id="otroTipoWrapper" style="display:{{ $isOther ? 'block' : 'none' }};">
                    <label class="filter-label">Otro tipo</label>
                    <input type="text" name="otro_tipo" class="form-control" value="{{ old('otro_tipo', $isOther ? $tipoActual : '') }}" placeholder="Ej: licencia especial">
                </div>
                <div>
                    <label class="filter-label">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="{{ old('fecha_inicio', optional($bloqueo->fecha_inicio)->toDateString()) }}" required>
                </div>
                <div>
                    <label class="filter-label">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="{{ old('fecha_fin', optional($bloqueo->fecha_fin)->toDateString()) }}" required>
                </div>
                <div style="grid-column:1 / -1;">
                    <label class="filter-label">Motivo</label>
                    <input type="text" name="motivo" class="form-control" value="{{ old('motivo', $bloqueo->motivo) }}" required>
                </div>
                <div style="grid-column:1 / -1;">
                    <label class="filter-label">Detalle</label>
                    <textarea name="detalle" class="form-control" rows="3">{{ old('detalle', $bloqueo->detalle) }}</textarea>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
                    <a href="{{ route('bienestar.index') }}" class="btn btn-outline btn-sm">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tipo = document.getElementById('tipoBloqueo');
    const wrapper = document.getElementById('otroTipoWrapper');
    if (!tipo || !wrapper) {
        return;
    }

    const toggle = function () {
        wrapper.style.display = tipo.value === 'otro' ? 'block' : 'none';
    };

    tipo.addEventListener('change', toggle);
    toggle();
});
</script>
@endpush
