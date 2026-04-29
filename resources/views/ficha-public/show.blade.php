@extends('layouts.public')

@section('title', 'Ficha del colaborador - Proserge')

@section('content')
<div class="public-ficha-container ficha-workspace">
    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h1 class="ficha-card-title">Ficha del colaborador</h1>
                <p class="ficha-card-subtitle">
                    @if($ficha)
                        {{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}
                    @else
                        Link no disponible
                    @endif
                </p>
            </div>
            @if($mode === 'edit')
                <span class="ficha-status ficha-status-pending">Pendiente</span>
            @elseif($mode === 'readonly')
                <span class="ficha-status ficha-status-sent">Enviada</span>
            @elseif(in_array($mode, ['expired', 'disabled', 'invalid'], true))
                <span class="ficha-status ficha-status-expired">No disponible</span>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    @if(in_array($mode, ['invalid', 'expired', 'disabled'], true))
        <div class="ficha-card">
            <div class="ficha-card-body">
                <div class="ficha-alert ficha-alert-danger">
                    Este link no esta habilitado. Solicita a RRHH un nuevo enlace si necesitas completar la ficha.
                </div>
            </div>
        </div>
    @else
        @php
            $readonly = $mode !== 'edit';
            $familyRows = old('familiares');
            if (!is_array($familyRows)) {
                $familyRows = $familiares->count() > 0
                    ? $familiares->map(fn ($item) => [
                        'nombres_apellidos' => $item->nombres_apellidos,
                        'parentesco' => $item->parentesco,
                        'fecha_nacimiento' => optional($item->fecha_nacimiento)->toDateString(),
                        'tipo_documento' => $item->tipo_documento,
                        'numero_documento' => $item->numero_documento,
                        'telefono' => $item->telefono,
                        'vive_con_trabajador' => $item->vive_con_trabajador,
                        'contacto_emergencia' => $item->contacto_emergencia,
                    ])->values()->all()
                    : collect(['Padre', 'Madre', 'Conyuge'])->map(fn ($parentesco) => [
                        'nombres_apellidos' => '',
                        'parentesco' => $parentesco,
                        'fecha_nacimiento' => '',
                        'tipo_documento' => 'DNI',
                        'numero_documento' => '',
                        'telefono' => '',
                        'vive_con_trabajador' => false,
                        'contacto_emergencia' => false,
                    ])->all();
            }
            $currentFieldValue = fn (string $key): string => (string) old('fields.' . $key, $data[$key] ?? '');
        @endphp

        <form method="POST" action="{{ route('ficha-colaborador.submit', ['token' => $token]) }}" enctype="multipart/form-data" class="ficha-workspace" id="workerFichaForm">
            @csrf

            <div class="ficha-card">
                <div class="ficha-card-body">
                    @foreach($sections as $section)
                        <section class="ficha-section">
                            <div class="ficha-section-header">
                                <h2 class="ficha-section-title">{{ $section['title'] }}</h2>
                            </div>
                            @if(($section['key'] ?? '') === 'quinta_categoria')
                                <div class="ficha-card-body" style="padding-bottom:0;">
                                    <div class="ficha-alert" style="background:#fff;border-color:#e2e8f0;color:#334155;">
                                        Por la presente cumplo con informar cual es mi empleador principal para la retencion de Quinta Categoria. Si P&S PROSERGE S.R.L. es mi empleador principal, declaro si percibo o no otras remuneraciones y, de corresponder, registro los otros empleadores en la tabla inferior.
                                    </div>
                                </div>
                            @endif
                            <div class="ficha-fields">
                                @foreach($section['fields'] as $field)
                                    @php
                                        $key = $field['key'];
                                        $type = $field['type'];
                                        $value = old('fields.' . $key, $data[$key] ?? '');
                                        $locked = (bool) ($field['locked_public'] ?? false);
                                        $fieldReadonly = $readonly || $locked;
                                        $isTextarea = $type === 'textarea';
                                        $isVerify = in_array($key, $verifyFields ?? [], true);
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
                                            default => false,
                                        };
                                        $fieldDisabled = $fieldReadonly || $conditionalHidden;
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
                                            @if($isVerify && !$locked)
                                                <span class="ficha-status" style="padding:2px 7px;font-size:10px;">Verificar</span>
                                            @endif
                                        </label>

                                        @if($locked)
                                            <input type="hidden" name="fields[{{ $key }}]" value="{{ $value }}">
                                        @endif

                                        @if($type === 'select')
                                            <select class="ficha-select" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" data-current-value="{{ $value }}" {{ $fieldDisabled ? 'disabled' : '' }}>
                                                <option value="">Seleccionar</option>
                                                @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                                                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                            @if($fieldReadonly && !$locked)
                                                <input type="hidden" name="fields[{{ $key }}]" value="{{ $value }}">
                                            @endif
                                        @elseif($isTextarea)
                                            <textarea class="ficha-textarea" id="field_{{ $key }}" name="fields[{{ $key }}]" data-ficha-key="{{ $key }}" {{ $fieldReadonly ? 'readonly' : '' }} {{ (!$fieldReadonly && $conditionalHidden) ? 'disabled' : '' }}>{{ $value }}</textarea>
                                        @else
                                            <input class="ficha-input" id="field_{{ $key }}" type="{{ $type }}" name="fields[{{ $key }}]" value="{{ $value }}" data-ficha-key="{{ $key }}" {{ $fieldReadonly ? 'readonly' : '' }} {{ (!$fieldReadonly && $conditionalHidden) ? 'disabled' : '' }}>
                                        @endif

                                        @error('fields.' . $key)
                                            <span class="ficha-error">{{ $message }}</span>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Remuneraciones percibidas de otros empleadores</h2>
                            @if(!$readonly)
                                <button type="button" class="btn btn-outline btn-sm" id="addEmployerBtn">Agregar empleador</button>
                            @endif
                        </div>
                        <div class="ficha-card-body">
                            <div class="ficha-batch-table-wrap">
                                <table class="ficha-batch-table">
                                    <thead>
                                        <tr>
                                            <th>Empresa</th>
                                            <th>RUC</th>
                                            <th>Monto anual</th>
                                            <th>Retencion</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="quintaEmployersBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Familiares o contactos de emergencia</h2>
                            @if(!$readonly)
                                <button type="button" class="btn btn-outline btn-sm" id="addFamilyBtn">Agregar familiar</button>
                            @endif
                        </div>
                        <div class="ficha-card-body">
                            <div class="ficha-family-list" id="familyList">
                                <div class="ficha-batch-table-wrap">
                                    <table class="ficha-batch-table ficha-family-table">
                                        <thead>
                                            <tr>
                                                <th>Parentesco</th>
                                                <th>Apellidos y nombres</th>
                                                <th>Fecha nacimiento</th>
                                                <th>Vive conmigo</th>
                                                <th>Telefono</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="familyTableBody">
                                            @foreach($familyRows as $index => $familiar)
                                                <tr data-family-item>
                                                    <td><input class="ficha-input" name="familiares[{{ $index }}][parentesco]" value="{{ $familiar['parentesco'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
                                                    <td>
                                                        <input class="ficha-input" name="familiares[{{ $index }}][nombres_apellidos]" value="{{ $familiar['nombres_apellidos'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}>
                                                        <input type="hidden" name="familiares[{{ $index }}][tipo_documento]" value="{{ $familiar['tipo_documento'] ?? 'DNI' }}">
                                                        <input type="hidden" name="familiares[{{ $index }}][numero_documento]" value="{{ $familiar['numero_documento'] ?? '' }}">
                                                        <input type="hidden" name="familiares[{{ $index }}][contacto_emergencia]" value="{{ ($familiar['contacto_emergencia'] ?? false) ? '1' : '0' }}">
                                                    </td>
                                                    <td><input class="ficha-input" type="date" name="familiares[{{ $index }}][fecha_nacimiento]" value="{{ $familiar['fecha_nacimiento'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
                                                    <td style="text-align:center;"><input type="checkbox" name="familiares[{{ $index }}][vive_con_trabajador]" value="1" @checked((bool) ($familiar['vive_con_trabajador'] ?? false)) {{ $readonly ? 'disabled' : '' }}></td>
                                                    <td><input class="ficha-input" name="familiares[{{ $index }}][telefono]" value="{{ $familiar['telefono'] ?? '' }}" {{ $readonly ? 'readonly' : '' }}></td>
                                                    <td>@if(!$readonly)<button type="button" class="btn btn-outline btn-sm" data-remove-family aria-label="Eliminar familiar">X</button>@endif</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            @error('familiares') <span class="ficha-error">{{ $message }}</span> @enderror
                        </div>
                    </section>

                    <section class="ficha-section">
                        <div class="ficha-section-header">
                            <h2 class="ficha-section-title">Documentos requeridos</h2>
                        </div>
                        <div class="ficha-card-body">
                            <div class="ficha-fields" style="padding:0;">
                                @foreach($documentRequirements as $docKey => $requirement)
                                    @php
                                        $storedDoc = ($archivos ?? collect())->firstWhere('tipo', $docKey);
                                        $docLabel = $requirement['label'] ?? $requirement;
                                        $docRequired = (bool) ($requirement['required'] ?? true);
                                    @endphp
                                    <div class="ficha-field ficha-field-wide">
                                        <label class="ficha-label" for="documento_{{ $docKey }}">
                                            {{ $docLabel }}
                                            @if(!$readonly && $docRequired)
                                                <span class="ficha-required">*</span>
                                            @elseif(!$readonly)
                                                <span class="ficha-status" style="padding:2px 7px;font-size:10px;">Si aplica</span>
                                            @endif
                                        </label>
                                        @if($readonly)
                                            <div class="ficha-input" style="height:auto;min-height:42px;">
                                                {{ $storedDoc?->nombre_original ?: 'Documento registrado' }}
                                            </div>
                                        @else
                                            <input id="documento_{{ $docKey }}" class="ficha-input" type="file" name="documentos[{{ $docKey }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" {{ $docRequired ? 'required' : '' }}>
                                            @error('documentos.' . $docKey) <span class="ficha-error">{{ $message }}</span> @enderror
                                        @endif
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
                            <div class="ficha-workspace" style="gap:10px;">
                                @foreach($declarationCheckboxes as $declarationKey => $declarationLabel)
                                    @if($readonly)
                                        <div class="ficha-input" style="height:auto;min-height:42px;line-height:1.5;">{{ $declarationLabel }}</div>
                                    @else
                                        <label class="ficha-check ficha-declaration-check" style="align-items:flex-start;line-height:1.5;">
                                            <input type="checkbox" name="declaraciones[{{ $declarationKey }}]" value="1" @checked(old('declaraciones.' . $declarationKey)) required>
                                            <span>{{ $declarationLabel }}</span>
                                        </label>
                                        @error('declaraciones.' . $declarationKey) <span class="ficha-error">{{ $message }}</span> @enderror
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
                            @if($readonly && $firmaBase64)
                                <img class="ficha-preview-image" src="{{ $firmaBase64 }}" alt="Firma digital">
                            @else
                                <div class="signature-pad-wrap">
                                    <canvas id="signaturePad" class="signature-pad"></canvas>
                                    <input type="hidden" name="firma_base64" id="firmaBase64" value="{{ old('firma_base64') }}">
                                    <div class="ficha-actions-bar" style="justify-content:flex-start;">
                                        <button type="button" class="btn btn-outline btn-sm" id="clearSignature">Limpiar firma</button>
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
                            @if($readonly && $huellaDataUrl)
                                <img class="ficha-preview-image" src="{{ $huellaDataUrl }}" alt="Huella digital">
                            @else
                                <div class="ficha-fields" style="padding:0;">
                                    <div class="ficha-field ficha-field-wide">
                                        <label class="ficha-label" for="huella">Foto de huella <span class="ficha-required">*</span></label>
                                        <input id="huella" class="ficha-input" type="file" name="huella" accept="image/*" capture="environment">
                                        @error('huella') <span class="ficha-error">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="ficha-field">
                                        <img id="huellaPreview" class="ficha-preview-image" style="display:none;" alt="Previsualizacion de huella">
                                    </div>
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            </div>

            @if(!$readonly)
                <div class="ficha-actions-bar">
                    <button type="submit" class="btn btn-primary">Enviar ficha</button>
                </div>
            @endif
        </form>
    @endif
</div>
@endsection

@push('scripts')
@if(in_array($mode, ['edit', 'readonly'], true))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const isReadonly = @json($mode !== 'edit');
    const canvas = document.getElementById('signaturePad');
    const hidden = document.getElementById('firmaBase64');
    const clearBtn = document.getElementById('clearSignature');
    let drawing = false;
    let hasSignature = Boolean(hidden && hidden.value);

    if (canvas && hidden) {
        const ctx = canvas.getContext('2d');

        const resizeCanvas = function () {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const rect = canvas.getBoundingClientRect();
            const current = hasSignature ? hidden.value : null;
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2.4;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
            if (current) {
                const img = new Image();
                img.onload = () => ctx.drawImage(img, 0, 0, rect.width, rect.height);
                img.src = current;
            }
        };

        const point = function (event) {
            const rect = canvas.getBoundingClientRect();
            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };
        };

        canvas.addEventListener('pointerdown', function (event) {
            drawing = true;
            hasSignature = true;
            canvas.setPointerCapture(event.pointerId);
            const p = point(event);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        });

        canvas.addEventListener('pointermove', function (event) {
            if (!drawing) return;
            const p = point(event);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hidden.value = canvas.toDataURL('image/png');
        });

        const stop = function () {
            if (!drawing) return;
            drawing = false;
            hidden.value = canvas.toDataURL('image/png');
        };

        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        window.addEventListener('resize', resizeCanvas);

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hidden.value = '';
                hasSignature = false;
            });
        }

        resizeCanvas();
    }

    const huella = document.getElementById('huella');
    const preview = document.getElementById('huellaPreview');
    if (huella && preview) {
        huella.addEventListener('change', function () {
            const file = huella.files && huella.files[0];
            if (!file) {
                preview.style.display = 'none';
                preview.removeAttribute('src');
                return;
            }
            preview.src = URL.createObjectURL(file);
            preview.style.display = 'block';
        });
    }

    const byKey = (key) => document.querySelector('[data-ficha-key="' + key + '"]');
    const fieldWrap = (key) => document.querySelector('[data-ficha-field="' + key + '"]');
    const setVisible = function (key, visible) {
        const node = fieldWrap(key);
        if (node) node.style.display = visible ? '' : 'none';
    };
    const setEnabled = function (key, enabled) {
        const input = byKey(key);
        if (!input) return;
        if (isReadonly) return;
        input.disabled = !enabled;
        if (!enabled && input.tagName !== 'SELECT') input.value = '';
    };

    let ubigeo = {};
    const loadUbigeo = async function () {
        try {
            const response = await fetch(@json(asset('data/ubigeo-peru.json')), { cache: 'force-cache' });
            const payload = await response.json();
            ubigeo = payload.data || {};
        } catch (error) {
            ubigeo = {};
        }
    };

    const fillSelect = function (select, values, current) {
        if (!select) return;
        const selected = current || select.dataset.currentValue || select.value;
        const matched = values.find(value => String(value).toUpperCase() === String(selected).toUpperCase()) || selected;
        select.innerHTML = '<option value="">Seleccionar</option>' + values.map(v => '<option value="' + String(v).replace(/"/g, '&quot;') + '">' + v + '</option>').join('');
        if (matched) select.value = matched;
        select.dataset.currentValue = select.value;
    };
    const bindUbigeo = function (prefix) {
        const dep = byKey(prefix + '_departamento') || byKey('departamento_' + prefix);
        const prov = byKey(prefix + '_provincia') || byKey('provincia_' + prefix);
        const dist = byKey(prefix + '_distrito') || byKey('distrito_' + prefix);
        fillSelect(dep, Object.keys(ubigeo));
        const updateProv = function () {
            fillSelect(prov, Object.keys(ubigeo[dep.value] || {}));
            updateDist();
        };
        const updateDist = function () {
            fillSelect(dist, ubigeo[dep.value]?.[prov.value] || []);
        };
        dep?.addEventListener('change', updateProv);
        prov?.addEventListener('change', updateDist);
        updateProv();
    };
    const applyConditionals = function () {
        const estadoCivilOtro = byKey('estado_civil')?.value === 'Otro';
        setVisible('estado_civil_otro', estadoCivilOtro);
        setEnabled('estado_civil_otro', estadoCivilOtro);

        const nacionalidadOtra = byKey('nacionalidad')?.value === 'Otra';
        setVisible('nacionalidad_otra', nacionalidadOtra);
        setEnabled('nacionalidad_otra', nacionalidadOtra);

        const nacimientoPais = byKey('pais_nacimiento')?.value || 'Peru';
        const nacimientoPeru = nacimientoPais === 'Peru';
        setVisible('pais_nacimiento_otro', !nacimientoPeru);
        setVisible('departamento_nacimiento', nacimientoPeru);
        setVisible('provincia_nacimiento', nacimientoPeru);
        setVisible('distrito_nacimiento', nacimientoPeru);
        setVisible('lugar_nacimiento_extranjero', !nacimientoPeru);
        setEnabled('pais_nacimiento_otro', !nacimientoPeru);
        setEnabled('lugar_nacimiento_extranjero', !nacimientoPeru);
        setEnabled('departamento_nacimiento', nacimientoPeru);
        setEnabled('provincia_nacimiento', nacimientoPeru);
        setEnabled('distrito_nacimiento', nacimientoPeru);

        const domicilioPeru = byKey('domicilio_tipo')?.value !== 'Extranjero';
        setVisible('domicilio_pais_otro', !domicilioPeru);
        setVisible('domicilio_departamento', domicilioPeru);
        setVisible('domicilio_provincia', domicilioPeru);
        setVisible('domicilio_distrito', domicilioPeru);
        setVisible('domicilio_direccion', domicilioPeru);
        setVisible('domicilio_referencia', true);
        setVisible('domicilio_extranjero', !domicilioPeru);
        setEnabled('domicilio_pais_otro', !domicilioPeru);
        setEnabled('domicilio_extranjero', !domicilioPeru);
        setEnabled('domicilio_departamento', domicilioPeru);
        setEnabled('domicilio_provincia', domicilioPeru);
        setEnabled('domicilio_distrito', domicilioPeru);
        setEnabled('domicilio_direccion', domicilioPeru);
        setEnabled('domicilio_referencia', true);

        const banco = byKey('banco')?.value || '';
        const bancoConCuenta = banco === 'BCP' || banco === 'Interbank';
        setVisible('numero_cuenta', bancoConCuenta);
        setVisible('banco_otro', banco === 'Otro');
        setVisible('cci', banco === 'Otro');
        setEnabled('numero_cuenta', bancoConCuenta);
        setEnabled('banco_otro', banco === 'Otro');
        setEnabled('cci', banco === 'Otro');

        const empleadorOtro = byKey('quinta_empleador_principal')?.value === 'Otra empresa';
        setVisible('quinta_otra_empresa', empleadorOtro);
        setVisible('quinta_otra_empresa_ruc', empleadorOtro);
        setEnabled('quinta_otra_empresa', empleadorOtro);
        setEnabled('quinta_otra_empresa_ruc', empleadorOtro);

        const spp = byKey('sistema_pensionario')?.value === 'Sistema Privado de Pensiones';
        setVisible('tipo_comision', spp);
        setVisible('tipo_afp', spp);
        setVisible('cuspp', spp);
        setEnabled('tipo_comision', spp);
        setEnabled('tipo_afp', spp);
        setEnabled('cuspp', spp);
    };
    ['estado_civil', 'nacionalidad', 'pais_nacimiento', 'domicilio_tipo', 'banco', 'quinta_empleador_principal', 'sistema_pensionario'].forEach(key => byKey(key)?.addEventListener('change', applyConditionals));
    applyConditionals();

    const syncLaborDate = function () {
        const value = byKey('fecha_ingreso')?.value || '';
        if (!value) return;
        if (byKey('quinta_fecha_anio')) byKey('quinta_fecha_anio').value = String(new Date().getFullYear());
        if (byKey('quinta_fecha_mes')) byKey('quinta_fecha_mes').value = new Date().toLocaleString('es-PE', { month: 'long' });
        if (byKey('quinta_fecha_dia')) byKey('quinta_fecha_dia').value = String(new Date().getDate()).padStart(2, '0');
    };
    byKey('fecha_ingreso')?.addEventListener('change', syncLaborDate);
    syncLaborDate();

    const updateQuintaDefaults = function () {
        const now = new Date();
        const month = now.toLocaleString('es-PE', { month: 'long' });
        if (byKey('quinta_fecha_anio') && !byKey('quinta_fecha_anio').value) byKey('quinta_fecha_anio').value = String(now.getFullYear());
        if (byKey('quinta_fecha_mes') && !byKey('quinta_fecha_mes').value) byKey('quinta_fecha_mes').value = month;
        if (byKey('quinta_fecha_dia') && !byKey('quinta_fecha_dia').value) byKey('quinta_fecha_dia').value = String(now.getDate()).padStart(2, '0');

        const city = byKey('domicilio_distrito')?.value || byKey('domicilio_provincia')?.value || byKey('domicilio_departamento')?.value || 'Arequipa';
        if (byKey('quinta_ciudad') && !byKey('quinta_ciudad').value) byKey('quinta_ciudad').value = city;

        const domicilio = [
            byKey('domicilio_direccion')?.value || byKey('domicilio_extranjero')?.value || '',
            byKey('domicilio_distrito')?.value || '',
            byKey('domicilio_provincia')?.value || '',
            byKey('domicilio_departamento')?.value || '',
        ].filter(Boolean).join(' / ');
        if (byKey('quinta_domicilio') && !byKey('quinta_domicilio').value && domicilio) byKey('quinta_domicilio').value = domicilio;
    };
    ['domicilio_direccion', 'domicilio_extranjero', 'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito'].forEach(key => {
        byKey(key)?.addEventListener('change', updateQuintaDefaults);
        byKey(key)?.addEventListener('input', updateQuintaDefaults);
    });
    updateQuintaDefaults();
    loadUbigeo().then(function () {
        bindUbigeo('nacimiento');
        bindUbigeo('domicilio');
        applyConditionals();
        updateQuintaDefaults();
    });

    const employerBody = document.getElementById('quintaEmployersBody');
    const employersJson = byKey('quinta_otros_empleadores_json');
    const escapeHtml = function (value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    };
    const readEmployers = function () {
        try { return JSON.parse(employersJson?.value || '[]'); } catch (e) { return []; }
    };
    const syncEmployers = function () {
        if (!employerBody || !employersJson) return;
        const rows = Array.from(employerBody.querySelectorAll('tr')).map(row => ({
            empresa: row.querySelector('[data-employer="empresa"]')?.value || '',
            ruc: row.querySelector('[data-employer="ruc"]')?.value || '',
            monto: row.querySelector('[data-employer="monto"]')?.value || '',
            retencion: row.querySelector('[data-employer="retencion"]')?.value || '',
        })).filter(row => row.empresa || row.ruc || row.monto || row.retencion);
        employersJson.value = JSON.stringify(rows);
    };
    const addEmployerRow = function (row = {}) {
        if (!employerBody) return;
        const readonlyAttr = isReadonly ? ' readonly' : '';
        const removeButton = isReadonly ? '' : '<button type="button" class="btn btn-outline btn-sm" data-remove-employer>X</button>';
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input class="ficha-input" data-employer="empresa" value="' + escapeHtml(row.empresa || '') + '"' + readonlyAttr + '></td><td><input class="ficha-input" data-employer="ruc" value="' + escapeHtml(row.ruc || '') + '"' + readonlyAttr + '></td><td><input class="ficha-input" data-employer="monto" value="' + escapeHtml(row.monto || '') + '"' + readonlyAttr + '></td><td><input class="ficha-input" data-employer="retencion" value="' + escapeHtml(row.retencion || '') + '"' + readonlyAttr + '></td><td>' + removeButton + '</td>';
        employerBody.appendChild(tr);
    };
    readEmployers().forEach(addEmployerRow);
    if (!isReadonly && employerBody && employerBody.children.length === 0) addEmployerRow();
    document.getElementById('addEmployerBtn')?.addEventListener('click', () => addEmployerRow());
    employerBody?.addEventListener('input', syncEmployers);
    employerBody?.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-remove-employer]');
        if (!btn) return;
        btn.closest('tr')?.remove();
        if (employerBody.children.length === 0) addEmployerRow();
        syncEmployers();
    });
    document.getElementById('workerFichaForm')?.addEventListener('submit', syncEmployers);

    const list = document.getElementById('familyTableBody');
    const addBtn = document.getElementById('addFamilyBtn');

    const reindexFamilies = function () {
        Array.from(list.querySelectorAll('[data-family-item]')).forEach(function (item, index) {
            item.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace(/familiares\[\d+\]/, 'familiares[' + index + ']');
            });
        });
    };

    if (!isReadonly && addBtn && list) {
        addBtn.addEventListener('click', function () {
            const count = list.querySelectorAll('[data-family-item]').length + 1;
            const clone = document.createElement('tr');
            clone.setAttribute('data-family-item', '');
            clone.innerHTML = '<td><input class="ficha-input" name="familiares[0][parentesco]" value="Hijo ' + Math.max(count - 2, 1) + '"></td><td><input class="ficha-input" name="familiares[0][nombres_apellidos]" value=""><input type="hidden" name="familiares[0][tipo_documento]" value="DNI"><input type="hidden" name="familiares[0][numero_documento]" value=""><input type="hidden" name="familiares[0][contacto_emergencia]" value="0"></td><td><input class="ficha-input" type="date" name="familiares[0][fecha_nacimiento]" value=""></td><td style="text-align:center;"><input type="checkbox" name="familiares[0][vive_con_trabajador]" value="1"></td><td><input class="ficha-input" name="familiares[0][telefono]" value=""></td><td><button type="button" class="btn btn-outline btn-sm" data-remove-family>X</button></td>';
            list.appendChild(clone);
            reindexFamilies();
        });

        list.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-family]');
            if (!button) return;
            const items = list.querySelectorAll('[data-family-item]');
            if (items.length <= 1) return;
            button.closest('[data-family-item]').remove();
            reindexFamilies();
        });
    }
});
</script>
@endif
@endpush
