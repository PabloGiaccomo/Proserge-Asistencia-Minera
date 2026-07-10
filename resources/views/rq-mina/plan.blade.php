@extends('layouts.app')

@section('title', 'Plan Operativo Semanal')

@php
    $planOperativo = $item['plan_operativo'] ?? [];
    $detalle = $item['detalle'] ?? [];
    $fechaInicio = !empty($item['fecha_inicio']) ? \Carbon\Carbon::parse($item['fecha_inicio']) : null;
    $fechaFin = !empty($item['fecha_fin']) ? \Carbon\Carbon::parse($item['fecha_fin']) : null;
    $semana = $fechaInicio ? $fechaInicio->isoWeek() : null;
    $anioSemana = $fechaInicio ? $fechaInicio->isoWeekYear() : null;
    $canImportPlan = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'importar');
    $canUpdatePlan = \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'actualizar');
@endphp

@section('content')
<style>
.rqm-summary-card .card-body { padding:10px 16px; }
.rqm-summary-line { display:flex; align-items:center; gap:10px; overflow-x:auto; white-space:nowrap; }
.rqm-summary-item { display:inline-flex; align-items:center; gap:6px; min-width:max-content; padding-right:10px; border-right:1px solid #e2e8f0; }
.rqm-summary-item:last-child { border-right:0; padding-right:0; }
.rqm-summary-item.is-observation { min-width:0; }
.rqm-meta-label { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.35px; font-weight:700; }
.rqm-meta-value { font-size:13px; color:#0f172a; font-weight:700; }
.rqm-summary-item.is-observation .rqm-meta-value { display:inline-block; max-width:520px; overflow:hidden; text-overflow:ellipsis; vertical-align:bottom; font-weight:600; }
.rq-plan-draft-alert { display:flex; align-items:center; justify-content:space-between; gap:12px; border:1px solid #bae6fd; background:#f0f9ff; color:#075985; border-radius:12px; padding:12px 14px; margin-bottom:16px; font-size:13px; }
.rq-plan-draft-alert[hidden] { display:none; }
.rq-plan-draft-alert strong { display:block; color:#0f172a; margin-bottom:2px; }
.rq-plan-draft-alert button { border:1px solid #7dd3fc; background:#fff; color:#075985; border-radius:8px; padding:8px 10px; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; }
.rq-plan-draft-alert button:hover { background:#e0f2fe; }
@media (max-width:720px) {
    .rqm-summary-line { align-items:flex-start; }
    .rqm-summary-item.is-observation .rqm-meta-value { max-width:280px; }
    .rq-plan-draft-alert { flex-direction:column; align-items:flex-start; }
    .rq-plan-draft-alert button { width:100%; }
}
</style>

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
            @if($canImportPlan)
                <a href="{{ route('rq-mina.plan.importar', $item['id']) }}" class="btn btn-primary">Importar plan operativo</a>
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
    data-draft-key="rq_mina_plan_draft:{{ $item['id'] }}"
    data-personnel-editor-id="rqPersonalRequestEditor"
    data-plan-editor-id="rqPlanOperativoEditor"
>
    @csrf
    @method('PUT')

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
    ])

    <div class="form-actions" style="margin-top:16px;">
        <a href="{{ route('rq-mina.show', $item['id']) }}" class="btn btn-outline">Cancelar</a>
        @if($canUpdatePlan)
            <button type="submit" class="btn btn-primary">Guardar plan operativo</button>
        @endif
    </div>
</form>

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
        form.addEventListener('input', scheduleSave);
        form.addEventListener('change', scheduleSave);
        form.addEventListener('click', function () {
            window.setTimeout(scheduleSave, 0);
        });
        form.addEventListener('submit', saveDraft);
        window.addEventListener('beforeunload', saveDraft);

        discardButton?.addEventListener('click', function () {
            try {
                window.localStorage.removeItem(draftKey);
            } catch (error) {
                // No requiere accion adicional.
            }
            window.location.reload();
        });
    });
})();
</script>
@endsection
