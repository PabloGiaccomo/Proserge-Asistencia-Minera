<?php

namespace Tests\Feature;

use App\Modules\Evaluaciones\Services\EvaluacionesHistoricalReconciler;
use App\Modules\Evaluaciones\Services\EvaluacionesHistoricalWorkbookReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EvaluacionesHistoricalImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_historical_attendance_is_separate_from_operational_attendance(): void
    {
        $this->assertTrue(Schema::hasTable('asistencia_historica'));
        foreach (['trabajador_id', 'fecha', 'presente', 'source_file', 'source_sheet', 'source_row', 'source_id', 'lote_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('asistencia_historica', $column));
        }
        $this->assertFalse(Schema::hasColumn('asistencia_historica', 'mina_id'));
        $this->assertFalse(Schema::hasColumn('asistencia_historica', 'parada_id'));
    }

    public function test_historical_evaluation_can_keep_unknown_mine_and_evaluator_without_fake_ids(): void
    {
        $this->assertTrue(Schema::hasTable('evaluaciones_import_lotes'));
        foreach (['import_lote_id', 'source_file', 'source_sheet', 'source_row', 'source_id', 'source_mina', 'source_evaluador'] as $column) {
            $this->assertTrue(Schema::hasColumn('evaluacion_desempeno', $column));
        }

        $nullable = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->whereIn('column_name', ['mina_id', 'supervisor_id'])
            ->pluck('is_nullable', 'column_name');
        $this->assertSame('YES', $nullable['mina_id']);
        $this->assertSame('YES', $nullable['supervisor_id']);
    }

    public function test_reader_resolves_control_internal_id_through_personal_sheet(): void
    {
        $legacyId = 'ABC12345';
        $worker = $this->personal('01234567');
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet()->setTitle('PERSONAL');
        $sheet->fromArray([['Id', 'DNI', 'Nombre Completo'], [$legacyId, '01234567', 'PERSONA HISTORICA']], null, 'A1');
        $sheet = $workbook->createSheet()->setTitle('EV DESEMPENO');
        $sheet->fromArray([['Id Desempeno', 'Fecha', 'Hora', 'Mina', 'Semana', 'Desempeno En El Trabajo', 'Orden Y Limpieza', 'Compromiso', 'Respuesta Emocional', 'Seguridad Trabajo', 'Total', 'Observaciones', 'DNI Supervisor', 'DNI Empleado', 'Tuvo incidencia', 'Descripcion Incidencia', 'Id Detalle'], ['EV1', '2025-04-30', '09:00', 'CERRO VERDE', 18, 3, 3, 3, 3, 3, 15, '', '', $legacyId, 0, '', null]], null, 'A1');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-hist-'.Str::random(12).'.xlsx';
        (new Xlsx($workbook))->save($path);

        try {
            $read = app(EvaluacionesHistoricalWorkbookReader::class)->read(['control' => $path]);
            $manifest = app(EvaluacionesHistoricalReconciler::class)->reconcile($read);
            $this->assertSame($worker, $manifest['proposed'][0]['trabajador_id']);
            $this->assertSame('01234567', $manifest['proposed'][0]['dni']);
        } finally {
            unlink($path);
            $workbook->disconnectWorksheets();
        }
    }

    public function test_reconciler_blocks_unknown_identity_bad_date_and_grade_without_collapsing_legitimate_rows(): void
    {
        $worker = $this->personal('12345678');
        $base = [
            'type' => 'daily', 'source_file' => 'consolidado', 'source_sheet' => 'Consolidado',
            'source_id' => null, 'dni_ref' => '12345678', 'name' => 'PERSONA HISTORICA',
            'date' => '2025-04-30', 'scores' => [3, 3, 3, 3, 3], 'mine' => 'CERRO VERDE',
        ];
        $rows = [
            [...$base, 'source_row' => 2],
            [...$base, 'source_row' => 3],
            [...$base, 'source_row' => 4, 'dni_ref' => '99999999'],
            [...$base, 'source_row' => 5, 'date' => 'fecha imposible'],
            [...$base, 'source_row' => 6, 'scores' => [3, 3, 5, 3, 3]],
            [...$base, 'source_row' => 7, 'dni_ref' => '1234567'],
        ];
        $manifest = app(EvaluacionesHistoricalReconciler::class)->reconcile(['rows' => $rows, 'personal_map' => []]);

        $this->assertCount(2, $manifest['proposed']);
        $this->assertSame($worker, $manifest['proposed'][0]['trabajador_id']);
        $this->assertNotSame($manifest['proposed'][0]['source_key'], $manifest['proposed'][1]['source_key']);
        $this->assertCount(4, $manifest['blocked']);
    }

    public function test_reader_rejects_wrong_columns_before_mapping_personal_data(): void
    {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet()->setTitle('Consolidado');
        $sheet->fromArray([['NOMBRE', 'DNI EQUIVOCADO'], ['12345678', 'PERSONA']], null, 'A1');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-hist-'.Str::random(12).'.xlsx';
        (new Xlsx($workbook))->save($path);

        try {
            $read = app(EvaluacionesHistoricalWorkbookReader::class)->read(['consolidado' => $path]);
            $this->assertSame([], $read['rows']);
            $this->assertNotEmpty($read['issues']);
        } finally {
            unlink($path);
            $workbook->disconnectWorksheets();
        }
    }

    public function test_existing_source_key_is_unchanged_only_when_identity_date_and_scores_match(): void
    {
        $worker = $this->personal('87654321');
        $row = [
            'type' => 'daily', 'source_file' => 'control', 'source_sheet' => 'EV DESEMPENO',
            'source_row' => 2, 'source_id' => 'EV-OLD-1', 'dni_ref' => '87654321',
            'date' => '2025-04-30', 'scores' => [3, 3, 3, 3, 3],
        ];
        $key = hash('sha256', 'control|EV DESEMPENO|EV-OLD-1');
        DB::table('evaluacion_desempeno')->insert([
            'id' => (string) Str::uuid(), 'fecha' => '2025-04-30', 'hora' => '09:00:00',
            'mina_id' => null, 'supervisor_id' => null, 'trabajador_id' => $worker,
            'desempeno_trabajo' => 3, 'orden_limpieza' => 3, 'compromiso' => 3,
            'respuesta_emocional' => 3, 'seguridad_trabajo' => 3, 'total' => 15,
            'origen' => 'HISTORICA', 'source_key' => $key,
        ]);
        $service = app(EvaluacionesHistoricalReconciler::class);

        $same = $service->reconcile(['rows' => [$row], 'personal_map' => []]);
        $this->assertCount(0, $same['proposed']);
        $this->assertCount(1, $same['unchanged']);

        $changed = $service->reconcile(['rows' => [[...$row, 'scores' => [4, 3, 3, 3, 3]]], 'personal_map' => []]);
        $this->assertCount(0, $changed['proposed']);
        $this->assertCount(1, $changed['blocked']);
    }

    public function test_two_books_match_by_dni_date_scores_and_preserve_multiplicity(): void
    {
        $this->personal('34567890');
        $base = [
            'type' => 'daily', 'source_sheet' => 'EV DESEMPENO', 'dni_ref' => '34567890',
            'date' => '2025-04-30', 'scores' => [4, 3, 3, 3, 3],
        ];
        $control = [...$base, 'source_file' => 'control', 'source_row' => 2, 'source_id' => 'EV-1'];
        $consolidado = [...$base, 'source_file' => 'consolidado', 'source_sheet' => 'Consolidado', 'source_row' => 8, 'source_id' => null];
        $service = app(EvaluacionesHistoricalReconciler::class);

        $matched = $service->reconcile(['rows' => [$control, $consolidado], 'personal_map' => []]);
        $this->assertCount(1, $matched['proposed']);
        $this->assertSame('control', $matched['proposed'][0]['source_file']);
        $this->assertCount(1, $matched['unchanged']);

        $conflict = $service->reconcile(['rows' => [$control, [...$consolidado, 'scores' => [3, 3, 3, 3, 3]]], 'personal_map' => []]);
        $this->assertCount(0, $conflict['proposed']);
        $this->assertCount(2, $conflict['blocked']);
    }

    public function test_repeated_source_id_blocks_both_rows_before_confirmation(): void
    {
        $this->personal('45678901');
        $base = [
            'type' => 'daily', 'source_file' => 'control', 'source_sheet' => 'EV DESEMPENO',
            'source_id' => 'EV-DUP', 'dni_ref' => '45678901', 'date' => '2025-04-30',
            'scores' => [3, 3, 3, 3, 3],
        ];
        $manifest = app(EvaluacionesHistoricalReconciler::class)->reconcile([
            'rows' => [[...$base, 'source_row' => 2], [...$base, 'source_row' => 3]],
            'personal_map' => [],
        ]);

        $this->assertCount(0, $manifest['proposed']);
        $this->assertCount(2, $manifest['blocked']);
    }

    public function test_preview_requires_both_primary_workbooks(): void
    {
        $read = app(EvaluacionesHistoricalWorkbookReader::class)->read([]);

        $this->assertCount(2, $read['issues']);
        $this->assertSame([], $read['rows']);
    }

    private function personal(string $dni): string
    {
        $id = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $id, 'dni' => $dni, 'nombre_completo' => 'PERSONA HISTORICA',
            'puesto' => 'TECNICO', 'qr_code' => 'QR-'.Str::upper(Str::random(8)), 'estado' => 'ACTIVO',
        ]);

        return $id;
    }
}
