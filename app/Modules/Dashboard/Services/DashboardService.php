<?php

namespace App\Modules\Dashboard\Services;

use App\Models\AsistenciaDetalle;
use App\Models\EvaluacionDesempeno;
use App\Models\EvaluacionResidente;
use App\Models\EvaluacionSupervisor;
use App\Models\Usuario;
use App\Modules\Dashboard\Policies\DashboardPolicy;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\RQProserge\Policies\RQProsergePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public function __construct(
        private readonly DashboardPolicy $policy,
        private readonly EvaluacionesPolicy $evaluationPolicy,
        private readonly RQProsergePolicy $rqProsergePolicy,
    ) {}

    public function resumen(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->view($usuario)) {
            return null;
        }

        $rqMina = $this->policy->canViewSection($usuario, 'rqMina') ? $this->rqMinaBase($usuario, $filters) : null;

        return [
            'rq_mina_total' => $rqMina?->count(),
            'rq_mina_por_estado' => $rqMina?->selectRaw('estado, COUNT(*) as total')
                ->groupBy('estado')
                ->pluck('total', 'estado'),
            'rq_proserge_total' => $this->policy->canViewSection($usuario, 'rqProserge') ? $this->rqProsergeBase($usuario, $filters)->count() : null,
            'grupos_total' => $this->policy->canViewSection($usuario, 'manPower') ? $this->grupoBase($usuario, $filters)->count() : null,
            'asistencias_cerradas' => $this->policy->canViewSection($usuario, 'asistencia') ? $this->asistenciaBase($usuario, $filters)->where('ae.estado', 'CERRADO')->count() : null,
            'faltas_activas' => $this->policy->canViewSection($usuario, 'faltas') ? $this->faltaBase($usuario, $filters)->where('f.estado', 'ACTIVA')->count() : null,
            ...$this->evaluationCounts($usuario, $filters),
        ];
    }

    public function rqMina(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->canViewSection($usuario, __FUNCTION__)) {
            return null;
        }

        if ($this->isMinaOnlyFilteredOut($filters)) {
            return [
                'totales_por_estado' => [],
                'tendencia' => [],
                'distribucion' => [],
            ];
        }

        return [
            'totales_por_estado' => $this->rqMinaBase($usuario, $filters)
                ->selectRaw('estado, COUNT(*) as total')
                ->groupBy('estado')
                ->orderBy('estado')
                ->get()
                ->map(fn ($r): array => ['estado' => $r->estado, 'total' => (int) $r->total])
                ->all(),
            'tendencia' => $this->rqMinaBase($usuario, $filters)
                ->selectRaw('DATE(created_at) as fecha, COUNT(*) as total')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('fecha')
                ->get()
                ->map(fn ($r): array => ['fecha' => $r->fecha, 'total' => (int) $r->total])
                ->all(),
            'distribucion' => $this->rqMinaBase($usuario, $filters)
                ->leftJoin('minas as m', 'm.id', '=', 'rq_mina.mina_id')
                ->selectRaw('rq_mina.mina_id, m.nombre as mina_nombre, COUNT(*) as total')
                ->groupBy('rq_mina.mina_id', 'm.nombre')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r): array => ['mina_id' => $r->mina_id, 'mina_nombre' => $r->mina_nombre, 'total' => (int) $r->total])
                ->all(),
        ];
    }

    public function rqProserge(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->canViewSection($usuario, __FUNCTION__)) {
            return null;
        }

        $base = $this->rqProsergeBase($usuario, $filters);
        $solicitadoSql = $this->rqMinaDetalleSolicitadoSumSql();

        $avance = $base
            ->leftJoin('rq_mina_detalle as rmd', 'rmd.rq_mina_id', '=', 'rp.rq_mina_id')
            ->selectRaw("rp.id as rq_proserge_id, rp.rq_mina_id, {$solicitadoSql} as solicitado, SUM(rmd.cantidad_atendida) as atendido")
            ->groupBy('rp.id', 'rp.rq_mina_id')
            ->get()
            ->map(function ($r): array {
                $sol = (int) ($r->solicitado ?? 0);
                $ate = (int) ($r->atendido ?? 0);
                $pct = $sol > 0 ? round(($ate / $sol) * 100, 2) : 0;

                return [
                    'rq_proserge_id' => $r->rq_proserge_id,
                    'rq_mina_id' => $r->rq_mina_id,
                    'solicitado' => $sol,
                    'atendido' => $ate,
                    'avance_porcentaje' => $pct,
                ];
            })
            ->all();

        return [
            'requerimientos_total' => $this->rqProsergeBase($usuario, $filters)->count(),
            'requerimientos_pendientes' => collect($avance)
                ->filter(fn (array $item): bool => ($item['atendido'] ?? 0) < ($item['solicitado'] ?? 0))
                ->count(),
            'personal_asignado' => $this->rqProsergeBase($usuario, $filters)
                ->join('rq_proserge_detalle as rpd', 'rpd.rq_proserge_id', '=', 'rp.id')
                ->distinct('rpd.personal_id')
                ->count('rpd.personal_id'),
            'avance' => $avance,
        ];
    }

    public function manPower(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->canViewSection($usuario, __FUNCTION__)) {
            return null;
        }

        return [
            'grupos_total' => $this->grupoBase($usuario, $filters)->count(),
            'grupos_por_turno' => $this->grupoBase($usuario, $filters)
                ->selectRaw('turno, COUNT(*) as total')
                ->groupBy('turno')
                ->get()
                ->map(fn ($r): array => ['turno' => $r->turno, 'total' => (int) $r->total])
                ->all(),
            'grupos_por_destino' => $this->grupoBase($usuario, $filters)
                ->selectRaw('gt.destino_tipo as destino_tipo, COUNT(*) as total')
                ->groupBy('gt.destino_tipo')
                ->get()
                ->map(fn ($r): array => ['destino_tipo' => $r->destino_tipo, 'total' => (int) $r->total])
                ->all(),
            'supervisores_top' => $this->grupoBase($usuario, $filters)
                ->leftJoin('personal as p', 'p.id', '=', 'gt.supervisor_id')
                ->selectRaw('gt.supervisor_id, p.nombre_completo, COUNT(*) as total')
                ->groupBy('gt.supervisor_id', 'p.nombre_completo')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->map(fn ($r): array => ['supervisor_id' => $r->supervisor_id, 'nombre_completo' => $r->nombre_completo, 'total' => (int) $r->total])
                ->all(),
            'grupos_activos_hoy' => $this->grupoBase($usuario, $filters)
                ->whereDate('gt.fecha', now()->toDateString())
                ->whereNotIn('gt.estado', ['CANCELADO', 'CERRADO'])
                ->count(),
        ];
    }

    public function asistencia(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->canViewSection($usuario, __FUNCTION__)) {
            return null;
        }

        $presentes = $this->asistenciaDetalleBase($usuario, $filters)->where('ad.estado', 'PRESENTE')->count();
        $ausentes = $this->asistenciaDetalleBase($usuario, $filters)->where('ad.estado', 'AUSENTE')->count();
        $totalMarcados = $presentes + $ausentes;

        return [
            'grupos_iniciados' => $this->asistenciaBase($usuario, $filters)->where('ae.estado', 'REGISTRADO')->count(),
            'grupos_cerrados' => $this->asistenciaBase($usuario, $filters)->where('ae.estado', 'CERRADO')->count(),
            'presentes' => $presentes,
            'ausentes' => $ausentes,
            'porcentaje_asistencia' => $totalMarcados > 0 ? round(($presentes / $totalMarcados) * 100, 2) : 0,
            'porcentaje_inasistencia' => $totalMarcados > 0 ? round(($ausentes / $totalMarcados) * 100, 2) : 0,
            'asistencia_por_destino' => $this->asistenciaBase($usuario, $filters)
                ->selectRaw('ae.destino_tipo, COUNT(*) as total')
                ->groupBy('ae.destino_tipo')
                ->get()
                ->map(fn ($r): array => ['destino_tipo' => $r->destino_tipo, 'total' => (int) $r->total])
                ->all(),
            'asistencia_por_fecha' => $this->asistenciaBase($usuario, $filters)
                ->selectRaw('ae.fecha, COUNT(*) as total')
                ->groupBy('ae.fecha')
                ->orderBy('ae.fecha')
                ->get()
                ->map(fn ($r): array => ['fecha' => $r->fecha, 'total' => (int) $r->total])
                ->all(),
        ];
    }

    public function faltas(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->canViewSection($usuario, __FUNCTION__)) {
            return null;
        }

        return [
            'faltas_por_estado' => $this->faltaBase($usuario, $filters)
                ->selectRaw('f.estado, COUNT(*) as total')
                ->groupBy('f.estado')
                ->get()
                ->map(fn ($r): array => ['estado' => $r->estado, 'total' => (int) $r->total])
                ->all(),
            'faltas_por_destino' => $this->faltaBase($usuario, $filters)
                ->selectRaw('f.destino_tipo, COUNT(*) as total')
                ->groupBy('f.destino_tipo')
                ->get()
                ->map(fn ($r): array => ['destino_tipo' => $r->destino_tipo, 'total' => (int) $r->total])
                ->all(),
            'faltas_por_trabajador' => $this->faltaBase($usuario, $filters)
                ->leftJoin('personal as p', 'p.id', '=', 'f.trabajador_id')
                ->selectRaw('f.trabajador_id, p.nombre_completo, COUNT(*) as total')
                ->groupBy('f.trabajador_id', 'p.nombre_completo')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->map(fn ($r): array => ['trabajador_id' => $r->trabajador_id, 'nombre_completo' => $r->nombre_completo, 'total' => (int) $r->total])
                ->all(),
            'faltas_por_fecha' => $this->faltaBase($usuario, $filters)
                ->selectRaw('f.fecha, COUNT(*) as total')
                ->groupBy('f.fecha')
                ->orderBy('f.fecha')
                ->get()
                ->map(fn ($r): array => ['fecha' => $r->fecha, 'total' => (int) $r->total])
                ->all(),
            'faltas_generadas_desde_asistencia' => $this->faltaBase($usuario, $filters)
                ->whereNotNull('f.asistencia_encabezado_id')
                ->count(),
        ];
    }

    public function evaluaciones(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->canViewSection($usuario, __FUNCTION__)) {
            return null;
        }

        $counts = $this->evaluationCounts($usuario, $filters);
        if (!$this->evaluationPolicy->canViewType($usuario, 'desempeno')) {
            return $counts + array_fill_keys(['promedio_general_desempeno', 'promedio_por_trabajador', 'promedio_por_supervisor', 'promedio_por_destino', 'ranking_top'], null);
        }

        $table = 'evaluacion_desempeno';
        $scores = $this->evaluationBase($usuario, $filters, 'desempeno')->whereBetween($table.'.total', [0, 20]);

        return [
            ...$counts,
            'promedio_general_desempeno' => round((float) ($scores->avg($table.'.total') ?? 0), 2),
            'promedio_por_trabajador' => (clone $scores)
                ->selectRaw("{$table}.trabajador_id, ROUND(AVG({$table}.total),2) as promedio_total, COUNT(*) as evaluaciones")
                ->groupBy($table.'.trabajador_id')
                ->orderByDesc('promedio_total')
                ->get()
                ->map(fn ($r): array => ['trabajador_id' => $r->trabajador_id, 'promedio_total' => (float) $r->promedio_total, 'evaluaciones' => (int) $r->evaluaciones])
                ->all(),
            'promedio_por_supervisor' => (clone $scores)
                ->selectRaw("{$table}.supervisor_id, ROUND(AVG({$table}.total),2) as promedio_total, COUNT(*) as evaluaciones")
                ->groupBy($table.'.supervisor_id')
                ->orderByDesc('promedio_total')
                ->get()
                ->map(fn ($r): array => ['supervisor_id' => $r->supervisor_id, 'promedio_total' => (float) $r->promedio_total, 'evaluaciones' => (int) $r->evaluaciones])
                ->all(),
            'promedio_por_destino' => (clone $scores)
                ->selectRaw("{$table}.destino_tipo, ROUND(AVG({$table}.total),2) as promedio_total, COUNT(*) as evaluaciones")
                ->groupBy($table.'.destino_tipo')
                ->orderByDesc('promedio_total')
                ->get()
                ->map(fn ($r): array => ['destino_tipo' => $r->destino_tipo, 'promedio_total' => (float) $r->promedio_total, 'evaluaciones' => (int) $r->evaluaciones])
                ->all(),
            'ranking_top' => (clone $scores)
                ->leftJoin('personal as p', 'p.id', '=', $table.'.trabajador_id')
                ->selectRaw("{$table}.trabajador_id, p.nombre_completo, ROUND(AVG({$table}.total),2) as promedio_total")
                ->groupBy($table.'.trabajador_id', 'p.nombre_completo')
                ->orderByDesc('promedio_total')
                ->limit(10)
                ->get()
                ->map(fn ($r): array => ['trabajador_id' => $r->trabajador_id, 'nombre_completo' => $r->nombre_completo, 'promedio_total' => (float) $r->promedio_total])
                ->all(),
        ];
    }

    public function alertas(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->view($usuario)) {
            return null;
        }

        $canViewRq = $this->policy->canViewSection($usuario, 'rqMina');
        $canViewAttendance = $this->policy->canViewSection($usuario, 'asistencia');
        $canViewGroups = $this->policy->canViewSection($usuario, 'manPower');
        $canViewFaults = $this->policy->canViewSection($usuario, 'faltas');
        $canViewEvaluation = $this->policy->canViewSection($usuario, 'evaluaciones')
            && $this->evaluationPolicy->canEvaluateType($usuario, 'desempeno');

        $activeFaults = $canViewFaults ? $this->faltaBase($usuario, $filters)->where('f.estado', 'ACTIVA') : null;
        $multipleFaults = $activeFaults ? DB::query()->fromSub((clone $activeFaults)->select('f.trabajador_id')
            ->groupBy('f.trabajador_id')->havingRaw('COUNT(*) >= 2'), 'faltas_multiples')->count() : null;

        return [
            'rq_mina_pendientes_envio' => $canViewRq ? $this->rqMinaBase($usuario, $filters)->where('estado', 'BORRADOR')->count() : null,
            'requerimientos_sin_atencion_completa' => $canViewRq ? $this->rqMinaBase($usuario, $filters)
                ->join('rq_mina_detalle as rmd', 'rmd.rq_mina_id', '=', 'rq_mina.id')
                ->whereRaw('rmd.cantidad_atendida < ' . $this->rqMinaDetalleSolicitadoValueSql())
                ->distinct('rq_mina.id')
                ->count('rq_mina.id') : null,
            'grupos_sin_asistencia_cerrada' => $canViewGroups && $canViewAttendance ? $this->grupoBase($usuario, $filters)
                ->whereNotExists($this->asistenciaBase($usuario, [])->selectRaw('1')->whereColumn('ae.grupo_trabajo_id', 'gt.id')->where('ae.estado', 'CERRADO'))
                ->count('gt.id') : null,
            'faltas_activas_pendientes' => $activeFaults?->count(),
            'trabajadores_multiples_faltas_periodo' => $multipleFaults,
            'evaluaciones_pendientes' => $canViewEvaluation && $canViewAttendance ? $this->pendingEvaluations($usuario, $filters) : null,
        ];
    }

    private function rqMinaDetalleSolicitadoSumSql(string $alias = 'rmd'): string
    {
        return 'SUM(' . $this->rqMinaDetalleSolicitadoValueSql($alias) . ')';
    }

    private function rqMinaDetalleSolicitadoValueSql(string $alias = 'rmd'): string
    {
        if (!Schema::hasColumn('rq_mina_detalle', 'cantidad_total')) {
            return $alias . '.cantidad';
        }

        return 'COALESCE(NULLIF(' . $alias . '.cantidad_total, 0), ' . $alias . '.cantidad)';
    }

    private function rqMinaBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('rq_mina');

        $this->applyDateRange($q, 'rq_mina.created_at', $filters);

        if (!empty($filters['mina_id'])) {
            $q->where('rq_mina.mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $q->where('rq_mina.estado', strtoupper((string) $filters['estado']));
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $scope = $this->scopeMinaIds($usuario);
            $q->whereIn('rq_mina.mina_id', $scope);
        }

        if (!empty($filters['destino_tipo']) && strtoupper((string) $filters['destino_tipo']) !== 'MINA') {
            $q->whereRaw('1 = 0');
        }

        if (!empty($filters['destino_id']) && (empty($filters['destino_tipo']) || strtoupper((string) $filters['destino_tipo']) === 'MINA')) {
            $q->where('rq_mina.mina_id', $filters['destino_id']);
        }

        return $q;
    }

    private function rqProsergeBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('rq_proserge as rp');

        $this->applyDateRange($q, 'rp.created_at', $filters);

        if (!empty($filters['mina_id'])) {
            $q->where('rp.mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $q->where('rp.estado', strtoupper((string) $filters['estado']));
        }

        if (!$this->rqProsergePolicy->hasUnrestrictedMinaAccess($usuario)) {
            $q->whereIn('rp.mina_id', $this->scopeMinaIds($usuario));
        }

        if (!empty($filters['destino_tipo']) && strtoupper((string) $filters['destino_tipo']) !== 'MINA') {
            $q->whereRaw('1 = 0');
        }

        if (!empty($filters['destino_id']) && (empty($filters['destino_tipo']) || strtoupper((string) $filters['destino_tipo']) === 'MINA')) {
            $q->where('rp.mina_id', $filters['destino_id']);
        }

        return $q;
    }

    private function grupoBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('grupo_trabajo as gt')
            ->leftJoin('rq_mina as rm', 'rm.id', '=', 'gt.rq_mina_id');

        $this->applyDateRange($q, 'gt.fecha', $filters, true);

        if (!empty($filters['supervisor_id'])) {
            $q->where('gt.supervisor_id', $filters['supervisor_id']);
        }

        if (!empty($filters['estado'])) {
            $q->where('gt.estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['destino_tipo'])) {
            $q->where('gt.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $q->where('gt.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['mina_id'])) {
            $q->where('rm.mina_id', $filters['mina_id']);
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $q->whereIn('rm.mina_id', $this->scopeMinaIds($usuario));
        }

        return $q;
    }

    private function asistenciaBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('asistencia_encabezado as ae');

        $this->applyDateRange($q, 'ae.fecha', $filters, true);

        if (!empty($filters['supervisor_id'])) {
            $q->where('ae.supervisor_id', $filters['supervisor_id']);
        }

        if (!empty($filters['estado'])) {
            $q->where('ae.estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['destino_tipo'])) {
            $q->where('ae.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $q->where('ae.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['mina_id'])) {
            $q->where('ae.mina_id', $filters['mina_id']);
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $q->whereIn('ae.mina_id', $this->scopeMinaIds($usuario));
        }

        return $q;
    }

    private function asistenciaDetalleBase(Usuario $usuario, array $filters): Builder
    {
        return $this->asistenciaBase($usuario, $filters)
            ->join('asistencia_detalle as ad', 'ae.id', '=', 'ad.asistencia_id')
            ->when(!empty($filters['trabajador_id']), fn ($q) => $q->where('ad.trabajador_id', $filters['trabajador_id']));
    }

    private function faltaBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('faltas as f');

        $this->applyDateRange($q, 'f.fecha', $filters, true);

        if (!empty($filters['destino_tipo'])) {
            $q->where('f.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $q->where('f.destino_id', $filters['destino_id']);
        }
        if (!empty($filters['mina_id'])) {
            $q->where('f.destino_tipo', 'MINA')->where('f.destino_id', $filters['mina_id']);
        }

        if (!empty($filters['trabajador_id'])) {
            $q->where('f.trabajador_id', $filters['trabajador_id']);
        }

        if (!empty($filters['estado'])) {
            $q->where('f.estado', strtoupper((string) $filters['estado']));
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $scope = $this->scopeMinaIds($usuario);
            $q->where(function ($w) use ($scope): void {
                $w->where('f.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('f.destino_id', $scope);
            });
        }

        return $q;
    }

    private function evaluationCounts(Usuario $usuario, array $filters): array
    {
        $counts = [];
        $canViewEvaluations = $this->policy->canViewSection($usuario, 'evaluaciones');
        foreach (['desempeno' => 'desempeno', 'supervisores' => 'supervisor', 'residentes' => 'residente'] as $type => $key) {
            $counts['evaluaciones_'.$key.'_total'] = $canViewEvaluations && $this->evaluationPolicy->canViewType($usuario, $type)
                ? $this->evaluationBase($usuario, $filters, $type)->count() : null;
        }

        return $counts;
    }

    private function evaluationBase(Usuario $usuario, array $filters, string $type): Builder
    {
        [$model, $workerColumn, $supervisorColumn] = match ($type) {
            'desempeno' => [EvaluacionDesempeno::class, 'trabajador_id', 'supervisor_id'],
            'supervisores' => [EvaluacionSupervisor::class, 'evaluado_id', 'evaluador_id'],
            'residentes' => [EvaluacionResidente::class, 'residente_id', 'evaluador_id'],
        };
        $query = $this->evaluationPolicy->restrictDashboardRows($model::query(), $usuario, $type)->toBase();
        $this->applyDateRange($query, $query->from.'.fecha', $filters, true);

        foreach (['destino_tipo' => 'destino_tipo', 'destino_id' => 'destino_id', 'trabajador_id' => $workerColumn, 'supervisor_id' => $supervisorColumn] as $filter => $column) {
            if (!empty($filters[$filter])) {
                $query->where($query->from.'.'.$column, $filters[$filter]);
            }
        }
        if (!empty($filters['mina_id'])) {
            $type === 'residentes' ? $query->whereRaw('1 = 0') : $query->where($query->from.'.mina_id', $filters['mina_id']);
        }

        return $query;
    }

    private function pendingEvaluations(Usuario $usuario, array $filters): int
    {
        if (!$usuario->personal_id) {
            return 0;
        }

        $query = $this->asistenciaDetalleBase($usuario, $filters)
            ->leftJoin('evaluacion_desempeno as ed', 'ed.asistencia_detalle_id', '=', 'ad.id')
            ->where('ae.estado', 'CERRADO')
            ->whereIn('ad.estado', AsistenciaDetalle::ESTADOS_REAL)
            ->where('ad.trabajador_id', '<>', $usuario->personal_id)
            ->whereNull('ed.id');
        if (!$this->evaluationPolicy->canEvaluateOthers($usuario)) {
            $query->where(function (Builder $owner) use ($usuario): void {
                $owner->where('ae.supervisor_id', $usuario->personal_id)
                    ->orWhereExists(fn (Builder $marked) => $marked->selectRaw('1')->from('asistencia_detalle as marked')
                        ->whereColumn('marked.asistencia_id', 'ae.id')->where('marked.marcado_por_id', $usuario->id));
            });
        }

        return $query->count();
    }

    private function applyDateRange(Builder $query, string $column, array $filters, bool $isDateColumn = false): void
    {
        if (!empty($filters['fecha_desde'])) {
            $isDateColumn
                ? $query->whereDate($column, '>=', $filters['fecha_desde'])
                : $query->where($column, '>=', $filters['fecha_desde'].' 00:00:00');
        }

        if (!empty($filters['fecha_hasta'])) {
            $isDateColumn
                ? $query->whereDate($column, '<=', $filters['fecha_hasta'])
                : $query->where($column, '<=', $filters['fecha_hasta'].' 23:59:59');
        }
    }

    private function scopeMinaIds(Usuario $usuario): Collection
    {
        return $usuario->scopesMina()->pluck('mina_id');
    }

    private function isMinaOnlyFilteredOut(array $filters): bool
    {
        return !empty($filters['destino_tipo']) && strtoupper((string) $filters['destino_tipo']) !== 'MINA';
    }
}
