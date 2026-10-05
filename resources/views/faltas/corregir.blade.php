@extends('layouts.app')

@section('title', 'Corregir falta - Proserge')

@section('content')
<div class="attendance-ops-page absence-ops-page">
    @if($item)
        <header class="ops-page-header">
            <div><p class="ops-eyebrow">Regularización de asistencia</p><h1>Corregir falta</h1><p>{{ $item->trabajador_nombre ?: 'Trabajador sin nombre' }} · {{ \Carbon\Carbon::parse($item->fecha)->format('d/m/Y') }}</p></div>
            <div class="ops-page-actions"><a href="{{ route('faltas.show', $item->id) }}" class="ops-btn secondary"><svg viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Volver</a></div>
        </header>

        <form action="{{ route('faltas.corregir-post', $item->id) }}" method="POST" class="ops-panel attendance-form-panel">
            @csrf
            <div class="ops-panel-head"><div><h2>Confirmar asistencia real</h2><p>La falta quedará como corregida y la asistencia origen pasará a presente.</p></div></div>
            <div class="absence-correction-context">
                <div><span>Trabajador</span><strong>{{ $item->trabajador_nombre ?: 'Sin nombre' }}</strong><small>{{ $item->trabajador_documento ?: 'Sin documento' }}</small></div>
                <div><span>Destino</span><strong>{{ $item->destino_nombre ?: 'Sin destino identificado' }}</strong><small>{{ ucfirst(strtolower((string) ($item->destino_tipo ?: 'Sin tipo'))) }}</small></div>
                <div><span>Motivo original</span><strong>{{ ucfirst(strtolower((string) ($item->motivo ?: 'Ausencia'))) }}</strong><small>{{ $item->descripcion ?: 'Sin detalle' }}</small></div>
            </div>
            <div class="attendance-form-grid">
                <div class="ops-field"><label for="correction-time">Hora real de ingreso</label><input type="time" name="hora_marcado" id="correction-time" value="{{ old('hora_marcado') }}"></div>
                <div class="ops-field wide"><label for="correction-reason">Motivo de la corrección</label><textarea name="motivo_correccion" id="correction-reason" rows="4" minlength="5" maxlength="1000" required placeholder="Ejemplo: el trabajador marcó tarde y no fue registrado en el cierre">{{ old('motivo_correccion') }}</textarea></div>
            </div>
            <div class="attendance-form-actions"><a href="{{ route('faltas.show', $item->id) }}" class="ops-btn secondary">Cancelar</a><button type="submit" class="ops-btn primary">Aplicar corrección</button></div>
        </form>
    @else
        <section class="ops-panel"><div class="ops-empty">La falta no existe o no se encuentra dentro de tu alcance.</div></section>
    @endif
</div>
@endsection
