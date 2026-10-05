<?php

namespace Tests\Unit;

use App\Modules\Evaluaciones\Services\EvaluacionesDetailAnalytics;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Tests\TestCase;

class EvaluacionesDetailAnalyticsTest extends TestCase
{
    private function row(mixed $score, array $detail): array
    {
        return ['score' => $score, 'month' => '2026-09', 'detail' => $detail];
    }

    public function test_daily_excludes_missing_values_and_counts_incidence_records(): void
    {
        $result = (new EvaluacionesDetailAnalytics)->build('desempeno', collect([
            $this->row(20, ['desempeno_trabajo'=>4, 'tuvo_incidencia'=>true]),
            $this->row(10, ['desempeno_trabajo'=>2, 'tuvo_incidencia'=>false]),
            $this->row(null, ['desempeno_trabajo'=>null]), $this->row(99, []),
        ]));
        $this->assertSame(2, $result['n']);
        $this->assertEquals(15, $result['median']);
        $metric = $result['charts'][0]['items'][0];
        $this->assertEquals(3, $metric['value']);
        $this->assertSame(2, $metric['missing']);
        $this->assertSame('1 / 2', $result['extra'][0]['value']);
        $this->assertSame([0,0,1,1], array_column($result['bands'], 'count'));
        $this->assertEquals(15, $result['trend'][0]['average']);
    }

    public function test_supervisor_block_weights_follow_existing_template(): void
    {
        $answers = array_fill_keys(array_keys(SupervisorEvaluationTemplate::WEIGHTS), 5);
        $result = (new EvaluacionesDetailAnalytics)->build('supervisores', collect([$this->row(100, ['respuestas'=>$answers])]));
        $this->assertEquals([40,30,30], array_column($result['charts'][0]['items'], 'value'));
        $this->assertEquals([40,30,30], array_column($result['charts'][0]['items'], 'max'));
        $this->assertSame([
            'Aporte ponderado por bloque · puntos de 100',
            'Bloque A: Competencias Técnicas (Asociadas al Cargo) · escala 1 a 5',
            'Bloque B: Desempeño en SSOMA · escala 1 a 5',
            'Bloque C: Habilidades blandas · escala 1 a 5',
        ], array_column($result['charts'], 'title'));
        foreach (array_slice($result['charts'], 1) as $blockChart) {
            $this->assertTrue($blockChart['collapsed'] ?? false);
        }
        $this->assertSame([9, 7, 10], array_map(fn (array $chart) => count($chart['items']), array_slice($result['charts'], 1)));
        $this->assertCount(26, array_merge(...array_column(array_slice($result['charts'], 1), 'items')));
        $partial = (new EvaluacionesDetailAnalytics)->build('supervisores', collect([
            $this->row(8, ['respuestas'=>['A4'=>5]]), $this->row(null, ['respuestas'=>null]),
        ]));
        $this->assertEquals(8, $partial['charts'][0]['items'][0]['value']);
        $this->assertSame(1, $partial['charts'][0]['items'][0]['missing']);
        $this->assertNull($partial['charts'][1]['items'][0]['value']);
    }

    public function test_resident_deliverables_do_not_treat_missing_as_none_or_yes(): void
    {
        $result = (new EvaluacionesDetailAnalytics)->build('residentes', collect([
            $this->row(10, ['indicadores_kpi'=>1,'costos_servicio'=>0,'indicadores_kpi_items'=>['REPORTE_ASISTENCIA'],
                'costos_servicio_items'=>['NINGUNO'],'eventos_seguridad_respuesta'=>'SI']),
            $this->row(8, ['indicadores_kpi'=>0,'indicadores_kpi_items'=>['NINGUNO'],'eventos_seguridad_respuesta'=>'NO']),
            $this->row(null, []),
        ]));
        $this->assertEquals(0.5, $result['charts'][0]['items'][0]['value']);
        $deliverable = $result['charts'][1]['items'][0];
        $this->assertSame(1, $deliverable['value']);
        $this->assertSame(2, $deliverable['n']);
        $this->assertSame(1, $deliverable['missing']);
        $this->assertEquals(50, $deliverable['percent']);
        $this->assertSame('1 / 2', $result['extra'][0]['value']);
    }

    public function test_empty_results_have_no_fabricated_averages(): void
    {
        foreach (['desempeno','supervisores','residentes'] as $type) {
            $result = (new EvaluacionesDetailAnalytics)->build($type, collect());
            $this->assertNull($result['median']);
            $this->assertSame(0, $result['n']);
            $this->assertSame([], $result['trend']);
        }
    }
}
