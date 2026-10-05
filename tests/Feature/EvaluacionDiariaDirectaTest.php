<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Modules\Evaluaciones\Services\EvaluacionDesempenoService;
use App\Modules\Evaluaciones\Services\EvaluacionesDashboardService;
use App\Modules\Evaluaciones\Services\EvaluacionesPageService;
use App\Modules\Evaluaciones\Services\PromedioDesempenoService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluacionDiariaDirectaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_schema_supports_daily_origin_and_idempotent_submission(): void
    {
        $this->assertTrue(Schema::hasColumn('evaluacion_desempeno', 'origen'));
        $this->assertTrue(Schema::hasColumn('evaluacion_desempeno', 'submission_key'));

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->whereIn('column_name', ['origen', 'submission_key', 'supervisor_id'])
            ->pluck('is_nullable', 'column_name');

        $this->assertSame('YES', $columns['origen']);
        $this->assertSame('YES', $columns['submission_key']);
        $this->assertSame('YES', $columns['supervisor_id']);

        $this->assertSame(1, DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->where('index_name', 'uq_eval_des_submission_key')
            ->where('non_unique', 0)
            ->count());
    }

    public function test_submission_key_prevents_one_replay_but_not_separate_evaluations(): void
    {
        $worker = (string) Str::uuid();
        $mine = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $worker,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'TRABAJADOR PRUEBA DIRECTA',
            'puesto' => 'TECNICO',
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
        ]);
        DB::table('minas')->insert([
            'id' => $mine,
            'nombre' => 'MINA PRUEBA DIRECTA',
            'unidad_minera' => 'UM PRUEBA',
            'estado' => 'ACTIVO',
        ]);

        $row = [
            'fecha' => '2026-10-05',
            'hora' => '09:00:00',
            'mina_id' => $mine,
            'supervisor_id' => null,
            'trabajador_id' => $worker,
            'desempeno_trabajo' => 3,
            'orden_limpieza' => 3,
            'compromiso' => 3,
            'respuesta_emocional' => 3,
            'seguridad_trabajo' => 3,
            'total' => 15,
            'origen' => 'DIRECTA',
        ];
        $submission = (string) Str::uuid();
        DB::table('evaluacion_desempeno')->insert([...$row, 'id' => (string) Str::uuid(), 'submission_key' => $submission]);
        DB::table('evaluacion_desempeno')->insert([...$row, 'id' => (string) Str::uuid(), 'submission_key' => (string) Str::uuid()]);

        try {
            DB::table('evaluacion_desempeno')->insert([...$row, 'id' => (string) Str::uuid(), 'submission_key' => $submission]);
            $this->fail('Una misma clave de envio no puede guardarse dos veces.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
        }

        $this->assertSame(2, DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->count());
    }

    public function test_direct_requires_permission_active_mine_and_valid_personal(): void
    {
        $worker = $this->personal();
        $mine = $this->mine();
        $allowed = $this->user(['evaluar_desempeno']);
        $denied = $this->user(['ver_desempeno']);
        $service = app(EvaluacionDesempenoService::class);

        $this->assertSame('EVAL_FORBIDDEN', $service->createDirect($denied, $this->payload($worker, $mine))['code']);
        $this->assertSame('EVAL_MINE_INVALID', $service->createDirect($allowed, $this->payload($worker, (string) Str::uuid()))['code']);
        DB::table('minas')->where('id', $mine)->update(['estado' => 'INACTIVO']);
        $this->assertSame('EVAL_MINE_INVALID', $service->createDirect($allowed, $this->payload($worker, $mine))['code']);
        DB::table('minas')->where('id', $mine)->update(['estado' => 'ACTIVO']);
        $this->assertSame('EVAL_WORKER_NOT_FOUND', $service->createDirect($allowed, $this->payload((string) Str::uuid(), $mine))['code']);
        $this->assertSame(0, DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->count());
    }

    public function test_account_without_personal_can_evaluate_but_linked_user_cannot_self_evaluate(): void
    {
        $worker = $this->personal();
        $mine = $this->mine();
        $unlinked = $this->user(['evaluar_desempeno']);
        $linked = $this->user(['evaluar_desempeno'], $worker);
        $service = app(EvaluacionDesempenoService::class);

        $this->assertSame('EVAL_SELF_NOT_ALLOWED', $service->createDirect($linked, $this->payload($worker, $mine))['code']);
        $result = $service->createDirect($unlinked, $this->payload($worker, $mine));
        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('evaluacion_desempeno', [
            'id' => $result['item']->id,
            'origen' => 'DIRECTA',
            'supervisor_id' => null,
            'evaluado_por_usuario_id' => $unlinked->id,
            'mina_id' => $mine,
            'asistencia_detalle_id' => null,
            'total' => 15,
        ]);
    }

    public function test_separate_direct_submissions_same_day_succeed_but_reused_key_does_not(): void
    {
        $worker = $this->personal();
        $mine = $this->mine();
        $actor = $this->user(['evaluar_desempeno']);
        $service = app(EvaluacionDesempenoService::class);
        $payload = $this->payload($worker, $mine);

        $this->assertTrue($service->createDirect($actor, $payload)['ok']);
        $this->assertSame('EVAL_DUPLICATED', $service->createDirect($actor, $payload)['code']);
        $this->assertTrue($service->createDirect($actor, $this->payload($worker, $mine))['ok']);
        $this->assertSame(2, DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->count());
        $this->assertDatabaseHas('promedio_desempeno', ['trabajador_id' => $worker, 'cantidad_evaluaciones' => 2]);
    }

    public function test_average_failure_rolls_back_direct_evaluation(): void
    {
        $worker = $this->personal();
        $mine = $this->mine();
        $actor = $this->user(['evaluar_desempeno']);
        $average = \Mockery::mock(PromedioDesempenoService::class);
        $average->shouldReceive('refreshForTrabajador')->once()->with($worker)->andThrow(new \RuntimeException('average failed'));
        $this->app->instance(PromedioDesempenoService::class, $average);

        try {
            app(EvaluacionDesempenoService::class)->createDirect($actor, $this->payload($worker, $mine));
            $this->fail('El fallo del promedio debio propagarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('average failed', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->count());
        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $worker]);
    }

    public function test_direct_author_sees_record_without_mine_scope_in_history_lookup_and_dashboard(): void
    {
        $worker = $this->personal();
        $mine = $this->mine();
        $actor = $this->user(['evaluar_desempeno', 'ver_todas_evaluaciones']);
        $item = app(EvaluacionDesempenoService::class)->createDirect($actor, $this->payload($worker, $mine))['item'];

        $page = app(EvaluacionesPageService::class)->build($actor, 'desempeno', '2026-10-05');
        $this->assertTrue($page['dailyHistory']->contains('id', $item->id));
        $this->assertSame($item->id, app(EvaluacionDesempenoService::class)->find($actor, $item->id)?->id);
        $this->assertTrue(app(EvaluacionDesempenoService::class)->list($actor, [])->contains('id', $item->id));

        $dashboard = app(EvaluacionesDashboardService::class)->build($actor, [
            'desde' => '2026-10-01', 'hasta' => '2026-10-31', 'mina' => '', 'cargo' => '', 'evaluacion_tipo' => 'desempeno',
        ]);
        $this->assertSame(1, $dashboard['total']);
    }

    private function personal(): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'PERSONA DIRECTA',
            'puesto' => 'PERSONAL',
            'qr_code' => 'QR-'.Str::upper(Str::random(8)),
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function mine(): string
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert(['id' => $id, 'nombre' => 'MINA DIRECTA '.Str::random(5), 'unidad_minera' => 'UM', 'estado' => 'ACTIVO']);

        return $id;
    }

    private function user(array $actions, ?string $personalId = null): Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'EVALUADOR_DIRECTO_'.Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['evaluaciones' => $actions])),
            'estado' => 'ACTIVO',
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)).'@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
        ]);

        return Usuario::findOrFail($userId);
    }

    private function payload(string $worker, string $mine): array
    {
        return [
            'trabajador_id' => $worker,
            'mina_id' => $mine,
            'fecha' => '2026-10-05',
            'submission_key' => (string) Str::uuid(),
            'desempeno_trabajo' => 3,
            'orden_limpieza' => 3,
            'compromiso' => 3,
            'respuesta_emocional' => 3,
            'seguridad_trabajo' => 3,
        ];
    }
}
