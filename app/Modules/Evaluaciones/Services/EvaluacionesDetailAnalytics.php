<?php

namespace App\Modules\Evaluaciones\Services;

use App\Modules\Evaluaciones\Support\ResidentEvaluationTemplate as Resident;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate as Supervisor;
use Illuminate\Support\Collection;

class EvaluacionesDetailAnalytics
{
    public function build(string $type, Collection $rows): array
    {
        $max = $type === 'supervisores' ? 100 : 20;
        $scores = $rows->pluck('score')->filter(fn ($v) => $this->valid($v, 0, $max))->values();
        $records = $rows->pluck('detail');
        $charts = [];
        $extra = [];
        if ($type === 'desempeno') {
            $criteria = ['desempeno_trabajo' => 'Desempeno en el trabajo', 'orden_limpieza' => 'Orden y limpieza',
                'seguridad_trabajo' => 'Seguridad en el trabajo', 'compromiso' => 'Compromiso', 'respuesta_emocional' => 'Respuesta emocional'];
            $charts[] = ['title' => 'Perfil de criterios · escala 1 a 4', 'items' => $this->criteria($records, $criteria, 1, 4)];
            $known = $records->filter(fn ($r) => isset($r['tuvo_incidencia']) && in_array($r['tuvo_incidencia'], [true, false, 0, 1, '0', '1'], true));
            $incidents = $known->filter(fn ($r) => (bool) $r['tuvo_incidencia'])->count();
            $extra[] = ['label' => 'Evaluaciones con incidencia', 'value' => $incidents.' / '.$known->count(),
                'note' => $known->count() ? round($incidents / $known->count() * 100, 1).'% de registros con dato; no es una tasa de accidentes.' : 'Sin dato de incidencia.'];
        } elseif ($type === 'supervisores') {
            $withResponses = $records->filter(fn ($r) => isset($r['respuestas']) && is_array($r['respuestas']) && $r['respuestas'] !== []);
            $blocks = [];
            $competencies = [];
            foreach (Supervisor::ITEMS as $block => $questions) {
                $weights = array_intersect_key(Supervisor::WEIGHTS, $questions);
                $blockMax = array_sum($weights) * 100;
                $contributions = $withResponses->map(function ($r) use ($weights) {
                    $normalized = Supervisor::normalizeResponses($r['respuestas']);
                    return array_sum(array_map(fn ($key) => $normalized[$key] / 5 * $weights[$key] * 100, array_keys($weights)));
                });
                $blocks[] = $this->metric(Supervisor::SECTION_TITLES[$block], $contributions, $blockMax, $records->count());
                foreach ($questions as $key => $label) {
                    $values = $withResponses->map(fn ($r) => $r['respuestas'][$key] ?? null)->filter(fn ($v) => $this->valid($v, 1, 5));
                    $competencies[$block][] = $this->metric($key.' · '.$label, $values, 5, $records->count());
                }
            }
            $charts[] = ['title' => 'Aporte ponderado por bloque · puntos de 100', 'items' => $blocks];
            foreach ($competencies as $block => $items) {
                $charts[] = ['title' => 'Bloque '.$block.': '.Supervisor::SECTION_TITLES[$block].' · escala 1 a 5', 'items' => $items, 'collapsed' => true];
            }
            $extra[] = ['label' => 'Evaluaciones con respuestas', 'value' => $withResponses->count().' / '.$records->count(),
                'note' => 'En el aporte ponderado, respuestas vacias valen 0 segun la ficha. El promedio por competencia solo usa respuestas de 1 a 5.'];
        } elseif ($type === 'residentes') {
            $charts[] = ['title' => 'Composicion de la nota mensual · puntos de 20', 'items' => [
                ...$this->criteria($records, ['indicadores_kpi' => 'Entregables KPI', 'costos_servicio' => 'Costos de servicio',
                    'eventos_seguridad' => 'Eventos de seguridad', 'reportes_calidad' => 'Reportes de calidad'], 0, 4),
                ...$this->criteria($records, ['liderazgo_gestion_innovacion' => 'Liderazgo, gestion e innovacion'], 1, 4),
            ]];
            $deliverables = [];
            foreach (['indicadores_kpi_items' => Resident::KPI_OPTIONS, 'costos_servicio_items' => Resident::COST_OPTIONS] as $field => $options) {
                $known = $records->filter(fn ($r) => isset($r[$field]) && is_array($r[$field]) && $r[$field] !== []
                    && !array_diff($r[$field], array_keys($options))
                    && (!in_array(Resident::NONE, $r[$field], true) || count($r[$field]) === 1));
                foreach ($options as $key => $label) {
                    if ($key === Resident::NONE) continue;
                    $count = $known->filter(fn ($r) => in_array($key, $r[$field], true))->count();
                    $deliverables[] = ['label' => $label, 'value' => $count, 'max' => $known->count(), 'n' => $known->count(),
                        'missing' => $records->count() - $known->count(), 'percent' => $known->count() ? round($count / $known->count() * 100, 1) : 0];
                }
            }
            $charts[] = ['title' => 'Entregables marcados · evaluaciones con detalle', 'items' => $deliverables];
            foreach (['eventos_seguridad_respuesta' => 'Eventos de seguridad: respuesta Si', 'reportes_calidad_respuesta' => 'Reportes de calidad: respuesta Si'] as $field => $label) {
                $known = $records->pluck($field)->filter(fn ($v) => in_array($v, ['SI', 'NO'], true));
                $extra[] = ['label' => $label, 'value' => $known->filter(fn ($v) => $v === 'SI')->count().' / '.$known->count(),
                    'note' => 'Respuesta del formulario; Si aporta 4 puntos. No equivale a cantidad de incidentes.'];
            }
        }
        $bands = [];
        for ($i = 0; $i < 4; $i++) {
            $low = $i * $max / 4;
            $high = ($i + 1) * $max / 4;
            $bands[] = ['label' => $low.' a '.($i === 3 ? '' : '<').$high,
                'count' => $scores->filter(fn ($v) => $v >= $low && ($i === 3 ? $v <= $high : $v < $high))->count()];
        }
        $trend = $rows->groupBy('month')->sortKeys()->map(function ($items, $month) use ($max) {
            $valid = $items->pluck('score')->filter(fn ($v) => $this->valid($v, 0, $max));
            return ['month' => $month, 'average' => $valid->isEmpty() ? null : round($valid->avg(), 1), 'n' => $valid->count()];
        })->values()->all();

        return ['type' => $type, 'max' => $max, 'total' => $rows->count(), 'n' => $scores->count(),
            'median' => $scores->isEmpty() ? null : round($scores->median(), 1),
            'min' => $scores->min(), 'maxObserved' => $scores->max(), 'charts' => $charts, 'extra' => $extra,
            'bands' => $bands, 'trend' => $trend];
    }

    private function criteria(Collection $records, array $fields, float $min, float $max): array
    {
        $items = [];
        foreach ($fields as $field => $label) {
            $items[] = $this->metric($label, $records->pluck($field)->filter(fn ($v) => $this->valid($v, $min, $max)), $max, $records->count());
        }
        return $items;
    }

    private function metric(string $label, Collection $values, float $max, int $total): array
    {
        $mean = $values->isEmpty() ? null : $values->avg();
        return ['label' => $label, 'value' => $mean === null ? null : round($mean, 2), 'max' => round($max, 2),
            'percent' => $mean === null || $max === 0.0 ? 0 : round($mean / $max * 100, 1),
            'n' => $values->count(), 'missing' => $total - $values->count()];
    }

    private function valid(mixed $value, float $min, float $max): bool
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= $min && $value <= $max;
    }
}
