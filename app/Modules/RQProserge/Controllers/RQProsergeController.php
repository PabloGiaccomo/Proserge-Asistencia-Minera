<?php

namespace App\Modules\RQProserge\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RQProserge;
use App\Models\Usuario;
use App\Modules\RQProserge\Requests\AssignPersonalRequest;
use App\Modules\RQProserge\Requests\StoreRQProsergeRequest;
use App\Modules\RQProserge\Requests\UnassignPersonalRequest;
use App\Modules\RQProserge\Requests\UpdateRQProsergeRequest;
use App\Modules\RQProserge\Resources\RQProsergeResource;
use App\Modules\RQProserge\Services\RQProsergeService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class RQProsergeController extends Controller
{
    public function __construct(private readonly RQProsergeService $service)
    {
    }

    public function index(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if (!$this->service->canList($usuario)) {
            return ApiResponse::error(
                message: 'No autorizado para consultar RQ Proserge',
                code: 'RQ_PROSERGE_FORBIDDEN',
                status: 403,
            );
        }

        $filters = $request->validate([
            'mina_id' => ['nullable', 'string', 'size:36'],
            'estado' => ['nullable', 'string', 'max:30'],
            'responsable_rrhh_id' => ['nullable', 'string', 'size:36'],
            'rq_mina_id' => ['nullable', 'string', 'size:36'],
        ]);

        $items = $this->service->listForUser($usuario, $filters);

        return ApiResponse::success(
            data: RQProsergeResource::collection($items)->resolve(),
            message: 'RQ Proserge obtenidos',
            code: 'RQ_PROSERGE_LIST_OK',
        );
    }

    public function show(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rq = $this->service->findForUser($usuario, $id);

        if (!$rq) {
            return ApiResponse::error(
                message: 'RQ Proserge no encontrado o sin acceso',
                code: 'RQ_PROSERGE_NOT_FOUND',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: RQProsergeResource::make($rq)->resolve(),
            message: 'RQ Proserge obtenido',
            code: 'RQ_PROSERGE_SHOW_OK',
        );
    }

    public function store(StoreRQProsergeRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rq = $this->service->create($usuario, $request->validated());

        if (!$rq) {
            return ApiResponse::error(
                message: 'Usuario sin permisos o sin acceso a la mina solicitada',
                code: 'RQ_PROSERGE_FORBIDDEN',
                detail: ['mina_id' => $request->validated()['mina_id']],
                status: 403,
            );
        }

        return ApiResponse::success(
            data: RQProsergeResource::make($rq)->resolve(),
            message: 'RQ Proserge creado',
            code: 'RQ_PROSERGE_CREATE_OK',
            status: 201,
        );
    }

    public function update(UpdateRQProsergeRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rq = RQProserge::query()->find($id);

        if (!$rq) {
            return ApiResponse::error(
                message: 'RQ Proserge no encontrado',
                code: 'RQ_PROSERGE_NOT_FOUND',
                status: 404,
            );
        }

        if (!$this->service->canAssign($usuario, $rq)) {
            return ApiResponse::error(
                message: 'No autorizado para actualizar este RQ Proserge',
                code: 'RQ_PROSERGE_FORBIDDEN',
                status: 403,
            );
        }

        $rq->fill($request->validated());
        $rq->save();

        return ApiResponse::success(
            data: RQProsergeResource::make($rq->load(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle']))->resolve(),
            message: 'RQ Proserge actualizado',
            code: 'RQ_PROSERGE_UPDATE_OK',
        );
    }

    public function asignar(AssignPersonalRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rq = RQProserge::query()->with(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle'])->find($id);

        if (!$rq) {
            return ApiResponse::error(
                message: 'RQ Proserge no encontrado',
                code: 'RQ_PROSERGE_NOT_FOUND',
                status: 404,
            );
        }

        try {
            $result = $this->service->assignPersonal($usuario, $rq, $request->validated());
        } catch (Throwable $e) {
            return ApiResponse::error(
                message: 'Error tecnico al asignar personal',
                code: 'RQ_PROSERGE_ASSIGN_TECHNICAL_ERROR',
                detail: ['exception' => $e->getMessage()],
                status: 500,
            );
        }

        if (($result['ok'] ?? false) === false) {
            $code = (string) ($result['code'] ?? 'RQ_PROSERGE_ASSIGN_FAILED');

            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo asignar personal'),
                code: $code,
                status: str_contains($code, 'FORBIDDEN') ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: RQProsergeResource::make($result['rq'])->resolve(),
            message: 'Personal asignado correctamente',
            code: 'RQ_PROSERGE_ASSIGN_OK',
        );
    }

    public function desasignar(UnassignPersonalRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rq = RQProserge::query()->with(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle'])->find($id);

        if (!$rq) {
            return ApiResponse::error(
                message: 'RQ Proserge no encontrado',
                code: 'RQ_PROSERGE_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->unassignPersonal($usuario, $rq, $request->validated()['rq_proserge_detalle_id']);

        if (($result['ok'] ?? false) === false) {
            $code = (string) ($result['code'] ?? 'RQ_PROSERGE_UNASSIGN_FAILED');

            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo desasignar personal'),
                code: $code,
                status: str_contains($code, 'FORBIDDEN') ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: RQProsergeResource::make($result['rq'])->resolve(),
            message: 'Personal desasignado correctamente',
            code: 'RQ_PROSERGE_UNASSIGN_OK',
        );
    }

    public function disponibles(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $payload = $request->validate([
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rq = $this->service->findForUser($usuario, $id);

        if (!$rq) {
            return ApiResponse::error(
                message: 'RQ Proserge no encontrado o sin acceso',
                code: 'RQ_PROSERGE_NOT_FOUND',
                status: 404,
            );
        }

        $data = $this->service->disponibles(
            rq: $rq,
            fechaInicio: $payload['fecha_inicio'],
            fechaFin: $payload['fecha_fin'],
            limit: (int) ($payload['limit'] ?? 25),
        );

        return ApiResponse::success(
            data: $data,
            message: 'Disponibilidad obtenida',
            code: 'RQ_PROSERGE_DISPONIBLES_OK',
        );
    }
}
