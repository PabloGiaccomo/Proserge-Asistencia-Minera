@php
    $readonly = (bool) ($readonly ?? false);
    $formMode = (string) ($formMode ?? 'public');
    $archivos = collect($archivos ?? []);
    $familiares = is_array($familiares ?? null) ? $familiares : [];
    $familyRows = old('familiares', $familiares);
    $fieldValue = fn (string $key): string => (string) old('fields.' . $key, $data[$key] ?? '');
    $firmaActual = (string) old('firma_base64', $firmaBase64 ?? '');
    $huellaArchivo = $archivos->firstWhere('tipo', 'huella');
    $downloadRoute = fn ($archivo) => isset($ingreso) && $archivo
        ? route('personal.ingresos.archivos.download', [$ingreso->id, $archivo->id])
        : '#';
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
    
@endonce

<div class="ingreso-form-stack">
    @foreach($sections as $section)
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
                        $uppercase = !in_array($type, ['email', 'hidden'], true);
                        if ($key === 'puesto') {
                            $options = [];
                        }
                        $paisNacimientoActual = $fieldValue('pais_nacimiento') ?: 'Peru';
                        $domicilioPaisActual = $fieldValue('domicilio_tipo') ?: 'Peru';
                        $bancoActual = $fieldValue('banco');
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
                            <div class="ficha-input ingreso-readonly">{{ $value !== '' ? $value : '-' }}</div>
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
                                type="{{ $type === 'tel' ? 'text' : $type }}"
                                name="fields[{{ $key }}]"
                                value="{{ $value }}"
                                data-ficha-key="{{ $key }}"
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
            <div class="ficha-batch-table-wrap">
                <table class="ficha-batch-table">
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
                            <tr data-family-row>
                                <td>
                                    @if($readonly)
                                        {{ $familiar['parentesco'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" name="familiares[{{ $index }}][parentesco]" value="{{ $familiar['parentesco'] ?? '' }}" data-uppercase="1">
                                    @endif
                                </td>
                                <td>
                                    @if($readonly)
                                        {{ $familiar['nombres_apellidos'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" name="familiares[{{ $index }}][nombres_apellidos]" value="{{ $familiar['nombres_apellidos'] ?? '' }}" data-uppercase="1">
                                    @endif
                                </td>
                                <td>
                                    @if($readonly)
                                        {{ $familiar['fecha_nacimiento'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" type="date" name="familiares[{{ $index }}][fecha_nacimiento]" value="{{ $familiar['fecha_nacimiento'] ?? '' }}">
                                    @endif
                                </td>
                                <td>
                                    @if($readonly)
                                        {{ $familiar['numero_documento'] ?? '-' }}
                                    @else
                                        <input type="hidden" name="familiares[{{ $index }}][tipo_documento]" value="{{ $familiar['tipo_documento'] ?? 'DNI' }}">
                                        <input class="ficha-input" name="familiares[{{ $index }}][numero_documento]" value="{{ $familiar['numero_documento'] ?? '' }}">
                                    @endif
                                </td>
                                <td>
                                    @if($readonly)
                                        {{ $familiar['telefono'] ?? '-' }}
                                    @else
                                        <input class="ficha-input" name="familiares[{{ $index }}][telefono]" value="{{ $familiar['telefono'] ?? '' }}">
                                    @endif
                                </td>
                                <td style="text-align:center;">
                                    @if($readonly)
                                        {{ !empty($familiar['vive_con_trabajador']) ? 'Si' : 'No' }}
                                    @else
                                        <input type="checkbox" name="familiares[{{ $index }}][vive_con_trabajador]" value="1" @checked(!empty($familiar['vive_con_trabajador']))>
                                    @endif
                                </td>
                                <td style="text-align:center;">
                                    @if($readonly)
                                        {{ !empty($familiar['estudia']) ? 'Si' : 'No' }}
                                    @else
                                        <input type="checkbox" name="familiares[{{ $index }}][estudia]" value="1" @checked(!empty($familiar['estudia']))>
                                    @endif
                                </td>
                                @if(!$readonly)
                                    <td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>
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
                            @if(!$readonly)<span class="ficha-status ficha-status-pending">Opcional</span>@endif
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
                    <canvas class="signature-pad" data-signature-pad></canvas>
                    <input type="hidden" name="firma_base64" value="{{ $firmaActual }}" data-signature-input>
                    <div class="ficha-actions-bar" style="justify-content:flex-start;">
                        <button type="button" class="btn btn-outline btn-sm" data-clear-signature>Limpiar firma</button>
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
</div>

@once
    @push('scripts')
        @include('personal.fichas.partials.conditional-fields-script')
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-uppercase="1"]').forEach(function (input) {
                input.addEventListener('input', function () {
                    const start = input.selectionStart;
                    const end = input.selectionEnd;
                    input.value = input.value.toLocaleUpperCase('es-PE');
                    if (start !== null && end !== null) {
                        input.setSelectionRange(start, end);
                    }
                });
            });

            document.querySelectorAll('[data-add-family]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const body = button.closest('.ficha-section')?.querySelector('[data-family-body]');
                    if (!body) return;
                    const index = body.querySelectorAll('[data-family-row]').length;
                    const row = document.createElement('tr');
                    row.setAttribute('data-family-row', '');
                    row.innerHTML = `
                        <td><input class="ficha-input" name="familiares[${index}][parentesco]" data-uppercase="1"></td>
                        <td><input class="ficha-input" name="familiares[${index}][nombres_apellidos]" data-uppercase="1"></td>
                        <td><input class="ficha-input" type="date" name="familiares[${index}][fecha_nacimiento]"></td>
                        <td><input type="hidden" name="familiares[${index}][tipo_documento]" value="DNI"><input class="ficha-input" name="familiares[${index}][numero_documento]"></td>
                        <td><input class="ficha-input" name="familiares[${index}][telefono]"></td>
                        <td style="text-align:center;"><input type="checkbox" name="familiares[${index}][vive_con_trabajador]" value="1"></td>
                        <td style="text-align:center;"><input type="checkbox" name="familiares[${index}][estudia]" value="1"></td>
                        <td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>
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
                const input = canvas.closest('.signature-pad-wrap')?.querySelector('[data-signature-input]');
                const clear = canvas.closest('.signature-pad-wrap')?.querySelector('[data-clear-signature]');
                if (!input) return;
                const ctx = canvas.getContext('2d');
                let drawing = false;
                let hasSignature = Boolean(input.value);

                const resize = function () {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const rect = canvas.getBoundingClientRect();
                    const current = input.value;
                    canvas.width = Math.max(rect.width, 1) * ratio;
                    canvas.height = Math.max(rect.height, 180) * ratio;
                    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    ctx.lineWidth = 2.4;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#0f172a';
                    if (current) {
                        const img = new Image();
                        img.onload = function () {
                            ctx.drawImage(img, 0, 0, rect.width, rect.height || 180);
                        };
                        img.src = current;
                    }
                };

                const point = function (event) {
                    const source = event.touches?.[0] || event.changedTouches?.[0] || event;
                    const rect = canvas.getBoundingClientRect();
                    return { x: source.clientX - rect.left, y: source.clientY - rect.top };
                };

                const start = function (event) {
                    event.preventDefault();
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
                    input.value = canvas.toDataURL('image/png');
                };
                const stop = function (event) {
                    if (!drawing) return;
                    event?.preventDefault?.();
                    drawing = false;
                    input.value = canvas.toDataURL('image/png');
                };

                canvas.addEventListener('pointerdown', start);
                canvas.addEventListener('pointermove', move);
                window.addEventListener('pointerup', stop);
                clear?.addEventListener('click', function () {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    input.value = '';
                    hasSignature = false;
                });
                window.addEventListener('resize', resize);
                resize();

                canvas.closest('form')?.addEventListener('submit', function (event) {
                    if (!hasSignature && input.hasAttribute('required')) {
                        event.preventDefault();
                    }
                });
            });

            document.querySelectorAll('form[data-ingreso-submit]').forEach(function (form) {
                form.addEventListener('submit', function () {
                    const button = form.querySelector('button[type="submit"]');
                    if (button) {
                        button.disabled = true;
                        button.textContent = button.dataset.loadingText || 'Enviando...';
                    }
                });
            });
        });
        </script>
    @endpush
@endonce
