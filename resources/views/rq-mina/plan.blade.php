@extends('layouts.app')

@section('title', 'Plan operativo')

@php
    $serverPlanOperativo = $item['plan_operativo'] ?? [];
    $serverDetalle = $item['detalle'] ?? [];
    $plans = $item['plans'] ?? [];
    $selectedPlan = $item['selected_plan'] ?? ($plans[0] ?? null);
    $selectedPlanId = (string) ($item['selected_plan_id'] ?? ($selectedPlan['id'] ?? ''));
    $submittedRevision = old('plan_revision');
    $submittedDetalle = old('detalle', []);
    $submittedPlanOperativo = old('plan_operativo', []);
    $recoverSubmittedInput = old('rq_mina_id') === (string) $item['id']
        && old('plan_id') === $selectedPlanId
        && in_array(old('plan_editor_submission'), ['1', 1, true], true)
        && in_array(old('plan_editor_complete'), ['1', 1, true], true)
        && is_string($submittedRevision) && $submittedRevision !== ''
        && is_array($submittedDetalle) && is_array($submittedPlanOperativo);
    $planOperativo = $recoverSubmittedInput ? $submittedPlanOperativo : $serverPlanOperativo;
    $detalle = $recoverSubmittedInput ? $submittedDetalle : $serverDetalle;
    $planRevision = $recoverSubmittedInput ? $submittedRevision : ($item['plan_revision'] ?? '');
    $selectedPlanArchived = strtoupper((string) ($selectedPlan['estado'] ?? '')) === 'ARCHIVADO';
    $semanaPlan = $item['semana_plan'] ?? ($selectedPlan['semana_referencia'] ?? '');
    $permissions = session('user.permissions', []);
    $canUpdatePlan = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'rq_mina', 'actualizar')
        && ($item['puede_actualizar'] ?? true);
    $canViewTransportes = \App\Support\Rbac\PermissionMatrix::allowsDirect($permissions, 'transportes', 'ver');
    $lista = (array) request()->query('lista', []);
    $rqRouteParams = ['id' => $item['id'], 'lista' => $lista];
    $rqShowUrl = route('rq-mina.show', $rqRouteParams);
    $rqEstado = strtoupper((string) ($item['estado'] ?? ''));
    $canSavePlan = $canUpdatePlan && !$selectedPlanArchived && !in_array($rqEstado, ['CERRADO', 'CANCELADO'], true);
    $rqProgress = match ($rqEstado) {
        'BORRADOR' => 'En preparación',
        'ENVIADO' => 'Atención de RR.HH. iniciada',
        'CERRADO' => 'Parada cerrada',
        'CANCELADO' => 'Parada cancelada',
        default => 'Plan de la parada',
    };
    $rqNextStep = match ($rqEstado) {
        'BORRADOR' => ($item['puede_iniciar_rrhh'] ?? false)
            ? 'Al guardar con cargos y cantidades, se inicia la atención de RR.HH.'
            : 'Puedes preparar y guardar el pedido. Una persona autorizada podrá iniciar la atención al guardar.',
        'ENVIADO' => 'Al guardar, los cambios en los cargos se reflejan en el pedido de personal.',
        default => null,
    };
@endphp

@push('styles')
<style>
    .rq-plan-save-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px 20px;
        margin-bottom: 16px;
        padding: 12px 16px;
    }
    .rq-plan-save-bar p { margin: 2px 0 0; color: #475569; }
    .rq-plan-context { padding: 12px 16px; margin-bottom: 16px; }
    .rq-plan-context strong, .rq-plan-context span { display: block; overflow-wrap: anywhere; }
    .rq-plan-context span, .rq-plan-dates { color: #475569; font-size: 13px; }
    .rq-plan-dates { margin: 0 0 10px; }
    .rq-plan-settings { border-top: 1px solid #e2e8f0; padding-top: 10px; }
    .rq-plan-settings summary { cursor: pointer; font-weight: 600; }
    .rq-plan-settings .rq-plan-manager-actions { margin-top: 12px; justify-content: flex-start; }
    @media (max-width: 560px) {
        .rq-plan-save-bar .btn { width: 100%; justify-content: center; }
    }
</style>
@endpush

@section('content')

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-title">Plan operativo</h1>
            <p class="page-subtitle">
                {{ $item['lugar'] ?? '-' }}
                @if($semanaPlan)
                    | {{ $semanaPlan }}
                @endif
            </p>
        </div>
        <div class="page-actions">
            @if($canViewTransportes)
                <a href="{{ route('transporte.planificacion', ['rq_mina_id' => $item['id'], 'rq_mina_plan_id' => $selectedPlanId, 'fecha' => $selectedPlan['fecha_inicio'] ?? $item['fecha_inicio'] ?? null, 'turno' => 'A', 'lista' => $lista]) }}" class="btn btn-outline">Planificar transporte</a>
            @endif
            <a href="{{ $rqShowUrl }}" class="btn btn-outline">Volver a la parada</a>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-error" role="alert">{{ session('error') }}</div>
@endif

@include('rq-mina.partials.field-options')
@include('rq-mina.partials.personal-autocomplete')

<div class="card rq-plan-save-bar">
    <div>
        <strong>{{ $rqProgress }}</strong>
        @if($canSavePlan && $rqNextStep)
            <p id="rqPlanSaveEffect">{{ $rqNextStep }}</p>
        @endif
    </div>
    @if($canSavePlan)
        <button type="submit" form="rqMinaPlanForm" class="btn btn-primary" @if($rqNextStep) aria-describedby="rqPlanSaveEffect" @endif>Guardar plan operativo</button>
    @endif
</div>

<div class="card rq-plan-context" aria-label="Parada registrada">
    <strong>{{ $item['area'] ?? '-' }}</strong>
    <span>{{ $item['lugar'] ?? '-' }} · {{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</span>
</div>

<section
    class="card rq-plan-manager"
    data-rq-plan-manager
    data-rq-id="{{ $item['id'] }}"
    data-selected-plan-id="{{ $selectedPlanId }}"
    data-create-url="{{ route('rq-mina.planes.store', $item['id']) }}"
    data-plan-url="{{ route('rq-mina.plan', $rqRouteParams) }}"
>
    <div class="card-body">
        <div class="rq-plan-selector-row">
            <label class="form-label" for="rqPlanSelector">Seleccionar plan</label>
            <select id="rqPlanSelector" class="form-control rq-plan-selector" data-rq-plan-selector>
                @foreach($plans as $plan)
                    <option value="{{ $plan['id'] }}" @selected((string) $plan['id'] === $selectedPlanId)>
                        {{ $plan['codigo'] }} - {{ $plan['nombre'] }}{{ strtoupper((string) $plan['estado']) === 'ARCHIVADO' ? ' (archivado)' : '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <noscript>
            <ul>
                @foreach($plans as $plan)
                    <li><a href="{{ route('rq-mina.plan', [...$rqRouteParams, 'plan_id' => $plan['id']]) }}">{{ $plan['codigo'] }} - {{ $plan['nombre'] }}</a></li>
                @endforeach
            </ul>
        </noscript>

        @if($selectedPlan)
            <p class="rq-plan-dates">{{ $selectedPlan['fecha_inicio'] }} al {{ $selectedPlan['fecha_fin'] }} · {{ $selectedPlan['estado'] }}</p>
        @endif

        <details class="rq-plan-settings">
            <summary>Administrar planes y ver observaciones</summary>
            @if($canUpdatePlan)
                <div class="rq-plan-manager-actions">
                    <button type="button" class="btn btn-primary" data-open-plan-modal="create">Nuevo plan</button>
                    <button type="button" class="btn btn-outline" data-open-plan-modal="edit" @if(!$selectedPlan || $selectedPlanArchived) disabled @endif>Editar plan</button>
                    <button type="button" class="btn btn-outline" data-open-plan-modal="duplicate" @if(!$selectedPlan) disabled @endif>Duplicar plan</button>
                    <button type="button" class="btn btn-outline danger" data-open-plan-modal="archive" @if(!$selectedPlan || $selectedPlanArchived) disabled @endif>Archivar plan</button>
                </div>
            @endif
            <p class="rq-plan-observations">{{ ($selectedPlan['observaciones'] ?? '') ?: 'Sin observaciones del plan.' }}</p>
            @if(!empty($item['observaciones']))
                <p class="rq-plan-observations">Parada · {{ $item['observaciones'] }}</p>
            @endif
        </details>

        @if($selectedPlanArchived)
            <div class="alert alert-warning rq-plan-archived-alert">
                Este plan está archivado y disponible para consulta.
            </div>
        @endif
    </div>
</section>

<div class="rq-plan-draft-alert" data-rq-plan-draft-banner hidden>
    <div>
        <strong>Copia pendiente en este navegador</strong>
        <span data-rq-plan-draft-message>Puedes recuperar la copia o seguir con los datos guardados. Si los editas, tus cambios reemplazarán la copia.</span>
    </div>
    <div>
        <button type="button" data-rq-plan-draft-recover>Recuperar copia</button>
        <button type="button" data-rq-plan-draft-discard>Descartar copia</button>
    </div>
</div>

<form
    method="POST"
    action="{{ route('rq-mina.plan.update', $rqRouteParams) }}"
    id="rqMinaPlanForm"
    @if($canUpdatePlan && !$selectedPlanArchived && session('user.id')) data-rq-plan-draft @endif
    data-rq-id="{{ $item['id'] }}"
    data-draft-key="rq_mina_plan_draft:{{ session('user.id') }}:{{ $item['id'] }}:{{ $selectedPlanId }}"
    data-draft-base="{{ hash('sha256', json_encode([$serverDetalle, $serverPlanOperativo], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)) }}"
    data-recovered-input="{{ $recoverSubmittedInput ? '1' : '0' }}"
    data-saved-draft-id="{{ session('rq_mina_saved_draft_id') }}"
    data-selected-plan-id="{{ $selectedPlanId }}"
    data-personnel-editor-id="rqPersonalRequestEditor"
    data-plan-editor-id="rqPlanOperativoEditor"
>
    @csrf
    @method('PUT')
    <input type="hidden" name="rq_mina_id" value="{{ $item['id'] }}">
    <input type="hidden" name="plan_id" value="{{ $selectedPlanId }}">
    <input type="hidden" name="plan_revision" value="{{ $planRevision }}">
    <input type="hidden" name="draft_id" value="">
    <input type="hidden" name="plan_editor_submission" value="1">
    <input type="hidden" name="plan_operativo_json" value="">

    <div class="rq-personnel-note" style="margin-bottom:12px;">
        El pedido de personal corresponde a la parada completa. La distribucion por area, grupo, actividad y turno se administra dentro del plan seleccionado.
    </div>

    <fieldset @disabled(!$canSavePlan) style="min-inline-size:0;border:0;padding:0;margin:0">
        @include('rq-mina.partials.personal-request-editor', [
            'editorId' => 'rqPersonalRequestEditor',
            'detalle' => $detalle,
        ])
    </fieldset>

    @include('rq-mina.partials.plan-operativo-editor', [
        'editorId' => 'rqPlanOperativoEditor',
        'planOperativo' => $planOperativo,
        'weekLabel' => $semanaPlan,
        'paradaFechaInicio' => $selectedPlan['fecha_inicio'] ?? $item['fecha_inicio'] ?? '',
        'paradaFechaFin' => $selectedPlan['fecha_fin'] ?? $item['fecha_fin'] ?? '',
        'readOnly' => !$canSavePlan,
    ])

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ $rqShowUrl }}" class="btn btn-outline">Volver a la parada</a>
        @if($canSavePlan)
            <button type="submit" class="btn btn-primary" @if($rqNextStep) aria-describedby="rqPlanSaveEffect" @endif>Guardar plan operativo</button>
        @endif
    </div>
    <input type="hidden" name="plan_editor_complete" value="1">
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

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('#rqMinaPlanForm');
        if (!form) return;

        const planEditor = window.rqMinaPlanEditors?.[form.dataset.planEditorId || ''];
        if (!planEditor) return;

        form.addEventListener('formdata', function (event) {
            const data = event.formData;
            const program = JSON.stringify(planEditor.getPlan());
            for (const field of Array.from(data.keys())) {
                if (field.startsWith('plan_operativo[')) data.delete(field);
            }
            data.set('plan_operativo_json', program);
            data.set('plan_editor_complete', 'json');
        });

        if (!form.hasAttribute('data-rq-plan-draft')) return;

        const rqId = form.dataset.rqId || '';
        const draftKey = form.dataset.draftKey || '';
        const banner = document.querySelector('[data-rq-plan-draft-banner]');
        const recoverButton = document.querySelector('[data-rq-plan-draft-recover]');
        const discardButton = document.querySelector('[data-rq-plan-draft-discard]');
        const personnelEditor = window.rqMinaPersonnelEditors?.[form.dataset.personnelEditorId || ''];
        const revisionField = form.querySelector('[name="plan_revision"]');

        if (!rqId || !draftKey || !personnelEditor) {
            return;
        }

        function readDraft() {
            try {
                const draft = readJson(window.localStorage.getItem(draftKey));
                if (!draft?.id || draft.rq_mina_id !== rqId || !Array.isArray(draft.detalle) || !Array.isArray(draft.plan_operativo)) {
                    return null;
                }

                return draft;
            } catch (error) {
                return null;
            }
        }

        function removeDraft() {
            try {
                window.localStorage.removeItem(draftKey);
            } catch (error) {
                // El guardado del servidor sigue disponible.
            }
        }

        function editorContent() {
            return {
                detalle: personnelEditor.getRows(),
                plan_operativo: planEditor.getPlan(),
            };
        }

        const base = form.dataset.draftBase;
        const initialContent = JSON.stringify(editorContent());
        const hasRecoveredInput = form.dataset.recoveredInput === '1';
        const isDirty = () => hasRecoveredInput || JSON.stringify(editorContent()) !== initialContent;
        let timer = null;
        let submitting = false;
        let draft = readDraft();
        if (draft?.id === form.dataset.savedDraftId) {
            removeDraft();
            draft = null;
        }

        if (draft) {
            banner.hidden = false;
            if (draft.base !== base) {
                recoverButton.hidden = true;
                document.querySelector('[data-rq-plan-draft-message]').textContent = 'El plan guardado cambió desde que se creó esta copia. Puedes descartarla o seguir editando el plan actual.';
            }
        }

        function saveDraft() {
            if (!isDirty()) return '';

            const id = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            try {
                window.localStorage.setItem(draftKey, JSON.stringify({ id, base, revision: revisionField?.value || '', rq_mina_id: rqId, ...editorContent() }));
                draft = null;
                banner.hidden = true;
                return id;
            } catch (error) {
                return '';
            }
        }

        function scheduleSave() {
            window.clearTimeout(timer);
            timer = window.setTimeout(saveDraft, 250);
        }

        window.rqMinaPlanHasUnsavedChanges = isDirty;

        form.addEventListener('input', scheduleSave);
        form.addEventListener('change', scheduleSave);
        form.addEventListener('click', function (event) {
            const mutatesPlan = event.target.closest('[data-add-plan-group], [data-remove-group], [data-add-activity], [data-remove-activity], [data-add-transport], [data-remove-transport], [data-add-personnel-row], [data-remove-personnel-row], .rq-plan-sait-check');
            if (mutatesPlan) {
                scheduleSave();
            }
        });
        form.addEventListener('submit', function () {
            window.clearTimeout(timer);
            form.querySelector('[name="draft_id"]').value = saveDraft();
            submitting = true;
        });
        window.addEventListener('beforeunload', function (event) {
            if (submitting || !isDirty()) return;
            saveDraft();
            event.preventDefault();
            event.returnValue = '';
        });
        window.addEventListener('pageshow', function () {
            submitting = false;
        });

        recoverButton?.addEventListener('click', function () {
            if (!draft || draft.base !== base) return;
            if (isDirty() && !window.confirm('Recuperar esta copia reemplazará los cambios que acabas de escribir. ¿Continuar?')) return;
            personnelEditor.setRows(draft.detalle);
            planEditor.setPlan(draft.plan_operativo);
            if (revisionField && typeof draft.revision === 'string' && draft.revision !== '') {
                revisionField.value = draft.revision;
            }
            draft = null;
            banner.hidden = true;
        });
        discardButton?.addEventListener('click', function () {
            window.clearTimeout(timer);
            if (draft && readDraft()?.id === draft.id) removeDraft();
            draft = null;
            banner.hidden = true;
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const manager = document.querySelector('[data-rq-plan-manager]');
        if (!manager) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const selector = manager.querySelector('[data-rq-plan-selector]');
        selector?.addEventListener('change', function () {
            const planId = selector.value || '';
            selector.value = manager.dataset.selectedPlanId || '';
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
            if (window.rqMinaPlanHasUnsavedChanges?.()) {
                showError(form, 'Guarda los cambios del programa antes de administrar planes.');
                return;
            }

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

                const planUrl = new URL(manager.dataset.planUrl, window.location.origin);
                const redirectUrl = new URL(data.data?.redirect_url || window.location.href, window.location.origin);
                if (redirectUrl.origin === window.location.origin && redirectUrl.pathname === planUrl.pathname) {
                    planUrl.searchParams.forEach(function (value, name) {
                        if (name.startsWith('lista[')) redirectUrl.searchParams.set(name, value);
                    });
                    window.location.href = redirectUrl.toString();
                }
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
