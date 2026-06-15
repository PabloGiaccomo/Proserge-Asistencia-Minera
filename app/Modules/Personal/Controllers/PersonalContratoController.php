<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalContrato;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalContratoController extends WebPageController
{
    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalContratoService $contratoService,
    ) {
    }

    public function expiring(Request $request): View
    {
        $rawMonth = $request->input('mes');
        $rawYear = $request->input('anio');
        $month = is_numeric($rawMonth) ? max(1, min(12, (int) $rawMonth)) : now()->month;
        $year = is_numeric($rawYear) ? max(2000, min(2100, (int) $rawYear)) : now()->year;

        $filters = [
            'mes' => $month,
            'anio' => $year,
            'trabajador' => $request->input('trabajador', ''),
            'cargo' => $request->input('cargo', ''),
            'estado_laboral' => $request->input('estado_laboral', ''),
            'tipo_contrato' => $request->input('tipo_contrato', ''),
        ];

        return view('personal.contratos.vencimientos', [
            'filters' => $filters,
            'contratos' => $this->contratoService->listExpiringContracts($filters),
            'decisionOptions' => $this->contratoService->decisionStateOptions(),
            'contractTypeOptions' => $this->contratoService->contractTypeOptions(),
            'reasonOptions' => $this->contratoService->noRenewalReasonOptions(),
            'cessationReasonOptions' => $this->contratoService->controlledCeaseReasonOptions(),
        ]);
    }

    public function index(string $id): View
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        return view('personal.contratos.index', [
            'personal' => $personal,
            'trabajador' => PersonalResource::make($personal)->resolve(),
            'contratos' => $this->contratoService->listForPersonal($personal, $this->requireAuthenticatedUser()),
            'contratoService' => $this->contratoService,
        ]);
    }

    public function show(string $id, string $contractId): View
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $contrato = $this->contratoService->findForPersonal($personal, $contractId);
        abort_if(!$contrato, 404);

        return view('personal.contratos.show', [
            'personal' => $personal,
            'trabajador' => PersonalResource::make($personal)->resolve(),
            'contrato' => $contrato,
            'snapshot' => $contrato->snapshot_json ?: ($contrato->snapshot_inicial_json ?: []),
            'contratoService' => $this->contratoService,
            'signedContractFileExists' => $contrato->hasSignedFile()
                && Storage::disk('local')->exists($contrato->signed_contract_path),
        ]);
    }

    public function downloadSignedContract(string $id, string $contractId): Response
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $contract = $this->contratoService->findForPersonal($personal, $contractId);
        abort_if(!$contract, 404);
        abort_unless($contract->hasSignedFile() && Storage::disk('local')->exists($contract->signed_contract_path), 404);

        $filename = $contract->signed_contract_original_name
            ?: 'contrato_firmado_' . $contract->contrato_numero . '.pdf';

        return response(Storage::disk('local')->get($contract->signed_contract_path), 200, [
            'Content-Type' => $contract->signed_contract_mime ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $filename) . '"',
        ]);
    }

    public function uploadSignedContract(Request $request, string $id, string $contractId): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $contract = $this->contratoService->findForPersonal($personal, $contractId);
        abort_if(!$contract, 404);

        $validated = $request->validate([
            'contrato_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ], [
            'contrato_pdf.required' => 'Sube el contrato firmado en PDF.',
            'contrato_pdf.mimes' => 'El contrato firmado debe ser PDF.',
            'contrato_pdf.max' => 'El PDF no debe superar 20 MB.',
        ]);

        try {
            $this->contratoService->uploadSignedFileForContract(
                $personal,
                $contract,
                $validated['contrato_pdf'],
                $this->requireAuthenticatedUser(),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.index', $personal->id)
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo subir el contrato firmado.');
        }

        return redirect()
            ->route('personal.contratos.index', $personal->id)
            ->with('success', 'Contrato firmado subido correctamente.');
    }

    public function renew(Request $request, string $id): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate([
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observacion_renovacion' => ['nullable', 'string', 'max:5000'],
        ], [
            'fecha_inicio.required' => 'La fecha de inicio del nuevo contrato es obligatoria.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ]);

        try {
            $this->contratoService->prepareRenewal($personal, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.index', $personal->id)
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo preparar la renovacion.');
        }

        return redirect()
            ->route('personal.contrato-datos.edit', $personal->id)
            ->with('success', 'Renovacion creada en preparacion. El trabajador conserva su estado si tiene contrato vigente firmado.');
    }

    public function reentry(Request $request, string $id): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate([
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observacion_renovacion' => ['nullable', 'string', 'max:5000'],
        ], [
            'fecha_inicio.required' => 'La fecha de inicio del reingreso es obligatoria.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ]);

        try {
            $this->contratoService->prepareReentry($personal, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.index', $personal->id)
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo preparar el reingreso.');
        }

        return redirect()
            ->route('personal.contrato-datos.edit', $personal->id)
            ->with('success', 'Reingreso creado en preparacion. El trabajador no quedara activo hasta subir el contrato firmado.');
    }

    public function decision(Request $request, string $contractId): RedirectResponse
    {
        $contract = PersonalContrato::query()->find($contractId);
        abort_if(!$contract, 404);

        $validated = $request->validate([
            'estado_decision_renovacion' => ['required', 'string', 'max:40'],
            'motivo_no_renovacion' => ['nullable', 'string', 'max:80'],
            'observacion_decision' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->contratoService->registerRenewalDecision($contract, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.expiring', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar la decision.');
        }

        return redirect()
            ->route('personal.contratos.expiring', $request->query())
            ->with('success', 'Decision registrada correctamente.');
    }

    public function bulkDecision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_ids' => ['required', 'array', 'min:1'],
            'contract_ids.*' => ['required', 'string'],
            'estado_decision_renovacion' => ['required', 'string', 'max:40'],
            'motivo_no_renovacion' => ['nullable', 'string', 'max:80'],
            'observacion_decision' => ['nullable', 'string', 'max:5000'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observacion_renovacion' => ['nullable', 'string', 'max:5000'],
        ], [
            'contract_ids.required' => 'Selecciona al menos un contrato.',
            'contract_ids.min' => 'Selecciona al menos un contrato.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ]);

        try {
            $summary = $this->contratoService->registerBulkRenewalDecision(
                $validated['contract_ids'],
                $validated,
                $this->requireAuthenticatedUser(),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: 'No se pudo registrar la decision.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => $summary['renovaciones'] > 0
                ? 'Decision registrada y renovacion preparada correctamente.'
                : 'Decision registrada correctamente.',
            'summary' => $summary,
        ]);
    }

    public function prepareFromDecision(Request $request, string $contractId): RedirectResponse
    {
        $contract = PersonalContrato::query()->find($contractId);
        abort_if(!$contract, 404);

        $validated = $request->validate([
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'observacion_renovacion' => ['nullable', 'string', 'max:5000'],
        ], [
            'fecha_inicio.required' => 'La fecha de inicio del nuevo contrato es obligatoria.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ]);

        try {
            $renewal = $this->contratoService->prepareRenewalFromDecision($contract, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.expiring', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo preparar la renovacion.');
        }

        return redirect()
            ->route('personal.contrato-datos.edit', $renewal->personal_id)
            ->with('success', 'Renovacion preparada desde la decision registrada.');
    }

    public function closeNotRenewed(Request $request, string $contractId): RedirectResponse
    {
        $contract = PersonalContrato::query()->find($contractId);
        abort_if(!$contract, 404);

        $validated = $request->validate([
            'fecha_cese' => ['nullable', 'date'],
            'motivo_cese_controlado' => ['required', 'string', 'max:80'],
            'observacion_cese_controlado' => ['nullable', 'string', 'max:5000'],
            'observacion_cierre_no_renovacion' => ['nullable', 'string', 'max:5000'],
            'confirmar_cierre_anticipado' => ['sometimes', 'accepted'],
        ], [
            'motivo_cese_controlado.required' => 'El motivo de cese es obligatorio.',
            'confirmar_cierre_anticipado.accepted' => 'El contrato aun no vence. Confirme si desea cerrar anticipadamente.',
        ]);

        try {
            $closed = $this->contratoService->closeAsNotRenewed($contract, $validated, $this->requireAuthenticatedUser());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.expiring', $request->query())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo cerrar el contrato.');
        }

        $workerState = strtoupper((string) $closed->personal?->estado);
        $message = $workerState === 'CESADO'
            ? 'Contrato cerrado como no renovado y trabajador cesado.'
            : 'Contrato cerrado como no renovado. El trabajador conserva estado activo por otro contrato vigente firmado.';

        return redirect()
            ->route('personal.contratos.expiring', $request->query())
            ->with('success', $message);
    }

    public function destroy(Request $request, string $id, string $contractId): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate([
            'motivo_anulacion' => ['required', 'string', 'max:2000'],
        ], [
            'motivo_anulacion.required' => 'El motivo de anulacion es obligatorio.',
        ]);

        try {
            $this->contratoService->annulContract(
                $personal,
                $contractId,
                $validated['motivo_anulacion'],
                $this->requireAuthenticatedUser(),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.contratos.index', $personal->id)
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo anular el contrato.');
        }

        return redirect()
            ->route('personal.contratos.index', $personal->id)
            ->with('success', 'Contrato anulado correctamente. El registro se conserva en el historial.');
    }
}
