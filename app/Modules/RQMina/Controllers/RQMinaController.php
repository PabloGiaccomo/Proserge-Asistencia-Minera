<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\RQMina\Requests\SendRQMinaRequest;
use App\Modules\RQMina\Requests\StoreRQMinaRequest;
use App\Modules\RQMina\Requests\UpdateRQMinaRequest;
use App\Modules\RQMina\Resources\RQMinaResource;
use App\Modules\RQMina\Services\RQMinaService;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RQMinaController extends Controller
{
    public function __construct(
        private readonly RQMinaService $service,
        private readonly NotificationService $notificationService,
    )
    {
    }

    public function index(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $filters = $request->validate([
            'mina_id' => ['nullable', 'string', 'size:36'],
            'estado' => ['nullable', 'string', 'max:30'],
            'created_by_usuario_id' => ['nullable', 'string', 'size:36'],
            'fecha_inicio_desde' => ['nullable', 'date'],
            'fecha_inicio_hasta' => ['nullable', 'date'],
            'fecha_fin_desde' => ['nullable', 'date'],
            'fecha_fin_hasta' => ['nullable', 'date'],
        ]);

        if (!empty($filters['mina_id']) && !$this->service->canAccessMina($usuario, $filters['mina_id'])) {
            return ApiResponse::error(
                message: 'Usuario sin acceso a la mina solicitada',
                code: 'MINA_SCOPE_FORBIDDEN',
                detail: ['mina_id' => $filters['mina_id']],
                status: 403,
            );
        }

        $items = $this->service->listForUser($usuario, $filters);

        return ApiResponse::success(
            data: RQMinaResource::collection($items)->resolve(),
            message: 'RQ Mina obtenidos',
            code: 'RQ_MINA_LIST_OK',
        );
    }

    public function show(Request $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return ApiResponse::error(
                message: 'RQ Mina no encontrado o sin acceso',
                code: 'RQ_MINA_NOT_FOUND',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: RQMinaResource::make($rqMina)->resolve(),
            message: 'RQ Mina obtenido',
            code: 'RQ_MINA_SHOW_OK',
        );
    }

    public function store(StoreRQMinaRequest $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rqMina = $this->service->create($usuario, $request->validated());

        if (!$rqMina) {
            return ApiResponse::error(
                message: 'Usuario sin acceso a la mina solicitada',
                code: 'MINA_SCOPE_FORBIDDEN',
                detail: ['mina_id' => $request->validated()['mina_id']],
                status: 403,
            );
        }

        return ApiResponse::success(
            data: RQMinaResource::make($rqMina)->resolve(),
            message: 'RQ Mina creado en BORRADOR',
            code: 'RQ_MINA_CREATE_OK',
            status: 201,
        );
    }

    public function update(UpdateRQMinaRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rqMina = RQMina::query()->find($id);

        if (!$rqMina) {
            return ApiResponse::error(
                message: 'RQ Mina no encontrado',
                code: 'RQ_MINA_NOT_FOUND',
                status: 404,
            );
        }

        if (in_array($rqMina->estado, ['CERRADO', 'CANCELADO'], true)) {
            return ApiResponse::error(
                message: 'RQ Mina no editable en estado actual',
                code: 'RQ_MINA_NOT_EDITABLE',
                detail: ['estado' => $rqMina->estado],
                status: 422,
            );
        }

        if (!$this->service->canUpdate($usuario, $rqMina)) {
            return ApiResponse::error(
                message: 'No autorizado para editar este RQ Mina',
                code: 'RQ_MINA_FORBIDDEN_EDIT',
                status: 403,
            );
        }

        $updated = $this->service->update($usuario, $rqMina, $request->validated());

        if (!$updated) {
            return ApiResponse::error(
                message: 'Usuario sin acceso a la mina solicitada',
                code: 'MINA_SCOPE_FORBIDDEN',
                detail: ['mina_id' => $request->validated()['mina_id']],
                status: 403,
            );
        }

        return ApiResponse::success(
            data: RQMinaResource::make($updated)->resolve(),
            message: 'RQ Mina actualizado',
            code: 'RQ_MINA_UPDATE_OK',
        );
    }

    public function enviar(SendRQMinaRequest $request, string $id)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $rqMina = RQMina::query()->with(['mina:id,nombre', 'creador:id,email', 'detalle'])->find($id);

        if (!$rqMina) {
            return ApiResponse::error(
                message: 'RQ Mina no encontrado',
                code: 'RQ_MINA_NOT_FOUND',
                status: 404,
            );
        }

        $sent = $this->service->send($usuario, $rqMina);

        if (!$sent) {
            return ApiResponse::error(
                message: 'No autorizado o estado invalido para enviar',
                code: 'RQ_MINA_SEND_FORBIDDEN',
                detail: ['estado' => $rqMina->estado],
                status: 403,
            );
        }

        try {
            $this->notificationService->emit('rq_mina_enviado', $this->buildSendNotificationContext($usuario, $sent));
        } catch (\Throwable $exception) {
            Log::error('rqmina.api_send_notification_exception', [
                'rq_id' => (string) $sent->id,
                'mina_id' => (string) $sent->mina_id,
                'actor_usuario_id' => (string) $usuario->id,
                'error_message' => $exception->getMessage(),
                'error_trace' => $exception->getTraceAsString(),
            ]);
        }

        return ApiResponse::success(
            data: RQMinaResource::make($sent)->resolve(),
            message: 'RQ Mina enviado',
            code: 'RQ_MINA_SEND_OK',
        );
    }

    private function buildSendNotificationContext(Usuario $usuario, RQMina $rqMina): array
    {
        $mineName = (string) ($rqMina->mina?->nombre ?? 'mina no definida');
        $areaName = (string) ($rqMina->area ?? 'sin area');
        $fechaInicio = $rqMina->fecha_inicio ? $rqMina->fecha_inicio->format('d/m/Y') : 'sin fecha';
        $fechaFin = $rqMina->fecha_fin ? $rqMina->fecha_fin->format('d/m/Y') : $fechaInicio;

        return [
            'actor_user_id' => (string) $usuario->id,
            'mine_id' => (string) $rqMina->mina_id,
            'entity_type' => 'rq_mina',
            'entity_id' => (string) $rqMina->id,
            'title' => 'RQ Mina enviado',
            'permission_module' => 'rq_mina',
            'permission_action' => 'ver',
            'require_permission' => false,
            'message' => sprintf(
                '%s | Area: %s | %s al %s. Requiere atencion RRHH/Planner.',
                $mineName,
                $areaName,
                $fechaInicio,
                $fechaFin
            ),
            'dedupe_key' => 'rq_mina_enviado:' . $rqMina->id,
        ];
    }
}
