<?php

namespace App\Modules\Epps\Services;

use App\Models\EppEntrega;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppKardexRowGrouper;
use App\Modules\Epps\Support\EppKardexTemplateColumns;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Official form, operational dates and evidence coverage must remain separate. */
class EppKardexExcelService
{
    public function __construct(private readonly EppScopeService $scope) {}

    public function download(Personal $worker, Usuario $actor, string $from, string $to): StreamedResponse
    {
        $book = $this->workbook($worker, $actor, $from, $to);
        $writer = new Xlsx($book);

        return response()->streamDownload(fn () => $writer->save('php://output'), 'SGC-FOR-59_'.Str::slug($worker->nombre_completo, '_').'_'.$from.'_'.$to.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }

    public function workbook(Personal $worker, Usuario $actor, string $from, string $to): Spreadsheet
    {
        $actor = Usuario::query()->findOrFail($actor->id);
        abort_unless($actor->estado === 'ACTIVO' && PermissionMatrix::userCanDirect($actor, 'epps', 'ver_evidencia') && PermissionMatrix::userCanDirect($actor, 'epps', 'descargar_constancia'), 403);
        $this->scope->assertPersonalAccess($actor, $worker);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to, 422);
        $events = app(EppKardexMovementService::class)->events($worker, $actor, $from, $to, true);
        // Administrative corrections remain in the evidence history, not this delivery form.
        $events = array_values(array_filter($events, fn ($event) => in_array($event['code'], ['N', 'C', 'I', 'P', 'D'], true)));
        $book = IOFactory::load(resource_path('epp-templates/SGC-FOR-59.xlsx'));
        $columns = [];
        $free = [];
        foreach ($book->getAllSheets() as $side => $sheet) {
            $header = $side === 0 ? 12 : 5;
            for ($col = 3; $col <= 30; $col++) {
                $label = (string) $sheet->getCell([$col, $header])->getValue();
                if (trim($label) !== '') {
                    $columns[] = ['side' => $side, 'col' => $col, 'label' => $label];
                } else {
                    $free[] = ['side' => $side, 'col' => $col];
                }
            }
        }
        $positions = [];
        foreach (collect($events)->pluck('name')->unique() as $name) {
            $match = EppKardexTemplateColumns::match($name, $columns);
            if ($match === null) {
                if (! $free) {
                    // Additional pages retain the template rather than dropping unknown items.
                    $sheet = clone $book->getSheet(1);
                    $sheet->setTitle('Adicional '.($book->getSheetCount() - 1));
                    $book->addSheet($sheet);
                    for ($col = 3; $col <= 30; $col++) {
                        $sheet->getCell([$col, 5])->setValue(null);
                        $free[] = ['side' => $book->getSheetCount() - 1, 'col' => $col];
                    }
                }
                $match = array_shift($free);
                $header = $match['side'] === 0 ? 12 : 5;
                $this->text($book->getSheet($match['side']), [$match['col'], $header], $name);
            }
            $positions[$name] = $match;
        }
        // Resolve real template columns first: aliases must not overwrite one cell.
        $rows = collect(EppKardexRowGrouper::group(array_map(fn ($event) => $event + [
            'column_key' => $positions[$event['name']]['side'].':'.$positions[$event['name']]['col'],
        ], $events)));
        foreach ($book->getAllSheets() as $side => $sheet) {
            $header = $side === 0 ? 12 : 5;
            $used = collect($positions)->where('side', $side)->pluck('col')->all();
            for ($col = 3; $col <= 30; $col++) {
                if (! in_array($col, $used, true)) {
                    $sheet->getCell([$col, $header])->setValue(null);
                }
            }
            $start = $side === 0 ? 14 : 7;
            $extra = max(0, $rows->count() - 20);
            if ($extra) {
                $sheet->insertNewRowBefore($start + 20, $extra);
                for ($row = $start + 20; $row < $start + 20 + $extra; $row++) {
                    $sheet->duplicateStyle($sheet->getStyle('A'.$start.':AE'.$start), 'A'.$row.':AE'.$row);
                }
            }
            for ($row = $start; $row < $start + max(20, $rows->count()); $row++) {
                for ($col = 1; $col <= 31; $col++) {
                    $sheet->getCell([$col, $row])->setValue(null);
                }
                $sheet->setCellValue('A'.$row, $row - $start + 1);
            }
            if ($side === 0) {
                $parts = preg_split('/\s+/', trim($worker->nombre_completo));
                $this->text($sheet, 'C5', implode(' ', array_slice($parts, 0, 2)));
                $this->text($sheet, 'U5', implode(' ', array_slice($parts, 2)));
                $this->text($sheet, 'C6', $worker->dni ?: $worker->numero_documento);
                $this->text($sheet, 'O6', $worker->puesto ?: '');
                $opening = $this->scope->scopeDeliveries(EppEntrega::query(), $actor)->where('personal_id', $worker->id)->min('fecha_entrega');
                $sheet->setCellValue('AE6', $opening ? Date::stringToExcel(substr($opening, 0, 10)) : null);
                $sheet->getStyle('AE6')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                $sheet->getStyle('AE6')->getFont()->getColor()->setARGB('FF000000');
            }
            if ($side === 0) {
                // Keep a gutter before D and exclude the wide signature column.
                $labelStyle = $sheet->getStyle('W8')->exportArray();
                $sheet->unmergeCells('W8:Z9')->mergeCells('W8:Y9');
                $sheet->getStyle('W8:Y9')->applyFromArray($labelStyle);
                $sheet->duplicateStyle($sheet->getStyle('U8:U9'), 'Z8:Z9');
                $sheet->mergeCells('AA8:AA9')->mergeCells('AB8:AD9');
                $sheet->duplicateStyle($sheet->getStyle('V8:V9'), 'AA8:AA9');
                $sheet->getStyle('AB8:AD9')->applyFromArray($labelStyle);
                $this->text($sheet, 'AA8', 'D');
                $this->text($sheet, 'AB8', 'Devolución');
            }
            $footerSignature = null;
            foreach ($rows as $index => $packed) {
                $date = $packed['date'];
                $row = $start + $index;
                $sheet->setCellValue('B'.$row, Date::stringToExcel($date));
                $sheet->getStyle('B'.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                $sheet->getStyle('B'.$row)->getFont()->getColor()->setARGB('FF000000');
                foreach ($packed['cells'] as $event) {
                    $position = $positions[$event['name']];
                    if ($position['side'] !== $side) {
                        continue;
                    }
                    $this->text($sheet, [$position['col'], $row], $event['code']);
                    $sheet->getStyle([$position['col'], $row])->getAlignment()->setWrapText(true);
                    if ($event['signature'] !== null && ($footerSignature === null || strcmp($event['signed_at'].'|'.$event['acceptance_id'], $footerSignature['signed_at'].'|'.$footerSignature['acceptance_id']) > 0)) {
                        $footerSignature = $event;
                    }
                }
                $sheet->getRowDimension($row)->setRowHeight(22);
            }
            if ($footerSignature !== null) {
                $this->drawSignature($sheet, 'AE'.(($side === 0 ? 34 : 28) + $extra), $footerSignature, 'Firma del trabajador - cierre');
            }
            $sheet->getPageSetup()->setPrintArea('A1:AE'.(($side === 0 ? 40 : 33) + $extra));
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Flatten only the exported copy; the signed private evidence remains unchanged. */
    private function drawSignature($sheet, string $coordinate, array $event, string $description): void
    {
        $source = @imagecreatefromstring($event['signature']);
        if ($source === false) {
            return;
        }
        $image = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagealphablending($image, true);
        imagecopy($image, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imagedestroy($source);
        $drawing = new MemoryDrawing;
        $drawing->setName('Firma '.$event['date'].' '.$event['acceptance_id'])->setDescription($description)->setImageResource($image)->setRenderingFunction(MemoryDrawing::RENDERING_PNG)->setMimeType(MemoryDrawing::MIMETYPE_PNG);
        $drawing->setCoordinates($coordinate)->setOffsetX(2)->setOffsetY(2)->setWidthAndHeight(110, 50)->setWorksheet($sheet);
    }

    private function text($sheet, $cell, ?string $value): void
    {
        $sheet->getCell($cell)->setValueExplicit((string) $value, DataType::TYPE_STRING);
    }
}
