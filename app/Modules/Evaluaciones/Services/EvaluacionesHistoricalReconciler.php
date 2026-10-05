<?php

namespace App\Modules\Evaluaciones\Services;

use App\Modules\Personal\Support\PersonalNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

class EvaluacionesHistoricalReconciler
{
    public function reconcile(array $read): array
    {
        $map = $read['personal_map'] ?? [];
        $rows = $read['rows'] ?? [];
        $dniByRow = [];
        $evaluatorDniByRow = [];
        foreach ($rows as $index => $row) {
            $dniByRow[$index] = $this->dni($row['dni_ref'] ?? null, $map);
            $evaluatorDniByRow[$index] = $this->dni($row['evaluator_ref'] ?? null, $map);
        }

        $people = [];
        foreach (array_chunk(array_values(array_unique(array_filter([...$dniByRow, ...$evaluatorDniByRow]))), 500) as $chunk) {
            foreach (DB::table('personal')->whereIn('dni', $chunk)->get(['id', 'dni', 'nombre_completo']) as $person) {
                $people[$person->dni][] = $person;
            }
        }

        $prepared = [];
        $blocked = [];
        $warnings = [];
        foreach ($rows as $index => $row) {
            $dni = $dniByRow[$index];
            $person = $dni ? ($people[$dni] ?? []) : [];
            $date = $this->date($row['date'] ?? null);
            $reason = match (true) {
                $dni === null => 'DNI o ID interno no reconocido.',
                count($person) !== 1 => 'DNI inexistente o ambiguo en Personal.',
                $date === null => 'Fecha no interpretable.',
                default => null,
            };

            if ($reason === null && ($row['type'] ?? '') === 'daily') {
                $scores = $row['scores'] ?? [];
                if (count($scores) !== 5 || collect($scores)->contains(fn ($score) => ! preg_match('/^[1-4]$/', trim((string) $score)))) {
                    $reason = 'Las cinco notas deben estar entre 1 y 4.';
                }
            }

            if ($reason === null && ($row['type'] ?? '') === 'attendance'
                && ! array_key_exists(trim((string) ($row['header_id'] ?? '')), $read['attendance_headers'] ?? [])) {
                $reason = 'Encabezado historico de asistencia inexistente.';
            }

            $entry = [
                ...$row, 'dni' => $dni, 'date' => $date,
                'trabajador_id' => count($person) === 1 ? (string) $person[0]->id : null,
                'evaluador_id' => count($people[$evaluatorDniByRow[$index] ?? ''] ?? []) === 1
                    ? (string) $people[$evaluatorDniByRow[$index]][0]->id : null,
                'source_key' => hash('sha256', implode('|', [
                    $row['source_file'] ?? '', $row['source_sheet'] ?? '',
                    trim((string) ($row['source_id'] ?? '')) ?: 'fila:'.($row['source_row'] ?? ''),
                ])),
            ];
            if ($reason !== null) {
                $blocked[] = [...$entry, 'reason' => $reason];
                continue;
            }

            if (($row['type'] ?? '') === 'daily') {
                $entry['scores'] = array_map('intval', $row['scores']);
                $entry['total'] = array_sum($entry['scores']);
            }

            if (! empty($row['name']) && PersonalNormalizer::normalizeKey((string) $row['name'])
                !== PersonalNormalizer::normalizeKey((string) $person[0]->nombre_completo)) {
                $warnings[] = ['source_key' => $entry['source_key'], 'reason' => 'Nombre de Excel distinto al de Personal.'];
            }

            $prepared[] = $entry;
        }

        $keyCounts = collect($prepared)->countBy('source_key');
        $prepared = array_values(array_filter($prepared, function (array $entry) use ($keyCounts, &$blocked): bool {
            if ($keyCounts[$entry['source_key']] > 1) {
                $blocked[] = [...$entry, 'reason' => 'Identificador de origen repetido en el archivo.'];
                return false;
            }

            return true;
        }));

        $attendanceById = [];
        foreach ($prepared as $entry) {
            if ($entry['type'] === 'attendance' && filled($entry['source_id'] ?? null)) {
                $attendanceById[(string) $entry['source_id']] = $entry;
            }
        }
        $valid = [];
        foreach ($prepared as $entry) {
            if ($entry['type'] === 'daily' && $entry['source_file'] === 'control' && filled($entry['detail_ref'] ?? null)) {
                $detail = $attendanceById[(string) $entry['detail_ref']] ?? null;
                if (! $detail || $detail['trabajador_id'] !== $entry['trabajador_id'] || $detail['date'] !== $entry['date']) {
                    $blocked[] = [...$entry, 'reason' => 'Referencia de asistencia incompatible con DNI o fecha.'];
                    continue;
                }
            }
            $valid[] = $entry;
        }

        $proposed = [];
        $unchanged = [];
        $dailyGroups = [];
        foreach ($valid as $entry) {
            if ($entry['type'] === 'daily') {
                $dailyGroups[$entry['trabajador_id'].'|'.$entry['date']][] = $entry;
            } else {
                $proposed[] = $entry;
            }
        }

        foreach ($dailyGroups as $group) {
            $control = array_values(array_filter($group, fn ($entry) => $entry['source_file'] === 'control'));
            $consolidado = array_values(array_filter($group, fn ($entry) => $entry['source_file'] === 'consolidado'));
            if ($control && $consolidado) {
                $signature = fn ($items) => collect($items)->map(fn ($entry) => implode(',', $entry['scores']))->sort()->values()->all();
                if ($signature($control) !== $signature($consolidado)) {
                    foreach ($group as $entry) {
                        $blocked[] = [...$entry, 'reason' => 'Conflicto entre libros para DNI, fecha o notas.'];
                    }
                    continue;
                }
                array_push($unchanged, ...$consolidado);
                array_push($proposed, ...$control);
            } else {
                array_push($proposed, ...$group);
            }
        }

        $existingDaily = [];
        $existingAttendance = [];
        $existingSupervisor = [];
        $existingResident = [];
        foreach (array_chunk(array_values(array_unique(array_column($proposed, 'source_key'))), 500) as $keys) {
            foreach (DB::table('evaluacion_desempeno')->whereIn('source_key', $keys)->get() as $existing) {
                $existingDaily[$existing->source_key] = $existing;
            }
            foreach (DB::table('asistencia_historica')->whereIn('source_key', $keys)->get() as $existing) {
                $existingAttendance[$existing->source_key] = $existing;
            }
            foreach (DB::table('evaluacion_supervisor')->whereIn('source_key', $keys)->get() as $existing) {
                $existingSupervisor[$existing->source_key] = $existing;
            }
            foreach (DB::table('evaluacion_residente')->whereIn('source_key', $keys)->get() as $existing) {
                $existingResident[$existing->source_key] = $existing;
            }
        }

        $newRows = [];
        foreach ($proposed as $entry) {
            $existing = match ($entry['type']) {
                'daily' => $existingDaily[$entry['source_key']] ?? null,
                'attendance' => $existingAttendance[$entry['source_key']] ?? null,
                'supervisor' => $existingSupervisor[$entry['source_key']] ?? null,
                'resident' => $existingResident[$entry['source_key']] ?? null,
                default => null,
            };
            if (! $existing) {
                $newRows[] = $entry;
                continue;
            }

            $targetColumn = match ($entry['type']) {
                'supervisor' => 'evaluado_id',
                'resident' => 'residente_id',
                default => 'trabajador_id',
            };
            $same = (string) $existing->{$targetColumn} === $entry['trabajador_id']
                && substr((string) $existing->fecha, 0, 10) === $entry['date'];
            if ($same && $entry['type'] === 'daily') {
                $same = [(int) $existing->desempeno_trabajo, (int) $existing->orden_limpieza,
                    (int) $existing->compromiso, (int) $existing->respuesta_emocional,
                    (int) $existing->seguridad_trabajo] === $entry['scores'];
            }
            if ($same && $entry['type'] === 'supervisor') {
                $same = (float) $existing->resultado_final === (float) ($entry['values'][31] ?? null);
            }
            if ($same && $entry['type'] === 'resident') {
                $same = (float) $existing->total === (float) ($entry['values'][10] ?? null);
            }

            if ($same) {
                $unchanged[] = $entry;
            } else {
                $blocked[] = [...$entry, 'reason' => 'La fuente ya existe con datos diferentes.'];
            }
        }

        foreach ($read['issues'] ?? [] as $issue) {
            $blocked[] = ['source_file' => $issue['source'], 'reason' => $issue['message']];
        }

        return ['proposed' => $newRows, 'unchanged' => $unchanged, 'blocked' => $blocked, 'warnings' => $warnings];
    }

    private function dni(mixed $value, array $map): ?string
    {
        $raw = trim((string) $value);
        $raw = array_key_exists($raw, $map) ? trim((string) $map[$raw]) : $raw;
        if (! preg_match('/^\d{8}(?:\.0+)?$/', $raw)) {
            return null;
        }

        return PersonalNormalizer::dni($raw);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value) && (float) $value >= 20000 && (float) $value <= 80000) {
            return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $raw = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $raw);
            if ($date && $date->format($format) === $raw) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
