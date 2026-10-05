<?php

namespace App\Modules\Evaluaciones\Services;

use App\Modules\Personal\Support\PersonalNormalizer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EvaluacionesHistoricalWorkbookReader
{
    public function read(array $privatePaths): array
    {
        $result = ['rows' => [], 'personal_map' => [], 'attendance_headers' => [], 'issues' => []];

        foreach (['control', 'consolidado'] as $required) {
            if (! array_key_exists($required, $privatePaths)) {
                $result['issues'][] = ['source' => $required, 'message' => 'Falta uno de los dos libros principales.'];
            }
        }

        foreach ($privatePaths as $source => $path) {
            if (! in_array($source, ['control', 'consolidado'], true) || ! is_readable($path)) {
                $result['issues'][] = ['source' => $source, 'message' => 'Archivo no reconocido o ilegible.'];

                continue;
            }

            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly($source === 'control'
                ? ['PERSONAL', 'ASISTENCIA ENCABEZADO', 'ASISTENCIA DETALLE', 'EV DESEMPENO', 'EV SUPERVISORES', 'EV RESIDENTES']
                : ['Consolidado']);
            $workbook = $reader->load($path);

            try {
                if ($source === 'control') {
                    $this->readControl($workbook, $result);
                } else {
                    $this->readConsolidado($workbook, $result);
                }
            } finally {
                $workbook->disconnectWorksheets();
            }
        }

        return $result;
    }

    private function readControl($workbook, array &$result): void
    {
        $personal = $this->sheet($workbook, 'PERSONAL', [0 => 'Id', 1 => 'DNI'], $result);
        if ($personal) {
            foreach ($this->dataRows($personal) as [$line, $cells]) {
                $id = trim((string) ($cells[0] ?? ''));
                if ($id !== '') {
                    $dni = trim((string) ($cells[1] ?? ''));
                    if (array_key_exists($id, $result['personal_map']) && $result['personal_map'][$id] !== $dni) {
                        $result['personal_map'][$id] = null;
                        $result['issues'][] = ['source' => 'control', 'message' => "ID interno de Personal ambiguo en fila {$line}."];
                    } else {
                        $result['personal_map'][$id] = $dni;
                    }
                }
            }
        }

        $headers = $this->sheet($workbook, 'ASISTENCIA ENCABEZADO', [0 => 'Id Asistencia', 1 => 'Fecha'], $result);
        if ($headers) {
            foreach ($this->dataRows($headers) as [$line, $cells]) {
                $id = trim((string) ($cells[0] ?? ''));
                if ($id !== '') {
                    $result['attendance_headers'][$id] = ['date' => $cells[1] ?? null, 'mine' => $cells[3] ?? null];
                }
            }
        }

        $detail = $this->sheet($workbook, 'ASISTENCIA DETALLE', [0 => 'Id Detalle', 2 => 'Id Asistencia', 3 => 'DNI Empleado'], $result);
        if ($detail) {
            foreach ($this->dataRows($detail) as [$line, $cells]) {
                $result['rows'][] = [
                    'type' => 'attendance', 'source_file' => 'control', 'source_sheet' => 'ASISTENCIA DETALLE',
                    'source_row' => $line, 'source_id' => $cells[0] ?? null, 'date' => $cells[1] ?? null,
                    'header_id' => $cells[2] ?? null, 'dni_ref' => $cells[3] ?? null,
                ];
            }
        }

        $daily = $this->sheet($workbook, 'EV DESEMPENO', [1 => 'Fecha', 13 => 'DNI Empleado'], $result);
        if ($daily) {
            foreach ($this->dataRows($daily) as [$line, $cells]) {
                $result['rows'][] = [
                    'type' => 'daily', 'source_file' => 'control', 'source_sheet' => 'EV DESEMPENO',
                    'source_row' => $line, 'source_id' => $cells[0] ?? null, 'date' => $cells[1] ?? null,
                    'hora' => $cells[2] ?? null, 'mine' => $cells[3] ?? null, 'scores' => array_slice($cells, 5, 5),
                    'source_total' => $cells[10] ?? null, 'observaciones' => $cells[11] ?? null,
                    'evaluator_ref' => $cells[12] ?? null, 'dni_ref' => $cells[13] ?? null,
                    'tuvo_incidencia' => $cells[14] ?? null, 'descripcion_incidencia' => $cells[15] ?? null,
                    'detail_ref' => $cells[16] ?? null,
                ];
            }
        }

        foreach (['EV SUPERVISORES' => 'supervisor', 'EV RESIDENTES' => 'resident'] as $sheetName => $type) {
            $sheet = $this->sheet($workbook, $sheetName,
                $type === 'supervisor' ? [0 => 'Id EvSup', 3 => 'Fecha'] : [0 => 'Id EvRes', 1 => 'Fecha'], $result);
            if (! $sheet) {
                continue;
            }
            foreach ($this->dataRows($sheet) as [$line, $cells]) {
                $result['rows'][] = [
                    'type' => $type, 'source_file' => 'control', 'source_sheet' => $sheetName,
                    'source_row' => $line, 'source_id' => $cells[0] ?? null,
                    'date' => $cells[$type === 'supervisor' ? 3 : 1] ?? null,
                    'dni_ref' => $cells[$type === 'supervisor' ? 2 : 7] ?? null,
                    'evaluator_ref' => $cells[$type === 'supervisor' ? 1 : 8] ?? null,
                    'values' => $cells,
                ];
            }
        }
    }

    private function readConsolidado($workbook, array &$result): void
    {
        $sheet = $this->sheet($workbook, 'Consolidado', [0 => 'DNI', 11 => 'FECHA DE EVALUACION', 12 => 'UNIDAD MINERA'], $result);
        if (! $sheet) {
            return;
        }

        foreach ($this->dataRows($sheet) as [$line, $cells]) {
            $result['rows'][] = [
                'type' => 'daily', 'source_file' => 'consolidado', 'source_sheet' => 'Consolidado',
                'source_row' => $line, 'source_id' => null, 'dni_ref' => $cells[0] ?? null,
                'name' => $cells[1] ?? null, 'evaluator_name' => $cells[3] ?? null,
                'scores' => array_slice($cells, 4, 5), 'source_total' => $cells[9] ?? null,
                'observaciones' => $cells[10] ?? null, 'date' => $cells[11] ?? null,
                'mine' => $cells[12] ?? null,
            ];
        }
    }

    private function sheet($workbook, string $name, array $anchors, array &$result): ?Worksheet
    {
        $sheet = $workbook->getSheetByName($name);
        $source = $name === 'Consolidado' ? 'consolidado' : 'control';
        if (! $sheet) {
            $result['issues'][] = ['source' => $source, 'message' => "Falta la hoja {$name}."];

            return null;
        }

        foreach ($anchors as $index => $label) {
            $actual = $sheet->getCell([$index + 1, 1])->getValue();
            if (PersonalNormalizer::normalizeKey((string) $actual) !== PersonalNormalizer::normalizeKey($label)) {
                $result['issues'][] = ['source' => $source, 'message' => "Columnas inesperadas en {$name}."];

                return null;
            }
        }

        return $sheet;
    }

    private function dataRows(Worksheet $sheet): iterable
    {
        $lastColumn = $sheet->getHighestDataColumn();
        for ($line = 2, $end = $sheet->getHighestDataRow(); $line <= $end; $line++) {
            $cells = $sheet->rangeToArray("A{$line}:{$lastColumn}{$line}", null, false, false)[0];
            if (collect($cells)->contains(fn ($cell) => $cell !== null && trim((string) $cell) !== '')) {
                yield [$line, $cells];
            }
        }
    }
}
