<?php

namespace Tests\Feature;

use App\Modules\Evaluaciones\Services\PromedioDesempenoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromedioDesempenoVisibilityTest extends TestCase
{
    public function test_refresh_uses_evaluation_committed_after_outer_snapshot(): void
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        $worker = (string) Str::uuid();
        $mine = (string) Str::uuid();
        config(['database.connections.promedio_writer' => config('database.connections.'.DB::getDefaultConnection())]);

        try {
            DB::table('personal')->insert([
                'id' => $worker, 'dni' => (string) random_int(10000000, 99999999),
                'nombre_completo' => 'Prueba visibilidad promedio', 'puesto' => 'Tecnico',
                'qr_code' => (string) Str::uuid(), 'estado' => 'POSTULANTE',
            ]);
            DB::table('minas')->insert(['id' => $mine, 'nombre' => 'Mina visibilidad '.Str::random(6), 'unidad_minera' => 'Prueba', 'estado' => 'ACTIVO']);
            $first = $this->evaluation($worker, $mine, 10, '2026-06-01');
            DB::table('evaluacion_desempeno')->insert($first);
            app(PromedioDesempenoService::class)->refreshForTrabajador($worker);

            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();
            $this->assertSame(1, DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->count());

            DB::connection('promedio_writer')->table('evaluacion_desempeno')->insert($this->evaluation($worker, $mine, 20, '2026-06-02'));
            // El snapshot anterior sigue viendo una evaluacion; el agregado debe
            // usar los datos confirmados despues de adquirir el lock del trabajador.
            $this->assertSame(1, DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->count());
            DB::table('personal')->where('id', $worker)->lockForUpdate()->first();

            app(PromedioDesempenoService::class)->refreshForTrabajador($worker);

            $this->assertDatabaseHas('promedio_desempeno', ['trabajador_id' => $worker, 'cantidad_evaluaciones' => 2, 'promedio_total' => 15, 'ultima_evaluacion' => '2026-06-02']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('promedio_writer');
            DB::table('evaluacion_desempeno')->where('trabajador_id', $worker)->delete();
            DB::table('promedio_desempeno')->where('trabajador_id', $worker)->delete();
            DB::table('personal')->where('id', $worker)->delete();
            DB::table('minas')->where('id', $mine)->delete();
        }
    }

    private function evaluation(string $worker, string $mine, int $total, string $date): array
    {
        return [
            'id' => (string) Str::uuid(), 'fecha' => $date, 'hora' => '09:00:00',
            'mina_id' => $mine, 'trabajador_id' => $worker, 'supervisor_id' => $worker,
            'desempeno_trabajo' => 2, 'orden_limpieza' => 2, 'compromiso' => 2,
            'respuesta_emocional' => 2, 'seguridad_trabajo' => 2, 'total' => $total,
        ];
    }
}
