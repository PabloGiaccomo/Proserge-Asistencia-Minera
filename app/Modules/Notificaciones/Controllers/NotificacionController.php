<?php

namespace App\Modules\Notificaciones\Controllers;

use App\Http\Controllers\Controller;
use App\Models\NotificationRecipient;
use App\Modules\Notificaciones\Services\NotificationInboxService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        $filters = $request->only(['status', 'module', 'priority', 'mine_id', 'q']);

        $notifications = $this->inbox->listForUser((string) $user->id, $filters, (int) $request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ], 'Notificaciones obtenidas');
    }

    public function unreadCount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        return ApiResponse::success([
            'count' => $this->inbox->unreadCount((string) $user->id),
        ], 'Conteo obtenido');
    }

    public function markRead(Request $request, string $recipientId)
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        $recipient = $this->findRecipientForUser((string) $user->id, $recipientId);
        if (!$recipient) {
            return ApiResponse::error('Notificacion no encontrada', 'NOTIFICATION_NOT_FOUND', status: 404);
        }

        $this->inbox->markAsRead($recipient);

        return ApiResponse::success($recipient->fresh(['event']), 'Notificacion marcada como leida');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        $updated = $this->inbox->markAllAsRead((string) $user->id);

        return ApiResponse::success(['updated' => $updated], 'Notificaciones marcadas como leidas');
    }

    public function archive(Request $request, string $recipientId)
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        $recipient = $this->findRecipientForUser((string) $user->id, $recipientId);
        if (!$recipient) {
            return ApiResponse::error('Notificacion no encontrada', 'NOTIFICATION_NOT_FOUND', status: 404);
        }

        $this->inbox->archive($recipient);

        return ApiResponse::success($recipient->fresh(['event']), 'Notificacion archivada');
    }

    private function findRecipientForUser(string $userId, string $recipientId): ?NotificationRecipient
    {
        if ($userId === '') {
            return null;
        }

        return NotificationRecipient::query()
            ->where('id', $recipientId)
            ->where('usuario_id', $userId)
            ->with('event')
            ->first();
    }
}
