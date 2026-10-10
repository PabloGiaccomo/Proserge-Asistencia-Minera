<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Personal;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Services\EmoAppointmentService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Modules\Personal\Support\CompleteEmoData;
use App\Modules\Personal\Support\EmoOrigin;
use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ReprogramEmoData;
use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningTransactionVisibilityTest extends TestCase
{
    use MiningCatalogFixtures;

    private Personal $worker;

    private Usuario $evaluator;

    private PersonalMinaHabilitacionService $service;

    private array $mineIds = [];

    private array $examIds = [];

    private string $primaryConnection;

    private array $clinicIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->primaryConnection = DB::getDefaultConnection();
        config(['database.connections.mining_writer' => config('database.connections.'.$this->primaryConnection)]);
        DB::connection()->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::connection('mining_writer')->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00', 'America/Lima'));
        $this->app->instance(OperationalNotificationService::class,
            \Mockery::mock(OperationalNotificationService::class)->shouldIgnoreMissing());
        $this->service = app(PersonalMinaHabilitacionService::class);

        // Fixtures must be committed so the second connection can see them.
        DB::transaction(function (): void {
            $this->evaluator = $this->actor();
            $document = (string) random_int(74000000, 74999999);
            $this->worker = Personal::query()->create([
                'id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document,
                'tipo_documento' => 'DNI', 'nombre_completo' => 'PRUEBA VISIBILIDAD '.Str::random(8),
                'puesto' => 'Operario', 'ocupacion' => 'Operario', 'estado' => 'ACTIVO',
                'contrato' => 'FIJO', 'qr_code' => 'TEST-'.Str::uuid(), 'es_supervisor' => false,
            ]);
        });
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->primaryConnection)) {
                DB::setDefaultConnection($this->primaryConnection);
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if (isset($this->worker)) {
                    DB::transaction(function (): void {
                        $assignments = DB::table('personal_mina')->where('personal_id', $this->worker->id)->pluck('id');
                        $exams = DB::table('personal_mina_examenes')->whereIn('personal_mina_id', $assignments)->pluck('id');
                        DB::table('personal_mina_examen_intentos')->whereIn('personal_mina_examen_id', $exams)->delete();
                        DB::table('personal_mina_historial')->whereIn('personal_mina_id', $assignments)->delete();
                        DB::table('personal_mina_examenes')->whereIn('id', $exams)->delete();
                        DB::table('personal_mina')->whereIn('id', $assignments)->delete();
                        DB::table('personal_curso_registros')->where('personal_id', $this->worker->id)->delete();
                        $appointments = DB::table('personal_emo_citas')->where('personal_id', $this->worker->id)->pluck('id');
                        DB::table('personal_emo_cita_historial')->whereIn('emo_cita_id', $appointments)->delete();
                        DB::table('personal_emo_citas')->whereIn('id', $appointments)->delete();
                        DB::table('clinicas_emo')->whereIn('id', $this->clinicIds)->delete();
                        DB::table('mina_requisitos')->whereIn('mina_id', $this->mineIds)->delete();
                        DB::table('minas')->whereIn('id', $this->mineIds)->delete();
                        DB::table('examen_minero_precios')->whereIn('examen_id', $this->examIds)->delete();
                        DB::table('examenes_mineros')->whereIn('id', $this->examIds)->delete();
                        DB::table('personal')->where('id', $this->worker->id)->delete();
                        DB::table('usuarios')->where('id', $this->evaluator->id)->delete();
                        DB::table('roles')->where('id', $this->evaluator->rol_id)->delete();
                    });
                }
                DB::purge('mining_writer');
                DB::purge($this->primaryConnection);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_convalidation_rechecks_current_locked_assignment_not_old_snapshot(): void
    {
        $origin = $this->assignment()->examenes->sole();
        $target = $this->assignment($origin->examen_id)->examenes->sole();
        $this->service->registerAttempt($origin, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01',
        ], null, $this->evaluator);
        $this->startSnapshot();
        $this->write(fn () => $this->service->deactivateAssignment($target->asignacion, $this->evaluator));

        try {
            $this->service->convalidateExam($target, $origin->id, [], $this->evaluator);
            $this->fail('A committed deactivation must prevent convalidation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('convalidacion', $exception->errors());
        }
        $this->assertFalse(PersonalMinaExamen::query()->lockForUpdate()->findOrFail($target->id)->es_convalidado);
    }

    public function test_sync_preserves_new_approval_committed_after_outer_snapshot(): void
    {
        $assignment = $this->assignment();
        $workerExam = $assignment->examenes->sole();
        $record = PersonalCursoRegistro::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $this->worker->id,
            'mina_id' => $assignment->mina_id, 'examen_id' => $workerExam->examen_id,
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-09-01',
            'catalogo_snapshot' => [], 'usuario_registro_id' => $this->evaluator->id,
            'origen' => 'Prueba de visibilidad',
        ]);
        $this->startSnapshot();
        $this->write(fn () => $this->service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01',
        ], null, $this->evaluator));

        $this->service->syncCourseRecordToMines($record, $this->evaluator);

        $fresh = PersonalMinaExamen::query()->lockForUpdate()->findOrFail($workerExam->id);
        $this->assertSame('2026-10-01', $fresh->fecha_realizacion->toDateString());
        $this->assertSame([1, 2], $fresh->intentos()->orderBy('numero_intento')->lockForUpdate()->pluck('numero_intento')->all());
    }

    public function test_generation_sees_exam_created_after_outer_snapshot(): void
    {
        $assignment = $this->assignment();
        $exam = $this->exam('VISIBILIDAD EXTRA '.Str::uuid());
        $this->examIds[] = $exam->id;
        $this->requirement($assignment->mina, $exam);
        $this->startSnapshot();
        $this->write(fn () => $this->service->generateRequiredExams($assignment, $this->evaluator, false));

        $this->assertSame(0, $this->service->generateRequiredExams($assignment, $this->evaluator, false));
        $this->assertCount(2, $assignment->examenes()->lockForUpdate()->get());
    }

    public function test_attempt_number_uses_current_read_after_outer_snapshot(): void
    {
        $workerExam = $this->assignment()->examenes->sole();
        $this->startSnapshot();
        $payload = ['resultado' => 'DESAPROBADO', 'fecha_realizacion' => '2026-10-01'];
        $this->write(fn () => $this->service->registerAttempt($workerExam, $payload, null, $this->evaluator));

        $this->service->registerAttempt($workerExam, $payload, null, $this->evaluator);

        $this->assertSame([1, 2], $workerExam->intentos()->orderBy('numero_intento')->lockForUpdate()->pluck('numero_intento')->all());
    }

    public function test_refresh_does_not_downgrade_newly_committed_approval(): void
    {
        $assignment = $this->assignment();
        $workerExam = $assignment->examenes->sole();
        $this->startSnapshot();
        $this->write(fn () => $this->service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01',
        ], null, $this->evaluator));

        $this->assertFalse($this->service->refreshAssignmentFromExams($assignment, $this->evaluator, false));
        $this->assertSame('HABILITADO', PersonalMina::query()->lockForUpdate()->findOrFail($assignment->id)->estado_habilitacion);
    }

    public function test_assignment_update_checks_current_exams_after_outer_snapshot(): void
    {
        $assignment = $this->assignment();
        $workerExam = $assignment->examenes->sole();
        $this->startSnapshot();
        $this->write(fn () => $this->service->registerAttempt($workerExam, [
            'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-01',
        ], null, $this->evaluator));

        $updated = $this->service->updateAssignment($assignment, ['estado_habilitacion' => 'HABILITADO'], $this->evaluator);

        $this->assertSame('HABILITADO', $updated->estado_habilitacion);
    }

    public function test_emo_scheduling_keeps_committed_file_when_after_commit_notification_throws(): void
    {
        Storage::fake('local');
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO']);
        $clinic = $this->emoClinic();
        $notifications = \Mockery::mock(OperationalNotificationService::class)->shouldIgnoreMissing();
        $notifications->shouldReceive('examenProgramado')->andThrow(new \RuntimeException('Notification failed after commit'));
        $this->app->instance(OperationalNotificationService::class, $notifications);

        try {
            $this->service->registerAttempt($exam, [
                'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-06', 'clinica_id' => $clinic->id,
            ], UploadedFile::fake()->create('evidencia-programacion.pdf', 10, 'application/pdf'), $this->evaluator);
            $this->fail('El fallo post-commit debe permanecer visible al llamador.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Notification failed after commit', $exception->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $committed = PersonalEmoCita::on('mining_writer')->where('personal_id', $this->worker->id)->sole();
        $this->assertSame('PROGRAMADA', $committed->estado);
        $attempt = $committed->intentos()->sole();
        $this->assertSame('evidencia-programacion.pdf', $attempt->archivo_nombre_original);
        Storage::disk('local')->assertExists($attempt->archivo_path);
    }

    public function test_emo_completion_keeps_committed_medical_file_when_after_commit_callback_throws(): void
    {
        Storage::fake('local');
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO']);
        $clinic = $this->emoClinic();
        $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator);
        $event = 'eloquent.updated: '.PersonalEmoCita::class;
        Event::listen($event, function (PersonalEmoCita $updated): void {
            if ($updated->estado === 'COMPLETADA') {
                DB::afterCommit(fn () => throw new \RuntimeException('Completion callback failed after commit'));
            }
        });
        try {
            app(EmoAppointmentService::class)->complete($appointment, new CompleteEmoData(
                CarbonImmutable::parse('2026-10-06', 'America/Lima'), EmoOutcome::APTO,
                file: UploadedFile::fake()->create('resultado-medico.pdf', 10, 'application/pdf'),
            ), $this->evaluator);
            $this->fail('El fallo post-commit debe permanecer visible al llamador.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Completion callback failed after commit', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, DB::transactionLevel());
        $committed = PersonalEmoCita::on('mining_writer')->findOrFail($appointment->id);
        $this->assertSame('COMPLETADA', $committed->estado);
        $this->assertSame('APTO', $committed->resultado_emo);
        $attempt = $committed->intentos()->sole();
        $this->assertSame('resultado-medico.pdf', $attempt->archivo_nombre_original);
        Storage::disk('local')->assertExists($attempt->archivo_path);
    }

    public function test_emo_two_real_transactions_different_clinics_leave_one_appointment_and_one_operational_error(): void
    {
        $this->assertTrue(class_exists(EmoAppointmentService::class), 'Falta serializar citas EMO por trabajador.');
        $first = $this->assignment()->examenes->sole();
        $first->examen->update(['categoria_operativa' => 'EMO']);
        $second = $this->assignment($first->examen_id)->examenes->sole();
        $clinic = $this->emoClinic();
        $other = $this->emoClinic();
        DB::beginTransaction();
        $appointment = app(EmoAppointmentService::class)->schedule($first, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator);
        $writer = $this->startEmoWriter('schedule', $second->id, $other->id);
        try {
            $this->assertEmoWriterWaiting($writer);
            DB::commit();
            $writer->wait();
            $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
            $this->assertStringContainsString('"error":"clinica_id"', $writer->getOutput());
            $this->assertSame(1, PersonalEmoCita::where('personal_id', $this->worker->id)->count());
            $this->assertSame($clinic->id, $appointment->fresh()->clinica_id);
            $this->assertSame(1, $first->intentos()->count());
            $this->assertSame(0, $second->intentos()->count());
        } finally {
            $writer->stop();
        }
    }

    public function test_emo_cancellation_and_stale_reprogramming_do_not_revive_cancelled_appointment(): void
    {
        $this->assertTrue(class_exists(EmoAppointmentService::class), 'Falta serializar transiciones de citas EMO.');
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO']);
        $clinic = $this->emoClinic();
        $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator);
        DB::beginTransaction();
        app(EmoAppointmentService::class)->cancel($appointment, 'Cambio operativo', $this->evaluator);
        $writer = $this->startEmoWriter('reprogram', $appointment->id, $clinic->id);
        try {
            $this->assertEmoWriterWaiting($writer);
            DB::commit();
            $writer->wait();
            $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
            $this->assertStringContainsString('"error":"cita"', $writer->getOutput());
            $this->assertSame('ANULADA', $appointment->fresh()->estado);
            $this->assertSame(1, $appointment->historial()->where('evento', 'ANULADA')->count());
            $this->assertSame(0, $appointment->historial()->where('evento', 'REPROGRAMADA')->count());
        } finally {
            $writer->stop();
        }
    }

    public function test_emo_current_read_detects_appointment_committed_after_outer_snapshot(): void
    {
        $this->assertTrue(class_exists(EmoAppointmentService::class), 'Falta lectura actual bajo bloqueo de citas EMO.');
        $first = $this->assignment()->examenes->sole();
        $first->examen->update(['categoria_operativa' => 'EMO']);
        $second = $this->assignment($first->examen_id)->examenes->sole();
        $clinic = $this->emoClinic();
        $other = $this->emoClinic();
        $this->startSnapshot();
        PersonalEmoCita::where('personal_id', $this->worker->id)->get();
        $this->write(fn () => app(EmoAppointmentService::class)->schedule($first, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator));
        try {
            app(EmoAppointmentService::class)->schedule($second, new ScheduleEmoData(
                CarbonImmutable::parse('2026-10-06', 'America/Lima'), $other->id,
            ), $this->evaluator);
            $this->fail('La cita confirmada debe bloquear otra clinica aun con snapshot anterior.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('clinica_id', $exception->errors());
        }
        $this->assertCount(1, PersonalEmoCita::where('personal_id', $this->worker->id)->lockForUpdate()->get());
    }

    public static function staleEmoCommands(): array
    {
        return [['cancel'], ['complete']];
    }

    #[DataProvider('staleEmoCommands')]
    public function test_emo_waiting_command_rejects_aba_transition_with_identical_date_clinic_and_state(string $command): void
    {
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO']);
        $clinic = $this->emoClinic();
        $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator);
        DB::beginTransaction();
        $cancelled = app(EmoAppointmentService::class)->cancel($appointment, 'Cambio temporal', $this->evaluator);
        app(EmoAppointmentService::class)->reprogram($cancelled, new ReprogramEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id, 'Retomar fecha y clinica', EmoOrigin::MANUAL,
        ), $this->evaluator);
        // The writer reads the previously committed PROGRAMADA snapshot, then
        // waits for the worker lock while both transitions remain uncommitted.
        $writer = $this->startEmoWriter($command, $appointment->id, $clinic->id);
        try {
            $this->assertEmoWriterWaiting($writer);
            DB::commit();
            $writer->wait();
            $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
            $this->assertStringContainsString('"error":"cita"', $writer->getOutput());
            $current = $appointment->fresh();
            $this->assertSame('PROGRAMADA', $current->estado);
            $this->assertSame($appointment->revision + 2, $current->revision);
            $this->assertSame('PENDIENTE', $current->intentos()->sole()->resultado);
            $this->assertSame(1, $current->historial()->where('evento', 'ANULADA')->count());
            $this->assertSame(1, $current->historial()->where('evento', 'REPROGRAMADA')->count());
            $this->assertSame(0, $current->historial()->where('evento', 'COMPLETADA')->count());
        } finally {
            $writer->stop();
        }
    }

    public function test_emo_price_snapshot_uses_price_committed_after_outer_snapshot(): void
    {
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO', 'empresa_paga' => true]);
        $clinic = $this->emoClinic();
        $this->startSnapshot();
        DB::table('examen_minero_precios')->where('examen_id', $exam->examen_id)->get();
        $this->write(fn () => $this->service->storeExamPrice(ExamenMinero::findOrFail($exam->examen_id), [
            'precio' => '150.00', 'fecha_inicio' => '2026-10-01',
        ], $this->evaluator));
        $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator);
        $this->assertSame('150.00', $appointment->intentos()->lockForUpdate()->sole()->precio_aplicado);
    }

    public function test_emo_price_writer_waits_for_catalog_before_writing_price_history(): void
    {
        $exam = $this->assignment()->examenes->sole();
        DB::beginTransaction();
        ExamenMinero::whereKey($exam->examen_id)->sharedLock()->firstOrFail();
        $writer = $this->startEmoWriter('price', $exam->examen_id, '');
        try {
            $this->assertEmoWriterWaiting($writer);
            // An independent dirty-read observer proves that the blocked writer
            // has not inserted a price before acquiring its catalog lock.
            DB::connection('mining_writer')->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
            $this->assertSame(0, DB::connection('mining_writer')->table('examen_minero_precios')
                ->where('examen_id', $exam->examen_id)->count());
            DB::commit();
            $writer->wait();
            $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
            $this->assertStringContainsString('"success":true', $writer->getOutput());
            $this->assertSame('150.00', DB::table('examen_minero_precios')->where('examen_id', $exam->examen_id)->sole()->precio);
        } finally {
            $writer->stop();
            DB::connection('mining_writer')->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
    }

    public function test_emo_convalidation_snapshots_medical_result_committed_after_outer_snapshot(): void
    {
        $origin = $this->assignment()->examenes->sole();
        $origin->examen->update(['categoria_operativa' => 'EMO']);
        $target = $this->assignment($origin->examen_id)->examenes->sole();
        $clinic = $this->emoClinic();
        $this->startSnapshot();
        PersonalEmoCita::where('personal_id', $this->worker->id)->get();
        $this->write(fn () => $this->service->registerAttempt($origin, [
            'resultado' => 'APTO_CON_RESTRICCION', 'detalle_resultado_emo' => 'Usar lentes',
            'fecha_realizacion' => '2026-10-06', 'clinica_id' => $clinic->id,
        ], null, $this->evaluator));
        $updated = $this->service->convalidateExam($target, $origin->id, [], $this->evaluator);
        $this->assertSame('APTO_CON_RESTRICCION', $updated->contexto_convalidacion_json['resultado_emo'] ?? null);
        $this->assertSame('Usar lentes', $updated->contexto_convalidacion_json['detalle_resultado_emo'] ?? null);
    }

    public function test_emo_cancel_rechecks_requirement_committed_after_outer_snapshot(): void
    {
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO']);
        $clinic = $this->emoClinic();
        $appointment = app(EmoAppointmentService::class)->schedule($exam, new ScheduleEmoData(
            CarbonImmutable::parse('2026-10-06', 'America/Lima'), $clinic->id,
        ), $this->evaluator);
        $this->startSnapshot();
        DB::table('mina_requisitos')->where('id', $exam->mina_requisito_id)->get();
        $this->write(fn () => DB::table('mina_requisitos')->where('id', $exam->mina_requisito_id)->update(['activo' => false]));
        try {
            app(EmoAppointmentService::class)->cancel($appointment, 'Cambio operativo', $this->evaluator);
            $this->fail('La anulacion debe revalidar el requisito confirmado despues del snapshot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('examen', $exception->errors());
        }
        $this->assertSame('PROGRAMADA', PersonalEmoCita::whereKey($appointment->id)->lockForUpdate()->firstOrFail()->estado);
    }

    public function test_emo_nested_lock_timeout_is_an_operational_error_without_partial_writes(): void
    {
        $exam = $this->assignment()->examenes->sole();
        $exam->examen->update(['categoria_operativa' => 'EMO']);
        $clinic = $this->emoClinic();
        DB::beginTransaction();
        Personal::whereKey($this->worker->id)->lockForUpdate()->firstOrFail();
        $writer = $this->startEmoWriter('timeout', $exam->id, $clinic->id);
        try {
            $this->assertEmoWriterWaiting($writer);
            $writer->wait();
            $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
            $this->assertStringContainsString('"error":"cita"', $writer->getOutput());
            $this->assertSame(0, PersonalEmoCita::where('personal_id', $this->worker->id)->count());
            $this->assertSame(0, $exam->intentos()->count());
        } finally {
            $writer->stop();
            DB::rollBack();
        }
    }

    private function emoClinic(): ClinicaEmo
    {
        $name = 'Clinica carrera '.Str::uuid();
        $clinic = ClinicaEmo::create(['nombre' => $name, 'nombre_normalizado' => strtoupper($name), 'activo' => true]);
        $this->clinicIds[] = $clinic->id;

        return $clinic;
    }

    private function startEmoWriter(string $operation, string $id, string $clinicId): Process
    {
        $program = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        config(['database.default' => 'mysql', 'database.connections.mysql' => $input['connection']]);
        Illuminate\Support\Facades\DB::purge('mysql');
        if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'proserge_app_test') { exit(9); }
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-10-06 10:00:00', 'America/Lima'));
        $app->instance(App\Modules\Notificaciones\Services\OperationalNotificationService::class,
            Mockery::mock(App\Modules\Notificaciones\Services\OperationalNotificationService::class)->shouldIgnoreMissing());
        $actor = App\Models\Usuario::findOrFail($input['actor']);
        $subject = match ($input['operation']) {
            'schedule', 'timeout' => App\Models\PersonalMinaExamen::findOrFail($input['id']),
            'price' => App\Models\ExamenMinero::findOrFail($input['id']),
            default => App\Models\PersonalEmoCita::findOrFail($input['id']),
        };
        print 'READY:'.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
        flush();
        try {
            if ($input['operation'] === 'timeout') {
                Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
                Illuminate\Support\Facades\DB::beginTransaction();
            }
            $service = app(App\Modules\Personal\Services\EmoAppointmentService::class);
            if (in_array($input['operation'], ['schedule', 'timeout'], true)) {
                $service->schedule($subject, new App\Modules\Personal\Support\ScheduleEmoData(
                    Carbon\CarbonImmutable::parse('2026-10-06', 'America/Lima'), $input['clinic']), $actor);
            } elseif ($input['operation'] === 'price') {
                app(App\Modules\Personal\Services\PersonalMinaHabilitacionService::class)->storeExamPrice($subject,
                    ['precio' => '150.00', 'fecha_inicio' => '2026-10-01'], $actor);
            } elseif ($input['operation'] === 'cancel') {
                $service->cancel($subject, 'Contexto concurrente anterior', $actor);
            } elseif ($input['operation'] === 'complete') {
                $service->complete($subject, new App\Modules\Personal\Support\CompleteEmoData(
                    Carbon\CarbonImmutable::parse('2026-10-06', 'America/Lima'), App\Modules\Personal\Support\EmoOutcome::APTO), $actor);
            } else {
                $service->reprogram($subject, new App\Modules\Personal\Support\ReprogramEmoData(
                    Carbon\CarbonImmutable::parse('2026-10-07', 'America/Lima'), $input['clinic'],
                    'Reprogramacion concurrente', App\Modules\Personal\Support\EmoOrigin::MANUAL), $actor);
            }
            print json_encode(['success' => true]);
        } catch (Illuminate\Validation\ValidationException $exception) {
            print json_encode(['error' => array_key_first($exception->errors())]);
        } finally {
            while (Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                Illuminate\Support\Facades\DB::rollBack();
            }
        }
        PHP;
        $process = new Process([PHP_BINARY, '-r', $program, json_encode([
            'operation' => $operation, 'id' => $id, 'clinic' => $clinicId, 'actor' => $this->evaluator->id,
            'connection' => config('database.connections.'.$this->primaryConnection),
        ], JSON_THROW_ON_ERROR)], base_path());
        $process->setTimeout(15);
        $process->start();

        return $process;
    }

    private function assertEmoWriterWaiting(Process $process): void
    {
        $deadline = microtime(true) + 8;
        $diagnostic = null;
        do {
            if (preg_match('/READY:(\d+)/', $process->getOutput(), $matches)) {
                $transaction = DB::connection('mining_writer')->selectOne('SELECT trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ?', [(int) $matches[1]]);
                $diagnostic = DB::connection('mining_writer')->selectOne('SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [(int) $matches[1]]);
                // MariaDB can expose a waiting transaction as RUNNING in
                // INNODB_TRX. Its active SQL still proves entry into the row
                // locked by this test, which cannot finish before our commit.
                $lockedQuery = preg_match('/(?:select .* from `personal`.*for update|select .* from `examenes_mineros`.*for update|update `examenes_mineros`)/i', $diagnostic->INFO ?? '') === 1;
                if (($transaction && $transaction->trx_state === 'LOCK WAIT') || $lockedQuery) {
                    $this->assertTrue($process->isRunning());

                    return;
                }
            }
            usleep(20000);
        } while ($process->isRunning() && microtime(true) < $deadline);
        $this->fail('La segunda conexion no espero el lock real: '.$process->getOutput().$process->getErrorOutput()
            .' '.json_encode($diagnostic));
    }

    private function assignment(?string $examId = null): PersonalMina
    {
        $exam = $examId ? ExamenMinero::query()->findOrFail($examId)
            : $this->exam('VISIBILIDAD '.Str::uuid());
        $this->examIds[] = $exam->id;
        $mine = $this->mine();
        $this->mineIds[] = $mine->id;
        $this->requirement($mine, $exam);

        return $this->service->assignMine([
            'personal_id' => $this->worker->id, 'mina_id' => $mine->id,
        ], $this->evaluator, false);
    }

    private function startSnapshot(): void
    {
        DB::beginTransaction();
        DB::table('personal_mina_examenes')->whereIn('personal_mina_id',
            DB::table('personal_mina')->where('personal_id', $this->worker->id)->select('id'))->get();
    }

    private function write(callable $operation): void
    {
        DB::setDefaultConnection('mining_writer');
        try {
            $operation();
        } finally {
            DB::setDefaultConnection($this->primaryConnection);
        }
    }
}
