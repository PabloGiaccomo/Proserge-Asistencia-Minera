<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionDiariaRequest;
use App\Modules\Evaluaciones\Requests\UpdateEvaluacionDesempenoRequest;
use App\Modules\Evaluaciones\Services\EvaluacionDesempenoService;
use App\Modules\Evaluaciones\Services\EvaluacionesPageService;
use App\Modules\Evaluaciones\Services\EvaluacionSupervisorService;
use App\Modules\Evaluaciones\Support\ResidentEvaluationTemplate;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EvaluacionDesempenoPageController extends WebPageController
{
    public function __construct(
        private readonly EvaluacionesPageService $pageService,
        private readonly EvaluacionDesempenoService $performanceService,
        private readonly EvaluacionSupervisorService $supervisorService,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $this->requireAuthenticatedUser();
        $filters = $request->validate([
            'tipo' => ['nullable', 'string', 'in:desempeno,supervisores,residentes'],
            'fecha' => ['nullable', 'date'],
        ]);
        $type = $filters['tipo'] ?? $this->typeFromRoute($request);
        $date = $filters['fecha'] ?? now()->toDateString();
        $data = $this->pageService->build($user, $type, $date);

        abort_if($data['availableTypes']->isEmpty(), 403, 'No tienes acceso a evaluaciones.');

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

        return $this->resultRedirect($result, 'desempeno', 'Evaluación diaria registrada correctamente.');
    }

    public function searchPersonal(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
            'id' => ['nullable', 'string', 'size:36'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $this->pageService->searchPersonal(
                (string) ($filters['q'] ?? ''),
                $filters['id'] ?? null,
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
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['required', 'integer', 'between:1,5'],
            'comentarios_finales' => ['nullable', 'string', 'max:3000'],
            'aspectos_positivos' => ['nullable', 'string', 'max:3000'],
            'capacitaciones_recomendadas' => ['nullable', 'string', 'max:3000'],
        ]);
        $user = $this->requireAuthenticatedUser();

        if (!$user->personal_id) {
            return back()->withInput()->withErrors(['evaluacion' => 'Tu cuenta debe estar vinculada a Personal.']);
        }

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

        if (!$user->personal_id) {
            return back()->withInput()->withErrors(['evaluacion' => 'Tu cuenta debe estar vinculada a Personal.']);
        }

        $result = $this->performanceService->createResidente($user, [
            ...$payload,
            'fecha' => $payload['periodo_mes'].'-01',
            'evaluador_id' => $user->personal_id,
        ]);

        return $this->resultRedirect($result, 'residentes', 'Evaluación mensual de residente registrada correctamente.');
    }

    public function show(string $id): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno', 'evaluacion' => $id]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('evaluaciones.index', ['tipo' => 'desempeno']);
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
