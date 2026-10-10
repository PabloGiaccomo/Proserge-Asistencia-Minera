<?php

namespace Tests\Feature;

use App\Models\ClinicaEmo;
use App\Models\ExamenMinero;
use App\Models\Personal;
use App\Models\PersonalCursoRegistro;
use App\Models\PersonalEmoCita;
use App\Models\PersonalEmoExportAudit;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Personal\Controllers\PersonalMinaScheduledControlExportController;
use App\Modules\Personal\Services\PersonalMinaScheduledControlExcelService;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Support\Rbac\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class PersonalMinaScheduledControlExportTest extends TestCase
{
    use DatabaseTransactions;
    use MiningCatalogFixtures;

    private Personal $worker;

    private Usuario $exportActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        config(['app.display_timezone' => 'America/Lima']);
        $this->exportActor = $this->actor();
        $this->worker = Personal::create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(72000000, 72999999),
            'numero_documento' => '00123456', 'nombre_completo' => 'Exportacion '.Str::uuid(),
            'puesto' => 'Soldador', 'qr_code' => (string) Str::uuid(),
        ]);
        // Also exercise the controller without permission middleware to verify defense in depth.
        if (! class_exists(PersonalMinaScheduledControlExportController::class)) {
            return;
        }
        Route::middleware(['web', 'web.auth'])
            ->get('/_test/scheduled-export-controller', PersonalMinaScheduledControlExportController::class);
    }

    public function test_workbook_exports_every_page_and_preserves_emo_detail_and_filtered_mines(): void
    {
        $emo = $this->workerExam('EMO');
        $other = $this->workerExam('EMO', $emo->examen);
        $clinic = ClinicaEmo::create(['nombre' => 'Clinica del control', 'nombre_normalizado' => 'CLINICA DEL CONTROL', 'activo' => true]);
        $appointment = PersonalEmoCita::create(['personal_id' => $this->worker->id,
            'clinica_id' => $clinic->id, 'clinica_nombre_snapshot' => $clinic->nombre,
            'fecha_programacion' => '2026-10-07', 'fecha_realizacion' => '2026-10-08',
            'estado' => 'COMPLETADA', 'resultado_emo' => 'APTO_CON_OBSERVACION',
            'detalle_resultado_emo' => 'Control anual', 'origen' => 'MANUAL']);
        foreach ([$emo, $other] as $exam) {
            $this->attempt($exam, ['emo_cita_id' => $appointment->id, 'resultado' => 'APROBADO', 'fecha_realizacion' => '2026-10-08']);
        }
        for ($i = 2; $i <= 31; $i++) {
            $this->attempt($emo, ['numero_intento' => $i]);
        }
        $this->attempt($emo, ['numero_intento' => 32, 'fecha_programacion' => '2026-09-01']);
        $course = $this->workerExam('CURSO');
        $this->attempt($course);

        $response = $this->download($this->filters(['scheduled_category' => 'EMO',
            'scheduled_mine_id' => $emo->asignacion->mina_id, 'scheduled_page' => 2, 'scheduled_limit' => 25]));
        $this->assertStringContainsString('control_asistencia_cursos_emo_', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $book = $this->openWorkbook($response);
        $control = $book->getSheetByName('Control');
        $this->assertSame(['Control', 'Filtros'], $book->getSheetNames());
        $this->assertSame(['Fecha programada', 'Tipo', 'Clinica', 'Trabajador', 'Documento', 'Cargo',
            'Curso/EMO', 'Minas', 'Intentos', 'Asistencia', 'Resultado EMO', 'Detalle',
            'Fecha realizada', 'Estado operativo'], $control->rangeToArray('A1:N1')[0]);
        $this->assertSame(32, $control->getHighestDataRow());
        $rows = $control->rangeToArray('A2:N32');
        foreach ($rows as $row) {
            $this->assertSame('EMO', $row[1]);
            $this->assertSame($emo->asignacion->mina->nombre, $row[7]);
            $this->assertSame('00123456', $row[4]);
        }
        $completed = collect($rows)->first(fn ($row) => $row[10] === 'APTO_CON_OBSERVACION');
        $this->assertNotNull($completed);
        $this->assertSame('2026-10-07', $completed[0]);
        $this->assertSame('Clinica del control', $completed[2]);
        $this->assertSame('ASISTIO', $completed[9]);
        $this->assertSame('Control anual', $completed[11]);
        $this->assertSame('2026-10-08', $completed[12]);
        $this->assertSame(DataType::TYPE_STRING, $control->getCell('E2')->getDataType());
        $this->assertSame('A2', $control->getFreezePane());
        $this->assertSame('A1:N32', $control->getAutoFilter()->getRange());
        $filters = $book->getSheetByName('Filtros')->toArray();
        $this->assertContains(['scheduled_mine_id', $emo->asignacion->mina_id], $filters);
        $this->assertContains(['scheduled_category', 'EMO'], $filters);
        $this->assertContains(['scheduled_from', '2026-10-01'], $filters);
        $this->assertContains(['scheduled_to', '2026-10-31'], $filters);
        $this->assertContains(['Actor', $this->exportActor->email], $filters);
        $generated = collect($filters)->first(fn ($row) => $row[0] === 'Fecha/hora de generacion');
        $this->assertNotNull($generated);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} -05$/', $generated[1]);
        $this->assertStringNotContainsString('scheduled_limit', json_encode($filters));
        $this->assertStringNotContainsString('scheduled_page', json_encode($filters));
        $book->disconnectWorksheets();
    }

    #[DataProvider('formulaPrefixes')]
    public function test_untrusted_text_is_literal_without_altering_visible_value(string $text): void
    {
        // Legacy database text can predate the Personal model's trim/uppercase mutator.
        DB::table('personal')->where('id', $this->worker->id)->update(['nombre_completo' => $text, 'puesto' => $text]);
        $exam = $this->workerExam('EMO');
        $exam->update(['nombre_snapshot' => $text]);
        $exam->asignacion->mina->update(['nombre' => $text]);
        $clinic = ClinicaEmo::create(['nombre' => 'Prueba '.Str::uuid(), 'nombre_normalizado' => Str::uuid(), 'activo' => true]);
        $appointment = PersonalEmoCita::create(['personal_id' => $this->worker->id,
            'clinica_id' => $clinic->id, 'clinica_nombre_snapshot' => $text,
            'fecha_programacion' => '2026-10-07', 'fecha_realizacion' => '2026-10-07',
            'estado' => 'COMPLETADA', 'resultado_emo' => 'APTO_CON_RESTRICCION',
            'detalle_resultado_emo' => $text, 'origen' => 'MANUAL']);
        $this->attempt($exam, ['emo_cita_id' => $appointment->id, 'resultado' => 'APROBADO']);
        $this->exportActor->update(['email' => $text]);
        $book = $this->openWorkbook($this->download($this->filters()));
        foreach (['C2', 'D2', 'F2', 'G2', 'H2', 'L2'] as $coordinate) {
            $cell = $book->getSheetByName('Control')->getCell($coordinate);
            $this->assertSame($text, $cell->getValue(), $coordinate);
            $this->assertSame($text, $cell->getFormattedValue());
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
            $this->assertTrue($cell->getStyle()->getQuotePrefix());
        }
        $filterSheet = $book->getSheetByName('Filtros');
        foreach ($filterSheet->toArray() as $index => $row) {
            if ($row[0] === 'Actor') {
                $cell = $filterSheet->getCell('B'.($index + 1));
                $this->assertSame($text, $cell->getFormattedValue());
                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                $this->assertTrue($cell->getStyle()->getQuotePrefix());
            }
        }
        $book->disconnectWorksheets();
    }

    public static function formulaPrefixes(): array
    {
        return [['  =1+1'], ['  +SUM(1,2)'], ['  -2+3'], ['  @SUM(1,2)']];
    }

    public function test_success_audit_contains_only_actor_timestamp_normalized_functional_filters_and_grouped_count(): void
    {
        $exam = $this->workerExam('CURSO');
        $second = $this->workerExam('CURSO', $exam->examen);
        $record = PersonalCursoRegistro::create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id,
            'examen_id' => $exam->examen_id, 'mina_id' => $exam->asignacion->mina_id,
            'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE',
            'catalogo_snapshot' => [], 'usuario_registro_id' => $this->exportActor->id, 'origen' => 'Prueba']);
        foreach ([$exam, $second] as $workerExam) {
            $this->attempt($workerExam, ['personal_curso_registro_id' => $record->id]);
        }
        $before = now();
        $this->consume($this->download($this->filters(['scheduled_worker' => '  00123456  ', 'scheduled_page' => 4])));
        $audit = PersonalEmoExportAudit::query()->sole();
        $this->assertSame($this->exportActor->id, $audit->usuario_id);
        $this->assertSame(1, $audit->cantidad_filas);
        $this->assertTrue($audit->exportado_at->greaterThanOrEqualTo($before->startOfSecond()));
        $this->assertSame(['scheduled_worker' => '00123456', 'scheduled_from' => '2026-10-01',
            'scheduled_to' => '2026-10-31'], $audit->filtros_json);
        $this->assertSame(64, strlen($audit->filtros_hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit->filtros_hash);
        $this->consume($this->download($this->filters(['scheduled_worker' => '00123456', 'scheduled_page' => 99, 'scheduled_limit' => 100])));
        $this->assertSame([$audit->filtros_hash], PersonalEmoExportAudit::query()->distinct()->pluck('filtros_hash')->all());
        $this->assertStringNotContainsString($this->worker->nombre_completo, json_encode($audit->filtros_json));
    }

    #[DataProvider('deniedPermissions')]
    public function test_requires_both_permissions_before_validation_even_without_permission_middleware(array $permissions): void
    {
        $this->assertTrue(class_exists(PersonalMinaScheduledControlExportController::class), 'Falta el controlador del Excel.');
        $this->exportActor->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['habilitacion_minera' => $permissions])]);
        foreach ([route('personal.habilitacion-minera.scheduled.export'), '/_test/scheduled-export-controller'] as $url) {
            $this->withSession($this->catalogSession($this->exportActor))
                ->getJson($url.'?scheduled_from=invalid&scheduled_exam_id=invalid')->assertForbidden();
        }
        $this->assertSame(0, PersonalEmoExportAudit::query()->count());
    }

    public static function deniedPermissions(): array
    {
        return [[[]], [['exportar']], [['ver_programados']], [['ver']]];
    }

    public function test_controller_with_both_permissions_uses_shared_validation_and_streams_excel(): void
    {
        $this->assertTrue(class_exists(PersonalMinaScheduledControlExportController::class), 'Falta el controlador del Excel.');
        $this->exportActor->rol->update(['permisos' => PermissionCatalog::matrixFromSelections(['habilitacion_minera' => ['ver_programados', 'exportar']])]);
        $exam = $this->workerExam('CURSO');
        $this->attempt($exam);
        $this->withSession($this->catalogSession($this->exportActor))
            ->getJson('/_test/scheduled-export-controller?scheduled_from=invalid')->assertUnprocessable()->assertJsonValidationErrors('scheduled_from');
        $this->assertSame(0, PersonalEmoExportAudit::query()->count());
        $response = $this->withSession($this->catalogSession($this->exportActor))
            ->get(route('personal.habilitacion-minera.scheduled.export', ['scheduled_from' => '2026-10-01', 'scheduled_to' => '2026-10-31']))->assertOk();
        $this->assertStringStartsWith('PK', $response->streamedContent());
        $this->assertSame(1, PersonalEmoExportAudit::query()->where('usuario_id', $this->exportActor->id)->count());
    }

    public function test_exactly_10000_grouped_rows_export_and_10001_rejects_without_success_audit(): void
    {
        $catalog = $this->exam('Limite '.Str::uuid(), ['categoria_operativa' => 'CURSO']);
        $rows = [];
        for ($group = 0; $group < 40; $group++) {
            $exam = $this->workerExam('CURSO', $catalog);
            for ($attempt = 1; $attempt <= 250; $attempt++) {
                $rows[] = ['id' => (string) Str::uuid(), 'personal_mina_examen_id' => $exam->id,
                    'numero_intento' => $attempt, 'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE'];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('personal_mina_examen_intentos')->insert($chunk);
        }
        $book = $this->openWorkbook($this->download($this->filters(['scheduled_page' => 500])));
        $this->assertSame(10001, $book->getSheetByName('Control')->getHighestDataRow());
        $this->assertSame(10000, PersonalEmoExportAudit::query()->sole()->cantidad_filas);
        $book->disconnectWorksheets();
        $this->attempt($exam, ['numero_intento' => 251]);
        try {
            $this->download($this->filters());
            $this->fail('Debe rechazar mas de 10000 filas sin truncar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_to', $exception->errors());
        }
        $this->assertSame(1, PersonalEmoExportAudit::query()->count());
    }

    public function test_audit_persistence_failure_emits_no_workbook_bytes(): void
    {
        $exam = $this->workerExam('CURSO');
        $this->attempt($exam);
        $response = $this->download($this->filters());
        $dispatcher = PersonalEmoExportAudit::getEventDispatcher();
        PersonalEmoExportAudit::setEventDispatcher(clone $dispatcher);
        PersonalEmoExportAudit::creating(function (): void {
            throw new \RuntimeException('Simulated audit persistence failure');
        });
        ob_start();
        try {
            $response->sendContent();
            $this->fail('Una falla de auditoria debe impedir la descarga.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit persistence failure', $exception->getMessage());
            $this->assertSame('', ob_get_contents(), 'No debe entregar un Excel sin auditoria.');
            $this->assertSame(0, PersonalEmoExportAudit::query()->count());
        } finally {
            ob_end_clean();
            PersonalEmoExportAudit::setEventDispatcher($dispatcher);
        }
    }

    private function filters(array $input = []): ScheduledControlFilters
    {
        return ScheduledControlFilters::fromValidated($input + ['scheduled_from' => '2026-10-01',
            'scheduled_to' => '2026-10-31'], CarbonImmutable::parse('2026-10-07', 'America/Lima'));
    }

    private function download(ScheduledControlFilters $filters): StreamedResponse
    {
        $this->assertTrue(class_exists(PersonalMinaScheduledControlExcelService::class), 'Falta el exportador del control.');

        return app(PersonalMinaScheduledControlExcelService::class)->download($filters, $this->exportActor);
    }

    private function consume(StreamedResponse $response): string
    {
        ob_start();
        try {
            $response->sendContent();

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function openWorkbook(StreamedResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'control-test-');
        try {
            file_put_contents($path, $this->consume($response));

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function workerExam(string $category, ?ExamenMinero $catalog = null): PersonalMinaExamen
    {
        $catalog ??= $this->exam('Exportacion '.Str::uuid(), ['categoria_operativa' => $category]);
        $mine = $this->mine();
        $assignment = PersonalMina::create(['id' => (string) Str::uuid(), 'personal_id' => $this->worker->id,
            'mina_id' => $mine->id, 'activo' => true, 'estado' => 'EN_PROCESO']);

        return PersonalMinaExamen::create(['id' => (string) Str::uuid(), 'personal_mina_id' => $assignment->id,
            'examen_id' => $catalog->id, 'nombre_snapshot' => $catalog->nombre, 'estado' => 'PROGRAMADO']);
    }

    private function attempt(PersonalMinaExamen $exam, array $extra = []): PersonalMinaExamenIntento
    {
        return PersonalMinaExamenIntento::create($extra + ['id' => (string) Str::uuid(),
            'personal_mina_examen_id' => $exam->id, 'numero_intento' => 1,
            'fecha_programacion' => '2026-10-07', 'resultado' => 'PENDIENTE']);
    }
}
