<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Modules\Personal\Support\PersonalExportConfig;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
        'sexo' => 'Sexo',
        'estado_civil' => 'Estado civil',
        'nacionalidad' => 'Nacionalidad',
        'grupo_sanguineo' => 'G. sanguineo',
        'brevete' => 'Brevete',
        'fecha_nacimiento' => 'Fecha nacimiento',
        'pais_nacimiento' => 'Pais nacimiento',
        'departamento_nacimiento' => 'Departamento nacimiento',
        'provincia_nacimiento' => 'Provincia nacimiento',
        'distrito_nacimiento' => 'Distrito nacimiento',
        'lugar_nacimiento_extranjero' => 'Lugar nacimiento extranjero',
        'telefono_alterno' => 'Telefono alterno',
        'domicilio_pais' => 'Pais domicilio',
        'domicilio_departamento' => 'Departamento domicilio',
        'domicilio_provincia' => 'Provincia domicilio',
        'domicilio_distrito' => 'Distrito domicilio',
        'domicilio_direccion' => 'Direccion domicilio',
        'domicilio_referencia' => 'Referencia domicilio',
        'domicilio_extranjero' => 'Domicilio extranjero',
        'banco' => 'Banco',
        'numero_cuenta' => 'Numero de cuenta',
        'cci' => 'CCI',
        'grado_instruccion' => 'Grado de instruccion',
        'profesion_oficio' => 'Profesion u oficio',
        'especialidad' => 'Especialidad',
        'anio_experiencia' => 'Anos de experiencia',
        'anio_egreso' => 'Anio de egreso',
        'carrera' => 'Carrera',
        'institucion' => 'Institucion',
        'sistema_pensionario' => 'Sistema pensionario',
        'tipo_afp' => 'AFP',
        'cuspp' => 'CUSPP',
        'talla_zapato' => 'Talla zapato/botas',
        'talla_polo' => 'Talla camisa/chaleco',
        'talla_pantalon' => 'Talla pantalon',
        'talla_respirador' => 'Talla respirador',
        'familiares_resumen' => 'Familiares o contactos',
        'contactos_emergencia' => 'Contactos de emergencia',
    ];

    /** @var array<string, string> */
    private const FICHA_JSON_COLUMNS = [
        'sexo' => 'sexo',
        'estado_civil' => 'estado_civil',
        'nacionalidad' => 'nacionalidad',
        'grupo_sanguineo' => 'grupo_sanguineo',
        'brevete' => 'brevete',
        'fecha_nacimiento' => 'fecha_nacimiento',
        'pais_nacimiento' => 'pais_nacimiento',
        'departamento_nacimiento' => 'departamento_nacimiento',
        'provincia_nacimiento' => 'provincia_nacimiento',
        'distrito_nacimiento' => 'distrito_nacimiento',
        'lugar_nacimiento_extranjero' => 'lugar_nacimiento_extranjero',
        'telefono_alterno' => 'telefono_alterno',
        'domicilio_pais' => 'domicilio_tipo',
        'domicilio_departamento' => 'domicilio_departamento',
        'domicilio_provincia' => 'domicilio_provincia',
        'domicilio_distrito' => 'domicilio_distrito',
        'domicilio_direccion' => 'domicilio_direccion',
        'domicilio_referencia' => 'domicilio_referencia',
        'domicilio_extranjero' => 'domicilio_extranjero',
        'banco' => 'banco',
        'numero_cuenta' => 'numero_cuenta',
        'cci' => 'cci',
        'grado_instruccion' => 'grado_instruccion',
        'profesion_oficio' => 'profesion_oficio',
        'especialidad' => 'especialidad',
        'anio_experiencia' => 'anio_experiencia',
        'anio_egreso' => 'anio_egreso',
        'carrera' => 'carrera',
        'institucion' => 'institucion',
        'sistema_pensionario' => 'sistema_pensionario',
        'tipo_afp' => 'tipo_afp',
        'cuspp' => 'cuspp',
        'talla_zapato' => 'talla_zapato',
        'talla_polo' => 'talla_polo',
        'talla_pantalon' => 'talla_pantalon',
        'talla_respirador' => 'talla_respirador',
    ];

    /** @var array<string, array{fill:string,font:string}> */
    private const MINE_CELL_STYLES = [
        'mine-ok' => ['fill' => 'FFDDFCE7', 'font' => 'FF166534'],
        'mine-warn' => ['fill' => 'FFFEF3C7', 'font' => 'FF92400E'],
        'mine-neutral' => ['fill' => 'FFE5E7EB', 'font' => 'FF374151'],
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
        $records = $this->recordsForConfig($config);

        $table = $this->buildTable($records, $config->columns);
        $spreadsheet = $this->buildSpreadsheet($table['headers'], $table['rows'], $table['cell_styles']);

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

    public function previewTable(PersonalExportConfig $config, int $limit = 20): array
    {
        if (count($config->personalIds) === 0 || count($config->columns) === 0) {
            return [
                'headers' => array_map(
                    fn (string $key): string => $this->availableColumns()[$key] ?? $key,
                    $config->columns
                ),
                'rows' => [],
                'row_ids' => [],
                'cell_styles' => [],
                'records' => 0,
                'has_more' => false,
            ];
        }

        $records = $this->recordsForConfig($config, $limit + 1);
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);
        $rowIds = $records
            ->map(fn (Personal $personal): string => (string) $personal->id)
            ->values()
            ->all();
        $table = $this->buildTable($records, $config->columns);

        return [
            'headers' => $table['headers'],
            'rows' => $table['rows'],
            'row_ids' => $rowIds,
            'cell_styles' => $table['cell_styles'],
            'records' => count($config->personalIds),
            'has_more' => $hasMore,
        ];
    }

    public function searchWorkers(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->personalService
            ->buildFilteredQuery([
                'search' => $query,
                'with_minas' => true,
                'limit' => max(1, min(20, $limit)),
            ])
            ->with(['minas'])
            ->limit(max(1, min(20, $limit)))
            ->get()
            ->map(fn (Personal $personal): array => $this->workerSummary($personal))
            ->values()
            ->all();
    }

    public function workersByIds(array $personalIds): array
    {
        $ids = collect($personalIds)
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($ids) === 0) {
            return [];
        }

        return $this->sortRecordsBySelectedIds(
            Personal::query()
                ->whereIn('id', $ids)
                ->with(['minas'])
                ->get(),
            $ids
        )
            ->map(fn (Personal $personal): array => $this->workerSummary($personal))
            ->values()
            ->all();
    }

    private function buildQueryFromConfig(PersonalExportConfig $config): Builder
    {
        $query = $this->personalService->buildFilteredQuery($config->toFilters())->reorder();

        if (count($config->personalIds) > 0) {
            $query->whereIn('personal.id', $config->personalIds);
        }

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

    private function buildTable(Collection $records, array $columns): array
    {
        $columnDefinitions = $this->columnDefinitionsFor($records, $columns);
        $headers = array_map(static fn (array $definition): string => $definition['label'], $columnDefinitions);
        $rows = [];
        $cellStyles = [];

        foreach ($records as $record) {
            /** @var Personal $record */
            $phoneData = PersonalNormalizer::normalizePhonePayload($record->telefono_1 ?? $record->telefono ?? null);
            $telefono1 = $record->telefono_1 ?? $phoneData['telefono_1'];
            $telefono2 = $record->telefono_2 ?? $phoneData['telefono_2'];
            $row = [];
            $styleRow = [];

            foreach ($columnDefinitions as $definition) {
                if (($definition['type'] ?? '') === 'mine') {
                    $cell = $this->mineCellFor($record, (string) $definition['mine_id']);
                    $row[] = $cell['value'];
                    $styleRow[] = $cell['style'];
                    continue;
                }

                $columnKey = (string) ($definition['key'] ?? '');
                $row[] = $this->valueForColumn($record, $columnKey, $telefono1, $telefono2);
                $styleRow[] = '';
            }

            $rows[] = $row;
            $cellStyles[] = $styleRow;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'cell_styles' => $cellStyles,
        ];
    }

    private function columnDefinitionsFor(Collection $records, array $columns): array
    {
        $availableColumns = $this->availableColumns();
        $includeMineMatrix = count(array_intersect($columns, ['minas', 'estado_mina'])) > 0;
        $mineColumns = $includeMineMatrix ? $this->mineColumnsForRecords($records) : [];
        $mineMatrixAdded = false;
        $definitions = [];

        foreach ($columns as $columnKey) {
            if (in_array($columnKey, ['minas', 'estado_mina'], true)) {
                if (!$mineMatrixAdded) {
                    foreach ($mineColumns as $mineColumn) {
                        $definitions[] = $mineColumn;
                    }
                    $mineMatrixAdded = true;
                }

                continue;
            }

            $definitions[] = [
                'type' => 'field',
                'key' => $columnKey,
                'label' => $availableColumns[$columnKey] ?? $columnKey,
            ];
        }

        return $definitions;
    }

    private function mineColumnsForRecords(Collection $records): array
    {
        return $records
            ->flatMap(fn (Personal $personal): Collection => $personal->minas)
            ->filter(fn ($mine): bool => filled($mine?->id) && filled($mine?->nombre))
            ->unique(fn ($mine): string => (string) $mine->id)
            ->sortBy(fn ($mine): string => mb_strtoupper((string) $mine->nombre))
            ->map(fn ($mine): array => [
                'type' => 'mine',
                'key' => 'mine:' . $mine->id,
                'mine_id' => (string) $mine->id,
                'label' => (string) $mine->nombre,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{value:string, style:string}
     */
    private function mineCellFor(Personal $personal, string $mineId): array
    {
        $mine = $personal->minas->firstWhere('id', $mineId);
        if (!$mine) {
            return ['value' => '-', 'style' => ''];
        }

        $status = PersonalNormalizer::mineStatusLabel(
            $mine->pivot?->estado_habilitacion ?: $mine->pivot?->estado
        );

        return match ($status) {
            'habilitado' => ['value' => 'Habilitado', 'style' => 'mine-ok'],
            'no_habilitado' => ['value' => 'No habilitado', 'style' => 'mine-neutral'],
            default => ['value' => 'En proceso', 'style' => 'mine-warn'],
        };
    }

    private function recordsForConfig(PersonalExportConfig $config, ?int $limit = null): Collection
    {
        $query = $this->buildQueryFromConfig($config)->with([
            'minas',
            'fichaColaborador.familiares',
        ]);
        if ($limit !== null) {
            $query->limit($limit);
        } elseif ($config->limit !== null) {
            $query->limit($config->limit);
        }

        return $this->sortRecordsBySelectedIds($query->get(), $config->personalIds);
    }

    private function valueForColumn(Personal $personal, string $columnKey, mixed $telefono1, mixed $telefono2): string
    {
        if (isset(self::FICHA_JSON_COLUMNS[$columnKey])) {
            $fallback = match ($columnKey) {
                'telefono_alterno' => (string) ($telefono2 ?? ''),
                default => '',
            };

            return $this->fichaFieldValue($personal, self::FICHA_JSON_COLUMNS[$columnKey], $fallback);
        }

        return match ($columnKey) {
            'dni' => (string) $personal->dni,
            'nombre_completo' => (string) $personal->nombre_completo,
            'puesto' => (string) $personal->puesto,
            'contrato' => (string) PersonalNormalizer::contractLabel($personal->contrato),
            'ocupacion' => (string) ($personal->ocupacion ?? ''),
            'supervisor' => $personal->es_supervisor ? 'Si' : 'No',
            'fecha_ingreso' => (string) (optional($personal->fecha_ingreso)->toDateString() ?? ''),
            'estado' => strtoupper((string) $personal->estado),
            'telefono_1' => (string) ($telefono1 ?? ''),
            'telefono_2' => (string) ($telefono2 ?? ''),
            'telefono' => (string) (PersonalNormalizer::combinePhones($telefono1, $telefono2) ?? ''),
            'correo' => (string) ($personal->correo ?? ''),
            'familiares_resumen' => $this->familiaresSummary($personal),
            'contactos_emergencia' => $this->familiaresSummary($personal, true),
            default => '',
        };
    }

    private function fichaFieldValue(Personal $personal, string $fieldKey, string $fallback = ''): string
    {
        $data = is_array($personal->fichaColaborador?->datos_json)
            ? $personal->fichaColaborador->datos_json
            : [];

        $value = trim((string) ($data[$fieldKey] ?? ''));

        if ($fieldKey === 'estado_civil' && $value === 'Otro') {
            return $this->appendDetail($value, $data['estado_civil_otro'] ?? '');
        }

        if ($fieldKey === 'nacionalidad' && $value === 'Otra') {
            return $this->appendDetail($value, $data['nacionalidad_otra'] ?? '');
        }

        if ($fieldKey === 'pais_nacimiento' && $value === 'Otro') {
            return $this->appendDetail($value, $data['pais_nacimiento_otro'] ?? '');
        }

        if ($fieldKey === 'domicilio_tipo' && $value === 'Extranjero') {
            return $this->appendDetail($value, $data['domicilio_pais_otro'] ?? '');
        }

        if ($fieldKey === 'banco' && $value === 'Otro') {
            return $this->appendDetail($value, $data['banco_otro'] ?? '');
        }

        return $value !== '' ? $value : $fallback;
    }

    private function appendDetail(string $value, mixed $detail): string
    {
        $detail = trim((string) $detail);

        return $detail !== '' ? $value . ': ' . $detail : $value;
    }

    private function familiaresSummary(Personal $personal, bool $onlyEmergency = false): string
    {
        $familiares = $personal->fichaColaborador?->familiares;
        if (!$familiares instanceof Collection || $familiares->isEmpty()) {
            return '';
        }

        return $familiares
            ->filter(fn ($familiar): bool => !$onlyEmergency || (bool) $familiar->contacto_emergencia)
            ->map(function ($familiar): string {
                $parts = collect([
                    trim((string) $familiar->parentesco),
                    trim((string) $familiar->nombres_apellidos),
                    trim((string) $familiar->numero_documento) !== '' ? 'Doc. ' . trim((string) $familiar->numero_documento) : '',
                    trim((string) $familiar->telefono) !== '' ? 'Tel. ' . trim((string) $familiar->telefono) : '',
                    $familiar->fecha_nacimiento ? 'Nac. ' . $familiar->fecha_nacimiento->toDateString() : '',
                    $familiar->vive_con_trabajador ? 'Vive con trabajador' : '',
                    $familiar->estudia ? 'Estudia' : '',
                    $familiar->contacto_emergencia ? 'Contacto emergencia' : '',
                ])->filter()->values();

                return $parts->implode(' - ');
            })
            ->filter()
            ->values()
            ->implode(' | ');
    }

    private function sortRecordsBySelectedIds(Collection $records, array $personalIds): Collection
    {
        if (count($personalIds) === 0) {
            return $records;
        }

        $order = array_flip(array_values($personalIds));

        return $records
            ->sortBy(fn (Personal $personal): int => $order[(string) $personal->id] ?? PHP_INT_MAX)
            ->values();
    }

    private function workerSummary(Personal $personal): array
    {
        $mineNames = [];
        $mineStates = [];

        foreach ($personal->minas as $mine) {
            $mineNames[] = $mine->nombre;
            $mineStates[] = sprintf('%s (%s)', $mine->nombre, PersonalNormalizer::mineStatusLabel($mine->pivot?->estado));
        }

        $phoneData = PersonalNormalizer::normalizePhonePayload($personal->telefono_1 ?? $personal->telefono ?? null);
        $telefono1 = $personal->telefono_1 ?? $phoneData['telefono_1'];
        $telefono2 = $personal->telefono_2 ?? $phoneData['telefono_2'];

        return [
            'id' => (string) $personal->id,
            'dni' => (string) $personal->dni,
            'documento' => (string) ($personal->numero_documento ?? $personal->dni),
            'nombre' => (string) $personal->nombre_completo,
            'puesto' => (string) $personal->puesto,
            'contrato' => (string) PersonalNormalizer::contractLabel($personal->contrato),
            'ocupacion' => (string) ($personal->ocupacion ?? ''),
            'supervisor' => $personal->es_supervisor ? 'Si' : 'No',
            'fecha_ingreso' => (string) (optional($personal->fecha_ingreso)->toDateString() ?? ''),
            'estado' => strtoupper((string) $personal->estado),
            'telefono_1' => (string) ($telefono1 ?? ''),
            'telefono_2' => (string) ($telefono2 ?? ''),
            'telefono' => (string) (PersonalNormalizer::combinePhones($telefono1, $telefono2) ?? ''),
            'correo' => (string) ($personal->correo ?? ''),
            'minas' => implode('; ', $mineNames),
            'estado_mina' => implode('; ', $mineStates),
        ];
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

    private function buildSpreadsheet(array $headers, array $rows, array $cellStyles = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheetRows = array_merge([$headers], $rows);
        $totalColumns = max(array_map(static fn (array $row): int => count($row), $sheetRows));

        foreach ($sheetRows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $columnLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                $cellAddress = $columnLetter . ($rowIndex + 1);
                $sheet->setCellValue($cellAddress, (string) ($value ?? ''));

                if ($rowIndex > 0) {
                    $this->applyMineCellStyle($sheet, $cellAddress, $cellStyles[$rowIndex - 1][$colIndex] ?? '');
                }
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

    private function applyMineCellStyle($sheet, string $cellAddress, string $styleKey): void
    {
        if (!isset(self::MINE_CELL_STYLES[$styleKey])) {
            return;
        }

        $style = self::MINE_CELL_STYLES[$styleKey];
        $sheet->getStyle($cellAddress)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB($style['fill']);
        $sheet->getStyle($cellAddress)->getFont()
            ->getColor()
            ->setARGB($style['font']);
        $sheet->getStyle($cellAddress)->getFont()->setBold(true);
    }
}
