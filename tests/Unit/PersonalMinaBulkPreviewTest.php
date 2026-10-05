<?php

namespace Tests\Unit;

use App\Modules\Personal\Services\PersonalMinaBulkPreviewService;
use App\Models\Personal;
use App\Models\ExamenMinero;
use App\Models\Mina;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PersonalMinaBulkPreviewTest extends TestCase
{
    public function test_preview_keeps_review_filter_and_escapes_workbook_content(): void
    {
        $row = ['sheet' => 'notas', 'line' => 2, 'type' => 'Resultado', 'dni' => '01234567',
            'name' => '<script>alert(1)</script>', 'personal' => null, 'exam' => 'NO EXISTE',
            'mine' => 'CERRO VERDE', 'matched_mine' => null,
            'matched_exam' => null, 'date' => null, 'result' => '', 'score' => null,
            'expiry' => null, 'observation' => '', 'issues' => ['Examen no encontrado en el catalogo.'],
            'categories' => ['examen']];
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = view('personal.habilitacion-minera.bulk-preview', [
            'rows' => [$row], 'report' => (new PersonalMinaBulkPreviewService)->report([$row]),
        ])->render();
        $this->assertStringContainsString('id="bulk-filter"', $html);
        $this->assertStringContainsString('data-bulk-categories="examen"', $html);
        $this->assertStringContainsString('No hay filas en esta categoria.', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_reference_validation_reports_unknown_inactive_ambiguous_and_wrong_identity(): void
    {
        $service = new PersonalMinaBulkPreviewService;
        $base = ['dni' => '01234567', 'name' => 'PERSONAL PRUEBA', 'exam' => 'induccion', 'mine' => 'CERRO VERDE', 'issues' => []];
        $workers = collect([new Personal(['id' => 'p1', 'dni' => '01234567', 'nombre_completo' => 'PERSONAL PRUEBA']),
            new Personal(['id' => 'p2', 'dni' => '99999999', 'nombre_completo' => 'OTRO']),
            new Personal(['id' => 'p3', 'dni' => '99999999', 'nombre_completo' => 'DUPLICADO'])]);
        $exams = collect([
            new ExamenMinero(['id' => 'e1', 'nombre' => ' INDUCCION ', 'activo' => true]),
            new ExamenMinero(['id' => 'e2', 'nombre' => 'INACTIVO', 'activo' => false]),
            new ExamenMinero(['id' => 'e3', 'nombre' => 'DUPLICADO', 'activo' => true]),
            new ExamenMinero(['id' => 'e4', 'nombre' => 'duplicado', 'activo' => true]),
        ]);
        $mines = collect([new Mina(['id' => 'm1', 'nombre' => 'CERRO VERDE', 'unidad_minera' => 'Cerro Verde'])]);
        $rows = $service->validateReferences([
            $base,
            array_replace($base, ['dni' => '88888888', 'exam' => 'NO EXISTE']),
            array_replace($base, ['dni' => '99999999', 'exam' => 'DUPLICADO']),
            array_replace($base, ['name' => 'NOMBRE DIFERENTE', 'exam' => 'INACTIVO']),
        ], $workers, $exams, $mines);
        $this->assertSame([], $rows[0]['issues']);
        $this->assertSame(' INDUCCION ', $rows[0]['matched_exam']);
        $this->assertContains('DNI no encontrado en Personal.', $rows[1]['issues']);
        $this->assertContains('Examen no encontrado en el catalogo.', $rows[1]['issues']);
        $this->assertContains('Documento ambiguo en Personal.', $rows[2]['issues']);
        $this->assertNull($rows[2]['matched_exam']);
        $this->assertContains('El examen existe, pero esta inactivo.', $rows[3]['issues']);
        $this->assertContains('identidad', $rows[3]['categories']);
        $report = $service->report($rows);
        $this->assertSame(3, $report['with_issues']);
        $this->assertSame([2, 3, 0, 1, 0, 0], array_column($report['groups'], 'count'));
    }

    public function test_rejects_bad_dates_scores_and_formulas_and_preserves_invalid_document(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'MINA'],
            ['1234567', '', 'CURSO', '31/02/2026', 'CERRO VERDE'],
            ['01234567', 'PERSONAL PRUEBA', 'CURSO', '10/01/2026', 'CERRO VERDE'],
        ]);
        $book->createSheet()->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'REALIZACION', 'RESULTADO', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'],
            ['01234567', 'PERSONAL PRUEBA', 'CURSO', '09/01/2026', 'APROBADO', -1, '08/01/2026', '=1+1', 'CERRO VERDE'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'bulk-test');
        try {
            (new Xlsx($book))->save($path);
            $rows = (new PersonalMinaBulkPreviewService)->read($path);
            $this->assertSame('1234567', $rows[0]['dni']);
            $this->assertNull($rows[0]['date']);
            $this->assertContains('Falta el nombre del trabajador.', $rows[0]['issues']);
            $this->assertContains('La nota debe ser un numero no negativo.', $rows[2]['issues']);
            $this->assertNotContains('La realizacion es anterior a la programacion de este archivo.', $rows[2]['issues']);
            $this->assertContains('Vencimiento invalido o anterior a la realizacion.', $rows[2]['issues']);
            $this->assertContains('Formula no permitida en OBSERVACION. Use un valor, no una formula.', $rows[2]['issues']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_reads_both_sheets_dates_formulas_and_duplicates_without_writing_database(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'MINA'],
            ['01234567', 'PERSONAL PRUEBA', 'CURSO', 46000, 'CERRO VERDE'],
            ['01234567', 'PERSONAL PRUEBA', 'CURSO', 46000, 'CERRO VERDE'],
        ]);
        $book->createSheet()->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'REALIZACION', 'RESULTADO', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'],
            ['01234567', 'PERSONAL PRUEBA', 'CURSO', 46000, 'APTO CON RESTRICCION', null, '=+D2+365', 'REVISAR', 'CERRO VERDE'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'bulk-test');
        try {
            (new Xlsx($book))->save($path);
            $rows = (new PersonalMinaBulkPreviewService)->read($path);
            $this->assertCount(3, $rows);
            $this->assertSame('01234567', $rows[0]['dni']);
            $this->assertSame('2025-12-09', $rows[0]['date']);
            $this->assertContains('Fila duplicada en esta hoja.', $rows[1]['issues']);
            $this->assertContains('Fila duplicada en esta hoja.', $rows[0]['issues']);
            $this->assertSame('2026-12-09', $rows[2]['expiry']);
            $this->assertSame([], $rows[2]['issues']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_rejects_missing_second_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-test');
        $book = new Spreadsheet;
        try {
            (new Xlsx($book))->save($path);
            $this->expectException(ValidationException::class);
            (new PersonalMinaBulkPreviewService)->read($path);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_rejects_the_old_programming_layout_without_target_mine(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-test');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION'],
            ['01234567', 'PERSONAL PRUEBA', 'CURSO', '10/01/2026'],
        ]);
        $book->createSheet()->fromArray([
            ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'REALIZACION', 'RESULTADO', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'],
        ]);

        try {
            (new Xlsx($book))->save($path);
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('se esperaba MINA');
            (new PersonalMinaBulkPreviewService)->read($path);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
