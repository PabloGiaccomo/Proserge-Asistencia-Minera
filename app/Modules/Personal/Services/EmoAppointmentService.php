<?php

namespace App\Modules\Personal\Services;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalEmoCitaHistorial;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Support\CompleteEmoData;
use App\Modules\Personal\Support\EmoOrigin;
use App\Modules\Personal\Support\ReprogramEmoData;
use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmoAppointmentService
{
    public function __construct(
        private readonly PersonalMinaHabilitacionService $mining,
        private readonly OperationalNotificationService $notifications,
    ) {}

    public function schedule(PersonalMinaExamen $exam, ScheduleEmoData $data, Usuario $actor): PersonalEmoCita
    {
        return $this->transaction(function () use ($exam, $data, $actor): PersonalEmoCita {
            $workerId = $this->workerId($exam);
            $this->lockWorker($workerId);
            $appointment = PersonalEmoCita::where('personal_id', $workerId)
                ->whereDate('fecha_programacion', $data->scheduledAt->toDateString())->lockForUpdate()->first();
            [$exams, $attempts] = $this->lockWorkerExams($workerId);
            $exam = $exams->get($exam->id) ?? throw ValidationException::withMessages(['examen' => 'El examen ya no pertenece al trabajador.']);
            $this->assertOperational($exam);
            if ($exam->estado === PersonalMinaExamen::ESTADO_NO_APLICA) {
                app(LegacyMiningDeployRepairService::class)->closeNotApplicablePendingLocked($exam, $attempts, $actor, $appointment?->id);
                $appointment?->refresh();
                $exam->forceFill([
                    'intentos_ciclo_desde' => now(),
                    'intentos_ciclo_numero_desde' => 1 + (int) $attempts->where('personal_mina_examen_id', $exam->id)->max('numero_intento'),
                ])->save();
            }
            $clinic = $this->activeClinic($data->clinicId);
            $this->assertNoLegacySchedule($attempts, $data->scheduledAt->toDateString());
            if ($appointment) {
                $this->assertScheduled($appointment);
                $this->assertClinic($appointment, $clinic);
                $this->assertExactExam($appointment, $attempts, $exams, $exam->examen_id);
            } else {
                $appointment = PersonalEmoCita::create([
                    'personal_id' => $workerId, 'clinica_id' => $clinic->id,
                    'clinica_nombre_snapshot' => $clinic->nombre, 'fecha_programacion' => $data->scheduledAt->toDateString(),
                    'estado' => PersonalEmoCita::ESTADO_PROGRAMADA, 'revision' => 1, 'origen' => $data->origin->value,
                    'usuario_programacion_id' => $actor->id, 'usuario_actualizacion_id' => $actor->id,
                ]);
                $this->history($appointment, 'CREADA', [], $this->snapshot($appointment), $data->origin, $actor);
            }
            $record = $this->sourceRecord($data->sourceCourseRecordId, $exam, $appointment);
            $existing = $attempts->first(fn ($attempt) => $attempt->emo_cita_id === $appointment->id
                && $attempt->personal_mina_examen_id === $exam->id
                && $attempt->resultado === PersonalMinaExamenIntento::RESULTADO_PENDIENTE);
            if ($existing) {
                if ($record && $existing->personal_curso_registro_id !== $record->id) {
                    throw ValidationException::withMessages(['personal_curso_registro_id' => 'La programacion ya tiene otro origen.']);
                }

                return $appointment;
            }
            if ($attempts->contains(fn ($attempt) => $attempt->personal_mina_examen_id === $exam->id
                && $attempt->resultado === 'PENDIENTE' && $attempt->fecha_programacion)) {
                throw ValidationException::withMessages(['fecha_programacion' => 'Este examen ya tiene una programacion pendiente.']);
            }
            $this->assertExamCanReceiveAttempt($exam);
            $cancelledLink = $attempts->first(fn ($attempt) => $attempt->emo_cita_id === $appointment->id
                && $attempt->personal_mina_examen_id === $exam->id
                && $attempt->resultado === PersonalMinaExamenIntento::RESULTADO_ANULADO);
            if ($cancelledLink) {
                if ($record && $cancelledLink->personal_curso_registro_id !== $record->id) {
                    throw ValidationException::withMessages(['personal_curso_registro_id' => 'La programacion anulada tiene otro origen.']);
                }
                $cancelledLink->forceFill(['emo_cita_id' => null])->save();
            }
            $price = $this->mining->resolveAttemptPriceSnapshot($exam, null, $data->scheduledAt->toDateString(), null, true);
            $attempt = PersonalMinaExamenIntento::create([
                'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id,
                'emo_cita_id' => $appointment->id, 'personal_curso_registro_id' => $record?->id,
                'numero_intento' => 1 + (int) $attempts->where('personal_mina_examen_id', $exam->id)->max('numero_intento'),
                'fecha_programacion' => $data->scheduledAt->toDateString(), 'resultado' => 'PENDIENTE',
                'observacion' => $this->observation($data->observation), 'usuario_registro_id' => $actor->id,
                ...$this->priceAttributes($price),
            ]);
            $this->mining->applyEmoAppointmentAttempt($exam, $attempt, null, $actor);
            $this->mining->refreshAssignmentFromExams($exam->asignacion, $actor);
            $previousRevision = $appointment->revision;
            $appointment->forceFill(['revision' => $previousRevision + 1, 'usuario_actualizacion_id' => $actor->id])->save();
            $this->history($appointment, 'MINA_VINCULADA', ['revision' => $previousRevision], [
                'revision' => $appointment->revision,
                'personal_mina_id' => $exam->personal_mina_id, 'mina_id' => $exam->asignacion->mina_id,
                'examen_id' => $exam->examen_id, 'intento_id' => $attempt->id,
                'intento_anterior_anulado_id' => $cancelledLink?->id,
            ], $data->origin, $actor);
            $this->notifyScheduled($exam, $attempt, $actor);

            return $appointment;
        });
    }

    public function complete(PersonalEmoCita $appointment, CompleteEmoData $data, Usuario $actor): PersonalEmoCita
    {
        $expectedRevision = $appointment->revision;

        return $this->transaction(function () use ($appointment, $data, $actor, $expectedRevision): PersonalEmoCita {
            $storedFile = null;
            $this->lockWorker($appointment->personal_id);
            $given = $appointment;
            $appointment = $this->lockedAppointment($appointment);
            $this->assertUnchanged($given, $appointment, $expectedRevision);
            $this->assertScheduled($appointment);
            [$exams, $attempts] = $this->lockWorkerExams($appointment->personal_id);
            $linked = $this->linkedPendingAttempts($appointment, $attempts);
            $clinic = $this->activeClinic($appointment->clinica_id);
            $this->assertClinic($appointment, $clinic);
            $this->assertNoLegacySchedule($attempts, $appointment->fecha_programacion->toDateString());
            $today = CarbonImmutable::today(config('app.display_timezone', 'America/Lima'))->toDateString();
            if ($appointment->fecha_programacion->toDateString() > $today) {
                throw ValidationException::withMessages(['fecha_programacion' => 'Todavia no se puede registrar resultado porque la fecha programada no ha pasado.']);
            }
            $dateDone = $data->performedAt->toDateString();
            if ($dateDone > $today || $dateDone < $appointment->fecha_programacion->toDateString()) {
                throw ValidationException::withMessages(['fecha_realizacion' => 'La realizacion debe ser desde la fecha programada y no puede ser futura.']);
            }
            $detail = $this->observation($data->detail);
            if ($data->outcome->requiresDetail() && ! $detail) {
                throw ValidationException::withMessages(['detalle_resultado_emo' => 'Detalle la restriccion u observacion del resultado EMO.']);
            }
            if ($data->manualExpiration && $data->manualExpiration->toDateString() < $dateDone) {
                throw ValidationException::withMessages(['fecha_vencimiento' => 'La fecha de vencimiento no puede ser menor a la fecha de realizacion.']);
            }
            if ($data->score !== null && ! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $data->score)) {
                throw ValidationException::withMessages(['nota' => 'Indique una nota decimal valida.']);
            }
            $observation = $this->medicalObservation($data, $detail);
            $exactExamId = null;
            foreach ($linked as $attempt) {
                $exam = $exams->get($attempt->personal_mina_examen_id);
                $this->assertOperational($exam);
                $exactExamId ??= $exam->examen_id;
                if ($exam->examen_id !== $exactExamId || $attempt->resultado !== 'PENDIENTE') {
                    throw ValidationException::withMessages(['examen' => 'Los enlaces de esta cita ya no son compatibles.']);
                }
                if ($exam->requiere_nota_snapshot && $data->outcome->genericResult() === 'APROBADO' && $data->score === null) {
                    throw ValidationException::withMessages(['nota' => 'La nota es obligatoria para este examen.']);
                }
            }
            $source = null;
            if ($data->sourceCourseRecordId) {
                $source = $linked->firstWhere('personal_curso_registro_id', $data->sourceCourseRecordId);
                if (! $source) {
                    throw ValidationException::withMessages(['personal_curso_registro_id' => 'El origen no esta vinculado a esta cita.']);
                }
                $this->sourceRecord($data->sourceCourseRecordId, $exams->get($source->personal_mina_examen_id), $appointment);
            }
            if ($data->file) {
                $path = $data->file->storeAs('habilitacion-minera/emo/'.$appointment->id,
                    Str::uuid().'.'.($data->file->getClientOriginalExtension() ?: 'bin'), 'local');
                if (! $path) {
                    throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo medico.']);
                }
                $storedFile = $path;
                // Keep compensation on every enclosing transaction, since a nested
                // service may commit its savepoint while the importing transaction rolls back.
                // Only actual rollback compensates files; afterCommit errors cannot undo a commit.
                $connection = DB::connection()->getName();
                foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
                    if ($transaction->connection === $connection) {
                        $transaction->addCallbackForRollback(fn () => Storage::disk('local')->delete($path));
                    }
                }
            }
            $before = $this->snapshot($appointment) + ['intentos' => $linked->map(fn ($attempt) => [
                'intento_id' => $attempt->id, 'archivo_path' => $attempt->archivo_path,
                'archivo_nombre_original' => $attempt->archivo_nombre_original,
                'archivo_mime' => $attempt->archivo_mime, 'archivo_size' => $attempt->archivo_size,
            ])->all()];
            foreach ($linked as $attempt) {
                $exam = $exams->get($attempt->personal_mina_examen_id);
                $score = $exam->requiere_nota_snapshot ? $data->score : null;
                $result = $data->outcome->genericResult();
                if ($result === 'APROBADO' && $score !== null && $exam->nota_minima_snapshot !== null
                    && (float) $score < (float) $exam->nota_minima_snapshot) {
                    $result = 'DESAPROBADO';
                }
                $price = $this->mining->resolveAttemptPriceSnapshot($exam, $dateDone, null, null, true);
                $attributes = [
                    'fecha_realizacion' => $dateDone, 'resultado' => $result, 'nota' => $score,
                    'observacion' => $observation, 'usuario_registro_id' => $actor->id,
                    ...$this->priceAttributes($price),
                ];
                if ($storedFile) {
                    $attributes += ['archivo_path' => $storedFile,
                        'archivo_nombre_original' => $data->file->getClientOriginalName(),
                        'archivo_mime' => $data->file->getMimeType(), 'archivo_size' => $data->file->getSize()];
                }
                $attempt->forceFill($attributes)->save();
                $this->mining->applyEmoAppointmentAttempt($exam, $attempt, $data->manualExpiration, $actor);
            }
            $appointment->forceFill([
                'fecha_realizacion' => $dateDone, 'estado' => PersonalEmoCita::ESTADO_COMPLETADA,
                'revision' => $appointment->revision + 1,
                'resultado_emo' => $data->outcome->value, 'detalle_resultado_emo' => $detail,
                'usuario_resultado_id' => $actor->id, 'usuario_actualizacion_id' => $actor->id,
            ])->save();
            $this->refreshLinkedAssignments($linked, $exams, $actor);
            $this->history($appointment, 'COMPLETADA', $before, $this->snapshot($appointment), $data->origin, $actor);

            return $appointment;
        });
    }

    public function cancel(PersonalEmoCita $appointment, string $reason, Usuario $actor): PersonalEmoCita
    {
        $expectedRevision = $appointment->revision;

        return $this->transaction(function () use ($appointment, $reason, $actor, $expectedRevision): PersonalEmoCita {
            $this->lockWorker($appointment->personal_id);
            $locked = $this->lockedAppointment($appointment);
            $this->assertScheduled($locked);
            $this->assertUnchanged($appointment, $locked, $expectedRevision);
            $reason = $this->requiredReason($reason);
            [$exams, $attempts] = $this->lockWorkerExams($locked->personal_id);
            $linked = $this->linkedPendingAttempts($locked, $attempts);
            $before = $this->snapshot($locked);
            foreach ($linked as $attempt) {
                $this->assertOperational($exams->get($attempt->personal_mina_examen_id));
                if ($attempt->resultado !== 'PENDIENTE') {
                    throw ValidationException::withMessages(['cita' => 'La cita tiene un resultado registrado y no puede anularse.']);
                }
                $attempt->forceFill(['resultado' => 'ANULADO'])->save();
                $this->mining->applyEmoAppointmentAttempt($exams->get($attempt->personal_mina_examen_id), $attempt, null, $actor);
            }
            $locked->forceFill(['estado' => PersonalEmoCita::ESTADO_ANULADA,
                'revision' => $locked->revision + 1, 'usuario_actualizacion_id' => $actor->id])->save();
            $this->refreshLinkedAssignments($linked, $exams, $actor);
            $this->history($locked, 'ANULADA', $before, $this->snapshot($locked) + ['motivo' => $reason], EmoOrigin::fromInput($locked->origen), $actor);

            return $locked;
        });
    }

    public function reprogram(PersonalEmoCita $appointment, ReprogramEmoData $data, Usuario $actor): PersonalEmoCita
    {
        $expectedRevision = $appointment->revision;

        return $this->transaction(function () use ($appointment, $data, $actor, $expectedRevision): PersonalEmoCita {
            $this->lockWorker($appointment->personal_id);
            // Lock both dates in stable order before any assignment/exam.
            $appointments = PersonalEmoCita::where('personal_id', $appointment->personal_id)
                ->where(fn ($query) => $query->whereKey($appointment->id)->orWhereDate('fecha_programacion', $data->scheduledAt->toDateString()))
                ->orderBy('id')->lockForUpdate()->get();
            $locked = $appointments->firstWhere('id', $appointment->id) ?? throw ValidationException::withMessages(['cita' => 'La cita ya no existe.']);
            $this->assertUnchanged($appointment, $locked, $expectedRevision);
            if ($locked->estado !== PersonalEmoCita::ESTADO_ANULADA) {
                throw ValidationException::withMessages(['cita' => 'Solo una cita anulada puede reprogramarse.']);
            }
            if ($appointments->contains(fn ($other) => $other->id !== $locked->id)) {
                throw ValidationException::withMessages(['fecha_programacion' => 'El trabajador ya tiene una cita registrada en la fecha elegida.']);
            }
            $reason = $this->requiredReason($data->reason);
            $clinic = $this->activeClinic($data->clinicId);
            [$exams, $attempts] = $this->lockWorkerExams($locked->personal_id);
            $this->assertNoLegacySchedule($attempts, $data->scheduledAt->toDateString());
            $linked = $this->linkedReprogrammableAttempts($locked, $attempts, $exams);
            $before = $this->snapshot($locked);
            foreach ($linked as $attempt) {
                $exam = $exams->get($attempt->personal_mina_examen_id);
                $this->assertOperational($exam);
                if ($attempt->resultado !== 'ANULADO') {
                    throw ValidationException::withMessages(['cita' => 'Los intentos de la cita ya no permiten reprogramacion.']);
                }
                $this->assertExactExam($locked, $attempts, $exams, $exam->examen_id);
                if ($attempts->contains(fn ($other) => $other->id !== $attempt->id
                    && $other->personal_mina_examen_id === $exam->id && $other->resultado === 'PENDIENTE'
                    && $other->fecha_programacion)) {
                    throw ValidationException::withMessages(['fecha_programacion' => 'Este examen ya tiene otra programacion pendiente.']);
                }
                $attempt->forceFill(['fecha_programacion' => $data->scheduledAt->toDateString(), 'resultado' => 'PENDIENTE',
                    ...$this->priceAttributes($this->mining->resolveAttemptPriceSnapshot($exam, null, $data->scheduledAt->toDateString(), null, true))])->save();
                $this->mining->applyEmoAppointmentAttempt($exam, $attempt, null, $actor);
                $this->notifyScheduled($exam, $attempt, $actor);
            }
            $locked->forceFill(['fecha_programacion' => $data->scheduledAt->toDateString(),
                'clinica_id' => $clinic->id, 'clinica_nombre_snapshot' => $clinic->nombre,
                'estado' => PersonalEmoCita::ESTADO_PROGRAMADA,
                'revision' => $locked->revision + 1, 'usuario_actualizacion_id' => $actor->id])->save();
            $this->refreshLinkedAssignments($linked, $exams, $actor);
            $this->history($locked, 'REPROGRAMADA', $before, $this->snapshot($locked) + ['motivo' => $reason], $data->origin, $actor);

            return $locked;
        });
    }

    public function regularizeLegacyAttempt(PersonalMinaExamenIntento $attempt, ClinicaEmo $clinic, Usuario $actor): PersonalEmoCita
    {
        return $this->transaction(function () use ($attempt, $clinic, $actor): PersonalEmoCita {
            $exam = PersonalMinaExamen::findOrFail($attempt->personal_mina_examen_id);
            $workerId = $this->workerId($exam);
            $this->lockWorker($workerId);
            // Read the requested date under the worker lock; recheck the attempt after domain locks.
            $date = $attempt->fecha_programacion?->toDateString();
            if (! $date) {
                throw ValidationException::withMessages(['fecha_programacion' => 'El intento historico no tiene fecha programada.']);
            }
            $appointment = PersonalEmoCita::where('personal_id', $workerId)->whereDate('fecha_programacion', $date)->lockForUpdate()->first();
            [$exams, $attempts] = $this->lockWorkerExams($workerId);
            $lockedAttempt = $attempts->firstWhere('id', $attempt->id);
            if (! $lockedAttempt || $lockedAttempt->resultado !== 'PENDIENTE'
                || $lockedAttempt->fecha_programacion?->toDateString() !== $date) {
                throw ValidationException::withMessages(['intento' => 'Solo una programacion historica pendiente puede regularizarse.']);
            }
            $exam = $exams->get($lockedAttempt->personal_mina_examen_id);
            $this->assertOperational($exam);
            $clinic = $this->activeClinic($clinic->id);
            if ($lockedAttempt->emo_cita_id) {
                if ($appointment && $lockedAttempt->emo_cita_id === $appointment->id && $appointment->clinica_id === $clinic->id) {
                    return $appointment;
                }
                throw ValidationException::withMessages(['intento' => 'Este intento ya tiene una cita vinculada.']);
            }
            if ($appointment) {
                $this->assertScheduled($appointment);
                $this->assertClinic($appointment, $clinic);
                $this->assertExactExam($appointment, $attempts, $exams, $exam->examen_id);
            } else {
                $appointment = PersonalEmoCita::create([
                    'personal_id' => $workerId, 'clinica_id' => $clinic->id, 'clinica_nombre_snapshot' => $clinic->nombre,
                    'fecha_programacion' => $date, 'estado' => 'PROGRAMADA', 'revision' => 1, 'origen' => EmoOrigin::REGULARIZACION->value,
                    'usuario_programacion_id' => $lockedAttempt->usuario_registro_id, 'usuario_actualizacion_id' => $actor->id,
                ]);
                $this->history($appointment, 'CREADA', [], $this->snapshot($appointment), EmoOrigin::REGULARIZACION, $actor);
            }
            $lockedAttempt->forceFill(['emo_cita_id' => $appointment->id])->save();
            $previousRevision = $appointment->revision;
            $appointment->forceFill(['revision' => $previousRevision + 1, 'usuario_actualizacion_id' => $actor->id])->save();
            $this->history($appointment, 'REGULARIZADA', ['intento_id' => $lockedAttempt->id, 'emo_cita_id' => null, 'revision' => $previousRevision],
                ['intento_id' => $lockedAttempt->id, 'emo_cita_id' => $appointment->id, 'revision' => $appointment->revision,
                    'mina_id' => $exam->asignacion->mina_id], EmoOrigin::REGULARIZACION, $actor);

            return $appointment;
        });
    }

    public function markExamNotApplicable(PersonalMinaExamen $exam, array $payload, Usuario $actor): PersonalMinaExamen
    {
        $workerId = $this->workerId($exam);

        return $this->transaction(function () use ($exam, $payload, $actor, $workerId): PersonalMinaExamen {
            $this->lockWorker($workerId);
            $appointmentIds = PersonalMinaExamenIntento::query()
                ->where('personal_mina_examen_id', $exam->id)
                ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
                ->whereNotNull('emo_cita_id')->pluck('emo_cita_id');
            $appointments = PersonalEmoCita::query()->whereIn('id', $appointmentIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            [$exams, $attempts] = $this->lockWorkerExams($workerId);
            $exam = $exams->get($exam->id) ?? throw ValidationException::withMessages(['examen' => 'El examen ya no pertenece al trabajador.']);
            $this->assertOperational($exam);
            if (!$exam->requisitoMina?->permite_no_aplica) {
                throw ValidationException::withMessages(['no_aplica' => 'Este examen no permite marcar no aplica.']);
            }

            $pending = $attempts->where('personal_mina_examen_id', $exam->id)
                ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE);
            foreach ($pending as $attempt) {
                $attempt->forceFill(['resultado' => PersonalMinaExamenIntento::RESULTADO_ANULADO])->save();
            }
            foreach ($pending->pluck('emo_cita_id')->filter()->unique() as $appointmentId) {
                $appointment = $appointments->get($appointmentId);
                if (!$appointment) {
                    continue;
                }
                $before = $this->snapshot($appointment);
                $hasOtherPending = $attempts->contains(fn (PersonalMinaExamenIntento $attempt): bool =>
                    $attempt->emo_cita_id === $appointmentId
                    && $attempt->personal_mina_examen_id !== $exam->id
                    && $attempt->resultado === PersonalMinaExamenIntento::RESULTADO_PENDIENTE);
                $appointment->forceFill([
                    'estado' => $hasOtherPending ? $appointment->estado : PersonalEmoCita::ESTADO_ANULADA,
                    'revision' => $appointment->revision + 1,
                    'usuario_actualizacion_id' => $actor->id,
                ])->save();
                $this->history($appointment, $hasOtherPending ? 'MINA_ANULADA_NO_APLICA' : 'ANULADA_NO_APLICA',
                    $before, $this->snapshot($appointment) + [
                        'personal_mina_examen_id' => $exam->id,
                        'motivo' => trim((string) ($payload['observacion'] ?? '')) ?: 'Marcado no aplica',
                    ], EmoOrigin::fromInput($appointment->origen), $actor);
            }

            $exam->forceFill([
                'estado' => PersonalMinaExamen::ESTADO_NO_APLICA,
                'resultado' => 'NO_APLICA',
                'observacion' => $this->observation($payload['observacion'] ?? null),
                'usuario_actualizacion_id' => $actor->id,
                'fecha_actualizacion' => now(),
                'intentos_ciclo_desde' => now(),
                'intentos_ciclo_numero_desde' => 1 + (int) $attempts->where('personal_mina_examen_id', $exam->id)->max('numero_intento'),
            ])->save();
            $this->mining->refreshAssignmentFromExams($exam->asignacion, $actor);

            return $exam->fresh(['asignacion', 'intentos']);
        });
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException|DeadlockException $exception) {
            if ($exception instanceof DeadlockException || in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
                throw ValidationException::withMessages(['cita' => 'Otra operacion modifico la cita. Actualice el control y revise la programacion existente.']);
            }
            throw $exception;
        }
    }

    private function workerId(PersonalMinaExamen $exam): string
    {
        return PersonalMina::findOrFail($exam->personal_mina_id)->personal_id;
    }

    private function lockWorker(string $workerId): void
    {
        Personal::whereKey($workerId)->lockForUpdate()->firstOrFail();
    }

    /** Current reads, including inside an existing REPEATABLE READ transaction.
     * Lock the worker's assignments/exams as a stable superset before attempts.
     * Only explicit appointment links are ever changed.
     *
     * @return array{Collection, Collection}
     */
    private function lockWorkerExams(string $workerId): array
    {
        $assignments = PersonalMina::where('personal_id', $workerId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $exams = PersonalMinaExamen::whereIn('personal_mina_id', $assignments->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($exams as $exam) {
            $exam->setRelation('asignacion', $assignments->get($exam->personal_mina_id));
        }
        $attempts = PersonalMinaExamenIntento::whereIn('personal_mina_examen_id', $exams->keys())->orderBy('id')->lockForUpdate()->get();
        foreach ($exams as $exam) {
            $exam->setRelation('intentos', $attempts->where('personal_mina_examen_id', $exam->id)->values());
        }

        return [$exams, $attempts];
    }

    private function assertOperational(PersonalMinaExamen $exam): void
    {
        // Read-only configuration uses current shared reads: unrelated workers
        // must not acquire opposite exclusive clinic/catalog locks.
        $catalog = ExamenMinero::whereKey($exam->examen_id)->sharedLock()->first();
        $requirement = MinaRequisito::whereKey($exam->mina_requisito_id)->sharedLock()->first();
        $mine = Mina::activeOperational()->whereKey($exam->asignacion->mina_id)->sharedLock()->first();
        if (! $exam->asignacion->activo || ! $mine || ! $requirement?->activo
            || $requirement->mina_id !== $exam->asignacion->mina_id || $requirement->examen_id !== $exam->examen_id
            || ! $catalog?->activo || $catalog->categoria_operativa !== ExamenMinero::CATEGORIA_EMO) {
            throw ValidationException::withMessages(['examen' => 'Verifique la asignacion, mina y requisito activos de un examen de categoria EMO.']);
        }
        $exam->setRelation('examen', $catalog)->setRelation('requisitoMina', $requirement);
    }

    private function activeClinic(string $id): ClinicaEmo
    {
        $clinic = ClinicaEmo::whereKey($id)->sharedLock()->first();
        if (! $clinic?->activo) {
            throw ValidationException::withMessages(['clinica_id' => 'Seleccione una clinica activa.']);
        }

        return $clinic;
    }

    private function assertClinic(PersonalEmoCita $appointment, ClinicaEmo $clinic): void
    {
        if ($appointment->clinica_id !== $clinic->id) {
            throw ValidationException::withMessages(['clinica_id' => 'El trabajador ya tiene una cita el '
                .$appointment->fecha_programacion->format('d/m/Y').' en '.$appointment->clinica_nombre_snapshot.'.']);
        }
    }

    private function assertScheduled(PersonalEmoCita $appointment): void
    {
        if ($appointment->estado !== PersonalEmoCita::ESTADO_PROGRAMADA) {
            throw ValidationException::withMessages(['cita' => 'La cita ya fue completada o anulada. Revise su historial.']);
        }
    }

    /** The caller's observed revision is captured before waiting for locks.
     * Every command-validity change increments the persisted revision while
     * holding the worker/appointment locks, including links and ABA transitions.
     */
    private function assertUnchanged(PersonalEmoCita $given, PersonalEmoCita $locked, ?int $expectedRevision): void
    {
        if ($expectedRevision === null || $expectedRevision !== $locked->revision
            || $given->estado !== $locked->estado || $given->clinica_id !== $locked->clinica_id
            || $given->fecha_programacion?->toDateString() !== $locked->fecha_programacion?->toDateString()) {
            throw ValidationException::withMessages(['cita' => 'La cita cambio durante la operacion. Actualice el control antes de continuar.']);
        }
    }

    private function lockedAppointment(PersonalEmoCita $appointment): PersonalEmoCita
    {
        return PersonalEmoCita::whereKey($appointment->id)->where('personal_id', $appointment->personal_id)->lockForUpdate()->firstOrFail();
    }

    private function assertExactExam(PersonalEmoCita $appointment, Collection $attempts, Collection $exams, string $examId): void
    {
        foreach ($attempts->where('emo_cita_id', $appointment->id) as $attempt) {
            $linked = $exams->get($attempt->personal_mina_examen_id);
            $this->assertOperational($linked);
            if ($linked->examen_id !== $examId) {
                throw ValidationException::withMessages(['examen' => 'La cita solo admite minas que requieren exactamente el mismo examen.']);
            }
        }
    }

    private function assertNoLegacySchedule(Collection $attempts, string $date): void
    {
        $legacy = $attempts->filter(fn ($attempt) => ! $attempt->emo_cita_id && $attempt->resultado === 'PENDIENTE'
            && $attempt->fecha_programacion?->toDateString() === $date);
        foreach ($legacy as $attempt) {
            $exam = PersonalMinaExamen::whereKey($attempt->personal_mina_examen_id)->lockForUpdate()->firstOrFail();
            $catalog = ExamenMinero::whereKey($exam->examen_id)->sharedLock()->first();
            if ($catalog?->categoria_operativa === ExamenMinero::CATEGORIA_EMO) {
                throw ValidationException::withMessages(['fecha_programacion' => 'Existe un EMO historico sin clinica para ese dia. Regularice primero la programacion.']);
            }
        }
    }

    private function assertExamCanReceiveAttempt(PersonalMinaExamen $exam): void
    {
        // NO_APLICA closes one operational cycle. An explicit later schedule
        // starts another while keeping physical attempt numbers monotonic.
    }

    private function linkedPendingAttempts(PersonalEmoCita $appointment, Collection $attempts): Collection
    {
        $linked = $attempts->where('emo_cita_id', $appointment->id)
            ->where('resultado', PersonalMinaExamenIntento::RESULTADO_PENDIENTE)
            ->values();
        if ($linked->isEmpty()) {
            throw ValidationException::withMessages(['cita' => 'La cita no tiene examenes vinculados.']);
        }

        return $linked;
    }

    private function linkedReprogrammableAttempts(PersonalEmoCita $appointment, Collection $attempts, Collection $exams): Collection
    {
        $linked = $attempts->where('emo_cita_id', $appointment->id)
            ->where('resultado', PersonalMinaExamenIntento::RESULTADO_ANULADO)
            ->filter(function (PersonalMinaExamenIntento $attempt) use ($exams): bool {
                $exam = $exams->get($attempt->personal_mina_examen_id);
                if (! $exam || $exam->estado === PersonalMinaExamen::ESTADO_NO_APLICA) {
                    return false;
                }

                return $exam->intentos_ciclo_numero_desde === null
                    || $attempt->numero_intento >= $exam->intentos_ciclo_numero_desde;
            })->values();
        if ($linked->isEmpty()) {
            throw ValidationException::withMessages(['cita' => 'La cita no tiene examenes vinculados que permitan reprogramacion.']);
        }

        return $linked;
    }

    private function sourceRecord(?string $id, PersonalMinaExamen $exam, PersonalEmoCita $appointment): ?PersonalCursoRegistro
    {
        if (! $id) {
            return null;
        }
        $record = PersonalCursoRegistro::whereKey($id)->lockForUpdate()->first();
        if (! $record || $record->personal_id !== $appointment->personal_id || $record->examen_id !== $exam->examen_id
            || $record->mina_id !== $exam->asignacion->mina_id || ($record->emo_cita_id && $record->emo_cita_id !== $appointment->id)) {
            throw ValidationException::withMessages(['personal_curso_registro_id' => 'El registro de origen debe corresponder al trabajador, examen y mina vinculados.']);
        }
        if (! $record->emo_cita_id) {
            $record->forceFill(['emo_cita_id' => $appointment->id])->save();
        }

        return $record;
    }

    private function refreshLinkedAssignments(Collection $attempts, Collection $exams, Usuario $actor): void
    {
        $assignments = $attempts->map(fn ($attempt) => $exams->get($attempt->personal_mina_examen_id)->asignacion)
            ->unique('id')->sortBy('id');
        foreach ($assignments as $assignment) {
            $this->mining->refreshAssignmentFromExams($assignment, $actor);
        }
    }

    private function medicalObservation(CompleteEmoData $data, ?string $detail): ?string
    {
        $parts = collect([$detail ? strtoupper($data->outcome->label()).': '.$detail : null,
            $data->observation])->filter()->unique()->implode("\n");

        return $this->observation($parts);
    }

    private function observation(?string $value): ?string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > 5000) {
            throw ValidationException::withMessages(['observacion' => 'La observacion no debe superar 5000 caracteres.']);
        }

        return $value !== '' ? $value : null;
    }

    private function requiredReason(string $reason): string
    {
        return $this->observation($reason) ?? throw ValidationException::withMessages(['motivo' => 'Indique el motivo de la operacion.']);
    }

    private function priceAttributes(array $price): array
    {
        return ['precio_aplicado' => $price['precio'], 'moneda_aplicada' => $price['moneda'],
            'fecha_precio_aplicado' => $price['fecha'], 'fuente_precio' => $price['fuente']];
    }

    private function snapshot(PersonalEmoCita $appointment): array
    {
        return ['fecha_programacion' => $appointment->fecha_programacion?->toDateString(),
            'fecha_realizacion' => $appointment->fecha_realizacion?->toDateString(), 'clinica_id' => $appointment->clinica_id,
            'clinica_nombre_snapshot' => $appointment->clinica_nombre_snapshot, 'estado' => $appointment->estado,
            'revision' => $appointment->revision,
            'resultado_emo' => $appointment->resultado_emo, 'detalle_resultado_emo' => $appointment->detalle_resultado_emo,
            'usuario_programacion_id' => $appointment->usuario_programacion_id, 'usuario_resultado_id' => $appointment->usuario_resultado_id];
    }

    private function history(PersonalEmoCita $appointment, string $event, array $before, array $after, EmoOrigin $origin, Usuario $actor): void
    {
        PersonalEmoCitaHistorial::create(['emo_cita_id' => $appointment->id, 'evento' => $event,
            'valores_anteriores_json' => $before, 'valores_nuevos_json' => $after,
            'usuario_id' => $actor->id, 'origen' => $origin->value, 'fecha_cambio' => now()]);
    }

    private function notifyScheduled(PersonalMinaExamen $exam, PersonalMinaExamenIntento $attempt, Usuario $actor): void
    {
        DB::afterCommit(function () use ($exam, $attempt, $actor): void {
            $current = $attempt->fresh();
            if ($current?->resultado === 'PENDIENTE' && $current->emoCita?->estado === 'PROGRAMADA') {
                $this->notifications->examenProgramado($exam->fresh(['asignacion.personal', 'asignacion.mina']), $actor, $current);
            }
        });
    }
}
