<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\EvaluacionSupervisor;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Illuminate\Support\Facades\DB;

class EvaluacionesHistoricalMapper
{
    public function map(array $manifest): array
    {
        $mapped = [];
        $blocked = $manifest['blocked'] ?? [];
        $warnings = $manifest['warnings'] ?? [];
        $residentPeriods = [];

        foreach ($manifest['proposed'] ?? [] as $entry) {
            $type = $entry['type'] ?? null;
            $values = $entry['values'] ?? [];
            $reason = null;
            $payload = [];

            if ($type === 'daily') {
                $payload = [
                    'origen' => 'HISTORICA',
                    'trabajador_id' => $entry['trabajador_id'],
                    'fecha' => $entry['date'],
                    'hora' => null,
                    'desempeno_trabajo' => $entry['scores'][0],
                    'orden_limpieza' => $entry['scores'][1],
                    'compromiso' => $entry['scores'][2],
                    'respuesta_emocional' => $entry['scores'][3],
                    'seguridad_trabajo' => $entry['scores'][4],
                    'total' => array_sum($entry['scores']),
                    'observaciones' => $entry['observaciones'] ?? null,
                    'supervisor_id' => $entry['evaluador_id'] ?? null,
                    'tuvo_incidencia' => (bool) ($entry['tuvo_incidencia'] ?? false),
                    'descripcion_incidencia' => $entry['descripcion_incidencia'] ?? null,
                    'source_mina' => $entry['mine'] ?? null,
                    'source_evaluador' => $entry['evaluator_name'] ?? $entry['evaluator_ref'] ?? null,
                ];
            } elseif ($type === 'attendance') {
                $payload = [
                    'trabajador_id' => $entry['trabajador_id'],
                    'fecha' => $entry['date'],
                    'presente' => true,
                    'source_mina' => $entry['mine'] ?? null,
                ];
            } elseif ($type === 'supervisor') {
                $responses = [];
                $missing = [];
                foreach (array_keys(SupervisorEvaluationTemplate::WEIGHTS) as $index => $key) {
                    $raw = $values[$index + 5] ?? null;
                    if ($raw !== null && trim((string) $raw) !== '' && ! preg_match('/^[1-5]$/', trim((string) $raw))) {
                        $reason = 'Respuesta de supervisor fuera de rango.';
                        break;
                    }
                    $responses[$key] = $raw === null || trim((string) $raw) === '' ? 0 : (int) $raw;
                    if ($raw === null || trim((string) $raw) === '') {
                        $missing[] = $key;
                    }
                }
                $score = $this->score($values[31] ?? null, 100);
                if ($score === null) {
                    $reason = 'Total historico de supervisor invalido.';
                }
                $payload = [
                    'evaluado_id' => $entry['trabajador_id'],
                    'evaluador_id' => $entry['evaluador_id'] ?? null,
                    'fecha' => $entry['date'],
                    'respuestas' => $responses,
                    'resultado_final' => $score,
                    'calculo_version' => 'HISTORICA',
                    'estado' => $missing ? EvaluacionSupervisor::PENDING_REGULARIZATION : 'REGISTRADA',
                    'source_payload' => $values,
                    'source_mina' => $values[4] ?? null,
                    'source_evaluador' => $entry['evaluator_ref'] ?? null,
                    'comentarios_finales' => $values[32] ?? null,
                    'aspectos_positivos' => $values[33] ?? null,
                    'capacitaciones_recomendadas' => $values[34] ?? null,
                ];
                if ($missing) {
                    $warnings[] = [...$entry, 'reason' => 'Pendiente de regularizacion: criterios sin dato '.implode(', ', $missing).'. Se conserva la nota original, fuera de indicadores hasta completar la revision.'];
                }
            } elseif ($type === 'resident') {
                $score = $this->score($values[10] ?? null, 20);
                $leadership = $values[6] ?? null;
                if ($score === null || ! preg_match('/^[1-4]$/', trim((string) $leadership))) {
                    $reason = 'Total o liderazgo historico de residente invalido.';
                }
                $period = substr((string) $entry['date'], 0, 7).'-01';
                $periodKey = $entry['trabajador_id'].'|'.$period;
                $residentPeriods[$periodKey][] = $entry['source_key'];
                $payload = [
                    'residente_id' => $entry['trabajador_id'],
                    'evaluador_id' => $entry['evaluador_id'] ?? null,
                    'fecha' => $entry['date'],
                    'periodo_mes' => $period,
                    'indicadores_kpi_items' => null,
                    'costos_servicio_items' => null,
                    'eventos_seguridad_respuesta' => $values[4] ?? null,
                    'reportes_calidad_respuesta' => $values[5] ?? null,
                    'liderazgo_gestion_innovacion' => $leadership,
                    'indicadores_kpi' => null,
                    'costos_servicio' => null,
                    'eventos_seguridad' => null,
                    'reportes_calidad' => null,
                    'liderazgo_gestion' => null,
                    'innovacion' => null,
                    'total' => $score,
                    'calculo_version' => 'HISTORICA',
                    'source_payload' => $values,
                    'source_evaluador' => $entry['evaluator_ref'] ?? null,
                    'comentarios' => $values[9] ?? null,
                ];
            } else {
                $reason = 'Tipo historico no reconocido.';
            }

            $payload += [
                'source_key' => $entry['source_key'] ?? null,
                'source_file' => $entry['source_file'] ?? null,
                'source_sheet' => $entry['source_sheet'] ?? null,
                'source_row' => $entry['source_row'] ?? null,
                'source_id' => $entry['source_id'] ?? null,
            ];

            if ($reason !== null) {
                $blocked[] = [...$entry, 'reason' => $reason];
            } else {
                $mapped[] = [...$entry, 'payload' => $payload];
            }
        }

        $existingPeriods = [];
        if ($residentPeriods) {
            foreach (DB::table('evaluacion_residente')->whereIn('residente_id', array_unique(array_map(
                fn ($key) => explode('|', $key)[0], array_keys($residentPeriods)
            )))->get(['residente_id', 'periodo_mes']) as $record) {
                $existingPeriods[$record->residente_id.'|'.substr((string) $record->periodo_mes, 0, 10)] = true;
            }
        }

        $proposed = [];
        foreach ($mapped as $entry) {
            if ($entry['type'] === 'resident') {
                $key = $entry['trabajador_id'].'|'.$entry['payload']['periodo_mes'];
                if (count($residentPeriods[$key]) > 1 || isset($existingPeriods[$key])) {
                    $blocked[] = [...$entry, 'reason' => 'El residente ya tiene una evaluacion en ese periodo.'];

                    continue;
                }
            }
            $proposed[] = $entry;
        }

        return [...$manifest, 'proposed' => $proposed, 'blocked' => $blocked, 'warnings' => $warnings];
    }

    private function score(mixed $value, int $maximum): ?float
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > $maximum) {
            return null;
        }

        return round((float) $value, 2);
    }
}
