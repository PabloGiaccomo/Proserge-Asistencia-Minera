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
use App\Modules\Transporte\Support\TransportePlanningNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
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

        $context = $this->service->buildContext($usuario, $filters);
        $context['lista'] = TransportePlanningNavigation::listFilters($request->query('lista'));
        $editId = $request->query('editar');
        $context['editService'] = is_string($editId)
            ? collect($context['servicios'])->first(fn (array $service): bool => $service['id'] === $editId && $service['capabilities']['editar'])
            : null;

        return view('transporte.planificacion', $context);
    }

    public function store(StoreTransporteServicioRequest $request): RedirectResponse|JsonResponse
    {
        $result = $this->service->createServicio($this->requireAuthenticatedUser(), $request->validated());

        return $this->respond($request, $result, 'Servicio de transporte creado.');
    }

    public function update(UpdateTransporteServicioRequest $request, string $servicio): RedirectResponse|JsonResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->updateServicio($this->requireAuthenticatedUser(), $model, $request->validated());

        return $this->respond($request, $result, 'Servicio de transporte actualizado.', $model);
    }

    public function pasajeros(StoreTransportePasajeroRequest $request, string $servicio): RedirectResponse|JsonResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->addPasajeros($this->requireAuthenticatedUser(), $model, $request->validated());

        return $this->respond($request, $result, 'Pasajeros asignados.', $model);
    }

    public function retirarPasajero(RetireTransportePasajeroRequest $request, string $servicio, string $pasajero): RedirectResponse|JsonResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->retirePasajero($this->requireAuthenticatedUser(), $model, $pasajero, $request->validated('motivo'));

        return $this->respond($request, $result, 'Pasajero retirado.', $model);
    }

    public function copiar(CopyTransporteServicioRequest $request, string $servicio): RedirectResponse|JsonResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $result = $this->service->copyServicio($this->requireAuthenticatedUser(), $model, $request->validated());

        return $this->respond($request, $result, 'Servicio copiado en borrador.', $model);
    }

    public function estado(ChangeTransporteEstadoRequest $request, string $servicio): RedirectResponse|JsonResponse
    {
        $model = TransporteServicio::query()->findOrFail($servicio);
        $payload = $request->validated();
        $result = $this->service->changeEstado($this->requireAuthenticatedUser(), $model, $payload['estado'], $payload['observacion'] ?? null);

        return $this->respond($request, $result, 'Estado actualizado.', $model);
    }

    private function respond(Request $request, array $result, string $success, ?TransporteServicio $service = null): RedirectResponse|JsonResponse
    {
        $failed = !$result['ok'];
        $message = $result['message'] ?? 'No se pudo completar la acción.';
        $forbidden = $result['forbidden'] ?? false;
        if ($request->expectsJson()) {
            if ($failed) {
                return response()->json(['ok' => false, 'message' => $message], $forbidden ? 403 : 422);
            }
            $request->session()->flash('success', $success);

            return response()->json([
                'ok' => true,
                'redirect' => TransportePlanningNavigation::url($request, service: $service, absolute: false),
            ]);
        }

        abort_if($forbidden, 403, 'No autorizado');
        $redirect = redirect()->to(TransportePlanningNavigation::url($request, $failed, $service));

        return $failed ? $redirect->withInput()->with('error', $message) : $redirect->with('success', $success);
    }
}
