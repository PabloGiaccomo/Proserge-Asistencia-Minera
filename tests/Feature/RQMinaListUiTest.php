<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RQMinaListUiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_listado_rq_mina_renderiza_tarjetas_con_plan_y_transporte(): void
    {
        $roleId = $this->createRole([
            'rq_mina' => ['ver', 'editar', 'duplicar', 'enviar', 'eliminar'],
        ]);
        $userId = $this->createUser($roleId);
        $minaId = $this->createMina('BOROO UI');
        $this->assignMinaScope($userId, $minaId);
        $rqId = $this->createRqMina($minaId, $userId, 'BORRADOR');
        $this->addDetalle($rqId, 'Mecanico', 4, 1, 5);
        $this->addTransporte($rqId, 'Bus 40', 1);
        $this->addPlan($rqId, $userId);

        $response = $this->withSession($this->sessionFor($userId))->get('/rq-mina?q=BOROO');

        $response->assertOk();
        $response->assertSee('rq-card', false);
        $response->assertSee('BOROO UI');
        $response->assertSee('Plan operativo');
        $response->assertSee('1 plan');
        $response->assertSee('Personal solicitado');
        $response->assertSee('5 persona(s)');
        $response->assertSee('Transporte');
        $response->assertSee('Bus 40');
        $response->assertSee('Mas');
        $response->assertSee('Buscar: BOROO');
    }

    public function test_listado_rq_mina_muestra_advertencias_y_respeta_acciones_por_permiso(): void
    {
        $roleId = $this->createRole([
            'rq_mina' => ['ver'],
        ]);
        $userId = $this->createUser($roleId);
        $minaId = $this->createMina('CERRO UI');
        $this->assignMinaScope($userId, $minaId);
        $rqId = $this->createRqMina($minaId, $userId, 'ENVIADO');
        $this->addDetalle($rqId, 'Supervisor', 1, 0, 1);

        $response = $this->withSession($this->sessionFor($userId))->get('/rq-mina');

        $response->assertOk();
        $response->assertSee('Sin plan operativo');
        $response->assertSee('Transporte no planificado');
        $response->assertSee('Ver');
        $response->assertDontSee('Abrir plan');
        $response->assertDontSee('>Editar</a>', false);
        $response->assertDontSee('>Copiar</a>', false);
        $response->assertDontSee('>Eliminar</button>', false);
        $response->assertDontSee('>Enviar</button>', false);
    }

    private function createRole(array $permissions): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => 'RQ_UI_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUser(string $roleId): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => 'rq-ui-' . Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createMina(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => $name,
            'unidad_minera' => 'UM UI',
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function assignMinaScope(string $userId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'mina_id' => $minaId,
        ]);
    }

    private function createRqMina(string $minaId, string $userId, string $estado): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'destino_nombre' => DB::table('minas')->where('id', $minaId)->value('nombre'),
            'area' => 'SECCION UI C2',
            'fecha_inicio' => '2026-07-23',
            'fecha_fin' => '2026-08-23',
            'observaciones' => 'Registro UI',
            'estado' => $estado,
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function addDetalle(string $rqId, string $puesto, int $cantidad, int $backup, int $total): void
    {
        DB::table('rq_mina_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $rqId,
            'puesto' => $puesto,
            'cantidad' => $cantidad,
            'cantidad_backup' => $backup,
            'cantidad_total' => $total,
            'cantidad_atendida' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addTransporte(string $rqId, string $transporte, int $cantidad): void
    {
        DB::table('rq_mina_transporte_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $rqId,
            'transporte' => $transporte,
            'cantidad' => $cantidad,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addPlan(string $rqId, string $userId): void
    {
        $planId = (string) Str::uuid();
        $groupId = (string) Str::uuid();
        $activityId = (string) Str::uuid();

        DB::table('rq_mina_planes')->insert([
            'id' => $planId,
            'rq_mina_id' => $rqId,
            'codigo' => 'PLAN-001',
            'nombre' => 'Plan UI',
            'version' => 1,
            'fecha_inicio' => '2026-07-23',
            'fecha_fin' => '2026-08-23',
            'semana_referencia' => 'Semana UI',
            'estado' => 'VIGENTE',
            'created_by_usuario_id' => $userId,
            'updated_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $groupId,
            'rq_mina_id' => $rqId,
            'rq_mina_plan_id' => $planId,
            'area_operativa' => 'C2',
            'modulo' => 'UI',
            'nombre' => 'Grupo UI',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividades')->insert([
            'id' => $activityId,
            'grupo_id' => $groupId,
            'sait' => 'SAIT UI',
            'sector' => 'Sector UI',
            'area' => 'Area UI',
            'ait_trabajo' => 'Trabajo UI',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sessionFor(string $userId): array
    {
        $permissions = DB::table('roles')
            ->join('usuarios', 'usuarios.rol_id', '=', 'roles.id')
            ->where('usuarios.id', $userId)
            ->value('roles.permisos');

        return [
            'auth_token' => 'test-token-' . $userId,
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'rq-ui@test.local',
                'permissions' => json_decode((string) $permissions, true) ?: PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
