<?php

namespace Tests\Unit;

use App\Modules\RQMina\Support\ParadaKpiDefinition;
use PHPUnit\Framework\TestCase;

class ParadaKpiDefinitionTest extends TestCase
{
    public function test_it_builds_dashboard_cards_from_consolidated_metrics(): void
    {
        $cards = ParadaKpiDefinition::cards([
            'rq' => ['total_objetivo' => 12],
            'coverage' => ['global' => [
                'porcentaje_total' => 90,
                'titular_efectivo' => 8,
                'titular_objetivo' => 10,
                'respaldo_efectivo' => 2,
                'respaldo_objetivo' => 2,
            ]],
            'man_power' => ['resumen' => [
                'total_distribuido' => 11,
                'requeridos_por_plan' => 12,
                'brecha' => 1,
                'exceso' => 0,
            ]],
            'execution' => ['summary' => [
                'porcentaje_cumplimiento_real' => 75,
                'presentes' => 9,
                'planificado' => 12,
                'filas_cerradas' => 2,
                'filas' => 3,
                'filas_abiertas' => 1,
            ]],
            'transport' => ['resumen' => [
                'personas_con_transporte' => 10,
                'personas_distribuidas' => 11,
                'personas_sin_transporte' => 1,
            ]],
        ]);

        $this->assertCount(6, $cards);
        $this->assertSame('RQ Mina', $cards[0]['label']);
        $this->assertSame(12, $cards[0]['value']);
        $this->assertSame('90%', $cards[1]['value']);
        $this->assertSame('warning', $cards[2]['tone']);
        $this->assertSame('danger', $cards[3]['tone']);
    }
}
