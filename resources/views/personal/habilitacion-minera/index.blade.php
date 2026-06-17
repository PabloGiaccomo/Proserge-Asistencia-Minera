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

    $stateLabel = fn ($state): string => match ($state) {
        'ASIGNADO_PENDIENTE_INICIO' => 'Asignado - pendiente de iniciar',
        'NEUTRO' => 'Disponible',
        'BLOQUEADA' => 'Bloqueada',
        default => $stateOptions[$state] ?? $state ?? '-',
    };
    $examStateLabel = fn ($state): string => $examStateOptions[$state] ?? $state ?? '-';
    $attemptResultLabel = fn ($state): string => $attemptResultOptions[$state] ?? $state ?? '-';

    $mineBoard = $service->mineStatusBoardFor($selectedWorker);
    $mineBoardCollection = collect($mineBoard);
    $selectedWorkerAssignedMineCount = $mineBoardCollection->filter(fn ($tile) => !empty($tile['assignment']))->count();
    $selectedWorkerAssignableMineCount = $mineBoardCollection->filter(fn ($tile) => $selectedWorker && empty($tile['assignment']) && ($tile['state'] ?? null) !== 'BLOQUEADA')->count();
    $requirementsByMine = $requirements->groupBy('mina_id');
    $activeRequirementsByMine = $requirements
        ->filter(fn ($requirement) => (bool) ($requirement->activo ?? true) && filled($requirement->examen_id))
        ->groupBy('mina_id');
    $selectedMineId = trim((string) ($filters['mina_id'] ?? request('mina_id', '')));
    $selectedMine = $selectedMineId !== '' ? $mines->firstWhere('id', $selectedMineId) : null;
    $selectedMineRequirements = $selectedMineId !== '' ? ($activeRequirementsByMine->get($selectedMineId) ?? collect()) : collect();
    $isMineMatrix = $selectedMineId !== '';

    $resolvedExamStates = [
        \App\Models\PersonalMinaExamen::ESTADO_APROBADO,
        \App\Models\PersonalMinaExamen::ESTADO_VIGENTE,
        \App\Models\PersonalMinaExamen::ESTADO_CONVALIDADO,
        \App\Models\PersonalMinaExamen::ESTADO_NO_APLICA,
        \App\Models\PersonalMinaExamen::ESTADO_POR_VENCER,
    ];

    $badgeForHabilitationState = function (?string $state): string {
        return match ($state) {
            \App\Models\PersonalMina::ESTADO_HABILITADO => 'ok',
            \App\Models\PersonalMina::ESTADO_NO_HABILITADO,
            \App\Models\PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION => 'danger',
            \App\Models\PersonalMina::ESTADO_OBSERVADO => 'info',
            'ASIGNADO_PENDIENTE_INICIO' => 'info',
            'NEUTRO' => 'neutral',
            'BLOQUEADA' => 'danger',
            default => 'warn',
        };
    };

    $badgeForExamState = function (?string $state): string {
        return match ($state) {
            \App\Models\PersonalMinaExamen::ESTADO_APROBADO,
            \App\Models\PersonalMinaExamen::ESTADO_VIGENTE,
            \App\Models\PersonalMinaExamen::ESTADO_CONVALIDADO,
            \App\Models\PersonalMinaExamen::ESTADO_NO_APLICA => 'ok',
            \App\Models\PersonalMinaExamen::ESTADO_POR_VENCER,
            \App\Models\PersonalMinaExamen::ESTADO_PROGRAMADO => 'warn',
            \App\Models\PersonalMinaExamen::ESTADO_OBSERVADO => 'orange',
            \App\Models\PersonalMinaExamen::ESTADO_DESAPROBADO,
            \App\Models\PersonalMinaExamen::ESTADO_VENCIDO => 'danger',
            \App\Models\PersonalMinaExamen::ESTADO_PENDIENTE => 'neutral',
            default => 'neutral',
        };
    };

    $attemptCountFor = function ($exam): int {
        if (!$exam || !$exam->relationLoaded('intentos')) {
            return 0;
        }

        return $exam->intentos
            ->where('resultado', '!=', \App\Models\PersonalMinaExamenIntento::RESULTADO_ANULADO)
            ->count();
    };

    $visualAssignmentState = function ($assignment, $requirementsForMine) use ($resolvedExamStates, $service): string {
        if (!$assignment) {
            return \App\Models\PersonalMina::ESTADO_EN_PROCESO;
        }

        $state = $service->visualAssignmentStateFor($assignment);
        if ($state === 'ASIGNADO_PENDIENTE_INICIO') {
            return $state;
        }
        if ($state !== \App\Models\PersonalMina::ESTADO_HABILITADO) {
            return $state;
        }

        $configured = collect($requirementsForMine)->filter(fn ($requirement) => (bool) ($requirement->activo ?? true) && filled($requirement->examen_id));
        $requiredExams = $assignment->examenes->where('obligatorio_snapshot', true);

        if ($configured->isEmpty() || $assignment->examenes->isEmpty() || $requiredExams->isEmpty()) {
            return \App\Models\PersonalMina::ESTADO_EN_PROCESO;
        }

        return $requiredExams->every(fn ($exam) => in_array($exam->estado, $resolvedExamStates, true))
            ? $state
            : \App\Models\PersonalMina::ESTADO_EN_PROCESO;
    };

    $progressForAssignment = function ($assignment) use ($resolvedExamStates): array {
        if (!$assignment) {
            return ['done' => 0, 'total' => 0, 'percent' => 0];
        }

        $exams = $assignment->examenes->where('obligatorio_snapshot', true);
        if ($exams->isEmpty()) {
            $exams = $assignment->examenes;
        }

        $total = $exams->count();
        $done = $exams->filter(fn ($exam) => in_array($exam->estado, $resolvedExamStates, true))->count();

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ];
    };

    $nextActionForAssignment = function ($assignment, $requirementsForMine) use ($attemptCountFor, $visualAssignmentState, $service): string {
        if (!$assignment) {
            return 'Sin proceso';
        }
        if ($service->visualAssignmentStateFor($assignment) === 'ASIGNADO_PENDIENTE_INICIO') {
            return 'Programar examenes';
        }

        $configured = collect($requirementsForMine)->filter(fn ($requirement) => (bool) ($requirement->activo ?? true) && filled($requirement->examen_id));
        if ($configured->isEmpty()) {
            return 'Configurar examenes';
        }
        if ($assignment->examenes->isEmpty()) {
            return 'Generar examenes';
        }

        $exams = $assignment->examenes->where('obligatorio_snapshot', true);
        if ($exams->isEmpty()) {
            $exams = $assignment->examenes;
        }

        foreach ([
            \App\Models\PersonalMinaExamen::ESTADO_VENCIDO => 'Actualizar vencido',
            \App\Models\PersonalMinaExamen::ESTADO_OBSERVADO => 'Revisar observacion',
            \App\Models\PersonalMinaExamen::ESTADO_PENDIENTE => 'Programar examen',
            \App\Models\PersonalMinaExamen::ESTADO_PROGRAMADO => 'Registrar resultado',
            \App\Models\PersonalMinaExamen::ESTADO_POR_VENCER => 'Revisar vencimiento',
        ] as $state => $label) {
            if ($exams->contains(fn ($exam) => $exam->estado === $state)) {
                return $label;
            }
        }

        $failed = $exams->first(fn ($exam) => $exam->estado === \App\Models\PersonalMinaExamen::ESTADO_DESAPROBADO);
        if ($failed) {
            $maxAttempts = max(1, (int) ($failed->max_intentos_snapshot ?: 1));

            return $attemptCountFor($failed) < $maxAttempts
                ? 'Registrar siguiente intento'
                : 'No habilitado';
        }

        return $visualAssignmentState($assignment, $requirementsForMine) === \App\Models\PersonalMina::ESTADO_HABILITADO
            ? 'Sin pendientes'
            : 'Revisar proceso';
    };

    $workerLimitOptions = [10, 20, 50, 80, 200];
    $assignmentPerPageOptions = [10, 15, 25, 50, 100];
    $workerLimit = (int) ($filters['worker_limit'] ?? request('worker_limit', 20));
    $assignmentPerPage = (int) ($filters['per_page'] ?? request('per_page', 15));
    $workerVisibleCount = method_exists($workers, 'count') ? $workers->count() : collect($workers)->count();
    $workerTotalCount = method_exists($workers, 'total') ? (int) $workers->total() : (int) ($workersTotal ?? $workerVisibleCount);
    $workerFirstItem = method_exists($workers, 'firstItem') ? (int) ($workers->firstItem() ?? 0) : ($workerVisibleCount > 0 ? 1 : 0);
    $workerLastItem = method_exists($workers, 'lastItem') ? (int) ($workers->lastItem() ?? 0) : $workerVisibleCount;

    $paginationWindow = function ($paginator): array {
        if (!method_exists($paginator, 'lastPage')) {
            return [];
        }

        $last = (int) $paginator->lastPage();
        $current = (int) $paginator->currentPage();
        if ($last <= 1) {
            return [];
        }
        if ($last <= 9) {
            return range(1, $last);
        }

        $rawPages = [1, 2, $current - 1, $current, $current + 1, $last - 1, $last];
        $pages = collect($rawPages)
            ->filter(fn ($page) => $page >= 1 && $page <= $last)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $window = [];
        $previous = null;
        foreach ($pages as $page) {
            if ($previous !== null && $page > $previous + 1) {
                $window[] = '...';
            }
            $window[] = $page;
            $previous = $page;
        }

        return $window;
    };
    $workerPaginationWindow = $paginationWindow($workers);

    $workerActiveFilters = [
        'trabajador' => filled($filters['trabajador'] ?? request('trabajador')),
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

    $selectedWorkerAssignmentsForJson = $mineBoardCollection
        ->pluck('assignment')
        ->filter();
    $assignmentSource = $assignmentSource
        ->flatten(1)
        ->concat($selectedWorkerAssignmentsForJson)
        ->filter()
        ->unique(fn ($assignment) => $assignment->id)
        ->values();

    $assignmentsJson = $assignmentSource->map(function ($a) use ($service, $currentQuery) {
        return [
            'id' => $a->id,
            'personal_id' => $a->personal_id,
            'mina_id' => $a->mina_id,
            'personal_nombre' => $a->personal?->nombre_completo,
            'mina_nombre' => $a->mina?->nombre,
            'estado_habilitacion' => $a->estadoHabilitacionActual(),
            'generate_exams_url' => route('personal.habilitacion-minera.generate-exams', array_merge(['assignmentId' => $a->id], $currentQuery)),
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
                            'fecha_programacion' => $att->fecha_programacion ? $att->fecha_programacion->format('d/m/Y') : null,
                            'fecha_programacion_iso' => $att->fecha_programacion ? $att->fecha_programacion->toDateString() : null,
                            'fecha_realizacion' => $att->fecha_realizacion ? $att->fecha_realizacion->format('d/m/Y') : null,
                            'fecha_realizacion_iso' => $att->fecha_realizacion ? $att->fecha_realizacion->toDateString() : null,
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
        max-width: 100%;
        overflow-x: hidden;
    }

    .mine-page.is-ajax-loading .mine-mines-card,
    .mine-page.is-ajax-loading .mine-assignments-card {
        opacity: 0.58;
        pointer-events: none;
        transition: opacity 0.16s ease;
    }

    .mine-page.is-ajax-loading .mine-worker-card {
        opacity: 0.92;
        transition: opacity 0.16s ease;
    }

    .mine-ajax-status {
        position: sticky;
        top: 8px;
        z-index: 30;
        display: none;
        justify-self: end;
        width: fit-content;
        padding: 7px 12px;
        border: 1px solid #bae6fd;
        border-radius: 999px;
        background: #eff6ff;
        color: #075985;
        font-size: 12px;
        font-weight: 800;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.10);
    }

    .mine-page.is-ajax-loading .mine-ajax-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .mine-ajax-status::before {
        content: "";
        width: 10px;
        height: 10px;
        border: 2px solid #7dd3fc;
        border-top-color: #0f766e;
        border-radius: 999px;
        animation: mineSpin 0.8s linear infinite;
    }

    @keyframes mineSpin {
        to {
            transform: rotate(360deg);
        }
    }

    .mine-worker-card {
        order: 2;
    }

    .mine-assignments-card {
        order: 3;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
    }

    .mine-mines-card {
        order: 1;
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

    .mine-assignments-card.card {
        overflow: hidden;
    }

    .mine-assignments-card .card-body {
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
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

    button.mine-tile {
        width: 100%;
        font: inherit;
        text-align: left;
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

    .mine-tile.info {
        border-color: #93c5fd;
        background: #eff6ff;
    }

    .mine-tile.neutral {
        border-color: #e2e8f0;
        background: #f8fafc;
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

    .mine-badge.orange {
        background: #ffedd5;
        color: #9a3412;
        border-color: #fdba74;
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

    .mine-matrix-filters {
        background: #f8fafc;
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

    .mine-view-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin: 8px 0 10px;
        color: #334155;
        font-size: 12px;
    }

    .mine-view-size {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .mine-view-select {
        width: auto;
        min-width: 48px;
        min-height: 32px;
        border: 1px solid #dbe3ef;
        border-radius: 9px;
        padding: 5px 8px;
        background: #ffffff;
        color: #334155;
        font-size: 12px;
        font-weight: 800;
    }

    .mine-view-select:focus {
        outline: none;
        border-color: #19d3c5;
        box-shadow: 0 0 0 3px rgba(25, 211, 197, 0.12);
    }

    .mine-view-summary {
        color: #475569;
        font-size: 12px;
        font-weight: 700;
    }

    .mine-table-wrap,
    .worker-table-wrap {
        overflow-x: auto;
        max-width: 100%;
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
        min-width: 620px;
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

    .worker-table tbody tr:not(.is-selected-worker) td {
        background: #ffffff;
    }

    .worker-table tbody tr:not(.is-selected-worker):hover td,
    .mine-table tbody tr:hover td {
        background: #f8fafc;
    }

    .worker-table tbody tr.is-selected-worker td {
        background: #eff6ff;
    }

    .mine-table tbody tr:last-child td,
    .worker-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .mine-matrix-wrap,
    .mining-matrix-wrapper {
        overflow-x: auto;
        overflow-y: auto;
        max-width: 100%;
        width: 100%;
        min-width: 0;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #ffffff;
        max-height: 72vh;
        overscroll-behavior-x: contain;
        scrollbar-gutter: stable both-edges;
    }

    .mine-matrix-table {
        width: max-content;
        min-width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .mine-matrix-table th,
    .mine-matrix-table td {
        border-bottom: 1px solid #edf2f7;
        border-right: 1px solid #edf2f7;
        padding: 9px;
        text-align: left;
        vertical-align: top;
        font-size: 12px;
        background: #ffffff;
    }

    .mine-matrix-table th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: #f8fafc;
        color: #475569;
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .mine-matrix-table td:first-child,
    .mine-matrix-table th:first-child {
        position: sticky;
        left: 0;
        z-index: 4;
        min-width: 250px;
        max-width: 300px;
        box-shadow: 1px 0 0 #edf2f7;
    }

    .mine-matrix-table th:first-child {
        z-index: 5;
    }

    .mine-matrix-table tbody tr:hover td {
        background: #f8fafc;
    }

    .mine-matrix-table .mine-col-doc {
        min-width: 110px;
    }

    .mine-matrix-table .mine-col-cargo {
        min-width: 180px;
    }

    .mine-matrix-table .mine-col-state {
        min-width: 145px;
    }

    .mine-matrix-table .mine-col-progress {
        min-width: 145px;
    }

    .mine-matrix-table .mine-col-action {
        min-width: 170px;
    }

    .mine-matrix-exam-head {
        display: grid;
        gap: 3px;
        min-width: 160px;
        max-width: 210px;
        white-space: normal;
    }

    .mine-matrix-exam-head strong {
        color: #0f172a;
        line-height: 1.2;
    }

    .mine-state-chip,
    .mine-next-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: max-content;
        max-width: 100%;
        border-radius: 999px;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1.1;
        border: 1px solid #dbe3ef;
        background: #f8fafc;
        color: #334155;
        white-space: normal;
    }

    .mine-state-chip.ok,
    .mine-next-action.ok {
        background: #dcfce7;
        color: #166534;
        border-color: #86efac;
    }

    .mine-state-chip.warn,
    .mine-next-action.warn {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
    }

    .mine-state-chip.danger,
    .mine-next-action.danger {
        background: #fee2e2;
        color: #991b1b;
        border-color: #fecaca;
    }

    .mine-state-chip.info {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: #93c5fd;
    }

    .mine-state-chip.orange,
    .mine-next-action.orange {
        background: #ffedd5;
        color: #9a3412;
        border-color: #fdba74;
    }

    .mine-state-chip.neutral {
        background: #f1f5f9;
        color: #475569;
        border-color: #cbd5e1;
    }

    .mine-progress {
        display: grid;
        gap: 5px;
        min-width: 118px;
    }

    .mine-progress-line {
        height: 7px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .mine-progress-bar {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: #0d9488;
    }

    .mine-progress-text {
        color: #475569;
        font-size: 11px;
        font-weight: 800;
    }

    .mine-exam-cell {
        width: 100%;
        min-width: 154px;
        min-height: 86px;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 8px;
        display: grid;
        gap: 5px;
        text-align: left;
        background: #f8fafc;
        color: #0f172a;
        cursor: pointer;
        transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
    }

    .mine-exam-cell:hover {
        transform: translateY(-1px);
        border-color: #0d9488;
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.12);
    }

    .mine-exam-cell.ok {
        background: #f0fdf4;
        border-color: #86efac;
    }

    .mine-exam-cell.warn {
        background: #fffbeb;
        border-color: #fcd34d;
    }

    .mine-exam-cell.orange {
        background: #fff7ed;
        border-color: #fdba74;
    }

    .mine-exam-cell.danger {
        background: #fef2f2;
        border-color: #fecaca;
    }

    .mine-exam-cell.neutral {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #475569;
    }

    .mine-exam-cell.is-missing {
        cursor: pointer;
        border-style: dashed;
    }

    .mine-exam-cell.is-missing:hover {
        transform: none;
        box-shadow: none;
        border-color: #cbd5e1;
    }

    .mine-exam-cell-name {
        font-size: 11px;
        font-weight: 900;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .mine-exam-cell-meta,
    .mine-exam-cell-date {
        color: #64748b;
        font-size: 11px;
        font-weight: 750;
        line-height: 1.25;
    }

    .mine-operational-note {
        display: grid;
        gap: 5px;
        padding: 10px 12px;
        border: 1px solid #dbeafe;
        border-radius: 12px;
        background: #eff6ff;
        color: #1e3a8a;
        font-size: 12px;
        line-height: 1.35;
        margin-bottom: 10px;
    }

    .mine-general-grid {
        display: grid;
        gap: 10px;
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

    .mine-inline-form {
        display: inline-flex;
        margin: 0;
    }

    .mine-worker-actions {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .mine-worker-actions .mine-actions-panel {
        min-width: 250px;
    }

    .mine-tile-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 4px;
    }

    .mine-tile-actions form {
        margin: 0;
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

    .mine-page-buttons {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .mine-page-button,
    .mine-page-ellipsis {
        min-width: 32px;
        min-height: 32px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 9px;
        font-size: 12px;
        font-weight: 800;
        line-height: 1;
    }

    .mine-page-button {
        border: 1px solid #dbe3ef;
        background: #ffffff;
        color: #334155;
        text-decoration: none;
    }

    .mine-page-button:hover {
        border-color: #0d9488;
        background: #f0fdfa;
        color: #0f766e;
    }

    .mine-page-button.is-active {
        border-color: #0d9488;
        background: #0d9488;
        color: #ffffff;
        box-shadow: 0 8px 18px rgba(13, 148, 136, 0.2);
    }

    .mine-page-button.is-disabled {
        color: #94a3b8;
        background: #f8fafc;
        cursor: not-allowed;
        box-shadow: none;
    }

    .mine-page-ellipsis {
        color: #64748b;
        min-width: 24px;
        padding-inline: 2px;
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

    .selected-worker-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
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

    .mine-exam-item.is-focused {
        border-color: #0d9488;
        box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
        background: #f0fdfa;
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

    .mine-exam-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding-top: 2px;
    }

    .mine-exam-action-row .btn {
        min-height: 32px;
    }

    .mine-exam-panel {
        display: none;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        padding: 12px;
        gap: 10px;
    }

    .mine-exam-panel.is-open {
        display: grid;
    }

    .mine-programmed-list {
        display: grid;
        gap: 10px;
    }

    .mine-programmed-item {
        display: grid;
        gap: 8px;
        padding: 10px;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #ffffff;
    }

    .mine-programmed-item.is-future {
        background: #f8fafc;
    }

    .mine-programmed-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
    }

    .mine-exam-empty-panel {
        padding: 10px;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        font-size: 12px;
        background: #ffffff;
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

    .mine-dialog:not([open]) {
        display: none !important;
    }

    .mine-dialog[open].is-wide.no-body-scroll {
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
        overflow: auto;
        max-height: min(62vh, 680px);
        min-height: min(430px, 62vh);
        height: min(62vh, 680px);
        display: grid;
        align-content: start;
        align-items: start;
        padding: 10px;
        gap: 10px;
        scrollbar-gutter: stable both-edges;
    }

    .mine-config-row {
        display: grid;
        grid-template-columns: 260px minmax(780px, 1fr);
        min-width: 1040px;
        min-height: 232px;
        align-items: stretch;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        background: #ffffff;
    }

    .mine-config-row:last-child {
        border-bottom: 1px solid #e2e8f0;
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
        min-height: 232px;
    }

    .mine-config-exams-strip {
        display: flex;
        gap: 10px;
        align-items: stretch;
        overflow-x: auto;
        overflow-y: hidden;
        padding: 14px 14px 20px;
        min-width: 0;
        min-height: 232px;
        box-sizing: border-box;
        scroll-snap-type: x proximity;
        scrollbar-gutter: stable;
    }

    .mine-config-exam-card {
        min-width: 270px;
        width: 270px;
        flex: 0 0 270px;
        min-height: 176px;
        scroll-snap-align: start;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        padding: 10px;
        display: grid;
        gap: 7px;
        align-content: start;
    }

    .mine-config-exam-card.is-removing {
        opacity: 0.55;
        pointer-events: none;
    }

    .mine-config-inline-error {
        color: #b91c1c;
        font-size: 12px;
        font-weight: 800;
        line-height: 1.35;
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
            min-width: 760px;
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

    .mine-inline-loading {
        grid-column: 1 / -1;
        display: none;
        align-items: center;
        gap: 12px;
        border: 1px solid #99f6e4;
        background: #f0fdfa;
        color: #134e4a;
        border-radius: 12px;
        padding: 12px 14px;
        font-size: 13px;
        line-height: 1.4;
    }

    .mine-inline-loading.is-visible {
        display: flex;
    }

    .mine-inline-loading[hidden] {
        display: none;
    }

    .mine-inline-loading strong {
        display: block;
        color: #0f766e;
        font-size: 13px;
    }

    .mine-inline-loading span {
        color: #475569;
    }

    .mine-inline-spinner {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        border: 3px solid #ccfbf1;
        border-top-color: #0d9488;
        flex: 0 0 auto;
        animation: mine-spin 0.8s linear infinite;
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

        .mine-board {
            grid-template-columns: 1fr;
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

        .mine-view-toolbar {
            grid-template-columns: 1fr;
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

        .worker-table-wrap {
            overflow: visible;
            border: 0;
            background: transparent;
        }

        .worker-table {
            min-width: 0;
            border-collapse: separate;
            border-spacing: 0 10px;
        }

        .worker-table thead {
            display: none;
        }

        .worker-table,
        .worker-table tbody,
        .worker-table tr,
        .worker-table td {
            display: block;
            width: 100%;
        }

        .worker-table tr {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 10px;
        }

        .worker-table td {
            border-bottom: 0;
            padding: 5px 0;
        }

        .worker-table td[data-label]::before {
            content: attr(data-label) ": ";
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .mine-matrix-wrap,
        .mining-matrix-wrapper,
        .mine-table-wrap {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    }
</style>

<div class="module-page mine-page">
    <script type="application/json" id="mineRuntimeData">
        @json(['requirements' => $mineReqsJson, 'assignments' => $assignmentsJson])
    </script>

    <div class="mine-ajax-status" id="mineAjaxStatus" role="status" aria-live="polite">
        Actualizando vista...
    </div>

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
                                <span class="mine-action-item-title">Importar Excel master con vista previa</span>
                                <span class="mine-action-item-copy">En validacion: analiza primero, luego confirma la carga.</span>
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

            @if($selectedWorker)
                @php
                    $deselectWorkerQuery = collect($currentQuery)
                        ->except(['worker_id', 'open_assign', 'open_manage'])
                        ->all();
                @endphp
                <a
                    href="{{ route('personal.habilitacion-minera.index', $deselectWorkerQuery) }}"
                    class="btn btn-outline btn-sm"
                    data-mine-deselect-worker
                >
                    Deseleccionar trabajador
                </a>
            @endif
        </div>

        <div class="card-body">
            @if(!$selectedWorker)
                <div class="mine-operational-note" data-testid="mine-board-empty-worker">
                    <strong>Selecciona un trabajador para ver las minas disponibles.</strong>
                    <span>Usa el buscador de abajo por nombre, DNI o cargo. Al seleccionar, se activan las tarjetas de minas y sus acciones.</span>
                </div>
            @else
            <div class="mine-board" data-testid="mine-worker-mine-board">
                @foreach($mineBoard as $tile)
                    @php
                        $state = $tile['state'] ?? 'NEUTRO';
                        $tileClass = match($state) {
                            \App\Models\PersonalMina::ESTADO_HABILITADO => 'ok',
                            \App\Models\PersonalMina::ESTADO_EN_PROCESO, \App\Models\PersonalMina::ESTADO_OBSERVADO => 'warn',
                            'ASIGNADO_PENDIENTE_INICIO' => 'info',
                            'BLOQUEADA' => 'blocked',
                            default => 'neutral',
                        };
                        $assignment = $tile['assignment'] ?? null;
                        $summary = $tile['summary'] ?? [];
                        $canClick = $state !== 'BLOQUEADA';
                        $badgeClass = match ($tileClass) {
                            'ok' => 'ok',
                            'warn' => 'warn',
                            'info' => 'info',
                            'blocked' => 'danger',
                            default => '',
                        };
                        $clickAction = $assignment
                            ? "openWorkerExams(" . \Illuminate\Support\Js::from($assignment->id) . ", " . \Illuminate\Support\Js::from($selectedWorker?->nombre_completo ?: '') . ", " . \Illuminate\Support\Js::from($tile['mine']->nombre) . ")"
                            : 'openMineExams(this)';
                        $tileAction = 'Selecciona trabajador';
                        if ($state === 'BLOQUEADA') {
                            $tileAction = 'Bloqueado';
                        } elseif ($assignment) {
                            $tileAction = $state === \App\Models\PersonalMina::ESTADO_HABILITADO ? 'Ver proceso' : 'Continuar exámenes';
                        } elseif ($selectedWorker) {
                            $tileAction = 'Asignar a esta mina';
                        }
                        if ($state !== 'BLOQUEADA' && $assignment) {
                            $tileAction = $state === 'ASIGNADO_PENDIENTE_INICIO'
                                ? 'Programar examenes'
                                : ($state === \App\Models\PersonalMina::ESTADO_HABILITADO ? 'Ver proceso' : 'Continuar examenes');
                        } elseif ($state !== 'BLOQUEADA' && $selectedWorker && !$assignment) {
                            $tileAction = 'Disponible para asignar';
                        }
                    @endphp

                    <div
                        class="mine-tile {{ $tileClass }}"
                        data-mine-id="{{ $tile['mine']->id }}"
                        data-mine-name="{{ $tile['mine']->nombre }}"
                        data-visual-state="{{ $state }}"
                        onclick="{{ $canClick ? $clickAction : '' }}"
                        title="{{ $tile['reason'] ?? '' }}"
                    >
                        <span class="mine-tile-title">{{ $tile['mine']->nombre }}</span>
                        <span class="mine-badge {{ $badgeClass }}">
                            {{ $tile['label'] }}
                        </span>
                        <span class="mine-muted">{{ $tile['reason'] }}</span>
                        @if($state === 'ASIGNADO_PENDIENTE_INICIO')
                            <span class="mine-muted">Sin examenes iniciados.</span>
                        @elseif(($summary['total'] ?? 0) > 0)
                            <span class="mine-muted">
                                {{ $summary['resueltos'] ?? 0 }}/{{ $summary['total'] ?? 0 }} resueltos
                                @if(($summary['programados'] ?? 0) > 0)
                                    · {{ $summary['programados'] }} programados
                                @endif
                                @if(($summary['vencidos'] ?? 0) > 0)
                                    · {{ $summary['vencidos'] }} vencidos
                                @endif
                            </span>
                        @elseif($assignment)
                            <span class="mine-muted">Sin examenes iniciados.</span>
                        @endif
                        <span class="mine-action-hint">{{ $tileAction }}</span>

                        @if($canManage && $selectedWorker && $state !== 'BLOQUEADA' && !$assignment)
                            <div class="mine-tile-actions" onclick="event.stopPropagation()">
                                <form method="POST" action="{{ route('personal.habilitacion-minera.assign', array_merge($currentQuery, ['worker_id' => $selectedWorker->id])) }}" data-loading-message="Asignando trabajador a mina...">
                                    @csrf
                                    <input type="hidden" name="personal_id" value="{{ $selectedWorker->id }}">
                                    <input type="hidden" name="mina_id" value="{{ $tile['mine']->id }}">
                                    <input type="hidden" name="estado_habilitacion" value="{{ \App\Models\PersonalMina::ESTADO_EN_PROCESO }}">
                                    <button type="submit" class="btn btn-outline btn-xs">Asignar</button>
                                </form>
                            </div>
                        @elseif($canManage && $selectedWorker && $assignment)
                            <div class="mine-tile-actions" onclick="event.stopPropagation()">
                                <form
                                    method="POST"
                                    action="{{ route('personal.habilitacion-minera.deactivate', array_merge(['assignmentId' => $assignment->id], $currentQuery, ['worker_id' => $selectedWorker->id, 'mina_id' => $tile['mine']->id])) }}"
                                    data-loading-message="Desasignando mina del trabajador..."
                                    onsubmit="return confirm('Desasignar esta mina del trabajador? No se borra el historial ni los examenes registrados.');"
                                >
                                    @csrf
                                    <input type="hidden" name="observacion" value="Desasignado manualmente desde habilitacion minera.">
                                    <button type="submit" class="btn btn-outline btn-xs">Desasignar</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    <div class="card mine-worker-card">
        <div class="card-header mine-worker-header">
            <div>
                <span class="card-title">Seleccionar trabajador</span>
                <p class="mine-header-copy">Busca por nombre, DNI o cargo. Luego usa las minas disponibles para asignar o gestionar su proceso.</p>
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
                @if($selectedWorker)
                    <input type="hidden" name="worker_id" value="{{ $selectedWorker->id }}">
                @endif
                <div class="mine-filter-row">
                    <label @class(['mine-filter-group', 'is-wide', 'is-active' => $workerActiveFilters['trabajador']])>
                        <span class="mine-filter-label">Buscar trabajador</span>
                        <input
                            type="text"
                            name="trabajador"
                            id="trabajadorInput"
                            class="mine-filter-control"
                            value="{{ $filters['trabajador'] ?? '' }}"
                            placeholder="Buscar por nombre, DNI o cargo"
                            data-filter-field
                        >
                    </label>

                </div>

                <p class="mine-filter-help">El selector solo busca trabajadores. Los filtros de mina y estado están en la matriz operativa.</p>
            </form>

            <div class="mine-view-toolbar" aria-label="Control de visualizacion del listado">
                <label class="mine-view-size">
                    <span>Mostrar</span>
                    <select
                        name="worker_limit"
                        class="mine-view-select"
                        form="workerSearchForm"
                        data-external-filter-change
                        data-ignore-active="true"
                    >
                        @foreach($workerLimitOptions as $amount)
                            <option value="{{ $amount }}" @selected($workerLimit === $amount)>
                                {{ $amount }}
                            </option>
                        @endforeach
                    </select>
                    <span>trabajadores</span>
                </label>

                <span class="mine-view-summary">
                    @if($workerVisibleCount > 0)
                        Mostrando {{ $workerFirstItem }}-{{ $workerLastItem }} de {{ $workerTotalCount }}
                    @else
                        Sin trabajadores para mostrar
                    @endif
                </span>
            </div>

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
                            <tr @class(['is-selected-worker' => $selectedWorker && (string) $selectedWorker->id === (string) $worker->id])>
                                <td data-label="Trabajador"><strong>{{ $worker->nombre_completo }}</strong></td>
                                <td data-label="Documento"><span class="mine-muted">{{ $worker->numero_documento ?: $worker->dni ?: 'Sin documento' }}</span></td>
                                <td data-label="Puesto"><span class="mine-muted">{{ $worker->puesto ?: 'Sin cargo' }}</span></td>
                                <td data-label="Estado"><span class="mine-badge">{{ $worker->estado ?: 'Sin estado' }}</span></td>
                                <td data-label="Accion" class="text-center">
                                    <div class="mine-worker-actions" data-testid="worker-actions-{{ $worker->id }}">
                                        <a
                                            class="btn btn-outline btn-xs"
                                            href="{{ route('personal.habilitacion-minera.index', array_merge($currentQuery, ['worker_id' => $worker->id])) }}"
                                        >
                                            Seleccionar
                                        </a>

                                        @if($canManage)
                                            <a
                                                class="btn btn-outline btn-xs"
                                                data-testid="worker-assign-{{ $worker->id }}"
                                                href="{{ route('personal.habilitacion-minera.index', array_merge($currentQuery, ['worker_id' => $worker->id, 'open_assign' => 1])) }}"
                                            >
                                                Asignar a mina
                                            </a>
                                        @endif

                                        @if(($worker->minas_activas_count ?? 0) > 0)
                                            <a
                                                class="btn btn-outline btn-xs"
                                                data-testid="worker-manage-{{ $worker->id }}"
                                                href="{{ route('personal.habilitacion-minera.index', array_merge($currentQuery, ['worker_id' => $worker->id, 'open_manage' => 1])) }}"
                                            >
                                                Gestionar examenes
                                            </a>
                                        @endif
                                    </div>
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
                        @elseif($workerTotalCount > 0)
                            Mostrando 1 - {{ $workerVisibleCount }} de {{ $workerTotalCount }} trabajadores
                        @else
                            Sin trabajadores para mostrar
                        @endif
                    </div>

                    @if($workers->hasPages())
                        <div class="mine-pagination-links">
                            <nav class="mine-page-buttons" aria-label="Paginacion de trabajadores">
                                @if($workers->onFirstPage())
                                    <span class="mine-page-button is-disabled" aria-disabled="true">‹</span>
                                @else
                                    <a class="mine-page-button" href="{{ $workers->previousPageUrl() }}" rel="prev" aria-label="Pagina anterior">‹</a>
                                @endif

                                @foreach($workerPaginationWindow as $page)
                                    @if($page === '...')
                                        <span class="mine-page-ellipsis" aria-hidden="true">...</span>
                                    @elseif((int) $page === (int) $workers->currentPage())
                                        <span class="mine-page-button is-active" aria-current="page">{{ $page }}</span>
                                    @else
                                        <a class="mine-page-button" href="{{ $workers->url($page) }}" aria-label="Ir a pagina {{ $page }}">{{ $page }}</a>
                                    @endif
                                @endforeach

                                @if($workers->hasMorePages())
                                    <a class="mine-page-button" href="{{ $workers->nextPageUrl() }}" rel="next" aria-label="Pagina siguiente">›</a>
                                @else
                                    <span class="mine-page-button is-disabled" aria-disabled="true">›</span>
                                @endif
                            </nav>
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
                        <span>Minas asignadas: {{ $selectedWorkerAssignedMineCount }}</span>
                    </div>

                    <div class="selected-worker-actions">
                        @if($canManage)
                            <button type="button" class="btn btn-outline btn-xs" onclick="openDialog('modal-asignar-mina')">
                                Asignar a mina
                            </button>
                        @endif

                        @if($selectedWorkerAssignedMineCount > 0)
                            <button type="button" class="btn btn-outline btn-xs" onclick="openDialog('modal-gestionar-worker')">
                                Gestionar examenes
                            </button>
                        @endif
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
                <span class="card-title">Matriz operativa</span>
                <p class="mine-header-copy">
                    @if($isMineMatrix && $selectedMine)
                        Trabajadores y examenes de {{ $selectedMine->nombre }}. Cada celda abre el detalle operativo.
                    @else
                        Filtra una mina para ver sus examenes como columnas. Sin filtro, se muestra el estado general por trabajador.
                    @endif
                </p>
            </div>
        </div>

        <div class="card-body">
            <form
                method="GET"
                action="{{ route('personal.habilitacion-minera.index') }}"
                id="matrixFilterForm"
                class="mine-worker-filters mine-matrix-filters"
                autocomplete="off"
            >
                @if($selectedWorker)
                    <input type="hidden" name="worker_id" value="{{ $selectedWorker->id }}">
                @endif
                <input type="hidden" name="worker_limit" value="{{ $workerLimit }}">

                <div class="mine-filter-row">
                    <label @class(['mine-filter-group', 'is-active' => filled($filters['mina_id'] ?? request('mina_id'))])>
                        <span class="mine-filter-label">Mina</span>
                        <select name="mina_id" class="mine-filter-control" data-filter-change data-filter-field>
                            <option value="">Todas las minas</option>
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

                    <label @class(['mine-filter-group', 'is-active' => filled($filters['estado_examen'] ?? request('estado_examen'))])>
                        <span class="mine-filter-label">Estado examen</span>
                        <select name="estado_examen" class="mine-filter-control" data-filter-change data-filter-field>
                            <option value="">Todos</option>
                            @foreach($examStateOptions as $key => $label)
                                <option value="{{ $key }}" @selected(($filters['estado_examen'] ?? '') === $key)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </form>

            <div class="mine-view-toolbar" aria-label="Control de visualizacion de trabajadores por mina">
                <label class="mine-view-size">
                    <span>Mostrar</span>
                    <select
                        name="per_page"
                        class="mine-view-select"
                        form="matrixFilterForm"
                        data-external-filter-change
                        data-ignore-active="true"
                    >
                        @foreach($assignmentPerPageOptions as $amount)
                            <option value="{{ $amount }}" @selected($assignmentPerPage === $amount)>
                                {{ $amount }}
                            </option>
                        @endforeach
                    </select>
                    <span>trabajadores</span>
                </label>

                <span class="mine-view-summary">
                    @if(method_exists($assignments, 'total') && $assignments->total() > 0)
                        Mostrando {{ $assignments->firstItem() }}-{{ $assignments->lastItem() }} de {{ $assignments->total() }}
                    @else
                        Sin trabajadores para mostrar
                    @endif
                </span>
            </div>

            @if($isMineMatrix)
                @php
                    $matrixExamState = strtoupper(trim((string) ($filters['estado_examen'] ?? request('estado_examen', ''))));
                    $matrixRequirements = $selectedMineRequirements;

                    if ($matrixExamState !== '' && array_key_exists($matrixExamState, $examStateOptions)) {
                        $matchingRequirementIds = collect();
                        $matchingExamIds = collect();

                        foreach ($assignments as $workerAssignments) {
                            foreach ($workerAssignments as $workerAssignment) {
                                foreach ($workerAssignment->examenes as $exam) {
                                    if ($exam->estado !== $matrixExamState) {
                                        continue;
                                    }

                                    if ($exam->mina_requisito_id) {
                                        $matchingRequirementIds->push((string) $exam->mina_requisito_id);
                                    }

                                    if ($exam->examen_id) {
                                        $matchingExamIds->push((string) $exam->examen_id);
                                    }
                                }
                            }
                        }

                        $matchingRequirementIds = $matchingRequirementIds->unique()->values();
                        $matchingExamIds = $matchingExamIds->unique()->values();
                        $matrixRequirements = $selectedMineRequirements
                            ->filter(function ($requirement) use ($matchingRequirementIds, $matchingExamIds) {
                                return $matchingRequirementIds->contains((string) $requirement->id)
                                    || ($requirement->examen_id && $matchingExamIds->contains((string) $requirement->examen_id));
                            })
                            ->values();
                    }
                @endphp

                @if($selectedMineRequirements->isEmpty())
                    <div class="mine-operational-note">
                        <strong>Sin examenes configurados para esta mina.</strong>
                        <span>Usa Acciones &gt; Configurar examenes por mina para crear las reglas. Ningun trabajador se mostrara visualmente como habilitado mientras no existan examenes configurados y generados.</span>
                    </div>
                @elseif($matrixExamState !== '' && $matrixRequirements->isEmpty())
                    <div class="mine-operational-note">
                        <strong>Sin examenes con estado {{ $examStateLabel($matrixExamState) }}.</strong>
                        <span>Cambia el filtro de estado de examen para ver otras columnas de la matriz.</span>
                    </div>
                @endif

                <div class="mine-matrix-wrap mining-matrix-wrapper">
                    <table class="mine-matrix-table" data-testid="mine-operational-matrix">
                        <thead>
                            <tr>
                                <th>Trabajador</th>
                                <th class="mine-col-doc">DNI</th>
                                <th class="mine-col-cargo">Cargo</th>
                                <th class="mine-col-state">Estado laboral</th>
                                <th class="mine-col-state">Estado habilitacion</th>
                                <th class="mine-col-progress">Avance</th>
                                <th class="mine-col-action">Accion siguiente</th>
                                @foreach($matrixRequirements as $requirement)
                                    <th>
                                        <span class="mine-matrix-exam-head">
                                            <strong>{{ $requirement->examen?->nombre ?: $requirement->nombre }}</strong>
                                            <span class="mine-muted">{{ $requirement->obligatorio ? 'Obligatorio' : 'Opcional' }}</span>
                                        </span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($assignments as $personalId => $workerAssignments)
                                @php
                                    $assignment = $workerAssignments->first();
                                    $worker = $assignment?->personal;
                                    $displayState = $visualAssignmentState($assignment, $selectedMineRequirements);
                                    $displayBadge = $badgeForHabilitationState($displayState);
                                    $progress = $progressForAssignment($assignment);
                                    $nextAction = $nextActionForAssignment($assignment, $selectedMineRequirements);
                                    $warnings = $assignment ? collect($service->warningsFor($assignment))->unique() : collect();
                                @endphp

                                <tr data-visual-state="{{ $displayState }}">
                                    <td>
                                        <div class="mine-cell-main">
                                            <strong>{{ $worker?->nombre_completo ?: 'N/A' }}</strong>
                                            @if($warnings->isNotEmpty())
                                                <div class="mine-inline-tags">
                                                    @foreach($warnings as $warning)
                                                        <span class="mine-badge warn">{{ $warning }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td><span class="mine-muted">{{ $worker?->numero_documento ?: $worker?->dni ?: 'Sin documento' }}</span></td>
                                    <td><span class="mine-muted">{{ $worker?->puesto ?: '-' }}</span></td>
                                    <td><span class="mine-badge">{{ $worker?->estado ?: '-' }}</span></td>
                                    <td><span class="mine-state-chip {{ $displayBadge }}">{{ $stateLabel($displayState) }}</span></td>
                                    <td>
                                        <div class="mine-progress" aria-label="Avance {{ $progress['done'] }} de {{ $progress['total'] }}">
                                            <span class="mine-progress-line"><span class="mine-progress-bar" style="width: {{ $progress['percent'] }}%;"></span></span>
                                            <span class="mine-progress-text">{{ $progress['done'] }}/{{ $progress['total'] }} examenes</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="mine-general-grid">
                                            <span class="mine-next-action {{ $displayBadge }}">{{ $nextAction }}</span>
                                            @if($canManage && $assignment)
                                                <form
                                                    method="POST"
                                                    action="{{ route('personal.habilitacion-minera.deactivate', array_merge(['assignmentId' => $assignment->id], $currentQuery, ['worker_id' => $worker?->id, 'mina_id' => $assignment->mina_id])) }}"
                                                    class="mine-inline-form"
                                                    data-loading-message="Desasignando mina del trabajador..."
                                                    onsubmit="return confirm('Desasignar esta mina del trabajador? No se borra el historial ni los examenes registrados.');"
                                                >
                                                    @csrf
                                                    <input type="hidden" name="observacion" value="Desasignado manualmente desde matriz operativa.">
                                                    <button type="submit" class="btn btn-outline btn-xs">Desasignar mina</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>

                                    @foreach($matrixRequirements as $requirement)
                                        @php
                                            $workerExam = $assignment?->examenes->first(function ($exam) use ($requirement) {
                                                return (string) $exam->mina_requisito_id === (string) $requirement->id
                                                    || ((string) $exam->examen_id === (string) $requirement->examen_id && blank($exam->mina_requisito_id));
                                            });
                                            $examBadge = $badgeForExamState($workerExam?->estado);
                                            $attemptCount = $attemptCountFor($workerExam);
                                            $maxAttempts = max(1, (int) ($workerExam?->max_intentos_snapshot ?: $requirement->examen?->max_intentos ?: 1));
                                            if ($workerExam?->estado === \App\Models\PersonalMinaExamen::ESTADO_DESAPROBADO) {
                                                $examBadge = $attemptCount < $maxAttempts ? 'orange' : 'danger';
                                            }
                                            $expiration = 'Sin vencimiento';
                                            if ($workerExam?->fecha_vencimiento) {
                                                $expiration = $workerExam->estado === \App\Models\PersonalMinaExamen::ESTADO_VENCIDO
                                                    ? 'Vencido ' . $formatDate($workerExam->fecha_vencimiento)
                                                    : 'Vence ' . $formatDate($workerExam->fecha_vencimiento);
                                            } elseif ($workerExam && in_array($workerExam->estado, $resolvedExamStates, true)) {
                                                $expiration = $workerExam->estado === \App\Models\PersonalMinaExamen::ESTADO_NO_APLICA
                                                    ? 'No aplica'
                                                    : 'Aprobado sin vencimiento';
                                            }
                                            $examTitle = $requirement->examen?->nombre ?: $requirement->nombre;
                                        @endphp

                                        <td>
                                            @if($workerExam)
                                                <button
                                                    type="button"
                                                    class="mine-exam-cell {{ $examBadge }}"
                                                    onclick="openWorkerExams({{ \Illuminate\Support\Js::from($assignment->id) }}, {{ \Illuminate\Support\Js::from($worker?->nombre_completo ?: '') }}, {{ \Illuminate\Support\Js::from($assignment->mina?->nombre ?: '') }}, {{ \Illuminate\Support\Js::from($workerExam->id) }})"
                                                    title="{{ $examTitle }} - {{ $examStateLabel($workerExam->estado) }} - Intentos {{ $attemptCount }}/{{ $maxAttempts }} - {{ $expiration }}"
                                                >
                                                    <span class="mine-state-chip {{ $examBadge }}">{{ $examStateLabel($workerExam->estado) }}</span>
                                                    <span class="mine-exam-cell-name">{{ $examTitle }}</span>
                                                    <span class="mine-exam-cell-meta">Intento {{ $attemptCount }}/{{ $maxAttempts }}</span>
                                                    <span class="mine-exam-cell-date">{{ $expiration }}</span>
                                                </button>
                                            @elseif($assignment)
                                                <button
                                                    type="button"
                                                    class="mine-exam-cell neutral is-missing"
                                                    onclick="openWorkerExams({{ \Illuminate\Support\Js::from($assignment->id) }}, {{ \Illuminate\Support\Js::from($worker?->nombre_completo ?: '') }}, {{ \Illuminate\Support\Js::from($assignment->mina?->nombre ?: '') }})"
                                                    title="{{ $examTitle }} sin examen generado"
                                                >
                                                    <span class="mine-state-chip neutral">Sin generar</span>
                                                    <span class="mine-exam-cell-name">{{ $examTitle }}</span>
                                                    <span class="mine-exam-cell-meta">Generar examenes requeridos</span>
                                                    <span class="mine-exam-cell-date">Sin intento</span>
                                                </button>
                                            @else
                                                <span class="mine-exam-cell neutral is-missing">
                                                    <span class="mine-state-chip neutral">Sin proceso</span>
                                                    <span class="mine-exam-cell-name">{{ $examTitle }}</span>
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 7 + $matrixRequirements->count() }}" class="mine-empty-state">
                                        No hay trabajadores asignados a esta mina con los filtros actuales.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="mine-operational-note">
                    <strong>Vista general.</strong>
                    <span>Selecciona una mina en los filtros para ver trabajadores, examenes, intentos y vencimientos en formato matriz.</span>
                </div>

            <div class="mine-table-wrap">
                <table class="mine-table">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Minas / examenes</th>
                            <th>Estado visual</th>
                            <th>Accion siguiente</th>
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
                                                $reqsForMine = $activeRequirementsByMine->get($wa->mina_id) ?? collect();
                                                $wState = $visualAssignmentState($wa, $reqsForMine);
                                                $wBadge = $badgeForHabilitationState($wState);
                                                $wProgress = $progressForAssignment($wa);
                                            @endphp

                                            <button
                                                type="button"
                                                class="mine-badge {{ $wBadge }} mine-btn-link"
                                                onclick="openWorkerExams({{ \Illuminate\Support\Js::from($wa->id) }}, {{ \Illuminate\Support\Js::from($worker?->nombre_completo ?: '') }}, {{ \Illuminate\Support\Js::from($wa->mina?->nombre ?: '') }})"
                                                title="Ver exámenes de {{ $worker?->nombre_completo }} en {{ $wa->mina?->nombre }}"
                                            >
                                                {{ $wa->mina?->nombre }} ({{ $wProgress['done'] }}/{{ $wProgress['total'] }})
                                            </button>
                                            @if($canManage)
                                                <form
                                                    method="POST"
                                                    action="{{ route('personal.habilitacion-minera.deactivate', array_merge(['assignmentId' => $wa->id], $currentQuery, ['worker_id' => $worker?->id, 'mina_id' => $wa->mina_id])) }}"
                                                    class="mine-inline-form"
                                                    data-loading-message="Desasignando mina del trabajador..."
                                                    onsubmit="return confirm('Desasignar esta mina del trabajador? No se borra el historial ni los examenes registrados.');"
                                                >
                                                    @csrf
                                                    <input type="hidden" name="observacion" value="Desasignado manualmente desde listado de habilitacion minera.">
                                                    <button type="submit" class="btn btn-outline btn-xs">Desasignar</button>
                                                </form>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>

                                <td>
                                    @php
                                        $worst = null;
                                        $worstLabel = null;

                                        foreach ($workerAssignments as $wa) {
                                            $reqsForMine = $activeRequirementsByMine->get($wa->mina_id) ?? collect();
                                            $s = $visualAssignmentState($wa, $reqsForMine);

                                            if (
                                                !$worst
                                                || $s === \App\Models\PersonalMina::ESTADO_NO_HABILITADO
                                                || $s === \App\Models\PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION
                                                || ($worst === \App\Models\PersonalMina::ESTADO_HABILITADO && $s !== \App\Models\PersonalMina::ESTADO_HABILITADO)
                                            ) {
                                                $worst = $s;
                                                $worstLabel = $stateLabel($s);
                                            }
                                        }

                                        $badgeClass = $badgeForHabilitationState($worst);
                                    @endphp

                                    <span class="mine-badge {{ $badgeClass }}">{{ $worstLabel ?: '-' }}</span>
                                </td>

                                <td>
                                    <div class="mine-general-grid">
                                        @foreach($workerAssignments as $wa)
                                            @php
                                                $reqsForMine = $activeRequirementsByMine->get($wa->mina_id) ?? collect();
                                                $nextState = $visualAssignmentState($wa, $reqsForMine);
                                            @endphp
                                            <span class="mine-next-action {{ $badgeForHabilitationState($nextState) }}">
                                                {{ $wa->mina?->nombre }}: {{ $nextActionForAssignment($wa, $reqsForMine) }}
                                            </span>
                                        @endforeach
                                    </div>
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
                                <td colspan="5" class="mine-empty-state">
                                    No hay asignaciones mineras con los filtros actuales.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif

            @if(method_exists($assignments, 'links'))
                <div class="mine-pagination-controls">
                    <div class="mine-pagination-summary">
                        @if($assignments->total() > 0)
                            Mostrando {{ $assignments->firstItem() }} - {{ $assignments->lastItem() }} de {{ $assignments->total() }} trabajadores
                        @else
                            Sin trabajadores para mostrar
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

    @if($selectedWorker && $canManage)
        <dialog id="modal-asignar-mina" class="mine-dialog is-wide">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Asignacion individual</span>
                    <strong>Asignar mina a {{ $selectedWorker->nombre_completo }}</strong>
                    <p class="mine-dialog-subtitle">Solo se habilitan minas disponibles. Las ya asignadas o bloqueadas no se reasignan desde aqui.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <div class="mine-board">
                    @foreach($mineBoardCollection as $tile)
                        @php
                            $state = $tile['state'] ?? 'NEUTRO';
                            $assignment = $tile['assignment'] ?? null;
                            $isBlocked = $state === 'BLOQUEADA';
                            $tileClass = $isBlocked ? 'blocked' : ($assignment ? 'info' : 'neutral');
                        @endphp

                        <div class="mine-tile {{ $tileClass }}">
                            <span class="mine-tile-title">{{ $tile['mine']->nombre }}</span>
                            <span class="mine-badge {{ $isBlocked ? 'danger' : ($assignment ? 'info' : '') }}">
                                {{ $assignment ? 'Ya asignada' : ($isBlocked ? 'Bloqueada' : 'Disponible') }}
                            </span>
                            <span class="mine-muted">{{ $tile['reason'] ?? 'Sin proceso iniciado.' }}</span>

                            @if(!$assignment && !$isBlocked)
                                <form method="POST" action="{{ route('personal.habilitacion-minera.assign', array_merge($currentQuery, ['worker_id' => $selectedWorker->id])) }}" data-loading-message="Asignando trabajador a mina...">
                                    @csrf
                                    <input type="hidden" name="personal_id" value="{{ $selectedWorker->id }}">
                                    <input type="hidden" name="mina_id" value="{{ $tile['mine']->id }}">
                                    <input type="hidden" name="estado_habilitacion" value="{{ \App\Models\PersonalMina::ESTADO_EN_PROCESO }}">
                                    <button type="submit" class="btn btn-primary btn-xs">Asignar</button>
                                </form>
                            @elseif($assignment)
                                <div class="mine-tile-actions">
                                    <button type="button" class="btn btn-outline btn-xs" onclick="openWorkerExams({{ \Illuminate\Support\Js::from($assignment->id) }}, {{ \Illuminate\Support\Js::from($selectedWorker->nombre_completo) }}, {{ \Illuminate\Support\Js::from($tile['mine']->nombre) }})">
                                        Gestionar examenes
                                    </button>
                                    <form
                                        method="POST"
                                        action="{{ route('personal.habilitacion-minera.deactivate', array_merge(['assignmentId' => $assignment->id], $currentQuery, ['worker_id' => $selectedWorker->id, 'mina_id' => $tile['mine']->id])) }}"
                                        data-loading-message="Desasignando mina del trabajador..."
                                        onsubmit="return confirm('Desasignar esta mina del trabajador? No se borra el historial ni los examenes registrados.');"
                                    >
                                        @csrf
                                        <input type="hidden" name="observacion" value="Desasignado manualmente desde asignacion individual.">
                                        <button type="submit" class="btn btn-outline btn-xs">Desasignar</button>
                                    </form>
                                </div>
                            @else
                                <span class="mine-muted">No disponible para asignar.</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </dialog>
    @endif

    @if($selectedWorker)
        <dialog id="modal-gestionar-worker" class="mine-dialog is-wide">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Proceso minero</span>
                    <strong>Gestionar examenes de {{ $selectedWorker->nombre_completo }}</strong>
                    <p class="mine-dialog-subtitle">Abre la mina asignada y registra programacion, resultados, no aplica o documentos.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                @if($selectedWorkerAssignedMineCount === 0)
                    <div class="mine-operational-note">
                        <strong>Sin minas asignadas.</strong>
                        <span>Primero asigna una mina para generar sus examenes requeridos.</span>
                    </div>
                @else
                    <div class="mine-board">
                        @foreach($mineBoardCollection->filter(fn ($tile) => !empty($tile['assignment'])) as $tile)
                            @php
                                $assignment = $tile['assignment'];
                                $state = $tile['state'] ?? $assignment->estadoHabilitacionActual();
                                $badgeClass = $badgeForHabilitationState($state);
                                $summary = $tile['summary'] ?? [];
                            @endphp

                            <div class="mine-tile {{ $badgeClass === 'ok' ? 'ok' : ($badgeClass === 'danger' ? 'blocked' : ($badgeClass === 'info' ? 'info' : 'warn')) }}">
                                <span class="mine-tile-title">{{ $tile['mine']->nombre }}</span>
                                <span class="mine-badge {{ $badgeClass }}">{{ $stateLabel($state) }}</span>
                                <span class="mine-muted">{{ $summary['resueltos'] ?? 0 }}/{{ $summary['total'] ?? 0 }} resueltos</span>
                                <span class="mine-action-hint">{{ $tile['reason'] ?? 'Abrir proceso.' }}</span>
                                <div class="mine-tile-actions">
                                    <button type="button" class="btn btn-outline btn-xs" onclick="openWorkerExams({{ \Illuminate\Support\Js::from($assignment->id) }}, {{ \Illuminate\Support\Js::from($selectedWorker->nombre_completo) }}, {{ \Illuminate\Support\Js::from($tile['mine']->nombre) }})">
                                        Abrir gestion
                                    </button>

                                    @if($canManage && $assignment->examenes->isEmpty())
                                        <form method="POST" action="{{ route('personal.habilitacion-minera.generate-exams', array_merge(['assignmentId' => $assignment->id], $currentQuery)) }}" data-loading-message="Generando examenes requeridos...">
                                            @csrf
                                            <button type="submit" class="btn btn-primary btn-xs">Generar examenes</button>
                                        </form>
                                    @endif

                                    @if($canManage)
                                        <form
                                            method="POST"
                                            action="{{ route('personal.habilitacion-minera.deactivate', array_merge(['assignmentId' => $assignment->id], $currentQuery, ['worker_id' => $selectedWorker->id, 'mina_id' => $tile['mine']->id])) }}"
                                            data-loading-message="Desasignando mina del trabajador..."
                                            onsubmit="return confirm('Desasignar esta mina del trabajador? No se borra el historial ni los examenes registrados.');"
                                        >
                                            @csrf
                                            <input type="hidden" name="observacion" value="Desasignado manualmente desde gestion de examenes.">
                                            <button type="submit" class="btn btn-outline btn-xs">Desasignar</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </dialog>
    @endif

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

                        <div class="mine-config-row" data-mine-config-row data-mine-name="{{ $mine->nombre }}" data-requirement-count="{{ $mineRequirements->count() }}" data-search="{{ $searchText }}">
                            <div class="mine-config-mine-cell">
                                <span class="mine-config-exam-title">{{ $mine->nombre }}</span>
                                <span class="mine-muted" data-mine-config-count>{{ $mineRequirements->count() }} examen{{ $mineRequirements->count() === 1 ? '' : 'es' }} configurado{{ $mineRequirements->count() === 1 ? '' : 's' }}</span>
                            </div>

                            <div class="mine-config-exams-strip" data-mine-config-strip>
                                @forelse($mineRequirements as $requirement)
                                    <div class="mine-config-exam-card" data-requirement-card data-requirement-id="{{ $requirement->id }}">
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

                                        <form method="POST" action="{{ route('personal.habilitacion-minera.requisitos.deactivate', array_merge(['requirementId' => $requirement->id], $currentQuery)) }}" data-loading-message="Quitando requisito de la mina..." data-requirement-deactivate-form>
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-xs">Quitar</button>
                                        </form>
                                    </div>
                                @empty
                                    <div class="mine-config-exam-card is-empty" data-empty-requirements-card>
                                        <span>Sin exámenes configurados</span>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </dialog>

        <dialog id="modal-excel" class="mine-dialog is-wide" data-persistent-modal="true" data-modal-storage-key="mineExcelImportModalOpen">
            <div class="mine-dialog-header">
                <div class="mine-dialog-title">
                    <span class="mine-dialog-kicker">Importación controlada</span>
                    <strong>Importar Excel master</strong>
                    <p class="mine-dialog-subtitle">Primero analiza el archivo; nada se guarda hasta confirmar la importación.</p>
                </div>
                <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
            </div>

            <div class="mine-dialog-body">
                <div class="alert alert-info">Al confirmar se crean catálogos detectados y se actualizan habilitaciones solo de trabajadores existentes. Los DNI no encontrados quedan pendientes de registro manual.</div>

                <form method="POST" enctype="multipart/form-data" action="{{ route('personal.habilitacion-minera.import.preview', $currentQuery) }}" class="mine-form mine-form-section" data-loading-message="Analizando Excel master. Puede tardar si el archivo tiene muchas hojas..." data-inline-loading="#mineExcelPreviewLoading" data-loading-button-label="Analizando..." data-defer-loading-submit="true">
                    @csrf
                    <div class="mine-section-title is-full">
                        <div>
                            <strong>Archivo a analizar</strong>
                            <span>Usa el master actualizado. El sistema mostrará una vista previa antes de guardar.</span>
                        </div>
                    </div>
                    <label class="is-wide">Archivo Excel<input type="file" name="archivo" accept=".xlsx,.xls,.xlsm,.csv" required></label>
                    <div class="mine-form-actions">
                        <button type="submit" class="btn btn-primary btn-sm">Analizar vista previa</button>
                    </div>
                    <div id="mineExcelPreviewLoading" class="mine-inline-loading" hidden>
                        <div class="mine-inline-spinner" aria-hidden="true"></div>
                        <div>
                            <strong>Analizando Excel master</strong>
                            <span>Estamos leyendo hojas, DNIs, minas, examenes, acciones pendientes y estados. Espera sin cerrar esta ventana.</span>
                        </div>
                    </div>
                </form>

                @if($importPreview)
                    @php
                        $previewGeneratedAt = $importPreview['generated_at'] ?? null;

                        try {
                            $previewGeneratedAt = $previewGeneratedAt
                                ? \Illuminate\Support\Carbon::parse($previewGeneratedAt)
                                    ->timezone(config('app.display_timezone', 'America/Lima'))
                                    ->format('d/m/Y H:i:s')
                                : '-';
                        } catch (\Throwable) {
                            $previewGeneratedAt = $importPreview['generated_at'] ?? '-';
                        }
                    @endphp
                    <div class="alert alert-info">Vista previa generada el {{ $previewGeneratedAt }} hora Perú. No se guardaron cambios definitivos todavía.</div>

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

                    <form method="POST" action="{{ route('personal.habilitacion-minera.import.confirm', $currentQuery) }}" class="mine-form-section" data-loading-message="Confirmando importación. Esto puede tardar por la cantidad de trabajadores y minas..." data-inline-loading="#mineExcelConfirmLoading" data-loading-button-label="Importando..." data-defer-loading-submit="true">
                        @csrf
                        <input type="hidden" name="token" value="{{ $importPreview['token'] }}">
                        <div class="mine-form-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Confirmar importación</button>
                        </div>
                        <div id="mineExcelConfirmLoading" class="mine-inline-loading" hidden>
                            <div class="mine-inline-spinner" aria-hidden="true"></div>
                            <div>
                                <strong>Importando Excel master</strong>
                                <span>Estamos guardando catálogos, requisitos, asignaciones de trabajadores existentes, exámenes, intentos y estados. No cierres esta ventana hasta que termine.</span>
                            </div>
                        </div>
                    </form>
                @endif
            </div>
        </dialog>

        @if($importPreview && ($importPreviewOpen ?? false))
            <script>
                window.addEventListener('DOMContentLoaded', function () {
                    const dialog = document.getElementById('modal-excel');
                    if (dialog && !dialog.open) {
                        window.sessionStorage?.setItem('mineExcelImportModalOpen', '1');
                        dialog.showModal();
                    }
                });
            </script>
        @endif

        @if(session('habilitacion_mina_import_completed'))
            <script>
                window.addEventListener('DOMContentLoaded', function () {
                    window.sessionStorage?.removeItem('mineExcelImportModalOpen');
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
    const dialog = document.getElementById(id);
    if (!dialog) return;

    persistDialogOpenState(dialog, true);
    dialog.showModal();
}

function closeDialog(button) {
    const dialog = button.closest('dialog');
    if (!dialog) return;

    persistDialogOpenState(dialog, false);
    dialog.close();
}

function persistDialogOpenState(dialog, isOpen) {
    const storageKey = dialog?.dataset?.modalStorageKey;
    if (!storageKey || !window.sessionStorage) return;

    if (isOpen) {
        window.sessionStorage.setItem(storageKey, '1');
    } else {
        window.sessionStorage.removeItem(storageKey);
    }
}

function escHtml(value) {
    if (value === null || value === undefined) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(value)));
    return div.innerHTML;
}

function escAttr(value) {
    return escHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

document.addEventListener('click', function(event) {
    if (!event.target.closest('.mine-actions-menu')) {
        closeActionsMenu();
    }

    const actionButton = event.target.closest('[data-exam-action-toggle]');
    if (actionButton) {
        const card = actionButton.closest('[data-worker-exam-card]');
        const target = actionButton.dataset.examActionToggle;
        if (!card || !target) return;

        card.querySelectorAll('[data-exam-action-panel]').forEach(function(panel) {
            panel.classList.toggle('is-open', panel.dataset.examActionPanel === target && !panel.classList.contains('is-open'));
        });
    }
});

function bindMineDialogBackdrop(scope) {
    (scope || document).querySelectorAll('dialog.mine-dialog').forEach(function(dialog) {
        if (dialog.dataset.backdropBound === 'true') return;

        dialog.dataset.backdropBound = 'true';
        dialog.addEventListener('click', function(event) {
            if (event.target === dialog) {
                persistDialogOpenState(dialog, false);
                dialog.close();
            }
        });
    });
}

bindMineDialogBackdrop(document);

function restorePersistentDialogs() {
    document.querySelectorAll('dialog.mine-dialog[data-modal-storage-key]').forEach(function(dialog) {
        const storageKey = dialog.dataset.modalStorageKey;
        if (window.sessionStorage?.getItem(storageKey) === '1' && !dialog.open) {
            dialog.showModal();
        }
    });
}

window.addEventListener('DOMContentLoaded', restorePersistentDialogs);

function showMineLoading(message) {
    const overlay = document.getElementById('mineLoadingOverlay');
    const messageNode = document.getElementById('mineLoadingMessage');
    if (!overlay) return;
    if (messageNode) messageNode.textContent = message || 'Procesando informacion...';
    overlay.classList.add('is-visible');
}

function showInlineFormLoading(form) {
    const target = form.dataset.inlineLoading ? document.querySelector(form.dataset.inlineLoading) : null;
    if (target) {
        target.hidden = false;
        target.classList.add('is-visible');
    }

    const label = form.dataset.loadingButtonLabel || 'Procesando...';
    form.querySelectorAll('button[type="submit"]').forEach(function(button) {
        button.dataset.originalText = button.textContent;
        button.textContent = label;
        button.disabled = true;
    });
}

function mineConfigCountText(count) {
    const normalized = Math.max(0, Number(count) || 0);
    return normalized + ' examen' + (normalized === 1 ? '' : 'es') + ' configurado' + (normalized === 1 ? '' : 's');
}

function updateMineConfigRowSearch(row) {
    if (!row) return;

    const mineName = row.dataset.mineName || '';
    const examNames = Array.from(row.querySelectorAll('[data-requirement-card] .mine-config-exam-title'))
        .map(function(node) { return node.textContent || ''; })
        .join(' ');
    row.dataset.search = (mineName + ' ' + examNames).toLocaleLowerCase();
}

function ensureMineConfigEmptyCard(strip) {
    if (!strip || strip.querySelector('[data-empty-requirements-card]')) return;

    const empty = document.createElement('div');
    empty.className = 'mine-config-exam-card is-empty';
    empty.dataset.emptyRequirementsCard = 'true';
    empty.innerHTML = '<span>Sin examenes configurados</span>';
    strip.appendChild(empty);
}

function updateMineConfigCount(row, count) {
    if (!row) return;

    const normalized = Math.max(0, Number(count) || 0);
    row.dataset.requirementCount = String(normalized);

    const counter = row.querySelector('[data-mine-config-count]');
    if (counter) {
        counter.textContent = mineConfigCountText(normalized);
    }

    const strip = row.querySelector('[data-mine-config-strip]');
    if (normalized === 0) {
        ensureMineConfigEmptyCard(strip);
    }
}

function showMineConfigError(card, message) {
    if (!card) return;

    let node = card.querySelector('[data-requirement-error]');
    if (!node) {
        node = document.createElement('div');
        node.className = 'mine-config-inline-error';
        node.dataset.requirementError = 'true';
        card.appendChild(node);
    }

    node.textContent = message || 'No se pudo quitar el examen de la mina.';
}

document.addEventListener('submit', function(event) {
    const form = event.target;
    if (!form || !form.matches('[data-requirement-deactivate-form]')) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const card = form.closest('[data-requirement-card]');
    const row = form.closest('[data-mine-config-row]');
    const submitButton = form.querySelector('button[type="submit"]');
    const originalText = submitButton ? submitButton.textContent : '';

    card?.classList.add('is-removing');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Quitando...';
    }

    fetch(form.action, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new FormData(form),
    })
        .then(function(response) {
            if (!response.ok) {
                return response.json().catch(function() { return {}; }).then(function(payload) {
                    throw new Error(payload.message || 'No se pudo quitar el examen de la mina.');
                });
            }

            return response.json();
        })
        .then(function(payload) {
            const deactivatedIds = Array.isArray(payload.deactivated_requirement_ids)
                ? payload.deactivated_requirement_ids
                : [payload.requirement_id];

            deactivatedIds
                .filter(Boolean)
                .forEach(function(requirementId) {
                    document.querySelectorAll('[data-requirement-card]').forEach(function(targetCard) {
                        if (targetCard.dataset.requirementId === String(requirementId)) {
                            targetCard.remove();
                        }
                    });
                });

            if (card && card.isConnected) {
                card.remove();
            }

            updateMineConfigCount(row, payload.active_count);
            updateMineConfigRowSearch(row);
        })
        .catch(function(error) {
            card?.classList.remove('is-removing');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalText || 'Quitar';
            }
            showMineConfigError(card, error.message);
        });
});

document.addEventListener('submit', function(event) {
    const form = event.target;
    if (form && form.matches('form[data-loading-message]')) {
        const dialog = form.closest('dialog');
        if (dialog) {
            persistDialogOpenState(dialog, true);
        }

        showInlineFormLoading(form);
        showMineLoading(form.dataset.loadingMessage);

        if (form.dataset.deferLoadingSubmit === 'true' && form.dataset.loadingSubmitted !== 'true') {
            event.preventDefault();
            form.dataset.loadingSubmitted = 'true';
            window.setTimeout(function() {
                HTMLFormElement.prototype.submit.call(form);
            }, 80);
        }
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

let mineAjaxController = null;
let mineAjaxTimer = null;

function setMineAjaxLoading(isLoading) {
    const page = document.querySelector('.mine-page');
    const status = document.getElementById('mineAjaxStatus');

    if (page) {
        page.classList.toggle('is-ajax-loading', isLoading);
    }

    if (status) {
        status.hidden = !isLoading;
    }
}

function updateMineRuntimeData(sourceDocument) {
    const source = sourceDocument.getElementById('mineRuntimeData');
    const target = document.getElementById('mineRuntimeData');

    if (source && target) {
        target.textContent = source.textContent;
    }

    try {
        const data = JSON.parse((target || source)?.textContent || '{}');
        mineRequirementsData = data.requirements || {};
        assignmentsData = data.assignments || [];
    } catch (error) {
        console.warn('No se pudo actualizar la data de habilitacion minera.', error);
    }
}

function updateMineActiveFilters(scope) {
    (scope || document).querySelectorAll('[data-filter-field], [data-filter-change]').forEach(function(field) {
        if (!field || field.dataset.ignoreActive === 'true') return;

        const group = field.closest('.mine-filter-group');
        if (!group) return;

        group.classList.toggle('is-active', String(field.value || '').trim() !== '');
    });
}

function captureMineFocusState() {
    const active = document.activeElement;

    if (!active || !active.matches('input, textarea, select')) {
        return null;
    }

    const form = active.closest('form');
    if (!form || !['workerSearchForm', 'matrixFilterForm'].includes(form.id)) {
        return null;
    }

    return {
        formId: form.id,
        id: active.id || '',
        name: active.name || '',
        value: active.value,
        selectionStart: typeof active.selectionStart === 'number' ? active.selectionStart : null,
        selectionEnd: typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
        preserveValue: active.id === 'trabajadorInput',
    };
}

function restoreMineFocusState(state) {
    if (!state) return;

    const form = document.getElementById(state.formId);
    if (!form) return;

    let field = state.id ? document.getElementById(state.id) : null;
    if (!field && state.name) {
        field = Array.from(form.elements).find(function(item) {
            return item.name === state.name;
        }) || null;
    }

    if (!field || typeof field.focus !== 'function') return;

    if (state.preserveValue && 'value' in field && field.value !== state.value) {
        field.value = state.value;
        updateMineActiveFilters(form);
    }

    field.focus({ preventScroll: true });

    if (
        state.selectionStart !== null
        && state.selectionEnd !== null
        && typeof field.setSelectionRange === 'function'
    ) {
        field.setSelectionRange(state.selectionStart, state.selectionEnd);
    }
}

function replaceMineAjaxSections(sourceDocument, focusState) {
    ['.mine-mines-card', '.mine-worker-card', '.mine-assignments-card'].forEach(function(selector) {
        const next = sourceDocument.querySelector(selector);
        const current = document.querySelector(selector);

        if (next && current) {
            current.replaceWith(next);
        }
    });

    ['modal-asignar-mina', 'modal-gestionar-worker'].forEach(function(id) {
        const next = sourceDocument.getElementById(id);
        const current = document.getElementById(id);

        if (next && current && !current.open) {
            current.replaceWith(next);
        }
    });

    updateMineRuntimeData(sourceDocument);
    updateMineActiveFilters(document);
    bindMineDialogBackdrop(document);
    restoreMineFocusState(focusState);
}

function mineUrlFromForm(form, resetPagination) {
    const url = new URL(form.action || window.location.href, window.location.origin);
    const params = new URLSearchParams(new FormData(form));

    if (resetPagination) {
        params.delete('page');
        params.delete('worker_page');
    }

    Array.from(params.keys()).forEach(function(key) {
        if (String(params.get(key) || '').trim() === '') {
            params.delete(key);
        }
    });

    url.search = params.toString();

    return url;
}

function navigateMineAjax(url, options) {
    const nextUrl = url instanceof URL ? url : new URL(url, window.location.origin);
    const opts = options || {};
    const focusState = captureMineFocusState();

    if (mineAjaxController) {
        mineAjaxController.abort();
    }

    mineAjaxController = new AbortController();
    const currentController = mineAjaxController;
    setMineAjaxLoading(true);

    return fetch(nextUrl.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'text/html',
        },
        signal: currentController.signal,
    })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.text();
        })
        .then(function(html) {
            const sourceDocument = new DOMParser().parseFromString(html, 'text/html');
            replaceMineAjaxSections(sourceDocument, captureMineFocusState() || focusState);

            if (opts.push !== false) {
                window.history.pushState({ mineAjax: true }, '', nextUrl.pathname + nextUrl.search);
            }

            if (nextUrl.searchParams.get('open_assign')) {
                openDialog('modal-asignar-mina');
            }

            if (nextUrl.searchParams.get('open_manage')) {
                openDialog('modal-gestionar-worker');
            }
        })
        .catch(function(error) {
            if (error.name === 'AbortError') return;
            window.location.href = nextUrl.toString();
        })
        .finally(function() {
            if (mineAjaxController === currentController) {
                mineAjaxController = null;
                setMineAjaxLoading(false);
            }
        });
}

function submitMineFilterForm(form, delay) {
    clearTimeout(mineAjaxTimer);
    mineAjaxTimer = setTimeout(function() {
        navigateMineAjax(mineUrlFromForm(form, true));
    }, delay || 0);
}

document.addEventListener('input', function(event) {
    const field = event.target;
    if (!field || field.id !== 'trabajadorInput') return;

    const form = field.closest('form');
    if (!form) return;

    updateMineActiveFilters(form);
    submitMineFilterForm(form, 450);
});

document.addEventListener('change', function(event) {
    const field = event.target;
    if (!field) return;

    const formId = field.getAttribute('form');
    const form = field.closest('form') || (formId ? document.getElementById(formId) : null);
    const isFilterField = field.matches('select[data-filter-change], [data-external-filter-change]');

    if (!form || !isFilterField || !['workerSearchForm', 'matrixFilterForm'].includes(form.id)) return;

    updateMineActiveFilters(form);
    submitMineFilterForm(form, 0);
});

document.addEventListener('click', function(event) {
    const link = event.target.closest('.mine-mines-card a[href], .mine-worker-card a[href], .mine-assignments-card a[href]');
    if (!link || link.target || link.hasAttribute('download')) return;

    const url = new URL(link.href, window.location.origin);
    if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;

    event.preventDefault();
    navigateMineAjax(url);
});

window.addEventListener('popstate', function() {
    navigateMineAjax(new URL(window.location.href), { push: false });
});

updateMineActiveFilters(document);

window.addEventListener('DOMContentLoaded', function() {
    if (@json((bool) request('open_assign'))) {
        openDialog('modal-asignar-mina');
    }

    if (@json((bool) request('open_manage'))) {
        openDialog('modal-gestionar-worker');
    }
});

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

let mineRequirementsData = @json($mineReqsJson);
let assignmentsData = @json($assignmentsJson);
const attemptsUrlTemplate = @json(route('personal.habilitacion-minera.exam-attempts.store', ['workerExamId' => '__EXAM__']));
const completeAttemptUrlTemplate = @json(route('personal.habilitacion-minera.exam-attempts.complete', ['attemptId' => '__ATTEMPT__']));
const noAplicaUrlTemplate = @json(route('personal.habilitacion-minera.exam.not-applicable', ['workerExamId' => '__EXAM__']));
const convalidateUrlTemplate = @json(route('personal.habilitacion-minera.exam.convalidate', ['workerExamId' => '__EXAM__']));
const csrfToken = @json(csrf_token());
const canManageMining = @json($canManage);
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

function parseDisplayDate(value) {
    if (!value) return null;

    const parts = String(value).split('/');
    if (parts.length !== 3) return null;

    const day = Number(parts[0]);
    const month = Number(parts[1]) - 1;
    const year = Number(parts[2]);
    if (!day || month < 0 || !year) return null;

    return new Date(year, month, day);
}

function daysUntilDisplayDate(value) {
    const target = parseDisplayDate(value);
    if (!target) return null;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    target.setHours(0, 0, 0, 0);

    return Math.ceil((target.getTime() - today.getTime()) / 86400000);
}

function examBadgeClass(exam) {
    const attemptsUsed = Number(exam.attempt_count || 0);
    const attemptsMax = Number(exam.max_intentos || 1);

    if (['APROBADO', 'VIGENTE', 'CONVALIDADO', 'NO_APLICA'].includes(exam.estado)) return 'ok';
    if (exam.estado === 'POR_VENCER' || exam.estado === 'PROGRAMADO') return 'warn';
    if (exam.estado === 'OBSERVADO') return 'orange';
    if (exam.estado === 'DESAPROBADO') return attemptsUsed < attemptsMax ? 'orange' : 'danger';
    if (exam.estado === 'VENCIDO') return 'danger';

    return 'neutral';
}

function expirationTextForExam(exam) {
    if (!exam.fecha_vencimiento) {
        if (['APROBADO', 'VIGENTE', 'CONVALIDADO'].includes(exam.estado)) {
            return 'Aprobado sin vencimiento';
        }
        if (exam.estado === 'NO_APLICA') {
            return 'No aplica para este trabajador';
        }

        return '';
    }

    const days = daysUntilDisplayDate(exam.fecha_vencimiento);
    if (exam.estado === 'VENCIDO' || (days !== null && days < 0)) {
        return 'Examen vencido el ' + exam.fecha_vencimiento;
    }
    if (exam.estado === 'POR_VENCER' || (days !== null && days <= 30)) {
        return 'Por vencer en ' + Math.max(0, days || 0) + ' dias (' + exam.fecha_vencimiento + ')';
    }

    return 'Vigente hasta ' + exam.fecha_vencimiento;
}

function todayInputDate() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today.toISOString().slice(0, 10);
}

function resultOptionsHtml(includePending) {
    let html = '';
    Object.entries(attemptResultLabels || {}).forEach(function(entry) {
        const key = entry[0];
        const label = entry[1];
        if (!includePending && key === 'PENDIENTE') return;
        html += '<option value="' + escAttr(key) + '">' + escHtml(label) + '</option>';
    });

    return html;
}

function formField(label, html, className) {
    return '<label' + (className ? ' class="' + escAttr(className) + '"' : '') + '>' + escHtml(label) + html + '</label>';
}

function renderScheduleForm(exam, canSchedule) {
    if (!canSchedule) {
        return '<div class="mine-exam-empty-panel">No se puede programar otro intento para este examen. Revisa si ya tiene una programación pendiente, si ya fue resuelto o si agotó intentos.</div>';
    }

    let html = '<form method="POST" action="' + attemptsUrlTemplate.replace('__EXAM__', exam.id) + '" class="mine-form" data-loading-message="Programando examen...">';
    html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
    html += '<input type="hidden" name="resultado" value="PENDIENTE">';
    html += formField('Fecha de programación', '<input type="date" name="fecha_programacion" required>');
    html += formField('Observación', '<input type="text" name="observacion" placeholder="Opcional">', 'is-wide');
    html += '<button type="submit" class="btn btn-primary btn-xs" style="grid-column:1/-1;">Guardar programación</button>';
    html += '</form>';

    return html;
}

function renderCompleteScheduledForm(attempt) {
    let html = '<form method="POST" enctype="multipart/form-data" action="' + completeAttemptUrlTemplate.replace('__ATTEMPT__', attempt.id) + '" class="mine-form" data-loading-message="Registrando resultado programado...">';
    html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
    html += formField('Realización', '<input type="date" name="fecha_realizacion" value="' + todayInputDate() + '" required>');
    html += formField('Resultado', '<select name="resultado" required>' + resultOptionsHtml(false) + '</select>');
    html += formField('Nota', '<input type="number" name="nota" step="0.01">');
    html += formField('Vencimiento manual', '<input type="date" name="fecha_vencimiento">');
    html += formField('Archivo', '<input type="file" name="archivo">');
    html += formField('Observación', '<input type="text" name="observacion">', 'is-wide');
    html += '<button type="submit" class="btn btn-primary btn-xs" style="grid-column:1/-1;">Registrar resultado</button>';
    html += '</form>';

    return html;
}

function renderProgrammedPanel(exam, scheduledAttempts) {
    if (!scheduledAttempts.length) {
        return '<div class="mine-exam-empty-panel">Este examen no tiene programaciones pendientes.</div>';
    }

    let html = '<div class="mine-programmed-list">';
    scheduledAttempts.forEach(function(attempt) {
        const days = daysUntilDisplayDate(attempt.fecha_programacion);
        const isDue = days !== null && days <= 0;
        const dueText = isDue
            ? 'Ya corresponde registrar resultado'
            : 'Faltan ' + escHtml(days) + ' día(s)';

        html += '<div class="mine-programmed-item' + (isDue ? '' : ' is-future') + '">';
        html += '<div class="mine-programmed-head">';
        html += '<strong>Programado: ' + escHtml(attempt.fecha_programacion || '-') + '</strong>';
        html += '<span class="mine-badge ' + (isDue ? 'warn' : 'info') + '">' + dueText + '</span>';
        html += '</div>';

        if (attempt.observacion) {
            html += '<span class="mine-muted">' + escHtml(attempt.observacion) + '</span>';
        }

        if (isDue && canManageMining) {
            html += renderCompleteScheduledForm(attempt);
        } else if (!isDue) {
            html += '<span class="mine-muted">Aún no se habilita la carga de resultado porque la fecha programada no ha pasado.</span>';
        } else {
            html += '<span class="mine-muted">No tienes permiso para registrar resultados.</span>';
        }

        html += '</div>';
    });
    html += '</div>';

    return html;
}

function renderPerformedForm(exam, canRegister) {
    if (!canRegister) {
        return '<div class="mine-exam-empty-panel">No se puede registrar otro resultado para este examen. Puede estar resuelto o sin intentos disponibles.</div>';
    }

    let html = '<form method="POST" enctype="multipart/form-data" action="' + attemptsUrlTemplate.replace('__EXAM__', exam.id) + '" class="mine-form" data-loading-message="Registrando examen realizado...">';
    html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
    html += formField('Realización', '<input type="date" name="fecha_realizacion" value="' + todayInputDate() + '" required>');
    html += formField('Resultado', '<select name="resultado" required>' + resultOptionsHtml(false) + '</select>');
    html += formField('Nota', '<input type="number" name="nota" step="0.01">');
    html += formField('Vencimiento manual', '<input type="date" name="fecha_vencimiento">');
    html += formField('Archivo', '<input type="file" name="archivo">');
    html += formField('Observación', '<input type="text" name="observacion">', 'is-wide');
    html += '<button type="submit" class="btn btn-primary btn-xs" style="grid-column:1/-1;">Registrar examen realizado</button>';
    html += '</form>';

    return html;
}

function renderWorkerExamCard(exam, focusExamId) {
    const attemptsUsed = Number(exam.attempt_count || 0);
    const attemptsMax = Number(exam.max_intentos || 1);
    const attemptsAvailable = attemptsUsed < attemptsMax;
    const resolvedStates = ['APROBADO', 'VIGENTE', 'CONVALIDADO', 'NO_APLICA'];
    const resolved = resolvedStates.includes(exam.estado);
    const canRegisterAttempt = canManageMining && !resolved && attemptsAvailable;
    const scheduledAttempts = (exam.intentos || []).filter(function(attempt) {
        return attempt.fecha_programacion && attempt.resultado === 'PENDIENTE';
    });
    const canSchedule = canRegisterAttempt && scheduledAttempts.length === 0;
    const badgeClass = examBadgeClass(exam);
    const stateLabel = examStateLabels[exam.estado] || exam.estado;
    const expirationLabel = expirationTextForExam(exam);
    const details = [];
    const focused = focusExamId && String(exam.id) === String(focusExamId);

    if (exam.lugar) details.push('Lugar: ' + exam.lugar);
    if (exam.precio !== null && exam.precio !== undefined) details.push('Precio: ' + exam.precio);
    if (exam.fecha_programacion) details.push('Última programación: ' + exam.fecha_programacion);
    if (exam.fecha_realizacion) details.push('Última realización: ' + exam.fecha_realizacion);
    if (expirationLabel) details.push(expirationLabel);

    let html = '<div class="mine-exam-item' + (focused ? ' is-focused' : '') + '" data-worker-exam-card="' + escAttr(exam.id) + '">';
    html += '<div class="mine-exam-head">';
    html += '<div class="mine-exam-titleline"><strong>' + escHtml(exam.nombre) + '</strong><span class="mine-badge ' + badgeClass + '">' + escHtml(stateLabel) + '</span></div>';
    html += '<span class="mine-muted">Intentos: ' + escHtml(attemptsUsed) + '/' + escHtml(attemptsMax) + '</span>';
    html += '</div>';
    html += '<div class="mine-muted">' + escHtml(details.join(' · ')) + '</div>';
    html += '<div class="mine-exam-action-row">';
    html += '<button type="button" class="btn btn-outline btn-xs" data-exam-action-toggle="schedule">Programar examen</button>';
    html += '<button type="button" class="btn btn-outline btn-xs" data-exam-action-toggle="scheduled">Ver programados' + (scheduledAttempts.length ? ' (' + scheduledAttempts.length + ')' : '') + '</button>';
    html += '<button type="button" class="btn btn-primary btn-xs" data-exam-action-toggle="performed">Registrar examen realizado</button>';
    html += '</div>';
    html += '<div class="mine-exam-panel" data-exam-action-panel="schedule">' + renderScheduleForm(exam, canSchedule) + '</div>';
    html += '<div class="mine-exam-panel" data-exam-action-panel="scheduled">' + renderProgrammedPanel(exam, scheduledAttempts) + '</div>';
    html += '<div class="mine-exam-panel" data-exam-action-panel="performed">' + renderPerformedForm(exam, canRegisterAttempt) + '</div>';

    if (exam.intentos && exam.intentos.length) {
        html += '<div class="mine-attempt-list">';
        exam.intentos.forEach(function(attempt) {
            const result = attemptResultLabels[attempt.resultado] || attempt.resultado || '-';
            const parts = ['Intento ' + attempt.numero + ': ' + result];

            if (attempt.fecha_programacion) parts.push('Prog. ' + attempt.fecha_programacion);
            if (attempt.fecha_realizacion) parts.push('Real. ' + attempt.fecha_realizacion);
            if (attempt.nota !== null && attempt.nota !== undefined) parts.push('Nota: ' + attempt.nota);
            if (attempt.observacion) parts.push(attempt.observacion);

            html += '<div class="mine-muted">' + escHtml(parts.join(' · ')) + '</div>';
            if (attempt.archivo_url) {
                html += '<a class="btn btn-outline btn-xs" href="' + escHtml(attempt.archivo_url) + '">Descargar archivo</a>';
            }
        });
        html += '</div>';
    }

    if (exam.estado !== 'NO_APLICA' && exam.estado !== 'CONVALIDADO') {
        html += '<details class="mine-details-card">';
        html += '<summary><span>Marcar no aplica</span><span class="mine-badge info">Por área</span></summary>';
        html += '<form method="POST" action="' + noAplicaUrlTemplate.replace('__EXAM__', exam.id) + '" class="mine-form" data-loading-message="Marcando examen como no aplica...">';
        html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
        html += formField('Observación no aplica', '<input type="text" name="observacion">', 'is-wide');
        html += '<button type="submit" class="btn btn-outline btn-xs" style="grid-column:1/-1;">Marcar no aplica</button>';
        html += '</form>';
        html += '</details>';
    }

    html += '</div>';

    return html;
}

function openWorkerExams(assignmentId, workerName, mineName, focusExamId) {
    const modalTitle = document.getElementById('workerExamModalTitle');
    const modalBody = document.getElementById('workerExamModalBody');

    modalTitle.textContent = 'Exámenes de ' + workerName + ' en ' + mineName;

    const data = (assignmentsData || []).find(function(item) {
        return String(item.id) === String(assignmentId);
    });

    let html = '';

    if (!data) {
        html = '<div class="mine-exam-item">';
        html += '<div class="mine-exam-head">';
        html += '<div class="mine-exam-titleline"><strong>Asignacion no encontrada en la vista</strong><span class="mine-badge warn">Actualizar</span></div>';
        html += '<span class="mine-muted">No se pudo cargar el detalle de esta mina. Actualiza la pagina o vuelve a seleccionar el trabajador.</span>';
        html += '</div>';
        html += '</div>';
    } else if (!data.examenes || !data.examenes.length) {
        html = '<div class="mine-exam-item">';
        html += '<div class="mine-exam-head">';
        html += '<div class="mine-exam-titleline"><strong>Sin examenes generados</strong><span class="mine-badge info">Pendiente de inicio</span></div>';
        html += '<span class="mine-muted">La mina ya esta asignada, pero todavia no tiene sus examenes creados para gestionar programacion, resultados o no aplica.</span>';
        html += '</div>';
        if (canManageMining && data.generate_exams_url) {
            html += '<form method="POST" action="' + escAttr(data.generate_exams_url) + '" class="mine-form" data-loading-message="Generando examenes requeridos...">';
            html += '<input type="hidden" name="_token" value="' + csrfToken + '">';
            html += '<button type="submit" class="btn btn-primary btn-xs" style="grid-column:1/-1;">Generar examenes requeridos</button>';
            html += '</form>';
        } else {
            html += '<span class="mine-muted">No tienes permiso para generar examenes desde esta vista.</span>';
        }
        html += '</div>';
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
            html += renderWorkerExamCard(exam, focusExamId);
        });

        html += '</div>';
    }

    modalBody.innerHTML = html;
    document.getElementById('modal-worker-exams').showModal();

    if (focusExamId) {
        const focusedItem = modalBody.querySelector('.mine-exam-item.is-focused');
        if (focusedItem) {
            window.setTimeout(function() {
                focusedItem.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }, 50);
        }
    }
}
</script>
@endpush
