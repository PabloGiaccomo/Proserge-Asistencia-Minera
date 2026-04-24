<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Modules\Personal\Support\PersonalExportConfig;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPersonalService
{
    /** @var array<string, string> */
    private const AVAILABLE_COLUMNS = [
        'dni' => 'DNI',
        'nombre_completo' => 'Apellidos y Nombres',
        'puesto' => 'Puesto/Cargo',
        'contrato' => 'Contrato',
        'ocupacion' => 'Ocupacion',
        'supervisor' => 'Supervisor',
        'fecha_ingreso' => 'Fecha Ingreso',
        'estado' => 'Estado General',
        'telefono_1' => 'Telefono 1',
        'telefono_2' => 'Telefono 2',
        'telefono' => 'Telefono (Resumen)',
        'correo' => 'Correo',
        'minas' => 'Minas Asociadas',
        'estado_mina' => 'Estados en Mina',
    ];

    public function __construct(private readonly PersonalService $personalService)
    {
    }

    public function availableColumns(): array
    {
        return self::AVAILABLE_COLUMNS;
    }

    public function download(array $filters, ?string $filename = null): StreamedResponse
    {
        $allColumnKeys = array_keys($this->availableColumns());
        $input = $filters;
        $input['scope'] = $input['scope'] ?? 'current';
        $input['columns'] = $allColumnKeys;

        $config = PersonalExportConfig::fromInput($input, $allColumnKeys);

        return $this->downloadWithConfig($config, $filename);
    }

    public function downloadWithConfig(PersonalExportConfig $config, ?string $filename = null): StreamedResponse
    {
        $query = $this->buildQueryFromConfig($config)->with(['minas']);
        if ($config->limit !== null) {
            $query->limit($config->limit);
        }

        $records = $query->get();

        $sheetData = $this->buildRows($records, $config->columns);
        $spreadsheet = $this->buildSpreadsheet($sheetData);

        $writer = new Xlsx($spreadsheet);
        $downloadName = $filename ?: 'personal_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(PersonalExportConfig $config): array
    {
        $countQuery = $this->buildQueryFromConfig($config)->reorder();
        $totalRecords = (int) $countQuery->count('personal.id');
        $recordsToExport = $config->limit === null
            ? $totalRecords
            : min($totalRecords, $config->limit);

        return [
            'records' => $recordsToExport,
            'columnsCount' => count($config->columns),
            'filtersLabel' => $this->describeFilters($config),
            'orderLabel' => $this->describeOrder($config),
        ];
    }

    private function buildQueryFromConfig(PersonalExportConfig $config): Builder
    {
        $query = $this->personalService->buildFilteredQuery($config->toFilters())->reorder();

        if ($config->scope === 'mine' && empty($config->mina)) {
            $query->whereRaw('1=0');
        }

        $orderDirection = $config->order === 'desc' ? 'desc' : 'asc';

        match ($config->sort) {
            'dni' => $query->orderBy('personal.dni', $orderDirection),
            'contrato' => $query->orderBy('personal.contrato', $orderDirection),
            'fecha_ingreso' => $query->orderBy('personal.fecha_ingreso', $orderDirection),
            'mina' => $query->orderByRaw(
                '(SELECT MIN(m.nombre) FROM personal_mina pm JOIN minas m ON m.id = pm.mina_id WHERE pm.personal_id = personal.id) ' . $orderDirection
            ),
            default => $query->orderBy('personal.nombre_completo', $orderDirection),
        };

        return $query->orderBy('personal.nombre_completo');
    }

    private function buildRows(Collection $records, array $columns): array
    {
        $availableColumns = $this->availableColumns();
        $headers = array_map(
            static fn (string $key) => $availableColumns[$key] ?? $key,
            $columns
        );

        $rows = [$headers];

        foreach ($records as $record) {
            /** @var Personal $record */
            $mineNames = [];
            $mineStates = [];

            foreach ($record->minas as $mine) {
                $mineNames[] = $mine->nombre;
                $mineStates[] = sprintf('%s (%s)', $mine->nombre, PersonalNormalizer::mineStatusLabel($mine->pivot?->estado));
            }

            $phoneData = PersonalNormalizer::normalizePhonePayload($record->telefono_1 ?? $record->telefono ?? null);
            $telefono1 = $record->telefono_1 ?? $phoneData['telefono_1'];
            $telefono2 = $record->telefono_2 ?? $phoneData['telefono_2'];

            $rows[] = array_map(function (string $columnKey) use ($record, $telefono1, $telefono2, $mineNames, $mineStates): string {
                return match ($columnKey) {
                    'dni' => (string) $record->dni,
                    'nombre_completo' => (string) $record->nombre_completo,
                    'puesto' => (string) $record->puesto,
                    'contrato' => (string) PersonalNormalizer::contractLabel($record->contrato),
                    'ocupacion' => (string) ($record->ocupacion ?? ''),
                    'supervisor' => $record->es_supervisor ? 'Si' : 'No',
                    'fecha_ingreso' => (string) (optional($record->fecha_ingreso)->toDateString() ?? ''),
                    'estado' => strtoupper((string) $record->estado),
                    'telefono_1' => (string) ($telefono1 ?? ''),
                    'telefono_2' => (string) ($telefono2 ?? ''),
                    'telefono' => (string) (PersonalNormalizer::combinePhones($telefono1, $telefono2) ?? ''),
                    'correo' => (string) ($record->correo ?? ''),
                    'minas' => implode('; ', $mineNames),
                    'estado_mina' => implode('; ', $mineStates),
                    default => '',
                };
            }, $columns);
        }

        return $rows;
    }

    private function describeFilters(PersonalExportConfig $config): string
    {
        $filters = [];

        if ($config->search) {
            $filters[] = 'Busqueda';
        }
        if ($config->estado) {
            $filters[] = 'Estado';
        }
        if ($config->tipo) {
            $filters[] = 'Tipo';
        }
        if ($config->mina) {
            $filters[] = 'Mina';
        }
        if ($config->minaEstado) {
            $filters[] = 'Estado en mina';
        }
        if ($config->contrato) {
            $filters[] = 'Contrato';
        }

        return count($filters) > 0 ? implode(', ', $filters) : 'Sin filtros';
    }

    private function describeOrder(PersonalExportConfig $config): string
    {
        $sortLabel = match ($config->sort) {
            'dni' => 'DNI',
            'mina' => 'Mina',
            'contrato' => 'Contrato',
            'fecha_ingreso' => 'Fecha ingreso',
            default => 'Nombre',
        };

        $orderLabel = $config->order === 'desc' ? 'Descendente' : 'Ascendente';

        return $sortLabel . ' (' . $orderLabel . ')';
    }

    private function buildSpreadsheet(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $totalColumns = max(array_map(static fn (array $row): int => count($row), $rows));

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $columnLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->setCellValue($columnLetter . ($rowIndex + 1), (string) ($value ?? ''));
            }
        }

        $sheet->freezePane('A2');

        $headerLastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:' . $headerLastColumn . '1')->getFont()->setBold(true);

        for ($columnIndex = 1; $columnIndex <= $totalColumns; $columnIndex++) {
            $columnId = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($columnId)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
