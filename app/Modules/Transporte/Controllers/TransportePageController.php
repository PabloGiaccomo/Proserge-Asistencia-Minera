<?php

namespace App\Modules\Transporte\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\TransporteServicio;
use App\Modules\Transporte\Requests\ChangeTransporteEstadoRequest;
use App\Modules\Transporte\Requests\CopyTransporteServicioRequest;
use App\Modules\Transporte\Requests\RetireTransportePasajeroRequest;
use App\Modules\Transporte\Requests\StoreTransportePasajeroRequest;
use App\Modules\Transporte\Requests\StoreTransporteServicioRequest;
use App\Modules\Transporte\Requests\UpdateTransporteServicioRequest;
use App\Modules\Transporte\Services\TransportePlanningService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransportePageController extends WebPageController
{
    public function __construct(private readonly TransportePlanningService $service)
    {
    }

    public function index(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();

        $filters = [
            'rq_mina_id' => $request->query('rq_mina_id'),
            'rq_mina_plan_id' => $request->query('rq_mina_plan_id'),
            'fecha' => $request->query('fecha'),
            'turno' => $request->query('turno'),
        ];

        return view('transporte.planificacion', $this->service->buildContext($usuario, $filters));
    }

    public function store(StoreTransporteServicioRequest $request): RedirectResponse
    {
        $result = $this->service->createServicio($this->requireAuthenticatedUser(), $request->validated());

        return $this->redirectResult($result, 'Servicio de transporte creado.');
    }

    public function update(UpdateTransporteServicioRequest $request, string $servicio): RedirectResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->updateServicio($this->requireAuthenticatedUser(), $model, $request->validated());

        return $this->redirectResult($result, 'Servicio de transporte actualizado.');
    }

    public function pasajeros(StoreTransportePasajeroRequest $request, string $servicio): RedirectResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->addPasajeros($this->requireAuthenticatedUser(), $model, $request->validated());

        return $this->redirectResult($result, 'Pasajeros asignados.');
    }

    public function retirarPasajero(RetireTransportePasajeroRequest $request, string $servicio, string $pasajero): RedirectResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->retirePasajero($this->requireAuthenticatedUser(), $model, $pasajero, $request->validated('motivo'));

        return $this->redirectResult($result, 'Pasajero retirado.');
    }

    public function copiar(CopyTransporteServicioRequest $request, string $servicio): RedirectResponse
    {
        $model = TransporteServicio::query()->with('alcances')->findOrFail($servicio);
        $result = $this->service->copyServicio($this->requireAuthenticatedUser(), $model, $request->validated());

        return $this->redirectResult($result, 'Servicio copiado en borrador.');
    }

    public function estado(ChangeTransporteEstadoRequest $request, string $servicio): RedirectResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $payload = $request->validated();
        $result = $this->service->changeEstado($this->requireAuthenticatedUser(), $model, $payload['estado'], $payload['observacion'] ?? null);

        return $this->redirectResult($result, 'Estado actualizado.');
    }

    private function redirectResult(array $result, string $success): RedirectResponse
    {
        if (($result['ok'] ?? false) === false) {
            return back()
                ->withInput()
                ->with('error', (string) ($result['message'] ?? 'No se pudo completar la accion.'));
        }

        return back()->with('success', $success);
    }
}
