<?php

namespace Tests\Feature;

use App\Models\TransporteServicio;
use App\Models\TransporteServicioPasajero;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransporteApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rolId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $this->rolId,
            'nombre' => 'TRANSPORTE_TEST',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'transportes' => ['ver', 'crear', 'editar', 'actualizar', 'entregar', 'recepcionar', 'administrar'],
                'man_power' => ['ver', 'crear', 'asignar'],
            ])),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_crea_servicio_personal_con_dos_alcances(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);

        $response = $this->withToken($token)->postJson('/api/v1/transporte/servicios', [
            'rq_mina_id' => $fixture['rq_mina_id'],
            'rq_mina_plan_id' => $fixture['plan_id'],
            'tipo' => 'PERSONAL',
            'fecha' => '2026-08-01',
            'turno' => 'A',
            'placa' => 'ABC-123',
            'conductor_personal_id' => $fixture['conductor_id'],
            'capacidad' => 20,
            'alcances' => [
                ['rq_mina_actividad_grupo_id' => $fixture['grupo_operativo_id']],
                ['grupo_trabajo_id' => $fixture['grupo_trabajo_id']],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('code', 'TRANSPORTE_CREATE_OK')
            ->assertJsonPath('data.placa', 'ABC-123')
            ->assertJsonCount(2, 'data.alcances');
    }

    public function test_servicio_carga_no_admite_pasajeros(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $serviceId = $this->crearServicio($fixture, ['tipo' => TransporteServicio::TIPO_CARGA, 'capacidad' => null]);

        $response = $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'TRANSPORTE_CARGA_NO_PASAJEROS');
    }

    public function test_asigna_pasajero_activo_y_calcula_ocupacion(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $serviceId = $this->crearServicio($fixture, ['capacidad' => 2]);

        $response = $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ]);

        $response->assertOk()
            ->assertJsonPath('code', 'TRANSPORTE_PASSENGERS_OK')
            ->assertJsonPath('data.metricas.ocupacion', 1)
            ->assertJsonPath('data.metricas.asientos_disponibles', 1);
    }

    public function test_no_excede_capacidad_silenciosamente(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $serviceId = $this->crearServicio($fixture, ['capacidad' => 0]);

        $response = $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'TRANSPORTE_CAPACITY_EXCEEDED');
    }

    public function test_confirmar_requiere_placa_conductor_capacidad_y_alcance(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $serviceId = $this->crearServicio($fixture, ['placa' => null, 'conductor_personal_id' => null, 'capacidad' => null]);

        $response = $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/estado", [
            'estado' => TransporteServicio::ESTADO_CONFIRMADO,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'TRANSPORTE_CONFIRM_REQUIRES_PLATE');
    }

    public function test_no_duplica_placa_en_misma_fecha_turno_tramo(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $this->crearServicio($fixture, ['placa' => 'DUP-123']);

        $response = $this->withToken($token)->postJson('/api/v1/transporte/servicios', [
            'rq_mina_id' => $fixture['rq_mina_id'],
            'rq_mina_plan_id' => $fixture['plan_id'],
            'tipo' => 'PERSONAL',
            'fecha' => '2026-08-01',
            'turno' => 'A',
            'tramo' => 'IDA',
            'placa' => 'DUP-123',
            'conductor_personal_id' => $fixture['conductor_id'],
            'capacidad' => 10,
            'alcances' => [['grupo_trabajo_id' => $fixture['grupo_trabajo_id']]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'TRANSPORTE_PLATE_DUPLICATED');
    }

    public function test_copiar_servicio_crea_borrador_sin_pasajeros(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $serviceId = $this->crearServicio($fixture, ['capacidad' => 2]);

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertOk();

        $response = $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/copiar", [
            'fecha' => '2026-08-02',
            'turno' => 'A',
            'copiar_placa' => false,
            'copiar_conductor' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', TransporteServicio::ESTADO_BORRADOR)
            ->assertJsonPath('data.placa', null)
            ->assertJsonCount(0, 'data.pasajeros');
    }

    public function test_retirar_integrante_retira_pasajero_activo(): void
    {
        $fixture = $this->fixture();
        $serviceId = $this->crearServicio($fixture, ['capacidad' => 2]);
        $passengerId = (string) Str::uuid();

        DB::table('transporte_servicio_pasajeros')->insert([
            'id' => $passengerId,
            'transporte_servicio_id' => $serviceId,
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
            'personal_id' => $fixture['personal_id'],
            'tramo' => 'IDA',
            'estado' => TransporteServicioPasajero::ESTADO_ASIGNADO,
            'asignado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $this->withToken($token)->postJson("/api/v1/man-power/grupos/{$fixture['grupo_trabajo_id']}/integrantes/{$fixture['detalle_id']}/retirar", [
            'motivo' => 'Retiro de prueba',
        ])->assertOk();

        $this->assertDatabaseHas('transporte_servicio_pasajeros', [
            'id' => $passengerId,
            'estado' => TransporteServicioPasajero::ESTADO_RETIRADO,
            'motivo_retiro' => 'INTEGRANTE_RETIRADO_DE_MAN_POWER',
        ]);
    }

    public function test_backfill_dry_run_no_modifica(): void
    {
        $fixture = $this->fixture();

        $this->legacyTransport($fixture, ['placas_asignadas' => 'DRY-123']);

        $this->artisan('transporte:backfill-servicios --dry-run')->assertExitCode(0);

        $this->assertDatabaseMissing('transporte_servicios', ['placa' => 'DRY-123']);
    }

    public function test_valida_plan_fecha_turno_y_plan_archivado(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture, [
            'fecha' => '2026-08-10',
        ]))->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_DATE_OUT_OF_RQ');

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture, [
            'turno' => 'C',
        ]))->assertStatus(422);

        DB::table('rq_mina_planes')->where('id', $fixture['plan_id'])->update(['estado' => 'ARCHIVADO']);

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSPORTE_PLAN_ARCHIVED');
    }

    public function test_valida_pertenencia_de_plan_grupo_y_actividad(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $other = $this->fixture();

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture, [
            'rq_mina_plan_id' => $other['plan_id'],
        ]))->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_PLAN_INVALID');

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture, [
            'alcances' => [['rq_mina_actividad_grupo_id' => $other['grupo_operativo_id']]],
        ]))->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture, [
            'alcances' => [['rq_mina_actividad_id' => $other['actividad_id']]],
        ]))->assertStatus(422);
    }

    public function test_confirma_exige_alcance_y_no_permite_sobreocupacion(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $sinAlcance = $this->crearServicio($fixture, ['capacidad' => 1], false);

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$sinAlcance}/estado", [
            'estado' => TransporteServicio::ESTADO_CONFIRMADO,
        ])->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_CONFIRM_REQUIRES_SCOPE');

        $sobreocupado = $this->crearServicio($fixture, ['capacidad' => 1]);
        $detalleDosId = $this->extraDetalle($fixture);
        foreach ([$fixture['detalle_id'] => $fixture['personal_id'], $detalleDosId => DB::table('grupo_trabajo_detalle')->where('id', $detalleDosId)->value('personal_id')] as $detalleId => $personalId) {
            DB::table('transporte_servicio_pasajeros')->insert([
                'id' => (string) Str::uuid(),
                'transporte_servicio_id' => $sobreocupado,
                'grupo_trabajo_detalle_id' => $detalleId,
                'personal_id' => $personalId,
                'tramo' => 'IDA',
                'estado' => TransporteServicioPasajero::ESTADO_ASIGNADO,
                'asignado_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$sobreocupado}/estado", [
            'estado' => TransporteServicio::ESTADO_CONFIRMADO,
        ])->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_CONFIRM_OVERBOOKED');
    }

    public function test_no_duplica_conductor_en_misma_fecha_turno_tramo(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $this->crearServicio($fixture, ['placa' => 'DRV-001']);

        $this->withToken($token)->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture, [
            'placa' => 'DRV-002',
            'conductor_personal_id' => $fixture['conductor_id'],
        ]))->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_DRIVER_DUPLICATED');
    }

    public function test_pasajeros_filtra_retirados_reubicados_fecha_turno_y_grupo(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);

        foreach (['RETIRADO', 'REUBICADO'] as $estado) {
            DB::table('grupo_trabajo_detalle')->where('id', $fixture['detalle_id'])->update(['estado_distribucion' => $estado]);
            $serviceId = $this->crearServicio($fixture, ['placa' => 'FLT-'.$estado, 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);
            $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros", [
                'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
            ])->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_PASSENGER_CONTEXT_INVALID');
        }

        DB::table('grupo_trabajo_detalle')->where('id', $fixture['detalle_id'])->update(['estado_distribucion' => 'ASIGNADO']);
        $otherService = $this->crearServicio($fixture, ['fecha' => '2026-08-02', 'placa' => 'DATE-01', 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);
        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$otherService}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_PASSENGER_CONTEXT_INVALID');
    }

    public function test_no_duplica_pasajero_en_mismo_tramo_y_permite_retorno(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $idaUno = $this->crearServicio($fixture, ['placa' => 'IDA-01', 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);
        $idaDos = $this->crearServicio($fixture, ['placa' => 'IDA-02', 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);
        $retorno = $this->crearServicio($fixture, ['placa' => 'RET-01', 'tramo' => 'RETORNO', 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$idaUno}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$idaDos}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertStatus(422)->assertJsonPath('code', 'TRANSPORTE_PASSENGER_CONTEXT_INVALID');

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$retorno}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertOk();
    }

    public function test_retiro_de_pasajero_exige_motivo_y_no_retira_integrante(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $serviceId = $this->crearServicio($fixture, ['capacidad' => 2]);
        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertOk();
        $pasajeroId = (string) DB::table('transporte_servicio_pasajeros')->where('transporte_servicio_id', $serviceId)->value('id');

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros/{$pasajeroId}/retirar", [])
            ->assertStatus(422);

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$serviceId}/pasajeros/{$pasajeroId}/retirar", [
            'motivo' => 'Cambio operativo',
        ])->assertOk();

        $this->assertDatabaseHas('grupo_trabajo_detalle', [
            'id' => $fixture['detalle_id'],
            'estado_distribucion' => 'ASIGNADO',
        ]);
    }

    public function test_reubicacion_de_pasajero_es_transaccional(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $origen = $this->crearServicio($fixture, ['placa' => 'ORI-01', 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);
        $destino = $this->crearServicio($fixture, ['placa' => 'DST-01', 'conductor_personal_id' => $this->personal($fixture['mina_id'], true)]);

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$origen}/pasajeros", [
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
        ])->assertOk();
        $pasajeroId = (string) DB::table('transporte_servicio_pasajeros')->where('transporte_servicio_id', $origen)->value('id');

        $this->withToken($token)->postJson("/api/v1/transporte/servicios/{$origen}/pasajeros/{$pasajeroId}/reubicar", [
            'destino_servicio_id' => $destino,
            'motivo' => 'Cambio de unidad',
        ])->assertOk();

        $this->assertDatabaseHas('transporte_servicio_pasajeros', [
            'id' => $pasajeroId,
            'estado' => TransporteServicioPasajero::ESTADO_REUBICADO,
        ]);
        $this->assertDatabaseHas('transporte_servicio_pasajeros', [
            'transporte_servicio_id' => $destino,
            'grupo_trabajo_detalle_id' => $fixture['detalle_id'],
            'estado' => TransporteServicioPasajero::ESTADO_ASIGNADO,
        ]);
    }

    public function test_scope_y_permisos_bloquean_operaciones(): void
    {
        $fixture = $this->fixture();

        $this->withToken($this->tokenForUserWithoutScope())->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSPORTE_RQ_FORBIDDEN');

        $this->withToken($this->tokenForRole([]))->postJson('/api/v1/transporte/servicios', $this->servicePayload($fixture))
            ->assertStatus(403)
            ->assertJsonPath('code', 'TRANSPORTE_CREATE_FORBIDDEN');
    }

    public function test_transporte_legacy_visible_y_backfill_ambiguo_e_idempotente(): void
    {
        $fixture = $this->fixture();
        $token = $this->tokenForScopedUser($fixture['mina_id']);
        $this->legacyTransport($fixture, ['placas_asignadas' => 'LEG-123']);
        $this->legacyTransport($fixture, ['placas_asignadas' => 'AMB-1;AMB-2']);

        $this->withToken($token)->getJson('/api/v1/transporte/planificacion?rq_mina_id='.$fixture['rq_mina_id'].'&rq_mina_plan_id='.$fixture['plan_id'].'&fecha=2026-08-01&turno=A')
            ->assertOk()
            ->assertJsonFragment(['legacy_label' => 'TRANSPORTE LEGACY SIN ESTRUCTURA COMPLETA']);

        $this->artisan('transporte:backfill-servicios')->assertExitCode(0);
        $this->artisan('transporte:backfill-servicios')->assertExitCode(0);

        $this->assertSame(1, DB::table('transporte_servicios')->where('placa', 'LEG-123')->count());
        $this->assertSame(0, DB::table('transporte_servicios')->where('placa', 'AMB-1')->count());
    }

    private function fixture(): array
    {
        $minaId = (string) Str::uuid();
        $rqMinaId = (string) Str::uuid();
        $planId = (string) Str::uuid();
        $grupoOperativoId = (string) Str::uuid();
        $actividadId = (string) Str::uuid();
        $grupoTrabajoId = (string) Str::uuid();
        $detalleId = (string) Str::uuid();
        $plannerId = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $minaId,
            'nombre' => 'Mina Transporte',
            'unidad_minera' => 'UMT',
            'estado' => 'ACTIVO',
        ]);

        DB::table('usuarios')->insert([
            'id' => $plannerId,
            'email' => Str::lower(Str::random(10)).'@planner.test',
            'password' => bcrypt('secret'),
            'rol_id' => $this->rolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personalId = $this->personal($minaId, false);
        $conductorId = $this->personal($minaId, true);

        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $minaId,
            'area' => 'C2',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-05',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $plannerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_planes')->insert([
            'id' => $planId,
            'rq_mina_id' => $rqMinaId,
            'codigo' => 'PLAN-001',
            'nombre' => 'Plan test',
            'version' => 1,
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-05',
            'estado' => 'VIGENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $grupoOperativoId,
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'nombre' => 'Grupo C2',
            'area_operativa' => 'C2',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividades')->insert([
            'id' => $actividadId,
            'grupo_id' => $grupoOperativoId,
            'sait' => 'SAIT-01',
            'ait_trabajo' => 'Trabajo',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grupo_trabajo')->insert([
            'id' => $grupoTrabajoId,
            'rq_mina_id' => $rqMinaId,
            'rq_mina_plan_id' => $planId,
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'fecha' => '2026-08-01',
            'turno' => 'DIA',
            'supervisor_id' => $conductorId,
            'mina' => 'Mina Transporte',
            'servicio' => 'Servicio C2',
            'area' => 'C2',
            'paradero' => 'Paradero A',
            'horario_salida' => '06:00',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'estado' => 'PROGRAMADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grupo_trabajo_detalle')->insert([
            'id' => $detalleId,
            'grupo_trabajo_id' => $grupoTrabajoId,
            'personal_id' => $personalId,
            'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('minaId', 'rqMinaId', 'planId', 'grupoOperativoId', 'actividadId', 'grupoTrabajoId', 'detalleId', 'personalId', 'conductorId') + [
            'mina_id' => $minaId,
            'rq_mina_id' => $rqMinaId,
            'plan_id' => $planId,
            'grupo_operativo_id' => $grupoOperativoId,
            'actividad_id' => $actividadId,
            'grupo_trabajo_id' => $grupoTrabajoId,
            'detalle_id' => $detalleId,
            'personal_id' => $personalId,
            'conductor_id' => $conductorId,
        ];
    }

    private function servicePayload(array $fixture, array $overrides = []): array
    {
        return [
            'rq_mina_id' => $fixture['rq_mina_id'],
            'rq_mina_plan_id' => $fixture['plan_id'],
            'tipo' => 'PERSONAL',
            'fecha' => '2026-08-01',
            'turno' => 'A',
            'tramo' => 'IDA',
            'placa' => 'PAY-'.Str::upper(Str::random(4)),
            'conductor_personal_id' => $fixture['conductor_id'],
            'capacidad' => 10,
            'alcances' => [['grupo_trabajo_id' => $fixture['grupo_trabajo_id']]],
            ...$overrides,
        ];
    }

    private function legacyTransport(array $fixture, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('rq_mina_actividad_transportes')->insert([
            'id' => $id,
            'grupo_id' => $fixture['grupo_operativo_id'],
            'actividad_id' => $fixture['actividad_id'],
            'rq_mina_plan_id' => $fixture['plan_id'],
            'alcance' => 'SAIT-01',
            'unidad_carga' => 'Personal',
            'origen' => 'EMPRESA',
            'unidades_transporte' => 'Van 15',
            'placas_asignadas' => 'DRY-123',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-01',
            'estado_logistico' => 'ASIGNADO',
            'recepcion_estado' => 'PENDIENTE',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    private function crearServicio(array $fixture, array $overrides = [], bool $withScope = true): string
    {
        $id = (string) Str::uuid();

        DB::table('transporte_servicios')->insert([
            'id' => $id,
            'rq_mina_id' => $fixture['rq_mina_id'],
            'rq_mina_plan_id' => $fixture['plan_id'],
            'tipo' => $overrides['tipo'] ?? TransporteServicio::TIPO_PERSONAL,
            'fecha' => $overrides['fecha'] ?? '2026-08-01',
            'turno' => $overrides['turno'] ?? 'A',
            'tramo' => $overrides['tramo'] ?? 'IDA',
            'placa' => array_key_exists('placa', $overrides) ? $overrides['placa'] : 'CAR-123',
            'conductor_personal_id' => array_key_exists('conductor_personal_id', $overrides) ? $overrides['conductor_personal_id'] : $fixture['conductor_id'],
            'conductor_nombre_snapshot' => 'Conductor Test',
            'capacidad' => array_key_exists('capacidad', $overrides) ? $overrides['capacidad'] : 10,
            'estado' => 'BORRADOR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withScope) {
            DB::table('transporte_servicio_alcances')->insert([
                'id' => (string) Str::uuid(),
                'transporte_servicio_id' => $id,
                'grupo_trabajo_id' => $fixture['grupo_trabajo_id'],
                'orden' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function extraDetalle(array $fixture): string
    {
        $personalId = $this->personal($fixture['mina_id'], false);
        $detalleId = (string) Str::uuid();
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => $detalleId,
            'grupo_trabajo_id' => $fixture['grupo_trabajo_id'],
            'personal_id' => $personalId,
            'estado_distribucion' => 'ASIGNADO',
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $detalleId;
    }

    private function personal(string $minaId, bool $supervisor): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $supervisor ? 'CONDUCTOR TEST' : 'PASAJERO TEST',
            'puesto' => $supervisor ? 'CONDUCTOR' : 'TECNICO',
            'es_supervisor' => $supervisor ? 1 : 0,
            'qr_code' => 'QR-'.Str::random(8),
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

    private function tokenForScopedUser(string $minaId): string
    {
        $usuarioId = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $usuarioId,
            'email' => Str::lower(Str::random(10)).'@test.local',
            'password' => bcrypt('secret'),
            'rol_id' => $this->rolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);

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

    private function tokenForUserWithoutScope(): string
    {
        return $this->tokenForRole(['transportes' => ['ver', 'crear', 'editar', 'actualizar', 'entregar', 'recepcionar']]);
    }

    private function tokenForRole(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'TRANSPORTE_ROLE_'.Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $usuarioId = (string) Str::uuid();
        DB::table('usuarios')->insert([
            'id' => $usuarioId,
            'email' => Str::lower(Str::random(10)).'@role.test',
            'password' => bcrypt('secret'),
            'rol_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
}
