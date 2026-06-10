@extends('layouts.app')

@section('title', 'Habilitación minera - Proserge')

@section('content')
@php
    $permissions = session('user.permissions', []);
    $canManage = \App\Support\Rbac\PermissionMatrix::allowsAny($permissions, 'personal', ['actualizar', 'administrar']);
    $currentQuery = request()->query();

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

    $stateLabel = fn ($state): string => $stateOptions[$state] ?? $state ?? '-';
    $examStateLabel = fn ($state): string => $examStateOptions[$state] ?? $state ?? '-';
    $attemptResultLabel = fn ($state): string => $attemptResultOptions[$state] ?? $state ?? '-';

    $mineBoard = $service->mineStatusBoardFor($selectedWorker);
    $requirementsByMine = $requirements->groupBy('mina_id');

    $workerLimitOptions = [10, 20, 50, 80, 100, 200];
    $assignmentPerPageOptions = [10, 15, 25, 50, 100];
    $workerLimit = (int) ($filters['worker_limit'] ?? request('worker_limit', 20));
    $assignmentPerPage = (int) ($filters['per_page'] ?? request('per_page', 15));

    $workerActiveFilters = [
        'trabajador' => filled($filters['trabajador'] ?? request('trabajador')),
        'mina_id' => filled($filters['mina_id'] ?? request('mina_id')),
        'estado_habilitacion' => filled($filters['estado_habilitacion'] ?? request('estado_habilitacion')),
        'estado_laboral' => filled($filters['estado_laboral'] ?? request('estado_laboral')),
    ];
    $workerActiveFilterCount = collect($workerActiveFilters)->filter()->count();

    $mineReqsJson = $requirementsByMine->map(function ($reqs) {
        return $reqs->map(function ($r) {
            return [
                'nombre' => $r->examen?->nombre ?: $r->nombre,
                'obligatorio' => (bool) $r->obligatorio,
                'tiene_vigencia' => (bool) $r->examen?->tiene_vigencia,
                'empresa_paga' => (bool) $r->examen?->empresa_paga,
                'max_intentos' => $r->examen?->max_intentos,
                'permite_convalidacion' => (bool) $r->examen?->permite_convalidacion,
                'permite_convalidacion_mina' => (bool) $r->permite_convalidacion_mina,
            ];
        })->values();
    });

    $assignmentSource = $assignments instanceof \Illuminate\Pagination\AbstractPaginator
        ? collect($assignments->items())
        : collect($assignments);

    $assignmentsJson = $assignmentSource->flatten(1)->map(function ($a) use ($service) {
        return [
            'id' => $a->id,
            'personal_nombre' => $a->personal?->nombre_completo,
            'mina_nombre' => $a->mina?->nombre,
            'estado_habilitacion' => $a->estadoHabilitacionActual(),
            'examenes' => $a->examenes->map(function ($e) {
                $attempts = $e->intentos;
                $attemptCount = $attempts->where('resultado', '!=', \App\Models\PersonalMinaExamenIntento::RESULTADO_ANULADO)->count();

                return [
                    'id' => $e->id,
                    'nombre' => $e->nombre_snapshot,
                    'estado' => $e->estado,
                    'lugar' => $e->lugar_snapshot,
                    'precio' => $e->precio_snapshot,
                    'max_intentos' => $e->max_intentos_snapshot,
                    'attempt_count' => $attemptCount,
                    'permite_reintento' => (bool) $e->permite_reintento_snapshot,
                    'tiene_vigencia' => (bool) $e->tiene_vigencia_snapshot,
                    'fecha_programacion' => $e->fecha_programacion ? $e->fecha_programacion->format('d/m/Y') : null,
                    'fecha_realizacion' => $e->fecha_realizacion ? $e->fecha_realizacion->format('d/m/Y') : null,
                    'fecha_vencimiento' => $e->fecha_vencimiento ? $e->fecha_vencimiento->format('d/m/Y') : null,
                    'intentos' => $attempts->map(function ($att) {
                        return [
                            'id' => $att->id,
                            'numero' => $att->numero_intento,
                            'resultado' => $att->resultado,
                            'nota' => $att->nota,
                            'observacion' => $att->observacion,
                            'archivo_nombre' => $att->archivo_nombre_original,
                            'archivo_url' => $att->archivo_path ? route('personal.habilitacion-minera.attempt.download', $att->id) : null,
                        ];
                    })->values(),
                ];
            })->values(),
            'warnings' => $service->warningsFor($a),
        ];
    })->values();
@endphp

<style>
    .mine-page {
        display: grid;
        gap: 16px;
    }

    .mine-worker-card {
        order: 1;
    }

    .mine-mines-card {
        order: 2;
    }

    .mine-assignments-card {
        order: 3;
    }

    .mine-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .mine-toolbar .page-subtitle {
        margin-top: 4px;
    }

    .mine-page .card {
        overflow: visible;
    }

    .mine-card-header,
    .mine-worker-header,
    .mine-list-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex-wrap: wrap;
    }

    .mine-header-copy {
        margin: 4px 0 0;
        color: #64748b;
        font-size: 12px;
        line-height: 1.4;
    }

    .mine-actions-menu {
        position: relative;
        display: inline-block;
    }

    .mine-actions-panel {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        z-index: 100;
        min-width: 280px;
        padding: 8px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 18px 44px rgba(15, 23, 42, 0.18);
    }

    .mine-actions-panel.open {
        display: grid;
        gap: 6px;
    }

    .mine-actions-panel form {
        margin: 0;
    }

    .mine-action-item {
        width: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #ffffff;
        color: #0f172a;
        padding: 10px 11px;
        display: grid;
        gap: 3px;
        text-align: left;
        cursor: pointer;
        transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
    }

    .mine-action-item:hover {
        border-color: #0d9488;
        background: #f0fdfa;
        transform: translateY(-1px);
    }

    .mine-action-item-title {
        font-size: 13px;
        font-weight: 900;
    }

    .mine-action-item-copy {
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.35;
    }

    .mine-board {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 10px;
    }

    .mine-tile {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        min-height: 114px;
        display: grid;
        gap: 8px;
        background: #ffffff;
        cursor: pointer;
        transition: box-shadow 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
    }

    .mine-tile:hover {
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.1);
        border-color: #94a3b8;
        transform: translateY(-1px);
    }

    .mine-tile.ok {
        border-color: #86efac;
        background: #f0fdf4;
    }

    .mine-tile.warn {
        border-color: #fde68a;
        background: #fffbeb;
    }

    .mine-tile.blocked {
        border-color: #cbd5e1;
        background: #f1f5f9;
        color: #64748b;
        cursor: default;
    }

    .mine-tile.blocked:hover {
        transform: none;
        box-shadow: none;
    }

    .mine-tile-title {
        font-weight: 900;
        color: #0f172a;
        line-height: 1.2;
    }

    .mine-muted {
        color: #64748b;
        font-size: 12px;
        line-height: 1.35;
    }

    .mine-action-hint {
        color: #0f172a;
        font-size: 12px;
        font-weight: 800;
        line-height: 1.25;
    }

    .mine-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: max-content;
        max-width: 100%;
        border-radius: 999px;
        padding: 4px 9px;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.1;
        background: #e2e8f0;
        color: #334155;
        border: 1px solid transparent;
        white-space: normal;
    }

    .mine-badge.ok {
        background: #dcfce7;
        color: #166534;
        border-color: #86efac;
    }

    .mine-badge.warn {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
    }

    .mine-badge.danger {
        background: #fee2e2;
        color: #991b1b;
        border-color: #fecaca;
    }

    .mine-badge.info {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: #93c5fd;
    }

    .mine-filter-count {
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
        font-size: 11px;
        font-weight: 800;
    }

    .mine-worker-filters {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px;
        background: #ffffff;
        margin-bottom: 12px;
    }

    .mine-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: flex-end;
    }

    .mine-filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 160px;
        flex: 1;
    }

    .mine-filter-group.is-wide {
        min-width: 240px;
        flex: 1.4;
    }

    .mine-filter-group.is-small {
        min-width: 120px;
        max-width: 150px;
        flex: 0 0 140px;
    }

    .mine-filter-label {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .mine-filter-label::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: #cbd5e1;
        transition: background-color 0.15s ease;
    }

    .mine-filter-control {
        width: 100%;
        min-height: 38px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 8px 11px;
        background: #ffffff;
        color: #0f172a;
        font-size: 13px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    }

    .mine-filter-control:hover {
        border-color: #cbd5e1;
    }

    .mine-filter-control:focus {
        outline: none;
        border-color: #19d3c5;
        box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.12);
    }

    .mine-filter-group.is-active .mine-filter-label {
        color: #0d9488;
    }

    .mine-filter-group.is-active .mine-filter-label::before {
        background: #0d9488;
    }

    .mine-filter-group.is-active .mine-filter-control {
        border-color: #0d9488;
        background: #f0fdfa;
        box-shadow: 0 0 0 2px rgba(13, 148, 136, 0.08);
    }

    .mine-filter-help {
        margin-top: 8px;
        color: #94a3b8;
        font-size: 11px;
    }

    .mine-table-wrap,
    .worker-table-wrap {
        overflow-x: auto;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
    }

    .mine-table,
    .worker-table {
        width: 100%;
        border-collapse: collapse;
    }

    .mine-table {
        min-width: 820px;
    }

    .worker-table {
        min-width: 700px;
    }

    .mine-table th,
    .mine-table td,
    .worker-table th,
    .worker-table td {
        border-bottom: 1px solid #f1f5f9;
        padding: 10px;
        text-align: left;
        vertical-align: top;
        font-size: 12px;
    }

    .worker-table td {
        vertical-align: middle;
    }

    .mine-table th,
    .worker-table th {
        color: #475569;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        background: #f8fafc;
        font-weight: 800;
    }

    .worker-table tbody tr:hover td,
    .mine-table tbody tr:hover td {
        background: #f8fafc;
    }

    .worker-table tbody tr.active td {
        background: #eff6ff;
    }

    .mine-table tbody tr:last-child td,
    .worker-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .mine-cell-main {
        display: grid;
        gap: 4px;
    }

    .mine-cell-subline {
        color: #64748b;
        font-size: 12px;
    }

    .mine-inline-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .mine-btn-link {
        background: none;
        border: 1px solid transparent;
        color: #2563eb;
        font-weight: 800;
        cursor: pointer;
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 11px;
    }

    .mine-btn-link:hover {
        background: #dbeafe;
        border-color: #bfdbfe;
    }

    .text-center {
        text-align: center !important;
    }

    .btn-xs {
        font-size: 11px;
        padding: 4px 8px;
        line-height: 1.1;
    }

    .mine-empty-state {
        text-align: center;
        padding: 18px;
        color: #64748b;
    }

    .mine-pagination-controls {
        margin-top: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .mine-pagination-summary {
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
    }

    .mine-pagination-links {
        display: flex;
        justify-content: flex-end;
    }

    .selected-worker-alert {
        margin-top: 12px;
    }

    .selected-worker-main {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        align-items: center;
    }

    .selected-worker-main span::before {
        content: " - ";
    }

    .selected-worker-warning {
        margin-top: 8px;
        color: #92400e;
        font-size: 12px;
        font-weight: 700;
    }

    .mine-exam-grid {
        display: grid;
        gap: 8px;
    }

    .mine-exam-item {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px;
        background: #ffffff;
        display: grid;
        gap: 8px;
    }

    .mine-exam-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
    }

    .mine-exam-titleline {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .mine-attempt-list {
        padding-left: 12px;
        border-left: 2px solid #e2e8f0;
        display: grid;
        gap: 4px;
    }

    .mine-dialog {
        width: min(1080px, calc(100vw - 28px));
        max-height: 90vh;
        margin: auto;
        position: fixed;
        inset: 0;
        border: 0;
        border-radius: 16px;
        padding: 0;
        background: #f8fafc;
        box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
    }

    .mine-dialog.is-compact {
        width: min(760px, calc(100vw - 28px));
    }

    .mine-dialog.is-wide {
        width: min(1180px, calc(100vw - 28px));
    }

    .mine-dialog.is-wide.no-body-scroll {
        height: min(90vh, 820px);
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .mine-dialog::backdrop {
        background: rgba(15, 23, 42, 0.45);
    }

    .mine-dialog-header {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        padding: 18px 20px;
        border-bottom: 1px solid #e2e8f0;
        background: #ffffff;
        border-radius: 16px 16px 0 0;
        position: sticky;
        top: 0;
        z-index: 2;
    }

    .mine-dialog-title {
        display: grid;
        gap: 3px;
    }

    .mine-dialog-kicker {
        color: #0d9488;
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .mine-dialog-title strong {
        color: #0f172a;
        font-size: 18px;
        line-height: 1.2;
    }

    .mine-dialog-subtitle {
        margin: 0;
        color: #64748b;
        font-size: 12px;
        line-height: 1.4;
    }

    .mine-dialog-close {
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        color: #475569;
        font-weight: 900;
        cursor: pointer;
    }

    .mine-dialog-close:hover {
        background: #f1f5f9;
        color: #0f172a;
    }

    .mine-dialog-body {
        padding: 18px 20px 20px;
        overflow: auto;
        max-height: calc(90vh - 78px);
        display: grid;
        gap: 16px;
    }

    .mine-dialog.no-body-scroll .mine-dialog-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        max-height: calc(90vh - 78px);
        grid-template-rows: none;
    }

    .mine-dialog.no-body-scroll .mine-dialog-header {
        flex: 0 0 auto;
        position: static;
    }

    .mine-columns {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 12px;
        align-items: start;
    }

    .mine-columns.is-horizontal {
        display: flex;
        overflow-x: auto;
        overflow-y: hidden;
        gap: 12px;
        padding-bottom: 10px;
        scroll-snap-type: x proximity;
    }

    .mine-columns.is-horizontal .mine-column {
        min-width: 300px;
        width: 300px;
        max-width: 300px;
        flex: 0 0 300px;
        scroll-snap-align: start;
    }

    .mine-column {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        background: #ffffff;
        display: grid;
        gap: 8px;
    }

    .mine-column-title {
        font-weight: 900;
        color: #0f172a;
    }

    .mine-form {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 12px;
        align-items: end;
    }

    .mine-form label {
        display: grid;
        gap: 5px;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }

    .mine-form > label {
        grid-column: span 4;
    }

    .mine-form > label.is-wide,
    .mine-form > .is-wide {
        grid-column: span 8;
    }

    .mine-form > label.is-full,
    .mine-form > .is-full {
        grid-column: 1 / -1;
    }

    .mine-form input,
    .mine-form select,
    .mine-form textarea {
        width: 100%;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 10px 11px;
        background: #ffffff;
        color: #0f172a;
        font-size: 13px;
    }

    .mine-form input:focus,
    .mine-form select:focus,
    .mine-form textarea:focus {
        outline: none;
        border-color: #0d9488;
        box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
    }

    .mine-form textarea {
        min-height: 68px;
        resize: vertical;
    }

    .mine-checkline {
        display: flex !important;
        gap: 7px !important;
        align-items: flex-start;
        font-size: 12px;
        font-weight: 800;
        color: #475569;
        padding: 10px 11px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #ffffff;
    }

    .mine-checkline input[type="checkbox"] {
        width: auto;
        margin-top: 2px;
        accent-color: #0d9488;
    }

    .conditional-field {
        display: none !important;
    }

    .conditional-field.is-visible {
        display: grid !important;
    }

    .mine-details-card {
        display: grid;
        gap: 8px;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
    }

    .mine-details-card summary {
        cursor: pointer;
        font-weight: 900;
        color: #0f172a;
        list-style: none;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: center;
    }

    .mine-details-card summary .mine-badge {
        margin-left: auto;
    }

    .mine-details-card summary::-webkit-details-marker {
        display: none;
    }

    .mine-details-card summary::after {
        content: "+";
        width: 24px;
        height: 24px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
    }

    .mine-details-card[open] summary::after {
        content: "-";
    }

    .mine-form-section {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px;
        background: #ffffff;
        display: grid;
        gap: 12px;
    }

    .mine-section-title {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 10px;
    }

    .mine-section-title strong {
        color: #0f172a;
        font-size: 14px;
    }

    .mine-section-title span {
        color: #64748b;
        font-size: 12px;
        line-height: 1.35;
    }

    .mine-form-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
        padding-top: 4px;
    }

    .mine-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
    }

    .mine-preview-stat {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        padding: 12px;
        display: grid;
        gap: 4px;
    }

    .mine-preview-stat strong {
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
    }

    .mine-preview-stat span {
        color: #0f172a;
        font-size: 18px;
        font-weight: 900;
    }

    .mine-config-matrix-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
        overflow-y: visible;
        overflow-x: hidden;
        max-height: none;
        min-height: 0;
        height: auto;
        display: grid;
        align-content: start;
    }

    .mine-config-row {
        display: grid;
        grid-template-columns: 240px minmax(0, 1fr);
        border-bottom: 1px solid #e2e8f0;
        background: #ffffff;
    }

    .mine-config-row:last-child {
        border-bottom: 0;
    }

    .mine-config-mine-cell {
        position: sticky;
        left: 0;
        z-index: 1;
        background: #f8fafc;
        border-right: 1px solid #e2e8f0;
        padding: 12px;
        display: grid;
        align-content: start;
        gap: 6px;
    }

    .mine-config-exams-strip {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        overflow-y: hidden;
        padding: 12px;
        min-width: 0;
        scroll-snap-type: x proximity;
        scrollbar-gutter: stable;
    }

    .mine-config-exam-card {
        min-width: 250px;
        width: 250px;
        flex: 0 0 250px;
        scroll-snap-align: start;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        padding: 10px;
        display: grid;
        gap: 7px;
        align-content: start;
    }

    .mine-config-exam-card.is-empty {
        color: #94a3b8;
        align-content: center;
        justify-content: center;
        text-align: center;
    }

    .mine-config-exam-title {
        color: #0f172a;
        font-size: 13px;
        font-weight: 900;
        line-height: 1.25;
    }

    @media (max-width: 760px) {
        .mine-config-row {
            grid-template-columns: 1fr;
        }

        .mine-config-mine-cell {
            position: static;
            border-right: 0;
            border-bottom: 1px solid #e2e8f0;
        }
    }

    .mine-subnav {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
    }

    .mine-subnav input,
    .mine-subnav select {
        min-height: 36px;
        border: 1px solid #dbe3ef;
        border-radius: 9px;
        padding: 8px 10px;
        color: #0f172a;
    }

    .mine-subnav input {
        min-width: min(320px, 100%);
        flex: 1;
    }

    .mine-helper-card {
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #1e3a8a;
        border-radius: 12px;
        padding: 12px;
        display: grid;
        gap: 4px;
        font-size: 12px;
        line-height: 1.4;
    }

    .mine-helper-card strong {
        color: #1d4ed8;
    }

    .mine-loading-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        background: rgba(15, 23, 42, 0.58);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .mine-loading-overlay.is-visible {
        display: flex;
    }

    .mine-loading-card {
        width: min(420px, 100%);
        border-radius: 16px;
        background: #ffffff;
        padding: 20px;
        box-shadow: 0 24px 80px rgba(15, 23, 42, 0.3);
        display: grid;
        gap: 12px;
        text-align: center;
    }

    .mine-spinner {
        width: 38px;
        height: 38px;
        border-radius: 999px;
        border: 4px solid #dbeafe;
        border-top-color: #0d9488;
        margin: 0 auto;
        animation: mine-spin 0.8s linear infinite;
    }

    @keyframes mine-spin {
        to {
            transform: rotate(360deg);
        }
    }

    @media (max-width: 760px) {
        .mine-actions-panel {
            left: 0;
            right: auto;
            min-width: min(280px, calc(100vw - 32px));
        }

        .mine-filter-row {
            flex-direction: column;
            align-items: stretch;
        }

        .mine-filter-group,
        .mine-filter-group.is-wide,
        .mine-filter-group.is-small {
            width: 100%;
            max-width: none;
            flex: 1;
        }

        .mine-pagination-controls {
            align-items: flex-start;
            flex-direction: column;
        }

        .mine-form {
            grid-template-columns: 1fr;
        }

        .mine-form > label,
        .mine-form > label.is-wide,
        .mine-form > .is-wide {
            grid-column: 1 / -1;
        }

        .selected-worker-main {
            display: grid;
            gap: 3px;
        }

        .selected-worker-main span::before {
            content: "";
        }
    }
</style>

<div class="module-page mine-page">
    <div class="page-header">
        <div class="mine-toolbar">
            <div>
                <h1 class="page-title">Habilitación minera</h1>
                <p class="page-subtitle">Control de trabajadores, minas y exámenes.</p>
            </div>

            <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">Personal</a>

                @if($canManage)
                    <div class="mine-actions-menu">
                        <button type="button" class="btn btn-primary btn-sm mine-actions-btn" onclick="toggleActionsMenu(this)">
                            Acciones &#9660;
                        </button>

                        <div class="mine-actions-panel">
                            <button type="button" class="mine-action-item" onclick="openDialog('modal-examen')">
                                <span class="mine-action-item-title">Agregar examen</span>
                                <span class="mine-action-item-copy">Crea un requisito reutilizable para una o varias minas.</span>
                            </button>
                            <button type="button" class="mine-action-item" onclick="openDialog('modal-editar-examen')">
                                <span class="mine-action-item-title">Editar examen</span>
                                <span class="mine-action-item-copy">Actualiza vigencia, intentos, precio, nota o estado.</span>
                            </button>
                            <button type="button" class="mine-action-item" onclick="openDialog('modal-configuracion')">
                                <span class="mine-action-item-title">Configurar exámenes por mina</span>
                                <span class="mine-action-item-copy">Define qué requisitos corresponden a cada mina.</span>
                            </button>
                            <button type="button" class="mine-action-item" onclick="openDialog('modal-excel')">
                                <span class="mine-action-item-title">Importar Excel master</span>
                                <span class="mine-action-item-copy">Analiza primero, luego confirma la carga.</span>
                            </button>

                            <button type="button" class="mine-action-item" onclick="openDialog('modal-recalcular')">
                                <span class="mine-action-item-title">Recalcular estados</span>
                                <span class="mine-action-item-copy">Genera faltantes, corrige habilitaciones y revisa vencimientos.</span>
                            </button>

                            <button type="button" class="mine-action-item" onclick="openDialog('modal-precios')">
                                <span class="mine-action-item-title">Historial de precios</span>
                                <span class="mine-action-item-copy">Consulta y registra costos por fecha de vigencia.</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mine-mines-card">
        <div class="card-header mine-card-header">
            <div>
                <span class="card-title">Minas disponibles {{ $selectedWorker ? 'para ' . $selectedWorker->nombre_completo : '' }}</span>
                <p class="mine-header-copy">Haz clic en una mina para ver los exámenes requeridos. Si seleccionas un trabajador, podrás asignarlo directamente.</p>
            </div>
        </div>

        <div class="card-body">
            <div class="mine-board">
                @foreach($mineBoard as $tile)
                    @php
                        $state = $tile['state'] ?? 'NEUTRO';
                        $tileClass = match($state) {
                            \App\Models\PersonalMina::ESTADO_HABILITADO => 'ok',
                            \App\Models\PersonalMina::ESTADO_EN_PROCESO, \App\Models\PersonalMina::ESTADO_OBSERVADO => 'warn',
                            'BLOQUEADA' => 'blocked',
                            default => 'neutral',
                        };
                        $canClick = $state !== 'BLOQUEADA';
                        $tileAction = 'Selecciona trabajador';
                        if ($state === 'BLOQUEADA') {
                            $tileAction = 'Bloqueado';
                        } elseif (!empty($tile['assignment'])) {
                            $tileAction = $state === \App\Models\PersonalMina::ESTADO_HABILITADO ? 'Ver proceso' : 'Continuar exámenes';
                        } elseif ($selectedWorker) {
                            $tileAction = 'Asignar a esta mina';
                        }
                    @endphp

                    <div
                        class="mine-tile {{ $tileClass }}"
                        data-mine-id="{{ $tile['mine']->id }}"
                        data-mine-name="{{ $tile['mine']->nombre }}"
                        onclick="{{ $canClick ? 'openMineExams(this)' : '' }}"
                        title="{{ $tile['reason'] ?? '' }}"
                    >
                        <span class="mine-tile-title">{{ $tile['mine']->nombre }}</span>
                        <span class="mine-badge {{ $tileClass === 'ok' ? 'ok' : ($tileClass === 'warn' ? 'warn' : ($tileClass === 'blocked' ? 'danger' : '')) }}">
                            {{ $tile['label'] }}
                        </span>
                        <span class="mine-muted">{{ $tile['reason'] }}</span>
                        <span class="mine-action-hint">{{ $tileAction }}</span>

                        @if($canManage && $selectedWorker && $state !== 'BLOQUEADA' && empty($tile['assignment']))
                            <form method="POST" action="{{ route('personal.habilitacion-minera.assign', $currentQuery) }}" onclick="event.stopPropagation()" data-loading-message="Asignando trabajador a mina...">
                                @csrf
                                <input type="hidden" name="personal_id" value="{{ $selectedWorker->id }}">
                                <input type="hidden" name="mina_id" value="{{ $tile['mine']->id }}">
                                <input type="hidden" name="estado_habilitacion" value="{{ \App\Models\PersonalMina::ESTADO_EN_PROCESO }}">
                                <button type="submit" class="btn btn-outline btn-xs">Asignar a esta mina</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card mine-worker-card">
        <div class="card-header mine-worker-header">
            <div>
                <span class="card-title">Seleccionar trabajador</span>
                <p class="mine-header-copy">La búsqueda y los filtros se aplican automáticamente, sin botón de filtrar.</p>
            </div>

            @if($workerActiveFilterCount > 0)
                <span class="mine-filter-count">
                    {{ $workerActiveFilterCount }} filtro{{ $workerActiveFilterCount > 1 ? 's' : '' }} activo{{ $workerActiveFilterCount > 1 ? 's' : '' }}
                </span>
            @endif
        </div>

        <div class="card-body">
            <form
                method="GET"
                action="{{ route('personal.habilitacion-minera.index') }}"
                id="workerSearchForm"
                class="mine-worker-filters"
                autocomplete="off"
            >
                <div class="mine-filter-row">
                    <label @class(['mine-filter-group', 'is-wide', 'is-active' => $workerActiveFilters['trabajador']])>
                        <span class="mine-filter-label">Buscar trabajador</span>
                        <input
                            type="text"
                            name="trabajador"
                            id="trabajadorInput"
                            class="mine-filter-control"
                            value="{{ $filters['trabajador'] ?? '' }}"
                            placeholder="Nombre, DNI o puesto"
                            data-filter-field
                        >
                    </label>

                    <label @class(['mine-filter-group', 'is-active' => $workerActiveFilters['mina_id']])>
                        <span class="mine-filter-label">Mina</span>
                        <select name="mina_id" class="mine-filter-control" data-filter-change data-filter-field>
                            <option value="">Todas</option>
                            @foreach($mines as $mine)
                                <option
                                    value="{{ $mine->id }}"
                                    @selected((string)($filters['mina_id'] ?? '') === (string)$mine->id)
                                >
                                    {{ $mine->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label @class(['mine-filter-group', 'is-active' => $workerActiveFilters['estado_habilitacion']])>
                        <span class="mine-filter-label">Estado habilitación</span>
                        <select name="estado_habilitacion" class="mine-filter-control" data-filter-change data-filter-field>
                            <option value="">Todos</option>
                            @foreach($stateOptions as $key => $label)
                                <option
                                    value="{{ $key }}"
                                    @selected(($filters['estado_habilitacion'] ?? '') === $key)
                                >
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label @class(['mine-filter-group', 'is-active' => $workerActiveFilters['estado_laboral']])>
                        <span class="mine-filter-label">Estado laboral</span>
                        <select name="estado_laboral" class="mine-filter-control" data-filter-change data-filter-field>
                            <option value="">Todos</option>
                            @foreach([
                                'ACTIVO' => 'Activo',
                                'FALTA_CONTRATO' => 'Falta contrato',
                                'CESADO' => 'Cesado',
                                'PENDIENTE_COMPLETAR_FICHA' => 'Pendiente ficha',
                                'FICHA_ENVIADA' => 'Ficha enviada',
                                'OBSERVADO' => 'Observado'
                            ] as $key => $label)
                                <option
                                    value="{{ $key }}"
                                    @selected(($filters['estado_laboral'] ?? '') === $key)
                                >
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mine-filter-group is-small">
                        <span class="mine-filter-label">Trabajadores</span>
                        <select name="worker_limit" class="mine-filter-control" data-filter-change data-ignore-active="true">
                            @foreach($workerLimitOptions as $amount)
                                <option value="{{ $amount }}" @selected($workerLimit === $amount)>
                                    {{ $amount }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="mine-filter-group is-small">
                        <span class="mine-filter-label">Asignaciones</span>
                        <select name="per_page" class="mine-filter-control" data-filter-change data-ignore-active="true">
                            @foreach($assignmentPerPageOptions as $amount)
                                <option value="{{ $amount }}" @selected($assignmentPerPage === $amount)>
                                    {{ $amount }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <p class="mine-filter-help">Los filtros con valor se resaltan en verde. Para limpiar, borra el texto o vuelve cada selector a “Todos”.</p>
            </form>

            <div class="worker-table-wrap">
                <table class="worker-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Documento</th>
                            <th>Puesto</th>
                            <th>Estado</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($workers as $worker)
                            <tr @class(['active' => (int)($selectedWorker?->id ?? 0) === (int)$worker->id])>
                                <td><strong>{{ $worker->nombre_completo }}</strong></td>
                                <td><span class="mine-muted">{{ $worker->numero_documento ?: $worker->dni ?: 'Sin documento' }}</span></td>
                                <td><span class="mine-muted">{{ $worker->puesto ?: 'Sin cargo' }}</span></td>
                                <td><span class="mine-badge">{{ $worker->estado ?: 'Sin estado' }}</span></td>
                                <td class="text-center">
                                    <a
                                        class="btn btn-outline btn-xs"
                                        href="{{ route('personal.habilitacion-minera.index', array_merge($currentQuery, ['worker_id' => $worker->id])) }}"
                                    >
                                        Seleccionar
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="mine-empty-state">
                                    No se encontraron trabajadores con los filtros actuales.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(method_exists($workers, 'links'))
                <div class="mine-pagination-controls">
                    <div class="mine-pagination-summary">
                        @if($workers->total() > 0)
                            Mostrando {{ $workers->firstItem() }} - {{ $workers->lastItem() }} de {{ $workers->total() }} trabajadores
                        @else
                            Sin trabajadores para mostrar
                        @endif
                    </div>

                    @if($workers->hasPages())
                        <div class="mine-pagination-links">
                            {{ $workers->withQueryString()->onEachSide(1)->links() }}
                        </div>
                    @endif
                </div>
            @endif

            @if($selectedWorker)
                <div class="alert alert-info selected-worker-alert">
                    <div class="selected-worker-main">
                        <strong>{{ $selectedWorker->nombre_completo }}</strong>
                        <span>{{ $selectedWorker->numero_documento ?: $selectedWorker->dni ?: 'Sin documento' }}</span>
                        <span>{{ $selectedWorker->puesto ?: 'Sin cargo' }}</span>
                        <span>Estado laboral: {{ $selectedWorker->estado ?: 'Sin estado' }}</span>
                    </div>

                    @if(!$selectedWorker->contratoLaboralActual || !$selectedWorker->contratoLaboralActual->signed_contract_path)
                        <div class="selected-worker-warning">
                            Advertencia: no se detectó contrato vigente firmado. Esto no bloquea la habilitación minera.
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="card mine-assignments-card">
        <div class="card-header mine-list-header">
            <div>
                <span class="card-title">Trabajadores por mina</span>
                <p class="mine-header-copy">Resumen operativo de asignaciones, estados y advertencias por trabajador.</p>
            </div>

            @if(method_exists($assignments, 'total'))
                <span class="mine-muted">
                    Mostrando {{ $assignments->firstItem() ?: 0 }} - {{ $assignments->lastItem() ?: 0 }} de {{ $assignments->total() }} trabajadores
                </span>
            @endif
        </div>

        <div class="card-body">
            <div class="mine-table-wrap">
                <table class="mine-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Minas</th>
                            <th>Estado</th>
                            <th>Advertencias</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($assignments as $personalId => $workerAssignments)
                            @php
                                $firstAssignment = $workerAssignments->first();
                                $worker = $firstAssignment?->personal;
                            @endphp

                            <tr>
                                <td>
                                    <div class="mine-cell-main">
                                        <strong>{{ $worker?->nombre_completo ?: 'N/A' }}</strong>
                                        <span class="mine-cell-subline">{{ $worker?->numero_documento ?: $worker?->dni ?: 'Sin documento' }} · {{ $worker?->puesto ?: '-' }}</span>
                                        <span class="mine-badge">{{ $worker?->estado ?: '-' }}</span>
                                    </div>
                                </td>

                                <td>
                                    <div class="mine-inline-tags">
                                        @foreach($workerAssignments as $wa)
                                            @php
                                                $wState = $wa->estadoHabilitacionActual();
                                                $wBadge = $wState === \App\Models\PersonalMina::ESTADO_HABILITADO
                                                    ? 'ok'
                                                    : (in_array($wState, [\App\Models\PersonalMina::ESTADO_NO_HABILITADO, \App\Models\PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION], true) ? 'danger' : 'warn');
                                            @endphp

                                            <button
                                                type="button"
                                                class="mine-badge {{ $wBadge }} mine-btn-link"
                                                onclick="openWorkerExams('{{ $wa->id }}', '{{ addslashes($worker?->nombre_completo ?: '') }}', '{{ addslashes($wa->mina?->nombre ?: '') }}')"
                                                title="Ver exámenes de {{ $worker?->nombre_completo }} en {{ $wa->mina?->nombre }}"
                                            >
                                                {{ $wa->mina?->nombre }}
                                            </button>
                                        @endforeach
                                    </div>
                                </td>

                                <td>
                                    @php
                                        $worst = null;
                                        $worstLabel = null;

                                        foreach ($workerAssignments as $wa) {
                                            $s = $wa->estadoHabilitacionActual();

                                            if (!$worst || $s === \App\Models\PersonalMina::ESTADO_NO_HABILITADO || $s === \App\Models\PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION) {
                                                $worst = $s;
                                                $worstLabel = $stateLabel($s);
                                            }
                                        }

                                        $badgeClass = $worst === \App\Models\PersonalMina::ESTADO_HABILITADO
                                            ? 'ok'
                                            : (in_array($worst, [\App\Models\PersonalMina::ESTADO_NO_HABILITADO, \App\Models\PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION], true) ? 'danger' : 'warn');
                                    @endphp

                                    <span class="mine-badge {{ $badgeClass }}">{{ $worstLabel ?: '-' }}</span>
                                </td>

                                <td>
                                    @php
                                        $allWarnings = collect();

                                        foreach ($workerAssignments as $wa) {
                                            $allWarnings = $allWarnings->merge($service->warningsFor($wa));
                                        }

                                        $allWarnings = $allWarnings->unique();
                                    @endphp

                                    <div class="mine-inline-tags">
                                        @forelse($allWarnings as $warning)
                                            <span class="mine-badge warn">{{ $warning }}</span>
                                        @empty
                                            <span class="mine-muted">Sin advertencias.</span>
                                        @endforelse
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="mine-empty-state">
                                    No hay asignaciones mineras con los filtros actuales.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(method_exists($assignments, 'links'))
                <div class="mine-pagination-controls">
                    <div class="mine-pagination-summary">
                        @if($assignments->total() > 0)
                            Mostrando {{ $assignments->firstItem() }} - {{ $assignments->lastItem() }} de {{ $assignments->total() }} asignaciones
                        @else
                            Sin asignaciones para mostrar
                        @endif
                    </div>

                    @if($assignments->hasPages())
                        <div class="mine-pagination-links">
                            {{ $assignments->withQueryString()->onEachSide(1)->links() }}
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if($canManage)
        <dialog id="modal-examen" class="mine-dialog is-compact">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Catalogo de requisitos</span>
                    <strong>Agregar examen</strong>
                    <p class="mine-dialog-subtitle">Crea un examen o requisito para luego asignarlo a una mina.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <form method="POST" action="{{ route('personal.habilitacion-minera.examenes.store', $currentQuery) }}" class="mine-form mine-form-section" data-loading-message="Guardando examen...">
                    @csrf

                    <div class="mine-helper-card is-full">
                        <strong>Uso:</strong>
                        <span>Primero crea el examen aquí. Después entra a “Configurar exámenes por mina” para decir en qué minas aplica.</span>
                    </div>

                    <div class="mine-section-title is-full">
                        <div>
                            <strong>Examen</strong>
                            <span>Nombre y clasificación del requisito.</span>
                        </div>
                    </div>

                    <label class="is-wide">Nombre del examen<input type="text" name="nombre" required></label>
                    <label>Tipo de examen<input type="text" name="tipo" required></label>

                    <div class="mine-section-title is-full">
                        <div>
                            <strong>Reglas del resultado</strong>
                            <span>Define intentos, nota y si una desaprobación cierra el proceso.</span>
                        </div>
                    </div>

                    <label class="mine-checkline">
                        <input type="hidden" name="requiere_lugar" value="0">
                        <input type="checkbox" name="requiere_lugar" value="1" data-toggle-target=".field-lugar">
                        <span>El examen se toma en un lugar específico</span>
                    </label>

                    <label class="conditional-field field-lugar">Nombre del lugar<input type="text" name="lugar"></label>

                    <label>
                        Máximo de intentos
                        <select name="max_intentos" required>
                            <option value="2">2</option>
                            <option value="1">1</option>
                        </select>
                    </label>

                    <label class="mine-checkline">
                        <input type="hidden" name="permite_reintento" value="0">
                        <input type="checkbox" name="permite_reintento" value="1" checked>
                        <span>Permite segundo intento</span>
                    </label>

                    <label class="mine-checkline">
                        <input type="hidden" name="requiere_nota" value="0">
                        <input type="checkbox" name="requiere_nota" value="1" data-toggle-target=".field-nota">
                        <span>Necesita nota mínima</span>
                    </label>

                    <label class="conditional-field field-nota">Nota mínima aprobatoria<input type="number" step="0.01" name="nota_minima"></label>

                    <label class="mine-checkline">
                        <input type="hidden" name="desaprueba_finaliza_proceso" value="0">
                        <input type="checkbox" name="desaprueba_finaliza_proceso" value="1">
                        <span>Desaprobar finaliza proceso</span>
                    </label>

                    <label class="mine-checkline">
                        <input type="hidden" name="permite_convalidacion" value="0">
                        <input type="checkbox" name="permite_convalidacion" value="1">
                        <span>Permite usar este resultado en otra mina compatible</span>
                    </label>

                    <div class="mine-section-title is-full">
                        <div>
                            <strong>Vigencia y costo</strong>
                            <span>Completa solo si el examen vence o si la empresa registra un costo.</span>
                        </div>
                    </div>

                    <label class="mine-checkline">
                        <input type="hidden" name="tiene_vigencia" value="0">
                        <input type="checkbox" name="tiene_vigencia" value="1" data-toggle-target=".field-vigencia">
                        <span>El examen tiene fecha de vencimiento</span>
                    </label>

                    <label class="conditional-field field-vigencia">Días de vigencia<input type="number" min="1" name="vigencia_dias" placeholder="Ej. 365"></label>

                    <label class="mine-checkline">
                        <input type="hidden" name="empresa_paga" value="0">
                        <input type="checkbox" name="empresa_paga" value="1" data-toggle-target=".field-precio">
                        <span>Registrar costo pagado por la empresa</span>
                    </label>

                    <label class="conditional-field field-precio">Precio<input type="number" min="0" step="0.01" name="precio"></label>
                    <label class="conditional-field field-precio">Moneda<input type="text" maxlength="10" name="moneda" value="PEN"></label>
                    <label class="conditional-field field-precio">Precio vigente desde<input type="date" name="precio_desde"></label>

                    <label class="is-full">Observación<textarea name="observacion"></textarea></label>

                    <div class="mine-form-actions">
                        <button type="button" class="btn btn-outline btn-sm" onclick="closeDialog(this)">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Guardar examen</button>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog id="modal-editar-examen" class="mine-dialog is-wide">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Mantenimiento</span>
                    <strong>Editar examen</strong>
                    <p class="mine-dialog-subtitle">Abre un examen para cambiar sus reglas sin entrar a otra pantalla.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <div class="mine-subnav">
                    <input type="search" id="examEditSearch" placeholder="Buscar examen por nombre o tipo">
                    <span class="mine-muted">Abre solo el examen que necesitas editar.</span>
                </div>

                @forelse($allExams as $exam)
                    <details class="mine-details-card" data-exam-edit-card data-search="{{ mb_strtolower($exam->nombre . ' ' . $exam->tipo) }}">
                        <summary>
                            <span>{{ $exam->nombre }}</span>
                            <span class="mine-badge {{ $exam->activo ? 'ok' : 'danger' }}">{{ $exam->activo ? 'Activo' : 'Inactivo' }}</span>
                        </summary>

                        <form method="POST" action="{{ route('personal.habilitacion-minera.examenes.update', array_merge(['examId' => $exam->id], $currentQuery)) }}" class="mine-form" data-loading-message="Guardando cambios del examen...">
                            @csrf

                            <label class="is-wide">Nombre<input type="text" name="nombre" value="{{ $exam->nombre }}" required></label>
                            <label>Tipo<input type="text" name="tipo" value="{{ $exam->tipo }}"></label>

                            <label class="mine-checkline"><input type="hidden" name="requiere_lugar" value="0"><input type="checkbox" name="requiere_lugar" value="1" @checked($exam->requiere_lugar) data-toggle-target=".edit-lugar-{{ $exam->id }}"><span>Se toma en un lugar específico</span></label>
                            <label @class(['conditional-field', 'edit-lugar-' . $exam->id, 'is-visible' => $exam->requiere_lugar])>Nombre del lugar<input type="text" name="lugar" value="{{ $exam->lugar }}"></label>

                            <label class="mine-checkline"><input type="hidden" name="empresa_paga" value="0"><input type="checkbox" name="empresa_paga" value="1" @checked($exam->empresa_paga) data-toggle-target=".edit-precio-{{ $exam->id }}"><span>Registrar costo pagado por empresa</span></label>
                            <label @class(['conditional-field', 'edit-precio-' . $exam->id, 'is-visible' => $exam->empresa_paga])>Precio<input type="number" min="0" step="0.01" name="precio" value="{{ $exam->precio }}"></label>
                            <label @class(['conditional-field', 'edit-precio-' . $exam->id, 'is-visible' => $exam->empresa_paga])>Moneda<input type="text" maxlength="10" name="moneda" value="{{ $exam->moneda ?: 'PEN' }}"></label>
                            <label @class(['conditional-field', 'edit-precio-' . $exam->id, 'is-visible' => $exam->empresa_paga])>Precio vigente desde<input type="date" name="precio_desde" value="{{ optional($exam->precio_desde)->toDateString() }}"></label>

                            <label class="mine-checkline"><input type="hidden" name="tiene_vigencia" value="0"><input type="checkbox" name="tiene_vigencia" value="1" @checked($exam->tiene_vigencia) data-toggle-target=".edit-vigencia-{{ $exam->id }}"><span>Tiene fecha de vencimiento</span></label>
                            <label @class(['conditional-field', 'edit-vigencia-' . $exam->id, 'is-visible' => $exam->tiene_vigencia])>Días de vigencia<input type="number" min="1" name="vigencia_dias" value="{{ $exam->vigencia_dias }}"></label>

                            <label>
                                Máximo de intentos
                                <select name="max_intentos" required>
                                    <option value="2" @selected($exam->max_intentos === 2)>2</option>
                                    <option value="1" @selected($exam->max_intentos === 1)>1</option>
                                </select>
                            </label>

                            <label class="mine-checkline"><input type="hidden" name="permite_reintento" value="0"><input type="checkbox" name="permite_reintento" value="1" @checked($exam->permite_reintento)><span>Permite reintento</span></label>
                            <label class="mine-checkline"><input type="hidden" name="requiere_nota" value="0"><input type="checkbox" name="requiere_nota" value="1" @checked($exam->requiere_nota) data-toggle-target=".edit-nota-{{ $exam->id }}"><span>Necesita nota mínima</span></label>
                            <label @class(['conditional-field', 'edit-nota-' . $exam->id, 'is-visible' => $exam->requiere_nota])>Nota mínima<input type="number" step="0.01" name="nota_minima" value="{{ $exam->nota_minima }}"></label>
                            <label class="mine-checkline"><input type="hidden" name="desaprueba_finaliza_proceso" value="0"><input type="checkbox" name="desaprueba_finaliza_proceso" value="1" @checked($exam->desaprueba_finaliza_proceso)><span>Desaprobar finaliza proceso</span></label>
                            <label class="mine-checkline"><input type="hidden" name="permite_convalidacion" value="0"><input type="checkbox" name="permite_convalidacion" value="1" @checked($exam->permite_convalidacion)><span>Permite convalidación</span></label>
                            <label class="mine-checkline"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" @checked($exam->activo)><span>Activo</span></label>
                            <label>Orden<input type="number" min="0" name="orden" value="{{ $exam->orden }}"></label>
                            <label class="is-wide">Observación<textarea name="observacion">{{ $exam->observacion }}</textarea></label>
                            <label>Observación de precio<input type="text" name="observacion_precio" placeholder="Opcional si cambia precio"></label>

                            <div class="mine-form-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
                            </div>
                        </form>
                    </details>
                @empty
                    <span class="mine-muted">No hay exámenes registrados.</span>
                @endforelse
            </div>
        </dialog>

        <dialog id="modal-configuracion" class="mine-dialog is-wide no-body-scroll">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Reglas por mina</span>
                    <strong>Configurar exámenes por mina</strong>
                    <p class="mine-dialog-subtitle">Asigna requisitos al catálogo de cada mina y revisa lo que ya está configurado.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <form method="POST" action="{{ route('personal.habilitacion-minera.requisitos.store', $currentQuery) }}" class="mine-form mine-form-section" data-loading-message="Guardando requisito de mina...">
                    @csrf

                    <div class="mine-helper-card is-full">
                        <strong>Cómo funciona:</strong>
                        <span>“Permite no aplica” deja marcar un examen como completado cuando por área o función no corresponde rendirlo. “Convalidación” permite usar un resultado vigente de otra mina compatible para no volver a cargarlo.</span>
                    </div>

                    <div class="mine-section-title is-full">
                        <div>
                            <strong>Agregar requisito a una mina</strong>
                            <span>El sistema generará ese examen para los trabajadores asignados a esa mina.</span>
                        </div>
                    </div>

                    <label>Mina<select name="mina_id" required>@foreach($mines as $mine)<option value="{{ $mine->id }}">{{ $mine->nombre }}</option>@endforeach</select></label>
                    <label class="is-wide">Examen<select name="examen_id" required>@foreach($exams as $exam)<option value="{{ $exam->id }}">{{ $exam->nombre }}</option>@endforeach</select></label>
                    <label>Prioridad visual<input type="number" min="0" name="orden" value="0" placeholder="0, 1, 2..."></label>
                    <label class="mine-checkline"><input type="hidden" name="obligatorio" value="0"><input type="checkbox" name="obligatorio" value="1" checked><span>Obligatorio</span></label>
                    <label class="mine-checkline"><input type="hidden" name="permite_no_aplica" value="0"><input type="checkbox" name="permite_no_aplica" value="1" checked><span>Puede marcarse como no aplica por área</span></label>
                    <label class="mine-checkline"><input type="hidden" name="permite_convalidacion_mina" value="0"><input type="checkbox" name="permite_convalidacion_mina" value="1"><span>Puede convalidarse desde otra mina compatible</span></label>
                    <label>Días de vigencia solo para esta mina<input type="number" min="1" name="vigencia_dias_override" placeholder="Opcional"></label>
                    <label class="is-wide">Observación<input type="text" name="observacion_mina"></label>
                    <div class="mine-form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Agregar a mina</button>
                    </div>
                </form>

                <div class="mine-subnav">
                    <input type="search" id="mineConfigSearch" placeholder="Buscar mina o examen configurado">
                    <span class="mine-muted">Cada mina es una fila; sus exámenes aparecen hacia la derecha.</span>
                </div>

                <div class="mine-config-matrix-wrap">
                    @foreach($mines as $mine)
                        @php
                            $mineRequirements = $requirementsByMine->get($mine->id, collect());
                            $searchText = mb_strtolower($mine->nombre . ' ' . $mineRequirements->map(fn ($req) => $req->examen?->nombre ?: $req->nombre)->implode(' '));
                        @endphp

                        <div class="mine-config-row" data-mine-config-row data-search="{{ $searchText }}">
                            <div class="mine-config-mine-cell">
                                <span class="mine-config-exam-title">{{ $mine->nombre }}</span>
                                <span class="mine-muted">{{ $mineRequirements->count() }} examen{{ $mineRequirements->count() === 1 ? '' : 'es' }} configurado{{ $mineRequirements->count() === 1 ? '' : 's' }}</span>
                            </div>

                            <div class="mine-config-exams-strip">
                                @forelse($mineRequirements as $requirement)
                                    <div class="mine-config-exam-card">
                                        <span class="mine-config-exam-title">{{ $requirement->examen?->nombre ?: $requirement->nombre }}</span>
                                        <div class="mine-inline-tags">
                                            <span class="mine-badge {{ $requirement->obligatorio ? 'danger' : 'info' }}">{{ $requirement->obligatorio ? 'Obligatorio' : 'Opcional' }}</span>
                                            @if($requirement->permite_no_aplica)
                                                <span class="mine-badge ok">No aplica permitido</span>
                                            @endif
                                            @if($requirement->permite_convalidacion_mina)
                                                <span class="mine-badge ok">Convalida</span>
                                            @endif
                                        </div>
                                        <span class="mine-muted">
                                            {{ $requirement->examen?->tiene_vigencia ? 'Con vencimiento' : 'Sin vencimiento' }} ·
                                            Intentos {{ $requirement->examen?->max_intentos ?: '-' }} ·
                                            Prioridad {{ $requirement->orden ?? 0 }}
                                        </span>
                                        @if($requirement->vigencia_dias_override)
                                            <span class="mine-muted">Vigencia para esta mina: {{ $requirement->vigencia_dias_override }} días</span>
                                        @endif

                                        <form method="POST" action="{{ route('personal.habilitacion-minera.requisitos.deactivate', array_merge(['requirementId' => $requirement->id], $currentQuery)) }}" data-loading-message="Quitando requisito de la mina...">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-xs">Quitar</button>
                                        </form>
                                    </div>
                                @empty
                                    <div class="mine-config-exam-card is-empty">
                                        <span>Sin exámenes configurados</span>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </dialog>

        <dialog id="modal-excel" class="mine-dialog is-wide">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Importación controlada</span>
                    <strong>Importar Excel master</strong>
                    <p class="mine-dialog-subtitle">Primero analiza el archivo; nada se guarda hasta confirmar la importación.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <div class="alert alert-info">Al confirmar se crean o actualizan trabajadores, minas, exámenes por mina, asignaciones e intentos detectados.</div>

                <form method="POST" enctype="multipart/form-data" action="{{ route('personal.habilitacion-minera.import.preview', $currentQuery) }}" class="mine-form mine-form-section" data-loading-message="Analizando Excel master. Puede tardar si el archivo tiene muchas hojas...">
                    @csrf
                    <div class="mine-section-title is-full">
                        <div>
                            <strong>Archivo a analizar</strong>
                            <span>Usa el master actualizado. El sistema mostrará una vista previa antes de guardar.</span>
                        </div>
                    </div>
                    <label class="is-wide">Archivo Excel<input type="file" name="archivo" accept=".xlsx,.xls,.csv" required></label>
                    <div class="mine-form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Analizar vista previa</button>
                    </div>
                </form>

                @if($importPreview)
                    <div class="alert alert-info">Vista previa generada el {{ $importPreview['generated_at'] }}. No se guardaron cambios definitivos todavía.</div>

                    <div class="mine-preview-grid">
                        @foreach($importPreview['summary'] as $key => $value)
                            <div class="mine-preview-stat">
                                <strong>{{ str_replace('_', ' ', $key) }}</strong>
                                <span>{{ $value }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if(!empty($importPreview['errors']))
                        <div class="alert alert-danger">Filas con errores: {{ count($importPreview['errors']) }}</div>
                    @endif

                    @if(!empty($importPreview['unmapped']))
                        <details>
                            <summary>Datos no mapeados ({{ count($importPreview['unmapped']) }})</summary>
                            <pre style="white-space:pre-wrap;">{{ json_encode(array_slice($importPreview['unmapped'], 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif

                    @if(!empty($importPreview['conflicts']))
                        <details>
                            <summary>Conflictos detectados ({{ count($importPreview['conflicts']) }})</summary>
                            <pre style="white-space:pre-wrap;">{{ json_encode($importPreview['conflicts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif

                    <form method="POST" action="{{ route('personal.habilitacion-minera.import.confirm', $currentQuery) }}" class="mine-form-section" data-loading-message="Confirmando importación. Esto puede tardar por la cantidad de trabajadores y minas...">
                        @csrf
                        <input type="hidden" name="token" value="{{ $importPreview['token'] }}">
                        <div class="mine-form-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Confirmar importación</button>
                        </div>
                    </form>
                @endif
            </div>
        </dialog>

        <dialog id="modal-recalcular" class="mine-dialog is-compact">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Revisión automática</span>
                    <strong>Recalcular estados</strong>
                    <p class="mine-dialog-subtitle">Úsalo cuando se hayan cambiado requisitos, resultados o datos importados.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <div class="mine-helper-card">
                    <strong>Qué revisa:</strong>
                    <span>Genera exámenes faltantes, actualiza vencimientos, corrige habilitados sin respaldo y vuelve a calcular el estado de cada asignación.</span>
                </div>

                <form method="POST" action="{{ route('personal.habilitacion-minera.sync-current', $currentQuery) }}" class="mine-form-section" data-loading-message="Recalculando habilitaciones. Puede tardar si hay muchos trabajadores...">
                    @csrf
                    <div class="mine-form-actions">
                        <button type="button" class="btn btn-outline btn-sm" onclick="closeDialog(this)">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Recalcular ahora</button>
                    </div>
                </form>
            </div>
        </dialog>

        @if($importPreview)
            <script>
                window.addEventListener('DOMContentLoaded', function () {
                    document.getElementById('modal-excel')?.showModal();
                });
            </script>
        @endif

        <dialog id="modal-precios" class="mine-dialog is-wide">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Costos</span>
                    <strong>Historial de precios de exámenes</strong>
                    <p class="mine-dialog-subtitle">Registra precios por fecha sin modificar intentos antiguos.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                @foreach($exams as $exam)
                    <details class="mine-details-card">
                        <summary>{{ $exam->nombre }} · {{ $exam->empresa_paga ? 'Empresa paga' : 'Sin pago empresa' }}</summary>

                        <form method="POST" action="{{ route('personal.habilitacion-minera.examenes.prices.store', array_merge(['examId' => $exam->id], $currentQuery)) }}" class="mine-form" data-loading-message="Guardando precio del examen...">
                            @csrf
                            <label>Precio<input type="number" min="0" step="0.01" name="precio" required></label>
                            <label>Moneda<input type="text" name="moneda" value="{{ $exam->moneda ?: 'PEN' }}" required></label>
                            <label>Desde<input type="date" name="fecha_inicio" required></label>
                            <label>Hasta<input type="date" name="fecha_fin"></label>
                            <label class="is-wide">Observación<input type="text" name="observacion"></label>
                            <div class="mine-form-actions">
                                <button type="submit" class="btn btn-outline btn-xs">Agregar precio</button>
                            </div>
                        </form>

                        @foreach($exam->precios as $price)
                            <div class="mine-muted">
                                {{ $price->precio }} {{ $price->moneda }} · desde {{ $formatDate($price->fecha_inicio) }} hasta {{ $formatDate($price->fecha_fin) }} · {{ $price->observacion }}
                            </div>
                        @endforeach
                    </details>
                @endforeach
            </div>
        </dialog>
    @endif
</div>

<dialog id="modal-mine-exams" class="mine-dialog is-compact">
    <div class="mine-dialog-header">
        <div class="mine-dialog-title">
            <span class="mine-dialog-kicker">Requisitos</span>
            <strong id="mineExamModalTitle">Exámenes de la mina</strong>
            <p class="mine-dialog-subtitle">Lista de exámenes configurados para esta mina.</p>
        </div>
        <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
    </div>
    <div class="mine-dialog-body" id="mineExamModalBody"></div>
</dialog>

<dialog id="modal-worker-exams" class="mine-dialog is-wide">
    <div class="mine-dialog-header">
        <div class="mine-dialog-title">
            <span class="mine-dialog-kicker">Proceso del trabajador</span>
            <strong id="workerExamModalTitle">Exámenes del trabajador</strong>
            <p class="mine-dialog-subtitle">Registra programación, resultados, archivos y observaciones por examen.</p>
        </div>
        <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
    </div>
    <div class="mine-dialog-body" id="workerExamModalBody"></div>
</dialog>

<div id="mineLoadingOverlay" class="mine-loading-overlay" role="status" aria-live="polite">
    <div class="mine-loading-card">
        <div class="mine-spinner" aria-hidden="true"></div>
        <strong id="mineLoadingTitle">Procesando...</strong>
        <span id="mineLoadingMessage" class="mine-muted">Espera un momento.</span>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleActionsMenu(btn) {
    const panel = btn.nextElementSibling;
    const isOpen = panel.classList.contains('open');
    closeActionsMenu();

    if (!isOpen) {
        panel.classList.add('open');
    }
}

function closeActionsMenu() {
    document.querySelectorAll('.mine-actions-panel.open').forEach(function(panel) {
        panel.classList.remove('open');
    });
}

function openDialog(id) {
    closeActionsMenu();
    document.getElementById(id)?.showModal();
}

function closeDialog(button) {
    button.closest('dialog')?.close();
}

function escHtml(value) {
    if (value === null || value === undefined) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(value)));
    return div.innerHTML;
}

document.addEventListener('click', function(event) {
    if (!event.target.closest('.mine-actions-menu')) {
        closeActionsMenu();
    }
});

document.querySelectorAll('dialog.mine-dialog').forEach(function(dialog) {
    dialog.addEventListener('click', function(event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

function showMineLoading(message) {
    const overlay = document.getElementById('mineLoadingOverlay');
    const messageNode = document.getElementById('mineLoadingMessage');
    if (!overlay) return;
    if (messageNode) messageNode.textContent = message || 'Procesando informacion...';
    overlay.classList.add('is-visible');
}

document.addEventListener('submit', function(event) {
    const form = event.target;
    if (form && form.matches('form[data-loading-message]')) {
        showMineLoading(form.dataset.loadingMessage);
    }
});

function initMineTextFilter(inputId, itemSelector) {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', function() {
        const query = input.value.trim().toLowerCase();
        document.querySelectorAll(itemSelector).forEach(function(item) {
            const haystack = String(item.dataset.search || '').toLowerCase();
            item.style.display = !query || haystack.includes(query) ? '' : 'none';
        });
    });
}

initMineTextFilter('examEditSearch', '[data-exam-edit-card]');
initMineTextFilter('mineConfigSearch', '[data-mine-config-row]');

(function initAutoFilters() {
    const form = document.getElementById('workerSearchForm');
    if (!form) return;

    const searchInput = document.getElementById('trabajadorInput');
    let timer = null;
    let submitting = false;

    const updateActiveState = function(field) {
        if (!field || field.dataset.ignoreActive === 'true') return;

        const group = field.closest('.mine-filter-group');
        if (!group) return;

        const hasValue = String(field.value || '').trim() !== '';
        group.classList.toggle('is-active', hasValue);
    };

    const removePageBeforeSubmit = function() {
        const url = new URL(form.action, window.location.origin);
        url.searchParams.delete('page');
        form.action = url.pathname + url.search;
    };

    const submitForm = function(delay) {
        clearTimeout(timer);

        timer = setTimeout(function() {
            if (submitting) return;
            submitting = true;
            removePageBeforeSubmit();
            form.submit();
        }, delay);
    };

    form.querySelectorAll('[data-filter-field], [data-filter-change]').forEach(updateActiveState);

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            updateActiveState(searchInput);
            submitForm(450);
        });
    }

    form.querySelectorAll('select[data-filter-change]').forEach(function(select) {
        select.addEventListener('change', function() {
            updateActiveState(select);
            submitForm(0);
        });
    });
})();

(function initConditionalFields() {
    function syncCheckbox(checkbox) {
        const targetSelector = checkbox.dataset.toggleTarget;
        if (!targetSelector) return;

        const scope = checkbox.closest('form') || document;
        scope.querySelectorAll(targetSelector).forEach(function(field) {
            field.classList.toggle('is-visible', checkbox.checked);
        });
    }

    document.querySelectorAll('[data-toggle-target]').forEach(function(checkbox) {
        syncCheckbox(checkbox);
        checkbox.addEventListener('change', function() {
            syncCheckbox(checkbox);
        });
    });
})();

const mineRequirementsData = @json($mineReqsJson);
const assignmentsData = @json($assignmentsJson);
const attemptsUrlTemplate = @json(route('personal.habilitacion-minera.exam-attempts.store', ['workerExamId' => '__EXAM__']));
const noAplicaUrlTemplate = @json(route('personal.habilitacion-minera.exam.not-applicable', ['workerExamId' => '__EXAM__']));
const convalidateUrlTemplate = @json(route('personal.habilitacion-minera.exam.convalidate', ['workerExamId' => '__EXAM__']));
const csrfToken = @json(csrf_token());
const examStateLabels = @json($examStateOptions);
const attemptResultLabels = @json($attemptResultOptions);

function openMineExams(tile) {
    const mineId = tile.getAttribute('data-mine-id');
    const mineName = tile.getAttribute('data-mine-name');
    const modalTitle = document.getElementById('mineExamModalTitle');
    const modalBody = document.getElementById('mineExamModalBody');

    modalTitle.textContent = 'Exámenes requeridos - ' + mineName;

    const reqs = (mineRequirementsData && mineRequirementsData[mineId]) || [];
    let html = '';

    if (!reqs.length) {
        html = '<span class="mine-muted">Esta mina no tiene exámenes configurados.</span>';
    } else {
        html += '<div class="mine-exam-grid">';

        reqs.forEach(function(req) {
            const badge = req.obligatorio
                ? '<span class="mine-badge danger">Obligatorio</span>'
                : '<span class="mine-badge">Opcional</span>';
            const convalida = req.permite_convalidacion || req.permite_convalidacion_mina
                ? '<span class="mine-badge ok">Convalida</span>'
                : '';

            const details = [];
            if (req.tiene_vigencia) details.push('Con vencimiento');
            if (req.empresa_paga) details.push('Empresa paga');
            details.push('Máx. ' + (req.max_intentos || 1) + ' intento(s)');

            html += '<div class="mine-exam-item">';
            html += '<div class="mine-exam-head">';
            html += '<div class="mine-exam-titleline"><strong>' + escHtml(req.nombre) + '</strong>' + badge + convalida + '</div>';
            html += '<span class="mine-muted">' + escHtml(details.join(' · ')) + '</span>';
            html += '</div>';
            html += '</div>';
        });

        html += '</div>';
    }

    html += '<hr style="border-color:#e2e8f0; margin:8px 0;">';
    html += '<p class="mine-muted">Para registrar intentos o revisar avances, selecciona una asignación en la tabla “Trabajadores por mina”.</p>';

    modalBody.innerHTML = html;
    document.getElementById('modal-mine-exams').showModal();
}

function openWorkerExams(assignmentId, workerName, mineName) {
    const modalTitle = document.getElementById('workerExamModalTitle');
    const modalBody = document.getElementById('workerExamModalBody');

    modalTitle.textContent = 'Exámenes de ' + workerName + ' en ' + mineName;

    const data = (assignmentsData || []).find(function(item) {
        return String(item.id) === String(assignmentId);
    });

    let html = '';

    if (!data || !data.examenes || !data.examenes.length) {
        html = '<span class="mine-muted">No tiene exámenes generados para esta mina.</span>';
    } else {
        const total = data.examenes.length;
        const resolvedStates = ['APROBADO', 'VIGENTE', 'CONVALIDADO', 'NO_APLICA', 'POR_VENCER'];
        const resolved = data.examenes.filter(function(exam) {
            return resolvedStates.includes(exam.estado);
        }).length;
        const pending = data.examenes.filter(function(exam) {
            return ['PENDIENTE', 'PROGRAMADO'].includes(exam.estado);
        }).length;
        const expired = data.examenes.filter(function(exam) {
            return exam.estado === 'VENCIDO';
        }).length;
        const failed = data.examenes.filter(function(exam) {
            return exam.estado === 'DESAPROBADO';
        }).length;
        const soon = data.examenes.filter(function(exam) {
            return exam.estado === 'POR_VENCER';
        }).length;
        const progress = total ? (resolved + '/' + total) : '0/0';

        html += '<div class="mine-exam-item">';
        html += '<div class="mine-exam-head">';
        html += '<div class="mine-exam-titleline"><strong>Estado general</strong><span class="mine-badge">Proceso de habilitación</span></div>';
        html += '<span class="mine-muted">Resumen automático de requisitos para esta mina.</span>';
        html += '</div>';
        html += '<div class="mine-inline-tags">';
        html += '<span class="mine-badge ok">Avance ' + escHtml(progress) + '</span>';
        html += '<span class="mine-badge warn">Pendientes ' + escHtml(pending) + '</span>';
        html += '<span class="mine-badge danger">Vencidos ' + escHtml(expired) + '</span>';
        html += '<span class="mine-badge danger">Desaprobados ' + escHtml(failed) + '</span>';
        html += '<span class="mine-badge warn">Por vencer ' + escHtml(soon) + '</span>';
        html += '</div>';
        html += '</div>';
        html += '<div class="mine-exam-grid">';

        data.examenes.forEach(function(exam) {
            let badgeClass = 'warn';

            if (['APROBADO', 'VIGENTE', 'CONVALIDADO', 'NO_APLICA'].includes(exam.estado)) {
                badgeClass = 'ok';
            } else if (['DESAPROBADO', 'VENCIDO'].includes(exam.estado)) {
                badgeClass = 'danger';
            }

            const stateLabel = examStateLabels[exam.estado] || exam.estado;
            const showAttemptForm = ['PENDIENTE', 'PROGRAMADO', 'DESAPROBADO', 'VENCIDO'].includes(exam.estado);
            let nextAction = 'Revisar';
            if (exam.estado === 'PENDIENTE') nextAction = 'Programar';
            if (exam.estado === 'PROGRAMADO') nextAction = 'Registrar resultado';
            if (exam.estado === 'DESAPROBADO' && Number(exam.attempt_count || 0) < Number(exam.max_intentos || 1)) nextAction = 'Registrar segundo intento';
            if (exam.estado === 'DESAPROBADO' && Number(exam.attempt_count || 0) >= Number(exam.max_intentos || 1)) nextAction = 'Sin intentos disponibles';
            if (exam.estado === 'VENCIDO') nextAction = 'Reprogramar o registrar nuevo resultado';
            if (['APROBADO', 'VIGENTE', 'CONVALIDADO', 'NO_APLICA', 'POR_VENCER'].includes(exam.estado)) nextAction = 'Resuelto';
            const details = [];

            if (exam.lugar) details.push('Lugar: ' + exam.lugar);
            if (exam.precio !== null && exam.precio !== undefined) details.push('Precio: ' + exam.precio);
            details.push('Prog.: ' + (exam.fecha_programacion || '-'));
            details.push('Real.: ' + (exam.fecha_realizacion || '-'));
            details.push('Vence: ' + (exam.fecha_vencimiento || '-'));

            html += '<div class="mine-exam-item">';
            html += '<div class="mine-exam-head">';
            html += '<div class="mine-exam-titleline"><strong>' + escHtml(exam.nombre) + '</strong><span class="mine-badge ' + badgeClass + '">' + escHtml(stateLabel) + '</span></div>';
            html += '<span class="mine-muted">Intentos: ' + escHtml(exam.attempt_count || 0) + '/' + escHtml(exam.max_intentos || 1) + '</span>';
            html += '</div>';
            html += '<span class="mine-action-hint">Acción siguiente: ' + escHtml(nextAction) + '</span>';
            html += '<div class="mine-muted">' + escHtml(details.join(' · ')) + '</div>';

            if (exam.intentos && exam.intentos.length) {
                html += '<div class="mine-attempt-list">';
                exam.intentos.forEach(function(attempt) {
                    const result = attemptResultLabels[attempt.resultado] || attempt.resultado || '-';
                    let line = 'Intento ' + attempt.numero + ': ' + result;

                    if (attempt.nota !== null && attempt.nota !== undefined) line += ' · Nota: ' + attempt.nota;
                    if (attempt.observacion) line += ' · ' + attempt.observacion;

                    html += '<div class="mine-muted">' + escHtml(line) + '</div>';
                    if (attempt.archivo_url) {
                        html += '<a class="btn btn-outline btn-xs" href="' + escHtml(attempt.archivo_url) + '">Descargar archivo</a>';
                    }
                });
                html += '</div>';
            }

            if (showAttemptForm) {
                html += '<form method="POST" enctype="multipart/form-data" action="' + attemptsUrlTemplate.replace('__EXAM__', exam.id) + '" class="mine-form" data-loading-message="Registrando intento del examen...">';
                html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
                html += '<label>Programación<input type="date" name="fecha_programacion"></label>';
                html += '<label>Realización<input type="date" name="fecha_realizacion"></label>';
                html += '<label>Resultado<select name="resultado" required>';
                @foreach($attemptResultOptions as $key => $label)
                    html += '<option value="{{ $key }}">{{ $label }}</option>';
                @endforeach
                html += '</select></label>';
                html += '<label>Nota<input type="number" name="nota" step="0.01"></label>';
                html += '<label>Vencimiento manual<input type="date" name="fecha_vencimiento"></label>';
                html += '<label>Archivo<input type="file" name="archivo"></label>';
                html += '<label>Observación<input type="text" name="observacion"></label>';
                html += '<button type="submit" class="btn btn-outline btn-xs" style="grid-column:1/-1;">Registrar intento</button>';
                html += '</form>';
            }

            if (exam.estado !== 'NO_APLICA' && exam.estado !== 'CONVALIDADO') {
                html += '<form method="POST" action="' + noAplicaUrlTemplate.replace('__EXAM__', exam.id) + '" class="mine-form" data-loading-message="Marcando examen como no aplica...">';
                html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
                html += '<label>Observación no aplica<input type="text" name="observacion" required></label>';
                html += '<button type="submit" class="btn btn-outline btn-xs">Marcar no aplica</button>';
                html += '</form>';
            }

            html += '</div>';
        });

        html += '</div>';
    }

    modalBody.innerHTML = html;
    document.getElementById('modal-worker-exams').showModal();
}
</script>
@endpush
