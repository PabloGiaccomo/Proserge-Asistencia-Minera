<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class PersonalFichaExportService
{
    public const MAX_PDF_RECORDS = 60;
    public const PDF_CHUNK_SIZE = 10;
    public const MAX_EXCEL_RECORDS = 3000;

    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalFichaPdfService $pdfService,
        private readonly PersonalFichaService $fichaService,
    ) {
    }

    public function availableColumns(): array
    {
        $columns = [
            'estado_ficha' => 'Estado de ficha',
            'trabajador' => 'Trabajador',
            'tipo_documento' => 'Tipo de documento',
            'numero_documento' => 'Numero de documento',
            'fecha_envio' => 'Fecha de envio',
            'fecha_aprobacion' => 'Fecha de aprobacion',
        ];

        foreach (PersonalFichaCatalog::fields() as $key => $field) {
            $columns[$key] = (string) ($field['label'] ?? $key);
        }

        return $columns;
    }

    public function recommendedColumns(): array
    {
        $recommended = [
            'estado_ficha',
            'trabajador',
            'tipo_documento',
            'numero_documento',
        ];

        foreach (PersonalFichaCatalog::requiredKeys() as $key) {
            $recommended[] = $key;
        }

        return array_values(array_unique(array_filter($recommended, fn (string $key): bool => array_key_exists($key, $this->availableColumns()))));
    }

    public function preview(array $input): array
    {
        $limit = $this->sanitizeExcelLimit($input['ficha_limit'] ?? null);
        $totalRecords = $this->buildFichaQuery($input)->count();
        $records = $limit === null ? $totalRecords : min($totalRecords, $limit);
        $selectedColumns = $this->selectedColumns($input['ficha_columns'] ?? null);

        return [
            'records' => $records,
            'columnsCount' => count($selectedColumns),
            'maxPdfRecords' => self::MAX_PDF_RECORDS,
            'chunkSize' => self::PDF_CHUNK_SIZE,
        ];
    }

    public function downloadExcel(array $input, ?string $filename = null): StreamedResponse
    {
        $columns = $this->selectedColumns($input['ficha_columns'] ?? null);
        $limit = $this->sanitizeExcelLimit($input['ficha_limit'] ?? null);
        $records = $this->buildFichaQuery($input)
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        $sheetData = $this->buildRows($records, $columns);
        $spreadsheet = $this->buildSpreadsheet($sheetData);
        $writer = new Xlsx($spreadsheet);
        $downloadName = $filename ?: 'fichas_personal_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function startPdfJob(array $input): array
    {
        $requestedLimit = $this->sanitizePdfLimit($input['ficha_limit'] ?? null);
        $ids = $this->buildFichaQuery($input)
            ->limit($requestedLimit)
            ->pluck('id')
            ->values()
            ->all();

        if (count($ids) === 0) {
            abort(422, 'No hay fichas disponibles para exportar con los filtros actuales.');
        }

        $jobId = (string) Str::uuid();
        $jobDir = $this->jobDir($jobId);
        Storage::disk('local')->makeDirectory($jobDir);

        $meta = [
            'job_id' => $jobId,
            'status' => 'pending',
            'total' => count($ids),
            'processed' => 0,
            'chunk_size' => self::PDF_CHUNK_SIZE,
            'ids' => $ids,
            'zip_path' => $jobDir . '/fichas.zip',
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveJobMeta($jobId, $meta);

        return $this->jobProgressPayload($meta);
    }

    public function processPdfJob(string $jobId): array
    {
        $meta = $this->loadJobMeta($jobId);
        $total = (int) ($meta['total'] ?? 0);
        $processed = (int) ($meta['processed'] ?? 0);
        $ids = array_values($meta['ids'] ?? []);

        if ($processed >= $total) {
            $meta['status'] = 'completed';
            $this->saveJobMeta($jobId, $meta);

            return $this->jobProgressPayload($meta);
        }

        $chunkIds = array_slice($ids, $processed, (int) ($meta['chunk_size'] ?? self::PDF_CHUNK_SIZE));
        $fichas = PersonalFicha::query()
            ->with(['personal', 'familiares'])
            ->whereIn('id', $chunkIds)
            ->get()
            ->sortBy(fn (PersonalFicha $ficha) => array_search($ficha->id, $chunkIds, true))
            ->values();

        $zipFullPath = Storage::disk('local')->path((string) $meta['zip_path']);
        $zip = new ZipArchive();
        $result = $zip->open($zipFullPath, ZipArchive::CREATE);

        if ($result !== true) {
            abort(500, 'No se pudo preparar el archivo ZIP de fichas.');
        }

        foreach ($fichas as $index => $ficha) {
            $position = $processed + $index + 1;
            $filename = str_pad((string) $position, 3, '0', STR_PAD_LEFT) . '_' . $this->pdfService->filename($ficha);
            $zip->addFromString($filename, $this->pdfService->output($ficha));
        }

        $zip->close();

        $meta['processed'] = min($processed + count($chunkIds), $total);
        $meta['status'] = $meta['processed'] >= $total ? 'completed' : 'processing';
        $this->saveJobMeta($jobId, $meta);

        return $this->jobProgressPayload($meta);
    }

    public function zipDownloadPath(string $jobId): string
    {
        $meta = $this->loadJobMeta($jobId);

        if (($meta['status'] ?? '') !== 'completed') {
            abort(409, 'La exportacion aun no termina.');
        }

        return Storage::disk('local')->path((string) $meta['zip_path']);
    }

    private function selectedColumns(mixed $columns): array
    {
        $available = $this->availableColumns();
        $requested = is_array($columns) ? $columns : $this->recommendedColumns();
        $selected = collect($requested)
            ->map(fn ($value) => (string) $value)
            ->filter(fn (string $value): bool => array_key_exists($value, $available))
            ->values()
            ->all();

        return count($selected) > 0 ? $selected : $this->recommendedColumns();
    }

    private function sanitizeExcelLimit(mixed $value): ?int
    {
        $limit = is_numeric($value) ? (int) $value : null;

        if ($limit === null || $limit <= 0) {
            return null;
        }

        return min($limit, self::MAX_EXCEL_RECORDS);
    }

    private function sanitizePdfLimit(mixed $value): int
    {
        $limit = is_numeric($value) ? (int) $value : self::MAX_PDF_RECORDS;

        if ($limit <= 0) {
            $limit = self::MAX_PDF_RECORDS;
        }

        return min($limit, self::MAX_PDF_RECORDS);
    }

    private function buildFichaQuery(array $input)
    {
        $personalIds = $this->resolveFilteredPersonalIds($input);

        return PersonalFicha::query()
            ->with(['personal'])
            ->whereIn('personal_id', $personalIds)
            ->orderBy('numero_documento')
            ->orderBy('created_at');
    }

    private function resolveFilteredPersonalIds(array $input): array
    {
        $filters = $input;
        $visibleStateFilter = strtoupper(trim((string) ($filters['estado'] ?? '')));
        if (!in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true)) {
            $visibleStateFilter = match (strtolower((string) ($filters['estado'] ?? ''))) {
                'activo' => 'ACTIVO',
                'inactivo' => 'INACTIVO',
                'cesado' => 'CESADO',
                default => '',
            };
        }

        if ($visibleStateFilter !== '') {
            unset($filters['estado']);
        }

        $rows = PersonalResource::collection($this->personalService->list($filters))->resolve();

        if ($visibleStateFilter !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => strtoupper((string) ($row['estado'] ?? '')) === $visibleStateFilter));
        }

        return collect($rows)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function buildRows(Collection $records, array $columns): array
    {
        $availableColumns = $this->availableColumns();
        $headers = array_map(static fn (string $key): string => $availableColumns[$key] ?? $key, $columns);
        $rows = [$headers];

        foreach ($records as $record) {
            /** @var PersonalFicha $record */
            $data = $this->fichaService->normalizeFichaData($record->datos_json ?? []);
            $rows[] = array_map(function (string $column) use ($record, $data): string {
                return match ($column) {
                    'estado_ficha' => PersonalFichaCatalog::stateLabel($record->estado),
                    'trabajador' => (string) ($record->personal?->nombre_completo ?? $data['nombres'] ?? ''),
                    'tipo_documento' => (string) ($record->tipo_documento ?? $data['tipo_documento'] ?? ''),
                    'numero_documento' => (string) ($record->numero_documento ?? $data['numero_documento'] ?? ''),
                    'fecha_envio' => (string) (optional($record->submitted_at)->toDateString() ?? ''),
                    'fecha_aprobacion' => (string) (optional($record->approved_at)->toDateString() ?? ''),
                    default => (string) ($data[$column] ?? ''),
                };
            }, $columns);
        }

        return $rows;
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

    private function jobDir(string $jobId): string
    {
        return 'personal_exports/' . $jobId;
    }

    private function jobMetaPath(string $jobId): string
    {
        return $this->jobDir($jobId) . '/meta.json';
    }

    private function saveJobMeta(string $jobId, array $meta): void
    {
        Storage::disk('local')->put($this->jobMetaPath($jobId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function loadJobMeta(string $jobId): array
    {
        $path = $this->jobMetaPath($jobId);

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'No se encontro la exportacion solicitada.');
        }

        $meta = json_decode((string) Storage::disk('local')->get($path), true);

        if (!is_array($meta)) {
            abort(500, 'La exportacion solicitada esta danada.');
        }

        return $meta;
    }

    private function jobProgressPayload(array $meta): array
    {
        $total = max((int) ($meta['total'] ?? 0), 1);
        $processed = min((int) ($meta['processed'] ?? 0), $total);
        $status = (string) ($meta['status'] ?? 'pending');

        return [
            'job_id' => (string) ($meta['job_id'] ?? ''),
            'status' => $status,
            'total' => (int) ($meta['total'] ?? 0),
            'processed' => $processed,
            'percent' => (int) floor(($processed / $total) * 100),
            'chunk_size' => (int) ($meta['chunk_size'] ?? self::PDF_CHUNK_SIZE),
            'download_url' => $status === 'completed'
                ? route('personal.fichas.export.pdf.download', (string) ($meta['job_id'] ?? ''))
                : null,
        ];
    }
}
