<?php

namespace App\Modules\Evaluaciones\Support;

class ResidentEvaluationTemplate
{
    public const NONE = 'NINGUNO';

    public const KPI_OPTIONS = [
        'REPORTE_ASISTENCIA' => 'Reporte de Asistencia',
        'REPORTE_EVALUACION_DESEMPENO' => 'Reporte de Ev. Desempeño',
        'ENTREGA_INFORMES' => 'Entrega de Informes',
        'ENTREGA_PROTOCOLOS' => 'Entrega de Protocolos',
        self::NONE => 'Ninguno',
    ];

    public const COST_OPTIONS = [
        'COSTOS_MENSUALES' => 'Presenta Costos Mensuales',
        'CURVA_S' => 'Curva S.',
        self::NONE => 'Ninguno',
    ];

    public const BINARY_OPTIONS = [
        'SI' => 'Sí',
        'NO' => 'No',
    ];

    public static function calculate(array $payload): array
    {
        $kpis = self::normalizeSelections($payload['indicadores_kpi_items'] ?? [], self::KPI_OPTIONS);
        $costs = self::normalizeSelections($payload['costos_servicio_items'] ?? [], self::COST_OPTIONS);
        $securityResponse = self::normalizeBinary($payload['eventos_seguridad_respuesta'] ?? null);
        $qualityResponse = self::normalizeBinary($payload['reportes_calidad_respuesta'] ?? null);
        $leadership = max(1, min(4, (int) ($payload['liderazgo_gestion_innovacion'] ?? 1)));

        $kpiScore = in_array(self::NONE, $kpis, true) ? 0 : count($kpis);
        $costScore = in_array(self::NONE, $costs, true) ? 0 : 2 * count($costs);
        $securityScore = $securityResponse === 'SI' ? 4 : 0;
        $qualityScore = $qualityResponse === 'SI' ? 4 : 0;

        return [
            'indicadores_kpi_items' => $kpis,
            'costos_servicio_items' => $costs,
            'eventos_seguridad_respuesta' => $securityResponse,
            'reportes_calidad_respuesta' => $qualityResponse,
            'liderazgo_gestion_innovacion' => $leadership,
            'indicadores_kpi' => $kpiScore,
            'costos_servicio' => $costScore,
            'eventos_seguridad' => $securityScore,
            'reportes_calidad' => $qualityScore,
            'liderazgo_gestion' => $leadership,
            'innovacion' => 0,
            'total' => $kpiScore + $costScore + $securityScore + $qualityScore + $leadership,
        ];
    }

    private static function normalizeSelections(mixed $values, array $allowed): array
    {
        $normalized = collect(is_array($values) ? $values : [])
            ->map(fn ($value): string => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => array_key_exists($value, $allowed))
            ->unique()
            ->values()
            ->all();

        return in_array(self::NONE, $normalized, true) ? [self::NONE] : $normalized;
    }

    private static function normalizeBinary(mixed $value): string
    {
        return strtoupper(trim((string) $value)) === 'NO' ? 'NO' : 'SI';
    }
}
