<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
            ['id' => $this->rolPlannerId, 'nombre' => 'PLANNER', 'permisos' => json_encode([]), 'estado' => 'ACTIVO'],
            ['id' => $this->rolRrhhId, 'nombre' => 'RRHH', 'permisos' => json_encode([]), 'estado' => 'ACTIVO'],
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
        $grupoId = $this->crearGrupo($rqMinaId, $rqProsergeId, $supervisorId, $usuarioId);

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

    private function crearGrupo(string $rqMinaId, string $rqProsergeId, string $supervisorId, string $usuarioId, array $personalIds = []): string
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
            'turno' => 'DIA',
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
