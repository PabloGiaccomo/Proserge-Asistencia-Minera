<?php

namespace App\Modules\Personal\Services;

use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

class PersonalFichaMacroExtractor
{
    private array $aliases = [
        'tipo_documento' => ['tipodocumento', 'tipodedocumento', 'documentotipo'],
        'numero_documento' => ['identificador', 'dni', 'documento', 'nrodocumento', 'numerodocumento', 'documentodeidentidad', 'nrodni', 'numdni', 'carnedeextranjeria', 'pasaporte'],
        'nombre_completo' => ['apellidosynombres', 'nombresyapellidos', 'trabajador', 'colaborador', 'nombrecompleto', 'apellidosnombres'],
        'nombres' => ['nombres', 'nombre'],
        'apellido_paterno' => ['apellidopaterno', 'apellidopaterno'],
        'apellido_materno' => ['apellidomaterno', 'apellidomaterno'],
        'sexo' => ['sexo', 'genero'],
        'grupo_sanguineo' => ['gruposanguineo', 'gsanguin', 'gsanguineo', 'tiposangre'],
        'brevete' => ['brevete', 'nrobrevete', 'numerobrevete'],
        'estado_civil' => ['estadocivil'],
        'nacionalidad' => ['nacionalidad'],
        'fecha_nacimiento' => ['fechanacimiento', 'fechadenacimiento', 'fecnacimiento'],
        'pais_nacimiento' => ['paisnacimiento', 'paisdenacimiento'],
        'departamento_nacimiento' => ['departamentonacimiento', 'dptonacimiento'],
        'provincia_nacimiento' => ['provincianacimiento'],
        'distrito_nacimiento' => ['distritonacimiento'],
        'telefono' => ['celular', 'celularparticular', 'telefono', 'telefonocelular', 'contacto', 'movil'],
        'correo' => ['correo', 'email', 'correoelectronico'],
        'domicilio_direccion' => ['domiciliocolaborador', 'domicilio', 'direccion', 'direccionactual', 'domicilioactual'],
        'domicilio_departamento' => ['departamento', 'dpto'],
        'domicilio_provincia' => ['provincia'],
        'domicilio_distrito' => ['distritocolaborador', 'distrito'],
        'puesto' => ['cargo', 'puesto', 'cargopuesto', 'cargoaplicar'],
        'ocupacion' => ['ocupacion'],
        'contrato' => ['contrato', 'tipocontrato', 'tipodecontrato', 'modalidadcontrato'],
        'fecha_ingreso' => ['fechaingreso', 'fechadeingreso', 'inicio', 'fechainicio', 'fechainiciocontrato'],
        'fecha_fin_contrato' => ['fechafincontrato', 'fincontrato', 'fechatermino', 'fechafin', 'vencimientocontrato'],
        'unidad_minera' => ['mina', 'unidadminera', 'sede', 'centrodetrabajo'],
        'area' => ['area'],
        'banco' => ['banco', 'entidadbancaria'],
        'numero_cuenta' => ['cuenta', 'numerocuenta', 'nrocuenta', 'cuentabancaria'],
        'cci' => ['cci', 'codigocuentainterbancaria'],
        'grado_instruccion' => ['gradoinstruccion', 'gradodeinstruccion', 'nivelestudios'],
        'profesion_oficio' => ['profesionuoficio', 'profesionoficio', 'profesion', 'oficio'],
        'especialidad' => ['especialidad'],
        'carrera' => ['carrera'],
        'anio_egreso' => ['anioegreso', 'anodeegreso', 'egreso'],
        'anio_experiencia' => ['anioexperiencia', 'anosexperiencia', 'experiencia'],
        'institucion' => ['institucion', 'centroestudios'],
        'talla_polo' => ['tallapolo', 'tallacamisa', 'polo', 'camisa'],
        'talla_pantalon' => ['tallapantalon', 'pantalon'],
        'talla_zapato' => ['tallazapato', 'zapato', 'calzado'],
        'talla_respirador' => ['tallarespirador', 'respirador'],
    ];

    public function extract(UploadedFile $file): array
    {
        $warnings = [];
        $rows = [];
        $text = '';
        $extension = strtolower((string) $file->getClientOriginalExtension());

        try {
            if (in_array($extension, ['xlsx', 'xlsm', 'xls', 'csv'], true)) {
                $rows = $this->readSpreadsheetRows($file);
                $text = $this->rowsToText($rows);
            } elseif ($extension === 'docx') {
                $text = $this->readDocxText($file->getRealPath());
            } elseif (in_array($extension, ['txt', 'csv'], true)) {
                $text = (string) file_get_contents($file->getRealPath());
            } elseif ($extension === 'pdf') {
                $text = $this->readPdfFallback($file->getRealPath());
                $warnings[] = 'El PDF se leyo con extraccion basica. Si el documento esta escaneado, RRHH debe completar los campos faltantes.';
            } else {
                $text = (string) file_get_contents($file->getRealPath());
                $warnings[] = 'Formato no optimizado para extraccion automatica; se intentara leer texto plano.';
            }
        } catch (\Throwable $exception) {
            $warnings[] = 'No se pudo leer automaticamente el archivo: ' . $exception->getMessage();
        }

        $detected = PersonalFichaCatalog::emptyData();

        if ($rows !== []) {
            $detected = $this->mergeDetected($detected, $this->extractFromHeaderRows($rows));
            $detected = $this->mergeDetected($detected, $this->extractFromKeyValueRows($rows));
        }

        $detected = $this->mergeDetected($detected, $this->extractFromText($text));
        $detected['contrato'] = $this->detectContractType($file, $rows, $detected);
        $detected = $this->normalizeDetected($detected);

        $missing = collect(PersonalFichaCatalog::requiredKeys())
            ->filter(fn (string $key): bool => trim((string) ($detected[$key] ?? '')) === '')
            ->values()
            ->all();

        if (($detected['tipo_documento'] ?? '') === 'DNI' && !PersonalNormalizer::isValidDni((string) ($detected['numero_documento'] ?? ''))) {
            $warnings[] = 'El numero de DNI detectado no tiene 8 digitos. Revisalo antes de generar el link.';
        }

        if (($detected['telefono'] ?? '') !== '') {
            $phoneData = PersonalNormalizer::normalizePhonePayload($detected['telefono']);
            if (($phoneData['had_more_than_two'] ?? false) === true) {
                $warnings[] = 'Se detectaron mas de dos telefonos. Solo se conservaron los dos primeros numeros validos.';
            }
        }

        return [
            'fields' => $detected,
            'missing' => $missing,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'contract_summary' => $this->contractSummary($detected['contrato'] ?? null),
            'source_text_preview' => mb_substr($text, 0, 2000),
        ];
    }

    public function extractMany(UploadedFile $file): array
    {
        $warnings = [];
        $rows = [];
        $text = '';
        $extension = strtolower((string) $file->getClientOriginalExtension());

        try {
            if (in_array($extension, ['xlsx', 'xlsm', 'xls', 'csv'], true)) {
                $rows = $this->readSpreadsheetRows($file);
                $text = $this->rowsToText($rows);
            }
        } catch (\Throwable $exception) {
            $warnings[] = 'No se pudo leer automaticamente el archivo: ' . $exception->getMessage();
        }

        if ($rows === []) {
            $single = $this->extract($file);

            return [
                'items' => [[
                    ...$single,
                    'row_number' => 1,
                ]],
                'warnings' => $single['warnings'],
                'source_text_preview' => $single['source_text_preview'],
            ];
        }

        $items = collect($this->extractAllFromHeaderRows($rows, $file))
            ->take(200)
            ->values()
            ->all();

        if ($items === []) {
            $single = $this->extract($file);
            $items = [[
                ...$single,
                'row_number' => 1,
            ]];
        }

        if (count($items) >= 200) {
            $warnings[] = 'Se procesaron los primeros 200 trabajadores del archivo para evitar una carga demasiado pesada.';
        }

        return [
            'items' => $items,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'source_text_preview' => mb_substr($text, 0, 2000),
        ];
    }

    private function readSpreadsheetRows(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);

        foreach ($spreadsheet->getSheetNames() as $name) {
            if (str_contains(PersonalNormalizer::normalizeKey($name), 'resumen')) {
                $sheet = $spreadsheet->getSheetByName($name) ?: $sheet;
                break;
            }
        }

        return $sheet->toArray(null, false, true, false);
    }

    private function readDocxText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();

        $xml = preg_replace('/<w:tab\/>/', ' ', $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function readPdfFallback(string $path): string
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/[^\PC\s]/u', ' ', $raw) ?? $raw;
        $raw = preg_replace('/[^A-Za-z0-9@\.\-\_\/:\s]/', ' ', $raw) ?? $raw;

        return preg_replace('/\s+/', ' ', $raw) ?? $raw;
    }

    private function rowsToText(array $rows): string
    {
        return collect($rows)
            ->map(fn (array $row): string => collect($row)->map(fn ($value): string => PersonalNormalizer::text($value))->filter()->implode(' | '))
            ->filter()
            ->implode("\n");
    }

    private function extractFromHeaderRows(array $rows): array
    {
        $header = $this->bestHeaderMap($rows);
        if ($header === null) {
            return [];
        }

        for ($i = $header['index'] + 1; $i < count($rows); $i++) {
            $row = $rows[$i] ?? [];
            $filled = collect($row)->filter(fn ($value): bool => PersonalNormalizer::text($value) !== '')->count();
            if ($filled === 0) {
                continue;
            }

            $detected = $this->detectedFromMappedRow($row, $header['map']);

            if (count($detected) > 0) {
                return $this->normalizeTemplateRow($this->expandFullName($detected));
            }
        }

        return [];
    }

    private function extractAllFromHeaderRows(array $rows, UploadedFile $file): array
    {
        $header = $this->bestHeaderMap($rows);
        if ($header === null) {
            return [];
        }

        $items = [];
        for ($i = $header['index'] + 1; $i < count($rows); $i++) {
            $row = $rows[$i] ?? [];
            $filled = collect($row)->filter(fn ($value): bool => PersonalNormalizer::text($value) !== '')->count();
            if ($filled === 0) {
                continue;
            }

            $detected = $this->normalizeTemplateRow($this->expandFullName($this->detectedFromMappedRow($row, $header['map'])));
            if (PersonalNormalizer::text($detected['numero_documento'] ?? '') === '' && PersonalNormalizer::text($detected['nombre_completo'] ?? '') === '' && PersonalNormalizer::text($detected['nombres'] ?? '') === '') {
                continue;
            }

            $detected['contrato'] = $this->detectContractType($file, $rows, $detected);
            $fields = $this->normalizeDetected($detected);
            $itemWarnings = [];

            if (($fields['tipo_documento'] ?? '') === 'DNI' && !PersonalNormalizer::isValidDni((string) ($fields['numero_documento'] ?? ''))) {
                $itemWarnings[] = 'El numero de DNI detectado no tiene 8 digitos.';
            }

            $items[] = [
                'row_number' => $i + 1,
                'fields' => $fields,
                'missing' => collect(PersonalFichaCatalog::requiredKeys())
                    ->filter(fn (string $key): bool => trim((string) ($fields[$key] ?? '')) === '')
                    ->values()
                    ->all(),
                'warnings' => $itemWarnings,
                'contract_summary' => $this->contractSummary($fields['contrato'] ?? null),
                'source_text_preview' => '',
            ];
        }

        return $items;
    }

    private function bestHeaderMap(array $rows): ?array
    {
        $best = ['index' => null, 'score' => 0, 'map' => []];
        $scanLimit = min(count($rows), 20);

        for ($i = 0; $i < $scanLimit; $i++) {
            $map = [];
            foreach (($rows[$i] ?? []) as $column => $cell) {
                $field = $this->fieldFromLabel((string) $cell);
                if ($field !== null) {
                    $map[$column] = $field;
                }
            }

            if (count($map) > $best['score']) {
                $best = ['index' => $i, 'score' => count($map), 'map' => $map];
            }
        }

        if ($best['index'] === null || $best['score'] < 2) {
            return null;
        }

        return $best;
    }

    private function detectedFromMappedRow(array $row, array $map): array
    {
        $detected = [];
        foreach ($map as $column => $field) {
            $value = PersonalNormalizer::text($row[$column] ?? null);
            if ($value !== '') {
                $detected[$field] = $value;
            }
        }

        return $detected;
    }

    private function extractFromKeyValueRows(array $rows): array
    {
        $detected = [];

        foreach ($rows as $row) {
            $cells = array_values(array_map(fn ($value): string => PersonalNormalizer::text($value), $row));
            $count = count($cells);

            for ($i = 0; $i < $count; $i++) {
                $cell = $cells[$i] ?? '';
                if ($cell === '') {
                    continue;
                }

                [$label, $inlineValue] = $this->splitInlinePair($cell);
                $field = $this->fieldFromLabel($label);
                if ($field === null) {
                    continue;
                }

                $value = $inlineValue;
                if ($value === '') {
                    if ($this->looksLikeHeaderRow($cells)) {
                        continue;
                    }

                    for ($j = $i + 1; $j < min($count, $i + 4); $j++) {
                        if (($cells[$j] ?? '') !== '') {
                            $value = $cells[$j];
                            break;
                        }
                    }
                }

                if ($value !== '') {
                    $detected[$field] = $value;
                }
            }
        }

        return $this->normalizeTemplateRow($this->expandFullName($detected));
    }

    private function extractFromText(string $text): array
    {
        $detected = [];
        $normalizedText = PersonalNormalizer::text($text);

        if ($normalizedText === '') {
            return [];
        }

        foreach (preg_split('/\R+/', $normalizedText) ?: [] as $line) {
            [$label, $value] = $this->splitInlinePair((string) $line);
            $field = $this->fieldFromLabel($label);
            if ($field !== null && $value !== '') {
                $detected[$field] = $value;
            }
        }

        if (empty($detected['correo']) && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $normalizedText, $match)) {
            $detected['correo'] = $match[0];
        }

        if (empty($detected['telefono'])) {
            foreach (preg_split('/\R+/', $normalizedText) ?: [] as $line) {
                if (!preg_match('/(celular|telefono|movil)/i', (string) $line)) {
                    continue;
                }

                $phoneData = PersonalNormalizer::normalizePhonePayload((string) $line);
                $detected['telefono'] = PersonalNormalizer::combinePhones($phoneData['telefono_1'] ?? null, $phoneData['telefono_2'] ?? null) ?? '';
                break;
            }
        }

        if (empty($detected['numero_documento']) && preg_match('/\b\d{8}\b/', $normalizedText, $match)) {
            $detected['tipo_documento'] = 'DNI';
            $detected['numero_documento'] = $match[0];
        }

        if (empty($detected['contrato'])) {
            $key = PersonalNormalizer::normalizeKey($normalizedText);
            foreach (['indeterminado' => 'INDET', 'intermitente' => 'INTER', 'servicioespecifico' => 'FIJO', 'personalfijo' => 'FIJO', 'bajoregimen' => 'REG'] as $needle => $contract) {
                if (str_contains($key, $needle)) {
                    $detected['contrato'] = $contract;
                    break;
                }
            }
        }

        return $this->normalizeTemplateRow($this->expandFullName($detected));
    }

    private function normalizeDetected(array $detected): array
    {
        $data = PersonalFichaCatalog::emptyData();

        foreach ($detected as $key => $value) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $data[$key] = PersonalNormalizer::text($value);
        }

        $data['numero_documento'] = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');
        $data['tipo_documento'] = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento']);
        $data['contrato'] = PersonalNormalizer::contract($data['contrato'] ?? null);

        foreach (['fecha_nacimiento', 'fecha_ingreso'] as $dateField) {
            $data[$dateField] = PersonalNormalizer::isoDate($data[$dateField] ?? null) ?? '';
        }

        if (($data['telefono'] ?? '') !== '') {
            $phoneData = PersonalNormalizer::normalizePhonePayload($data['telefono']);
            $data['telefono'] = PersonalNormalizer::combinePhones($phoneData['telefono_1'] ?? null, $phoneData['telefono_2'] ?? null) ?? '';
        }

        if (($data['correo'] ?? '') !== '') {
            $data['correo'] = mb_strtolower($data['correo']);
        }

        if (($data['domicilio_tipo'] ?? '') === '') {
            $data['domicilio_tipo'] = 'Peru';
        }

        return $data;
    }

    private function mergeDetected(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (PersonalNormalizer::text($value) === '') {
                continue;
            }

            if (PersonalNormalizer::text($base[$key] ?? '') !== '') {
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function looksLikeHeaderRow(array $cells): bool
    {
        $matches = 0;

        foreach ($cells as $cell) {
            if ($this->fieldFromLabel($cell) !== null) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    private function normalizeTemplateRow(array $detected): array
    {
        $districtText = PersonalNormalizer::text($detected['domicilio_distrito'] ?? '');
        if ($districtText !== '') {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+/', $districtText) ?: [])));
            if (count($parts) >= 3) {
                $detected['domicilio_distrito'] = $parts[0];
                $detected['domicilio_provincia'] = $detected['domicilio_provincia'] ?? $parts[1];
                $detected['domicilio_departamento'] = $detected['domicilio_departamento'] ?? $parts[2];
            }
        }

        if (!empty($detected['domicilio_direccion']) || !empty($detected['domicilio_distrito'])) {
            $detected['domicilio_tipo'] = $detected['domicilio_tipo'] ?? 'Peru';
        }

        return $detected;
    }

    private function detectContractType(UploadedFile $file, array $rows, array $detected): string
    {
        $name = PersonalNormalizer::normalizeKey($file->getClientOriginalName());
        $headers = collect($rows[0] ?? [])
            ->map(fn ($value): string => PersonalNormalizer::normalizeKey((string) $value))
            ->filter()
            ->implode('|');

        if (str_contains($name, 'indet') || str_contains($headers, 'fechainicio|sueldonum')) {
            return 'INDET';
        }

        if (str_contains($name, 'inter') || str_contains($headers, 'sueldohoraparadas') || str_contains($headers, 'sueldodiataller')) {
            return 'INTER';
        }

        if (str_contains($name, 'se') || str_contains($headers, 'fechafincontrato')) {
            return 'FIJO';
        }

        return (string) ($detected['contrato'] ?? 'REG');
    }

    private function fieldFromLabel(string $label): ?string
    {
        $key = PersonalNormalizer::normalizeKey($label);
        if ($key === '') {
            return null;
        }

        foreach ($this->aliases as $field => $aliases) {
            if (in_array($key, $aliases, true)) {
                return $field;
            }
        }

        if (str_starts_with($key, 'fecha')) {
            return null;
        }

        foreach ($this->aliases as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($alias !== '' && str_contains($key, $alias)) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function splitInlinePair(string $cell): array
    {
        if (str_contains($cell, ':')) {
            [$label, $value] = explode(':', $cell, 2);

            return [trim($label), trim($value)];
        }

        return [$cell, ''];
    }

    private function expandFullName(array $detected): array
    {
        $fullName = PersonalNormalizer::text($detected['nombre_completo'] ?? '');
        if ($fullName === '') {
            return $detected;
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $parts = array_values(array_filter($parts));

        if (count($parts) >= 3) {
            $detected['apellido_paterno'] = $detected['apellido_paterno'] ?? $parts[0];
            $detected['apellido_materno'] = $detected['apellido_materno'] ?? $parts[1];
            $detected['nombres'] = $detected['nombres'] ?? implode(' ', array_slice($parts, 2));
        } elseif (count($parts) > 0) {
            $detected['nombres'] = $detected['nombres'] ?? $fullName;
        }

        unset($detected['nombre_completo']);

        return $detected;
    }

    private function contractSummary(?string $contract): string
    {
        return PersonalNormalizer::contractLabel($contract);
    }
}
