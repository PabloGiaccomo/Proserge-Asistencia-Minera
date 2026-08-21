<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MiAsistenciaWebTest extends TestCase
{
    use DatabaseTransactions;

    public function test_responsable_solo_ve_sus_grupos(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisorId = $this->createPersonal('RESPONSABLE PROPIO');
        $otherSupervisorId = $this->createPersonal('RESPONSABLE AJENO');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $ownGroupId = $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO PROPIO');
        $otherGroupId = $this->createGroup($rqMinaId, $minaId, $userId, $otherSupervisorId, 'SERVICIO AJENO');

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.index', ['fecha' => '2026-08-19']))
            ->assertOk()
            ->assertSee('SERVICIO PROPIO')
            ->assertDontSee('SERVICIO AJENO');

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.show', $ownGroupId))
            ->assertOk();

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.show', $otherGroupId))
            ->assertNotFound();
    }

    public function test_permiso_ver_todas_muestra_grupos_del_alcance(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver', 'ver_todas_asistencias']]);
        $supervisorId = $this->createPersonal('SUPERVISOR UNO');
        $otherSupervisorId = $this->createPersonal('SUPERVISOR DOS');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $this->assignMineScope($userId, $minaId);
        $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO TURNO DIA', 'DIA');
        $this->createGroup($rqMinaId, $minaId, $userId, $otherSupervisorId, 'SERVICIO TURNO NOCHE', 'NOCHE');

        $this->withSession($this->sessionFor($userId))
            ->get(route('mi-asistencia.index', ['fecha' => '2026-08-19']))
            ->assertOk()
            ->assertSee('Todas las asistencias')
            ->assertSee('SERVICIO TURNO DIA')
            ->assertSee('SERVICIO TURNO NOCHE');
    }

    public function test_responsable_puede_marcar_su_grupo_sin_permiso_global(): void
    {
        $roleId = $this->createRole(['mi_asistencia' => ['ver']]);
        $supervisorId = $this->createPersonal('RESPONSABLE MARCADOR');
        $workerId = $this->createPersonal('TRABAJADOR DEL GRUPO');
        $userId = $this->createUser($roleId, $supervisorId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, $supervisorId, 'SERVICIO ASISTENCIA');
        $detailId = $this->addMember($groupId, $workerId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'mi-asistencia-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'mi-asistencia-csrf')
            ->postJson(route('mi-asistencia.marcar', $groupId), [
                'grupo_trabajo_detalle_id' => $detailId,
                'estado' => 'PRESENTE',
                'hora_marcado' => '07:05',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('asistencia_detalle', [
            'grupo_trabajo_detalle_id' => $detailId,
            'trabajador_id' => $workerId,
            'estado' => 'PRESENTE',
            'marcado_por_id' => $userId,
        ]);
    }

    public function test_catalogo_expone_permiso_para_ver_todas_las_asistencias(): void
    {
        $actions = PermissionCatalog::availableModuleActions();

        $this->assertContains('ver_todas_asistencias', $actions['mi_asistencia']);
        $this->assertSame('Ver y registrar todas las asistencias', PermissionCatalog::actionLabel('ver_todas_asistencias'));
    }

    public function test_usuario_global_registra_grupo_sin_responsable_con_su_identidad(): void
    {
        $roleId = $this->createRole([
            'mi_asistencia' => ['ver', 'ver_todas_asistencias'],
        ]);
        $personalId = $this->createPersonal('USUARIO ASISTENCIA GLOBAL');
        $workerId = $this->createPersonal('TRABAJADOR SIN RESPONSABLE');
        $userId = $this->createUser($roleId, $personalId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $this->assignMineScope($userId, $minaId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, null, 'SERVICIO SIN RESPONSABLE');
        $detailId = $this->addMember($groupId, $workerId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'mi-asistencia-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'mi-asistencia-csrf')
            ->postJson(route('mi-asistencia.marcar', $groupId), [
                'grupo_trabajo_detalle_id' => $detailId,
                'estado' => 'PRESENTE',
                'hora_marcado' => '07:05',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('asistencia_encabezado', [
            'grupo_trabajo_id' => $groupId,
            'supervisor_id' => $personalId,
        ]);
        $this->assertDatabaseHas('grupo_trabajo', [
            'id' => $groupId,
            'supervisor_id' => null,
        ]);
    }

    public function test_usuario_global_cierra_grupo_sin_responsable_y_registra_falta_con_su_identidad(): void
    {
        $roleId = $this->createRole([
            'mi_asistencia' => ['ver', 'ver_todas_asistencias'],
        ]);
        $personalId = $this->createPersonal('USUARIO CIERRE GLOBAL');
        $workerId = $this->createPersonal('TRABAJADOR AUSENTE SIN RESPONSABLE');
        $userId = $this->createUser($roleId, $personalId);
        $minaId = $this->createMine();
        $rqMinaId = $this->createRqMina($minaId, $userId);
        $this->assignMineScope($userId, $minaId);
        $groupId = $this->createGroup($rqMinaId, $minaId, $userId, null, 'CIERRE SIN RESPONSABLE');
        $this->addMember($groupId, $workerId);

        $this->withSession([...$this->sessionFor($userId), '_token' => 'mi-asistencia-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'mi-asistencia-csrf')
            ->postJson(route('mi-asistencia.cerrar', $groupId))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('faltas', [
            'trabajador_id' => $workerId,
            'motivo' => 'INASISTENCIA_ASISTENCIA',
            'estado' => 'ACTIVA',
            'registrada_por_id' => $personalId,
        ]);
    }

    private function createRole(array $permissions): string
    {
        $id = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => 'ASISTENCIA_' . Str::upper(Str::random(8)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUser(string $roleId, string $personalId): string
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(10)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createPersonal(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $name,
            'puesto' => 'OPERARIO',
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createMine(): string
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'MINA ' . Str::upper(Str::random(6)),
            'unidad_minera' => 'UM TEST',
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createRqMina(string $minaId, string $userId): string
    {
        $id = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'OPERACIONES',
            'fecha_inicio' => '2026-08-19',
            'fecha_fin' => '2026-08-25',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createGroup(
        string $rqMinaId,
        string $minaId,
        string $userId,
        ?string $supervisorId,
        string $service,
        string $turno = 'DIA',
    ): string {
        $id = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $id,
            'fecha' => '2026-08-19',
            'supervisor_id' => $supervisorId,
            'mina' => 'MINA TEST',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'rq_mina_id' => $rqMinaId,
            'servicio' => $service,
            'area' => 'OPERACIONES',
            'horario_salida' => $turno === 'DIA' ? '07:00:00' : '19:00:00',
            'turno' => $turno,
            'estado' => 'PROGRAMADO',
            'created_by_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($supervisorId) {
            $this->addMember($id, $supervisorId);
        }

        return $id;
    }

    private function addMember(string $groupId, string $personalId): string
    {
        $id = (string) Str::uuid();
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => $id,
            'grupo_trabajo_id' => $groupId,
            'personal_id' => $personalId,
            'puesto_asignado_snapshot' => 'OPERARIO',
            'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignMineScope(string $userId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'mina_id' => $minaId,
        ]);
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'mi-asistencia@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
