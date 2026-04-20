<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionSupervisorRequest;
use App\Modules\Evaluaciones\Requests\UpdateEvaluacionSupervisorRequest;
use App\Modules\Evaluaciones\Resources\EvaluacionSupervisorResource;
use App\Modules\Evaluaciones\Services\EvaluacionSupervisorService;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class EvaluacionSupervisorController extends Controller
{
    public function __construct(private readonly EvaluacionSupervisorService $service)
    {
    }

    public function index(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'evaluado_id' => ['nullable', 'string', 'size:36'],
            'grupo_trabajo_id' => ['nullable', 'string', 'size:36'],
        ]);

        $items = $this->service->list($usuario, $filters);

        if ($items === null) {
            return ApiResponse::error('No autorizado para consultar evaluaciones de supervisor', 'EVAL_FORBIDDEN', status: 403);
        }

        return ApiResponse::success(
            data: EvaluacionSupervisorResource::collection($items)->resolve(),
            message: 'Evaluaciones de supervisor obtenidas',
            code: 'EVAL_SUPERVISOR_LIST_OK',
        );
    }

    public function show(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $item = $this->service->find($usuario, $id);

        if (!$item) {
            return ApiResponse::error('Evaluacion de supervisor no encontrada o sin acceso', 'EVAL_SUPERVISOR_NOT_FOUND', status: 404);
        }

        return ApiResponse::success(
            data: EvaluacionSupervisorResource::make($item)->resolve(),
            message: 'Evaluacion de supervisor obtenida',
            code: 'EVAL_SUPERVISOR_SHOW_OK',
        );
    }

    public function store(StoreEvaluacionSupervisorRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $result = $this->service->create($usuario, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                (string) $result['message'],
                (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: EvaluacionSupervisorResource::make($result['item'])->resolve(),
            message: 'Evaluacion de supervisor creada',
            code: 'EVAL_SUPERVISOR_CREATE_OK',
            status: 201,
        );
    }

    public function update(UpdateEvaluacionSupervisorRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $item = $this->service->find($usuario, $id);

        if (!$item) {
            return ApiResponse::error('Evaluacion de supervisor no encontrada o sin acceso', 'EVAL_SUPERVISOR_NOT_FOUND', status: 404);
        }

        $result = $this->service->update($usuario, $item, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                (string) $result['message'],
                (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: EvaluacionSupervisorResource::make($result['item'])->resolve(),
            message: 'Evaluacion de supervisor actualizada',
            code: 'EVAL_SUPERVISOR_UPDATE_OK',
        );
    }

    public function calcular(Request $request)
    {
        $payload = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $calculo = $this->service->calculate($payload['respuestas']);

        return ApiResponse::success(
            data: $calculo,
            message: 'Puntaje final calculado',
            code: 'EVAL_SUPERVISOR_CALC_OK',
        );
    }

    public function plantilla()
    {
        return ApiResponse::success(
            data: [
                'items' => SupervisorEvaluationTemplate::ITEMS,
                'weights' => SupervisorEvaluationTemplate::WEIGHTS,
            ],
            message: 'Plantilla de evaluacion de supervisor obtenida',
            code: 'EVAL_SUPERVISOR_TEMPLATE_OK',
        );
    }
}
