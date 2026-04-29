<?php

namespace App\Modules\Notificaciones\Services;

use App\Models\NotificationRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationInboxService
{
    public function listForUser(string $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->baseQuery($userId);

        if (!empty($filters['status'])) {
            $query->where('status', strtoupper((string) $filters['status']));
        }

        if (!empty($filters['module'])) {
            $query->whereHas('event', fn (Builder $q) => $q->where('module', (string) $filters['module']));
        }

        if (!empty($filters['priority'])) {
            $query->whereHas('event', fn (Builder $q) => $q->where('priority', (string) $filters['priority']));
        }

        if (!empty($filters['mine_id'])) {
            $query->whereHas('event', fn (Builder $q) => $q->where('mina_id', (string) $filters['mine_id']));
        }

        if (!empty($filters['q'])) {
            $search = '%' . mb_strtolower((string) $filters['q']) . '%';
            $query->whereHas('event', function (Builder $q) use ($search): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(message) LIKE ?', [$search]);
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function unreadCount(string $userId): int
    {
        return $this->baseQuery($userId)
            ->where('status', 'UNREAD')
            ->count();
    }

    public function latestForHeader(string $userId, int $limit = 8): Collection
    {
        return $this->baseQuery($userId)
            ->whereIn('status', ['UNREAD', 'READ', 'ACTIONED'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function markAsRead(NotificationRecipient $recipient): NotificationRecipient
    {
        if ($recipient->status === 'UNREAD') {
            $recipient->status = 'READ';
            $recipient->read_at = now();
            $recipient->save();
        }

        return $recipient;
    }

    public function markAllAsRead(string $userId): int
    {
        return NotificationRecipient::query()
            ->where('usuario_id', $userId)
            ->where('status', 'UNREAD')
            ->update([
                'status' => 'READ',
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function archive(NotificationRecipient $recipient): NotificationRecipient
    {
        $recipient->status = 'ARCHIVED';
        $recipient->archived_at = now();
        $recipient->save();

        return $recipient;
    }

    private function baseQuery(string $userId): Builder
    {
        return NotificationRecipient::query()
            ->with(['event.type', 'event.mina'])
            ->where('usuario_id', $userId)
            ->whereHas('event', function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
    }
}
