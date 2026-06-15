<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RQMinaApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $adminRoleId;

    private string $userRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRoleId = (string) Str::uuid();
        $this->userRoleId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $this->adminRoleId,
                'nombre' => 'ADMIN',
                'permisos' => json_encode(['rq_mina.read', 'rq_mina.write']),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->userRoleId,
                'nombre' => 'PLANNER',
                'permisos' => json_encode(['rq_mina.read', 'rq_mina.write']),
                'estado' => 'ACTIVO',
            ],
        ]);
    }

    public function test_crea_rq_mina_en_borrador(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina', [
            'mina_id' => $minaId,
            'area' => 'Mantenimiento',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-14',
            'observaciones' => 'Solicitud inicial',
            'detalle' => [
                ['puesto' => 'Tecnico', 'cantidad' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.estado', 'BORRADOR');
    }

    public function test_crea_rq_mina_con_destino_taller_y_transporte(): void
    {
        $minaId = $this->createMina();
        $tallerId = $this->createTaller();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina', [
            'destino_tipo' => 'TALLER',
            'destino_id' => $tallerId,
            'area' => 'Mantenimiento',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-14',
            'observaciones' => 'Solicitud con transporte',
            'detalle' => [
                ['puesto' => 'Tecnico', 'cantidad' => 2],
            ],
            'transporte' => [
                ['transporte' => 'Camioneta', 'cantidad' => 1],
                ['transporte' => 'Camion', 'cantidad' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.destino_tipo', 'TALLER')
            ->assertJsonPath('data.destino_id', $tallerId)
            ->assertJsonFragment(['transporte' => 'Camioneta', 'cantidad' => 1])
            ->assertJsonFragment(['transporte' => 'Camion', 'cantidad' => 2]);

        $this->assertDatabaseHas('rq_mina', [
            'destino_tipo' => 'TALLER',
            'destino_id' => $tallerId,
            'mina_id' => $minaId,
        ]);
        $this->assertDatabaseHas('rq_mina_transporte_detalle', [
            'transporte' => 'Camion',
            'cantidad' => 2,
        ]);
    }

    public function test_crea_rq_mina_con_supervisor_a_cargo(): void
    {
        $minaId = $this->createMina();
        $supervisorId = $this->createPersonal($minaId, true, 'Supervisor Prueba Uno', 'Supervisor');
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina', [
            'mina_id' => $minaId,
            'area' => 'Mantenimiento',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-14',
            'detalle' => [
                ['puesto' => 'Tecnico', 'cantidad' => 1],
            ],
            'supervisor_id' => $supervisorId,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.supervisor_id', $supervisorId)
            ->assertJsonPath('data.supervisor.nombre', 'Supervisor Prueba Uno');

        $this->assertDatabaseHas('rq_mina', [
            'supervisor_id' => $supervisorId,
        ]);
    }

    public function test_crea_rq_mina_con_plan_operativo_semanal(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina', [
            'mina_id' => $minaId,
            'area' => 'Parada Planta',
            'fecha_inicio' => '2026-04-13',
            'fecha_fin' => '2026-04-19',
            'plan_operativo' => [
                [
                    'area_operativa' => 'C1',
                    'modulo' => 'Seca',
                    'nombre' => 'Grupo Seca C1',
                    'actividades' => [
                        [
                            'client_key' => 'act-1',
                            'sait' => 'SAIT-100',
                            'sector' => 'Chancado',
                            'area' => 'C1 Seca',
                            'ait_trabajo' => 'AIT-01 / AIT-02',
                            'detalle_trabajos_relevantes' => 'Cambio de liners',
                            'supervisor_campo_dia' => 'Supervisor Dia',
                            'turnos' => [
                                ['fecha' => '2026-04-13', 'dia_label' => 'Lun 13/04', 'turno_a' => 'X', 'real_turno_a' => '8', 'turno_b' => '', 'real_turno_b' => ''],
                                ['fecha' => '2026-04-14', 'dia_label' => 'Mar 14/04', 'turno_a' => '', 'real_turno_a' => '', 'turno_b' => 'X', 'real_turno_b' => 'OK'],
                            ],
                        ],
                    ],
                    'transportes' => [
                        [
                            'alcance' => 'SAIT-100',
                            'unidad_carga' => 'Grua 80T',
                            'unidades_transporte' => 'Van 15 y minibus 35 asientos',
                            'indicaciones' => 'Desde miercoles turno A',
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.plan_operativo.0.area_operativa', 'C1')
            ->assertJsonPath('data.plan_operativo.0.actividades.0.sait', 'SAIT-100')
            ->assertJsonPath('data.plan_operativo.0.actividades.0.turnos.0.real_turno_a', '8')
            ->assertJsonPath('data.plan_operativo.0.actividades.0.turnos.1.real_turno_b', 'OK')
            ->assertJsonPath('data.detalle.0.cantidad', 1);

        $this->assertDatabaseHas('rq_mina_actividad_grupos', [
            'area_operativa' => 'C1',
            'modulo' => 'Seca',
        ]);
        $this->assertDatabaseHas('rq_mina_actividades', [
            'sait' => 'SAIT-100',
            'sector' => 'Chancado',
        ]);
        $this->assertDatabaseHas('rq_mina_actividad_turnos', [
            'fecha' => '2026-04-14',
            'turno_b' => 'X',
            'real_turno_b' => 'OK',
            'real' => 'OK',
        ]);
        $this->assertDatabaseHas('rq_mina_actividad_turnos', [
            'fecha' => '2026-04-13',
            'turno_a' => 'X',
            'real_turno_a' => '8',
        ]);
        $this->assertDatabaseHas('rq_mina_actividad_transportes', [
            'unidad_carga' => 'Grua 80T',
        ]);
    }

    public function test_opciones_de_campos_rq_mina_se_guardan_y_eliminan(): void
    {
        $usuarioId = $this->createUsuario($this->userRoleId);

        $session = [
            'auth_token' => 'test-token',
            'user_id' => $usuarioId,
            'user' => [
                'id' => $usuarioId,
                'email' => 'planner@test.local',
                'permissions' => ['*'],
            ],
        ];

        $storeResponse = $this->withSession($session)->postJson('/rq-mina/opciones-campo', [
            'field' => 'rq_mina.plan.modulo',
            'value' => 'Seca C1',
        ]);

        $storeResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.value', 'Seca C1');

        $optionId = $storeResponse->json('data.id');

        $this->withSession($session)
            ->getJson('/rq-mina/opciones-campo?field=rq_mina.plan.modulo&q=seca')
            ->assertOk()
            ->assertJsonPath('data.0.value', 'Seca C1');

        $this->withSession($session)
            ->deleteJson('/rq-mina/opciones-campo/' . $optionId)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('rq_mina_field_options', [
            'id' => $optionId,
        ]);
    }

    public function test_crea_rq_mina_con_destino_oficina(): void
    {
        $minaId = $this->createMina();
        $oficinaId = $this->createOficina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina', [
            'destino_tipo' => 'OFICINA',
            'destino_id' => $oficinaId,
            'area' => 'Administracion',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-14',
            'detalle' => [
                ['puesto' => 'Asistente', 'cantidad' => 1],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.destino_tipo', 'OFICINA')
            ->assertJsonPath('data.destino_id', $oficinaId);
    }

    public function test_no_deja_editar_rq_mina_cerrado(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);
        $rqId = $this->createRQMina($minaId, $usuarioId, 'CERRADO');

        $response = $this->withToken($token)->putJson('/api/v1/rq-mina/'.$rqId, [
            'mina_id' => $minaId,
            'area' => 'Area editada',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-15',
            'observaciones' => null,
            'detalle' => [
                ['puesto' => 'Soldador', 'cantidad' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'RQ_MINA_NOT_EDITABLE');
    }

    public function test_usuario_sin_scope_no_puede_ver_rq_mina(): void
    {
        $minaId = $this->createMina();
        $creadorId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($creadorId, $minaId);
        $rqId = $this->createRQMina($minaId, $creadorId, 'BORRADOR');

        $otroUsuarioId = $this->createUsuario($this->userRoleId);
        $token = $this->createToken($otroUsuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/rq-mina/'.$rqId);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RQ_MINA_NOT_FOUND');
    }

    public function test_creador_puede_editar_rq_mina_en_borrador(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);
        $rqId = $this->createRQMina($minaId, $usuarioId, 'BORRADOR');

        $response = $this->withToken($token)->putJson('/api/v1/rq-mina/'.$rqId, [
            'mina_id' => $minaId,
            'area' => 'Area actualizada',
            'fecha_inicio' => '2026-04-11',
            'fecha_fin' => '2026-04-16',
            'observaciones' => 'Actualizacion creador',
            'detalle' => [
                ['puesto' => 'Electricista', 'cantidad' => 3],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 'RQ_MINA_UPDATE_OK')
            ->assertJsonPath('data.area', 'Area actualizada');
    }

    public function test_otro_usuario_sin_privilegios_no_puede_editar(): void
    {
        $minaId = $this->createMina();
        $creadorId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($creadorId, $minaId);
        $rqId = $this->createRQMina($minaId, $creadorId, 'BORRADOR');

        $otroUsuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($otroUsuarioId, $minaId);
        $token = $this->createToken($otroUsuarioId);

        $response = $this->withToken($token)->putJson('/api/v1/rq-mina/'.$rqId, [
            'mina_id' => $minaId,
            'area' => 'Intento no autorizado',
            'fecha_inicio' => '2026-04-11',
            'fecha_fin' => '2026-04-16',
            'observaciones' => null,
            'detalle' => [
                ['puesto' => 'Tecnico', 'cantidad' => 1],
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'RQ_MINA_FORBIDDEN_EDIT');
    }

    public function test_enviar_cambia_estado_de_borrador_a_enviado(): void
    {
        $minaId = $this->createMina();
        $usuarioId = $this->createUsuario($this->userRoleId);
        $this->assignMinaScope($usuarioId, $minaId);
        $token = $this->createToken($usuarioId);
        $rqId = $this->createRQMina($minaId, $usuarioId, 'BORRADOR');

        $response = $this->withToken($token)->postJson('/api/v1/rq-mina/'.$rqId.'/enviar', []);

        $response->assertStatus(200)
            ->assertJsonPath('code', 'RQ_MINA_SEND_OK')
            ->assertJsonPath('data.estado', 'ENVIADO');
    }

    private function createMina(): string
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

    private function createTaller(): string
    {
        $id = (string) Str::uuid();

        DB::table('talleres')->insert([
            'id' => $id,
            'nombre' => 'Taller '.Str::upper(Str::random(4)),
            'ubicacion' => 'Ubicacion taller',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createOficina(): string
    {
        $id = (string) Str::uuid();

        DB::table('oficinas')->insert([
            'id' => $id,
            'nombre' => 'Oficina '.Str::upper(Str::random(4)),
            'ubicacion' => 'Ubicacion oficina',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createPersonal(string $minaId, bool $esSupervisor, string $nombre, string $puesto): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => $nombre,
            'puesto' => $puesto,
            'ocupacion' => $esSupervisor ? 'E' : 'O',
            'contrato' => 'REG',
            'es_supervisor' => $esSupervisor ? 1 : 0,
            'qr_code' => 'QR-' . Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
        ]);

        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $id,
            'mina_id' => $minaId,
            'estado' => 'HABILITADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createUsuario(string $rolId): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(8)).'@test.local',
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

    private function createToken(string $usuarioId): string
    {
        $plain = Str::random(80);

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    private function createRQMina(string $minaId, string $creadorId, string $estado): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'Area base',
            'fecha_inicio' => '2026-04-10',
            'fecha_fin' => '2026-04-12',
            'observaciones' => null,
            'estado' => $estado,
            'created_by_usuario_id' => $creadorId,
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

        return $id;
    }
}
