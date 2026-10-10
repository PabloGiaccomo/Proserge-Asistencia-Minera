<?php

namespace App\Modules\Personal\Services;

use App\Models\{Personal, ExamenMinero, Mina, PersonalCursoRegistro, PersonalMinaExamenIntento, Usuario};
use App\Modules\Personal\Support\CursoResultado;
use App\Modules\Personal\Support\CompleteEmoData;
use App\Modules\Personal\Support\EmoOrigin;
use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalCursoBulkService
{
    public function validatePlan(array $rows, Collection $exams): array
    {
        foreach ($rows as &$row) {
            $exam = $exams->get($row['examen_id']);
            if (!$row['issues'] && $exam) {
                $medical = EmoOutcome::fromLegacyText($row['result'] ?? '');
                $approved = ($medical && $medical['outcome']->genericResult() === 'APROBADO') || ($row['result'] ?? '') === 'APROBADO';
                if ($row['type'] === 'Resultado' && $approved && $exam->requiere_nota
                    && (!is_numeric($row['score']) || ($exam->nota_minima !== null && (float) $row['score'] < (float) $exam->nota_minima))) {
                    $this->issue($row, 'La nota falta o es menor al minimo del catalogo para un resultado aprobatorio.');
                }
                if (mb_strlen((string) $row['observation']) > 4900 || (is_numeric($row['score']) && (float) $row['score'] > 99999999.99)) {
                    $this->issue($row, 'La nota u observacion supera el limite permitido.');
                }
                if ($row['type'] === 'Resultado' && $approved && $exam->tiene_vigencia && !$row['expiry']) {
                    $this->issue($row, 'Falta el vencimiento requerido por el catalogo.');
                }
                $row['catalog_snapshot'] = \Illuminate\Support\Arr::only($exam->attributesToArray(), ['requiere_nota','nota_minima','tiene_vigencia','vigencia_dias',
                    'permite_reintento','max_intentos','precio','moneda','precio_desde']);
            }
            $row['disposition'] = $row['issues'] ? 'issues' : (
                ($row['appointment_action'] ?? null) === 'Sin cambios'
                ||
                str_contains($row['action'], 'no duplicar') || str_contains($row['action'], 'omitir duplicado')
                || str_contains($row['action'], 'no crear otro intento') ? 'unchanged' : 'changes');
        }
        unset($row);
        // Count the projected cycle, grouping mirrored mine attempts by date.
        $history = app(PersonalCursoImportPlanner::class)->history($rows)->groupBy(
            fn ($event) => $this->pairKey($event['personal_id'], $event['examen_id'], $event['mina_id'] ?? null)
        );
        $rowIndexesByPair = [];
        foreach ($rows as $index => $row) {
            $key = $this->pairKey(
                (string) ($row['personal_id'] ?? ''),
                (string) ($row['examen_id'] ?? ''),
                $row['mina_id'] ?? null,
            );
            $rowIndexesByPair[$key][] = $index;
        }
        foreach ($rowIndexesByPair as $key => $indexes) {
            $group = collect($indexes)->map(fn ($index) => $rows[$index]);
            $exam = $exams->get($group->first()['examen_id']);
            if (!$exam) continue;
            if (($group->first()['operational_category'] ?? null) === ExamenMinero::CATEGORIA_EMO) {
                foreach ($indexes as $index) {
                    $rows[$index]['attempts_used'] = 0;
                    $rows[$index]['attempts_limit'] = max(1, (int) $exam->max_intentos);
                    $rows[$index]['attempts_exceeded'] = false;
                }
                continue;
            }
            $events = $history->get($key, collect());
            $newRows = $group->where('disposition', 'changes')->filter(fn ($r) => !$r['existing_attempt_ids']);
            $dates = $events->map(fn ($e) => $e['scheduled'] ?: $e['done'])->filter()->unique();
            $dates = $dates->merge($newRows->map(fn ($r) => $r['matched_schedule'] ?: $r['date']))->unique();
            $limit = $exam->permite_reintento ? max(1, (int) $exam->max_intentos) : 1;
            foreach ($indexes as $index) {
                $rows[$index]['attempts_used'] = $dates->count();
                $rows[$index]['attempts_limit'] = $limit;
                $rows[$index]['attempts_exceeded'] = $dates->count() > $limit;
            }
        }
        return $this->markPendingMineSync($rows);
    }

    public function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function apply(array $preview, Usuario $user): array
    {
        if (!Schema::hasTable('personal_curso_registros')) {
            throw ValidationException::withMessages(['archivo' => 'Falta instalar la migracion del historial de cursos. No se guardaron cambios.']);
        }
        if (!Schema::hasColumn('personal_curso_registros', 'mina_id')) {
            throw ValidationException::withMessages(['archivo' => 'Falta instalar la migracion de mina destino para la carga masiva. No se guardaron cambios.']);
        }
        if (!Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            throw ValidationException::withMessages(['archivo' => 'Falta instalar la migracion que vincula cursos con habilitacion minera. No se guardaron cambios.']);
        }
        return DB::transaction(function () use ($preview, $user) {
            // Serialize imports for the same workers and lock catalog configuration during revalidation.
            Personal::query()->whereIn('id', array_column($preview['rows'], 'personal_id'))->orderBy('id')->lockForUpdate()->get();
            ExamenMinero::query()->whereIn('id', array_column($preview['rows'], 'examen_id'))->orderBy('id')->lockForUpdate()->get();
            $rows = app(PersonalMinaBulkPreviewService::class)->plan($preview['source']);
            if (!hash_equals($preview['fingerprint'], $this->fingerprint($rows))) {
                throw ValidationException::withMessages(['archivo' => 'El historial o catalogo cambio desde la revision. Revise nuevamente el archivo.']);
            }
            if (collect($rows)->contains(fn ($r) => $r['issues'] !== [])) {
                throw ValidationException::withMessages(['archivo' => 'Corrija las filas observadas antes de confirmar. No se guardaron cambios.']);
            }
            $summary = ['creados'=>0,'actualizados'=>0,'omitidos'=>0,'errores'=>0,
                'minas_actualizadas'=>0,'examenes_generados'=>0];
            $mineService = app(PersonalMinaHabilitacionService::class);
            $emoRows = collect($rows)->where('operational_category', ExamenMinero::CATEGORIA_EMO)
                ->sortBy(fn (array $row): int => $row['type'] === 'Programacion' ? 0 : 1);
            foreach ($emoRows as $row) {
                if ($row['disposition'] !== 'changes') {
                    $summary['omitidos']++;
                    continue;
                }
                $workerExam = \App\Models\PersonalMinaExamen::query()
                    ->where('examen_id', $row['examen_id'])
                    ->whereHas('asignacion', fn ($query) => $query
                        ->where('personal_id', $row['personal_id'])
                        ->where('mina_id', $row['mina_id'])
                        ->where('activo', true))
                    ->first();
                if (!$workerExam) {
                    throw ValidationException::withMessages(['archivo' => 'La mina o el EMO del trabajador cambio desde la revision. Revise nuevamente el archivo.']);
                }
                $appointments = app(EmoAppointmentService::class);
                if ($row['type'] === 'Programacion') {
                    $appointments->schedule($workerExam, new ScheduleEmoData(
                        CarbonImmutable::parse($row['scheduled_date'], config('app.display_timezone', 'America/Lima')),
                        $row['clinic_id'], $row['observation'] ?: null, EmoOrigin::CARGA_MASIVA,
                    ), $user);
                    $summary['creados']++;
                    continue;
                }
                $appointment = \App\Models\PersonalEmoCita::query()
                    ->where('personal_id', $row['personal_id'])
                    ->whereDate('fecha_programacion', $row['scheduled_date'])
                    ->first();
                if (!$appointment || $appointment->clinica_id !== $row['clinic_id']) {
                    throw ValidationException::withMessages(['archivo' => 'La cita EMO cambio desde la revision. Revise nuevamente el archivo.']);
                }
                $score = $row['score'] === '' || $row['score'] === null ? null : (string) $row['score'];
                $expiration = $row['expiry']
                    ? CarbonImmutable::parse($row['expiry'], config('app.display_timezone', 'America/Lima'))
                    : null;
                $appointments->complete($appointment, new CompleteEmoData(
                    CarbonImmutable::parse($row['performed_date'], config('app.display_timezone', 'America/Lima')),
                    EmoOutcome::fromInput($row['result']), detail: $row['observation'] ?: null,
                    score: $score, manualExpiration: $expiration,
                    origin: EmoOrigin::CARGA_MASIVA,
                ), $user);
                $summary['actualizados']++;
            }

            $courseRows = collect($rows)->where('operational_category', '!=', ExamenMinero::CATEGORIA_EMO)->values();
            \App\Models\PersonalMina::query()
                ->whereIn('personal_id', $courseRows->pluck('personal_id')->filter()->unique())
                ->whereIn('mina_id', $courseRows->pluck('mina_id')->filter()->unique())
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($courseRows as $row) {
                if ($row['disposition'] !== 'changes') { $summary['omitidos']++; continue; }
                if (($row['mine_sync_only'] ?? false) === true) {
                    foreach (array_unique($row['sync_global_ids'] ?? []) as $recordId) {
                        $global = PersonalCursoRegistro::query()->lockForUpdate()->findOrFail($recordId);
                        $sync = $mineService->syncCourseRecordToMines($global, $user);
                        $summary['minas_actualizadas'] += $sync['minas_actualizadas'];
                        $summary['examenes_generados'] += $sync['examenes_generados'];
                    }
                    $summary['actualizados']++;
                    continue;
                }
                $payload = CursoResultado::payload(['resultado'=>$row['type'] === 'Resultado' ? $row['result'] : 'PENDIENTE',
                    'observacion'=>$row['observation'], 'nota'=>$row['score'] === '' ? null : $row['score'],
                    'fecha_programacion'=>$row['type'] === 'Programacion' ? $row['date'] : $row['matched_schedule'],
                    'fecha_realizacion'=>$row['type'] === 'Resultado' ? $row['date'] : null,'fecha_vencimiento'=>$row['expiry']]);
                $global = null;
                foreach ($row['existing_attempt_ids'] as $id) {
                    if (str_starts_with($id, 'curso:')) {
                        $global = PersonalCursoRegistro::query()->lockForUpdate()->findOrFail(substr($id, 6));
                        if ($global->resultado !== 'PENDIENTE') throw ValidationException::withMessages(['archivo'=>'La programacion ya tiene resultado. Revise el archivo.']);
                    } else {
                        $attempt = PersonalMinaExamenIntento::query()->with('examenTrabajador.asignacion')->lockForUpdate()->findOrFail($id);
                        if ($attempt->resultado !== 'PENDIENTE') throw ValidationException::withMessages(['archivo'=>'La programacion ya tiene resultado. Revise el archivo.']);
                        app(PersonalMinaHabilitacionService::class)->completeScheduledAttempt($attempt, $payload, null, $user);
                    }
                }
                $attributes = $payload + ['usuario_actualizacion_id'=>$user->id];
                if ($global) {
                    $global->fill($attributes)->save();
                    $summary['actualizados']++;
                } else {
                    $global = PersonalCursoRegistro::query()->create($attributes + ['id'=>(string) Str::uuid(), 'personal_id'=>$row['personal_id'],
                        'examen_id'=>$row['examen_id'], 'mina_id'=>$row['mina_id'],
                        'usuario_registro_id'=>$user->id, 'catalogo_snapshot'=>$row['catalog_snapshot'],
                        'origen'=>$preview['token'].' / '.$row['sheet'].' / '.$row['line']]);
                    $summary[$row['existing_attempt_ids'] ? 'actualizados' : 'creados']++;
                }
                $sync = $mineService->syncCourseRecordToMines($global->fresh(), $user);
                $summary['minas_actualizadas'] += $sync['minas_actualizadas'];
                $summary['examenes_generados'] += $sync['examenes_generados'];
            }
            return $summary;
        }, 3);
    }

    private function markPendingMineSync(array $rows): array
    {
        if (!Schema::hasTable('personal_curso_registros')
            || !Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            return $rows;
        }

        $recordIds = collect($rows)
            ->flatMap(fn ($row) => $row['existing_attempt_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'curso:'))
            ->map(fn ($id) => substr($id, 6))
            ->unique()
            ->values();
        if ($recordIds->isEmpty()) {
            return $rows;
        }

        $records = PersonalCursoRegistro::query()
            ->whereIn('id', $recordIds)
            ->get(['id', 'personal_id', 'examen_id', 'mina_id'])
            ->keyBy('id');
        $operationalMineIds = Mina::query()->activeOperational()->pluck('id');
        $targetsByPair = DB::table('personal_mina as pm')
            ->join('mina_requisitos as mr', 'mr.mina_id', '=', 'pm.mina_id')
            ->where('pm.activo', true)
            ->where('mr.activo', true)
            ->whereIn('pm.mina_id', $operationalMineIds)
            ->whereIn('pm.personal_id', $records->pluck('personal_id')->unique())
            ->whereIn('mr.examen_id', $records->pluck('examen_id')->unique())
            ->get(['pm.id as assignment_id', 'pm.personal_id', 'pm.mina_id', 'mr.examen_id'])
            ->groupBy(fn ($target) => $this->pairKey($target->personal_id, $target->examen_id, $target->mina_id))
            ->map(fn ($targets) => $targets->pluck('assignment_id')->unique());
        $syncedByRecord = DB::table('personal_mina_examen_intentos as attempt')
            ->join('personal_mina_examenes as exam', 'exam.id', '=', 'attempt.personal_mina_examen_id')
            ->whereIn('attempt.personal_curso_registro_id', $recordIds)
            ->get(['attempt.personal_curso_registro_id', 'exam.personal_mina_id'])
            ->groupBy('personal_curso_registro_id')
            ->map(fn ($links) => $links->pluck('personal_mina_id')->unique());

        foreach ($rows as &$row) {
            $globalIds = collect($row['existing_attempt_ids'] ?? [])
                ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'curso:'))
                ->map(fn ($id) => substr($id, 6))
                ->filter(fn ($id) => $records->has($id))
                ->unique()
                ->values();
            if ($globalIds->contains(fn ($recordId) => blank($records->get($recordId)->mina_id))) {
                $this->issue($row, 'El curso historico no tiene mina de origen. Revise su trazabilidad antes de aplicarlo.');
                $row['disposition'] = 'issues';
                $globalIds = $globalIds->filter(fn ($recordId) => filled($records->get($recordId)->mina_id))->values();
            }
            $pendingAssignments = collect();
            foreach ($globalIds as $recordId) {
                $record = $records->get($recordId);
                $targets = $targetsByPair->get($this->pairKey($record->personal_id, $record->examen_id, $record->mina_id), collect());
                $pendingAssignments = $pendingAssignments->merge(
                    $targets->diff($syncedByRecord->get($recordId, collect()))
                );
            }
            $pendingCount = $pendingAssignments->unique()->count();
            $row['mines_pending_sync'] = $pendingCount;
            $row['sync_global_ids'] = $globalIds->all();
            $row['mine_sync_only'] = false;
            if ($row['disposition'] === 'unchanged' && $pendingCount > 0) {
                $row['disposition'] = 'changes';
                $row['mine_sync_only'] = true;
                $row['action'] = 'Aplicar el curso existente a '.$pendingCount.' mina(s) que lo requieren';
            }
        }
        unset($row);

        return $rows;
    }

    private function issue(array &$row, string $message): void
    {
        $row['issues'][] = $message;
        $row['categories'][] = 'historial';
        $row['action'] = 'Revisar observaciones';
    }

    private function pairKey(string $personalId, string $examId, ?string $mineId): string
    {
        return $personalId.'|'.$examId.'|'.($mineId ?: 'SIN_MINA');
    }
}
