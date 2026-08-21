@extends('layouts.app')

@section('title', 'Evaluaciones - Proserge')

@section('content')
@php
    $typeLabels = [
        'desempeno' => ['Evaluación diaria', 'Técnicos y personal operativo'],
        'supervisores' => ['Supervisores', 'Evaluación realizada por residentes'],
        'residentes' => ['Residentes', 'Seguimiento mensual'],
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

    @if($errors->any())
        <div class="eval-alert eval-alert-error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @if($activeType === 'desempeno')
        <section class="eval-section">
            <div class="eval-section-heading">
                <div>
                    <h2>Evaluaciones pendientes del día</h2>
                    <p>Solo aparecen asistencias cerradas que registraste como encargado.</p>
                </div>
                <span class="eval-count">{{ $dailyPending->sum(fn ($group) => $group['workers']->count()) }} pendientes</span>
            </div>

            @if(!$user->personal_id)
                <div class="eval-empty">Tu cuenta debe estar vinculada a un trabajador para identificar quién realiza la evaluación.</div>
            @elseif($dailyPending->isEmpty())
                <div class="eval-empty">
                    <strong>No tienes evaluaciones pendientes para esta fecha.</strong>
                    <span>Cuando cierres una asistencia, el personal que trabajó aparecerá aquí.</span>
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
                                @foreach($group['workers'] as $detail)
                                    <details class="eval-worker" {{ old('asistencia_detalle_id') === $detail->id ? 'open' : '' }}>
                                        <summary>
                                            <span>
                                                <strong>{{ $detail->trabajador->nombre_completo }}</strong>
                                                <small>{{ $detail->trabajador->puesto ?: 'Puesto sin definir' }} &middot; {{ $detail->estado }}</small>
                                            </span>
                                            @if($canEvaluate['desempeno'])
                                                <span class="eval-worker-action">Evaluar</span>
                                            @endif
                                        </summary>

                                        @if($canEvaluate['desempeno'])
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
                                                        <fieldset class="eval-score-field">
                                                            <legend>{{ $label }}</legend>
                                                            <div class="eval-score-options">
                                                                @foreach(range(1, 4) as $score)
                                                                    <label>
                                                                        <input type="radio" name="{{ $field }}" value="{{ $score }}" required>
                                                                        <span>{{ $score }}</span>
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                        </fieldset>
                                                    @endforeach
                                                </div>

                                                <div class="eval-total" aria-live="polite"><span>Total</span><strong data-score-total>0 / 20</strong></div>

                                                <label class="eval-check">
                                                    <input type="checkbox" name="tuvo_incidencia" value="1" data-incident-toggle>
                                                    <span>Se registró una incidencia durante la jornada</span>
                                                </label>
                                                <div class="eval-field is-hidden" data-incident-field>
                                                    <label>Descripción de la incidencia</label>
                                                    <textarea name="descripcion_incidencia" rows="3" placeholder="Describe qué ocurrió y las acciones tomadas"></textarea>
                                                </div>
                                                <div class="eval-field">
                                                    <label>Observaciones</label>
                                                    <textarea name="observaciones" rows="3" placeholder="Comentario adicional sobre el desempeño"></textarea>
                                                </div>

                                                <div class="eval-actions">
                                                    <button type="submit" class="btn btn-primary">Guardar evaluación</button>
                                                </div>
                                            </form>
                                        @endif
                                    </details>
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
                    <div><h2>Nueva evaluación de supervisor</h2><p>El residente registra la evaluación dentro de su unidad minera.</p></div>
                </div>
                <form method="POST" action="{{ route('evaluaciones.supervisores.store') }}" class="eval-form">
                    @csrf
                    <div class="eval-form-grid eval-form-grid-3">
                        <div class="eval-field"><label>Unidad minera</label><select name="mina_id" required data-mine-select><option value="">Seleccionar mina</option>@foreach($mines as $mine)<option value="{{ $mine->id }}">{{ $mine->nombre }}</option>@endforeach</select></div>
                        @include('evaluaciones.partials.person-search', ['name' => 'evaluado_id', 'label' => 'Supervisor evaluado'])
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
    @elseif($activeType === 'residentes')
        @if($canEvaluate['residentes'])
            <section class="eval-section">
                <div class="eval-section-heading"><div><h2>Nueva evaluación mensual</h2><p>Seguimiento consolidado del residente por periodo.</p></div></div>
                <form method="POST" action="{{ route('evaluaciones.residentes.store') }}" class="eval-form">
                    @csrf
                    <div class="eval-form-grid">
                        @include('evaluaciones.partials.person-search', ['name' => 'residente_id', 'label' => 'Residente evaluado'])
                        <div class="eval-field"><label>Mes evaluado</label><input type="month" name="periodo_mes" value="{{ now()->format('Y-m') }}" required></div>
                    </div>
                    <div class="eval-resident-grid" data-resident-evaluation>
                        <fieldset class="eval-choice-panel" data-resident-multi>
                            <legend>Indicadores KPIs</legend>
                            <p>Marca los reportes o entregables cumplidos.</p>
                            <div class="eval-check-list">
                                @foreach($residentTemplate['kpis'] as $value => $label)
                                    <label><input type="checkbox" name="indicadores_kpi_items[]" value="{{ $value }}" {{ $value === 'NINGUNO' ? 'data-none-option' : '' }}><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <fieldset class="eval-choice-panel" data-resident-multi>
                            <legend>Costos de servicio mensual</legend>
                            <p>Cada elemento seleccionado suma 2 puntos.</p>
                            <div class="eval-check-list">
                                @foreach($residentTemplate['costs'] as $value => $label)
                                    <label><input type="checkbox" name="costos_servicio_items[]" value="{{ $value }}" {{ $value === 'NINGUNO' ? 'data-none-option' : '' }}><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <fieldset class="eval-choice-panel">
                            <legend>Eventos de seguridad</legend>
                            <div class="eval-segmented-options">
                                @foreach($residentTemplate['binary'] as $value => $label)
                                    <label><input type="radio" name="eventos_seguridad_respuesta" value="{{ $value }}" required><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <fieldset class="eval-choice-panel">
                            <legend>Reportes de calidad</legend>
                            <div class="eval-segmented-options">
                                @foreach($residentTemplate['binary'] as $value => $label)
                                    <label><input type="radio" name="reportes_calidad_respuesta" value="{{ $value }}" required><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <fieldset class="eval-choice-panel">
                            <legend>Liderazgo, gestión e innovación</legend>
                            <div class="eval-segmented-options eval-segmented-options-4">
                                @foreach(range(1, 4) as $score)
                                    <label><input type="radio" name="liderazgo_gestion_innovacion" value="{{ $score }}" required><span>{{ $score }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="eval-resident-total">
                            <span>Puntaje calculado</span>
                            <strong data-resident-total>0 / 20</strong>
                        </div>
                    </div>
                    <div class="eval-field"><label>Comentario</label><textarea name="comentarios" rows="4" placeholder="Fortalezas, alertas y acuerdos de mejora" required></textarea></div>
                    <div class="eval-actions"><button class="btn btn-primary" type="submit">Guardar evaluación mensual</button></div>
                </form>
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
    form.addEventListener('change', () => {
        const total = [...form.querySelectorAll('.eval-score-field input:checked')]
            .reduce((sum, input) => sum + Number(input.value), 0);
        output.textContent = `${total} / 20`;
    });

    const toggle = form.querySelector('[data-incident-toggle]');
    const field = form.querySelector('[data-incident-field]');
    toggle?.addEventListener('change', () => {
        field.classList.toggle('is-hidden', !toggle.checked);
        field.querySelector('textarea').required = toggle.checked;
    });
});

document.querySelectorAll('[data-eval-person-search]').forEach((search) => {
    const input = search.querySelector('[data-eval-person-input]');
    const hidden = search.querySelector('[data-eval-person-id]');
    const results = search.querySelector('[data-eval-person-results]');
    const clear = search.querySelector('[data-eval-person-clear]');
    const help = search.querySelector('[data-eval-person-help]');
    const searchUrl = search.dataset.searchUrl;
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
});
</script>
@endpush
@endsection
