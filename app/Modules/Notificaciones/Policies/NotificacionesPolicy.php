<?php

namespace App\Modules\Notificaciones\Policies;

use App\Models\NotificationType;
use App\Models\NotificationUserSetting;
use App\Models\RQMina;
use App\Models\RQProserge;
use App\Models\Usuario;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use App\Modules\RQProserge\Policies\RQProsergePolicy;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class NotificacionesPolicy
{
    public function canUse(Usuario $user, string $action = 'ver'): bool
    {
        if ($user->estado !== 'ACTIVO' || PermissionMatrix::activeRoleNames($user) === []
            || ! PermissionMatrix::userCanDirect($user, 'notificaciones', $action)) {
            return false;
        }

        // Match Web administration access; delivery still honors every preference.
        if (array_intersect(PermissionMatrix::activeRoleNames($user), ['ADMIN', 'SUPERADMIN']) !== []) {
            return true;
        }

        if (! Schema::hasTable('notification_user_settings')) {
            return true;
        }

        $setting = NotificationUserSetting::query()->where('usuario_id', $user->id)->first();

        return ! $setting || ($setting->in_app_enabled
            && (! $setting->muted_until || now()->gte($setting->muted_until)));
    }

    public function canViewType(Usuario $user, NotificationType $type): bool
    {
        $module = $this->permissionModule($type);
        $action = trim((string) $type->required_permission_action) ?: 'ver';

        if ($type->code === 'lista_herramientas_por_vencer'
            && ! PermissionMatrix::userCanDirect($user, 'man_power', 'ver')) {
            return false;
        }

        return $module !== '' && PermissionMatrix::userCanDirect($user, $module, $action);
    }

    public function contextRequirement(NotificationType $type, array $context): ?array
    {
        $actions = collect($context['permission_actions'] ?? [])
            ->map(fn ($action) => strtolower(trim((string) $action)))->filter()->unique()->values()->all();
        if ($actions === [] && ! empty($context['permission_action'])) {
            $actions = [strtolower(trim((string) $context['permission_action']))];
        }
        if ($actions === [] && ! empty($context['permission_module'])) {
            $actions = [trim((string) $type->required_permission_action) ?: 'ver'];
        }

        return $actions === [] ? null : [
            'permission_module' => trim((string) ($context['permission_module'] ?? $this->permissionModule($type))),
            'permission_actions' => $actions,
        ];
    }

    public function restrictContextPermissions(Builder $events, Usuario $user, string $scopeModule): void
    {
        $permissions = PermissionMatrix::effectivePermissions($user);
        $events->where(function (Builder $allowed) use ($permissions, $user, $scopeModule): void {
            $allowed->whereNull('payload->_notification_access');
            foreach ($permissions as $module => $actions) {
                $granted = array_keys(array_filter($actions, fn ($value) => $value === true));
                if ($granted === []) {
                    continue;
                }
                $allowed->orWhere(function (Builder $context) use ($module, $granted, $user, $scopeModule): void {
                    $context->where('payload->_notification_access->permission_module', $module)
                        ->where(function (Builder $any) use ($granted): void {
                            foreach ($granted as $action) {
                                $any->orWhereJsonContains('payload->_notification_access->permission_actions', $action);
                            }
                        });
                    if (! $this->requiresMinaScope($scopeModule)) {
                        $this->restrictMinaScope($context, $user, $module);
                    }
                });
            }
        });
    }

    public function resolveMineId(array $context): string
    {
        $mineId = trim((string) ($context['mine_id'] ?? ''));
        if ($mineId !== '') {
            return $mineId;
        }

        $rqId = $this->resolveParadaId($context);
        if ($rqId !== '') {
            return (string) RQMina::query()->whereKey($rqId)->value('mina_id');
        }

        return '';
    }

    public function resolveParadaId(array $context): string
    {
        $rqId = (string) ($context['payload']['rq_mina_id'] ?? '');
        if (in_array($context['entity_type'] ?? null, ['rq_mina', RQMina::class], true)) {
            $rqId = (string) ($context['entity_id'] ?? $rqId);
        }
        if ($rqId !== '') {
            return $rqId;
        }
        if (in_array($context['entity_type'] ?? null, ['rq_proserge', RQProserge::class], true)) {
            return (string) RQProserge::query()->whereKey($context['entity_id'] ?? '')->value('rq_mina_id');
        }

        return '';
    }

    public function permissionModule(NotificationType $type): string
    {
        return trim((string) $type->required_permission_module) ?: trim((string) $type->module);
    }

    public function scopeModule(NotificationType $type): string
    {
        return $type->code === 'lista_herramientas_por_vencer' ? 'herramientas' : $this->permissionModule($type);
    }

    public function canAccessParada(Usuario $user, string $paradaId): bool
    {
        return $paradaId !== '' && app(ParadaHerramientaService::class)->accessibleParadasQuery($user)->whereKey($paradaId)->exists();
    }

    public function requiresMinaScope(string $module): bool
    {
        return in_array($module, ['rq_mina', 'rq_proserge', 'man_power', 'asistencias', 'faltas', 'herramientas', 'epps', 'transportes'], true);
    }

    public function hasUnrestrictedMinaAccess(Usuario $user, string $module): bool
    {
        if ($module === 'rq_proserge') {
            return app(RQProsergePolicy::class)->hasUnrestrictedMinaAccess($user);
        }

        return MinaScopeAccess::isPrivileged($user)
            || (in_array($module, ['rq_mina', 'man_power', 'asistencias', 'faltas', 'herramientas'], true)
                && PermissionMatrix::userCanDirect($user, $module, 'administrar'));
    }

    public function restrictMinaScope(Builder $events, Usuario $user, string $module): void
    {
        if (! $this->requiresMinaScope($module) || $this->hasUnrestrictedMinaAccess($user, $module)) {
            return;
        }

        if ($module === 'herramientas') {
            $paradas = app(ParadaHerramientaService::class)->accessibleParadasQuery($user)->selectRaw('1');
            $this->matchParadaReference($paradas, 'rq_mina');
            $events->whereExists($paradas->toBase());

            return;
        }

        $events->where(function (Builder $query) use ($user): void {
            $query->whereIn('mina_id', $user->scopesMina()->select('mina_id'))
                ->orWhere(function (Builder $legacy) use ($user): void {
                    $legacy->whereNull('mina_id')->whereExists(function (QueryBuilder $rq) use ($user): void {
                        $rq->selectRaw('1')->from('rq_mina as notification_rq')
                            ->whereIn('notification_rq.mina_id', $user->scopesMina()->select('mina_id'));
                        $this->matchParadaReference($rq, 'notification_rq');
                    });
                });
        });
    }

    private function matchParadaReference(Builder|QueryBuilder $query, string $table): void
    {
        $query->where(function ($reference) use ($table): void {
            $reference->where(function ($entity) use ($table): void {
                $entity->whereIn('notification_events.entity_type', ['rq_mina', RQMina::class])
                    ->whereColumn($table.'.id', 'notification_events.entity_id');
            })->orWhere(function ($payload) use ($table): void {
                $payload->where(function ($unknownEntity): void {
                    $unknownEntity->whereNull('notification_events.entity_type')
                        ->orWhereNotIn('notification_events.entity_type', ['rq_mina', RQMina::class]);
                })->whereRaw($table.".id = JSON_UNQUOTE(JSON_EXTRACT(notification_events.payload, '$.rq_mina_id'))");
            })->orWhere(function ($proserge) use ($table): void {
                $proserge->whereIn('notification_events.entity_type', ['rq_proserge', RQProserge::class])
                    ->whereNull('notification_events.payload->rq_mina_id')
                    ->whereExists(function (QueryBuilder $rq) use ($table): void {
                        $rq->selectRaw('1')->from('rq_proserge as notification_rqp')
                            ->whereColumn('notification_rqp.id', 'notification_events.entity_id')
                            ->whereColumn('notification_rqp.rq_mina_id', $table.'.id');
                    });
            });
        });
    }
}
