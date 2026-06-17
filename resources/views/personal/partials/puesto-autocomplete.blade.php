@php
    $puestoOptionsForInput = collect($options ?? $puestoOptions ?? [])
        ->map(fn ($value) => trim((string) $value))
        ->filter(fn (string $value): bool => $value !== '')
        ->unique()
        ->values();
    $puestoInputName = $name ?? 'puesto';
    $puestoInputId = $inputId ?? 'puesto_autocomplete';
    $puestoListId = $listId ?? ($puestoInputId . '_list');
    $puestoInputClass = $class ?? 'form-control';
    $puestoInputValue = (string) ($value ?? '');
    $puestoPlaceholder = $placeholder ?? 'Escribe para buscar cargo / puesto';
    $puestoRequired = (bool) ($required ?? false);
    $puestoDisabled = (bool) ($disabled ?? false);
    $puestoReadonly = (bool) ($readonly ?? false);
    $puestoErrorId = $puestoInputId . '_error';
    $puestoDataField = $dataField ?? null;
@endphp

<input
    class="{{ $puestoInputClass }}"
    id="{{ $puestoInputId }}"
    type="text"
    name="{{ $puestoInputName }}"
    value="{{ $puestoInputValue }}"
    list="{{ $puestoListId }}"
    placeholder="{{ $puestoPlaceholder }}"
    autocomplete="off"
    aria-describedby="{{ $puestoErrorId }}"
    data-puesto-autocomplete
    @if($puestoDataField) data-contract-edit-field="{{ $puestoDataField }}" @endif
    {{ $puestoRequired ? 'required' : '' }}
    {{ $puestoDisabled ? 'disabled' : '' }}
    {{ $puestoReadonly ? 'readonly' : '' }}
>
<datalist id="{{ $puestoListId }}">
    @foreach($puestoOptionsForInput as $puestoOption)
        <option value="{{ $puestoOption }}"></option>
    @endforeach
</datalist>
<div id="{{ $puestoErrorId }}" class="puesto-autocomplete-error" data-puesto-autocomplete-error style="display:none;">
    Elige un cargo o puesto de la lista del sistema.
</div>

@once
    <style>
        .puesto-autocomplete-error {
            margin-top: 6px;
            color: #dc2626;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.35;
        }
    </style>
    <script>
    (function () {
        if (window.__prosergePuestoAutocomplete) return;
        window.__prosergePuestoAutocomplete = true;

        const normalize = function (value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/\s+/g, ' ')
                .trim()
                .toUpperCase();
        };

        const findCatalogValue = function (input) {
            const value = normalize(input.value);
            if (!value) return '';

            const options = Array.from(input.list?.options || []);
            const match = options.find(function (option) {
                return normalize(option.value) === value;
            });

            return match ? match.value : null;
        };

        const errorFor = function (input) {
            return document.getElementById(input.id + '_error') || null;
        };

        const setInvalidMessage = function (input, message) {
            const error = errorFor(input);
            input.setCustomValidity(message || '');
            if (!error) return;

            error.textContent = message || 'Elige un cargo o puesto de la lista del sistema.';
            error.style.display = message ? 'block' : 'none';
        };

        const validatePuestoInput = function (input, showMessage) {
            const rawValue = String(input.value || '').trim();
            const match = findCatalogValue(input);

            if (!rawValue) {
                setInvalidMessage(input, input.required && showMessage ? 'Selecciona un cargo o puesto de la lista.' : '');
                return !input.required;
            }

            if (match === null) {
                setInvalidMessage(input, showMessage ? 'Selecciona un cargo o puesto de la lista.' : '');
                return false;
            }

            input.value = match;
            setInvalidMessage(input, '');
            return true;
        };

        document.addEventListener('input', function (event) {
            const input = event.target.closest?.('[data-puesto-autocomplete]');
            if (!input) return;

            setInvalidMessage(input, '');
        });

        document.addEventListener('change', function (event) {
            const input = event.target.closest?.('[data-puesto-autocomplete]');
            if (!input) return;

            validatePuestoInput(input, true);
        });

        document.addEventListener('blur', function (event) {
            const input = event.target.closest?.('[data-puesto-autocomplete]');
            if (!input) return;

            validatePuestoInput(input, true);
        }, true);

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;

            const inputs = Array.from(form.querySelectorAll('[data-puesto-autocomplete]'))
                .filter(function (input) {
                    return !input.disabled && !input.readOnly;
                });

            for (const input of inputs) {
                validatePuestoInput(input, true);

                if (!input.checkValidity()) {
                    event.preventDefault();
                    input.reportValidity();
                    input.focus();
                    return;
                }
            }
        });
    })();
    </script>
@endonce
