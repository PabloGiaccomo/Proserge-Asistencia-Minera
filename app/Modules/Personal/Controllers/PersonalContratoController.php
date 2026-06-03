<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PersonalContratoController extends WebPageController
{
    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalContratoService $contratoService,
    ) {
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

    public function destroy(string $id, string $contractId): RedirectResponse
    {
        $personal = $this->personalService->find($id);
        abort_if(!$personal, 404);

        $deleted = $this->contratoService->deleteHistoricalContract($personal, $contractId);

        return redirect()
            ->route('personal.contratos.index', $personal->id)
            ->with($deleted ? 'success' : 'error', $deleted
                ? 'Contrato anterior eliminado correctamente.'
                : 'Solo se pueden eliminar contratos anteriores cerrados.');
    }
}
