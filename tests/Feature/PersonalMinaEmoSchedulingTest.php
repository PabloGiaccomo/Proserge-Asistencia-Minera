<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Personal;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class PersonalMinaEmoSchedulingTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    private Personal $worker;

    private Usuario $scheduler;

    private Usuario $evaluator;

    private ClinicaEmo $clinic;

    private PersonalMinaHabilitacionService $mining;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'America/Lima'));
        $this->app->instance(OperationalNotificationService::class,
            \Mockery::mock(OperationalNotificationService::class)->shouldIgnoreMissing());
        $this->mining = app(PersonalMinaHabilitacionService::class);
        $this->scheduler = $this->actor();
        $this->evaluator = $this->actor();
        $document = (string) random_int(75000000, 75999999);
        $this->worker = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document,
            'tipo_documento' => 'DNI', 'nombre_completo' => 'PRUEBA EMO '.Str::random(8),
            'puesto' => 'Operario', 'ocupacion' => 'Operario', 'estado' => 'ACTIVO',
            'contrato' => 'FIJO', 'qr_code' => 'TEST-'.Str::uuid(), 'es_supervisor' => false,
        ]);
        $this->clinic = $this->clinic();
    }

    public function test_same_exact_exam_two_mines_share_one_appointment_and_idempotent_links(): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $appointment = $this->schedule($first);
        $shared = $this->schedule($second);
        $this->schedule($second);

        $this->assertSame($appointment->id, $shared->id);
        $this->assertSame(1, PersonalEmoCita::where('personal_id', $this->worker->id)->count());
        $this->assertCount(2, $appointment->intentos()->get());
        $this->assertSame(2, $appointment->historial()->where('evento', 'MINA_VINCULADA')->count());
    }

    public function test_different_exam_identity_cannot_share_an_appointment(): void
    {
        $appointment = $this->schedule($this->workerExam());
        $second = $this->workerExam();
        $this->reject(fn () => $this->schedule($second), 'examen');
        $this->assertCount(1, $appointment->intentos()->get());
        $this->assertSame(0, $second->intentos()->count());
    }

    public function test_other_clinic_is_rejected_without_partial_attempt_or_appointment(): void
    {
        $first = $this->workerExam();
        $appointment = $this->schedule($first);
        $second = $this->workerExam($first->examen);
        $this->reject(fn () => $this->schedule($second, '2026-10-07', $this->clinic()), 'clinica_id');
        $this->assertCount(1, $appointment->intentos()->get());
        $this->assertSame(0, $second->intentos()->count());
        $this->assertSame(1, PersonalEmoCita::where('personal_id', $this->worker->id)->count());
    }

    public function test_inactive_clinic_and_non_emo_exam_are_rejected(): void
    {
        $exam = $this->workerExam();
        $inactive = $this->clinic();
        $inactive->update(['activo' => false]);
        $this->reject(fn () => $this->schedule($exam, '2026-10-07', $inactive), 'clinica_id');
        $exam->examen->update(['categoria_operativa' => 'CURSO']);
        $this->reject(fn () => $this->schedule($exam), 'examen');
        $this->assertSame(0, $exam->intentos()->count());
    }

    public function test_origins_accept_only_the_three_operational_sources(): void
    {
        $this->assertTrue(enum_exists(EmoOrigin::class), 'Falta el contrato de origen de citas EMO.');
        foreach (['MANUAL', 'CARGA_MASIVA', 'REGULARIZACION'] as $origin) {
            $this->assertSame($origin, EmoOrigin::fromInput($origin)->value);
        }
        foreach (['MASTER', 'desconocido', '', null, []] as $unknown) {
            $this->reject(fn () => EmoOrigin::fromInput($unknown), 'origen');
        }
    }

    #[DataProvider('outcomes')]
    public function test_completes_only_linked_mines_with_exact_medical_outcome(string $outcome, string $generic, ?string $detail): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $unlinked = $this->workerExam($first->examen);
        $this->schedule($first);
        $appointment = $this->schedule($second);
        $completed = $this->complete($appointment, $outcome, $detail);

        $this->assertSame('COMPLETADA', $completed->estado);
        $this->assertSame($outcome, $completed->resultado_emo);
        $this->assertSame($detail, $completed->detalle_resultado_emo);
        $this->assertSame($this->scheduler->id, $completed->usuario_programacion_id);
        $this->assertSame($this->evaluator->id, $completed->usuario_resultado_id);
        foreach ([$first, $second] as $exam) {
            $this->assertSame($generic, $exam->fresh()->resultado);
            $this->assertSame($generic, $exam->intentos()->sole()->resultado);
            $this->assertSame($generic === 'APROBADO' ? 'HABILITADO' : 'EN_PROCESO', $exam->asignacion->fresh()->estado_habilitacion);
            if ($detail) {
                $this->assertStringContainsString($detail, $exam->fresh()->observacion);
            }
        }
        $this->assertSame('PENDIENTE', $unlinked->fresh()->estado);
        $this->assertSame(0, $unlinked->intentos()->count());
    }

    public static function outcomes(): array
    {
        return [
            ['APTO', 'APROBADO', null], ['NO_APTO', 'DESAPROBADO', null],
            ['APTO_CON_RESTRICCION', 'APROBADO', 'Evitar trabajo en altura'],
            ['APTO_CON_OBSERVACION', 'APROBADO', 'Control medico en treinta dias'],
        ];
    }

    public function test_restriction_and_observation_require_detail_before_any_write(): void
    {
        $appointment = $this->schedule($this->workerExam());
        foreach (['APTO_CON_RESTRICCION', 'APTO_CON_OBSERVACION'] as $outcome) {
            $this->reject(fn () => $this->complete($appointment, $outcome), 'detalle_resultado_emo');
        }
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame('PENDIENTE', $appointment->intentos()->sole()->resultado);
    }

    public function test_completed_appointment_rejects_new_mines_result_edits_cancellation_and_reprogramming(): void
    {
        $exam = $this->workerExam();
        $appointment = $this->complete($this->schedule($exam));
        $this->reject(fn () => $this->schedule($this->workerExam($exam->examen)), 'cita');
        $this->reject(fn () => $this->complete($appointment, 'NO_APTO'), 'cita');
        $this->reject(fn () => $this->service()->cancel($appointment, 'Cambio', $this->scheduler), 'cita');
        $this->reject(fn () => $this->service()->reprogram($appointment, $this->reprogramData(), $this->scheduler), 'cita');
        $this->assertSame('APTO', $appointment->fresh()->resultado_emo);
        $this->assertSame($this->clinic->id, $appointment->fresh()->clinica_id);
    }

    public function test_cancelled_appointment_is_reprogrammed_on_same_identity_with_audited_values(): void
    {
        $exam = $this->workerExam();
        $appointment = $this->schedule($exam);
        $cancelled = $this->service()->cancel($appointment, 'Clinica sin disponibilidad', $this->scheduler);
        $this->assertSame('ANULADO', $exam->intentos()->sole()->resultado);
        $this->assertSame('PENDIENTE', $exam->fresh()->estado);
        $newClinic = $this->clinic();
        $reprogrammed = $this->service()->reprogram($cancelled, $this->reprogramData('2026-10-08', $newClinic), $this->evaluator);
        $this->assertSame($appointment->id, $reprogrammed->id);
        $this->assertSame('PROGRAMADA', $reprogrammed->estado);
        $this->assertSame($newClinic->id, $reprogrammed->clinica_id);
        $this->assertSame('2026-10-08', $exam->intentos()->sole()->fecha_programacion->toDateString());
        $this->assertSame('PENDIENTE', $exam->intentos()->sole()->resultado);
        $history = $reprogrammed->historial()->where('evento', 'REPROGRAMADA')->sole();
        $this->assertSame('2026-10-07', $history->valores_anteriores_json['fecha_programacion']);
        $this->assertSame('2026-10-08', $history->valores_nuevos_json['fecha_programacion']);
        $this->assertSame('Cambio de disponibilidad', $history->valores_nuevos_json['motivo']);
        $this->assertSame($this->evaluator->id, $history->usuario_id);
        $this->assertSame($this->scheduler->id, $reprogrammed->usuario_programacion_id);
    }

    public function test_no_aplica_anula_el_vinculo_emo_y_permite_programar_un_nuevo_ciclo_en_cero(): void
    {
        $exam = $this->workerExam();
        $exam->requisitoMina->forceFill(['permite_no_aplica' => true])->save();
        $appointment = $this->schedule($exam);

        $this->mining->markExamNotApplicable($exam->fresh(), ['observacion' => 'No requerido temporalmente'], $this->evaluator);

        $this->assertSame('ANULADO', $appointment->intentos()->sole()->resultado);
        $this->assertSame('ANULADA', $appointment->fresh()->estado);
        $this->assertSame('NO_APLICA', $exam->fresh()->estado);
        $this->assertSame(0, $this->mining->operationalAttemptCount($exam->fresh('intentos')));
        $this->assertSame(1, $appointment->historial()->where('evento', 'ANULADA_NO_APLICA')->count());

        $new = $this->schedule($exam->fresh(), '2026-10-08');

        $this->assertNotSame($appointment->id, $new->id);
        $this->assertSame(2, $exam->intentos()->count());
        $this->assertSame(2, $exam->intentos()->max('numero_intento'));
        $this->assertSame(0, $this->mining->operationalAttemptCount($exam->fresh('intentos')));
        $this->assertSame('PROGRAMADO', $exam->fresh()->estado);
        $this->assertSame('EN_PROCESO', $exam->asignacion->fresh()->estado_habilitacion);
    }

    public function test_no_aplica_en_una_mina_no_cancela_la_cita_emo_compartida_por_otra_mina(): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $first->requisitoMina->forceFill(['permite_no_aplica' => true])->save();
        $this->schedule($first);
        $appointment = $this->schedule($second);

        $this->mining->markExamNotApplicable($first->fresh(), [], $this->evaluator);

        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame('ANULADO', $appointment->intentos()->where('personal_mina_examen_id', $first->id)->sole()->resultado);
        $this->assertSame('PENDIENTE', $appointment->intentos()->where('personal_mina_examen_id', $second->id)->sole()->resultado);
        $this->assertSame('PROGRAMADO', $second->fresh()->estado);
        $this->assertSame(1, $appointment->historial()->where('evento', 'MINA_ANULADA_NO_APLICA')->count());
    }

    public function test_cita_compartida_se_completa_sin_proyectar_el_enlace_anulado_por_no_aplica(): void
    {
        $excluded = $this->workerExam();
        $active = $this->workerExam($excluded->examen);
        $excluded->requisitoMina->forceFill(['permite_no_aplica' => true])->save();
        $this->schedule($excluded);
        $appointment = $this->schedule($active);
        $this->mining->markExamNotApplicable($excluded->fresh(), [], $this->evaluator);

        $completed = $this->complete($appointment->fresh());

        $this->assertSame('COMPLETADA', $completed->estado);
        $this->assertSame('ANULADO', $excluded->intentos()->sole()->resultado);
        $this->assertSame('NO_APLICA', $excluded->fresh()->estado);
        $this->assertSame('APROBADO', $active->intentos()->sole()->resultado);
        $this->assertContains($active->fresh()->estado, ['APROBADO', 'VIGENTE', 'POR_VENCER']);
    }

    public function test_cita_compartida_se_anula_y_reprograma_solo_para_el_enlace_activo_tras_no_aplica(): void
    {
        $excluded = $this->workerExam();
        $active = $this->workerExam($excluded->examen);
        $excluded->requisitoMina->forceFill(['permite_no_aplica' => true])->save();
        $this->schedule($excluded);
        $appointment = $this->schedule($active);
        $this->mining->markExamNotApplicable($excluded->fresh(), [], $this->evaluator);

        $cancelled = $this->service()->cancel($appointment->fresh(), 'Cambio de agenda', $this->scheduler);
        $reprogrammed = $this->service()->reprogram($cancelled, $this->reprogramData('2026-10-08'), $this->scheduler);

        $this->assertSame('PROGRAMADA', $reprogrammed->estado);
        $this->assertSame('ANULADO', $excluded->intentos()->sole()->resultado);
        $this->assertSame('NO_APLICA', $excluded->fresh()->estado);
        $this->assertSame('PENDIENTE', $active->intentos()->sole()->resultado);
        $this->assertSame('2026-10-08', $active->intentos()->sole()->fecha_programacion->toDateString());
    }

    public function test_programar_no_aplica_en_la_misma_cita_crea_nuevo_intento_fisico_en_ciclo_cero(): void
    {
        $reopened = $this->workerExam();
        $other = $this->workerExam($reopened->examen);
        $reopened->requisitoMina->forceFill(['permite_no_aplica' => true])->save();
        $appointment = $this->schedule($reopened);
        $this->schedule($other);
        $this->mining->markExamNotApplicable($reopened->fresh(), [], $this->evaluator);

        $same = $this->schedule($reopened->fresh(), '2026-10-07');

        $this->assertSame($appointment->id, $same->id);
        $this->assertSame(['ANULADO', 'PENDIENTE'], $reopened->intentos()->orderBy('numero_intento')->pluck('resultado')->all());
        $this->assertSame([1, 2], $reopened->intentos()->orderBy('numero_intento')->pluck('numero_intento')->all());
        $old = $reopened->intentos()->where('numero_intento', 1)->sole();
        $this->assertNull($old->emo_cita_id);
        $replacementAudit = $same->historial()->where('evento', 'MINA_VINCULADA')->get()
            ->first(fn ($history) => ($history->valores_nuevos_json['intento_anterior_anulado_id'] ?? null) !== null);
        $this->assertNotNull($replacementAudit);
        $this->assertSame($old->id, $replacementAudit->valores_nuevos_json['intento_anterior_anulado_id']);
        $this->assertSame(0, $this->mining->operationalAttemptCount($reopened->fresh('intentos')));
        $this->assertSame('PROGRAMADO', $reopened->fresh()->estado);
    }

    public function test_reprogramming_to_occupied_day_rolls_back_and_preserves_original_history(): void
    {
        $first = $this->workerExam();
        $original = $this->service()->cancel($this->schedule($first), 'Cambio', $this->scheduler);
        $this->schedule($this->workerExam($first->examen), '2026-10-08');
        $events = $original->historial()->count();
        $this->reject(fn () => $this->service()->reprogram($original, $this->reprogramData(), $this->scheduler), 'fecha_programacion');
        $this->assertSame('ANULADA', $original->fresh()->estado);
        $this->assertSame('2026-10-07', $original->fresh()->fecha_programacion->toDateString());
        $this->assertSame($events, $original->historial()->count());
    }

    public function test_legacy_schedule_blocks_same_day_until_explicit_clinic_regularization(): void
    {
        $first = $this->workerExam();
        $legacy = PersonalMinaExamenIntento::create([
            'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $first->id,
            'numero_intento' => 1, 'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
            'observacion' => 'Historico', 'usuario_registro_id' => $this->scheduler->id,
        ]);
        $second = $this->workerExam($first->examen);
        $this->reject(fn () => $this->schedule($second), 'fecha_programacion');
        $appointment = $this->service()->regularizeLegacyAttempt($legacy, $this->clinic, $this->evaluator);
        $this->assertSame($appointment->id, $legacy->fresh()->emo_cita_id);
        $this->assertSame('Historico', $legacy->fresh()->observacion);
        $this->assertSame($this->scheduler->id, $legacy->fresh()->usuario_registro_id);
        $this->assertSame('REGULARIZACION', $appointment->origen);
        $this->assertSame($appointment->id, $this->schedule($second)->id);
        $this->assertSame(1, $first->intentos()->count());
    }

    public function test_legacy_completion_requires_regularization_and_is_not_silently_closed(): void
    {
        $exam = $this->workerExam();
        $legacy = PersonalMinaExamenIntento::create([
            'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id,
            'numero_intento' => 1, 'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
        ]);
        $this->reject(fn () => $this->mining->completeScheduledAttempt($legacy, [
            'resultado' => 'APTO', 'fecha_realizacion' => '2026-10-07',
        ], null, $this->evaluator), 'clinica_id');
        $this->assertNull($legacy->fresh()->emo_cita_id);
        $this->assertSame('PENDIENTE', $legacy->fresh()->resultado);
    }

    public function test_legacy_emo_cannot_hide_behind_a_course_on_the_same_day(): void
    {
        $course = $this->workerExam($this->exam('Curso '.Str::uuid(), ['categoria_operativa' => 'CURSO']));
        $legacy = $this->workerExam();
        foreach ([$course, $legacy] as $index => $exam) {
            PersonalMinaExamenIntento::create([
                'id' => $index === 0 ? '00000000-0000-4000-8000-000000000001' : (string) Str::uuid(),
                'personal_mina_examen_id' => $exam->id, 'numero_intento' => 1,
                'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
            ]);
        }
        $this->reject(fn () => $this->schedule($this->workerExam($legacy->examen)), 'fecha_programacion');
    }

    public function test_completion_from_stale_cancelled_and_reprogrammed_context_is_rejected(): void
    {
        $appointment = $this->schedule($this->workerExam(), '2026-10-05');
        $cancelled = $this->service()->cancel($appointment, 'Cambio', $this->scheduler);
        $this->service()->reprogram($cancelled, $this->reprogramData('2026-10-06', $this->clinic()), $this->scheduler);
        $this->reject(fn () => $this->complete($appointment), 'cita');
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
    }

    public static function staleScheduledCommands(): array
    {
        return [['cancel'], ['complete']];
    }

    #[DataProvider('staleScheduledCommands')]
    public function test_stale_context_cannot_change_reprogrammed_appointment_with_identical_values(string $command): void
    {
        $stale = $this->schedule($this->workerExam());
        $cancelled = $this->service()->cancel($stale, 'Cambio temporal', $this->scheduler);
        $current = $this->service()->reprogram($cancelled, $this->reprogramData('2026-10-07'), $this->scheduler);
        $this->assertSame($stale->estado, $current->estado);
        $this->assertSame($stale->clinica_id, $current->clinica_id);
        $this->assertSame($stale->fecha_programacion->toDateString(), $current->fecha_programacion->toDateString());
        $events = $current->historial()->count();

        $this->reject(fn () => $command === 'cancel'
            ? $this->service()->cancel($stale, 'Contexto anterior', $this->scheduler)
            : $this->complete($stale), 'cita');

        $this->assertSame('PROGRAMADA', $current->fresh()->estado);
        $this->assertSame('PENDIENTE', $current->intentos()->sole()->resultado);
        $this->assertSame($events, $current->historial()->count());
    }

    public function test_revision_invalidates_commands_after_new_links_and_each_transition_but_not_idempotent_schedule(): void
    {
        $first = $this->workerExam();
        $stale = $this->schedule($first);
        $this->assertSame($stale->revision, $this->schedule($first)->revision);
        $current = $this->schedule($this->workerExam($first->examen));
        $this->assertSame($stale->revision + 1, $current->revision);
        $this->reject(fn () => $this->complete($stale), 'cita');
        $cancelled = $this->service()->cancel($current, 'Cambio temporal', $this->scheduler);
        $this->assertSame($current->revision + 1, $cancelled->revision);
        $reprogrammed = $this->service()->reprogram($cancelled, $this->reprogramData('2026-10-07'), $this->scheduler);
        $this->assertSame($cancelled->revision + 1, $reprogrammed->revision);
        $this->reject(fn () => $this->service()->reprogram($cancelled, $this->reprogramData('2026-10-07'), $this->scheduler), 'cita');
        $completed = $this->complete($reprogrammed);
        $this->assertSame($reprogrammed->revision + 1, $completed->revision);
        $history = $completed->historial()->where('evento', 'COMPLETADA')->sole();
        $this->assertSame($reprogrammed->revision, $history->valores_anteriores_json['revision'] ?? null);
        $this->assertSame($completed->revision, $history->valores_nuevos_json['revision'] ?? null);
    }

    public function test_regularizing_new_legacy_link_invalidates_previous_appointment_context(): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $regularized = [];
        foreach ([$first, $second] as $exam) {
            $legacy = PersonalMinaExamenIntento::create([
                'id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id,
                'numero_intento' => 1, 'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
                'usuario_registro_id' => $this->scheduler->id,
            ]);
            $regularized[] = $this->service()->regularizeLegacyAttempt($legacy, $this->clinic, $this->evaluator);
        }
        [$stale, $current] = $regularized;
        $this->assertGreaterThan(1, $stale->revision);
        $this->assertSame($stale->revision + 1, $current->revision);
        $this->reject(fn () => $this->service()->cancel($stale, 'Contexto anterior', $this->scheduler), 'cita');
        $this->assertSame('PROGRAMADA', $current->fresh()->estado);
    }

    public function test_medical_file_is_removed_when_an_enclosing_transaction_rolls_back(): void
    {
        Storage::fake('local');
        $appointment = $this->schedule($this->workerExam());
        DB::beginTransaction();
        $this->complete($appointment, 'APTO', file: UploadedFile::fake()->create('medico.pdf', 4, 'application/pdf'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        DB::rollBack();
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_scheduling_evidence_is_removed_when_enclosing_transaction_rolls_back(): void
    {
        Storage::fake('local');
        $exam = $this->workerExam();
        DB::beginTransaction();
        $this->mining->registerAttempt($exam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07', 'clinica_id' => $this->clinic->id,
        ], UploadedFile::fake()->create('orden.pdf', 3, 'application/pdf'), $this->scheduler);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        DB::rollBack();
        $this->assertSame(0, $exam->intentos()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_completion_revalidates_every_link_and_rejects_inactive_requirement_atomically(): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $this->schedule($first);
        $appointment = $this->schedule($second);
        $second->requisitoMina->update(['activo' => false]);
        $this->reject(fn () => $this->complete($appointment), 'examen');
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame(['PENDIENTE', 'PENDIENTE'], $appointment->intentos()->pluck('resultado')->all());
    }

    public function test_result_cannot_be_future_or_before_appointment_and_rejects_future_schedule_today(): void
    {
        $appointment = $this->schedule($this->workerExam(), '2026-10-08');
        $this->reject(fn () => $this->complete($appointment), 'fecha_programacion');
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00', 'America/Lima'));
        foreach (['2026-10-07', '2026-10-10'] as $date) {
            $this->reject(fn () => $this->service()->complete($appointment,
                new CompleteEmoData($this->date($date), EmoOutcome::APTO), $this->evaluator), 'fecha_realizacion');
        }
    }

    public function test_second_link_failure_rolls_back_medical_file_attempts_exams_assignments_and_audit(): void
    {
        Storage::fake('local');
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $this->schedule($first);
        $appointment = $this->schedule($second);
        $events = $appointment->historial()->count();
        $updates = 0;
        PersonalMinaExamenIntento::updating(function ($attempt) use (&$updates, $appointment): void {
            if ($attempt->emo_cita_id === $appointment->id && ++$updates === 2) {
                throw new \RuntimeException('Fallo controlado del segundo enlace');
            }
        });
        try {
            $this->complete($appointment, 'APTO', null, UploadedFile::fake()->create('medico.pdf', 4, 'application/pdf'));
            $this->fail('El fallo del segundo enlace debe propagarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo controlado del segundo enlace', $exception->getMessage());
        } finally {
            PersonalMinaExamenIntento::flushEventListeners();
        }
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame($events, $appointment->historial()->count());
        $this->assertSame(['PENDIENTE', 'PENDIENTE'], $appointment->intentos()->pluck('resultado')->all());
        foreach ([$first, $second] as $exam) {
            $this->assertSame('PROGRAMADO', $exam->fresh()->estado);
            $this->assertSame('EN_PROCESO', $exam->asignacion->fresh()->estado_habilitacion);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_medical_file_is_private_and_linked_to_each_completed_attempt(): void
    {
        Storage::fake('local');
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $this->schedule($first);
        $appointment = $this->schedule($second);
        $this->complete($appointment, 'APTO', null, UploadedFile::fake()->create('resultado.pdf', 4, 'application/pdf'));
        $paths = $appointment->intentos()->pluck('archivo_path');
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertCount(1, $paths->unique());
        $this->assertSame(['resultado.pdf', 'resultado.pdf'], $appointment->intentos()->pluck('archivo_nombre_original')->all());
    }

    public function test_per_exam_validity_and_score_validation_are_atomic(): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $first->update(['tiene_vigencia_snapshot' => true, 'vigencia_dias_snapshot' => 30]);
        $second->update(['tiene_vigencia_snapshot' => true, 'vigencia_dias_snapshot' => 90,
            'requiere_nota_snapshot' => true, 'nota_minima_snapshot' => '14.00']);
        $this->schedule($first);
        $appointment = $this->schedule($second);
        $this->reject(fn () => $this->complete($appointment), 'nota');
        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->complete($appointment, 'APTO', score: '17.50');
        $this->assertSame('2026-11-06', $first->fresh()->fecha_vencimiento->toDateString());
        $this->assertSame('2027-01-05', $second->fresh()->fecha_vencimiento->toDateString());
        $this->assertSame('17.50', $second->fresh()->nota_obtenida);
        $this->assertNull($first->fresh()->nota_obtenida);
    }

    public function test_manual_expiration_is_applied_to_every_link_and_invalid_date_rejected(): void
    {
        $first = $this->workerExam();
        $second = $this->workerExam($first->examen);
        $this->schedule($first);
        $appointment = $this->schedule($second);
        $this->reject(fn () => $this->complete($appointment, expiration: '2026-10-06'), 'fecha_vencimiento');
        $this->complete($appointment, expiration: '2027-02-01');
        foreach ([$first, $second] as $exam) {
            $this->assertSame('2027-02-01', $exam->fresh()->fecha_vencimiento->toDateString());
        }
    }

    public function test_price_snapshot_uses_scheduled_then_performed_date(): void
    {
        $exam = $this->workerExam();
        $exam->examen->update(['empresa_paga' => true]);
        $this->mining->storeExamPrice($exam->examen, ['precio' => '100.00', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-05'], $this->scheduler);
        $this->mining->storeExamPrice($exam->examen, ['precio' => '150.00', 'fecha_inicio' => '2026-10-06'], $this->scheduler);
        $appointment = $this->schedule($exam, '2026-10-05');
        $this->assertSame('100.00', $appointment->intentos()->sole()->precio_aplicado);
        $this->assertSame('2026-10-05', $appointment->intentos()->sole()->fecha_precio_aplicado->toDateString());
        $this->complete($appointment);
        $this->assertSame('150.00', $appointment->intentos()->sole()->precio_aplicado);
        $this->assertSame('2026-10-07', $appointment->intentos()->sole()->fecha_precio_aplicado->toDateString());
    }

    public function test_early_renewal_pending_or_failed_keeps_current_approval_and_notifies_new_date(): void
    {
        $exam = $this->workerExam();
        $this->complete($this->schedule($exam), expiration: '2026-10-20');
        $notifiedDate = null;
        $this->app->make(OperationalNotificationService::class)->shouldReceive('examenProgramado')->once()
            ->withArgs(function ($notifiedExam, $actor, $attempt) use (&$notifiedDate, $exam): bool {
                $notifiedDate = $attempt->fecha_programacion->toDateString();

                return $notifiedExam->id === $exam->id && $actor->id === $this->scheduler->id && filled($attempt->emo_cita_id);
            });
        $renewal = $this->schedule($exam, '2026-10-08');
        $this->assertSame('2026-10-08', $notifiedDate);
        $this->assertSame('APROBADO', $exam->fresh()->resultado);
        $this->assertSame('2026-10-20', $exam->fresh()->fecha_vencimiento->toDateString());
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'America/Lima'));
        $this->service()->complete($renewal, new CompleteEmoData(
            $this->date('2026-10-08'), EmoOutcome::NO_APTO, origin: EmoOrigin::MANUAL,
        ), $this->evaluator);
        $this->assertSame('APROBADO', $exam->fresh()->resultado);
        $this->assertSame('2026-10-20', $exam->fresh()->fecha_vencimiento->toDateString());
        $this->assertSame('HABILITADO', $exam->asignacion->fresh()->estado_habilitacion);
    }

    public function test_mining_entry_points_delegate_without_losing_medical_detail_file_or_manual_expiration(): void
    {
        Storage::fake('local');
        $exam = $this->workerExam();
        $this->mining->registerAttempt($exam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07',
            'clinica_id' => $this->clinic->id, 'observacion' => 'Programado manualmente',
        ], null, $this->scheduler);
        $attempt = $exam->intentos()->sole();
        $this->assertNotNull($attempt->emo_cita_id);
        $this->mining->completeScheduledAttempt($attempt, [
            'resultado' => 'APTO_CON_RESTRICCION', 'detalle_resultado_emo' => 'Usar lentes',
            'observacion' => 'Seguimiento SSOMA', 'fecha_realizacion' => '2026-10-07',
            'fecha_vencimiento' => '2027-01-10',
        ], UploadedFile::fake()->create('resultado.pdf', 2, 'application/pdf'), $this->evaluator);
        $cita = $attempt->fresh()->emoCita;
        $this->assertSame('APTO_CON_RESTRICCION', $cita->resultado_emo);
        $this->assertSame('Usar lentes', $cita->detalle_resultado_emo);
        $this->assertStringContainsString('Usar lentes', $exam->fresh()->observacion);
        $this->assertStringContainsString('Seguimiento SSOMA', $exam->fresh()->observacion);
        $this->assertSame('2027-01-10', $exam->fresh()->fecha_vencimiento->toDateString());
        Storage::disk('local')->assertExists($attempt->fresh()->archivo_path);
    }

    public function test_existing_mining_scheduling_entry_point_preserves_uploaded_evidence(): void
    {
        Storage::fake('local');
        $exam = $this->workerExam();
        $this->mining->registerAttempt($exam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07', 'clinica_id' => $this->clinic->id,
        ], UploadedFile::fake()->create('orden-medica.pdf', 3, 'application/pdf'), $this->scheduler);
        $attempt = $exam->intentos()->sole();
        $this->assertNotNull($attempt->emo_cita_id);
        $this->assertSame('orden-medica.pdf', $attempt->archivo_nombre_original);
        Storage::disk('local')->assertExists($attempt->archivo_path);
    }

    public function test_direct_result_registration_is_atomic_and_does_not_emit_a_pending_schedule_notification(): void
    {
        $exam = $this->workerExam();
        $notifications = 0;
        $this->app->make(OperationalNotificationService::class)->shouldReceive('examenProgramado')
            ->andReturnUsing(function () use (&$notifications): void {
                $notifications++;
            });
        $this->mining->registerAttempt($exam, [
            'resultado' => 'APTO_CON_OBSERVACION', 'fecha_realizacion' => '2026-10-07',
            'clinica_id' => $this->clinic->id, 'detalle_resultado_emo' => 'Control anual',
        ], null, $this->evaluator);
        $attempt = $exam->intentos()->sole();
        $this->assertSame('COMPLETADA', $attempt->emoCita->estado);
        $this->assertSame('APTO_CON_OBSERVACION', $attempt->emoCita->resultado_emo);
        $this->assertSame(0, $notifications);
        $other = $this->workerExam();
        $this->reject(fn () => $this->mining->registerAttempt($other, [
            'resultado' => 'APTO', 'fecha_realizacion' => '2026-10-06', 'clinica_id' => $this->clinic->id,
            'fecha_vencimiento' => '2026-10-05',
        ], null, $this->evaluator), 'fecha_vencimiento');
        $this->assertSame(0, $other->intentos()->count());
        $this->assertSame(1, PersonalEmoCita::where('personal_id', $this->worker->id)->count());
    }

    public function test_completion_retains_reference_to_replaced_scheduling_evidence_in_audit(): void
    {
        Storage::fake('local');
        $exam = $this->workerExam();
        $this->mining->registerAttempt($exam, [
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07', 'clinica_id' => $this->clinic->id,
        ], UploadedFile::fake()->create('orden.pdf', 3, 'application/pdf'), $this->scheduler);
        $attempt = $exam->intentos()->sole();
        $oldPath = $attempt->archivo_path;
        $this->complete($attempt->emoCita, file: UploadedFile::fake()->create('resultado.pdf', 4, 'application/pdf'));
        $history = $attempt->emoCita->historial()->where('evento', 'COMPLETADA')->sole();
        $this->assertSame($oldPath, $history->valores_anteriores_json['intentos'][0]['archivo_path'] ?? null);
        $this->assertNotSame($oldPath, $attempt->fresh()->archivo_path);
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_convalidation_snapshots_medical_outcome_detail_clinic_and_appointment(): void
    {
        $origin = $this->workerExam();
        $target = $this->workerExam($origin->examen);
        $appointment = $this->complete($this->schedule($origin), 'APTO_CON_RESTRICCION', 'No subir escaleras');
        $updated = $this->mining->convalidateExam($target, $origin->id, ['observacion' => 'Confirmado'], $this->evaluator);
        $context = $updated->contexto_convalidacion_json;
        $this->assertSame('APTO_CON_RESTRICCION', $context['resultado_emo'] ?? null);
        $this->assertSame('No subir escaleras', $context['detalle_resultado_emo'] ?? null);
        $this->assertSame($appointment->id, $context['emo_cita_id'] ?? null);
        $this->assertSame($this->clinic->id, $context['clinica_id'] ?? null);
        $this->assertSame($this->clinic->nombre, $context['clinica_nombre_snapshot'] ?? null);
        $this->assertStringContainsString('No subir escaleras', $updated->observacion);
        $this->assertSame(0, $target->intentos()->count());
    }

    public function test_source_course_record_is_linked_only_to_its_explicit_mine(): void
    {
        $exam = $this->workerExam();
        $record = PersonalCursoRegistro::create([
            'id' => (string) Str::uuid(), 'personal_id' => $this->worker->id,
            'mina_id' => $exam->asignacion->mina_id, 'examen_id' => $exam->examen_id,
            'resultado' => 'PENDIENTE', 'fecha_programacion' => '2026-10-07',
            'catalogo_snapshot' => [], 'usuario_registro_id' => $this->scheduler->id, 'origen' => 'Carga prueba',
        ]);
        $appointment = $this->service()->schedule($exam, new ScheduleEmoData(
            $this->date('2026-10-07'), $this->clinic->id, origin: EmoOrigin::CARGA_MASIVA, sourceCourseRecordId: $record->id,
        ), $this->scheduler);
        $this->assertSame($appointment->id, $record->fresh()->emo_cita_id);
        $this->assertSame($record->id, $appointment->intentos()->sole()->personal_curso_registro_id);
        $other = $this->workerExam($exam->examen);
        $this->reject(fn () => $this->service()->schedule($other, new ScheduleEmoData(
            $this->date('2026-10-07'), $this->clinic->id, origin: EmoOrigin::CARGA_MASIVA, sourceCourseRecordId: $record->id,
        ), $this->scheduler), 'personal_curso_registro_id');
    }

    private function service(): EmoAppointmentService
    {
        $this->assertTrue(class_exists(EmoAppointmentService::class), 'Falta el servicio transaccional de citas EMO.');

        return app(EmoAppointmentService::class);
    }

    private function workerExam(?ExamenMinero $catalog = null): PersonalMinaExamen
    {
        $catalog ??= $this->exam('EMO '.Str::uuid(), ['categoria_operativa' => 'EMO']);
        $mine = $this->mine();
        $this->requirement($mine, $catalog);

        return $this->mining->assignMine([
            'personal_id' => $this->worker->id, 'mina_id' => $mine->id,
        ], $this->scheduler, false)->examenes->sole();
    }

    private function clinic(): ClinicaEmo
    {
        $name = 'Clinica '.Str::uuid();

        return ClinicaEmo::create(['nombre' => $name, 'nombre_normalizado' => strtoupper($name), 'activo' => true]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/Lima');
    }

    private function schedule(PersonalMinaExamen $exam, string $date = '2026-10-07', ?ClinicaEmo $clinic = null): PersonalEmoCita
    {
        return $this->service()->schedule($exam, new ScheduleEmoData(
            $this->date($date), ($clinic ?? $this->clinic)->id, origin: EmoOrigin::MANUAL,
        ), $this->scheduler);
    }

    private function complete(PersonalEmoCita $appointment, string $outcome = 'APTO', ?string $detail = null,
        ?UploadedFile $file = null, ?string $score = null, ?string $expiration = null): PersonalEmoCita
    {
        return $this->service()->complete($appointment, new CompleteEmoData(
            $this->date('2026-10-07'), EmoOutcome::fromInput($outcome), detail: $detail, score: $score,
            manualExpiration: $expiration ? $this->date($expiration) : null, file: $file, origin: EmoOrigin::MANUAL,
        ), $this->evaluator);
    }

    private function reprogramData(string $date = '2026-10-08', ?ClinicaEmo $clinic = null): ReprogramEmoData
    {
        return new ReprogramEmoData($this->date($date), ($clinic ?? $this->clinic)->id,
            'Cambio de disponibilidad', EmoOrigin::MANUAL);
    }

    private function reject(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Debio rechazar la operacion: '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
