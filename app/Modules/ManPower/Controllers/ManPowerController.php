<?php

namespace App\Modules\ManPower\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\ManPower\Requests\AddGrupoPersonalRequest;
use App\Modules\ManPower\Requests\RemoveGrupoPersonalRequest;
use App\Modules\ManPower\Requests\StoreGrupoTrabajoRequest;
use App\Modules\ManPower\Requests\UpdateGrupoTrabajoRequest;
use App\Modules\ManPower\Resources\GrupoTrabajoResource;
use App\Modules\ManPower\Services\GrupoTrabajoService;
use App\Modules\ManPower\Services\ManPowerParadasService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class ManPowerController extends Controller
{
    public function __construct(
        private readonly ManPowerParadasService $paradasService,
        private readonly GrupoTrabajoService $grupoService,
    ) {
    }

    public function paradas(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'mina_id' => ['nullable', 'string', 'size:36'],
            'estado' => ['nullable', 'string', 'max:30'],
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

        $result = $this->grupoService->addPersonal($usuario, $grupo, $request->validated()['personal_id']);

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
