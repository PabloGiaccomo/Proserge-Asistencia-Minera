<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Personal;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Modules\Personal\Requests\PersonalMinaScheduledControlRequest;
use App\Modules\Personal\Services\PersonalMinaScheduledControlService;
use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Modules\Personal\Support\ScheduledControlRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class PersonalMinaScheduledControlQueryTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    private Personal $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        config(['app.display_timezone' => 'America/Lima']);
        $this->worker = Personal::create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(71000000, 71999999),
            'numero_documento' => '00123456', 'nombre_completo' => 'CONTROL '.Str::uuid(),
            'puesto' => 'Soldador de control', 'qr_code' => (string) Str::uuid(),
        ]);
    }

    public function test_defaults_use_lima_day_and_normalize_optional_filters(): void
    {
        $this->assertTrue(class_exists(ScheduledControlFilters::class));
        $filters = ScheduledControlFilters::fromValidated(['scheduled_worker' => '  Soldador  ',
            'scheduled_clinic_id' => '  ', 'scheduled_category' => 'CURSO',
            'scheduled_attendance_state' => 'PROGRAMADO', 'scheduled_limit' => '50',
            'scheduled_page' => '2'], CarbonImmutable::parse('2026-10-08 02:00:00', 'UTC'));
        $this->assertSame('2026-09-07', $filters->from->toDateString());
        $this->assertSame('2026-12-06', $filters->to->toDateString());
        $this->assertSame('America/Lima', $filters->from->timezoneName);
        $this->assertSame('Soldador', $filters->worker);
        $this->assertNull($filters->clinicId);
        $this->assertSame('CURSO', $filters->category->value);
        $this->assertSame('PROGRAMADO', $filters->attendanceState->value);
        $this->assertSame(50, $filters->limit);
        $this->assertSame(2, $filters->page);
        $this->assertSame('2026-09-07', $filters->toQuery()['scheduled_from']);
    }

    #[DataProvider('invalidFilters')]
    public function test_rejects_invalid_dates_ranges_and_http_values(array $input, string $field): void
    {
        $this->assertTrue(class_exists(ScheduledControlFilters::class));
        try {
            $this->filters($input);
            $this->fail('Debe rechazar '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidFilters(): array
    {
        return [
            [['scheduled_from' => '2026-10-08', 'scheduled_to' => '2026-10-07'], 'scheduled_to'],
            [['scheduled_from' => '2026-01-01', 'scheduled_to' => '2027-01-02'], 'scheduled_to'],
            [['scheduled_from' => '2026-02-30'], 'scheduled_from'],
            [['scheduled_to' => '07/10/2026'], 'scheduled_to'],
            [['scheduled_limit' => 500], 'scheduled_limit'],
            [['scheduled_page' => 0], 'scheduled_page'],
            [['scheduled_exam_id' => 'nombre'], 'scheduled_exam_id'],
            [['scheduled_category' => 'OTRO'], 'scheduled_category'],
            [['scheduled_attendance_state' => 'APROBADO'], 'scheduled_attendance_state'],
            [['scheduled_worker' => str_repeat('a', 101)], 'scheduled_worker'],
        ];
    }

    public function test_request_uses_shared_validation_and_accepts_a_366_day_inclusive_range(): void
    {
        $this->assertTrue(class_exists(PersonalMinaScheduledControlRequest::class));
        $request = PersonalMinaScheduledControlRequest::create('/', 'GET', [
            'scheduled_from' => '2026-01-01', 'scheduled_to' => '2027-01-01',
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();
        $filters = ScheduledControlFilters::fromValidated($request->validated(), CarbonImmutable::now('America/Lima'));
        $this->assertSame('2027-01-01', $filters->to->toDateString());
        $request = PersonalMinaScheduledControlRequest::create('/', 'GET', [
            'scheduled_from' => '2026-10-08', 'scheduled_to' => '2026-10-07',
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $this->expectException(ValidationException::class);
        $request->validateResolved();
    }

    public function test_request_preserves_literal_zero_search_and_pagination_preserves_current_tab(): void
    {
        $request = PersonalMinaScheduledControlRequest::create('/', 'GET', ['scheduled_worker' => ' 0 ']);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();
        $this->assertSame('0', $request->validated()['scheduled_worker']);
        $this->app['request']->query->set('vista', 'scheduled');
        $page = $this->service()->paginate($this->filters(), 25);
        $this->assertStringContainsString('vista=scheduled', $page->url(2));
        $this->assertArrayNotHasKey('scheduled_page', $this->filters()->toQuery(false));
        $this->assertArrayNotHasKey('scheduled_limit', $this->filters()->toQuery(false));
    }

    public function test_pagination_uses_filter_limit_when_legacy_argument_disagrees(): void
    {
        $exam = $this->workerExam('CURSO');
        for ($index = 1; $index <= 30; $index++) {
            $this->attempt($exam, ['numero_intento' => $index]);
        }

        $filters = $this->filters(['scheduled_limit' => 25]);
        $page = $this->service()->paginate($filters, 100);

        $this->assertSame(25, $page->perPage());
        $this->assertCount(25, $page->items());
        $this->assertStringContainsString('scheduled_limit=25', $page->url(2));
    }

    public function test_date_bounds_are_inclusive_and_shared_course_record_is_one_physical_row(): void
    {
        $first = $this->workerExam('CURSO');
        $second = $this->workerExam('CURSO', $first->examen);
        $record = $this->record($first, ['fecha_programacion' => '2026-09-07']);
        $this->attempt($first, ['fecha_programacion' => '2026-09-07', 'personal_curso_registro_id' => $record->id]);
        $this->attempt($second, ['fecha_programacion' => '2026-09-07', 'personal_curso_registro_id' => $record->id]);
        $last = $this->attempt($first, ['numero_intento' => 2, 'fecha_programacion' => '2026-12-06']);
        $this->attempt($first, ['numero_intento' => 3, 'fecha_programacion' => '2026-09-06']);
        $this->attempt($first, ['numero_intento' => 4, 'fecha_programacion' => '2026-12-07']);
        $rows = $this->service()->allForExport($this->filters())->keyBy('rowKey');
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->has($last->id));
        $this->assertCount(2, $rows[$record->id]->mineIds);
        $filtered = $this->service()->allForExport($this->filters(['scheduled_mine_id' => $second->asignacion->mina_id]));
        $this->assertCount(1, $filtered);
        $this->assertSame([$second->asignacion->mina_id], $filtered[0]->mineIds);
        $this->assertCount(1, $filtered[0]->attemptLabels);
    }

    public function test_filters_more_than_500_rows_in_sql_before_pagination_and_keeps_query(): void
    {
        $exam = $this->workerExam('CURSO');
        $exams = [$exam, $this->workerExam('CURSO', $exam->examen), $this->workerExam('CURSO', $exam->examen)];
        $rows = [];
        for ($index = 1; $index <= 520; $index++) {
            $rows[] = ['id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exams[($index - 1) % 3]->id,
                'numero_intento' => intdiv($index - 1, 3) + 1, 'fecha_programacion' => $index <= 510 ? '2026-10-07' : '2026-10-08',
                'resultado' => $index <= 510 ? 'PENDIENTE' : 'NO_ASISTIO'];
        }
        DB::table('personal_mina_examen_intentos')->insert($rows);
        $filters = $this->filters(['scheduled_worker' => 'Soldador', 'scheduled_category' => 'CURSO',
            'scheduled_exam_id' => $exam->examen_id,
            'scheduled_limit' => 25, 'scheduled_page' => 2]);
        DB::enableQueryLog();
        $page = $this->service()->paginate($filters, $filters->limit);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(520, $page->total());
        $this->assertSame(2, $page->currentPage());
        $this->assertCount(25, $page->items());
        $this->assertInstanceOf(ScheduledControlRow::class, $page->items()[0]);
        $this->assertLessThanOrEqual(3, count($queries), 'La pagina no debe consultar relaciones por fila.');
        $this->assertStringContainsString('scheduled_worker=Soldador', $page->url(3));
        $this->assertStringContainsString('scheduled_page=3', $page->url(3));
        $this->assertStringContainsString('limit 25 offset 25', strtolower(collect($queries)->pluck('query')->implode(' ')));
        $noShows = $this->service()->paginate($this->filters(['scheduled_attendance_state' => 'NO_ASISTIO']), 25);
        $this->assertSame(10, $noShows->total());
        $this->assertSame(174, $this->service()->paginate($this->filters([
            'scheduled_mine_id' => $exam->asignacion->mina_id]), 25)->total());
        $this->assertSame(10, $this->service()->paginate($this->filters(['scheduled_from' => '2026-10-08',
            'scheduled_to' => '2026-10-08']), 25)->total());
        $this->assertSame(520, $this->service()->paginate($this->filters(['scheduled_worker' => '00123456']), 25)->total());
        $this->assertSame(520, $this->service()->paginate($this->filters(['scheduled_worker' => $this->worker->nombre_completo]), 25)->total());
        $this->assertSame(0, $this->service()->paginate($this->filters(['scheduled_worker' => 'no coincide']), 25)->total());
        $this->assertCount(50, $this->service()->paginate($this->filters(['scheduled_limit' => 50]), 50)->items());
        $this->assertCount(100, $this->service()->paginate($this->filters(['scheduled_limit' => 100]), 100)->items());
    }

    public function test_four_row_identities_shared_mines_and_legacy_clinic_are_preserved(): void
    {
        $emo = $this->workerExam('EMO');
        $second = $this->workerExam('EMO', $emo->examen);
        $clinic = $this->clinic();
        $appointment = $this->appointment($clinic);
        $this->attempt($emo, ['emo_cita_id' => $appointment->id]);
        $this->attempt($second, ['emo_cita_id' => $appointment->id]);
        $legacy = $this->attempt($emo, ['numero_intento' => 2]);
        $legacyOther = $this->attempt($second, ['numero_intento' => 2]);
        $course = $this->workerExam('CURSO');
        $record = $this->record($course);
        $this->attempt($course, ['personal_curso_registro_id' => $record->id]);
        $legacyCourse = $this->attempt($course, ['numero_intento' => 2]);
        $rows = $this->service()->allForExport($this->filters())->keyBy('rowKey');
        $this->assertCount(5, $rows);
        $this->assertCount(2, $rows[$appointment->id]->mineIds);
        $this->assertCount(2, $rows[$appointment->id]->attemptLabels);
        $this->assertSame($clinic->id, $rows[$appointment->id]->clinicId);
        $this->assertTrue($rows->has($record->id));
        $this->assertTrue($rows->has($legacyCourse->id));
        $this->assertNull($rows['legacy-emo-attempt:'.$legacy->id]->clinicId);
        $this->assertSame('Historico sin clinica', $rows['legacy-emo-attempt:'.$legacy->id]->clinicName);
        $this->assertTrue($rows->has('legacy-emo-attempt:'.$legacyOther->id));
        $this->assertCount(2, $this->service()->allForExport($this->filters(['scheduled_category' => 'CURSO'])));
        $this->assertCount(3, $this->service()->allForExport($this->filters(['scheduled_category' => 'EMO'])));
        $this->assertCount(3, $this->service()->allForExport($this->filters(['scheduled_exam_id' => $emo->examen_id])));
        $this->assertCount(2, $this->service()->allForExport($this->filters(['scheduled_exam_id' => $course->examen_id])));
        $this->assertCount(0, $this->service()->allForExport($this->filters(['scheduled_clinic_id' => (string) Str::uuid()])));
        $filtered = $this->service()->allForExport($this->filters(['scheduled_clinic_id' => $clinic->id,
            'scheduled_mine_id' => $emo->asignacion->mina_id]));
        $this->assertCount(1, $filtered);
        $this->assertSame([$emo->asignacion->mina_id], $filtered[0]->mineIds);
        $this->assertSame([$emo->asignacion->mina->nombre], $filtered[0]->mineNames);
        $this->assertCount(1, $filtered[0]->attemptLabels);
        $this->assertSame('00123456', $filtered[0]->workerDocument);
        $this->assertSame('PROGRAMADO', $filtered[0]->attendanceState);
        $this->assertTrue((new \ReflectionClass($filtered[0]))->isReadOnly());
    }

    public function test_only_modern_emo_row_exposes_appointment_revision(): void
    {
        $emo = $this->workerExam('EMO');
        $clinic = $this->clinic();
        $appointment = $this->appointment($clinic, ['revision' => 7]);
        $this->attempt($emo, ['emo_cita_id' => $appointment->id]);
        $legacyEmo = $this->attempt($emo, ['numero_intento' => 2]);
        $course = $this->workerExam('CURSO');
        $courseRecord = $this->record($course);
        $this->attempt($course, ['personal_curso_registro_id' => $courseRecord->id]);
        $legacyCourse = $this->attempt($course, ['numero_intento' => 2]);

        $rows = $this->service()->allForExport($this->filters())->keyBy('rowKey');

        $this->assertSame(7, $rows[$appointment->id]->appointmentRevision);
        $this->assertNull($rows['legacy-emo-attempt:'.$legacyEmo->id]->appointmentRevision);
        $this->assertNull($rows[$courseRecord->id]->appointmentRevision);
        $this->assertNull($rows[$legacyCourse->id]->appointmentRevision);
    }

    #[DataProvider('attendanceCases')]
    public function test_course_and_emo_attendance_precedence(string $category, string $result, ?string $performed,
        string $appointmentState, string $expected): void
    {
        $exam = $this->workerExam($category);
        $extra = ['resultado' => $result, 'fecha_realizacion' => $performed];
        if ($category === 'CURSO') {
            $extra['personal_curso_registro_id'] = $this->record($exam, $extra)->id;
        } else {
            $extra['emo_cita_id'] = $this->appointment($this->clinic(), [
                'estado' => $appointmentState, 'fecha_realizacion' => $performed,
                'resultado_emo' => $result === 'APROBADO' ? 'APTO_CON_OBSERVACION' : null,
                'detalle_resultado_emo' => $result === 'APROBADO' ? 'Control anual' : null,
            ])->id;
        }
        $this->attempt($exam, $extra);
        $rows = $this->service()->allForExport($this->filters(['scheduled_attendance_state' => $expected]));
        $this->assertCount(1, $rows);
        $this->assertSame($expected, $rows[0]->attendanceState);
        if ($category === 'EMO' && $result === 'APROBADO') {
            $this->assertSame(EmoOutcome::APTO_CON_OBSERVACION, $rows[0]->emoOutcome);
            $this->assertSame('Control anual', $rows[0]->resultDetail);
        }
    }

    public static function attendanceCases(): array
    {
        return [
            ['CURSO', 'PENDIENTE', null, 'PROGRAMADA', 'PROGRAMADO'],
            ['CURSO', 'APROBADO', null, 'PROGRAMADA', 'ASISTIO'],
            ['CURSO', 'PENDIENTE', '2026-10-07', 'PROGRAMADA', 'ASISTIO'],
            ['CURSO', 'NO_ASISTIO', '2026-10-07', 'PROGRAMADA', 'NO_ASISTIO'],
            ['CURSO', 'ANULADO', '2026-10-07', 'PROGRAMADA', 'ANULADO'],
            ['EMO', 'PENDIENTE', null, 'PROGRAMADA', 'PROGRAMADO'],
            ['EMO', 'PENDIENTE', '2026-10-07', 'PROGRAMADA', 'ASISTIO'],
            ['EMO', 'APROBADO', '2026-10-07', 'COMPLETADA', 'ASISTIO'],
            ['EMO', 'NO_ASISTIO', '2026-10-07', 'PROGRAMADA', 'NO_ASISTIO'],
            ['EMO', 'NO_ASISTIO', '2026-10-07', 'ANULADA', 'ANULADO'],
        ];
    }

    public function test_export_reads_max_plus_one_and_rejects_overflow_without_truncation(): void
    {
        $exam = $this->workerExam('CURSO');
        for ($i = 1; $i <= 3; $i++) {
            $this->attempt($exam, ['numero_intento' => $i]);
        }
        $filters = $this->filters(['scheduled_page' => 99, 'scheduled_limit' => 25]);
        $this->assertCount(3, $this->service()->allForExport($filters, 3));
        DB::enableQueryLog();
        try {
            $this->service()->allForExport($filters, 2);
            $this->fail('No debe exportar una lista truncada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_to', $exception->errors());
            $this->assertStringContainsString('rango', implode(' ', $exception->errors()['scheduled_to']));
            $this->assertCount(1, DB::getQueryLog());
            $this->assertStringContainsString('limit 3', strtolower(DB::getQueryLog()[0]['query']));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_options_ignore_text_dates_and_state_keep_selected_inactive_clinic(): void
    {
        $emo = $this->workerExam('EMO');
        $clinic = $this->clinic();
        $appointment = $this->appointment($clinic);
        $this->attempt($emo, ['emo_cita_id' => $appointment->id]);
        $clinic->update(['activo' => false]);
        $selected = $this->filters(['scheduled_clinic_id' => $clinic->id]);
        $options = $this->service()->filterOptions($selected);
        $ignored = $this->service()->filterOptions($this->filters(['scheduled_clinic_id' => $clinic->id,
            'scheduled_worker' => 'ausente', 'scheduled_from' => '2020-01-01',
            'scheduled_to' => '2020-01-01', 'scheduled_attendance_state' => 'ANULADO']));
        $this->assertEquals($options, $ignored);
        $this->assertContains($clinic->id, array_column($options['clinics'], 'id'));
        $this->assertContains($emo->examen_id, array_column($options['exams'], 'id'));
        $this->assertContains($emo->asignacion->mina_id, array_column($options['mines'], 'id'));
        $this->assertNotContains($clinic->id, array_column($this->service()->filterOptions($this->filters())['clinics'], 'id'));
        $this->assertCount(4, $options['attendanceStates']);
    }

    public function test_unlinked_course_record_is_visible_and_origin_mine_filters_correctly(): void
    {
        $exam = $this->workerExam('CURSO');
        $record = $this->record($exam);
        $rows = $this->service()->allForExport($this->filters(['scheduled_mine_id' => $record->mina_id]));
        $this->assertCount(1, $rows);
        $this->assertSame($record->id, $rows[0]->rowKey);
        $this->assertSame([], $rows[0]->attemptLabels);
    }

    private function filters(array $input = []): ScheduledControlFilters
    {
        $this->assertTrue(class_exists(ScheduledControlFilters::class));

        return ScheduledControlFilters::fromValidated($input, CarbonImmutable::parse('2026-10-07', 'America/Lima'));
    }

    private function service(): PersonalMinaScheduledControlService
    {
        $this->assertTrue(class_exists(PersonalMinaScheduledControlService::class));

        return app(PersonalMinaScheduledControlService::class);
    }

    private function workerExam(string $category, ?ExamenMinero $catalog = null): PersonalMinaExamen
    {
        $catalog ??= $this->exam('Control '.Str::uuid(), ['categoria_operativa' => $category]);
        $mine = $this->mine();
        $assignment = PersonalMina::create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id,
            'mina_id' => $mine->id, 'activo' => true, 'estado' => 'EN_PROCESO']);

        return PersonalMinaExamen::create(['id' => (string) Str::uuid(), 'personal_mina_id' => $assignment->id,
            'examen_id' => $catalog->id, 'nombre_snapshot' => $catalog->nombre,
            'lugar_snapshot' => 'NO ES UNA CLINICA', 'estado' => 'PROGRAMADO']);
    }

    private function attempt(PersonalMinaExamen $exam, array $extra = []): PersonalMinaExamenIntento
    {
        return PersonalMinaExamenIntento::create($extra + ['id' => (string) Str::uuid(),
            'personal_mina_examen_id' => $exam->id, 'numero_intento' => 1,
            'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE']);
    }

    private function record(PersonalMinaExamen $exam, array $extra = []): PersonalCursoRegistro
    {
        unset($extra['personal_curso_registro_id']);

        return PersonalCursoRegistro::create($extra + ['id' => (string) Str::uuid(),
            'personal_id' => $this->worker->id, 'examen_id' => $exam->examen_id,
            'mina_id' => $exam->asignacion->mina_id, 'fecha_programacion' => '2026-10-07',
            'resultado' => 'PENDIENTE', 'catalogo_snapshot' => [],
            'usuario_registro_id' => $this->actor()->id, 'origen' => 'Prueba control']);
    }

    private function clinic(): ClinicaEmo
    {
        $name = 'Clinica '.Str::uuid();

        return ClinicaEmo::create(['nombre' => $name, 'nombre_normalizado' => strtoupper($name), 'activo' => true]);
    }

    private function appointment(ClinicaEmo $clinic, array $extra = []): PersonalEmoCita
    {
        return PersonalEmoCita::create($extra + ['personal_id' => $this->worker->id,
            'clinica_id' => $clinic->id, 'clinica_nombre_snapshot' => $clinic->nombre,
            'fecha_programacion' => '2026-10-07', 'estado' => 'PROGRAMADA', 'origen' => 'MANUAL']);
    }
}
