<?php

namespace Tests\Feature;

use App\Modules\Evaluaciones\Services\PromedioDesempenoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromedioDesempenoRefreshTest extends TestCase
{
    use DatabaseTransactions;

    public function test_refresh_removes_only_workers_average_after_last_evaluation_is_deleted(): void
    {
        $workerId = $this->createWorker();
        $otherWorkerId = $this->createWorker();
        $mineId = $this->createMine();
        $evaluationId = $this->createEvaluation($workerId, $mineId, '2026-10-15', 3);
        $otherEvaluationId = $this->createEvaluation($otherWorkerId, $mineId, '2026-10-10', 4);
        $service = app(PromedioDesempenoService::class);
        $service->refreshForTrabajador($workerId);
        $service->refreshForTrabajador($otherWorkerId);
        $otherAverageId = DB::table('promedio_desempeno')->where('trabajador_id', $otherWorkerId)->value('id');

        $this->assertDatabaseHas('promedio_desempeno', ['trabajador_id' => $workerId, 'cantidad_evaluaciones' => 1]);

        DB::table('evaluacion_desempeno')->where('id', $evaluationId)->delete();
        $service->refreshForTrabajador($workerId);

        $this->assertDatabaseMissing('promedio_desempeno', ['trabajador_id' => $workerId]);
        $this->assertDatabaseHas('promedio_desempeno', [
            'id' => $otherAverageId,
            'trabajador_id' => $otherWorkerId,
            'cantidad_evaluaciones' => 1,
            'promedio_total' => 20,
            'ultima_evaluacion' => '2026-10-10',
        ]);
        $this->assertDatabaseHas('evaluacion_desempeno', ['id' => $otherEvaluationId]);
        $this->assertDatabaseHas('personal', ['id' => $workerId]);
    }

    public function test_refresh_recalculates_existing_average_from_remaining_evaluations(): void
    {
        $workerId = $this->createWorker();
        $mineId = $this->createMine();
        $this->createEvaluation($workerId, $mineId, '2026-10-01', 2);
        $this->createEvaluation($workerId, $mineId, '2026-10-03', 3);
        $latestEvaluationId = $this->createEvaluation($workerId, $mineId, '2026-10-15', 4);
        $service = app(PromedioDesempenoService::class);
        $service->refreshForTrabajador($workerId);
        $averageId = DB::table('promedio_desempeno')->where('trabajador_id', $workerId)->value('id');

        $this->assertDatabaseHas('promedio_desempeno', [
            'id' => $averageId,
            'cantidad_evaluaciones' => 3,
            'promedio_total' => 15,
            'ultima_evaluacion' => '2026-10-15',
        ]);

        DB::table('evaluacion_desempeno')->where('id', $latestEvaluationId)->delete();
        $service->refreshForTrabajador($workerId);

        $this->assertDatabaseHas('promedio_desempeno', [
            'id' => $averageId,
            'trabajador_id' => $workerId,
            'cantidad_evaluaciones' => 2,
            'promedio_total' => 12.5,
            'ultima_evaluacion' => '2026-10-03',
        ]);
        $this->assertSame(1, DB::table('promedio_desempeno')->where('trabajador_id', $workerId)->count());
        $this->assertSame(2, DB::table('evaluacion_desempeno')->where('trabajador_id', $workerId)->count());
    }

    private function createWorker(): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'PERSONA PROMEDIO PRUEBA',
            'puesto' => 'TECNICO',
            'qr_code' => 'QR-'.Str::uuid(),
            'estado' => 'POSTULANTE',
        ]);

        return $id;
    }

    private function createMine(): string
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => 'MINA PROMEDIO '.Str::random(5),
            'unidad_minera' => 'UM PRUEBA',
            'estado' => 'ACTIVO',
        ]);

        return $id;
    }

    private function createEvaluation(string $workerId, string $mineId, string $date, int $score): string
    {
        $id = (string) Str::uuid();
        DB::table('evaluacion_desempeno')->insert([
            'id' => $id,
            'fecha' => $date,
            'hora' => '09:00:00',
            'mina_id' => $mineId,
            'trabajador_id' => $workerId,
            'supervisor_id' => null,
            'desempeno_trabajo' => $score,
            'orden_limpieza' => $score,
            'compromiso' => $score,
            'respuesta_emocional' => $score,
            'seguridad_trabajo' => $score,
            'total' => $score * 5,
            'origen' => 'DIRECTA',
        ]);

        return $id;
    }
}
