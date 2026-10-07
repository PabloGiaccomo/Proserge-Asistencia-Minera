<section class="epp-operation-card"><h2>{{ $payload['worker']['name'] }}</h2><p>Documento: {{ $payload['worker']['document'] }}</p><p>Almacen: {{ $payload['warehouse']['name'] }}</p></section>
<section class="epp-operation-card"><h2>{{ $operation->tipo === 'DEVOLUCION' ? 'Devolveras ahora' : 'Recibiras ahora' }}: {{ collect($payload['details'])->sum('processed_quantity') }}</h2>
    @forelse($payload['details'] as $detail)
        <article class="epp-review-item"><h3>{{ $detail['snapshot']['real']['nombre'] }}</h3><p><strong>{{ $detail['processed_quantity'] }} unidades</strong> · {{ $operation->tipo === 'DEVOLUCION' ? 'Devueltas en este lote' : ($detail['return_policy'] === 'RETORNABLE' ? 'Retornable: se debera devolver' : 'Consumible: no requiere devolucion') }}</p>
            @if($operation->tipo === 'DEVOLUCION')<p>Condicion: {{ $detail['return_condition'] }} · Destino: {{ $detail['inventory_destination'] }}</p><p>Saldo restante: {{ $detail['snapshot']['saldo_restante'] }} unidades de la entrega de origen.</p>@endif
            <p>@foreach($detail['attributes'] ?: [] as $name => $value){{ $name }}: {{ $value }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>
            @if($detail['requested_epp_id'] !== $detail['epp_id'])<p>Sustitucion: solicitado {{ $detail['snapshot']['solicitado']['nombre'] }}; se entrega {{ $detail['snapshot']['real']['nombre'] }}. Motivo: {{ $detail['reason'] }}</p>@endif
        </article>
    @empty<p>No hay unidades por {{ $operation->tipo === 'DEVOLUCION' ? 'devolver' : 'recibir' }} en este lote.</p>@endforelse
</section>
@if($operation->tipo !== 'DEVOLUCION')
<section class="epp-operation-card"><h2>Quedara pendiente: {{ collect($payload['pending'])->sum('pending_quantity') }}</h2><p>Estas unidades no se declaran recibidas con esta firma.</p>
    @forelse($payload['pending'] as $detail)<article class="epp-review-item"><h3>{{ $detail['snapshot']['solicitado']['nombre'] }}</h3><p>{{ $detail['pending_quantity'] }} unidades pendientes. {{ ($detail['snapshot']['disponibilidad'] ?? '') === 'STOCK_NO_CONCILIADO' ? 'Stock sin conciliar: requiere conteo del almacen.' : 'Falta disponibilidad para completar la entrega.' }}</p></article>@empty<p>Sin unidades pendientes.</p>@endforelse
</section>
@endif
@if($payload['rejected'])<section class="epp-operation-card"><h2>Articulos rechazados</h2>@foreach($payload['rejected'] as $detail)<p>{{ $detail['snapshot']['solicitado']['nombre'] }}: {{ $detail['requested_quantity'] }} unidades. {{ $detail['reason'] }}</p>@endforeach</section>@endif
