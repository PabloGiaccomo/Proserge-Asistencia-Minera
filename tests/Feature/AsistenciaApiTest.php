<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AsistenciaApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolPlannerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rolPlannerId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $this->rolPlannerId,
            'nombre' => 'PLANNER',
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
        ]);
    }

    public function test_no_marca_si_grupo_no_existe(): void
    {
        $minaId = $this->crearMina();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $personalId = $this->crearPersonal($minaId, false);

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.Str::uuid().'/marcar', [
            'personal_id' => $personalId,
            'estado' => 'PRESENTE',
        ]);

        $response->assertStatus(404)->assertJsonPath('code', 'ASISTENCIA_GRUPO_NOT_FOUND');
    }

    public function test_no_marca_personal_fuera_del_grupo(): void
    {
        [$minaId, $grupoId, $supervisorId] = $this->crearEscenarioGrupo();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $personalFuera = $this->crearPersonal($minaId, false);

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $personalFuera,
            'estado' => 'PRESENTE',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'ASISTENCIA_PERSON_NOT_IN_GROUP');
        $this->assertNotEquals($supervisorId, $personalFuera);
    }

    public function test_marcado_individual_funciona(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $trabajadorId,
            'estado' => 'PRESENTE',
            'hora_marcado' => '06:40',
        ]);

        $response->assertOk()->assertJsonPath('code', 'ASISTENCIA_MARCAR_OK');

        $this->assertDatabaseHas('asistencia_detalle', [
            'trabajador_id' => $trabajadorId,
            'estado' => 'PRESENTE',
        ]);

        $this->assertDatabaseHas('asistencia_encabezado', [
            'grupo_trabajo_id' => $grupoId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
        ]);
    }

    public function test_marcado_masivo_funciona(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar-masivo', [
            'personal_ids' => [$trabajadorId],
            'estado' => 'PRESENTE',
        ]);

        $response->assertOk()->assertJsonPath('code', 'ASISTENCIA_MARCAR_MASIVO_OK');
    }

    public function test_cierre_genera_faltas_a_ausentes(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $trabajadorId,
            'estado' => 'PRESENTE',
        ])->assertOk();

        $close = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/cerrar', []);
        $close->assertOk()->assertJsonPath('code', 'ASISTENCIA_CERRAR_OK');

        $ausente = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupoId)
            ->where('personal_id', '!=', $trabajadorId)
            ->value('personal_id');

        $this->assertDatabaseHas('faltas', [
            'trabajador_id' => $ausente,
            'motivo' => 'INASISTENCIA_ASISTENCIA',
        ]);
    }

    public function test_no_permite_cerrar_dos_veces(): void
    {
        [$minaId, $grupoId] = $this->crearEscenarioGrupo();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/cerrar', [])->assertOk();

        $second = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/cerrar', []);
        $second->assertStatus(422)->assertJsonPath('code', 'ASISTENCIA_ALREADY_CLOSED');
    }

    public function test_usuario_sin_scope_no_accede(): void
    {
        [, $grupoId] = $this->crearEscenarioGrupo();
        $usuarioId = $this->crearUsuario();
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/asistencia/grupos/'.$grupoId);

        $response->assertStatus(404)->assertJsonPath('code', 'ASISTENCIA_GRUPO_NOT_FOUND');
    }

    public function test_reabrir_funciona_si_agregaron_nuevos_despues_del_cierre(): void
    {
        [$minaId, $grupoId] = $this->crearEscenarioGrupo();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/cerrar', [])->assertOk();

        $nuevo = $this->crearPersonal($minaId, false);
        DB::table('grupo_trabajo_detalle')->insert([
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $grupoId,
            'personal_id' => $nuevo,
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reopen = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/reabrir', []);
        $reopen->assertOk()->assertJsonPath('code', 'ASISTENCIA_REABRIR_OK');
    }

    private function crearEscenarioGrupo(bool $withWorker = false): array
    {
        $minaId = $this->crearMina();
        $plannerId = $this->crearUsuario();
        $rrhhId = $this->crearUsuario();

        $rqMinaId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $minaId,
            'area' => 'Area',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-05',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $plannerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rqDetId = (string) Str::uuid();
        DB::table('rq_mina_detalle')->insert([
            'id' => $rqDetId,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'Tecnico',
            'cantidad' => 2,
            'cantidad_atendida' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rqProId = (string) Str::uuid();
        DB::table('rq_proserge')->insert([
            'id' => $rqProId,
            'rq_mina_id' => $rqMinaId,
            'mina_id' => $minaId,
            'responsable_rrhh_id' => $rrhhId,
            'estado' => 'BORRADOR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supervisorId = $this->crearPersonal($minaId, true);
        $trabajadorId = $this->crearPersonal($minaId, false);

        DB::table('rq_proserge_detalle')->insert([
            [
                'id' => (string) Str::uuid(),
                'rq_proserge_id' => $rqProId,
                'rq_mina_detalle_id' => $rqDetId,
                'personal_id' => $supervisorId,
                'puesto_asignado' => 'Supervisor',
                'fecha_inicio' => '2026-07-01',
                'fecha_fin' => '2026-07-02',
                'estado' => 'ASIGNADO',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'rq_proserge_id' => $rqProId,
                'rq_mina_detalle_id' => $rqDetId,
                'personal_id' => $trabajadorId,
                'puesto_asignado' => 'Tecnico',
                'fecha_inicio' => '2026-07-01',
                'fecha_fin' => '2026-07-02',
                'estado' => 'ASIGNADO',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $grupoId = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $grupoId,
            'fecha' => '2026-07-01',
            'supervisor_id' => $supervisorId,
            'mina' => 'Mina X',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'rq_mina_id' => $rqMinaId,
            'rq_proserge_id' => $rqProId,
            'servicio' => 'Servicio',
            'area' => 'Area',
            'horario_salida' => '06:30:00',
            'turno' => 'DIA',
            'estado' => 'BORRADOR',
            'created_by_id' => $plannerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grupo_trabajo_detalle')->insert([
            [
                'id' => (string) Str::uuid(),
                'grupo_trabajo_id' => $grupoId,
                'personal_id' => $supervisorId,
                'estado_asistencia' => 'AUSENTE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'grupo_trabajo_id' => $grupoId,
                'personal_id' => $trabajadorId,
                'estado_asistencia' => 'AUSENTE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if ($withWorker) {
            return [$minaId, $grupoId, $supervisorId, $trabajadorId];
        }

        return [$minaId, $grupoId, $supervisorId];
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

    private function crearUsuario(): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $this->rolPlannerId,
            'personal_id' => null,
        ]);

        return $id;
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

    private function asignarScope(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
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
