<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\ExamenMinero;
use App\Models\MinaRequisito;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\PersonalMina;
use App\Modules\Personal\Services\PersonalMinaExcelImportService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Modules\Personal\Services\PersonalMinaDashboardService;
use App\Modules\Personal\Services\PersonalMinaImportPreviewStore;
use App\Modules\Personal\Services\MineRequirementSyncService;
use App\Modules\Personal\Services\MiningExamCatalogAuditService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalMinaHabilitacionController extends WebPageController
{
    public function __construct(
        private readonly PersonalMinaHabilitacionService $service,
        private readonly PersonalMinaExcelImportService $excelImport,
        private readonly PersonalMinaDashboardService $dashboardService,
        private readonly PersonalMinaImportPreviewStore $importPreviewStore,
    )
    {
    }

    public function bulkPreview(Request $request, \App\Modules\Personal\Services\PersonalMinaBulkPreviewService $preview): View
    {
        $this->assertMiningPermission('importar');
        $rows = null;
        $token = null;
        if ($request->isMethod('post')) {
            $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
            try {
                $source = $preview->read($request->file('archivo')->getRealPath());
                $rows = $preview->plan($source);
                $token = (string) \Illuminate\Support\Str::uuid();
                $this->importPreviewStore->store(['token'=>$token,'kind'=>'cursos','source'=>$source,'rows'=>$rows,
                    'fingerprint'=>app(\App\Modules\Personal\Services\PersonalCursoBulkService::class)->fingerprint($rows)],
                    $this->requireAuthenticatedUser()->id);
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\PhpOffice\PhpSpreadsheet\Exception $exception) {
                report($exception);
                throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel. Verifique que no este danado ni protegido.']);
            }
        }

        $report = $rows !== null ? $preview->report($rows) : null;

        $canViewManagement = $this->canMiningPermission('ver');
        $canConfigure = $this->canMiningPermission('configurar');
        $canCreateExam = $canViewManagement && $this->canMiningPermission(['crear', 'configurar']);
        $canEditExam = $canViewManagement && $this->canMiningPermission(['editar', 'configurar']);

        return view('personal.habilitacion-minera.bulk-preview', compact('rows', 'report', 'token', 'canConfigure', 'canViewManagement', 'canCreateExam', 'canEditExam'));
    }

    public function bulkConfirm(Request $request, \App\Modules\Personal\Services\PersonalCursoBulkService $bulk): RedirectResponse
    {
        $this->assertMiningPermission('importar');
        $validated = $request->validate(['token'=>['required','uuid'], 'confirmar'=>['accepted']]);
        $user = $this->requireAuthenticatedUser();
        $preview = $this->importPreviewStore->retrieve($validated['token'], $user->id);
        abort_unless($preview && ($preview['kind'] ?? '') === 'cursos', 422, 'La revision vencio. Cargue nuevamente el archivo.');
        foreach ($preview['rows'] as $row) {
            if (($row['disposition'] ?? '') === 'changes') {
                $this->assertMiningPermission($row['type'] === 'Programacion' ? 'programar' : 'registrar');
            }
        }
        $summary = $bulk->apply($preview, $user);
        $this->importPreviewStore->delete($validated['token'], $user->id);
        return redirect()->route('personal.habilitacion-minera.bulk-preview')
            ->with('success', 'Carga confirmada. '.collect($summary)->map(fn ($n, $key) => ucfirst($key).': '.$n)->implode(' · '));
    }

    public function bulkRecheck(Request $request, \App\Modules\Personal\Services\PersonalMinaBulkPreviewService $preview): View
    {
        $this->assertMiningPermission('importar');
        $validated = $request->validate(['token' => ['required', 'uuid']]);
        $userId = (string) $this->requireAuthenticatedUser()->id;
        $old = $this->importPreviewStore->retrieve($validated['token'], $userId);
        abort_unless($old && ($old['kind'] ?? '') === 'cursos' && isset($old['source']), 422, 'La revision vencio. Cargue nuevamente el archivo.');

        return $this->renderRecheckedBulkPreview($preview, $old, $validated['token'], $userId);
    }

    public function bulkConfigureRequirement(Request $request, \App\Modules\Personal\Services\PersonalMinaBulkPreviewService $preview): View
    {
        $this->assertMiningPermission('configurar');
        $this->assertMiningPermission('importar');
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'mina_id' => ['required', 'uuid'],
            'examen_id' => ['required', 'uuid'],
            'confirmar' => ['accepted'],
            'obligatorio' => ['nullable', 'boolean'],
            'permite_no_aplica' => ['nullable', 'boolean'],
            'permite_convalidacion_mina' => ['nullable', 'boolean'],
        ]);
        $user = $this->requireAuthenticatedUser();
        $old = $this->importPreviewStore->retrieve($validated['token'], (string) $user->id);
        abort_unless($old && ($old['kind'] ?? '') === 'cursos' && isset($old['source']), 422, 'La revision vencio. Cargue nuevamente el archivo.');

        $currentRows = $preview->plan($old['source']);
        $missing = collect($currentRows)->contains(fn (array $row): bool =>
            ($row['mina_id'] ?? null) === $validated['mina_id']
            && ($row['examen_id'] ?? null) === $validated['examen_id']
            && count($row['issues']) === 1
            && in_array('El curso o EMO no esta configurado como requisito activo para la mina indicada.', $row['issues'], true));
        abort_unless($missing, 422, 'El requisito ya cambio o no corresponde a este archivo. Revise de nuevo.');

        $this->service->storeRequirementDeferred([
            'mina_id' => $validated['mina_id'],
            'examen_id' => $validated['examen_id'],
            'obligatorio' => (bool) ($validated['obligatorio'] ?? true),
            'permite_no_aplica' => (bool) ($validated['permite_no_aplica'] ?? false),
            'permite_convalidacion_mina' => (bool) ($validated['permite_convalidacion_mina'] ?? false),
        ], $user);
        session()->flash('success', 'Requisito configurado. La habilitacion de la mina se actualiza en segundo plano.');

        return $this->renderRecheckedBulkPreview($preview, $old, $validated['token'], (string) $user->id);
    }

    private function renderRecheckedBulkPreview(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService $preview, array $old, string $oldToken, string $userId): View
    {
        $rows = $preview->plan($old['source']);
        $token = (string) \Illuminate\Support\Str::uuid();
        $this->importPreviewStore->store([
            'token' => $token,
            'kind' => 'cursos',
            'source' => $old['source'],
            'rows' => $rows,
            'fingerprint' => app(\App\Modules\Personal\Services\PersonalCursoBulkService::class)->fingerprint($rows),
        ], $userId);
        $this->importPreviewStore->delete($oldToken, $userId);
        $report = $preview->report($rows);

        $canViewManagement = $this->canMiningPermission('ver');
        $canConfigure = $this->canMiningPermission('configurar');
        $canCreateExam = $canViewManagement && $this->canMiningPermission(['crear', 'configurar']);
        $canEditExam = $canViewManagement && $this->canMiningPermission(['editar', 'configurar']);

        return view('personal.habilitacion-minera.bulk-preview', compact('rows', 'report', 'token', 'canConfigure', 'canViewManagement', 'canCreateExam', 'canEditExam'));
    }

    public function index(Request $request): View
    {
        $this->assertMiningPermission('ver');

        $allowedViews = array_filter([
            'worker',
            'dashboard',
            $this->canMiningPermission('ver_matriz') ? 'matrix' : null,
            $this->canMiningPermission('ver_vencimientos') ? 'expiring' : null,
            $this->canMiningPermission('ver_programados') ? 'scheduled' : null,
        ]);
        $requestedView = in_array((string) $request->input('vista'), $allowedViews, true)
            ? (string) $request->input('vista')
            : 'worker';
        $partial = $request->ajax();
        $needsWorker = $requestedView === 'worker';
        $needsMatrix = $requestedView === 'matrix';
        $needsDashboard = $requestedView === 'dashboard';
        $needsExpiring = $requestedView === 'expiring';
        $needsScheduled = $requestedView === 'scheduled';

        $filters = [
            'mina_id' => $request->input('mina_id', ''),
            'trabajador' => $request->input('trabajador', ''),
            'worker_id' => $request->input('worker_id', ''),
            'estado_habilitacion' => '',
            'estado_laboral' => '',
            'estado_examen' => $request->input('estado_examen', ''),
            'per_page' => $request->input('per_page', 15),
            'worker_limit' => (int) $request->input('worker_limit', 20),
            'worker_page' => max(1, (int) $request->input('worker_page', 1)),
        ];
        $filters['per_page'] = $this->service->perPageFromFilters($filters);
        $allowedWorkerLimits = [10, 20, 50, 80, 200];
        $filters['worker_limit'] = in_array($filters['worker_limit'], $allowedWorkerLimits, true) ? $filters['worker_limit'] : 20;
        $selectedWorker = $needsWorker ? $this->service->findWorker($filters['worker_id']) : null;
        $upcomingExpirationDays = 60;
        $scheduledExamDays = 60;
        $dashboardFilters = [
            'mina_id' => $request->input('dashboard_mina_id', ''),
            'estado_habilitacion' => $request->input('dashboard_estado_habilitacion', ''),
            'estado_examen' => '',
        ];

        $canViewPrices = $this->canMiningPermission(['ver_historial_precios', 'configurar']);
        $workers = $needsWorker
            ? $this->service->workerOptions($filters['trabajador'] ?: null, $filters['worker_limit'], $filters['worker_page'])
            : new LengthAwarePaginator([], 0, $filters['worker_limit'], 1);
        $assignments = $needsMatrix
            ? $this->service->listGroupedByWorker($filters)
            : new LengthAwarePaginator([], 0, $filters['per_page'], 1);
        $requirements = ($needsWorker || $needsMatrix)
            ? $this->service->listRequirements($filters['mina_id'] ?: null)
            : collect();
        $mines = ($needsWorker || $needsMatrix || $needsDashboard) ? $this->service->activeMines() : collect();

        return view('personal.habilitacion-minera.index', [
            'filters' => $filters,
            'assignments' => $assignments,
            'requirements' => $requirements,
            'exams' => !$partial ? $this->service->listMiningExams() : collect(),
            'allExams' => !$partial ? $this->service->listAllMiningExams() : collect(),
            'priceHistory' => !$partial && $canViewPrices ? $this->service->listPriceHistory() : collect(),
            'mines' => $mines,
            'workers' => $workers,
            'workersTotal' => $workers->total(),
            'selectedWorker' => $selectedWorker,
            'mineBoard' => $needsWorker ? $this->service->mineStatusBoardFor($selectedWorker) : [],
            'upcomingExpirationDays' => $upcomingExpirationDays,
            'upcomingExpirations' => $needsExpiring ? $this->service->upcomingExamExpirations($upcomingExpirationDays) : collect(),
            'scheduledExamDays' => $scheduledExamDays,
            'scheduledExams' => $needsScheduled ? $this->service->scheduledExamAttempts($scheduledExamDays) : collect(),
            'stateOptions' => $this->service->habilitationStateOptions(),
            'examStateOptions' => $this->service->examStateOptions(),
            'attemptResultOptions' => $this->service->attemptResultOptions(),
            'importPreview' => session('habilitacion_mina_import_preview'),
            'importPreviewOpen' => (bool) session('habilitacion_mina_import_modal_open', false),
            'service' => $this->service,
            'miningDashboard' => $needsDashboard ? $this->dashboardService->dashboard($dashboardFilters) : $this->dashboardService->emptyDashboard(),
            'dashboardFilters' => $dashboardFilters,
            'requirementSyncs' => !$partial && $needsWorker && $this->canMiningPermission('configurar')
                ? app(MineRequirementSyncService::class)->statusesByMine()
                : collect(),
        ]);
    }

    public function dashboardExamWorkers(Request $request): JsonResponse
    {
        $this->assertMiningPermission('ver');
        $validated = $request->validate([
            'examen_nombre' => ['required', 'string', 'max:191'],
            'estado_examen' => ['required', 'string', 'in:PENDIENTE,PROGRAMADO,POR_VENCER,VENCIDO,OBSERVADO,DESAPROBADO'],
            'mina_id' => ['nullable', 'string', 'size:36'],
            'estado_habilitacion' => ['nullable', 'string', 'in:HABILITADO,EN_PROCESO'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $workers = $this->dashboardService->examWorkers($validated);

        return response()->json([
            'items' => collect($workers->items())->map(fn ($row): array => [
                'nombre' => $row->nombre_completo,
                'documento' => $row->dni ?: $row->numero_documento,
                'mina' => $row->mina,
                'estado' => $row->estado,
                'fecha_programacion' => $this->formatDashboardDate($row->fecha_programacion),
                'fecha_realizacion' => $this->formatDashboardDate($row->fecha_realizacion),
                'fecha_vencimiento' => $this->formatDashboardDate($row->fecha_vencimiento),
            ])->values(),
            'pagination' => [
                'current_page' => $workers->currentPage(),
                'last_page' => $workers->lastPage(),
                'total' => $workers->total(),
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function storeExam(Request $request): RedirectResponse
    {
        $this->assertMiningPermission(['crear', 'configurar']);

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:191'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'tipo' => ['required', 'string', 'max:80'],
            'requiere_lugar' => ['nullable', 'boolean'],
            'lugar' => ['nullable', 'string', 'max:191'],
            'empresa_paga' => ['nullable', 'boolean'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'moneda' => ['nullable', 'string', 'max:10'],
            'precio_desde' => ['nullable', 'date'],
            'tiene_vigencia' => ['nullable', 'boolean'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
            'permite_reintento' => ['nullable', 'boolean'],
            'max_intentos' => ['required', 'integer', 'min:1', 'max:2'],
            'critico' => ['nullable', 'boolean'],
            'desaprueba_finaliza_proceso' => ['nullable', 'boolean'],
            'requiere_nota' => ['nullable', 'boolean'],
            'nota_minima' => ['nullable', 'numeric'],
            'permite_convalidacion' => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'observacion_precio' => ['nullable', 'string', 'max:5000'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->service->storeMiningExam($validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar el examen.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Examen minero registrado correctamente.');
    }

    public function updateExam(Request $request, string $examId): RedirectResponse
    {
        $this->assertMiningPermission(['editar', 'configurar']);

        $exam = ExamenMinero::query()->find($examId);
        abort_if(!$exam, 404);

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:191'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'tipo' => ['nullable', 'string', 'max:80'],
            'requiere_lugar' => ['nullable', 'boolean'],
            'lugar' => ['nullable', 'string', 'max:191'],
            'empresa_paga' => ['nullable', 'boolean'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'moneda' => ['nullable', 'string', 'max:10'],
            'precio_desde' => ['nullable', 'date'],
            'tiene_vigencia' => ['nullable', 'boolean'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
            'permite_reintento' => ['nullable', 'boolean'],
            'max_intentos' => ['required', 'integer', 'min:1', 'max:2'],
            'critico' => ['nullable', 'boolean'],
            'desaprueba_finaliza_proceso' => ['nullable', 'boolean'],
            'requiere_nota' => ['nullable', 'boolean'],
            'nota_minima' => ['nullable', 'numeric'],
            'permite_convalidacion' => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'observacion_precio' => ['nullable', 'string', 'max:5000'],
            'activo' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->service->updateMiningExam($exam, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo actualizar el examen.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Examen minero actualizado correctamente.');
    }

    public function storeExamPrice(Request $request, string $examId): RedirectResponse
    {
        $this->assertMiningPermission('configurar');

        $exam = ExamenMinero::query()->find($examId);
        abort_if(!$exam, 404);

        $validated = $request->validate([
            'precio' => ['required', 'numeric', 'min:0'],
            'moneda' => ['required', 'string', 'max:10'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->storeExamPrice($exam, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar el precio.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Precio de examen registrado correctamente.');
    }

    public function storeRequirement(Request $request): RedirectResponse
    {
        $this->assertMiningPermission('configurar');

        $validated = $request->validate([
            'mina_id' => ['required', 'string', 'size:36'],
            'examen_id' => ['nullable', 'string', 'size:36'],
            'nombre' => ['nullable', 'string', 'max:191'],
            'tipo' => ['nullable', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'obligatorio' => ['nullable', 'boolean'],
            'critico' => ['nullable', 'boolean'],
            'reprogramable' => ['nullable', 'boolean'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'permite_no_aplica' => ['nullable', 'boolean'],
            'permite_convalidacion_mina' => ['nullable', 'boolean'],
            'fecha_inicio_convalidacion' => ['nullable', 'date'],
            'fecha_fin_convalidacion' => ['nullable', 'date', 'after_or_equal:fecha_inicio_convalidacion'],
            'convalidar_desde_otras_minas' => ['nullable', 'boolean'],
            'minas_origen_convalidacion' => ['nullable', 'array'],
            'minas_origen_convalidacion.*' => ['nullable', 'string', 'size:36'],
            'vigencia_dias_override' => ['nullable', 'integer', 'min:1'],
            'observacion_mina' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->storeRequirementDeferred($validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar el requisito.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', ['mina_id' => $validated['mina_id']])
            ->with('success', 'Requisito registrado. Las habilitaciones de la mina se estan actualizando.');
    }

    public function catalogAudit(MiningExamCatalogAuditService $audit): JsonResponse
    {
        $this->assertMiningPermission('configurar');

        return response()->json($audit->report())->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->assertMiningPermission('asignar');

        $validated = $request->validate([
            'personal_id' => ['required', 'string', 'size:36'],
            'mina_id' => ['required', 'string', 'size:36'],
            'estado_habilitacion' => ['required', 'string', 'max:40'],
            'fecha_asignacion' => ['nullable', 'date'],
            'fecha_inicio_proceso' => ['nullable', 'date'],
            'fecha_habilitacion' => ['nullable', 'date'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'confirmar_trabajador_cesado' => ['sometimes', 'accepted'],
        ]);

        try {
            $this->service->assignMine($validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo asignar la mina.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', array_merge($request->query(), [
                'mina_id' => $validated['mina_id'],
                'worker_id' => $validated['personal_id'],
            ]))
            ->with('success', 'Mina asignada al trabajador correctamente.');
    }

    public function update(Request $request, string $assignmentId): RedirectResponse
    {
        $this->assertMiningPermission(['actualizar', 'editar']);

        $assignment = PersonalMina::query()->find($assignmentId);
        abort_if(!$assignment, 404);

        $validated = $request->validate([
            'estado_habilitacion' => ['required', 'string', 'max:40'],
            'fecha_inicio_proceso' => ['nullable', 'date'],
            'fecha_habilitacion' => ['nullable', 'date'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'confirmar_trabajador_cesado' => ['sometimes', 'accepted'],
        ]);

        try {
            $this->service->updateAssignment($assignment, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo actualizar la habilitacion.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Habilitacion actualizada correctamente.');
    }

    public function deactivate(Request $request, string $assignmentId): RedirectResponse
    {
        $this->assertMiningPermission('desasignar');

        $assignment = PersonalMina::query()->find($assignmentId);
        abort_if(!$assignment, 404);

        $validated = $request->validate([
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->service->deactivateAssignment($assignment, $this->requireAuthenticatedUser(), $validated['observacion'] ?? null);

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Asignacion minera desactivada correctamente.');
    }

    public function deactivateRequirement(Request $request, string $requirementId): RedirectResponse|JsonResponse
    {
        $this->assertMiningPermission('configurar');

        $requirement = MinaRequisito::query()->find($requirementId);
        abort_if(!$requirement, 404);

        $requirement = $this->service->deactivateRequirementDeferred($requirement, $this->requireAuthenticatedUser());
        $message = 'Examen quitado de la mina. Las habilitaciones se estan actualizando.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'requirement_id' => $requirement->id,
                'deactivated_requirement_ids' => $requirement->getAttribute('deactivated_requirement_ids') ?: [$requirement->id],
                'mina_id' => $requirement->mina_id,
                'active_count' => MinaRequisito::query()
                    ->where('mina_id', $requirement->mina_id)
                    ->where('activo', true)
                    ->count(),
            ]);
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', $message);
    }

    public function generateExams(Request $request, string $assignmentId): RedirectResponse
    {
        $this->assertMiningPermission('programar');

        $assignment = PersonalMina::query()->find($assignmentId);
        abort_if(!$assignment, 404);

        $created = $this->service->generateRequiredExams($assignment, $this->requireAuthenticatedUser());

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', $created > 0 ? 'Examenes requeridos generados correctamente.' : 'No habia examenes nuevos por generar.');
    }

    public function storeAttempt(Request $request, string $workerExamId): RedirectResponse
    {
        $workerExam = PersonalMinaExamen::query()->find($workerExamId);
        abort_if(!$workerExam, 404);

        $validated = $request->validate([
            'fecha_programacion' => ['nullable', 'date'],
            'fecha_realizacion' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'resultado' => ['required', 'string', 'max:40'],
            'nota' => ['nullable', 'numeric'],
            'archivo' => ['nullable', 'file', 'max:10240'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->assertMiningPermission($this->isSchedulingAttempt($validated) ? 'programar' : 'registrar');

        try {
            $this->service->registerAttempt($workerExam, $validated, $request->file('archivo'), $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar el intento.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Intento registrado correctamente.');
    }

    public function completeScheduledAttempt(Request $request, string $attemptId): RedirectResponse
    {
        $this->assertMiningPermission('registrar');

        $attempt = PersonalMinaExamenIntento::query()->find($attemptId);
        abort_if(!$attempt, 404);

        $validated = $request->validate([
            'fecha_realizacion' => ['required', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'resultado' => ['required', 'string', 'max:40'],
            'nota' => ['nullable', 'numeric'],
            'archivo' => ['nullable', 'file', 'max:10240'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->completeScheduledAttempt($attempt, $validated, $request->file('archivo'), $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar el resultado programado.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Resultado del examen programado registrado correctamente.');
    }

    public function notApplicable(Request $request, string $workerExamId): RedirectResponse
    {
        $this->assertMiningPermission('registrar');

        $workerExam = PersonalMinaExamen::query()->find($workerExamId);
        abort_if(!$workerExam, 404);

        $validated = $request->validate([
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->markExamNotApplicable($workerExam, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo marcar no aplica.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Examen marcado como no aplica.');
    }

    public function convalidationOptions(string $workerExamId): JsonResponse
    {
        $this->assertMiningPermission('convalidar');

        $workerExam = PersonalMinaExamen::query()->findOrFail($workerExamId);

        return response()->json($this->service->convalidationOptions($workerExam))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function convalidate(Request $request, string $workerExamId): RedirectResponse
    {
        $this->assertMiningPermission('convalidar');

        $workerExam = PersonalMinaExamen::query()->find($workerExamId);
        abort_if(!$workerExam, 404);

        $validated = $request->validate([
            'examen_origen_id' => ['required', 'string', 'size:36'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->service->convalidateExam($workerExam, $validated['examen_origen_id'], $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo convalidar el examen.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Examen convalidado correctamente.');
    }

    public function downloadAttempt(string $attemptId)
    {
        $this->assertMiningPermission('ver');

        $attempt = PersonalMinaExamenIntento::query()->find($attemptId);
        abort_if(!$attempt || trim((string) $attempt->archivo_path) === '', 404);
        abort_unless(Storage::disk('local')->exists($attempt->archivo_path), 404);

        return Storage::disk('local')->download(
            $attempt->archivo_path,
            $attempt->archivo_nombre_original ?: 'archivo_examen',
        );
    }

    public function previewImport(Request $request): RedirectResponse
    {
        $this->assertMiningPermission('importar');

        $validated = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,xlsm,csv', 'max:20480'],
        ]);

        try {
            $preview = $this->excelImport->preview($validated['archivo']);
            $userId = (string) $this->requireAuthenticatedUser()->id;
            $previousToken = (string) session('habilitacion_mina_import_preview.token', '');
            $displayPreview = $this->importPreviewStore->store($preview, $userId);
            if ($previousToken !== '' && $previousToken !== ($preview['token'] ?? '')) {
                $this->importPreviewStore->delete($previousToken, $userId);
            }
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'No se pudo analizar el Excel. Revisa el archivo o comunicalo a administracion si el problema persiste.');
        }

        session(['habilitacion_mina_import_preview' => $displayPreview]);

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Excel analizado. Revisa la vista previa antes de confirmar.')
            ->with('habilitacion_mina_import_modal_open', true);
    }

    public function confirmImport(Request $request): RedirectResponse
    {
        $this->assertMiningPermission('importar');

        $displayPreview = session('habilitacion_mina_import_preview');
        $token = (string) $request->input('token', $displayPreview['token'] ?? '');
        if (!$displayPreview || $token === '' || $token !== ($displayPreview['token'] ?? null)) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'No hay una vista previa de importacion vigente.');
        }

        if (!$request->boolean('confirmar_reemplazo')) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'Confirma que revisaste las desactivaciones y el reemplazo del catálogo antes de continuar.')
                ->with('habilitacion_mina_import_modal_open', true);
        }

        $user = $this->requireAuthenticatedUser();
        $preview = $this->importPreviewStore->retrieve($token, (string) $user->id);
        if (!$preview) {
            session()->forget('habilitacion_mina_import_preview');

            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'La vista previa vencio. Vuelve a analizar el Excel antes de confirmar.');
        }

        try {
            $result = $this->excelImport->confirm($preview, $user);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->importPreviewStore->delete($token, (string) $user->id);
            session()->forget('habilitacion_mina_import_preview');

            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', $exception->errors()['archivo'][0] ?? 'El catálogo cambió. Analiza nuevamente el Master.');
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'No se pudo confirmar la importacion. No se aplicaron cambios parciales.')
                ->with('habilitacion_mina_import_modal_open', true);
        }

        $this->importPreviewStore->delete($token, (string) $user->id);
        session()->forget('habilitacion_mina_import_preview');

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Importacion confirmada: ' . collect($result)->map(fn ($value, $key) => $key . ': ' . $value)->implode(', '))
            ->with('habilitacion_mina_import_completed', true);
    }

    public function syncCurrent(Request $request): RedirectResponse
    {
        $this->assertMiningPermission('actualizar');

        $result = $this->service->syncCurrentInformation($this->requireAuthenticatedUser());

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Estados recalculados: ' . collect($result)->map(fn ($value, $key) => str_replace('_', ' ', $key) . ': ' . $value)->implode(', '));
    }

    private function assertMiningPermission(string|array $actions): void
    {
        abort_unless($this->canMiningPermission($actions), 403, 'No tienes permiso para realizar esta accion.');
    }

    private function formatDashboardDate($date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function canMiningPermission(string|array $actions): bool
    {
        return PermissionMatrix::allowsAny(
            session('user.permissions', []),
            'habilitacion_minera',
            is_array($actions) ? $actions : [$actions],
        );
    }

    private function isSchedulingAttempt(array $validated): bool
    {
        return strtoupper(trim((string) ($validated['resultado'] ?? ''))) === PersonalMinaExamenIntento::RESULTADO_PENDIENTE
            && filled($validated['fecha_programacion'] ?? null)
            && blank($validated['fecha_realizacion'] ?? null);
    }
}
