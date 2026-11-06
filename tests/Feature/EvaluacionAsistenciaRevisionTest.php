<?php

namespace Tests\Feature;

use App\Models\AsistenciaDetalle;
use App\Models\EvaluacionDesempeno;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Services\EvaluacionAsistenciaRevisionService;
use App\Modules\Evaluaciones\Services\EvaluacionesDashboardService;
use App\Modules\Evaluaciones\Services\PromedioDesempenoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class EvaluacionAsistenciaRevisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->assertSame('13376', (string) config('database.connections.mysql.port'));
    }

    public static function origins(): array
    {
        return ['asistencia' => ['ASISTENCIA'], 'historica' => ['HISTORICA'], 'directa' => ['DIRECTA']];
    }

    #[DataProvider('origins')]
    public function test_solo_la_evaluacion_vinculada_pasa_a_revision_y_conserva_notas_de_otros_origenes(string $origin): void
    {
        [$actor, $mine] = $this->actor();
        $worker = $this->personal('Trabajador evaluado');
        $detail = $this->attendance($actor, $mine, $worker);
        $linked = $this->evaluation($actor, $mine, $worker, 16, $origin, $detail->id);
        $historical = $this->evaluation($actor, $mine, $worker, 12, 'HISTORICA');
        $direct = $this->evaluation($actor, $mine, $worker, 18, 'DIRECTA');
        $averages = app(PromedioDesempenoService::class);
        $averages->refreshForTrabajador($worker);

        $this->markAbsent($detail, null);
        $this->markAbsent($detail, null);
        $this->assertSame('PENDIENTE_REVISION', $linked->fresh()->estado_revision);
        $this->assertSame(16, $linked->fresh()->total);
        $this->assertSame('VALIDA', $historical->fresh()->estado_revision);
        $this->assertSame('VALIDA', $direct->fresh()->estado_revision);
        $this->assertSame(15.0, $averages->list(['trabajador_id' => $worker])[0]['promedio_total']);
        $this->assertSame(15.0, $averages->comparacion(['trabajador_ids' => [$worker]])[0]['promedio_total']);
        $dashboard = app(EvaluacionesDashboardService::class)->build($actor, [
            'desde' => '2026-11-01', 'hasta' => '2026-11-30', 'mina' => $mine,
            'cargo' => '', 'evaluacion_tipo' => 'desempeno',
        ]);
        $this->assertSame(2, $dashboard['total']);
        $this->assertSame(15.0, $dashboard['summary'][0]['average']);
        $event = DB::table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $linked->id)->sole();
        $this->assertNull($event->actor_usuario_id);
        $this->assertSame('La asistencia vinculada cambió a ausente.', Crypt::decryptString($event->motivo));

        $this->asActor($actor)->get(route('evaluaciones.revisiones-asistencia'))->assertOk()->assertSee('Trabajador evaluado');
        $this->post(route('evaluaciones.revisiones-asistencia.resolve', $linked->id), [
            'revision' => 1, 'decision' => 'EXCLUIR', 'motivo' => 'Revisión del evaluador original.',
        ])->assertRedirect(route('evaluaciones.revisiones-asistencia'));
        $this->assertSame('EXCLUIDA', $linked->fresh()->estado_revision);
        $this->assertSame(16, $linked->fresh()->total);
        $this->assertDatabaseHas('promedio_desempeno', ['trabajador_id' => $worker, 'cantidad_evaluaciones' => 2, 'promedio_total' => 15]);
    }

    public function test_revision_con_detalle_eliminado_permite_excluir_pero_no_ratificar(): void
    {
        [$actor, $mine] = $this->actor();
        $worker = $this->personal('Trabajador con origen eliminado');
        $detail = $this->attendance($actor, $mine, $worker);
        $evaluation = $this->evaluation($actor, $mine, $worker, 16, 'HISTORICA', $detail->id);
        $this->markAbsent($detail, $actor);
        DB::table('asistencia_detalle')->where('id', $detail->id)->delete();

        $this->asActor($actor)->get(route('evaluaciones.revisiones-asistencia'))->assertOk()->assertSee('Trabajador con origen eliminado');
        $this->postJson(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), [
            'revision' => 1, 'decision' => 'RATIFICAR', 'motivo' => 'Sin evidencia de asistencia.',
        ])->assertUnprocessable();
        $this->post(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), [
            'revision' => 1, 'decision' => 'EXCLUIR', 'motivo' => 'Se conserva la nota histórica sin respaldo de asistencia.',
        ])->assertRedirect();
        $this->assertSame('EXCLUIDA', $evaluation->fresh()->estado_revision);
        $this->assertSame(16, $evaluation->fresh()->total);
        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $worker]);
        $this->assertSame(2, DB::table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $evaluation->id)->count());
    }

    public static function ownership(): array
    {
        return ['usuario original' => [false], 'evaluador legado' => [true]];
    }

    #[DataProvider('ownership')]
    public function test_solo_el_evaluador_original_resuelve_aunque_otro_tenga_privilegios(bool $legacy): void
    {
        [$actor, $mine] = $this->actor();
        [$other] = $this->actor($mine, true);
        $worker = $this->personal('Evaluación de evaluador legado');
        $detail = $this->attendance($actor, $mine, $worker);
        $evaluation = $this->evaluation($actor, $mine, $worker, 16, 'HISTORICA', $detail->id);
        if ($legacy) {
            DB::table('evaluacion_desempeno')->where('id', $evaluation->id)->update(['evaluado_por_usuario_id' => null]);
        }
        $this->markAbsent($detail, $actor);
        $payload = ['revision' => 1, 'decision' => 'EXCLUIR', 'motivo' => 'Decisión del evaluador original.'];

        $this->asActor($other)->get(route('evaluaciones.revisiones-asistencia'))->assertOk()->assertDontSee('Evaluación de evaluador legado');
        $this->postJson(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), $payload)->assertForbidden();
        $this->asActor($actor)->post(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), $payload)->assertRedirect();
        $this->assertSame(16, $evaluation->fresh()->total);
        $this->assertSame('EXCLUIDA', $evaluation->fresh()->estado_revision);
    }

    public function test_reintento_idempotente_no_sobrescribe_motivo_y_revision_anterior_no_resuelve_un_nuevo_cambio(): void
    {
        [$actor, $mine] = $this->actor();
        $worker = $this->personal('Evaluación con varias revisiones');
        $detail = $this->attendance($actor, $mine, $worker);
        $evaluation = $this->evaluation($actor, $mine, $worker, 16, 'ASISTENCIA', $detail->id);
        $this->markAbsent($detail, $actor);
        $detail->forceFill(['estado' => 'PRESENTE'])->save();
        $this->asActor($actor);
        $payload = ['revision' => 1, 'decision' => 'RATIFICAR', 'motivo' => 'Asistencia corregida y cerrada.'];
        $this->post(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), $payload)->assertRedirect();
        $this->post(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), [...$payload, 'motivo' => 'Reintento con otro texto.'])->assertRedirect();
        $this->assertSame(2, DB::table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $evaluation->id)->count());
        $decision = DB::table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $evaluation->id)->where('revision', 2)->sole();
        $this->assertSame('Asistencia corregida y cerrada.', Crypt::decryptString($decision->motivo));

        $this->markAbsent($detail, $actor);
        $this->postJson(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), $payload)->assertConflict();
        $this->assertSame('PENDIENTE_REVISION', $evaluation->fresh()->estado_revision);
        $this->assertSame(16, $evaluation->fresh()->total);
        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $worker]);
        $this->assertSame(3, DB::table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $evaluation->id)->count());
    }

    public function test_resolucion_revalida_permiso_actual_despues_del_bloqueo(): void
    {
        [$actor, $mine] = $this->actor();
        $worker = $this->personal('Evaluación con permiso retirado');
        $detail = $this->attendance($actor, $mine, $worker);
        $evaluation = $this->evaluation($actor, $mine, $worker, 16, 'ASISTENCIA', $detail->id);
        $this->markAbsent($detail, $actor);
        $revoked = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($actor, &$revoked): void {
            if (! $revoked && str_contains(strtolower($sql), 'for update')) {
                $revoked = true;
                DB::table('roles')->where('id', $actor->rol_id)->update(['permisos' => json_encode(PermissionCatalog::emptyMatrix())]);
            }
        });

        try {
            app(EvaluacionAsistenciaRevisionService::class)->resolve($evaluation->id, $actor, 1, 'EXCLUIR', 'Permiso revocado durante la espera.');
            $this->fail('La resolución debe rechazar el permiso revocado.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($revoked);
        $this->assertSame('PENDIENTE_REVISION', $evaluation->fresh()->estado_revision);
        $this->assertSame(1, DB::table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $evaluation->id)->count());
    }

    public function test_ratificar_rechaza_un_detalle_cuyo_trabajador_cambio_durante_la_espera(): void
    {
        [$actor, $mine] = $this->actor();
        $worker = $this->personal('Evaluación con origen cambiado');
        $otherWorker = $this->personal('Otro trabajador');
        $detail = $this->attendance($actor, $mine, $worker);
        $evaluation = $this->evaluation($actor, $mine, $worker, 16, 'ASISTENCIA', $detail->id);
        $this->markAbsent($detail, $actor);
        $detail->forceFill(['estado' => 'PRESENTE'])->save();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($detail, $otherWorker, &$changed): void {
            if (! $changed && str_contains(strtolower($sql), 'for update')) {
                $changed = true;
                DB::table('asistencia_detalle')->where('id', $detail->id)->update(['trabajador_id' => $otherWorker]);
            }
        });

        $this->asActor($actor)->postJson(route('evaluaciones.revisiones-asistencia.resolve', $evaluation->id), [
            'revision' => 1, 'decision' => 'RATIFICAR', 'motivo' => 'Revisión de la marca vinculada.',
        ])->assertConflict();
        $this->assertTrue($changed);
        $this->assertSame('PENDIENTE_REVISION', $evaluation->fresh()->estado_revision);
        $this->assertSame(16, $evaluation->fresh()->total);
        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $worker]);
    }

    public static function revocations(): array
    {
        return ['rol' => ['rol'], 'rol adicional' => ['adicional'], 'cuenta' => ['cuenta'], 'mina' => ['mina']];
    }

    #[DataProvider('revocations')]
    public function test_resolucion_lee_autorizacion_revocada_por_otra_conexion_pese_al_snapshot_previo(string $revocation): void
    {
        DB::rollBack();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        $primary = DB::getDefaultConnection();
        $writer = 'revision_writer';
        config(['database.connections.'.$writer => config('database.connections.'.$primary)]);
        $actor = $evaluation = $detail = null;
        $worker = $mine = $additionalRole = null;
        DB::setDefaultConnection($writer);
        try {
            [$actor, $mine] = $this->actor();
            if ($revocation === 'adicional') {
                $additionalRole = (string) Str::uuid();
                DB::table('roles')->where('id', $actor->rol_id)->update(['permisos' => json_encode(PermissionCatalog::matrixFromSelections([
                    'evaluaciones' => ['ver_desempeno'],
                ]))]);
                DB::table('roles')->insert(['id' => $additionalRole, 'nombre' => 'REVISION_ADICIONAL_'.Str::random(8), 'estado' => 'ACTIVO',
                    'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['evaluaciones' => ['evaluar_desempeno']]))]);
                DB::table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $actor->id, 'rol_id' => $additionalRole]);
            }
            $worker = $this->personal('Revisión con revocación concurrente');
            $detail = $this->attendance($actor, $mine, $worker);
            $evaluation = $this->evaluation($actor, $mine, $worker, 16, 'ASISTENCIA', $detail->id);
            $this->markAbsent($detail, $actor);
            DB::setDefaultConnection($primary);
            $actor = Usuario::query()->findOrFail($actor->id);
            $revoked = false;
            DB::connection()->beforeExecuting(function (string $sql) use ($writer, $actor, $additionalRole, $revocation, &$revoked): void {
                if (! $revoked && str_contains(strtolower($sql), 'for update')) {
                    $revoked = true;
                    $connection = DB::connection($writer);
                    match ($revocation) {
                        'rol' => $connection->table('roles')->where('id', $actor->rol_id)->update(['permisos' => json_encode(PermissionCatalog::emptyMatrix())]),
                        'adicional' => $connection->table('roles')->where('id', $additionalRole)->update(['estado' => 'INACTIVO']),
                        'cuenta' => $connection->table('usuarios')->where('id', $actor->id)->update(['estado' => 'INACTIVO']),
                        'mina' => $connection->table('usuario_mina_scope')->where('usuario_id', $actor->id)->delete(),
                    };
                }
            });

            try {
                app(EvaluacionAsistenciaRevisionService::class)->resolve($evaluation->id, $actor, 1, 'EXCLUIR', 'Permiso revocado desde otra conexión.');
                $this->fail('La resolución debe usar el permiso actual y rechazar el snapshot anterior.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($revoked);
            $this->assertSame('PENDIENTE_REVISION', $evaluation->fresh()->estado_revision);
        } finally {
            DB::setDefaultConnection($primary);
            DB::rollBack();
            DB::beginTransaction();
            $connection = DB::connection($writer);
            if ($evaluation) {
                $connection->table('evaluacion_asistencia_revision_eventos')->where('evaluacion_id', $evaluation->id)->delete();
                $connection->table('evaluacion_desempeno')->where('id', $evaluation->id)->delete();
            }
            if ($detail) {
                $connection->table('asistencia_detalle')->where('id', $detail->id)->delete();
                $connection->table('asistencia_encabezado')->where('id', $detail->asistencia_id)->delete();
            }
            if ($actor) {
                $connection->table('usuario_roles')->where('usuario_id', $actor->id)->delete();
                $connection->table('usuario_mina_scope')->where('usuario_id', $actor->id)->delete();
                $connection->table('usuarios')->where('id', $actor->id)->delete();
                $connection->table('roles')->where('id', $actor->rol_id)->delete();
                $connection->table('personal')->where('id', $actor->personal_id)->delete();
            }
            if ($additionalRole) {
                $connection->table('roles')->where('id', $additionalRole)->delete();
            }
            if ($worker) {
                $connection->table('promedio_desempeno')->where('trabajador_id', $worker)->delete();
                $connection->table('personal')->where('id', $worker)->delete();
            }
            if ($mine) {
                $connection->table('minas')->where('id', $mine)->delete();
            }
            DB::purge($writer);
        }
    }

    private function actor(?string $mine = null, bool $privileged = false): array
    {
        $mine ??= (string) Str::uuid();
        DB::table('minas')->insertOrIgnore(['id' => $mine, 'nombre' => 'Mina revisión '.$mine, 'unidad_minera' => 'Prueba', 'estado' => 'ACTIVO']);
        $role = (string) Str::uuid();
        $actions = ['ver_desempeno', 'evaluar_desempeno', 'dashboards'];
        if ($privileged) {
            $actions[] = 'administrar';
            $actions[] = 'ver_todas_evaluaciones';
        }
        DB::table('roles')->insert(['id' => $role, 'nombre' => 'REVISION_'.Str::random(12), 'estado' => 'ACTIVO',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['evaluaciones' => $actions]))]);
        $id = (string) Str::uuid();
        DB::table('usuarios')->insert(['id' => $id, 'email' => $id.'@test.local', 'password' => 'unused', 'rol_id' => $role,
            'personal_id' => $this->personal('Evaluador original'), 'estado' => 'ACTIVO']);
        DB::table('usuario_mina_scope')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $id, 'mina_id' => $mine]);

        return [Usuario::findOrFail($id), $mine];
    }

    private function personal(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert(['id' => $id, 'dni' => (string) random_int(10000000, 99999999), 'qr_code' => 'TEST-'.$id,
            'nombre_completo' => $name, 'puesto' => 'Operario', 'estado' => 'ACTIVO']);

        return $id;
    }

    private function attendance(Usuario $actor, string $mine, string $worker): AsistenciaDetalle
    {
        $header = (string) Str::uuid();
        DB::table('asistencia_encabezado')->insert(['id' => $header, 'fecha' => '2026-11-04', 'hora_ingreso' => '07:00:00',
            'mina_id' => $mine, 'supervisor_id' => $actor->personal_id, 'destino_tipo' => 'MINA', 'destino_id' => $mine, 'estado' => 'CERRADO']);

        return AsistenciaDetalle::create(['id' => (string) Str::uuid(), 'asistencia_id' => $header,
            'trabajador_id' => $worker, 'hora_marcado' => '06:58:00', 'estado' => 'PRESENTE']);
    }

    private function evaluation(Usuario $actor, string $mine, string $worker, int $score, string $origin, ?string $detail = null): EvaluacionDesempeno
    {
        $evaluation = EvaluacionDesempeno::create(['id' => (string) Str::uuid(), 'fecha' => '2026-11-04', 'mina_id' => $mine,
            'destino_tipo' => 'MINA', 'destino_id' => $mine, 'trabajador_id' => $worker, 'supervisor_id' => $actor->personal_id,
            'evaluado_por_usuario_id' => $actor->id, 'origen' => $origin, 'asistencia_detalle_id' => $detail,
            'desempeno_trabajo' => 3, 'orden_limpieza' => 3, 'compromiso' => 3, 'respuesta_emocional' => 3, 'seguridad_trabajo' => 3,
            'total' => $score]);

        return $evaluation->fresh();
    }

    private function markAbsent(AsistenciaDetalle $detail, ?Usuario $actor): void
    {
        DB::transaction(function () use ($detail, $actor): void {
            $detail->forceFill(['estado' => 'AUSENTE'])->save();
            app(EvaluacionAsistenciaRevisionService::class)->markPendingForDetail($detail, $actor);
        });
    }

    private function asActor(Usuario $actor): static
    {
        return $this->withSession(['auth_token' => 'synthetic', 'user_id' => $actor->id, 'user' => ['id' => $actor->id]]);
    }
}
