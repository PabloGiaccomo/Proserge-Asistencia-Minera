<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
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
        $rows = $this->validateReferences($rows, $workers, $exams, $mines);
        $rows = $this->validateMineRequirements($rows);

        $rows = app(PersonalCursoImportPlanner::class)->preview($rows);
        return app(PersonalCursoBulkService::class)->validatePlan($rows, $exams->keyBy('id'));
    }

    public function validateReferences(array $rows, Collection $workers, Collection $exams, ?Collection $mines = null): array
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
            $candidates = $examIndex->get($this->normalizedName($row['exam']), collect());
            $active = $candidates->filter(fn ($exam) => $exam->activo);
            $row['matched_exam'] = $active->count() === 1 ? $active->first()->nombre : null;
            $row['examen_id'] = $active->count() === 1 ? $active->first()->id : null;
            if ($active->count() !== 1) {
                $row['categories'][] = 'examen';
                $row['issues'][] = $candidates->isEmpty() ? 'Examen no encontrado en el catalogo.'
                    : ($active->isEmpty() ? 'El examen existe, pero esta inactivo.' : 'Examen ambiguo: hay varios registros activos con ese nombre.');
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
                $headers = $index === 0
                    ? ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'PROGRAMACION', 'MINA']
                    : ['DNI', 'APELLIDOS Y NOMBRES', 'CURSO/EMO', 'REALIZACION', 'RESULTADO', 'NOTA', 'VENCIMIENTO ANUAL', 'OBSERVACION', 'MINA'];
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
                    foreach ($values as $column => $value) {
                        if (is_string($value) && str_starts_with($value, '=') && !($index === 1 && $column === 6)) {
                            $issues[] = 'Formula no permitida en '.$headers[$column].'. Use un valor, no una formula.';
                        }
                    }
                    if ($index === 1 && ($values[5] ?? '') !== '' && $values[5] !== null
                        && (!is_numeric($values[5]) || !is_finite((float) $values[5]) || (float) $values[5] < 0)) {
                        $issues[] = 'La nota debe ser un numero no negativo.';
                    }
                    $exam = trim((string) $values[2]);
                    if ($exam === '') {
                        $issues[] = 'Falta curso o examen.';
                    }
                    $date = $this->date($values[3]);
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
                    if ($index === 1 && $values[6] !== null && $values[6] !== '') {
                        $raw = $values[6];
                        // Only this template's local date addition is evaluated, never arbitrary formulas.
                        if (is_string($raw) && preg_match('/^=\+?D'.$line.'\+365$/i', $raw) && is_numeric($values[3])) {
                            $raw = (float) $values[3] + 365;
                        }
                        $expiry = $this->date($raw);
                        if (!$expiry || ($date && $expiry < $date)) {
                            $issues[] = 'Vencimiento invalido o anterior a la realizacion.';
                        }
                    }
                    $result = $index === 1 ? Str::upper(Str::ascii(trim((string) $values[4]))) : '';
                    if ($index === 1 && !in_array($result, ['APTO', 'APROBADO', 'DESAPROBADO', 'NO APTO', 'APTO CON RESTRICCION'], true)) {
                        $issues[] = 'Resultado pendiente de revision: '.$result;
                    }
                    if ($result === 'APTO CON RESTRICCION' && trim((string) ($values[7] ?? '')) === '') {
                        $issues[] = 'Apto con restriccion requiere detallar la restriccion.';
                    }
                    $rows[] = ['sheet' => $sheet->getTitle(), 'line' => $line, 'type' => $index === 0 ? 'Programacion' : 'Resultado',
                        'dni' => $dni, 'name' => (string) $values[1], 'exam' => $exam, 'mine' => $mine, 'date' => $date,
                        'result' => $result, 'score' => $values[5] ?? null, 'expiry' => $expiry,
                        'observation' => $values[7] ?? '', 'issues' => $issues];
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
