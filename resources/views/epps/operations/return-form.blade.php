@extends('layouts.app')
@section('title', 'Devolucion multiple EPP - Proserge')
@section('content')
<div class="epp-operation" data-epp-operation>
    <header class="epp-operation-card"><p>Paso 1 de 2 · Saldos del trabajador</p><h1>Devolucion multiple</h1><p>Selecciona las unidades que entrega el trabajador y su condicion. Revisa el saldo restante antes de firmar.</p></header>
    @include('epps.operations.messages')
    <form method="GET" action="{{ route('epps.operations.returns.create') }}" class="epp-operation-card" data-operation-form>
        <label>Buscar trabajador por nombre o documento<input type="search" value="{{ $worker?->nombre_completo }}" data-worker-search data-search-url="{{ route('epps.operations.workers') }}" data-return-search autocomplete="off"></label>
        <input type="hidden" name="personal_id" value="{{ $worker?->id }}" data-worker-id>
        <div data-worker-results class="epp-worker-results" aria-live="polite"></div>
        <p data-worker-selected>{{ $worker ? $worker->nombre_completo.' · '.$worker->dni : 'Selecciona un trabajador de los resultados.' }}</p>
        <label>Almacen<select name="almacen_id" required>@foreach($warehouses as $item)<option value="{{ $item->id }}" @selected($warehouse?->id === $item->id)>{{ $item->nombre }}</option>@endforeach</select></label>
        <button class="epp-operation-button" type="submit">Ver saldos por devolver</button>
    </form>
    @if($worker)
    <form method="POST" action="{{ route('epps.operations.returns.store') }}" data-return-form>
        @csrf
        <input type="hidden" name="tipo" value="DEVOLUCION">
        <input type="hidden" name="personal_id" value="{{ $worker->id }}">
        <input type="hidden" name="almacen_id" value="{{ $warehouse?->id }}">
        <input type="hidden" name="submission_uuid" value="{{ $submissionUuid }}">
        @forelse($balances as $delivery)
        @php($index = $loop->index)
        @php($attributes = $delivery->atributos_json ?: array_filter(['TALLA' => $delivery->talla, 'COLOR' => $delivery->color]))
        <article class="epp-operation-card" data-return-line data-balance="{{ $delivery->cantidad_pendiente_devolucion }}">
            <h2>{{ $delivery->operacionDetalle?->snapshot_json['real']['nombre'] ?? $delivery->epp->nombre }}</h2><p>Entregado el {{ $delivery->fecha_entrega->format('d/m/Y') }}</p>
            @if($delivery->es_legacy)<p>Entrega historica sin firma original. La devolucion se recibira en {{ $warehouse?->nombre }}.</p>@endif
            <p>@foreach($attributes as $name => $value){{ $name }}: {{ $value }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>
            <p>Saldo por devolver: {{ $delivery->cantidad_pendiente_devolucion }}</p>
            <label class="epp-operation-check"><input type="checkbox" data-return-select><span>Incluir en esta devolucion</span></label>
            <input type="hidden" name="lines[{{ $index }}][epp_id]" value="{{ $delivery->epp_id }}" data-return-field disabled>
            <input type="hidden" name="lines[{{ $index }}][entrega_origen_id]" value="{{ $delivery->id }}" data-return-field disabled>
            @foreach($attributes as $name => $value)<input type="hidden" name="lines[{{ $index }}][atributos][{{ $name }}]" value="{{ $value }}" data-return-field disabled>@endforeach
            <label>Unidades devueltas ahora<input type="number" name="lines[{{ $index }}][cantidad_solicitada]" value="1" min="1" max="{{ min(1000000, $delivery->cantidad_pendiente_devolucion) }}" required data-return-quantity data-return-field disabled></label>
            <label>Condicion<select name="lines[{{ $index }}][condicion_devolucion]" data-return-condition data-return-field disabled><option value="BUENO">Bueno</option><option value="DESGASTADO">Desgastado</option><option value="DANADO">Danado</option><option value="INCOMPLETO">Incompleto</option><option value="OTRO">Otro (describir en observacion)</option></select></label>
            <label>Destino<select name="lines[{{ $index }}][destino_inventario]" data-return-destination data-return-field disabled><option value="DISPONIBLE">Disponible para entregar</option><option value="CUARENTENA">Cuarentena para revision</option><option value="BAJA">Baja</option></select></label>
            <label>Observacion<textarea name="lines[{{ $index }}][motivo]" maxlength="1000" data-return-field disabled></textarea></label>
            <p data-return-remaining aria-live="polite">Saldo restante: {{ $delivery->cantidad_pendiente_devolucion }}</p>
        </article>
        @empty<section class="epp-operation-card"><p>No hay saldos retornables positivos en este almacen. Los consumibles no requieren devolucion.</p></section>@endforelse
        @if($balances->isNotEmpty())<button type="submit" class="epp-operation-button is-primary">Preparar devolucion y revisar firma</button><p data-return-error hidden role="alert">Selecciona al menos un articulo por devolver.</p>@endif
    </form>
    @endif
</div>
@endsection
