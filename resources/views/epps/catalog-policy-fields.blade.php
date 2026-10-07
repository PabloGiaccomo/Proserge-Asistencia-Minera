@php
    $catalogPolicy = $catalogPolicy ?? [];
    $policyValue = $useOldPolicy ? old('politica_devolucion', $catalogPolicy['politica_devolucion'] ?? 'RETORNABLE') : ($catalogPolicy['politica_devolucion'] ?? 'RETORNABLE');
    $policyControls = ['control_inventario' => ['Controlar inventario por almacén', true], 'permite_entrega_repetida' => ['Permitir entregas repetidas', false], 'requiere_serie' => ['Exigir número de serie', false], 'es_requerido_cobertura' => ['Incluir en cobertura requerida', true]];
@endphp
<label class="epp-field-wide">Política de devolución
    <select name="politica_devolucion" required>
        <option value="RETORNABLE" @selected($policyValue === 'RETORNABLE')>Retornable: se debe devolver</option>
        <option value="CONSUMIBLE" @selected($policyValue === 'CONSUMIBLE')>Consumible: no exige devolución</option>
    </select>
    <small>Los EPP existentes empiezan como retornables. Revisa la política según su uso operativo.</small>
</label>
@foreach($policyControls as $field => [$label, $default])
    <label class="epp-option-toggle">
        <input type="hidden" name="{{ $field }}" value="0">
        <input type="checkbox" name="{{ $field }}" value="1" @checked($useOldPolicy ? old($field, $catalogPolicy[$field] ?? $default) : ($catalogPolicy[$field] ?? $default))>
        <span>{{ $label }}</span>
    </label>
@endforeach
<label class="epp-option-toggle epp-field-wide"><input type="checkbox" name="politica_revisada" value="1" required><span>He revisado la política de devolución y los controles de este EPP.</span></label>
