<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
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
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                'asistencias' => ['ver', 'actualizar', 'registrar', 'cerrar', 'reabrir'],
                'man_power' => ['ver', 'editar', 'asignar'],
            ])),
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

    public function test_marcado_exacto_por_distribucion_conserva_trazabilidad(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $detalleGrupoId = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupoId)
            ->where('personal_id', $trabajadorId)
            ->value('id');

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'grupo_trabajo_detalle_id' => $detalleGrupoId,
            'estado' => 'TARDANZA',
            'hora_marcado' => '06:55',
            'observaciones' => 'Ingreso con retraso comunicado',
        ]);

        $response->assertOk()->assertJsonPath('code', 'ASISTENCIA_MARCAR_OK');

        $this->assertDatabaseHas('asistencia_detalle', [
            'grupo_trabajo_detalle_id' => $detalleGrupoId,
            'trabajador_id' => $trabajadorId,
            'estado' => 'TARDANZA',
            'origen_registro' => 'MANUAL',
        ]);

        $this->assertDatabaseHas('parada_ejecucion_resumen', [
            'rq_mina_id' => DB::table('grupo_trabajo')->where('id', $grupoId)->value('rq_mina_id'),
            'turno' => 'DIA',
            'presentes' => 1,
            'tardanzas' => 1,
        ]);
    }

    public function test_marcado_exacto_por_asistencia_detalle_id(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $trabajadorId,
            'estado' => 'PRESENTE',
        ])->assertOk();

        $asistenciaDetalleId = DB::table('asistencia_detalle')
            ->where('trabajador_id', $trabajadorId)
            ->value('id');

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'asistencia_detalle_id' => $asistenciaDetalleId,
            'estado' => 'AUSENTE',
            'observaciones' => 'Correccion operativa',
        ]);

        $response->assertOk()->assertJsonPath('code', 'ASISTENCIA_MARCAR_OK');

        $this->assertDatabaseHas('asistencia_detalle', [
            'id' => $asistenciaDetalleId,
            'estado' => 'AUSENTE',
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

    public function test_justificado_y_no_corresponde_no_generan_falta(): void
    {
        [$minaId, $grupoId, $supervisorId, $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $trabajadorId,
            'estado' => 'JUSTIFICADO',
            'motivo_estado' => 'Descanso medico comunicado',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $supervisorId,
            'estado' => 'NO_CORRESPONDE',
            'motivo_estado' => 'Reubicado antes de inicio efectivo',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/cerrar', [])->assertOk();

        $this->assertDatabaseMissing('faltas', [
            'trabajador_id' => $trabajadorId,
            'motivo' => 'INASISTENCIA_ASISTENCIA',
            'estado' => 'ACTIVA',
        ]);
        $this->assertDatabaseMissing('faltas', [
            'trabajador_id' => $supervisorId,
            'motivo' => 'INASISTENCIA_ASISTENCIA',
            'estado' => 'ACTIVA',
        ]);
    }

    public function test_justificado_exige_motivo(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $trabajadorId,
            'estado' => 'JUSTIFICADO',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'ASISTENCIA_MOTIVE_REQUIRED');
    }

    public function test_execution_asigna_actividad_principal_y_recalcula_resumen_por_actividad(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);
        $rqMinaId = DB::table('grupo_trabajo')->where('id', $grupoId)->value('rq_mina_id');
        $actividadId = $this->crearActividadOperativa($rqMinaId, $grupoId);
        $detalleGrupoId = DB::table('grupo_trabajo_detalle')
            ->where('grupo_trabajo_id', $grupoId)
            ->where('personal_id', $trabajadorId)
            ->value('id');

        $response = $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/integrantes/'.$detalleGrupoId.'/actividad-principal', [
            'rq_mina_actividad_id' => $actividadId,
            'observacion' => 'Asignacion operativa principal',
        ]);

        $response->assertOk()->assertJsonPath('code', 'ASISTENCIA_ACTIVIDAD_PRINCIPAL_OK');

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'grupo_trabajo_detalle_id' => $detalleGrupoId,
            'estado' => 'PRESENTE',
        ])->assertOk();

        $this->assertDatabaseHas('grupo_trabajo_detalle_actividades', [
            'grupo_trabajo_detalle_id' => $detalleGrupoId,
            'rq_mina_actividad_id' => $actividadId,
            'es_principal' => 1,
        ]);

        $this->assertDatabaseHas('parada_ejecucion_resumen', [
            'rq_mina_id' => $rqMinaId,
            'rq_mina_actividad_id' => $actividadId,
            'presentes' => 1,
        ]);
    }

    public function test_backfill_y_recalculo_dry_run_funcionan_sin_modificar(): void
    {
        [$minaId, $grupoId, , $trabajadorId] = $this->crearEscenarioGrupo(true);
        $usuarioId = $this->crearUsuario();
        $this->asignarScope($usuarioId, $minaId);
        $token = $this->crearToken($usuarioId);

        $this->withToken($token)->postJson('/api/v1/asistencia/grupos/'.$grupoId.'/marcar', [
            'personal_id' => $trabajadorId,
            'estado' => 'PRESENTE',
        ])->assertOk();

        $detalleId = DB::table('asistencia_detalle')
            ->where('trabajador_id', $trabajadorId)
            ->value('id');

        DB::table('asistencia_detalle')->where('id', $detalleId)->update([
            'grupo_trabajo_detalle_id' => null,
            'rq_proserge_detalle_id' => null,
            'origen_registro' => null,
        ]);

        $this->artisan('asistencia:backfill-distribuciones --dry-run')->assertExitCode(0);
        $this->assertDatabaseHas('asistencia_detalle', [
            'id' => $detalleId,
            'grupo_trabajo_detalle_id' => null,
        ]);

        $this->artisan('parada:recalcular-ejecucion --dry-run')->assertExitCode(0);
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

    private function crearActividadOperativa(string $rqMinaId, string $grupoTrabajoId): string
    {
        $grupoOperativoId = (string) Str::uuid();
        DB::table('rq_mina_actividad_grupos')->insert([
            'id' => $grupoOperativoId,
            'rq_mina_id' => $rqMinaId,
            'nombre' => 'Grupo operativo',
            'area_operativa' => 'Area',
            'modulo' => 'Modulo',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actividadId = (string) Str::uuid();
        DB::table('rq_mina_actividades')->insert([
            'id' => $actividadId,
            'grupo_id' => $grupoOperativoId,
            'sait' => 'SAIT-1',
            'area' => 'Area',
            'sector' => 'Sector',
            'ait_trabajo' => 'Actividad principal',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grupo_trabajo')->where('id', $grupoTrabajoId)->update([
            'rq_mina_actividad_grupo_id' => $grupoOperativoId,
            'cantidad_planificada_snapshot' => 1,
        ]);

        DB::table('grupo_trabajo_actividades')->insert([
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $grupoTrabajoId,
            'rq_mina_actividad_id' => $actividadId,
            'cantidad_planificada_snapshot' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rq_mina_actividad_turnos')->insert([
            'id' => (string) Str::uuid(),
            'actividad_id' => $actividadId,
            'fecha' => '2026-07-01',
            'dia_label' => 'MARTES',
            'turno_a' => '1',
            'turno_b' => '0',
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actividadId;
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
