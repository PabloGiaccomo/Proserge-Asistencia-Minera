<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionDesempenoRequest;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionResidenteRequest;
use App\Modules\Evaluaciones\Requests\StoreEvaluacionSupervisorRequest;
use App\Modules\Evaluaciones\Requests\UpdateEvaluacionDesempenoRequest;
use App\Modules\Evaluaciones\Resources\EvaluacionDesempenoResource;
use App\Modules\Evaluaciones\Resources\EvaluacionResidenteResource;
use App\Modules\Evaluaciones\Resources\EvaluacionSupervisorResource;
use App\Modules\Evaluaciones\Services\EvaluacionDesempenoService;
use App\Modules\Evaluaciones\Services\PromedioDesempenoService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class EvaluacionDesempenoController extends Controller
{
    public function __construct(
        private readonly EvaluacionDesempenoService $service,
        private readonly PromedioDesempenoService $promedios,
    ) {
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
            'trabajador_id' => ['nullable', 'string', 'size:36'],
            'grupo_trabajo_id' => ['nullable', 'string', 'size:36'],
        ]);

        $items = $this->service->list($usuario, $filters);

        if ($items === null) {
            return ApiResponse::error('No autorizado para ver evaluaciones', 'EVAL_FORBIDDEN', status: 403);
        }

        return ApiResponse::success(
            data: EvaluacionDesempenoResource::collection($items)->resolve(),
            message: 'Evaluaciones de desempeno obtenidas',
            code: 'EVAL_DESEMPENO_LIST_OK',
        );
    }

    public function show(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $item = $this->service->find($usuario, $id);

        if (!$item) {
            return ApiResponse::error('Evaluacion no encontrada o sin acceso', 'EVAL_NOT_FOUND', status: 404);
        }

        return ApiResponse::success(
            data: EvaluacionDesempenoResource::make($item)->resolve(),
            message: 'Evaluacion de desempeno obtenida',
            code: 'EVAL_DESEMPENO_SHOW_OK',
        );
    }

    public function store(StoreEvaluacionDesempenoRequest $request)
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
            data: EvaluacionDesempenoResource::make($result['item'])->resolve(),
            message: 'Evaluacion de desempeno creada',
            code: 'EVAL_DESEMPENO_CREATE_OK',
            status: 201,
        );
    }

    public function update(UpdateEvaluacionDesempenoRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $item = $this->service->find($usuario, $id);

        if (!$item) {
            return ApiResponse::error('Evaluacion no encontrada o sin acceso', 'EVAL_NOT_FOUND', status: 404);
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
            data: EvaluacionDesempenoResource::make($result['item'])->resolve(),
            message: 'Evaluacion de desempeno actualizada',
            code: 'EVAL_DESEMPENO_UPDATE_OK',
        );
    }

    public function promedios(Request $request)
    {
        $filters = $request->validate([
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'trabajador_id' => ['nullable', 'string', 'size:36'],
        ]);

        return ApiResponse::success(
            data: $this->promedios->list($filters),
            message: 'Promedios de desempeno obtenidos',
            code: 'EVAL_PROMEDIOS_OK',
        );
    }

    public function comparacion(Request $request)
    {
        $filters = $request->validate([
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'trabajador_ids' => ['nullable', 'array'],
            'trabajador_ids.*' => ['string', 'size:36'],
        ]);

        return ApiResponse::success(
            data: $this->promedios->comparacion($filters),
            message: 'Comparacion de desempeno obtenida',
            code: 'EVAL_COMPARACION_OK',
        );
    }

    public function storeSupervisor(StoreEvaluacionSupervisorRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->createSupervisor($usuario, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error((string) $result['message'], (string) $result['code'], status: 403);
        }

        return ApiResponse::success(
            data: EvaluacionSupervisorResource::make($result['item'])->resolve(),
            message: 'Evaluacion de supervisor registrada',
            code: 'EVAL_SUPERVISOR_CREATE_OK',
            status: 201,
        );
    }

    public function storeResidente(StoreEvaluacionResidenteRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->service->createResidente($usuario, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error((string) $result['message'], (string) $result['code'], status: 403);
        }

        return ApiResponse::success(
            data: EvaluacionResidenteResource::make($result['item'])->resolve(),
            message: 'Evaluacion de residente registrada',
            code: 'EVAL_RESIDENTE_CREATE_OK',
            status: 201,
        );
    }
}
