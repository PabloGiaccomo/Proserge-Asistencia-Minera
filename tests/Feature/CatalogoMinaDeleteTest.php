<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogoMinaDeleteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_listado_de_minas_oculta_talleres_oficinas_y_marcadores_accidentales(): void
    {
        $userId = $this->createUser(['catalogos' => ['ver'], 'minas' => ['ver']]);
        $suffix = Str::upper(Str::random(6));
        $realMine = 'MINA OPERATIVA ' . $suffix;
        $workshopName = 'TALLER OPERATIVO ' . $suffix;
        $officeName = 'OFICINA OPERATIVA ' . $suffix;

        $this->createMine($realMine);
        $this->createMine($workshopName);
        $this->createMine($officeName);
        $this->createMine('Sin mina');

        DB::table('talleres')->insert([
            'id' => (string) Str::uuid(),
            'nombre' => $workshopName,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('oficinas')->insert([
            'id' => (string) Str::uuid(),
            'nombre' => $officeName,
            'ubicacion' => 'Administracion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('catalogos.minas.index'))
            ->assertOk()
            ->assertSee($realMine)
            ->assertDontSee($workshopName)
            ->assertDontSee($officeName)
            ->assertDontSee('Sin mina');
    }

    public function test_usuario_con_permiso_ve_boton_eliminar_mina(): void
    {
        $userId = $this->createUser(['catalogos' => ['ver'], 'minas' => ['ver', 'eliminar']]);
        $mineId = $this->createMine();

        $this->withSession($this->sessionFor($userId))
            ->get(route('catalogos.minas.index'))
            ->assertOk()
            ->assertSee(route('catalogos.minas.destroy', $mineId), false)
            ->assertSee(route('catalogos.minas.force-destroy', $mineId), false)
            ->assertSee('Eliminar');
    }

    public function test_elimina_mina_sin_movimientos_y_sus_paraderos(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();
        $paraderoId = (string) Str::uuid();

        DB::table('mina_paraderos')->insert([
            'id' => $paraderoId,
            'mina_id' => $mineId,
            'nombre' => 'Paradero temporal',
            'ubicacion' => 'Entrada',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('catalogos.minas.destroy', $mineId))
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('success', 'Mina eliminada correctamente.');

        $this->assertDatabaseMissing('minas', ['id' => $mineId]);
        $this->assertDatabaseMissing('mina_paraderos', ['id' => $paraderoId]);
    }

    public function test_no_elimina_mina_con_movimientos_asociados(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();

        DB::table('rq_mina')->insert([
            'id' => (string) Str::uuid(),
            'mina_id' => $mineId,
            'area' => 'Parada',
            'fecha_inicio' => '2026-06-12',
            'fecha_fin' => '2026-06-13',
            'estado' => 'BORRADOR',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->from(route('catalogos.minas.index'))
            ->post(route('catalogos.minas.destroy', $mineId))
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('minas', ['id' => $mineId]);
    }

    public function test_eliminacion_definitiva_limpia_habilitacion_y_conserva_trabajador(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();
        $mineName = DB::table('minas')->where('id', $mineId)->value('nombre');
        $personalId = $this->createPersonal();
        $personalMinaId = (string) Str::uuid();
        $examId = (string) Str::uuid();
        $requirementId = (string) Str::uuid();
        $workerExamId = (string) Str::uuid();
        $attemptId = (string) Str::uuid();
        $historialId = (string) Str::uuid();
        $scopeId = (string) Str::uuid();
        $paraderoId = (string) Str::uuid();

        DB::table('examenes_mineros')->insert([
            'id' => $examId,
            'nombre' => 'Examen accidental',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mina_requisitos')->insert([
            'id' => $requirementId,
            'mina_id' => $mineId,
            'examen_id' => $examId,
            'nombre' => 'Examen accidental',
            'obligatorio' => true,
            'critico' => false,
            'reprogramable' => true,
            'activo' => true,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina')->insert([
            'id' => $personalMinaId,
            'personal_id' => $personalId,
            'mina_id' => $mineId,
            'estado' => 'EN_PROCESO',
            'estado_habilitacion' => 'EN_PROCESO',
            'fecha_asignacion' => now()->toDateString(),
            'fecha_inicio_proceso' => now()->toDateString(),
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina_historial')->insert([
            'id' => $historialId,
            'personal_mina_id' => $personalMinaId,
            'estado_anterior' => null,
            'estado_nuevo' => 'EN_PROCESO',
            'observacion' => 'Carga accidental',
            'usuario_id' => $userId,
            'fecha_cambio' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina_examenes')->insert([
            'id' => $workerExamId,
            'personal_mina_id' => $personalMinaId,
            'mina_requisito_id' => $requirementId,
            'examen_id' => $examId,
            'nombre_snapshot' => 'Examen accidental',
            'estado' => 'PENDIENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina_examen_intentos')->insert([
            'id' => $attemptId,
            'personal_mina_examen_id' => $workerExamId,
            'numero_intento' => 1,
            'resultado' => 'PENDIENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mina_paraderos')->insert([
            'id' => $paraderoId,
            'mina_id' => $mineId,
            'nombre' => 'Paradero accidental',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuario_mina_scope')->insert([
            'id' => $scopeId,
            'usuario_id' => $userId,
            'mina_id' => $mineId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notificationEventId = $this->createNotificationEventForMine($mineId, $userId);

        $this->withSession($this->sessionFor($userId))
            ->post(route('catalogos.minas.force-destroy', $mineId), [
                'confirmacion' => $mineName,
            ])
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('minas', ['id' => $mineId]);
        $this->assertDatabaseMissing('personal_mina', ['id' => $personalMinaId]);
        $this->assertDatabaseMissing('personal_mina_historial', ['id' => $historialId]);
        $this->assertDatabaseMissing('personal_mina_examenes', ['id' => $workerExamId]);
        $this->assertDatabaseMissing('personal_mina_examen_intentos', ['id' => $attemptId]);
        $this->assertDatabaseMissing('mina_requisitos', ['id' => $requirementId]);
        $this->assertDatabaseMissing('mina_paraderos', ['id' => $paraderoId]);
        $this->assertDatabaseMissing('usuario_mina_scope', ['id' => $scopeId]);
        $this->assertDatabaseHas('personal', ['id' => $personalId]);

        if ($notificationEventId !== null) {
            $this->assertDatabaseHas('notification_events', [
                'id' => $notificationEventId,
                'mina_id' => null,
            ]);
        }
    }

    public function test_eliminacion_definitiva_requiere_nombre_de_mina(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();

        $this->withSession($this->sessionFor($userId))
            ->from(route('catalogos.minas.index'))
            ->post(route('catalogos.minas.force-destroy', $mineId), [
                'confirmacion' => 'OTRA MINA',
            ])
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('minas', ['id' => $mineId]);
    }

    public function test_eliminacion_definitiva_bloquea_movimientos_operativos(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();
        $mineName = DB::table('minas')->where('id', $mineId)->value('nombre');

        DB::table('rq_mina')->insert([
            'id' => (string) Str::uuid(),
            'mina_id' => $mineId,
            'area' => 'Parada',
            'fecha_inicio' => '2026-06-12',
            'fecha_fin' => '2026-06-13',
            'estado' => 'BORRADOR',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->from(route('catalogos.minas.index'))
            ->post(route('catalogos.minas.force-destroy', $mineId), [
                'confirmacion' => $mineName,
            ])
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('minas', ['id' => $mineId]);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'CATALOGO_MINAS_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function createMine(?string $name = null): string
    {
        $mineId = (string) Str::uuid();
        $name ??= 'MINA DELETE ' . Str::upper(Str::random(6));

        DB::table('minas')->insert([
            'id' => $mineId,
            'nombre' => $name,
            'unidad_minera' => $name,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $mineId;
    }

    private function createPersonal(): string
    {
        $personalId = (string) Str::uuid();
        $dni = (string) random_int(10000000, 99999999);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => $dni,
            'tipo_documento' => 'DNI',
            'numero_documento' => $dni,
            'nombre_completo' => 'TRABAJADOR MINA ACCIDENTAL',
            'puesto' => 'OPERARIO',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personalId;
    }

    private function createNotificationEventForMine(string $mineId, string $userId): ?string
    {
        if (!Schema::hasTable('notification_events') || !Schema::hasTable('notification_types')) {
            return null;
        }

        $typeId = (string) Str::uuid();
        $eventId = (string) Str::uuid();

        DB::table('notification_types')->insert([
            'id' => $typeId,
            'code' => 'test.mine.delete.' . Str::lower(Str::random(8)),
            'module' => 'catalogos',
            'category' => 'operacion',
            'default_priority' => 'medium',
            'default_title' => 'Prueba mina',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notification_events')->insert([
            'id' => $eventId,
            'notification_type_id' => $typeId,
            'actor_usuario_id' => $userId,
            'mina_id' => $mineId,
            'module' => 'catalogos',
            'priority' => 'medium',
            'title' => 'Prueba mina',
            'message' => 'Evento asociado a mina',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $eventId;
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'catalogo@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
