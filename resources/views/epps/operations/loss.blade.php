@extends('layouts.app')
@section('title', 'Registrar perdida EPP - Proserge')
@section('content')
<div class="epp-operation"><section class="epp-operation-card">
<h1>Registrar perdida de EPP</h1>
<p>{{ $delivery->personal->nombre_completo }} — {{ $delivery->epp->nombre }}. Saldo retornable: {{ $delivery->cantidad_pendiente_devolucion }}</p>
<p>Reduce la responsabilidad con auditoria. No registra devolucion, firma ni ingreso fisico de stock.</p>
@include('epps.operations.messages')
<form method="POST" action="{{ route('epps.deliveries.loss', $delivery) }}">@csrf
<input type="hidden" name="submission_uuid" value="{{ old('submission_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
<label>Cantidad perdida <input type="number" name="cantidad" min="1" max="{{ $delivery->cantidad_pendiente_devolucion }}" value="{{ old('cantidad', 1) }}" required></label>
<label>Motivo <textarea name="motivo" maxlength="1000" required>{{ old('motivo') }}</textarea></label>
<button type="submit" class="epp-operation-button">Registrar perdida auditada</button>
</form></section></div>
@endsection
