<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluacionesApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $rolPlannerId;

    private string $rolVisitanteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rolPlannerId = (string) Str::uuid();
        $this->rolVisitanteId = (string) Str::uuid();

        DB::table('roles')->insert([
            ['id' => $this->rolPlannerId, 'nombre' => 'PLANNER', 'permisos' => json_encode([]), 'estado' => 'ACTIVO'],
            ['id' => $this->rolVisitanteId, 'nombre' => 'VISITANTE', 'permisos' => json_encode([]), 'estado' => 'ACTIVO'],
        ]);
    }

    public function test_no_duplica_evaluacion_en_mismo_grupo_y_trabajador(): void
    {
        [$minaId, $grupoId, $trabajadorId] = $this->crearContexto();
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $payload = $this->payloadDesempeno($grupoId, $trabajadorId);

        $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $payload)->assertStatus(201);
        $second = $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $payload);

        $second->assertStatus(422)->assertJsonPath('code', 'EVAL_DUPLICATED');
    }

    public function test_solo_evalua_personal_valido_del_grupo(): void
    {
        [$minaId, $grupoId] = $this->crearContexto();
        $otro = $this->crearPersonal($minaId, false);
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $this->payloadDesempeno($grupoId, $otro));
        $response->assertStatus(422)->assertJsonPath('code', 'EVAL_TRABAJADOR_NOT_IN_GROUP');
    }

    public function test_promedios_calculan_bien(): void
    {
        [$minaId, $grupoId, $trabajadorId] = $this->crearContexto();
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $this->payloadDesempeno($grupoId, $trabajadorId, 10))->assertStatus(201);

        $grupo2 = $this->duplicarGrupoConAsistencia($grupoId, $trabajadorId, $minaId);
        $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $this->payloadDesempeno($grupo2, $trabajadorId, 20))->assertStatus(201);

        $avg = $this->withToken($token)->getJson('/api/v1/evaluaciones/promedios?trabajador_id='.$trabajadorId);
        $avg->assertOk()->assertJsonPath('data.0.promedio_total', 75);
    }

    public function test_filtros_por_destino_funcionan(): void
    {
        [$minaId, $grupoId, $trabajadorId] = $this->crearContexto();
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $this->payloadDesempeno($grupoId, $trabajadorId))->assertStatus(201);

        $response = $this->withToken($token)->getJson('/api/v1/evaluaciones/desempeno?destino_tipo=MINA&destino_id='.$minaId);
        $response->assertOk()->assertJsonPath('data.0.destino_tipo', 'MINA');
    }

    public function test_supervisor_y_residente_respetan_reglas_acceso(): void
    {
        [$minaId, $grupoId, $trabajadorId, $supervisorId] = $this->crearContexto();
        $usuarioId = $this->crearUsuario($this->rolPlannerId);
        $token = $this->crearToken($usuarioId);

        $sup = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', [
            'evaluador_id' => $supervisorId,
            'evaluado_id' => $trabajadorId,
            'fecha' => '2026-09-01',
            'grupo_trabajo_id' => $grupoId,
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'respuestas' => ['A1' => 4, 'B1' => 4, 'C1' => 4],
        ]);
        $sup->assertStatus(403);

        $this->asignarScope($usuarioId, $minaId);

        $supOk = $this->withToken($token)->postJson('/api/v1/evaluaciones/supervisor', [
            'evaluador_id' => $supervisorId,
            'evaluado_id' => $trabajadorId,
            'fecha' => '2026-09-01',
            'grupo_trabajo_id' => $grupoId,
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'respuestas' => ['A1' => 4, 'B1' => 4, 'C1' => 4],
        ]);
        $supOk->assertStatus(201);

        $resOk = $this->withToken($token)->postJson('/api/v1/evaluaciones/residente', [
            'fecha' => '2026-09-01',
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'indicadores_kpi' => 80,
            'costos_servicio' => 70,
            'eventos_seguridad' => 90,
            'reportes_calidad' => 85,
            'liderazgo_gestion' => 75,
            'innovacion' => 70,
            'residente_id' => $trabajadorId,
            'evaluador_id' => $supervisorId,
        ]);
        $resOk->assertStatus(201);
    }

    public function test_usuario_sin_permiso_no_opera(): void
    {
        [$minaId, $grupoId, $trabajadorId] = $this->crearContexto();
        $usuarioId = $this->crearUsuario($this->rolVisitanteId);
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/evaluaciones/desempeno', $this->payloadDesempeno($grupoId, $trabajadorId));
        $response->assertStatus(403)->assertJsonPath('code', 'EVAL_FORBIDDEN');
    }

    private function payloadDesempeno(string $grupoId, string $trabajadorId, int $base = 15): array
    {
        return [
            'grupo_trabajo_id' => $grupoId,
            'trabajador_id' => $trabajadorId,
            'desempeno_trabajo' => $base,
            'orden_limpieza' => $base,
            'compromiso' => $base,
            'respuesta_emocional' => $base,
            'seguridad_trabajo' => $base,
        ];
    }

    private function crearContexto(): array
    {
        $minaId = $this->crearMina();
        $creador = $this->crearUsuario($this->rolPlannerId);
        $supervisorId = $this->crearPersonal($minaId, true);
        $trabajadorId = $this->crearPersonal($minaId, false);

        $rqId = (string) Str::uuid();
        DB::table('rq_mina')->insert([
            'id' => $rqId,
            'mina_id' => $minaId,
            'area' => 'Area',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-02',
            'estado' => 'ENVIADO',
            'created_by_usuario_id' => $creador,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grupoId = (string) Str::uuid();
        DB::table('grupo_trabajo')->insert([
            'id' => $grupoId,
            'fecha' => '2026-09-01',
            'supervisor_id' => $supervisorId,
            'mina' => 'Mina X',
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
            ['id' => (string) Str::uuid(), 'grupo_trabajo_id' => $grupoId, 'personal_id' => $supervisorId, 'estado_asistencia' => 'AUSENTE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'grupo_trabajo_id' => $grupoId, 'personal_id' => $trabajadorId, 'estado_asistencia' => 'AUSENTE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $asistenciaId = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert([
            'id' => $asistenciaId,
            'grupo_trabajo_id' => $grupoId,
            'fecha' => '2026-09-01',
            'hora_ingreso' => '06:30:00',
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'supervisor_id' => $supervisorId,
            'estado' => 'CERRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencia_detalle')->insert([
            ['id' => (string) Str::uuid(), 'asistencia_id' => $asistenciaId, 'trabajador_id' => $supervisorId, 'hora_marcado' => '06:35:00', 'estado' => 'PRESENTE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'asistencia_id' => $asistenciaId, 'trabajador_id' => $trabajadorId, 'hora_marcado' => '06:40:00', 'estado' => 'PRESENTE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$minaId, $grupoId, $trabajadorId, $supervisorId];
    }

    private function duplicarGrupoConAsistencia(string $grupoId, string $trabajadorId, string $minaId): string
    {
        $base = DB::table('grupo_trabajo')->where('id', $grupoId)->first();
        $nuevo = (string) Str::uuid();

        DB::table('grupo_trabajo')->insert([
            'id' => $nuevo,
            'fecha' => '2026-09-02',
            'supervisor_id' => $base->supervisor_id,
            'mina' => $base->mina,
            'unidad' => $base->unidad,
            'destino_tipo' => $base->destino_tipo,
            'destino_id' => $base->destino_id,
            'rq_mina_id' => $base->rq_mina_id,
            'servicio' => $base->servicio,
            'area' => $base->area,
            'horario_salida' => $base->horario_salida,
            'turno' => $base->turno,
            'estado' => 'BORRADOR',
            'created_by_id' => $base->created_by_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grupo_trabajo_detalle')->insert([
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $nuevo,
            'personal_id' => $trabajadorId,
            'estado_asistencia' => 'AUSENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asistenciaId = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert([
            'id' => $asistenciaId,
            'grupo_trabajo_id' => $nuevo,
            'fecha' => '2026-09-02',
            'hora_ingreso' => '06:30:00',
            'mina_id' => $minaId,
            'destino_tipo' => 'MINA',
            'destino_id' => $minaId,
            'supervisor_id' => $base->supervisor_id,
            'estado' => 'CERRADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asistencia_detalle')->insert([
            'id' => (string) Str::uuid(),
            'asistencia_id' => $asistenciaId,
            'trabajador_id' => $trabajadorId,
            'hora_marcado' => '06:35:00',
            'estado' => 'PRESENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $nuevo;
    }

    private function crearMina(): string
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $id, 'nombre' => 'Mina '.Str::upper(Str::random(4)), 'unidad_minera' => 'UM '.Str::upper(Str::random(3)), 'estado' => 'ACTIVO']);

        return $id;
    }

    private function crearUsuario(string $rolId): string
    {
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert(['id' => $id, 'email' => Str::lower(Str::random(8)).'@test.local', 'password' => bcrypt('secret123'), 'rol_id' => $rolId]);

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
