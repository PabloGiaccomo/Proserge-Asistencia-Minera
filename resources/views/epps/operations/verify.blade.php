@extends('layouts.app')
@section('title', 'Verificacion EPP - Proserge')
@section('content')
<div class="epp-operation"><section class="epp-operation-card">
<h1>Verificacion de constancia EPP</h1>
<p role="status">{{ $verification['valid'] ? 'FIRMADO_VALIDO' : 'EVIDENCIA_INVALIDA' }}</p>
<p>Codigo: {{ $acceptance->codigo_verificacion }}</p>
<p>Operacion: {{ $operation->id }} — {{ $operation->tipo }}</p>
<p>Trabajador: {{ data_get($acceptance->contenido_json, 'worker.name') }}</p>
<p>Firmante: {{ $acceptance->firmante_nombre }} — {{ $acceptance->tipo_firmante }}</p>
<p>Fecha: {{ $acceptance->firmado_at }} {{ $acceptance->zona_horaria }}</p>
@unless($verification['valid'])<p role="alert">La evidencia requiere revision. No repitas la operacion para corregir esta alerta.</p>@endunless
<div style="overflow-x:auto"><div style="min-width:640px">@include('epps.operations.evidence-lines', ['payload' => $acceptance->contenido_json])</div></div>
@foreach($operation->operacionesCompensatorias as $correction)<p>{{ $correction->tipo }}: {{ $correction->id }} — {{ $correction->motivo }}</p>@endforeach
@allowed('epps', 'descargar_constancia')<a class="epp-operation-button" href="{{ route('epps.evidence.receipt', $operation) }}">Descargar constancia PDF</a>@endallowed
</section></div>
@endsection
