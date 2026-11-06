<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\NotificationRolePreference;
use App\Models\NotificationType;
use App\Models\NotificationUserSetting;
use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationRecipientResolverService;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationUserPreferencesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public function test_user_configuration_permission_is_enforced_on_the_real_post_route(): void
    {
        $editor = $this->user($this->role(['usuarios' => ['ver', 'editar'], 'notificaciones' => ['ver', 'actualizar']]));
        $target = $this->user($this->role([]));
        $this->withSession($this->sessionFor($editor))->postJson(route('usuarios.notificaciones', $target->id), ['notifications_allowed' => '1'])
            ->assertForbidden();
        $this->assertDatabaseMissing('notification_user_settings', ['usuario_id' => $target->id]);
    }

    public function test_real_user_form_grants_preferences_not_role_permissions(): void
    {
        $editor = $this->user($this->role(['usuarios' => ['configurar']]));
        $target = $this->user($this->role(['usuarios' => ['ver']]));
        $type = $this->type();
        $response = $this->withSession($this->sessionFor($editor))->post(route('usuarios.notificaciones', $target->id), [
            'notifications_allowed' => '1', 'notification_type_ids' => [$type->id],
            'preferences' => [$type->id => ['in_app_enabled' => '1', 'minimum_priority' => 'low']],
        ]);
        $response->assertRedirect(route('usuarios.show', $target->id))->assertSessionHasNoErrors();
        $this->assertSame([], $this->recipients($type, $target));
        $this->withSession($this->sessionFor($target))->getJson(route('notificaciones.poll'))->assertForbidden();
    }

    public function test_unchecking_a_type_in_user_form_stops_delivery_without_changing_other_users(): void
    {
        $editor = $this->user($this->role(['usuarios' => ['configurar']]));
        $role = $this->role(['usuarios' => ['ver'], 'notificaciones' => ['ver']]);
        $target = $this->user($role);
        $other = $this->user($role);
        $type = $this->type();
        $this->assertSame([$target->id], $this->recipients($type, $target));
        $this->withSession($this->sessionFor($editor))->post(route('usuarios.notificaciones', $target->id), [
            'notifications_allowed' => '1', 'notification_type_ids' => [$type->id],
            'preferences' => [$type->id => ['minimum_priority' => 'low']],
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $this->recipients($type, $target));
        $this->assertSame([$other->id], $this->recipients($type, $other));
    }

    #[DataProvider('falseCheckboxes')]
    public function test_role_form_false_checkbox_value_remains_disabled(mixed $value): void
    {
        $editor = $this->user($this->role(['roles' => ['actualizar']]));
        $role = $this->role(['usuarios' => ['ver'], 'notificaciones' => ['ver']]);
        $target = $this->user($role);
        $type = $this->type();
        $this->withSession($this->sessionFor($editor))->put(route('seguridad.roles.update', $role->id), [
            'nombre' => $role->nombre, 'estado' => 'ACTIVO',
            'notification_type_ids' => [$type->id], 'notificaciones' => [$type->id => $value],
        ])->assertSessionHasNoErrors();
        $this->assertFalse(NotificationRolePreference::query()->where('rol_id', $role->id)->where('notification_type_id', $type->id)->firstOrFail()->is_enabled);
        $this->assertSame([], $this->recipients($type, $target));
    }

    #[DataProvider('malformedUserCheckboxes')]
    public function test_role_form_rejects_invalid_boolean_before_saving_role_or_preferences(mixed $value): void
    {
        $editor = $this->user($this->role(['roles' => ['actualizar']]));
        $role = $this->role(['notificaciones' => ['ver']]);
        $type = $this->type();
        $name = $role->nombre;
        $this->withSession($this->sessionFor($editor))->putJson(route('seguridad.roles.update', $role->id), [
            'nombre' => 'CHANGED-'.Str::random(8), 'estado' => 'ACTIVO',
            'notification_type_ids' => [$type->id], 'notificaciones' => [$type->id => $value],
        ])->assertUnprocessable()->assertJsonValidationErrors('notificaciones.'.$type->id);
        $this->assertSame($name, $role->fresh()->nombre);
        $this->assertDatabaseMissing('notification_role_preferences', ['rol_id' => $role->id, 'notification_type_id' => $type->id]);
    }

    #[DataProvider('falseCheckboxes')]
    public function test_user_form_persists_explicit_false_without_enabling_delivery(mixed $value): void
    {
        $editor = $this->user($this->role(['usuarios' => ['configurar']]));
        $target = $this->user($this->role(['notificaciones' => ['ver'], 'usuarios' => ['ver']]));
        $type = $this->type();
        $this->withSession($this->sessionFor($editor))->post(route('usuarios.notificaciones', $target->id), [
            'notifications_allowed' => '1', 'notification_type_ids' => [$type->id],
            'preferences' => [$type->id => ['in_app_enabled' => $value, 'minimum_priority' => null]],
        ])->assertSessionHasNoErrors();
        $preference = NotificationPreference::query()->where('usuario_id', $target->id)->firstOrFail();
        $this->assertFalse($preference->in_app_enabled);
        $this->assertSame('low', $preference->minimum_priority);
        $this->assertSame([], $this->recipients($type, $target));
    }

    #[DataProvider('malformedUserCheckboxes')]
    public function test_user_form_rejects_malformed_checkbox_values(mixed $value): void
    {
        $editor = $this->user($this->role(['usuarios' => ['configurar']]));
        $target = $this->user($this->role(['usuarios' => ['ver'], 'notificaciones' => ['ver']]));
        $type = $this->type();
        $this->withSession($this->sessionFor($editor))->postJson(route('usuarios.notificaciones', $target->id), [
            'notifications_allowed' => '1', 'notification_type_ids' => [$type->id],
            'preferences' => [$type->id => ['in_app_enabled' => $value, 'minimum_priority' => 'low']],
        ])->assertUnprocessable()->assertJsonValidationErrors('preferences.'.$type->id.'.in_app_enabled');
        $this->assertDatabaseMissing('notification_preferences', ['usuario_id' => $target->id, 'notification_type_id' => $type->id]);
    }

    public function test_saving_priority_does_not_cancel_existing_global_or_type_mute(): void
    {
        $editor = $this->user($this->role(['usuarios' => ['configurar']]));
        $target = $this->user($this->role(['usuarios' => ['ver'], 'notificaciones' => ['ver']]));
        $type = $this->type();
        $until = now()->addDay()->startOfSecond();
        NotificationUserSetting::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $target->id, 'in_app_enabled' => true, 'muted_until' => $until]);
        NotificationPreference::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $target->id, 'notification_type_id' => $type->id, 'in_app_enabled' => true, 'mute_until' => $until]);
        $this->withSession($this->sessionFor($editor))->post(route('usuarios.notificaciones', $target->id), [
            'notifications_allowed' => '1', 'notification_type_ids' => [$type->id],
            'preferences' => [$type->id => ['in_app_enabled' => '1', 'minimum_priority' => 'high']],
        ])->assertSessionHasNoErrors();
        $this->assertNotNull(NotificationUserSetting::query()->where('usuario_id', $target->id)->value('muted_until'));
        $this->assertNotNull(NotificationPreference::query()->where('usuario_id', $target->id)->where('notification_type_id', $type->id)->value('mute_until'));
        $this->assertSame([], $this->recipients($type, $target));
    }

    public function test_live_session_loses_web_write_access_when_editor_revokes_update(): void
    {
        $editor = $this->user($this->role(['roles' => ['actualizar']]));
        $role = $this->role(['usuarios' => ['ver'], 'notificaciones' => ['ver', 'actualizar']]);
        $target = $this->user($role);
        $this->withSession($this->sessionFor($target))->getJson(route('notificaciones.poll'))->assertOk();
        $this->withSession($this->sessionFor($editor))->put(route('seguridad.roles.update', $role->id), [
            'nombre' => $role->nombre, 'estado' => 'ACTIVO', 'permisos_present' => '1',
            'permisos' => ['usuarios' => ['ver' => '1'], 'notificaciones' => ['ver' => '1']],
        ])->assertSessionHasNoErrors();
        $this->withSession($this->sessionFor($target))->getJson(route('notificaciones.poll'))->assertOk();
        $this->postJson(route('notificaciones.mark-all-read'))->assertForbidden();
    }

    public function test_invalid_priority_does_not_partially_change_existing_preferences(): void
    {
        $editor = $this->user($this->role(['usuarios' => ['configurar']]));
        $target = $this->user($this->role(['usuarios' => ['ver'], 'notificaciones' => ['ver']]));
        $type = $this->type();
        NotificationUserSetting::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $target->id, 'in_app_enabled' => false]);
        $this->withSession($this->sessionFor($editor))->postJson(route('usuarios.notificaciones', $target->id), [
            'notifications_allowed' => '1', 'notification_type_ids' => [$type->id],
            'preferences' => [$type->id => ['in_app_enabled' => '1', 'minimum_priority' => 'invalid']],
        ])->assertUnprocessable();
        $this->assertFalse(NotificationUserSetting::query()->where('usuario_id', $target->id)->firstOrFail()->in_app_enabled);
        $this->assertDatabaseMissing('notification_preferences', ['usuario_id' => $target->id, 'notification_type_id' => $type->id]);
    }

    public function test_poll_reports_current_update_permission_after_a_role_change(): void
    {
        $role = $this->role(['notificaciones' => ['ver', 'actualizar']]);
        $target = $this->user($role);
        $this->withSession($this->sessionFor($target))->getJson(route('notificaciones.poll'))
            ->assertOk()->assertJsonPath('can_update', true);
        $role->update(['permisos' => PermissionCatalog::matrixFromSelections(['notificaciones' => ['ver']])]);
        $this->getJson(route('notificaciones.poll'))->assertOk()->assertJsonPath('can_update', false);
        $this->postJson(route('notificaciones.mark-all-read'))->assertForbidden();
    }

    public function test_catalog_migration_preserves_ids_disabled_types_preferences_and_roles(): void
    {
        $role = $this->role(['notificaciones' => ['ver']]);
        $target = $this->user($role);
        $type = NotificationType::query()->where('code', 'habilitacion_examen_programado')->firstOrFail();
        $originalId = $type->id;
        $type->update(['is_active' => false, 'module' => 'personal', 'required_permission_module' => 'personal', 'required_permission_action' => 'actualizar']);
        $until = now()->addDay()->startOfSecond();
        NotificationPreference::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $target->id, 'notification_type_id' => $type->id, 'in_app_enabled' => false, 'mute_until' => $until]);
        NotificationRolePreference::query()->create(['id' => (string) Str::uuid(), 'rol_id' => $role->id, 'notification_type_id' => $type->id, 'is_enabled' => false]);
        $permissions = $role->permisos;
        $count = NotificationType::query()->count();
        $migration = require database_path('migrations/2026_10_21_000100_align_notification_destination_permissions.php');
        $migration->up();
        $migration->up();
        $this->assertSame($count, NotificationType::query()->count());
        $this->assertSame($originalId, $type->fresh()->id);
        $this->assertFalse($type->fresh()->is_active);
        $this->assertSame('habilitacion_minera', $type->fresh()->required_permission_module);
        $this->assertFalse(NotificationPreference::query()->where('usuario_id', $target->id)->firstOrFail()->in_app_enabled);
        $this->assertTrue(NotificationPreference::query()->where('usuario_id', $target->id)->firstOrFail()->mute_until->equalTo($until));
        $this->assertFalse(NotificationRolePreference::query()->where('rol_id', $role->id)->firstOrFail()->is_enabled);
        $this->assertSame($permissions, $role->fresh()->permisos);
    }

    public function test_approved_ficha_contract_notice_opens_contract_data_not_ficha_review(): void
    {
        $target = $this->user($this->role(['notificaciones' => ['ver'], 'personal' => ['editar_datos_contrato']]));
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'TRABAJADOR PRUEBA', 'puesto' => 'OPERARIO', 'qr_code' => 'QR-'.Str::random(12), 'estado' => 'ACTIVO',
        ]);
        $ficha = PersonalFicha::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $personal->id, 'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => 'DNI', 'numero_documento' => $personal->dni, 'datos_json' => [],
        ]);
        $event = app(NotificationService::class)->emit('personal_ficha_aprobada_falta_contrato', [
            'entity_type' => Personal::class, 'entity_id' => $personal->id, 'target_user_ids' => [$target->id],
            'message' => 'Contrato pendiente', 'payload' => ['personal_id' => $personal->id, 'ficha_id' => $ficha->id],
        ]);
        $this->assertNotNull($event);
        $recipient = $event->recipients()->where('usuario_id', $target->id)->firstOrFail();
        $this->withSession($this->sessionFor($target))->get(route('notificaciones.action', $recipient->id))
            ->assertRedirect(route('personal.contrato-datos.edit', $personal->id));
        $this->assertSame('UNREAD', $recipient->fresh()->status);
    }

    #[DataProvider('domainTypes')]
    public function test_destination_permission_does_not_need_unrelated_personal_update(string $code, string $module, string $action): void
    {
        $target = $this->user($this->role([$module => [$action], 'notificaciones' => ['ver']]));
        $type = NotificationType::query()->where('code', $code)->firstOrFail();
        $this->assertTrue(PermissionMatrix::userCanDirect($target, $module, $action));
        $this->assertFalse(PermissionMatrix::userCanDirect($target, 'personal', 'actualizar'));
        $this->assertSame([$target->id], $this->recipients($type, $target));
    }

    #[DataProvider('domainTypes')]
    public function test_personal_update_does_not_grant_notifications_for_a_denied_destination(string $code, string $module, string $action): void
    {
        $target = $this->user($this->role(['personal' => ['actualizar'], 'notificaciones' => ['ver']]));
        $type = NotificationType::query()->where('code', $code)->firstOrFail();
        $this->assertFalse(PermissionMatrix::userCanDirect($target, $module, $action));
        if ($module !== 'personal') {
            $this->assertFalse(PermissionMatrix::userCanDirect($target, $module, 'actualizar'));
        }
        if ($module === 'habilitacion_minera') {
            $this->withSession($this->sessionFor($target))->getJson('/personal/habilitacion-minera')->assertForbidden();
        }
        $this->assertSame([], $this->recipients($type, $target));
    }

    public static function domainTypes(): array
    {
        return [
            ['habilitacion_examen_programado', 'habilitacion_minera', 'ver'],
            ['habilitacion_mina_estado_critico', 'habilitacion_minera', 'ver'],
            ['habilitacion_mina_actualizada', 'habilitacion_minera', 'ver'],
            ['habilitacion_mina_habilitado', 'habilitacion_minera', 'ver'],
            ['personal_contrato_firmado', 'personal', 'ver_contratos'],
            ['contrato_no_renovado_cerrado', 'personal', 'ver_contratos'],
            ['contrato_decision_renovacion', 'vencimientos', 'ver'],
            ['personal_ficha_aprobada_falta_contrato', 'personal', 'editar_datos_contrato'],
        ];
    }

    public static function malformedUserCheckboxes(): array
    {
        return [['false'], [['unexpected']], ['enabled']];
    }

    public static function falseCheckboxes(): array
    {
        return [[false], [0], ['0']];
    }

    private function role(array $permissions): Rol
    {
        return Rol::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'AUDIT-'.Str::random(12), 'estado' => 'ACTIVO', 'permisos' => PermissionCatalog::matrixFromSelections($permissions)]);
    }

    private function user(Rol $role): Usuario
    {
        return Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::random(15).'@audit.test', 'password' => 'test-only-password', 'rol_id' => $role->id, 'estado' => 'ACTIVO']);
    }

    private function sessionFor(Usuario $user): array
    {
        return ['auth_token' => 'audit-only-session', 'user_id' => $user->id, 'user' => ['id' => $user->id, 'permissions' => PermissionCatalog::emptyMatrix()]];
    }

    private function type(): NotificationType
    {
        return NotificationType::query()->create([
            'id' => (string) Str::uuid(), 'code' => 'audit_'.Str::random(12), 'module' => 'usuarios',
            'category' => 'seguridad', 'default_priority' => 'high', 'required_permission_module' => 'usuarios',
            'required_permission_action' => 'ver', 'default_title' => 'Aviso sintetico', 'is_active' => true,
        ]);
    }

    private function recipients(NotificationType $type, Usuario $user): array
    {
        return app(NotificationRecipientResolverService::class)->resolve($type, ['target_user_ids' => [$user->id]])->all();
    }
}
