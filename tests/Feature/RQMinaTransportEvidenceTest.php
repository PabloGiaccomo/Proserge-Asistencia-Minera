<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\RQMina;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaActividadTransporte;
use App\Models\Usuario;
use App\Modules\RQMina\Services\RQMinaPlanService;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RQMinaTransportEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_omitir_transporte_atendido_conserva_fila_y_evidencia(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();

        $this->assertProtected($user, $rq, $group, [['id' => $group->id, 'nombre' => $group->nombre]], $transport);
    }

    public function test_quitar_el_grupo_no_borra_sus_transportes_atendidos(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();

        $this->assertProtected($user, $rq, $group, [], $transport);
    }

    public function test_un_evento_directo_impide_borrar_un_requerimiento_sin_estado_logistico(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();
        $transport->forceFill(['estado_logistico' => 'REQUERIDO', 'placas_asignadas' => null, 'documentos' => []])->save();
        $eventId = (string) Str::uuid();
        DB::table('rq_mina_actividad_transporte_eventos')->insert([
            'id' => $eventId, 'rq_mina_id' => $rq->id, 'transporte_id' => $transport->id,
            'tipo' => 'CAMBIO_REQUERIMIENTO', 'descripcion' => 'Historial sintético',
        ]);

        $this->assertProtected($user, $rq, $group, [], $transport);

        $this->assertDatabaseHas('rq_mina_actividad_transporte_eventos', ['id' => $eventId, 'transporte_id' => $transport->id]);
    }

    public function test_una_observacion_de_recepcion_impide_borrar_un_requerimiento(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();
        $transport->forceFill([
            'estado_logistico' => 'REQUERIDO', 'placas_asignadas' => null, 'documentos' => [],
            'recepcion_observacion' => 'Observación sintética pendiente',
        ])->save();

        $this->assertProtected($user, $rq, $group, [], $transport);
    }

    public function test_cada_documento_privado_impide_borrar_el_requerimiento(): void
    {
        foreach (['doc_vehiculo_path', 'doc_proserge_path', 'doc_mantenimiento_path', 'doc_checklist_path'] as $field) {
            [$user, $rq, $group, $transport] = $this->fixture();
            $transport->forceFill([
                'estado_logistico' => 'REQUERIDO', 'placas_asignadas' => null, 'documentos' => [],
                $field => 'logistica/transportes/prueba/evidencia.pdf',
            ])->save();

            $this->assertProtected($user, $rq, $group, [], $transport);
        }
    }

    public function test_quitar_actividad_con_ejecucion_conserva_el_vinculo_historico(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();
        $activityId = (string) Str::uuid();
        DB::table('rq_mina_actividades')->insert(['id' => $activityId, 'grupo_id' => $group->id, 'sait' => 'SAIT-PRUEBA', 'orden' => 1]);
        $executionId = (string) Str::uuid();
        DB::table('parada_ejecucion_resumen')->insert([
            'id' => $executionId, 'rq_mina_id' => $rq->id, 'rq_mina_plan_id' => $group->rq_mina_plan_id,
            'rq_mina_actividad_grupo_id' => $group->id, 'rq_mina_actividad_id' => $activityId,
            'fecha' => '2026-11-05', 'turno' => 'A',
        ]);

        try {
            app(RQMinaService::class)->updatePlanOperativo($user, $rq->fresh(), [[
                'id' => $group->id, 'nombre' => $group->nombre, 'transportes' => [[
                    'id' => $transport->id, 'alcance' => 'SAIT-PRUEBA', 'unidad_carga' => 'Bus de prueba',
                ]],
            ]], null, $group->rq_mina_plan_id);
            $this->fail('Quitar una actividad ejecutada debe conservar su vínculo histórico.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('historial', $exception->getMessage());
        }

        $this->assertDatabaseHas('rq_mina_actividades', ['id' => $activityId]);
        $this->assertDatabaseHas('parada_ejecucion_resumen', ['id' => $executionId, 'rq_mina_actividad_id' => $activityId]);
    }

    public function test_legacy_inequivoco_conserva_identidad_documentos_y_eventos(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();
        $eventId = (string) Str::uuid();
        DB::table('rq_mina_actividad_transporte_eventos')->insert([
            'id' => $eventId, 'rq_mina_id' => $rq->id, 'transporte_id' => $transport->id, 'tipo' => 'CAMBIO_REQUERIMIENTO',
        ]);
        $payload = [['nombre' => $group->nombre, 'transportes' => [['alcance' => 'SAIT-PRUEBA', 'unidad_carga' => 'Bus de prueba']]]];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            app(RQMinaService::class)->updatePlanOperativo($user, $rq->fresh(), $payload, null, $group->rq_mina_plan_id);

            $this->assertDatabaseHas('rq_mina_actividad_grupos', ['id' => $group->id]);
            $this->assertDatabaseHas('rq_mina_actividad_transportes', ['id' => $transport->id, 'grupo_id' => $group->id, 'placas_asignadas' => 'PRU-001']);
            $this->assertSame('logistica/transportes/prueba/archivo.pdf', $transport->fresh()->documentos[0]['path']);
            $this->assertDatabaseHas('rq_mina_actividad_transporte_eventos', ['id' => $eventId, 'transporte_id' => $transport->id]);
        }
    }

    public function test_legacy_con_firma_cambiada_no_reasigna_evidencia_por_posicion(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();

        $this->assertProtected($user, $rq, $group, [[
            'nombre' => $group->nombre,
            'transportes' => [['alcance' => 'OTRO SAIT', 'unidad_carga' => 'Camión distinto']],
        ]], $transport);
    }

    public function test_legacy_con_firma_ambigua_conserva_la_evidencia_sin_adivinar(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();

        $this->assertProtected($user, $rq, $group, [[
            'nombre' => $group->nombre,
            'transportes' => [
                ['alcance' => 'SAIT-PRUEBA', 'unidad_carga' => 'Bus de prueba'],
                ['alcance' => 'SAIT-PRUEBA', 'unidad_carga' => 'Bus de prueba'],
            ],
        ]], $transport);
    }

    public function test_repetir_un_grupo_no_burla_la_proteccion_de_evidencia(): void
    {
        [$user, $rq, $group, $transport] = $this->fixture();

        try {
            app(RQMinaService::class)->updatePlanOperativo($user, $rq->fresh(), [
                ['id' => $group->id, 'nombre' => $group->nombre],
                ['id' => strtoupper($group->id), 'nombre' => $group->nombre, 'transportes' => [[
                    'id' => $transport->id, 'alcance' => 'SAIT-PRUEBA', 'unidad_carga' => 'Bus de prueba',
                ]]],
            ], null, $group->rq_mina_plan_id);
            $this->fail('Un grupo repetido no debe provocar un borrado intermedio.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('repetidos', $exception->getMessage());
        }

        $this->assertDatabaseHas('rq_mina_actividad_transportes', ['id' => $transport->id, 'placas_asignadas' => 'PRU-001']);
    }

    private function assertProtected(Usuario $user, RQMina $rq, RQMinaActividadGrupo $group, array $payload, RQMinaActividadTransporte $transport): void
    {
        try {
            app(RQMinaService::class)->updatePlanOperativo($user, $rq->fresh(), $payload, null, $group->rq_mina_plan_id);
            $this->fail('El guardado debe conservar el requerimiento con evidencia logística.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Logística', $exception->getMessage());
        }

        $this->assertDatabaseHas('rq_mina_actividad_grupos', ['id' => $group->id]);
        $this->assertDatabaseHas('rq_mina_actividad_transportes', ['id' => $transport->id, 'grupo_id' => $group->id]);
    }

    private function fixture(): array
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $role = Rol::firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO', 'permisos' => []]);
        $user = Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'synthetic-test-password',
            'rol_id' => $role->id, 'estado' => 'ACTIVO',
        ]);
        $mineId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $mineId, 'nombre' => 'Mina sintética '.Str::random(8), 'unidad_minera' => 'Prueba', 'estado' => 'ACTIVO']);
        $rq = RQMina::query()->create([
            'id' => (string) Str::uuid(), 'mina_id' => $mineId, 'area' => 'Parada sintética',
            'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30', 'estado' => 'BORRADOR', 'created_by_usuario_id' => $user->id,
        ]);
        $plan = app(RQMinaPlanService::class)->ensureDefaultPlan($rq, $user);
        $group = RQMinaActividadGrupo::query()->create([
            'id' => (string) Str::uuid(), 'rq_mina_id' => $rq->id, 'rq_mina_plan_id' => $plan->id,
            'nombre' => 'Grupo sintético', 'orden' => 1,
        ]);
        $transport = RQMinaActividadTransporte::query()->create([
            'id' => (string) Str::uuid(), 'grupo_id' => $group->id, 'rq_mina_plan_id' => $plan->id,
            'alcance' => 'SAIT-PRUEBA', 'unidad_carga' => 'Bus de prueba', 'estado_logistico' => 'ASIGNADO',
            'placas_asignadas' => 'PRU-001', 'documentos' => [['path' => 'logistica/transportes/prueba/archivo.pdf']],
            'orden' => 1,
        ]);

        return [$user, $rq, $group, $transport];
    }
}
