<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalContrato;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $filters = [
            'mes' => $request->input('mes', now()->month),
            'anio' => $request->input('anio', now()->year),
            'area' => $request->input('area', ''),
            'cargo' => $request->input('cargo', ''),
            'estado_decision' => $request->input('estado_decision', ''),
            'estado_laboral' => $request->input('estado_laboral', ''),
            'estado_contractual' => $request->input('estado_contractual', PersonalContrato::ESTADO_ACTIVO),
        ];

        return view('personal.contratos.vencimientos', [
            'filters' => $filters,
            'contratos' => $this->contratoService->listExpiringContracts($filters),
            'decisionOptions' => $this->contratoService->decisionStateOptions(),
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
        ]);
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
