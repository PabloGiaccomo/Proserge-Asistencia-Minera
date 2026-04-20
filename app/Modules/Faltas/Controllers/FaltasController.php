<?php

namespace App\Modules\Faltas\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Faltas\Requests\AnularFaltaRequest;
use App\Modules\Faltas\Requests\CorregirFaltaRequest;
use App\Modules\Faltas\Requests\UpdateFaltaRequest;
use App\Modules\Faltas\Resources\FaltaResource;
use App\Modules\Faltas\Services\FaltasService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class FaltasController extends Controller
{
    public function __construct(private readonly FaltasService $service)
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
            'personal_id' => ['nullable', 'string', 'size:36'],
            'grupo_trabajo_id' => ['nullable', 'string', 'size:36'],
            'estado' => ['nullable', 'string', 'in:ACTIVA,CORREGIDA,ANULADA'],
            'motivo' => ['nullable', 'string', 'max:40'],
        ]);

        $items = $this->service->list($usuario, $filters);

        if ($items === null) {
            return ApiResponse::error(
                message: 'No autorizado para consultar faltas',
                code: 'FALTAS_FORBIDDEN',
                status: 403,
            );
        }

        return ApiResponse::success(
            data: FaltaResource::collection($items)->resolve(),
            message: 'Faltas obtenidas',
            code: 'FALTAS_LIST_OK',
        );
    }

    public function show(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $falta = $this->service->findForUser($usuario, $id);

        if (!$falta) {
            return ApiResponse::error(
                message: 'Falta no encontrada o sin acceso',
                code: 'FALTA_NOT_FOUND',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: FaltaResource::make($falta)->resolve(),
            message: 'Falta obtenida',
            code: 'FALTA_SHOW_OK',
        );
    }

    public function update(UpdateFaltaRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $falta = $this->service->findForUser($usuario, $id);

        if (!$falta) {
            return ApiResponse::error(
                message: 'Falta no encontrada o sin acceso',
                code: 'FALTA_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->update($usuario, $falta, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: FaltaResource::make($result['falta'])->resolve(),
            message: 'Falta actualizada',
            code: 'FALTA_UPDATE_OK',
        );
    }

    public function corregirAsistencia(CorregirFaltaRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $falta = $this->service->findForUser($usuario, $id);

        if (!$falta) {
            return ApiResponse::error(
                message: 'Falta no encontrada o sin acceso',
                code: 'FALTA_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->corregirAsistencia($usuario, $falta, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: FaltaResource::make($result['falta'])->resolve(),
            message: 'Falta corregida con asistencia',
            code: 'FALTA_CORREGIR_OK',
        );
    }

    public function anular(AnularFaltaRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $falta = $this->service->findForUser($usuario, $id);

        if (!$falta) {
            return ApiResponse::error(
                message: 'Falta no encontrada o sin acceso',
                code: 'FALTA_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->anular($usuario, $falta, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: FaltaResource::make($result['falta'])->resolve(),
            message: 'Falta anulada',
            code: 'FALTA_ANULAR_OK',
        );
    }
}
