@extends('layouts.app')

@section('title', 'Registrar asistencia - Proserge')

@section('content')
<div class="attendance-ops-page">
    @if($grupo)
        <header class="ops-page-header">
            <div><p class="ops-eyebrow">Marcación individual</p><h1>Registrar asistencia</h1><p>{{ $grupo['servicio'] }} · {{ \Carbon\Carbon::parse($grupo['fecha'])->format('d/m/Y') }} · {{ $grupo['mina_nombre'] ?: 'Sin destino' }}</p></div>
            <div class="ops-page-actions"><a href="{{ route('asistencia.show', $grupo['grupo_id']) }}" class="ops-btn secondary"><svg viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Volver</a></div>
        </header>

        <form action="{{ route('asistencia.marcar-post', $grupo['grupo_id']) }}" method="POST" class="ops-panel attendance-form-panel">
            @csrf
            <div class="ops-panel-head"><div><h2>Datos de la marcación</h2><p>Selecciona al trabajador y el estado que corresponde.</p></div></div>
            <div class="attendance-form-grid">
                <div class="ops-field wide">
                    <label for="group-detail">Trabajador</label>
                    <select name="grupo_trabajo_detalle_id" id="group-detail" required>
                        <option value="">Seleccionar trabajador</option>
                        @foreach($grupo['integrantes'] as $person)
                            <option value="{{ $person['grupo_trabajo_detalle_id'] }}" @selected(old('grupo_trabajo_detalle_id') === $person['grupo_trabajo_detalle_id'])>{{ $person['nombre_completo'] }} · {{ $person['dni'] ?: 'Sin documento' }} · {{ $person['puesto'] ?: 'Sin puesto' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ops-field">
                    <label for="attendance-state-input">Estado</label>
                    <select name="estado" id="attendance-state-input" required>
                        @foreach(['PRESENTE' => 'Presente', 'TARDANZA' => 'Tardanza', 'AUSENTE' => 'Ausente', 'JUSTIFICADO' => 'Justificado', 'NO_CORRESPONDE' => 'No corresponde'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('estado', 'PRESENTE') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ops-field">
                    <label for="marked-time">Hora</label>
                    <input type="time" name="hora_marcado" id="marked-time" value="{{ old('hora_marcado', now()->format('H:i')) }}">
                </div>
                <div class="ops-field wide attendance-reason-field">
                    <label for="attendance-reason">Motivo</label>
                    <input type="text" name="motivo_estado" id="attendance-reason" value="{{ old('motivo_estado') }}" maxlength="500" placeholder="Obligatorio para justificado o no corresponde">
                </div>
                <div class="ops-field wide">
                    <label for="attendance-observation">Observación</label>
                    <textarea name="observaciones" id="attendance-observation" rows="3" placeholder="Detalle adicional opcional">{{ old('observaciones') }}</textarea>
                </div>
            </div>
            <div class="attendance-form-actions">
                <a href="{{ route('asistencia.masivo', $grupo['grupo_id']) }}" class="ops-btn secondary">Marcación masiva</a>
                <button type="submit" class="ops-btn primary">Guardar marcación</button>
            </div>
        </form>
    @else
        <section class="ops-panel"><div class="ops-empty">El grupo no existe o no se encuentra dentro de tu alcance.</div></section>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const state = document.getElementById('attendance-state-input');
    const reason = document.getElementById('attendance-reason');
    if (!state || !reason) return;
    const sync = () => reason.required = ['JUSTIFICADO', 'NO_CORRESPONDE'].includes(state.value);
    state.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
