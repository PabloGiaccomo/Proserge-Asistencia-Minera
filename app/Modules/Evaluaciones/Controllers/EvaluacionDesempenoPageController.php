<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\AsistenciaDetalle;
use App\Models\EvaluacionesImportLote;
use App\Models\EvaluacionSupervisor;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionDiariaRequest;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionDirectaRequest;
use App\Modules\Evaluaciones\Requests\UpdateEvaluacionDesempenoRequest;
use App\Modules\Evaluaciones\Requests\UpdateEvaluacionSupervisorRequest;
use App\Modules\Evaluaciones\Services\EvaluacionDesempenoService;
use App\Modules\Evaluaciones\Services\EvaluacionesDashboardService;
use App\Modules\Evaluaciones\Services\EvaluacionesPageService;
use App\Modules\Evaluaciones\Services\EvaluacionSupervisorService;
use App\Modules\Evaluaciones\Support\ResidentEvaluationTemplate;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EvaluacionDesempenoPageController extends WebPageController
{
    public function __construct(
        private readonly EvaluacionesPageService $pageService,
        private readonly EvaluacionDesempenoService $performanceService,
        private readonly EvaluacionSupervisorService $supervisorService,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->requireAuthenticatedUser();
        $filters = $request->validate([
            'tipo' => ['nullable', 'string', 'in:desempeno,supervisores,residentes,dashboard'],
            'fecha' => ['nullable', 'date'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'mina' => ['nullable', 'string', 'max:36'],
            'cargo' => ['nullable', 'string', 'max:191'],
            'evaluacion_tipo' => ['nullable', 'in:desempeno,supervisores,residentes'],
            'origen' => ['nullable', 'in:ASISTENCIA,DIRECTA,HISTORICA,ACTUAL,LEGADO'],
            'estado_supervisor' => ['nullable', 'in:pendientes,regularizadas'],
            'buscar_supervisor' => ['nullable', 'string', 'max:191'],
            'pagina_supervisor' => ['nullable', 'integer', 'min:1'],
            'regularizar_supervisor' => ['nullable', 'uuid'],
        ]);
        $type = $filters['tipo'] ?? $this->typeFromRoute($request);
        if ($type === 'dashboard') {
            abort_unless(PermissionMatrix::userCanDirect($user, 'evaluaciones', 'dashboards'), 403, 'No tienes permiso para ver el Dashboard de evaluaciones.');
        }
        $date = $filters['fecha'] ?? now()->toDateString();
        if ($request->filled('asistencia_detalle_id')) {
            abort_unless($type === 'desempeno', 422, 'Selecciona la evaluación diaria para abrir esta asistencia.');
            $detail = $this->validatedDailyDetail($request);
            abort_unless($detail->asistencia->fecha->toDateString() === $date, 422, 'La asistencia no corresponde a la fecha seleccionada.');
        }
        $data = $this->pageService->build($user, $type, $date, $filters);

        abort_if($data['availableTypes']->isEmpty(), 403, 'No tienes acceso a evaluaciones.');

        $data['regularizingSupervisor'] = null;
        $data['canRegularizeSupervisor'] = $this->supervisorService->canRegularize($user);
        $data['supervisorHistoryFilters'] = $filters;
        $data['historicalReports'] = PermissionMatrix::userCanDirect($user, 'evaluaciones', 'administrar')
            ? EvaluacionesImportLote::query()->where('estado', 'CONFIRMADO')->orderByDesc('confirmed_at')->get(['id', 'confirmed_at']) : collect();
        if (! empty($filters['regularizar_supervisor'])) {
            abort_unless($data['activeType'] === 'supervisores', 422);
            $data['regularizingSupervisor'] = $this->pendingSupervisor($filters['regularizar_supervisor']);
        }

        $residentId = $request->query('residente_id', '');
        $residentMonth = $request->query('periodo_mes', '');
        $residentId = is_string($residentId) ? $residentId : '';
        $residentMonth = is_string($residentMonth) ? $residentMonth : '';
        $data['residentSelection'] = ['id' => $residentId, 'month' => $residentMonth];
        $data['residentAvailability'] = $data['activeType'] === 'residentes'
            && $residentId !== '' && $residentMonth !== ''
            ? $this->performanceService->residentAvailability($user, $residentId, $residentMonth)
            : null;

        if ($data['activeType'] === 'dashboard') {
            $dashboardFilters = ['desde' => $filters['desde'] ?? now()->startOfMonth()->toDateString(),
                'hasta' => $filters['hasta'] ?? now()->toDateString(), 'mina' => $filters['mina'] ?? '', 'cargo' => $filters['cargo'] ?? '',
                'evaluacion_tipo' => $filters['evaluacion_tipo'] ?? '', 'origen' => $filters['origen'] ?? ''];
            if ($dashboardFilters['hasta'] < $dashboardFilters['desde']) {
                throw ValidationException::withMessages(['hasta' => 'La fecha final debe ser igual o posterior a la inicial.']);
            }
            $data['dashboardFilters'] = $dashboardFilters;
            $data['dashboard'] = app(EvaluacionesDashboardService::class)->build($user, $dashboardFilters);
            if ($request->header('X-Evaluation-Dashboard') === '1') {
                return view('evaluaciones.partials.dashboard', $data);
            }
        }

        return view('evaluaciones.index', [
            ...$data,
            'user' => $user,
            'supervisorTemplate' => SupervisorEvaluationTemplate::ITEMS,
            'supervisorSectionTitles' => SupervisorEvaluationTemplate::SECTION_TITLES,
            'residentTemplate' => [
                'kpis' => ResidentEvaluationTemplate::KPI_OPTIONS,
                'costs' => ResidentEvaluationTemplate::COST_OPTIONS,
                'binary' => ResidentEvaluationTemplate::BINARY_OPTIONS,
            ],
        ]);
    }

    public function storeDaily(StoreEvaluacionDiariaRequest $request): RedirectResponse
    {
        $result = $this->performanceService->createDaily(
            $this->requireAuthenticatedUser(),
            $request->validated(),
        );

        if (($result['ok'] ?? false) !== true) {
            return back()->withInput()->withErrors(['evaluacion' => $result['message'] ?? 'No se pudo guardar la evaluación.']);
        }

        return redirect()->route('evaluaciones.index', [
            'tipo' => 'desempeno',
            'fecha' => $result['item']->fecha->toDateString(),
        ])->with('success', 'Evaluación diaria registrada correctamente.');
    }

    public function storeDirect(StoreEvaluacionDirectaRequest $request): RedirectResponse
    {
        $result = $this->performanceService->createDirect(
            $this->requireAuthenticatedUser(),
            $request->validated(),
        );

        if (($result['ok'] ?? false) !== true) {
            return back()->withInput()->withErrors(['evaluacion' => $result['message'] ?? 'No se pudo guardar la evaluación.']);
        }

        return redirect()->route('evaluaciones.index', [
            'tipo' => 'desempeno',
            'fecha' => $result['item']->fecha->toDateString(),
        ])->with('success', 'Evaluación diaria registrada correctamente.');
    }

    public function searchPersonal(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $filters = $request->validate([
            'tipo' => ['required', 'string', 'in:desempeno,supervisores,residentes'],
            'q' => ['nullable', 'string', 'max:191'],
            'id' => ['nullable', 'string', 'size:36'],
            'mina_id' => ['nullable', 'string', 'size:36', 'exists:minas,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $this->pageService->searchPersonal(
                $user,
                $filters['tipo'],
                (string) ($filters['q'] ?? ''),
                $filters['id'] ?? null,
                $filters['mina_id'] ?? null,
                (int) ($filters['limit'] ?? 12),
            ),
        ]);
    }

    public function storeSupervisor(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'mina_id' => ['required', 'string', 'size:36', 'exists:minas,id'],
            'evaluado_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'fecha' => ['required', 'date'],
            ...SupervisorEvaluationTemplate::responseRules(),
            'comentarios_finales' => ['nullable', 'string', 'max:3000'],
            'aspectos_positivos' => ['nullable', 'string', 'max:3000'],
            'capacitaciones_recomendadas' => ['nullable', 'string', 'max:3000'],
        ], SupervisorEvaluationTemplate::responseMessages());
        $user = $this->requireAuthenticatedUser();

        $result = $this->supervisorService->create($user, [
            ...$payload,
            'evaluador_id' => $user->personal_id,
            'destino_tipo' => 'MINA',
            'destino_id' => $payload['mina_id'],
        ]);

        return $this->resultRedirect($result, 'supervisores', 'Evaluación de supervisor registrada correctamente.');
    }

    public function storeResident(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'residente_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'periodo_mes' => ['required', 'date_format:Y-m'],
            'indicadores_kpi_items' => ['required', 'array', 'min:1'],
            'indicadores_kpi_items.*' => ['required', 'string', 'in:'.implode(',', array_keys(ResidentEvaluationTemplate::KPI_OPTIONS))],
            'costos_servicio_items' => ['required', 'array', 'min:1'],
            'costos_servicio_items.*' => ['required', 'string', 'in:'.implode(',', array_keys(ResidentEvaluationTemplate::COST_OPTIONS))],
            'eventos_seguridad_respuesta' => ['required', 'string', 'in:SI,NO'],
            'reportes_calidad_respuesta' => ['required', 'string', 'in:SI,NO'],
            'liderazgo_gestion_innovacion' => ['required', 'integer', 'between:1,4'],
            'comentarios' => ['required', 'string', 'max:3000'],
        ]);
        $user = $this->requireAuthenticatedUser();

        $result = $this->performanceService->createResidente($user, [
            ...$payload,
            'fecha' => $payload['periodo_mes'].'-01',
            'evaluador_id' => $user->personal_id,
        ]);

        return $this->resultRedirect($result, 'residentes', 'Evaluación mensual de residente registrada correctamente.');
    }

    public function regularizeSupervisor(UpdateEvaluacionSupervisorRequest $request, string $id): RedirectResponse
    {
        $item = $this->pendingSupervisor($id);

        return $this->resultRedirect($this->supervisorService->update($this->requireAuthenticatedUser(), $item, $request->validated()),
            'supervisores', 'Evaluación regularizada correctamente. Ya participa en los indicadores.');
    }

    public function followUpSupervisor(Request $request, string $id): RedirectResponse
    {
        $item = $this->pendingSupervisor($id);
        $payload = $request->validate(['motivo' => ['required', 'string', 'max:3000']], [
            'motivo.required' => 'Indica por qué sigue pendiente la regularización.',
        ]);

        return $this->resultRedirect($this->supervisorService->recordHistoricalFollowUp($this->requireAuthenticatedUser(), $item, $payload['motivo']),
            'supervisores', 'Seguimiento guardado. La evaluación sigue pendiente y fuera de indicadores.');
    }

    private function pendingSupervisor(string $id): EvaluacionSupervisor
    {
        $user = $this->requireAuthenticatedUser();
        abort_unless($this->supervisorService->canRegularize($user), 403, 'No tienes permiso para regularizar evaluaciones históricas.');
        $item = $this->supervisorService->find($user, $id);
        abort_unless($item, 404);
        abort_unless($item->isHistorical() && $item->estado === EvaluacionSupervisor::PENDING_REGULARIZATION,
            409, 'Esta evaluación ya no está pendiente de regularización.');

        return $item;
    }

    public function show(string $id): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno', 'evaluacion' => $id]);
    }

    public function create(Request $request): RedirectResponse
    {
        if ($request->filled('asistencia_detalle_id')) {
            $detail = $this->validatedDailyDetail($request);

            return redirect()->route('evaluaciones.index', [
                'tipo' => 'desempeno',
                'fecha' => $detail->asistencia->fecha->toDateString(),
                'asistencia_detalle_id' => $detail->id,
            ]);
        }

        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno']);
    }

    private function validatedDailyDetail(Request $request): AsistenciaDetalle
    {
        $validated = $request->validate([
            'asistencia_detalle_id' => ['required', 'string', 'size:36'],
        ]);
        $detail = AsistenciaDetalle::query()
            ->with(['asistencia', 'trabajador'])
            ->find($validated['asistencia_detalle_id']);
        $availability = $this->performanceService->dailyAvailability($this->requireAuthenticatedUser(), $detail);

        if (! $availability['ok']) {
            $code = $availability['code'] ?? '';
            $status = in_array($code, ['EVAL_FORBIDDEN', 'EVAL_ATTENDANCE_OWNER_REQUIRED'], true)
                ? 403 : ($code === 'EVAL_DUPLICATED' ? 409 : 422);
            abort($status, $availability['message'] ?? 'No se puede abrir esta evaluación.');
        }

        return $detail;
    }

    public function edit(string $id): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno', 'evaluacion' => $id]);
    }

    public function promedios(): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno']);
    }

    public function comparacion(): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno']);
    }

    public function update(UpdateEvaluacionDesempenoRequest $request, string $id): RedirectResponse
    {
        $result = $this->performanceService->updateForUser(
            $this->requireAuthenticatedUser(),
            $id,
            $request->validated(),
        );

        return $this->resultRedirect($result, 'desempeno', 'Evaluación actualizada correctamente.');
    }

    private function typeFromRoute(Request $request): string
    {
        return match ($request->route()?->getName()) {
            'evaluaciones.supervisor' => 'supervisores',
            'evaluaciones.residentes' => 'residentes',
            default => 'desempeno',
        };
    }

    private function resultRedirect(array $result, string $type, string $message): RedirectResponse
    {
        if (($result['ok'] ?? false) !== true) {
            return back()->withInput()->withErrors([
                'evaluacion' => $result['message'] ?? 'No se pudo guardar la evaluación.',
            ]);
        }

        return redirect()->route('evaluaciones.index', ['tipo' => $type])->with('success', $message);
    }
}
