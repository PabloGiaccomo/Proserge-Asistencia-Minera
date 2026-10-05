@php
    $readonly = (bool) ($readonly ?? false);
    $formMode = (string) ($formMode ?? 'public');
    $archivos = collect($archivos ?? []);
    $familiares = is_array($familiares ?? null) ? $familiares : [];
    $familyRows = old('familiares', $familiares);
    $fieldValue = fn (string $key): string => (string) old('fields.' . $key, $data[$key] ?? '');
    $firmaActual = (string) old('firma_base64', $firmaBase64 ?? '');
    $huellaArchivo = $archivos->firstWhere('tipo', 'huella');
    $canViewSensitiveFichaData = (bool) ($canViewSensitiveFichaData ?? true);
    $sensitiveFichaSections = ['Datos bancarios', 'Sistema pensionario'];
    $downloadRoute = fn ($archivo) => isset($ingreso) && $archivo
        ? route('personal.ingresos.archivos.download', [$ingreso->id, $archivo->id])
        : '#';
    $normalizeFichaOption = static function (?string $value): string {
        return \Illuminate\Support\Str::of((string) $value)
            ->trim()
            ->ascii()
            ->upper()
            ->toString();
    };
    $bankTypeForDisplay = static function (?string $value) use ($normalizeFichaOption): string {
        $normalized = $normalizeFichaOption($value);

        if ($normalized === 'BCP' || str_contains($normalized, 'BANCO DE CREDITO')) {
            return 'BCP';
        }

        if ($normalized === 'INTERBANK') {
            return 'Interbank';
        }

        if (in_array($normalized, ['OTRO', 'OTRA'], true)) {
            return 'Otro';
        }

        return $normalized === '' ? '' : 'Otro';
    };
    $ubigeoFields = [
        'departamento_nacimiento',
        'provincia_nacimiento',
        'distrito_nacimiento',
        'domicilio_departamento',
        'domicilio_provincia',
        'domicilio_distrito',
    ];
@endphp

@once
    <style>
        [data-uppercase="1"],
        input[data-uppercase="1"],
        textarea[data-uppercase="1"] {
            text-transform: uppercase;
        }

        .signature-pad {
            touch-action: none !important;
        }

        .signature-pad.is-invalid {
            border-color: #dc2626 !important;
            border-style: solid !important;
            background: #fff5f5 !important;
        }

        .ficha-family-table-container {
            width: 100%;
            overflow-x: auto;
        }

        @media (min-width: 641px) {
            .ficha-family-check-text {
                display: none !important;
            }
            .mobile-del-label {
                display: none !important;
            }
            .desktop-del-icon {
                display: inline !important;
            }
        }

        @media (max-width: 640px) {
            .ficha-family-table-container {
                overflow-x: visible !important;
            }

            .ficha-family-responsive,
            .ficha-family-responsive tbody {
                display: block !important;
                width: 100% !important;
                min-width: 0 !important;
            }

            .ficha-family-responsive thead {
                display: none !important;
            }

            .ficha-family-responsive tbody {
                display: flex !important;
                flex-direction: column !important;
                gap: 14px !important;
            }

            .ficha-family-row-card {
                display: flex !important;
                flex-direction: column !important;
                gap: 10px !important;
                padding: 14px !important;
                border: 1px solid #dbe3ef !important;
                border-radius: 10px !important;
                background: #f8fafc !important;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04) !important;
            }

            .ficha-family-row-card td {
                display: flex !important;
                flex-direction: column !important;
                gap: 4px !important;
                padding: 0 !important;
                border: none !important;
                text-align: left !important;
            }

            .ficha-family-row-card td[data-label]::before {
                content: attr(data-label);
                font-size: 11px;
                font-weight: 700;
                color: #475569;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }

            .ficha-family-row-card td.ficha-family-check-cell {
                flex-direction: row !important;
                align-items: center !important;
                gap: 8px !important;
                padding: 4px 0 !important;
                text-align: left !important;
            }

            .ficha-family-row-card td.ficha-family-check-cell::before {
                display: none !important;
            }

            .ficha-family-row-card .ficha-check-label {
                display: inline-flex !important;
                align-items: center !important;
                gap: 8px !important;
                cursor: pointer;
            }

            .ficha-family-row-card .ficha-family-check-text {
                display: inline !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                color: #334155 !important;
            }

            .ficha-family-row-card .ficha-family-check-text-readonly::before {
                content: attr(data-label) ': ';
                font-weight: 700;
                color: #475569;
            }

            .ficha-family-row-card td.ficha-family-action-cell {
                margin-top: 4px !important;
                padding-top: 10px !important;
                border-top: 1px dashed #cbd5e1 !important;
            }

            .ficha-family-row-card .btn-remove-family {
                width: 100% !important;
                padding: 8px 12px !important;
                border-color: #fca5a5 !important;
                color: #b91c1c !important;
                background: #fef2f2 !important;
                font-weight: 600 !important;
                justify-content: center !important;
            }

            .ficha-family-row-card .desktop-del-icon {
                display: none !important;
            }

            .ficha-family-row-card .mobile-del-label {
                display: inline !important;
            }
        }
    </style>
@endonce

<div class="ingreso-form-stack">
    @if(!$canViewSensitiveFichaData)
        <div class="ficha-alert ficha-alert-warning">
            Los datos bancarios, pensionarios, firma y huella estan ocultos para este rol.
        </div>
    @endif

    @foreach($sections as $section)
        @if(!$canViewSensitiveFichaData && in_array($section['title'] ?? '', $sensitiveFichaSections, true))
            @continue
        @endif
        @php
            $isSensitiveFichaSection = in_array($section['title'] ?? '', $sensitiveFichaSections, true);
        @endphp
        <section class="ficha-section">
            <div class="ficha-section-header">
                <h2 class="ficha-section-title">{{ $section['title'] }}</h2>
            </div>
            <div class="ficha-fields">
                @foreach($section['fields'] as $field)
                    @php
                        $key = $field['key'];
                        $type = $field['type'] ?? 'text';
                        $value = $fieldValue($key);
                        $options = $field['options'] ?? [];
                        $isWide = in_array($type, ['textarea', 'hidden'], true);
                        $isRequired = (bool) ($field['required'] ?? false);
                        $isNumeric = in_array($key, [
                            'numero_documento',
                            'numero_cuenta',
                            'cci',
                            'empleador_ruc',
                            'quinta_otra_empresa_ruc',
                            'anio_experiencia',
                            'anio_egreso',
                            'quinta_fecha_dia',
                            'quinta_fecha_mes',
                            'quinta_fecha_anio',
                            'quinta_ejercicio_anio',
                        ], true);
                        $isTel = in_array($key, ['telefono', 'telefono_alterno'], true) || $type === 'tel';
                        $uppercase = !in_array($type, ['email', 'hidden'], true) && !$isNumeric && !$isTel && $key !== 'remuneracion';
                        $inputMode = match (true) {
                            $key === 'remuneracion' => 'decimal',
                            $isNumeric => 'numeric',
                            $isTel => 'tel',
                            $type === 'email' => 'email',
                            default => null,
                        };
                        $inputType = $isTel ? 'tel' : $type;
                        if ($key === 'puesto') {
                            $options = [];
                        }
                        $paisNacimientoActual = $fieldValue('pais_nacimiento') ?: 'Peru';
                        $domicilioPaisActual = $fieldValue('domicilio_tipo') ?: 'Peru';
                        $bancoActual = $bankTypeForDisplay($fieldValue('banco'));
                        $sistemaPensionarioActual = $fieldValue('sistema_pensionario');
                        $quintaEmpleadorActual = $fieldValue('quinta_empleador_principal');
                        $isConditionallyRequired = match ($key) {
                            'estado_civil_otro' => $fieldValue('estado_civil') === 'Otro',
                            'nacionalidad_otra' => $fieldValue('nacionalidad') === 'Otra',
                            'pais_nacimiento_otro', 'lugar_nacimiento_extranjero' => $paisNacimientoActual === 'Otro',
                            'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento' => $paisNacimientoActual !== 'Otro',
                            'domicilio_pais_otro', 'domicilio_extranjero' => $domicilioPaisActual === 'Extranjero',
                            'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion' => $domicilioPaisActual !== 'Extranjero',
                            'numero_cuenta' => in_array($bancoActual, ['BCP', 'Interbank'], true),
                            'banco_otro' => $bancoActual === 'Otro',
                            'cci' => $bancoActual === 'Otro',
                            'tipo_comision', 'tipo_afp', 'cuspp' => $sistemaPensionarioActual === 'Sistema Privado de Pensiones',
                            'quinta_otra_empresa', 'quinta_otra_empresa_ruc' => $quintaEmpleadorActual === 'Otra empresa',
                            default => false,
                        };
                        $conditionalHidden = match ($key) {
                            'estado_civil_otro' => $fieldValue('estado_civil') !== 'Otro',
                            'nacionalidad_otra' => $fieldValue('nacionalidad') !== 'Otra',
                            'pais_nacimiento_otro', 'lugar_nacimiento_extranjero' => $paisNacimientoActual !== 'Otro',
                            'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento' => $paisNacimientoActual === 'Otro',
                            'domicilio_pais_otro', 'domicilio_extranjero' => $domicilioPaisActual !== 'Extranjero',
                            'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion' => $domicilioPaisActual === 'Extranjero',
                            'numero_cuenta' => !in_array($bancoActual, ['BCP', 'Interbank'], true),
                            'banco_otro' => $bancoActual !== 'Otro',
                            'cci' => $bancoActual === '',
                            'tipo_comision', 'tipo_afp', 'cuspp' => $sistemaPensionarioActual !== 'Sistema Privado de Pensiones',
                            'quinta_otra_empresa', 'quinta_otra_empresa_ruc' => $quintaEmpleadorActual !== 'Otra empresa',
                            default => false,
                        };
                        if ($readonly && $canViewSensitiveFichaData && $isSensitiveFichaSection) {
                            $conditionalHidden = false;
                        }
                        $isRequired = $isRequired || $isConditionallyRequired;
                        $fieldDisabled = $conditionalHidden;
                    @endphp

                    @if($type === 'hidden')
                        <input type="hidden" id="field_{{ $key }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}">
                        @continue
                    @endif

                    <div class="ficha-field {{ $isWide ? 'ficha-field-wide' : '' }}" data-ficha-field="{{ $key }}" style="{{ $conditionalHidden ? 'display:none;' : '' }}">
                        <label class="ficha-label" for="field_{{ $key }}">
                            {{ $field['label'] }}
                            @if($isRequired)
                                <span class="ficha-required">*</span>
                            @endif
                        </label>

                        @if($readonly)
                            @php
                                $readonlyValue = $type === 'date'
                                    ? \App\Modules\Personal\Support\PersonalFichaCatalog::displayDate($value)
                                    : $value;
                            @endphp
                            <div class="ficha-input ingreso-readonly">{{ $readonlyValue !== '' ? $readonlyValue : '-' }}</div>
                        @elseif($key === 'puesto')
                            <input
                                id="field_{{ $key }}"
                                class="ficha-input"
                                type="text"
                                name="fields[{{ $key }}]"
                                value="{{ $value }}"
                                list="puesto-options"
                                autocomplete="off"
                                data-ficha-key="{{ $key }}"
                                data-uppercase="1"
                                {{ $fieldDisabled ? 'disabled' : '' }}
                                {{ (!$fieldDisabled && $isRequired) ? 'required' : '' }}>
                        @elseif($type === 'textarea')
                            <textarea
                                id="field_{{ $key }}"
                                class="ficha-input"
                                name="fields[{{ $key }}]"
                                rows="3"
                                data-ficha-key="{{ $key }}"
                                data-uppercase="{{ $uppercase ? '1' : '0' }}"
                                {{ $fieldDisabled ? 'disabled' : '' }}
                                {{ (!$fieldDisabled && $isRequired) ? 'required' : '' }}>{{ $value }}</textarea>
                        @elseif($type === 'select' || in_array($key, $ubigeoFields, true))
                            <select
                                id="field_{{ $key }}"
                                class="ficha-input"
                                name="fields[{{ $key }}]"
                                data-ficha-key="{{ $key }}"
                                data-current-value="{{ $value }}"
                                {{ $fieldDisabled ? 'disabled' : '' }}
                                {{ (!$fieldDisabled && $isRequired) ? 'required' : '' }}>
                                <option value="">Seleccionar</option>
                                @foreach($options as $optionValue => $optionLabel)
                                    @php
                                        $realValue = is_string($optionValue) ? $optionValue : $optionLabel;
                                    @endphp
                                    <option value="{{ $realValue }}" @selected((string) $value === (string) $realValue)>{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        @else
                            <input
                                id="field_{{ $key }}"
                                class="ficha-input"
                                type="{{ $inputType }}"
                                name="fields[{{ $key }}]"
                                value="{{ $value }}"
                                data-ficha-key="{{ $key }}"
                                @if($inputMode) inputmode="{{ $inputMode }}" @endif
                                data-uppercase="{{ $uppercase ? '1' : '0' }}"
                                {{ $fieldDisabled ? 'disabled' : '' }}
                                {{ (!$fieldDisabled && $isRequired) ? 'required' : '' }}>
                        @endif

                        @error('fields.' . $key)
                            <span class="ficha-error">{{ $message }}</span>
                        @enderror
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <datalist id="puesto-options">
        @foreach($puestoOptions ?? [] as $puesto)
            <option value="{{ $puesto }}"></option>
        @endforeach
    </datalist>

    <section class="ficha-section">
        <div class="ficha-section-header">
            <h2 class="ficha-section-title">Familiares o contactos de emergencia</h2>
            @if(!$readonly)
                <button type="button" class="btn btn-outline btn-sm" data-add-family>Agregar familiar</button>
            @endif
        </div>
        <div class="ficha-card-body">
            <div class="ficha-family-table-container ficha-batch-table-wrap">
                <table class="ficha-batch-table ficha-family-responsive">
                    <thead>
                        <tr>
                            <th>Parentesco</th>
                            <th>Apellidos y nombres</th>
                            <th>Fecha nacimiento</th>
                            <th>Documento</th>
                            <th>Telefono</th>
                            <th>Vive conmigo</th>
                            <th>Estudia</th>
                            @if(!$readonly)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody data-family-body>
                        @foreach($familyRows as $index => $familiar)
                            <tr data-family-row class="ficha-family-row-card">
                                <td data-label="Parentesco">
                                    @if($readonly)
                                        {{ $familiar['parentesco'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" name="familiares[{{ $index }}][parentesco]" value="{{ $familiar['parentesco'] ?? '' }}" data-uppercase="1" placeholder="Ej: Conyuge, Hijo(a)">
                                    @endif
                                </td>
                                <td data-label="Apellidos y nombres">
                                    @if($readonly)
                                        {{ $familiar['nombres_apellidos'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" name="familiares[{{ $index }}][nombres_apellidos]" value="{{ $familiar['nombres_apellidos'] ?? '' }}" data-uppercase="1" placeholder="Nombres completos">
                                    @endif
                                </td>
                                <td data-label="Fecha de nacimiento">
                                    @if($readonly)
                                        {{ \App\Modules\Personal\Support\PersonalFichaCatalog::displayDate($familiar['fecha_nacimiento'] ?? '') ?: '-' }}
                                    @else
                                        <input class="ficha-input" type="date" name="familiares[{{ $index }}][fecha_nacimiento]">
                                    @endif
                                </td>
                                <td data-label="N° Documento (DNI)">
                                    @if($readonly)
                                        {{ $familiar['numero_documento'] ?? '-' }}
                                    @else
                                        <input type="hidden" name="familiares[{{ $index }}][tipo_documento]" value="{{ $familiar['tipo_documento'] ?? 'DNI' }}">
                                        <input class="ficha-input" name="familiares[{{ $index }}][numero_documento]" value="{{ $familiar['numero_documento'] ?? '' }}" inputmode="numeric" placeholder="8 digitos">
                                    @endif
                                </td>
                                <td data-label="Telefono">
                                    @if($readonly)
                                        {{ $familiar['telefono'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" type="tel" inputmode="tel" name="familiares[{{ $index }}][telefono]" value="{{ $familiar['telefono'] ?? '' }}" placeholder="9 digitos">
                                    @endif
                                </td>
                                <td data-label="Vive conmigo" class="ficha-family-check-cell" style="text-align:center;">
                                    @if($readonly)
                                        <span class="ficha-family-check-text-readonly">{{ !empty($familiar['vive_con_trabajador']) ? 'Si' : 'No' }}</span>
                                    @else
                                        <label class="ficha-check-label">
                                            <input type="checkbox" name="familiares[{{ $index }}][vive_con_trabajador]" value="1" @checked(!empty($familiar['vive_con_trabajador']))>
                                            <span class="ficha-family-check-text">Vive conmigo</span>
                                        </label>
                                    @endif
                                </td>
                                <td data-label="Estudia" class="ficha-family-check-cell" style="text-align:center;">
                                    @if($readonly)
                                        <span class="ficha-family-check-text-readonly">{{ !empty($familiar['estudia']) ? 'Si' : 'No' }}</span>
                                    @else
                                        <label class="ficha-check-label">
                                            <input type="checkbox" name="familiares[{{ $index }}][estudia]" value="1" @checked(!empty($familiar['estudia']))>
                                            <span class="ficha-family-check-text">Estudia</span>
                                        </label>
                                    @endif
                                </td>
                                @if(!$readonly)
                                    <td class="ficha-family-action-cell">
                                        <button type="button" class="btn btn-outline btn-sm btn-remove-family" data-remove-family>
                                            <span class="desktop-del-icon">X</span>
                                            <span class="mobile-del-label">Eliminar familiar</span>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="ficha-section">
        <div class="ficha-section-header">
            <h2 class="ficha-section-title">Documentos</h2>
        </div>
        <div class="ficha-card-body">
            @if(!$readonly)
                <div class="ficha-alert ficha-alert-warning">
                    Adjunta todos los documentos que tengas a la mano. Si te falta alguno, igual puedes enviar la ficha y RRHH lo marcara para regularizar.
                </div>
            @endif
            <div class="ficha-fields" style="padding:0;">
                @foreach($documentRequirements as $docKey => $requirement)
                    @php
                        $storedDoc = $archivos->firstWhere('tipo', $docKey);
                    @endphp
                    <div class="ficha-field ficha-field-wide">
                        <label class="ficha-label" for="documento_{{ $docKey }}">
                            {{ $requirement['label'] ?? $docKey }}
                            @if(!$readonly)<span class="ficha-status ficha-status-pending">Obligatorio</span>@endif
                        </label>
                        @if($storedDoc)
                            <div class="ingreso-file-row">
                                <span>{{ $storedDoc->nombre_original ?: 'Archivo cargado' }}</span>
                                @if(isset($ingreso))
                                    <a class="btn btn-outline btn-sm" href="{{ $downloadRoute($storedDoc) }}">Descargar</a>
                                @endif
                            </div>
                        @elseif($readonly)
                            <div class="ficha-input ingreso-readonly">No cargado</div>
                        @endif
                        @if(!$readonly)
                            <input id="documento_{{ $docKey }}" class="ficha-input" type="file" name="documentos[{{ $docKey }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
                            <div class="ficha-card-subtitle" style="margin-top:6px;">
                                {{ $storedDoc ? 'Puedes reemplazar el archivo seleccionando otro.' : 'Faltara regularizar si no lo adjuntas ahora.' }}
                            </div>
                        @endif
                        @error('documentos.' . $docKey)
                            <span class="ficha-error">{{ $message }}</span>
                        @enderror
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="ficha-section">
        <div class="ficha-section-header">
            <h2 class="ficha-section-title">Declaraciones finales</h2>
        </div>
        <div class="ficha-card-body">
            <div class="ingreso-declarations">
                @foreach($declarationCheckboxes as $declarationKey => $declarationLabel)
                    @if($readonly)
                        <div class="ficha-input ingreso-readonly">{{ $declarationLabel }}</div>
                    @else
                        <label class="ficha-check">
                            <input type="checkbox" name="declaraciones[{{ $declarationKey }}]" value="1" @checked(old('declaraciones.' . $declarationKey)) {{ $formMode === 'public' ? 'required' : '' }}>
                            <span>{{ $declarationLabel }}</span>
                        </label>
                        @error('declaraciones.' . $declarationKey)
                            <span class="ficha-error">{{ $message }}</span>
                        @enderror
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    @if($canViewSensitiveFichaData)
        <section class="ficha-section">
            <div class="ficha-section-header">
                <h2 class="ficha-section-title">Firma digital</h2>
            </div>
            <div class="ficha-card-body">
                @if($readonly)
                    @if($firmaActual)
                        <img class="ficha-preview-image" src="{{ $firmaActual }}" alt="Firma digital">
                    @else
                        <div class="ficha-input ingreso-readonly">Sin firma registrada</div>
                    @endif
                @else
                    <div class="public-signature-help">
                        <strong>Firma dentro del recuadro.</strong>
                        <ul>
                            <li>Dibuja tu firma completa con el dedo o mouse.</li>
                            <li>No pongas solo la huella o iniciales sueltas en este espacio.</li>
                            <li>Si te equivocas, limpia la firma y vuelve a firmar.</li>
                        </ul>
                    </div>
                    <div class="signature-pad-wrap">
                        <canvas class="signature-pad" data-signature-pad data-required="{{ $formMode === 'public' ? '1' : '0' }}" style="touch-action: none;"></canvas>
                        <input type="hidden" name="firma_base64" value="{{ $firmaActual }}" data-signature-input data-required="{{ $formMode === 'public' ? '1' : '0' }}">
                        <div class="ficha-actions-bar" style="justify-content:flex-start;">
                            <button type="button" class="btn btn-outline btn-sm" data-clear-signature>Limpiar firma</button>
                        </div>
                        <div class="ficha-error" data-signature-error style="display:none;margin-top:6px;font-size:12px;color:#dc2626;font-weight:600;">
                            Debes realizar tu firma dentro del recuadro antes de enviar la ficha.
                        </div>
                        @error('firma_base64') <span class="ficha-error">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>
        </section>

        <section class="ficha-section">
            <div class="ficha-section-header">
                <h2 class="ficha-section-title">Huella digital</h2>
            </div>
            <div class="ficha-card-body">
                <div class="public-signature-help">
                    <strong>La huella debe verse marcada en papel.</strong>
                    <ul>
                        <li>Marca tu dedo con tinta y coloca la huella en una hoja blanca.</li>
                        <li>Toma una foto clara, con buena luz y enfocada.</li>
                        <li>No subas una foto del dedo; debe verse la huella impresa en el papel.</li>
                    </ul>
                </div>
                @if($huellaArchivo && isset($ingreso))
                    <div class="ingreso-file-row">
                        <span>{{ $huellaArchivo->nombre_original ?: 'Huella cargada' }}</span>
                        <a class="btn btn-outline btn-sm" href="{{ $downloadRoute($huellaArchivo) }}">Descargar</a>
                    </div>
                @endif
                @if(!$readonly)
                    <input class="ficha-input" type="file" name="huella" accept="image/*" capture="environment" {{ $formMode === 'public' && !$huellaArchivo ? 'required' : '' }}>
                    @error('huella') <span class="ficha-error">{{ $message }}</span> @enderror
                @elseif(!$huellaArchivo)
                    <div class="ficha-input ingreso-readonly">Sin huella registrada</div>
                @endif
            </div>
        </section>
    @endif
</div>

@if(!$readonly)
    @once
        @push('scripts')
            @include('personal.fichas.partials.conditional-fields-script')
            <script>
        document.addEventListener('DOMContentLoaded', function () {
            const normalizeUppercase = function (el) {
                if (el && typeof el.value === 'string') {
                    const upper = el.value.toLocaleUpperCase('es-PE');
                    if (el.value !== upper) {
                        el.value = upper;
                    }
                }
            };

            document.addEventListener('blur', function (event) {
                if (event.target && event.target.matches && event.target.matches('[data-uppercase="1"]')) {
                    normalizeUppercase(event.target);
                }
            }, true);

            document.querySelectorAll('[data-add-family]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const body = button.closest('.ficha-section')?.querySelector('[data-family-body]');
                    if (!body) return;
                    let maxIndex = -1;
                    body.querySelectorAll('[name*="familiares["]').forEach(function (el) {
                        const m = el.name.match(/familiares\[(\d+)\]/);
                        if (m) {
                            maxIndex = Math.max(maxIndex, parseInt(m[1], 10));
                        }
                    });
                    const index = maxIndex + 1;
                    const row = document.createElement('tr');
                    row.setAttribute('data-family-row', '');
                    row.className = 'ficha-family-row-card';
                    row.innerHTML = `
                        <td data-label="Parentesco"><input class="ficha-input" name="familiares[${index}][parentesco]" data-uppercase="1" placeholder="Ej: Conyuge, Hijo(a)"></td>
                        <td data-label="Apellidos y nombres"><input class="ficha-input" name="familiares[${index}][nombres_apellidos]" data-uppercase="1" placeholder="Nombres completos"></td>
                        <td data-label="Fecha de nacimiento"><input class="ficha-input" type="date" name="familiares[${index}][fecha_nacimiento]"></td>
                        <td data-label="N° Documento (DNI)"><input type="hidden" name="familiares[${index}][tipo_documento]" value="DNI"><input class="ficha-input" name="familiares[${index}][numero_documento]" inputmode="numeric" placeholder="8 digitos"></td>
                        <td data-label="Telefono"><input class="ficha-input" type="tel" inputmode="tel" name="familiares[${index}][telefono]" placeholder="9 digitos"></td>
                        <td data-label="Vive conmigo" class="ficha-family-check-cell" style="text-align:center;">
                            <label class="ficha-check-label">
                                <input type="checkbox" name="familiares[${index}][vive_con_trabajador]" value="1">
                                <span class="ficha-family-check-text">Vive conmigo</span>
                            </label>
                        </td>
                        <td data-label="Estudia" class="ficha-family-check-cell" style="text-align:center;">
                            <label class="ficha-check-label">
                                <input type="checkbox" name="familiares[${index}][estudia]" value="1">
                                <span class="ficha-family-check-text">Estudia</span>
                            </label>
                        </td>
                        <td class="ficha-family-action-cell">
                            <button type="button" class="btn btn-outline btn-sm btn-remove-family" data-remove-family>
                                <span class="desktop-del-icon">X</span>
                                <span class="mobile-del-label">Eliminar familiar</span>
                            </button>
                        </td>
                    `;
                    body.appendChild(row);
                });
            });

            document.addEventListener('click', function (event) {
                const button = event.target.closest('[data-remove-family]');
                if (button) {
                    button.closest('[data-family-row]')?.remove();
                }
            });

            document.querySelectorAll('[data-signature-pad]').forEach(function (canvas) {
                const wrap = canvas.closest('.signature-pad-wrap');
                const input = wrap?.querySelector('[data-signature-input]');
                const clear = wrap?.querySelector('[data-clear-signature]');
                const errorBox = wrap?.querySelector('[data-signature-error]');
                if (!input) return;
                const ctx = canvas.getContext('2d');
                let drawing = false;
                let hasSignature = Boolean(input.value);
                let lastWidth = 0;
                let lastHeight = 0;

                const clearError = function () {
                    canvas.classList.remove('is-invalid');
                    if (errorBox) errorBox.style.display = 'none';
                };

                const showError = function () {
                    canvas.classList.add('is-invalid');
                    if (errorBox) errorBox.style.display = 'block';
                };

                const validateSignature = function () {
                    const isRequired = input.dataset.required === '1' || canvas.dataset.required === '1';
                    if (isRequired && !hasSignature && !input.value) {
                        showError();
                        canvas.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false;
                    }
                    clearError();
                    return true;
                };

                const form = canvas.closest('form');
                if (form) {
                    form._validateSignature = validateSignature;
                }

                const resize = function () {
                    const rect = canvas.getBoundingClientRect();
                    const w = Math.round(rect.width);
                    const h = Math.round(rect.height) || 220;

                    if (w <= 0 || h <= 0) return;

                    // Do not wipe canvas when virtual keyboard opens/closes
                    if (w === lastWidth && h === lastHeight) {
                        return;
                    }

                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const currentData = input.value || (hasSignature ? canvas.toDataURL('image/png') : null);

                    lastWidth = w;
                    lastHeight = h;

                    canvas.width = w * ratio;
                    canvas.height = h * ratio;
                    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    ctx.lineWidth = 2.4;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#0f172a';

                    if (currentData) {
                        const img = new Image();
                        img.onload = function () {
                            ctx.drawImage(img, 0, 0, w, h);
                        };
                        img.src = currentData;
                    }
                };

                const point = function (event) {
                    const rect = canvas.getBoundingClientRect();
                    return {
                        x: event.clientX - rect.left,
                        y: event.clientY - rect.top
                    };
                };

                const start = function (event) {
                    event.preventDefault();
                    clearError();
                    if (event.pointerId !== undefined) {
                        try {
                            canvas.setPointerCapture(event.pointerId);
                        } catch (e) {}
                    }
                    drawing = true;
                    hasSignature = true;
                    const p = point(event);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                };

                const move = function (event) {
                    if (!drawing) return;
                    event.preventDefault();
                    const p = point(event);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                };

                const stop = function (event) {
                    if (!drawing) return;
                    event.preventDefault();
                    drawing = false;
                    if (event.pointerId !== undefined) {
                        try {
                            if (canvas.hasPointerCapture && canvas.hasPointerCapture(event.pointerId)) {
                                canvas.releasePointerCapture(event.pointerId);
                            }
                        } catch (e) {}
                    }
                    input.value = canvas.toDataURL('image/png');
                    clearError();
                };

                canvas.addEventListener('pointerdown', start);
                canvas.addEventListener('pointermove', move);
                canvas.addEventListener('pointerup', stop);
                canvas.addEventListener('pointercancel', stop);

                clear?.addEventListener('click', function () {
                    ctx.save();
                    ctx.setTransform(1, 0, 0, 1, 0, 0);
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.restore();
                    input.value = '';
                    hasSignature = false;
                    clearError();
                });

                window.addEventListener('resize', resize);
                window.addEventListener('orientationchange', function () {
                    setTimeout(resize, 120);
                });
                resize();

                form?.addEventListener('submit', function (event) {
                    if (!validateSignature()) {
                        event.preventDefault();
                    }
                });
            });

            document.querySelectorAll('form[data-ingreso-submit]').forEach(function (form) {
                form.addEventListener('submit', function () {
                    form.querySelectorAll('[data-uppercase="1"]').forEach(normalizeUppercase);
                });
            });
        });
            </script>
        @endpush
    @endonce
@endif
