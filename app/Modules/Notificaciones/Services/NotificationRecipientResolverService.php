<?php

namespace App\Modules\Notificaciones\Services;

use App\Models\NotificationPreference;
use App\Models\NotificationRolePreference;
use App\Models\NotificationType;
use App\Models\NotificationUserSetting;
use App\Models\Usuario;
use App\Modules\Notificaciones\Policies\NotificacionesPolicy;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationRecipientResolverService
{
    public function __construct(private readonly NotificacionesPolicy $policy) {}

    public function resolve(NotificationType $type, array $context = []): Collection
    {
        $onlyUserIds = collect($context['target_user_ids'] ?? [])
            ->map(fn ($id) => trim((string) $id))->filter()->unique()->values();

        if (! $type->is_active || (array_key_exists('target_user_ids', $context) && $onlyUserIds->isEmpty())) {
            return collect();
        }

        $actorUserId = (string) ($context['actor_user_id'] ?? '');
        $mineId = $this->policy->resolveMineId($context);
        $priority = strtolower((string) ($context['priority'] ?? $type->default_priority ?? 'medium'));
        $module = $this->policy->permissionModule($type);
        $requirement = $this->policy->contextRequirement($type, $context);
        $scopeModule = $this->policy->scopeModule($type);
        if (! $this->policy->requiresMinaScope($scopeModule)) {
            $scopeModule = $requirement['permission_module'] ?? $scopeModule;
        }
        $paradaId = $scopeModule === 'herramientas' ? $this->policy->resolveParadaId($context) : '';
        $query = Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO');

        if ($onlyUserIds->isNotEmpty()) {
            $query->whereIn('id', $onlyUserIds);
        }

        $users = $query->get();
        $userIds = $users->pluck('id');
        $roleIds = $users->flatMap(fn (Usuario $user) => $this->roleIdsForUser($user))->unique()->values();
        $preferences = $this->loadPreferencesByUser((string) $type->id, $userIds);
        $userSettings = $this->loadSettingsByUser($userIds);
        $rolePreferences = $this->loadRolePreferences((string) $type->id, $roleIds);
        $scopeUserIds = collect();

        if ($mineId !== '' && $userIds->isNotEmpty()
            && ($this->policy->requiresMinaScope($module) || $this->policy->requiresMinaScope($requirement['permission_module'] ?? ''))) {
            $scopeUserIds = DB::table('usuario_mina_scope')->where('mina_id', $mineId)
                ->whereIn('usuario_id', $userIds)->pluck('usuario_id');
        }

        $recipientIds = $users->filter(function (Usuario $user) use ($type, $requirement, $scopeModule, $paradaId, $actorUserId, $mineId, $priority, $preferences, $userSettings, $rolePreferences, $scopeUserIds): bool {
            $userId = (string) $user->id;
            if ($actorUserId === $userId || PermissionMatrix::activeRoleNames($user) === []) {
                return false;
            }

            $setting = $userSettings->get($userId);
            if ($setting && (! $setting->in_app_enabled || ($setting->muted_until && now()->lt($setting->muted_until)))) {
                return false;
            }

            if ($this->rolePreferenceDecision($user, $rolePreferences) === false) {
                return false;
            }

            $preference = $preferences->get($userId);
            if ($preference && ! $this->matchesPreference($preference, $priority)) {
                return false;
            }

            if (! PermissionMatrix::userCanDirect($user, 'notificaciones', 'ver') || ! $this->policy->canViewType($user, $type)) {
                return false;
            }

            // Context may narrow the audience, never bypass the type's permission.
            if ($requirement && ! PermissionMatrix::userCanDirectAny($user, $requirement['permission_module'], $requirement['permission_actions'])) {
                return false;
            }

            if ($scopeModule === 'herramientas') {
                return $this->policy->canAccessParada($user, $paradaId);
            }

            return ! $this->policy->requiresMinaScope($scopeModule)
                || $this->policy->hasUnrestrictedMinaAccess($user, $scopeModule)
                || ($mineId !== '' && $scopeUserIds->contains($userId));
        })->pluck('id')->unique()->values();

        Log::info('notificaciones.recipient_resolver_summary', [
            'notification_type' => (string) $type->code,
            'actor_usuario_id' => $actorUserId,
            'mina_id' => $mineId,
            'required_module' => $module,
            'recipient_count' => $recipientIds->count(),
        ]);

        return $recipientIds;
    }

    private function roleIdsForUser(Usuario $user): array
    {
        return collect([$user->rol])->merge($user->rolesAdicionales)->filter()
            ->where('estado', 'ACTIVO')->pluck('id')->unique()->values()->all();
    }

    private function loadPreferencesByUser(string $notificationTypeId, Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('notification_preferences')) {
            return collect();
        }

        return NotificationPreference::query()->where('notification_type_id', $notificationTypeId)
            ->whereIn('usuario_id', $userIds)->get(['usuario_id', 'in_app_enabled', 'minimum_priority', 'mute_until'])
            ->keyBy('usuario_id');
    }

    private function loadSettingsByUser(Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('notification_user_settings')) {
            return collect();
        }

        return NotificationUserSetting::query()->whereIn('usuario_id', $userIds)
            ->get(['usuario_id', 'in_app_enabled', 'muted_until'])->keyBy('usuario_id');
    }

    private function loadRolePreferences(string $notificationTypeId, Collection $roleIds): Collection
    {
        if ($roleIds->isEmpty() || ! Schema::hasTable('notification_role_preferences')) {
            return collect();
        }

        return NotificationRolePreference::query()->where('notification_type_id', $notificationTypeId)
            ->whereIn('rol_id', $roleIds)->get(['rol_id', 'is_enabled'])->keyBy('rol_id');
    }

    private function rolePreferenceDecision(Usuario $user, Collection $rolePreferences): ?bool
    {
        $hasExplicit = false;
        foreach ($this->roleIdsForUser($user) as $roleId) {
            $preference = $rolePreferences->get($roleId);
            if (! $preference) {
                continue;
            }
            $hasExplicit = true;
            if ($preference->is_enabled) {
                return true;
            }
        }

        return $hasExplicit ? false : null;
    }

    private function matchesPreference(NotificationPreference $preference, string $priority): bool
    {
        if (! $preference->in_app_enabled || ($preference->mute_until && now()->lt($preference->mute_until))) {
            return false;
        }

        return $this->priorityRank($priority) >= $this->priorityRank((string) ($preference->minimum_priority ?? 'low'));
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
