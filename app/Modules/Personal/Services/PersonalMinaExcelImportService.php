<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\PersonalMinaExamen;
use App\Models\PersonalMinaExamenIntento;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

class PersonalMinaExcelImportService
{
    public function __construct(private readonly PersonalMinaHabilitacionService $habilitation)
    {
    }

    public function preview(UploadedFile $file): array
    {
        $this->extendRuntimeLimit(600);

        $rows = [];
        $unmapped = [];
        $errors = [];
        $omitted = [];
        $examCatalog = [];

        foreach ($this->loadWorksheetsForPreview($file, $omitted) as $sheet) {
            if ($sheet->getSheetState() !== 'visible') {
                $omitted[] = [
                    'hoja' => $sheet->getTitle(),
                    'motivo' => 'Hoja oculta o auxiliar. No participa en la importacion.',
                ];
                continue;
            }

            if ($this->isAuxiliarySheetName($sheet->getTitle())) {
                $omitted[] = [
                    'hoja' => $sheet->getTitle(),
                    'motivo' => 'Nombre de hoja auxiliar o de respaldo.',
                ];
                continue;
            }

            $grid = $this->sheetGrid($sheet);
            $headerRow = $this->detectHeaderRow($grid);
            if ($headerRow === null) {
                $omitted[] = [
                    'hoja' => $sheet->getTitle(),
                    'motivo' => 'No se detectaron columnas de trabajador/documento.',
                ];
                continue;
            }

            $columns = $this->detectColumns($grid, $headerRow);
            if (!$columns['documento']) {
                $omitted[] = [
                    'hoja' => $sheet->getTitle(),
                    'motivo' => 'No se detecto columna de DNI/documento.',
                ];
                continue;
            }
            if (empty($columns['examenes'])) {
                $omitted[] = [
                    'hoja' => $sheet->getTitle(),
                    'motivo' => 'Hoja resumen o sin examenes mineros detectados.',
                ];
                continue;
            }

            $mineName = $this->detectMineName($sheet->getTitle(), $columns);
            if ($mineName === '') {
                $omitted[] = [
                    'hoja' => $sheet->getTitle(),
                    'motivo' => 'No se pudo identificar una unidad minera valida.',
                ];
                continue;
            }
            $examCatalog[] = [
                'hoja' => $sheet->getTitle(),
                'mina' => $mineName,
                'examenes' => collect(array_keys($columns['examenes']))
                    ->filter()
                    ->map(fn ($name) => ['nombre' => (string) $name])
                    ->values()
                    ->all(),
            ];

            for ($rowIndex = $headerRow + 1; $rowIndex <= count($grid); $rowIndex++) {
                $row = $grid[$rowIndex] ?? [];
                if ($this->rowIsBlank($row)) {
                    continue;
                }

                $rawDocument = (string) ($row[$columns['documento']] ?? '');
                $document = $this->cleanDocument($rawDocument);
                if ($document === '') {
                    $errors[] = [
                        'hoja' => $sheet->getTitle(),
                        'fila' => $rowIndex,
                        'motivo' => 'Fila sin DNI/documento.',
                    ];
                    continue;
                }

                $examData = [];
                foreach ($columns['examenes'] as $examName => $fields) {
                    $values = $this->examValuesFromRow($row, $fields);
                    if (!$this->hasUsefulExamValues($values)) {
                        continue;
                    }

                    $examData[] = [
                        'nombre' => $examName,
                        'existe' => false,
                        'datos' => $values,
                        'preview' => $this->previewMetadataForExamValues($values),
                    ];
                }

                foreach ($columns['no_mapeadas'] as $column => $header) {
                    $value = trim((string) ($row[$column] ?? ''));
                    if ($value !== '') {
                        $unmapped[] = [
                            'hoja' => $sheet->getTitle(),
                            'fila' => $rowIndex,
                            'columna' => $header,
                            'valor' => mb_substr($value, 0, 120),
                        ];
                    }
                }

                $rows[] = [
                    'hoja' => $sheet->getTitle(),
                    'fila' => $rowIndex,
                    'mina' => $mineName,
                    'mina_existe' => false,
                    'documento' => $document,
                    'documento_original' => $this->rawDocumentDigits($rawDocument),
                    'documento_corregido_con_cero' => strlen($this->rawDocumentDigits($rawDocument)) === 7 && strlen($document) === 8,
                    'trabajador_existe' => false,
                    'nombre' => PersonalNormalizer::text($row[$columns['nombre']] ?? '') ?: null,
                    'cargo' => PersonalNormalizer::text($row[$columns['cargo']] ?? '') ?: null,
                    'ocupacion' => PersonalNormalizer::text($row[$columns['ocupacion']] ?? '') ?: null,
                    'area' => PersonalNormalizer::text($row[$columns['area']] ?? '') ?: null,
                    'telefono' => PersonalNormalizer::text($row[$columns['telefono']] ?? '') ?: null,
                    'estado_laboral' => PersonalNormalizer::text($row[$columns['estado_laboral']] ?? '') ?: null,
                    'estado_habilitacion' => PersonalNormalizer::text($row[$columns['estado_habilitacion']] ?? '') ?: null,
                    'examenes' => $examData,
                ];
            }
        }

        $index = $this->previewDatabaseIndex($rows, $examCatalog);
        $rows = $this->decoratePreviewRows($rows, $index);
        $examCatalog = $this->decorateExamCatalog($examCatalog, $index);

        $preview = $this->buildPreviewSummary($rows, $errors, $omitted, $unmapped, $index, $examCatalog);
        $preview['catalog_fingerprint'] = $this->catalogFingerprint();

        return $preview;
    }

    public function confirm(array $preview, Usuario $user): array
    {
        $this->extendRuntimeLimit(900);

        $counts = [
            'trabajadores_creados' => 0,
            'trabajadores_actualizados' => 0,
            'trabajadores_no_encontrados' => 0,
            'minas_creadas' => 0,
            'examenes_creados' => 0,
            'examenes_renombrados' => 0,
            'examenes_reactivados' => 0,
            'examenes_catalogo_desactivados' => 0,
            'examenes_convalidables_activados' => 0,
            'examenes_convalidables_desactivados' => 0,
            'examenes_mina_agregados' => 0,
            'examenes_mina_actualizados' => 0,
            'examenes_mina_reactivados' => 0,
            'examenes_mina_desactivados' => 0,
            'minas_catalogo_reconciliado' => 0,
            'asignaciones_creadas' => 0,
            'examenes_trabajador_actualizados' => 0,
            'intentos_importados' => 0,
            'intentos_omitidos_duplicados' => 0,
            'estados_habilitacion_actualizados' => 0,
            'precios_detectados_omitidos' => 0,
            'convalidaciones_sugeridas' => 0,
            'filas_omitidas' => 0,
        ];

        DB::transaction(function () use ($preview, $user, &$counts): void {
            if (!isset($preview['catalog_fingerprint'])
                || !hash_equals($preview['catalog_fingerprint'], $this->catalogFingerprint())) {
                throw ValidationException::withMessages([
                    'archivo' => 'El catálogo de minas, exámenes o requisitos cambió desde la revisión. Analiza nuevamente el Master antes de confirmar.',
                ]);
            }

            $workerCache = [];
            $mineCache = [];
            $examCache = [];
            $requirementCache = [];
            $assignmentCache = [];
            $processedWorkerIds = [];
            $missingDocuments = [];
            $changedMineIds = [];
            $examCatalog = $preview['catalogo_examenes'] ?? $this->examCatalogFromRows($preview['rows'] ?? []);
            $catalogByMine = $this->catalogByMine($examCatalog);
            if ($catalogByMine === [] || collect($catalogByMine)->sum(fn (array $row) => count($row['examenes'] ?? [])) === 0) {
                throw new \RuntimeException('El Master no contiene un catalogo minero valido. No se reemplazo ningun dato.');
            }
            $sharedExamKeys = $this->sharedExamKeys($examCatalog);
            $currentExamIds = [];
            $currentRequirementIds = [];

            foreach ($catalogByMine as $catalogRow) {
                $mina = $this->resolveMineCached((string) ($catalogRow['mina'] ?? ''), $counts, $mineCache);
                if (!$mina) {
                    continue;
                }

                $requirementsChangedBefore = $counts['examenes_mina_agregados']
                    + $counts['examenes_mina_actualizados']
                    + $counts['examenes_mina_reactivados'];

                foreach ($catalogRow['examenes'] ?? [] as $examRow) {
                    $exam = $this->resolveExamCached($examRow, $counts, $user, $examCache);
                    $currentExamIds[$exam->id] = true;
                    $allowsConvalidation = isset($sharedExamKeys[$this->sharedExamIdentity($examRow)]);
                    $this->syncExamConvalidation($exam, $allowsConvalidation, $counts, $user);
                    $requirement = $this->resolveRequirementCached($mina, $exam, $counts, $requirementCache, $allowsConvalidation);
                    $currentRequirementIds[$requirement->id] = true;
                }

                $requirementsChangedAfter = $counts['examenes_mina_agregados']
                    + $counts['examenes_mina_actualizados']
                    + $counts['examenes_mina_reactivados'];
                if ($requirementsChangedAfter > $requirementsChangedBefore) {
                    $changedMineIds[$mina->id] = true;
                }
                $counts['minas_catalogo_reconciliado']++;
            }

            foreach ($this->replaceActiveCatalog(
                array_keys($currentExamIds),
                array_keys($currentRequirementIds),
                $counts,
                $user,
            ) as $mineId) {
                $changedMineIds[$mineId] = true;
            }

            foreach (array_keys($changedMineIds) as $mineId) {
                $sync = $this->habilitation->syncMineAssignmentsAfterRequirementChanges($mineId, $user, false);
                $counts['estados_habilitacion_actualizados'] += (int) ($sync['asignaciones_recalculadas'] ?? 0);
            }

            foreach ($preview['rows'] ?? [] as $row) {
                if (empty($row['documento'])) {
                    $counts['filas_omitidas']++;
                    continue;
                }

                $personal = $this->resolveWorkerCached($row, $workerCache);
                $mina = $this->resolveMineCached((string) ($row['mina'] ?? ''), $counts, $mineCache);
                if (!$mina) {
                    $counts['filas_omitidas']++;
                    continue;
                }

                $resolvedExams = [];
                foreach ($row['examenes'] ?? [] as $examRow) {
                    $exam = $this->resolveExamCached($examRow, $counts, $user, $examCache);
                    if ($this->hasImportedPrice($examRow['datos'] ?? [])) {
                        $counts['precios_detectados_omitidos']++;
                    }
                    if (!empty($examRow['preview']['convalidacion_sugerida'])) {
                        $counts['convalidaciones_sugeridas']++;
                    }
                    $allowsConvalidation = isset($sharedExamKeys[$this->sharedExamIdentity($examRow)]);
                    $this->resolveRequirementCached($mina, $exam, $counts, $requirementCache, $allowsConvalidation);
                    $resolvedExams[] = [$exam, $examRow];
                }

                if (!$personal) {
                    $document = $this->cleanDocument((string) ($row['documento'] ?? ''));
                    if ($document !== '' && !isset($missingDocuments[$document])) {
                        $counts['trabajadores_no_encontrados']++;
                        $missingDocuments[$document] = true;
                    }
                    $counts['filas_omitidas']++;
                    continue;
                }

                if (!isset($processedWorkerIds[$personal->id])) {
                    $counts['trabajadores_actualizados']++;
                    $processedWorkerIds[$personal->id] = true;
                }

                $assignment = $this->resolveAssignmentCached($personal, $mina, $user, $counts, $assignmentCache);
                if ($resolvedExams) {
                    $this->habilitation->generateRequiredExams($assignment, $user, false, false);
                }

                $workerExams = PersonalMinaExamen::query()
                    ->with('intentos')
                    ->where('personal_mina_id', $assignment->id)
                    ->get()
                    ->keyBy('examen_id');

                foreach ($resolvedExams as [$exam, $examRow]) {
                    $workerExam = $workerExams->get($exam->id);
                    if ($workerExam) {
                        $applied = $this->applyExamValues($workerExam, $examRow['datos'] ?? [], $user);
                        if ($applied === 'created') {
                            $counts['intentos_importados']++;
                        } elseif ($applied === 'duplicate') {
                            $counts['intentos_omitidos_duplicados']++;
                        }
                        $counts['examenes_trabajador_actualizados']++;
                    }
                }

                if ($this->applyImportedAssignmentState($personal, $mina, $row, $user)) {
                    $counts['estados_habilitacion_actualizados']++;
                }
                if ($this->habilitation->refreshAssignmentFromExams($assignment, $user, false)) {
                    $counts['estados_habilitacion_actualizados']++;
                }
            }

        });

        return $counts;
    }

    private function catalogFingerprint(): string
    {
        $tables = [
            'minas' => ['id', 'nombre', 'estado'],
            'examenes_mineros' => ['id', 'nombre', 'activo', 'permite_convalidacion'],
            'mina_requisitos' => ['id', 'mina_id', 'examen_id', 'nombre', 'activo', 'permite_convalidacion_mina', 'convalidar_desde_otras_minas'],
        ];
        $snapshot = [];

        foreach ($tables as $table => $columns) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get($columns)->all();
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function loadWorksheetsForPreview(UploadedFile $file, array &$omitted): \Generator
    {
        $path = $file->getRealPath();
        $sheetInfo = IOFactory::createReaderForFile($path)->listWorksheetInfo($path);

        foreach ($sheetInfo as $info) {
            $sheetName = (string) ($info['worksheetName'] ?? '');
            if (($info['sheetState'] ?? 'visible') !== 'visible') {
                $omitted[] = ['hoja' => $sheetName, 'motivo' => 'Hoja oculta o auxiliar. No participa en la importacion.'];
                continue;
            }

            if (in_array(\Illuminate\Support\Str::ascii(mb_strtolower(trim($sheetName))), ['td', 'resumen gral'], true)) {
                $omitted[] = ['hoja' => $sheetName, 'motivo' => 'Hoja resumen o auxiliar.'];
                continue;
            }

            $headerSpreadsheet = $this->previewReader($path, $sheetName, 12)->load($path);
            try {
                $headerSheet = $headerSpreadsheet->getSheetByName($sheetName);
                $headerGrid = $headerSheet ? $this->sheetGrid($headerSheet) : [];
                $headerRow = $this->detectHeaderRow($headerGrid);
                if ($headerRow === null) {
                    $omitted[] = ['hoja' => $sheetName, 'motivo' => 'No se detectaron columnas de trabajador/documento.'];
                    continue;
                }

                $columns = $this->detectColumns($headerGrid, $headerRow);
                if (!$columns['documento']) {
                    $omitted[] = ['hoja' => $sheetName, 'motivo' => 'No se detecto columna de DNI/documento.'];
                    continue;
                }
                if (empty($columns['examenes'])) {
                    $omitted[] = ['hoja' => $sheetName, 'motivo' => 'Hoja resumen o sin examenes mineros detectados.'];
                    continue;
                }
            } finally {
                $headerSpreadsheet->disconnectWorksheets();
                unset($headerSpreadsheet);
                gc_collect_cycles();
            }

            $reader = $this->previewReader($path, $sheetName, 2000);
            $spreadsheet = $reader->load($path);

            try {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                if ($sheet) {
                    yield $sheet;
                }
            } finally {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $reader);
                gc_collect_cycles();
            }
        }
    }

    private function previewReader(string $path, string $sheetName, int $maxRows)
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new class($maxRows) implements IReadFilter {
            public function __construct(private readonly int $maxRows)
            {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= $this->maxRows && Coordinate::columnIndexFromString($columnAddress) <= 120;
            }
        });

        return $reader;
    }

    private function extendRuntimeLimit(int $seconds): void
    {
        @ini_set('max_execution_time', (string) $seconds);
        @set_time_limit($seconds);
    }

    private function sheetGrid($sheet): array
    {
        $highestRow = min((int) $sheet->getHighestDataRow(), 2000);
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $grid = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($column = 1; $column <= $highestColumn; $column++) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column) . $row);
                $value = $cell->getValue();
                if (is_string($value) && str_starts_with($value, '=')) {
                    $cached = $cell->getOldCalculatedValue();
                    if ($cached !== null && $cached !== '') {
                        $value = $cached;
                    }
                }
                if (is_numeric($value) && SpreadsheetDate::isDateTime($cell)) {
                    $value = SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
                }
                $grid[$row][$column] = trim((string) $value);
            }
        }

        foreach ($sheet->getMergeCells() as $mergedRange) {
            [$start, $end] = Coordinate::rangeBoundaries($mergedRange);
            [$startColumn, $startRow] = $start;
            [$endColumn, $endRow] = $end;
            if ($startRow > $highestRow || $startColumn > $highestColumn) {
                continue;
            }

            $value = trim((string) ($grid[$startRow][$startColumn] ?? ''));
            if ($value === '') {
                continue;
            }

            for ($row = $startRow; $row <= min($endRow, $highestRow); $row++) {
                for ($column = $startColumn; $column <= min($endColumn, $highestColumn); $column++) {
                    if (trim((string) ($grid[$row][$column] ?? '')) === '') {
                        $grid[$row][$column] = $value;
                    }
                }
            }
        }

        return $grid;
    }

    private function detectHeaderRow(array $grid): ?int
    {
        $bestRow = null;
        $bestScore = 0;
        foreach (array_slice($grid, 0, 12, true) as $rowNumber => $row) {
            $score = 0;
            foreach ($row as $value) {
                $normalized = $this->normalizeHeader($value);
                if ($this->isDocumentHeader($normalized)) {
                    $score += 5;
                }
                if ($this->isNameHeader($normalized) || $this->isWorkerDataHeader($normalized)) {
                    $score += 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $rowNumber;
            }
        }

        return $bestScore >= 5 ? $bestRow : null;
    }

    private function detectColumns(array $grid, int $headerRow): array
    {
        $headers = $grid[$headerRow] ?? [];
        $columns = [
            'documento' => null,
            'nombre' => null,
            'cargo' => null,
            'ocupacion' => null,
            'area' => null,
            'telefono' => null,
            'estado_laboral' => null,
            'estado_habilitacion' => null,
            'examenes' => [],
            'no_mapeadas' => [],
        ];

        foreach ($headers as $column => $header) {
            $normalized = $this->normalizeHeader($header);
            $parent = $this->nearestParentHeader($grid, $headerRow, $column);
            $parentNormalized = $this->normalizeHeader($parent);
            $combined = trim($parentNormalized . ' ' . $normalized);

            if ($this->isDocumentHeader($combined)) {
                $columns['documento'] = $this->preferredDocumentColumn($grid, $headerRow, $columns['documento'], $column);
                continue;
            }
            if ($this->isNameHeader($combined)) {
                $columns['nombre'] = $column;
                continue;
            }
            if (str_contains($combined, 'cargo') || str_contains($combined, 'puesto')) {
                $columns['cargo'] = $column;
                continue;
            }
            if (str_contains($combined, 'ocupacion')) {
                $columns['ocupacion'] = $column;
                continue;
            }
            if (preg_match('/\barea\b/u', $combined)) {
                $columns['area'] = $column;
                continue;
            }
            if (str_contains($combined, 'telefono') || str_contains($combined, 'celular')) {
                $columns['telefono'] = $column;
                continue;
            }
            if (str_contains($combined, 'habilitacion') || str_contains($combined, 'acredit') || str_contains($combined, 'acred')) {
                $columns['estado_habilitacion'] = $column;
                continue;
            }
            if (str_contains($combined, 'estado laboral') || str_contains($combined, 'contrato')) {
                $columns['estado_laboral'] = $column;
                continue;
            }

            if ($this->isOverallHabilitationHeader($normalized)) {
                $columns['estado_habilitacion'] = $column;
                continue;
            }

            if ($this->isAggregateSummaryHeader($normalized)) {
                continue;
            }

            $subfield = $this->detectExamSubfield($normalized ?: $combined);
            if ($subfield && $parentNormalized !== '' && !$this->isWorkerDataHeader($parentNormalized)) {
                $examName = mb_substr(PersonalNormalizer::text($parent), 0, 191);
                $columns['examenes'][$examName][$subfield] = $column;
                continue;
            }

            if ($this->isIgnoredWorkerMetadataHeader($normalized, $combined)) {
                continue;
            }

            if (trim((string) $header) !== '') {
                $columns['no_mapeadas'][$column] = $parent ? trim($parent . ' / ' . $header) : $header;
            }
        }

        return $columns;
    }

    private function nearestParentHeader(array $grid, int $headerRow, int $column): string
    {
        for ($row = $headerRow - 1; $row >= 1; $row--) {
            $value = trim((string) ($grid[$row][$column] ?? ''));
            if ($value !== '') {
                return $value;
            }

            for ($left = $column - 1; $left >= 1; $left--) {
                $leftValue = trim((string) ($grid[$row][$left] ?? ''));
                if ($leftValue !== '') {
                    return $leftValue;
                }
            }
        }

        return '';
    }

    private function detectMineName(string $sheetTitle, array $columns): string
    {
        if (empty($columns['examenes'])) {
            return '';
        }

        $name = PersonalNormalizer::text(preg_replace('/\s*\(\d+\)\s*$/u', '', $sheetTitle) ?: $sheetTitle);
        $parts = preg_split('/\s+/u', $name) ?: [];
        while (count($parts) > 2 && mb_strtolower((string) end($parts)) === mb_strtolower((string) $parts[0])) {
            array_pop($parts);
            $name = implode(' ', $parts);
        }

        return mb_substr($name, 0, 191);
    }

    private function isAuxiliarySheetName(string $sheetTitle): bool
    {
        $name = $this->normalizeHeader($sheetTitle);

        return preg_match('/^(hoja|sheet)\s*\d*$/u', $name) === 1
            || preg_match('/^(copia|backup|respaldo|temporal|temp)(\s|$)/u', $name) === 1;
    }

    private function examValuesFromRow(array $row, array $fields): array
    {
        $values = [];
        foreach ($fields as $field => $column) {
            $values[$field] = trim((string) ($row[$column] ?? ''));
        }

        return $values;
    }

    private function hasUsefulExamValues(array $values): bool
    {
        return collect($values)->contains(fn ($value) => trim((string) $value) !== '');
    }

    private function previewMetadataForExamValues(array $values): array
    {
        $payload = [
            'fecha_programacion' => $this->normalizeDate($values['fecha_programacion'] ?? null),
            'fecha_realizacion' => $this->normalizeDate($values['fecha_realizacion'] ?? null),
            'fecha_vencimiento' => $this->normalizeDate($values['fecha_vencimiento'] ?? null),
            'resultado' => $this->normalizeResult($values['resultado'] ?? $values['estado'] ?? '') ?: PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
            'nota' => $this->numericOrNull($values['nota'] ?? null),
            'observacion' => PersonalNormalizer::text($values['observacion'] ?? '') ?: null,
        ];
        $state = $this->stateFromImportedValues($values, $payload);

        return [
            'estado_mapeado' => $state,
            'resultado_mapeado' => $payload['resultado'],
            'accion_pendiente' => $this->pendingActionFromImportedValues($values, $state),
            'no_aplica_detectado' => $state === PersonalMinaExamen::ESTADO_NO_APLICA,
            'convalidacion_sugerida' => $this->isConvalidationHint($values),
            'precio_detectado_omitido' => $this->hasImportedPrice($values),
        ];
    }

    private function previewDatabaseIndex(array $rows, array $examCatalog = []): array
    {
        $workerDocs = collect($rows)->pluck('documento')->filter()->unique()->values();
        $workerDocVariants = $workerDocs
            ->flatMap(fn ($document) => $this->documentLookupVariants((string) $document))
            ->unique()
            ->values();
        $mineNames = collect($examCatalog)
            ->pluck('mina')
            ->merge(collect($rows)->pluck('mina'))
            ->filter()
            ->unique()
            ->values();
        $examNames = collect($examCatalog)
            ->flatMap(fn ($row) => collect($row['examenes'] ?? [])->pluck('nombre'))
            ->merge(collect($rows)->flatMap(fn ($row) => collect($row['examenes'] ?? [])->pluck('nombre')))
            ->filter()
            ->unique()
            ->values();

        $workersByDocument = [];
        if ($workerDocVariants->isNotEmpty()) {
            Personal::query()
                ->whereIn('numero_documento', $workerDocVariants)
                ->orWhereIn('dni', $workerDocVariants)
                ->get(['id', 'nombre_completo', 'numero_documento', 'dni'])
                ->each(function (Personal $worker) use (&$workersByDocument): void {
                    foreach ([$worker->numero_documento, $worker->dni] as $document) {
                        $document = $this->cleanDocument((string) $document);
                        if ($document !== '') {
                            $current = $workersByDocument[$document] ?? null;
                            if (!$current || (!$this->workerHasExactDocument($current, $document) && $this->workerHasExactDocument($worker, $document))) {
                                $workersByDocument[$document] = $worker;
                            }
                        }
                    }
                });
        }

        $minesByName = Mina::query()
            ->get(['id', 'nombre'])
            ->mapWithKeys(fn (Mina $mine) => [$this->indexKey($mine->nombre) => $mine])
            ->all();

        $allExams = ExamenMinero::query()
            ->get(['id', 'nombre', 'activo', 'permite_convalidacion'])
            ->values();
        $activeExams = $allExams->where('activo', true)->values();
        $examsByName = $allExams
            ->sortBy(fn (ExamenMinero $exam) => $exam->activo ? 1 : 0)
            ->mapWithKeys(fn (ExamenMinero $exam) => [$this->indexKey($exam->nombre) => $exam])
            ->all();
        $examsByCanonicalName = $allExams
            ->groupBy(fn (ExamenMinero $exam) => $this->examIndexKey($exam->nombre))
            ->filter(fn ($matches, $key) => $key !== '' && $matches->count() === 1)
            ->map(fn ($matches) => $matches->first())
            ->all();

        $mineIds = $mineNames
            ->map(fn ($name) => $minesByName[$this->indexKey((string) $name)]?->id ?? null)
            ->filter()
            ->unique()
            ->values();
        $examIds = $examNames
            ->map(fn ($name) => $this->matchingExam((string) $name, [
                'examsByName' => $examsByName,
                'examsByCanonicalName' => $examsByCanonicalName,
                'allExams' => $allExams,
            ])?->id)
            ->filter()
            ->unique()
            ->values();
        $workerIds = collect($workersByDocument)
            ->map(fn (Personal $worker) => $worker->id)
            ->unique()
            ->values();

        $requirementKeys = [];
        if ($mineIds->isNotEmpty() && $examIds->isNotEmpty()) {
            MinaRequisito::query()
                ->whereIn('mina_id', $mineIds)
                ->whereIn('examen_id', $examIds)
                ->where('activo', true)
                ->get(['mina_id', 'examen_id'])
                ->each(function (MinaRequisito $requirement) use (&$requirementKeys): void {
                    $requirementKeys[$requirement->mina_id . '|' . $requirement->examen_id] = true;
                });
        }

        $assignmentKeys = [];
        if ($workerIds->isNotEmpty() && $mineIds->isNotEmpty()) {
            PersonalMina::query()
                ->whereIn('personal_id', $workerIds)
                ->whereIn('mina_id', $mineIds)
                ->where('activo', true)
                ->get(['personal_id', 'mina_id'])
                ->each(function (PersonalMina $assignment) use (&$assignmentKeys): void {
                    $assignmentKeys[$assignment->personal_id . '|' . $assignment->mina_id] = true;
                });
        }

        return [
            'workersByDocument' => $workersByDocument,
            'minesByName' => $minesByName,
            'examsByName' => $examsByName,
            'examsByCanonicalName' => $examsByCanonicalName,
            'activeExams' => $activeExams,
            'allExams' => $allExams,
            'requirementKeys' => $requirementKeys,
            'assignmentKeys' => $assignmentKeys,
        ];
    }

    private function decoratePreviewRows(array $rows, array $index): array
    {
        foreach ($rows as &$row) {
            $worker = $index['workersByDocument'][$row['documento']] ?? null;
            $mine = $index['minesByName'][$this->indexKey((string) ($row['mina'] ?? ''))] ?? null;

            $row['trabajador_existe'] = (bool) $worker;
            $row['mina_existe'] = (bool) $mine;
            $row['trabajador_no_encontrado'] = !$worker;
            $row['accion_importacion'] = $worker
                ? 'ACTUALIZAR_HABILITACION_EXISTENTE'
                : 'OMITIR_TRABAJADOR_NO_ENCONTRADO';

            foreach ($row['examenes'] as &$examRow) {
                $examName = (string) ($examRow['nombre'] ?? '');
                $matchedExam = $this->matchingExam($examName, $index);
                $examRow['existe'] = (bool) $matchedExam;
                $examRow['examen_id_existente'] = $matchedExam?->id;
                $examRow['nombre_anterior'] = $matchedExam?->nombre;
                $examRow['accion_catalogo'] = $this->catalogActionFor($examName, $matchedExam);
                $examRow['examenes_parecidos'] = $this->similarExamSuggestionsFor($examName, $index);
            }
            unset($examRow);
        }
        unset($row);

        return $rows;
    }

    private function decorateExamCatalog(array $examCatalog, array $index): array
    {
        foreach ($examCatalog as &$catalogRow) {
            foreach ($catalogRow['examenes'] as &$examRow) {
                $name = (string) ($examRow['nombre'] ?? '');
                $matchedExam = $this->matchingExam($name, $index);
                $examRow['existe'] = (bool) $matchedExam;
                $examRow['examen_id_existente'] = $matchedExam?->id;
                $examRow['nombre_anterior'] = $matchedExam?->nombre;
                $examRow['accion_catalogo'] = $this->catalogActionFor($name, $matchedExam);
            }
            unset($examRow);
        }
        unset($catalogRow);

        return $examCatalog;
    }

    private function buildPreviewSummary(array $rows, array $errors, array $omitted, array $unmapped, array $index, array $examCatalog): array
    {
        $workerDocs = collect($rows)->pluck('documento')->filter()->unique();
        $mineNames = collect($examCatalog)->pluck('mina')->merge(collect($rows)->pluck('mina'))->filter()->unique();
        $catalogExamRows = collect($examCatalog)->flatMap(fn ($row) => $row['examenes'] ?? []);
        $sharedExamKeys = $this->sharedExamKeys($examCatalog);
        $existingExamsById = collect($index['allExams'] ?? [])->keyBy('id');
        $convalidationPlan = $catalogExamRows
            ->unique(fn ($exam) => $this->sharedExamIdentity($exam))
            ->map(function ($exam) use ($sharedExamKeys, $existingExamsById): array {
                $existing = $existingExamsById->get($exam['examen_id_existente'] ?? '');

                return [
                    'nombre' => (string) ($exam['nombre'] ?? ''),
                    'actual' => (bool) ($existing?->permite_convalidacion ?? false),
                    'nuevo' => isset($sharedExamKeys[$this->sharedExamIdentity($exam)]),
                ];
            });
        $convalidationChanges = $convalidationPlan->filter(fn ($change) => $change['actual'] !== $change['nuevo']);
        $convalidationAllowed = $convalidationPlan->where('nuevo', true)->pluck('nombre')->sort()->values()->all();
        $convalidationNotAllowed = $convalidationPlan->where('nuevo', false)->pluck('nombre')->sort()->values()->all();
        $convalidationToActivate = $convalidationChanges->where('nuevo', true)->pluck('nombre')->sort()->values()->all();
        $convalidationToDeactivate = $convalidationChanges->where('nuevo', false)->pluck('nombre')->sort()->values()->all();
        $matchedExamIds = $catalogExamRows->pluck('examen_id_existente')->filter()->unique();
        $conflicts = $this->conflicts($rows, $index);
        $missingWorkerDocs = $workerDocs->filter(fn ($doc) => !isset($index['workersByDocument'][$doc]));
        $existingWorkerDocs = $workerDocs->filter(fn ($doc) => isset($index['workersByDocument'][$doc]));
        $requirementsToDeactivate = $this->obsoleteRequirementsByMine($examCatalog, $index);

        return [
            'token' => (string) Str::uuid(),
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'trabajadores_nuevos' => $missingWorkerDocs->count(),
                'trabajadores_no_encontrados' => $missingWorkerDocs->count(),
                'trabajadores_existentes' => $existingWorkerDocs->count(),
                'filas_importables' => collect($rows)->where('trabajador_existe', true)->count(),
                'filas_no_importables_trabajador_no_encontrado' => collect($rows)->where('trabajador_existe', false)->count(),
                'minas_nuevas' => $mineNames->filter(fn ($name) => !isset($index['minesByName'][$this->indexKey((string) $name)]))->count(),
                'minas_existentes' => $mineNames->filter(fn ($name) => isset($index['minesByName'][$this->indexKey((string) $name)]))->count(),
                'examenes_nuevos' => $catalogExamRows->where('existe', false)->pluck('nombre')->unique()->count(),
                'examenes_existentes' => $catalogExamRows->where('existe', true)->pluck('examen_id_existente')->unique()->count(),
                'examenes_catalogo_por_desactivar' => collect($index['activeExams'] ?? [])->reject(fn (ExamenMinero $exam) => $matchedExamIds->contains($exam->id))->count(),
                'nombres_examen_por_actualizar' => $catalogExamRows->where('accion_catalogo', 'ACTUALIZAR_NOMBRE')->pluck('examen_id_existente')->unique()->count(),
                'examenes_compartidos_convalidables' => count($sharedExamKeys),
                'examenes_convalidables_por_activar' => count($convalidationToActivate),
                'examenes_convalidables_por_desactivar' => count($convalidationToDeactivate),
                'examenes_agregados_a_minas' => $this->countMissingRequirements($examCatalog, $index),
                'requisitos_obsoletos_por_desactivar' => collect($requirementsToDeactivate)->sum(fn ($row) => count($row['requisitos'] ?? [])),
                'trabajadores_asignados_a_minas' => $this->countMissingAssignments($rows, $index),
                'filas_con_error' => count($errors),
                'filas_omitidas' => count($omitted),
                'datos_no_mapeados' => count($unmapped),
                'conflictos' => $conflicts->count(),
                'cambios_precio_detectados' => $this->countPriceChanges($rows),
                'precios_detectados_omitidos' => $this->countPriceChanges($rows),
                'convalidaciones_sugeridas' => $this->countConvalidationHints($rows),
                'no_aplica_detectados' => $this->countMappedExamStates($rows, PersonalMinaExamen::ESTADO_NO_APLICA),
                'dni_7_digitos_corregidos' => collect($rows)->where('documento_corregido_con_cero', true)->pluck('documento')->unique()->count(),
            ],
            'sheets' => [
                'detectadas' => collect($examCatalog)->pluck('hoja')->merge(collect($rows)->pluck('hoja'))->filter()->unique()->values()->all(),
                'omitidas' => $omitted,
            ],
            'trabajadores_no_encontrados' => $rows
                ? collect($rows)
                    ->where('trabajador_existe', false)
                    ->map(fn ($row) => [
                        'hoja' => $row['hoja'] ?? null,
                        'fila' => $row['fila'] ?? null,
                        'documento' => $row['documento'] ?? null,
                        'nombre' => $row['nombre'] ?? null,
                    ])
                    ->unique(fn ($row) => ($row['documento'] ?? '') . '|' . ($row['hoja'] ?? '') . '|' . ($row['fila'] ?? ''))
                    ->values()
                    ->all()
                : [],
            'examenes_no_encontrados' => $catalogExamRows
                ->where('existe', false)
                ->pluck('nombre')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'convalidacion_por_activar' => $convalidationToActivate,
            'convalidacion_por_desactivar' => $convalidationToDeactivate,
            'convalidacion_permitida' => $convalidationAllowed,
            'convalidacion_no_permitida' => $convalidationNotAllowed,
            'nombres_examen_por_actualizar' => $catalogExamRows
                ->where('accion_catalogo', 'ACTUALIZAR_NOMBRE')
                ->map(fn ($exam) => [
                    'actual' => $exam['nombre_anterior'] ?? null,
                    'nuevo' => $exam['nombre'] ?? null,
                ])
                ->unique(fn ($exam) => ($exam['actual'] ?? '') . '|' . ($exam['nuevo'] ?? ''))
                ->values()
                ->all(),
            'equivalencias_nombres_seguras' => $catalogExamRows
                ->where('accion_catalogo', 'USAR_NOMBRE_EXISTENTE')
                ->map(fn ($exam) => [
                    'detectado' => $exam['nombre'] ?? null,
                    'catalogo' => $exam['nombre_anterior'] ?? null,
                ])
                ->unique(fn ($exam) => ($exam['detectado'] ?? '') . '|' . ($exam['catalogo'] ?? ''))
                ->values()
                ->all(),
            'examenes_ausentes_en_excel' => collect($index['activeExams'] ?? [])
                ->reject(fn (ExamenMinero $exam) => $matchedExamIds->contains($exam->id))
                ->pluck('nombre')
                ->filter()
                ->sort()
                ->values()
                ->all(),
            'examenes_faltantes_por_mina' => $this->missingExamNamesByMine($examCatalog),
            'requisitos_obsoletos_por_mina' => $requirementsToDeactivate,
            'examenes_parecidos' => $this->similarExamSuggestions($examCatalog, $index),
            'catalogo_examenes' => $examCatalog,
            'rows' => $rows,
            'errors' => $errors,
            'omitted' => $omitted,
            'unmapped' => array_slice($unmapped, 0, 200),
            'conflicts' => $conflicts->values()->all(),
        ];
    }

    private function resolveWorker(array $row): ?Personal
    {
        $document = $this->cleanDocument((string) ($row['documento'] ?? ''));
        $documentVariants = $this->documentLookupVariants($document);

        $candidates = Personal::query()
            ->whereIn('numero_documento', $documentVariants)
            ->orWhereIn('dni', $documentVariants)
            ->get();
        $personal = $this->bestWorkerForDocument($candidates, $document);

        if ($personal) {
            return $personal;
        }

        return null;
    }

    private function resolveWorkerCached(array $row, array &$cache): ?Personal
    {
        $key = $this->cleanDocument((string) ($row['documento'] ?? ''));
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveWorker($row);
    }

    private function resolveMine(string $name, array &$counts): ?Mina
    {
        $name = mb_substr(PersonalNormalizer::text($name), 0, 191);
        if ($name === '') {
            return null;
        }

        $mine = Mina::query()->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($name)])->first();
        if ($mine) {
            return $mine;
        }

        $counts['minas_creadas']++;

        return Mina::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => $name,
            'unidad_minera' => $name,
            'ubicacion' => 'Importado',
            'estado' => 'ACTIVO',
        ]);
    }

    private function resolveMineCached(string $name, array &$counts, array &$cache): ?Mina
    {
        $key = $this->indexKey($name);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveMine($name, $counts);
    }

    private function resolveExam(array $examRow, array &$counts, Usuario $user): ExamenMinero
    {
        $name = mb_substr(PersonalNormalizer::text($examRow['nombre'] ?? ''), 0, 191);
        $allExams = ExamenMinero::query()->get();
        $exam = $allExams
            ->sortByDesc(fn (ExamenMinero $candidate) => (int) $candidate->activo)
            ->first(fn (ExamenMinero $candidate) => $this->indexKey($candidate->nombre) === $this->indexKey($name));
        $matchType = $exam ? 'exact' : null;
        if (!$exam) {
            $canonicalKey = $this->examIndexKey($name);
            $canonicalMatches = $allExams
                ->filter(fn (ExamenMinero $candidate) => $this->examIndexKey($candidate->nombre) === $canonicalKey);
            $exam = $canonicalMatches->count() === 1 ? $canonicalMatches->first() : null;
            $matchType = $exam ? 'canonical' : null;
        }
        if ($exam) {
            if (!$exam->activo) {
                $exam->forceFill([
                    'activo' => true,
                    'updated_by_usuario_id' => $user->id,
                ])->save();
                $counts['examenes_reactivados']++;
            }

            if ($exam->nombre !== $name && $name !== '') {
                $exam->forceFill([
                    'nombre' => $name,
                    'updated_by_usuario_id' => $user->id,
                ])->save();
                MinaRequisito::query()
                    ->where('examen_id', $exam->id)
                    ->where('activo', true)
                    ->update(['nombre' => $name, 'updated_at' => now()]);
                $counts['examenes_renombrados']++;
            }

            return $exam;
        }

        $counts['examenes_creados']++;

        return $this->habilitation->storeMiningExam([
            'nombre' => $name,
            'tipo' => 'Importado',
            'permite_reintento' => true,
            'max_intentos' => 2,
        ], $user);
    }

    private function resolveExamCached(array $examRow, array &$counts, Usuario $user, array &$cache): ExamenMinero
    {
        $key = $this->examIndexKey((string) ($examRow['nombre'] ?? ''));
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveExam($examRow, $counts, $user);
    }

    private function resolveRequirement(Mina $mine, ExamenMinero $exam, array &$counts, bool $allowsConvalidation = false): MinaRequisito
    {
        $requirement = MinaRequisito::query()
            ->where('mina_id', $mine->id)
            ->where('examen_id', $exam->id)
            ->orderByDesc('activo')
            ->orderByDesc('updated_at')
            ->first();
        if ($requirement) {
            $payload = [
                'nombre' => $exam->nombre,
                'tipo' => $exam->tipo,
                'activo' => true,
                'permite_convalidacion_mina' => $allowsConvalidation,
                'convalidar_desde_otras_minas' => $allowsConvalidation,
            ];
            if (!$allowsConvalidation) {
                $payload['fecha_inicio_convalidacion'] = null;
                $payload['fecha_fin_convalidacion'] = null;
                $payload['minas_origen_convalidacion_json'] = null;
            }
            $requirement->forceFill($payload);
            if ($requirement->isDirty()) {
                if (!$requirement->getOriginal('activo')) {
                    $counts['examenes_mina_reactivados']++;
                }
                $requirement->save();
                $counts['examenes_mina_actualizados']++;
            }

            return $requirement;
        }

        $counts['examenes_mina_agregados']++;

        return MinaRequisito::query()->create([
            'id' => (string) Str::uuid(),
            'mina_id' => $mine->id,
            'examen_id' => $exam->id,
            'nombre' => $exam->nombre,
            'tipo' => $exam->tipo,
            'obligatorio' => true,
            'critico' => (bool) $exam->critico,
            'reprogramable' => (bool) $exam->permite_reintento,
            'vigencia_dias' => $exam->vigencia_dias,
            'activo' => true,
            'orden' => 0,
            'permite_no_aplica' => true,
            'permite_convalidacion_mina' => $allowsConvalidation,
            'convalidar_desde_otras_minas' => $allowsConvalidation,
        ]);
    }

    private function resolveRequirementCached(Mina $mine, ExamenMinero $exam, array &$counts, array &$cache, bool $allowsConvalidation = false): MinaRequisito
    {
        $key = $mine->id . '|' . $exam->id;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveRequirement($mine, $exam, $counts, $allowsConvalidation);
    }

    private function syncExamConvalidation(ExamenMinero $exam, bool $allowsConvalidation, array &$counts, Usuario $user): void
    {
        if ((bool) $exam->permite_convalidacion === $allowsConvalidation) {
            return;
        }

        $exam->forceFill([
            'permite_convalidacion' => $allowsConvalidation,
            'updated_by_usuario_id' => $user->id,
        ])->save();
        $counts[$allowsConvalidation ? 'examenes_convalidables_activados' : 'examenes_convalidables_desactivados']++;
    }

    private function resolveAssignment(Personal $personal, Mina $mine, Usuario $user, array &$counts): PersonalMina
    {
        $assignment = PersonalMina::query()
            ->where('personal_id', $personal->id)
            ->where('mina_id', $mine->id)
            ->where('activo', true)
            ->first();
        if ($assignment) {
            return $assignment;
        }

        $counts['asignaciones_creadas']++;

        return $this->habilitation->assignMine([
            'personal_id' => $personal->id,
            'mina_id' => $mine->id,
            'estado_habilitacion' => PersonalMina::ESTADO_EN_PROCESO,
            'fecha_asignacion' => Carbon::today()->toDateString(),
        ], $user, false);
    }

    private function resolveAssignmentCached(Personal $personal, Mina $mine, Usuario $user, array &$counts, array &$cache): PersonalMina
    {
        $key = $personal->id . '|' . $mine->id;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveAssignment($personal, $mine, $user, $counts);
    }

    private function applyExamValues(PersonalMinaExamen $workerExam, array $values, Usuario $user): ?string
    {
        $result = $this->normalizeResult($values['resultado'] ?? $values['estado'] ?? '');
        $payload = [
            'fecha_programacion' => $this->normalizeDate($values['fecha_programacion'] ?? null),
            'fecha_realizacion' => $this->normalizeDate($values['fecha_realizacion'] ?? null),
            'fecha_vencimiento' => $this->normalizeDate($values['fecha_vencimiento'] ?? null),
            'resultado' => $result ?: PersonalMinaExamenIntento::RESULTADO_PENDIENTE,
            'nota' => $this->numericOrNull($values['nota'] ?? null),
            'observacion' => PersonalNormalizer::text($values['observacion'] ?? '') ?: null,
        ];
        $state = $this->stateFromImportedValues($values, $payload);
        $hasStateOnlyValue = $this->hasImportedStateOnlyValue($values, $state);

        if (!$result && !$payload['fecha_programacion'] && !$payload['fecha_realizacion'] && !$payload['fecha_vencimiento'] && !$payload['observacion'] && $payload['nota'] === null && !$hasStateOnlyValue) {
            return null;
        }

        if ($state === PersonalMinaExamen::ESTADO_NO_APLICA && !$payload['fecha_programacion'] && !$payload['fecha_realizacion']) {
            $this->updateWorkerExamSnapshotFromImport($workerExam, $values, $payload, $user);

            return 'updated';
        }

        if ($this->attemptAlreadyImported($workerExam, $payload)) {
            $this->updateWorkerExamSnapshotFromImport($workerExam, $values, $payload, $user);

            return 'duplicate';
        }

        try {
            $created = $this->storeImportedAttempt($workerExam, $payload, $user);
            $this->updateWorkerExamSnapshotFromImport($workerExam, $values, $payload, $user);

            return $created ? 'created' : null;
        } catch (\Throwable) {
            $this->updateWorkerExamSnapshotFromImport($workerExam, $values, $payload, $user);

            return null;
        }
    }

    private function storeImportedAttempt(PersonalMinaExamen $workerExam, array $payload, Usuario $user): bool
    {
        $attempts = $workerExam->relationLoaded('intentos')
            ? $workerExam->intentos
            : PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $workerExam->id)->get();

        $nextAttempt = ((int) ($attempts->max('numero_intento') ?? 0)) + 1;

        $priceSnapshot = $this->habilitation->resolveAttemptPriceSnapshot(
            $workerExam,
            Carbon::today()->toDateString(),
            $payload['fecha_programacion'],
            $payload['fecha_realizacion'],
        );

        DB::table('personal_mina_examen_intentos')->insert([
            'id' => (string) Str::uuid(),
            'personal_mina_examen_id' => $workerExam->id,
            'numero_intento' => $nextAttempt,
            'fecha_programacion' => $payload['fecha_programacion'],
            'fecha_realizacion' => $payload['fecha_realizacion'],
            'resultado' => $payload['resultado'],
            'nota' => $payload['nota'],
            'precio_aplicado' => $priceSnapshot['precio'],
            'moneda_aplicada' => $priceSnapshot['moneda'],
            'fecha_precio_aplicado' => $priceSnapshot['fecha'],
            'fuente_precio' => $priceSnapshot['fuente'],
            'observacion' => $payload['observacion'],
            'usuario_registro_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function attemptAlreadyImported(PersonalMinaExamen $workerExam, array $payload): bool
    {
        $attempts = $workerExam->relationLoaded('intentos')
            ? $workerExam->intentos
            : PersonalMinaExamenIntento::query()->where('personal_mina_examen_id', $workerExam->id)->get();

        return $attempts->contains(function (PersonalMinaExamenIntento $attempt) use ($payload): bool {
            if ($attempt->resultado !== $payload['resultado']) {
                return false;
            }

            foreach (['fecha_programacion', 'fecha_realizacion'] as $field) {
                $attemptDate = optional($attempt->{$field})->toDateString();
                if ($attemptDate !== $payload[$field]) {
                    return false;
                }
            }

            return true;
        });
    }

    private function updateWorkerExamSnapshotFromImport(?PersonalMinaExamen $workerExam, array $values, array $payload, Usuario $user): void
    {
        if (!$workerExam) {
            return;
        }

        $state = $this->stateFromImportedValues($values, $payload);
        $observation = $payload['observacion'] ?: $workerExam->observacion;
        $place = PersonalNormalizer::text($values['lugar'] ?? '') ?: $workerExam->lugar_snapshot;

        DB::table('personal_mina_examenes')
            ->where('id', $workerExam->id)
            ->update([
                'lugar_snapshot' => $place ? mb_substr($place, 0, 191) : null,
                'estado' => $state,
                'resultado' => $payload['resultado'],
                'fecha_programacion' => $payload['fecha_programacion'],
                'fecha_realizacion' => $payload['fecha_realizacion'],
                'fecha_vencimiento' => $payload['fecha_vencimiento'],
                'nota_obtenida' => $payload['nota'],
                'observacion' => $observation,
                'usuario_actualizacion_id' => $user->id,
                'fecha_actualizacion' => now(),
                'updated_at' => now(),
            ]);
    }

    private function stateFromImportedValues(array $values, array $payload): string
    {
        $rawText = implode(' ', [
            $values['estado'] ?? '',
            $values['resultado'] ?? '',
            $values['observacion'] ?? '',
            $values['aplica'] ?? '',
            $values['fecha_programacion'] ?? '',
            $values['fecha_realizacion'] ?? '',
            $values['fecha_vencimiento'] ?? '',
        ]);
        $raw = $this->normalizeHeader($rawText);
        $rawCompact = trim(str_replace(['.', '/'], '', $raw));

        if (
            str_contains($raw, 'no aplica')
            || str_contains($raw, 'no corresponde')
            || str_contains($raw, 'no requerido')
            || in_array($rawCompact, ['na', 'n a'], true)
        ) {
            return PersonalMinaExamen::ESTADO_NO_APLICA;
        }
        if (str_contains($raw, 'desaprob') || str_contains($raw, 'no apt') || str_contains($raw, 'rechaz')) {
            return PersonalMinaExamen::ESTADO_DESAPROBADO;
        }
        if (str_contains($raw, 'levantar observ') || str_contains($raw, 'observ') || str_contains($raw, 'restric')) {
            return PersonalMinaExamen::ESTADO_OBSERVADO;
        }
        if (str_contains($raw, 'vencido')) {
            return PersonalMinaExamen::ESTADO_VENCIDO;
        }
        if (str_contains($raw, 'por vencer')) {
            return PersonalMinaExamen::ESTADO_POR_VENCER;
        }
        if (str_contains($raw, 'programar emo') || str_contains($raw, 'pendiente resultado')) {
            return PersonalMinaExamen::ESTADO_PROGRAMADO;
        }
        if (str_contains($raw, 'vigente') || str_contains($raw, 'aprob') || str_contains($raw, 'apto')) {
            return $this->stateForImportedExpiration($payload['fecha_vencimiento']);
        }
        if (str_contains($raw, 'program') || str_contains($raw, 'confirm') || str_contains($raw, 'pend')) {
            return PersonalMinaExamen::ESTADO_PROGRAMADO;
        }

        return $payload['fecha_programacion'] ? PersonalMinaExamen::ESTADO_PROGRAMADO : PersonalMinaExamen::ESTADO_PENDIENTE;
    }

    private function stateForImportedExpiration(?string $expiration): string
    {
        if (!$expiration) {
            return PersonalMinaExamen::ESTADO_APROBADO;
        }

        $today = Carbon::today();
        $end = Carbon::parse($expiration);
        if ($end->lt($today)) {
            return PersonalMinaExamen::ESTADO_VENCIDO;
        }
        if ($today->diffInDays($end, false) <= 30) {
            return PersonalMinaExamen::ESTADO_POR_VENCER;
        }

        return PersonalMinaExamen::ESTADO_VIGENTE;
    }

    private function applyImportedAssignmentState(Personal $personal, Mina $mina, array $row, Usuario $user): bool
    {
        $assignment = PersonalMina::query()
            ->where('personal_id', $personal->id)
            ->where('mina_id', $mina->id)
            ->where('activo', true)
            ->first();
        if (!$assignment) {
            return false;
        }

        $excelState = $this->normalizeImportedHabilitationState($row['estado_habilitacion'] ?? '');
        if (!$excelState) {
            return false;
        }

        $examCount = PersonalMinaExamen::query()
            ->where('personal_mina_id', $assignment->id)
            ->count();
        if ($excelState === PersonalMina::ESTADO_HABILITADO && $examCount === 0) {
            $excelState = PersonalMina::ESTADO_EN_PROCESO;
        }
        if ($excelState === PersonalMina::ESTADO_HABILITADO && $this->habilitation->calculatedAssignmentStatus($assignment) !== PersonalMina::ESTADO_HABILITADO) {
            $excelState = PersonalMina::ESTADO_EN_PROCESO;
        }

        if ($assignment->estadoHabilitacionActual() === $excelState) {
            return false;
        }

        $assignment->forceFill([
            'estado' => $excelState,
            'estado_habilitacion' => $excelState,
            'fecha_habilitacion' => $excelState === PersonalMina::ESTADO_HABILITADO ? Carbon::today()->toDateString() : null,
            'usuario_actualizacion_id' => $user->id,
        ])->save();

        return true;
    }

    private function normalizeImportedHabilitationState(string $value): ?string
    {
        $value = $this->normalizeHeader($value);

        return match (true) {
            str_contains($value, 'habilitado') && !str_contains($value, 'no habilitado') => PersonalMina::ESTADO_HABILITADO,
            str_contains($value, 'no habilitado') => PersonalMina::ESTADO_NO_HABILITADO,
            str_contains($value, 'observ') => PersonalMina::ESTADO_OBSERVADO,
            str_contains($value, 'desaprob') => PersonalMina::ESTADO_NO_HABILITADO,
            str_contains($value, 'proceso') || str_contains($value, 'pend') || str_contains($value, 'program') => PersonalMina::ESTADO_EN_PROCESO,
            default => null,
        };
    }

    private function hasImportedPrice(array $values): bool
    {
        return $this->numericOrNull($values['precio'] ?? null) !== null;
    }

    private function isConvalidationHint(array $values): bool
    {
        $raw = $this->normalizeHeader(implode(' ', [
            $values['estado'] ?? '',
            $values['resultado'] ?? '',
            $values['observacion'] ?? '',
            $values['aplica'] ?? '',
        ]));

        return str_contains($raw, 'convalid');
    }

    private function pendingActionFromImportedValues(array $values, string $state): ?string
    {
        $raw = $this->normalizeHeader(implode(' ', [
            $values['estado'] ?? '',
            $values['resultado'] ?? '',
            $values['observacion'] ?? '',
            $values['aplica'] ?? '',
        ]));

        if (str_contains($raw, 'programar emo') || (str_contains($raw, 'program') && str_contains($raw, 'emo'))) {
            return 'PROGRAMAR_EXAMEN';
        }
        if (str_contains($raw, 'pendiente resultado')) {
            return 'REGISTRAR_RESULTADO';
        }
        if (str_contains($raw, 'levantar observ')) {
            return 'LEVANTAR_OBSERVACION';
        }
        if ($state === PersonalMinaExamen::ESTADO_OBSERVADO) {
            return 'REVISAR_OBSERVACION';
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));

        return preg_replace('/\s+/u', ' ', $value) ?: '';
    }

    private function isDocumentHeader(string $value): bool
    {
        return str_contains($value, 'dni') || str_contains($value, 'documento') || preg_match('/\bdoc\b/u', $value);
    }

    private function isNameHeader(string $value): bool
    {
        return str_contains($value, 'nombre') || str_contains($value, 'trabajador') || str_contains($value, 'colaborador');
    }

    private function isWorkerDataHeader(string $value): bool
    {
        return $this->isDocumentHeader($value)
            || $this->isNameHeader($value)
            || str_contains($value, 'cargo')
            || str_contains($value, 'puesto')
            || str_contains($value, 'ocupacion')
            || str_contains($value, 'area')
            || str_contains($value, 'telefono')
            || str_contains($value, 'contrato');
    }

    private function isIgnoredWorkerMetadataHeader(string $normalized, string $combined): bool
    {
        $compact = str_replace(['.', ' ', '/', '-', '°', 'º'], '', $normalized);

        return in_array($compact, ['n', 'no', 'nro', 'num', 'numero', '#', 'cc'], true)
            || in_array($normalized, ['estado', 'responsable', 'residencia', 'paso', 'obsr'], true)
            || str_contains($combined, 'cargo conteo')
            || str_contains($combined, 'fecha fin')
            || str_contains($combined, 'centro costo');
    }

    private function detectExamSubfield(string $value): ?string
    {
        $compact = str_replace(['.', ' ', '/', '-'], '', $value);

        return match (true) {
            str_contains($value, 'program') || str_contains($value, 'prog') || str_contains($value, 'inicio') || preg_match('/\benv\b/u', $value) => 'fecha_programacion',
            str_contains($value, 'realizacion') || str_contains($value, 'fecha examen') || preg_match('/\breal\b/u', $value) => 'fecha_realizacion',
            str_contains($value, 'venc') || str_contains($value, 'vto') || str_contains($compact, 'fv') => 'fecha_vencimiento',
            str_contains($value, 'resultado') => 'resultado',
            str_contains($value, 'estado') => 'estado',
            str_contains($value, 'observ') || in_array($value, ['obs', 'obsr'], true) => 'observacion',
            str_contains($value, 'sede') || str_contains($value, 'clinica') || str_contains($value, 'lugar') => 'lugar',
            str_contains($value, 'nota') => 'nota',
            str_contains($value, 'precio') || str_contains($value, 'costo') => 'precio',
            str_contains($value, 'requiere') || preg_match('/\baplica\b/u', $value) => 'aplica',
            default => null,
        };
    }

    private function rowIsBlank(array $row): bool
    {
        return !collect($row)->contains(fn ($value) => trim((string) $value) !== '');
    }

    private function cleanDocument(string $value): string
    {
        $digits = $this->rawDocumentDigits($value);

        return strlen($digits) === 7 ? '0' . $digits : $digits;
    }

    private function rawDocumentDigits(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d+(\.0+)?$/', $value)) {
            $value = str_replace('.0', '', $value);
        }

        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function documentLookupVariants(string $document): array
    {
        $document = $this->cleanDocument($document);
        if ($document === '') {
            return [];
        }

        $variants = [$document];
        if (strlen($document) === 8 && str_starts_with($document, '0')) {
            $variants[] = ltrim($document, '0') ?: $document;
        }

        return array_values(array_unique($variants));
    }

    private function bestWorkerForDocument($workers, string $document): ?Personal
    {
        if ($workers->isEmpty()) {
            return null;
        }

        return $workers->first(fn (Personal $worker) => $this->workerHasExactDocument($worker, $document))
            ?: $workers->first();
    }

    private function workerHasExactDocument(Personal $worker, string $document): bool
    {
        foreach ([$worker->numero_documento, $worker->dni] as $value) {
            if ($this->rawDocumentDigits((string) $value) === $document) {
                return true;
            }
        }

        return false;
    }

    private function preferredDocumentColumn(array $grid, int $headerRow, ?int $currentColumn, int $candidateColumn): int
    {
        if ($currentColumn === null) {
            return $candidateColumn;
        }

        return $this->documentColumnScore($grid, $headerRow, $candidateColumn) > $this->documentColumnScore($grid, $headerRow, $currentColumn)
            ? $candidateColumn
            : $currentColumn;
    }

    private function documentColumnScore(array $grid, int $headerRow, int $column): int
    {
        $score = 0;
        $lastRow = min(count($grid), $headerRow + 80);
        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $digits = $this->rawDocumentDigits((string) ($grid[$row][$column] ?? ''));
            $length = strlen($digits);
            if ($length === 8) {
                $score += 5;
                if (str_starts_with($digits, '0')) {
                    $score += 3;
                }
                continue;
            }
            if ($length === 7) {
                $score += 3;
                continue;
            }
            if ($length > 0) {
                $score += 1;
            }
        }

        return $score;
    }

    private function isOverallHabilitationHeader(string $value): bool
    {
        return in_array($value, ['estado gral', 'estado gral.', 'estado general', 'estado acreditacion'], true);
    }

    private function isAggregateSummaryHeader(string $value): bool
    {
        return in_array($value, ['vigente', 'vencido', 'no aplica', 'por vencer'], true)
            || preg_match('/^estado\s+gral\.?$/u', $value) === 1;
    }

    private function hasImportedStateOnlyValue(array $values, string $state): bool
    {
        if ($state === PersonalMinaExamen::ESTADO_PENDIENTE) {
            return false;
        }

        foreach (['estado', 'resultado', 'observacion', 'aplica', 'fecha_programacion', 'fecha_realizacion', 'fecha_vencimiento'] as $field) {
            if (trim((string) ($values[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function indexKey(string $value): string
    {
        return $this->normalizeHeader($value);
    }

    private function examIndexKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', $this->normalizeHeader($value)) ?: '';
    }

    private function matchingExam(string $name, array $index): ?ExamenMinero
    {
        $matched = $index['examsByName'][$this->indexKey($name)]
            ?? $index['examsByCanonicalName'][$this->examIndexKey($name)]
            ?? null;
        if ($matched) {
            return $matched;
        }

        return null;
    }

    private function catalogActionFor(string $name, ?ExamenMinero $exam): string
    {
        if (!$exam) {
            return 'CREAR_EXAMEN';
        }
        if (!$exam->activo) {
            return 'REACTIVAR_EXAMEN';
        }
        if ($exam->nombre === $name) {
            return 'SIN_CAMBIOS';
        }

        return $this->examIndexKey($exam->nombre) === $this->examIndexKey($name)
            ? 'ACTUALIZAR_NOMBRE'
            : 'USAR_NOMBRE_EXISTENTE';
    }

    private function examCatalogFromRows(array $rows): array
    {
        return collect($rows)
            ->groupBy(fn ($row) => ($row['hoja'] ?? '') . '|' . ($row['mina'] ?? ''))
            ->map(function ($sheetRows) {
                $first = $sheetRows->first();

                return [
                    'hoja' => $first['hoja'] ?? null,
                    'mina' => $first['mina'] ?? null,
                    'examenes' => $sheetRows
                        ->flatMap(fn ($row) => $row['examenes'] ?? [])
                        ->filter(fn ($exam) => filled($exam['nombre'] ?? null))
                        ->unique(fn ($exam) => $this->examIndexKey((string) ($exam['nombre'] ?? '')))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function catalogByMine(array $examCatalog): array
    {
        return collect($examCatalog)
            ->filter(fn ($row) => filled($row['mina'] ?? null))
            ->groupBy(fn ($row) => $this->indexKey((string) ($row['mina'] ?? '')))
            ->map(function ($mineRows): array {
                $first = $mineRows->first();

                return [
                    'hoja' => $first['hoja'] ?? null,
                    'hojas' => $mineRows->pluck('hoja')->filter()->unique()->values()->all(),
                    'mina' => $first['mina'] ?? null,
                    'examenes' => $mineRows
                        ->flatMap(fn ($row) => $row['examenes'] ?? [])
                        ->filter(fn ($exam) => filled($exam['nombre'] ?? null))
                        ->unique(fn ($exam) => $this->sharedExamIdentity($exam))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Replaces the active catalog while preserving historical rows referenced by attempts.
     * Inactive records are excluded from future configurations and habilitation calculations.
     *
     * @return array<int, string> Mine IDs whose active requirements changed.
     */
    private function replaceActiveCatalog(
        array $currentExamIds,
        array $currentRequirementIds,
        array &$counts,
        Usuario $user,
    ): array {
        if ($currentExamIds === [] || $currentRequirementIds === []) {
            throw new \RuntimeException('El Master no contiene examenes y requisitos suficientes para reemplazar el catalogo activo.');
        }

        $obsoleteRequirements = MinaRequisito::query()
            ->where('activo', true)
            ->whereNotIn('id', $currentRequirementIds)
            ->get(['id', 'mina_id']);

        if ($obsoleteRequirements->isNotEmpty()) {
            MinaRequisito::query()
                ->whereIn('id', $obsoleteRequirements->pluck('id'))
                ->update([
                    'activo' => false,
                    'updated_at' => now(),
                ]);
            $counts['examenes_mina_desactivados'] += $obsoleteRequirements->count();
        }

        $obsoleteExams = ExamenMinero::query()
            ->where('activo', true)
            ->whereNotIn('id', $currentExamIds)
            ->get(['id']);

        if ($obsoleteExams->isNotEmpty()) {
            ExamenMinero::query()
                ->whereIn('id', $obsoleteExams->pluck('id'))
                ->update([
                    'activo' => false,
                    'updated_by_usuario_id' => $user->id,
                    'updated_at' => now(),
                ]);
            $counts['examenes_catalogo_desactivados'] += $obsoleteExams->count();
        }

        return $obsoleteRequirements->pluck('mina_id')->filter()->unique()->values()->all();
    }

    private function obsoleteRequirementsByMine(array $examCatalog, array $index): array
    {
        $expectedByMine = collect($this->catalogByMine($examCatalog))
            ->mapWithKeys(function (array $catalogRow) use ($index): array {
                $mine = $index['minesByName'][$this->indexKey((string) ($catalogRow['mina'] ?? ''))] ?? null;
                if (!$mine) {
                    return [];
                }

                return [$mine->id => [
                    'exam_ids' => collect($catalogRow['examenes'] ?? [])
                    ->map(fn ($examRow) => $this->matchingExam((string) ($examRow['nombre'] ?? ''), $index)?->id)
                    ->filter()
                    ->unique()
                    ->values(),
                    'names' => collect($catalogRow['examenes'] ?? [])
                        ->pluck('nombre')
                        ->map(fn ($name) => $this->examIndexKey((string) $name))
                        ->filter()
                        ->flip(),
                ]];
            });

        return MinaRequisito::query()
            ->with(['examen:id,nombre', 'mina:id,nombre'])
            ->where('activo', true)
            ->get()
            ->reject(function (MinaRequisito $requirement) use ($expectedByMine): bool {
                $expected = $expectedByMine->get($requirement->mina_id);
                if (!$expected) {
                    return false;
                }
                if (filled($requirement->examen_id) && $expected['exam_ids']->contains($requirement->examen_id)) {
                    return true;
                }

                $name = $requirement->examen?->nombre ?: $requirement->nombre;

                return $expected['names']->has($this->examIndexKey((string) $name));
            })
            ->groupBy('mina_id')
            ->map(function ($requirements): array {
                $first = $requirements->first();

                return [
                    'mina' => $first->mina?->nombre ?: 'Mina sin nombre',
                    'requisitos' => $requirements->map(fn (MinaRequisito $requirement) => [
                        'id' => $requirement->id,
                        'nombre' => $requirement->examen?->nombre ?: $requirement->nombre,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function sharedExamKeys(array $examCatalog): array
    {
        $minesByExam = [];
        foreach ($examCatalog as $catalogRow) {
            $mineKey = $this->indexKey((string) ($catalogRow['mina'] ?? ''));
            if ($mineKey === '') {
                continue;
            }
            foreach ($catalogRow['examenes'] ?? [] as $examRow) {
                $examKey = $this->sharedExamIdentity($examRow);
                if ($examKey !== '') {
                    $minesByExam[$examKey][$mineKey] = true;
                }
            }
        }

        return collect($minesByExam)
            ->filter(fn ($mines) => count($mines) > 1)
            ->map(fn () => true)
            ->all();
    }

    private function sharedExamIdentity(array $examRow): string
    {
        $existingId = trim((string) ($examRow['examen_id_existente'] ?? ''));

        return $existingId !== ''
            ? 'id:' . $existingId
            : 'name:' . $this->examIndexKey((string) ($examRow['nombre'] ?? ''));
    }

    private function missingExamNamesByMine(array $examCatalog): array
    {
        $allNames = collect($examCatalog)
            ->flatMap(fn ($row) => collect($row['examenes'] ?? [])->pluck('nombre'))
            ->filter()
            ->unique(fn ($name) => $this->examIndexKey((string) $name))
            ->values();

        return collect($examCatalog)
            ->map(function ($row) use ($allNames) {
                $present = collect($row['examenes'] ?? [])
                    ->pluck('nombre')
                    ->map(fn ($name) => $this->examIndexKey((string) $name))
                    ->filter()
                    ->flip();
                $missing = $allNames
                    ->reject(fn ($name) => $present->has($this->examIndexKey((string) $name)))
                    ->values();

                return [
                    'hoja' => $row['hoja'] ?? null,
                    'mina' => $row['mina'] ?? null,
                    'nombres' => $missing->all(),
                ];
            })
            ->filter(fn ($row) => !empty($row['nombres']))
            ->values()
            ->all();
    }

    private function countMissingRequirements(array $rows, array $index): int
    {
        $count = 0;
        $seen = [];
        foreach ($rows as $row) {
            $mine = $index['minesByName'][$this->indexKey((string) ($row['mina'] ?? ''))] ?? null;
            foreach ($row['examenes'] ?? [] as $examRow) {
                $key = mb_strtolower((string) ($row['mina'] ?? '') . '|' . (string) ($examRow['nombre'] ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $exam = $this->matchingExam((string) ($examRow['nombre'] ?? ''), $index);
                if (!$mine || !$exam || !isset($index['requirementKeys'][$mine->id . '|' . $exam->id])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countMissingAssignments(array $rows, array $index): int
    {
        return collect($rows)
            ->unique(fn ($row) => ($row['documento'] ?? '') . '|' . ($row['mina'] ?? ''))
            ->filter(function ($row) use ($index): bool {
                $worker = $index['workersByDocument'][$row['documento']] ?? null;
                $mine = $index['minesByName'][$this->indexKey((string) ($row['mina'] ?? ''))] ?? null;

                return $worker && (!$mine || !isset($index['assignmentKeys'][$worker->id . '|' . $mine->id]));
            })
            ->count();
    }

    private function countPriceChanges(array $rows): int
    {
        return collect($rows)
            ->flatMap(fn ($row) => $row['examenes'] ?? [])
            ->filter(fn ($exam) => trim((string) data_get($exam, 'datos.precio', '')) !== '')
            ->count();
    }

    private function countConvalidationHints(array $rows): int
    {
        return collect($rows)
            ->flatMap(fn ($row) => $row['examenes'] ?? [])
            ->filter(fn ($exam) => (bool) data_get($exam, 'preview.convalidacion_sugerida', false))
            ->count();
    }

    private function countMappedExamStates(array $rows, string $state): int
    {
        return collect($rows)
            ->flatMap(fn ($row) => $row['examenes'] ?? [])
            ->filter(fn ($exam) => data_get($exam, 'preview.estado_mapeado') === $state)
            ->count();
    }

    private function conflicts(array $rows, array $index)
    {
        return collect();
    }

    private function similarExamSuggestions(array $rows, array $index): array
    {
        return collect($rows)
            ->flatMap(fn ($row) => $row['examenes'] ?? [])
            ->pluck('nombre')
            ->filter()
            ->unique()
            ->flatMap(fn ($name) => $this->similarExamSuggestionsFor((string) $name, $index))
            ->unique(fn ($item) => $item['detectado'] . '|' . $item['existente'])
            ->values()
            ->take(50)
            ->all();
    }

    private function similarExamSuggestionsFor(string $name, array $index): array
    {
        $normalized = $this->indexKey($name);
        if ($normalized === '' || $this->matchingExam($name, $index)) {
            return [];
        }

        $suggestions = [];
        foreach ($index['examsByName'] as $existingKey => $exam) {
            if ($existingKey === '') {
                continue;
            }
            $distance = levenshtein($normalized, $existingKey);
            $maxLength = max(strlen($normalized), strlen($existingKey), 1);
            $ratio = 1 - ($distance / $maxLength);
            $contains = str_contains($normalized, $existingKey) || str_contains($existingKey, $normalized);
            if (!$contains && $ratio < 0.78) {
                continue;
            }

            $suggestions[] = [
                'detectado' => $name,
                'existente' => $exam->nombre,
                'similitud' => round($contains ? max($ratio, 0.9) : $ratio, 2),
                'accion' => 'SUGERIR_REVISION_MANUAL',
            ];
        }

        usort($suggestions, fn ($a, $b) => $b['similitud'] <=> $a['similitud']);

        return array_slice($suggestions, 0, 3);
    }

    private function normalizeDate(mixed $value): ?string
    {
        $date = PersonalNormalizer::isoDate($value);
        if (!$date) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $year = (int) substr($date, 0, 4);

        return $year >= 1900 && $year <= 2100 ? $date : null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null;
    }

    private function normalizeResult(string $value): ?string
    {
        $value = $this->normalizeHeader($value);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, 'desaprob') || str_contains($value, 'no apt') || str_contains($value, 'rechaz')) {
            return PersonalMinaExamenIntento::RESULTADO_DESAPROBADO;
        }
        if (str_contains($value, 'observ') || str_contains($value, 'restric') || str_contains($value, 'levantar observ')) {
            return PersonalMinaExamenIntento::RESULTADO_PENDIENTE;
        }
        if (str_contains($value, 'aprob') || str_contains($value, 'vigente') || str_contains($value, 'habil') || str_contains($value, 'apto') || str_contains($value, 'vencido') || str_contains($value, 'por vencer')) {
            return PersonalMinaExamenIntento::RESULTADO_APROBADO;
        }
        if (str_contains($value, 'program') || str_contains($value, 'pend') || str_contains($value, 'confirm')) {
            return PersonalMinaExamenIntento::RESULTADO_PENDIENTE;
        }
        if (str_contains($value, 'no asist')) {
            return PersonalMinaExamenIntento::RESULTADO_NO_ASISTIO;
        }

        return null;
    }

    private function stateFromResult(string $result): string
    {
        return match ($result) {
            PersonalMinaExamenIntento::RESULTADO_APROBADO => PersonalMinaExamen::ESTADO_APROBADO,
            PersonalMinaExamenIntento::RESULTADO_DESAPROBADO => PersonalMinaExamen::ESTADO_DESAPROBADO,
            default => PersonalMinaExamen::ESTADO_PENDIENTE,
        };
    }
}
