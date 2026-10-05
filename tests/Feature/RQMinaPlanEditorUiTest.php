<?php

namespace Tests\Feature;

use App\Models\RQMinaActividadTransporte;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RQMinaPlanEditorUiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_editor_de_plan_muestra_la_atencion_de_logistica_y_los_ids(): void
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RQ_PLAN_UI_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'rq_mina' => ['ver', 'editar'],
                'logistica' => ['ver', 'actualizar'],
            ])),
            'estado' => 'ACTIVO',
        ]);

        $userId = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => 'rq-plan-ui-' . Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => null,
            'estado' => 'ACTIVO',
        ]);

        $minaId = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $minaId,
            'nombre' => 'RQ UI PLAN ' . Str::upper(Str::random(4)),
            'unidad_minera' => 'UM UI',
            'estado' => 'ACTIVO',
        ]);
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'mina_id' => $minaId,
        ]);

        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'destino_nombre' => 'RQ UI PLAN',
            'area' => 'SECCION UI',
            'fecha_inicio' => '2026-07-23',
            'fecha_fin' => '2026-08-23',
            'observaciones' => 'Registro UI',
            'estado' => 'BORRADOR',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = (string) Str::uuid();
        DB::table('rq_mina_planes')->insert([
            'id' => $planId,
            'rq_mina_id' => $rqId,
            'codigo' => 'PLAN-001',
            'nombre' => 'Plan UI',
            'version' => 1,
            'fecha_inicio' => '2026-07-23',
            'fecha_fin' => '2026-08-23',
            'estado' => 'VIGENTE',
            'created_by_usuario_id' => $userId,
            'updated_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grupoId = (string) Str::uuid();
        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $grupoId,
            'rq_mina_id' => $rqId,
            'rq_mina_plan_id' => $planId,
            'area_operativa' => 'C1',
            'modulo' => 'UI',
            'nombre' => 'Grupo UI',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transporteId = (string) Str::uuid();
        DB::table('rq_mina_actividad_transportes')->insert([
            'id' => $transporteId,
            'grupo_id' => $grupoId,
            'rq_mina_plan_id' => $planId,
            'alcance' => 'SAIT UI',
            'unidad_carga' => 'Grua UI',
            'unidades_transporte' => 'Van 15',
            'placas_asignadas' => 'UI-123',
            'origen' => 'ALQUILADO',
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_RECIBIDO,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withSession([
            'auth_token' => 'test-token-' . $userId,
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'rq-plan-ui@test.local',
                'permissions' => PermissionCatalog::matrixFromSelections([
                    'rq_mina' => ['ver', 'editar'],
                ]),
            ],
        ])->get('/rq-mina/' . $rqId . '/plan');

        $response->assertOk();
        $response->assertSee('atencion_label', false);
        $response->assertSee('Atendido', false);
        $response->assertSee('UI-123', false);
        $response->assertSee($transporteId, false);
        $response->assertSee($grupoId, false);
        $response->assertSee('data-atencion-logistica', false);
        $response->assertSee('[id]', false);
    }
}
