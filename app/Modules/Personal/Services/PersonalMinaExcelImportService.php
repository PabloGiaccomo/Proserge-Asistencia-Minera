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
        @set_time_limit(300);

        $spreadsheet = $this->loadSpreadsheetForPreview($file);
        $rows = [];
        $unmapped = [];
        $errors = [];
        $omitted = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
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
            for ($rowIndex = $headerRow + 1; $rowIndex <= count($grid); $rowIndex++) {
                $row = $grid[$rowIndex] ?? [];
                if ($this->rowIsBlank($row)) {
                    continue;
                }

                $document = $this->cleanDocument($row[$columns['documento']] ?? '');
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
                    'trabajador_existe' => false,
                    'nombre' => PersonalNormalizer::text($row[$columns['nombre']] ?? '') ?: null,
                    'cargo' => PersonalNormalizer::text($row[$columns['cargo']] ?? '') ?: null,
                    'area' => PersonalNormalizer::text($row[$columns['area']] ?? '') ?: null,
                    'telefono' => PersonalNormalizer::text($row[$columns['telefono']] ?? '') ?: null,
                    'estado_laboral' => PersonalNormalizer::text($row[$columns['estado_laboral']] ?? '') ?: null,
                    'estado_habilitacion' => PersonalNormalizer::text($row[$columns['estado_habilitacion']] ?? '') ?: null,
                    'examenes' => $examData,
                ];
            }
        }

        $index = $this->previewDatabaseIndex($rows);
        $rows = $this->decoratePreviewRows($rows, $index);

        return $this->buildPreviewSummary($rows, $errors, $omitted, $unmapped, $index);
    }

    public function confirm(array $preview, Usuario $user): array
    {
        @set_time_limit(600);

        $counts = [
            'trabajadores_creados' => 0,
            'trabajadores_actualizados' => 0,
            'minas_creadas' => 0,
            'examenes_creados' => 0,
            'examenes_mina_agregados' => 0,
            'asignaciones_creadas' => 0,
            'examenes_trabajador_actualizados' => 0,
            'intentos_importados' => 0,
            'intentos_omitidos_duplicados' => 0,
            'estados_habilitacion_actualizados' => 0,
            'filas_omitidas' => 0,
        ];

        DB::transaction(function () use ($preview, $user, &$counts): void {
            $workerCache = [];
            $mineCache = [];
            $examCache = [];
            $requirementCache = [];
            $assignmentCache = [];

            foreach ($preview['rows'] ?? [] as $row) {
                if (empty($row['documento'])) {
                    $counts['filas_omitidas']++;
                    continue;
                }

                $personal = $this->resolveWorkerCached($row, $counts, $workerCache);
                $mina = $this->resolveMineCached((string) ($row['mina'] ?? ''), $counts, $mineCache);
                if (!$mina) {
                    $counts['filas_omitidas']++;
                    continue;
                }

                $resolvedExams = [];
                foreach ($row['examenes'] ?? [] as $examRow) {
                    $exam = $this->resolveExamCached($examRow, $counts, $user, $examCache);
                    $this->applyPriceFromImport($exam, $examRow['datos'] ?? [], $user);
                    $this->resolveRequirementCached($mina, $exam, $counts, $requirementCache);
                    $resolvedExams[] = [$exam, $examRow];
                }

                $assignment = $this->resolveAssignmentCached($personal, $mina, $user, $counts, $assignmentCache);
                if ($resolvedExams) {
                    $this->habilitation->generateRequiredExams($assignment, $user);
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
            }
        });

        return $counts;
    }

    private function loadSpreadsheetForPreview(UploadedFile $file)
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $sheetNames = collect($reader->listWorksheetNames($file->getRealPath()))
            ->reject(fn ($name) => in_array(\Illuminate\Support\Str::ascii(mb_strtolower(trim((string) $name))), ['td', 'resumen gral'], true))
            ->values()
            ->all();
        if ($sheetNames) {
            $reader->setLoadSheetsOnly($sheetNames);
        }
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= 2000 && Coordinate::columnIndexFromString($columnAddress) <= 120;
            }
        });

        return $reader->load($file->getRealPath());
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
                $columns['documento'] = $column;
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

            $subfield = $this->detectExamSubfield($normalized ?: $combined);
            if ($subfield && $parentNormalized !== '' && !$this->isWorkerDataHeader($parentNormalized)) {
                $examName = mb_substr(PersonalNormalizer::text($parent), 0, 191);
                $columns['examenes'][$examName][$subfield] = $column;
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
        $normalized = $this->normalizeHeader($name);
        $aliases = [
            'marcobre' => 'MARCOBRE',
            'cerro verde' => 'CERRO VERDE',
            'chinalco' => 'CHINALCO',
            'cuajone' => 'CUAJONE',
            'toquepala' => 'TOQUEPALA',
            'orcopampa' => 'ORCOPAMPA',
            'boroo' => 'BOROO',
        ];

        foreach ($aliases as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
        }

        return mb_substr($name, 0, 191);
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

    private function previewDatabaseIndex(array $rows): array
    {
        $workerDocs = collect($rows)->pluck('documento')->filter()->unique()->values();
        $mineNames = collect($rows)->pluck('mina')->filter()->unique()->values();
        $examNames = collect($rows)->flatMap(fn ($row) => collect($row['examenes'] ?? [])->pluck('nombre'))->filter()->unique()->values();

        $workersByDocument = [];
        if ($workerDocs->isNotEmpty()) {
            Personal::query()
                ->whereIn('numero_documento', $workerDocs)
                ->orWhereIn('dni', $workerDocs)
                ->get(['id', 'nombre_completo', 'numero_documento', 'dni'])
                ->each(function (Personal $worker) use (&$workersByDocument): void {
                    foreach ([$worker->numero_documento, $worker->dni] as $document) {
                        $document = $this->cleanDocument((string) $document);
                        if ($document !== '') {
                            $workersByDocument[$document] = $worker;
                        }
                    }
                });
        }

        $minesByName = Mina::query()
            ->get(['id', 'nombre'])
            ->mapWithKeys(fn (Mina $mine) => [$this->indexKey($mine->nombre) => $mine])
            ->all();

        $examsByName = ExamenMinero::query()
            ->where('activo', true)
            ->get(['id', 'nombre'])
            ->mapWithKeys(fn (ExamenMinero $exam) => [$this->indexKey($exam->nombre) => $exam])
            ->all();

        $mineIds = $mineNames
            ->map(fn ($name) => $minesByName[$this->indexKey((string) $name)]?->id ?? null)
            ->filter()
            ->unique()
            ->values();
        $examIds = $examNames
            ->map(fn ($name) => $examsByName[$this->indexKey((string) $name)]?->id ?? null)
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

            foreach ($row['examenes'] as &$examRow) {
                $examRow['existe'] = isset($index['examsByName'][$this->indexKey((string) ($examRow['nombre'] ?? ''))]);
            }
            unset($examRow);
        }
        unset($row);

        return $rows;
    }

    private function buildPreviewSummary(array $rows, array $errors, array $omitted, array $unmapped, array $index): array
    {
        $workerDocs = collect($rows)->pluck('documento')->filter()->unique();
        $mineNames = collect($rows)->pluck('mina')->filter()->unique();
        $examNames = collect($rows)->flatMap(fn ($row) => collect($row['examenes'] ?? [])->pluck('nombre'))->filter()->unique();
        $conflicts = $this->conflicts($rows, $index);

        return [
            'token' => (string) Str::uuid(),
            'generated_at' => now()->toDateTimeString(),
            'summary' => [
                'trabajadores_nuevos' => $workerDocs->filter(fn ($doc) => !isset($index['workersByDocument'][$doc]))->count(),
                'trabajadores_existentes' => $workerDocs->filter(fn ($doc) => isset($index['workersByDocument'][$doc]))->count(),
                'minas_nuevas' => $mineNames->filter(fn ($name) => !isset($index['minesByName'][$this->indexKey((string) $name)]))->count(),
                'minas_existentes' => $mineNames->filter(fn ($name) => isset($index['minesByName'][$this->indexKey((string) $name)]))->count(),
                'examenes_nuevos' => $examNames->filter(fn ($name) => !isset($index['examsByName'][$this->indexKey((string) $name)]))->count(),
                'examenes_existentes' => $examNames->filter(fn ($name) => isset($index['examsByName'][$this->indexKey((string) $name)]))->count(),
                'examenes_agregados_a_minas' => $this->countMissingRequirements($rows, $index),
                'trabajadores_asignados_a_minas' => $this->countMissingAssignments($rows, $index),
                'filas_con_error' => count($errors),
                'filas_omitidas' => count($omitted),
                'datos_no_mapeados' => count($unmapped),
                'conflictos' => $conflicts->count(),
                'cambios_precio_detectados' => $this->countPriceChanges($rows),
            ],
            'rows' => $rows,
            'errors' => $errors,
            'omitted' => $omitted,
            'unmapped' => array_slice($unmapped, 0, 200),
            'conflicts' => $conflicts->values()->all(),
        ];
    }

    private function resolveWorker(array $row, array &$counts): Personal
    {
        $personal = Personal::query()
            ->where('numero_documento', $row['documento'])
            ->orWhere('dni', $row['documento'])
            ->first();

        if ($personal) {
            $updates = [];
            if (!$personal->nombre_completo && !empty($row['nombre'])) {
                $updates['nombre_completo'] = $row['nombre'];
            }
            if (!$personal->puesto && !empty($row['cargo'])) {
                $updates['puesto'] = $row['cargo'];
            }
            if (!$personal->telefono && !empty($row['telefono'])) {
                $updates['telefono'] = $row['telefono'];
            }
            if ($updates) {
                $personal->forceFill($updates)->save();
                $counts['trabajadores_actualizados']++;
            }

            return $personal;
        }

        $counts['trabajadores_creados']++;

        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => $row['documento'],
            'tipo_documento' => 'DNI',
            'numero_documento' => $row['documento'],
            'nombre_completo' => $row['nombre'] ?: 'SIN NOMBRE ' . $row['documento'],
            'puesto' => $row['cargo'] ?: 'SIN CARGO IMPORTADO',
            'ocupacion' => $row['cargo'] ?: 'SIN CARGO IMPORTADO',
            'contrato' => $row['estado_laboral'] ?: null,
            'qr_code' => 'QR-' . Str::upper(Str::random(12)),
            'telefono' => $row['telefono'] ?: null,
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
            'origen_registro' => 'IMPORTADO',
        ]);
    }

    private function resolveWorkerCached(array $row, array &$counts, array &$cache): Personal
    {
        $key = $this->cleanDocument((string) ($row['documento'] ?? ''));
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveWorker($row, $counts);
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
        $exam = ExamenMinero::query()
            ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($name)])
            ->where('activo', true)
            ->first();
        if ($exam) {
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
        $key = $this->indexKey((string) ($examRow['nombre'] ?? ''));
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveExam($examRow, $counts, $user);
    }

    private function resolveRequirement(Mina $mine, ExamenMinero $exam, array &$counts): MinaRequisito
    {
        $requirement = MinaRequisito::query()
            ->where('mina_id', $mine->id)
            ->where('examen_id', $exam->id)
            ->where('activo', true)
            ->first();
        if ($requirement) {
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
            'permite_convalidacion_mina' => (bool) $exam->permite_convalidacion,
        ]);
    }

    private function resolveRequirementCached(Mina $mine, ExamenMinero $exam, array &$counts, array &$cache): MinaRequisito
    {
        $key = $mine->id . '|' . $exam->id;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        return $cache[$key] = $this->resolveRequirement($mine, $exam, $counts);
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
        ], $user);
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

        if (!$result && !$payload['fecha_programacion'] && !$payload['fecha_realizacion'] && !$payload['fecha_vencimiento'] && !$payload['observacion'] && $payload['nota'] === null) {
            return null;
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

        $currentAttempts = $attempts
            ->where('resultado', '!=', PersonalMinaExamenIntento::RESULTADO_ANULADO)
            ->count();
        $nextAttempt = $currentAttempts + 1;
        $maxAttempts = max(1, (int) ($workerExam->max_intentos_snapshot ?: 2));
        if ($nextAttempt > $maxAttempts) {
            return false;
        }

        DB::table('personal_mina_examen_intentos')->insert([
            'id' => (string) Str::uuid(),
            'personal_mina_examen_id' => $workerExam->id,
            'numero_intento' => $nextAttempt,
            'fecha_programacion' => $payload['fecha_programacion'],
            'fecha_realizacion' => $payload['fecha_realizacion'],
            'resultado' => $payload['resultado'],
            'nota' => $payload['nota'],
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
        if ($state === PersonalMinaExamen::ESTADO_NO_APLICA && !$observation) {
            $observation = 'No aplica confirmado desde Excel master.';
        }

        DB::table('personal_mina_examenes')
            ->where('id', $workerExam->id)
            ->update([
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
        if (str_contains($raw, 'observ')) {
            return PersonalMinaExamen::ESTADO_OBSERVADO;
        }
        if (str_contains($raw, 'vencido')) {
            return PersonalMinaExamen::ESTADO_VENCIDO;
        }
        if (str_contains($raw, 'por vencer')) {
            return PersonalMinaExamen::ESTADO_POR_VENCER;
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
            str_contains($value, 'desaprob') => PersonalMina::ESTADO_FINALIZADO_POR_DESAPROBACION,
            str_contains($value, 'proceso') || str_contains($value, 'pend') => PersonalMina::ESTADO_EN_PROCESO,
            default => null,
        };
    }

    private function applyPriceFromImport(ExamenMinero $exam, array $values, Usuario $user): void
    {
        $price = $this->numericOrNull($values['precio'] ?? null);
        if ($price === null) {
            return;
        }

        $date = $this->normalizeDate($values['fecha_realizacion'] ?? null)
            ?: $this->normalizeDate($values['fecha_programacion'] ?? null)
            ?: Carbon::today()->toDateString();

        $exists = $exam->precios()
            ->where('precio', $price)
            ->where('fecha_inicio', $date)
            ->exists();
        if ($exists) {
            return;
        }

        $this->habilitation->storeExamPrice($exam, [
            'precio' => $price,
            'moneda' => 'PEN',
            'fecha_inicio' => $date,
            'observacion' => 'Precio detectado desde Excel master.',
        ], $user);
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
            || str_contains($value, 'area')
            || str_contains($value, 'telefono')
            || str_contains($value, 'contrato');
    }

    private function detectExamSubfield(string $value): ?string
    {
        return match (true) {
            str_contains($value, 'program') || preg_match('/\benv\b/u', $value) => 'fecha_programacion',
            str_contains($value, 'realizacion') || str_contains($value, 'fecha examen') || preg_match('/\breal\b/u', $value) => 'fecha_realizacion',
            str_contains($value, 'venc') || str_contains($value, 'vto') => 'fecha_vencimiento',
            str_contains($value, 'resultado') => 'resultado',
            str_contains($value, 'estado') => 'estado',
            str_contains($value, 'observ') => 'observacion',
            str_contains($value, 'sede') || str_contains($value, 'clinica') || str_contains($value, 'lugar') => 'lugar',
            str_contains($value, 'nota') => 'nota',
            str_contains($value, 'precio') || str_contains($value, 'costo') => 'precio',
            default => null,
        };
    }

    private function rowIsBlank(array $row): bool
    {
        return !collect($row)->contains(fn ($value) => trim((string) $value) !== '');
    }

    private function cleanDocument(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function indexKey(string $value): string
    {
        return $this->normalizeHeader($value);
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

                $exam = $index['examsByName'][$this->indexKey((string) ($examRow['nombre'] ?? ''))] ?? null;
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

                return !$worker || !$mine || !isset($index['assignmentKeys'][$worker->id . '|' . $mine->id]);
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

    private function conflicts(array $rows, array $index)
    {
        return collect($rows)
            ->filter(function ($row) use ($index): bool {
                $worker = $index['workersByDocument'][$row['documento']] ?? null;

                return $worker && !empty($row['nombre']) && $worker->nombre_completo && Str::ascii(mb_strtolower($worker->nombre_completo)) !== Str::ascii(mb_strtolower($row['nombre']));
            })
            ->map(fn ($row) => [
                'hoja' => $row['hoja'],
                'fila' => $row['fila'],
                'documento' => $row['documento'],
                'motivo' => 'El nombre del Excel difiere del registrado.',
            ]);
    }

    private function normalizeDate(mixed $value): ?string
    {
        return PersonalNormalizer::isoDate($value);
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
