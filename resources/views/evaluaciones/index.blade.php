@extends('layouts.app')

@section('title', 'Evaluaciones - Proserge')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/evaluations-dashboard.css') }}?v={{ filemtime(public_path('css/evaluations-dashboard.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('js/evaluations-dashboard.js') }}?v={{ filemtime(public_path('js/evaluations-dashboard.js')) }}" defer></script>
@endpush

@section('content')
@php
    $typeLabels = [
        'desempeno' => ['Evaluación diaria', 'Técnicos y personal operativo'],
        'supervisores' => ['Supervisores', 'Evaluación de supervisores'],
        'residentes' => ['Residentes', 'Seguimiento mensual'],
        'dashboard' => ['Dashboard', 'Analisis de evaluaciones'],
    ];
    $scoreCriteria = [
        'desempeno_trabajo' => 'Desempeño en el trabajo',
        'orden_limpieza' => 'Orden y limpieza',
        'seguridad_trabajo' => 'Seguridad en el trabajo',
        'compromiso' => 'Compromiso',
        'respuesta_emocional' => 'Respuesta emocional',
    ];
@endphp

<div class="evaluations-page">
    <header class="evaluations-heading">
        <div>
            <h1>Evaluaciones</h1>
            <p>Seguimiento operativo de desempeño, supervisores y residentes.</p>
        </div>
        @if($activeType === 'desempeno')
            <form method="GET" action="{{ route('evaluaciones.index') }}" class="eval-date-filter">
                <input type="hidden" name="tipo" value="desempeno">
                <label for="evaluation-date">Día de evaluación</label>
                <input id="evaluation-date" type="date" name="fecha" value="{{ $selectedDate }}" onchange="this.form.submit()">
            </form>
        @endif
        @if(\App\Support\Rbac\PermissionMatrix::userCanDirect($user, 'evaluaciones', 'administrar') && app(\App\Modules\Evaluaciones\Services\EvaluacionesHistoricalImportService::class)->isOpen())
            <a class="btn btn-outline" href="{{ route('evaluaciones.historico.importar') }}">Cargar datos históricos</a>
        @endif
    </header>

    @if($availableTypes->count() > 1)
        <nav class="eval-tabs" aria-label="Tipos de evaluación">
            @foreach($availableTypes as $type)
                <a href="{{ route('evaluaciones.index', ['tipo' => $type, 'fecha' => $selectedDate]) }}"
                   class="eval-tab {{ $activeType === $type ? 'is-active' : '' }}">
                    <strong>{{ $typeLabels[$type][0] }}</strong>
                    <span>{{ $typeLabels[$type][1] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    @if(in_array($activeType, ['supervisores', 'residentes'], true) && $canEvaluate[$activeType])
        <div class="eval-alert" role="status" style="background:#eef8f6;border:1px solid #cbe6e0;color:#175b50;overflow-wrap:anywhere">
            <strong>{{ $evaluatorName }}</strong> está evaluando.
        </div>
    @endif

    @if($errors->any())
        <div class="eval-alert eval-alert-error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @if($activeType === 'desempeno')
        @if($canEvaluate['desempeno'])
        @php($directOld = old('submission_key') !== null)
        <section class="eval-section">
            <div class="eval-section-heading">
                <div><h2>Evaluación directa</h2><p>Registra una evaluación diaria sin asistencia ni parada, indicando la mina.</p></div>
            </div>
            <details class="eval-attendance" @if($directOld) open @endif>
                <summary class="eval-attendance-heading"><strong>Nueva evaluación directa</strong><span class="eval-worker-action">Evaluar</span></summary>
                <form method="POST" action="{{ route('evaluaciones.desempeno.directa.store') }}" class="eval-form eval-score-form">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <div class="eval-form-grid eval-form-grid-3">
                        @include('evaluaciones.partials.person-search', ['name' => 'trabajador_id', 'label' => 'Persona evaluada', 'evaluationType' => 'desempeno'])
                        <div class="eval-field">
                            <label for="eval-direct-mine">Mina</label>
                            <select id="eval-direct-mine" name="mina_id" required @if($errors->has('mina_id')) aria-invalid="true" @endif>
                                <option value="">Seleccionar mina</option>
                                @foreach($directMines as $mine)
                                <option value="{{ $mine->id }}" @selected($directOld && old('mina_id') === $mine->id)>{{ $mine->nombre }}</option>
                                @endforeach
                            </select>
                            @error('mina_id')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </div>
                        <div class="eval-field">
                            <label for="eval-direct-date">Fecha</label>
                            <input id="eval-direct-date" type="date" name="fecha" value="{{ $directOld ? old('fecha', $selectedDate) : $selectedDate }}" required @if($directOld && $errors->has('fecha')) aria-invalid="true" @endif>
                            @error('fecha')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </div>
                    </div>
                    @error('trabajador_id')<small class="eval-field-error">{{ $message }}</small>@enderror

                    <div class="eval-score-grid">
                        @foreach($scoreCriteria as $field => $label)
                            <fieldset class="eval-score-field {{ $directOld && $errors->has($field) ? 'eval-has-error' : '' }}">
                                <legend>{{ $label }}</legend>
                                <div class="eval-score-options">
                                    @foreach(range(1, 4) as $score)
                                        <label><input type="radio" name="{{ $field }}" value="{{ $score }}" @checked($directOld && (string) old($field) === (string) $score) required><span>{{ $score }}</span></label>
                                    @endforeach
                                </div>
                                @if($directOld) @error($field)<small class="eval-field-error">{{ $message }}</small>@enderror @endif
                            </fieldset>
                        @endforeach
                    </div>
                    <div class="eval-total" aria-live="polite"><span>Total</span><strong data-score-total>0 / 20</strong></div>
                    <label class="eval-check"><input type="checkbox" name="tuvo_incidencia" value="1" data-incident-toggle @checked($directOld && old('tuvo_incidencia'))><span>Se registró una incidencia durante la jornada</span></label>
                    <div class="eval-field {{ $directOld && old('tuvo_incidencia') ? '' : 'is-hidden' }}" data-incident-field>
                        <label for="eval-direct-incident">Descripción de la incidencia</label>
                        <textarea id="eval-direct-incident" name="descripcion_incidencia" rows="3" @if($directOld && old('tuvo_incidencia')) required @endif @if($directOld && $errors->has('descripcion_incidencia')) aria-invalid="true" @endif>{{ $directOld ? old('descripcion_incidencia') : '' }}</textarea>
                        @if($directOld) @error('descripcion_incidencia')<small class="eval-field-error">{{ $message }}</small>@enderror @endif
                    </div>
                    <div class="eval-field"><label for="eval-direct-notes">Observaciones</label><textarea id="eval-direct-notes" name="observaciones" rows="3">{{ $directOld ? old('observaciones') : '' }}</textarea>@if($directOld) @error('observaciones')<small class="eval-field-error">{{ $message }}</small>@enderror @endif</div>
                    <div class="eval-actions"><button type="button" class="btn btn-outline" data-eval-cancel>Cancelar</button><button type="submit" class="btn btn-primary">Guardar evaluación</button></div>
                </form>
            </details>
        </section>
        @endif

        <section class="eval-section">
            <div class="eval-section-heading">
                <div>
                    <h2>Evaluaciones pendientes del día</h2>
                    <p>{{ $canEvaluateOthers ? 'Asistencias pendientes de evaluación, incluidas las registradas por otros responsables.' : 'Asistencias que registraste como encargado.' }} Consulta quién ya fue evaluado y qué asistencias aún no están listas.</p>
                </div>
                <span class="eval-count">{{ $dailyPending->sum(fn ($group) => $group['workers']->where('status', 'available')->count()) }} pendientes</span>
            </div>

            @if(!$user->personal_id)
                <div class="eval-empty">Para evaluar desde una asistencia, tu cuenta debe estar vinculada a Personal. Puedes usar la evaluación directa.</div>
            @elseif($dailyPending->isEmpty())
                <div class="eval-empty">
                    <strong>No hay asistencias para esta fecha.</strong>
                    <span>El personal aparecerá aquí cuando se registre su asistencia.</span>
                </div>
            @else
                <div class="eval-attendance-list">
                    @foreach($dailyPending as $group)
                        @php($attendance = $group['attendance'])
                        <article class="eval-attendance">
                            <div class="eval-attendance-heading">
                                <div>
                                    <h3>{{ $attendance->mina?->nombre ?? 'Mina sin identificar' }}</h3>
                                    <p>{{ $attendance->grupoTrabajo?->servicio ?? 'Servicio' }} &middot; {{ $attendance->grupoTrabajo?->area ?? 'Área sin definir' }} &middot; {{ ucfirst(strtolower($attendance->grupoTrabajo?->turno ?? '')) }}</p>
                                </div>
                                <div class="eval-week">
                                    <span>Semana {{ $group['week'] }}</span>
                                    <small>{{ $group['week_range'] }}</small>
                                </div>
                            </div>

                            <div class="eval-worker-list">
                                @foreach($group['workers'] as $worker)
                                    @php($detail = $worker['detail'])
                                    @php($canOpen = $worker['status'] === 'available')
                                    @if($canOpen)
                                    <details class="eval-worker" {{ old('asistencia_detalle_id', request('asistencia_detalle_id')) === $detail->id ? 'open' : '' }}>
                                        <summary>
                                    @else
                                    <div class="eval-worker"><div class="eval-worker-static">
                                    @endif
                                            <span>
                                                <strong>{{ $detail->trabajador?->nombre_completo ?? 'Trabajador no disponible' }}</strong>
                                                <small>{{ $detail->trabajador?->puesto ?: 'Puesto sin definir' }} &middot; {{ $detail->estado }}</small>
                                            </span>
                                            @if($canOpen)
                                                <span class="eval-worker-action">Evaluar</span>
                                            @else
                                                <span class="eval-worker-status">{{ $worker['status'] === 'evaluated' ? 'Evaluado' : 'No evaluable' }}</span>
                                            @endif
                                    @if($canOpen)
                                        </summary>
                                            <form method="POST" action="{{ route('evaluaciones.desempeno.store') }}" class="eval-form eval-score-form">
                                                @csrf
                                                <input type="hidden" name="asistencia_detalle_id" value="{{ $detail->id }}">

                                                <div class="eval-context-row">
                                                    <span><b>Evalua:</b> {{ $user->personal?->nombre_completo ?? session('user.name', session('user.email')) }}</span>
                                                    <span><b>Fecha:</b> {{ $attendance->fecha->format('d/m/Y') }}</span>
                                                    <span><b>Mina:</b> {{ $attendance->mina?->nombre ?? '-' }}</span>
                                                </div>

                                                <div class="eval-score-grid">
                                                    @foreach($scoreCriteria as $field => $label)
                                                        <fieldset class="eval-score-field {{ $errors->has($field) && old('asistencia_detalle_id') === $detail->id ? 'eval-has-error' : '' }}">
                                                            <legend>{{ $label }}</legend>
                                                            <div class="eval-score-options">
                                                                @foreach(range(1, 4) as $score)
                                                                    <label>
                                                                        <input type="radio" name="{{ $field }}" value="{{ $score }}" @checked((string) old('asistencia_detalle_id') === (string) $detail->id && (string) old($field) === (string) $score) required>
                                                                        <span>{{ $score }}</span>
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                            @if(old('asistencia_detalle_id') === $detail->id)
                                                                @error($field)<small class="eval-field-error">{{ $message }}</small>@enderror
                                                            @endif
                                                        </fieldset>
                                                    @endforeach
                                                </div>

                                                <div class="eval-total" aria-live="polite"><span>Total</span><strong data-score-total>0 / 20</strong></div>

                                                <label class="eval-check">
                                                    <input type="checkbox" name="tuvo_incidencia" value="1" data-incident-toggle @checked(old('asistencia_detalle_id') === $detail->id && old('tuvo_incidencia'))>
                                                    <span>Se registró una incidencia durante la jornada</span>
                                                </label>
                                                <div class="eval-field {{ old('asistencia_detalle_id') === $detail->id && old('tuvo_incidencia') ? '' : 'is-hidden' }}" data-incident-field>
                                                    <label for="incidencia-{{ $detail->id }}">Descripción de la incidencia</label>
                                                    <textarea id="incidencia-{{ $detail->id }}" name="descripcion_incidencia" rows="3" placeholder="Describe qué ocurrió y las acciones tomadas" @if(old('asistencia_detalle_id') === $detail->id && old('tuvo_incidencia')) required @endif @if($errors->has('descripcion_incidencia') && old('asistencia_detalle_id') === $detail->id) aria-invalid="true" @endif>{{ old('asistencia_detalle_id') === $detail->id ? old('descripcion_incidencia') : '' }}</textarea>
                                                    @if(old('asistencia_detalle_id') === $detail->id)
                                                        @error('descripcion_incidencia')<small class="eval-field-error">{{ $message }}</small>@enderror
                                                    @endif
                                                </div>
                                                <div class="eval-field">
                                                    <label>Observaciones</label>
                                                    <textarea name="observaciones" rows="3" placeholder="Comentario adicional sobre el desempeño" @if($errors->has('observaciones') && old('asistencia_detalle_id') === $detail->id) aria-invalid="true" @endif>{{ old('asistencia_detalle_id') === $detail->id ? old('observaciones') : '' }}</textarea>
                                                    @if(old('asistencia_detalle_id') === $detail->id)
                                                        @error('observaciones')<small class="eval-field-error">{{ $message }}</small>@enderror
                                                    @endif
                                                </div>

                                                <div class="eval-actions">
                                                    <button type="button" class="btn btn-outline" data-eval-cancel>Cancelar</button>
                                                    <button type="submit" class="btn btn-primary">Guardar evaluación</button>
                                                </div>
                                            </form>
                                    </details>
                                    @else
                                    </div></div>
                                    @endif
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="eval-section">
            <div class="eval-section-heading">
                <div><h2>Historial reciente</h2><p>Últimas evaluaciones diarias registradas.</p></div>
            </div>
            @include('evaluaciones.partials.daily-history', ['items' => $dailyHistory])
        </section>
    @elseif($activeType === 'supervisores')
        @if($canEvaluate['supervisores'])
            <section class="eval-section">
                <div class="eval-section-heading">
                    <div><h2>Nueva evaluación de supervisor</h2><p>Evaluación por unidad minera, sin requerir asignación a una parada.</p></div>
                </div>
                <form method="POST" action="{{ route('evaluaciones.supervisores.store') }}" class="eval-form">
                    @csrf
                    <div class="eval-form-grid eval-form-grid-3">
                        <div class="eval-field"><label>Unidad minera</label><select name="mina_id" required data-mine-select><option value="">Seleccionar mina</option>@foreach($mines as $mine)<option value="{{ $mine->id }}">{{ $mine->nombre }}</option>@endforeach</select></div>
                        @include('evaluaciones.partials.person-search', ['name' => 'evaluado_id', 'label' => 'Supervisor evaluado', 'evaluationType' => 'supervisores'])
                        <div class="eval-field"><label>Fecha</label><input type="date" name="fecha" value="{{ now()->toDateString() }}" required></div>
                    </div>

                    <div class="eval-template">
                        @foreach($supervisorTemplate as $section => $items)
                            <details {{ $loop->first ? 'open' : '' }}>
                                <summary>Bloque {{ $section }}: {{ $supervisorSectionTitles[$section] }} <span>{{ count($items) }} criterios</span></summary>
                                <div class="eval-template-items">
                                    @foreach($items as $key => $label)
                                        <label class="eval-template-item"><span><b>{{ $key }}</b> {{ $label }}</span><select name="respuestas[{{ $key }}]" required><option value="">-</option>@foreach(range(1, 5) as $score)<option value="{{ $score }}">{{ $score }}</option>@endforeach</select></label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>

                    <div class="eval-form-grid">
                        <div class="eval-field"><label>Aspectos positivos</label><textarea name="aspectos_positivos" rows="3"></textarea></div>
                        <div class="eval-field"><label>Capacitaciones recomendadas</label><textarea name="capacitaciones_recomendadas" rows="3"></textarea></div>
                    </div>
                    <div class="eval-field"><label>Comentarios finales</label><textarea name="comentarios_finales" rows="3"></textarea></div>
                    <div class="eval-actions"><button class="btn btn-primary" type="submit">Guardar evaluación</button></div>
                </form>
            </section>
        @endif

        <section class="eval-section">
            <div class="eval-section-heading"><div><h2>Evaluaciones de supervisores</h2><p>Registros disponibles dentro de tu alcance.</p></div></div>
            @include('evaluaciones.partials.supervisor-history', ['items' => $supervisorHistory])
        </section>
    @elseif($activeType === 'dashboard')
        @include('evaluaciones.partials.dashboard')
    @elseif($activeType === 'residentes')
        @if($canEvaluate['residentes'])
            <section class="eval-section">
                <div class="eval-section-heading"><div><h2>Nueva evaluación mensual</h2><p>Seguimiento consolidado del residente por periodo.</p></div></div>
                <form method="GET" action="{{ route('evaluaciones.residentes') }}" class="eval-form">
                    <div class="eval-form-grid">
                        @include('evaluaciones.partials.person-search', ['name' => 'residente_id', 'label' => 'Residente evaluado', 'evaluationType' => 'residentes', 'selectedId' => $residentSelection['id']])
                        <div class="eval-field"><label for="resident-month">Mes evaluado</label><input id="resident-month" type="month" name="periodo_mes" value="{{ old('periodo_mes', $residentSelection['month'] ?: now()->format('Y-m')) }}" required></div>
                    </div>
                    <div class="eval-actions"><button class="btn btn-primary" type="submit">Continuar</button></div>
                </form>
                @if($residentAvailability && !$residentAvailability['ok'])
                    <div class="eval-alert" role="status">{{ $residentAvailability['message'] }}</div>
                @elseif($residentAvailability && $residentAvailability['ok'])
                <form method="POST" action="{{ route('evaluaciones.residentes.store') }}" class="eval-form eval-resident-form">
                    @csrf
                    <input type="hidden" name="residente_id" value="{{ $residentSelection['id'] }}">
                    <input type="hidden" name="periodo_mes" value="{{ $residentSelection['month'] }}">
                    <div class="eval-resident-grid" data-resident-evaluation>
                        <fieldset class="eval-choice-panel" data-resident-multi>
                            <legend>Indicadores KPIs</legend>
                            <p>Marca los reportes o entregables cumplidos.</p>
                            <div class="eval-check-list">
                                @foreach($residentTemplate['kpis'] as $value => $label)
                                    <label><input type="checkbox" name="indicadores_kpi_items[]" value="{{ $value }}" @checked(in_array($value, old('indicadores_kpi_items', []), true)) {{ $value === 'NINGUNO' ? 'data-none-option' : '' }}><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            @error('indicadores_kpi_items')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </fieldset>

                        <fieldset class="eval-choice-panel" data-resident-multi>
                            <legend>Costos de servicio mensual</legend>
                            <p>Cada elemento seleccionado suma 2 puntos.</p>
                            <div class="eval-check-list">
                                @foreach($residentTemplate['costs'] as $value => $label)
                                    <label><input type="checkbox" name="costos_servicio_items[]" value="{{ $value }}" @checked(in_array($value, old('costos_servicio_items', []), true)) {{ $value === 'NINGUNO' ? 'data-none-option' : '' }}><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            @error('costos_servicio_items')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </fieldset>

                        <fieldset class="eval-choice-panel">
                            <legend>Eventos de seguridad</legend>
                            <div class="eval-segmented-options">
                                @foreach($residentTemplate['binary'] as $value => $label)
                                    <label><input type="radio" name="eventos_seguridad_respuesta" value="{{ $value }}" @checked(old('eventos_seguridad_respuesta') === $value) required><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            @error('eventos_seguridad_respuesta')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </fieldset>

                        <fieldset class="eval-choice-panel">
                            <legend>Reportes de calidad</legend>
                            <div class="eval-segmented-options">
                                @foreach($residentTemplate['binary'] as $value => $label)
                                    <label><input type="radio" name="reportes_calidad_respuesta" value="{{ $value }}" @checked(old('reportes_calidad_respuesta') === $value) required><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            @error('reportes_calidad_respuesta')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </fieldset>

                        <fieldset class="eval-choice-panel">
                            <legend>Liderazgo, gestión e innovación</legend>
                            <div class="eval-segmented-options eval-segmented-options-4">
                                @foreach(range(1, 4) as $score)
                                    <label><input type="radio" name="liderazgo_gestion_innovacion" value="{{ $score }}" @checked((string) old('liderazgo_gestion_innovacion') === (string) $score) required><span>{{ $score }}</span></label>
                                @endforeach
                            </div>
                            @error('liderazgo_gestion_innovacion')<small class="eval-field-error">{{ $message }}</small>@enderror
                        </fieldset>

                        <div class="eval-resident-total">
                            <span>Puntaje calculado</span>
                            <strong data-resident-total>0 / 20</strong>
                        </div>
                    </div>
                    <div class="eval-field"><label>Comentario</label><textarea name="comentarios" rows="4" placeholder="Fortalezas, alertas y acuerdos de mejora" required>{{ old('comentarios') }}</textarea>@error('comentarios')<small class="eval-field-error">{{ $message }}</small>@enderror</div>
                    <div class="eval-actions"><button class="btn btn-primary" type="submit">Guardar evaluación mensual</button></div>
                </form>
                @endif
            </section>
        @endif

        <section class="eval-section">
            <div class="eval-section-heading"><div><h2>Evaluaciones de residentes</h2><p>Historial mensual dentro de tu alcance.</p></div></div>
            @include('evaluaciones.partials.resident-history', ['items' => $residentHistory])
        </section>
    @endif
</div>

@push('scripts')
<script>
document.querySelectorAll('.eval-score-form').forEach((form) => {
    const output = form.querySelector('[data-score-total]');
    const calculate = () => {
        const total = [...form.querySelectorAll('.eval-score-field input:checked')]
            .reduce((sum, input) => sum + Number(input.value), 0);
        output.textContent = `${total} / 20`;
    };
    form.addEventListener('change', calculate);
    calculate();

    const toggle = form.querySelector('[data-incident-toggle]');
    const field = form.querySelector('[data-incident-field]');
    const syncIncident = () => {
        field.classList.toggle('is-hidden', !toggle.checked);
        field.querySelector('textarea').required = toggle.checked;
    };
    toggle?.addEventListener('change', syncIncident);
    if (toggle) syncIncident();

    form.querySelector('[data-eval-cancel]')?.addEventListener('click', () => {
        form.closest('details').open = false;
    });
    form.addEventListener('submit', () => {
        if (!form.reportValidity()) return;
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        submit.textContent = 'Guardando...';
    });
});

document.querySelectorAll('[data-eval-person-search]').forEach((search) => {
    const input = search.querySelector('[data-eval-person-input]');
    const hidden = search.querySelector('[data-eval-person-id]');
    const results = search.querySelector('[data-eval-person-results]');
    const clear = search.querySelector('[data-eval-person-clear]');
    const help = search.querySelector('[data-eval-person-help]');
    const searchUrl = search.dataset.searchUrl;
    const evaluationType = search.dataset.evaluationType;
    const mineInput = search.dataset.mineSource
        ? search.closest('form')?.querySelector(search.dataset.mineSource)
        : null;
    let timer = null;
    let requestNumber = 0;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);

    const closeResults = () => {
        results.hidden = true;
        results.innerHTML = '';
    };

    const personMeta = (person) => [person.documento, person.puesto, person.estado].filter(Boolean).join(' | ');

    const selectPerson = (person) => {
        input.value = person.nombre || '';
        hidden.value = person.id || '';
        input.setCustomValidity('');
        input.classList.add('is-selected');
        clear.classList.add('is-visible');
        help.textContent = personMeta(person);
        help.classList.add('is-selected');
        closeResults();
    };

    const renderResults = (items) => {
        if (!items.length) {
            results.innerHTML = '<div class="eval-person-empty">No se encontraron coincidencias.</div>';
        } else {
            results.innerHTML = items.map((person, index) => `
                <button type="button" class="eval-person-result" data-person-index="${index}" role="option">
                    <strong>${escapeHtml(person.nombre)}</strong>
                    <span>${escapeHtml(personMeta(person))}</span>
                </button>
            `).join('');
        }
        results.hidden = false;
        results.querySelectorAll('[data-person-index]').forEach((button) => {
            button.addEventListener('click', () => selectPerson(items[Number(button.dataset.personIndex)]));
        });
    };

    const fetchPeople = async (parameters) => {
        parameters.tipo = evaluationType;
        if (mineInput?.value) parameters.mina_id = mineInput.value;
        const currentRequest = ++requestNumber;
        try {
            const response = await fetch(`${searchUrl}?${new URLSearchParams(parameters)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok || currentRequest !== requestNumber) return;
            const payload = await response.json();
            const items = Array.isArray(payload.data) ? payload.data : [];
            if (parameters.id && items[0]) selectPerson(items[0]);
            else if (!parameters.id) renderResults(items);
        } catch (error) {
            if (!parameters.id) {
                results.innerHTML = '<div class="eval-person-empty">No se pudo completar la búsqueda.</div>';
                results.hidden = false;
            }
        }
    };

    input.addEventListener('input', () => {
        hidden.value = '';
        input.classList.remove('is-selected');
        clear.classList.toggle('is-visible', input.value.length > 0);
        help.textContent = 'Escribe al menos 2 caracteres y selecciona una persona.';
        help.classList.remove('is-selected');
        input.setCustomValidity('');
        clearTimeout(timer);
        const query = input.value.trim();
        if (mineInput && !mineInput.value) {
            closeResults();
            help.textContent = 'Selecciona primero la unidad minera.';
            return;
        }
        if (query.length < 2) {
            closeResults();
            return;
        }
        timer = setTimeout(() => fetchPeople({ q: query, limit: '12' }), 180);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeResults();
    });

    clear.addEventListener('click', () => {
        input.value = '';
        hidden.value = '';
        input.classList.remove('is-selected');
        input.setCustomValidity('');
        clear.classList.remove('is-visible');
        help.textContent = 'Escribe al menos 2 caracteres y selecciona una persona.';
        help.classList.remove('is-selected');
        closeResults();
        input.focus();
    });

    mineInput?.addEventListener('change', () => {
        input.value = '';
        hidden.value = '';
        input.classList.remove('is-selected');
        clear.classList.remove('is-visible');
        help.textContent = mineInput.value
            ? 'Escribe al menos 2 caracteres y selecciona un supervisor de esta mina.'
            : 'Selecciona primero la unidad minera.';
        closeResults();
    });

    search.closest('form')?.addEventListener('submit', (event) => {
        if (hidden.value) return;
        event.preventDefault();
        input.setCustomValidity('Selecciona una persona de la lista de resultados.');
        input.reportValidity();
    });

    document.addEventListener('click', (event) => {
        if (!search.contains(event.target)) closeResults();
    });

    if (hidden.value) fetchPeople({ id: hidden.value });
});

document.querySelectorAll('[data-resident-evaluation]').forEach((evaluation) => {
    const form = evaluation.closest('form');
    const total = evaluation.querySelector('[data-resident-total]');

    const calculate = () => {
        const selected = (name) => [...form.querySelectorAll(`input[name="${name}"]:checked`)].map((input) => input.value);
        const kpis = selected('indicadores_kpi_items[]');
        const costs = selected('costos_servicio_items[]');
        const security = selected('eventos_seguridad_respuesta')[0];
        const quality = selected('reportes_calidad_respuesta')[0];
        const leadership = Number(selected('liderazgo_gestion_innovacion')[0] || 0);
        const score = (kpis.includes('NINGUNO') ? 0 : kpis.length)
            + (costs.includes('NINGUNO') ? 0 : costs.length * 2)
            + (security === 'SI' ? 4 : 0)
            + (quality === 'SI' ? 4 : 0)
            + leadership;
        total.textContent = `${score} / 20`;
    };

    evaluation.querySelectorAll('[data-resident-multi]').forEach((group) => {
        group.addEventListener('change', (event) => {
            const changed = event.target.closest('input[type="checkbox"]');
            if (!changed || !changed.checked) return;

            const none = group.querySelector('[data-none-option]');
            if (changed === none) {
                group.querySelectorAll('input[type="checkbox"]:not([data-none-option])').forEach((input) => input.checked = false);
            } else if (none) {
                none.checked = false;
            }
            calculate();
        });
    });

    evaluation.addEventListener('change', calculate);
    calculate();
    form.addEventListener('submit', () => {
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        submit.textContent = 'Guardando...';
    });
});
</script>
@endpush
@endsection
