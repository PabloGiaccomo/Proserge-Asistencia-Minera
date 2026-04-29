<?php

namespace App\Modules\Notificaciones\Controllers;

use App\Http\Controllers\Controller;
use App\Models\NotificationRecipient;
use App\Modules\Notificaciones\Services\NotificationInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificacionPageController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox)
    {
    }

    public function index(Request $request): RedirectResponse
    {
        if (!$this->notificationTablesReady()) {
            return redirect()->route('inicio')->with('error', 'Debes crear las tablas de notificaciones antes de abrir la bandeja.');
        }

        $userId = (string) session('user.id', '');
        abort_if($userId === '', 401);

        return redirect()
            ->route('inicio', ['open_notifications' => 1] + $request->query())
            ->with('info', 'Bandeja de notificaciones abierta.');
    }

    public function count(Request $request): JsonResponse
    {
        if (!$this->notificationTablesReady()) {
            return response()->json(['count' => 0]);
        }

        $userId = (string) session('user.id', '');
        if ($userId === '') {
            return response()->json(['count' => 0]);
        }

        return response()->json(['count' => $this->inbox->unreadCount($userId)]);
    }

    public function markRead(string $recipientId): RedirectResponse
    {
        if (!$this->notificationTablesReady()) {
            return back()->with('error', 'No existen tablas de notificaciones en la base de datos.');
        }

        $recipient = $this->findRecipientForCurrentUser($recipientId);
        $this->inbox->markAsRead($recipient);

        return back()->with('success', 'Notificacion marcada como leida.');
    }

    public function markAllRead(): RedirectResponse
    {
        if (!$this->notificationTablesReady()) {
            return back()->with('error', 'No existen tablas de notificaciones en la base de datos.');
        }

        $userId = (string) session('user.id', '');
        abort_if($userId === '', 401);

        $updated = $this->inbox->markAllAsRead($userId);

        return back()->with('success', sprintf('Se marcaron %d notificaciones como leidas.', $updated));
    }

    public function archive(string $recipientId): RedirectResponse
    {
        if (!$this->notificationTablesReady()) {
            return back()->with('error', 'No existen tablas de notificaciones en la base de datos.');
        }

        $recipient = $this->findRecipientForCurrentUser($recipientId);
        $this->inbox->archive($recipient);

        return back()->with('success', 'Notificacion archivada.');
    }

    public function openAction(string $recipientId): RedirectResponse
    {
        if (!$this->notificationTablesReady()) {
            return redirect()->route('inicio')->with('error', 'No existen tablas de notificaciones en la base de datos.');
        }

        $recipient = $this->findRecipientForCurrentUser($recipientId);
        $this->inbox->markAsRead($recipient);

        if ($recipient->status !== 'ARCHIVED') {
            $recipient->status = 'ACTIONED';
            $recipient->actioned_at = now();
            $recipient->save();
        }

        $route = $recipient->event?->action_route;

        if (!$route) {
            return redirect()->route('notificaciones.index');
        }

        return redirect($route);
    }

    private function findRecipientForCurrentUser(string $recipientId): NotificationRecipient
    {
        $userId = (string) session('user.id', '');
        abort_if($userId === '', 401);

        return NotificationRecipient::query()
            ->where('id', $recipientId)
            ->where('usuario_id', $userId)
            ->with('event')
            ->firstOrFail();
    }

    private function notificationTablesReady(): bool
    {
        return Schema::hasTable('notification_types')
            && Schema::hasTable('notification_events')
            && Schema::hasTable('notification_recipients');
    }

    public function poll(Request $request): JsonResponse
    {
        if (!$this->notificationTablesReady()) {
            Log::warning('notificaciones.poll_tables_not_ready', [
                'session_user_id' => session('user.id'),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'NOTIFICATION_TABLES_NOT_READY',
                'count' => 0,
                'items' => [],
                'timestamp' => now()->timestamp,
            ], 200);
        }

        $userId = (string) session('user.id', '');
        if ($userId === '') {
            Log::warning('notificaciones.poll_missing_session_user', [
                'session_user' => session('user'),
                'session_user_id' => session('user_id'),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'SESSION_USER_NOT_FOUND',
                'message' => 'No hay usuario en sesion',
                'count' => 0,
                'items' => [],
                'timestamp' => now()->timestamp,
            ], 401);
        }

        $limit = max(1, min(20, (int) $request->integer('limit', 10)));

        $notifications = $this->inbox->latestForHeader($userId, $limit);
        $count = $this->inbox->unreadCount($userId);

        $items = $notifications->map(function (NotificationRecipient $recipient): array {
            $event = $recipient->event;

            return [
                'id' => (string) $recipient->id,
                'title' => (string) ($event?->title ?? ''),
                'message' => (string) ($event?->message ?? ''),
                'module' => (string) ($event?->module ?? ''),
                'priority' => (string) ($event?->priority ?? 'medium'),
                'status' => (string) $recipient->status,
                'created_at' => $recipient->created_at?->toIso8601String(),
                'occurred_at' => $event?->occurred_at?->toIso8601String(),
                'action_label' => (string) ($event?->action_label ?? 'Abrir'),
                'action_url' => !empty($event?->action_route) ? route('notificaciones.action', $recipient->id) : null,
                'mark_read_url' => route('notificaciones.mark-read', $recipient->id),
                'archive_url' => route('notificaciones.archive', $recipient->id),
            ];
        })->values()->all();

        Log::info('notificaciones.poll_success', [
            'session_user_id' => $userId,
            'unread_count' => $count,
            'items_count' => count($items),
            'item_ids' => array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $items),
        ]);

        return response()->json([
            'ok' => true,
            'count' => $count,
            'items' => $items,
            'timestamp' => now()->timestamp,
        ]);
    }
}
