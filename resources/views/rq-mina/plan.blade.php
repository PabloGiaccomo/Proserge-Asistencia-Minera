@extends('layouts.app')

@section('title', 'Plan Operativo Semanal')

@php
    $planOperativo = $item['plan_operativo'] ?? [];
    $detalle = $item['detalle'] ?? [];
    $plans = $item['plans'] ?? [];
    $selectedPlan = $item['selected_plan'] ?? ($plans[0] ?? null);
    $selectedPlanId = (string) ($item['selected_plan_id'] ?? ($selectedPlan['id'] ?? ''));
    $selectedPlanArchived = strtoupper((string) ($selectedPlan['estado'] ?? '')) === 'ARCHIVADO';
    $fechaInicio = !empty($item['fecha_inicio']) ? \Carbon\Carbon::parse($item['fecha_inicio']) : null;
    $fechaFin = !empty($item['fecha_fin']) ? \Carbon\Carbon::parse($item['fecha_fin']) : null;
    $semana = $fechaInicio ? $fechaInicio->isoWeek() : null;
    $anioSemana = $fechaInicio ? $fechaInicio->isoWeekYear() : null;
    $canImportPlan = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'importar');
    $canUpdatePlan = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'actualizar');
    $canViewTransportes = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'transportes', 'ver');
@endphp

@section('content')

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">Plan Operativo Semanal</h1>
            <p class="page-subtitle">
                {{ $item['lugar'] ?? '-' }}
                @if($semana)
                    | Semana {{ $semana }} / {{ $anioSemana }}
                @endif
            </p>
        </div>
        <div class="page-actions">
            @if($canViewTransportes)
                <a href="{{ route('transporte.planificacion', ['rq_mina_id' => $item['id'], 'rq_mina_plan_id' => $selectedPlanId, 'fecha' => $selectedPlan['fecha_inicio'] ?? $item['fecha_inicio'] ?? null, 'turno' => 'A']) }}" class="btn btn-outline">Planificar transporte</a>
            @endif
            @if($canImportPlan)
                <a href="{{ route('rq-mina.plan.importar', ['id' => $item['id'], 'plan_id' => $selectedPlanId]) }}" class="btn btn-primary {{ $selectedPlanArchived ? 'disabled' : '' }}" @if($selectedPlanArchived) aria-disabled="true" onclick="return false;" @endif>Importar plan operativo</a>
            @endif
            <a href="{{ route('rq-mina.show', $item['id']) }}" class="btn btn-outline">Volver</a>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

@include('rq-mina.partials.field-options')
@include('rq-mina.partials.personal-autocomplete')

<div class="card rqm-summary-card" style="margin-bottom:16px;">
    <div class="card-header">
        <h3 class="card-title">Parada registrada</h3>
    </div>
    <div class="card-body">
        <div class="rqm-summary-line">
            <div class="rqm-summary-item">
                <span class="rqm-meta-label">Lugar</span>
                <span class="rqm-meta-value">{{ $item['lugar'] ?? '-' }}</span>
            </div>
            <div class="rqm-summary-item">
                <span class="rqm-meta-label">Parada</span>
                <span class="rqm-meta-value">{{ $item['area'] ?? '-' }}</span>
            </div>
            <div class="rqm-summary-item">
                <span class="rqm-meta-label">Semana</span>
                <span class="rqm-meta-value">{{ $semana ? 'Semana '.$semana.' / '.$anioSemana : '-' }}</span>
            </div>
            <div class="rqm-summary-item">
                <span class="rqm-meta-label">Fechas</span>
                <span class="rqm-meta-value">{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</span>
            </div>
            <div class="rqm-summary-item is-observation">
                <span class="rqm-meta-label">Observaciones</span>
                <span class="rqm-meta-value" title="{{ $item['observaciones'] ?: 'Sin observaciones.' }}">{{ $item['observaciones'] ?: 'Sin observaciones.' }}</span>
            </div>
        </div>
    </div>
</div>

<section
    class="card rq-plan-manager"
    data-rq-plan-manager
    data-rq-id="{{ $item['id'] }}"
    data-selected-plan-id="{{ $selectedPlanId }}"
    data-create-url="{{ route('rq-mina.planes.store', $item['id']) }}"
    data-plan-url="{{ route('rq-mina.plan', $item['id']) }}"
>
    <div class="card-header rq-plan-manager-header">
        <div>
            <h3 class="card-title">Planes operativos de la parada</h3>
            <p>Plan seleccionado: <strong>{{ $selectedPlan['codigo'] ?? '-' }} - {{ $selectedPlan['nombre'] ?? 'Sin nombre' }}</strong></p>
        </div>
        <div class="rq-plan-manager-actions">
            @if($canUpdatePlan)
                <button type="button" class="btn btn-primary" data-open-plan-modal="create">Nuevo plan</button>
                <button type="button" class="btn btn-outline" data-open-plan-modal="edit" @if(!$selectedPlan || $selectedPlanArchived) disabled @endif>Editar plan</button>
                <button type="button" class="btn btn-outline" data-open-plan-modal="duplicate" @if(!$selectedPlan) disabled @endif>Duplicar plan</button>
                <button type="button" class="btn btn-outline danger" data-open-plan-modal="archive" @if(!$selectedPlan || $selectedPlanArchived) disabled @endif>Archivar plan</button>
            @endif
        </div>
    </div>
    <div class="card-body">
        <div class="rq-plan-selector-row">
            <label class="form-label" for="rqPlanSelector">Seleccionar plan</label>
            <select id="rqPlanSelector" class="form-control rq-plan-selector" data-rq-plan-selector>
                @foreach($plans as $plan)
                    <option value="{{ $plan['id'] }}" @selected((string) $plan['id'] === $selectedPlanId)>
                        {{ $plan['codigo'] }} - {{ $plan['nombre'] }} | {{ $plan['fecha_inicio'] }} al {{ $plan['fecha_fin'] }} | {{ $plan['estado'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="rq-plan-cards" aria-label="Planes disponibles">
            @foreach($plans as $plan)
                @php
                    $isActivePlan = (string) $plan['id'] === $selectedPlanId;
                    $planUrl = route('rq-mina.plan', ['id' => $item['id'], 'plan_id' => $plan['id']]);
                @endphp
                <a href="{{ $planUrl }}" class="rq-plan-card {{ $isActivePlan ? 'is-active' : '' }} {{ strtoupper((string) $plan['estado']) === 'ARCHIVADO' ? 'is-archived' : '' }}" data-plan-link>
                    <span class="rq-plan-card-code">{{ $plan['codigo'] }}</span>
                    <strong>{{ $plan['nombre'] }}</strong>
                    <small>{{ $plan['fecha_inicio'] }} al {{ $plan['fecha_fin'] }}</small>
                    <small>{{ $plan['semana_referencia'] ?: 'Semana auto' }}</small>
                    <span class="rq-plan-status">{{ $plan['estado'] }}</span>
                </a>
            @endforeach
        </div>

        @if($selectedPlan)
            <div class="rq-plan-selected-summary">
                <div>
                    <span>Codigo</span>
                    <strong>{{ $selectedPlan['codigo'] }}</strong>
                </div>
                <div>
                    <span>Nombre</span>
                    <strong>{{ $selectedPlan['nombre'] }}</strong>
                </div>
                <div>
                    <span>Fechas</span>
                    <strong>{{ $selectedPlan['fecha_inicio'] }} al {{ $selectedPlan['fecha_fin'] }}</strong>
                </div>
                <div>
                    <span>Semana</span>
                    <strong>{{ $selectedPlan['semana_referencia'] ?: 'Calculada automaticamente' }}</strong>
                </div>
                <div>
                    <span>Estado</span>
                    <strong>{{ $selectedPlan['estado'] }}</strong>
                </div>
                <div>
                    <span>Version</span>
                    <strong>{{ $selectedPlan['version'] }}</strong>
                </div>
            </div>
            @if(!empty($selectedPlan['observaciones']))
                <p class="rq-plan-observations">{{ $selectedPlan['observaciones'] }}</p>
            @endif
        @endif

        @if($selectedPlanArchived)
            <div class="alert alert-warning rq-plan-archived-alert">
                Este plan esta archivado. Puedes consultarlo, pero no modificar grupos, actividades, turnos, transportes ni importar contenido.
            </div>
        @endif
    </div>
</section>

<div class="rq-plan-draft-alert" data-rq-plan-draft-banner hidden>
    <div>
        <strong>Borrador recuperado</strong>
        <span>Se restauro la informacion que estaba escrita en este mismo plan operativo.</span>
    </div>
    <button type="button" data-rq-plan-draft-discard>Descartar borrador</button>
</div>

<form
    method="POST"
    action="{{ route('rq-mina.plan.update', $item['id']) }}"
    id="rqMinaPlanForm"
    data-rq-plan-draft
    data-rq-id="{{ $item['id'] }}"
    data-draft-key="rq_mina_plan_draft:{{ $item['id'] }}:{{ $selectedPlanId }}"
    data-selected-plan-id="{{ $selectedPlanId }}"
    data-personnel-editor-id="rqPersonalRequestEditor"
    data-plan-editor-id="rqPlanOperativoEditor"
>
    @csrf
    @method('PUT')
    <input type="hidden" name="plan_id" value="{{ $selectedPlanId }}">

    <div class="rq-personnel-note" style="margin-bottom:12px;">
        El pedido de personal corresponde a la parada completa. La distribucion por area, grupo, actividad y turno se administra dentro del plan seleccionado.
    </div>

    @include('rq-mina.partials.personal-request-editor', [
        'editorId' => 'rqPersonalRequestEditor',
        'detalle' => $detalle,
    ])

    @include('rq-mina.partials.plan-operativo-editor', [
        'editorId' => 'rqPlanOperativoEditor',
        'planOperativo' => $planOperativo,
        'weekNumber' => $semana,
        'weekYear' => $anioSemana,
        'paradaFechaInicio' => $item['fecha_inicio'] ?? '',
        'paradaFechaFin' => $item['fecha_fin'] ?? '',
        'readOnly' => $selectedPlanArchived,
    ])

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ route('rq-mina.show', $item['id']) }}" class="btn btn-outline">Cancelar</a>
        @if($canUpdatePlan && !$selectedPlanArchived)
            <button type="submit" class="btn btn-primary">Guardar plan operativo</button>
        @endif
    </div>
</form>

@if($canUpdatePlan && $selectedPlan)
    <div class="rq-plan-modal" data-plan-modal="create" hidden>
        <div class="rq-plan-modal-backdrop" data-close-plan-modal></div>
        <form class="rq-plan-modal-card" data-plan-form="create">
            <h3>Nuevo plan</h3>
            <div class="rq-plan-modal-grid">
                <label>Nombre<input name="nombre" required maxlength="191" class="form-control"></label>
                <label>Fecha inicio<input name="fecha_inicio" type="date" required class="form-control" value="{{ $item['fecha_inicio'] ?? '' }}"></label>
                <label>Fecha fin<input name="fecha_fin" type="date" required class="form-control" value="{{ $item['fecha_fin'] ?? '' }}"></label>
                <label>Estado<select name="estado" class="form-control"><option value="BORRADOR">BORRADOR</option><option value="VIGENTE">VIGENTE</option></select></label>
                <label class="span-2">Semana referencia<input name="semana_referencia" maxlength="80" class="form-control" placeholder="Se calcula automaticamente si lo dejas vacio"></label>
                <label class="span-2">Observaciones<textarea name="observaciones" class="form-control" rows="3"></textarea></label>
            </div>
            <div class="rq-plan-modal-error" data-plan-form-error hidden></div>
            <div class="rq-plan-modal-actions">
                <button type="button" class="btn btn-outline" data-close-plan-modal>Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear plan</button>
            </div>
        </form>
    </div>

    <div class="rq-plan-modal" data-plan-modal="edit" hidden>
        <div class="rq-plan-modal-backdrop" data-close-plan-modal></div>
        <form class="rq-plan-modal-card" data-plan-form="edit" data-action-url="{{ route('rq-mina.planes.update', ['rqMinaId' => $item['id'], 'planId' => $selectedPlanId]) }}">
            <h3>Editar plan {{ $selectedPlan['codigo'] }}</h3>
            <div class="rq-plan-modal-grid">
                <label>Nombre<input name="nombre" required maxlength="191" class="form-control" value="{{ $selectedPlan['nombre'] }}"></label>
                <label>Fecha inicio<input name="fecha_inicio" type="date" required class="form-control" value="{{ $selectedPlan['fecha_inicio'] }}"></label>
                <label>Fecha fin<input name="fecha_fin" type="date" required class="form-control" value="{{ $selectedPlan['fecha_fin'] }}"></label>
                <label>Estado<select name="estado" class="form-control"><option value="BORRADOR" @selected($selectedPlan['estado'] === 'BORRADOR')>BORRADOR</option><option value="VIGENTE" @selected($selectedPlan['estado'] === 'VIGENTE')>VIGENTE</option></select></label>
                <label class="span-2">Semana referencia<input name="semana_referencia" maxlength="80" class="form-control" value="{{ $selectedPlan['semana_referencia'] }}"></label>
                <label class="span-2">Observaciones<textarea name="observaciones" class="form-control" rows="3">{{ $selectedPlan['observaciones'] }}</textarea></label>
            </div>
            <div class="rq-plan-modal-error" data-plan-form-error hidden></div>
            <div class="rq-plan-modal-actions">
                <button type="button" class="btn btn-outline" data-close-plan-modal>Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar plan</button>
            </div>
        </form>
    </div>

    <div class="rq-plan-modal" data-plan-modal="duplicate" hidden>
        <div class="rq-plan-modal-backdrop" data-close-plan-modal></div>
        <form class="rq-plan-modal-card" data-plan-form="duplicate" data-action-url="{{ route('rq-mina.planes.duplicate', ['rqMinaId' => $item['id'], 'planId' => $selectedPlanId]) }}">
            <h3>Duplicar plan {{ $selectedPlan['codigo'] }}</h3>
            <p class="rq-plan-modal-note">Se copiaran areas, grupos, actividades, cantidades planificadas y requerimientos de transporte. No se copiaran datos reales, trabajadores, placas asignadas, asistencia ni eventos historicos.</p>
            <div class="rq-plan-modal-grid">
                <label>Nombre del nuevo plan<input name="nombre" required maxlength="191" class="form-control"></label>
                <label>Fecha inicio<input name="fecha_inicio" type="date" required class="form-control" value="{{ $selectedPlan['fecha_inicio'] }}"></label>
                <label>Fecha fin<input name="fecha_fin" type="date" required class="form-control" value="{{ $selectedPlan['fecha_fin'] }}"></label>
                <label class="span-2">Observaciones<textarea name="observaciones" class="form-control" rows="3"></textarea></label>
            </div>
            <div class="rq-plan-modal-error" data-plan-form-error hidden></div>
            <div class="rq-plan-modal-actions">
                <button type="button" class="btn btn-outline" data-close-plan-modal>Cancelar</button>
                <button type="submit" class="btn btn-primary">Duplicar plan</button>
            </div>
        </form>
    </div>

    <div class="rq-plan-modal" data-plan-modal="archive" hidden>
        <div class="rq-plan-modal-backdrop" data-close-plan-modal></div>
        <form class="rq-plan-modal-card rq-plan-modal-card-small" data-plan-form="archive" data-action-url="{{ route('rq-mina.planes.archive', ['rqMinaId' => $item['id'], 'planId' => $selectedPlanId]) }}">
            <h3>Archivar plan {{ $selectedPlan['codigo'] }}</h3>
            <p class="rq-plan-modal-note">El plan {{ $selectedPlan['nombre'] }} quedara en modo consulta. No se eliminaran sus grupos, actividades ni transportes.</p>
            <div class="rq-plan-modal-error" data-plan-form-error hidden></div>
            <div class="rq-plan-modal-actions">
                <button type="button" class="btn btn-outline" data-close-plan-modal>Cancelar</button>
                <button type="submit" class="btn btn-primary danger">Archivar plan</button>
            </div>
        </form>
    </div>
@endif

<script>
(function () {
    function readJson(value) {
        try {
            return JSON.parse(value || 'null');
        } catch (error) {
            return null;
        }
    }

    function debounce(callback, delay) {
        let timer = null;

        return function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(callback, delay);
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('[data-rq-plan-draft]');
        if (!form) return;

        const rqId = form.dataset.rqId || '';
        const draftKey = form.dataset.draftKey || '';
        const banner = document.querySelector('[data-rq-plan-draft-banner]');
        const discardButton = document.querySelector('[data-rq-plan-draft-discard]');
        const personnelEditor = window.rqMinaPersonnelEditors?.[form.dataset.personnelEditorId || ''];
        const planEditor = window.rqMinaPlanEditors?.[form.dataset.planEditorId || ''];
        let isDirty = false;

        if (!rqId || !draftKey || !personnelEditor || !planEditor) {
            return;
        }

        function readDraft() {
            try {
                const draft = readJson(window.localStorage.getItem(draftKey));
                if (!draft || String(draft.rq_mina_id || '') !== String(rqId)) {
                    return null;
                }

                return draft;
            } catch (error) {
                return null;
            }
        }

        function showBanner() {
            if (banner) {
                banner.hidden = false;
            }
        }

        function buildDraft() {
            return {
                version: 1,
                rq_mina_id: rqId,
                saved_at: new Date().toISOString(),
                detalle: personnelEditor.getRows(),
                plan_operativo: planEditor.getPlan(),
            };
        }

        function saveDraft() {
            try {
                window.localStorage.setItem(draftKey, JSON.stringify(buildDraft()));
            } catch (error) {
                // Si el navegador bloquea localStorage, el guardado normal del formulario sigue funcionando.
            }
        }

        const draft = readDraft();
        if (draft) {
            personnelEditor.setRows(draft.detalle || []);
            planEditor.setPlan(draft.plan_operativo || []);
            showBanner();
        }

        const scheduleSave = debounce(saveDraft, 250);
        function markDirtyAndSave() {
            isDirty = true;
            scheduleSave();
        }

        window.rqMinaPlanHasUnsavedChanges = function () {
            return isDirty;
        };

        form.addEventListener('input', markDirtyAndSave);
        form.addEventListener('change', markDirtyAndSave);
        form.addEventListener('click', function (event) {
            const mutatesPlan = event.target.closest('[data-add-plan-group], [data-remove-group], [data-add-activity], [data-remove-activity], [data-add-transport], [data-remove-transport], .rq-plan-sait-check');
            if (mutatesPlan) {
                window.setTimeout(markDirtyAndSave, 0);
            }
        });
        form.addEventListener('submit', function () {
            isDirty = false;
            saveDraft();
        });
        window.addEventListener('beforeunload', saveDraft);
        document.querySelectorAll('[data-plan-link]').forEach(function(link) {
            link.addEventListener('click', function(event) {
                if (!isDirty) return;
                if (!window.confirm('Tienes cambios sin guardar en el plan actual. Si cambias de plan, estos cambios se perderan.')) {
                    event.preventDefault();
                }
            });
        });

        discardButton?.addEventListener('click', function () {
            try {
                window.localStorage.removeItem(draftKey);
            } catch (error) {
                // No requiere accion adicional.
            }
            window.location.reload();
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const manager = document.querySelector('[data-rq-plan-manager]');
        if (!manager) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const selector = manager.querySelector('[data-rq-plan-selector]');
        selector?.addEventListener('change', function () {
            if (window.rqMinaPlanHasUnsavedChanges && window.rqMinaPlanHasUnsavedChanges()) {
                if (!window.confirm('Tienes cambios sin guardar en el plan actual. Si cambias de plan, estos cambios se perderan.')) {
                    selector.value = manager.dataset.selectedPlanId || selector.value;
                    return;
                }
            }
            const planId = selector.value || '';
            const url = new URL(manager.dataset.planUrl || window.location.href, window.location.origin);
            if (planId) {
                url.searchParams.set('plan_id', planId);
            }
            window.location.href = url.toString();
        });

        function openModal(name) {
            const modal = document.querySelector('[data-plan-modal="' + name + '"]');
            if (modal) modal.hidden = false;
        }

        function closeModal(target) {
            const modal = target.closest('[data-plan-modal]');
            if (modal) modal.hidden = true;
        }

        document.querySelectorAll('[data-open-plan-modal]').forEach(function(button) {
            button.addEventListener('click', function() {
                if (button.disabled) return;
                openModal(button.dataset.openPlanModal || '');
            });
        });

        document.querySelectorAll('[data-close-plan-modal]').forEach(function(button) {
            button.addEventListener('click', function() {
                closeModal(button);
            });
        });

        function showError(form, message) {
            const box = form.querySelector('[data-plan-form-error]');
            if (!box) return;
            box.textContent = message || 'No se pudo completar la accion.';
            box.hidden = false;
        }

        function payloadFromForm(form) {
            const payload = {};
            new FormData(form).forEach(function(value, key) {
                payload[key] = value;
            });
            return payload;
        }

        async function submitPlanForm(form) {
            const type = form.dataset.planForm || '';
            const method = type === 'edit' ? 'PUT' : 'POST';
            const url = type === 'create' ? manager.dataset.createUrl : form.dataset.actionUrl;
            if (!url) return;

            const submit = form.querySelector('[type="submit"]');
            if (submit) submit.disabled = true;

            try {
                const response = await fetch(url, {
                    method: method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: type === 'archive' ? JSON.stringify({}) : JSON.stringify(payloadFromForm(form)),
                });
                const data = await response.json().catch(function() { return {}; });

                if (!response.ok || data.ok === false) {
                    showError(form, data.message || 'No se pudo completar la accion.');
                    return;
                }

                window.location.href = data.data?.redirect_url || window.location.href;
            } catch (error) {
                showError(form, 'No se pudo conectar con el servidor.');
            } finally {
                if (submit) submit.disabled = false;
            }
        }

        document.querySelectorAll('[data-plan-form]').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                submitPlanForm(form);
            });
        });
    });
})();
</script>
@endsection
