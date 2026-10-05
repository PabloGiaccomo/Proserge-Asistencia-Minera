<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalMinaExamenIntento;
use Illuminate\Support\Collection;

/** Read-only reconciliation of course events within their explicit target mine. */
class PersonalCursoImportPlanner
{
    public function preview(array $rows): array
    {
        return $this->reconcile($rows, $this->history($rows));
    }

    public function history(array $rows): Collection
    {
        $workerIds = array_values(array_filter(array_unique(array_column($rows, 'personal_id'))));
        $examIds = array_values(array_filter(array_unique(array_column($rows, 'examen_id'))));
        $history = collect();
        if ($workerIds && $examIds) {
            $history = PersonalMinaExamenIntento::query()
                ->with('examenTrabajador.asignacion')
                ->whereHas('examenTrabajador', fn ($query) => $query->whereIn('examen_id', $examIds)
                    ->whereHas('asignacion', fn ($assignment) => $assignment->whereIn('personal_id', $workerIds)))
                ->get()->map(fn ($attempt) => [
                    'id' => $attempt->id,
                    'personal_id' => $attempt->examenTrabajador->asignacion->personal_id,
                    'examen_id' => $attempt->examenTrabajador->examen_id,
                    'mina_id' => $attempt->examenTrabajador->asignacion->mina_id,
                    'scheduled' => $attempt->fecha_programacion?->toDateString(),
                    'done' => $attempt->fecha_realizacion?->toDateString(),
                    'result' => $attempt->resultado,
                    'score' => $attempt->nota,
                    'observation' => $attempt->observacion,
                ]);
            if (\Illuminate\Support\Facades\Schema::hasTable('personal_curso_registros')) {
                $history = $history->concat(\App\Models\PersonalCursoRegistro::query()
                    ->whereIn('personal_id', $workerIds)->whereIn('examen_id', $examIds)->get()->map(fn ($event) => [
                        'id' => 'curso:'.$event->id, 'personal_id' => $event->personal_id, 'examen_id' => $event->examen_id,
                        'mina_id' => $event->mina_id,
                        'scheduled' => $event->fecha_programacion?->toDateString(), 'done' => $event->fecha_realizacion?->toDateString(),
                        'result' => $event->resultado, 'score' => $event->nota,
                        'expiry' => $event->fecha_vencimiento?->toDateString(), 'observation' => $event->observacion,
                    ]));
            }
        }

        return $history;
    }

    public function reconcile(array $rows, Collection $history): array
    {
        $index = $history->groupBy(fn ($event) => $this->key($event['personal_id'], $event['examen_id'], $event['mina_id'] ?? null));
        $legacyIndex = $history
            ->filter(fn ($event) => blank($event['mina_id'] ?? null))
            ->groupBy(fn ($event) => $event['personal_id'].'|'.$event['examen_id']);
        $schedules = [];
        foreach ($rows as $i => &$row) {
            $row['action'] = 'Revisar observaciones';
            $row['linked_rows'] = [];
            $row['existing_attempt_ids'] = [];
            $row['matched_schedule'] = null;
            if (!$row['issues'] && $row['personal_id'] && $row['examen_id'] && $row['type'] === 'Programacion') {
                $schedules[$this->key($row['personal_id'], $row['examen_id'], $row['mina_id'])][$row['date']][] = $i;
            }
        }
        unset($row);
        foreach ($rows as $i => &$row) {
            if ($row['issues'] || !$row['personal_id'] || !$row['examen_id']) {
                continue;
            }
            $key = $this->key($row['personal_id'], $row['examen_id'], $row['mina_id']);
            $events = $index->get($key, collect());
            $legacyEvents = $legacyIndex->get($row['personal_id'].'|'.$row['examen_id'], collect());
            if ($row['type'] === 'Programacion') {
                $sameDate = $events->where('scheduled', $row['date']);
                if ($sameDate->isEmpty() && $legacyEvents->where('scheduled', $row['date'])->isNotEmpty()) {
                    $this->conflict($row, 'Existe una programacion historica de este curso sin mina de origen. Revise antes de registrar otra.');
                    continue;
                }
                $row['action'] = $sameDate->isNotEmpty() ? 'Programacion ya registrada: no duplicar' : 'Crear programacion del trabajador';
                $row['existing_attempt_ids'] = $sameDate->pluck('id')->values()->all();
                continue;
            }
            $sameResultDate = $events->where('done', $row['date'])->where('result', '!=', 'PENDIENTE');
            $result = in_array($row['result'], ['APTO', 'APROBADO', 'APTO CON RESTRICCION'], true) ? 'APROBADO' : 'DESAPROBADO';
            if ($sameResultDate->isNotEmpty()) {
                $observation = \App\Modules\Personal\Support\CursoResultado::payload([
                    'resultado' => $row['result'], 'observacion' => $row['observation'] ?? '',
                ])['observacion'] ?? '';
                $equivalent = $sameResultDate->every(fn ($event) => $event['result'] === $result
                    && (!array_key_exists('expiry', $event) || $event['expiry'] === ($row['expiry'] ?? null))
                    && (!array_key_exists('observation', $event) || trim((string) $event['observation']) === trim((string) $observation))
                    && (($event['score'] === null && ($row['score'] === null || $row['score'] === ''))
                        || ($event['score'] !== null && is_numeric($row['score']) && (float) $event['score'] === (float) $row['score'])));
                if (!$equivalent) {
                    $this->conflict($row, 'Ya existe un resultado distinto en esa fecha. No se sobrescribira el historial.');
                } else {
                    $row['action'] = 'Resultado existente: revisar vigencia y omitir duplicado';
                    $row['existing_attempt_ids'] = $sameResultDate->pluck('id')->values()->all();
                }
                continue;
            }
            if ($legacyEvents->where('done', $row['date'])->where('result', '!=', 'PENDIENTE')->isNotEmpty()) {
                $this->conflict($row, 'Existe un resultado historico de este curso sin mina de origen. Revise antes de registrar otro.');
                continue;
            }
            $pending = $events->where('result', 'PENDIENTE')->filter(fn ($event) => $event['scheduled'] && $event['scheduled'] <= $row['date']);
            $fileDates = array_filter(array_keys($schedules[$key] ?? []), fn ($date) => $date <= $row['date']);
            $dates = array_values(array_unique(array_merge($fileDates, $pending->pluck('scheduled')->all())));
            // A date match is decisive. Never consume a later programming for an older result.
            if (in_array($row['date'], $dates, true)) {
                $dates = [$row['date']];
            }
            if (count($dates) > 1) {
                $this->conflict($row, 'Hay varias programaciones anteriores posibles. Seleccione el intento antes de guardar.');
                continue;
            }
            if (!$dates) {
                $row['action'] = 'Registrar resultado del trabajador sin programacion vinculada';
                continue;
            }
            $date = $dates[0];
            $row['matched_schedule'] = $date;
            $row['linked_rows'] = $schedules[$key][$date] ?? [];
            $row['existing_attempt_ids'] = $pending->where('scheduled', $date)->pluck('id')->values()->all();
            $row['action'] = $row['existing_attempt_ids'] ? 'Completar programacion existente' : 'Unir programacion y resultado del archivo';
        }
        unset($row);
        $claimed = [];
        foreach ($rows as $i => $row) {
            if ($row['type'] === 'Resultado' && !$row['issues'] && $row['matched_schedule']) {
                $claimed[$this->key($row['personal_id'], $row['examen_id'], $row['mina_id']).'|'.$row['matched_schedule']][] = $i;
            }
        }
        foreach ($claimed as $indices) {
            if (count($indices) > 1) {
                foreach ($indices as $i) {
                    $this->conflict($rows[$i], 'Varias notas intentan completar la misma programacion.');
                }
                continue;
            }
            $resultIndex = $indices[0];
            foreach ($rows[$resultIndex]['linked_rows'] as $scheduleIndex) {
                $rows[$scheduleIndex]['action'] = 'Programacion vinculada a nota; no crear otro intento';
                $rows[$scheduleIndex]['linked_rows'] = [$resultIndex];
            }
        }

        return $rows;
    }

    private function conflict(array &$row, string $message): void
    {
        $row['issues'][] = $message;
        $row['categories'][] = 'historial';
        $row['action'] = 'Revisar conflicto de historial';
    }

    private function key(string $personalId, string $examId, ?string $mineId): string
    {
        return $personalId.'|'.$examId.'|'.($mineId ?: 'SIN_MINA');
    }
}
