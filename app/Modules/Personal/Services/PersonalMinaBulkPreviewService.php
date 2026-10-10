<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\ClinicaEmo;
use App\Modules\Personal\Support\EmoOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PersonalMinaBulkPreviewService
{
    public function preview(string $path): array
    {
        return $this->plan($this->read($path));
    }

    public function plan(array $rows): array
    {
        $documents = array_values(array_unique(array_column($rows, 'dni')));
        $workers = Personal::query()->whereIn('dni', $documents)
            ->orWhereIn('numero_documento', $documents)
            ->get(['id', 'dni', 'numero_documento', 'nombre_completo']);
        $exams = ExamenMinero::query()->get();
        $mines = Mina::query()->activeOperational()->get(['id', 'nombre', 'unidad_minera']);
        $clinics = ClinicaEmo::query()->get(['id', 'nombre', 'nombre_normalizado', 'activo']);
        $rows = $this->validateReferences($rows, $workers, $exams, $mines, $clinics);
        $rows = $this->validateMineRequirements($rows);

        $rows = app(PersonalCursoImportPlanner::class)->preview($rows);
        return app(PersonalCursoBulkService::class)->validatePlan($rows, $exams->keyBy('id'));
    }

    public function validateReferences(array $rows, Collection $workers, Collection $exams, ?Collection $mines = null,
        ?Collection $clinics = null): array
    {
        $workerIndex = [];
        foreach ($workers as $worker) {
            foreach (array_unique([$worker->dni, $worker->numero_documento]) as $document) {
                if ($document !== null && $document !== '') {
                    $workerIndex[(string) $document][$worker->id] = $worker;
                }
            }
        }
        $examIndex = $exams->groupBy(fn ($exam) => $this->normalizedName($exam->nombre));
        $mines ??= Mina::query()->activeOperational()->get(['id', 'nombre', 'unidad_minera']);
        $mineIndex = collect();
        foreach ($mines as $mine) {
            foreach (array_unique([$mine->nombre, $mine->unidad_minera]) as $name) {
                if (filled($name)) {
                    $mineIndex->push([$this->normalizedName((string) $name), $mine]);
                }
            }
        }
        $mineIndex = $mineIndex->groupBy(fn (array $item): string => $item[0]);
        $clinics ??= ClinicaEmo::query()->get(['id', 'nombre', 'nombre_normalizado', 'activo']);
        $clinicIndex = $clinics->groupBy(fn (ClinicaEmo $clinic): string => $this->normalizedName($clinic->nombre_normalizado ?: $clinic->nombre));
        foreach ($rows as &$row) {
            $row['categories'] = $row['issues'] ? ['archivo'] : [];
            $matches = collect($workerIndex[$row['dni']] ?? []);
            $row['personal'] = $matches->count() === 1 ? $matches->first()->nombre_completo : null;
            $row['personal_id'] = $matches->count() === 1 ? $matches->first()->id : null;
            if ($matches->count() !== 1) {
                $row['issues'][] = $matches->isEmpty() ? 'DNI no encontrado en Personal.' : 'Documento ambiguo en Personal.';
                $row['categories'][] = 'dni';
            } elseif ($this->normalizedName($row['name']) !== $this->normalizedName($row['personal'])) {
                $row['issues'][] = 'El nombre del Excel no coincide con Personal. Revisar la identidad; no se reemplazara el nombre.';
                $row['categories'][] = 'identidad';
            }
            $mineCandidates = $mineIndex->get($this->normalizedName((string) ($row['mine'] ?? '')), collect())
                ->map(fn (array $item) => $item[1])
                ->unique('id')
                ->values();
            $row['matched_mine'] = $mineCandidates->count() === 1 ? $mineCandidates->first()->nombre : null;
            $row['mina_id'] = $mineCandidates->count() === 1 ? $mineCandidates->first()->id : null;
            if ($mineCandidates->count() !== 1) {
                $row['categories'][] = 'mina';
                $row['issues'][] = trim((string) ($row['mine'] ?? '')) === ''
                    ? 'Falta indicar la mina de destino.'
                    : ($mineCandidates->isEmpty()
                        ? 'Mina no encontrada o no disponible para habilitacion.'
                        : 'Mina ambigua: el nombre coincide con mas de una unidad activa.');
            }
            $identity = app(MiningExamIdentityResolver::class);
            $resolved = $identity->resolve($row['exam'], $row['mina_id'], $exams);
            $row['examen_id'] = $resolved['examen_id'];
            $matchedExam = $exams->firstWhere('id', $resolved['examen_id']);
            $row['matched_exam'] = $matchedExam?->nombre;
            $row['operational_category'] = $matchedExam?->categoria_operativa ?: ExamenMinero::CATEGORIA_CURSO;
            $row['clinic_id'] = null;
            $row['clinic_name'] = null;
            $row['appointment_action'] = null;
            $row['appointment_id'] = null;
            $row['appointment_revision'] = null;
            $row['examenes_parecidos'] = [];
            if ($resolved['status'] !== 'matched') {
                $candidates = $examIndex->get($this->normalizedName($row['exam']), collect());
                $row['categories'][] = 'examen';
                $row['issues'][] = $resolved['status'] === 'ambiguous'
                    ? 'Examen ambiguo: hay varios registros activos con ese nombre.'
                    : ($candidates->isEmpty() ? 'Examen no encontrado en el catalogo.' : 'El examen existe, pero esta inactivo.');
                $row['examenes_parecidos'] = $identity->suggestions($row['exam'], $exams);
                if ($row['examenes_parecidos']) {
                    $row['issues'][] = 'Revisar en Organizar examenes: nombres parecidos a '.implode(', ', array_column($row['examenes_parecidos'], 'nombre')).'. No se asociaron automaticamente.';
                }
            }
            if ($row['operational_category'] === ExamenMinero::CATEGORIA_EMO) {
                if ($row['type'] === 'Resultado' && !in_array($row['result'] ?? '', [
                    'APTO', 'NO APTO', 'APTO CON RESTRICCION', 'APTO CON OBSERVACION',
                ], true)) {
                    $row['issues'][] = 'Seleccione un resultado EMO valido: APTO, NO APTO, APTO CON RESTRICCION o APTO CON OBSERVACION.';
                    $row['categories'][] = 'archivo';
                }
                $clinicName = trim((string) ($row['clinic'] ?? ''));
                if (($row['layout_version'] ?? 1) !== 2) {
                    $row['issues'][] = 'EMO requiere el layout v2 con CLINICA.';
                    $row['categories'][] = 'clinica';
                } elseif ($clinicName === '') {
                    $row['issues'][] = 'La clinica es obligatoria para EMO.';
                    $row['categories'][] = 'clinica';
                } else {
                    $clinicCandidates = $clinicIndex->get($this->normalizedName($clinicName), collect());
                    $active = $clinicCandidates->where('activo', true)->values();
                    if ($active->count() !== 1) {
                        $row['issues'][] = $clinicCandidates->isNotEmpty()
                            ? 'La clinica indicada esta inactiva.'
                            : 'Clinica no encontrada. Use el nombre exacto del catalogo activo.';
                        $row['categories'][] = 'clinica';
                    } else {
                        $row['clinic_id'] = $active->first()->id;
                        $row['clinic_name'] = $active->first()->nombre;
                    }
                }
                if ($row['type'] === 'Resultado' && blank($row['scheduled_date'] ?? null)) {
                    $row['issues'][] = 'El resultado EMO requiere PROGRAMACION para identificar la cita.';
                    $row['categories'][] = 'archivo';
                }
            }
            $row['categories'] = array_values(array_unique($row['categories']));
        }
        unset($row);

        return $rows;
    }

    private function validateMineRequirements(array $rows): array
    {
        $mineIds = collect($rows)->pluck('mina_id')->filter()->unique();
        $examIds = collect($rows)->pluck('examen_id')->filter()->unique();
        $configured = MinaRequisito::query()
            ->where('activo', true)
            ->whereIn('mina_id', $mineIds)
            ->whereIn('examen_id', $examIds)
            ->get(['mina_id', 'examen_id'])
            ->keyBy(fn (MinaRequisito $requirement): string => $requirement->mina_id.'|'.$requirement->examen_id);

        foreach ($rows as &$row) {
            if (($row['mina_id'] ?? null) && ($row['examen_id'] ?? null)
                && !$configured->has($row['mina_id'].'|'.$row['examen_id'])) {
                $row['issues'][] = 'El curso o EMO no esta configurado como requisito activo para la mina indicada.';
                $row['categories'][] = 'mina';
            }
        }
        unset($row);

        return $rows;
    }

    public function report(array $rows): array
    {
        $groups = [];
        foreach (['dni' => 'DNI no reconocidos o ambiguos', 'examen' => 'Examenes no encontrados, inactivos o ambiguos',
            'mina' => 'Minas no reconocidas o sin el requisito configurado',
            'identidad' => 'Nombres por revisar', 'archivo' => 'Fechas, notas y otros datos por corregir',
            'historial' => 'Conflictos de programaciones y resultados'] as $key => $label) {
            $groups[] = ['key' => $key, 'label' => $label, 'count' => count(array_filter($rows,
                fn ($row) => in_array($key, $row['categories'], true)))];
        }

        return ['groups' => $groups, 'with_issues' => count(array_filter($rows, fn ($row) => $row['issues'] !== [])),
            'changes' => count(array_filter($rows, fn ($row) => ($row['disposition'] ?? '') === 'changes')),
            'unchanged' => count(array_filter($rows, fn ($row) => ($row['disposition'] ?? '') === 'unchanged'))];
    }

    private function normalizedName(string $value): string
    {
        return Str::upper(Str::ascii(Str::squish($value)));
    }

    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $info = $reader->listWorksheetInfo($path);
        foreach ($info as $sheet) {
            if ($sheet['totalRows'] > 3001 || $sheet['totalColumns'] > 30) {
                throw ValidationException::withMessages(['archivo' => 'El archivo supera el limite de 3000 filas por hoja o 30 columnas.']);
            }
        }
        if (count($info) !== 2) {
            throw ValidationException::withMessages(['archivo' => 'Se requieren dos hojas: programaciones y resultados, en ese orden.']);
        }
        $book = $reader->load($path);
        try {
            if ($book->getSheetCount() !== 2) {
                throw ValidationException::withMessages(['archivo' => 'Se requieren dos hojas: programaciones y resultados, en ese orden.']);
            }
            $rows = [];
            foreach ($book->getWorksheetIterator() as $index => $sheet) {
                $legacyHeaders = $index === 0
                    ? ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'MINA']
                    : ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'REALIZACION', 'RESULTADO', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'];
                $v2Headers = $index === 0
                    ? ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'CLINICA', 'MINA']
                    : ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'REALIZACION', 'RESULTADO', 'CLINICA', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'];
                $firstHeaders = [];
                for ($column = 1; $column <= count($v2Headers); $column++) {
                    $firstHeaders[] = Str::upper(Str::ascii(trim((string) $sheet->getCell([$column, 1])->getValue())));
                }
                $layoutVersion = array_slice($firstHeaders, 0, count($v2Headers)) === $v2Headers ? 2 : 1;
                $headers = $layoutVersion === 2 ? $v2Headers : $legacyHeaders;
                foreach ($headers as $column => $header) {
                    if (Str::upper(Str::ascii(trim((string) $sheet->getCell([$column + 1, 1])->getValue()))) !== $header) {
                        throw ValidationException::withMessages(['archivo' => 'Encabezado incorrecto en '.$sheet->getTitle().': se esperaba '.$header.'.']);
                    }
                }
                $seen = [];
                for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
                    $values = [];
                    foreach ($headers as $column => $header) {
                        $values[] = $sheet->getCell([$column + 1, $line])->getValue();
                    }
                    if (count(array_filter($values, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }
                    $issues = [];
                    $dni = trim((string) $values[0]);
                    if (!preg_match('/^\d{8}$/', $dni)) {
                        $issues[] = 'DNI invalido: debe tener 8 digitos. Use formato texto para conservar ceros iniciales.';
                    }
                    if (trim((string) $values[1]) === '') {
                        $issues[] = 'Falta el nombre del trabajador.';
                    }
                    $expiryColumn = $index === 1 ? ($layoutVersion === 2 ? 8 : 6) : null;
                    foreach ($values as $column => $value) {
                        if (is_string($value) && str_starts_with($value, '=') && $column !== $expiryColumn) {
                            $issues[] = 'Formula no permitida en '.$headers[$column].'. Use un valor, no una formula.';
                        }
                    }
                    $scoreColumn = $index === 1 ? ($layoutVersion === 2 ? 7 : 5) : null;
                    if ($scoreColumn !== null && ($values[$scoreColumn] ?? '') !== '' && $values[$scoreColumn] !== null
                        && (!is_numeric($values[$scoreColumn]) || !is_finite((float) $values[$scoreColumn]) || (float) $values[$scoreColumn] < 0)) {
                        $issues[] = 'La nota debe ser un numero no negativo.';
                    }
                    $exam = trim((string) $values[2]);
                    if ($exam === '') {
                        $issues[] = 'Falta curso o examen.';
                    }
                    $scheduledDate = $index === 0 || $layoutVersion === 2 ? $this->date($values[3]) : null;
                    $performedDate = $index === 1 ? $this->date($values[$layoutVersion === 2 ? 4 : 3]) : null;
                    $date = $index === 0 ? $scheduledDate : $performedDate;
                    if (!$date) {
                        $issues[] = 'Fecha invalida; use una fecha Excel o dd/mm/aaaa.';
                    } elseif ($index === 1 && $date > now()->toDateString()) {
                        $issues[] = 'La realizacion no puede estar en el futuro.';
                    }
                    $mine = trim((string) $values[count($headers) - 1]);
                    if ($mine === '') {
                        $issues[] = 'Falta indicar la mina de destino.';
                    }
                    $key = $dni.'|'.$this->normalizedName($exam).'|'.$this->normalizedName($mine).'|'.$date;
                    if (isset($seen[$key])) {
                        $issues[] = 'Fila duplicada en esta hoja.';
                        $rows[$seen[$key]]['issues'][] = 'Fila duplicada en esta hoja.';
                    }
                    $seen[$key] = count($rows);
                    $expiry = null;
                    if ($index === 1 && $values[$expiryColumn] !== null && $values[$expiryColumn] !== '') {
                        $raw = $values[$expiryColumn];
                        // Only this template's local date addition is evaluated, never arbitrary formulas.
                        $performedColumnLetter = $layoutVersion === 2 ? 'E' : 'D';
                        $performedRaw = $values[$layoutVersion === 2 ? 4 : 3];
                        if (is_string($raw) && preg_match('/^=\+?'.$performedColumnLetter.$line.'\+365$/i', $raw) && is_numeric($performedRaw)) {
                            $raw = (float) $performedRaw + 365;
                        }
                        $expiry = $this->date($raw);
                        if (!$expiry || ($date && $expiry < $date)) {
                            $issues[] = 'Vencimiento invalido o anterior a la realizacion.';
                        }
                    }
                    $resultColumn = $layoutVersion === 2 ? 5 : 4;
                    $observationColumn = $layoutVersion === 2 ? 9 : 7;
                    $clinicColumn = $layoutVersion === 2 ? ($index === 0 ? 4 : 6) : null;
                    $rawResult = $index === 1 ? trim((string) $values[$resultColumn]) : '';
                    $result = Str::upper(Str::ascii($rawResult));
                    $medical = EmoOutcome::fromLegacyText($rawResult);
                    if ($medical) {
                        $result = str_replace('_', ' ', $medical['outcome']->value);
                    }
                    $observation = $index === 1 ? trim((string) ($values[$observationColumn] ?? '')) : '';
                    if ($observation === '' && filled($medical['detail'] ?? null)) {
                        $observation = $medical['detail'];
                    }
                    if ($index === 1 && !in_array($result, ['APTO', 'APROBADO', 'DESAPROBADO', 'NO APTO', 'APTO CON RESTRICCION', 'APTO CON OBSERVACION'], true)) {
                        $issues[] = 'Resultado pendiente de revision: '.$result;
                    }
                    if (in_array($result, ['APTO CON RESTRICCION', 'APTO CON OBSERVACION'], true)
                        && $observation === '') {
                        $issues[] = 'El resultado EMO con restriccion u observacion requiere detalle.';
                    }
                    $rows[] = ['sheet' => $sheet->getTitle(), 'line' => $line, 'type' => $index === 0 ? 'Programacion' : 'Resultado',
                        'layout_version' => $layoutVersion, 'dni' => $dni, 'name' => (string) $values[1],
                        'exam' => $exam, 'mine' => $mine, 'clinic' => $clinicColumn === null ? '' : trim((string) ($values[$clinicColumn] ?? '')),
                        'scheduled_date' => $scheduledDate, 'performed_date' => $performedDate, 'date' => $date,
                        'result' => $result, 'score' => $scoreColumn === null ? null : ($values[$scoreColumn] ?? null), 'expiry' => $expiry,
                        'observation' => $observation, 'issues' => $issues];
                }
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        foreach (['!d/m/Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim((string) $value));
            if ($date && $date->format(substr($format, 1)) === trim((string) $value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
