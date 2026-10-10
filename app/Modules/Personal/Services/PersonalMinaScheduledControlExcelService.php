<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalEmoExportAudit;
use App\Models\Usuario;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Modules\Personal\Support\ScheduledControlRow;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalMinaScheduledControlExcelService
{
    public function __construct(private readonly PersonalMinaScheduledControlService $control) {}

    public function download(ScheduledControlFilters $filters, Usuario $actor): StreamedResponse
    {
        // The shared query reads 10001 grouped rows and rejects overflow before a workbook exists.
        $rows = $this->control->allForExport($filters);
        $generatedAt = CarbonImmutable::now(config('app.display_timezone', 'America/Lima'));
        $functionalFilters = $filters->toQuery(false);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Control');
        $this->writeRow($sheet, 1, ['Fecha programada', 'Tipo', 'Clinica', 'Trabajador',
            'Documento', 'Cargo', 'Curso/EMO', 'Minas', 'Intentos', 'Asistencia',
            'Resultado EMO', 'Detalle', 'Fecha realizada', 'Estado operativo']);
        foreach ($rows as $index => $row) {
            $this->writeRow($sheet, $index + 2, $this->rowValues($row));
        }
        $this->styleSheet($sheet, 'N', [14, 10, 26, 36, 16, 24, 32, 32, 30, 16, 24, 42, 14, 24]);
        $sheet->setAutoFilter('A1:N'.($rows->count() + 1));

        $filterSheet = $spreadsheet->createSheet()->setTitle('Filtros');
        $this->writeRow($filterSheet, 1, ['Filtro', 'Valor']);
        $filterRows = $functionalFilters + ['Actor' => (string) $actor->email,
            'ID actor' => (string) $actor->id, 'Fecha/hora de generacion' => $generatedAt->format('Y-m-d H:i:s T')];
        $index = 2;
        foreach ($filterRows as $key => $value) {
            $this->writeRow($filterSheet, $index++, [$key, $value]);
        }
        $this->styleSheet($filterSheet, 'B', [32, 60]);
        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        return response()->streamDownload(function () use ($writer, $spreadsheet, $functionalFilters, $actor, $rows, $generatedAt): void {
            $stream = null;
            try {
                // Keep the completed workbook private until its audit is persisted.
                $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
                if ($stream === false) {
                    throw new \RuntimeException('No se pudo preparar la descarga del control.');
                }
                $writer->save($stream);
                if (! rewind($stream)) {
                    throw new \RuntimeException('No se pudo preparar la descarga del control.');
                }
                // Audit only after the writer succeeds and before delivering any workbook bytes.
                PersonalEmoExportAudit::create([
                    'usuario_id' => $actor->id, 'filtros_json' => $functionalFilters,
                    'filtros_hash' => hash('sha256', json_encode($functionalFilters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                    'cantidad_filas' => $rows->count(), 'exportado_at' => $generatedAt->setTimezone(config('app.timezone', 'UTC')),
                ]);
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $spreadsheet->disconnectWorksheets();
            }
        }, 'control_asistencia_cursos_emo_'.$generatedAt->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function rowValues(ScheduledControlRow $row): array
    {
        return [$row->scheduledDate->toDateString(), $row->sourceType, $row->clinicName,
            $row->workerName, $row->workerDocument, $row->positionName, $row->examName,
            implode('; ', $row->mineNames), implode('; ', $row->attemptLabels),
            $row->attendanceState, $row->emoOutcome?->value, $row->resultDetail,
            $row->performedAt?->toDateString(), $row->operationalState];
    }

    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $coordinate = Coordinate::stringFromColumnIndex($index + 1).$row;
            $text = (string) ($value ?? '');
            $quotePrefix = preg_match('/^\s*[=+\-@]/u', $text) === 1;
            // Quote prefix is Excel metadata, so dangerous text stays visibly unchanged.
            // Explicit strings also protect documents, leading zeroes and all other text.
            $sheet->setCellValueExplicit($coordinate, $text, DataType::TYPE_STRING);
            if ($quotePrefix) {
                // setCellValueExplicit resets quotePrefix; apply the neutralizer afterward.
                $sheet->getStyle($coordinate)->setQuotePrefix(true);
            }
        }
    }

    private function styleSheet(Worksheet $sheet, string $lastColumn, array $widths): void
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF245B73');
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:'.$lastColumn.$sheet->getHighestDataRow())->getAlignment()->setWrapText(true)->setVertical('top');
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
    }
}
