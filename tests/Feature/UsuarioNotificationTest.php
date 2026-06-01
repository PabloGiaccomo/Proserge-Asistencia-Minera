<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
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
            'usuarios' => ['crear', 'ver'],
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
