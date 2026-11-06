<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Mina;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Models\NotificationRolePreference;
use App\Models\NotificationType;
use App\Models\NotificationUserSetting;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\RQMina;
use App\Models\RQProserge;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationInboxService;
use App\Modules\Notificaciones\Services\NotificationRecipientResolverService;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_gerente_does_not_receive_critical_notifications_without_domain_permission(): void
    {
        $role = $this->role(['notificaciones' => ['ver']], 'GERENTE');
        $user = $this->user($role);

        $this->assertNotContains($user->id, $this->resolve($this->type(), ['priority' => 'critical']));
    }

    public function test_inactive_admin_role_cannot_grant_delivery_through_its_name(): void
    {
        $role = $this->role([], 'ADMIN', 'INACTIVO');
        $user = $this->user($role);

        $this->assertNotContains($user->id, $this->resolve($this->type(), ['priority' => 'critical']));
    }

    public function test_delivery_requires_notifications_view_and_domain_permission_even_for_explicit_targets(): void
    {
        $domainOnly = $this->user($this->role(['usuarios' => ['ver']]));
        $notificationsOnly = $this->user($this->role(['notificaciones' => ['ver']]));

        $ids = $this->resolve($this->type(), [
            'target_user_ids' => [$domainOnly->id, $notificationsOnly->id],
            'require_permission' => false,
        ]);

        $this->assertSame([], $ids);
    }

    public function test_explicit_empty_targets_never_become_a_broadcast(): void
    {
        $this->user($this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]));
        $this->assertSame([], $this->resolve($this->type(), ['target_user_ids' => [], 'require_permission' => false]));
    }

    public function test_context_permission_module_can_only_narrow_the_declared_permission(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]));
        $type = $this->type();
        $this->assertNotContains($user->id, $this->resolve($type, ['permission_module' => 'roles']));

        $this->attachRole($user, $this->role(['roles' => ['ver']]));
        $this->assertContains($user->id, $this->resolve($type, ['permission_module' => 'roles']));
        $this->assertNotContains($user->id, $this->resolve($type, ['permission_actions' => ['crear', 'eliminar']]));
    }

    public function test_inactive_user_never_receives_even_with_explicit_target_and_preferences(): void
    {
        $user = $this->user($this->role([], 'ADMIN'));
        $user->update(['estado' => 'INACTIVO']);
        $this->assertSame([], $this->resolve($this->type(), ['target_user_ids' => [$user->id]]));
    }

    public function test_additional_inactive_role_cannot_enable_a_disabled_type(): void
    {
        $active = $this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]);
        $inactive = $this->role([], null, 'INACTIVO');
        $user = $this->user($active);
        $this->attachRole($user, $inactive);
        $type = $this->type();
        $this->rolePreference($active, $type, false);
        $this->rolePreference($inactive, $type, true);

        $this->assertNotContains($user->id, $this->resolve($type));
    }

    public function test_additional_inactive_role_cannot_disable_an_enabled_type(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]));
        $inactive = $this->role([], null, 'INACTIVO');
        $this->attachRole($user, $inactive);
        $type = $this->type();
        $this->rolePreference($inactive, $type, false);

        $this->assertContains($user->id, $this->resolve($type));
    }

    public function test_active_additional_roles_combine_permissions_without_self_notification(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver']]));
        $this->attachRole($user, $this->role(['usuarios' => ['ver']]));
        $type = $this->type();

        $this->assertContains($user->id, $this->resolve($type));
        $this->assertNotContains($user->id, $this->resolve($type, ['actor_user_id' => $user->id]));
        $type->update(['is_active' => false]);
        $this->assertSame([], $this->resolve($type->fresh()));
    }

    public function test_critical_notifications_respect_admin_mute_and_type_preferences(): void
    {
        $user = $this->user($this->role([], 'ADMIN'));
        $type = $this->type();
        NotificationUserSetting::query()->create([
            'id' => (string) Str::uuid(), 'usuario_id' => $user->id,
            'in_app_enabled' => true, 'muted_until' => now()->addHour(),
        ]);
        $this->assertNotContains($user->id, $this->resolve($type, ['priority' => 'critical']));
        NotificationUserSetting::query()->where('usuario_id', $user->id)->update(['muted_until' => null]);
        NotificationPreference::query()->create([
            'id' => (string) Str::uuid(), 'usuario_id' => $user->id,
            'notification_type_id' => $type->id, 'in_app_enabled' => false,
        ]);
        $this->assertNotContains($user->id, $this->resolve($type, ['priority' => 'critical']));
    }

    public function test_habilitacion_mine_is_context_not_an_operational_scope_requirement(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'personal' => ['actualizar']]));
        $type = $this->type(['module' => 'personal', 'required_permission_module' => 'personal', 'required_permission_action' => 'actualizar']);
        $mine = $this->mine();

        $this->assertContains($user->id, $this->resolve($type, ['mine_id' => $mine->id]));
    }

    public function test_rq_proserge_respects_explicit_scope_even_for_gerente(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'rq_proserge' => ['ver']], 'GERENTE'));
        $allowed = $this->mine();
        $denied = $this->mine();
        $this->scope($user, $allowed);
        $type = $this->type(['module' => 'rq_proserge', 'required_permission_module' => 'rq_proserge']);

        $this->assertNotContains($user->id, $this->resolve($type, ['mine_id' => $denied->id, 'priority' => 'high']));
        $this->assertContains($user->id, $this->resolve($type, ['mine_id' => $allowed->id]));
    }

    #[DataProvider('apiActions')]
    public function test_api_requires_the_corresponding_notification_permission(string $method, string $path): void
    {
        $user = $this->user($this->role(['usuarios' => ['ver']]));
        $recipient = $this->recipient($user, $this->type());

        $this->withToken($this->token($user))->json($method, str_replace('{id}', $recipient->id, $path))
            ->assertForbidden()->assertJsonPath('code', 'NOTIFICATIONS_FORBIDDEN');
        $this->assertSame('UNREAD', $recipient->fresh()->status);
    }

    #[DataProvider('apiWrites')]
    public function test_api_view_permission_does_not_allow_writes(string $method, string $path): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]));
        $recipient = $this->recipient($user, $this->type());

        $this->withToken($this->token($user))->json($method, str_replace('{id}', $recipient->id, $path))->assertForbidden();
        $this->assertSame('UNREAD', $recipient->fresh()->status);
    }

    #[DataProvider('mutedSettings')]
    public function test_api_respects_general_disable_and_mute(array $settings): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver']]));
        $recipient = $this->recipient($user, $this->type());
        NotificationUserSetting::query()->create([
            'id' => (string) Str::uuid(), 'usuario_id' => $user->id,
            ...$settings, 'muted_until' => ! empty($settings['muted_until']) ? now()->addHour() : null,
        ]);
        $this->withToken($this->token($user));

        $this->getJson('/api/v1/notificaciones')->assertForbidden();
        $this->postJson('/api/v1/notificaciones/'.$recipient->id.'/leer')->assertForbidden();
        $this->assertSame('UNREAD', $recipient->fresh()->status);
    }

    public function test_revoked_domain_access_hides_existing_notification_everywhere_without_deleting_it(): void
    {
        $role = $this->role(['notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver']]);
        $user = $this->user($role);
        $recipient = $this->recipient($user, $this->type());
        $this->withToken($this->token($user));
        $this->getJson('/api/v1/notificaciones')->assertOk()->assertJsonPath('data.meta.total', 1);

        $role->update(['permisos' => PermissionCatalog::matrixFromSelections(['notificaciones' => ['ver', 'actualizar']])]);

        $this->getJson('/api/v1/notificaciones')->assertOk()->assertJsonPath('data.meta.total', 0)->assertJsonPath('data.items', []);
        $this->getJson('/api/v1/notificaciones/no-leidas/count')->assertOk()->assertJsonPath('data.count', 0);
        $this->postJson('/api/v1/notificaciones/'.$recipient->id.'/leer')->assertNotFound();
        $this->postJson('/api/v1/notificaciones/marcar-todas-leidas')->assertOk()->assertJsonPath('data.updated', 0);

        $this->withSession($this->sessionFor($user));
        $this->getJson(route('notificaciones.poll'))->assertOk()->assertJsonPath('count', 0)->assertJsonPath('items', []);
        $this->get(route('notificaciones.action', $recipient->id))->assertNotFound();
        $this->postJson(route('notificaciones.mark-read', $recipient->id))->assertNotFound();
        $this->assertSame('UNREAD', $recipient->fresh()->status);
        $this->assertDatabaseHas('notification_recipients', ['id' => $recipient->id]);
    }

    public function test_revoked_scope_filters_before_pagination_and_bulk_read(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver', 'actualizar'], 'rq_proserge' => ['ver'], 'usuarios' => ['ver']]));
        $mine = $this->mine();
        $this->scope($user, $mine);
        $type = $this->type(['module' => 'rq_proserge', 'required_permission_module' => 'rq_proserge']);
        $hidden = $this->recipient($user, $type, $mine);
        $visible = $this->recipient($user, $this->type(['code' => 'visible_'.Str::random(8)]));
        $this->assertSame(2, app(NotificationInboxService::class)->unreadCount($user->id));

        $user->scopesMina()->delete();
        $inbox = app(NotificationInboxService::class);
        $page = $inbox->listForUser($user->id, [], 1);
        $this->assertSame(1, $page->total());
        $this->assertSame($visible->id, $page->items()[0]->id);
        $this->assertSame(1, $inbox->unreadCount($user->id));
        $this->assertSame([$visible->id], $inbox->latestForHeader($user->id)->pluck('id')->all());
        $this->assertSame(1, $inbox->markAllAsRead($user->id));
        $this->assertSame('UNREAD', $hidden->fresh()->status);

        $this->withToken($this->token($user));
        $this->postJson('/api/v1/notificaciones/'.$hidden->id.'/leer')->assertNotFound();
        $this->withSession($this->sessionFor($user));
        $this->get(route('notificaciones.action', $hidden->id))->assertNotFound();
        $this->postJson(route('notificaciones.archive', $hidden->id))->assertNotFound();
    }

    public function test_opening_an_authorized_action_does_not_mutate_read_or_action_state(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]));
        $recipient = $this->recipient($user, $this->type());
        $this->withSession($this->sessionFor($user));

        $this->get(route('notificaciones.action', $recipient->id))->assertRedirect('/usuarios');
        $recipient->refresh();
        $this->assertSame('UNREAD', $recipient->status);
        $this->assertNull($recipient->read_at);
        $this->assertNull($recipient->actioned_at);
        $this->postJson(route('notificaciones.mark-read', $recipient->id))->assertForbidden();
    }

    public function test_api_preserves_explicit_update_without_granting_read_permission(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['actualizar'], 'usuarios' => ['ver']]));
        $recipient = $this->recipient($user, $this->type());
        $this->withToken($this->token($user));

        $this->getJson('/api/v1/notificaciones')->assertForbidden();
        $this->postJson('/api/v1/notificaciones/'.$recipient->id.'/leer')->assertOk()->assertJsonMissingPath('data.event');
        $this->assertSame('READ', $recipient->fresh()->status);

        $this->postJson('/api/v1/notificaciones/'.$recipient->id.'/archivar')->assertOk()->assertJsonMissingPath('data.event');
    }

    public function test_api_allows_authorized_archive_and_rechecks_deactivated_roles(): void
    {
        $role = $this->role(['notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver']]);
        $user = $this->user($role);
        $recipient = $this->recipient($user, $this->type());
        $this->withToken($this->token($user));

        $this->postJson('/api/v1/notificaciones/'.$recipient->id.'/archivar')->assertOk()->assertJsonPath('data.event.id', $recipient->notification_event_id);
        $this->assertSame('ARCHIVED', $recipient->fresh()->status);
        $role->update(['estado' => 'INACTIVO']);
        $this->getJson('/api/v1/notificaciones')->assertForbidden();
        $this->postJson('/api/v1/notificaciones/'.$recipient->id.'/leer')->assertForbidden();
    }

    public function test_user_cannot_access_another_recipients_copy_and_expired_copies_are_not_mutated(): void
    {
        $role = $this->role(['notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver']]);
        $user = $this->user($role);
        $other = $this->user($role);
        $type = $this->type();
        $foreign = $this->recipient($other, $type);
        $expired = $this->recipient($user, $type);
        $expired->event->update(['expires_at' => now()->subMinute()]);
        $this->withToken($this->token($user));

        $this->postJson('/api/v1/notificaciones/'.$foreign->id.'/leer')->assertNotFound();
        $this->postJson('/api/v1/notificaciones/'.$expired->id.'/archivar')->assertNotFound();
        $this->postJson('/api/v1/notificaciones/marcar-todas-leidas')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertSame('UNREAD', $foreign->fresh()->status);
        $this->assertSame('UNREAD', $expired->fresh()->status);
    }

    public function test_context_requirement_is_persisted_and_rechecked_before_reading_or_mutating(): void
    {
        $role = $this->role(['notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver'], 'roles' => ['ver']]);
        $user = $this->user($role);
        $type = $this->type();
        $event = app(NotificationService::class)->emit($type->code, [
            'target_user_ids' => [$user->id], 'message' => 'Aviso con requisito adicional',
            'permission_module' => 'roles', 'permission_actions' => ['ver'],
            'payload' => ['dato' => 'conservado'],
        ]);
        $this->assertNotNull($event);
        $this->assertSame('conservado', $event->payload['dato']);
        $recipient = $event->recipients()->where('usuario_id', $user->id)->firstOrFail();
        $inbox = app(NotificationInboxService::class);
        $this->assertSame(1, $inbox->unreadCount($user->id));

        $role->update(['permisos' => PermissionCatalog::matrixFromSelections([
            'notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver'],
        ])]);
        $this->assertSame(0, $inbox->listForUser($user->id, [], 1)->total());
        $this->assertSame(0, $inbox->unreadCount($user->id));
        $this->assertCount(0, $inbox->latestForHeader($user->id));
        $this->assertSame(0, $inbox->markAllAsRead($user->id));
        $this->assertNull($inbox->findForUser($user->id, $recipient->id));
        $this->assertSame('UNREAD', $recipient->fresh()->status);
    }

    #[DataProvider('broadInboxRoles')]
    public function test_broad_inbox_permissions_fit_a_one_megabyte_database_packet(string $roleName): void
    {
        $role = $this->role([], $roleName);
        if ($roleName === 'NOTIF_FULL_CUSTOM') {
            $role->update(['permisos' => PermissionCatalog::fullAccessMatrix()]);
        }
        $user = $this->user($role);
        foreach (['usuarios', 'roles', 'personal', 'evaluaciones', 'rq_mina', 'rq_proserge', 'man_power', 'epps', 'herramientas', 'logistica'] as $module) {
            $this->type(['module' => $module, 'required_permission_module' => $module]);
        }
        $type = $this->type();
        $allowed = $this->recipient($user, $type);
        $allowed->event->update(['payload' => ['_notification_access' => [
            'permission_module' => 'usuarios', 'permission_actions' => ['ver'],
        ]]]);
        $unknownModule = $this->recipient($user, $type);
        $unknownModule->event->update(['payload' => ['_notification_access' => [
            'permission_module' => 'unknown_module', 'permission_actions' => ['ver'],
        ]]]);
        $unknownAction = $this->recipient($user, $type);
        $unknownAction->event->update(['payload' => ['_notification_access' => [
            'permission_module' => 'usuarios', 'permission_actions' => ['unknown_action'],
        ]]]);
        $packetSizes = [];
        DB::listen(static function ($query) use (&$packetSizes): void {
            if (str_contains($query->sql, 'notification_recipients')) {
                $packetSizes[] = strlen($query->sql) + strlen(json_encode($query->bindings));
            }
        });

        $inbox = app(NotificationInboxService::class);
        try {
            $this->assertSame(1, $inbox->unreadCount($user->id));
            $this->assertSame([$allowed->id], $inbox->latestForHeader($user->id)->modelKeys());
            $this->assertSame(1, $inbox->listForUser($user->id)->total());
        } catch (QueryException $exception) {
            $this->fail('Inbox query failed with database error '.($exception->errorInfo[1] ?? $exception->getCode()));
        }
        $this->assertNotEmpty($packetSizes);
        $this->assertLessThan(1048576, max($packetSizes), 'Inbox queries must fit servers with 1 MiB max_allowed_packet.');
    }

    public static function broadInboxRoles(): array
    {
        return [['ADMIN'], ['SUPERADMIN'], ['NOTIF_FULL_CUSTOM']];
    }

    public function test_legacy_tools_notifications_recheck_the_known_additional_permission(): void
    {
        $role = $this->role(['notificaciones' => ['ver', 'actualizar'], 'herramientas' => ['ver'], 'man_power' => ['ver']]);
        $user = $this->user($role);
        $mine = $this->mine();
        $this->scope($user, $mine);
        $type = NotificationType::query()->where('code', 'lista_herramientas_por_vencer')->firstOrFail();
        $recipient = $this->recipient($user, $type, $mine);
        $rq = $this->parada($user, $mine);
        $recipient->event->update(['entity_type' => 'rq_mina', 'entity_id' => $rq->id]);
        $inbox = app(NotificationInboxService::class);
        $this->assertSame(1, $inbox->unreadCount($user->id));

        $role->update(['permisos' => PermissionCatalog::matrixFromSelections([
            'notificaciones' => ['ver', 'actualizar'], 'herramientas' => ['ver'],
        ])]);
        $this->assertSame(0, $inbox->unreadCount($user->id));
        $this->assertNull($inbox->findForUser($user->id, $recipient->id));
    }

    public function test_legacy_tools_mine_is_resolved_from_the_parada_when_event_mine_is_null(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver', 'actualizar'], 'herramientas' => ['ver'], 'man_power' => ['ver']]));
        $mine = $this->mine();
        $this->scope($user, $mine);
        $rq = RQMina::query()->create([
            'id' => (string) Str::uuid(), 'mina_id' => $mine->id, 'destino_tipo' => 'MINA',
            'destino_id' => $mine->id, 'destino_nombre' => $mine->nombre,
            'area' => 'Prueba notificaciones', 'fecha_inicio' => now()->addWeek(),
            'fecha_fin' => now()->addWeeks(2), 'estado' => 'ENVIADO', 'created_by_usuario_id' => $user->id,
        ]);
        $type = NotificationType::query()->where('code', 'lista_herramientas_por_vencer')->firstOrFail();
        $recipient = $this->recipient($user, $type);
        $recipient->event->update(['entity_type' => 'rq_mina', 'entity_id' => $rq->id, 'payload' => ['rq_mina_id' => $rq->id]]);
        $inbox = app(NotificationInboxService::class);
        $this->assertSame(1, $inbox->unreadCount($user->id));

        $user->scopesMina()->delete();
        $this->assertSame(0, $inbox->unreadCount($user->id));
        $this->assertNull($inbox->findForUser($user->id, $recipient->id));
        $this->assertNotContains($user->id, $this->resolve($type, ['entity_type' => 'rq_mina', 'entity_id' => $rq->id, 'target_user_ids' => [$user->id]]));
    }

    public function test_context_operational_scope_is_rechecked_even_for_a_non_operational_type(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver', 'actualizar'], 'usuarios' => ['ver'], 'rq_proserge' => ['ver']]));
        $mine = $this->mine();
        $type = $this->type();
        $context = [
            'target_user_ids' => [$user->id], 'mine_id' => $mine->id,
            'permission_module' => 'rq_proserge', 'permission_action' => 'ver',
            'message' => 'Contexto operativo adicional',
        ];
        $this->assertNotContains($user->id, $this->resolve($type, $context));
        $this->scope($user, $mine);
        $event = app(NotificationService::class)->emit($type->code, $context);
        $this->assertNotNull($event);
        $inbox = app(NotificationInboxService::class);
        $this->assertSame(1, $inbox->unreadCount($user->id));
        $user->scopesMina()->delete();
        $this->assertSame(0, $inbox->unreadCount($user->id));
    }

    public function test_conflicting_legacy_payload_cannot_override_the_paradas_actual_mine(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'herramientas' => ['ver'], 'man_power' => ['ver']]));
        $allowedMine = $this->mine();
        $deniedMine = $this->mine();
        $this->scope($user, $allowedMine);
        $rqs = [];
        foreach ([$allowedMine, $deniedMine] as $mine) {
            $rqs[] = RQMina::query()->create([
                'id' => (string) Str::uuid(), 'mina_id' => $mine->id, 'destino_tipo' => 'MINA',
                'destino_id' => $mine->id, 'destino_nombre' => $mine->nombre,
                'area' => 'Prueba notificaciones', 'fecha_inicio' => now()->addWeek(),
                'fecha_fin' => now()->addWeeks(2), 'estado' => 'ENVIADO', 'created_by_usuario_id' => $user->id,
            ]);
        }
        $type = NotificationType::query()->where('code', 'lista_herramientas_por_vencer')->firstOrFail();
        $recipient = $this->recipient($user, $type);
        $recipient->event->update(['entity_type' => 'rq_mina', 'entity_id' => $rqs[1]->id, 'payload' => ['rq_mina_id' => $rqs[0]->id]]);
        $this->assertSame(0, app(NotificationInboxService::class)->unreadCount($user->id));
    }

    public function test_new_tools_event_stores_the_mine_inferred_from_its_parada(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'herramientas' => ['ver'], 'man_power' => ['ver']]));
        $mine = $this->mine();
        $this->scope($user, $mine);
        $rq = RQMina::query()->create([
            'id' => (string) Str::uuid(), 'mina_id' => $mine->id, 'destino_tipo' => 'MINA',
            'destino_id' => $mine->id, 'destino_nombre' => $mine->nombre,
            'area' => 'Prueba notificaciones', 'fecha_inicio' => now()->addWeek(),
            'fecha_fin' => now()->addWeeks(2), 'estado' => 'ENVIADO', 'created_by_usuario_id' => $user->id,
        ]);
        $event = app(NotificationService::class)->emit('lista_herramientas_por_vencer', [
            'entity_type' => 'rq_mina', 'entity_id' => $rq->id, 'message' => 'Recordatorio de lista',
            'target_user_ids' => [$user->id], 'permission_module' => 'man_power', 'permission_action' => 'ver',
            'payload' => ['rq_mina_id' => $rq->id],
        ]);
        $this->assertNotNull($event);
        $this->assertSame($mine->id, $event->mina_id);
        $this->assertSame('man_power', $event->payload['_notification_access']['permission_module']);
        $this->assertSame($rq->id, $event->payload['rq_mina_id']);
        $user->scopesMina()->delete();
        $this->assertSame(0, app(NotificationInboxService::class)->unreadCount($user->id));
    }

    public function test_tools_notification_respects_the_paradas_assigned_supervisor(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'herramientas' => ['ver'], 'man_power' => ['ver']]));
        $mine = $this->mine();
        $this->scope($user, $mine);
        $supervisor = $this->personal();
        $rq = $this->parada($user, $mine, $supervisor);
        $this->assertNull(app(ParadaHerramientaService::class)->findParadaForUser($user, $rq->id));
        $type = NotificationType::query()->where('code', 'lista_herramientas_por_vencer')->firstOrFail();
        $recipient = $this->recipient($user, $type, $mine);
        $context = ['entity_type' => 'rq_mina', 'entity_id' => $rq->id, 'mine_id' => $mine->id, 'target_user_ids' => [$user->id]];
        $recipient->event->update(['entity_type' => 'rq_mina', 'entity_id' => $rq->id]);
        $this->assertSame(0, app(NotificationInboxService::class)->unreadCount($user->id));
        $this->assertNotContains($user->id, $this->resolve($type, $context));
    }

    public function test_tools_notification_preserves_assigned_supervisors_access_without_mine_scope(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'herramientas' => ['ver'], 'man_power' => ['ver']]));
        $supervisor = $this->personal();
        $user->update(['personal_id' => $supervisor->id]);
        $rq = $this->parada($user, $this->mine(), $supervisor);
        $this->assertNotNull(app(ParadaHerramientaService::class)->findParadaForUser($user, $rq->id));
        $event = app(NotificationService::class)->emit('lista_herramientas_por_vencer', [
            'entity_type' => 'rq_mina', 'entity_id' => $rq->id, 'target_user_ids' => [$user->id],
            'permission_module' => 'man_power', 'permission_action' => 'ver', 'message' => 'Recordatorio',
        ]);
        $this->assertNotNull($event);
        $this->assertSame(1, app(NotificationInboxService::class)->unreadCount($user->id));
    }

    public function test_tools_administration_keeps_its_existing_unrestricted_scope(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'herramientas' => ['ver', 'administrar'], 'man_power' => ['ver']]));
        $rq = $this->parada($user, $this->mine(), $this->personal());
        $this->assertNotNull(app(ParadaHerramientaService::class)->findParadaForUser($user, $rq->id));
        $event = app(NotificationService::class)->emit('lista_herramientas_por_vencer', [
            'entity_type' => 'rq_mina', 'entity_id' => $rq->id, 'target_user_ids' => [$user->id],
            'permission_module' => 'man_power', 'permission_action' => 'ver', 'message' => 'Recordatorio',
        ]);
        $this->assertNotNull($event);
        $this->assertSame(1, app(NotificationInboxService::class)->unreadCount($user->id));
    }

    public function test_legacy_rq_proserge_alias_without_mine_or_payload_retains_authorized_access(): void
    {
        $user = $this->user($this->role(['notificaciones' => ['ver'], 'rq_proserge' => ['ver']]));
        $mine = $this->mine();
        $this->scope($user, $mine);
        $rq = RQProserge::query()->create([
            'id' => (string) Str::uuid(), 'mina_id' => $mine->id,
            'rq_mina_id' => $this->parada($user, $mine)->id, 'responsable_rrhh_id' => $user->id, 'estado' => 'PENDIENTE',
        ]);
        $type = $this->type(['module' => 'rq_proserge', 'required_permission_module' => 'rq_proserge']);
        $recipient = $this->recipient($user, $type);
        $recipient->event->update(['entity_type' => 'rq_proserge', 'entity_id' => $rq->id]);
        $inbox = app(NotificationInboxService::class);
        $this->assertSame(1, $inbox->unreadCount($user->id));
        $user->scopesMina()->delete();
        $this->assertSame(0, $inbox->unreadCount($user->id));
    }

    public static function apiActions(): array
    {
        return [
            ['GET', '/api/v1/notificaciones'],
            ['GET', '/api/v1/notificaciones/no-leidas/count'],
            ...self::apiWrites(),
        ];
    }

    public static function apiWrites(): array
    {
        return [
            ['POST', '/api/v1/notificaciones/{id}/leer'],
            ['POST', '/api/v1/notificaciones/{id}/archivar'],
            ['POST', '/api/v1/notificaciones/marcar-todas-leidas'],
        ];
    }

    public static function mutedSettings(): array
    {
        return [[['in_app_enabled' => false]], [['in_app_enabled' => true, 'muted_until' => true]]];
    }

    private function role(array $permissions, ?string $name = null, string $state = 'ACTIVO'): Rol
    {
        if ($name !== null) {
            $role = Rol::query()->firstOrNew(['nombre' => $name]);
            $role->fill(['id' => $role->id ?? (string) Str::uuid(), 'permisos' => PermissionCatalog::matrixFromSelections($permissions), 'estado' => $state])->save();

            return $role;
        }

        return Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => $name ?? 'NOTIF_'.Str::upper(Str::random(10)),
            'permisos' => PermissionCatalog::matrixFromSelections($permissions), 'estado' => $state,
        ]);
    }

    private function user(Rol $role): Usuario
    {
        return Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::random(15).'@notificaciones.test',
            'password' => 'test-only-password', 'rol_id' => $role->id, 'estado' => 'ACTIVO',
        ]);
    }

    private function attachRole(Usuario $user, Rol $role): void
    {
        $user->rolesAdicionales()->attach($role->id, ['id' => (string) Str::uuid(), 'tipo' => 'ADICIONAL']);
    }

    private function type(array $overrides = []): NotificationType
    {
        return NotificationType::query()->create([
            'id' => (string) Str::uuid(), 'code' => 'test_'.Str::random(12),
            'module' => 'usuarios', 'category' => 'seguridad', 'default_priority' => 'high',
            'required_permission_module' => 'usuarios', 'required_permission_action' => 'ver',
            'default_title' => 'Aviso de prueba', 'default_action_route' => '/usuarios',
            'is_active' => true, ...$overrides,
        ]);
    }

    private function resolve(NotificationType $type, array $context = []): array
    {
        return app(NotificationRecipientResolverService::class)->resolve($type, $context)->all();
    }

    private function rolePreference(Rol $role, NotificationType $type, bool $enabled): void
    {
        NotificationRolePreference::query()->create([
            'id' => (string) Str::uuid(), 'rol_id' => $role->id,
            'notification_type_id' => $type->id, 'is_enabled' => $enabled,
        ]);
    }

    private function mine(): Mina
    {
        return Mina::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'MINA '.Str::upper(Str::random(10)),
            'unidad_minera' => 'UNIDAD TEST', 'estado' => 'ACTIVO',
        ]);
    }

    private function personal(): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'SUPERVISOR PRUEBA', 'puesto' => 'SUPERVISOR',
            'qr_code' => 'QR-'.Str::random(12), 'estado' => 'ACTIVO',
        ]);
    }

    private function parada(Usuario $actor, Mina $mine, ?Personal $supervisor = null): RQMina
    {
        return RQMina::query()->create([
            'id' => (string) Str::uuid(), 'mina_id' => $mine->id, 'destino_tipo' => 'MINA',
            'destino_id' => $mine->id, 'destino_nombre' => $mine->nombre, 'supervisor_id' => $supervisor?->id,
            'area' => 'Prueba notificaciones', 'fecha_inicio' => now()->addWeek(),
            'fecha_fin' => now()->addWeeks(2), 'estado' => 'ENVIADO', 'created_by_usuario_id' => $actor->id,
        ]);
    }

    private function scope(Usuario $user, Mina $mine): void
    {
        $user->scopesMina()->create(['id' => (string) Str::uuid(), 'mina_id' => $mine->id]);
    }

    private function recipient(Usuario $user, NotificationType $type, ?Mina $mine = null): NotificationRecipient
    {
        $event = NotificationEvent::query()->create([
            'id' => (string) Str::uuid(), 'notification_type_id' => $type->id,
            'module' => $type->module, 'mina_id' => $mine?->id, 'priority' => 'high',
            'title' => 'Aviso de prueba', 'message' => 'Contenido de acceso restringido.',
            'action_route' => '/usuarios', 'occurred_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        return NotificationRecipient::query()->create([
            'id' => (string) Str::uuid(), 'notification_event_id' => $event->id,
            'usuario_id' => $user->id, 'status' => 'UNREAD', 'delivered_at' => now(),
        ]);
    }

    private function token(Usuario $user): string
    {
        $plain = Str::random(50);
        AuthToken::query()->create([
            'id' => (string) Str::uuid(), 'usuario_id' => $user->id,
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHour(),
        ]);

        return $plain;
    }

    private function sessionFor(Usuario $user): array
    {
        return ['auth_token' => 'test-session', 'user_id' => $user->id, 'user' => ['id' => $user->id, 'permissions' => []]];
    }
}
