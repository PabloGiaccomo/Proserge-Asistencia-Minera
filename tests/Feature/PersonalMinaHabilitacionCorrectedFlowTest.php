<?php

namespace Tests\Feature;

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Services\PersonalMinaExcelImportService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Modules\Personal\Services\PersonalMinaImportPreviewStore;
use App\Modules\Personal\Services\MineRequirementSyncService;
use App\Modules\Personal\Services\MiningExamCatalogAuditService;
use App\Modules\Personal\Services\LegacyEmoOutcomeRepairService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PersonalMinaHabilitacionCorrectedFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reparacion_legacy_inequivoca_convierte_11_de_12_en_habilitado_con_auditoria(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $emo = $this->createExam('EMO LEGACY', ['categoria_operativa' => 'EMO']);
        $assignment = $this->assignmentWithExam($service, $actor, $emo);
        for ($index = 1; $index <= 11; $index++) {
            $catalog = $this->createExam('REQUISITO LEGACY '.$index.' '.Str::random(4));
            $service->storeRequirement(['mina_id' => $assignment->mina_id, 'examen_id' => $catalog->id]);
        }
        $service->generateRequiredExams($assignment, $actor, false, false);
        $candidate = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)->where('examen_id', $emo->id)->firstOrFail();
        PersonalMinaExamen::query()->where('personal_mina_id', $assignment->id)
            ->whereKeyNot($candidate->id)->update(['estado' => 'APROBADO', 'resultado' => 'APROBADO']);
        $candidate->forceFill([
            'estado' => 'OBSERVADO', 'resultado' => 'APROBADO',
            'fecha_realizacion' => '2026-04-29', 'fecha_vencimiento' => '2027-04-29',
            'observacion' => 'APTO CON RESTRICCIONES',
        ])->save();
        PersonalMinaExamenIntento::query()->create([
            'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $candidate->id,
            'numero_intento' => 1, 'fecha_realizacion' => '2026-04-29', 'resultado' => 'APROBADO',
            'observacion' => 'APTO CON RESTRICCIONES', 'usuario_registro_id' => $actor->id,
        ]);
        $assignment->forceFill(['estado' => 'OBSERVADO', 'estado_habilitacion' => 'OBSERVADO'])->save();

        $this->assertSame(['total' => 12, 'resueltos' => 11, 'pendientes' => 0, 'programados' => 0, 'desaprobados' => 0, 'vencidos' => 0],
            $service->assignmentExamSummary($assignment->fresh('examenes')));
        $repair = app(LegacyEmoOutcomeRepairService::class);
        $this->assertSame(1, $repair->preview()->count());
        $this->artisan('habilitacion-minera:repair-legacy-emo')
            ->expectsOutputToContain('Dry-run: no se modificaron datos.')
            ->assertSuccessful();
        $this->assertSame('OBSERVADO', $candidate->fresh()->estado, 'El comando no debe escribir sin --apply.');
        $this->artisan('habilitacion-minera:repair-legacy-emo', ['--apply' => true])
            ->expectsOutputToContain('Para aplicar debe indicar un actor existente y un motivo.')
            ->assertExitCode(2);
        $this->assertSame('OBSERVADO', $candidate->fresh()->estado);

        $this->artisan('habilitacion-minera:repair-legacy-emo', [
            '--apply' => true,
            '--actor' => $actor->id,
            '--motivo' => 'Correccion aprobada por SSOMA',
        ])->expectsOutputToContain('Reparados: 1.')->assertSuccessful();

        $this->assertSame('VIGENTE', $candidate->fresh()->estado);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $this->assertSame(12, $service->assignmentExamSummary($assignment->fresh('examenes'))['resueltos']);
        $audit = $assignment->historial()->where('observacion', 'like', 'Reparacion EMO legacy:%')->firstOrFail();
        $this->assertSame($actor->id, $audit->usuario_id);
        $this->assertStringContainsString('Correccion aprobada por SSOMA', $audit->observacion);
        $this->assertStringContainsString('"antes":{"estado":"OBSERVADO"', $audit->observacion);
        $this->assertStringContainsString('"despues":{"estado":"VIGENTE"', $audit->observacion);
        $this->assertSame(0, $repair->apply($actor, 'Segunda ejecucion')['reparados'], 'La reparacion debe ser idempotente.');
    }

    public function test_reparacion_legacy_omite_caso_ambiguo_si_el_ultimo_aprobado_no_tiene_prefijo_medico(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('EMO LEGACY AMBIGUO', [
            'categoria_operativa' => 'EMO',
        ]));
        $exam = $assignment->examenes->sole();
        $exam->forceFill(['estado' => 'OBSERVADO', 'resultado' => 'APROBADO'])->save();
        foreach ([1 => 'APTO CON OBSERVACIONES: CONTROL ANUAL', 2 => 'Revision medica manual'] as $number => $observation) {
            PersonalMinaExamenIntento::query()->create([
                'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id,
                'numero_intento' => $number, 'fecha_realizacion' => '2026-04-29', 'resultado' => 'APROBADO',
                'observacion' => $observation, 'usuario_registro_id' => $actor->id,
            ]);
        }

        $this->assertTrue(app(LegacyEmoOutcomeRepairService::class)->preview()->isEmpty());
        $this->assertSame('OBSERVADO', $exam->fresh()->estado);
    }

    public function test_bulk_locks_assignments_before_completing_existing_attempts(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar', 'registrar', 'programar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('BULK ORDEN '.Str::random(8));
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($assignment->examenes->sole(), [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => Carbon::today('America/Lima')->toDateString(),
        ], null, $actor);
        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => $assignment->personal->dni,
            'name' => $assignment->personal->nombre_completo, 'exam' => $exam->nombre,
            'date' => Carbon::today('America/Lima')->toDateString(), 'mine' => $assignment->mina->nombre,
            'result' => 'APTO', 'score' => null, 'expiry' => null, 'observation' => '', 'issues' => [],
        ]];
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $rows = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class)->plan($source);
        $this->assertSame([], $rows[0]['issues']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $bulk->apply(['token' => (string) Str::uuid(), 'source' => $source, 'rows' => $rows,
                'fingerprint' => $bulk->fingerprint($rows)], $actor);
            $locks = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'))->values();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $parentIndex = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `personal_mina`'));
        $attemptIndex = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `personal_mina_examen_intentos`'));
        $this->assertNotSame(false, $parentIndex);
        $this->assertNotSame(false, $attemptIndex);
        $this->assertLessThan($attemptIndex, $parentIndex);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
    }

    public function test_course_sync_rolls_back_attempt_and_habilitation_when_history_fails(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('SYNC ATOMICA '.Str::random(8));
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $workerExam = $assignment->examenes->firstOrFail();
        $record = $this->legacyCourseRecord($assignment->personal, $exam, $actor);
        $record->update(['mina_id' => $assignment->mina_id]);
        $before = $workerExam->getRawOriginal();
        $assignmentBefore = $assignment->fresh()->getRawOriginal();
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.\App\Models\PersonalMinaHistorial::class,
            fn () => throw new \RuntimeException('Simulated sync history failure'));

        try {
            $service->syncCourseRecordToMines($record->fresh(), $actor);
            $this->fail('Course sync must not leave an unaudited approval.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated sync history failure', $exception->getMessage());
        }

        $this->assertSame($before, $workerExam->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame(0, $workerExam->intentos()->count());
        $this->assertDatabaseHas('personal_curso_registros', ['id' => $record->id, 'mina_id' => $assignment->mina_id]);
    }

    public function test_assignment_refresh_rolls_back_expiration_and_state_when_history_fails(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('REFRESH ATOMICO '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->update([
            'estado' => 'APROBADO', 'resultado' => 'APROBADO',
            'fecha_vencimiento' => Carbon::today('America/Lima')->addYear()->toDateString(),
        ]);
        $before = $workerExam->fresh()->getRawOriginal();
        $assignmentBefore = $assignment->fresh()->getRawOriginal();
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.\App\Models\PersonalMinaHistorial::class,
            fn () => throw new \RuntimeException('Simulated refresh history failure'));

        try {
            $service->refreshAssignmentFromExams($assignment, $actor, false);
            $this->fail('Refresh must not leave a partial state change.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated refresh history failure', $exception->getMessage());
        }

        $this->assertSame($before, $workerExam->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
    }

    public function test_new_assignment_serializes_on_existing_worker_and_rejects_second_request(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('ASIGNACION SERIALIZADA');
        $payload = ['personal_id' => $worker->id, 'mina_id' => $mine->id];
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $service->assignMine($payload, $actor, false);
            $firstLock = collect(DB::getQueryLog())->pluck('query')
                ->first(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertNotNull($firstLock);
        $this->assertStringContainsString('from `personal`', $firstLock);
        try {
            $service->assignMine($payload, $actor, false);
            $this->fail('A second assignment request must not duplicate the relationship.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('mina_id', $exception->errors());
        }
        $this->assertSame(1, PersonalMina::query()->where('personal_id', $worker->id)->where('mina_id', $mine->id)->count());
    }

    public static function lockedAssignmentOperations(): array
    {
        return array_map(fn (string $operation): array => [$operation], [
            'schedule', 'result', 'complete', 'not_applicable', 'generate', 'update', 'deactivate',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lockedAssignmentOperations')]
    public function test_assignment_is_locked_before_child_writes(string $operation): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'programar', 'asignar', 'no_aplica']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('ORDEN LOCK '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->requisitoMina->update(['permite_no_aplica' => true]);
        if ($operation === 'complete') {
            $service->registerAttempt($workerExam, [
                'resultado' => 'PENDIENTE', 'fecha_programacion' => Carbon::today('America/Lima')->toDateString(),
            ], null, $actor);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            match ($operation) {
                'schedule' => $service->registerAttempt($workerExam, [
                    'resultado' => 'PENDIENTE', 'fecha_programacion' => Carbon::today('America/Lima')->toDateString(),
                ], null, $actor),
                'result' => $service->registerAttempt($workerExam, [
                    'resultado' => 'APROBADO', 'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
                ], null, $actor),
                'complete' => $service->completeScheduledAttempt($workerExam->intentos()->sole(), [
                    'resultado' => 'APROBADO', 'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
                ], null, $actor),
                'not_applicable' => $service->markExamNotApplicable($workerExam, [], $actor),
                'generate' => $service->generateRequiredExams($assignment, $actor),
                'update' => $service->updateAssignment($assignment, ['estado_habilitacion' => 'OBSERVADO'], $actor),
                'deactivate' => $service->deactivateAssignment($assignment, $actor),
            };
            $firstLock = collect(DB::getQueryLog())->pluck('query')
                ->first(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertNotNull($firstLock);
        $this->assertStringContainsString('from `personal_mina`', $firstLock);
    }

    public function test_generation_rolls_back_all_new_exams_if_one_creation_fails(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('BASE GENERACION '.Str::random(8)));
        foreach ([1, 2] as $order) {
            $service->storeRequirement([
                'mina_id' => $assignment->mina_id,
                'examen_id' => $this->createExam('GENERACION '.$order.' '.Str::random(8))->id,
                'obligatorio' => true, 'orden' => $order,
            ]);
        }
        $existingIds = $assignment->examenes()->pluck('id')->all();
        $created = 0;
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.PersonalMinaExamen::class,
            function () use (&$created): void {
                if (++$created === 2) {
                    throw new \RuntimeException('Simulated generation failure');
                }
            });

        try {
            $service->generateRequiredExams($assignment, $actor);
            $this->fail('Generation must not leave a partial set of new exams.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated generation failure', $exception->getMessage());
        }

        $this->assertSame(2, $created);
        $this->assertSame($existingIds, $assignment->examenes()->pluck('id')->all());
    }

    public function test_mining_expiration_uses_lima_day_when_server_is_already_tomorrow(): void
    {
        $this->useUtcClockBeforeLimaMidnight();
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('VENCE HOY LIMA '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01', 'fecha_vencimiento' => '2026-10-06',
        ], null, $actor);

        $this->assertSame('POR_VENCER', $workerExam->fresh()->estado);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $this->assertTrue($service->preservesCurrentApproval($workerExam->fresh(), 'DESAPROBADO'));
        $row = $service->upcomingExamExpirations()->first(fn (array $item): bool => $item['worker']->id === $assignment->personal_id);
        $this->assertNotNull($row);
        $this->assertSame(0, $row['dias_restantes']);
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_mining_assignment_and_schedule_counters_use_operational_day(): void
    {
        $this->useUtcClockBeforeLimaMidnight();
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['programar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('PROGRAMACION LIMA '.Str::random(8)));
        $service->registerAttempt($assignment->examenes->firstOrFail(), [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07',
        ], null, $actor);

        $this->assertSame('2026-10-06', $assignment->fecha_asignacion->toDateString());
        $row = $service->scheduledExamAttempts()->first(fn (array $item): bool => $item['worker']->id === $assignment->personal_id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['dias_para_programacion']);
    }

    public function test_tomorrow_schedule_cannot_be_completed_before_lima_midnight(): void
    {
        $this->useUtcClockBeforeLimaMidnight();
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'programar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('MANANA LIMA '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07',
        ], null, $actor);
        $pending = $workerExam->intentos()->sole();

        try {
            $service->completeScheduledAttempt($pending, [
                'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-06',
            ], null, $actor);
            $this->fail('Tomorrow in Lima is not an eligible schedule yet.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('fecha_programacion', $exception->errors());
        }

        $this->assertSame('PENDIENTE', $pending->fresh()->resultado);
        $this->assertNull($pending->fresh()->fecha_realizacion);
    }

    public function test_convalidation_window_includes_last_operational_day(): void
    {
        $this->useUtcClockBeforeLimaMidnight();
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'convalidar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('CONVALIDACION LIMA '.Str::random(8), ['permite_convalidacion' => true]);
        $origin = $this->assignmentWithExam($service, $actor, $exam)->examenes->firstOrFail();
        $target = $this->assignmentWithExam($service, $actor, $exam)->examenes->firstOrFail();
        $target->asignacion->update(['personal_id' => $origin->asignacion->personal_id]);
        $target->requisitoMina->update([
            'permite_convalidacion_mina' => true, 'convalidar_desde_otras_minas' => true,
            'fecha_inicio_convalidacion' => '2026-10-01', 'fecha_fin_convalidacion' => '2026-10-06',
        ]);
        $service->registerAttempt($origin, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01', 'fecha_vencimiento' => '2026-10-06',
        ], null, $actor);

        $options = $service->convalidationOptions($target);
        $this->assertTrue($options['available']);
        $this->assertSame($origin->id, $options['options'][0]['id']);
        $service->convalidateExam($target, $origin->id, [], $actor);
        $this->assertSame('POR_VENCER', $target->fresh()->estado);
        $this->assertTrue($target->fresh()->es_convalidado);
    }

    public static function assignmentMutations(): array
    {
        return ['update' => ['update'], 'deactivate' => ['deactivate']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assignmentMutations')]
    public function test_assignment_mutation_rolls_back_when_history_fails(string $operation): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('ASIGNACION ATOMICA '.Str::random(8)));
        $before = $assignment->fresh()->getRawOriginal();
        $historyCount = $assignment->historial()->count();
        $examBefore = $assignment->examenes->firstOrFail()->getRawOriginal();
        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: '.\App\Models\PersonalMinaHistorial::class,
            fn () => throw new \RuntimeException('Simulated assignment history failure'),
        );

        try {
            if ($operation === 'update') {
                $service->updateAssignment($assignment, [
                    'estado_habilitacion' => 'OBSERVADO', 'observacion' => 'Revision operativa',
                ], $actor);
            } else {
                $service->deactivateAssignment($assignment, $actor, 'Retiro operativo');
            }
            $this->fail('A history failure must abort the assignment change.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated assignment history failure', $exception->getMessage());
        }

        $this->assertSame($before, $assignment->fresh()->getRawOriginal());
        $this->assertSame($historyCount, $assignment->historial()->count());
        $this->assertSame($examBefore, $assignment->examenes()->sole()->getRawOriginal());
    }

    public function test_assignment_update_rolls_back_history_when_notification_fails(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('AVISO ASIGNACION '.Str::random(8)));
        $before = $assignment->fresh()->getRawOriginal();
        $historyCount = $assignment->historial()->count();
        $notifications = \Mockery::mock(OperationalNotificationService::class);
        $notifications->shouldReceive('habilitacionAsignacion')->once()
            ->andThrow(new \RuntimeException('Simulated assignment notification failure'));
        $service = new PersonalMinaHabilitacionService($notifications);

        try {
            $service->updateAssignment($assignment, ['estado_habilitacion' => 'OBSERVADO'], $actor);
            $this->fail('A notification failure must abort the assignment change.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated assignment notification failure', $exception->getMessage());
        }

        $this->assertSame($before, $assignment->fresh()->getRawOriginal());
        $this->assertSame($historyCount, $assignment->historial()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assignmentMutations')]
    public function test_assignment_mutation_keeps_actor_and_history(string $operation): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('AUDITORIA ASIGNACION '.Str::random(8)));
        $historyCount = $assignment->historial()->count();

        if ($operation === 'update') {
            $result = $service->updateAssignment($assignment, [
                'estado_habilitacion' => 'OBSERVADO', 'observacion' => 'Revision operativa',
            ], $actor);
            $this->assertSame('OBSERVADO', $result->estado_habilitacion);
            $this->assertTrue($result->activo);
        } else {
            $result = $service->deactivateAssignment($assignment, $actor, 'Retiro operativo');
            $this->assertFalse($result->activo);
            $this->assertSame('EN_PROCESO', $result->estado_habilitacion);
        }

        $this->assertSame($actor->id, $result->usuario_actualizacion_id);
        $this->assertSame($historyCount + 1, $assignment->historial()->count());
        $this->assertSame($actor->id, $assignment->historial()->orderByDesc('created_at')->first()->usuario_id);
        $this->assertSame(1, $assignment->examenes()->count());
    }

    public static function inactiveExamOperations(): array
    {
        $cases = [];
        foreach (['assignment', 'mine', 'requirement', 'catalog'] as $inactive) {
            foreach (['schedule', 'result', 'complete', 'not_applicable'] as $operation) {
                $cases[$inactive.' '.$operation] = [$inactive, $operation];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inactiveExamOperations')]
    public function test_inactive_context_rejects_exam_writes(string $inactive, string $operation): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'programar', 'no_aplica']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('CONTEXTO INACTIVO '.Str::random(8));
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->requisitoMina->update(['permite_no_aplica' => true]);
        if ($operation === 'complete') {
            $service->registerAttempt($workerExam, [
                'resultado' => 'PENDIENTE',
                'fecha_programacion' => Carbon::today('America/Lima')->toDateString(),
            ], null, $actor);
        }
        match ($inactive) {
            'assignment' => $assignment->update(['activo' => false]),
            'mine' => $assignment->mina->update(['estado' => 'INACTIVO']),
            'requirement' => $workerExam->requisitoMina->update(['activo' => false]),
            'catalog' => $exam->update(['activo' => false]),
        };
        $before = $workerExam->fresh()->getRawOriginal();
        $attempts = $workerExam->intentos()->get()->toArray();

        try {
            match ($operation) {
                'schedule' => $service->registerAttempt($workerExam, [
                    'resultado' => 'PENDIENTE', 'fecha_programacion' => Carbon::today('America/Lima')->toDateString(),
                ], null, $actor),
                'result' => $service->registerAttempt($workerExam, [
                    'resultado' => 'APROBADO', 'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
                ], null, $actor),
                'complete' => $service->completeScheduledAttempt($workerExam->intentos()->sole(), [
                    'resultado' => 'APROBADO', 'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
                ], null, $actor),
                'not_applicable' => $service->markExamNotApplicable($workerExam, [], $actor),
            };
            $this->fail('Inactive context must reject writes.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('examen', $exception->errors());
        }
        $this->assertSame($before, $workerExam->fresh()->getRawOriginal());
        $this->assertSame($attempts, $workerExam->intentos()->get()->toArray());
    }

    public function test_not_applicable_rolls_back_exam_and_assignment_on_failure(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['no_aplica']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('NO APLICA ATOMICO '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->requisitoMina->update(['permite_no_aplica' => true]);
        $historyCount = $assignment->historial()->count();
        $notifications = \Mockery::mock(OperationalNotificationService::class);
        $notifications->shouldReceive('habilitacionAsignacion')->once()->andThrow(new \RuntimeException('Simulated refresh failure'));
        $service = new PersonalMinaHabilitacionService($notifications);

        try {
            $service->markExamNotApplicable($workerExam, [], $actor);
            $this->fail('The refresh failure must abort the operation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated refresh failure', $exception->getMessage());
        }
        $this->assertSame('PENDIENTE', $workerExam->fresh()->estado);
        $this->assertSame('EN_PROCESO', $assignment->fresh()->estado_habilitacion);
        $this->assertSame($historyCount, $assignment->historial()->count());
    }

    public function test_readded_requirement_reuses_exam_and_preserves_attempt_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'programar', 'configurar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('REQUISITO REINCORPORADO '.Str::random(8));
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-06',
        ], null, $actor);
        $pending = $workerExam->intentos()->sole();
        $oldRequirement = $workerExam->requisitoMina;
        $oldSnapshot = $workerExam->fresh()->nota_minima_snapshot;
        $service->deactivateRequirement($oldRequirement, $actor);
        $newRequirement = $service->storeRequirement([
            'mina_id' => $assignment->mina_id, 'examen_id' => $exam->id, 'obligatorio' => true,
        ], $actor);
        $service->generateRequiredExams($assignment, $actor);
        $this->assertSame($newRequirement->id, $workerExam->fresh()->mina_requisito_id);
        $this->assertSame($oldSnapshot, $workerExam->fresh()->nota_minima_snapshot);
        $this->assertSame(1, $assignment->examenes()->count());
        $this->assertSame($pending->id, $workerExam->intentos()->sole()->id);
        $service->completeScheduledAttempt($pending, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-06',
        ], null, $actor);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $this->assertSame('APROBADO', $pending->fresh()->resultado);
        $history = $assignment->historial()->where('observacion', 'like', 'Requisito reincorporado:%')->sole();
        $this->assertStringContainsString($oldRequirement->id, $history->observacion);
        $this->assertStringContainsString($newRequirement->id, $history->observacion);
    }

    public function test_early_renewal_notifies_the_new_schedule_without_replacing_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'programar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('AVISO RENOVACION '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01', 'fecha_vencimiento' => '2026-10-26',
        ], null, $actor);
        $notifications = \Mockery::mock(\App\Modules\Notificaciones\Services\NotificationService::class);
        $notifications->shouldReceive('emit')->once()->withArgs(function (string $type, array $context) use ($workerExam): bool {
            $this->assertSame('habilitacion_examen_programado', $type);
            $this->assertSame('2026-10-10', $context['payload']['fecha_programacion']);
            $this->assertStringContainsString('10/10/2026', $context['message']);
            $this->assertSame('habilitacion_examen_programado:'.$workerExam->id.':20261010', $context['dedupe_key']);

            return true;
        })->andReturnNull();
        $service = new PersonalMinaHabilitacionService(new OperationalNotificationService($notifications));
        $service->registerAttempt($workerExam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-10',
        ], null, $actor);
        $this->assertSame('APROBADO', $workerExam->fresh()->resultado);
        $this->assertSame('2026-10-01', $workerExam->fresh()->fecha_realizacion->toDateString());
    }

    public function test_registro_de_intento_bloquea_examen_antes_de_asignar_numero(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('SERIALIZACION ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $service->registerAttempt($workerExam, [
                'resultado' => 'DESAPROBADO',
                'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            ], null, $actor);
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertTrue($queries->contains(fn (string $sql): bool =>
            str_contains($sql, 'personal_mina_examenes') && str_contains(strtolower($sql), 'for update')));
    }

    public function test_failed_early_renewal_keeps_current_approval_until_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'programar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('RENOVACION ANTICIPADA '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01',
            'fecha_vencimiento' => '2026-10-26', 'observacion' => 'APTO CON RESTRICCION: USO DE LENTES',
        ], null, $actor);
        $service->registerAttempt($workerExam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-06',
        ], null, $actor);
        $this->assertSame('POR_VENCER', $workerExam->fresh()->estado);
        $pending = $workerExam->intentos()->where('resultado', 'PENDIENTE')->sole();
        $service->completeScheduledAttempt($pending, [
            'resultado' => 'DESAPROBADO', 'fecha_realizacion' => '2026-10-06',
        ], null, $actor);
        $this->assertSame('APROBADO', $workerExam->fresh()->resultado);
        $this->assertSame('2026-10-01', $workerExam->fresh()->fecha_realizacion->toDateString());
        $this->assertSame('2026-10-26', $workerExam->fresh()->fecha_vencimiento->toDateString());
        $this->assertStringContainsString('USO DE LENTES', $workerExam->fresh()->observacion);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $this->assertSame('DESAPROBADO', $pending->fresh()->resultado);

        $this->travelTo(Carbon::parse('2026-10-27 10:00:00'));
        $service->syncTemporalStatuses();
        $this->assertSame('VENCIDO', $workerExam->fresh()->estado);
        $this->assertNotSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-27', 'fecha_vencimiento' => '2027-10-27',
        ], null, $actor);
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $this->assertSame(3, $workerExam->intentos()->count());
    }

    public function test_course_sync_failed_renewal_preserves_effective_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('CURSO RENOVACION '.Str::random(8));
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01', 'fecha_vencimiento' => '2026-10-26',
        ], null, $actor);
        $record = \App\Models\PersonalCursoRegistro::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $assignment->personal_id,
            'examen_id' => $exam->id, 'mina_id' => $assignment->mina_id,
            'fecha_realizacion' => '2026-10-06', 'resultado' => 'DESAPROBADO',
            'catalogo_snapshot' => [], 'usuario_registro_id' => $actor->id, 'origen' => 'Excel de prueba',
        ]);
        $service->syncCourseRecordToMines($record, $actor);
        $this->assertSame('APROBADO', $workerExam->fresh()->resultado);
        $this->assertSame('2026-10-26', $workerExam->fresh()->fecha_vencimiento->toDateString());
        $this->assertSame('HABILITADO', $assignment->fresh()->estado_habilitacion);
        $this->assertSame(2, $workerExam->intentos()->count());
    }

    public static function convalidationObservations(): array
    {
        return [[''], ['Verificado por el area'], [str_repeat('x', 5000)]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('convalidationObservations')]
    public function test_convalidation_preserves_origin_medical_restrictions(string $observation): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar', 'convalidar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $this->createExam('RESTRICCION CONSERVADA '.Str::random(8), ['permite_convalidacion' => true]);
        $origin = $this->assignmentWithExam($service, $actor, $exam)->examenes->firstOrFail();
        $target = $this->assignmentWithExam($service, $actor, $exam)->examenes->firstOrFail();
        $target->asignacion->update(['personal_id' => $origin->asignacion->personal_id]);
        $target->requisitoMina->update(['permite_convalidacion_mina' => true, 'convalidar_desde_otras_minas' => true]);
        $service->registerAttempt($origin, [
            'resultado' => 'APTO CON RESTRICCION', 'observacion' => 'USO DE LENTES',
            'fecha_realizacion' => '2026-10-01', 'fecha_vencimiento' => '2027-10-01',
        ], null, $actor);
        if (strlen($observation) === 5000) {
            try {
                $service->convalidateExam($target, $origin->id, ['observacion' => $observation], $actor);
                $this->fail('Restrictions must not be truncated to fit the observation.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('observacion', $exception->errors());
            }
            $this->assertSame('PENDIENTE', $target->fresh()->estado);

            return;
        }
        $service->convalidateExam($target, $origin->id, ['observacion' => $observation], $actor);
        $this->assertSame('CONVALIDADO', $target->fresh()->estado);
        $this->assertStringContainsString('APTO CON RESTRICCION', $target->fresh()->observacion);
        $this->assertStringContainsString('USO DE LENTES', $target->fresh()->observacion);
        $this->assertStringContainsString($observation, $target->fresh()->observacion);
        $this->assertStringContainsString('USO DE LENTES', $target->fresh()->contexto_convalidacion_json['observacion_origen']);
    }

    public function test_new_actual_attempt_clears_old_convalidation_metadata(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('CONVALIDACION VENCIDA '.Str::random(8)));
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->update([
            'estado' => 'VENCIDO', 'resultado' => 'APROBADO', 'fecha_vencimiento' => '2026-10-01',
            'es_convalidado' => true, 'mina_origen_convalidacion_id' => $assignment->mina_id,
            'fecha_aprobacion_origen' => '2025-10-01', 'fecha_convalidacion' => '2025-10-01 10:00:00',
            'usuario_convalidacion_id' => $actor->id, 'contexto_convalidacion_json' => ['historico' => true],
        ]);
        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-06', 'fecha_vencimiento' => '2027-10-06',
        ], null, $actor);
        $fresh = $workerExam->fresh();
        $this->assertFalse($fresh->es_convalidado);
        foreach (['examen_origen_convalidado_id', 'mina_origen_convalidacion_id', 'fecha_aprobacion_origen',
            'fecha_convalidacion', 'usuario_convalidacion_id', 'contexto_convalidacion_json'] as $field) {
            $this->assertNull($fresh->{$field}, $field);
        }
        $history = $assignment->historial()->where('observacion', 'like', 'Nueva realizacion:%')->sole();
        $this->assertSame($actor->id, $history->usuario_id);
        $this->assertStringContainsString($assignment->mina_id, $history->observacion);
        $this->assertStringContainsString('"historico":true', $history->observacion);
    }

    public function test_intentos_manuales_conservan_numeros_consecutivos_aun_si_superan_el_maximo(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('TRES INTENTOS ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();

        for ($number = 1; $number <= 3; $number++) {
            $service->registerAttempt($workerExam, [
                'resultado' => 'DESAPROBADO',
                'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            ], null, $actor);
        }

        $this->assertSame([1, 2, 3], PersonalMinaExamenIntento::query()
            ->where('personal_mina_examen_id', $workerExam->id)
            ->orderBy('numero_intento')
            ->pluck('numero_intento')->map(fn ($number) => (int) $number)->all());
        $this->assertSame(2, (int) $workerExam->fresh()->max_intentos_snapshot);
    }

    public function test_fallo_al_guardar_intento_nuevo_elimina_archivo_subido_y_revierte_datos(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('ARCHIVO FALLIDO ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();
        $invalidActor = clone $actor;
        $invalidActor->id = (string) Str::uuid();

        try {
            $service->registerAttempt($workerExam, [
                'resultado' => 'APROBADO',
                'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            ], UploadedFile::fake()->create('resultado.pdf', 12, 'application/pdf'), $invalidActor);
            $this->fail('El usuario inexistente debe impedir el guardado.');
        } catch (\Illuminate\Database\QueryException) {
            // The database rejects the actor after the file was stored.
        }

        $this->assertSame([], Storage::disk('local')->allFiles('habilitacion-minera'));
        $this->assertDatabaseCount('personal_mina_examen_intentos', 0);
        $this->assertSame(PersonalMinaExamen::ESTADO_PENDIENTE, $workerExam->fresh()->estado);
    }

    public function test_fallo_al_completar_programado_elimina_archivo_y_conserva_programacion(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['programar', 'registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('PROGRAMACION FALLIDA ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'PENDIENTE',
            'fecha_programacion' => Carbon::yesterday('America/Lima')->toDateString(),
        ], null, $actor);
        $attempt = PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $workerExam->id)->sole();
        $invalidActor = clone $actor;
        $invalidActor->id = (string) Str::uuid();

        try {
            $service->completeScheduledAttempt($attempt, [
                'resultado' => 'APROBADO',
                'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            ], UploadedFile::fake()->create('resultado.pdf', 12, 'application/pdf'), $invalidActor);
            $this->fail('El usuario inexistente debe impedir completar la programacion.');
        } catch (\Illuminate\Database\QueryException) {
            // The database rejects the actor after the file was stored.
        }

        $this->assertSame([], Storage::disk('local')->allFiles('habilitacion-minera'));
        $this->assertSame(PersonalMinaExamenIntento::RESULTADO_PENDIENTE, $attempt->fresh()->resultado);
        $this->assertSame(PersonalMinaExamen::ESTADO_PROGRAMADO, $workerExam->fresh()->estado);
    }

    public function test_no_aprueba_examen_manual_sin_fecha_de_realizacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('EXAMEN SIN FECHA ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();

        try {
            $service->registerAttempt($workerExam, ['resultado' => 'APROBADO'], null, $actor);
            $this->fail('Un resultado aprobado debe exigir fecha de realizacion.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('fecha_realizacion', $exception->errors());
        }

        $this->assertDatabaseCount('personal_mina_examen_intentos', 0);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_no_registra_resultado_manual_con_fecha_futura(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('EXAMEN FUTURO ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();

        try {
            $service->registerAttempt($workerExam, [
                'resultado' => 'APROBADO',
                'fecha_realizacion' => Carbon::today('America/Lima')->addDay()->toDateString(),
            ], null, $actor);
            $this->fail('No debe registrarse un resultado que todavia no ocurrio.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('fecha_realizacion', $exception->errors());
        }

        $this->assertDatabaseCount('personal_mina_examen_intentos', 0);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_no_completa_examen_programado_con_fecha_de_realizacion_futura(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['programar', 'registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('PROGRAMADO FUTURO ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => 'PENDIENTE',
            'fecha_programacion' => Carbon::yesterday('America/Lima')->toDateString(),
        ], null, $actor);
        $attempt = PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $workerExam->id)->sole();

        try {
            $service->completeScheduledAttempt($attempt, [
                'resultado' => 'APROBADO',
                'fecha_realizacion' => Carbon::today('America/Lima')->addDay()->toDateString(),
            ], null, $actor);
            $this->fail('No debe completarse un examen con fecha futura.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('fecha_realizacion', $exception->errors());
        }

        $this->assertSame(PersonalMinaExamenIntento::RESULTADO_PENDIENTE, $attempt->fresh()->resultado);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_examen_aprobado_que_ya_vencio_puede_rendirse_otra_vez(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('RENOVACION ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();
        $firstDate = Carbon::today('America/Lima')->subMonths(2)->toDateString();

        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO',
            'fecha_realizacion' => $firstDate,
            'fecha_vencimiento' => Carbon::yesterday('America/Lima')->toDateString(),
        ], null, $actor);
        $this->assertSame(PersonalMinaExamen::ESTADO_VENCIDO, $workerExam->fresh()->estado);

        $service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO',
            'fecha_realizacion' => Carbon::today('America/Lima')->toDateString(),
            'fecha_vencimiento' => Carbon::today('America/Lima')->addYear()->toDateString(),
        ], null, $actor);

        $this->assertSame(2, PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $workerExam->id)->count());
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_panel_recalcula_vencido_antes_de_mostrar_opcion_de_repetir(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['ver', 'registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('ESTADO VENCIDO ' . Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_APROBADO,
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_vencimiento' => Carbon::yesterday('America/Lima')->toDateString(),
        ])->save();

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $assignment->personal_id]))
            ->assertOk()
            ->assertSee('"estado":"VENCIDO"', false);

        $this->assertSame(PersonalMinaExamen::ESTADO_VENCIDO, $workerExam->fresh()->estado);
    }

    public function test_no_habilita_si_falta_generar_un_requisito_obligatorio_activo(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina requisitos completos');
        $firstExam = $this->createExam('Primer requisito '.Str::random(6));
        $secondExam = $this->createExam('Segundo requisito '.Str::random(6));

        foreach ([$firstExam, $secondExam] as $exam) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $missingExam = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $secondExam->id)
            ->sole();
        $missingExam->delete();

        $firstWorkerExam = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $firstExam->id)
            ->sole();
        $service->registerAttempt($firstWorkerExam, [
            'fecha_realizacion' => '2026-09-20',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $service->calculatedAssignmentStatus($assignment));

        $this->assertSame(1, $service->generateRequiredExams($assignment, $actor));
        $secondWorkerExam = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $secondExam->id)
            ->sole();
        $service->registerAttempt($secondWorkerExam, [
            'fecha_realizacion' => '2026-09-20',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_tarea_diaria_actualiza_vencimientos_y_deja_historial_de_sistema(): void
    {
        Carbon::setTestNow('2026-09-21 00:10:00');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['ver', 'actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('Vigencia automatica '.Str::random(6)));
        $workerExam = $assignment->examenes->firstOrFail();
        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
            'fecha_realizacion' => '2025-09-20',
            'fecha_vencimiento' => '2026-09-20',
        ])->save();
        $assignment->forceFill([
            'estado' => PersonalMina::ESTADO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'fecha_habilitacion' => '2025-09-20',
        ])->save();

        $this->artisan('habilitacion-minera:sincronizar-vigencias')
            ->expectsOutputToContain('Examenes actualizados: 1')
            ->assertSuccessful();

        $this->assertSame(PersonalMinaExamen::ESTADO_VENCIDO, $workerExam->fresh()->estado);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_historial', [
            'personal_mina_id' => $assignment->id,
            'estado_anterior' => PersonalMina::ESTADO_HABILITADO,
            'estado_nuevo' => PersonalMina::ESTADO_EN_PROCESO,
            'usuario_id' => null,
        ]);

        // Even before the persisted state is repaired, the board must use the
        // current exam facts and display this assignment as in process.
        $assignment->forceFill([
            'estado' => PersonalMina::ESTADO_NO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_NO_HABILITADO,
        ])->save();
        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $assignment->personal_id]))
            ->assertOk()
            ->assertSee('data-visual-state="EN_PROCESO"', false)
            ->assertSee('class="mine-tile warn"', false)
            ->assertSee('En regularizacion: tiene examenes vencidos por actualizar.')
            ->assertDontSee('data-visual-state="NO_HABILITADO"', false);
    }

    public function test_busqueda_ajax_de_trabajadores_es_selectiva_y_busca_documento_nombre_y_ocupacion(): void
    {
        $userId = $this->createUser(['habilitacion_minera' => ['ver']]);
        $byDocument = $this->createPersonalWithDocument('73456789', [
            'nombre_completo' => 'PERSONA DOCUMENTO OBJETIVO',
            'ocupacion' => 'TECNICO ELECTRICO',
        ]);
        $byOccupation = $this->createPersonalWithDocument('73567890', [
            'nombre_completo' => 'PERSONA OCUPACION OBJETIVO',
            'ocupacion' => 'PERFORISTA ESPECIALIZADO',
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $documentResponse = $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'vista' => 'worker',
                'trabajador' => '73456',
            ]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee($byDocument->nombre_completo)
            ->assertDontSee($byOccupation->nombre_completo);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'vista' => 'worker',
                'trabajador' => 'PERFORISTA',
            ]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee($byOccupation->nombre_completo);

        $this->assertStringContainsString("worker: ['.mine-view-switch', '.mine-mines-card', '.mine-worker-card']", $documentResponse->getContent());
        $this->assertStringContainsString("form.dataset.loadingSubmitted === 'true'", $documentResponse->getContent());
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'examen_minero_precios')));
    }

    public function test_bulk_course_result_applies_only_to_the_selected_mine(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar', 'registrar', 'programar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $sharedExam = $this->createExam('CURSO COMPARTIDO '.Str::random(6), [
            'tiene_vigencia' => true,
            'vigencia_dias' => 365,
        ]);
        $otherExam = $this->createExam('CURSO DISTINTO '.Str::random(6));
        $assignments = collect();

        $mines = [$this->createMine('Mina compartida uno'), $this->createMine('Mina compartida dos')];
        foreach ($mines as $mine) {
            $service->storeRequirement(['mina_id' => $mine->id, 'examen_id' => $sharedExam->id, 'obligatorio' => true]);
            $assignments->push($service->assignMine([
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ], $actor));
        }
        $this->grantMineScope($actor, $mines[0]);
        $differentMine = $this->createMine('Mina curso distinto');
        $service->storeRequirement(['mina_id' => $differentMine->id, 'examen_id' => $otherExam->id, 'obligatorio' => true]);
        $differentAssignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $differentMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $sharedExam->nombre, 'date' => '2026-01-10',
            'mine' => $mines[0]->nombre,
            'result' => 'APTO CON RESTRICCION', 'score' => null, 'expiry' => '2027-01-10',
            'observation' => 'USO DE LENTES', 'issues' => [],
        ]];
        $previewService = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $rows = $previewService->plan($source);
        $summary = $bulk->apply([
            'token' => (string) Str::uuid(), 'source' => $source, 'rows' => $rows,
            'fingerprint' => $bulk->fingerprint($rows),
        ], $actor);

        $record = \App\Models\PersonalCursoRegistro::query()->where('personal_id', $worker->id)->sole();
        $this->assertSame(1, $summary['minas_actualizadas']);
        $this->assertSame($mines[0]->id, $record->mina_id);
        $this->assertSame('APTO CON RESTRICCION: USO DE LENTES', $record->observacion);
        $targetExam = PersonalMinaExamen::query()->where('personal_mina_id', $assignments[0]->id)->sole();
        $this->assertSame($sharedExam->id, $targetExam->examen_id);
        $this->assertSame('APROBADO', $targetExam->resultado);
        $this->assertStringContainsString('USO DE LENTES', $targetExam->observacion);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignments[0]->fresh()->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examen_intentos', [
            'personal_mina_examen_id' => $targetExam->id,
            'personal_curso_registro_id' => $record->id,
        ]);
        $otherMineExam = PersonalMinaExamen::query()->where('personal_mina_id', $assignments[1]->id)->sole();
        $this->assertSame('PENDIENTE', $otherMineExam->estado);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignments[1]->fresh()->estado_habilitacion);
        $this->assertSame('PENDIENTE', PersonalMinaExamen::query()
            ->where('personal_mina_id', $differentAssignment->id)->sole()->estado);

        $repeatedRows = $previewService->plan($source);
        $this->assertSame('unchanged', $repeatedRows[0]['disposition']);
        $this->assertSame(0, $repeatedRows[0]['mines_pending_sync']);
        $this->assertSame(1, PersonalMinaExamenIntento::query()
            ->where('personal_curso_registro_id', $record->id)->count());
    }

    public function test_legacy_course_without_mine_is_not_applied_when_assigning_two_mines(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO SIN ORIGEN '.Str::random(6));
        $record = $this->legacyCourseRecord($worker, $exam, $actor);
        $service = app(PersonalMinaHabilitacionService::class);

        foreach ([$this->createMine('Destino uno'), $this->createMine('Destino dos')] as $mine) {
            $service->storeRequirement(['mina_id' => $mine->id, 'examen_id' => $exam->id]);
            $assignment = $service->assignMine([
                'personal_id' => $worker->id, 'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ], $actor);
            $this->assertSame('PENDIENTE', $assignment->examenes->sole()->estado);
            $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        }

        $this->assertSame(0, $record->intentosMineros()->count());
        $this->assertNull($record->fresh()->mina_id);
        $this->assertSame('APROBADO', $record->fresh()->resultado);
    }

    public function test_direct_sync_rejects_course_without_origin_mine_and_preserves_history(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['registrar']]));
        $record = $this->legacyCourseRecord($this->createPersonal(), $this->createExam('ORIGEN AUSENTE '.Str::random(6)), $actor);

        try {
            app(PersonalMinaHabilitacionService::class)->syncCourseRecordToMines($record, $actor);
            $this->fail('A course without an origin mine must require review.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
            $this->assertStringContainsString('mina de origen', $exception->errors()['archivo'][0]);
        }
        $this->assertSame(0, $record->intentosMineros()->count());
        $this->assertNull($record->fresh()->mina_id);
    }

    public function test_bulk_never_offers_legacy_course_as_automatic_multi_mine_sync(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('PREVIEW SIN ORIGEN '.Str::random(6));
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Mina de preview');
        $service->storeRequirement(['mina_id' => $mine->id, 'examen_id' => $exam->id]);
        $service->assignMine([
            'personal_id' => $worker->id, 'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $record = $this->legacyCourseRecord($worker, $exam, $actor);
        $rows = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class)->validatePlan([[
            'personal_id' => $worker->id, 'examen_id' => $exam->id, 'mina_id' => $mine->id,
            'type' => 'Resultado', 'date' => '2026-01-10', 'result' => 'APROBADO',
            'score' => null, 'expiry' => null, 'observation' => '', 'matched_schedule' => null,
            'issues' => [], 'categories' => [], 'existing_attempt_ids' => ['curso:'.$record->id],
            'action' => 'Resultado existente: omitir duplicado',
        ]], collect([$exam->id => $exam]));

        $this->assertSame('issues', $rows[0]['disposition']);
        $this->assertSame(0, $rows[0]['mines_pending_sync']);
        $this->assertFalse($rows[0]['mine_sync_only']);
        $this->assertSame([], $rows[0]['sync_global_ids']);
        $this->assertContains('historial', $rows[0]['categories']);
    }

    public function test_bulk_review_rejects_a_course_not_configured_for_the_selected_mine(): void
    {
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO SIN REQUISITO '.Str::random(6));
        $mine = $this->createMine('Mina sin curso configurado');
        $rows = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class)->plan([[
            'sheet' => 'notas',
            'line' => 2,
            'type' => 'Resultado',
            'dni' => $worker->dni,
            'name' => $worker->nombre_completo,
            'exam' => $exam->nombre,
            'mine' => $mine->nombre,
            'date' => '2026-01-10',
            'result' => 'APTO',
            'score' => null,
            'expiry' => null,
            'observation' => '',
            'issues' => [],
        ]]);

        $this->assertSame('issues', $rows[0]['disposition']);
        $this->assertContains('mina', $rows[0]['categories']);
        $this->assertContains(
            'El curso o EMO no esta configurado como requisito activo para la mina indicada.',
            $rows[0]['issues'],
        );
        $this->assertFalse(\App\Models\PersonalCursoRegistro::query()
            ->where('personal_id', $worker->id)->exists());
    }

    public function test_course_for_a_mine_is_applied_when_worker_is_later_assigned_to_that_mine(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar', 'registrar', 'programar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO PARA MINA FUTURA '.Str::random(6));
        $mine = $this->createMine('Mina asignada despues');
        $service = app(PersonalMinaHabilitacionService::class);
        $service->storeRequirement(['mina_id' => $mine->id, 'examen_id' => $exam->id, 'obligatorio' => true]);
        $this->grantMineScope($actor, $mine);
        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $exam->nombre, 'date' => '2026-01-10',
            'mine' => $mine->nombre, 'result' => 'APTO', 'score' => null, 'expiry' => null, 'observation' => '', 'issues' => [],
        ]];
        $previewService = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $rows = $previewService->plan($source);
        $bulk->apply(['token' => (string) Str::uuid(), 'source' => $source, 'rows' => $rows,
            'fingerprint' => $bulk->fingerprint($rows)], $actor);
        foreach (['2026-02-10', '2026-03-10'] as $date) {
            $nextSource = [array_replace($source[0], ['date' => $date])];
            $nextRows = $previewService->plan($nextSource);
            $bulk->apply(['token' => (string) Str::uuid(), 'source' => $nextSource, 'rows' => $nextRows,
                'fingerprint' => $bulk->fingerprint($nextRows)], $actor);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $workerExam = PersonalMinaExamen::query()->where('personal_mina_id', $assignment->id)->sole();
        $this->assertSame('APROBADO', $workerExam->resultado);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
        $this->assertSame(3, $workerExam->intentos()->count());
        $this->assertSame(3, $workerExam->intentos()->whereNotNull('personal_curso_registro_id')->count());
    }

    public function test_review_offers_to_sync_a_preexisting_course_record_that_has_not_reached_its_mine(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar', 'registrar', 'programar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO PENDIENTE DE SINCRONIZAR '.Str::random(6));
        $mine = $this->createMine('Mina pendiente de sincronizar');
        $service = app(PersonalMinaHabilitacionService::class);
        $service->storeRequirement(['mina_id' => $mine->id, 'examen_id' => $exam->id, 'obligatorio' => true]);
        $this->grantMineScope($actor, $mine);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $record = \App\Models\PersonalCursoRegistro::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $worker->id, 'examen_id' => $exam->id,
            'mina_id' => $mine->id,
            'fecha_realizacion' => '2026-01-10', 'resultado' => 'APROBADO', 'nota' => null,
            'observacion' => null, 'catalogo_snapshot' => [], 'usuario_registro_id' => $actor->id,
            'origen' => 'historial anterior a sincronizacion',
        ]);
        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $exam->nombre, 'date' => '2026-01-10',
            'mine' => $mine->nombre,
            'result' => 'APTO', 'score' => null, 'expiry' => null, 'observation' => '', 'issues' => [],
        ]];
        $previewService = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $rows = $previewService->plan($source);

        $this->assertSame('changes', $rows[0]['disposition']);
        $this->assertTrue($rows[0]['mine_sync_only']);
        $this->assertSame(1, $rows[0]['mines_pending_sync']);
        $summary = $bulk->apply(['token' => (string) Str::uuid(), 'source' => $source, 'rows' => $rows,
            'fingerprint' => $bulk->fingerprint($rows)], $actor);

        $this->assertSame(1, $summary['minas_actualizadas']);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examen_intentos', ['personal_curso_registro_id' => $record->id]);
        $this->assertSame('unchanged', $previewService->plan($source)[0]['disposition']);
    }

    public function test_bulk_courses_preserve_restriction_and_do_not_assign_mines_or_change_personal(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera'=>['importar','programar','registrar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO BULK '.Str::random(6));
        $mine = $this->createMine('Mina bulk sin asignacion');
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id, 'examen_id' => $exam->id, 'obligatorio' => true,
        ]);
        $this->grantMineScope($actor, $mine);
        $base = ['sheet'=>'programacion','line'=>2,'type'=>'Programacion','dni'=>$worker->dni,
            'name'=>$worker->nombre_completo,'exam'=>$exam->nombre,'date'=>'2026-01-10','result'=>'','score'=>null,
            'mine'=>$mine->nombre,'expiry'=>null,'observation'=>'','issues'=>[]];
        $source = [$base, array_replace($base, ['sheet'=>'notas','type'=>'Resultado','result'=>'APTO CON RESTRICCION',
            'expiry'=>'2027-01-10','observation'=>'USO DE LENTES'])];
        $previewService = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $rows = $previewService->plan($source);
        $this->assertSame(['unchanged','changes'], array_column($rows, 'disposition'));
        $preview = ['token'=>(string) Str::uuid(),'source'=>$source,'rows'=>$rows,'fingerprint'=>$bulk->fingerprint($rows)];
        $before = $worker->getRawOriginal();
        $summary = $bulk->apply($preview, $actor);
        $this->assertSame(1, $summary['creados']);
        $stored = \App\Models\PersonalCursoRegistro::query()->where('personal_id',$worker->id)->sole();
        $this->assertSame('APROBADO', $stored->resultado);
        $this->assertSame('APTO CON RESTRICCION: USO DE LENTES', $stored->observacion);
        $this->assertSame('2026-01-10', $stored->fecha_programacion->toDateString());
        $this->assertSame($before, $worker->fresh()->getRawOriginal());
        $this->assertFalse(PersonalMina::query()->where('personal_id',$worker->id)->exists());
        $this->assertSame(['unchanged','unchanged'], array_column($previewService->plan($source), 'disposition'));
        try { $bulk->apply($preview, $actor); $this->fail('Stale preview must fail'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('archivo',$e->errors()); }
        $this->assertSame(1, \App\Models\PersonalCursoRegistro::query()->where('personal_id',$worker->id)->count());
    }

    public function test_bulk_confirmation_rejects_observed_rows_and_changed_catalog(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera'=>['importar','registrar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO VALIDACION '.Str::random(6));
        $mine = $this->createMine('Mina validacion masiva');
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id, 'examen_id' => $exam->id, 'obligatorio' => true,
        ]);
        $this->grantMineScope($actor, $mine);
        $source = [['sheet'=>'notas','line'=>2,'type'=>'Resultado','dni'=>$worker->dni,'name'=>$worker->nombre_completo,
            'exam'=>$exam->nombre,'mine'=>$mine->nombre,'date'=>'2026-01-10','result'=>'APTO','score'=>null,'expiry'=>null,'observation'=>'','issues'=>[]]];
        $service = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $rows = $service->plan($source);
        $exam->update(['activo'=>false]);
        try {
            $bulk->apply(['token'=>(string) Str::uuid(),'rows'=>$rows,'source'=>$source,'fingerprint'=>$bulk->fingerprint($rows)],$actor);
            $this->fail('Changed catalog must fail');
        } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('archivo',$e->errors()); }
        $rows = $service->plan($source);
        try {
            $bulk->apply(['token'=>(string) Str::uuid(),'rows'=>$rows,'source'=>$source,'fingerprint'=>$bulk->fingerprint($rows)],$actor);
            $this->fail('Observed rows must fail');
        } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('archivo',$e->errors()); }
        $this->assertFalse(\App\Models\PersonalCursoRegistro::query()->where('personal_id',$worker->id)->exists());
    }

    public function test_bulk_confirm_checks_token_owner_and_result_permission(): void
    {
        $actor = $this->createUser(['habilitacion_minera'=>['importar']]);
        $other = $this->createUser(['habilitacion_minera'=>['importar','registrar']]);
        $token = (string) Str::uuid();
        $store = app(PersonalMinaImportPreviewStore::class);
        $store->store(['token'=>$token,'kind'=>'cursos','rows'=>[['disposition'=>'changes','type'=>'Resultado']]],$actor);
        try {
            $this->withSession($this->sessionFor($other))->post(route('personal.habilitacion-minera.bulk-confirm'),
                ['token'=>$token,'confirmar'=>1])->assertStatus(422);
            $this->withSession($this->sessionFor($actor))->post(route('personal.habilitacion-minera.bulk-confirm'),
                ['token'=>$token,'confirmar'=>1])->assertForbidden();
        } finally { $store->delete($token,$actor); }
    }

    public function test_bulk_completes_global_schedule_rejects_conflicts_and_counts_attempts_over_limit(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera'=>['importar','registrar','programar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO INTENTOS '.Str::random(6));
        $mine = $this->createMine('Mina intentos masivos');
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id, 'examen_id' => $exam->id, 'obligatorio' => true,
        ]);
        $this->assertDatabaseMissing('usuario_mina_scope', ['usuario_id' => $actor->id]);
        $base = ['sheet'=>'programacion','line'=>2,'type'=>'Programacion','dni'=>$worker->dni,'name'=>$worker->nombre_completo,
            'exam'=>$exam->nombre,'mine'=>$mine->nombre,'date'=>'2026-01-10','result'=>'','score'=>null,'expiry'=>null,'observation'=>'','issues'=>[]];
        $service = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $apply = function ($source) use ($service,$bulk,$actor) {
            $rows = $service->plan($source);
            return $bulk->apply(['token'=>(string) Str::uuid(),'source'=>$source,'rows'=>$rows,'fingerprint'=>$bulk->fingerprint($rows)],$actor);
        };
        $apply([$base]);
        $result = array_replace($base,['type'=>'Resultado','sheet'=>'notas','result'=>'DESAPROBADO']);
        $this->assertSame(1,$apply([$result])['actualizados']);
        $this->assertSame(1,\App\Models\PersonalCursoRegistro::query()->where('personal_id',$worker->id)->count());
        $conflict = $service->plan([array_replace($result,['result'=>'APTO'])]);
        $this->assertSame('issues',$conflict[0]['disposition']);
        $apply([array_replace($result,['date'=>'2026-02-10'])]);
        $third = $service->plan([array_replace($result,['date'=>'2026-03-10'])]);
        $this->assertSame('changes',$third[0]['disposition']);
        $this->assertSame(3,$third[0]['attempts_used']);
        $this->assertSame(2,$third[0]['attempts_limit']);
        $this->assertTrue($third[0]['attempts_exceeded']);
        $this->assertSame(1,$apply([array_replace($result,['date'=>'2026-03-10'])])['creados']);
        $this->assertSame(3,\App\Models\PersonalCursoRegistro::query()->where('personal_id',$worker->id)->count());
    }

    public function test_bulk_completa_programacion_minera_existente_sin_scope_de_mina(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar', 'registrar', 'programar']]));
        $worker = $this->createPersonal();
        $exam = $this->createExam('CURSO PENDIENTE ' . Str::random(6));
        $mine = $this->createMine('Mina programacion existente');
        $service = app(PersonalMinaHabilitacionService::class);
        $service->storeRequirement(['mina_id' => $mine->id, 'examen_id' => $exam->id]);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $workerExam = $assignment->examenes->firstOrFail();
        $service->registerAttempt($workerExam, [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
            'fecha_programacion' => '2026-10-01',
        ], null, $actor);
        $attempt = $workerExam->intentos()->firstOrFail();

        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado',
            'dni' => $worker->dni, 'name' => $worker->nombre_completo,
            'exam' => $exam->nombre, 'mine' => $mine->nombre,
            'date' => '2026-10-05', 'result' => 'APTO', 'score' => null,
            'expiry' => null, 'observation' => '', 'issues' => [],
        ]];
        $previewService = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $rows = $previewService->plan($source);
        $this->assertContains($attempt->id, $rows[0]['existing_attempt_ids']);
        $this->assertDatabaseMissing('usuario_mina_scope', ['usuario_id' => $actor->id]);

        $bulk = app(\App\Modules\Personal\Services\PersonalCursoBulkService::class);
        $bulk->apply([
            'token' => (string) Str::uuid(), 'source' => $source, 'rows' => $rows,
            'fingerprint' => $bulk->fingerprint($rows),
        ], $actor);

        $this->assertSame(PersonalMinaExamenIntento::RESULTADO_APROBADO, $attempt->fresh()->resultado);
    }

    public function test_restricted_aptitude_resolves_mining_requirement_and_requires_observation(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera'=>['registrar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service,$actor,$this->createExam('EMO RESTRINGIDO'));
        $exam = $assignment->examenes->first();
        try {
            $service->registerAttempt($exam,['resultado'=>'APTO_CON_RESTRICCION','fecha_realizacion'=>'2026-01-10'],null,$actor);
            $this->fail('Restriction is required');
        } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('observacion',$e->errors()); }
        $updated = $service->registerAttempt($exam,['resultado'=>'APTO_CON_RESTRICCION','fecha_realizacion'=>'2026-01-10',
            'observacion'=>'USO DE LENTES'],null,$actor);
        $this->assertSame('APROBADO',$updated->resultado);
        $this->assertStringContainsString('USO DE LENTES',$updated->observacion);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO,$assignment->fresh()->estado_habilitacion);
    }

    public function test_habilitacion_minera_excluye_oficinas_y_talleres_del_catalogo_operativo(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['ver', 'asignar']]));
        $officeAsMine = $this->createMine('Oficina catalogo');
        DB::table('oficinas')->insert([
            'id' => (string) Str::uuid(),
            'nombre' => $officeAsMine->nombre,
            'ubicacion' => 'Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $worker = $this->createPersonal();
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $worker->id,
            'mina_id' => $officeAsMine->id,
            'estado' => PersonalMina::ESTADO_EN_PROCESO,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(PersonalMinaHabilitacionService::class);
        $this->assertFalse($service->activeMines()->contains('id', $officeAsMine->id));
        $this->assertSame(0, $service->listGroupedByWorker(['per_page' => 15])->total());
        $this->assertFalse(app(\App\Modules\Personal\Services\PersonalMinaDashboardService::class)
            ->dashboard([])['by_mine']->contains('id', $officeAsMine->id));

        try {
            $service->assignMine([
                'personal_id' => $this->createPersonal()->id,
                'mina_id' => $officeAsMine->id,
            ], $actor);
            $this->fail('Una oficina no debe aceptar nuevas habilitaciones mineras.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('mina_id', $exception->errors());
        }

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['vista' => 'matrix']))
            ->assertOk()
            ->assertDontSee($officeAsMine->nombre);
    }

    public function test_dashboard_de_faltantes_resume_estados_minas_y_examenes_con_filtros(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['ver']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $first = $this->assignmentWithExam($service, $actor, $this->createExam('Examen pendiente dashboard'));
        $second = $this->assignmentWithExam($service, $actor, $this->createExam('Examen observado dashboard'));
        $withoutExams = $service->assignMine([
            'personal_id' => $this->createPersonal()->id,
            'mina_id' => $this->createMine('Mina sin examenes')->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $first->examenes()->update([
            'estado' => PersonalMinaExamen::ESTADO_PROGRAMADO,
            'fecha_programacion' => '2026-09-10',
            'fecha_realizacion' => null,
            'fecha_vencimiento' => '2027-09-10',
        ]);
        $second->update(['estado' => PersonalMina::ESTADO_HABILITADO, 'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO]);
        $second->examenes()->update(['estado' => PersonalMinaExamen::ESTADO_OBSERVADO]);

        $dashboard = app(\App\Modules\Personal\Services\PersonalMinaDashboardService::class)->dashboard([]);
        $this->assertGreaterThanOrEqual(1, $dashboard['states']->get(PersonalMina::ESTADO_EN_PROCESO));
        $this->assertGreaterThanOrEqual(1, $dashboard['states']->get(PersonalMina::ESTADO_HABILITADO));
        $this->assertEqualsCanonicalizing(
            [PersonalMina::ESTADO_EN_PROCESO, PersonalMina::ESTADO_HABILITADO],
            $dashboard['states']->keys()->all(),
        );
        $this->assertTrue($dashboard['by_mine']->contains('id', $first->mina_id));
        $this->assertTrue($dashboard['missing_exams']->contains(fn (array $row): bool => $row['nombre'] === 'EXAMEN PENDIENTE DASHBOARD'));
        $this->assertGreaterThanOrEqual(1, $dashboard['exam_kpis']['PROGRAMADO']);
        $this->assertGreaterThanOrEqual(1, $dashboard['exam_kpis']['SIN_EXAMENES']);
        $this->assertTrue($dashboard['by_mine']->contains('id', $withoutExams->mina_id));

        $filtered = app(\App\Modules\Personal\Services\PersonalMinaDashboardService::class)->dashboard([
            'mina_id' => $second->mina_id,
            'estado_examen' => PersonalMinaExamen::ESTADO_OBSERVADO,
        ]);
        $this->assertSame(1, $filtered['total']);
        $this->assertSame(PersonalMinaExamen::ESTADO_OBSERVADO, $filtered['missing_exams']->first()['estado']);

        $withWorkers = app(\App\Modules\Personal\Services\PersonalMinaDashboardService::class)->examWorkers([
            'mina_id' => $first->mina_id,
            'estado_examen' => PersonalMinaExamen::ESTADO_PROGRAMADO,
            'examen_nombre' => 'EXAMEN PENDIENTE DASHBOARD',
        ]);
        $this->assertSame(1, $withWorkers->total());
        $this->assertSame('TRABAJADOR HABILITACION', $withWorkers->first()->nombre_completo);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', [
                'vista' => 'dashboard',
                'dashboard_mina_id' => $first->mina_id,
                'dashboard_estado_examen' => PersonalMinaExamen::ESTADO_PROGRAMADO,
            ]))
            ->assertOk()->assertSee('Dashboard de faltantes')->assertSee('Estado general')
            ->assertSee('Habilitación por mina')->assertSee('Principales faltantes por examen')
            ->assertSee('Exámenes programados')->assertSee('Exámenes vencidos')
            ->assertSee('Exámenes por vencer')->assertSee('Personas sin exámenes')
            ->assertSee('Pendientes')->assertSee('Programados')->assertSee('Por vencer')
            ->assertSee('data-mine-exam-workers', false)->assertSee('data-mine-exam-name', false)
            ->assertSee('<option value="' . $first->mina_id . '" selected', false);

        $this->withSession($this->sessionFor($actor->id))
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('personal.habilitacion-minera.index', [
                'vista' => 'dashboard',
                'dashboard_mina_id' => $first->mina_id,
            ]))
            ->assertOk()
            ->assertSee('<option value="' . $first->mina_id . '" selected', false);

        $this->withSession($this->sessionFor($actor->id))
            ->getJson(route('personal.habilitacion-minera.dashboard.exam-workers', [
                'mina_id' => $first->mina_id,
                'estado_examen' => PersonalMinaExamen::ESTADO_PROGRAMADO,
                'examen_nombre' => 'EXAMEN PENDIENTE DASHBOARD',
            ]))
            ->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.nombre', 'TRABAJADOR HABILITACION')
            ->assertJsonPath('items.0.fecha_programacion', '10/09/2026')
            ->assertJsonPath('items.0.fecha_realizacion', null)
            ->assertJsonPath('items.0.fecha_vencimiento', '10/09/2027');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_vista_principal_muestra_acciones_y_no_formularios_administrativos_visibles(): void
    {
$userId = $this->createMiningOperator();

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Acciones')
            ->assertSee('Agregar examen')
            ->assertSee('Editar examen')
            ->assertSee('Configurar ex')
            ->assertSee('Importar Excel master')
            ->assertSee('Analizando Excel master')
            ->assertSee('data-defer-loading-submit="true"', false)
            ->assertSee('data-persistent-modal="true"', false)
            ->assertSee('mineExcelImportModalOpen')
            ->assertDontSee('Recalcular estados')
            ->assertDontSee('modal-recalcular')
            ->assertDontSee('Cargar informacion actual')
            ->assertDontSee('Asignar trabajador a mina')
            ->assertDontSee('Catalogo general de examenes mineros');
    }

    public function test_menu_acciones_respeta_permisos_granulares_de_habilitacion_minera(): void
    {
        $userId = $this->createUser([
            'habilitacion_minera' => ['ver', 'crear', 'ver_historial_precios'],
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Acciones')
            ->assertSee('Agregar examen')
            ->assertSee('Historial de precios')
            ->assertDontSee('Editar examen')
            ->assertDontSee("openDialog('modal-configuracion')", false)
            ->assertDontSee('Importar Excel master')
            ->assertDontSee('Agregar precio');
    }

    public function test_carga_masiva_ofrece_accesos_de_configuracion_sin_perder_la_revision(): void
    {
        $managerId = $this->createUser(['habilitacion_minera' => ['ver', 'importar', 'crear', 'editar', 'configurar']]);
        $this->withSession($this->sessionFor($managerId))
            ->get(route('personal.habilitacion-minera.bulk-preview'))
            ->assertOk()
            ->assertSee('Configurar minas y requisitos')
            ->assertSee('Agregar examen')
            ->assertSee('Editar examen')
            ->assertSee('target="_blank"', false)
            ->assertSee('abrir=configuracion', false)
            ->assertSee('abrir=agregar-examen', false)
            ->assertSee('abrir=editar-examen', false);

        $this->withSession($this->sessionFor($managerId))
            ->get(route('personal.habilitacion-minera.index', ['abrir' => 'configuracion']))
            ->assertOk()
            ->assertSee('const requestedManagementDialog = "configuracion";', false)
            ->assertSee("'configuracion': 'modal-configuracion'", false);

        $importerId = $this->createUser(['habilitacion_minera' => ['ver', 'importar']]);
        $this->withSession($this->sessionFor($importerId))
            ->get(route('personal.habilitacion-minera.bulk-preview'))
            ->assertOk()
            ->assertDontSee('Configurar minas y requisitos')
            ->assertDontSee('Agregar examen')
            ->assertDontSee('Editar examen');

    }

    public function test_backend_distingue_permiso_programar_de_registrar_resultado(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $assignment = $this->assignmentWithExam($service, $actor, $this->createExam('Examen permisos'));
        $workerExam = $assignment->examenes->first();

        $programmerId = $this->createUser(['habilitacion_minera' => ['ver', 'programar']]);
        $registrarId = $this->createUser(['habilitacion_minera' => ['ver', 'registrar']]);

        $this->withSession($this->sessionFor($programmerId))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $workerExam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
                'fecha_programacion' => '2026-07-20',
            ])
            ->assertRedirect();

        $this->withSession($this->sessionFor($programmerId))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $workerExam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
                'fecha_realizacion' => '2026-07-20',
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($registrarId))
            ->post(route('personal.habilitacion-minera.exam-attempts.store', $workerExam->id), [
                'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
                'fecha_programacion' => '2026-07-21',
            ])
            ->assertForbidden();
    }

    public function test_vista_no_abre_importar_excel_al_entrar_con_preview_guardada(): void
    {
        $userId = $this->createMiningOperator();
        $preview = $this->fakeImportPreview();

        $this->withSession(array_merge($this->sessionFor($userId), [
            'habilitacion_mina_import_preview' => $preview,
        ]))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Vista previa generada')
            ->assertSee('10/06/2026 12:52:31 hora Perú')
            ->assertSee('Importando Excel master')
            ->assertSee('data-inline-loading="#mineExcelConfirmLoading"', false)
            ->assertDontSee("window.sessionStorage?.setItem('mineExcelImportModalOpen', '1');", false);
    }

    public function test_vista_abre_importar_excel_solo_con_flag_temporal(): void
    {
        $userId = $this->createMiningOperator();
        $preview = $this->fakeImportPreview();

        $this->withSession(array_merge($this->sessionFor($userId), [
            'habilitacion_mina_import_preview' => $preview,
            'habilitacion_mina_import_modal_open' => true,
        ]))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee("window.sessionStorage?.setItem('mineExcelImportModalOpen', '1');", false);
    }

    public function test_agregar_examen_valida_campos_condicionales_y_maximo_dos_intentos(): void
    {
        $userId = $this->createMiningOperator();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Evaluacion operativa',
                'tipo' => 'Evaluacion',
                'requiere_lugar' => true,
                'empresa_paga' => true,
                'precio' => 120,
                'moneda' => 'PEN',
                'precio_desde' => '2026-06-01',
                'tiene_vigencia' => true,
                'vigencia_dias' => 180,
                'max_intentos' => 2,
                'requiere_nota' => true,
                'nota_minima' => 14,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'El lugar es obligatorio cuando el examen se toma en un lugar especifico.');

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Evaluacion operativa',
                'tipo' => 'Evaluacion',
                'max_intentos' => 3,
            ])
            ->assertSessionHasErrors('max_intentos');

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Evaluacion operativa',
                'tipo' => 'Evaluacion',
                'requiere_lugar' => true,
                'lugar' => 'Sede autorizada',
                'empresa_paga' => true,
                'precio' => 120,
                'moneda' => 'PEN',
                'precio_desde' => '2026-06-01',
                'tiene_vigencia' => true,
                'vigencia_dias' => 180,
                'max_intentos' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('examenes_mineros', [
            'nombre' => 'EVALUACION OPERATIVA',
            'requiere_lugar' => true,
            'empresa_paga' => true,
            'precio' => 120,
            'precio_desde' => '2026-06-01',
            'max_intentos' => 2,
        ]);
        $this->assertDatabaseHas('examen_minero_precios', [
            'precio' => 120,
            'moneda' => 'PEN',
            'fecha_inicio' => '2026-06-01',
        ]);
    }

    public function test_historial_de_precios_no_modifica_intentos_anteriores_y_el_intento_usa_fecha_de_registro(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $service->storeMiningExam([
            'nombre' => 'Control de acceso',
            'tipo' => 'Control',
            'empresa_paga' => true,
            'precio' => 100,
            'moneda' => 'PEN',
            'precio_desde' => '2026-06-01',
            'max_intentos' => 2,
        ], $actor);
        $service->storeExamPrice($exam, [
            'precio' => 150,
            'moneda' => 'PEN',
            'fecha_inicio' => '2026-06-06',
        ], $actor);

        $first = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($first->examenes->first(), [
            'fecha_programacion' => '2026-06-05',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $second = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($second->examenes->first(), [
            'fecha_programacion' => '2026-06-06',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $attempts = PersonalMinaExamenIntento::query()->orderBy('fecha_programacion')->get();
        $this->assertSame('150.00', $attempts->first()->precio_aplicado);
        $this->assertSame('150.00', $attempts->last()->precio_aplicado);
        $this->assertSame('historial_precio', $attempts->last()->fuente_precio);
        $this->assertSame('2026-06-06', $attempts->first()->fecha_precio_aplicado->toDateString());
    }

    public function test_configurar_examenes_por_mina_en_columnas_y_quitar_examen(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad columna');
        $exam = $this->createExam('Curso de ingreso');

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('mine-config-matrix-wrap', false)
            ->assertSee('mine-config-exams-strip', false)
            ->assertSee($mine->nombre)
            ->assertSee($exam->nombre);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.mine-config-matrix-wrap', $styles);
        $this->assertStringContainsString('height: min(62vh, 680px);', $styles);
        $this->assertStringContainsString('min-height: 232px;', $styles);
        $this->assertStringContainsString('overflow-y: hidden;', $styles);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertRedirect();

        $this->assertFalse($requirement->fresh()->activo);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertDontSee('data-requirement-id="' . $requirement->id . '"', false);
    }

    public function test_quitar_examen_de_mina_desde_modal_responde_json_sin_recargar(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad ajax');
        $exam = $this->createExam('Curso ajax');

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->postJson(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertOk()
            ->assertJsonPath('message', 'Examen quitado de la mina. Las habilitaciones se estan actualizando.')
            ->assertJsonPath('requirement_id', $requirement->id)
            ->assertJsonPath('deactivated_requirement_ids.0', $requirement->id)
            ->assertJsonPath('mina_id', $mine->id)
            ->assertJsonPath('active_count', 0);

        $this->assertFalse($requirement->fresh()->activo);
    }

    public function test_quitar_examen_de_mina_desactiva_duplicados_activos_equivalentes(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad duplicada');
        $exam = $this->createExam('Bloqueo duplicado');

        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $duplicate = MinaRequisito::query()->create([
            'id' => (string) Str::uuid(),
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'nombre' => $exam->nombre,
            'obligatorio' => true,
            'critico' => false,
            'reprogramable' => true,
            'activo' => true,
            'orden' => 2,
            'permite_no_aplica' => true,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->postJson(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertOk()
            ->assertJsonPath('active_count', 0)
            ->assertJsonFragment(['deactivated_requirement_ids' => [$requirement->id, $duplicate->id]]);

        $this->assertFalse($requirement->fresh()->activo);
        $this->assertFalse($duplicate->fresh()->activo);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertDontSee('data-requirement-id="' . $requirement->id . '"', false)
            ->assertDontSee('data-requirement-id="' . $duplicate->id . '"', false);
    }

    public function test_configurar_requisito_sincroniza_en_segundo_plano_sin_habilitar_prematuramente(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina automatica');
        $exam = $this->createExam('Examen automatico');
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->assertDatabaseMissing('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.store'), [
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $requirement = MinaRequisito::query()
            ->where('mina_id', $mine->id)
            ->where('examen_id', $exam->id)
            ->firstOrFail();
        $this->assertDatabaseHas('mina_requisito_syncs', [
            'mina_id' => $mine->id,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseMissing('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
        ]);
        $this->artisan('habilitacion-minera:sincronizar-requisitos')->assertExitCode(0);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
            'mina_requisito_id' => $requirement->id,
            'estado' => PersonalMinaExamen::ESTADO_PENDIENTE,
        ]);

        $workerExam = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $exam->id)
            ->firstOrFail();
        $service->registerAttempt($workerExam, [
            'fecha_realizacion' => '2026-06-10',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.requisitos.deactivate', $requirement->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($requirement->fresh()->activo);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estado_habilitacion);
        $this->assertDatabaseHas('mina_requisito_syncs', [
            'mina_id' => $mine->id,
            'estado' => 'PENDIENTE',
        ]);
        $this->artisan('habilitacion-minera:sincronizar-requisitos')->assertExitCode(0);
        $this->assertDatabaseHas('mina_requisito_syncs', [
            'mina_id' => $mine->id,
            'estado' => 'COMPLETADO',
        ]);
    }

    public function test_sin_tabla_de_sincronizacion_la_consulta_es_segura_y_no_se_guardan_requisitos(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['configurar']]));
        $mine = $this->createMine('Mina sin migracion');
        $exam = $this->createExam('Curso sin migracion');
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')
            ->with('mina_requisito_syncs')->andReturn(false);

        $this->assertTrue(app(MineRequirementSyncService::class)->statusesByMine()->isEmpty());
        try {
            app(PersonalMinaHabilitacionService::class)->storeRequirementDeferred([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
            ], $actor);
            $this->fail('La configuracion no debe guardarse sin la migracion.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('migracion', $exception->errors()['mina_id'][0]);
        }
        $this->assertDatabaseMissing('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $exam->id]);
    }

    public function test_requisito_y_tarea_pendiente_se_revierten_juntos_si_falla_el_encolado(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $mine = $this->createMine('Unidad rollback');
        $exam = $this->createExam('Curso rollback');
        $sync = \Mockery::mock(MineRequirementSyncService::class);
        $sync->shouldReceive('ensureAvailable')->once();
        $sync->shouldReceive('request')->once()->andThrow(new \RuntimeException('Fallo de prueba'));
        $this->app->instance(MineRequirementSyncService::class, $sync);

        try {
            app(PersonalMinaHabilitacionService::class)->storeRequirementDeferred([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
            ], $actor);
            $this->fail('El cambio no debio confirmarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo de prueba', $exception->getMessage());
        }

        $this->assertDatabaseMissing('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $exam->id]);
        $this->assertDatabaseMissing('mina_requisito_syncs', ['mina_id' => $mine->id]);
    }

    public function test_sincronizacion_de_requisitos_avanza_por_lotes_y_conserva_historial(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad por lotes');
        $exam = $this->createExam('Curso por lotes');
        $assignments = collect([1, 2])->map(fn () => $service->assignMine([
            'personal_id' => $this->createPersonal()->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor));

        $service->storeRequirementDeferred(['mina_id' => $mine->id, 'examen_id' => $exam->id], $actor);
        $sync = app(MineRequirementSyncService::class);
        $this->assertSame(1, $sync->processPending(1, 1)['procesados']);
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'EN_PROCESO', 'procesados' => 1]);
        $this->assertSame(1, $sync->processPending(1, 1)['procesados']);
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'COMPLETADO', 'procesados' => 2]);
        foreach ($assignments as $assignment) {
            $this->assertDatabaseHas('personal_mina_examenes', ['personal_mina_id' => $assignment->id, 'examen_id' => $exam->id]);
        }
    }

    public function test_nuevo_requisito_baja_habilitado_a_proceso_antes_de_la_sincronizacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad conservadora');
        $exam = $this->createExam('Curso conservador');
        $assignment = $service->assignMine([
            'personal_id' => $this->createPersonal()->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $assignment->forceFill([
            'estado' => PersonalMina::ESTADO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'fecha_habilitacion' => Carbon::today('America/Lima'),
        ])->save();

        $service->storeRequirementDeferred(['mina_id' => $mine->id, 'examen_id' => $exam->id], $actor);

        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->fresh()->estadoHabilitacionActual());
        $this->assertDatabaseHas('personal_mina_historial', [
            'personal_mina_id' => $assignment->id,
            'estado_anterior' => PersonalMina::ESTADO_HABILITADO,
            'estado_nuevo' => PersonalMina::ESTADO_EN_PROCESO,
        ]);
        $this->assertDatabaseMissing('personal_mina_examenes', ['personal_mina_id' => $assignment->id, 'examen_id' => $exam->id]);
    }

    public function test_fallo_de_lote_se_reintenta_sin_perder_el_requisito(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad reintento');
        $exam = $this->createExam('Curso reintento');
        $assignment = $service->assignMine([
            'personal_id' => $this->createPersonal()->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $service->storeRequirementDeferred(['mina_id' => $mine->id, 'examen_id' => $exam->id], $actor);

        $broken = \Mockery::mock(PersonalMinaHabilitacionService::class);
        $broken->shouldReceive('generateRequiredExams')->once()->andThrow(new \RuntimeException('Fallo transitorio de prueba'));
        $this->assertSame(1, (new MineRequirementSyncService($broken))->processPending(1, 1)['errores']);
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'ERROR', 'procesados' => 0]);
        $this->assertDatabaseHas('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $exam->id, 'activo' => 1]);

        $this->assertSame(1, app(MineRequirementSyncService::class)->processPending(1, 1)['completados']);
        $this->assertDatabaseHas('personal_mina_examenes', ['personal_mina_id' => $assignment->id, 'examen_id' => $exam->id]);
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'COMPLETADO']);
    }

    public function test_configurar_requisito_no_recorre_examenes_de_cada_trabajador_en_http(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad con volumen');
        $exam = $this->createExam('Curso con volumen');
        for ($index = 0; $index < 25; $index++) {
            $service->assignMine([
                'personal_id' => $this->createPersonal()->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ], $actor);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $service->storeRequirementDeferred(['mina_id' => $mine->id, 'examen_id' => $exam->id], $actor);
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertLessThan(25, $queries->count());
        $this->assertFalse($queries->contains(fn (string $query): bool =>
            str_contains($query, 'personal_mina_examenes')));
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'total' => 25]);
        $this->artisan('habilitacion-minera:sincronizar-requisitos')->assertExitCode(0);
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'COMPLETADO', 'procesados' => 25]);
    }

    public function test_quitar_requisito_dos_veces_no_reinicia_el_avance(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $mine = $this->createMine('Unidad sin doble tarea');
        $exam = $this->createExam('Curso sin doble tarea');
        $requirement = $service->storeRequirementDeferred(['mina_id' => $mine->id, 'examen_id' => $exam->id], $actor);
        app(MineRequirementSyncService::class)->processPending();

        $service->deactivateRequirementDeferred($requirement, $actor);
        app(MineRequirementSyncService::class)->processPending();
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'COMPLETADO']);

        $service->deactivateRequirementDeferred($requirement, $actor);
        $this->assertDatabaseHas('mina_requisito_syncs', ['mina_id' => $mine->id, 'estado' => 'COMPLETADO']);
    }

    public function test_diagnostico_catalogo_sugiere_nombres_parecidos_sin_fusionar_ids(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['ver', 'configurar']]));
        $mine = $this->createMine('Unidad diagnostico');
        $first = $this->createExam('ESPACIO CONFINADO');
        $second = $this->createExam('ESPACIOS CONFINADOS');
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $first->id,
        ]);

        $report = app(MiningExamCatalogAuditService::class)->report();
        $firstRow = collect($report['examenes'])->firstWhere('id', $first->id);
        $this->assertSame(1, $firstRow['requisitos_activos']);
        $this->assertSame(0, $firstRow['examenes_trabajador']);
        $this->assertTrue(collect($report['sugerencias'])->contains(fn (array $pair): bool =>
            collect([$pair['primero_id'], $pair['segundo_id']])->sort()->values()->all()
                === collect([$first->id, $second->id])->sort()->values()->all()));
        $this->assertDatabaseHas('examenes_mineros', ['id' => $first->id, 'activo' => 1]);
        $this->assertDatabaseHas('examenes_mineros', ['id' => $second->id, 'activo' => 1]);

        $this->withSession($this->sessionFor($actor->id))
            ->getJson(route('personal.habilitacion-minera.examenes.diagnostico'))
            ->assertOk()
            ->assertJsonCount(2, 'examenes');
        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('id="mineCatalogAuditButton"', false);

        $viewer = $this->createUser(['habilitacion_minera' => ['ver']]);
        $this->withSession($this->sessionFor($viewer))
            ->getJson(route('personal.habilitacion-minera.examenes.diagnostico'))
            ->assertForbidden();
        $this->withSession($this->sessionFor($viewer))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertDontSee('id="mineCatalogAuditButton"', false);
    }

    public function test_revision_masiva_recalcula_todas_las_filas_y_descarta_token_anterior(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar']]));
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina revision de nuevo');
        $exam = $this->createExam('CURSO REVISION DE NUEVO');
        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $exam->nombre, 'mine' => $mine->nombre,
            'date' => '2026-01-10', 'result' => 'APTO', 'score' => null, 'expiry' => null,
            'observation' => '', 'issues' => [],
        ]];
        $planner = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $rows = $planner->plan($source);
        $this->assertNotEmpty($rows[0]['issues']);
        $token = (string) Str::uuid();
        $store = app(PersonalMinaImportPreviewStore::class);
        $store->store([
            'token' => $token, 'kind' => 'cursos', 'source' => $source, 'rows' => $rows,
            'fingerprint' => app(\App\Modules\Personal\Services\PersonalCursoBulkService::class)->fingerprint($rows),
        ], $actor->id);
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id, 'examen_id' => $exam->id,
        ]);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.bulk-recheck'), ['token' => $token])
            ->assertOk()
            ->assertViewHas('report', fn (array $report): bool => $report['with_issues'] === 0);
        $newToken = $response->viewData('token');
        $this->assertNotSame($token, $newToken);
        $this->assertNull($store->retrieve($token, $actor->id));
        $this->assertNotNull($store->retrieve($newToken, $actor->id));
    }

    public function test_carga_masiva_configura_requisito_confirmado_y_revisa_archivo_completo(): void
    {
        Storage::fake('local');
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['importar', 'configurar']]));
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina corregida en revision');
        $exam = $this->createExam('CURSO CORREGIDO EN REVISION');
        $source = [[
            'sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $exam->nombre, 'mine' => $mine->nombre,
            'date' => '2026-01-10', 'result' => 'APTO', 'score' => null, 'expiry' => null,
            'observation' => '', 'issues' => [],
        ]];
        $planner = app(\App\Modules\Personal\Services\PersonalMinaBulkPreviewService::class);
        $rows = $planner->plan($source);
        $token = (string) Str::uuid();
        $store = app(PersonalMinaImportPreviewStore::class);
        $store->store([
            'token' => $token, 'kind' => 'cursos', 'source' => $source, 'rows' => $rows,
            'fingerprint' => app(\App\Modules\Personal\Services\PersonalCursoBulkService::class)->fingerprint($rows),
        ], $actor->id);

        $otherExam = $this->createExam('OTRO CURSO DE REVISION');
        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.bulk-configure-requirement'), [
                'token' => $token, 'mina_id' => $mine->id, 'examen_id' => $otherExam->id, 'confirmar' => '1',
            ])->assertStatus(422);
        $this->assertDatabaseMissing('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $otherExam->id]);

        $otherOperator = $this->createUser(['habilitacion_minera' => ['importar', 'configurar']]);
        $this->withSession($this->sessionFor($otherOperator))
            ->post(route('personal.habilitacion-minera.bulk-configure-requirement'), [
                'token' => $token, 'mina_id' => $mine->id, 'examen_id' => $exam->id, 'confirmar' => '1',
            ])->assertStatus(422);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.bulk-configure-requirement'), [
                'token' => $token, 'mina_id' => $mine->id, 'examen_id' => $exam->id, 'confirmar' => '1',
            ])
            ->assertOk()
            ->assertViewHas('report', fn (array $report): bool => $report['with_issues'] === 0);
        $this->assertDatabaseHas('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $exam->id, 'activo' => 1]);
        $this->assertNull($store->retrieve($token, $actor->id));
        $this->assertNotNull($store->retrieve($response->viewData('token'), $actor->id));

        $importer = $this->createUser(['habilitacion_minera' => ['importar']]);
        $this->withSession($this->sessionFor($importer))
            ->post(route('personal.habilitacion-minera.bulk-configure-requirement'), [
                'token' => $token, 'mina_id' => $mine->id, 'examen_id' => $exam->id, 'confirmar' => '1',
            ])->assertForbidden();
    }

    public function test_editar_examen_genera_historial_de_precio_sin_modificar_intentos_previos(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $service->storeMiningExam([
            'nombre' => 'Control editable',
            'tipo' => 'Control',
            'empresa_paga' => true,
            'precio' => 100,
            'moneda' => 'PEN',
            'precio_desde' => '2026-06-01',
            'max_intentos' => 2,
        ], $actor);

        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $service->registerAttempt($assignment->examenes->first(), [
            'fecha_programacion' => '2026-06-05',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);
        $oldAttempt = PersonalMinaExamenIntento::query()->firstOrFail();

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.examenes.update', $exam->id), [
                'nombre' => 'Control editable actualizado',
                'tipo' => 'Control',
                'empresa_paga' => true,
                'precio' => 160,
                'moneda' => 'PEN',
                'precio_desde' => '2026-06-08',
                'max_intentos' => 2,
                'activo' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('examenes_mineros', [
            'id' => $exam->id,
            'nombre' => 'CONTROL EDITABLE ACTUALIZADO',
            'precio' => 160,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('examen_minero_precios', [
            'examen_id' => $exam->id,
            'precio' => 160,
            'fecha_inicio' => '2026-06-08',
        ]);
        $this->assertSame('100.00', $oldAttempt->fresh()->precio_aplicado);

        $newAssignment = $this->assignmentWithExam($service, $actor, $exam->fresh());
        $service->registerAttempt($newAssignment->examenes->first(), [
            'fecha_programacion' => '2026-06-08',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $this->assertSame('160.00', PersonalMinaExamenIntento::query()->orderByDesc('fecha_programacion')->firstOrFail()->precio_aplicado);
    }

    public function test_recalcular_corrige_habilitado_sin_examenes_generados(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina sin examenes');
        $assignmentId = (string) Str::uuid();
        DB::table('personal_mina')->insert([
            'id' => $assignmentId,
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado' => PersonalMina::ESTADO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'fecha_asignacion' => '2026-06-08',
            'fecha_inicio_proceso' => '2026-06-08',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PersonalMinaHabilitacionService::class)->syncCurrentInformation($actor);

        $this->assertGreaterThanOrEqual(1, $result['asignaciones_corregidas']);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, PersonalMina::query()->findOrFail($assignmentId)->estado_habilitacion);
    }

    public function test_asignar_desde_tarjeta_genera_examenes_y_conserva_trabajador_seleccionado(): void
    {
        $userId = $this->createMiningOperator();
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina tarjeta');
        $exam = $this->createExam('Examen tarjeta');
        app(PersonalMinaHabilitacionService::class)->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['trabajador' => $worker->dni, 'worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Asignar')
            ->assertSee($worker->nombre_completo);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.assign', ['worker_id' => $worker->id]), [
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->assertRedirect();

        $assignment = PersonalMina::query()->where('personal_id', $worker->id)->where('mina_id', $mine->id)->firstOrFail();
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'examen_id' => $exam->id,
        ]);
    }

    public function test_selector_de_trabajador_queda_limpio_y_filtros_operativos_van_en_matriz(): void
    {
        $userId = $this->createMiningOperator();

        $response = $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Buscar por nombre, DNI o cargo')
            ->assertSee('id="matrixFilterForm"', false)
            ->assertSee('form="matrixFilterForm"', false);

        $html = $response->getContent();
        $workerForm = Str::betweenFirst($html, 'id="workerSearchForm"', '</form>');
        $matrixForm = Str::betweenFirst($html, 'id="matrixFilterForm"', '</form>');

        $this->assertStringNotContainsString('name="mina_id"', $workerForm);
        $this->assertStringNotContainsString('name="estado_habilitacion"', $workerForm);
        $this->assertStringNotContainsString('name="estado_laboral"', $workerForm);
        $this->assertStringNotContainsString('name="estado_examen"', $workerForm);
        $this->assertStringContainsString('name="mina_id"', $matrixForm);
        $this->assertStringNotContainsString('name="estado_habilitacion"', $matrixForm);
        $this->assertStringNotContainsString('name="estado_laboral"', $matrixForm);
        $this->assertStringContainsString('name="estado_examen"', $matrixForm);
    }

    public function test_selector_de_trabajador_muestra_total_real_y_no_solo_limite_visible(): void
    {
        $userId = $this->createMiningOperator();
        $prefix = 'CONTADOR VISUAL ' . Str::upper(Str::random(5));

        for ($i = 1; $i <= 12; $i++) {
            $this->createPersonalWithDocument((string) (78000000 + $i), [
                'nombre_completo' => $prefix . ' ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'trabajador' => $prefix,
                'worker_limit' => 10,
            ]))
            ->assertOk()
            ->assertSee('Mostrando 1-10 de 12')
            ->assertSee('mine-page-buttons', false)
            ->assertSee('worker_page=2', false);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'trabajador' => $prefix,
                'worker_limit' => 10,
                'worker_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Mostrando 11-12 de 12')
            ->assertSee($prefix . ' 11')
            ->assertSee($prefix . ' 12')
            ->assertDontSee($prefix . ' 01');
    }

    public function test_acciones_de_trabajador_diferencian_asignar_y_gestionar_examenes(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $assignedWorker = $this->createPersonal();
        $unassignedWorker = $this->createPersonal();
        $mine = $this->createMine('Mina acciones');
        $exam = $this->createExam('Examen acciones');
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $service->assignMine([
            'personal_id' => $assignedWorker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_limit' => 80]))
            ->assertOk()
            ->assertSee('data-testid="worker-assign-' . $assignedWorker->id . '"', false)
            ->assertSee('data-testid="worker-manage-' . $assignedWorker->id . '"', false)
            ->assertSee('data-testid="worker-assign-' . $unassignedWorker->id . '"', false);

        $this->assertStringNotContainsString(
            'data-testid="worker-manage-' . $unassignedWorker->id . '"',
            $response->getContent()
        );
    }

    public function test_gestionar_examenes_del_trabajador_permite_abrir_o_generar_desde_el_modal(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mineWithExams = $this->createMine('Mina gestion con examenes');
        $mineWithoutGenerated = $this->createMine('Mina gestion sin generados');
        $hiddenByFilterMine = $this->createMine('Mina filtro distinta');
        $exam = $this->createExam('Examen gestion modal');

        $service->storeRequirement([
            'mina_id' => $mineWithExams->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $service->storeRequirement([
            'mina_id' => $mineWithoutGenerated->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignmentWithExams = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mineWithExams->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $assignmentWithoutGeneratedId = (string) Str::uuid();
        DB::table('personal_mina')->insert([
            'id' => $assignmentWithoutGeneratedId,
            'personal_id' => $worker->id,
            'mina_id' => $mineWithoutGenerated->id,
            'estado' => PersonalMina::ESTADO_EN_PROCESO,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'fecha_asignacion' => '2026-06-11',
            'fecha_inicio_proceso' => '2026-06-11',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', [
                'worker_id' => $worker->id,
                'mina_id' => $hiddenByFilterMine->id,
            ]))
            ->assertOk()
            ->assertSee('Abrir gestion')
            ->assertSee('Generar examenes')
            ->assertSee('Programar examen')
            ->assertSee('Ver programados')
            ->assertSee('Registrar examen realizado')
            ->assertSee($assignmentWithExams->id)
            ->assertSee($assignmentWithoutGeneratedId)
            ->assertSee('/personal/habilitacion-minera/asignaciones/' . $assignmentWithoutGeneratedId . '/generar-examenes', false)
            ->assertSee('generate_exams_url', false);
    }

    public function test_minas_disponibles_muestran_asignado_pendiente_y_evitan_reasignacion(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina pendiente inicio');
        $exam = $this->createExam('Examen pendiente inicio');
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('data-testid="mine-worker-mine-board"', false)
            ->assertSee('data-visual-state="ASIGNADO_PENDIENTE_INICIO"', false)
            ->assertSee('class="mine-tile info"', false)
            ->assertSee('Programar examenes')
            ->assertSee('Sin examenes iniciados')
            ->assertSee('Ya asignada')
            ->assertSee('openWorkerExams', false);
    }

    public function test_desasignar_mina_desde_habilitacion_la_deja_disponible_para_el_trabajador(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina desasignable');
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Desasignar')
            ->assertSee(route('personal.habilitacion-minera.deactivate', ['assignmentId' => $assignment->id]), false);

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.deactivate', ['assignmentId' => $assignment->id, 'worker_id' => $worker->id]), [
                'observacion' => 'Ya no corresponde a esta mina.',
            ])
            ->assertRedirect(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]));

        $this->assertDatabaseHas('personal_mina', [
            'id' => $assignment->id,
            'activo' => false,
        ]);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Disponible para asignar')
            ->assertDontSee('Ya asignada');
    }

    public function test_matriz_diferencia_desaprobado_con_reintento_de_desaprobado_definitivo(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina reintento visual');
        $exam = $this->createExam('Examen reintento visual', [
            'permite_reintento' => true,
            'max_intentos' => 2,
        ]);
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $service->registerAttempt($assignment->examenes->first(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id, 'vista' => 'matrix']))
            ->assertOk()
            ->assertSee('mine-exam-cell orange', false)
            ->assertSee('Registrar siguiente intento');
    }

    public function test_listado_de_habilitacion_se_pagina_por_cantidad_elegida(): void
    {
        $userId = $this->createMiningOperator();
        $mine = $this->createMine('Mina paginada');

        for ($i = 1; $i <= 16; $i++) {
            $worker = $this->createPersonal();
            DB::table('personal_mina')->insert([
                'id' => (string) Str::uuid(),
                'personal_id' => $worker->id,
                'mina_id' => $mine->id,
                'estado' => PersonalMina::ESTADO_EN_PROCESO,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
                'fecha_asignacion' => '2026-06-08',
                'fecha_inicio_proceso' => '2026-06-08',
                'activo' => true,
                'created_at' => now()->addSeconds($i),
                'updated_at' => now()->addSeconds($i),
            ]);
        }

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id, 'per_page' => 10, 'vista' => 'matrix']))
            ->assertOk()
            ->assertSee('Mostrar')
            ->assertSee('trabajadores')
            ->assertDontSee('Asignaciones por página')
            ->assertSee('Mostrando 1 - 10 de 16 trabajadores');
    }

    public function test_matriz_operativa_por_mina_muestra_examenes_dinamicos_y_estados_visuales(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina matriz');
        $exam = $this->createExam('Examen matriz');

        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        $service->registerAttempt($assignment->examenes->first(), [
            'fecha_programacion' => '2026-06-08',
            'fecha_realizacion' => '2026-06-08',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_APROBADO,
        ], null, $actor);

        $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id, 'vista' => 'matrix']))
            ->assertOk()
            ->assertSee('Matriz operativa')
            ->assertSee('data-testid="mine-operational-matrix"', false)
            ->assertSee('Estado habilitacion')
            ->assertSee('Accion siguiente')
            ->assertSee($exam->nombre)
            ->assertSee('Intento 1/2')
            ->assertSee('mine-exam-cell ok', false)
            ->assertSee('data-visual-state="' . PersonalMina::ESTADO_HABILITADO . '"', false);
    }

    public function test_filtro_de_estado_examen_limita_trabajadores_y_columnas_de_la_matriz(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina filtro examen');
        $approvedExam = $this->createExam('Examen aprobado filtro');
        $pendingExam = $this->createExam('Examen pendiente filtro');

        foreach ([$approvedExam, $pendingExam] as $exam) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);

        PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $approvedExam->id)
            ->update(['estado' => PersonalMinaExamen::ESTADO_APROBADO]);

        PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->where('examen_id', $pendingExam->id)
            ->update(['estado' => PersonalMinaExamen::ESTADO_PENDIENTE]);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', [
                'mina_id' => $mine->id,
                'estado_examen' => PersonalMinaExamen::ESTADO_APROBADO,
                'vista' => 'matrix',
            ]))
            ->assertOk()
            ->assertSee('name="estado_examen"', false)
            ->assertDontSee('name="estado_habilitacion"', false)
            ->assertDontSee('name="estado_laboral"', false);

        $matrix = Str::betweenFirst($response->getContent(), 'data-testid="mine-operational-matrix"', '</table>');

        $this->assertStringContainsString($approvedExam->nombre, $matrix);
        $this->assertStringNotContainsString($pendingExam->nombre, $matrix);
    }

    public function test_proximos_vencimientos_muestra_solo_minas_asignadas_y_abre_gestion(): void
    {
        Carbon::setTestNow('2026-06-10 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonalWithDocument('73187777', [
            'nombre_completo' => 'TRABAJADOR POR VENCER',
        ]);
        $assignedMine = $this->createMine('Mina vencimiento asignada');
        $unassignedMine = $this->createMine('Mina vencimiento no asignada');
        $exam = $this->createExam('Examen por vencer', [
            'tiene_vigencia' => true,
            'vigencia_dias' => 180,
        ]);

        foreach ([$assignedMine, $unassignedMine] as $mine) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $assignedMine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor)->load('examenes');

        $workerExam = $assignment->examenes->first();
        $workerExam->forceFill([
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_realizacion' => '2026-01-10',
            'fecha_vencimiento' => '2026-06-25',
        ])->save();

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['vista' => 'expiring']))
            ->assertOk()
            ->assertSee('Seleccionar trabajador')
            ->assertSee('Matriz operativa')
            ->assertSee('Proximos vencimientos')
            ->assertSee('data-mine-view-tab="expiring"', false)
            ->assertSee('TRABAJADOR POR VENCER')
            ->assertSee('EXAMEN POR VENCER')
            ->assertSee('25/06/2026')
            ->assertSee('openWorkerExams', false);

        $expiringTable = Str::betweenFirst($response->getContent(), 'data-testid="mine-upcoming-expirations"', '</table>');

        $this->assertStringContainsString($assignedMine->nombre, $expiringTable);
        $this->assertStringNotContainsString($unassignedMine->nombre, $expiringTable);
    }

    public function test_examenes_programados_muestran_intentos_pendientes_y_abren_gestion(): void
    {
        Carbon::setTestNow('2026-06-10 08:00:00');

        $actor = Usuario::query()->findOrFail($this->createUser([
            'personal' => ['ver', 'actualizar'],
            'habilitacion_minera' => ['ver', 'ver_programados'],
        ]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonalWithDocument('73187778', [
            'nombre_completo' => 'TRABAJADOR PROGRAMADO',
        ]);
        $mine = $this->createMine('Mina programada');
        $exam = $this->createExam('Examen programado visible', ['categoria_operativa' => 'CURSO']);

        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor)->load('examenes');

        $workerExam = $assignment->examenes->first();
        $service->registerAttempt($workerExam, [
            'fecha_programacion' => '2026-06-19',
            'resultado' => PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
        ], null, $actor);

        $response = $this->withSession($this->sessionFor($actor->id))
            ->get(route('personal.habilitacion-minera.index', ['vista' => 'scheduled']))
            ->assertOk()
            ->assertSee('Control de asistencia de cursos y EMO')
            ->assertSee('data-mine-view-tab="scheduled"', false)
            ->assertSee('data-testid="mine-scheduled-exams"', false)
            ->assertSee('TRABAJADOR PROGRAMADO')
            ->assertSee('EXAMEN PROGRAMADO VISIBLE')
            ->assertSee('19/06/2026')
            ->assertSee('Ver programados')
            ->assertSee('openWorkerExams', false);

        $scheduledTable = Str::betweenFirst($response->getContent(), 'data-testid="mine-scheduled-exams"', '</table>');

        $this->assertStringContainsString($mine->nombre, $scheduledTable);
        $controlRow = $response->viewData('scheduledControl')->getCollection()->sole();
        $this->assertSame((string) $worker->id, $controlRow->workerId);
        $this->assertSame((string) $exam->id, $controlRow->examId);
        $this->assertSame([(string) $mine->id], $controlRow->mineIds);
    }

    public function test_filtros_de_vencimientos_y_programados_conservan_su_pestana_ajax(): void
    {
        $userId = $this->createUser([
            'personal' => ['ver', 'actualizar'],
            'habilitacion_minera' => ['ver', 'ver_vencimientos', 'ver_programados'],
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'vista' => 'expiring',
                'expiring_worker' => 'operador',
            ]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-mine-view-tab="expiring" aria-selected="true"', false)
            ->assertSee('name="expiring_worker"', false)
            ->assertSee('name="expiring_exam"', false)
            ->assertSee('name="expiring_mine"', false)
            ->assertSee('name="expiring_state"', false)
            ->assertSee('Sin resultados con estos filtros.');

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', [
                'vista' => 'scheduled',
                'scheduled_worker' => 'operador',
            ]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-mine-view-tab="scheduled" aria-selected="true"', false)
            ->assertSee('name="scheduled_worker"', false)
            ->assertSee('name="scheduled_exam_id"', false)
            ->assertSee('name="scheduled_mine_id"', false)
            ->assertSee('name="scheduled_attendance_state"', false)
            ->assertSee('Sin resultados con estos filtros.')
            ->assertSee("url.searchParams.set('vista', view);", false)
            ->assertSee('focusedViewSelectors', false);
    }

    public function test_entrada_a_habilitacion_carga_solo_la_vista_visible(): void
    {
        $userId = $this->createMiningOperator();

        $response = $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index'));

        $response->assertOk()
            ->assertSee('data-mine-view-tab="worker" aria-selected="true"', false)
            ->assertSee('ensureMineViewLoaded', false);
        $this->assertSame(0, $response->viewData('assignments')->total());
        $this->assertSame(0, $response->viewData('miningDashboard')['total']);
        $this->assertCount(0, $response->viewData('upcomingExpirations'));
        $this->assertCount(0, $response->viewData('scheduledExams'));

        $matrix = $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['vista' => 'matrix']));

        $matrix->assertOk()
            ->assertSee('data-mine-view-tab="matrix" aria-selected="true"', false);
    }

    public function test_vista_no_muestra_habilitado_visual_sin_examenes_configurados_o_generados(): void
    {
        $userId = $this->createMiningOperator();
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina visual');
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado' => PersonalMina::ESTADO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'fecha_asignacion' => '2026-06-08',
            'fecha_inicio_proceso' => '2026-06-08',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.habilitacion-minera.index', ['mina_id' => $mine->id, 'vista' => 'matrix']))
            ->assertOk()
            ->assertSee('Sin examenes configurados para esta mina')
            ->assertSee('data-visual-state="' . PersonalMina::ESTADO_EN_PROCESO . '"', false)
            ->assertSee('En proceso');
    }

    public function test_excel_master_preview_no_guarda_y_confirmacion_importa_datos(): void
    {
        $userId = $this->createMiningOperator();
        $file = $this->buildMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview')
            ->assertSessionHas('habilitacion_mina_import_modal_open');

        $this->assertDatabaseMissing('personal', ['numero_documento' => '77889900']);
        $this->assertDatabaseMissing('minas', ['nombre' => 'OPERACION DINAMICA']);

        $displayPreview = session('habilitacion_mina_import_preview');
        $this->assertArrayNotHasKey('rows', $displayPreview);
        $this->assertLessThan(1_000_000, strlen(serialize($displayPreview)));
        $preview = $this->storedImportPreview($userId);
        $this->assertSame(1, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(1, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(0, $preview['summary']['trabajadores_existentes']);
        $this->assertSame(0, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame(1, $preview['summary']['minas_nuevas']);
        $this->assertSame(1, $preview['summary']['examenes_nuevos']);
        $this->assertSame(1, $preview['summary']['precios_detectados_omitidos']);
        $this->assertSame('OMITIR_TRABAJADOR_NO_ENCONTRADO', $preview['rows'][0]['accion_importacion']);
        $this->assertNull(app(PersonalMinaImportPreviewStore::class)->retrieve($preview['token'], 'otro-usuario'));

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
                'confirmar_reemplazo' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'trabajadores_no_encontrados: 1'))
            ->assertSessionHas('habilitacion_mina_import_completed')
            ->assertSessionMissing('habilitacion_mina_import_preview');
        $this->assertNull(app(PersonalMinaImportPreviewStore::class)->retrieve($preview['token'], $userId));

        $this->assertDatabaseMissing('personal', ['numero_documento' => '77889900']);
        $this->assertDatabaseHas('minas', ['nombre' => 'OPERACION DINAMICA']);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'EXAMEN DINAMICO']);
        $this->assertDatabaseMissing('personal_mina_examenes', [
            'nombre_snapshot' => 'EXAMEN DINAMICO',
            'estado' => PersonalMinaExamen::ESTADO_APROBADO,
        ]);
    }

    public function test_excel_master_rechaza_vista_previa_si_cambio_el_catalogo_sin_aplicar_cambios(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $mine = $this->createMine('MINA ' . Str::upper(Str::random(6)));
        $oldExam = $this->createExam('EXAMEN ANTIGUO ' . Str::upper(Str::random(6)));
        $importer = app(PersonalMinaExcelImportService::class);
        $newExamName = 'EXAMEN NUEVO ' . Str::upper(Str::random(6));
        $preview = $importer->preview($this->buildSingleMineCatalogExcel($mine->nombre, $newExamName));

        $lateExam = $this->createExam('EXAMEN POSTERIOR ' . Str::upper(Str::random(6)));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $importer->confirm($preview, $actor);
        } finally {
            $this->assertDatabaseMissing('examenes_mineros', ['nombre' => $newExamName]);
            $this->assertTrue((bool) $oldExam->fresh()->activo);
            $this->assertTrue((bool) $lateExam->fresh()->activo);
        }
    }

    public function test_excel_master_requiere_confirmacion_explicita_para_reemplazar_catalogo(): void
    {
        $userId = $this->createMiningOperator();
        $preview = app(PersonalMinaExcelImportService::class)
            ->preview($this->buildSingleMineCatalogExcel('MINA CONFIRMACION', 'EXAMEN CONFIRMACION'));
        app(PersonalMinaImportPreviewStore::class)->store($preview, $userId);

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), ['token' => $preview['token']])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('minas', ['nombre' => 'MINA CONFIRMACION']);
        $this->assertNotNull(app(PersonalMinaImportPreviewStore::class)->retrieve($preview['token'], $userId));
    }

    public function test_excel_master_sin_datos_sincroniza_catalogo_nombres_y_convalidacion_compartida(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $suffix = Str::upper(Str::random(6));
        $oldName = 'CURSO-' . $suffix;
        $newName = 'CURSO ' . $suffix;
        $blankExamName = 'INDUCCION ' . $suffix;
        $mineOneName = 'OPERACION ' . $suffix . ' A';
        $mineTwoName = 'OPERACION ' . $suffix . ' B';
        $existingExam = $this->createExam($oldName);
        $file = $this->buildCatalogOnlyMasterExcel($mineOneName, $mineTwoName, $newName, $blankExamName);
        $service = app(PersonalMinaExcelImportService::class);

        $preview = $service->preview($file);

        $this->assertSame(1, $preview['summary']['nombres_examen_por_actualizar']);
        $this->assertSame(1, $preview['summary']['examenes_nuevos']);
        $this->assertSame(1, $preview['summary']['examenes_compartidos_convalidables']);
        $this->assertContains($blankExamName, $preview['examenes_no_encontrados']);
        $this->assertNotEmpty($preview['examenes_faltantes_por_mina']);

        $result = $service->confirm($preview, $actor);

        $this->assertSame(1, $result['examenes_renombrados']);
        $this->assertSame(1, $result['examenes_convalidables_activados']);
        $this->assertSame($newName, $existingExam->fresh()->nombre);
        $this->assertTrue((bool) $existingExam->fresh()->permite_convalidacion);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => $blankExamName, 'activo' => true]);

        foreach ([$mineOneName, $mineTwoName] as $mineName) {
            $mine = Mina::query()->where('nombre', $mineName)->firstOrFail();
            $this->assertDatabaseHas('mina_requisitos', [
                'mina_id' => $mine->id,
                'examen_id' => $existingExam->id,
                'nombre' => $newName,
                'permite_convalidacion_mina' => true,
                'convalidar_desde_otras_minas' => true,
                'activo' => true,
            ]);
        }

        $this->assertSame(0, PersonalMina::query()
            ->whereIn('mina_id', Mina::query()->whereIn('nombre', [$mineOneName, $mineTwoName])->pluck('id'))
            ->count());
    }

    public function test_excel_master_desactiva_convalidacion_cuando_examen_deja_de_ser_compartido(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $suffix = Str::upper(Str::random(6));
        $examName = 'CURSO COMPARTIDO ' . $suffix;
        $mineOneName = 'MINA ' . $suffix . ' A';
        $mineTwoName = 'MINA ' . $suffix . ' B';
        $exam = $this->createExam($examName);
        $importer = app(PersonalMinaExcelImportService::class);

        $sharedPreview = $importer->preview($this->buildCatalogOnlyMasterExcel(
            $mineOneName, $mineTwoName, $examName, 'UNICO ' . $suffix
        ));
        $this->assertContains($examName, $sharedPreview['convalidacion_por_activar']);
        $importer->confirm($sharedPreview, $actor);
        $this->assertTrue((bool) $exam->fresh()->permite_convalidacion);

        $singlePreview = $importer->preview($this->buildSingleMineCatalogExcel($mineOneName, $examName));
        $this->assertContains($examName, $singlePreview['convalidacion_por_desactivar']);
        $this->assertContains($examName, $singlePreview['convalidacion_no_permitida']);
        $this->assertSame(1, $singlePreview['summary']['examenes_convalidables_por_desactivar']);

        $displayPreview = app(PersonalMinaImportPreviewStore::class)->store($singlePreview, (string) $actor->id);
        $this->withSession(array_merge($this->sessionFor($actor->id), ['habilitacion_mina_import_preview' => $displayPreview]))
            ->get(route('personal.habilitacion-minera.index'))
            ->assertOk()
            ->assertSee('Convalidación prevista por este Master')
            ->assertSee('No convalidables');

        $result = $importer->confirm($singlePreview, $actor);

        $this->assertSame(1, $result['examenes_convalidables_desactivados']);
        $this->assertFalse((bool) $exam->fresh()->permite_convalidacion);
        $this->assertDatabaseHas('mina_requisitos', [
            'mina_id' => Mina::query()->where('nombre', $mineOneName)->firstOrFail()->id,
            'examen_id' => $exam->id,
            'permite_convalidacion_mina' => false,
            'convalidar_desde_otras_minas' => false,
        ]);

        $repeatPreview = $importer->preview($this->buildSingleMineCatalogExcel($mineOneName, $examName));
        $this->assertSame(0, $importer->confirm($repeatPreview, $actor)['examenes_convalidables_desactivados']);
    }

    public function test_excel_master_ignora_hojas_ocultas_y_no_crea_minas_auxiliares(): void
    {
        $service = app(PersonalMinaExcelImportService::class);
        $file = $this->buildMasterWithHiddenAuxiliarySheet();

        $preview = $service->preview($file);

        $this->assertContains('MINA VISIBLE', $preview['sheets']['detectadas']);
        $this->assertNotContains('Hoja1', $preview['sheets']['detectadas']);
        $this->assertTrue(collect($preview['sheets']['omitidas'])->contains(
            fn ($sheet) => $sheet['hoja'] === 'Hoja1' && str_contains($sheet['motivo'], 'oculta')
        ));

        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service->confirm($preview, $actor);

        $this->assertDatabaseHas('minas', ['nombre' => 'MINA VISIBLE']);
        $this->assertDatabaseMissing('minas', ['nombre' => 'Hoja1']);
    }

    public function test_excel_master_reemplaza_catalogo_vigente_por_mina_sin_borrar_historial(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $importer = app(PersonalMinaExcelImportService::class);
        $mine = $this->createMine('Mina catalogo');
        $oldExam = $this->createExam('EXAMEN ANTIGUO ' . Str::upper(Str::random(5)));
        $newExamName = 'EXAMEN VIGENTE ' . Str::upper(Str::random(5));
        $requirement = $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $oldExam->id,
            'obligatorio' => true,
        ], $actor);
        $worker = $this->createPersonal();
        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor)->load('examenes');
        $historicalExamId = $assignment->examenes->firstOrFail()->id;
        $file = $this->buildSingleMineCatalogExcel($mine->nombre, $newExamName);

        $preview = $importer->preview($file);

        $this->assertSame(1, $preview['summary']['requisitos_obsoletos_por_desactivar']);
        $this->assertTrue(collect($preview['requisitos_obsoletos_por_mina'])
            ->flatMap(fn ($row) => $row['requisitos'])
            ->contains(fn ($row) => $row['id'] === $requirement->id));

        $result = $importer->confirm($preview, $actor);

        $this->assertSame(1, $result['examenes_mina_desactivados']);
        $this->assertSame(1, $result['examenes_catalogo_desactivados']);
        $this->assertFalse((bool) $oldExam->fresh()->activo);
        $this->assertDatabaseHas('mina_requisitos', ['id' => $requirement->id, 'activo' => false]);
        $this->assertDatabaseHas('personal_mina_examenes', ['id' => $historicalExamId]);
        $this->assertDatabaseHas('mina_requisitos', [
            'mina_id' => $mine->id,
            'nombre' => $newExamName,
            'activo' => true,
        ]);

        $returnPreview = $importer->preview($this->buildSingleMineCatalogExcel($mine->nombre, $oldExam->nombre));
        $returnResult = $importer->confirm($returnPreview, $actor);

        $this->assertSame(1, $returnResult['examenes_mina_reactivados']);
        $this->assertTrue((bool) $oldExam->fresh()->activo);
        $this->assertDatabaseHas('mina_requisitos', ['id' => $requirement->id, 'activo' => true]);
        $this->assertDatabaseHas('personal_mina_examenes', ['id' => $historicalExamId]);
        $this->assertDatabaseHas('mina_requisitos', [
            'mina_id' => $mine->id,
            'nombre' => $newExamName,
            'activo' => false,
        ]);
    }

    public function test_excel_master_observa_variante_tipografica_sin_crear_duplicado_ni_desactivar_original(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $suffix = Str::upper(Str::random(5));
        $officialName = 'CURSO DE IZAJE ' . $suffix;
        $detectedName = 'CURSO DE IZAGE ' . $suffix;
        $official = $this->createExam($officialName);
        $file = $this->buildSingleMineCatalogExcel('MINA EQUIVALENCIA', $detectedName);
        $importer = app(PersonalMinaExcelImportService::class);

        $preview = $importer->preview($file);

        $this->assertContains($detectedName, $preview['examenes_no_encontrados']);
        $this->assertContains($officialName, $preview['examenes_ausentes_en_excel']);

        try {
            $importer->confirm($preview, $actor);
            $this->fail('Una errata sin asociacion confirmada no debe crear otro curso.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
        }

        $this->assertSame($officialName, $official->fresh()->nombre);
        $this->assertTrue((bool) $official->fresh()->activo);
        $this->assertDatabaseMissing('examenes_mineros', [
            'nombre' => $detectedName,
            'activo' => true,
        ]);
    }

    public function test_excel_master_actualiza_solo_habilitacion_de_trabajador_existente_sin_pisar_datos_internos(): void
    {
        $userId = $this->createMiningOperator();
        $worker = $this->createPersonalWithDocument('77889900', [
            'nombre_completo' => 'NOMBRE INTERNO',
            'puesto' => 'Cargo interno',
            'ocupacion' => 'Ocupacion interna',
            'contrato' => 'INDETERMINADO',
            'estado' => 'ACTIVO',
        ]);
        $file = $this->buildMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = $this->storedImportPreview($userId);
        $this->assertSame(0, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(0, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(1, $preview['summary']['trabajadores_existentes']);
        $this->assertSame(1, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame('ACTUALIZAR_HABILITACION_EXISTENTE', $preview['rows'][0]['accion_importacion']);

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
                'confirmar_reemplazo' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $worker->refresh();
        $this->assertSame('NOMBRE INTERNO', $worker->nombre_completo);
        $this->assertSame('Cargo interno', $worker->puesto);
        $this->assertSame('Ocupacion interna', $worker->ocupacion);
        $this->assertSame('INDETERMINADO', $worker->contrato);
        $this->assertSame('ACTIVO', $worker->estado);
        $this->assertSame(1, Personal::query()->where('numero_documento', '77889900')->count());

        $mine = Mina::query()->where('nombre', 'OPERACION DINAMICA')->firstOrFail();
        $assignment = PersonalMina::query()
            ->where('personal_id', $worker->id)
            ->where('mina_id', $mine->id)
            ->firstOrFail();
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'estado' => PersonalMinaExamen::ESTADO_APROBADO,
            'observacion' => 'Carga inicial',
        ]);

        $exam = ExamenMinero::query()->where('nombre', 'EXAMEN DINAMICO')->firstOrFail();
        $this->assertDatabaseMissing('examen_minero_precios', ['examen_id' => $exam->id]);
    }

    public function test_excel_master_trata_apto_con_restricciones_legacy_como_habilitante(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $worker = $this->createPersonalWithDocument('77889900');
        $importer = app(PersonalMinaExcelImportService::class);
        $preview = $importer->preview($this->buildMasterExcel('APTO_CON_RESTRICCIONES', '', 'EMO DINAMICO'));

        $importer->confirm($preview, $actor);

        $assignment = PersonalMina::query()->where('personal_id', $worker->id)->sole();
        $exam = $assignment->examenes()->sole();
        $this->assertSame(PersonalMinaExamen::ESTADO_APROBADO, $exam->estado);
        $this->assertSame(PersonalMinaExamenIntento::RESULTADO_APROBADO, $exam->resultado);
        $this->assertSame('APTO CON RESTRICCION', $exam->observacion);
        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->fresh()->estado_habilitacion);
    }

    public function test_confirmacion_excel_masiva_no_emite_notificaciones_por_cada_estado_intermedio(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $this->createPersonalWithDocument('77889900');
        $notifications = $this->mock(OperationalNotificationService::class);
        $notifications->shouldNotReceive('habilitacionAsignacion');

        $importer = app(PersonalMinaExcelImportService::class);
        $preview = $importer->preview($this->buildMasterExcel());
        $result = $importer->confirm($preview, $actor);

        $this->assertSame(1, $result['trabajadores_actualizados']);
        $this->assertSame(1, $result['asignaciones_creadas']);
    }

    public function test_excel_master_real_con_hojas_por_mina_importa_evaluaciones_y_multiples_minas(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createMiningOperator();
        $this->createPersonalWithDocument('77889911', [
            'nombre_completo' => 'TRABAJADOR MULTIMINA',
            'puesto' => 'Cargo interno multiple',
            'ocupacion' => 'Ocupacion interna multiple',
        ]);
        $file = $this->buildRealMasterExcel();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = $this->storedImportPreview($userId);
        $this->assertSame(2, $preview['summary']['trabajadores_asignados_a_minas']);
        $this->assertSame(0, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(0, $preview['summary']['filas_con_error']);
        $this->assertNotContains('RESUMEN GRAL', collect($preview['rows'])->pluck('hoja')->all());

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
                'confirmar_reemplazo' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $worker = Personal::query()->where('numero_documento', '77889911')->firstOrFail();
        $marcobre = Mina::query()->where('nombre', 'MARCOBRE')->firstOrFail();
        $cerroVerde = Mina::query()->where('nombre', 'CERRO VERDE')->firstOrFail();

        $this->assertDatabaseHas('personal_mina', [
            'personal_id' => $worker->id,
            'mina_id' => $marcobre->id,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('personal_mina', [
            'personal_id' => $worker->id,
            'mina_id' => $cerroVerde->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'activo' => true,
        ]);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'EXAMEN MEDICO']);
        $this->assertDatabaseHas('examenes_mineros', ['nombre' => 'INDUCCION']);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_vencimiento' => '2027-05-30',
        ]);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'estado' => PersonalMinaExamen::ESTADO_VENCIDO,
            'fecha_vencimiento' => '2026-05-12',
        ]);
    }

    public function test_excel_master_normaliza_dni_de_siete_digitos_y_actualiza_habilitacion_existente(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createMiningOperator();
        $workerId = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $workerId,
            'dni' => '09344260',
            'tipo_documento' => 'DNI',
            'numero_documento' => '09344260',
            'nombre_completo' => 'TRABAJADOR CON CERO',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => 'ACTIVO',
            'telefono' => '999999999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $file = $this->buildMasterExcelWithSevenDigitDni();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = $this->storedImportPreview($userId);
        $this->assertSame(0, $preview['summary']['trabajadores_nuevos']);
        $this->assertSame(0, $preview['summary']['trabajadores_no_encontrados']);
        $this->assertSame(1, $preview['summary']['trabajadores_existentes']);
        $this->assertSame(1, $preview['summary']['dni_7_digitos_corregidos']);
        $this->assertSame(0, $preview['summary']['conflictos']);
        $this->assertSame('09344260', $preview['rows'][0]['documento']);
        $this->assertTrue($preview['rows'][0]['documento_corregido_con_cero']);
        $this->assertNotContains('EXAMEN MEDICO / OBS', collect($preview['unmapped'])->pluck('columna')->all());
        $this->assertNotContains('N°', collect($preview['unmapped'])->pluck('columna')->all());
        $this->assertNotContains('OCUPACION', collect($preview['unmapped'])->pluck('columna')->all());

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
                'confirmar_reemplazo' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Personal::query()->where('numero_documento', '09344260')->count());
        $this->assertDatabaseMissing('personal', ['numero_documento' => '9344260']);
        $worker = Personal::query()->findOrFail($workerId);
        $this->assertSame('TRABAJADOR CON CERO', $worker->nombre_completo);
        $this->assertSame('Operario', $worker->puesto);
        $this->assertSame('Operario', $worker->ocupacion);
        $this->assertSame('FIJO', $worker->contrato);
        $this->assertSame('ACTIVO', $worker->estado);

        $mine = Mina::query()->where('nombre', 'BOROO')->firstOrFail();
        $assignment = PersonalMina::query()
            ->where('personal_id', $workerId)
            ->where('mina_id', $mine->id)
            ->firstOrFail();

        $this->assertSame(PersonalMina::ESTADO_HABILITADO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'nombre_snapshot' => 'EXAMEN MEDICO',
            'estado' => PersonalMinaExamen::ESTADO_VIGENTE,
            'fecha_programacion' => '2026-06-01',
            'fecha_vencimiento' => '2027-06-01',
            'lugar_snapshot' => 'CLINICA SAN MARTIN',
            'observacion' => 'Apto importado',
        ]);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'nombre_snapshot' => 'BLOQUEO',
            'estado' => PersonalMinaExamen::ESTADO_NO_APLICA,
            'observacion' => null,
        ]);
    }

    public function test_excel_master_programar_emo_queda_en_proceso_y_accion_pendiente(): void
    {
        Carbon::setTestNow('2026-06-08 09:00:00');

        $userId = $this->createMiningOperator();
        $worker = $this->createPersonalWithDocument('77889922');
        $file = $this->buildMasterExcelProgramarEmo();

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.habilitacion-minera.import.preview'), [
                'archivo' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('habilitacion_mina_import_preview');

        $preview = $this->storedImportPreview($userId);
        $this->assertSame(PersonalMinaExamen::ESTADO_PROGRAMADO, data_get($preview, 'rows.0.examenes.0.preview.estado_mapeado'));
        $this->assertSame('PROGRAMAR_EXAMEN', data_get($preview, 'rows.0.examenes.0.preview.accion_pendiente'));

        $this->withSession(array_merge($this->sessionFor($userId), ['habilitacion_mina_import_preview' => $preview]))
            ->post(route('personal.habilitacion-minera.import.confirm'), [
                'token' => $preview['token'],
                'confirmar_reemplazo' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $mine = Mina::query()->where('nombre', 'OPERACION PROGRAMACION')->firstOrFail();
        $assignment = PersonalMina::query()
            ->where('personal_id', $worker->id)
            ->where('mina_id', $mine->id)
            ->firstOrFail();
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertDatabaseHas('personal_mina_examenes', [
            'personal_mina_id' => $assignment->id,
            'nombre_snapshot' => 'EXAMEN MEDICO',
            'estado' => PersonalMinaExamen::ESTADO_PROGRAMADO,
        ]);
    }

    public function test_desaprobacion_no_bloquea_asignar_otra_mina_y_permite_nuevos_intentos(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['habilitacion_minera' => ['asignar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $exam = $this->createExam('Examen bloqueante', ['permite_reintento' => false, 'max_intentos' => 1]);
        $mineOne = $this->createMine('Operacion uno');
        $mineTwo = $this->createMine('Operacion dos');

        foreach ([$mineOne, $mineTwo] as $mine) {
            $service->storeRequirement([
                'mina_id' => $mine->id,
                'examen_id' => $exam->id,
                'obligatorio' => true,
            ]);
        }

        $assignment = $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mineOne->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
        $service->registerAttempt($assignment->examenes->first(), [
            'resultado' => PersonalMinaExamenIntento::RESULTADO_DESAPROBADO,
        ], null, $actor);

        $board = collect($service->mineStatusBoardFor($worker));
        $target = $board->first(fn ($item) => $item['mine']->id === $mineTwo->id);

        $this->assertNotSame('BLOQUEADA', $target['state']);
        $this->assertStringNotContainsString('desaprobo', mb_strtolower((string) $target['reason']));

        $this->withSession($this->sessionFor($actor->id))
            ->post(route('personal.habilitacion-minera.assign'), [
                'personal_id' => $worker->id,
                'mina_id' => $mineTwo->id,
                'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('personal_mina', [
            'personal_id' => $worker->id,
            'mina_id' => $mineTwo->id,
            'activo' => true,
        ]);
    }

    public function test_recalcular_corrige_habilitado_con_requisitos_pero_sin_examenes_generados(): void
    {
        $actor = Usuario::query()->findOrFail($this->createMiningOperator());
        $service = app(PersonalMinaHabilitacionService::class);
        $worker = $this->createPersonal();
        $mine = $this->createMine('Mina configurada sin generados');
        $exam = $this->createExam('Examen pendiente de generar');

        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        $assignmentId = (string) Str::uuid();
        DB::table('personal_mina')->insert([
            'id' => $assignmentId,
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado' => PersonalMina::ESTADO_HABILITADO,
            'estado_habilitacion' => PersonalMina::ESTADO_HABILITADO,
            'fecha_asignacion' => '2026-06-08',
            'fecha_inicio_proceso' => '2026-06-08',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $service->syncCurrentInformation($actor);
        $assignment = PersonalMina::query()->with('examenes')->findOrFail($assignmentId);

        $this->assertGreaterThanOrEqual(1, $result['examenes_generados']);
        $this->assertSame(PersonalMina::ESTADO_EN_PROCESO, $assignment->estado_habilitacion);
        $this->assertCount(1, $assignment->examenes);
    }

    public function test_snapshot_de_precio_caida_a_programacion_y_realizacion_si_no_hay_fecha_registro(): void
    {
        $actor = Usuario::query()->findOrFail($this->createUser(['personal' => ['actualizar']]));
        $service = app(PersonalMinaHabilitacionService::class);
        $exam = $service->storeMiningExam([
            'nombre' => 'Control precio prioridad',
            'empresa_paga' => true,
            'precio' => 80,
            'moneda' => 'PEN',
            'precio_desde' => '2026-01-01',
            'max_intentos' => 2,
        ], $actor);
        $service->storeExamPrice($exam, [
            'precio' => 120,
            'moneda' => 'PEN',
            'fecha_inicio' => '2026-06-05',
        ], $actor);
        $assignment = $this->assignmentWithExam($service, $actor, $exam);
        $workerExam = $assignment->examenes->first();

        $bySchedule = $service->resolveAttemptPriceSnapshot($workerExam, null, '2026-06-05', '2026-06-01');
        $byDone = $service->resolveAttemptPriceSnapshot($workerExam, null, null, '2026-06-05');

        $this->assertSame('2026-06-05', $bySchedule['fecha']);
        $this->assertSame('120.00', (string) $bySchedule['precio']);
        $this->assertSame('2026-06-05', $byDone['fecha']);
        $this->assertSame('120.00', (string) $byDone['precio']);
    }

    public function test_permisos_y_nombres_propios(): void
    {
        $denied = $this->createUser(['personal' => ['ver']]);

        $this->withSession($this->sessionFor($denied))
            ->post(route('personal.habilitacion-minera.examenes.store'), [
                'nombre' => 'Curso protegido',
                'tipo' => 'Curso',
                'max_intentos' => 1,
            ])
            ->assertForbidden();

        $files = [
            app_path('Models/ExamenMineroPrecio.php'),
            app_path('Modules/Personal/Services/PersonalMinaExcelImportService.php'),
            app_path('Modules/Personal/Services/PersonalMinaHabilitacionService.php'),
            app_path('Modules/Personal/Controllers/PersonalMinaHabilitacionController.php'),
            resource_path('views/personal/habilitacion-minera/index.blade.php'),
            database_path('migrations/2026_06_06_000300_refine_mining_exam_flow.php'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/elida|diego/i', file_get_contents($file), $file);
        }
        $this->assertDoesNotMatchRegularExpression(
            '/marcobre|cerro verde|chinalco|cuajone|toquepala|orcopampa|boroo/i',
            file_get_contents(app_path('Modules/Personal/Services/PersonalMinaExcelImportService.php')),
            'El importador no debe depender de nombres de minas quemados.'
        );
    }

    private function useUtcClockBeforeLimaMidnight(): void
    {
        $originalTimezone = date_default_timezone_get();
        $this->beforeApplicationDestroyed(fn () => date_default_timezone_set($originalTimezone));
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'America/Lima']);
        $this->travelTo(Carbon::parse('2026-10-07 02:00:00', 'UTC'));
    }

    private function assignmentWithExam(PersonalMinaHabilitacionService $service, Usuario $actor, ExamenMinero $exam): PersonalMina
    {
        $mine = $this->createMine('Mina precio');
        $worker = $this->createPersonal();
        $service->storeRequirement([
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'obligatorio' => true,
        ]);

        return $service->assignMine([
            'personal_id' => $worker->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
        ], $actor);
    }

    private function buildMasterExcel(
        string $result = 'Aprobado',
        string $observation = 'Carga inicial',
        string $examName = 'Examen dinamico',
    )
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Operacion dinamica');
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C2', 'Cargo');
        foreach (['D1', 'E1', 'F1', 'G1'] as $cell) {
            $sheet->setCellValue($cell, $examName);
        }
        $sheet->setCellValue('D2', 'Fecha de realizacion');
        $sheet->setCellValue('E2', 'Resultado');
        $sheet->setCellValue('F2', 'Observacion');
        $sheet->setCellValue('G2', 'Precio');
        $sheet->setCellValue('A3', '77889900');
        $sheet->setCellValue('B3', 'Trabajador Importado');
        $sheet->setCellValue('C3', 'Operario');
        $sheet->setCellValue('D3', '2026-06-06');
        $sheet->setCellValue('E3', $result);
        $sheet->setCellValue('F3', $observation);
        $sheet->setCellValue('G3', 450);

        $path = storage_path('app/testing/master-habilitacion-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildCatalogOnlyMasterExcel(string $mineOne, string $mineTwo, string $sharedExam, string $singleMineExam)
    {
        $spreadsheet = new Spreadsheet();
        $first = $spreadsheet->getActiveSheet();
        $first->setTitle($mineOne);
        $first->setCellValue('A2', 'DNI');
        $first->setCellValue('B2', 'Nombre completo');
        $first->setCellValue('C1', $sharedExam);
        $first->setCellValue('D1', $sharedExam);
        $first->setCellValue('E1', $singleMineExam);
        $first->setCellValue('F1', $singleMineExam);
        $first->setCellValue('C2', 'Fecha de realizacion');
        $first->setCellValue('D2', 'Resultado');
        $first->setCellValue('E2', 'Fecha de realizacion');
        $first->setCellValue('F2', 'Resultado');

        $second = $spreadsheet->createSheet();
        $second->setTitle($mineTwo);
        $second->setCellValue('A2', 'DNI');
        $second->setCellValue('B2', 'Nombre completo');
        $second->setCellValue('C1', $sharedExam);
        $second->setCellValue('D1', $sharedExam);
        $second->setCellValue('C2', 'Fecha de realizacion');
        $second->setCellValue('D2', 'Resultado');

        $path = storage_path('app/testing/master-catalogo-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildSingleMineCatalogExcel(string $mineName, string $examName)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($mineName, 0, 31));
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C1', $examName);
        $sheet->setCellValue('D1', $examName);
        $sheet->setCellValue('C2', 'Fecha de realizacion');
        $sheet->setCellValue('D2', 'Resultado');

        $path = storage_path('app/testing/master-una-mina-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-una-mina.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildMasterWithHiddenAuxiliarySheet()
    {
        $spreadsheet = new Spreadsheet();
        $visible = $spreadsheet->getActiveSheet();
        $visible->setTitle('MINA VISIBLE');
        $visible->setCellValue('A2', 'DNI');
        $visible->setCellValue('B2', 'Nombre completo');
        $visible->setCellValue('C1', 'EXAMEN VISIBLE');
        $visible->setCellValue('D1', 'EXAMEN VISIBLE');
        $visible->setCellValue('C2', 'Fecha de realizacion');
        $visible->setCellValue('D2', 'Resultado');

        $hidden = $spreadsheet->createSheet();
        $hidden->setTitle('Hoja1');
        $hidden->setSheetState('hidden');
        $hidden->setCellValue('A2', 'DNI');
        $hidden->setCellValue('B2', 'Nombre completo');
        $hidden->setCellValue('C1', 'EXAMEN OCULTO');
        $hidden->setCellValue('D1', 'EXAMEN OCULTO');
        $hidden->setCellValue('C2', 'Fecha de realizacion');
        $hidden->setCellValue('D2', 'Resultado');

        $path = storage_path('app/testing/master-hoja-oculta-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-hoja-oculta.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildMasterExcelProgramarEmo()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Operacion programacion');
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C2', 'Cargo');
        foreach (['D1', 'E1'] as $cell) {
            $sheet->setCellValue($cell, 'Examen medico');
        }
        $sheet->setCellValue('D2', 'Estado EMO');
        $sheet->setCellValue('E2', 'Observacion');
        $sheet->setCellValue('A3', '77889922');
        $sheet->setCellValue('B3', 'Trabajador Programado');
        $sheet->setCellValue('C3', 'Operario');
        $sheet->setCellValue('D3', 'PROGRAMAR EMO');
        $sheet->setCellValue('E3', 'Pendiente de cita');

        $path = storage_path('app/testing/master-programar-emo-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-programar-emo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildRealMasterExcel()
    {
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('RESUMEN GRAL');
        $summary->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'MARCOBRE', 'CERRO VERDE'],
            ['77889911', 'TRABAJADOR MULTIMINA', 'HABILITADO', 'EN PROCESO'],
        ]);

        $marcobre = $spreadsheet->createSheet();
        $marcobre->setTitle('MARCOBRE (2)');
        $marcobre->mergeCells('H1:J1');
        $marcobre->mergeCells('K1:M1');
        $marcobre->setCellValue('H1', 'EXAMEN MEDICO');
        $marcobre->setCellValue('K1', 'INDUCCION');
        $marcobre->fromArray([
            ['N°', 'ESTADO / HABILITACION', 'DNI', 'APELLIDOS Y NOMBRES', 'CARGO', 'CELULAR PARTICULAR', 'CC', 'F PROG.', 'F. VTO', 'ESTADO EMO', 'F PROG.', 'F. VTO', 'ESTADO / INDUCCION'],
            ['1', 'HABILITADO', '77889911', 'TRABAJADOR MULTIMINA', 'OPERARIO', '999111222', '101100001', '2026-05-30', '2027-05-30', 'VIGENTE', '2026-06-01', '2027-06-01', 'VIGENTE'],
        ], null, 'A2');

        $cerro = $spreadsheet->createSheet();
        $cerro->setTitle('CERRO VERDE CERRO');
        $cerro->mergeCells('H1:J1');
        $cerro->setCellValue('H1', 'EXAMEN MEDICO');
        $cerro->fromArray([
            ['N°', 'ESTADO ACRED.', 'DNI', 'APELLIDOS Y NOMBRES', 'CARGO', 'CELULAR PARTICULAR', 'CC', 'F PROG.', 'F. VTO', 'ESTADO EMO'],
            ['1', 'EN PROCESO', '77889911', 'TRABAJADOR MULTIMINA', 'OPERARIO', '999111222', '101100001', '2026-01-12', '2026-05-12', 'VENCIDO'],
        ], null, 'A2');

        $path = storage_path('app/testing/master-real-habilitacion-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-real.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function buildMasterExcelWithSevenDigitDni()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BOROO');
        $sheet->mergeCells('R1:V1');
        $sheet->mergeCells('W1:Y1');
        $sheet->setCellValue('R1', 'EXAMEN MEDICO');
        $sheet->setCellValue('W1', 'BLOQUEO');
        $sheet->fromArray([
            [
                'N°',
                'ESTADO ACRED.',
                'ESTADO',
                'RESPONSABLE',
                'TIPO CONTRATO',
                'OCUPACION',
                'CC',
                'DNI',
                'APELLIDOS Y NOMBRES',
                'CARGO',
                'CARGO CONTEO',
                'CELULAR PARTICULAR',
                'RESIDENCIA',
                'FECHA FIN',
                'ESTADO DE CONTRATO',
                'OBSR',
                'PASO',
                'F PROG.',
                'F. VTO',
                'ESTADO EMO',
                'OBS',
                'CLINICA',
                'F PROG.',
                'F. VTO',
                'ESTADO BLOQUEO',
            ],
            [
                '1',
                'HABILITADO',
                '',
                '',
                'INTER',
                'O',
                '101100022',
                '9344260',
                'NOMBRE DIFERENTE EN EXCEL',
                'OPERARIO',
                'OPERARIO',
                '999888777',
                'AREQUIPA',
                '',
                '',
                '',
                '',
                '2026-06-01',
                '2027-06-01',
                'VIGENTE',
                'Apto importado',
                'CLINICA SAN MARTIN',
                'NO APLICA',
                'NO APLICA',
                'NO APLICA',
            ],
        ], null, 'A2');

        $path = storage_path('app/testing/master-dni-cero-' . Str::random(8) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'master-dni-cero.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function legacyCourseRecord(Personal $worker, ExamenMinero $exam, Usuario $actor): \App\Models\PersonalCursoRegistro
    {
        return \App\Models\PersonalCursoRegistro::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $worker->id,
            'examen_id' => $exam->id, 'mina_id' => null,
            'fecha_realizacion' => '2026-01-10', 'resultado' => 'APROBADO',
            'catalogo_snapshot' => [], 'usuario_registro_id' => $actor->id,
            'origen' => 'Historico sin mina para prueba',
        ]);
    }

    private function createExam(string $name, array $overrides = []): ExamenMinero
    {
        $id = (string) Str::uuid();
        DB::table('examenes_mineros')->insert(array_merge([
            'id' => $id,
            'nombre' => mb_strtoupper($name),
            'tipo' => 'General',
            'requiere_lugar' => false,
            'empresa_paga' => false,
            'tiene_vigencia' => false,
            'permite_reintento' => true,
            'max_intentos' => 2,
            'critico' => false,
            'desaprueba_finaliza_proceso' => false,
            'requiere_nota' => false,
            'solo_resultado' => true,
            'permite_convalidacion' => false,
            'activo' => true,
            'orden' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return ExamenMinero::query()->findOrFail($id);
    }

    private function createMine(string $name): Mina
    {
        $id = (string) Str::uuid();
        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => mb_strtoupper($name) . ' ' . Str::upper(Str::random(4)),
            'unidad_minera' => $name,
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Mina::query()->findOrFail($id);
    }

    private function grantMineScope(Usuario $user, Mina $mine): void
    {
        DB::table('usuario_mina_scope')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $user->id,
            'mina_id' => $mine->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPersonal(): Personal
    {
        $id = (string) Str::uuid();
        $document = (string) random_int(73000000, 73999999);

        return $this->createPersonalWithDocument($document);
    }

    private function createPersonalWithDocument(string $document, array $overrides = []): Personal
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert(array_merge([
            'id' => $id,
            'dni' => $document,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_completo' => 'TRABAJADOR HABILITACION',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => 'ACTIVO',
            'telefono' => '999999999',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Personal::query()->findOrFail($id);
    }

    private function fakeImportPreview(): array
    {
        return [
            'token' => 'preview-test-token',
            'generated_at' => '2026-06-10T17:52:31+00:00',
            'summary' => [
                'filas_leidas' => 1,
                'trabajadores_existentes' => 1,
            ],
            'rows' => [],
            'errors' => [],
            'unmapped' => [],
            'conflicts' => [],
        ];
    }

    private function createMiningOperator(): string
    {
        return $this->createUser([
            'personal' => ['ver', 'actualizar'],
            'habilitacion_minera' => PermissionCatalog::availableModuleActions()['habilitacion_minera'],
        ]);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_HABILITACION_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function storedImportPreview(string $userId): array
    {
        $displayPreview = session('habilitacion_mina_import_preview');
        $this->assertIsArray($displayPreview);

        $preview = app(PersonalMinaImportPreviewStore::class)->retrieve((string) ($displayPreview['token'] ?? ''), $userId);
        $this->assertIsArray($preview);

        return $preview;
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'habilitacion@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
