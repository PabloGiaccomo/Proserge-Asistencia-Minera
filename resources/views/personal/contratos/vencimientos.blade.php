@extends('layouts.app')

@section('title', 'Vencimientos de contratos - Proserge')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canManage = \App\Support\Rbac\PermissionMatrix::allowsAny($permissions, 'personal', ['actualizar', 'administrar']);
    $canDownloadContractFormats = \App\Support\Rbac\PermissionMatrix::allows($permissions, 'personal', 'exportar');
    $canViewContractAmounts = $canManage || $canDownloadContractFormats;
    $canSelectRows = $canManage || $canDownloadContractFormats;
    $month = (int) ($filters['mes'] ?? now()->month);
    $year = (int) ($filters['anio'] ?? now()->year);
    $workerFilter = trim((string) ($filters['trabajador'] ?? ''));
    $isWorkerFilterMode = $workerFilter !== '';
    $today = \Illuminate\Support\Carbon::today();
    $formatDate = function ($date): string {
        if (!$date) {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return '-';
        }
    };
    $decisionLabel = fn ($value): string => $decisionOptions[$value ?: \App\Models\PersonalContrato::DECISION_PENDIENTE] ?? 'Pendiente';
    $reasonLabel = fn ($value): string => $value ? ($reasonOptions[$value] ?? $value) : '-';
    $contractTypeOptions = $contractTypeOptions ?? [];
    $monthNames = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre',
    ];
@endphp

<style>
.expiry-page {
    display: grid;
    gap: 16px;
}
.expiry-filters {
    display: grid;
    grid-template-columns: minmax(140px, 0.8fr) minmax(120px, 0.7fr) minmax(220px, 1.25fr) minmax(190px, 1.1fr) minmax(170px, 1fr) minmax(170px, 1fr) auto;
    gap: 12px;
    align-items: end;
}
.expiry-filters label {
    display: grid;
    gap: 5px;
    color: #475569;
    font-size: 12px;
    font-weight: 800;
}
.expiry-filters input,
.expiry-filters select,
.expiry-field {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    padding: 9px 10px;
    color: #0f172a;
    background: #fff;
}
.expiry-filters input:disabled,
.expiry-filters select:disabled {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
}
.expiry-filter-actions {
    display: flex;
    align-items: end;
    gap: 8px;
    min-height: 100%;
}
.expiry-filter-note {
    margin: 10px 0 0;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
}
.expiry-page.is-filtering .expiry-table-wrap,
.expiry-page.is-filtering .card:nth-of-type(2) {
    opacity: 0.62;
    pointer-events: none;
}
.expiry-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.expiry-actions-menu {
    position: relative;
}
.expiry-actions-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.expiry-actions-list {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    z-index: 30;
    min-width: 230px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 8px;
    background: #ffffff;
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.14);
}
.expiry-actions-list[hidden] {
    display: none;
}
.expiry-actions-list button {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 0;
    border-radius: 9px;
    padding: 10px 11px;
    background: transparent;
    color: #0f172a;
    font-weight: 800;
    text-align: left;
    cursor: pointer;
}
.expiry-actions-list button:hover {
    background: #f8fafc;
}
.expiry-table-wrap {
    overflow-x: auto;
}
.expiry-table {
    width: 100%;
    min-width: 1220px;
    border-collapse: collapse;
}
.expiry-table th,
.expiry-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 11px;
    text-align: left;
    vertical-align: middle;
}
.expiry-table th {
    color: #475569;
    font-size: 12px;
    text-transform: uppercase;
}
.expiry-select-cell {
    width: 42px;
    min-width: 42px;
    text-align: center !important;
}
.expiry-select-check {
    width: 16px;
    height: 16px;
    accent-color: #0d9488;
    cursor: pointer;
}
.expiry-contract-row {
    cursor: pointer;
    transition: background-color 0.14s ease;
}
.expiry-contract-row:hover {
    background: #f8fafc;
}
.expiry-contract-row.is-selected {
    background: #ecfeff;
}
.expiry-badge {
    display: inline-flex;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 800;
    background: #e2e8f0;
    color: #334155;
}
.expiry-badge.warn {
    background: #fef3c7;
    color: #92400e;
}
.expiry-badge.danger {
    background: #fee2e2;
    color: #991b1b;
}
.expiry-badge.ok {
    background: #dcfce7;
    color: #166534;
}
.expiry-muted {
    color: #64748b;
    font-size: 12px;
    line-height: 1.35;
}
.expiry-worker-name-wrap {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.expiry-worker-name {
    color: #0f172a;
}
.expiry-worker-name.has-history {
    cursor: help;
    border-bottom: 1px dotted #94a3b8;
}
.expiry-worker-history-hint {
    width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 11px;
    font-weight: 900;
}
.expiry-worker-history-tooltip {
    position: fixed;
    z-index: 120;
    width: min(430px, calc(100vw - 28px));
    max-height: 340px;
    overflow: auto;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: 12px;
    background: #ffffff;
    box-shadow: 0 20px 42px rgba(15, 23, 42, 0.18);
    color: #0f172a;
}
.expiry-worker-history-tooltip[hidden] {
    display: none;
}
.expiry-worker-history-title {
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    color: #0f766e;
}
.expiry-worker-history-item {
    display: grid;
    gap: 4px;
    padding: 9px 0;
    border-top: 1px solid #e2e8f0;
}
.expiry-worker-history-item:first-of-type {
    border-top: 0;
    padding-top: 0;
}
.expiry-worker-history-period {
    font-size: 13px;
    font-weight: 900;
}
.expiry-worker-history-detail {
    color: #475569;
    font-size: 12px;
    line-height: 1.35;
}
.expiry-action-icons {
    display: flex;
    align-items: center;
    gap: 8px;
}
.expiry-icon-action {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    color: #0f766e;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease;
}
.expiry-icon-action:hover:not(:disabled) {
    border-color: #5eead4;
    background: #f0fdfa;
    transform: translateY(-1px);
}
.expiry-icon-action:disabled {
    color: #94a3b8;
    background: #f8fafc;
    cursor: not-allowed;
}
.expiry-modal {
    position: fixed;
    inset: 0;
    z-index: 90;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.expiry-modal[hidden] {
    display: none;
}
.expiry-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.48);
}
.expiry-modal-card {
    position: relative;
    z-index: 1;
    width: min(760px, calc(100vw - 28px));
    max-height: min(92vh, 760px);
    display: flex;
    flex-direction: column;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 28px 70px rgba(15, 23, 42, 0.25);
    overflow: hidden;
}
.expiry-modal-header,
.expiry-modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid #e2e8f0;
}
.expiry-modal-footer {
    border-top: 1px solid #e2e8f0;
    border-bottom: 0;
    justify-content: flex-end;
}
.expiry-modal-title {
    margin: 0;
    color: #0f172a;
    font-size: 20px;
    font-weight: 900;
}
.expiry-modal-subtitle {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 13px;
}
.expiry-modal-close {
    width: 34px;
    height: 34px;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    cursor: pointer;
}
.expiry-modal-body {
    display: grid;
    gap: 14px;
    padding: 18px 20px;
    overflow: auto;
}
.expiry-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.expiry-form-grid label,
.expiry-form-full {
    display: grid;
    gap: 5px;
    color: #334155;
    font-size: 12px;
    font-weight: 800;
}
.expiry-form-full {
    grid-column: 1 / -1;
}
.expiry-field textarea,
textarea.expiry-field {
    min-height: 82px;
    resize: vertical;
}
.expiry-selected-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.expiry-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #ccfbf1;
    border-radius: 999px;
    padding: 6px 9px;
    background: #f0fdfa;
    color: #0f766e;
    font-size: 12px;
    font-weight: 800;
}
.expiry-toast-stack {
    position: fixed;
    right: 18px;
    bottom: 18px;
    z-index: 120;
    display: grid;
    gap: 8px;
    width: min(390px, calc(100vw - 28px));
}
.expiry-toast {
    border: 1px solid #ccfbf1;
    border-radius: 12px;
    padding: 11px 13px;
    background: #f0fdfa;
    color: #0f766e;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.16);
    font-size: 13px;
    font-weight: 800;
}
.expiry-toast.is-danger {
    border-color: #fecaca;
    background: #fef2f2;
    color: #991b1b;
}
.expiry-toast.is-warn {
    border-color: #fde68a;
    background: #fffbeb;
    color: #92400e;
}
@media (max-width: 1100px) {
    .expiry-filters {
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    }
}
@media (max-width: 720px) {
    .expiry-form-grid {
        grid-template-columns: 1fr;
    }
    .expiry-modal {
        padding: 10px;
    }
}
</style>

<div class="module-page expiry-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Vencimientos de contratos</h1>
                <p class="page-subtitle">
                    @if($isWorkerFilterMode)
                        Historial de contratos del trabajador buscado.
                    @else
                        Contratos que vencen dentro del mes seleccionado, incluyendo historicos.
                    @endif
                </p>
            </div>
            <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">Personal</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-header"><span class="card-title">Filtros</span></div>
        <div class="card-body">
            <form method="GET" action="{{ route('personal.contratos.expiring') }}" class="expiry-filters" id="expiryFiltersForm" autocomplete="off">
                <label>
                    Mes
                    <select name="mes" data-auto-filter data-date-filter @disabled($isWorkerFilterMode)>
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}" @selected($month === $i)>{{ str_pad((string) $i, 2, '0', STR_PAD_LEFT) }} - {{ $monthNames[$i] }}</option>
                        @endfor
                    </select>
                </label>
                <label>
                    Anio
                    <input type="number" name="anio" min="2000" max="2100" value="{{ $year }}" data-auto-filter data-date-filter @disabled($isWorkerFilterMode)>
                </label>
                <label>
                    Trabajador
                    <input type="search" name="trabajador" value="{{ $workerFilter }}" placeholder="Buscar nombre del trabajador" data-auto-filter data-worker-filter data-filter-delay="450">
                </label>
                <label>
                    Cargo / puesto
                    <input type="search" name="cargo" value="{{ $filters['cargo'] ?? '' }}" placeholder="Buscar cargo o puesto" data-auto-filter data-filter-delay="450">
                </label>
                <label>
                    Estado laboral
                    <select name="estado_laboral" data-auto-filter>
                        <option value="">Todos</option>
                        @foreach(['ACTIVO' => 'Activo', 'FALTA_CONTRATO' => 'Falta contrato', 'INACTIVO' => 'Inactivo', 'CESADO' => 'Cesado'] as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['estado_laboral'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Tipo de contrato
                    <select name="tipo_contrato" data-auto-filter>
                        <option value="">Todos</option>
                        @foreach($contractTypeOptions as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['tipo_contrato'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="expiry-filter-actions">
                    <a href="{{ route('personal.contratos.expiring') }}" class="btn btn-outline btn-sm">Limpiar</a>
                </div>
            </form>
            <p class="expiry-filter-note">
                @if($isWorkerFilterMode)
                    Al buscar trabajador se muestran todos sus contratos y se bloquea el filtro de mes y anio.
                @else
                    Los filtros se aplican automaticamente al cambiar un campo.
                @endif
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header expiry-card-header">
            <div>
                <span class="card-title">Contratos encontrados: {{ $contratos->count() }}</span>
                @if($canSelectRows && $contratos->isNotEmpty())
                    <div class="expiry-muted" id="expirySelectedCount">0 trabajadores seleccionados</div>
                @endif
            </div>
            @if($canDownloadContractFormats && $contratos->isNotEmpty())
                <div class="expiry-actions-menu" data-expiry-actions-menu>
                    <button type="button" class="btn btn-primary btn-sm expiry-actions-toggle" id="expiryActionsToggle" aria-expanded="false" aria-controls="expiryActionsList">
                        Acciones
                        <span aria-hidden="true">v</span>
                    </button>
                    <div class="expiry-actions-list" id="expiryActionsList" hidden>
                        <button type="button" data-expiry-contract-format-action>
                            <span>Renovacion de contrato</span>
                            <small id="expiryActionsSelectedBadge">0 sel.</small>
                        </button>
                    </div>
                </div>
            @endif
        </div>
        <div class="card-body">
            @if($contratos->isEmpty())
                <p class="text-muted" style="margin:0;">No hay contratos con vencimiento en el periodo seleccionado.</p>
            @else
                <div class="expiry-table-wrap">
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                @if($canSelectRows)
                                    <th class="expiry-select-cell">
                                        <input type="checkbox" id="expirySelectAllWorkers" class="expiry-select-check" aria-label="Seleccionar todos los contratos visibles">
                                    </th>
                                @endif
                                <th>Trabajador</th>
                                <th>Documento</th>
                                <th>Cargo</th>
                                <th>Periodo</th>
                                <th>Dias</th>
                                <th>Tipo de contrato</th>
                                <th>Estado</th>
                                <th>Decision</th>
                                <th>Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($contratos as $contrato)
                                @php
                                    $personal = $contrato->personal;
                                    $end = $contrato->fecha_fin;
                                    $days = $end ? (int) floor($today->diffInDays($end, false)) : null;
                                    $decision = $contrato->getAttribute('decision_visual') ?: ($contrato->estado_decision_renovacion ?: \App\Models\PersonalContrato::DECISION_PENDIENTE);
                                    $isInferredDecision = (bool) $contrato->getAttribute('decision_visual_inferida');
                                    $hasPreparation = (bool) $contrato->getAttribute('has_preparation_contract');
                                    $hasLaterContract = (bool) $contrato->getAttribute('has_later_contract');
                                    $visualContractState = $contrato->getAttribute('estado_visual') ?: strtoupper((string) $contrato->estado);
                                    $hasSigned = $contrato->hasSignedFile();
                                    $canRegisterDecision = $canManage && (bool) $contrato->getAttribute('can_register_decision');
                                    $nextStart = $end ? $end->copy()->addDay()->toDateString() : now()->toDateString();
                                    $workerDocument = $personal?->numero_documento ?: $personal?->dni;
                                    $contractType = $contrato->getAttribute('tipo_contrato_visual') ?: 'SIN_TIPO';
                                    $contractTypeLabel = $contrato->getAttribute('tipo_contrato_label') ?: ($contractType === 'SIN_TIPO' ? '-' : $contractType);
                                    $workerPayload = [
                                        'id' => (string) ($personal?->id ?? ''),
                                        'nombre' => (string) ($personal?->nombre_completo ?: 'Trabajador'),
                                        'documento' => (string) ($workerDocument ?: ''),
                                        'puesto' => (string) ($contrato->puesto ?: $personal?->puesto ?: ''),
                                    ];
                                    $previousContractsPayload = collect($contrato->getAttribute('previous_contracts_summary') ?? [])
                                        ->map(fn ($item): array => [
                                            'numero' => (int) ($item['numero'] ?? 0),
                                            'periodo' => $formatDate($item['fecha_inicio'] ?? null) . ' al ' . $formatDate($item['fecha_fin'] ?? null),
                                            'puesto' => filled($item['puesto'] ?? '') ? (string) $item['puesto'] : '-',
                                            'remuneracion' => $canViewContractAmounts && filled($item['remuneracion'] ?? '') ? (string) $item['remuneracion'] : null,
                                            'costo_hora' => $canViewContractAmounts && filled($item['costo_hora'] ?? '') ? (string) $item['costo_hora'] : null,
                                        ])
                                        ->values();
                                @endphp
                                <tr class="expiry-contract-row"
                                    data-contract-id="{{ $contrato->id }}"
                                    data-can-decision="{{ $canRegisterDecision ? '1' : '0' }}"
                                    data-contract-type="{{ $contractType }}"
                                    data-next-start="{{ $nextStart }}"
                                    data-worker-url="{{ route('personal.show', $contrato->personal_id) }}"
                                    data-contract-worker='@json($workerPayload)'
                                    data-worker-name="{{ $personal?->nombre_completo ?: 'Trabajador' }}"
                                    data-worker-document="{{ $workerDocument ?: '' }}"
                                    data-worker-position="{{ $contrato->puesto ?: $personal?->puesto ?: '' }}">
                                    @if($canSelectRows)
                                        <td class="expiry-select-cell" onclick="event.stopPropagation()">
                                            <input
                                                type="checkbox"
                                                class="expiry-select-check js-expiry-contract-worker-check"
                                                data-contract-format-worker-check
                                                data-contract-id="{{ $contrato->id }}"
                                                data-contract-type="{{ $contractType }}"
                                                data-can-decision="{{ $canRegisterDecision ? '1' : '0' }}"
                                                value="{{ $personal?->id ?? '' }}"
                                                data-worker-name="{{ $personal?->nombre_completo ?: 'Trabajador' }}"
                                                data-worker-payload='@json($workerPayload)'
                                                aria-label="Seleccionar {{ $personal?->nombre_completo ?: 'trabajador' }}"
                                                @disabled(!$personal?->id)>
                                        </td>
                                    @endif
                                    <td>
                                        @if($previousContractsPayload->isNotEmpty())
                                            <span class="expiry-worker-name-wrap">
                                                <strong
                                                    class="expiry-worker-name has-history js-expiry-worker-history"
                                                    tabindex="0"
                                                    data-contract-history='@json($previousContractsPayload)'
                                                    aria-label="Ver contratos anteriores de {{ $personal?->nombre_completo ?: 'trabajador' }}">
                                                    {{ $personal?->nombre_completo ?: 'Sin trabajador' }}
                                                </strong>
                                                <span class="expiry-worker-history-hint" aria-hidden="true">i</span>
                                            </span>
                                        @else
                                            <strong class="expiry-worker-name">{{ $personal?->nombre_completo ?: 'Sin trabajador' }}</strong>
                                        @endif
                                        <div class="expiry-muted">{{ $contratoService->contractDisplayLabel($contrato) }}</div>
                                    </td>
                                    <td>{{ $personal?->tipo_documento ?: 'DNI' }} {{ $workerDocument }}</td>
                                    <td>
                                        <strong>{{ $contrato->puesto ?: $personal?->puesto ?: '-' }}</strong>
                                    </td>
                                    <td>{{ $formatDate($contrato->fecha_inicio) }} al {{ $formatDate($contrato->fecha_fin) }}</td>
                                    <td>
                                        @if($days === null)
                                            <span class="expiry-badge">Sin fecha</span>
                                        @elseif($days < 0)
                                            <span class="expiry-badge danger">Vencido hace {{ abs($days) }} dias</span>
                                        @elseif($days <= 7)
                                            <span class="expiry-badge danger">Vence en {{ $days }} dias</span>
                                        @elseif($days <= 30)
                                            <span class="expiry-badge warn">Vence en {{ $days }} dias</span>
                                        @else
                                            <span class="expiry-badge ok">{{ $days }} dias</span>
                                        @endif
                                    </td>
                                    <td>{{ $contractTypeLabel }}</td>
                                    <td>
                                        <div><span class="expiry-badge {{ $visualContractState === 'RENOVADO' ? 'ok' : '' }}">{{ ucfirst(strtolower($visualContractState)) }}</span></div>
                                        @if($hasLaterContract)
                                            <div class="expiry-muted" style="margin-top:5px;">Tiene contrato posterior.</div>
                                        @endif
                                        <div class="expiry-muted" style="margin-top:5px;">Laboral: {{ ucfirst(strtolower($personal?->estado ?: '-')) }}</div>
                                        @if($hasPreparation && !$hasLaterContract)
                                            <div class="expiry-muted" style="margin-top:5px;">Renovacion en preparacion</div>
                                        @endif
                                        @if(strtoupper((string) $contrato->estado) === \App\Models\PersonalContrato::ESTADO_ACTIVO && !$hasSigned)
                                            <div class="expiry-muted" style="margin-top:5px;">Falta contrato firmado vigente</div>
                                        @endif
                                    </td>
                                    <td>
                                        <strong class="js-expiry-decision-label">{{ $decisionLabel($decision) }}</strong>
                                        @if($isInferredDecision)
                                            <div class="expiry-muted js-expiry-decision-note">Inferido por contrato posterior.</div>
                                        @elseif($contrato->decision_final)
                                            <div class="expiry-muted js-expiry-decision-note">Final: {{ $decisionLabel($contrato->decision_final) }}</div>
                                        @else
                                            <div class="expiry-muted js-expiry-decision-note">Sin decision final.</div>
                                        @endif
                                        @if($contrato->motivo_no_renovacion)
                                            <div class="expiry-muted">Motivo: {{ $reasonLabel($contrato->motivo_no_renovacion) }}</div>
                                        @endif
                                    </td>
                                    <td class="js-expiry-registry-cell">
                                        {{ $contrato->decisionUsuario?->personal?->nombre_completo ?: $contrato->decisionUsuario?->email ?: ($isInferredDecision ? 'Sistema' : '-') }}
                                        <div class="expiry-muted">{{ $contrato->fecha_decision ? $contrato->fecha_decision->format('d/m/Y H:i') : ($isInferredDecision ? 'Inferido' : 'Sin registro') }}</div>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div class="expiry-action-icons">
                                            @if($canManage)
                                                <button
                                                    type="button"
                                                    class="expiry-icon-action js-expiry-open-decision"
                                                    title="Registrar decision"
                                                    aria-label="Registrar decision"
                                                    data-contract-id="{{ $contrato->id }}"
                                                    @disabled(!$canRegisterDecision)>
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="M7 11.5L10.2 14.7L17 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M5 4H19C20.1 4 21 4.9 21 6V18C21 19.1 20.1 20 19 20H5C3.9 20 3 19.1 3 18V6C3 4.9 3.9 4 5 4Z" stroke="currentColor" stroke-width="2"/>
                                                    </svg>
                                                </button>
                                            @else
                                                <span class="expiry-muted">-</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<div id="expiryWorkerHistoryTooltip" class="expiry-worker-history-tooltip" hidden></div>

@if($canManage)
    <div id="expiryDecisionModal" class="expiry-modal" hidden>
        <div class="expiry-modal-backdrop" data-expiry-modal-close></div>
        <form id="expiryDecisionForm" class="expiry-modal-card" action="{{ route('personal.contratos.bulk-decision') }}" method="POST">
            @csrf
            <div class="expiry-modal-header">
                <div>
                    <h2 class="expiry-modal-title">Registrar decision</h2>
                    <p class="expiry-modal-subtitle" id="expiryDecisionSubtitle">Selecciona los datos para los contratos marcados.</p>
                </div>
                <button type="button" class="expiry-modal-close" data-expiry-modal-close aria-label="Cerrar">X</button>
            </div>
            <div class="expiry-modal-body">
                <div>
                    <strong id="expiryDecisionSelectedCount">0 contratos seleccionados</strong>
                    <div class="expiry-selected-list" id="expiryDecisionSelectedList" style="margin-top:8px;"></div>
                </div>
                <div id="expiryDecisionContractInputs"></div>
                <div class="expiry-form-grid">
                    <label>
                        Decision
                        <select name="estado_decision_renovacion" id="expiryDecisionState" class="expiry-field" required>
                            @foreach($decisionOptions as $key => $label)
                                @if(in_array($key, [
                                    \App\Models\PersonalContrato::DECISION_PENDIENTE,
                                    \App\Models\PersonalContrato::DECISION_EN_EVALUACION,
                                    \App\Models\PersonalContrato::DECISION_RENOVAR,
                                    \App\Models\PersonalContrato::DECISION_NO_RENOVAR,
                                ], true))
                                    <option value="{{ $key }}" @selected($key === \App\Models\PersonalContrato::DECISION_RENOVAR)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label data-expiry-no-renewal-field hidden>
                        Motivo si no renueva
                        <select name="motivo_no_renovacion" class="expiry-field">
                            <option value="">Seleccionar motivo</option>
                            @foreach($reasonOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label data-expiry-renewal-field>
                        Inicio del nuevo contrato
                        <input type="date" name="fecha_inicio" id="expiryRenewalStart" class="expiry-field">
                    </label>
                    <label data-expiry-renewal-field>
                        Fin del nuevo contrato
                        <input type="date" name="fecha_fin" class="expiry-field">
                    </label>
                    <label class="expiry-form-full" data-expiry-renewal-field>
                        Observacion de renovacion
                        <textarea name="observacion_renovacion" class="expiry-field" maxlength="5000" placeholder="Referencia interna de la renovacion"></textarea>
                    </label>
                    <label class="expiry-form-full">
                        Observacion de decision
                        <textarea name="observacion_decision" class="expiry-field" maxlength="5000" placeholder="Observacion operativa"></textarea>
                    </label>
                </div>
            </div>
            <div class="expiry-modal-footer">
                <button type="button" class="btn btn-outline" data-expiry-modal-close>Cancelar</button>
                <button type="submit" class="btn btn-primary" id="expiryDecisionSubmit">Guardar decision</button>
            </div>
        </form>
    </div>
@endif

<div id="expiryToastStack" class="expiry-toast-stack" aria-live="polite"></div>

@include('personal.partials.contract-format-modal', [
    'contractFormatCanDownload' => $canDownloadContractFormats,
    'contractFormatSelectedWorkerSelector' => '.js-expiry-contract-worker-check:checked',
])

<script>
(function () {
    const form = document.getElementById('expiryFiltersForm');
    if (!form) return;

    let timer = null;
    let isSubmitting = false;
    const workerField = form.querySelector('[data-worker-filter]');
    const dateFields = Array.from(form.querySelectorAll('[data-date-filter]'));

    const toggleDateFields = function () {
        const hasWorker = String(workerField?.value || '').trim() !== '';
        dateFields.forEach(function (field) {
            field.disabled = hasWorker;
        });
    };

    const submitFilters = function (delay) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (isSubmitting) return;
            toggleDateFields();
            isSubmitting = true;
            document.querySelector('.expiry-page')?.classList.add('is-filtering');
            form.submit();
        }, delay || 0);
    };

    toggleDateFields();

    form.querySelectorAll('[data-auto-filter]').forEach(function (field) {
        const eventName = field.tagName === 'INPUT' && field.type === 'search' ? 'input' : 'change';
        field.addEventListener(eventName, function () {
            if (field === workerField) {
                toggleDateFields();
            }
            const delay = Number(field.dataset.filterDelay || 0);
            submitFilters(delay);
        });
    });
})();

(function () {
    const tooltip = document.getElementById('expiryWorkerHistoryTooltip');
    const triggers = Array.from(document.querySelectorAll('.js-expiry-worker-history'));
    if (!tooltip || triggers.length === 0) return;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function parseHistory(trigger) {
        try {
            return JSON.parse(trigger.dataset.contractHistory || '[]');
        } catch (error) {
            return [];
        }
    }

    function renderHistory(items) {
        const rows = items.map(function (item) {
            const salary = item.remuneracion !== null && item.remuneracion !== undefined
                ? '<div class="expiry-worker-history-detail">Salario: ' + escapeHtml(item.remuneracion || '-') + '</div>'
                : '';
            const hourly = item.costo_hora !== null && item.costo_hora !== undefined
                ? '<div class="expiry-worker-history-detail">Salario por hora: ' + escapeHtml(item.costo_hora || '-') + '</div>'
                : '';

            return '<div class="expiry-worker-history-item">'
                + '<div class="expiry-worker-history-period">Contrato ' + escapeHtml(item.periodo || '-') + '</div>'
                + '<div class="expiry-worker-history-detail">Cargo: ' + escapeHtml(item.puesto || '-') + '</div>'
                + salary
                + hourly
                + '</div>';
        }).join('');

        tooltip.innerHTML = '<div class="expiry-worker-history-title">Contratos anteriores</div>' + rows;
    }

    function placeTooltip(eventOrElement) {
        const rect = eventOrElement instanceof Element
            ? eventOrElement.getBoundingClientRect()
            : eventOrElement.currentTarget.getBoundingClientRect();
        const preferredLeft = rect.left;
        const preferredTop = rect.bottom + 10;
        const maxLeft = window.innerWidth - tooltip.offsetWidth - 14;
        const maxTop = window.innerHeight - tooltip.offsetHeight - 14;

        tooltip.style.left = Math.max(14, Math.min(preferredLeft, maxLeft)).toString() + 'px';
        tooltip.style.top = Math.max(14, Math.min(preferredTop, maxTop)).toString() + 'px';
    }

    function showTooltip(event) {
        const items = parseHistory(event.currentTarget);
        if (!Array.isArray(items) || items.length === 0) return;
        renderHistory(items);
        tooltip.removeAttribute('hidden');
        placeTooltip(event);
    }

    function hideTooltip() {
        tooltip.setAttribute('hidden', '');
    }

    triggers.forEach(function (trigger) {
        trigger.addEventListener('mouseenter', showTooltip);
        trigger.addEventListener('focus', showTooltip);
        trigger.addEventListener('mousemove', placeTooltip);
        trigger.addEventListener('mouseleave', hideTooltip);
        trigger.addEventListener('blur', hideTooltip);
    });

    window.addEventListener('scroll', hideTooltip, true);
    window.addEventListener('resize', hideTooltip);
})();

(function () {
    const canManage = @json($canManage);
    const csrfToken = @json(csrf_token());
    const decisionEndpoint = @json(route('personal.contratos.bulk-decision'));
    const decisionLabels = @json($decisionOptions);
    const decisionRenew = @json(\App\Models\PersonalContrato::DECISION_RENOVAR);
    const decisionNoRenew = @json(\App\Models\PersonalContrato::DECISION_NO_RENOVAR);
    const checks = Array.from(document.querySelectorAll('.js-expiry-contract-worker-check'));
    const selectAll = document.getElementById('expirySelectAllWorkers');
    const selectedCount = document.getElementById('expirySelectedCount');
    const selectedBadge = document.getElementById('expiryActionsSelectedBadge');
    const actionsToggle = document.getElementById('expiryActionsToggle');
    const actionsList = document.getElementById('expiryActionsList');
    const contractFormatAction = document.querySelector('[data-expiry-contract-format-action]');
    const modal = document.getElementById('expiryDecisionModal');
    const modalForm = document.getElementById('expiryDecisionForm');
    const modalInputs = document.getElementById('expiryDecisionContractInputs');
    const modalCount = document.getElementById('expiryDecisionSelectedCount');
    const modalList = document.getElementById('expiryDecisionSelectedList');
    const modalState = document.getElementById('expiryDecisionState');
    const renewalStart = document.getElementById('expiryRenewalStart');
    const submitButton = document.getElementById('expiryDecisionSubmit');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function showToast(message, type) {
        const stack = document.getElementById('expiryToastStack');
        if (!stack) return;

        const toast = document.createElement('div');
        toast.className = 'expiry-toast' + (type ? ' is-' + type : '');
        toast.textContent = message;
        stack.appendChild(toast);
        window.setTimeout(function () {
            toast.remove();
        }, 4200);
    }

    function selectedChecks() {
        return checks.filter(function (input) {
            return input.checked && !input.disabled && String(input.value || '').trim() !== '';
        });
    }

    function contractTypeOf(input) {
        return String(input?.dataset.contractType || 'SIN_TIPO').trim() || 'SIN_TIPO';
    }

    function typeLabel(type) {
        return type === 'SIN_TIPO' ? 'Sin tipo de contrato' : type;
    }

    function parseWorkerPayload(input) {
        let worker = {};
        try {
            worker = JSON.parse(input.dataset.workerPayload || input.closest('tr')?.dataset.contractWorker || '{}');
        } catch (error) {
            worker = {};
        }

        return {
            id: String(worker.id || input.value || '').trim(),
            nombre: String(worker.nombre || input.dataset.workerName || 'Trabajador').trim(),
            documento: String(worker.documento || '').trim(),
            puesto: String(worker.puesto || '').trim(),
        };
    }

    function compatibleWithCurrentSelection(input) {
        const selected = selectedChecks().filter(function (item) { return item !== input; });
        if (selected.length === 0) {
            return true;
        }

        return contractTypeOf(selected[0]) === contractTypeOf(input);
    }

    function updateSelection() {
        const selected = selectedChecks();
        const enabled = checks.filter(function (input) { return !input.disabled; });

        checks.forEach(function (input) {
            const row = input.closest('tr');
            const checked = input.checked && !input.disabled;
            row?.classList.toggle('is-selected', checked);

            const action = row?.querySelector('.js-expiry-open-decision');
            if (action && canManage) {
                const canDecision = row?.dataset.canDecision === '1';
                action.disabled = !checked || !canDecision;
                action.title = canDecision
                    ? (checked ? 'Registrar decision' : 'Marca el contrato para registrar decision')
                    : 'Solo contratos activos permiten registrar decision';
            }
        });

        if (selectAll) {
            selectAll.checked = enabled.length > 0 && selected.length === enabled.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < enabled.length;
            selectAll.disabled = enabled.length === 0;
        }

        if (selectedCount) {
            selectedCount.textContent = selected.length + ' trabajador(es) seleccionado(s)';
        }
        if (selectedBadge) {
            selectedBadge.textContent = selected.length + ' sel.';
        }
    }

    function isInteractiveTarget(target) {
        return !!target.closest('a, button, input, select, textarea, label, form, [role="button"], [contenteditable="true"]');
    }

    function openDecisionModal() {
        const selected = selectedChecks().filter(function (input) {
            return input.dataset.canDecision === '1';
        });

        if (selected.length === 0) {
            showToast('Marca al menos un contrato activo para registrar la decision.', 'warn');
            return;
        }

        const firstType = contractTypeOf(selected[0]);
        const mixed = selected.some(function (input) {
            return contractTypeOf(input) !== firstType;
        });
        if (mixed) {
            showToast('Selecciona contratos con el mismo tipo de contrato para continuar.', 'warn');
            return;
        }

        if (modalInputs) {
            modalInputs.innerHTML = selected.map(function (input) {
                return '<input type="hidden" name="contract_ids[]" value="' + escapeHtml(input.dataset.contractId || '') + '">';
            }).join('');
        }
        if (modalCount) {
            modalCount.textContent = selected.length + ' contrato(s) seleccionado(s) - ' + typeLabel(firstType);
        }
        if (modalList) {
            modalList.innerHTML = selected.map(function (input) {
                const worker = parseWorkerPayload(input);
                return '<span class="expiry-chip">' + escapeHtml(worker.nombre) + '</span>';
            }).join('');
        }
        if (modalState) {
            modalState.value = decisionRenew;
        }
        if (renewalStart) {
            renewalStart.value = selected[0].closest('tr')?.dataset.nextStart || '';
        }
        toggleDecisionFields();
        modal?.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeDecisionModal() {
        modal?.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    function toggleDecisionFields() {
        const value = modalState?.value || decisionRenew;
        const isRenew = value === decisionRenew;
        const isNoRenew = value === decisionNoRenew;
        document.querySelectorAll('[data-expiry-renewal-field]').forEach(function (field) {
            field.hidden = !isRenew;
        });
        document.querySelectorAll('[data-expiry-no-renewal-field]').forEach(function (field) {
            field.hidden = !isNoRenew;
        });
        if (renewalStart) {
            renewalStart.required = isRenew;
        }
    }

    checks.forEach(function (input) {
        input.addEventListener('change', function () {
            if (input.checked && !compatibleWithCurrentSelection(input)) {
                input.checked = false;
                showToast('Los contratos seleccionados deben tener el mismo tipo de contrato.', 'warn');
            }
            updateSelection();
        });

        input.closest('tr')?.addEventListener('click', function (event) {
            if (isInteractiveTarget(event.target) || input.disabled) {
                return;
            }

            input.checked = !input.checked;
            input.dispatchEvent(new Event('change', {bubbles: true}));
        });
    });

    document.querySelectorAll('.expiry-contract-row').forEach(function (row) {
        row.addEventListener('dblclick', function () {
            const url = row.dataset.workerUrl;
            if (url) {
                window.location.href = url;
            }
        });
    });

    selectAll?.addEventListener('change', function () {
        if (!selectAll.checked) {
            checks.forEach(function (input) {
                if (!input.disabled) {
                    input.checked = false;
                }
            });
            updateSelection();
            return;
        }

        const enabled = checks.filter(function (input) { return !input.disabled; });
        const first = enabled[0];
        const allowedType = contractTypeOf(first);
        let skipped = 0;

        enabled.forEach(function (input) {
            if (contractTypeOf(input) === allowedType) {
                input.checked = true;
            } else {
                input.checked = false;
                skipped++;
            }
        });

        if (skipped > 0) {
            showToast('Se seleccionaron solo contratos de tipo ' + typeLabel(allowedType) + '.', 'warn');
        }
        updateSelection();
    });

    actionsToggle?.addEventListener('click', function (event) {
        event.stopPropagation();
        const isHidden = actionsList?.hasAttribute('hidden');
        actionsList?.toggleAttribute('hidden', !isHidden);
        actionsToggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-expiry-actions-menu]')) {
            actionsList?.setAttribute('hidden', '');
            actionsToggle?.setAttribute('aria-expanded', 'false');
        }
    });

    contractFormatAction?.addEventListener('click', function () {
        const selected = selectedChecks();
        actionsList?.setAttribute('hidden', '');
        actionsToggle?.setAttribute('aria-expanded', 'false');

        if (selected.length === 0) {
            showToast('Selecciona trabajadores antes de descargar el formato de renovacion.', 'warn');
            return;
        }

        const type = contractTypeOf(selected[0]);
        if (selected.some(function (input) { return contractTypeOf(input) !== type; })) {
            showToast('Para descargar el formato, todos los seleccionados deben tener el mismo tipo de contrato.', 'warn');
            return;
        }

        if (typeof window.openContractFormatModal === 'function') {
            window.openContractFormatModal();
        }
    });

    document.querySelectorAll('.js-expiry-open-decision').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const row = button.closest('tr');
            const check = row?.querySelector('.js-expiry-contract-worker-check');
            if (check && !check.checked) {
                showToast('Marca primero el contrato de la izquierda.', 'warn');
                return;
            }
            openDecisionModal();
        });
    });

    document.querySelectorAll('[data-expiry-modal-close]').forEach(function (button) {
        button.addEventListener('click', closeDecisionModal);
    });

    modalState?.addEventListener('change', toggleDecisionFields);

    modalForm?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const selected = selectedChecks().filter(function (input) {
            return input.dataset.canDecision === '1';
        });
        if (selected.length === 0) {
            showToast('Marca al menos un contrato activo para registrar la decision.', 'warn');
            return;
        }

        const formData = new FormData(modalForm);
        formData.delete('contract_ids[]');
        selected.forEach(function (input) {
            formData.append('contract_ids[]', input.dataset.contractId || '');
        });

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Guardando...';
        }

        try {
            const response = await fetch(decisionEndpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'No se pudo registrar la decision.');
            }

            const selectedState = String(modalState?.value || decisionRenew);
            const label = decisionLabels[selectedState] || 'Decision registrada';
            selected.forEach(function (input) {
                const row = input.closest('tr');
                const decisionLabelTarget = row?.querySelector('.js-expiry-decision-label');
                const decisionNoteTarget = row?.querySelector('.js-expiry-decision-note');
                const registry = row?.querySelector('.js-expiry-registry-cell');
                if (decisionLabelTarget) {
                    decisionLabelTarget.textContent = data.summary?.renovaciones > 0 ? (decisionLabels.RENOVACION_PREPARADA || 'Renovacion preparada') : label;
                }
                if (decisionNoteTarget) {
                    decisionNoteTarget.textContent = selectedState === decisionRenew ? 'Renovacion en preparacion.' : 'Decision registrada.';
                }
                if (registry) {
                    registry.innerHTML = 'Registrado ahora<div class="expiry-muted">Hace un momento</div>';
                }
            });

            showToast(data.message || 'Decision registrada correctamente.');
            closeDecisionModal();
            updateSelection();
        } catch (error) {
            showToast(error.message || 'No se pudo registrar la decision.', 'danger');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Guardar decision';
            }
        }
    });

    updateSelection();
})();
</script>
@endsection
