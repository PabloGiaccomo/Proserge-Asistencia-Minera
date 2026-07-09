<?php

namespace App\Modules\Logistica\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Epps\Services\EppService;
use App\Modules\Logistica\Services\LogisticaDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LogisticaPageController extends WebPageController
{
    public function __construct(
        private readonly LogisticaDashboardService $service,
        private readonly EppService $eppService
    )
    {
    }

    public function index(Request $request): View
    {
        $this->requireAuthenticatedUser();

        $data = $this->service->pageData($request->query());
        $data['eppModule'] = $this->eppService->pageData([
            'q' => $request->query('q'),
            'estado' => $request->query('estado'),
            'per_page' => $request->query('per_page'),
        ]);

        return view('logistica.index', $data);
    }

    public function updateTransport(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $this->service->updateTransportRequirement($id, $request->validate([
            'origen' => ['nullable', 'string', 'in:EMPRESA,ALQUILADO,OTRO'],
            'placas_asignadas' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'estado_logistico' => ['required', 'string', 'in:REQUERIDO,ASIGNADO,EN_USO,RETIRADO,REEMPLAZADO,DEVUELTO,INCIDENCIA'],
            'comentario_cambio' => ['nullable', 'string', 'max:2000'],
            'incidencia_operativa' => ['nullable', 'string', 'max:2000'],
            'recepcion_fecha' => ['nullable', 'date'],
            'recepcion_estado' => ['required', 'string', 'in:PENDIENTE,RECIBIDO,INCOMPLETO,NO_LLEGO,CON_OBSERVACION'],
            'recepcion_observacion' => ['nullable', 'string', 'max:2000'],
        ]), $usuario);

        return redirect()
            ->route('logistica.index', ['tab' => 'servicios'])
            ->with('success', 'Requerimiento de transporte actualizado.');
    }
}
