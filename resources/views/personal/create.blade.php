@extends('layouts.app')

@section('title', 'Nuevo Trabajador - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Nuevo trabajador</h1>
                <p class="page-subtitle">Registra al trabajador con la ficha completa y su configuracion interna.</p>
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
        @endphp

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Ficha del trabajador</h2>
                    <p class="ficha-card-subtitle">Completa los datos obligatorios de la ficha antes de registrar al trabajador.</p>
                </div>
                <span class="ficha-status ficha-status-pending">Alta manual</span>
            </div>
            <div class="ficha-card-body">
                @foreach($sections as $section)
                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h3 class="ficha-section-title">{{ $section['title'] }}</h3>
                        </div>
                        <div class="ficha-fields">
                            @foreach($section['fields'] as $field)
                                @php
                                    $key = $field['key'];
                                    $type = $field['type'];
                                    $value = $currentFieldValue($key);
                                    $isTextarea = $type === 'textarea';
                                    $fieldClass = $isTextarea ? 'ficha-field ficha-field-wide' : 'ficha-field';
                                    $paisNacimientoActual = $currentFieldValue('pais_nacimiento') ?: 'Peru';
                                    $domicilioPaisActual = $currentFieldValue('domicilio_tipo') ?: 'Peru';
                                    $bancoActual = $currentFieldValue('banco');
                                    $conditionalHidden = match ($key) {
                                        'estado_civil_otro' => $currentFieldValue('estado_civil') !== 'Otro',
                                        'nacionalidad_otra' => $currentFieldValue('nacionalidad') !== 'Otra',
                                        'pais_nacimiento_otro', 'lugar_nacimiento_extranjero' => $paisNacimientoActual !== 'Otro',
                                        'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento' => $paisNacimientoActual === 'Otro',
                                        'domicilio_pais_otro', 'domicilio_extranjero' => $domicilioPaisActual !== 'Extranjero',
                                        'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion' => $domicilioPaisActual === 'Extranjero',
                                        'numero_cuenta' => !in_array($bancoActual, ['BCP', 'Interbank'], true),
                                        'banco_otro', 'cci' => $bancoActual !== 'Otro',
                                        'tipo_comision', 'tipo_afp', 'cuspp' => $currentFieldValue('sistema_pensionario') !== 'Sistema Privado de Pensiones',
                                        'quinta_otra_empresa', 'quinta_otra_empresa_ruc' => $currentFieldValue('quinta_empleador_principal') !== 'Otra empresa',
                                        'fecha_fin_contrato' => !in_array($currentFieldValue('contrato'), ['FIJO', 'INTER', 'REG'], true),
                                        'fecha_cese' => $currentFieldValue('contrato') !== 'INDET',
                                        default => false,
                                    };
                                @endphp

                                @if($type === 'hidden')
                                    <input type="hidden" id="field_{{ $key }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}">
                                    @continue
                                @endif

                                <div class="{{ $fieldClass }}" data-ficha-field="{{ $key }}" style="{{ $conditionalHidden ? 'display:none;' : '' }}">
                                    <label class="ficha-label" for="field_{{ $key }}">
                                        {{ $field['label'] }}
                                        @if($field['required'])
                                            <span class="ficha-required">*</span>
                                        @endif
                                    </label>

                                    @if($type === 'select')
                                        <select class="ficha-select" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" data-current-value="{{ $value }}" {{ $conditionalHidden ? 'disabled' : '' }}>
                                            <option value="">Seleccionar</option>
                                            @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($isTextarea)
                                        <textarea class="ficha-textarea" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" {{ $conditionalHidden ? 'disabled' : '' }}>{{ $value }}</textarea>
                                    @else
                                        <input class="ficha-input" id="field_{{ $key }}" type="{{ $type }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}" {{ $conditionalHidden ? 'disabled' : '' }}>
                                    @endif

                                    @error('fields.' . $key)
                                        <span class="ficha-error">{{ $message }}</span>
                                    @enderror
                                    @if($key === 'contrato')
                                        <span class="ficha-help" style="display:block; margin-top:6px; color:#64748b; font-size:12px;">
                                            Regimen, fijo e intermitente pueden usar fin de contrato. Indeterminado usa fecha de cese o cese manual.
                                        </span>
                                    @endif
                                    @if($key === 'fecha_fin_contrato')
                                        <span class="ficha-help" style="display:block; margin-top:6px; color:#64748b; font-size:12px;">
                                            Cuando esta fecha vence, el trabajador pasa a cesado.
                                        </span>
                                    @endif
                                    @if($key === 'fecha_cese')
                                        <span class="ficha-help" style="display:block; margin-top:6px; color:#64748b; font-size:12px;">
                                            Para indeterminado, desde esta fecha el trabajador aparecera como cesado.
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Configuracion interna</h2>
                    <p class="ficha-card-subtitle">Define el estado del trabajador, su perfil y las ubicaciones donde puede operar.</p>
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
                        <h3 class="ficha-section-title">Minas</h3>
                    </div>
                    <div class="mines-grid">
                        @foreach($catalogMinas as $mina)
                            <div class="mine-selection-item">
                                <div class="mine-checkbox">
                                    <input type="checkbox" name="minas[]" value="{{ $mina }}" id="mina_{{ str_replace(' ', '_', $mina) }}" @checked(in_array($mina, old('minas', []), true))>
                                    <label for="mina_{{ str_replace(' ', '_', $mina) }}" class="mine-checkbox-label">
                                        <span class="checkbox-custom"></span>
                                        <span class="checkbox-text">{{ $mina }}</span>
                                    </label>
                                </div>
                                <div class="mine-status-select">
                                    <select name="mina_estado[{{ $mina }}]" class="form-control form-control-sm">
                                        <option value="habilitado" @selected(old('mina_estado.' . $mina, 'habilitado') === 'habilitado')>Habilitado</option>
                                        <option value="proceso" @selected(old('mina_estado.' . $mina) === 'proceso')>En proceso</option>
                                        <option value="no_habilitado" @selected(old('mina_estado.' . $mina) === 'no_habilitado')>No habilitado</option>
                                    </select>
                                </div>
                            </div>
                        @endforeach
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
    @include('personal.fichas.partials.conditional-fields-script', ['scope' => 'rrhh'])
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
