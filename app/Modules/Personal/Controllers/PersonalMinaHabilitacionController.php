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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalMinaHabilitacionController extends WebPageController
{
    public function __construct(
        private readonly PersonalMinaHabilitacionService $service,
        private readonly PersonalMinaExcelImportService $excelImport,
    )
    {
    }

    public function index(Request $request): View
    {
        $filters = [
            'mina_id' => $request->input('mina_id', ''),
            'trabajador' => $request->input('trabajador', ''),
            'worker_id' => $request->input('worker_id', ''),
            'estado_habilitacion' => $request->input('estado_habilitacion', ''),
            'estado_laboral' => $request->input('estado_laboral', ''),
            'per_page' => $request->input('per_page', 15),
            'worker_limit' => (int) $request->input('worker_limit', 20),
        ];
        $filters['per_page'] = $this->service->perPageFromFilters($filters);
        $allowedWorkerLimits = [10, 20, 50, 80, 200];
        $filters['worker_limit'] = in_array($filters['worker_limit'], $allowedWorkerLimits, true) ? $filters['worker_limit'] : 20;
        $selectedWorker = $this->service->findWorker($filters['worker_id']);

        return view('personal.habilitacion-minera.index', [
            'filters' => $filters,
            'assignments' => $this->service->listGroupedByWorker($filters),
            'requirements' => $this->service->listRequirements($filters['mina_id'] ?: null),
            'exams' => $this->service->listMiningExams(),
            'allExams' => $this->service->listAllMiningExams(),
            'priceHistory' => $this->service->listPriceHistory(),
            'mines' => $this->service->activeMines(),
            'workers' => $this->service->workerOptions($filters['trabajador'] ?: null, $filters['worker_limit']),
            'selectedWorker' => $selectedWorker,
            'stateOptions' => $this->service->habilitationStateOptions(),
            'examStateOptions' => $this->service->examStateOptions(),
            'attemptResultOptions' => $this->service->attemptResultOptions(),
            'importPreview' => session('habilitacion_mina_import_preview'),
            'service' => $this->service,
        ]);
    }

    public function storeExam(Request $request): RedirectResponse
    {
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
            $this->service->storeRequirement($validated);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar el requisito.');
        }

        return redirect()
            ->route('personal.habilitacion-minera.index', ['mina_id' => $validated['mina_id']])
            ->with('success', 'Requisito registrado correctamente.');
    }

    public function assign(Request $request): RedirectResponse
    {
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

    public function deactivateRequirement(Request $request, string $requirementId): RedirectResponse
    {
        $requirement = MinaRequisito::query()->find($requirementId);
        abort_if(!$requirement, 404);

        $this->service->deactivateRequirement($requirement);

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Examen quitado de la mina correctamente.');
    }

    public function generateExams(Request $request, string $assignmentId): RedirectResponse
    {
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

    public function notApplicable(Request $request, string $workerExamId): RedirectResponse
    {
        $workerExam = PersonalMinaExamen::query()->find($workerExamId);
        abort_if(!$workerExam, 404);

        $validated = $request->validate([
            'observacion' => ['required', 'string', 'max:5000'],
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

    public function convalidate(Request $request, string $workerExamId): RedirectResponse
    {
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
        $validated = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        try {
            $preview = $this->excelImport->preview($validated['archivo']);
        } catch (\Throwable $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'No se pudo analizar el Excel: ' . $exception->getMessage());
        }

        session(['habilitacion_mina_import_preview' => $preview]);

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Excel analizado. Revisa la vista previa antes de confirmar.');
    }

    public function confirmImport(Request $request): RedirectResponse
    {
        $preview = session('habilitacion_mina_import_preview');
        if (!$preview || ($request->input('token') && $request->input('token') !== ($preview['token'] ?? null))) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'No hay una vista previa de importacion vigente.');
        }

        try {
            $result = $this->excelImport->confirm($preview, $this->requireAuthenticatedUser());
        } catch (\Throwable $exception) {
            return redirect()
                ->route('personal.habilitacion-minera.index', $request->query())
                ->with('error', 'No se pudo confirmar la importacion: ' . $exception->getMessage());
        }

        session()->forget('habilitacion_mina_import_preview');

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Importacion confirmada: ' . collect($result)->map(fn ($value, $key) => $key . ': ' . $value)->implode(', '));
    }

    public function syncCurrent(Request $request): RedirectResponse
    {
        $result = $this->service->syncCurrentInformation($this->requireAuthenticatedUser());

        return redirect()
            ->route('personal.habilitacion-minera.index', $request->query())
            ->with('success', 'Estados recalculados: ' . collect($result)->map(fn ($value, $key) => str_replace('_', ' ', $key) . ': ' . $value)->implode(', '));
    }
}
