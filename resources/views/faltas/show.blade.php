@extends('layouts.app')

@section('title', 'Detalle de falta - Proserge')

@php
    $state = $item ? strtoupper((string) ($item->estado ?: 'ACTIVA')) : null;
    $stateLabels = ['ACTIVA' => 'Pendiente de revisar', 'CORREGIDA' => 'Corregida', 'ANULADA' => 'Anulada'];
    $stateClasses = ['ACTIVA' => 'danger', 'CORREGIDA' => 'success', 'ANULADA' => 'neutral'];
@endphp

@section('content')
<div class="attendance-ops-page absence-ops-page">
    @if($item)
        <header class="ops-page-header">
            <div><p class="ops-eyebrow">{{ $item->destino_nombre ?: 'Falta registrada' }}</p><h1>{{ $item->trabajador_nombre ?: 'Trabajador sin nombre' }}</h1><p>{{ $item->trabajador_documento ?: 'Sin documento' }} · {{ \Carbon\Carbon::parse($item->fecha)->format('d/m/Y') }}</p></div>
            <div class="ops-page-actions"><a href="{{ route('faltas.index') }}" class="ops-btn secondary"><svg viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Volver</a>@if($state === 'ACTIVA')@allowed('faltas', 'corregir')<a href="{{ route('faltas.corregir', $item->id) }}" class="ops-btn primary">Corregir asistencia</a>@endallowed@endif</div>
        </header>

        <div class="ops-analytics-grid">
            <section class="ops-panel absence-detail-panel">
                <div class="ops-panel-head"><div><h2>Información de la falta</h2><p>Origen y contexto operativo.</p></div><span class="ops-status {{ $stateClasses[$state] ?? 'neutral' }}">{{ $stateLabels[$state] ?? $state }}</span></div>
                <dl class="absence-detail-grid">
                    <div><dt>Fecha</dt><dd>{{ \Carbon\Carbon::parse($item->fecha)->format('d/m/Y') }}</dd></div>
                    <div><dt>Motivo</dt><dd>{{ ucfirst(strtolower((string) ($item->motivo ?: 'Ausencia'))) }}</dd></div>
                    <div><dt>Destino</dt><dd>{{ $item->destino_nombre ?: 'Sin destino identificado' }}</dd></div>
                    <div><dt>Origen</dt><dd>{{ $item->asistencia_detalle_id ? 'Cierre de asistencia' : 'Registro manual' }}</dd></div>
                    <div class="wide"><dt>Detalle</dt><dd>{{ $item->descripcion ?: $item->observaciones ?: 'Sin detalle adicional.' }}</dd></div>
                </dl>
            </section>

            <section class="ops-panel absence-detail-panel">
                <div class="ops-panel-head"><div><h2>Historial de regularización</h2><p>Acciones aplicadas sobre este registro.</p></div></div>
                <dl class="absence-detail-grid single">
                    <div><dt>Corrección</dt><dd>{{ $item->motivo_correccion ?: 'Sin corrección registrada' }}</dd></div>
                    <div><dt>Fecha de corrección</dt><dd>{{ $item->corregido_at ? \Carbon\Carbon::parse($item->corregido_at)->format('d/m/Y H:i') : '-' }}</dd></div>
                    <div><dt>Anulación</dt><dd>{{ $item->motivo_anulacion ?: 'Sin anulación registrada' }}</dd></div>
                </dl>
            </section>
        </div>

        @if($state === 'ACTIVA')
            @allowed('faltas', 'anular')
                <form action="{{ route('faltas.anular', $item->id) }}" method="POST" class="ops-panel absence-action-panel" onsubmit="return confirm('¿Confirmas que deseas anular esta falta?')">
                    @csrf
                    <div><h2>Anular registro</h2><p>Úsalo cuando la falta no corresponda. El registro permanecerá en el historial.</p></div>
                    <div class="ops-field"><label for="absence-cancel-reason">Motivo de anulación</label><input id="absence-cancel-reason" name="motivo_anulacion" type="text" minlength="5" maxlength="1000" required placeholder="Explica por qué no corresponde"></div>
                    <button class="ops-btn secondary" type="submit">Anular falta</button>
                </form>
            @endallowed
        @endif
    @else
        <section class="ops-panel"><div class="ops-empty">La falta no existe o no se encuentra dentro de tu alcance.</div></section>
    @endif
</div>
@endsection
