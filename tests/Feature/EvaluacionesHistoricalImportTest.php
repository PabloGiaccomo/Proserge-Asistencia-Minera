<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EvaluacionesHistoricalImportTest extends TestCase
{
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
}
