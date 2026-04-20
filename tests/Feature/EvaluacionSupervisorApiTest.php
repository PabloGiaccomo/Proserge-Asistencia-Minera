<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluacionSupervisorApiTest extends TestCase
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

    public function test_creacion_correcta_evaluacion_supervisor(): void
    {
        [$minaId, $grupoId, $evaluadorId, $evaluadoId] = $this->crearContextoMina();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', $this->payload($evaluadorId, $evaluadoId, $minaId, $grupoId));

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'EVAL_SUPERVISOR_CREATE_OK');
    }

    public function test_calculo_correcto_porcentaje_final(): void
    {
        $usuarioId = $this->crearUsuario();
        $token = $this->crearToken($usuarioId);

        $responses = [];
        foreach (['A1','A2','A3','A4','A5','A6','A7','A8','A9','B1','B2','B3','B4','B5','B6','B7','C1','C2','C3','C4','C5','C6','C7','C8','C9','C10'] as $k) {
            $responses[$k] = 5;
        }

        $response = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor/calcular', ['respuestas' => $responses]);

        $response->assertOk()->assertJsonPath('data.resultado_final', 100);
    }

    public function test_respuestas_vacias_valen_cero(): void
    {
        $usuarioId = $this->crearUsuario();
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor/calcular', ['respuestas' => ['A1' => 5]]);
        $score = (float) $response->json('data.resultado_final');

        $this->assertEquals(4.0, $score);
    }

    public function test_no_duplicidad_para_mismo_contexto(): void
    {
        [$minaId, $grupoId, $evaluadorId, $evaluadoId] = $this->crearContextoMina();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $payload = $this->payload($evaluadorId, $evaluadoId, $minaId, $grupoId);

        $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', $payload)->assertStatus(201);
        $second = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', $payload);

        $second->assertStatus(422)->assertJsonPath('code', 'EVAL_SUP_DUPLICATED');
    }

    public function test_visualizacion_y_edicion_correcta(): void
    {
        [$minaId, $grupoId, $evaluadorId, $evaluadoId] = $this->crearContextoMina();
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $create = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', $this->payload($evaluadorId, $evaluadoId, $minaId, $grupoId));
        $id = (string) $create->json('data.id');

        $show = $this->withToken($token)->getJson('/api/v1/evaluaciones/supervisor/'.$id);
        $show->assertOk()->assertJsonPath('data.id', $id);

        $update = $this->withToken($token)->putJson('/api/v1/evaluaciones/supervisor/'.$id, [
            'respuestas' => ['A1' => 5, 'B1' => 5, 'C1' => 5],
            'comentarios_finales' => 'Comentario actualizado',
            'aspectos_positivos' => 'Muy buen liderazgo',
            'capacitaciones_recomendadas' => 'Capacitacion tecnica',
            'firma' => 'FIRMA-OK',
            'estado' => 'REVISADA',
        ]);

        $update->assertOk()->assertJsonPath('data.estado', 'REVISADA');
        $update->assertJsonPath('data.comentarios_finales', 'Comentario actualizado');
        $update->assertJsonPath('data.firma', 'FIRMA-OK');
    }

    public function test_compatibilidad_destino_taller_oficina(): void
    {
        [$evaluadorId, $evaluadoId] = $this->crearParPersonalSinScope();
        $usuarioId = $this->crearUsuario();
        $token = $this->crearToken($usuarioId);

        $tallerId = (string) Str::uuid();
        DB::table('talleres')->insert(['id' => $tallerId, 'nombre' => 'Taller Sur', 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);
        $oficinaId = (string) Str::uuid();
        DB::table('oficinas')->insert(['id' => $oficinaId, 'nombre' => 'Oficina Este', 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);

        $taller = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', [
            'evaluador_id' => $evaluadorId,
            'evaluado_id' => $evaluadoId,
            'fecha' => '2026-10-01',
            'destino_tipo' => 'TALLER',
            'destino_id' => $tallerId,
            'respuestas' => ['A1' => 3],
        ]);
        $taller->assertStatus(201)->assertJsonPath('data.destino_tipo', 'TALLER');

        $oficina = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', [
            'evaluador_id' => $evaluadorId,
            'evaluado_id' => $evaluadoId,
            'fecha' => '2026-10-02',
            'destino_tipo' => 'OFICINA',
            'destino_id' => $oficinaId,
            'respuestas' => ['A1' => 3],
        ]);
        $oficina->assertStatus(201)->assertJsonPath('data.destino_tipo', 'OFICINA');
    }

    private function payload(string $evaluadorId, string $evaluadoId, string $minaId, string $grupoId): array
    {
        return [
            'evaluador_id' => $evaluadorId,
            'evaluado_id' => $evaluadoId,
            'fecha' => '2026-10-01',
            'mina_id' => $minaId,
            'grupo_trabajo_id' => $grupoId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'respuestas' => ['A1' => 4, 'B1' => 4, 'C1' => 4],
            'comentarios_finales' => 'Comentarios',
            'aspectos_positivos' => 'Positivos',
            'capacitaciones_recomendadas' => 'Capacitaciones',
            'firma' => 'FIRMA',
        ];
    }

    private function crearContextoMina(): array
    {
        $minaId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $minaId, 'nombre' => 'Mina Eval', 'unidad_minera' => 'UM-E', 'estado' => 'ACTIVO']);

        $evaluadorId = $this->crearPersonal($minaId, true);
        $evaluadoId = $this->crearPersonal($minaId, true);
        $creador = $this->crearUsuario();

        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $minaId,
            'area' => 'Area Eval',
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-02',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $creador,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grupoId = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $grupoId,
            'fecha' => '2026-10-01',
            'supervisor_id' => $evaluadorId,
            'mina' => 'Mina Eval',
            'unidad' => 'MINA',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'rq_mina_id' => $rqId,
            'servicio' => 'Srv',
            'area' => 'Area',
            'horario_salida' => '06:30:00',
            'turno' => 'DIA',
            'estado' => 'BORRADOR',
            'created_by_id' => $creador,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grupo_trabajo_detalle')->insert([
            ['id' => (string) Str::uuid(), 'grupo_trabajo_id' => $grupoId, 'personal_id' => $evaluadorId, 'estado_asistencia' => 'PRESENTE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'grupo_trabajo_id' => $grupoId, 'personal_id' => $evaluadoId, 'estado_asistencia' => 'PRESENTE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$minaId, $grupoId, $evaluadorId, $evaluadoId];
    }

    private function crearParPersonalSinScope(): array
    {
        $minaId = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $minaId, 'nombre' => 'Mina Aux', 'unidad_minera' => 'UM-A', 'estado' => 'ACTIVO']);

        return [$this->crearPersonal($minaId, true), $this->crearPersonal($minaId, true)];
    }

    private function crearUsuario(): string
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert(['id' => $id, 'email' => Str::lower(Str::random(8)).'@test.local', 'password' => bcrypt('secret123'), 'rol_id' => $this->rolPlannerId]);
        return $id;
    }

    private function crearToken(string $usuarioId): string
    {
        $plain = Str::random(80);
        DB::table('auth_tokens')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $usuarioId, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $plain;
    }

    private function asignarScope(string $usuarioId, string $minaId): void
    {
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $usuarioId, 'mina_id' => $minaId]);
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
