@extends('layouts.app')

@section('title', 'Nuevo Trabajador - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Nuevo trabajador</h1>
                <p class="page-subtitle">Registra los datos basicos y genera la ficha para que el trabajador complete lo faltante.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('personal.store') }}" class="ficha-workspace">
        @csrf
        @php
            $currentFieldValue = fn (string $key): string => (string) old('fields.' . $key, $initialFields[$key] ?? '');
            $manualFields = [
                ['key' => 'nombres', 'label' => 'Nombres', 'type' => 'text', 'required' => true],
                ['key' => 'apellido_paterno', 'label' => 'Apellido paterno', 'type' => 'text', 'required' => true],
                ['key' => 'apellido_materno', 'label' => 'Apellido materno', 'type' => 'text', 'required' => true],
                ['key' => 'tipo_documento', 'label' => 'Tipo de documento', 'type' => 'select', 'required' => true, 'options' => \App\Modules\Personal\Support\PersonalFichaCatalog::DOCUMENT_TYPES],
                ['key' => 'numero_documento', 'label' => 'Numero de documento', 'type' => 'text', 'required' => true],
                ['key' => 'telefono', 'label' => 'Telefono celular', 'type' => 'tel', 'required' => true],
                ['key' => 'correo', 'label' => 'Correo electronico', 'type' => 'email', 'required' => true],
            ];
        @endphp

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Datos basicos</h2>
                    <p class="ficha-card-subtitle">Solo se piden los datos necesarios para crear el registro y enviar la ficha.</p>
                </div>
                <span class="ficha-status ficha-status-pending">Alta manual</span>
            </div>
            <div class="ficha-card-body">
                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Identificacion y contacto</h3>
                    </div>
                    <div class="ficha-fields">
                        @foreach($manualFields as $field)
                            @php
                                $key = $field['key'];
                                $value = $currentFieldValue($key);
                                if ($key === 'tipo_documento' && $value === '') {
                                    $value = 'DNI';
                                }
                            @endphp
                            <div class="ficha-field" data-ficha-field="{{ $key }}">
                                <label class="ficha-label" for="field_{{ $key }}">
                                    {{ $field['label'] }}
                                    @if($field['required'])
                                        <span class="ficha-required">*</span>
                                    @endif
                                </label>

                                @if($field['type'] === 'select')
                                    <select class="ficha-select" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}">
                                        <option value="">Seleccionar</option>
                                        @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input class="ficha-input" id="field_{{ $key }}" type="{{ $field['type'] }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}">
                                @endif

                                @error('fields.' . $key)
                                    <span class="ficha-error">{{ $message }}</span>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </section>

                @foreach(['contrato' => 'REG', 'pais_nacimiento' => 'Peru', 'domicilio_tipo' => 'Peru', 'banco' => 'BCP'] as $key => $value)
                    <input type="hidden" id="field_{{ $key }}" name="fields[{{ $key }}]" value="{{ $currentFieldValue($key) ?: $value }}" data-ficha-key="{{ $key }}">
                @endforeach
            </div>
        </div>

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Configuracion interna</h2>
                    <p class="ficha-card-subtitle">Define el estado inicial, perfil interno, oficinas y talleres. Las minas se gestionan desde Habilitacion minera.</p>
                </div>
            </div>
            <div class="ficha-card-body">
                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Perfil interno</h3>
                    </div>
                    <div class="ficha-fields">
                        <div class="ficha-field">
                            <label class="ficha-label">Estado inicial</label>
                            <div class="ficha-input">Pendiente completar ficha</div>
                        </div>
                        <div class="ficha-field">
                            <label class="ficha-label" for="es_supervisor">Es supervisor</label>
                            <select class="ficha-select" id="es_supervisor" name="es_supervisor">
                                <option value="0" @selected(old('es_supervisor', '0') === '0')>No</option>
                                <option value="1" @selected(old('es_supervisor') === '1')>Si</option>
                            </select>
                            @error('es_supervisor')
                                <span class="ficha-error">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </section>

                <section class="ficha-section">
                    <div class="ficha-section-header">
                        <h3 class="ficha-section-title">Oficinas y talleres</h3>
                    </div>
                    <div class="mines-grid">
                        @foreach($catalogOficinas as $oficina)
                            <div class="mine-selection-item">
                                <div class="mine-checkbox">
                                    <input type="checkbox" name="minas[]" value="{{ $oficina }}" id="oficina_{{ $loop->index }}" @checked(in_array($oficina, old('minas', []), true))>
                                    <label for="oficina_{{ $loop->index }}" class="mine-checkbox-label">
                                        <span class="checkbox-custom"></span>
                                        <span class="checkbox-text">{{ $oficina }}</span>
                                    </label>
                                </div>
                            </div>
                        @endforeach

                        @foreach($catalogTalleres as $taller)
                            <div class="mine-selection-item">
                                <div class="mine-checkbox">
                                    <input type="checkbox" name="minas[]" value="{{ $taller }}" id="taller_{{ $loop->index }}" @checked(in_array($taller, old('minas', []), true))>
                                    <label for="taller_{{ $loop->index }}" class="mine-checkbox-label">
                                        <span class="checkbox-custom"></span>
                                        <span class="checkbox-text">{{ $taller }}</span>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach($catalogOficinas as $oficina)
                        <input type="hidden" name="mina_estado[{{ $oficina }}]" value="habilitado">
                    @endforeach
                    @foreach($catalogTalleres as $taller)
                        <input type="hidden" name="mina_estado[{{ $taller }}]" value="habilitado">
                    @endforeach
                </section>
            </div>
        </div>

        <div class="ficha-actions-bar">
            <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar trabajador</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
    <script>
    document.querySelectorAll('input[name="minas[]"]').forEach(function (checkbox) {
        const statusSelect = checkbox.closest('.mine-selection-item')?.querySelector('select');

        if (!statusSelect) {
            return;
        }

        checkbox.addEventListener('change', function () {
            if (!this.checked) {
                statusSelect.value = 'habilitado';
            }
        });
    });
    </script>
@endpush
