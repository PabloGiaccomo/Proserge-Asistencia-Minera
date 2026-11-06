<?php

namespace App\Modules\Notificaciones\Controllers;

use App\Http\Controllers\Controller;
use App\Models\NotificationRecipient;
use App\Modules\Notificaciones\Policies\NotificacionesPolicy;
use App\Modules\Notificaciones\Services\NotificationInboxService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox, private readonly NotificacionesPolicy $policy) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        if (! $this->policy->canUse($user)) {
            return ApiResponse::error('No tienes permiso para consultar notificaciones.', 'NOTIFICATIONS_FORBIDDEN', status: 403);
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
        if (! $user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        if (! $this->policy->canUse($user)) {
            return ApiResponse::error('No tienes permiso para consultar notificaciones.', 'NOTIFICATIONS_FORBIDDEN', status: 403);
        }

        return ApiResponse::success([
            'count' => $this->inbox->unreadCount((string) $user->id),
        ], 'Conteo obtenido');
    }

    public function markRead(Request $request, string $recipientId)
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        if (! $this->policy->canUse($user, 'actualizar')) {
            return ApiResponse::error('No tienes permiso para actualizar notificaciones.', 'NOTIFICATIONS_FORBIDDEN', status: 403);
        }

        $recipient = $this->findRecipientForUser((string) $user->id, $recipientId);
        if (! $recipient) {
            return ApiResponse::error('Notificacion no encontrada', 'NOTIFICATION_NOT_FOUND', status: 404);
        }

        $this->inbox->markAsRead($recipient);

        $result = $recipient->fresh($this->policy->canUse($user) ? ['event'] : []);

        return ApiResponse::success($result, 'Notificacion marcada como leida');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        if (! $this->policy->canUse($user, 'actualizar')) {
            return ApiResponse::error('No tienes permiso para actualizar notificaciones.', 'NOTIFICATIONS_FORBIDDEN', status: 403);
        }

        $updated = $this->inbox->markAllAsRead((string) $user->id);

        return ApiResponse::success(['updated' => $updated], 'Notificaciones marcadas como leidas');
    }

    public function archive(Request $request, string $recipientId)
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('No autenticado', 'AUTH_REQUIRED', status: 401);
        }

        if (! $this->policy->canUse($user, 'actualizar')) {
            return ApiResponse::error('No tienes permiso para actualizar notificaciones.', 'NOTIFICATIONS_FORBIDDEN', status: 403);
        }

        $recipient = $this->findRecipientForUser((string) $user->id, $recipientId);
        if (! $recipient) {
            return ApiResponse::error('Notificacion no encontrada', 'NOTIFICATION_NOT_FOUND', status: 404);
        }

        $this->inbox->archive($recipient);

        $result = $recipient->fresh($this->policy->canUse($user) ? ['event'] : []);

        return ApiResponse::success($result, 'Notificacion archivada');
    }

    private function findRecipientForUser(string $userId, string $recipientId): ?NotificationRecipient
    {
        return $this->inbox->findForUser($userId, $recipientId);
    }
}
