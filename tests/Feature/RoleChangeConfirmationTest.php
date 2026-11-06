<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\RolCambioEvento;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Seguridad\Services\RoleChangeNotificationService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleChangeConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_role_save_confirms_even_if_notification_fails_and_retry_keeps_audit(): void
    {
        $editor = $this->actor(['roles' => ['ver', 'actualizar']]);
        $target = $this->actor(['inicio' => ['ver']])->rol;
        $mock = $this->mock(NotificationService::class);
        $mock->shouldReceive('emit')->once()->andThrow(new \RuntimeException('Fallo sintético'));
        $this->withSession(['auth_token' => 'test', 'user_id' => $editor->id, 'user' => ['id' => $editor->id]]);
        $payload = ['nombre' => $target->nombre, 'estado' => 'ACTIVO', 'permisos_present' => 1, 'permisos' => ['perfil' => ['ver' => 1]]];
        $this->put(route('seguridad.roles.update', $target->id), $payload)
            ->assertRedirect(route('seguridad.roles.edit', $target->id))->assertSessionHas('success');
        self::assertTrue($target->fresh()->permisos['perfil']['ver']);
        $event = RolCambioEvento::query()->where('rol_id', $target->id)->sole();
        self::assertSame($editor->id, $event->actor_usuario_id);
        self::assertSame('PENDIENTE', $event->notificaciones_estado);
        self::assertTrue($event->antes_json['permisos']['inicio']['ver']);
        self::assertTrue($event->despues_json['permisos']['perfil']['ver']);
        self::assertSame(1, $event->intentos);

        $mock->shouldReceive('emit')->twice()->andReturnNull();
        app(RoleChangeNotificationService::class)->deliver($event->id);
        app(RoleChangeNotificationService::class)->deliver($event->id);
        self::assertSame('SIN_DESTINATARIOS', $event->fresh()->notificaciones_estado);
        $this->put(route('seguridad.roles.update', $target->id), $payload)->assertRedirect();
        self::assertSame(1, RolCambioEvento::query()->where('rol_id', $target->id)->count());
    }

    public function test_failure_to_record_role_change_rolls_back_permissions(): void
    {
        $editor = $this->actor(['roles' => ['ver', 'actualizar']]);
        $target = $this->actor(['inicio' => ['ver']])->rol;
        RolCambioEvento::creating(fn () => throw new \RuntimeException('Fallo de auditoría de prueba'));
        try {
            $this->withoutExceptionHandling()->withSession(['auth_token' => 'test', 'user_id' => $editor->id, 'user' => ['id' => $editor->id]])
                ->put(route('seguridad.roles.update', $target->id), [
                    'nombre' => $target->nombre, 'estado' => 'ACTIVO', 'permisos_present' => 1, 'permisos' => ['perfil' => ['ver' => 1]],
                ]);
            self::fail('La auditoría debe ser parte del guardado.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Fallo de auditoría de prueba', $exception->getMessage());
            self::assertTrue($target->fresh()->permisos['inicio']['ver']);
            self::assertFalse($target->fresh()->permisos['perfil']['ver']);
        } finally {
            RolCambioEvento::flushEventListeners();
        }
    }

    private function actor(array $permissions): Usuario
    {
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'ROL_PRUEBA_'.Str::random(10), 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections($permissions),
        ]);

        return Usuario::query()->create([
            'id' => (string) Str::uuid(), 'rol_id' => $role->id, 'estado' => 'ACTIVO',
            'email' => Str::uuid().'@test.local', 'password' => 'synthetic-password',
        ]);
    }
}
