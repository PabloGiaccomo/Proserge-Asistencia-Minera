<?php

namespace App\Modules\ManPower\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\ManPower\Requests\AddGrupoPersonalRequest;
use App\Modules\ManPower\Requests\CopyGruposDiaRequest;
use App\Modules\ManPower\Requests\CopyGrupoTrabajoRequest;
use App\Modules\ManPower\Requests\RetireGrupoPersonalRequest;
use App\Modules\ManPower\Requests\ReubicarGrupoPersonalRequest;
use App\Modules\ManPower\Requests\RemoveGrupoPersonalRequest;
use App\Modules\ManPower\Requests\StoreGrupoTrabajoRequest;
use App\Modules\ManPower\Requests\UpdateGrupoTrabajoRequest;
use App\Modules\ManPower\Resources\GrupoTrabajoResource;
use App\Modules\ManPower\Services\GrupoTrabajoService;
use App\Modules\ManPower\Services\ManPowerParadasService;
use App\Modules\ManPower\Services\ManPowerPlanningService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class ManPowerController extends Controller
{
    public function __construct(
        private readonly ManPowerParadasService $paradasService,
        private readonly GrupoTrabajoService $grupoService,
        private readonly ManPowerPlanningService $planningService,
    ) {
    }

    public function contexto(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'rq_mina_id' => ['nullable', 'string', 'size:36'],
            'plan_id' => ['nullable', 'string', 'size:36'],
            'actividad_id' => ['nullable', 'string', 'size:36'],
            'fecha' => ['nullable', 'date'],
            'turno' => ['nullable', 'string', 'in:DIA,NOCHE'],
            'q' => ['nullable', 'string', 'max:191'],
        ]);

        return ApiResponse::success(
            data: $this->planningService->buildContext($usuario, $filters),
            message: 'Contexto de Man Power obtenido',
            code: 'MANPOWER_CONTEXT_OK',
        );
    }

    public function paradas(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'mina_id' => ['nullable', 'string', 'size:36'],
            'unidad_minera' => ['nullable', 'string', 'max:191'],
            'estado' => ['nullable', 'string', 'max:30'],
            'fecha' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:191'],
        ]);

        $items = $this->paradasService->listParadas($usuario, $filters);

        if ($items->isEmpty() && !empty($filters['mina_id']) && !$usuario->scopesMina()->where('mina_id', $filters['mina_id'])->exists()) {
            return ApiResponse::error(
                message: 'Usuario sin acceso a la mina solicitada',
                code: 'MINA_SCOPE_FORBIDDEN',
                status: 403,
            );
        }

        return ApiResponse::success(
            data: $items,
            message: 'Paradas de Man Power obtenidas',
            code: 'MANPOWER_PARADAS_LIST_OK',
        );
    }

    public function paradaDetalle(Request $request, string $rqMinaId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $payload = $request->validate([
            'fecha' => ['required', 'date'],
        ]);

        $data = $this->paradasService->paradaDetalle($usuario, $rqMinaId, $payload['fecha']);

        if (!$data) {
            return ApiResponse::error(
                message: 'Parada no encontrada o sin acceso',
                code: 'MANPOWER_PARADA_NOT_FOUND',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: $data,
            message: 'Detalle de parada obtenido',
            code: 'MANPOWER_PARADA_SHOW_OK',
        );
    }

    public function storeGrupo(StoreGrupoTrabajoRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $result = $this->grupoService->createGrupo($usuario, $request->validated());

        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : 422;

            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo crear el grupo'),
                code: (string) ($result['code'] ?? 'MANPOWER_CREATE_FAILED'),
                status: $status,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Grupo de trabajo creado',
            code: 'MANPOWER_GRUPO_CREATE_OK',
            status: 201,
        );
    }

    public function updateGrupo(UpdateGrupoTrabajoRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->grupoService->updateGrupo($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : 422;

            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo actualizar el grupo'),
                code: (string) ($result['code'] ?? 'MANPOWER_UPDATE_FAILED'),
                status: $status,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Grupo de trabajo actualizado',
            code: 'MANPOWER_GRUPO_UPDATE_OK',
        );
    }

    public function agregarPersonal(AddGrupoPersonalRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $payload = $request->validated();
        $result = $this->grupoService->addPersonal(
            $usuario,
            $grupo,
            $payload['personal_id'] ?? null,
            $payload['rq_proserge_detalle_id'] ?? null,
        );

        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : 422;

            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo agregar personal'),
                code: (string) ($result['code'] ?? 'MANPOWER_ADD_PERSON_FAILED'),
                status: $status,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Personal agregado al grupo',
            code: 'MANPOWER_GRUPO_ADD_PERSON_OK',
        );
    }

    public function quitarPersonal(RemoveGrupoPersonalRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->grupoService->removePersonal($usuario, $grupo, $request->validated()['personal_id']);

        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : 422;

            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo quitar personal'),
                code: (string) ($result['code'] ?? 'MANPOWER_REMOVE_PERSON_FAILED'),
                status: $status,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Personal retirado del grupo',
            code: 'MANPOWER_GRUPO_REMOVE_PERSON_OK',
        );
    }

    public function retirarPersonal(RetireGrupoPersonalRequest $request, string $id, string $detalleId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->grupoService->retireDetalle($usuario, $grupo, $detalleId, $request->validated('motivo'));

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo retirar integrante'),
                code: (string) ($result['code'] ?? 'MANPOWER_RETIRE_PERSON_FAILED'),
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Integrante retirado del grupo',
            code: 'MANPOWER_GRUPO_RETIRE_PERSON_OK',
        );
    }

    public function asignarResponsable(Request $request, string $id, string $detalleId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->grupoService->setResponsable($usuario, $grupo, $detalleId);

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo cambiar el responsable'),
                code: (string) ($result['code'] ?? 'MANPOWER_SET_RESPONSIBLE_FAILED'),
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Responsable actualizado',
            code: 'MANPOWER_GRUPO_RESPONSIBLE_OK',
        );
    }

    public function reubicarPersonal(ReubicarGrupoPersonalRequest $request, string $id, string $detalleId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $payload = $request->validated();
        $result = $this->grupoService->reubicarDetalle($usuario, $grupo, $detalleId, $payload['grupo_destino_id'], $payload['motivo']);

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo reubicar integrante'),
                code: (string) ($result['code'] ?? 'MANPOWER_RELOCATE_PERSON_FAILED'),
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($result['grupo'])->resolve(),
            message: 'Integrante reubicado',
            code: 'MANPOWER_GRUPO_RELOCATE_PERSON_OK',
        );
    }

    public function copiarGrupo(CopyGrupoTrabajoRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->grupoService->copyGrupo($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudo copiar grupo'),
                code: (string) ($result['code'] ?? 'MANPOWER_COPY_GROUP_FAILED'),
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: [
                'grupo' => GrupoTrabajoResource::make($result['grupo'])->resolve(),
                'copiados' => $result['copiados'] ?? [],
                'omitidos' => $result['omitidos'] ?? [],
            ],
            message: 'Grupo copiado',
            code: 'MANPOWER_GRUPO_COPY_OK',
            status: 201,
        );
    }

    public function copiarGruposDia(CopyGruposDiaRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $result = $this->grupoService->copyDayGroups($usuario, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'No se pudieron copiar los grupos'),
                code: (string) ($result['code'] ?? 'MANPOWER_DAY_COPY_FAILED'),
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: $result,
            message: 'Grupos del dia copiados',
            code: 'MANPOWER_DAY_COPY_OK',
            status: 201,
        );
    }

    public function showGrupo(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado',
                code: 'MANPOWER_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $data = $this->grupoService->showGrupo($usuario, $grupo);

        if (!$data) {
            return ApiResponse::error(
                message: 'No autorizado para ver el grupo',
                code: 'MANPOWER_FORBIDDEN',
                status: 403,
            );
        }

        return ApiResponse::success(
            data: GrupoTrabajoResource::make($data)->resolve(),
            message: 'Grupo obtenido',
            code: 'MANPOWER_GRUPO_SHOW_OK',
        );
    }
}
