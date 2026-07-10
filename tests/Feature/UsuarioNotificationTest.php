<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use App\Modules\Notificaciones\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsuarioNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_crear_usuario_notifica_a_roles_personalizados_con_permiso(): void
    {
        $actorRoleId = $this->createRole('CREADOR_USUARIOS_' . Str::upper(Str::random(6)), [
            'usuarios' => ['crear', 'ver', 'asignar'],
        ]);
        $recipientRoleId = $this->createRole('JEFE_USUARIOS_' . Str::upper(Str::random(6)), [
            'usuarios' => ['crear', 'ver'],
            'notificaciones' => ['ver'],
        ]);
        $newUserRoleId = $this->createRole('USUARIO_NUEVO_' . Str::upper(Str::random(6)), [
            'inicio' => ['ver'],
        ]);

        $actorId = $this->createUser($actorRoleId, 'actor');
        $recipientId = $this->createUser($recipientRoleId, 'destino');
        $personalId = $this->createPersonal('nuevo');

        $this->ensureNotificationType();

        $response = $this
            ->withSession([
                'auth_token' => 'test-token',
                'user_id' => $actorId,
                'user' => [
                    'id' => $actorId,
                    'email' => 'actor@test.local',
                    'permissions' => PermissionCatalog::emptyMatrix(),
                ],
            ])
            ->post('/usuarios', [
                'personal_id' => $personalId,
                'email' => 'nuevo-' . Str::lower(Str::random(8)) . '@test.local',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'rol_id' => $newUserRoleId,
                'estado' => 'ACTIVO',
            ]);

        $response->assertRedirect();

        $eventId = DB::table('notification_events')
            ->where('dedupe_key', 'like', 'usuario_creado:%')
            ->latest('occurred_at')
            ->value('id');

        $this->assertNotNull($eventId);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $recipientId,
            'status' => 'UNREAD',
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_event_id' => $eventId,
            'usuario_id' => $actorId,
        ]);
    }

    public function test_usuario_con_notificaciones_denegadas_no_recibe_notificaciones(): void
    {
        $roleId = $this->createRole('DESTINO_NOTIF_' . Str::upper(Str::random(6)), [
            'usuarios' => ['ver'],
            'notificaciones' => ['ver'],
        ]);
        $allowedUserId = $this->createUser($roleId, 'permitido');
        $deniedUserId = $this->createUser($roleId, 'denegado');

        $this->ensureNotificationType();

        DB::table('notification_user_settings')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $deniedUserId,
            'in_app_enabled' => false,
            'email_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = app(NotificationService::class)->emit('usuario_creado', [
            'message' => 'Notificacion de prueba para preferencias de usuario.',
            'target_user_ids' => [$allowedUserId, $deniedUserId],
            'require_permission' => false,
            'dedupe_key' => 'usuario_creado:preferencias:' . Str::uuid(),
            'priority' => 'high',
            'category' => 'seguridad',
        ]);

        $this->assertNotNull($event);
        $this->assertDatabaseHas('notification_recipients', [
            'notification_event_id' => $event->id,
            'usuario_id' => $allowedUserId,
        ]);
        $this->assertDatabaseMissing('notification_recipients', [
            'notification_event_id' => $event->id,
            'usuario_id' => $deniedUserId,
        ]);
    }

    public function test_permiso_general_permitido_muestra_y_habilita_notificaciones_sin_permiso_de_rol(): void
    {
        $roleId = $this->createRole('SIN_MODULO_NOTIF_' . Str::upper(Str::random(6)), [
            'inicio' => ['ver'],
        ]);
        $userId = $this->createUser($roleId, 'notif-general');

        DB::table('notification_user_settings')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'in_app_enabled' => true,
            'email_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = view('partials.header', [
            'notificationsEnabled' => true,
            'headerUnreadCount' => 0,
            'headerNotifications' => collect(),
        ])->render();

        $this->assertStringContainsString('headerNotifToggle', $html);

        $this->withSession($this->sessionFor($userId, PermissionCatalog::matrixFromSelections([
            'inicio' => ['ver'],
        ])))
            ->getJson('/notificaciones/poll')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_permiso_general_denegado_bloquea_notificaciones_aunque_el_rol_tenga_permiso(): void
    {
        $roleId = $this->createRole('CON_MODULO_NOTIF_' . Str::upper(Str::random(6)), [
            'inicio' => ['ver'],
            'notificaciones' => ['ver', 'actualizar'],
        ]);
        $userId = $this->createUser($roleId, 'notif-denegado');

        DB::table('notification_user_settings')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'in_app_enabled' => false,
            'email_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId, PermissionCatalog::matrixFromSelections([
            'inicio' => ['ver'],
            'notificaciones' => ['ver', 'actualizar'],
        ])))
            ->getJson('/notificaciones/poll')
            ->assertForbidden()
            ->assertJsonPath('error', 'PERMISSION_DENIED');
    }

    private function createRole(string $name, array $permissions): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => $name,
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUser(string $roleId, string $prefix): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => $prefix . '-' . Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createPersonal(string $prefix): string
    {
        $id = (string) Str::uuid();
        $token = Str::upper(Str::random(8));

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Trabajador ' . $prefix . ' ' . $token,
            'puesto' => 'Operario',
            'qr_code' => 'QR-' . $token,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function sessionFor(string $userId, array $permissions): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'notificaciones@test.local',
                'permissions' => $permissions,
            ],
        ];
    }

    private function ensureNotificationType(): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['code' => 'usuario_creado'],
            [
                'id' => DB::table('notification_types')->where('code', 'usuario_creado')->value('id') ?? (string) Str::uuid(),
                'module' => 'usuarios',
                'category' => 'seguridad',
                'default_priority' => 'high',
                'required_permission_module' => 'usuarios',
                'required_permission_action' => 'crear',
                'default_title' => 'Nuevo usuario creado',
                'default_action_label' => 'Ver usuario',
                'default_action_route' => '/usuarios/{entity_id}',
                'is_active' => true,
                'created_at' => DB::table('notification_types')->where('code', 'usuario_creado')->value('created_at') ?? now(),
                'updated_at' => now(),
            ]
        );
    }
}
