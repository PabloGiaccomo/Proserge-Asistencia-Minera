<?php

namespace App\Modules\Notificaciones\Services;

use App\Models\NotificationRecipient;
use App\Models\NotificationType;
use App\Models\Usuario;
use App\Modules\Notificaciones\Policies\NotificacionesPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NotificationInboxService
{
    public function __construct(private readonly NotificacionesPolicy $policy) {}

    public function findForUser(string $userId, string $recipientId, string $action = 'actualizar'): ?NotificationRecipient
    {
        return $this->baseQuery($userId, $action)->whereKey($recipientId)->first();
    }

    public function listForUser(string $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->baseQuery($userId);

        if (! empty($filters['status'])) {
            $query->where('status', strtoupper((string) $filters['status']));
        }

        if (! empty($filters['module'])) {
            $query->whereHas('event', fn (Builder $q) => $q->where('module', (string) $filters['module']));
        }

        if (! empty($filters['priority'])) {
            $query->whereHas('event', fn (Builder $q) => $q->where('priority', (string) $filters['priority']));
        }

        if (! empty($filters['mine_id'])) {
            $query->whereHas('event', fn (Builder $q) => $q->where('mina_id', (string) $filters['mine_id']));
        }

        if (! empty($filters['q'])) {
            $search = '%'.mb_strtolower((string) $filters['q']).'%';
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
        return $this->baseQuery($userId, 'actualizar')
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

    private function baseQuery(string $userId, string $action = 'ver'): Builder
    {
        $query = NotificationRecipient::query()
            ->with(['event.type', 'event.mina'])
            ->where('usuario_id', $userId)
            ->whereHas('event', function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });

        $user = Usuario::query()->with(['rol', 'rolesAdicionales'])->find($userId);
        if (! $user || ! $this->policy->canUse($user, $action)) {
            return $query->whereRaw('1 = 0');
        }

        $typesByModule = NotificationType::query()->get()
            ->filter(fn (NotificationType $type) => $this->policy->canViewType($user, $type))
            ->groupBy(fn (NotificationType $type) => $this->policy->scopeModule($type));

        if ($typesByModule->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        // Context permission checks depend on whether the declared type has mine
        // scope, not its individual module. Share them within those two groups.
        $typesByScope = $typesByModule->groupBy(
            fn (Collection $types, string $module): int => (int) $this->policy->requiresMinaScope($module),
            preserveKeys: true,
        );

        // Apply current authorization before counting, paginating or bulk updating.
        return $query->whereHas('event', function (Builder $events) use ($typesByScope, $user): void {
            $events->where(function (Builder $allowed) use ($typesByScope, $user): void {
                foreach ($typesByScope as $scopedTypes) {
                    $allowed->orWhere(function (Builder $scope) use ($scopedTypes, $user): void {
                        $this->policy->restrictContextPermissions($scope, $user, (string) $scopedTypes->keys()->first());
                        $scope->where(function (Builder $declared) use ($scopedTypes, $user): void {
                            foreach ($scopedTypes as $module => $types) {
                                $declared->orWhere(function (Builder $group) use ($module, $types, $user): void {
                                    $group->whereIn('notification_type_id', $types->pluck('id'));
                                    $this->policy->restrictMinaScope($group, $user, $module);
                                });
                            }
                        });
                    });
                }
            });
        });
    }
}
