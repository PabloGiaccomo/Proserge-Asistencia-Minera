<?php

namespace Tests\Feature;

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
}
