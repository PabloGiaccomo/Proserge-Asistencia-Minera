@extends('layouts.app')
@section('title', 'Registrar devolucion EPP - Proserge')
@section('content')
<div class="epp-operation" data-epp-operation data-catalog-return>
    <header class="epp-operation-card"><p>Paso 1 de 2</p><h1>Registrar devolucion</h1><p>Selecciona al trabajador y marca los articulos que devuelve. Una sola firma confirmara las cantidades seleccionadas.</p><a href="{{ url('/logistica?tab=entregas') }}">Volver a Entregas y cambios</a></header>
    @include('epps.operations.messages')
    <form method="GET" action="{{ route('epps.catalog-returns.create') }}" class="epp-operation-card" data-operation-form>
        <label>Buscar trabajador por nombre o DNI<input type="search" value="{{ $worker?->nombre_completo }}" data-worker-search data-search-url="{{ route('epps.personal.buscar') }}" data-return-search autocomplete="off" required></label>
        <input type="hidden" name="personal_id" value="{{ $worker?->id }}" data-worker-id>
        <div data-worker-results class="epp-worker-results" aria-live="polite"></div>
        <p data-worker-selected>{{ $worker ? $worker->nombre_completo.' · '.$worker->dni : 'Selecciona un trabajador de los resultados.' }}</p>
        <button class="epp-operation-button" type="submit">Ver saldos por devolver</button>
    </form>
    @if($worker)
    <form method="POST" action="{{ route('epps.catalog-returns.store') }}" data-return-form>
        @csrf
        <input type="hidden" name="personal_id" value="{{ $worker->id }}">
        <input type="hidden" name="submission_uuid" value="{{ old('submission_uuid', $submissionUuid) }}">
        @forelse($balances as $delivery)
        @php($index = $loop->index)
        @php($previous = collect(old('items', []))->firstWhere('entrega_id', $delivery->id))
        <article class="epp-operation-card" data-return-line data-balance="{{ $delivery->cantidad_pendiente_devolucion }}">
            <h2>{{ $delivery->operacionDetalle?->snapshot_json['real']['nombre'] ?? $delivery->epp->nombre }}</h2>
            <p>Entregado el {{ $delivery->fecha_entrega->format('d/m/Y') }} · Talla: {{ $delivery->talla ?: 'No aplica' }} · Color: {{ $delivery->color ?: 'No aplica' }}</p>
            @foreach(($delivery->atributos_json ?? []) as $attribute)<p>{{ $attribute['nombre'] }}: {{ $attribute['valor'] }}</p>@endforeach
            <p>Saldo por devolver: {{ $delivery->cantidad_pendiente_devolucion }} unidades.</p>
            <label class="epp-operation-check"><input type="checkbox" data-return-select @checked($previous)><span>Incluir en esta devolucion</span></label>
            <input type="hidden" name="items[{{ $index }}][entrega_id]" value="{{ $delivery->id }}" data-return-field disabled>
            <label>Cantidad que devuelve ahora<input type="number" name="items[{{ $index }}][cantidad]" value="{{ $previous['cantidad'] ?? $delivery->cantidad_pendiente_devolucion }}" min="1" max="{{ min(1000000, $delivery->cantidad_pendiente_devolucion) }}" required data-return-quantity data-return-field disabled></label>
            <p data-return-remaining aria-live="polite">Saldo restante: {{ $delivery->cantidad_pendiente_devolucion }} unidades.</p>
        </article>
        @empty<section class="epp-operation-card"><p>Este trabajador no tiene EPP retornables pendientes en Entregas y cambios. Los consumibles y los articulos ya devueltos no se incluyen.</p></section>@endforelse
        @if($balances->isNotEmpty())
        <section class="epp-operation-card">
            <label>Fecha de devolucion<input type="date" name="devuelto_at" value="{{ old('devuelto_at', now()->toDateString()) }}" required></label>
            <label>Observacion (opcional)<textarea name="observacion" maxlength="1000">{{ old('observacion') }}</textarea></label>
            <button type="submit" class="epp-operation-button is-primary">Revisar devolucion y firmar</button>
            <p data-return-error hidden role="alert">Selecciona al menos un articulo por devolver.</p>
        </section>
        @endif
    </form>
    @endif
</div>
@endsection
