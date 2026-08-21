<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Modules\ManPower\Services\ManPowerPlanningService;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManPowerApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolPlannerId;

    private string $rolRrhhId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rolPlannerId = (string) Str::uuid();
        $this->rolRrhhId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $this->rolPlannerId,
                'nombre' => 'PLANNER_TEST_'.$this->rolPlannerId,
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'man_power' => ['ver', 'crear', 'editar', 'actualizar', 'asignar', 'duplicar'],
                ])),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->rolRrhhId,
                'nombre' => 'RRHH_TEST_'.$this->rolRrhhId,
                'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'man_power' => ['ver'],
                ])),
                'estado' => 'ACTIVO',
            ],
        ]);
    }

    public function test_usuario_con_scope_ve_paradas(): void
    {
        [$minaId, $rqMinaId] = $this->crearParadaAtendida();
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/man-power/paradas?mina_id='.$minaId);

        $response->assertOk()
            ->assertJsonPath('code', 'MANPOWER_PARADAS_LIST_OK')
            ->assertJsonFragment(['rq_mina_id' => $rqMinaId]);
    }

    public function test_filtra_paradas_por_unidad_minera_dentro_del_scope(): void
    {
        [$minaId, $rqMinaId] = $this->crearParadaAtendida();
        [$otraMinaId, $otraParadaId] = $this->crearParadaAtendida();
        $unidad = DB::table('minas')->where('id', $minaId)->value('unidad_minera');
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $this->asignarScope($usuarioId, $otraMinaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/man-power/paradas?unidad_minera='.urlencode((string) $unidad));

        $response->assertOk()
            ->assertJsonFragment(['rq_mina_id' => $rqMinaId])
            ->assertJsonMissing(['rq_mina_id' => $otraParadaId]);
    }

    public function test_cargo_compartible_permite_repetir_personal_en_grupos_del_mismo_turno(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $primerGrupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId);
        $segundoGrupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId);
        $asignacionId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
        $detalleRqId = DB::table('rq_proserge_detalle')->where('id', $asignacionId)->value('rq_mina_detalle_id');

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$primerGrupoId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $asignacionId,
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$segundoGrupoId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $asignacionId,
        ])->assertStatus(422)->assertJsonPath('code', 'MANPOWER_PERSON_GROUP_CONFLICT');

        DB::table('rq_mina_detalle')->where('id', $detalleRqId)->update(['compartible_man_power' => 1]);

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$segundoGrupoId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $asignacionId,
        ])->assertOk();

        $this->assertSame(2, DB::table('grupo_trabajo_detalle')
            ->where('rq_proserge_detalle_id', $asignacionId)
            ->where('estado_distribucion', 'ASIGNADO')
            ->count());
    }

    public function test_cargo_compartible_no_permite_dia_y_noche_en_la_misma_fecha(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $grupoDiaId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [], 'DIA');
        $grupoNocheId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [], 'NOCHE');
        $asignacionId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
        $detalleRqId = DB::table('rq_proserge_detalle')->where('id', $asignacionId)->value('rq_mina_detalle_id');

        DB::table('rq_mina_detalle')->where('id', $detalleRqId)->update(['compartible_man_power' => 1]);

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$grupoDiaId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $asignacionId,
        ])->assertOk();

        $contextoNoche = $this->withToken($token)->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&fecha=2026-06-01&turno=NOCHE');
        $contextoNoche->assertOk();
        $asignacionNoche = collect($contextoNoche->json('data.asignaciones'))->firstWhere('rq_proserge_detalle_id', $asignacionId);
        $this->assertFalse((bool) ($asignacionNoche['disponible'] ?? true));
        $this->assertSame('DIA', $asignacionNoche['turno_bloqueado'] ?? null);

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$grupoNocheId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $asignacionId,
        ])->assertStatus(422)->assertJsonPath('code', 'MANPOWER_PERSON_GROUP_CONFLICT');

        $this->assertSame(1, DB::table('grupo_trabajo_detalle')
            ->where('personal_id', $personalId)
            ->where('estado_distribucion', 'ASIGNADO')
            ->count());
    }

    public function test_responsable_del_grupo_se_cambia_entre_integrantes_activos(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $grupoId = $this->crearGrupo(
            $rqMinaId,
            $rqProsergeId,
            $supervisorId,
            $usuarioId,
            [$supervisorId, $personalId],
        );
        $detalleSupervisorId = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupoId)
            ->where('personal_id', $supervisorId)
            ->value('id');
        $detallePersonalId = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupoId)
            ->where('personal_id', $personalId)
            ->value('id');

        DB::table('grupo_trabajo_detalle')->where('id', $detallePersonalId)->update([
            'estado_distribucion' => 'RETIRADO',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/man-power/grupos/'.$grupoId.'/integrantes/'.$detallePersonalId.'/responsable')
            ->assertStatus(422)
            ->assertJsonPath('code', 'MANPOWER_RESPONSIBLE_NOT_ACTIVE_MEMBER');
        $this->assertSame($supervisorId, DB::table('grupo_trabajo')->where('id', $grupoId)->value('supervisor_id'));

        DB::table('grupo_trabajo_detalle')->where('id', $detallePersonalId)->update([
            'estado_distribucion' => 'ASIGNADO',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/man-power/grupos/'.$grupoId.'/integrantes/'.$detallePersonalId.'/responsable')
            ->assertOk()
            ->assertJsonPath('code', 'MANPOWER_GRUPO_RESPONSIBLE_OK')
            ->assertJsonPath('data.supervisor_id', $personalId);
        $this->assertSame($personalId, DB::table('grupo_trabajo')->where('id', $grupoId)->value('supervisor_id'));

        $this->withToken($token)
            ->postJson('/api/v1/man-power/grupos/'.$grupoId.'/integrantes/'.$detalleSupervisorId.'/responsable')
            ->assertOk()
            ->assertJsonPath('data.supervisor_id', $supervisorId);
        $this->assertSame($supervisorId, DB::table('grupo_trabajo')->where('id', $grupoId)->value('supervisor_id'));
    }

    public function test_copia_todos_los_grupos_del_dia_sobrescribiendo_el_destino(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $grupoDiaId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [], 'DIA');
        $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [], 'NOCHE');
        $asignacionId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$grupoDiaId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $asignacionId,
        ])->assertOk();

        $payload = [
            'rq_mina_id' => $rqMinaId,
            'fecha_origen' => '2026-06-01',
            'fecha_destino' => '2026-06-02',
            'copiar_integrantes' => true,
            'sobrescribir_destino' => true,
        ];

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/copiar-dia', $payload)
            ->assertCreated()
            ->assertJsonPath('code', 'MANPOWER_DAY_COPY_OK')
            ->assertJsonPath('data.grupos_copiados', 2)
            ->assertJsonPath('data.integrantes_copiados', 1);

        $this->assertSame(2, DB::table('grupo_trabajo')->where('rq_mina_id', $rqMinaId)->whereDate('fecha', '2026-06-02')->count());
        $this->assertSame(1, DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->where('gt.rq_mina_id', $rqMinaId)
            ->whereDate('gt.fecha', '2026-06-02')
            ->where('gtd.estado_distribucion', 'ASIGNADO')
            ->count());

        $extraGroupId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [], 'DIA');
        DB::table('grupo_trabajo')->where('id', $extraGroupId)->update([
            'fecha' => '2026-06-02',
            'servicio' => 'Grupo extra del destino',
            'updated_at' => now(),
        ]);
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $extraGroupId,
            'personal_id' => $personalId,
            'rq_proserge_detalle_id' => $asignacionId,
            'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/copiar-dia', $payload)
            ->assertCreated()
            ->assertJsonPath('data.grupos_copiados', 2)
            ->assertJsonPath('data.grupos_reemplazados', 3)
            ->assertJsonPath('data.integrantes_copiados', 1);

        $this->assertSame(2, DB::table('grupo_trabajo')
            ->where('rq_mina_id', $rqMinaId)
            ->whereDate('fecha', '2026-06-02')
            ->where('estado', '!=', 'CANCELADO')
            ->count());
        $this->assertSame(3, DB::table('grupo_trabajo')
            ->where('rq_mina_id', $rqMinaId)
            ->whereDate('fecha', '2026-06-02')
            ->where('estado', 'CANCELADO')
            ->count());
        $this->assertSame(1, DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->where('gt.rq_mina_id', $rqMinaId)
            ->whereDate('gt.fecha', '2026-06-02')
            ->where('gt.estado', '!=', 'CANCELADO')
            ->where('gtd.estado_distribucion', 'ASIGNADO')
            ->count());
        $this->assertSame(2, DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->where('gt.rq_mina_id', $rqMinaId)
            ->whereDate('gt.fecha', '2026-06-02')
            ->where('gt.estado', 'CANCELADO')
            ->where('gtd.estado_distribucion', 'RETIRADO')
            ->count());

        foreach (['DIA', 'NOCHE'] as $turno) {
            $this->withToken($token)
                ->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&fecha=2026-06-02&turno='.$turno)
                ->assertOk()
                ->assertJsonCount(1, 'data.grupos_man_power')
                ->assertJsonPath('data.grupos_man_power.0.estado', 'BORRADOR');
        }

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/copiar-dia', $payload)
            ->assertCreated()
            ->assertJsonPath('data.grupos_copiados', 2)
            ->assertJsonPath('data.grupos_reemplazados', 2);

        $this->assertSame(2, DB::table('grupo_trabajo')
            ->where('rq_mina_id', $rqMinaId)
            ->whereDate('fecha', '2026-06-02')
            ->where('estado', '!=', 'CANCELADO')
            ->count());

        foreach (['DIA', 'NOCHE'] as $turno) {
            $this->withToken($token)
                ->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&fecha=2026-06-02&turno='.$turno)
                ->assertOk()
                ->assertJsonCount(1, 'data.grupos_man_power');
        }
    }

    public function test_copia_grupos_a_dias_restantes_y_no_reemplaza_dias_que_ya_pasaron(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 08:00:00');

        try {
            [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
            [$planId, $operationalGroupId, $activityId] = $this->crearPlanOperativo($rqMinaId);
            DB::table('rq_mina')->where('id', $rqMinaId)->update(['fecha_fin' => '2026-06-10']);
            DB::table('rq_mina_planes')->where('id', $planId)->update(['fecha_fin' => '2026-06-10']);
            DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->update(['fecha_fin' => '2026-06-10']);

            $usuarioId = $this->crearUsuario($this->rolPlannerId);
            $this->asignarScope($usuarioId, $minaId);
            $token = $this->crearToken($usuarioId);
            $sourceGroupId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
                'rq_mina_id' => $rqMinaId,
                'rq_proserge_id' => $rqProsergeId,
                'rq_mina_plan_id' => $planId,
                'rq_mina_actividad_grupo_id' => $operationalGroupId,
                'actividad_ids' => [$activityId],
                'fecha' => '2026-06-01',
                'turno' => 'DIA',
                'servicio' => 'SAIT-TEST',
                'area' => 'Area test',
                'horario_salida' => '07:00',
                'destino_tipo' => 'MINA',
                'destino_id' => $minaId,
                'estado' => 'BORRADOR',
            ])->assertCreated()->json('data.id');

            $assignmentId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
            $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$sourceGroupId.'/agregar-personal', [
                'rq_proserge_detalle_id' => $assignmentId,
            ])->assertOk();

            $this->withSession([
                'auth_token' => 'man-power-range-copy-test',
                'user_id' => $usuarioId,
                'user' => [
                    'id' => $usuarioId,
                    'email' => 'planner-range@test.local',
                    'permissions' => PermissionCatalog::matrixFromSelections([
                        'man_power' => ['ver', 'crear', 'actualizar', 'asignar', 'duplicar'],
                    ]),
                    'mina_scopes' => [$minaId],
                ],
            ])->get('/man-power/grupos?rq_mina_id='.$rqMinaId.'&plan_id='.$planId.'&actividad_id='.$activityId.'&fecha=2026-06-01&vista=seleccion')
                ->assertOk()
                ->assertSee('data-copy-range="SEMANA"', false)
                ->assertSee('data-copy-range="PARADA"', false)
                ->assertSee('data-cancel-day', false);

            $basePayload = [
                'rq_mina_id' => $rqMinaId,
                'rq_mina_plan_id' => $planId,
                'rq_mina_actividad_id' => $activityId,
                'fecha_origen' => '2026-06-01',
                'copiar_integrantes' => true,
                'sobrescribir_destino' => true,
            ];

            $this->withToken($token)->postJson('/api/v1/man-power/grupos/copiar-rango', array_merge($basePayload, [
                'alcance' => 'SEMANA',
            ]))->assertCreated()
                ->assertJsonPath('code', 'MANPOWER_RANGE_COPY_OK')
                ->assertJsonPath('data.dias_copiados', 6)
                ->assertJsonPath('data.grupos_copiados', 6)
                ->assertJsonPath('data.integrantes_copiados', 6);

            $protectedGroups = DB::table('grupo_trabajo')
                ->where('rq_mina_id', $rqMinaId)
                ->whereIn('fecha', ['2026-06-02', '2026-06-03'])
                ->where('estado', '!=', 'CANCELADO')
                ->orderBy('fecha')
                ->pluck('id', 'fecha');

            CarbonImmutable::setTestNow('2026-06-04 08:00:00');
            $token = $this->crearToken($usuarioId);

            $this->withToken($token)->postJson('/api/v1/man-power/grupos/copiar-rango', array_merge($basePayload, [
                'alcance' => 'PARADA',
            ]))->assertCreated()
                ->assertJsonPath('data.dias_copiados', 7)
                ->assertJsonPath('data.grupos_copiados', 7);

            foreach ($protectedGroups as $date => $groupId) {
                $this->assertDatabaseHas('grupo_trabajo', [
                    'id' => $groupId,
                    'fecha' => $date,
                    'estado' => 'BORRADOR',
                ]);
            }

            $this->assertSame(1, DB::table('grupo_trabajo')
                ->where('rq_mina_id', $rqMinaId)
                ->whereDate('fecha', '2026-06-10')
                ->where('estado', '!=', 'CANCELADO')
                ->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_cancela_logicamente_los_grupos_del_sait_y_dia_seleccionados(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        [$planId, $operationalGroupId, $activityId] = $this->crearPlanOperativo($rqMinaId);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $createPayload = [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $operationalGroupId,
            'actividad_ids' => [$activityId],
            'fecha' => '2026-06-01',
            'servicio' => 'SAIT-TEST',
            'area' => 'Area test',
            'horario_salida' => '07:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'BORRADOR',
        ];

        $dayGroupId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', array_merge($createPayload, [
            'turno' => 'DIA',
        ]))->assertCreated()->json('data.id');

        $nightGroupId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', array_merge($createPayload, [
            'turno' => 'NOCHE',
            'horario_salida' => '19:00',
        ]))->assertCreated()->json('data.id');

        $assignmentId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$dayGroupId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $assignmentId,
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/cancelar-dia', [
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_id' => $activityId,
            'fecha' => '2026-06-01',
        ])->assertOk()
            ->assertJsonPath('code', 'MANPOWER_DAY_CANCEL_OK')
            ->assertJsonPath('data.grupos_cancelados', 2)
            ->assertJsonPath('data.integrantes_retirados', 1);

        foreach ([$dayGroupId, $nightGroupId] as $groupId) {
            $this->assertDatabaseHas('grupo_trabajo', [
                'id' => $groupId,
                'estado' => 'CANCELADO',
            ]);
        }

        $this->assertDatabaseHas('grupo_trabajo_detalle', [
            'grupo_trabajo_id' => $dayGroupId,
            'personal_id' => $personalId,
            'estado_distribucion' => 'RETIRADO',
        ]);
    }

    public function test_copia_grupo_de_un_sait_a_otro_en_un_dia_distinto(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        [$planId, $sourceOperationalGroupId, $sourceActivityId] = $this->crearPlanOperativo($rqMinaId);
        $destinationOperationalGroupId = (string) Str::uuid();
        $destinationActivityId = (string) Str::uuid();

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $destinationOperationalGroupId,
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'area_operativa' => 'Area destino',
            'modulo' => 'MOD-DESTINO',
            'nombre' => 'Grupo operativo destino',
            'orden' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rq_mina_actividades')->insert([
            'id' => $destinationActivityId,
            'grupo_id' => $destinationOperationalGroupId,
            'sait' => 'SAIT-DESTINO',
            'sector' => 'Sector destino',
            'area' => 'Area destino',
            'ait_trabajo' => 'Trabajo destino',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rq_mina_actividad_turnos')->insert([
            'id' => (string) Str::uuid(),
            'actividad_id' => $destinationActivityId,
            'fecha' => '2026-06-02',
            'dia_label' => 'Mar',
            'turno_a' => '7',
            'turno_b' => '4',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $basePayload = [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'turno' => 'DIA',
            'horario_salida' => '07:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'BORRADOR',
        ];

        $sourceGroupId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', array_merge($basePayload, [
            'rq_mina_actividad_grupo_id' => $sourceOperationalGroupId,
            'actividad_ids' => [$sourceActivityId],
            'fecha' => '2026-06-01',
            'servicio' => 'SAIT-TEST',
            'area' => 'Area test',
        ]))->assertCreated()->json('data.id');

        $assignmentId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$sourceGroupId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $assignmentId,
        ])->assertOk();

        $oldDestinationGroupId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', array_merge($basePayload, [
            'rq_mina_actividad_grupo_id' => $destinationOperationalGroupId,
            'actividad_ids' => [$destinationActivityId],
            'fecha' => '2026-06-02',
            'servicio' => 'Grupo anterior del destino',
            'area' => 'Area destino',
        ]))->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/v1/man-power/grupos/copiar-dia', [
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_origen_id' => $sourceActivityId,
            'rq_mina_actividad_destino_id' => $destinationActivityId,
            'fecha_origen' => '2026-06-01',
            'fecha_destino' => '2026-06-02',
            'copiar_integrantes' => true,
            'sobrescribir_destino' => true,
        ])->assertCreated()
            ->assertJsonPath('data.grupos_copiados', 1)
            ->assertJsonPath('data.grupos_reemplazados', 1)
            ->assertJsonPath('data.integrantes_copiados', 1);

        $newDestinationGroupId = DB::table('grupo_trabajo as gt')
            ->join('grupo_trabajo_actividades as gta', 'gta.grupo_trabajo_id', '=', 'gt.id')
            ->where('gt.rq_mina_id', $rqMinaId)
            ->whereDate('gt.fecha', '2026-06-02')
            ->where('gt.estado', '!=', 'CANCELADO')
            ->where('gta.rq_mina_actividad_id', $destinationActivityId)
            ->value('gt.id');

        $this->assertNotNull($newDestinationGroupId);
        $this->assertDatabaseHas('grupo_trabajo', [
            'id' => $newDestinationGroupId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $destinationOperationalGroupId,
            'servicio' => 'SAIT-DESTINO',
            'area' => 'Area destino',
            'sait_snapshot' => 'SAIT-DESTINO',
            'sector_snapshot' => 'Sector destino',
            'cantidad_planificada_snapshot' => 7,
        ]);
        $this->assertDatabaseHas('grupo_trabajo', ['id' => $oldDestinationGroupId, 'estado' => 'CANCELADO']);
        $this->assertDatabaseHas('grupo_trabajo_detalle', [
            'grupo_trabajo_id' => $newDestinationGroupId,
            'personal_id' => $personalId,
            'estado_distribucion' => 'ASIGNADO',
        ]);
        $this->assertSame(0, DB::table('grupo_trabajo as gt')
            ->join('grupo_trabajo_actividades as gta', 'gta.grupo_trabajo_id', '=', 'gt.id')
            ->where('gt.rq_mina_id', $rqMinaId)
            ->whereDate('gt.fecha', '2026-06-02')
            ->where('gt.estado', '!=', 'CANCELADO')
            ->where('gta.rq_mina_actividad_id', $sourceActivityId)
            ->count());
    }

    public function test_usuario_sin_scope_no_ve_ni_crea(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId] = $this->crearParadaAtendida(true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $token = $this->crearToken($usuarioId);

        $list = $this->withToken($token)->getJson('/api/v1/man-power/paradas?mina_id='.$minaId);
        $list->assertStatus(403)->assertJsonPath('code', 'MINA_SCOPE_FORBIDDEN');

        $create = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'supervisor_id' => $supervisorId,
            'servicio' => 'Servicio test',
            'area' => 'Area test',
            'horario_salida' => '06:30',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
        ]);

        $create->assertStatus(403)->assertJsonPath('code', 'MINA_SCOPE_FORBIDDEN');
    }

    public function test_solo_muestra_paradas_atendidas_por_proserge(): void
    {
        [$minaId, $rqAtendidoId] = $this->crearParadaAtendida();
        $rqNoAtendidoId = $this->crearParadaNoAtendida($minaId);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/man-power/paradas?mina_id='.$minaId);

        $response->assertOk()
            ->assertJsonFragment(['rq_mina_id' => $rqAtendidoId])
            ->assertJsonMissing(['rq_mina_id' => $rqNoAtendidoId]);
    }

    public function test_no_agrega_persona_fuera_universo_aprobado(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalAprobadoId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $grupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId);
        $personaNoAprobada = $this->crearPersonal($minaId, false);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$grupoId.'/agregar-personal', [
            'personal_id' => $personaNoAprobada,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'MANPOWER_PERSON_NOT_APPROVED');

        $this->assertNotEquals($personalAprobadoId, $personaNoAprobada);
    }

    public function test_no_deja_supervisor_invalido(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId] = $this->crearParadaAtendida(true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $supervisorInvalido = $this->crearPersonal($minaId, false);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'supervisor_id' => $supervisorInvalido,
            'servicio' => 'Servicio test',
            'area' => 'Area test',
            'horario_salida' => '06:30',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'MANPOWER_INVALID_SUPERVISOR');
    }

    public function test_permite_preparar_grupo_sin_responsable_ni_integrantes(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId] = $this->crearParadaAtendida(true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'servicio' => 'Servicio sin responsable',
            'area' => 'Area test',
            'horario_salida' => '06:30',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.supervisor', null);

        $grupoId = $response->json('data.id');
        $this->assertDatabaseHas('grupo_trabajo', [
            'id' => $grupoId,
            'supervisor_id' => null,
        ]);
        $this->assertSame(0, DB::table('grupo_trabajo_detalle')->where('grupo_trabajo_id', $grupoId)->count());
    }

    public function test_permite_crear_grupo_turno_dia_y_noche(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId] = $this->crearParadaAtendida(true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $dia = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'supervisor_id' => $supervisorId,
            'servicio' => 'Servicio test',
            'area' => 'Area test',
            'horario_salida' => '06:30',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
        ]);

        $dia->assertStatus(201)->assertJsonPath('data.turno', 'DIA');

        $noche = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-02',
            'turno' => 'NOCHE',
            'supervisor_id' => $supervisorId,
            'servicio' => 'Servicio test',
            'area' => 'Area test',
            'horario_salida' => '18:30',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
        ]);

        $noche->assertStatus(201)->assertJsonPath('data.turno', 'NOCHE');
    }

    public function test_permite_crear_grupo_con_destino_taller_u_oficina(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId] = $this->crearParadaAtendida(true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $tallerId = (string) Str::uuid();
        DB::table('talleres')->insert([
            'id' => $tallerId,
            'nombre' => 'Taller Central',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oficinaId = (string) Str::uuid();
        DB::table('oficinas')->insert([
            'id' => $oficinaId,
            'nombre' => 'Oficina Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taller = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-02',
            'turno' => 'DIA',
            'supervisor_id' => $supervisorId,
            'servicio' => 'Servicio taller',
            'area' => 'Area taller',
            'horario_salida' => '07:00',
            'destino_tipo' => 'TALLER',
            'destino_id' => $tallerId,
        ]);

        $taller->assertStatus(201)
            ->assertJsonPath('data.destino.tipo', 'TALLER')
            ->assertJsonPath('data.destino.id', $tallerId);

        $oficina = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'fecha' => '2026-06-03',
            'turno' => 'DIA',
            'supervisor_id' => $supervisorId,
            'servicio' => 'Servicio oficina',
            'area' => 'Area oficina',
            'horario_salida' => '08:00',
            'destino_tipo' => 'OFICINA',
            'destino_id' => $oficinaId,
        ]);

        $oficina->assertStatus(201)
            ->assertJsonPath('data.destino.tipo', 'OFICINA')
            ->assertJsonPath('data.destino.id', $oficinaId);
    }

    public function test_quitar_personal_funciona_sin_asistencia_iniciada(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $grupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [$personalId]);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$grupoId.'/quitar-personal', [
            'personal_id' => $personalId,
        ]);

        $response->assertOk()->assertJsonPath('code', 'MANPOWER_GRUPO_REMOVE_PERSON_OK');
        $this->assertDatabaseMissing('grupo_trabajo_detalle', ['grupo_trabajo_id' => $grupoId, 'personal_id' => $personalId]);
    }

    public function test_bloquea_cambios_si_asistencia_ya_iniciada(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $grupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [$personalId]);

        DB::table('asistencia_encabezado')->insert([
            'id' => (string) Str::uuid(),
            'fecha' => '2026-06-01',
            'hora_ingreso' => '06:30:00',
            'mina_id' => $minaId,
            'supervisor_id' => $supervisorId,
            'actividad_realizada' => 'Inicio',
            'estado' => 'REGISTRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$grupoId.'/agregar-personal', [
            'personal_id' => $personalId,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'MANPOWER_ASSISTENCIA_LOCKED');
    }

    public function test_contexto_solo_muestra_asignaciones_activas_de_rq_proserge(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $personaSinRq = $this->crearPersonal($minaId, false);
        $personaRetirada = $this->crearPersonal($minaId, false);

        DB::table('rq_proserge_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => DB::table('rq_mina_detalle')->where('rq_mina_id', $rqMinaId)->value('id'),
            'personal_id' => $personaRetirada,
            'puesto_asignado' => 'Tecnico retirado',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-03',
            'estado' => 'RETIRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&fecha=2026-06-01&turno=DIA');

        $response->assertOk()
            ->assertJsonPath('code', 'MANPOWER_CONTEXT_OK')
            ->assertJsonFragment(['personal_id' => $supervisorId])
            ->assertJsonFragment(['personal_id' => $personalId])
            ->assertJsonMissing(['personal_id' => $personaSinRq])
            ->assertJsonMissing(['personal_id' => $personaRetirada]);
    }

    public function test_crea_grupo_con_plan_grupo_operativo_y_trazabilidad_de_asignacion(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        [$planId, $grupoOperativoId, $actividadId] = $this->crearPlanOperativo($rqMinaId);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $assignmentIds = DB::table('rq_proserge_detalle')
            ->whereIn('personal_id', [$supervisorId, $personalId])
            ->pluck('id')
            ->all();

        DB::table('rq_proserge_detalle')->whereIn('id', $assignmentIds)->update([
            'posicion_asignacion' => 'TITULAR',
            'tipo_asignacion' => 'REGULAR',
            'puesto_asignado_snapshot' => 'Tecnico snapshot',
            'estado_habilitacion_snapshot' => 'HABILITADO',
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$actividadId],
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'supervisor_id' => $supervisorId,
            'servicio' => 'Soporte planificado',
            'area' => 'Area test',
            'horario_salida' => '06:30',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
            'rq_proserge_detalle_ids' => $assignmentIds,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.rq_mina_plan_id', $planId)
            ->assertJsonPath('data.rq_mina_actividad_grupo_id', $grupoOperativoId)
            ->assertJsonPath('data.planificacion.cantidad_planificada_snapshot', 2);

        $grupoId = $response->json('data.id');

        $this->assertDatabaseHas('grupo_trabajo', [
            'id' => $grupoId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'cantidad_planificada_snapshot' => 2,
        ]);

        foreach ($assignmentIds as $assignmentId) {
            $this->assertDatabaseHas('grupo_trabajo_detalle', [
                'grupo_trabajo_id' => $grupoId,
                'rq_proserge_detalle_id' => $assignmentId,
                'puesto_asignado_snapshot' => 'Tecnico snapshot',
                'estado_distribucion' => 'ASIGNADO',
            ]);
        }

        $this->assertDatabaseHas('grupo_trabajo_actividades', [
            'grupo_trabajo_id' => $grupoId,
            'rq_mina_actividad_id' => $actividadId,
            'cantidad_planificada_snapshot' => 2,
        ]);
    }

    public function test_crea_grupo_con_plan_usando_actividad_historica_mostrada_como_respaldo(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId] = $this->crearParadaAtendida(true, true);
        [$planId, $grupoOperativoId, $actividadId] = $this->crearPlanOperativo($rqMinaId);
        DB::table('rq_mina_actividad_grupos')->where('id', $grupoOperativoId)->update([
            'rq_mina_plan_id' => null,
            'updated_at' => now(),
        ]);

        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&plan_id='.$planId.'&actividad_id='.$actividadId.'&fecha=2026-06-01&turno=DIA')
            ->assertOk()
            ->assertJsonPath('data.selected.plan_id', $planId)
            ->assertJsonPath('data.selected.actividad_id', $actividadId)
            ->assertJsonPath('data.actividad.grupo_id', $grupoOperativoId);

        $response = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$actividadId],
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'servicio' => 'SAIT historico en plan vigente',
            'area' => 'Area test',
            'horario_salida' => '07:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'BORRADOR',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.rq_mina_plan_id', $planId)
            ->assertJsonPath('data.rq_mina_actividad_grupo_id', $grupoOperativoId);

        $this->assertDatabaseHas('grupo_trabajo_actividades', [
            'grupo_trabajo_id' => $response->json('data.id'),
            'rq_mina_actividad_id' => $actividadId,
        ]);
    }

    public function test_usa_referencia_semanal_por_turno_muestra_puestos_y_no_bloquea_por_cantidad(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        [$planId, $grupoOperativoId, $actividadId] = $this->crearPlanOperativo($rqMinaId);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $turnoValues = [
            'fecha' => null,
            'dia_label' => 'Lun',
            'turno_a' => '5',
            'turno_b' => '3',
        ];
        if (Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_a')) {
            $turnoValues['real_turno_a'] = '99';
        }
        if (Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_b')) {
            $turnoValues['real_turno_b'] = '88';
        }
        DB::table('rq_mina_actividad_turnos')->where('actividad_id', $actividadId)->update($turnoValues);

        $segundaActividadId = (string) Str::uuid();
        DB::table('rq_mina_actividades')->insert([
            'id' => $segundaActividadId,
            'grupo_id' => $grupoOperativoId,
            'sait' => 'SAIT-SECUNDARIO',
            'sector' => 'Sector secundario',
            'area' => 'Area secundaria',
            'ait_trabajo' => 'Trabajo secundario',
            'orden' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rq_mina_actividad_turnos')->insert([
            'id' => (string) Str::uuid(),
            'actividad_id' => $segundaActividadId,
            'fecha' => null,
            'dia_label' => 'Lun',
            'turno_a' => '9',
            'turno_b' => '8',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grupoId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$actividadId],
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'servicio' => 'Grupo SAIT principal',
            'area' => 'Area test',
            'horario_salida' => '07:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
        ])->assertStatus(201)->json('data.id');
        $assignmentId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
        $this->withToken($token)
            ->postJson('/api/v1/man-power/grupos/'.$grupoId.'/agregar-personal', [
                'rq_proserge_detalle_id' => $assignmentId,
            ])
            ->assertOk();

        $day = $this->withToken($token)->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&plan_id='.$planId.'&fecha=2026-06-01&turno=DIA');
        $day->assertOk()
            ->assertJsonPath('data.selected.actividad_id', $actividadId)
            ->assertJsonPath('data.actividad.sait', 'SAIT-TEST')
            ->assertJsonCount(2, 'data.actividades')
            ->assertJsonPath('data.resumen.requeridos_por_plan', 5)
            ->assertJsonPath('data.grupos_operativos.0.requerido', 5)
            ->assertJsonPath('data.grupos_operativos.0.asignado', 1)
            ->assertJsonPath('data.puestos.0.puesto', 'Tecnico')
            ->assertJsonPath('data.puestos.0.cantidad_rq', 2)
            ->assertJsonPath('data.puestos.0.cantidad_atendida', 1)
            ->assertJsonPath('data.puestos.0.distribuidos_turno', 1);

        $night = $this->withToken($token)->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&plan_id='.$planId.'&fecha=2026-06-01&turno=NOCHE');
        $night->assertOk()
            ->assertJsonPath('data.resumen.requeridos_por_plan', 3)
            ->assertJsonPath('data.grupos_operativos.0.requerido', 3)
            ->assertJsonPath('data.puestos.0.distribuidos_turno', 0);

        $secondaryDay = $this->withToken($token)->getJson('/api/v1/man-power/contexto?rq_mina_id='.$rqMinaId.'&plan_id='.$planId.'&actividad_id='.$segundaActividadId.'&fecha=2026-06-01&turno=DIA');
        $secondaryDay->assertOk()
            ->assertJsonPath('data.selected.actividad_id', $segundaActividadId)
            ->assertJsonPath('data.actividad.sait', 'SAIT-SECUNDARIO')
            ->assertJsonPath('data.actividad.area', 'Area secundaria')
            ->assertJsonPath('data.actividad.sector', 'Sector secundario')
            ->assertJsonPath('data.resumen.requeridos_por_plan', 9)
            ->assertJsonPath('data.grupos_operativos.0.requerido', 9);

        $mixedCreate = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$actividadId, $segundaActividadId],
            'fecha' => '2026-06-01',
            'turno' => 'NOCHE',
            'servicio' => 'Grupo sin limite rigido',
            'area' => 'Area test',
            'horario_salida' => '19:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
        ]);
        $mixedCreate->assertStatus(422)
            ->assertJsonPath('code', 'MANPOWER_SAIT_REQUIRED');

        $create = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$segundaActividadId],
            'fecha' => '2026-06-01',
            'turno' => 'NOCHE',
            'servicio' => 'Grupo SAIT secundario',
            'area' => 'Area secundaria',
            'horario_salida' => '19:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.planificacion.cantidad_planificada_snapshot', 8)
            ->assertJsonPath('data.planificacion.sector_snapshot', 'Sector secundario');

        $this->assertDatabaseHas('grupo_trabajo_actividades', [
            'grupo_trabajo_id' => $create->json('data.id'),
            'rq_mina_actividad_id' => $segundaActividadId,
            'cantidad_planificada_snapshot' => 8,
        ]);

        $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$segundaActividadId],
            'fecha' => '2026-06-01',
            'turno' => 'NOCHE',
            'servicio' => 'Duplicado',
            'area' => 'Area secundaria',
            'horario_salida' => '19:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'MANPOWER_SAIT_GROUP_EXISTS');

        if (Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_a')) {
            $this->assertSame('99', (string) DB::table('rq_mina_actividad_turnos')->where('actividad_id', $actividadId)->value('real_turno_a'));
        }
        if (Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_b')) {
            $this->assertSame('88', (string) DB::table('rq_mina_actividad_turnos')->where('actividad_id', $actividadId)->value('real_turno_b'));
        }
    }

    public function test_resumen_general_acumula_el_periodo_y_no_solo_el_dia_seleccionado(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        [$planId, $grupoOperativoId, $actividadId] = $this->crearPlanOperativo($rqMinaId);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        DB::table('rq_mina_actividad_turnos')->where('actividad_id', $actividadId)->update([
            'fecha' => null,
            'dia_label' => 'Lun',
            'turno_a' => '5',
            'turno_b' => '3',
        ]);

        $groupId = $this->withToken($token)->postJson('/api/v1/man-power/grupos', [
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'actividad_ids' => [$actividadId],
            'fecha' => '2026-06-01',
            'turno' => 'DIA',
            'servicio' => 'Grupo resumen periodo',
            'area' => 'Area test',
            'horario_salida' => '07:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
        ])->assertCreated()->json('data.id');
        $assignmentId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');
        $this->withToken($token)->postJson('/api/v1/man-power/grupos/'.$groupId.'/agregar-personal', [
            'rq_proserge_detalle_id' => $assignmentId,
        ])->assertOk();

        $summary = app(ManPowerPlanningService::class)->buildPeriodSummary(
            Usuario::query()->findOrFail($usuarioId),
            [
                'rq_mina_id' => $rqMinaId,
                'plan_id' => $planId,
                'actividad_id' => $actividadId,
                'fecha' => '2026-06-04',
            ],
        );

        $this->assertSame('2026-06-01', $summary['fecha_inicio']);
        $this->assertSame('2026-06-05', $summary['fecha_fin']);
        $this->assertSame(5, $summary['dias_periodo']);
        $this->assertSame(1, $summary['dias_con_referencia']);
        $this->assertSame(1, $summary['dias_con_grupos']);
        $this->assertSame(8, $summary['referencia_total']);
        $this->assertSame(1, $summary['distribuciones_total']);
        $this->assertSame(1, $summary['personal_unico_distribuido']);
        $this->assertSame(5, $summary['turnos']['DIA']['referencia']);
        $this->assertSame(3, $summary['turnos']['NOCHE']['referencia']);
    }

    public function test_backfill_dry_run_no_modifica_y_real_vincula_coincidencia_unica(): void
    {
        [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $personalId] = $this->crearParadaAtendida(true, true);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $grupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId, [$personalId]);
        $detalleId = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupoId)
            ->where('personal_id', $personalId)
            ->value('id');

        $this->artisan('man-power:backfill-traceability --dry-run')->assertExitCode(0);
        $this->assertDatabaseHas('grupo_trabajo_detalle', [
            'id' => $detalleId,
            'rq_proserge_detalle_id' => null,
        ]);

        $this->artisan('man-power:backfill-traceability')->assertExitCode(0);
        $assignmentId = DB::table('rq_proserge_detalle')->where('personal_id', $personalId)->value('id');

        $this->assertDatabaseHas('grupo_trabajo_detalle', [
            'id' => $detalleId,
            'rq_proserge_detalle_id' => $assignmentId,
            'estado_distribucion' => 'ASIGNADO',
        ]);

        $this->artisan('man-power:backfill-traceability')->assertExitCode(0);
        $this->assertSame(1, DB::table('grupo_trabajo_detalle')->where('id', $detalleId)->where('rq_proserge_detalle_id', $assignmentId)->count());
    }

    private function crearParadaAtendida(bool $extended = false, bool $withApprovedWorker = false): array
    {
        $minaId = $this->crearMina();
        $plannerId = $this->crearUsuario($this->rolPlannerId);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);

        $rqMinaId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $minaId,
            'area' => 'Area',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-05',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $plannerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rqMinaDetalleId = (string) Str::uuid();
        DB::table('rq_mina_detalle')->insert([
            'id' => $rqMinaDetalleId,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'Tecnico',
            'cantidad' => 2,
            'cantidad_atendida' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rqProsergeId = (string) Str::uuid();
        DB::table('rq_proserge')->insert([
            'id' => $rqProsergeId,
            'rq_mina_id' => $rqMinaId,
            'mina_id' => $minaId,
            'responsable_rrhh_id' => $rrhhId,
            'estado' => 'BORRADOR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supervisorId = $this->crearPersonal($minaId, true);
        DB::table('rq_proserge_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $supervisorId,
            'puesto_asignado' => 'Supervisor',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-03',
            'estado' => 'ASIGNADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $approvedId = null;
        if ($withApprovedWorker) {
            $approvedId = $this->crearPersonal($minaId, false);
            DB::table('rq_proserge_detalle')->insert([
                'id' => (string) Str::uuid(),
                'rq_proserge_id' => $rqProsergeId,
                'rq_mina_detalle_id' => $rqMinaDetalleId,
                'personal_id' => $approvedId,
                'puesto_asignado' => 'Tecnico',
                'fecha_inicio' => '2026-06-01',
                'fecha_fin' => '2026-06-03',
                'estado' => 'ASIGNADO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!$extended) {
            return [$minaId, $rqMinaId];
        }

        if ($withApprovedWorker) {
            return [$minaId, $rqMinaId, $rqProsergeId, $supervisorId, $approvedId];
        }

        return [$minaId, $rqMinaId, $rqProsergeId, $supervisorId];
    }

    private function crearParadaNoAtendida(string $minaId): string
    {
        $plannerId = $this->crearUsuario($this->rolPlannerId);
        $rqMinaId = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $minaId,
            'area' => 'Sin atender',
            'fecha_inicio' => '2026-06-10',
            'fecha_fin' => '2026-06-11',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $plannerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'Tecnico',
            'cantidad' => 1,
            'cantidad_atendida' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rqMinaId;
    }

    private function crearGrupo(string $rqMinaId, string $rqProsergeId, string $supervisorId, string $usuarioId, array $personalIds = [], string $turno = 'DIA'): string
    {
        $id = (string) Str::uuid();
        $minaId = DB::table('rq_mina')->where('id', $rqMinaId)->value('mina_id');

        DB::table('grupo_trabajo')->insert([
            'id' => $id,
            'fecha' => '2026-06-01',
            'supervisor_id' => $supervisorId,
            'mina' => 'Destino',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProsergeId,
            'servicio' => 'Servicio',
            'area' => 'Area',
            'horario_salida' => '06:30:00',
            'turno' => $turno,
            'estado' => 'BORRADOR',
            'created_by_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($personalIds as $personalId) {
            DB::table('grupo_trabajo_detalle')->insert([
                'id' => (string) Str::uuid(),
                'grupo_trabajo_id' => $id,
                'personal_id' => $personalId,
                'estado_asistencia' => 'AUSENTE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function crearPlanOperativo(string $rqMinaId): array
    {
        $planId = (string) Str::uuid();
        DB::table('rq_mina_planes')->insert([
            'id' => $planId,
            'rq_mina_id' => $rqMinaId,
            'codigo' => 'PLAN-TEST',
            'nombre' => 'Plan test',
            'version' => 1,
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-05',
            'estado' => 'VIGENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grupoId = (string) Str::uuid();
        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $grupoId,
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'area_operativa' => 'AQP',
            'modulo' => 'C2',
            'nombre' => 'Grupo operativo test',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actividadId = (string) Str::uuid();
        DB::table('rq_mina_actividades')->insert([
            'id' => $actividadId,
            'grupo_id' => $grupoId,
            'sait' => 'SAIT-TEST',
            'sector' => 'Sector test',
            'area' => 'Area test',
            'ait_trabajo' => 'Trabajo test',
            'supervisor_campo_dia' => 'Supervisor operativo',
            'supervisor_seguridad_dia' => 'Supervisor seguridad',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_turnos')->insert([
            'id' => (string) Str::uuid(),
            'actividad_id' => $actividadId,
            'fecha' => '2026-06-01',
            'dia_label' => 'Lun',
            'turno_a' => '2',
            'turno_b' => '1',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$planId, $grupoId, $actividadId];
    }

    private function crearMina(): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'Mina '.Str::upper(Str::random(4)),
            'unidad_minera' => 'UM '.Str::upper(Str::random(3)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function crearUsuario(string $rolId): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(10)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
            'personal_id' => null,
        ]);

        return $id;
    }

    private function asignarScope(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
    }

    private function crearToken(string $usuarioId): string
    {
        $plain = Str::random(80);

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    private function crearPersonal(string $minaId, bool $esSupervisor): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Personal '.Str::upper(Str::random(4)),
            'puesto' => $esSupervisor ? 'Supervisor' : 'Tecnico',
            'es_supervisor' => $esSupervisor ? 1 : 0,
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $id,
            'mina_id' => $minaId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
