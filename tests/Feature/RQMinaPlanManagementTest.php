<?php

namespace Tests\Feature;

use App\Models\RQMina;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaPlan;
use App\Models\Usuario;
use App\Modules\RQMina\Services\RQMinaPlanService;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RQMinaPlanManagementTest extends TestCase
{
    use DatabaseTransactions;

    private string $adminRoleId;

    private string $plannerRoleId;

    private string $readOnlyRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRoleId = (string) Str::uuid();
        $this->plannerRoleId = (string) Str::uuid();
        $this->readOnlyRoleId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $this->adminRoleId,
                'nombre' => 'ADMIN',
                'permisos' => json_encode([]),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->plannerRoleId,
                'nombre' => 'PLANNER',
                'permisos' => json_encode(['rq_mina.read', 'rq_mina.write']),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->readOnlyRoleId,
                'nombre' => 'LECTOR_RQ',
                'permisos' => json_encode(['rq_mina.read']),
                'estado' => 'ACTIVO',
            ],
        ]);
    }

    public function test_codigos_son_correlativos_por_parada_y_respetan_rango(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $rqA = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $rqB = $this->createRQMina($minaId, $usuarioId, '2026-05-01', '2026-05-31');
        $service = app(RQMinaPlanService::class);

        $defaultA = $service->ensureDefaultPlan($rqA);
        $defaultB = $service->ensureDefaultPlan($rqB);
        $planA2 = $service->createPlan($rqA, [
            'nombre' => 'Plan dos A',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-15',
            'estado' => RQMinaPlan::ESTADO_VIGENTE,
        ]);
        $planA3 = $service->createPlan($rqA, [
            'nombre' => 'Plan tres A',
            'fecha_inicio' => '2026-04-16',
            'fecha_fin' => '2026-04-20',
        ]);
        $planB2 = $service->createPlan($rqB, [
            'nombre' => 'Plan dos B',
            'fecha_inicio' => '2026-05-10',
            'fecha_fin' => '2026-05-15',
        ]);

        $this->assertSame('PLAN-001', $defaultA->codigo);
        $this->assertSame('PLAN-001', $defaultB->codigo);
        $this->assertSame('PLAN-002', $planA2->codigo);
        $this->assertSame('PLAN-003', $planA3->codigo);
        $this->assertSame('PLAN-002', $planB2->codigo);
        $this->assertSame('VIGENTE', $planA2->estado);
        $this->assertStringContainsString('Semana', $planA2->semana_referencia);

        $this->expectException(InvalidArgumentException::class);
        $service->createPlan($rqA, [
            'nombre' => 'Fuera de rango',
            'fecha_inicio' => '2026-03-31',
            'fecha_fin' => '2026-04-02',
        ]);
    }

    public function test_edita_plan_y_bloquea_archivado(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $service = app(RQMinaPlanService::class);
        $plan = $service->createPlan($rq, [
            'nombre' => 'Plan editable',
            'fecha_inicio' => '2026-04-05',
            'fecha_fin' => '2026-04-15',
        ]);

        $updated = $service->updatePlan($rq, $plan, [
            'nombre' => 'Plan actualizado',
            'fecha_inicio' => '2026-04-06',
            'fecha_fin' => '2026-04-16',
            'semana_referencia' => 'Semana operativa 15',
            'estado' => RQMinaPlan::ESTADO_VIGENTE,
            'observaciones' => 'Ajuste aprobado',
        ]);

        $this->assertSame('Plan actualizado', $updated->nombre);
        $this->assertSame('2026-04-06', $updated->fecha_inicio->toDateString());
        $this->assertSame('2026-04-16', $updated->fecha_fin->toDateString());
        $this->assertSame('Semana operativa 15', $updated->semana_referencia);
        $this->assertSame(RQMinaPlan::ESTADO_VIGENTE, $updated->estado);
        $this->assertSame('Ajuste aprobado', $updated->observaciones);

        $service->ensureDefaultPlan($rq);
        $archived = $service->archivePlan($rq, $updated);

        $this->expectException(InvalidArgumentException::class);
        $service->updatePlan($rq, $archived, [
            'nombre' => 'No debe editar',
            'fecha_inicio' => '2026-04-06',
            'fecha_fin' => '2026-04-16',
            'estado' => RQMinaPlan::ESTADO_BORRADOR,
        ]);
    }

    public function test_no_reduce_rango_si_existen_turnos_o_transportes_fuera(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $service = app(RQMinaPlanService::class);
        $plan = $service->ensureDefaultPlan($rq);
        $this->createPlanStructure((string) $rq->id, (string) $plan->id, '2026-04-05', '2026-04-20');

        try {
            $service->updatePlan($rq, $plan, [
                'nombre' => $plan->nombre,
                'fecha_inicio' => '2026-04-06',
                'fecha_fin' => '2026-04-19',
                'estado' => RQMinaPlan::ESTADO_BORRADOR,
            ]);
            $this->fail('El plan no debio permitir reducir fechas con registros fuera.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('turnos fuera', $exception->getMessage());
            $this->assertStringContainsString('transportes fuera', $exception->getMessage());
        }
    }

    public function test_seleccion_query_muestra_legacy_solo_en_plan_inicial(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $service = app(RQMinaPlanService::class);
        $planUno = $service->ensureDefaultPlan($rq);
        $planDos = $service->createPlan($rq, [
            'nombre' => 'Plan dos visible',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-20',
        ]);

        DB::table('rq_mina_actividad_grupos')->insert([
            [
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rq->id,
                'rq_mina_plan_id' => null,
                'nombre' => 'Grupo legacy inicial',
                'orden' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rq->id,
                'rq_mina_plan_id' => (string) $planDos->id,
                'nombre' => 'Grupo exclusivo plan dos',
                'orden' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $session = $this->sessionFor($usuarioId);

        $this->withSession($session)
            ->get('/rq-mina/' . $rq->id . '/plan?plan_id=' . $planUno->id)
            ->assertOk()
            ->assertSee('Grupo legacy inicial')
            ->assertDontSee('Grupo exclusivo plan dos');

        $this->withSession($session)
            ->get('/rq-mina/' . $rq->id . '/plan?plan_id=' . $planDos->id)
            ->assertOk()
            ->assertSee('Grupo exclusivo plan dos')
            ->assertDontSee('Grupo legacy inicial');
    }

    public function test_guardar_plan_dos_no_elimina_plan_uno_y_payload_legacy_opera_plan_inicial(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $usuario = Usuario::query()->with('rol')->findOrFail($usuarioId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $planService = app(RQMinaPlanService::class);
        $rqService = app(RQMinaService::class);
        $planUno = $planService->ensureDefaultPlan($rq, $usuario);
        $planDos = $planService->createPlan($rq, [
            'nombre' => 'Plan dos',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-20',
        ], $usuario);
        $grupoPlanUno = $this->createPlanStructure((string) $rq->id, (string) $planUno->id, '2026-04-02', '2026-04-03')['grupo_id'];

        $updatedRq = $rqService->updatePlanOperativo($usuario, $rq, [
            ['nombre' => 'Grupo nuevo plan dos', 'actividades' => [['sait' => 'SAIT-PLAN2']]],
        ], null, (string) $planDos->id);

        $this->assertNotNull($updatedRq);
        $this->assertDatabaseHas('rq_mina_actividad_grupos', [
            'id' => $grupoPlanUno,
            'rq_mina_plan_id' => (string) $planUno->id,
        ]);
        $this->assertDatabaseHas('rq_mina_actividad_grupos', [
            'rq_mina_plan_id' => (string) $planDos->id,
            'nombre' => 'Grupo nuevo plan dos',
        ]);

        $rqService->updatePlanOperativo($usuario, $rq->fresh(), [
            ['nombre' => 'Grupo legacy reemplaza inicial', 'actividades' => [['sait' => 'SAIT-LEGACY']]],
        ]);

        $this->assertDatabaseHas('rq_mina_actividad_grupos', [
            'rq_mina_plan_id' => (string) $planUno->id,
            'nombre' => 'Grupo legacy reemplaza inicial',
        ]);
        $this->assertDatabaseHas('rq_mina_actividad_grupos', [
            'rq_mina_plan_id' => (string) $planDos->id,
            'nombre' => 'Grupo nuevo plan dos',
        ]);
    }

    public function test_duplicar_copia_estructura_desplaza_fechas_y_limpia_ejecucion(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-05-31');
        $service = app(RQMinaPlanService::class);
        $source = $service->ensureDefaultPlan($rq);
        $sourceIds = $this->createPlanStructure((string) $rq->id, (string) $source->id, '2026-04-05', '2026-04-07');
        $this->createTransportEvent((string) $rq->id, $sourceIds['transporte_id'], $usuarioId);

        $duplicate = $service->duplicatePlan($rq, $source, [
            'nombre' => 'Plan duplicado',
            'fecha_inicio' => '2026-04-12',
            'fecha_fin' => '2026-04-18',
            'observaciones' => 'Copia limpia',
        ]);

        $newGroup = DB::table('rq_mina_actividad_grupos')->where('rq_mina_plan_id', $duplicate->id)->first();
        $newActivity = DB::table('rq_mina_actividades')->where('grupo_id', $newGroup->id)->first();
        $newTurno = DB::table('rq_mina_actividad_turnos')->where('actividad_id', $newActivity->id)->first();
        $newTransport = DB::table('rq_mina_actividad_transportes')->where('grupo_id', $newGroup->id)->first();

        $this->assertSame('PLAN-002', $duplicate->codigo);
        $this->assertSame('2026-04-16', (string) $newTurno->fecha);
        $this->assertSame('4', (string) $newTurno->turno_a);
        $this->assertNull($newTurno->real_turno_a);
        $this->assertNull($newTurno->real_turno_b);
        $this->assertNull($newTurno->real);
        $this->assertSame('2026-04-16', (string) $newTransport->fecha_inicio);
        $this->assertSame('2026-04-18', (string) $newTransport->fecha_fin);
        $this->assertSame(3, (int) $newTransport->dias_uso);
        $this->assertNull($newTransport->placas_asignadas);
        $this->assertSame(RQMinaActividadTransporte::ESTADO_REQUERIDO, $newTransport->estado_logistico);
        $this->assertNull($newTransport->comentario_cambio);
        $this->assertNull($newTransport->incidencia_operativa);
        $this->assertNull($newTransport->recepcion_fecha);
        $this->assertSame(RQMinaActividadTransporte::RECEPCION_PENDIENTE, $newTransport->recepcion_estado);
        $this->assertNull($newTransport->recepcion_observacion);

        if (Schema::hasTable('rq_mina_actividad_transporte_eventos')) {
            $this->assertDatabaseMissing('rq_mina_actividad_transporte_eventos', [
                'transporte_id' => $newTransport->id,
            ]);
        }
    }

    public function test_duplicacion_insuficiente_hace_rollback(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-05-31');
        $service = app(RQMinaPlanService::class);
        $source = $service->ensureDefaultPlan($rq);
        $this->createPlanStructure((string) $rq->id, (string) $source->id, '2026-04-05', '2026-04-07');
        $before = DB::table('rq_mina_planes')->where('rq_mina_id', $rq->id)->count();

        try {
            $service->duplicatePlan($rq, $source, [
                'nombre' => 'Plan incompleto',
                'fecha_inicio' => '2026-04-12',
                'fecha_fin' => '2026-04-13',
            ]);
            $this->fail('La duplicacion debio rechazarse por rango insuficiente.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no contiene toda la estructura', $exception->getMessage());
        }

        $this->assertSame($before, DB::table('rq_mina_planes')->where('rq_mina_id', $rq->id)->count());
        $this->assertDatabaseMissing('rq_mina_planes', [
            'rq_mina_id' => (string) $rq->id,
            'nombre' => 'Plan incompleto',
        ]);
    }

    public function test_reglas_de_archivo_y_permisos_web(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $rq = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $service = app(RQMinaPlanService::class);
        $default = $service->ensureDefaultPlan($rq);

        try {
            $service->archivePlan($rq, $default);
            $this->fail('No debio archivarse el unico plan activo.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('al menos un plan', $exception->getMessage());
        }

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => (string) $rq->id,
            'rq_mina_plan_id' => null,
            'nombre' => 'Grupo legacy',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planDos = $service->createPlan($rq, [
            'nombre' => 'Plan dos',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-15',
        ]);

        try {
            $service->archivePlan($rq, $default);
            $this->fail('PLAN-001 no debio archivarse con grupos legacy.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('grupos historicos', $exception->getMessage());
        }

        $archived = $service->archivePlan($rq, $planDos);
        $this->withSession($this->sessionFor($usuarioId))
            ->get('/rq-mina/' . $rq->id . '/plan?plan_id=' . $archived->id)
            ->assertOk()
            ->assertSee('Este plan esta archivado');

        $readOnlyUserId = $this->createUsuario($this->readOnlyRoleId);
        $this->assignMinaScope($readOnlyUserId, $minaId);
        $this->withSession($this->sessionFor($readOnlyUserId))
            ->postJson('/rq-mina/' . $rq->id . '/planes', [
                'nombre' => 'Sin permiso',
                'fecha_inicio' => '2026-04-20',
                'fecha_fin' => '2026-04-21',
            ])
            ->assertStatus(403);

        $noScopeUserId = $this->createUsuario($this->plannerRoleId);
        $this->withSession($this->sessionFor($noScopeUserId))
            ->postJson('/rq-mina/' . $rq->id . '/planes', [
                'nombre' => 'Sin scope',
                'fecha_inicio' => '2026-04-20',
                'fecha_fin' => '2026-04-21',
            ])
            ->assertStatus(404);
    }

    public function test_no_opera_plan_de_otra_parada_por_ruta_web(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->plannerRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $rqA = $this->createRQMina($minaId, $usuarioId, '2026-04-01', '2026-04-30');
        $rqB = $this->createRQMina($minaId, $usuarioId, '2026-05-01', '2026-05-31');
        $service = app(RQMinaPlanService::class);
        $planB = $service->ensureDefaultPlan($rqB);

        $this->withSession($this->sessionFor($usuarioId))
            ->putJson('/rq-mina/' . $rqA->id . '/planes/' . $planB->id, [
                'nombre' => 'Cruce indebido',
                'fecha_inicio' => '2026-04-10',
                'fecha_fin' => '2026-04-15',
                'estado' => RQMinaPlan::ESTADO_BORRADOR,
            ])
            ->assertStatus(404);
    }

    private function createMina(): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'Mina ' . Str::upper(Str::random(4)),
            'unidad_minera' => 'UM ' . Str::upper(Str::random(3)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createUsuario(string $rolId): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
            'personal_id' => null,
        ]);

        return $id;
    }

    private function assignMinaScope(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
    }

    private function createRQMina(string $minaId, string $usuarioId, string $fechaInicio, string $fechaFin): RQMina
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'Area base',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'observaciones' => null,
            'estado' => 'BORRADOR',
            'created_by_usuario_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_detalle')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $id,
            'puesto' => 'Tecnico',
            'cantidad' => 1,
            'cantidad_atendida' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return RQMina::query()->findOrFail($id);
    }

    private function createPlanStructure(string $rqMinaId, string $planId, string $fechaInicio, string $fechaFin): array
    {
        $grupoId = (string) Str::uuid();
        $actividadId = (string) Str::uuid();
        $turnoId = (string) Str::uuid();
        $transporteId = (string) Str::uuid();

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $grupoId,
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'area_operativa' => 'C1',
            'modulo' => 'MOLIENDA',
            'nombre' => 'Grupo operativo',
            'observaciones' => 'Base',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividades')->insert([
            'id' => $actividadId,
            'grupo_id' => $grupoId,
            'sait' => 'SAIT-100',
            'sector' => 'Sector A',
            'area' => 'Area C1',
            'ait_trabajo' => 'AIT-01',
            'detalle_trabajos_relevantes' => 'Cambio de liner',
            'supervisor_campo_dia' => 'Supervisor Dia',
            'supervisor_campo_noche' => 'Supervisor Noche',
            'supervisor_seguridad_dia' => 'SSOMA Dia',
            'supervisor_seguridad_noche' => 'SSOMA Noche',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_turnos')->insert([
            'id' => $turnoId,
            'actividad_id' => $actividadId,
            'fecha' => $fechaInicio,
            'dia_label' => 'Dia base',
            'turno_a' => '4',
            'real_turno_a' => '3',
            'turno_b' => '2',
            'real_turno_b' => '1',
            'real' => '1',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transport = [
            'id' => $transporteId,
            'grupo_id' => $grupoId,
            'actividad_id' => $actividadId,
            'alcance' => 'SAIT-100',
            'unidad_carga' => 'Grua 80T',
            'origen' => 'ALQUILADO',
            'unidades_transporte' => 'Van 15',
            'placas_asignadas' => 'ABC-123',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'dias_uso' => 3,
            'estado_logistico' => RQMinaActividadTransporte::ESTADO_ASIGNADO,
            'indicaciones' => 'Desde turno A',
            'comentario_cambio' => 'Cambio validado',
            'incidencia_operativa' => 'Incidencia fuente',
            'recepcion_fecha' => '2026-04-30',
            'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_RECIBIDO,
            'recepcion_observacion' => 'Retorno conforme',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('rq_mina_actividad_transportes', 'documentos')) {
            $transport['documentos'] = json_encode(['doc' => 'archivo.pdf']);
        }

        DB::table('rq_mina_actividad_transportes')->insert($transport);

        return [
            'grupo_id' => $grupoId,
            'actividad_id' => $actividadId,
            'turno_id' => $turnoId,
            'transporte_id' => $transporteId,
        ];
    }

    private function createTransportEvent(string $rqMinaId, string $transporteId, string $usuarioId): void
    {
        if (!Schema::hasTable('rq_mina_actividad_transporte_eventos')) {
            return;
        }

        DB::table('rq_mina_actividad_transporte_eventos')->insert([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $rqMinaId,
            'transporte_id' => $transporteId,
            'tipo' => 'CAMBIO_REQUERIMIENTO',
            'estado_anterior' => 'REQUERIDO',
            'estado_nuevo' => 'ASIGNADO',
            'descripcion' => 'Evento fuente',
            'transporte_snapshot' => json_encode(['placa' => 'ABC-123']),
            'fecha_evento' => now(),
            'usuario_id' => $usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sessionFor(string $usuarioId): array
    {
        $usuario = Usuario::query()->with('rol')->findOrFail($usuarioId);
        $permissions = $usuario->rol?->permisos ?? [];
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?: [];
        }

        return [
            'auth_token' => 'test-token-' . $usuarioId,
            'user_id' => $usuarioId,
            'user' => [
                'id' => $usuarioId,
                'email' => $usuario->email,
                'rol' => $usuario->rol?->nombre ?? 'Usuario',
                'permissions' => is_array($permissions) ? $permissions : [],
            ],
        ];
    }
}
