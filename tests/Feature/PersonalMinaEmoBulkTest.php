<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMinaExamen;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Services\EmoAppointmentService;
use App\Modules\Personal\Services\PersonalCursoBulkService;
use App\Modules\Personal\Services\PersonalMinaBulkPreviewService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use App\Modules\Personal\Support\ScheduleEmoData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class PersonalMinaEmoBulkTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    private Personal $worker;

    private Usuario $actor;

    private ExamenMinero $exam;

    private ClinicaEmo $clinic;

    private PersonalMinaHabilitacionService $mining;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'America/Lima'));
        $this->app->instance(OperationalNotificationService::class,
            \Mockery::mock(OperationalNotificationService::class)->shouldIgnoreMissing());
        $this->actor = $this->actor();
        $this->mining = app(PersonalMinaHabilitacionService::class);
        $document = (string) random_int(76000000, 76999999);
        $this->worker = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => $document, 'numero_documento' => $document,
            'tipo_documento' => 'DNI', 'nombre_completo' => 'BULK EMO '.Str::random(8),
            'puesto' => 'Operario', 'ocupacion' => 'Operario', 'estado' => 'ACTIVO',
            'contrato' => 'FIJO', 'qr_code' => 'TEST-'.Str::uuid(), 'es_supervisor' => false,
        ]);
        $this->exam = $this->exam('EMO BULK '.Str::random(8), ['categoria_operativa' => ExamenMinero::CATEGORIA_EMO]);
        $this->clinic = $this->clinic('Clinica Pulso');
    }

    public function test_preview_resolves_exact_active_clinic_and_never_writes(): void
    {
        $mine = $this->configuredMine();
        $before = [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()];

        $row = $this->plan([$this->row('Programacion', $mine->nombre)])[0];

        $this->assertSame($this->clinic->id, $row['clinic_id']);
        $this->assertSame($this->clinic->nombre, $row['clinic_name']);
        $this->assertSame('Crear cita EMO', $row['appointment_action']);
        $this->assertSame([], $row['issues']);
        $this->assertSame($before, [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()]);
    }

    public function test_preview_blocks_missing_unknown_and_inactive_clinics(): void
    {
        $mine = $this->configuredMine();
        $inactive = $this->clinic('Clinica Inactiva', false);
        $rows = $this->plan([
            $this->row('Programacion', $mine->nombre, clinic: ''),
            $this->row('Programacion', $mine->nombre, clinic: 'No existe', date: '2026-10-09'),
            $this->row('Programacion', $mine->nombre, clinic: $inactive->nombre, date: '2026-10-10'),
        ]);

        foreach ($rows as $row) {
            $this->assertContains('clinica', $row['categories']);
            $this->assertNotEmpty($row['issues']);
        }
        $this->assertSame(0, PersonalEmoCita::count());
    }

    public function test_old_layout_is_blocked_for_emo_and_result_never_infers_schedule_from_performed_date(): void
    {
        $mine = $this->configuredMine();
        $old = $this->row('Programacion', $mine->nombre, layout: 1, clinic: '');
        $result = $this->row('Resultado', $mine->nombre, scheduled: null, performed: '2026-10-07');

        [$plannedOld, $plannedResult] = $this->plan([$old, $result]);

        $this->assertContains('clinica', $plannedOld['categories']);
        $this->assertContains('EMO requiere el layout v2 con CLINICA.', $plannedOld['issues']);
        $this->assertContains('El resultado EMO requiere PROGRAMACION para identificar la cita.', $plannedResult['issues']);
    }

    public function test_emo_result_rejects_generic_course_outcome(): void
    {
        $mine = $this->configuredMine();
        $row = $this->plan([$this->row('Resultado', $mine->nombre, result: 'APROBADO')])[0];

        $this->assertContains('archivo', $row['categories']);
        $this->assertStringContainsString('resultado EMO valido', implode(' ', $row['issues']));
    }

    public function test_excel_bulk_conserva_detalle_medico_embebido_si_observacion_esta_vacia(): void
    {
        $mine = $this->configuredMine();
        $book = new Spreadsheet;
        $programming = $book->getActiveSheet();
        $programming->setTitle('programaciones');
        $programming->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'CLINICA', 'MINA'],
            [$this->worker->dni, $this->worker->nombre_completo, $this->exam->nombre, '07/10/2026', $this->clinic->nombre, $mine->nombre],
        ]);
        $results = $book->createSheet();
        $results->setTitle('resultados');
        $results->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'REALIZACION', 'RESULTADO', 'CLINICA', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'],
            [$this->worker->dni, $this->worker->nombre_completo, $this->exam->nombre, '07/10/2026', '07/10/2026',
                'APTO CON RESTRICCIONES: Usar lentes', $this->clinic->nombre, null, null, '', $mine->nombre],
        ]);
        $path = storage_path('app/testing/emo-bulk-detail-'.Str::random(8).'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($book))->save($path);

        try {
            $rows = app(PersonalMinaBulkPreviewService::class)->preview($path);
        } finally {
            @unlink($path);
        }

        $result = collect($rows)->firstWhere('type', 'Resultado');
        $this->assertSame('APTO CON RESTRICCION', $result['result']);
        $this->assertSame('Usar lentes', $result['observation']);
        $this->assertSame([], $result['issues']);
    }

    public function test_preview_blocks_two_clinics_in_file_and_existing_other_clinic(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();
        $other = $this->clinic('Clinica Natlan');
        $rows = $this->plan([
            $this->row('Programacion', $firstMine->nombre),
            $this->row('Programacion', $secondMine->nombre, clinic: $other->nombre),
        ]);
        $this->assertContains('historial', $rows[0]['categories']);
        $this->assertContains('historial', $rows[1]['categories']);

        $exam = $this->workerExam($firstMine->id);
        app(EmoAppointmentService::class)->schedule($exam,
            new ScheduleEmoData(
                CarbonImmutable::parse('2026-10-07'), $other->id,
            ), $this->actor);
        $conflict = $this->plan([$this->row('Programacion', $secondMine->nombre)])[0];
        $this->assertContains('historial', $conflict['categories']);
        $this->assertStringContainsString('otra clinica', implode(' ', $conflict['issues']));
    }

    public function test_same_clinic_two_mines_share_appointment_and_reimport_is_idempotent(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();
        $source = [
            $this->row('Programacion', $firstMine->nombre),
            $this->row('Programacion', $secondMine->nombre),
        ];

        $first = $this->apply($source);
        $reimportRows = $this->plan($source);
        $this->assertSame(['Sin cambios', 'Sin cambios'], array_column($reimportRows, 'appointment_action'),
            json_encode($reimportRows, JSON_THROW_ON_ERROR));
        $second = $this->apply($source);

        $appointment = PersonalEmoCita::query()->where('personal_id', $this->worker->id)->sole();
        $this->assertCount(2, $appointment->intentos);
        $this->assertSame(2, $first['creados']);
        $this->assertSame(2, $second['omitidos'], json_encode($second, JSON_THROW_ON_ERROR));
        $this->assertSame(1, PersonalEmoCita::query()->where('personal_id', $this->worker->id)->count());
    }

    public function test_result_completes_shared_appointment_once_for_all_linked_mines(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();
        $this->apply([
            $this->row('Programacion', $firstMine->nombre),
            $this->row('Programacion', $secondMine->nombre),
        ]);

        $summary = $this->apply([
            $this->row('Resultado', $firstMine->nombre, result: 'APTO CON OBSERVACION', observation: 'Control anual'),
        ]);

        $appointment = PersonalEmoCita::query()->where('personal_id', $this->worker->id)->sole();
        $this->assertSame('COMPLETADA', $appointment->estado);
        $this->assertSame('APTO_CON_OBSERVACION', $appointment->resultado_emo);
        $this->assertSame('Control anual', $appointment->detalle_resultado_emo);
        $this->assertSame(['APROBADO', 'APROBADO'], $appointment->intentos()->pluck('resultado')->all());
        $this->assertSame(1, $summary['actualizados']);
    }

    public function test_same_file_can_schedule_shared_appointment_then_complete_it_atomically(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();

        $summary = $this->apply([
            $this->row('Programacion', $firstMine->nombre),
            $this->row('Programacion', $secondMine->nombre),
            $this->row('Resultado', $firstMine->nombre, result: 'NO APTO'),
        ]);

        $appointment = PersonalEmoCita::query()->where('personal_id', $this->worker->id)->sole();
        $this->assertSame('COMPLETADA', $appointment->estado);
        $this->assertSame('NO_APTO', $appointment->resultado_emo);
        $this->assertSame(['DESAPROBADO', 'DESAPROBADO'], $appointment->intentos()->pluck('resultado')->all());
        $this->assertSame(2, $summary['creados']);
        $this->assertSame(1, $summary['actualizados']);
    }

    public function test_duplicate_equal_result_rows_for_shared_appointment_complete_once(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();
        $this->apply([
            $this->row('Programacion', $firstMine->nombre),
            $this->row('Programacion', $secondMine->nombre),
        ]);

        $summary = $this->apply([
            $this->row('Resultado', $firstMine->nombre, result: 'APTO'),
            $this->row('Resultado', $secondMine->nombre, result: 'APTO'),
        ]);

        $appointment = PersonalEmoCita::query()->where('personal_id', $this->worker->id)->sole();
        $this->assertSame('COMPLETADA', $appointment->estado);
        $this->assertSame(1, $summary['actualizados']);
        $this->assertSame(1, $summary['omitidos']);
    }

    public function test_confirmation_rejects_change_after_preview_and_rolls_back_whole_file(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();
        $source = [
            $this->row('Programacion', $firstMine->nombre),
            $this->row('Programacion', $secondMine->nombre, date: '2026-10-08'),
        ];
        $preview = $this->preview($source);
        $this->clinic->update(['activo' => false]);

        try {
            app(PersonalCursoBulkService::class)->apply($preview, $this->actor);
            $this->fail('El cambio posterior al preview debe bloquear toda la carga.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
        }

        $this->assertSame(0, PersonalEmoCita::query()->where('personal_id', $this->worker->id)->count());
        $this->assertSame(0, DB::table('personal_mina_examen_intentos')->whereIn(
            'personal_mina_examen_id', PersonalMinaExamen::query()->whereHas('asignacion',
                fn ($query) => $query->where('personal_id', $this->worker->id))->pluck('id'))->count());
    }

    public function test_confirmation_rejects_appointment_revision_change_after_preview(): void
    {
        $mine = $this->configuredMine();
        $appointment = app(EmoAppointmentService::class)->schedule(
            $this->workerExam($mine->id),
            new ScheduleEmoData(
                CarbonImmutable::parse('2026-10-07'), $this->clinic->id,
            ), $this->actor,
        );
        $preview = $this->preview([$this->row('Resultado', $mine->nombre, result: 'APTO')]);
        $appointment->increment('revision');

        try {
            app(PersonalCursoBulkService::class)->apply($preview, $this->actor);
            $this->fail('Una revision nueva de la cita debe invalidar el preview.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
        }

        $this->assertSame('PROGRAMADA', $appointment->fresh()->estado);
        $this->assertSame('PENDIENTE', $appointment->intentos()->sole()->resultado);
    }

    public function test_mid_file_failure_rolls_back_every_appointment_and_attempt(): void
    {
        $firstMine = $this->configuredMine();
        $secondMine = $this->configuredMine();
        $source = [
            $this->row('Programacion', $firstMine->nombre, date: '2026-10-07'),
            $this->row('Programacion', $secondMine->nombre, date: '2026-10-08'),
        ];
        $created = 0;
        PersonalEmoCita::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new \RuntimeException('Fallo controlado de segunda cita');
            }
        });

        try {
            $this->apply($source);
            $this->fail('El fallo de una fila debe revertir el archivo completo.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo controlado de segunda cita', $exception->getMessage());
        } finally {
            PersonalEmoCita::flushEventListeners();
        }

        $this->assertSame(0, PersonalEmoCita::query()->where('personal_id', $this->worker->id)->count());
        $this->assertSame(0, DB::table('personal_mina_examen_intentos')->whereIn(
            'personal_mina_examen_id', PersonalMinaExamen::query()->whereHas('asignacion',
                fn ($query) => $query->where('personal_id', $this->worker->id))->pluck('id'))->count());
    }

    public function test_result_for_unlinked_mine_blocks_file_with_new_or_existing_appointment(): void
    {
        $first = $this->configuredMine();
        $second = $this->configuredMine();
        $source = [
            $this->row('Programacion', $first->nombre),
            $this->row('Resultado', $second->nombre, result: 'APTO'),
        ];

        foreach ([false, true] as $existing) {
            if ($existing) {
                $this->apply([$source[0]]);
            }
            $before = [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()];
            $plan = $this->plan($source);
            $this->assertNotEmpty($plan[1]['issues']);
            $this->assertSame('issues', $plan[1]['disposition']);
            try {
                $this->apply($source);
                $this->fail('El resultado exige un enlace a su mina exacta antes de confirmar.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('archivo', $exception->errors());
            }
            $this->assertSame($before, [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()]);
            $this->assertNull($this->workerExam($second->id)->fresh()->resultado);
            if ($existing) {
                $this->assertSame('PROGRAMADA', PersonalEmoCita::where('personal_id', $this->worker->id)->sole()->estado);
            }
        }
    }

    public function test_same_day_exact_exam_conflict_blocks_every_row_even_for_confirmed_variants(): void
    {
        $firstExam = $this->exam;
        $first = $this->configuredMine();
        $firstRow = $this->row('Programacion', $first->nombre);
        $this->exam = $this->exam('OTRO EMO '.Str::random(8), ['categoria_operativa' => ExamenMinero::CATEGORIA_EMO]);
        $this->associate($this->exam, $firstExam);
        $second = $this->configuredMine();
        $source = [$firstRow, $this->row('Programacion', $second->nombre)];

        $plan = $this->plan($source);

        $this->assertSame([$firstExam->id, $this->exam->id], array_column($plan, 'examen_id'));
        foreach ($plan as $row) {
            $this->assertContains('historial', $row['categories']);
            $this->assertSame('issues', $row['disposition']);
        }
        try {
            $this->apply($source);
            $this->fail('Variantes confirmadas no comparten una cita sin examen_id exacto.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
        }
        $this->assertSame(0, PersonalEmoCita::where('personal_id', $this->worker->id)->count());
    }

    public function test_optional_score_reimport_uses_each_linked_exams_persisted_snapshot(): void
    {
        $first = $this->configuredMine();
        $second = $this->configuredMine();
        $this->workerExam($second->id)->update(['requiere_nota_snapshot' => true, 'nota_minima_snapshot' => 14]);
        $this->apply([
            $this->row('Programacion', $first->nombre),
            $this->row('Programacion', $second->nombre),
        ]);
        $result = $this->row('Resultado', $first->nombre, result: 'APTO');
        $result['score'] = 15;
        $this->apply([$result]);
        $appointment = PersonalEmoCita::where('personal_id', $this->worker->id)->sole();
        $this->assertNull($this->workerExam($first->id)->intentos()->sole()->nota);
        $this->assertSame('15.00', $this->workerExam($second->id)->intentos()->sole()->nota);
        $beforeRevision = $appointment->revision;

        $plan = $this->plan([$result]);

        $this->assertSame([], $plan[0]['issues']);
        $this->assertSame('Sin cambios', $plan[0]['appointment_action']);
        $this->assertSame(1, $this->apply([$result])['omitidos']);
        $this->assertSame($beforeRevision, $appointment->fresh()->revision);
        $result['score'] = 16;
        $this->assertNotEmpty($this->plan([$result])[0]['issues']);
    }

    public function test_preview_blocks_pending_legacy_emo_from_another_exact_exam_without_writes(): void
    {
        $legacyMine = $this->configuredMine();
        $legacyExam = $this->workerExam($legacyMine->id);
        $legacyExam->intentos()->create([
            'id' => (string) Str::uuid(), 'numero_intento' => 1,
            'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
            'usuario_registro_id' => $this->actor->id,
        ]);
        $this->exam = $this->exam('NUEVO EMO '.Str::random(8), ['categoria_operativa' => ExamenMinero::CATEGORIA_EMO]);
        $target = $this->configuredMine();
        $source = [$this->row('Programacion', $target->nombre)];
        $before = [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()];

        $row = $this->plan($source)[0];

        $this->assertContains('historial', $row['categories']);
        $this->assertStringContainsString('Regularice', implode(' ', $row['issues']));
        $this->assertSame('issues', $row['disposition']);
        $this->assertSame($before, [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()]);
        try {
            $this->apply($source);
            $this->fail('Un EMO historico pendiente debe regularizarse antes de confirmar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
        }
        $this->assertSame($before, [PersonalEmoCita::count(), DB::table('personal_mina_examen_intentos')->count()]);
        $this->assertNull($legacyExam->intentos()->sole()->emo_cita_id);
    }

    private function configuredMine(): Mina
    {
        $mine = $this->mine();
        $this->requirement($mine, $this->exam);
        $this->mining->assignMine(['personal_id' => $this->worker->id, 'mina_id' => $mine->id], $this->actor, false);

        return $mine;
    }

    private function workerExam(string $mineId): PersonalMinaExamen
    {
        return PersonalMinaExamen::query()->where('examen_id', $this->exam->id)
            ->whereHas('asignacion', fn ($query) => $query->where('personal_id', $this->worker->id)->where('mina_id', $mineId))
            ->sole();
    }

    private function clinic(string $name, bool $active = true): ClinicaEmo
    {
        return ClinicaEmo::query()->create([
            'nombre' => $name, 'nombre_normalizado' => Str::upper(Str::ascii(Str::squish($name))), 'activo' => $active,
        ]);
    }

    private function row(string $type, string $mine, ?string $clinic = null, string $date = '2026-10-07',
        int $layout = 2, ?string $scheduled = '2026-10-07', ?string $performed = '2026-10-07',
        string $result = '', string $observation = ''): array
    {
        $isResult = $type === 'Resultado';

        return [
            'sheet' => $isResult ? 'resultados' : 'programaciones', 'line' => 2, 'type' => $type,
            'layout_version' => $layout, 'dni' => $this->worker->dni, 'name' => $this->worker->nombre_completo,
            'exam' => $this->exam->nombre, 'mine' => $mine, 'clinic' => $clinic ?? $this->clinic->nombre,
            'scheduled_date' => $isResult ? $scheduled : $date, 'performed_date' => $isResult ? $performed : null,
            'date' => $isResult ? $performed : $date, 'result' => $result,
            'score' => null, 'expiry' => null, 'observation' => $observation, 'issues' => [],
        ];
    }

    private function plan(array $source): array
    {
        return app(PersonalMinaBulkPreviewService::class)->plan($source);
    }

    private function preview(array $source): array
    {
        $bulk = app(PersonalCursoBulkService::class);
        $rows = $this->plan($source);

        return ['token' => (string) Str::uuid(), 'source' => $source, 'rows' => $rows,
            'fingerprint' => $bulk->fingerprint($rows)];
    }

    private function apply(array $source): array
    {
        return app(PersonalCursoBulkService::class)->apply($this->preview($source), $this->actor);
    }
}
