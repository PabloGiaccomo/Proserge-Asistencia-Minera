<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMinaExamenIntento;
use App\Modules\Personal\Support\EmoOutcome;
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
            if (($row['operational_category'] ?? null) !== ExamenMinero::CATEGORIA_EMO
                && !$row['issues'] && $row['personal_id'] && $row['examen_id'] && $row['type'] === 'Programacion') {
                $schedules[$this->key($row['personal_id'], $row['examen_id'], $row['mina_id'])][$row['date']][] = $i;
            }
        }
        unset($row);
        foreach ($rows as $i => &$row) {
            if ($row['issues'] || !$row['personal_id'] || !$row['examen_id']) {
                continue;
            }
            if (($row['operational_category'] ?? null) === ExamenMinero::CATEGORIA_EMO) {
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
            $medical = EmoOutcome::fromLegacyText($row['result'] ?? '');
            $result = $medical
                ? $medical['outcome']->genericResult()
                : (strtoupper((string) ($row['result'] ?? '')) === 'APROBADO' ? 'APROBADO' : 'DESAPROBADO');
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
            if (($row['operational_category'] ?? null) !== ExamenMinero::CATEGORIA_EMO
                && $row['type'] === 'Resultado' && !$row['issues'] && $row['matched_schedule']) {
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

        return $this->reconcileEmo($rows);
    }

    private function reconcileEmo(array $rows): array
    {
        $workerIds = collect($rows)->where('operational_category', ExamenMinero::CATEGORIA_EMO)
            ->pluck('personal_id')->filter()->unique()->values();
        $dates = collect($rows)->where('operational_category', ExamenMinero::CATEGORIA_EMO)
            ->map(fn (array $row) => $row['scheduled_date'] ?? ($row['type'] === 'Programacion' ? ($row['date'] ?? null) : null))
            ->filter()->unique()->values();
        $appointments = collect();
        $legacySchedules = collect();
        if ($workerIds->isNotEmpty() && $dates->isNotEmpty()) {
            $appointments = PersonalEmoCita::query()
                ->with(['intentos.examenTrabajador.asignacion'])
                ->whereIn('personal_id', $workerIds)
                ->whereIn('fecha_programacion', $dates)
                ->get()
                ->keyBy(fn (PersonalEmoCita $appointment): string => $appointment->personal_id.'|'.$appointment->fecha_programacion->toDateString());
            // Match the central booking guard across all of the worker's mines
            // and exact exam IDs, including historical inactive assignments.
            $legacySchedules = PersonalMinaExamenIntento::query()
                ->with('examenTrabajador.asignacion')
                ->whereNull('emo_cita_id')
                ->where('resultado', 'PENDIENTE')
                ->whereIn('fecha_programacion', $dates)
                ->whereHas('examenTrabajador', fn ($query) => $query
                    ->whereHas('examen', fn ($catalog) => $catalog->where('categoria_operativa', ExamenMinero::CATEGORIA_EMO))
                    ->whereHas('asignacion', fn ($assignment) => $assignment->whereIn('personal_id', $workerIds)))
                ->get()
                ->groupBy(fn ($attempt): string => $attempt->examenTrabajador->asignacion->personal_id.'|'.$attempt->fecha_programacion->toDateString());
        }

        $fileGroups = [];
        foreach ($rows as $index => $row) {
            if (($row['operational_category'] ?? null) !== ExamenMinero::CATEGORIA_EMO
                || blank($row['personal_id'] ?? null) || blank($row['scheduled_date'] ?? null)) {
                continue;
            }
            $fileGroups[$row['personal_id'].'|'.$row['scheduled_date']][] = $index;
        }
        foreach ($fileGroups as $indices) {
            $clinicIds = collect($indices)->map(fn (int $index) => $rows[$index]['clinic_id'] ?? null)->filter()->unique();
            if ($clinicIds->count() > 1) {
                foreach ($indices as $index) {
                    $this->conflict($rows[$index], 'El archivo intenta programar al trabajador el mismo dia en dos clinicas diferentes.');
                }
            }
            $examIds = collect($indices)->map(fn (int $index) => $rows[$index]['examen_id'] ?? null)->filter()->unique();
            if ($examIds->count() > 1) {
                foreach ($indices as $index) {
                    $this->conflict($rows[$index], 'La cita del mismo trabajador y dia solo admite exactamente el mismo examen EMO.');
                }
            }
        }

        foreach ($rows as &$row) {
            if (($row['operational_category'] ?? null) !== ExamenMinero::CATEGORIA_EMO
                || $row['issues'] || blank($row['personal_id'] ?? null) || blank($row['examen_id'] ?? null)) {
                continue;
            }
            $scheduled = $row['scheduled_date'] ?? ($row['type'] === 'Programacion' ? ($row['date'] ?? null) : null);
            if (!$scheduled || blank($row['clinic_id'] ?? null)) {
                continue;
            }
            $key = $row['personal_id'].'|'.$scheduled;
            /** @var PersonalEmoCita|null $appointment */
            $appointment = $appointments->get($key);
            if ($appointment) {
                $row['appointment_id'] = $appointment->id;
                $row['appointment_revision'] = $appointment->revision;
            }
            $fileScheduleIndexes = collect($fileGroups[$key] ?? [])->filter(fn (int $index) => $rows[$index]['type'] === 'Programacion'
                && ($rows[$index]['examen_id'] ?? null) === $row['examen_id']
                && ($rows[$index]['mina_id'] ?? null) === $row['mina_id']
                && ($rows[$index]['clinic_id'] ?? null) === $row['clinic_id']);

            if ($row['type'] === 'Programacion' && $legacySchedules->has($key)) {
                $this->conflict($row, 'Existe un EMO historico sin clinica para ese dia. Regularice primero la programacion.');
                continue;
            }

            if ($appointment && $appointment->clinica_id !== $row['clinic_id']) {
                $this->conflict($row, 'Ya existe una cita EMO para el trabajador en otra clinica ese dia.');
                continue;
            }
            if ($appointment) {
                $linkedExamIds = $appointment->intentos->map(fn ($attempt) => $attempt->examenTrabajador?->examen_id)->filter()->unique();
                if ($linkedExamIds->isNotEmpty() && ($linkedExamIds->count() !== 1 || $linkedExamIds->first() !== $row['examen_id'])) {
                    $this->conflict($row, 'La cita existente corresponde a otro examen EMO y no puede compartirse.');
                    continue;
                }
            }

            if ($row['type'] === 'Programacion') {
                $linked = $appointment?->intentos->first(fn ($attempt) => $attempt->examenTrabajador?->asignacion?->mina_id === $row['mina_id']
                    && $attempt->examenTrabajador?->examen_id === $row['examen_id']);
                if ($linked) {
                    $row['existing_attempt_ids'] = [$linked->id];
                    $row['appointment_action'] = 'Sin cambios';
                    $row['action'] = 'Cita EMO ya registrada: no duplicar';
                } elseif ($appointment && $appointment->estado !== PersonalEmoCita::ESTADO_PROGRAMADA) {
                    $this->conflict($row, 'La cita EMO existente ya fue cerrada y no admite nuevas minas.');
                } else {
                    $row['appointment_action'] = $appointment ? 'Vincular mina a cita EMO' : 'Crear cita EMO';
                    $row['action'] = $appointment ? 'Vincular mina a cita EMO' : 'Crear cita EMO';
                }
                continue;
            }

            if (!$appointment && $fileScheduleIndexes->isEmpty()) {
                $this->conflict($row, 'No existe una cita EMO programada con esa fecha y clinica.');
                continue;
            }
            if ($appointment) {
                $linkedToMine = $appointment->intentos->contains(fn ($attempt) => $attempt->examenTrabajador?->asignacion?->mina_id === $row['mina_id']
                    && $attempt->examenTrabajador?->examen_id === $row['examen_id']);
                if (!$linkedToMine && $fileScheduleIndexes->isEmpty()) {
                    $this->conflict($row, 'La mina indicada no esta vinculada a esta cita EMO.');
                    continue;
                }
            }
            if ($appointment && $appointment->estado === PersonalEmoCita::ESTADO_COMPLETADA) {
                $expectedOutcome = str_replace(' ', '_', $row['result']);
                $equivalent = $appointment->resultado_emo === $expectedOutcome
                    && $appointment->fecha_realizacion?->toDateString() === ($row['performed_date'] ?? $row['date'])
                    && trim((string) $appointment->detalle_resultado_emo) === trim((string) ($row['observation'] ?? ''))
                    && $appointment->intentos->every(function ($attempt) use ($row): bool {
                        // complete() persists scores according to each linked
                        // worker-exam snapshot, rather than the current catalog.
                        $score = $attempt->examenTrabajador?->requiere_nota_snapshot ? ($row['score'] ?? null) : null;
                        $sameScore = ($attempt->nota === null && blank($score))
                            || ($attempt->nota !== null && is_numeric($score) && (float) $attempt->nota === (float) $score);

                        return $sameScore && (blank($row['expiry'] ?? null)
                            || $attempt->examenTrabajador?->fecha_vencimiento?->toDateString() === $row['expiry']);
                    });
                if ($equivalent) {
                    $row['appointment_action'] = 'Sin cambios';
                    $row['action'] = 'Resultado EMO existente: omitir duplicado';
                } else {
                    $this->conflict($row, 'La cita EMO ya tiene un resultado diferente. No se sobrescribira.');
                }
                continue;
            }
            if ($appointment && $appointment->estado !== PersonalEmoCita::ESTADO_PROGRAMADA) {
                $this->conflict($row, 'La cita EMO no esta disponible para registrar resultado.');
                continue;
            }
            $row['appointment_action'] = 'Completar cita EMO';
            $row['matched_schedule'] = $scheduled;
            $row['action'] = 'Completar cita EMO';
        }
        unset($row);

        $resultGroups = collect($rows)->keys()->filter(fn (int $index) => ($rows[$index]['operational_category'] ?? null) === ExamenMinero::CATEGORIA_EMO
            && $rows[$index]['type'] === 'Resultado'
            && $rows[$index]['issues'] === [])
            ->groupBy(fn (int $index): string => implode('|', [
                $rows[$index]['personal_id'], $rows[$index]['scheduled_date'],
                $rows[$index]['clinic_id'], $rows[$index]['examen_id'],
            ]));
        foreach ($resultGroups as $indices) {
            if ($indices->count() < 2) {
                continue;
            }
            $payloads = $indices->map(fn (int $index): array => [
                $rows[$index]['performed_date'], str_replace(' ', '_', $rows[$index]['result']),
                $rows[$index]['score'], $rows[$index]['expiry'], trim((string) $rows[$index]['observation']),
            ])->uniqueStrict();
            if ($payloads->count() !== 1) {
                foreach ($indices as $index) {
                    $this->conflict($rows[$index], 'Varias filas intentan completar la misma cita EMO con resultados diferentes.');
                }
                continue;
            }
            foreach ($indices->skip(1) as $index) {
                $rows[$index]['appointment_action'] = 'Sin cambios';
                $rows[$index]['action'] = 'Resultado de cita EMO compartida: omitir duplicado';
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
