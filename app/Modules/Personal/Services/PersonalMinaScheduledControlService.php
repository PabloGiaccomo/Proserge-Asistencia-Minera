<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Modules\Personal\Support\EmoOutcome;
use App\Modules\Personal\Support\ScheduledAttendanceState;
use App\Modules\Personal\Support\ScheduledControlFilters;
use App\Modules\Personal\Support\ScheduledControlRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonalMinaScheduledControlService
{
    public function paginate(ScheduledControlFilters $filters, int $perPage): LengthAwarePaginator
    {
        $perPage = $filters->limit;
        $page = $this->rowsQuery($filters)->paginate($perPage, ['*'], 'scheduled_page', $filters->page);
        $page->setCollection($this->hydrate($page->getCollection(), $filters));
        $page->withQueryString()->appends($filters->toQuery());

        return $page;
    }

    public function allForExport(ScheduledControlFilters $filters, int $maxRows = 10000): Collection
    {
        if ($maxRows < 1) {
            throw ValidationException::withMessages(['scheduled_to' => 'El limite de exportacion debe ser positivo.']);
        }
        $rows = $this->rowsQuery($filters)->limit($maxRows + 1)->get();
        if ($rows->count() > $maxRows) {
            throw ValidationException::withMessages([
                'scheduled_to' => "Los resultados superan {$maxRows} filas. Reduzca el rango de fechas o aplique mas filtros.",
            ]);
        }

        return $this->hydrate($rows, $filters);
    }

    public function filterOptions(ScheduledControlFilters $filters): array
    {
        $facets = [
            'categories' => ['source_type', null, 'scheduled_category', $filters->category?->value],
            'exams' => ['exam_id', 'exam_name', 'scheduled_exam_id', $filters->examId],
            'mines' => ['mine_id', 'mine_name', 'scheduled_mine_id', $filters->mineId],
        ];
        $options = [];
        foreach ($facets as $key => [$id, $name, $filterKey, $selected]) {
            $query = $this->linksQuery($filters, false, $filterKey)->whereNotNull($id);
            $values = $query->select($id.' as id')
                ->selectRaw(($name ? "MIN({$name})" : $id).' as name')->groupBy($id)->orderBy('name')->get();
            // Keep the user's selected context even when other structural filters yield no rows.
            if ($selected && ! $values->contains('id', $selected)) {
                $selectedRow = match ($key) {
                    'exams' => DB::table('examenes_mineros')->where('id', $selected)->first(['id', 'nombre as name']),
                    'mines' => Mina::query()->operationalCatalog()->where('id', $selected)->toBase()->first(['id', 'nombre as name']),
                    default => (object) ['id' => $selected, 'name' => $selected],
                };
                if ($selectedRow) {
                    $values->push($selectedRow);
                }
            }
            $options[$key] = $values->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name])->all();
        }
        $visibleClinics = $this->linksQuery($filters, false, 'scheduled_clinic_id')
            ->whereNotNull('clinic_id')->select('clinic_id');
        $options['clinics'] = DB::table('clinicas_emo')->where(function (Builder $query) use ($visibleClinics, $filters): void {
            $query->where(function (Builder $active) use ($visibleClinics): void {
                $active->where('activo', true)->whereIn('id', $visibleClinics);
            });
            if ($filters->clinicId) {
                $query->orWhere('id', $filters->clinicId);
            }
        })->orderBy('nombre')->get(['id', 'nombre as name', 'activo'])->map(fn ($row): array => [
            'id' => $row->id, 'name' => $row->name, 'active' => (bool) $row->activo,
        ])->all();
        $options['attendanceStates'] = array_map(fn (ScheduledAttendanceState $state): array => [
            'id' => $state->value, 'name' => $state->value,
        ], ScheduledAttendanceState::cases());

        return $options;
    }

    private function rowsQuery(ScheduledControlFilters $filters): Builder
    {
        $columns = ['source_type', 'scheduled_date', 'clinic_id', 'clinic_name', 'appointment_revision', 'worker_id', 'worker_document',
            'worker_name', 'position_name', 'exam_id', 'exam_name', 'emo_outcome', 'result_detail',
            'performed_at', 'operational_state'];
        $grouped = $this->linksQuery($filters)->select('row_key')->groupBy('row_key');
        foreach ($columns as $column) {
            $grouped->selectRaw("MIN({$column}) as {$column}");
        }
        $grouped->selectRaw('MAX(attendance_rank) as attendance_rank');
        $query = DB::query()->fromSub($grouped, 'physical_rows');
        if ($filters->attendanceState) {
            $query->where('attendance_rank', $this->attendanceRank($filters->attendanceState));
        }

        return $query->orderBy('scheduled_date')->orderBy('worker_name')->orderBy('exam_name')->orderBy('row_key');
    }

    /** Each record here represents one explicit mine/attempt link, before physical-row grouping. */
    private function linksQuery(ScheduledControlFilters $filters, bool $functional = true, ?string $omit = null): Builder
    {
        $query = DB::query()->fromSub($this->attemptLinks()->unionAll($this->courseLinks()), 'control_links');
        foreach (['scheduled_category' => ['source_type', $filters->category?->value],
            'scheduled_exam_id' => ['exam_id', $filters->examId],
            'scheduled_mine_id' => ['mine_id', $filters->mineId],
            'scheduled_clinic_id' => ['clinic_id', $filters->clinicId]] as $key => [$column, $value]) {
            if ($value !== null && $key !== $omit) {
                $query->where($column, $value);
            }
        }
        if ($functional) {
            $query->whereBetween('scheduled_date', [$filters->from->toDateString(), $filters->to->toDateString()]);
            if ($filters->worker !== null) {
                // Escape wildcards so a document or name is searched literally.
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->worker).'%';
                $query->where(function (Builder $search) use ($needle): void {
                    foreach (['worker_name', 'worker_document', 'worker_dni', 'position_name', 'occupation_name'] as $column) {
                        $search->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$needle]);
                    }
                });
            }
        }

        return $query;
    }

    private function attemptLinks(): Builder
    {
        $result = 'CASE WHEN ec.id IS NOT NULL THEN COALESCE(ec.resultado_emo, i.resultado) ELSE i.resultado END';
        $performed = 'CASE WHEN ec.id IS NOT NULL THEN ec.fecha_realizacion ELSE i.fecha_realizacion END';
        $cancelled = "ec.estado = 'ANULADA' OR ({$result}) = 'ANULADO'";

        return DB::table('personal_mina_examen_intentos as i')
            ->join('personal_mina_examenes as e', 'e.id', '=', 'i.personal_mina_examen_id')
            ->join('personal_mina as pm', 'pm.id', '=', 'e.personal_mina_id')
            ->joinSub(Mina::query()->activeOperational()->toBase(), 'm', 'm.id', '=', 'pm.mina_id')
            ->join('personal as p', 'p.id', '=', 'pm.personal_id')
            ->join('examenes_mineros as catalog', 'catalog.id', '=', 'e.examen_id')
            ->leftJoin('personal_emo_citas as ec', 'ec.id', '=', 'i.emo_cita_id')
            ->where('pm.activo', true)
            ->where(function (Builder $query): void {
                $query->whereNotNull('ec.id')->orWhere('catalog.categoria_operativa', 'EMO')
                    ->orWhere(function (Builder $course): void {
                        $course->where('catalog.categoria_operativa', 'CURSO')->whereNull('i.personal_curso_registro_id');
                    });
            })
            ->selectRaw("CASE WHEN ec.id IS NOT NULL THEN ec.id WHEN catalog.categoria_operativa = 'EMO'
                THEN CONCAT('legacy-emo-attempt:', i.id) ELSE i.id END as row_key")
            ->selectRaw("CASE WHEN ec.id IS NOT NULL THEN 'EMO' ELSE catalog.categoria_operativa END as source_type")
            ->selectRaw('CASE WHEN ec.id IS NOT NULL THEN ec.fecha_programacion ELSE i.fecha_programacion END as scheduled_date')
            ->selectRaw("ec.clinica_id as clinic_id, CASE WHEN ec.id IS NOT NULL THEN ec.clinica_nombre_snapshot
                WHEN catalog.categoria_operativa = 'EMO' THEN 'Historico sin clinica' ELSE NULL END as clinic_name")
            ->selectRaw('ec.revision as appointment_revision')
            ->selectRaw($this->workerColumns())
            ->selectRaw('e.examen_id as exam_id, e.nombre_snapshot as exam_name')
            ->selectRaw($this->stateExpression($cancelled, $result, $performed).' as attendance_rank')
            ->selectRaw("ec.resultado_emo as emo_outcome, CASE WHEN ec.id IS NOT NULL THEN ec.detalle_resultado_emo
                ELSE i.observacion END as result_detail, {$performed} as performed_at")
            ->selectRaw('COALESCE(ec.estado, e.estado) as operational_state, m.id as mine_id, m.nombre as mine_name')
            ->selectRaw("CONCAT(m.nombre, ': intento ', i.numero_intento, ' [', i.id, ']') as attempt_label");
    }

    private function courseLinks(): Builder
    {
        return DB::table('personal_curso_registros as c')
            ->join('personal as p', 'p.id', '=', 'c.personal_id')
            ->join('examenes_mineros as catalog', 'catalog.id', '=', 'c.examen_id')
            ->leftJoin('personal_mina_examen_intentos as i', 'i.personal_curso_registro_id', '=', 'c.id')
            ->leftJoin('personal_mina_examenes as e', 'e.id', '=', 'i.personal_mina_examen_id')
            ->leftJoin('personal_mina as pm', 'pm.id', '=', 'e.personal_mina_id')
            ->leftJoinSub(Mina::query()->activeOperational()->toBase(), 'm', function ($join): void {
                $join->on('m.id', '=', DB::raw('COALESCE(pm.mina_id, c.mina_id)'));
            })
            ->where('catalog.categoria_operativa', 'CURSO')->whereNull('c.emo_cita_id')
            ->where(fn (Builder $query) => $query->whereNull('pm.id')->orWhere('pm.activo', true))
            ->where(fn (Builder $query) => $query->whereNotNull('m.id')->orWhereRaw('COALESCE(pm.mina_id, c.mina_id) IS NULL'))
            ->selectRaw("c.id as row_key, 'CURSO' as source_type, c.fecha_programacion as scheduled_date,
                NULL as clinic_id, NULL as clinic_name, NULL as appointment_revision")
            ->selectRaw($this->workerColumns())
            ->selectRaw('c.examen_id as exam_id, COALESCE(e.nombre_snapshot, catalog.nombre) as exam_name')
            ->selectRaw($this->stateExpression("c.resultado = 'ANULADO'", 'c.resultado', 'c.fecha_realizacion').' as attendance_rank')
            ->selectRaw('NULL as emo_outcome, c.observacion as result_detail, c.fecha_realizacion as performed_at,
                COALESCE(e.estado, c.resultado) as operational_state, m.id as mine_id, m.nombre as mine_name')
            ->selectRaw("CASE WHEN i.id IS NOT NULL THEN CONCAT(m.nombre, ': intento ', i.numero_intento, ' [', i.id, ']') END as attempt_label");
    }

    private function workerColumns(): string
    {
        return "p.id as worker_id, COALESCE(NULLIF(p.numero_documento, ''), p.dni) as worker_document,
            p.dni as worker_dni, p.nombre_completo as worker_name, NULLIF(p.puesto, '') as position_name,
            p.ocupacion as occupation_name";
    }

    private function stateExpression(string $cancelled, string $result, string $performed): string
    {
        return "CASE WHEN {$cancelled} THEN 4 WHEN ({$result}) = 'NO_ASISTIO' THEN 3
            WHEN ({$performed}) IS NOT NULL OR (({$result}) IS NOT NULL AND ({$result}) NOT IN ('', 'PENDIENTE'))
            THEN 2 ELSE 1 END";
    }

    private function attendanceRank(ScheduledAttendanceState $state): int
    {
        return match ($state) {
            ScheduledAttendanceState::ANULADO => 4,
            ScheduledAttendanceState::NO_ASISTIO => 3,
            ScheduledAttendanceState::ASISTIO => 2,
            ScheduledAttendanceState::PROGRAMADO => 1,
        };
    }

    private function hydrate(Collection $rows, ScheduledControlFilters $filters): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        // Only the selected physical rows are expanded. No lazy Eloquent relations or full-history load.
        $links = $this->linksQuery($filters)->whereIn('row_key', $rows->pluck('row_key'))
            ->get(['row_key', 'mine_id', 'mine_name', 'attempt_label'])->groupBy('row_key');
        $timezone = config('app.display_timezone', 'America/Lima');

        return $rows->map(function ($row) use ($links, $timezone): ScheduledControlRow {
            $rowLinks = $links->get($row->row_key, collect());
            $mines = $rowLinks->whereNotNull('mine_id')->unique('mine_id')
                ->sortBy(fn ($link): string => $link->mine_name.'|'.$link->mine_id)->values();
            $state = match ((int) $row->attendance_rank) {
                4 => 'ANULADO', 3 => 'NO_ASISTIO', 2 => 'ASISTIO', default => 'PROGRAMADO',
            };
            $legacyMedical = $row->source_type === 'EMO' && !$row->emo_outcome
                ? EmoOutcome::fromLegacyText($row->result_detail) : null;
            $emoOutcome = $row->emo_outcome ? EmoOutcome::tryFrom($row->emo_outcome) : ($legacyMedical['outcome'] ?? null);
            $resultDetail = $legacyMedical ? $legacyMedical['detail'] : $row->result_detail;

            return new ScheduledControlRow(
                $row->row_key, $row->source_type, CarbonImmutable::parse($row->scheduled_date, $timezone),
                $row->clinic_id, $row->clinic_name,
                $row->appointment_revision !== null ? (int) $row->appointment_revision : null,
                $row->worker_id, $row->worker_document,
                $row->worker_name, $row->position_name, $row->exam_id, $row->exam_name,
                $mines->pluck('mine_id')->all(), $mines->pluck('mine_name')->all(),
                $rowLinks->pluck('attempt_label')->filter()->unique()->sort()->values()->all(), $state,
                $emoOutcome, $resultDetail,
                $row->performed_at ? CarbonImmutable::parse($row->performed_at, $timezone) : null,
                $row->operational_state,
            );
        });
    }
}
