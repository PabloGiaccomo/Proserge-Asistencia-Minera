<div class="tool-row">
    <input
        type="hidden"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][id]"
        value="{{ $row['id'] ?? '' }}"
    >
    <input
        type="text"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][descripcion]"
        class="form-control"
        placeholder="Descripcion"
        value="{{ $row['descripcion'] ?? '' }}"
        @readonly(!$puedeEditar)
    >
    <input
        type="number"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][cantidad_solicitada]"
        class="form-control qty"
        min="1"
        value="{{ $row['cantidad_solicitada'] ?? 1 }}"
        @readonly(!$puedeEditar)
    >
    <input
        type="text"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][observaciones]"
        class="form-control"
        placeholder="Observaciones"
        value="{{ $row['observaciones'] ?? '' }}"
        @readonly(!$puedeEditar)
    >
    <input
        type="date"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][pedido_solicitado_at]"
        class="form-control"
        value="{{ $row['pedido_solicitado_at'] ?? '' }}"
        @readonly(!($puedeActualizarPedido ?? false))
    >
    <input
        type="date"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][pedido_llego_at]"
        class="form-control"
        value="{{ $row['pedido_llego_at'] ?? '' }}"
        @readonly(!($puedeActualizarPedido ?? false))
    >
    @php
        $pedidoCompleto = !empty($row['pedido_solicitado_at']) && !empty($row['pedido_llego_at']);
    @endphp
    <span class="tools-status {{ $pedidoCompleto ? 'sent' : 'pending' }}">
        {{ $pedidoCompleto ? 'Pedido completo' : 'Pedido pendiente' }}
    </span>
    @if(($puedeActualizarPedido ?? false))
        <button type="submit" class="btn-row {{ $pedidoCompleto ? 'btn-row-outline' : '' }}" @if($pedidoCompleto) disabled @endif>
            {{ $pedidoCompleto ? 'Pedido completo' : 'Completar pedido' }}
        </button>
    @endif
    @if($puedeEditar)
        <button type="button" class="btn-remove-tool" onclick="this.closest('.tool-row').remove()">Quitar</button>
    @endif
</div>
