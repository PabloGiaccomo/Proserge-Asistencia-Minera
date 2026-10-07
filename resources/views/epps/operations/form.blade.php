@extends('layouts.app')
@section('title', 'Entrega multiple EPP - Proserge')
@section('content')
<div class="epp-operation" data-epp-operation>
    <header class="epp-operation-card"><p>Paso 1 de 2 · Preparar entrega</p><h1>Entrega multiple de EPP</h1><p>Elige al trabajador, el almacen y los articulos. Luego muestra el resumen al trabajador para que revise y firme.</p><a href="{{ route('logistica.index', ['tab' => 'entregas']) }}">Volver a Logistica</a></header>
    @include('epps.operations.messages')
    @if($warehouses->isEmpty())
        <p class="epp-operation-card" role="status">No tienes un almacen activo disponible. Solicita a Logistica la configuracion del almacen y tu alcance de mina.</p>
    @else
        <form method="GET" action="{{ $operation ? route('epps.operations.edit', $operation) : route('epps.operations.create') }}" class="epp-operation-card">
            <label>Almacen<select name="almacen_id" @disabled($operation)>
                @foreach($warehouses as $item)<option value="{{ $item->id }}" @selected($warehouse?->id === $item->id)>{{ $item->nombre }} · {{ $item->mina?->nombre ?? 'General' }}</option>@endforeach
            </select></label>
            @if($worker)<input type="hidden" name="personal_id" value="{{ $worker->id }}">@endif
            @unless($operation)<button class="epp-operation-button" type="submit">Usar este almacen</button>@endunless
        </form>
        <form method="POST" action="{{ $operation ? route('epps.operations.update', $operation) : route('epps.operations.store') }}" data-operation-form>
            @csrf
            @if($operation)@method('PUT')@endif
            <input type="hidden" name="tipo" value="ENTREGA">
            <input type="hidden" name="submission_uuid" value="{{ $submissionUuid }}">
            <input type="hidden" name="almacen_id" value="{{ $warehouse?->id }}">
            <section class="epp-operation-card">
                <h2>Trabajador que recibe</h2>
                <label>Buscar por nombre o documento<input type="search" data-worker-search data-search-url="{{ route('epps.operations.workers', [], false) }}" value="{{ $worker?->nombre_completo }}" autocomplete="off" @disabled($operation)></label>
                <div data-worker-results class="epp-worker-results" aria-live="polite"></div>
                <input type="hidden" name="personal_id" value="{{ $worker?->id }}" data-worker-id>
                <p data-worker-selected>{{ $worker ? $worker->nombre_completo.' · Documento: '.$worker->dni : 'Busca y selecciona al trabajador para continuar.' }}</p>
            </section>
            <div data-operation-lines>
                @foreach($lines as $index => $line)
                    @include('epps.operations.line', ['index' => $index, 'line' => $line])
                @endforeach
            </div>
            <template data-operation-template>@include('epps.operations.line', ['index' => '__INDEX__', 'line' => ['cantidad_solicitada' => 1]])</template>
            <div class="epp-operation-actions">
                <button type="button" class="epp-operation-button" data-add-line>+ Agregar otro articulo</button>
                <button type="submit" class="epp-operation-button is-primary">Preparar y revisar con el trabajador</button>
            </div>
        </form>
        <script type="application/json" data-operation-catalog>@json($catalog)</script>
        <script type="application/json" data-operation-existences>@json($existences)</script>
    @endif
</div>
@endsection
