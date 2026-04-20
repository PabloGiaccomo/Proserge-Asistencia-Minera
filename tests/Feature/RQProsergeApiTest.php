<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RQProsergeApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolRrhhId;

    private string $rolPlannerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rolRrhhId = (string) Str::uuid();
        $this->rolPlannerId = (string) Str::uuid();

        DB::table('roles')->insert([
            [
                'id' => $this->rolRrhhId,
                'nombre' => 'RRHH',
                'permisos' => json_encode(['rq_proserge.read', 'rq_proserge.write']),
                'estado' => 'ACTIVO',
            ],
            [
                'id' => $this->rolPlannerId,
                'nombre' => 'PLANNER',
                'permisos' => json_encode(['rq_mina.read']),
                'estado' => 'ACTIVO',
            ],
        ]);
    }

    public function test_rrhh_con_scope_si_accede(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);

        $response = $this->withToken($token)->getJson('/api/v1/rq-proserge/'.$rqProsergeId);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'RQ_PROSERGE_SHOW_OK');
    }

    public function test_rrhh_sin_scope_no_accede(): void
    {
        [$minaId, $rqProsergeId] = $this->crearEscenarioBase();
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $otraMinaId = $this->crearMina();
        $this->asignarScopeUsuario($rrhhId, $otraMinaId);
        $token = $this->crearToken($rrhhId);

        $response = $this->withToken($token)->getJson('/api/v1/rq-proserge/'.$rqProsergeId);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RQ_PROSERGE_NOT_FOUND');
    }

    public function test_no_asigna_trabajador_bloqueado(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);
        $this->bloquearPersonal($personalId, $rrhhId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PERSONAL_BLOCKED')
            ->assertJsonStructure(['ok', 'code', 'message', 'detail', 'data']);
    }

    public function test_no_asigna_trabajador_duplicado_en_rango(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId, $rqMinaId] = $this->crearEscenarioBase(true, true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        $otroRqProsergeId = $this->crearRQProserge($rqMinaId, $minaId, $rrhhId);
        $this->crearAsignacionProserge($otroRqProsergeId, $rqMinaDetalleId, $personalId, '2026-05-01', '2026-05-03');

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-02',
            'fecha_fin' => '2026-05-04',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PERSONAL_CONFLICT_RQ');
    }

    public function test_desasignar_libera_y_recalcula_cantidad_atendida(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $this->asignarScopeUsuario($rrhhId, $minaId);
        $token = $this->crearToken($rrhhId);
        $personalId = $this->crearPersonal($minaId);

        $asignacionId = $this->crearAsignacionProserge($rqProsergeId, $rqMinaDetalleId, $personalId, '2026-05-01', '2026-05-02');
        DB::table('rq_mina_detalle')->where('id', $rqMinaDetalleId)->update(['cantidad_atendida' => 1]);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/desasignar', [
            'rq_proserge_detalle_id' => $asignacionId,
        ]);

        $response->assertOk()->assertJsonPath('code', 'RQ_PROSERGE_UNASSIGN_OK');

        $this->assertDatabaseMissing('rq_proserge_detalle', ['id' => $asignacionId]);
        $this->assertDatabaseHas('rq_mina_detalle', ['id' => $rqMinaDetalleId, 'cantidad_atendida' => 0]);
    }

    public function test_respuesta_uniforme_en_error_negocio(): void
    {
        [$minaId, $rqProsergeId, $rqMinaDetalleId] = $this->crearEscenarioBase(true);
        $plannerId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScopeUsuario($plannerId, $minaId);
        $token = $this->crearToken($plannerId);
        $personalId = $this->crearPersonal($minaId);

        $response = $this->withToken($token)->postJson('/api/v1/rq-proserge/'.$rqProsergeId.'/asignar', [
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-02',
        ]);

        $response->assertStatus(403)
            ->assertJsonStructure(['ok', 'code', 'message', 'detail', 'data'])
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'RQ_PROSERGE_ASSIGN_FORBIDDEN');
    }

    private function crearEscenarioBase(bool $returnDetalle = false, bool $returnRQMinaId = false): array
    {
        $minaId = $this->crearMina();
        $creadorId = $this->crearUsuario($this->rolPlannerId);
        $rqMinaId = $this->crearRQMina($minaId, $creadorId);
        $rqMinaDetalleId = $this->crearRQMinaDetalle($rqMinaId);
        $rrhhId = $this->crearUsuario($this->rolRrhhId);
        $rqProsergeId = $this->crearRQProserge($rqMinaId, $minaId, $rrhhId);

        $output = [$minaId, $rqProsergeId];

        if ($returnDetalle) {
            $output[] = $rqMinaDetalleId;
        }

        if ($returnRQMinaId) {
            $output[] = $rqMinaId;
        }

        return $output;
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
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $rolId,
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
            'expires_at' => now()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    private function asignarScopeUsuario(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuarioId,
            'mina_id' => $minaId,
        ]);
    }

    private function crearRQMina(string $minaId, string $creadorId): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina')->insert([
            'id' => $id,
            'mina_id' => $minaId,
            'area' => 'Operacion',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-05',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $creadorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function crearRQMinaDetalle(string $rqMinaId): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_mina_detalle')->insert([
            'id' => $id,
            'rq_mina_id' => $rqMinaId,
            'puesto' => 'Tecnico',
            'cantidad' => 3,
            'cantidad_atendida' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function crearRQProserge(string $rqMinaId, string $minaId, string $rrhhId): string
    {
        $id = (string) Str::uuid();

        DB::table('rq_proserge')->insert([
            'id' => $id,
            'rq_mina_id' => $rqMinaId,
            'mina_id' => $minaId,
            'responsable_rrhh_id' => $rrhhId,
            'estado' => 'BORRADOR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function crearPersonal(string $minaId): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Trabajador '.Str::upper(Str::random(5)),
            'puesto' => 'Tecnico',
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

    private function bloquearPersonal(string $personalId, string $usuarioId): void
    {
        DB::table('personal_bloqueo')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'tipo' => 'DESCANSO_MEDICO',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => '2026-05-03',
            'motivo' => 'Bloqueo test',
            'bloqueado_por_id' => $usuarioId,
            'estado' => 'ACTIVO',
            'visible_para_planner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearAsignacionProserge(
        string $rqProsergeId,
        string $rqMinaDetalleId,
        string $personalId,
        string $fechaInicio,
        string $fechaFin
    ): string {
        $id = (string) Str::uuid();

        DB::table('rq_proserge_detalle')->insert([
            'id' => $id,
            'rq_proserge_id' => $rqProsergeId,
            'rq_mina_detalle_id' => $rqMinaDetalleId,
            'personal_id' => $personalId,
            'puesto_asignado' => 'Tecnico',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'estado' => 'ASIGNADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
