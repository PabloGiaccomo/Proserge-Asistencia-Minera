<?php

namespace App\Modules\Asistencia\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Asistencia\Requests\CerrarAsistenciaRequest;
use App\Modules\Asistencia\Requests\MarcarAsistenciaRequest;
use App\Modules\Asistencia\Requests\MarcarMasivoAsistenciaRequest;
use App\Modules\Asistencia\Requests\ReabrirAsistenciaRequest;
use App\Modules\Asistencia\Resources\AsistenciaGrupoResource;
use App\Modules\Asistencia\Services\AsistenciaService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class AsistenciaController extends Controller
{
    public function __construct(private readonly AsistenciaService $service)
    {
    }

    public function grupos(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'fecha' => ['nullable', 'date'],
            'turno' => ['nullable', 'string', 'in:DIA,NOCHE'],
            'mina_id' => ['nullable', 'string', 'size:36'],
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
        ]);

        $items = $this->service->listGrupos($usuario, $filters);

        if ($items === null) {
            return ApiResponse::error(
                message: 'No autorizado para ver asistencia',
                code: 'ASISTENCIA_FORBIDDEN',
                status: 403,
            );
        }

        return ApiResponse::success(
            data: $items,
            message: 'Grupos de asistencia obtenidos',
            code: 'ASISTENCIA_GRUPOS_LIST_OK',
        );
    }

    public function showGrupo(Request $request, string $grupoId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $grupo = $this->service->getGrupo($usuario, $grupoId);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado o sin acceso',
                code: 'ASISTENCIA_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: AsistenciaGrupoResource::make($grupo)->resolve(),
            message: 'Grupo de asistencia obtenido',
            code: 'ASISTENCIA_GRUPO_SHOW_OK',
        );
    }

    public function marcar(MarcarAsistenciaRequest $request, string $grupoId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $grupo = $this->service->getGrupo($usuario, $grupoId);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado o sin acceso',
                code: 'ASISTENCIA_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->marcar($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: AsistenciaGrupoResource::make($result['grupo'])->resolve(),
            message: 'Marcacion registrada',
            code: 'ASISTENCIA_MARCAR_OK',
        );
    }

    public function marcarMasivo(MarcarMasivoAsistenciaRequest $request, string $grupoId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $grupo = $this->service->getGrupo($usuario, $grupoId);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado o sin acceso',
                code: 'ASISTENCIA_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->marcarMasivo($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: AsistenciaGrupoResource::make($result['grupo'])->resolve(),
            message: 'Marcacion masiva registrada',
            code: 'ASISTENCIA_MARCAR_MASIVO_OK',
        );
    }

    public function cerrar(CerrarAsistenciaRequest $request, string $grupoId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $grupo = $this->service->getGrupo($usuario, $grupoId);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado o sin acceso',
                code: 'ASISTENCIA_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->cerrar($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: AsistenciaGrupoResource::make($result['grupo'])->resolve(),
            message: 'Asistencia cerrada',
            code: 'ASISTENCIA_CERRAR_OK',
        );
    }

    public function reabrir(ReabrirAsistenciaRequest $request, string $grupoId)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $grupo = $this->service->getGrupo($usuario, $grupoId);

        if (!$grupo) {
            return ApiResponse::error(
                message: 'Grupo no encontrado o sin acceso',
                code: 'ASISTENCIA_GRUPO_NOT_FOUND',
                status: 404,
            );
        }

        $result = $this->service->reabrir($usuario, $grupo);

        if (($result['ok'] ?? false) === false) {
            return ApiResponse::error(
                message: (string) $result['message'],
                code: (string) $result['code'],
                status: ($result['forbidden'] ?? false) ? 403 : 422,
            );
        }

        return ApiResponse::success(
            data: AsistenciaGrupoResource::make($result['grupo'])->resolve(),
            message: 'Asistencia reabierta',
            code: 'ASISTENCIA_REABRIR_OK',
        );
    }
}
