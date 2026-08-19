<?php

namespace App\Modules\Transporte\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TransporteServicio;
use App\Models\Usuario;
use App\Modules\Transporte\Requests\ChangeTransporteEstadoRequest;
use App\Modules\Transporte\Requests\CopyTransporteServicioRequest;
use App\Modules\Transporte\Requests\RetireTransportePasajeroRequest;
use App\Modules\Transporte\Requests\ReubicarTransportePasajeroRequest;
use App\Modules\Transporte\Requests\StoreTransporteAlcanceRequest;
use App\Modules\Transporte\Requests\StoreTransportePasajeroRequest;
use App\Modules\Transporte\Requests\StoreTransporteServicioRequest;
use App\Modules\Transporte\Requests\UpdateTransporteServicioRequest;
use App\Modules\Transporte\Services\TransportePlanningService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class TransporteController extends Controller
{
    public function __construct(private readonly TransportePlanningService $service)
    {
    }

    public function planificacion(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'rq_mina_id' => ['nullable', 'string', 'size:36'],
            'rq_mina_plan_id' => ['nullable', 'string', 'size:36'],
            'fecha' => ['nullable', 'date'],
            'turno' => ['nullable', 'string', 'in:A,B,DIA,NOCHE'],
        ]);

        return ApiResponse::success(
            data: $this->service->buildContext($usuario, $filters),
            message: 'Planificacion de transporte obtenida',
            code: 'TRANSPORTE_CONTEXT_OK',
        );
    }

    public function store(StoreTransporteServicioRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->createServicio($usuario, $request->validated());

        return $this->apiResult($result, 'TRANSPORTE_CREATE_OK', 'Servicio de transporte creado', 201);
    }

    public function update(UpdateTransporteServicioRequest $request, string $servicio)
    {
        $model = TransporteServicio::query()->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->updateServicio($usuario, $model, $request->validated());

        return $this->apiResult($result, 'TRANSPORTE_UPDATE_OK', 'Servicio de transporte actualizado');
    }

    public function alcances(StoreTransporteAlcanceRequest $request, string $servicio)
    {
        $model = TransporteServicio::query()->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $this->service->syncAlcances($usuario, $model, $request->validated('alcances'));

        return ApiResponse::success(
            data: $this->service->serializeServicio($model),
            message: 'Alcances actualizados',
            code: 'TRANSPORTE_SCOPES_OK',
        );
    }

    public function pasajeros(StoreTransportePasajeroRequest $request, string $servicio)
    {
        $model = TransporteServicio::query()->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->addPasajeros($usuario, $model, $request->validated());

        return $this->apiResult($result, 'TRANSPORTE_PASSENGERS_OK', 'Pasajeros asignados');
    }

    public function retirarPasajero(RetireTransportePasajeroRequest $request, string $servicio, string $pasajero)
    {
        $model = TransporteServicio::query()->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->retirePasajero($usuario, $model, $pasajero, $request->validated('motivo'));

        return $this->apiResult($result, 'TRANSPORTE_PASSENGER_RETIRE_OK', 'Pasajero retirado');
    }

    public function reubicarPasajero(ReubicarTransportePasajeroRequest $request, string $servicio, string $pasajero)
    {
        $model = TransporteServicio::query()->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $payload = $request->validated();
        $result = $this->service->reubicarPasajero($usuario, $model, $pasajero, $payload['destino_servicio_id'], $payload['motivo']);

        return $this->apiResult($result, 'TRANSPORTE_PASSENGER_RELOCATE_OK', 'Pasajero reubicado');
    }

    public function copiar(CopyTransporteServicioRequest $request, string $servicio)
    {
        $model = TransporteServicio::query()->with('alcances')->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->copyServicio($usuario, $model, $request->validated());

        return $this->apiResult($result, 'TRANSPORTE_COPY_OK', 'Servicio copiado', 201);
    }

    public function estado(ChangeTransporteEstadoRequest $request, string $servicio)
    {
        $model = TransporteServicio::query()->find($servicio);
        if (!$model) {
            return ApiResponse::error('Servicio no encontrado', 'TRANSPORTE_NOT_FOUND', status: 404);
        }

        /** @var Usuario $usuario */
        $usuario = $request->user();
        $payload = $request->validated();
        $result = $this->service->changeEstado($usuario, $model, $payload['estado'], $payload['observacion'] ?? null);

        return $this->apiResult($result, 'TRANSPORTE_STATE_OK', 'Estado actualizado');
    }

    private function apiResult(array $result, string $code, string $message, int $successStatus = 200)
    {
        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo completar la accion'),
                code: (string) ($result['code'] ?? 'TRANSPORTE_ERROR'),
                detail: (array) ($result['detail'] ?? []),
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: $this->service->serializeServicio($result['servicio']),
            message: $message,
            code: $code,
            status: $successStatus,
        );
    }
}
