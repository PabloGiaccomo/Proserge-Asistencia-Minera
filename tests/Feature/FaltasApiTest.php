<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaltasApiTest extends TestCase
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

    public function test_lista_faltas_por_destino(): void
    {
        $usuarioId = $this->crearUsuario();
        [$minaId] = $this->crearContextoMinaFalta();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/faltas?destino_tipo=MINA&destino_id='.$minaId);

        $response->assertOk()
            ->assertJsonPath('code', 'FALTAS_LIST_OK')
            ->assertJsonPath('data.0.destino_tipo', 'MINA');
    }

    public function test_usuario_sin_scope_no_ve_faltas_mina_restringidas(): void
    {
        $usuarioId = $this->crearUsuario();
        [$minaId, $faltaId] = $this->crearContextoMinaFalta();
        $token = $this->crearToken($usuarioId);

        $list = $this->withToken($token)->getJson('/api/v1/faltas?destino_tipo=MINA&destino_id='.$minaId);
        $list->assertOk()->assertJsonMissing(['id' => $faltaId]);

        $show = $this->withToken($token)->getJson('/api/v1/faltas/'.$faltaId);
        $show->assertStatus(404)->assertJsonPath('code', 'FALTA_NOT_FOUND');
    }

    public function test_no_duplica_falta_del_mismo_origen_asistencia(): void
    {
        $usuarioId = $this->crearUsuario();
        [$minaId, , $faltaId, $grupoId] = $this->crearContextoMinaFalta(true, 'REGISTRADO');
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/cerrar', [])->assertOk();

        $count = DB::table('faltas')
            ->where('id', $faltaId)
            ->orWhere('asistencia_detalle_id', DB::table('faltas')->where('id', $faltaId)->value('asistencia_detalle_id'))
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_corregir_falta_actualiza_consistencia(): void
    {
        $usuarioId = $this->crearUsuario();
        [$minaId, $faltaId] = $this->crearContextoMinaFalta();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/faltas/'.$faltaId.'/corregir-asistencia', [
            'motivo_correccion' => 'Marco ingreso de forma tardia',
            'hora_marcado' => '07:10',
        ]);

        $response->assertOk()->assertJsonPath('data.estado', 'CORREGIDA');

        $this->assertDatabaseHas('asistencia_detalle', [
            'id' => $response->json('data.asistencia_detalle_id'),
            'estado' => 'PRESENTE',
        ]);
    }

    public function test_anular_exige_motivo(): void
    {
        $usuarioId = $this->crearUsuario();
        [$minaId, $faltaId] = $this->crearContextoMinaFalta();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/faltas/'.$faltaId.'/anular', []);

        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_falta_conserva_vinculo_con_asistencia_y_destino(): void
    {
        $usuarioId = $this->crearUsuario();
        [$minaId, $faltaId, , $grupoId] = $this->crearContextoMinaFalta();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->getJson('/api/v1/faltas/'.$faltaId);

        $response->assertOk()
            ->assertJsonPath('data.destino_tipo', 'MINA')
            ->assertJsonPath('data.destino_id', $minaId)
            ->assertJsonPath('data.grupo_trabajo_id', $grupoId);
    }

    public function test_filtros_taller_oficina_funcionan(): void
    {
        $usuarioId = $this->crearUsuario();
        $token = $this->crearToken($usuarioId);

        $tallerId = (string) Str::uuid();
        DB::table('talleres')->insert([
            'id' => $tallerId,
            'nombre' => 'Taller Norte',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oficinaId = (string) Str::uuid();
        DB::table('oficinas')->insert([
            'id' => $oficinaId,
            'nombre' => 'Oficina Centro',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registrador = $this->crearPersonal($this->crearMina(), true);
        $trabajador = $this->crearPersonal($this->crearMina(), false);

        DB::table('faltas')->insert([
            [
                'id' => (string) Str::uuid(),
                'trabajador_id' => $trabajador,
                'fecha' => '2026-08-01',
                'motivo' => 'INASISTENCIA_ASISTENCIA',
                'estado' => 'ACTIVA',
                'registrada_por_id' => $registrador,
                'destino_tipo' => 'TALLER',
                'destino_id' => $tallerId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'trabajador_id' => $trabajador,
                'fecha' => '2026-08-02',
                'motivo' => 'INASISTENCIA_ASISTENCIA',
                'estado' => 'ACTIVA',
                'registrada_por_id' => $registrador,
                'destino_tipo' => 'OFICINA',
                'destino_id' => $oficinaId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $taller = $this->withToken($token)->getJson('/api/v1/faltas?destino_tipo=TALLER&destino_id='.$tallerId);
        $taller->assertOk()->assertJsonPath('data.0.destino_tipo', 'TALLER');

        $oficina = $this->withToken($token)->getJson('/api/v1/faltas?destino_tipo=OFICINA&destino_id='.$oficinaId);
        $oficina->assertOk()->assertJsonPath('data.0.destino_tipo', 'OFICINA');
    }

    private function crearContextoMinaFalta(bool $returnGrupo = false, string $estadoAsistencia = 'CERRADO'): array
    {
        $minaId = $this->crearMina();
        $creadorId = $this->crearUsuario();
        $supervisorId = $this->crearPersonal($minaId, true);
        $trabajadorId = $this->crearPersonal($minaId, false);

        $rqMinaId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqMinaId,
            'mina_id' => $minaId,
            'area' => 'Area Faltas',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-02',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $creadorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grupoId = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $grupoId,
            'fecha' => '2026-08-01',
            'supervisor_id' => $supervisorId,
            'mina' => 'Mina X',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'rq_mina_id' => $rqMinaId,
            'servicio' => 'Servicio',
            'area' => 'Area',
            'horario_salida' => '06:30:00',
            'turno' => 'DIA',
            'estado' => 'BORRADOR',
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

        $asistenciaId = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert([
            'id' => $asistenciaId,
            'grupo_trabajo_id' => $grupoId,
            'fecha' => '2026-08-01',
            'hora_ingreso' => '06:30:00',
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'supervisor_id' => $supervisorId,
            'estado' => $estadoAsistencia,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detalleId = (string) Str::uuid();
        DB::table('asistencia_detalle')->insert([
            'id' => $detalleId,
            'asistencia_id' => $asistenciaId,
            'trabajador_id' => $trabajadorId,
            'hora_marcado' => '00:00:00',
            'estado' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $faltaId = (string) Str::uuid();
        DB::table('faltas')->insert([
            'id' => $faltaId,
            'trabajador_id' => $trabajadorId,
            'fecha' => '2026-08-01',
            'motivo' => 'INASISTENCIA_ASISTENCIA',
            'descripcion' => 'Generada por cierre',
            'estado' => 'ACTIVA',
            'registrada_por_id' => $supervisorId,
            'asistencia_encabezado_id' => $asistenciaId,
            'asistencia_detalle_id' => $detalleId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($returnGrupo) {
            return [$minaId, $trabajadorId, $faltaId, $grupoId];
        }

        return [$minaId, $faltaId, $trabajadorId, $grupoId];
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
