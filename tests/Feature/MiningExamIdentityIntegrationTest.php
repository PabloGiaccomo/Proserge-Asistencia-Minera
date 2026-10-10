<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Modules\Personal\Services\MiningExamCatalogOrganizationService;
use App\Modules\Personal\Services\PersonalMinaBulkPreviewService;
use App\Modules\Personal\Services\PersonalMinaExcelImportService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Models\PersonalEmoCita;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningExamIdentityIntegrationTest extends TestCase
{
    use DatabaseTransactions, MiningCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
    }

    public function test_master_uses_same_operational_day_as_manual_habilitation(): void
    {
        $originalTimezone = date_default_timezone_get();
        $this->beforeApplicationDestroyed(fn () => date_default_timezone_set($originalTimezone));
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'America/Lima']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-07 02:00:00', 'UTC'));
        $exam = $this->exam('MASTER DIA OPERATIVO '.Str::random(6));
        $mine = $this->mine();
        $worker = $this->worker();
        $actor = $this->actor();
        $file = $this->master($mine->nombre, $exam->nombre, $worker);
        $importer = app(PersonalMinaExcelImportService::class);

        try {
            $preview = $importer->preview($file);
            $preview['rows'][0]['examenes'][0]['datos']['fecha_realizacion'] = '2026-10-01';
            $preview['rows'][0]['examenes'][0]['datos']['fecha_vencimiento'] = '2026-10-06';
            $importer->confirm($preview, $actor);
            $assignment = \App\Models\PersonalMina::query()
                ->where('personal_id', $worker->id)->where('mina_id', $mine->id)->sole();

            $this->assertSame('POR_VENCER', $assignment->examenes()->sole()->estado);
            $this->assertSame('2026-10-06', $assignment->fecha_asignacion->toDateString());
            $this->assertSame('HABILITADO', $assignment->estado_habilitacion);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_programar_emo_with_date_and_clinic_stays_pending_without_appointment_or_place_change(): void
    {
        $exam = $this->exam('EMO MASTER PENDIENTE '.Str::random(6), ['categoria_operativa' => \App\Models\ExamenMinero::CATEGORIA_EMO]);
        $mine = $this->mine();
        $this->requirement($mine, $exam);
        $worker = $this->worker();
        $actor = $this->actor();
        $assignment = app(PersonalMinaHabilitacionService::class)->assignMine([
            'personal_id' => $worker->id, 'mina_id' => $mine->id,
        ], $actor, false);
        $workerExam = $assignment->examenes()->where('examen_id', $exam->id)->sole();
        $workerExam->update(['lugar_snapshot' => 'LUGAR HISTORICO']);
        $file = $this->masterProgramarEmo($mine->nombre, $exam->nombre, $worker);

        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertSame('PROGRAMAR_EXAMEN', data_get($preview, 'rows.0.examenes.0.preview.accion_pendiente'));
            $importer->confirm($preview, $actor);

            $this->assertSame(0, PersonalEmoCita::query()->where('personal_id', $worker->id)->count());
            $this->assertSame(0, $workerExam->intentos()->count());
            $this->assertSame('LUGAR HISTORICO', $workerExam->fresh()->lugar_snapshot);
            $this->assertSame(\App\Models\PersonalMinaExamen::ESTADO_PROGRAMADO, $workerExam->fresh()->estado);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_programar_emo_preserves_current_valid_approval_and_only_previews_next_action(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-08 10:00:00', 'America/Lima'));
        $exam = $this->exam('EMO VIGENTE '.Str::random(6), ['categoria_operativa' => \App\Models\ExamenMinero::CATEGORIA_EMO]);
        $mine = $this->mine();
        $this->requirement($mine, $exam);
        $worker = $this->worker();
        $actor = $this->actor();
        $mining = app(PersonalMinaHabilitacionService::class);
        $assignment = $mining->assignMine(['personal_id' => $worker->id, 'mina_id' => $mine->id], $actor, false);
        $workerExam = $assignment->examenes()->where('examen_id', $exam->id)->sole();
        $workerExam->update(['requiere_nota_snapshot' => true, 'nota_minima_snapshot' => 14, 'lugar_snapshot' => 'LUGAR VIGENTE']);
        $clinic = \App\Models\ClinicaEmo::query()->create([
            'nombre' => 'Clinica Vigente', 'nombre_normalizado' => 'CLINICA VIGENTE', 'activo' => true,
        ]);
        $appointments = app(\App\Modules\Personal\Services\EmoAppointmentService::class);
        $appointment = $appointments->schedule($workerExam, new \App\Modules\Personal\Support\ScheduleEmoData(
            \Carbon\CarbonImmutable::parse('2026-10-07'), $clinic->id,
        ), $actor);
        $appointments->complete($appointment, new \App\Modules\Personal\Support\CompleteEmoData(
            \Carbon\CarbonImmutable::parse('2026-10-07'), \App\Modules\Personal\Support\EmoOutcome::APTO,
            score: '17', manualExpiration: \Carbon\CarbonImmutable::parse('2027-10-07'),
        ), $actor);
        $workerExam->refresh();
        $this->assertSame('APROBADO', $workerExam->resultado);
        $fields = ['estado', 'resultado', 'fecha_programacion', 'fecha_realizacion', 'fecha_vencimiento',
            'nota_obtenida', 'lugar_snapshot', 'es_convalidado', 'contexto_convalidacion_json',
            'examen_origen_convalidado_id', 'mina_origen_convalidacion_id', 'fecha_aprobacion_origen',
            'fecha_convalidacion', 'usuario_convalidacion_id'];
        $before = $workerExam->only($fields);
        $attemptBefore = $workerExam->intentos()->sole()->getAttributes();
        $appointmentBefore = $appointment->fresh()->getAttributes();
        $file = $this->masterProgramarEmo($mine->nombre, $exam->nombre, $worker);

        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertSame('PROGRAMAR_EXAMEN', data_get($preview, 'rows.0.examenes.0.preview.accion_pendiente'));
            $counts = $importer->confirm($preview, $actor);

            $this->assertEquals($before, $workerExam->fresh()->only($fields));
            $this->assertSame(0, $counts['intentos_importados']);
            $this->assertSame(1, $workerExam->intentos()->count());
            $this->assertSame($attemptBefore, $workerExam->intentos()->sole()->getAttributes());
            $this->assertSame($appointmentBefore, $appointment->fresh()->getAttributes());
            $this->assertSame(1, PersonalEmoCita::where('personal_id', $worker->id)->count());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_programar_emo_wins_over_contradictory_no_aplica_with_or_without_date(): void
    {
        foreach ([null, '07/10/2026'] as $scheduledDate) {
            $exam = $this->exam('EMO MASTER CONTRADICTORIO '.Str::random(6), [
                'categoria_operativa' => \App\Models\ExamenMinero::CATEGORIA_EMO,
            ]);
            $mine = $this->mine();
            $this->requirement($mine, $exam);
            $worker = $this->worker();
            $actor = $this->actor();
            $assignment = app(PersonalMinaHabilitacionService::class)->assignMine([
                'personal_id' => $worker->id, 'mina_id' => $mine->id,
            ], $actor, false);
            $workerExam = $assignment->examenes()->where('examen_id', $exam->id)->sole();
            $workerExam->update(['lugar_snapshot' => 'LUGAR HISTORICO']);
            $file = $this->masterContradictoryProgramarEmo(
                $mine->nombre, $exam->nombre, $worker, $scheduledDate,
            );

            try {
                $importer = app(PersonalMinaExcelImportService::class);
                $preview = $importer->preview($file);
                $this->assertSame('PROGRAMAR_EXAMEN', data_get($preview, 'rows.0.examenes.0.preview.accion_pendiente'));
                $this->assertSame(\App\Models\PersonalMinaExamen::ESTADO_PROGRAMADO,
                    data_get($preview, 'rows.0.examenes.0.preview.estado_mapeado'));
                $importer->confirm($preview, $actor);

                $this->assertSame(0, PersonalEmoCita::query()->where('personal_id', $worker->id)->count());
                $this->assertSame(0, $workerExam->intentos()->count());
                $this->assertSame(\App\Models\PersonalMinaExamen::ESTADO_PROGRAMADO, $workerExam->fresh()->estado);
                $this->assertSame('PENDIENTE', $workerExam->fresh()->resultado);
                $this->assertSame('LUGAR HISTORICO', $workerExam->fresh()->lugar_snapshot);
            } finally {
                unlink($file->getPathname());
            }
        }
    }

    public function test_bulk_resolves_confirmed_group_to_destination_requirement_in_both_directions(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($variant, $principal);
        $worker = $this->worker();
        foreach ([[$principal, $variant], [$variant, $principal]] as [$excel, $required]) {
            $mine = $this->mine();
            $this->requirement($mine, $required);
            $row = $this->bulkRow($worker, $excel->nombre, $mine->nombre);
            $result = app(PersonalMinaBulkPreviewService::class)->plan([$row])[0];
            $this->assertSame($required->id, $result['examen_id']);
            $this->assertSame([], $result['issues']);
        }
    }

    public function test_bulk_blocks_two_requirements_in_same_group_and_suggests_unconfirmed_spelling(): void
    {
        $principal = $this->exam('IZAJE');
        $variant = $this->exam('IZAGE');
        $mine = $this->mine();
        $worker = $this->worker();
        $service = app(PersonalMinaBulkPreviewService::class);
        $missing = $service->plan([$this->bulkRow($worker, 'IZAJEE', $mine->nombre)])[0];
        $this->assertNull($missing['examen_id']);
        $this->assertNotEmpty($missing['examenes_parecidos'] ?? []);
        $this->associate($variant, $principal);
        $this->requirement($mine, $principal);
        $this->requirement($mine, $variant);
        $ambiguous = $service->plan([$this->bulkRow($worker, 'IZAJE', $mine->nombre)])[0];
        $this->assertNull($ambiguous['examen_id']);
        $this->assertContains('examen', $ambiguous['categories']);
    }

    public function test_master_recognizes_alias_without_renaming_or_creating_exam(): void
    {
        $exam = $this->exam('TRABAJOS EN CALIENTE');
        $mine = $this->mine();
        $requirement = $this->requirement($mine, $exam);
        $actor = $this->actor();
        $organization = app(MiningExamCatalogOrganizationService::class);
        $organization->confirm($organization->preview(['accion' => 'asociar', 'principal_id' => $exam->id,
            'aliases' => [['nombre' => 'TABAJO EN CALIENTE', 'mina_id' => $mine->id]],
            'motivo' => 'Nombre corregido por administrador'], $actor), $actor);
        $file = $this->master($mine->nombre, 'TABAJO EN CALIENTE');
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertSame($exam->id, $preview['catalogo_examenes'][0]['examenes'][0]['examen_id_existente']);
            $counts = $importer->confirm($preview, $actor);
            $this->assertSame(0, $counts['examenes_creados']);
            $this->assertSame('TRABAJOS EN CALIENTE', $exam->fresh()->nombre);
            $this->assertTrue($requirement->fresh()->activo);
            $this->assertTrue($exam->fresh()->permite_convalidacion);
            $this->assertTrue($requirement->fresh()->permite_convalidacion_mina);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_blocks_unconfirmed_similar_course_and_changed_group_after_preview(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $mine = $this->mine();
        $this->requirement($mine, $principal);
        $file = $this->master($mine->nombre, 'ESPACIO CONFINADO');
        $importer = app(PersonalMinaExcelImportService::class);
        try {
            $preview = $importer->preview($file);
            $this->associate($variant, $principal);
            $this->expectException(ValidationException::class);
            $importer->confirm($preview, $this->actor());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_similar_unknown_name_requires_catalog_review_before_any_write(): void
    {
        $this->exam('ESPACIO CONFINADO');
        $mine = $this->mine();
        $file = $this->master($mine->nombre, 'ESPACIOS CONFINADOS');
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertNotEmpty($preview['catalog_identity_errors'] ?? []);
            $this->expectException(ValidationException::class);
            $importer->confirm($preview, $this->actor());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_reactivates_inactive_confirmed_alias_without_creating_or_renaming(): void
    {
        $exam = $this->exam('TRABAJO EN ALTURA');
        $mine = $this->mine();
        $actor = $this->actor();
        $organization = app(MiningExamCatalogOrganizationService::class);
        $organization->confirm($organization->preview(['accion' => 'asociar', 'principal_id' => $exam->id,
            'aliases' => [['nombre' => 'ALT', 'mina_id' => null]], 'motivo' => 'Alias operativo confirmado'], $actor), $actor);
        $exam->update(['activo' => false]);
        $file = $this->master($mine->nombre, 'ALT');
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertSame($exam->id, $preview['catalogo_examenes'][0]['examenes'][0]['examen_id_existente']);
            $result = $importer->confirm($preview, $actor);
            $this->assertSame(0, $result['examenes_creados']);
            $this->assertSame(1, $result['examenes_reactivados']);
            $this->assertSame('TRABAJO EN ALTURA', $exam->fresh()->nombre);
            $this->assertTrue($exam->fresh()->activo);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_bulk_sole_active_exact_name_is_not_ambiguous_with_unrelated_inactive_history(): void
    {
        $active = $this->exam('CURSO OPERATIVO');
        $this->exam('CURSO OPERATIVO', ['activo' => false]);
        $mine = $this->mine();
        $this->requirement($mine, $active);
        $row = app(PersonalMinaBulkPreviewService::class)->plan([$this->bulkRow($this->worker(), 'CURSO OPERATIVO', $mine->nombre)])[0];
        $this->assertSame($active->id, $row['examen_id']);
        $this->assertSame([], $row['issues']);
    }

    public function test_master_cache_keeps_confirmed_aliases_with_different_spacing_separate(): void
    {
        $actor = $this->actor();
        $mine = $this->mine();
        $first = $this->exam('EXAMEN MEDICO');
        $second = $this->exam('CURSO OPERATIVO');
        $organization = app(MiningExamCatalogOrganizationService::class);
        foreach ([[$first, 'EMO A'], [$second, 'EMOA']] as [$exam, $alias]) {
            $this->requirement($mine, $exam);
            $organization->confirm($organization->preview(['accion' => 'asociar', 'principal_id' => $exam->id,
                'aliases' => [['nombre' => $alias, 'mina_id' => $mine->id]], 'motivo' => 'Aliases distintos confirmados'], $actor), $actor);
        }
        $file = $this->master($mine->nombre, 'EMO A', null, 'EMOA');
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertCount(2, $preview['catalogo_examenes'][0]['examenes']);
            $importer->confirm($preview, $actor);
            $this->assertTrue($first->fresh()->activo);
            $this->assertTrue($second->fresh()->activo);
            $this->assertDatabaseHas('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $first->id, 'activo' => true]);
            $this->assertDatabaseHas('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $second->id, 'activo' => true]);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_group_suggests_origin_but_only_explicit_convalidation_changes_destination_with_null_grade(): void
    {
        [$service, $actor, $origin, $target] = $this->convalidationContext();
        $this->assertSame('PENDIENTE', $target->fresh()->estado);
        $options = $service->convalidationOptions($target);
        $this->assertSame($origin->id, $options['options'][0]['id'] ?? null);
        $service->convalidateExam($target, $origin->id, [], $actor);
        $this->assertSame('CONVALIDADO', $target->fresh()->estado);
        $this->assertNull($target->fresh()->nota_obtenida);
        $this->assertSame($origin->examen_id, $target->fresh()->contexto_convalidacion_json['examen_origen_id']);
    }

    public function test_master_uses_destination_variant_for_results_and_reimport_omits_duplicate(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($variant, $principal);
        $mine = $this->mine();
        $this->requirement($mine, $variant);
        $file = $this->master($mine->nombre, $principal->nombre, $this->worker());
        $importer = app(PersonalMinaExcelImportService::class);
        $actor = $this->actor();
        try {
            $preview = $importer->preview($file);
            $this->assertSame($variant->id, $preview['rows'][0]['examenes'][0]['examen_id_existente']);
            $this->assertSame($variant->id, $preview['catalogo_examenes'][0]['examenes'][0]['examen_id_existente']);
            $counts = $importer->confirm($preview, $actor);
            $this->assertSame(1, $counts['intentos_importados']);
            $counts = $importer->confirm($importer->preview($file), $actor);
            $this->assertSame(0, $counts['intentos_importados']);
            $this->assertSame(1, $counts['intentos_omitidos_duplicados']);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_result_failure_rolls_back_assignment_and_catalog_changes(): void
    {
        $exam = $this->exam('CURSO FALLA '.Str::random(6));
        $mine = $this->mine();
        $worker = $this->worker();
        $actor = $this->actor();
        $file = $this->master($mine->nombre, $exam->nombre, $worker);
        $service = \Mockery::mock(PersonalMinaHabilitacionService::class, [
            app(\App\Modules\Notificaciones\Services\OperationalNotificationService::class),
        ])->makePartial();
        $service->shouldReceive('resolveAttemptPriceSnapshot')->once()->andThrow(new \RuntimeException('Simulated attempt failure'));
        $importer = new PersonalMinaExcelImportService($service);
        $activeIds = \App\Models\ExamenMinero::query()->where('activo', true)->orderBy('id')->pluck('id')->all();

        try {
            try {
                $importer->confirm($importer->preview($file), $actor);
                $this->fail('An attempt failure must not be silently accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Simulated attempt failure', $exception->getMessage());
            }
            $this->assertDatabaseMissing('personal_mina', ['personal_id' => $worker->id, 'mina_id' => $mine->id]);
            $this->assertDatabaseMissing('mina_requisitos', ['mina_id' => $mine->id, 'examen_id' => $exam->id]);
            $this->assertSame($activeIds, \App\Models\ExamenMinero::query()->where('activo', true)->orderBy('id')->pluck('id')->all());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_conflicting_grade_does_not_overwrite_existing_result(): void
    {
        $exam = $this->exam('NOTA CONSERVADA '.Str::random(6));
        $mine = $this->mine();
        $worker = $this->worker();
        $actor = $this->actor();
        $file = $this->master($mine->nombre, $exam->nombre, $worker);
        $importer = app(PersonalMinaExcelImportService::class);

        try {
            $preview = $importer->preview($file);
            $preview['rows'][0]['examenes'][0]['datos']['nota'] = 17;
            $importer->confirm($preview, $actor);
            $workerExam = \App\Models\PersonalMinaExamen::query()->whereHas('asignacion',
                fn ($query) => $query->where('personal_id', $worker->id)->where('mina_id', $mine->id))->sole();
            $changed = $importer->preview($file);
            $changed['rows'][0]['examenes'][0]['datos']['nota'] = 19;
            try {
                $importer->confirm($changed, $actor);
                $this->fail('A changed grade must require review.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('archivo', $exception->errors());
            }
            $this->assertSame('17.00', $workerExam->fresh()->nota_obtenida);
            $this->assertSame('17.00', $workerExam->intentos()->sole()->nota);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_older_result_is_historical_and_does_not_replace_current_approval(): void
    {
        $exam = $this->exam('HISTORIA ORDENADA '.Str::random(6));
        $mine = $this->mine();
        $worker = $this->worker();
        $actor = $this->actor();
        $file = $this->master($mine->nombre, $exam->nombre, $worker);
        $importer = app(PersonalMinaExcelImportService::class);

        try {
            $preview = $importer->preview($file);
            $preview['rows'][0]['examenes'][0]['datos']['fecha_realizacion'] = '2026-01-11';
            $importer->confirm($preview, $actor);
            $workerExam = \App\Models\PersonalMinaExamen::query()->whereHas('asignacion',
                fn ($query) => $query->where('personal_id', $worker->id)->where('mina_id', $mine->id))->sole();
            $older = $importer->preview($file);
            $older['rows'][0]['examenes'][0]['datos']['fecha_realizacion'] = '2026-01-10';
            $older['rows'][0]['examenes'][0]['datos']['resultado'] = 'DESAPROBADO';
            $importer->confirm($older, $actor);

            $this->assertSame('APROBADO', $workerExam->fresh()->resultado);
            $this->assertSame('2026-01-11', $workerExam->fresh()->fecha_realizacion->toDateString());
            $this->assertSame(2, $workerExam->intentos()->count());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_failed_renewal_preserves_current_approval(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-06 10:00:00'));
        $exam = $this->exam('MASTER RENOVACION '.Str::random(6));
        $mine = $this->mine();
        $worker = $this->worker();
        $actor = $this->actor();
        $file = $this->master($mine->nombre, $exam->nombre, $worker);
        $importer = app(PersonalMinaExcelImportService::class);
        try {
            $preview = $importer->preview($file);
            $preview['rows'][0]['examenes'][0]['datos']['fecha_realizacion'] = '2026-10-01';
            $preview['rows'][0]['examenes'][0]['datos']['fecha_vencimiento'] = '2026-10-26';
            $importer->confirm($preview, $actor);
            $workerExam = \App\Models\PersonalMinaExamen::query()->whereHas('asignacion',
                fn ($query) => $query->where('personal_id', $worker->id)->where('mina_id', $mine->id))->sole();
            $failed = $importer->preview($file);
            $failed['rows'][0]['examenes'][0]['datos']['resultado'] = 'DESAPROBADO';
            $importer->confirm($failed, $actor);
            $this->assertSame('APROBADO', $workerExam->fresh()->resultado);
            $this->assertSame('2026-10-26', $workerExam->fresh()->fecha_vencimiento->toDateString());
            $this->assertSame('HABILITADO', $workerExam->asignacion->fresh()->estado_habilitacion);
            $this->assertSame(2, $workerExam->intentos()->count());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_blocks_two_destination_requirements_from_same_group(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($variant, $principal);
        $mine = $this->mine();
        $this->requirement($mine, $principal);
        $this->requirement($mine, $variant);
        $file = $this->master($mine->nombre, $principal->nombre);
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertNotEmpty($preview['catalog_identity_errors']);
            $this->expectException(ValidationException::class);
            $importer->confirm($preview, $this->actor());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_group_rejects_expired_foreign_worker_and_low_grade_sources(): void
    {
        [$service, $actor, $origin, $target] = $this->convalidationContext();
        foreach (['expired', 'foreign', 'grade'] as $case) {
            $origin->forceFill(['fecha_vencimiento' => $case === 'expired'
                ? \Carbon\CarbonImmutable::now(config('app.display_timezone', 'America/Lima'))->subDay()->toDateString() : null,
                'nota_obtenida' => $case === 'grade' ? 13 : null])->save();
            $origin->asignacion()->update(['personal_id' => $case === 'foreign' ? $this->worker()->id : $target->asignacion->personal_id]);
            $target->forceFill(['requiere_nota_snapshot' => $case === 'grade', 'nota_minima_snapshot' => 14])->save();
            $this->assertFalse($service->convalidationOptions($target)['available']);
            try {
                $service->convalidateExam($target, $origin->id, [], $actor);
                $this->fail('Invalid source must not be convalidated: '.$case);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertSame('PENDIENTE', $target->fresh()->estado);
        }
    }

    public function test_group_does_not_bypass_grade_requirement_or_disabled_convalidation(): void
    {
        [$service, $actor, $origin, $target] = $this->convalidationContext();
        $target->forceFill(['requiere_nota_snapshot' => true, 'nota_minima_snapshot' => 14])->save();
        $this->assertFalse($service->convalidationOptions($target)['available']);
        try {
            $service->convalidateExam($target, $origin->id, [], $actor);
            $this->fail('Null grade must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('nota', $exception->errors());
        }
        $target->forceFill(['requiere_nota_snapshot' => false])->save();
        $target->requisitoMina()->update(['permite_convalidacion_mina' => false]);
        $this->assertFalse($service->convalidationOptions($target)['available']);
    }

    private function worker(): Personal
    {
        $id = (string) Str::uuid();
        $dni = (string) random_int(72000000, 72999999);
        DB::table('personal')->insert(['id' => $id, 'dni' => $dni, 'tipo_documento' => 'DNI',
            'numero_documento' => $dni, 'nombre_completo' => 'PERSONAL DE PRUEBA', 'puesto' => 'Operario',
            'ocupacion' => 'Tecnico', 'contrato' => 'FIJO', 'es_supervisor' => false,
            'qr_code' => 'QR-'.Str::random(10), 'fecha_ingreso' => '2026-01-01', 'estado' => 'EN_PROCESO']);

        return Personal::query()->findOrFail($id);
    }

    private function bulkRow(Personal $worker, string $exam, string $mine): array
    {
        return ['sheet' => 'resultados', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $exam, 'mine' => $mine,
            'date' => now()->toDateString(), 'result' => 'APROBADO', 'score' => null,
            'expiry' => null, 'observation' => '', 'issues' => []];
    }

    private function master(string $mine, string $exam, ?Personal $worker = null, ?string $additionalExam = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($mine);
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C1', $exam);
        $sheet->setCellValue('D1', $exam);
        $sheet->setCellValue('C2', 'Fecha de realizacion');
        $sheet->setCellValue('D2', 'Resultado');
        if ($additionalExam) {
            $sheet->setCellValue('E1', $additionalExam);
            $sheet->setCellValue('F1', $additionalExam);
            $sheet->setCellValue('E2', 'Fecha de realizacion');
            $sheet->setCellValue('F2', 'Resultado');
        }
        if ($worker) {
            $sheet->setCellValue('A3', $worker->dni);
            $sheet->setCellValue('B3', $worker->nombre_completo);
            $sheet->setCellValue('C3', now()->format('d/m/Y'));
            $sheet->setCellValue('D3', 'APROBADO');
        }
        $path = storage_path('app/testing/identity-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'master.xlsx', null, null, true);
    }

    private function masterProgramarEmo(string $mine, string $exam, Personal $worker): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($mine);
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        foreach (['C1', 'D1', 'E1'] as $cell) {
            $sheet->setCellValue($cell, $exam);
        }
        $sheet->setCellValue('C2', 'Estado EMO');
        $sheet->setCellValue('D2', 'F. PROG.');
        $sheet->setCellValue('E2', 'CLINICA');
        $sheet->setCellValue('A3', $worker->dni);
        $sheet->setCellValue('B3', $worker->nombre_completo);
        $sheet->setCellValue('C3', 'PROGRAMAR EMO');
        $sheet->setCellValue('D3', '07/10/2026');
        $sheet->setCellValue('E3', 'CLINICA NO ES CITA');
        $path = storage_path('app/testing/master-programar-emo-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'master-programar-emo.xlsx', null, null, true);
    }

    private function masterContradictoryProgramarEmo(string $mine, string $exam, Personal $worker,
        ?string $scheduledDate): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($mine);
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        foreach (['C1', 'D1', 'E1', 'F1'] as $cell) {
            $sheet->setCellValue($cell, $exam);
        }
        $sheet->setCellValue('C2', 'Estado EMO');
        $sheet->setCellValue('D2', 'F. PROG.');
        $sheet->setCellValue('E2', 'LUGAR / CLINICA');
        $sheet->setCellValue('F2', 'APLICA');
        $sheet->setCellValue('A3', $worker->dni);
        $sheet->setCellValue('B3', $worker->nombre_completo);
        $sheet->setCellValue('C3', 'PROGRAMAR EMO');
        $sheet->setCellValue('D3', $scheduledDate);
        $sheet->setCellValue('E3', 'CLINICA CONTRADICTORIA');
        $sheet->setCellValue('F3', 'NO APLICA');
        $path = storage_path('app/testing/master-programar-emo-contradictorio-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'master-programar-emo-contradictorio.xlsx', null, null, true);
    }

    private function convalidationContext(): array
    {
        $principal = $this->exam('CURSO PRINCIPAL');
        $variant = $this->exam('CURSO VARIANTE');
        $this->associate($variant, $principal);
        $service = app(PersonalMinaHabilitacionService::class);
        $actor = $this->actor();
        $worker = $this->worker();
        $results = [];
        foreach ([$principal, $variant] as $exam) {
            $mine = $this->mine();
            $this->requirement($mine, $exam);
            $assignment = $service->assignMine(['personal_id' => $worker->id, 'mina_id' => $mine->id,
                'estado_habilitacion' => 'EN_PROCESO'], $actor);
            $results[] = $assignment->examenes->first();
        }
        $service->registerAttempt($results[0], ['resultado' => 'APROBADO',
            'fecha_realizacion' => \Carbon\CarbonImmutable::now(config('app.display_timezone', 'America/Lima'))->toDateString()], null, $actor);

        return [$service, $actor, $results[0]->fresh(), $results[1]->fresh()];
    }
}
