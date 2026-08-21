<?php

namespace Tests\Unit;

use App\Modules\Evaluaciones\Support\ResidentEvaluationTemplate;
use PHPUnit\Framework\TestCase;

class ResidentEvaluationTemplateTest extends TestCase
{
    public function test_calcula_el_maximo_de_veinte_puntos(): void
    {
        $result = ResidentEvaluationTemplate::calculate([
            'indicadores_kpi_items' => array_keys(ResidentEvaluationTemplate::KPI_OPTIONS),
            'costos_servicio_items' => ['COSTOS_MENSUALES', 'CURVA_S'],
            'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4,
        ]);

        $this->assertSame(['NINGUNO'], $result['indicadores_kpi_items']);
        $this->assertSame(16, $result['total']);

        $result = ResidentEvaluationTemplate::calculate([
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA', 'REPORTE_EVALUACION_DESEMPENO', 'ENTREGA_INFORMES', 'ENTREGA_PROTOCOLOS'],
            'costos_servicio_items' => ['COSTOS_MENSUALES', 'CURVA_S'],
            'eventos_seguridad_respuesta' => 'SI',
            'reportes_calidad_respuesta' => 'SI',
            'liderazgo_gestion_innovacion' => 4,
        ]);

        $this->assertSame(20, $result['total']);
    }

    public function test_ninguno_anula_el_componente_correspondiente(): void
    {
        $result = ResidentEvaluationTemplate::calculate([
            'indicadores_kpi_items' => ['REPORTE_ASISTENCIA', 'NINGUNO'],
            'costos_servicio_items' => ['COSTOS_MENSUALES', 'NINGUNO'],
            'eventos_seguridad_respuesta' => 'NO',
            'reportes_calidad_respuesta' => 'NO',
            'liderazgo_gestion_innovacion' => 3,
        ]);

        $this->assertSame(['NINGUNO'], $result['indicadores_kpi_items']);
        $this->assertSame(['NINGUNO'], $result['costos_servicio_items']);
        $this->assertSame(3, $result['total']);
    }
}
