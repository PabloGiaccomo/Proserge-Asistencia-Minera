<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SimpleXMLElement;
use ZipArchive;

class ImportPersonalService
{
    private const MAX_PUESTO_LENGTH = 120;

    private const MAX_CHANGE_DETAILS = 200;

    private const DEFAULT_COLUMNS = [
        'dni' => 3,
        'nombre' => 5,
        'puesto' => 6,
        'fecha_ingreso' => 8,
        'ocupacion' => 1,
        'contrato' => 0,
        'correo' => null,
    ];

    private const PHONE_ALIASES = [
        'celularparticular',
        'celular',
        'cel',
        'telefono',
        'telefono1',
        'telefono2',
        'telefonoparticular',
        'telefonocelular',
        'contacto',
        'contactotelefonico',
        'fono',
        'movil',
        'movil1',
        'movil2',
    ];

    private const FIXED_HEADERS = [
        'N',
        'NO',
        'NRO',
        'CONTRATO',
        'OCUPACION',
        'CC',
        'DNI',
        'DNI CONTEO',
        'APELLIDOS Y NOMBRES',
        'NOMBRES',
        'APELLIDOS',
        'NOMBRE',
        'CARGO',
        'PUESTO',
        'CARGO PUESTO',
        'CARGO GENERAL',
        'FECHA INGRESO',
        'FECHA INGRESO INICIAL',
        'FIN DE CONTRATO',
        'CELULAR',
        'CELULAR PARTICULAR',
        'TELEFONO',
        'CORREO',
        'EMAIL',
    ];

    public function import(UploadedFile $file): array
    {
        $rows = $this->readRows($file);

        if (count($rows) < 2) {
            throw new \RuntimeException('El archivo no contiene filas de datos.');
        }

        [$headers, $rawDataRows] = $this->resolveHeadersAndDataRows($rows);

        $dataRows = array_values(array_filter($rawDataRows, function (array $row): bool {
            foreach ($row as $cell) {
                if (PersonalNormalizer::text($cell) !== '') {
                    return true;
                }
            }

            return false;
        }));

        $columns = $this->detectColumns($headers);
        $detectedMines = $this->detectMineColumns($headers, $dataRows, $columns);
        $mineSync = $this->syncMines($detectedMines);
        $mineMap = $mineSync['map'];

        return DB::transaction(function () use ($dataRows, $columns, $mineMap, $mineSync): array {
            $hasTelefonoColumn = Schema::hasColumn('personal', 'telefono');
            $hasTelefono1Column = Schema::hasColumn('personal', 'telefono_1');
            $hasTelefono2Column = Schema::hasColumn('personal', 'telefono_2');

            $stats = [
                'nuevos' => 0,
                'reactivados' => 0,
                'inactivados' => 0,
                'puestosActualizados' => 0,
                'duplicados' => 0,
                'omitidos' => 0,
                'minasDetectadas' => array_values(array_map(fn (array $mine) => $mine['nombre'], $mineMap)),
                'minasActivasDetectadas' => count($mineMap),
                'minasCreadas' => (int) ($mineSync['stats']['creadas'] ?? 0),
                'minasReutilizadas' => (int) ($mineSync['stats']['reutilizadas'] ?? 0),
                'minasActualizadas' => (int) ($mineSync['stats']['actualizadas'] ?? 0),
                'relacionesMinaCreadas' => 0,
                'relacionesMinaActualizadas' => 0,
                'relacionesMinaCreadasOActualizadas' => 0,
                'relacionesMinaEliminadas' => 0,
                'actualizados' => 0,
                'camposActualizados' => 0,
                'telefonosDetectados' => 0,
                'trabajadoresCon1Telefono' => 0,
                'trabajadoresCon2Telefonos' => 0,
                'telefonosCasosInvalidosLimpios' => 0,
                'telefonosCasosOmitidos' => 0,
                'telefonosConMasDeDos' => 0,
                'cambiosDetectadosTotal' => 0,
                'cambiosDetectados' => [],
                'nuevosDetalle' => [],
                'reactivadosDetalle' => [],
                'inactivadosDetalle' => [],
            ];

            $existingSelect = [
                'id',
                'dni',
                'nombre_completo',
                'puesto',
                'ocupacion',
                'contrato',
                'es_supervisor',
                'fecha_ingreso',
                'estado',
            ];

            if ($hasTelefonoColumn) {
                $existingSelect[] = 'telefono';
            }

            if ($hasTelefono1Column) {
                $existingSelect[] = 'telefono_1';
            }

            if ($hasTelefono2Column) {
                $existingSelect[] = 'telefono_2';
            }

            if (Schema::hasColumn('personal', 'correo')) {
                $existingSelect[] = 'correo';
            }

            $existing = Personal::query()
                ->get($existingSelect)
                ->keyBy('dni');

            $processedDni = [];
            $activeDbDni = Personal::query()->where('estado', 'ACTIVO')->pluck('dni')->all();

            foreach ($dataRows as $row) {
                $dni = PersonalNormalizer::dni($row[$columns['dni']] ?? null);
                if (!PersonalNormalizer::isValidDni($dni)) {
                    $stats['omitidos']++;
                    continue;
                }

                if (isset($processedDni[$dni])) {
                    $stats['duplicados']++;
                    continue;
                }
                $processedDni[$dni] = true;

                $nombre = PersonalNormalizer::text($row[$columns['nombre']] ?? '') ?: 'Sin nombre';
                $puesto = PersonalNormalizer::text($row[$columns['puesto']] ?? '') ?: 'Sin puesto';
                $puesto = mb_substr($puesto, 0, self::MAX_PUESTO_LENGTH);
                $ocupacion = PersonalNormalizer::text($row[$columns['ocupacion']] ?? '');
                $contrato = PersonalNormalizer::contract($row[$columns['contrato']] ?? null);
                $fechaIngreso = PersonalNormalizer::isoDate($row[$columns['fecha_ingreso']] ?? null);
                $isSupervisor = PersonalNormalizer::isSupervisorOccupation($ocupacion);
                $phoneRaw = $this->extractPhoneRaw($row, $columns);
$phoneData = PersonalNormalizer::normalizePhonePayload($phoneRaw);

                if ($phoneData['valid_count'] === 1) {
                    $stats['trabajadoresCon1Telefono']++;
                }

                if ($phoneData['valid_count'] === 2) {
                    $stats['trabajadoresCon2Telefonos']++;
                }

                if ($phoneData['valid_count'] > 0) {
                    $stats['telefonosDetectados'] += (int) $phoneData['valid_count'];
                }

                if ($phoneData['had_invalid_cleanup'] || $phoneData['had_duplicates']) {
                    $stats['telefonosCasosInvalidosLimpios']++;
                }

                if ($phoneData['raw_has_content'] && $phoneData['valid_count'] === 0) {
                    $stats['telefonosCasosOmitidos']++;
                }

                if ($phoneData['had_more_than_two']) {
                    $stats['telefonosConMasDeDos']++;

                    Log::warning('Import Personal: se detectaron mas de dos telefonos, se conservaron solo dos.', [
                        'dni' => $dni,
                        'raw' => $phoneData['raw'] ?? null,
                        'telefonos_detectados' => $phoneData['all_valid_numbers'] ?? [],
                    ]);
                }

                $personal = $existing->get($dni);

                if ($personal) {
                    $updates = [];
                    $workerChanges = [];

                    if (strtoupper((string) $personal->estado) === 'INACTIVO') {
                        $updates['estado'] = 'ACTIVO';
                        $stats['reactivados']++;
                        $this->registerFieldChange($workerChanges, $stats, 'estado', 'Estado', $personal->estado, 'ACTIVO');

                        if (count($stats['reactivadosDetalle']) < self::MAX_CHANGE_DETAILS) {
                            $stats['reactivadosDetalle'][] = [
                                'dni' => $dni,
                                'nombre' => $nombre,
                                'antes' => 'INACTIVO',
                                'despues' => 'ACTIVO',
                            ];
                        }
                    }

                    if ($personal->nombre_completo !== $nombre) {
                        $updates['nombre_completo'] = $nombre;
                        $this->registerFieldChange($workerChanges, $stats, 'nombre_completo', 'Nombre', $personal->nombre_completo, $nombre);
                    }

                    if ($personal->puesto !== $puesto) {
                        $updates['puesto'] = $puesto;
                        $stats['puestosActualizados']++;
                        $this->registerFieldChange($workerChanges, $stats, 'puesto', 'Cargo/Puesto', $personal->puesto, $puesto);
                    }

                    if ((string) $personal->ocupacion !== $ocupacion) {
                        $updates['ocupacion'] = $ocupacion ?: null;
                        $this->registerFieldChange($workerChanges, $stats, 'ocupacion', 'Ocupación', $personal->ocupacion, $ocupacion ?: null);
                    }

                    if ((string) $personal->contrato !== $contrato) {
                        $updates['contrato'] = $contrato;
                        $this->registerFieldChange(
                            $workerChanges,
                            $stats,
                            'contrato',
                            'Contrato',
                            PersonalNormalizer::contractLabel($personal->contrato),
                            PersonalNormalizer::contractLabel($contrato)
                        );
                    }

                    if ((bool) $personal->es_supervisor !== $isSupervisor) {
                        $updates['es_supervisor'] = $isSupervisor;
                        $this->registerFieldChange($workerChanges, $stats, 'es_supervisor', 'Supervisor', $personal->es_supervisor ? 'Sí' : 'No', $isSupervisor ? 'Sí' : 'No');
                    }

                    if ($fechaIngreso !== null && optional($personal->fecha_ingreso)->toDateString() !== $fechaIngreso) {
                        $updates['fecha_ingreso'] = $fechaIngreso;
                        $this->registerFieldChange($workerChanges, $stats, 'fecha_ingreso', 'Fecha ingreso', optional($personal->fecha_ingreso)->toDateString(), $fechaIngreso);
                    }

                    $oldCombinedPhone = PersonalNormalizer::combinePhones(
                        $personal->telefono_1 ?? null,
                        $personal->telefono_2 ?? null
) ?? ($personal->telefono ?? null);
                    $newCombinedPhone = PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']);

                    if ((string) ($oldCombinedPhone ?? '') !== (string) ($newCombinedPhone ?? '')) {
                        $this->registerFieldChange($workerChanges, $stats, 'telefono', 'Teléfono', $oldCombinedPhone, $newCombinedPhone);
                    }

                    if ((string) ($personal->telefono ?? '') !== (string) ($newCombinedPhone ?? '')) {
                        $updates['telefono'] = $newCombinedPhone;
                    }

                    if ($hasTelefono1Column && (string) ($personal->telefono_1 ?? '') !== (string) ($phoneData['telefono_1'] ?? '')) {
                        $updates['telefono_1'] = $phoneData['telefono_1'];
                    }

                    if ($hasTelefono2Column && (string) ($personal->telefono_2 ?? '') !== (string) ($phoneData['telefono_2'] ?? '')) {
                        $updates['telefono_2'] = $phoneData['telefono_2'];
                    }

                    if (Schema::hasColumn('personal', 'correo') && isset($columns['correo']) && $columns['correo'] !== null) {
                        $correo = PersonalNormalizer::text($row[$columns['correo']] ?? '') ?: null;
                        if ((string) ($personal->correo ?? '') !== (string) ($correo ?? '')) {
                            $updates['correo'] = $correo;
                            $this->registerFieldChange($workerChanges, $stats, 'correo', 'Correo', $personal->correo, $correo);
                        }
                    }

if (count($updates) > 0) {
                        Personal::query()->where('id', $personal->id)->update($updates);
                        $personal->fill($updates);
                        $stats['actualizados']++;
                        $stats['cambiosDetectadosTotal']++;

                        if (count($stats['cambiosDetectados']) < self::MAX_CHANGE_DETAILS) {
                            $stats['cambiosDetectados'][] = [
                                'dni' => $dni,
                                'nombre' => $nombre,
                                'cambios' => array_values($workerChanges),
                            ];
                        }
                    }
                    $this->syncMineStatuses($personal, $row, $mineMap, $stats, $workerChanges);
                } else {
                    $newData = [
                        'id' => (string) Str::uuid(),
                        'dni' => $dni,
                        'nombre_completo' => $nombre,
                        'puesto' => $puesto,
                        'ocupacion' => $ocupacion ?: null,
                        'contrato' => $contrato,
                        'es_supervisor' => $isSupervisor,
                        'qr_code' => 'QR-' . $dni . '-' . Str::upper(Str::random(8)),
                        'fecha_ingreso' => $fechaIngreso,
                        'estado' => 'ACTIVO',
                    ];

                    if ($hasTelefonoColumn) {
                        $newData['telefono'] = PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']);
                    }

                    if ($hasTelefono1Column) {
                        $newData['telefono_1'] = $phoneData['telefono_1'];
                    }

                    if ($hasTelefono2Column) {
                        $newData['telefono_2'] = $phoneData['telefono_2'];
                    }

                    $personal = Personal::query()->create($newData);

                    $existing->put($dni, $personal);
                    $stats['nuevos']++;

if (count($stats['nuevosDetalle']) < self::MAX_CHANGE_DETAILS) {
                        $stats['nuevosDetalle'][] = [
                            'dni' => $dni,
                            'nombre' => $nombre,
                            'puesto' => $puesto,
                            'ocupacion' => $ocupacion ?: '-',
                            'contrato' => PersonalNormalizer::contractLabel($contrato),
                        ];
                    }

                    $workerChanges = [];
                    $this->syncMineStatuses($personal, $row, $mineMap, $stats, $workerChanges);
                }
            }

            foreach ($activeDbDni as $dbDni) {
                if (!isset($processedDni[$dbDni])) {
                    Personal::query()->where('dni', $dbDni)->update(['estado' => 'INACTIVO']);
                    $stats['inactivados']++;

                    $inactivated = $existing->get($dbDni);
                    if ($inactivated && count($stats['inactivadosDetalle']) < self::MAX_CHANGE_DETAILS) {
                        $stats['inactivadosDetalle'][] = [
                            'dni' => $dbDni,
                            'nombre' => $inactivated->nombre_completo,
                            'antes' => 'ACTIVO',
                            'despues' => 'INACTIVO',
                        ];
                    }
                }
            }

            return $stats;
        });
    }

    private function resolveHeadersAndDataRows(array $rows): array
    {
        $maxScan = min(10, count($rows));
        $bestIndex = 0;
        $bestScore = -1;

        for ($i = 0; $i < $maxScan; $i++) {
            $candidate = array_map(fn ($item) => PersonalNormalizer::text($item), $rows[$i] ?? []);
            $score = $this->scoreHeaderCandidate($candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }

        $headerRow = array_map(fn ($item) => PersonalNormalizer::text($item), $rows[$bestIndex] ?? []);
        $dataRows = array_slice($rows, $bestIndex + 1);

        return [$headerRow, $dataRows];
    }

    private function scoreHeaderCandidate(array $candidate): int
    {
        $score = 0;
        $found = [
            'dni' => false,
            'nombre' => false,
            'puesto' => false,
            'fecha_ingreso' => false,
            'ocupacion' => false,
            'contrato' => false,
        ];

        foreach ($candidate as $cell) {
            $upper = strtoupper(PersonalNormalizer::text($cell));
            if ($upper === '') {
                continue;
            }

            if (!$found['dni'] && str_contains($upper, 'DNI')) {
                $found['dni'] = true;
                $score++;
            }

            if (!$found['nombre'] && (str_contains($upper, 'NOMBRE') || str_contains($upper, 'APELLIDO'))) {
                $found['nombre'] = true;
                $score++;
            }

            if (!$found['puesto'] && (str_contains($upper, 'PUESTO') || str_contains($upper, 'CARGO'))) {
                $found['puesto'] = true;
                $score++;
            }

            if (!$found['fecha_ingreso'] && str_contains($upper, 'FECHA') && str_contains($upper, 'INGRESO')) {
                $found['fecha_ingreso'] = true;
                $score++;
            }

            if (!$found['ocupacion'] && str_contains($upper, 'OCUP')) {
                $found['ocupacion'] = true;
                $score++;
            }

            if (!$found['contrato'] && str_contains($upper, 'CONTRATO')) {
                $found['contrato'] = true;
                $score++;
            }
        }

        return $score;
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            try {
                // Prefer XML cached values for XLSX to avoid formula-evaluation failures.
                return $this->readXlsxRowsFallback($file->getRealPath());
            } catch (\Throwable) {
                if (!class_exists(IOFactory::class)) {
                    throw new \RuntimeException('No se pudo procesar el XLSX en este entorno.');
                }
            }
        }

        if (class_exists(IOFactory::class)) {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $this->resolveSheet($spreadsheet);

            return $sheet->toArray(null, false, true, false);
        }

        throw new \RuntimeException('No hay lector disponible para este tipo de archivo.');
    }

    private function resolveSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        foreach ($spreadsheet->getSheetNames() as $name) {
            if (strtoupper(PersonalNormalizer::text($name)) === 'RESUMEN GRAL') {
                return $spreadsheet->getSheetByName($name);
            }
        }

        return $spreadsheet->getSheet(0);
    }

    private function readXlsxRowsFallback(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            $zip->close();
            throw new \RuntimeException('Estructura XLSX invalida.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetPath = $this->resolveSheetPath($workbookXml, $relsXml);
        $sheetXml = $zip->getFromName($sheetPath);

        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('No se encontro una hoja valida en el XLSX.');
        }

        return $this->parseSheetRows($sheetXml, $sharedStrings);
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml === false) {
            return [];
        }

        $xml = simplexml_load_string($sharedXml);
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $items = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $items[] = (string) $si->t;
                continue;
            }

            $parts = [];
            foreach ($si->r as $r) {
                $parts[] = (string) $r->t;
            }
            $items[] = implode('', $parts);
        }

        return $items;
    }

    private function resolveSheetPath(string $workbookXml, string $relsXml): string
    {
        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (!$workbook instanceof SimpleXMLElement || !$rels instanceof SimpleXMLElement) {
            throw new \RuntimeException('No se pudo leer la estructura del libro XLSX.');
        }

        $workbook->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = $workbook->xpath('//s:sheets/s:sheet') ?: [];
        if (count($sheets) === 0) {
            throw new \RuntimeException('El libro XLSX no tiene hojas.');
        }

        $selected = $sheets[0];
        foreach ($sheets as $sheet) {
            $name = strtoupper(trim((string) ($sheet['name'] ?? '')));
            if ($name === 'RESUMEN GRAL') {
                $selected = $sheet;
                break;
            }
        }

        $ridAttr = $selected->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($ridAttr['id'] ?? '');
        if ($rid === '') {
            throw new \RuntimeException('No se pudo resolver la hoja seleccionada.');
        }

        foreach ($rels->Relationship as $rel) {
            $id = (string) ($rel['Id'] ?? '');
            if ($id !== $rid) {
                continue;
            }

            $target = (string) ($rel['Target'] ?? '');
            $target = ltrim($target, '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }

        throw new \RuntimeException('No se encontro la ruta de la hoja XLSX.');
    }

    private function parseSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $xml = simplexml_load_string($sheetXml);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->sheetData)) {
            throw new \RuntimeException('No se pudo leer las filas del XLSX.');
        }

        $rows = [];
        $maxCols = 0;

        foreach ($xml->sheetData->row as $row) {
            $current = [];

            foreach ($row->c as $cell) {
                $cellRef = (string) ($cell['r'] ?? '');
                $colIndex = $this->columnIndexFromCellRef($cellRef);
                $type = (string) ($cell['t'] ?? '');

                $value = null;
                if ($type === 's') {
                    $sharedIndex = (int) ($cell->v ?? 0);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }

                $current[$colIndex] = $value;
                $maxCols = max($maxCols, $colIndex + 1);
            }

            if (count($current) > 0) {
                ksort($current);
                $rows[] = $current;
            }
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $line = array_fill(0, $maxCols, null);
            foreach ($row as $index => $value) {
                $line[(int) $index] = $value;
            }
            $normalizedRows[] = $line;
        }

        return $normalizedRows;
    }

    private function columnIndexFromCellRef(string $cellRef): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
        if ($letters === '') {
            return 0;
        }

        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    private function detectColumns(array $headers): array
    {
        $indexes = [
            'dni' => null,
            'nombre' => null,
            'puesto' => null,
            'fecha_ingreso' => null,
            'ocupacion' => null,
            'contrato' => null,
            'telefonos' => [],
            'correo' => null,
        ];

        $preferredCell = $headers[12] ?? null;
        if ($preferredCell !== null && in_array(PersonalNormalizer::normalizeKey(PersonalNormalizer::text($preferredCell)), self::PHONE_ALIASES, true)) {
            $indexes['telefonos'][] = 12;
        }

        foreach ($headers as $index => $header) {
            $upper = strtoupper(PersonalNormalizer::text($header));
            if ($upper === '') {
                continue;
            }

            if ($indexes['dni'] === null && str_contains($upper, 'DNI')) {
                $indexes['dni'] = $index;
            }

            if ($indexes['nombre'] === null && (str_contains($upper, 'NOMBRE') || str_contains($upper, 'APELLIDO'))) {
                $indexes['nombre'] = $index;
            }

            if ($indexes['puesto'] === null && (str_contains($upper, 'PUESTO') || str_contains($upper, 'CARGO'))) {
                $indexes['puesto'] = $index;
            }

            if ($indexes['fecha_ingreso'] === null && str_contains($upper, 'FECHA') && str_contains($upper, 'INGRESO')) {
                $indexes['fecha_ingreso'] = $index;
            }

            if ($indexes['ocupacion'] === null && str_contains($upper, 'OCUP')) {
                $indexes['ocupacion'] = $index;
            }

            if ($indexes['contrato'] === null && str_contains($upper, 'CONTRATO')) {
                $indexes['contrato'] = $index;
            }

$normalizedHeader = PersonalNormalizer::normalizeKey($upper);
            $normalizedHeaderLower = mb_strtolower($normalizedHeader);
            $isPhoneColumn = false;

            if (in_array($normalizedHeaderLower, self::PHONE_ALIASES, true)) {
                $isPhoneColumn = true;
            }

            if (!$isPhoneColumn && $upper !== '' && (
                str_starts_with($upper, 'CEL') ||
                str_starts_with($upper, 'TEL') ||
                str_starts_with($upper, 'FON') ||
                str_starts_with($upper, 'MOV')
            )) {
                $isPhoneColumn = true;
            }

            if ($isPhoneColumn && !in_array($index, $indexes['telefonos'], true)) {
                $indexes['telefonos'][] = $index;
            }

            if ($indexes['correo'] === null && (str_contains($upper, 'CORREO') || str_contains($upper, 'EMAIL'))) {
                $indexes['correo'] = $index;
            }
        }

        foreach (self::DEFAULT_COLUMNS as $key => $fallback) {
            if ($indexes[$key] === null) {
                $indexes[$key] = $fallback;
            }
        }

        if (count($indexes['telefonos']) === 0 && isset($headers[12])) {
            $indexes['telefonos'][] = 12;
        }

        return $indexes;
    }

    private function extractPhoneRaw(array $row, array $columns): ?string
    {
        $values = [];

        foreach (($columns['telefonos'] ?? []) as $index) {
            if (!is_int($index)) {
                continue;
            }

            $value = PersonalNormalizer::text($row[$index] ?? null);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        if (count($values) === 0) {
            return null;
        }

        return implode(' / ', $values);
    }

    private function registerFieldChange(array &$workerChanges, array &$stats, string $key, string $label, mixed $before, mixed $after): void
    {
        $beforeText = $this->formatChangeValue($before);
        $afterText = $this->formatChangeValue($after);

        if ($beforeText === $afterText) {
            return;
        }

        $workerChanges[] = [
            'campo' => $key,
            'label' => $label,
            'antes' => $beforeText,
            'despues' => $afterText,
        ];

        $stats['camposActualizados']++;
    }

    private function formatChangeValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        $text = trim((string) $value);

        return $text === '' ? '-' : $text;
    }

    private function detectMineColumns(array $headers, array $rows, array $coreColumns): array
    {
        $fixedHeaders = array_flip(array_map(
            fn (string $item) => PersonalNormalizer::normalizeKey($item),
            self::FIXED_HEADERS
        ));

        $coreIndexSet = array_flip(array_values(array_filter($coreColumns, static fn ($value) => is_int($value))));
        $mineColumns = [];
        $seenMineKeys = [];

        foreach ($headers as $index => $header) {
            $label = PersonalNormalizer::text($header);
            if ($label === '') {
                continue;
            }

            if (isset($coreIndexSet[$index])) {
                continue;
            }

            if (isset($fixedHeaders[PersonalNormalizer::normalizeKey($label)])) {
                continue;
            }

            if (!$this->looksLikeMineColumn($rows, $index)) {
                continue;
            }

            $mineKey = PersonalNormalizer::normalizeKey($label);
            if ($mineKey === '' || isset($seenMineKeys[$mineKey])) {
                continue;
            }

            $seenMineKeys[$mineKey] = true;

            $mineColumns[] = [
                'indice' => $index,
                'nombre' => $label,
            ];
        }

        return $mineColumns;
    }

    private function looksLikeMineColumn(array $rows, int $index): bool
    {
        $sample = array_slice($rows, 0, 80);
        $filled = 0;
        $validStatuses = 0;

        foreach ($sample as $row) {
            $value = PersonalNormalizer::text($row[$index] ?? null);
            if ($value === '') {
                continue;
            }

            $filled++;
            if (PersonalNormalizer::mineStatus($value) !== null) {
                $validStatuses++;
            }
        }

        if ($filled === 0) {
            return false;
        }

        return $validStatuses > 0 && ($validStatuses / $filled) >= 0.2;
    }

    private function syncMines(array $detectedColumns): array
    {
        $created = 0;
        $reused = 0;
        $updated = 0;

        $dbMines = Mina::query()->get(['id', 'nombre', 'unidad_minera', 'estado']);

        $byKey = [];
        foreach ($dbMines as $mine) {
            $byKey[PersonalNormalizer::normalizeKey((string) $mine->unidad_minera)] = $mine;
            $byKey[PersonalNormalizer::normalizeKey((string) $mine->nombre)] = $mine;
        }

        $excelKeys = [];
        $map = [];

        foreach ($detectedColumns as $detected) {
            $name = $detected['nombre'];
            $key = PersonalNormalizer::normalizeKey($name);
            if ($key === '') {
                continue;
            }

            $excelKeys[$key] = true;

            $mine = $byKey[$key] ?? null;
            if ($mine) {
                $reused++;

                $nextData = [
                    'nombre' => $name,
                    'unidad_minera' => $name,
                    'estado' => 'ACTIVO',
                ];

                $needsUpdate = (string) $mine->nombre !== $nextData['nombre']
                    || (string) $mine->unidad_minera !== $nextData['unidad_minera']
                    || strtoupper((string) $mine->estado) !== $nextData['estado'];

                if ($needsUpdate) {
                    $mine->fill($nextData)->save();
                    $updated++;
                }
            } else {
                $mine = Mina::query()->create([
                    'id' => (string) Str::uuid(),
                    'nombre' => $name,
                    'unidad_minera' => $name,
                    'ubicacion' => 'Por definir',
                    'estado' => 'ACTIVO',
                ]);

                $created++;
            }

            $map[] = [
                'id' => $mine->id,
                'nombre' => $mine->nombre,
                'indice' => $detected['indice'],
            ];
        }

        foreach ($dbMines as $mine) {
            $key = PersonalNormalizer::normalizeKey((string) ($mine->unidad_minera ?: $mine->nombre));
            if ($key !== '' && !isset($excelKeys[$key])) {
                if (strtoupper((string) $mine->estado) !== 'INACTIVO') {
                    $mine->update(['estado' => 'INACTIVO']);
                }
            }
        }

        return [
            'map' => $map,
            'stats' => [
                'creadas' => $created,
                'reutilizadas' => $reused,
                'actualizadas' => $updated,
            ],
        ];
    }

    private function syncMineStatuses(Personal $personal, array $row, array $mineMap, array &$stats, array &$workerChanges = []): void
    {
        foreach ($mineMap as $mine) {
            $cellValue = $row[$mine['indice']] ?? null;
            $cellStatus = PersonalNormalizer::mineStatus($cellValue);

            $relation = PersonalMina::query()
                ->where('personal_id', $personal->id)
                ->where('mina_id', $mine['id'])
                ->first();

            $beforeStatus = $relation?->estado;

            if ($cellStatus === null || $cellStatus === 'NO_HABILITADO') {
                $deleted = PersonalMina::query()
                    ->where('personal_id', $personal->id)
                    ->where('mina_id', $mine['id'])
                    ->delete();

                $stats['relacionesMinaEliminadas'] += $deleted;

                if ($deleted > 0) {
                    $this->registerFieldChange(
                        $workerChanges,
                        $stats,
                        'mina_' . $mine['id'],
                        'Mina ' . $mine['nombre'],
                        PersonalNormalizer::mineStatusLabel($beforeStatus),
                        'Sin relación'
                    );
                }

                continue;
            }

            if ($relation) {
                if ($relation->estado !== $cellStatus) {
                    $relation->estado = $cellStatus;
                    $relation->save();
                    $stats['relacionesMinaActualizadas']++;
                    $stats['relacionesMinaCreadasOActualizadas']++;
                    $this->registerFieldChange(
                        $workerChanges,
                        $stats,
                        'mina_' . $mine['id'],
                        'Mina ' . $mine['nombre'],
                        PersonalNormalizer::mineStatusLabel($beforeStatus),
                        PersonalNormalizer::mineStatusLabel($cellStatus)
                    );
                }
            } else {
                PersonalMina::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'mina_id' => $mine['id'],
                    'estado' => $cellStatus,
                ]);

                $stats['relacionesMinaCreadas']++;
                $stats['relacionesMinaCreadasOActualizadas']++;
                $this->registerFieldChange(
                    $workerChanges,
                    $stats,
                    'mina_' . $mine['id'],
                    'Mina ' . $mine['nombre'],
                    'Sin relación',
                    PersonalNormalizer::mineStatusLabel($cellStatus)
                );
            }
        }
    }
}
