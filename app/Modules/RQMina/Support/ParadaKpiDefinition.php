<?php

namespace App\Modules\RQMina\Support;

class ParadaKpiDefinition
{
    public static function cards(array $dashboard): array
    {
        $rq = $dashboard['rq'] ?? [];
        $coverage = $dashboard['coverage']['global'] ?? [];
        $manPower = $dashboard['man_power']['resumen'] ?? [];
        $execution = $dashboard['execution']['summary'] ?? [];
        $transport = $dashboard['transport']['resumen'] ?? [];

        return [
            [
                'key' => 'rq_total',
                'label' => 'RQ Mina',
                'value' => (int) ($rq['total_objetivo'] ?? 0),
                'hint' => 'Total pedido con back up operativo.',
                'tone' => 'neutral',
            ],
            [
                'key' => 'rq_proserge',
                'label' => 'RQ Proserge',
                'value' => self::percent((float) ($coverage['porcentaje_total'] ?? 0)),
                'hint' => (int) ($coverage['titular_efectivo'] ?? 0).' titulares / '.(int) ($coverage['respaldo_efectivo'] ?? 0).' respaldo',
                'tone' => self::toneForPercent((float) ($coverage['porcentaje_total'] ?? 0)),
            ],
            [
                'key' => 'man_power',
                'label' => 'Man Power',
                'value' => (int) ($manPower['total_distribuido'] ?? 0).' / '.(int) ($manPower['requeridos_por_plan'] ?? 0),
                'hint' => 'Brecha '.(int) ($manPower['brecha'] ?? 0).', exceso '.(int) ($manPower['exceso'] ?? 0).'.',
                'tone' => ((int) ($manPower['brecha'] ?? 0)) > 0 ? 'warning' : 'success',
            ],
            [
                'key' => 'asistencia',
                'label' => 'Ejecucion',
                'value' => self::percent((float) ($execution['porcentaje_cumplimiento_real'] ?? 0)),
                'hint' => (int) ($execution['presentes'] ?? 0).' reales de '.(int) ($execution['planificado'] ?? 0).' planificados.',
                'tone' => self::toneForPercent((float) ($execution['porcentaje_cumplimiento_real'] ?? 0)),
            ],
            [
                'key' => 'transporte',
                'label' => 'Transporte',
                'value' => (int) ($transport['personas_con_transporte'] ?? 0).' / '.(int) ($transport['personas_distribuidas'] ?? 0),
                'hint' => (int) ($transport['personas_sin_transporte'] ?? 0).' personas sin transporte.',
                'tone' => ((int) ($transport['personas_sin_transporte'] ?? 0)) > 0 ? 'warning' : 'success',
            ],
            [
                'key' => 'datos',
                'label' => 'Datos cerrados',
                'value' => (int) ($execution['filas_cerradas'] ?? 0).' / '.(int) ($execution['filas'] ?? 0),
                'hint' => 'La ejecucion real solo es definitiva con asistencia cerrada.',
                'tone' => ((int) ($execution['filas_abiertas'] ?? 0)) > 0 ? 'warning' : 'neutral',
            ],
        ];
    }

    public static function formulas(): array
    {
        return [
            'RQ Mina' => 'Suma de rq_mina_detalle.cantidad_total; el back up 20% se mantiene como indicador independiente.',
            'RQ Proserge' => 'Usa RQProsergeCoverageService: titulares y suplentes activos contra objetivos del pedido.',
            'Man Power' => 'Usa ManPowerPlanningService: distribuidos activos contra plan operativo por fecha y turno.',
            'Ejecucion' => 'Usa parada_ejecucion_resumen calculado desde asistencia cerrada o pendiente.',
            'Transporte' => 'Usa TransportePlanningService: pasajeros asignados contra personas distribuidas.',
        ];
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';
    }

    private static function toneForPercent(float $value): string
    {
        if ($value >= 95) {
            return 'success';
        }

        if ($value >= 80) {
            return 'warning';
        }

        return 'danger';
    }
}
