<section class="epp-operation-card" data-operation-line data-line-index="{{ $index }}">
    <h2>Articulo <span data-line-number>{{ is_numeric($index) ? $index + 1 : '' }}</span></h2>
    <label>EPP solicitado<select name="lines[{{ $index }}][epp_id]" data-requested-product required>
        <option value="">Seleccionar articulo</option>
        @foreach($catalog as $product)<option value="{{ $product->id }}" @selected(($line['epp_id'] ?? '') === $product->id)>{{ $product->nombre }}</option>@endforeach
    </select></label>
    <label>Cantidad solicitada<div class="epp-quantity-controls"><button type="button" data-quantity-step="-1" aria-label="Reducir cantidad">−</button><input type="number" name="lines[{{ $index }}][cantidad_solicitada]" value="{{ $line['cantidad_solicitada'] ?? 1 }}" min="1" max="1000000" inputmode="numeric" required><button type="button" data-quantity-step="1" aria-label="Aumentar cantidad">+</button></div></label>
    <div data-line-attributes data-initial-attributes="{{ json_encode(($line['atributos'] ?? []) + array_filter(['TALLA' => $line['talla'] ?? null, 'COLOR' => $line['color'] ?? null, 'SERIE' => $line['serie'] ?? null])) }}"></div>
    <p data-line-availability role="status">Selecciona el articulo y su variante para consultar disponibilidad.</p>
    <label class="epp-operation-check"><input type="checkbox" name="lines[{{ $index }}][aceptar_parcial]" value="1" @checked($line['aceptar_parcial'] ?? false)>Permitir entrega parcial; el saldo quedara pendiente y no se firmara como recibido.</label>
    <details><summary>Sustitucion o motivo de nueva entrega</summary>
        <label>Articulo que se entregara<select name="lines[{{ $index }}][epp_real_id]" data-actual-product>
            <option value="">El mismo articulo solicitado</option>
            @foreach($catalog as $product)<option value="{{ $product->id }}" @selected(($line['epp_real_id'] ?? '') === $product->id && ($line['epp_id'] ?? '') !== $product->id)>{{ $product->nombre }}</option>@endforeach
        </select></label>
        <label class="epp-operation-check"><input type="checkbox" name="lines[{{ $index }}][aceptar_sustitucion]" value="1" @checked($line['aceptar_sustitucion'] ?? false)>Se revisara esta sustitucion con el trabajador.</label>
        <label>Motivo<textarea name="lines[{{ $index }}][motivo]" maxlength="1000">{{ $line['motivo'] ?? '' }}</textarea></label>
    </details>
    <button type="button" class="epp-operation-button" data-remove-line>Quitar este articulo</button>
</section>
