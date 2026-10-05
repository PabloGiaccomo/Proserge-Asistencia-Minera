<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Models\PersonalMina;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PersonalMinaDashboardService
{
    private const UNRESOLVED_EXAM_STATES = ['PENDIENTE', 'PROGRAMADO', 'DESAPROBADO', 'VENCIDO', 'OBSERVADO'];

    public function dashboard(array $filters): array
    {
        if (!Schema::hasTable('personal_mina') || !Schema::hasTable('minas')) {
            return $this->emptyDashboard();
        }

        $minaId = trim((string) ($filters['mina_id'] ?? ''));
        $habilitationState = strtoupper(trim((string) ($filters['estado_habilitacion'] ?? '')));
        $examState = strtoupper(trim((string) ($filters['estado_examen'] ?? '')));
        $stateExpression = "COALESCE(NULLIF(pm.estado_habilitacion, ''), NULLIF(pm.estado, ''), 'EN_PROCESO')";
        $operationalMineIds = Mina::query()->activeOperational()->pluck('id');
        $base = DB::table('personal_mina as pm')->join('minas as m', 'm.id', '=', 'pm.mina_id')
            ->where('pm.activo', true)
            ->whereIn('pm.mina_id', $operationalMineIds)
            ->whereIn(DB::raw($stateExpression), [
                PersonalMina::ESTADO_HABILITADO,
                PersonalMina::ESTADO_EN_PROCESO,
            ])
            ->when($minaId !== '', fn ($query) => $query->where('pm.mina_id', $minaId))
            ->when($habilitationState !== '', fn ($query) => $query->whereRaw("{$stateExpression} = ?", [$habilitationState]));

        if ($examState !== '' && Schema::hasTable('personal_mina_examenes')) {
            $base->whereExists(function ($query) use ($examState): void {
                $query->selectRaw('1')->from('personal_mina_examenes as filter_exam')
                    ->whereColumn('filter_exam.personal_mina_id', 'pm.id')->where('filter_exam.estado', $examState);
            });
        }

        $states = (clone $base)->selectRaw("{$stateExpression} as estado, COUNT(*) as total")
            ->groupBy('estado')->pluck('total', 'estado')->map(fn ($value): int => (int) $value);
        $byMine = (clone $base)->selectRaw("pm.mina_id, m.nombre, {$stateExpression} as estado, COUNT(*) as total")
            ->groupBy('pm.mina_id', 'm.nombre', 'estado')->get()->groupBy('mina_id')
            ->map(function (Collection $rows): array {
                $counts = $rows->pluck('total', 'estado')->map(fn ($value): int => (int) $value);
                return ['id' => (string) $rows->first()->mina_id, 'nombre' => (string) $rows->first()->nombre,
                    'total' => $counts->sum(), 'states' => $counts->all()];
            })->sortByDesc('total')->values();

        $examKpis = [
            'PROGRAMADO' => 0,
            'VENCIDO' => 0,
            'POR_VENCER' => 0,
            'SIN_EXAMENES' => 0,
        ];
        if (Schema::hasTable('personal_mina_examenes')) {
            $examScope = DB::table('personal_mina as pm')
                ->where('pm.activo', true)
                ->whereIn('pm.mina_id', $operationalMineIds)
                ->whereIn(DB::raw($stateExpression), [
                    PersonalMina::ESTADO_HABILITADO,
                    PersonalMina::ESTADO_EN_PROCESO,
                ])
                ->when($minaId !== '', fn ($query) => $query->where('pm.mina_id', $minaId))
                ->when($habilitationState !== '', fn ($query) => $query->whereRaw("{$stateExpression} = ?", [$habilitationState]));

            $examCounts = DB::table('personal_mina_examenes as pme')
                ->joinSub((clone $examScope)->select('pm.id'), 'dashboard_pm', 'dashboard_pm.id', '=', 'pme.personal_mina_id')
                ->whereIn('pme.estado', ['PROGRAMADO', 'VENCIDO', 'POR_VENCER'])
                ->selectRaw('pme.estado, COUNT(*) as total')
                ->groupBy('pme.estado')
                ->pluck('total', 'estado');

            foreach (['PROGRAMADO', 'VENCIDO', 'POR_VENCER'] as $state) {
                $examKpis[$state] = (int) $examCounts->get($state, 0);
            }

            $examKpis['SIN_EXAMENES'] = (int) (clone $examScope)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('personal_mina_examenes as no_exam')
                        ->whereColumn('no_exam.personal_mina_id', 'pm.id');
                })
                ->distinct()
                ->count('pm.personal_id');
        }

        $missingExams = collect();
        if (Schema::hasTable('personal_mina_examenes')) {
            $missingExams = DB::table('personal_mina_examenes as pme')
                ->join('personal_mina as pm', 'pm.id', '=', 'pme.personal_mina_id')->where('pm.activo', true)
                ->whereIn('pm.mina_id', $operationalMineIds)
                ->whereIn(DB::raw($stateExpression), [
                    PersonalMina::ESTADO_HABILITADO,
                    PersonalMina::ESTADO_EN_PROCESO,
                ])
                ->when($minaId !== '', fn ($query) => $query->where('pm.mina_id', $minaId))
                ->when($habilitationState !== '', fn ($query) => $query->whereRaw("{$stateExpression} = ?", [$habilitationState]))
                ->when($examState !== '', fn ($query) => $query->where('pme.estado', $examState),
                    fn ($query) => $query->whereIn('pme.estado', self::UNRESOLVED_EXAM_STATES))
                ->selectRaw("COALESCE(NULLIF(pme.nombre_snapshot, ''), 'EXAMEN SIN NOMBRE') as nombre, pme.estado, COUNT(*) as total")
                ->groupBy('nombre', 'pme.estado')->orderByDesc('total')->get()
                ->map(fn ($row): array => ['nombre' => (string) $row->nombre, 'estado' => (string) $row->estado, 'total' => (int) $row->total]);
        }

        return ['total' => $states->sum(), 'states' => $states, 'by_mine' => $byMine, 'missing_exams' => $missingExams,
            'exam_kpis' => $examKpis,
            'max_mine_total' => max(1, (int) $byMine->max('total')), 'max_exam_total' => max(1, (int) $missingExams->max('total'))];
    }

    public function emptyDashboard(): array
    {
        return ['total' => 0, 'states' => collect(), 'by_mine' => collect(), 'missing_exams' => collect(),
            'exam_kpis' => ['PROGRAMADO' => 0, 'VENCIDO' => 0, 'POR_VENCER' => 0, 'SIN_EXAMENES' => 0],
            'max_mine_total' => 1, 'max_exam_total' => 1];
    }

    public function examWorkers(array $filters)
    {
        $stateExpression = "COALESCE(NULLIF(pm.estado_habilitacion, ''), NULLIF(pm.estado, ''), 'EN_PROCESO')";
        $minaId = trim((string) ($filters['mina_id'] ?? ''));
        $habilitationState = strtoupper(trim((string) ($filters['estado_habilitacion'] ?? '')));

        return DB::table('personal_mina_examenes as pme')
            ->join('personal_mina as pm', 'pm.id', '=', 'pme.personal_mina_id')
            ->join('personal as p', 'p.id', '=', 'pm.personal_id')
            ->join('minas as m', 'm.id', '=', 'pm.mina_id')
            ->where('pm.activo', true)
            ->whereIn('pm.mina_id', Mina::query()->activeOperational()->pluck('id'))
            ->whereIn(DB::raw($stateExpression), [PersonalMina::ESTADO_HABILITADO, PersonalMina::ESTADO_EN_PROCESO])
            ->whereRaw("COALESCE(NULLIF(pme.nombre_snapshot, ''), 'EXAMEN SIN NOMBRE') = ?", [trim((string) $filters['examen_nombre'])])
            ->where('pme.estado', strtoupper((string) $filters['estado_examen']))
            ->when($minaId !== '', fn ($query) => $query->where('pm.mina_id', $minaId))
            ->when($habilitationState !== '', fn ($query) => $query->whereRaw("{$stateExpression} = ?", [$habilitationState]))
            ->select([
                'p.nombre_completo',
                'p.dni',
                'p.numero_documento',
                'm.nombre as mina',
                'pme.estado',
                'pme.fecha_programacion',
                'pme.fecha_realizacion',
                'pme.fecha_vencimiento',
            ])
            ->orderBy('p.nombre_completo')
            ->paginate(20);
    }
}
