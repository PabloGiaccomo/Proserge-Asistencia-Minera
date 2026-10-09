@extends('layouts.app')
@section('title', 'Compensar legado EPP - Proserge')
@section('content')
<div class="epp-operation"><section class="epp-operation-card">
<h1>Corregir o anular saldo legacy</h1>
<p>{{ $delivery->personal->nombre_completo }} — {{ $delivery->epp->nombre }}. Saldo: {{ $delivery->cantidad_pendiente_devolucion }}</p>
<p>Esta accion reduce responsabilidad y conserva el registro original sin firma. El almacen identifica al area que revisa; no se agrega stock porque el origen legacy no esta conciliado. Para recibir articulos fisicos usa Devolucion multiple.</p>
@include('epps.operations.messages')
<form method="POST" action="{{ route('epps.legacy.compensate', $delivery) }}">@csrf
<input type="hidden" name="submission_uuid" value="{{ old('submission_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
<label>Almacen responsable <select name="almacen_id" required>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->nombre }}</option>@endforeach</select></label>
<label>Accion <select name="tipo">@allowed('epps', 'corregir')<option value="CORRECCION">Corregir cantidad</option>@endallowed @allowed('epps', 'anular')<option value="ANULACION">Anular todo el saldo</option>@endallowed</select></label>
<label>Cantidad <input type="number" name="cantidad" min="1" max="{{ $delivery->cantidad_pendiente_devolucion }}" value="{{ $delivery->cantidad_pendiente_devolucion }}" required></label>
<label>Motivo <textarea name="motivo" maxlength="1000" required>{{ old('motivo') }}</textarea></label>
<button class="epp-operation-button" type="submit">Registrar compensacion auditada</button>
</form></section></div>
@endsection
