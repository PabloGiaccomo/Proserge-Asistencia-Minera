<?php

namespace App\Modules\Notificaciones\Services;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationRecipientResolverService
{
    public function resolve(NotificationType $type, array $context = []): Collection
    {
        $actorUserId = isset($context['actor_user_id']) ? (string) $context['actor_user_id'] : null;
        $mineId = isset($context['mine_id']) ? (string) $context['mine_id'] : null;
        $priority = strtolower((string) ($context['priority'] ?? $type->default_priority ?? 'medium'));
        $category = strtolower((string) ($context['category'] ?? $type->category ?? 'operacion'));
        $onlyUserIds = collect($context['target_user_ids'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->unique()->values();

        $query = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos']);

        if (Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'estado')) {
            $query->where('estado', 'ACTIVO');
        }

        if ($onlyUserIds->isNotEmpty()) {
            $query->whereIn('id', $onlyUserIds->all());
        }

        $requiredActions = collect($context['permission_actions'] ?? [])
            ->map(fn ($action) => strtolower(trim((string) $action)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $requiredModule = $context['permission_module'] ?? $type->required_permission_module;
        $contextHasExplicitAction = array_key_exists('permission_action', $context);
        $requiredAction = $contextHasExplicitAction
            ? strtolower(trim((string) ($context['permission_action'] ?? '')))
            : (!empty($requiredActions) ? null : strtolower((string) ($type->required_permission_action ?? '')));

        if ($requiredAction === '') {
            $requiredAction = null;
        }

        $requirePermission = filter_var($context['require_permission'] ?? true, FILTER_VALIDATE_BOOL);

        $users = $query->get();
        $userIds = $users->pluck('id')->map(fn ($id) => (string) $id)->values();

        $scopeUserIds = collect();
        if ($mineId !== '' && $mineId !== null && Schema::hasTable('usuario_mina_scope')) {
            $scopeQuery = DB::table('usuario_mina_scope')
                ->where('mina_id', $mineId);

            if ($userIds->isNotEmpty()) {
                $scopeQuery->whereIn('usuario_id', $userIds->all());
            }

            $scopeUserIds = $scopeQuery
                ->pluck('usuario_id')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();
        }

        $preferences = $this->loadPreferencesByUser((string) $type->id, $userIds);

        $users = $users->filter(function (Usuario $user) use ($actorUserId, $mineId, $requiredModule, $requiredAction, $requiredActions, $priority, $category, $requirePermission, $scopeUserIds, $preferences): bool {
            $userId = (string) $user->id;

            if ($actorUserId && $user->id === $actorUserId) {
                return false;
            }

            $preference = $preferences->get($userId);
            if ($preference instanceof NotificationPreference && !$this->matchesPreference($preference, $priority)) {
                return false;
            }

            $roleNames = $this->roleNamesForUser($user);
            $isGlobalRole = collect($roleNames)->contains(fn (string $name) => in_array($name, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true));

            if ($isGlobalRole && $this->shouldDeliverToGlobalRole($priority, $category)) {
                return true;
            }

            if ($mineId) {
                $hasMineScope = $scopeUserIds->contains($userId);

                if ($hasMineScope) {
                    if (!$requirePermission) {
                        Log::info('notificaciones.user_mina_access_check', [
                            'actor_usuario_id' => $actorUserId,
                            'usuario_id' => $userId,
                            'mina_id' => (string) $mineId,
                            'scope_minas' => [(string) $mineId],
                            'has_access' => true,
                            'reason' => 'mine_scope_without_permission_requirement',
                        ]);

                        return true;
                    }

                    if ($requiredModule && ($requiredAction || !empty($requiredActions))) {
                        $hasPermission = $this->matchesRequiredPermission($user, (string) $requiredModule, $requiredAction, $requiredActions);

                        Log::info('notificaciones.user_mina_access_check', [
                            'actor_usuario_id' => $actorUserId,
                            'usuario_id' => $userId,
                            'mina_id' => (string) $mineId,
                            'scope_minas' => [(string) $mineId],
                            'has_access' => $hasPermission,
                            'required_module' => $requiredModule,
                            'required_action' => $requiredAction,
                            'required_actions' => $requiredActions,
                            'reason' => $hasPermission ? 'mine_scope_and_permission_granted' : 'mine_scope_but_permission_denied',
                        ]);

                        if ($hasPermission) {
                            return true;
                        }
                    }

                    if (!$requirePermission) {
                        Log::info('notificaciones.user_mina_access_check', [
                            'actor_usuario_id' => $actorUserId,
                            'usuario_id' => $userId,
                            'mina_id' => (string) $mineId,
                            'scope_minas' => [(string) $mineId],
                            'has_access' => true,
                            'reason' => 'mine_scope_without_permission_requirement',
                        ]);

                        return true;
                    }
                }

                if ($isGlobalRole) {
                    $allowedGlobal = $this->shouldDeliverToGlobalRole($priority, $category);

                    Log::info('notificaciones.user_mina_access_check', [
                        'actor_usuario_id' => $actorUserId,
                        'usuario_id' => $userId,
                        'mina_id' => (string) $mineId,
                        'scope_minas' => [],
                        'has_access' => $allowedGlobal,
                        'reason' => $allowedGlobal ? 'global_role_delivery' : 'global_role_filtered_by_priority_category',
                    ]);

                    return $allowedGlobal;
                }

                Log::info('notificaciones.user_mina_access_check', [
                    'actor_usuario_id' => $actorUserId,
                    'usuario_id' => $userId,
                    'mina_id' => (string) $mineId,
                    'scope_minas' => [],
                    'has_access' => false,
                    'reason' => 'no_mine_scope',
                ]);

                return false;
            }

            if ($requiredModule && ($requiredAction || !empty($requiredActions)) && $requirePermission) {
                $hasPermission = $this->matchesRequiredPermission($user, (string) $requiredModule, $requiredAction, $requiredActions);

                if (!$hasPermission) {
                    return false;
                }
            }

            return true;
        });

        $recipientIds = $users->pluck('id')->unique()->values();

        Log::info('notificaciones.recipient_resolver_summary', [
            'notification_type' => (string) $type->code,
            'actor_usuario_id' => $actorUserId,
            'mina_id' => $mineId,
            'required_module' => $requiredModule,
            'required_action' => $requiredAction,
            'required_actions' => $requiredActions,
            'require_permission' => (bool) $requirePermission,
            'recipient_count' => $recipientIds->count(),
            'recipient_user_ids' => $recipientIds->all(),
        ]);

        return $recipientIds;
    }

    private function shouldDeliverToGlobalRole(string $priority, string $category): bool
    {
        if (in_array($priority, ['critical', 'high'], true)) {
            return true;
        }

        return in_array($category, ['seguridad', 'sistema'], true);
    }

    private function matchesRequiredPermission(Usuario $user, string $requiredModule, ?string $requiredAction, array $requiredActions): bool
    {
        $permissions = PermissionMatrix::effectivePermissions($user);

        if (!empty($requiredActions)) {
            return PermissionMatrix::allowsAny($permissions, $requiredModule, $requiredActions);
        }

        if ($requiredAction) {
            return PermissionMatrix::allows($permissions, $requiredModule, $requiredAction);
        }

        return true;
    }

    private function roleNamesForUser(Usuario $user): array
    {
        $names = [];

        if ($user->rol?->nombre) {
            $names[] = strtoupper((string) $user->rol->nombre);
        }

        if ($user->relationLoaded('rolesAdicionales')) {
            foreach ($user->rolesAdicionales as $rol) {
                if (!empty($rol->nombre)) {
                    $names[] = strtoupper((string) $rol->nombre);
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function loadPreferencesByUser(string $notificationTypeId, Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || !Schema::hasTable('notification_preferences')) {
            return collect();
        }

        return NotificationPreference::query()
            ->where('notification_type_id', $notificationTypeId)
            ->whereIn('usuario_id', $userIds->all())
            ->get(['usuario_id', 'in_app_enabled', 'minimum_priority', 'mute_until'])
            ->keyBy(fn (NotificationPreference $preference) => (string) $preference->usuario_id);
    }

    private function matchesPreference(NotificationPreference $preference, string $priority): bool
    {
        if (!$preference->in_app_enabled) {
            return false;
        }

        if ($preference->mute_until && now()->lt($preference->mute_until)) {
            return false;
        }

        $minimumPriority = strtolower((string) ($preference->minimum_priority ?? 'low'));

        return $this->priorityRank($priority) >= $this->priorityRank($minimumPriority);
    }

    private function priorityRank(string $priority): int
    {
        return match (strtolower($priority)) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
