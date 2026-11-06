<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Services\PromedioDesempenoService;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RQMinaDeletionSafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public function test_borrado_conserva_otra_operacion_del_mismo_trabajador_y_mina(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $before = $this->snapshot($other);
        $personalBefore = (array) DB::table('personal')->where('id', $target['worker'])->first();

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('success');

        foreach ($this->rows($target) as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        $this->assertSame($before, $this->snapshot($other));
        $this->assertSame($personalBefore, (array) DB::table('personal')->where('id', $target['worker'])->first());
        $this->assertDatabaseHas('minas', ['id' => $target['mine']]);
        $this->assertDatabaseHas('usuarios', ['id' => $user]);
    }

    public function test_error_de_restriccion_revierte_incluso_las_faltas_ya_borradas(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $externalLink = (string) Str::uuid();
        // Un vinculo ajeno obliga a detener el borrado, no a ampliar su alcance.
        DB::table('grupo_trabajo_actividades')->insert([
            'id' => $externalLink,
            'grupo_trabajo_id' => $other['group'],
            'rq_mina_actividad_id' => $target['activity'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $response = $this->deleteFromWeb($user, $target['rq']);

        $response->assertSessionHas('error')->assertSessionMissing('success');
        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
        $this->assertDatabaseHas('grupo_trabajo_actividades', ['id' => $externalLink]);
    }

    public function test_vinculo_proserge_cruzado_no_borra_el_grupo_de_otro_rq(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        DB::table('grupo_trabajo')->where('id', $other['group'])->update(['rq_proserge_id' => $target['proserge']]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $response = $this->deleteFromWeb($user, $target['rq']);

        $response->assertSessionHas('error', 'No se puede eliminar este RQ Mina: hay registros vinculados a otra parada. Corrige esos vinculos antes de eliminar.');
        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
    }

    public function test_grupo_del_rq_con_proserge_ajeno_no_permite_borrado_parcial(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        DB::table('grupo_trabajo')->where('id', $target['group'])->update(['rq_proserge_id' => $other['proserge']]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
    }

    public function test_asignacion_de_otro_rq_que_apunta_a_detalle_propio_se_conserva(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        DB::table('rq_proserge_detalle')->where('id', $other['assignment'])->update(['rq_mina_detalle_id' => $target['rqDetail']]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
    }

    public function test_distribucion_ajena_que_usa_asignacion_del_rq_no_se_modifica(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        DB::table('grupo_trabajo_detalle')->where('id', $other['groupDetail'])->update(['rq_proserge_detalle_id' => $target['assignment']]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
    }

    public function test_asistencia_ajena_no_pierde_sus_vinculos_por_cascada(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        DB::table('asistencia_detalle')->where('id', $other['attendanceDetail'])->update([
            'grupo_trabajo_detalle_id' => $target['groupDetail'],
            'rq_proserge_detalle_id' => $target['assignment'],
        ]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
    }

    public function test_pasajero_de_servicio_ajeno_no_se_elimina_por_detalle_cruzado(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $service = $this->insert('transporte_servicios', ['rq_mina_id' => $other['rq'], 'fecha' => '2026-06-20', 'turno' => 'DIA']);
        $passenger = $this->insert('transporte_servicio_pasajeros', ['transporte_servicio_id' => $service, 'grupo_trabajo_detalle_id' => $target['groupDetail'], 'personal_id' => $target['worker']]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
        $this->assertDatabaseHas('transporte_servicio_pasajeros', ['id' => $passenger]);
    }

    public function test_lista_herramientas_ajena_no_pierde_grupos_cruzados(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $list = $this->insert('parada_herramienta_listas', ['rq_mina_id' => $other['rq'], 'estado' => 'BORRADOR', 'anio_iso' => 2026, 'semana_iso' => 25, 'fecha_limite_envio' => '2026-06-20']);
        $toolGroup = $this->insert('parada_herramienta_grupos', ['lista_id' => $list, 'grupo_trabajo_id' => $target['group'], 'nombre' => 'Prueba', 'orden' => 1]);
        $beforeTarget = $this->snapshot($target);
        $beforeOther = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($beforeTarget, $this->snapshot($target));
        $this->assertSame($beforeOther, $this->snapshot($other));
        $this->assertDatabaseHas('parada_herramienta_grupos', ['id' => $toolGroup]);
    }

    public function test_grupo_legacy_sin_rq_directo_pero_con_proserge_propio_se_elimina(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        DB::table('grupo_trabajo')->where('id', $target['group'])->update(['rq_mina_id' => null]);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('success');

        foreach ($this->rows($target) as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
    }

    public function test_eliminar_pruebas_recalcula_promedio_sin_conservar_evaluaciones_borradas(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $evaluation = $this->evaluation($target, 10);
        $otherEvaluation = $this->evaluation($other, 20);
        app(PromedioDesempenoService::class)->refreshForTrabajador($target['worker']);
        $this->assertDatabaseHas('promedio_desempeno', ['trabajador_id' => $target['worker'], 'cantidad_evaluaciones' => 2, 'promedio_total' => 15]);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('success');

        $this->assertDatabaseMissing('evaluacion_desempeno', ['id' => $evaluation]);
        $this->assertDatabaseHas('evaluacion_desempeno', ['id' => $otherEvaluation]);
        $this->assertDatabaseHas('promedio_desempeno', ['trabajador_id' => $target['worker'], 'cantidad_evaluaciones' => 1, 'promedio_total' => 20]);

        $this->deleteFromWeb($user, $other['rq'])->assertSessionHas('success');

        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $target['worker']]);
        $this->assertDatabaseHas('personal', ['id' => $target['worker']]);
    }

    public function test_limpia_evidencia_de_herramientas_y_transporte_solo_del_rq_seleccionado(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $beforeOther = $this->snapshot($other);
        $list = $this->insert('parada_herramienta_listas', ['rq_mina_id' => $target['rq'], 'estado' => 'BORRADOR', 'anio_iso' => 2026, 'semana_iso' => 25, 'fecha_limite_envio' => '2026-06-20']);
        $toolGroup = $this->insert('parada_herramienta_grupos', ['lista_id' => $list, 'grupo_trabajo_id' => $target['group'], 'nombre' => 'Prueba', 'orden' => 1]);
        $item = $this->insert('parada_herramienta_items', ['grupo_id' => $toolGroup, 'descripcion' => 'Herramienta de prueba', 'cantidad_solicitada' => 1]);
        $service = $this->insert('transporte_servicios', ['rq_mina_id' => $target['rq'], 'rq_mina_plan_id' => $target['plan'], 'fecha' => '2026-06-20', 'turno' => 'DIA']);
        $passenger = $this->insert('transporte_servicio_pasajeros', ['transporte_servicio_id' => $service, 'grupo_trabajo_detalle_id' => $target['groupDetail'], 'personal_id' => $target['worker']]);
        $scope = $this->insert('transporte_servicio_alcances', ['transporte_servicio_id' => $service, 'rq_mina_actividad_grupo_id' => $target['activityGroup'], 'rq_mina_actividad_id' => $target['activity'], 'grupo_trabajo_id' => $target['group']]);
        $event = $this->insert('transporte_servicio_eventos', ['transporte_servicio_id' => $service, 'tipo' => 'CREADO']);
        $request = $this->insert('rq_mina_actividad_transportes', ['grupo_id' => $target['activityGroup'], 'actividad_id' => $target['activity'], 'rq_mina_plan_id' => $target['plan'], 'alcance' => 'GRUPO', 'orden' => 1]);
        $requestEvent = $this->insert('rq_mina_actividad_transporte_eventos', ['rq_mina_id' => $target['rq'], 'transporte_id' => $request, 'tipo' => 'CREADO']);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('success');

        foreach (['parada_herramienta_listas' => $list, 'parada_herramienta_grupos' => $toolGroup, 'parada_herramienta_items' => $item, 'transporte_servicios' => $service, 'transporte_servicio_pasajeros' => $passenger, 'transporte_servicio_alcances' => $scope, 'transporte_servicio_eventos' => $event, 'rq_mina_actividad_transportes' => $request, 'rq_mina_actividad_transporte_eventos' => $requestEvent] as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        $this->assertSame($beforeOther, $this->snapshot($other));
        $this->assertDatabaseHas('personal', ['id' => $target['worker']]);
    }

    public function test_sin_permiso_eliminar_no_cambia_ningun_registro(): void
    {
        $user = $this->user(['ver' => true, 'eliminar' => false]);
        $target = $this->operation($user);
        $this->scope($user, $target['mine']);
        $before = $this->snapshot($target);

        $this->deleteFromWeb($user, $target['rq'])->assertForbidden();

        $this->assertSame($before, $this->snapshot($target));
        $this->assertFalse(app(RQMinaService::class)->delete(Usuario::findOrFail($user), RQMina::findOrFail($target['rq'])));
    }

    public function test_creador_con_permiso_pero_sin_scope_no_puede_eliminar(): void
    {
        $user = $this->user(['ver' => true, 'eliminar' => true]);
        $target = $this->operation($user);
        $before = $this->snapshot($target);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($before, $this->snapshot($target));
        $this->assertFalse(app(RQMinaService::class)->delete(Usuario::findOrFail($user), RQMina::findOrFail($target['rq'])));
    }

    public function test_usuario_con_scope_y_permiso_no_elimina_rq_de_otro_creador(): void
    {
        $owner = $this->user();
        $user = $this->user(['ver' => true, 'eliminar' => true]);
        $target = $this->operation($owner);
        $this->scope($user, $target['mine']);
        $before = $this->snapshot($target);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($before, $this->snapshot($target));
    }

    public function test_creador_con_scope_y_permiso_puede_eliminar(): void
    {
        $user = $this->user(['ver' => true, 'eliminar' => true]);
        $target = $this->operation($user);
        $this->scope($user, $target['mine']);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('success');

        $this->assertDatabaseMissing('rq_mina', ['id' => $target['rq']]);
    }

    public function test_repetir_el_borrado_no_afecta_otro_rq(): void
    {
        $user = $this->user();
        $target = $this->operation($user);
        $other = $this->operation($user, $target['worker'], $target['mine']);
        $before = $this->snapshot($other);

        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('success');
        $this->deleteFromWeb($user, $target['rq'])->assertSessionHas('error');

        $this->assertSame($before, $this->snapshot($other));
    }

    public function test_peticion_sin_sesion_no_elimina(): void
    {
        $target = $this->operation($this->user());
        $before = $this->snapshot($target);

        $this->post(route('rq-mina.destroy', $target['rq']))->assertRedirect(route('login'));

        $this->assertSame($before, $this->snapshot($target));
    }

    private function user(?array $permissions = null): string
    {
        if ($permissions === null) {
            $role = Rol::firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid()]);
            $role->update(['estado' => 'ACTIVO']);
        } else {
            $role = Rol::create([
                'id' => (string) Str::uuid(),
                'nombre' => 'DELETE_REVIEW_'.Str::random(8),
                'estado' => 'ACTIVO',
                'permisos' => ['rq_mina' => $permissions],
            ]);
        }

        return $this->insert('usuarios', ['email' => Str::uuid().'@test.local', 'password' => bcrypt('test'), 'rol_id' => $role->id]);
    }

    private function scope(string $user, string $mine): void
    {
        $this->insert('usuario_mina_scope', ['usuario_id' => $user, 'mina_id' => $mine]);
    }

    private function deleteFromWeb(string $user, string $rq): TestResponse
    {
        return $this->withSession(['auth_token' => 'review-test', 'user_id' => $user])->post(route('rq-mina.destroy', $rq));
    }

    private function operation(string $user, ?string $worker = null, ?string $mine = null): array
    {
        $mine ??= $this->insert('minas', ['nombre' => 'Mina revision '.Str::random(6), 'unidad_minera' => 'Prueba', 'estado' => 'ACTIVO']);
        $worker ??= $this->insert('personal', ['dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'Trabajador de prueba', 'puesto' => 'Tecnico', 'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $rq = $this->insert('rq_mina', ['mina_id' => $mine, 'area' => 'Prueba', 'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-30', 'estado' => 'ENVIADO', 'created_by_usuario_id' => $user]);
        $rqDetail = $this->insert('rq_mina_detalle', ['rq_mina_id' => $rq, 'puesto' => 'Tecnico', 'cantidad' => 1, 'cantidad_atendida' => 1]);
        $proserge = $this->insert('rq_proserge', ['rq_mina_id' => $rq, 'mina_id' => $mine, 'responsable_rrhh_id' => $user, 'estado' => 'PENDIENTE']);
        $assignment = $this->insert('rq_proserge_detalle', ['rq_proserge_id' => $proserge, 'rq_mina_detalle_id' => $rqDetail, 'personal_id' => $worker, 'puesto_asignado' => 'Tecnico', 'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-30', 'estado' => 'ASIGNADO']);
        $plan = $this->insert('rq_mina_planes', ['rq_mina_id' => $rq, 'codigo' => 'TEST', 'nombre' => 'Plan prueba', 'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-30']);
        $activityGroup = $this->insert('rq_mina_actividad_grupos', ['rq_mina_id' => $rq, 'rq_mina_plan_id' => $plan, 'nombre' => 'Grupo prueba', 'orden' => 1]);
        $activity = $this->insert('rq_mina_actividades', ['grupo_id' => $activityGroup, 'ait_trabajo' => 'Prueba', 'orden' => 1]);
        $group = $this->insert('grupo_trabajo', ['rq_mina_id' => $rq, 'rq_proserge_id' => $proserge, 'rq_mina_plan_id' => $plan, 'rq_mina_actividad_grupo_id' => $activityGroup, 'fecha' => '2026-06-20', 'supervisor_id' => $worker, 'mina' => 'Prueba', 'servicio' => 'Prueba', 'area' => 'Prueba', 'horario_salida' => '07:00:00', 'turno' => 'DIA', 'estado' => 'BORRADOR', 'created_by_id' => $user]);
        $groupDetail = $this->insert('grupo_trabajo_detalle', ['grupo_trabajo_id' => $group, 'personal_id' => $worker, 'rq_proserge_detalle_id' => $assignment, 'estado_asistencia' => 'AUSENTE']);
        $groupActivity = $this->insert('grupo_trabajo_actividades', ['grupo_trabajo_id' => $group, 'rq_mina_actividad_id' => $activity]);
        $principal = $this->insert('grupo_trabajo_detalle_actividades', ['grupo_trabajo_detalle_id' => $groupDetail, 'rq_mina_actividad_id' => $activity, 'es_principal' => true]);
        $attendance = $this->insert('asistencia_encabezado', ['grupo_trabajo_id' => $group, 'fecha' => '2026-06-20', 'hora_ingreso' => '07:00:00', 'mina_id' => $mine, 'supervisor_id' => $worker, 'estado' => 'CERRADO']);
        $attendanceDetail = $this->insert('asistencia_detalle', ['asistencia_id' => $attendance, 'grupo_trabajo_detalle_id' => $groupDetail, 'rq_proserge_detalle_id' => $assignment, 'trabajador_id' => $worker, 'hora_marcado' => '00:00:00', 'estado' => 'AUSENTE']);
        $absence = $this->insert('faltas', ['trabajador_id' => $worker, 'fecha' => '2026-06-20', 'motivo' => 'INASISTENCIA_ASISTENCIA', 'estado' => 'ACTIVA', 'registrada_por_id' => $worker, 'asistencia_encabezado_id' => $attendance, 'asistencia_detalle_id' => $attendanceDetail]);
        $summary = $this->insert('parada_ejecucion_resumen', ['rq_mina_id' => $rq, 'rq_mina_plan_id' => $plan, 'rq_mina_actividad_grupo_id' => $activityGroup, 'rq_mina_actividad_id' => $activity, 'fecha' => '2026-06-20', 'turno' => 'DIA', 'ausentes' => 1]);

        return compact('user', 'mine', 'worker', 'rq', 'rqDetail', 'proserge', 'assignment', 'plan', 'activityGroup', 'activity', 'group', 'groupDetail', 'groupActivity', 'principal', 'attendance', 'attendanceDetail', 'absence', 'summary');
    }

    private function insert(string $table, array $attributes): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id, 'created_at' => now(), 'updated_at' => now()] + $attributes);

        return $id;
    }

    private function evaluation(array $operation, int $total): string
    {
        return $this->insert('evaluacion_desempeno', [
            'fecha' => '2026-06-20', 'hora' => '09:00:00', 'mina_id' => $operation['mine'],
            'trabajador_id' => $operation['worker'], 'supervisor_id' => $operation['worker'],
            'desempeno_trabajo' => 2, 'orden_limpieza' => 2, 'compromiso' => 2,
            'respuesta_emocional' => 2, 'seguridad_trabajo' => 2, 'total' => $total,
            'asistencia_detalle_id' => $operation['attendanceDetail'],
        ]);
    }

    private function rows(array $operation): array
    {
        return [
            'rq_mina' => $operation['rq'], 'rq_mina_detalle' => $operation['rqDetail'],
            'rq_proserge' => $operation['proserge'], 'rq_proserge_detalle' => $operation['assignment'],
            'rq_mina_planes' => $operation['plan'], 'rq_mina_actividad_grupos' => $operation['activityGroup'],
            'rq_mina_actividades' => $operation['activity'], 'grupo_trabajo' => $operation['group'],
            'grupo_trabajo_detalle' => $operation['groupDetail'], 'grupo_trabajo_actividades' => $operation['groupActivity'],
            'grupo_trabajo_detalle_actividades' => $operation['principal'], 'asistencia_encabezado' => $operation['attendance'],
            'asistencia_detalle' => $operation['attendanceDetail'], 'faltas' => $operation['absence'],
            'parada_ejecucion_resumen' => $operation['summary'],
        ];
    }

    private function snapshot(array $operation): array
    {
        $snapshot = [];
        foreach ($this->rows($operation) as $table => $id) {
            $snapshot[$table] = (array) DB::table($table)->where('id', $id)->first();
        }

        return $snapshot;
    }
}
