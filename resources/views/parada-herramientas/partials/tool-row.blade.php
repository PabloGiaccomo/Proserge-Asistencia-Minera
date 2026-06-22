@php
    $showUnidad = (bool) ($showUnidad ?? false);
@endphp

<div class="tool-row {{ $showUnidad ? 'has-unit' : '' }}">
    <input
        type="hidden"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][id]"
        value="{{ $row['id'] ?? '' }}"
    >
    <div class="tool-autocomplete-wrap">
        <input
            type="text"
            name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][descripcion]"
            class="form-control js-tool-description"
            data-tool-category="{{ $showUnidad ? 'CONSUMIBLE' : 'HERRAMIENTA' }}"
            placeholder="Descripcion"
            autocomplete="off"
            value="{{ $row['descripcion'] ?? '' }}"
            @readonly(!$puedeEditar)
        >
        <div class="tool-autocomplete-menu" hidden></div>
    </div>
    <input
        type="number"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][cantidad_solicitada]"
        class="form-control qty"
        min="0"
        value="{{ $row['cantidad_solicitada'] ?? 1 }}"
        @readonly(!$puedeEditar)
    >
    @if($showUnidad)
        <input
            type="text"
            name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][unidad]"
            class="form-control unit"
            placeholder="Unidad"
            value="{{ $row['unidad'] ?? '' }}"
            @readonly(!$puedeEditar)
        >
    @endif
    <input
        type="text"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][observaciones]"
        class="form-control js-tool-observation"
        list="toolObservationSuggestions"
        data-tool-category="{{ $showUnidad ? 'CONSUMIBLE' : 'HERRAMIENTA' }}"
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
        type="hidden"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][cantidad_entregada]"
        value="{{ $row['cantidad_entregada'] ?? 0 }}"
    >
    <input
        type="hidden"
        name="grupos[{{ $groupIndex }}][{{ $type }}][{{ $rowIndex }}][cantidad_recibida]"
        value="{{ $row['cantidad_recibida'] ?? 0 }}"
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
    @if($puedeEditar)
        <button type="button" class="btn-remove-tool" onclick="this.closest('.tool-row').remove()" aria-label="Quitar fila" title="Quitar fila">X</button>
    @endif
</div>
