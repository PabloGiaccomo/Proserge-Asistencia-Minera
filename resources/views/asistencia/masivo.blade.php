@extends('layouts.app')

@section('title', 'Marcación masiva - Proserge')

@section('content')
<div class="attendance-ops-page">
    @if($grupo)
        <header class="ops-page-header">
            <div><p class="ops-eyebrow">Acción de grupo</p><h1>Marcación masiva</h1><p>{{ $grupo['servicio'] }} · {{ \Carbon\Carbon::parse($grupo['fecha'])->format('d/m/Y') }} · {{ count($grupo['integrantes']) }} integrantes</p></div>
            <div class="ops-page-actions"><a href="{{ route('asistencia.show', $grupo['grupo_id']) }}" class="ops-btn secondary"><svg viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Volver</a></div>
        </header>

        <form action="{{ route('asistencia.marcar-masivo-post', $grupo['grupo_id']) }}" method="POST" class="ops-panel attendance-form-panel">
            @csrf
            <div class="ops-panel-head"><div><h2>Estado común</h2><p>El mismo estado se aplicará únicamente a las personas seleccionadas. La hora de marcación se registra automáticamente con la hora del servidor.</p></div></div>
            <div class="attendance-form-grid compact-grid">
                <div class="ops-field"><label for="bulk-state">Estado</label><select name="estado" id="bulk-state" required>@foreach(['PRESENTE' => 'Presente', 'TARDANZA' => 'Tardanza', 'AUSENTE' => 'Ausente', 'JUSTIFICADO' => 'Justificado', 'NO_CORRESPONDE' => 'No corresponde'] as $value => $label)<option value="{{ $value }}" @selected(old('estado', 'PRESENTE') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="ops-field wide attendance-reason-field"><label for="bulk-reason">Motivo</label><input type="text" name="motivo_estado" id="bulk-reason" value="{{ old('motivo_estado') }}" placeholder="Obligatorio para justificado o no corresponde"></div>
            </div>

            <div class="attendance-select-head">
                <div><strong>Seleccionar personal</strong><span id="selected-count">0 seleccionados</span></div>
                <label class="attendance-check-all"><input type="checkbox" id="select-all-attendance"> Seleccionar todos</label>
            </div>
            <div class="attendance-person-grid">
                @foreach($grupo['integrantes'] as $person)
                    <label class="attendance-person-option">
                        <input type="checkbox" name="grupo_trabajo_detalle_ids[]" value="{{ $person['grupo_trabajo_detalle_id'] }}" @checked(in_array($person['grupo_trabajo_detalle_id'], old('grupo_trabajo_detalle_ids', []), true))>
                        <span><strong>{{ $person['nombre_completo'] }}</strong><small>{{ $person['dni'] ?: 'Sin documento' }} · {{ $person['puesto'] ?: 'Sin puesto' }}</small></span>
                        <span class="ops-status neutral">{{ ucfirst(strtolower(str_replace('_', ' ', $person['estado_asistencia']))) }}</span>
                    </label>
                @endforeach
            </div>
            <div class="attendance-form-actions"><a href="{{ route('asistencia.show', $grupo['grupo_id']) }}" class="ops-btn secondary">Cancelar</a><button type="submit" class="ops-btn primary">Guardar seleccionados</button></div>
        </form>
    @else
        <section class="ops-panel"><div class="ops-empty">El grupo no existe o no se encuentra dentro de tu alcance.</div></section>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const all = document.getElementById('select-all-attendance');
    const boxes = [...document.querySelectorAll('input[name="grupo_trabajo_detalle_ids[]"]')];
    const count = document.getElementById('selected-count');
    const state = document.getElementById('bulk-state');
    const reason = document.getElementById('bulk-reason');
    const syncCount = () => {
        const selected = boxes.filter(box => box.checked).length;
        if (count) count.textContent = `${selected} seleccionados`;
        if (all) all.checked = boxes.length > 0 && selected === boxes.length;
    };
    all?.addEventListener('change', () => { boxes.forEach(box => box.checked = all.checked); syncCount(); });
    boxes.forEach(box => box.addEventListener('change', syncCount));
    const syncReason = () => { if (reason && state) reason.required = ['JUSTIFICADO', 'NO_CORRESPONDE'].includes(state.value); };
    state?.addEventListener('change', syncReason);
    syncCount();
    syncReason();
})();
</script>
@endpush
