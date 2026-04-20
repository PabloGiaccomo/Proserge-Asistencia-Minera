<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Usuario;
use App\Modules\Dashboard\Policies\DashboardPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private readonly DashboardPolicy $policy)
    {
    }

    public function resumen(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->view($usuario)) {
            return null;
        }

        return [
            'rq_mina_total' => $this->rqMinaBase($usuario, $filters)->count(),
            'rq_mina_por_estado' => $this->rqMinaBase($usuario, $filters)
                ->selectRaw('estado, COUNT(*) as total')
                ->groupBy('estado')
                ->pluck('total', 'estado'),
            'rq_proserge_total' => $this->rqProsergeBase($usuario, $filters)->count(),
            'grupos_total' => $this->grupoBase($usuario, $filters)->count(),
            'asistencias_cerradas' => $this->asistenciaBase($usuario, $filters)->where('ae.estado', 'CERRADO')->count(),
            'faltas_activas' => $this->faltaBase($usuario, $filters)->where('f.estado', 'ACTIVA')->count(),
            'evaluaciones_desempeno_total' => $this->evalDesBase($usuario, $filters)->count(),
            'evaluaciones_supervisor_total' => $this->evalSupBase($usuario, $filters)->count(),
            'evaluaciones_residente_total' => $this->evalResBase($usuario, $filters)->count(),
        ];
    }

    public function rqMina(Usuario $usuario, array $filters): ?array
    {
        if (!$this->policy->view($usuario)) {
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
        if (!$this->policy->view($usuario)) {
            return null;
        }

        $base = $this->rqProsergeBase($usuario, $filters);

        $avance = $base
            ->leftJoin('rq_mina_detalle as rmd', 'rmd.rq_mina_id', '=', 'rp.rq_mina_id')
            ->selectRaw('rp.id as rq_proserge_id, rp.rq_mina_id, SUM(rmd.cantidad) as solicitado, SUM(rmd.cantidad_atendida) as atendido')
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
        if (!$this->policy->view($usuario)) {
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
                ->selectRaw('destino_tipo, COUNT(*) as total')
                ->groupBy('destino_tipo')
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
        if (!$this->policy->view($usuario)) {
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
        if (!$this->policy->view($usuario)) {
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
        if (!$this->policy->view($usuario)) {
            return null;
        }

        return [
            'evaluaciones_desempeno_total' => $this->evalDesBase($usuario, $filters)->count(),
            'evaluaciones_supervisor_total' => $this->evalSupBase($usuario, $filters)->count(),
            'evaluaciones_residente_total' => $this->evalResBase($usuario, $filters)->count(),
            'promedio_general_desempeno' => round((float) ($this->evalDesBase($usuario, $filters)->avg('ed.total') ?? 0), 2),
            'promedio_por_trabajador' => $this->evalDesBase($usuario, $filters)
                ->selectRaw('ed.trabajador_id, ROUND(AVG(ed.total),2) as promedio_total, COUNT(*) as evaluaciones')
                ->groupBy('ed.trabajador_id')
                ->orderByDesc('promedio_total')
                ->get()
                ->map(fn ($r): array => ['trabajador_id' => $r->trabajador_id, 'promedio_total' => (float) $r->promedio_total, 'evaluaciones' => (int) $r->evaluaciones])
                ->all(),
            'promedio_por_supervisor' => $this->evalDesBase($usuario, $filters)
                ->selectRaw('ed.supervisor_id, ROUND(AVG(ed.total),2) as promedio_total, COUNT(*) as evaluaciones')
                ->groupBy('ed.supervisor_id')
                ->orderByDesc('promedio_total')
                ->get()
                ->map(fn ($r): array => ['supervisor_id' => $r->supervisor_id, 'promedio_total' => (float) $r->promedio_total, 'evaluaciones' => (int) $r->evaluaciones])
                ->all(),
            'promedio_por_destino' => $this->evalDesBase($usuario, $filters)
                ->selectRaw('ed.destino_tipo, ROUND(AVG(ed.total),2) as promedio_total, COUNT(*) as evaluaciones')
                ->groupBy('ed.destino_tipo')
                ->orderByDesc('promedio_total')
                ->get()
                ->map(fn ($r): array => ['destino_tipo' => $r->destino_tipo, 'promedio_total' => (float) $r->promedio_total, 'evaluaciones' => (int) $r->evaluaciones])
                ->all(),
            'ranking_top' => $this->evalDesBase($usuario, $filters)
                ->leftJoin('personal as p', 'p.id', '=', 'ed.trabajador_id')
                ->selectRaw('ed.trabajador_id, p.nombre_completo, ROUND(AVG(ed.total),2) as promedio_total')
                ->groupBy('ed.trabajador_id', 'p.nombre_completo')
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

        $pendientesEvaluacion = $this->asistenciaDetalleBase($usuario, $filters)
            ->leftJoin('evaluacion_desempeno as ed', 'ed.asistencia_detalle_id', '=', 'ad.id')
            ->where('ad.estado', 'PRESENTE')
            ->whereNull('ed.id')
            ->count();

        $faltasMultiples = $this->faltaBase($usuario, $filters)
            ->where('f.estado', 'ACTIVA')
            ->selectRaw('f.trabajador_id, COUNT(*) as total')
            ->groupBy('f.trabajador_id')
            ->havingRaw('COUNT(*) >= 2')
            ->count();

        return [
            'rq_mina_pendientes_envio' => $this->rqMinaBase($usuario, $filters)->where('estado', 'BORRADOR')->count(),
            'requerimientos_sin_atencion_completa' => $this->rqMinaBase($usuario, $filters)
                ->join('rq_mina_detalle as rmd', 'rmd.rq_mina_id', '=', 'rq_mina.id')
                ->whereColumn('rmd.cantidad_atendida', '<', 'rmd.cantidad')
                ->distinct('rq_mina.id')
                ->count('rq_mina.id'),
            'grupos_sin_asistencia_cerrada' => $this->grupoBase($usuario, $filters)
                ->leftJoin('asistencia_encabezado as ae', 'ae.grupo_trabajo_id', '=', 'gt.id')
                ->where(function ($q): void {
                    $q->whereNull('ae.id')->orWhere('ae.estado', '!=', 'CERRADO');
                })
                ->distinct('gt.id')
                ->count('gt.id'),
            'faltas_activas_pendientes' => $this->faltaBase($usuario, $filters)->where('f.estado', 'ACTIVA')->count(),
            'trabajadores_multiples_faltas_periodo' => $faltasMultiples,
            'evaluaciones_pendientes' => $pendientesEvaluacion,
        ];
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

        if (!$this->policy->isPrivileged($usuario)) {
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
            $scope = $this->scopeMinaIds($usuario);
            $q->where(function ($w) use ($scope): void {
                $w->where('gt.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('gt.destino_id', $scope)
                    ->orWhereIn('rm.mina_id', $scope);
            });
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
            $scope = $this->scopeMinaIds($usuario);
            $q->where(function ($w) use ($scope): void {
                $w->where('ae.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('ae.destino_id', $scope)
                    ->orWhereIn('ae.mina_id', $scope);
            });
        }

        return $q;
    }

    private function asistenciaDetalleBase(Usuario $usuario, array $filters): Builder
    {
        return DB::table('asistencia_detalle as ad')
            ->join('asistencia_encabezado as ae', 'ae.id', '=', 'ad.asistencia_id')
            ->when(!empty($filters['trabajador_id']), fn ($q) => $q->where('ad.trabajador_id', $filters['trabajador_id']))
            ->tap(fn ($q) => $this->applyDateRange($q, 'ae.fecha', $filters, true))
            ->when(!empty($filters['destino_tipo']), fn ($q) => $q->where('ae.destino_tipo', strtoupper((string) $filters['destino_tipo'])))
            ->when(!empty($filters['destino_id']), fn ($q) => $q->where('ae.destino_id', $filters['destino_id']))
            ->when(!empty($filters['mina_id']), fn ($q) => $q->where('ae.mina_id', $filters['mina_id']))
            ->when(!$this->policy->isPrivileged($usuario), function ($q) use ($usuario): void {
                $scope = $this->scopeMinaIds($usuario);
                $q->where(function ($w) use ($scope): void {
                    $w->where('ae.destino_tipo', '!=', 'MINA')
                        ->orWhereIn('ae.destino_id', $scope)
                        ->orWhereIn('ae.mina_id', $scope);
                });
            });
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

    private function evalDesBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('evaluacion_desempeno as ed');

        $this->applyDateRange($q, 'ed.fecha', $filters, true);

        if (!empty($filters['destino_tipo'])) {
            $q->where('ed.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $q->where('ed.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['trabajador_id'])) {
            $q->where('ed.trabajador_id', $filters['trabajador_id']);
        }

        if (!empty($filters['supervisor_id'])) {
            $q->where('ed.supervisor_id', $filters['supervisor_id']);
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $scope = $this->scopeMinaIds($usuario);
            $q->where(function ($w) use ($scope): void {
                $w->where('ed.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('ed.destino_id', $scope)
                    ->orWhereIn('ed.mina_id', $scope);
            });
        }

        return $q;
    }

    private function evalSupBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('evaluacion_supervisor as es');

        $this->applyDateRange($q, 'es.fecha', $filters, true);

        if (!empty($filters['destino_tipo'])) {
            $q->where('es.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $q->where('es.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['supervisor_id'])) {
            $q->where('es.evaluador_id', $filters['supervisor_id']);
        }

        if (!empty($filters['trabajador_id'])) {
            $q->where('es.evaluado_id', $filters['trabajador_id']);
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $scope = $this->scopeMinaIds($usuario);
            $q->where(function ($w) use ($scope): void {
                $w->where('es.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('es.destino_id', $scope)
                    ->orWhereIn('es.mina_id', $scope);
            });
        }

        return $q;
    }

    private function evalResBase(Usuario $usuario, array $filters): Builder
    {
        $q = DB::table('evaluacion_residente as er');

        $this->applyDateRange($q, 'er.fecha', $filters, true);

        if (!empty($filters['destino_tipo'])) {
            $q->where('er.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $q->where('er.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['supervisor_id'])) {
            $q->where('er.evaluador_id', $filters['supervisor_id']);
        }

        if (!empty($filters['trabajador_id'])) {
            $q->where('er.residente_id', $filters['trabajador_id']);
        }

        if (!$this->policy->isPrivileged($usuario)) {
            $scope = $this->scopeMinaIds($usuario);
            $q->where(function ($w) use ($scope): void {
                $w->where('er.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('er.destino_id', $scope);
            });
        }

        return $q;
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
