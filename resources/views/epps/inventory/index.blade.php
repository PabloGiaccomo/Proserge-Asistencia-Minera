@extends('layouts.app')
@section('title', 'Inventario EPP - Proserge')
@section('content')
<div class="lgt-page">
    <div class="lgt-card">
        <div class="lgt-card-header">
            <div><h1 class="lgt-title">Inventario de EPP por almacén</h1><p>Registra el conteo físico inicial y las diferencias con motivo. El stock antiguo no constituye una apertura.</p></div>
            <a class="epp-btn epp-btn-light" href="{{ route('logistica.index', ['tab' => 'identificacion']) }}">Catálogo de EPP</a>
        </div>
        @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
        @if($errors->any())<div role="alert"><p>Revisa los datos antes de continuar:</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    </div>

    @if($preview)
        <section class="lgt-card" aria-labelledby="inventoryPreviewTitle">
            <div class="lgt-card-header"><div><h2 id="inventoryPreviewTitle">Revisar {{ $preview['action'] === 'almacen' ? 'nuevo almacén' : $preview['action'] }}</h2><p>Almacén: {{ $preview['warehouse_name'] }}. Motivo: {{ $preview['payload']['motivo'] }}</p></div></div>
            @foreach($preview['rows'] as $row)
                <div class="lgt-card-header"><div><h3>{{ $row['nombre'] }}</h3><p>@foreach($row['atributos'] ?: [] as $name => $value){{ $name }}: {{ $value }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p><p>Saldo anterior: {{ $row['antes'] }}. Saldo confirmado: {{ $row['despues'] }} unidades.</p></div></div>
            @endforeach
            @if($preview['action'] === 'almacen')<p>Mina: {{ $mines->firstWhere('id', $preview['payload']['mina_id'] ?? '')?->nombre ?? 'Almacén general' }}</p>@endif
            <p>{{ count($preview['rows']) }} variantes; {{ collect($preview['rows'])->sum('despues') }} unidades después de confirmar.</p>
            <form method="POST" action="{{ route('epps.inventory.'.$preview['action'].'.confirm') }}" class="epp-form-grid">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                <label class="epp-option-toggle epp-field-wide"><input type="checkbox" name="confirmacion" value="1" required><span>He revisado el almacén, las variantes, cantidades y motivo.</span></label>
                <button type="submit" class="epp-btn epp-btn-primary">Confirmar {{ $preview['action'] === 'almacen' ? 'nuevo almacén' : $preview['action'] }}</button>
                <a class="epp-btn epp-btn-light" href="{{ route('epps.inventory.index') }}">Volver a editar</a>
            </form>
        </section>
    @else
        @if($canManage)
            <details class="lgt-card"><summary class="epp-btn epp-btn-light">Crear almacén</summary>
                <form method="POST" action="{{ route('epps.inventory.warehouse.preview') }}" class="epp-form-grid">
                    @csrf
                    <label>Nombre del almacén<input type="text" name="nombre" maxlength="191" required value="{{ old('nombre') }}"></label>
                    <label>Mina<select name="mina_id" @if(!$allowGlobal) required @endif><option value="">{{ $allowGlobal ? 'Almacén general' : 'Seleccionar mina' }}</option>@foreach($mines as $mine)<option value="{{ $mine->id }}">{{ $mine->nombre }}</option>@endforeach</select></label>
                    <label class="epp-field-wide">Motivo<textarea name="motivo" maxlength="1000" required>{{ old('motivo') }}</textarea></label>
                    <button class="epp-btn epp-btn-primary">Revisar nuevo almacén</button>
                </form>
            </details>
            <section class="lgt-card"><div class="lgt-card-header"><div><h2>Apertura de inventario</h2><p>Cada variante se abre una sola vez. Si ya está conciliada, registra un ajuste.</p></div></div>
                <form method="GET" action="{{ route('epps.inventory.index') }}" class="epp-form-grid">
                    <label>EPP a contar<select name="opening_epp_id" required><option value="">Seleccionar EPP</option>@foreach($catalog as $epp)<option value="{{ $epp->id }}" @selected($openingProduct?->id === $epp->id)>{{ $epp->nombre }}</option>@endforeach</select></label>
                    <label>Número de variantes<input type="number" name="opening_rows" min="1" max="20" value="{{ $openingRows }}" required></label>
                    <button class="epp-btn epp-btn-light">Preparar conteo</button>
                </form>
                @if($openingProduct)
                    <form method="POST" action="{{ route('epps.inventory.opening.preview') }}" class="epp-form-grid">
                        @csrf
                        <label class="epp-field-wide">Almacén<select name="almacen_id" required><option value="">Seleccionar almacén</option>@foreach($warehouses->where('estado', 'ACTIVO') as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('almacen_id') === $warehouse->id)>{{ $warehouse->nombre }} · {{ $warehouse->mina?->nombre ?? 'General' }}</option>@endforeach</select></label>
                        @for($index = 0; $index < $openingRows; $index++)
                            <fieldset class="epp-field-wide"><legend>{{ $openingProduct->nombre }} · Variante {{ $index + 1 }}</legend>
                                <input type="hidden" name="lines[{{ $index }}][epp_id]" value="{{ $openingProduct->id }}">
                                <div class="epp-form-grid">
                                    @if($openingProduct->requiere_talla)<label>Talla<select name="lines[{{ $index }}][talla]" required><option value="">Seleccionar talla</option>@foreach($openingProduct->tallas ?: [] as $value)<option @selected(old("lines.$index.talla") === $value)>{{ $value }}</option>@endforeach</select></label>@endif
                                    @if($openingProduct->requiere_color)<label>Color<select name="lines[{{ $index }}][color]" required><option value="">Seleccionar color</option>@foreach($openingProduct->colores ?: [] as $value)<option @selected(old("lines.$index.color") === $value)>{{ $value }}</option>@endforeach</select></label>@endif
                                    @foreach($openingProduct->otros_atributos ?: [] as $attribute)<label>{{ $attribute['nombre'] }}<select name="lines[{{ $index }}][atributos][{{ $attribute['nombre'] }}]" required><option value="">Seleccionar</option>@foreach($attribute['valores'] as $value)<option>{{ $value }}</option>@endforeach</select></label>@endforeach
                                    @if($openingProduct->requiere_serie)<label>Serie<input type="text" name="lines[{{ $index }}][serie]" maxlength="200" required></label>@endif
                                    <label>Unidades contadas<input type="number" name="lines[{{ $index }}][cantidad]" min="0" max="1000000" required value="{{ old("lines.$index.cantidad") }}"></label>
                                </div>
                            </fieldset>
                        @endfor
                        <label class="epp-field-wide">Motivo del conteo<textarea name="motivo" maxlength="1000" required>{{ old('motivo') }}</textarea></label>
                        <button class="epp-btn epp-btn-primary">Revisar apertura</button>
                    </form>
                @endif
            </section>
        @endif
    @endif

    <section class="lgt-card"><div class="lgt-card-header"><div><h2>Existencias registradas</h2><p>Una variante sin conteo confirmado figura como Sin conciliar; no tiene disponibilidad conocida.</p></div></div>
        <form method="GET" class="epp-form-grid"><label>Almacén<select name="almacen_id"><option value="">Todos los almacenes autorizados</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(request('almacen_id') === $warehouse->id)>{{ $warehouse->nombre }}</option>@endforeach</select></label><label>Buscar EPP<input type="text" name="q" value="{{ request('q') }}"></label><button class="epp-btn epp-btn-light">Filtrar</button></form>
        <p>{{ $existences->total() }} variantes registradas.</p>
        @forelse($existences as $existence)
            <article class="lgt-card-header"><div><h3>{{ $existence->epp?->nombre ?? 'EPP no disponible' }}</h3><p>{{ $existence->almacen?->nombre }}</p><p>@forelse($existence->atributos_json ?: [] as $name => $value){{ $name }}: {{ $value }}{{ !$loop->last ? ' · ' : '' }}@empty Sin variantes @endforelse</p>
                <p>Estado: {{ $existence->conciliado_at ? 'Conciliado' : 'Sin conciliar' }}. Disponible: {{ $existence->conciliado_at ? $existence->cantidad_disponible : 'Desconocido' }}. Reservado: {{ $existence->cantidad_reservada }}. Cuarentena: {{ $existence->cantidad_cuarentena }}.</p>
                @if($canAdjust && $existence->conciliado_at && !$preview)<details><summary class="epp-btn epp-btn-light">Registrar ajuste</summary><form method="POST" action="{{ route('epps.inventory.adjustment.preview') }}" class="epp-form-grid">@csrf<input type="hidden" name="existencia_id" value="{{ $existence->id }}"><label>Diferencia de unidades (+ entrada, - salida)<input type="number" name="delta" min="-1000000" max="1000000" required></label><label>Motivo<textarea name="motivo" maxlength="1000" required></textarea></label><button class="epp-btn epp-btn-primary">Revisar ajuste</button></form></details>@endif
            </div></article>
        @empty<p>No hay existencias registradas. Selecciona un EPP y registra su conteo inicial.</p>@endforelse
        {{ $existences->links() }}
    </section>
    <section class="lgt-card"><div class="lgt-card-header"><div><h2>Últimos movimientos</h2><p>Historial de cantidades con responsable y motivo.</p></div></div>@forelse($movements as $move)<article class="lgt-card-header"><div><h3>{{ $move->tipo }} · {{ $move->epp?->nombre }}</h3><p>{{ $move->created_at->format('d/m/Y H:i') }} · {{ $move->almacen?->nombre }} · {{ $move->actor?->email ?? 'Sin actor' }}</p><p>Saldo: {{ $move->saldo_anterior }} → {{ $move->saldo_posterior }}. Motivo: {{ $move->motivo }}</p></div></article>@empty<p class="lgt-card-header">Sin movimientos registrados.</p>@endforelse</section>
</div>
@endsection
