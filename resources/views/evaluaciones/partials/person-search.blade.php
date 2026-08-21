@php
    $fieldName = $name;
    $selectedId = (string) old($fieldName, '');
@endphp

<div
    class="eval-field eval-person-search"
    data-eval-person-search
    data-search-url="{{ route('evaluaciones.personal.buscar') }}"
>
    <label for="{{ $fieldName }}_search">{{ $label }}</label>
    <div class="eval-person-search-control">
        <input
            id="{{ $fieldName }}_search"
            type="search"
            autocomplete="off"
            placeholder="{{ $placeholder ?? 'Buscar por nombre, DNI o puesto' }}"
            data-eval-person-input
            required
        >
        <button type="button" class="eval-person-clear" data-eval-person-clear aria-label="Quitar persona seleccionada" title="Quitar selección">&times;</button>
    </div>
    <input type="hidden" name="{{ $fieldName }}" value="{{ $selectedId }}" data-eval-person-id>
    <div class="eval-person-results" data-eval-person-results role="listbox" hidden></div>
    <small class="eval-person-help" data-eval-person-help>Escribe al menos 2 caracteres y selecciona una persona.</small>
</div>
