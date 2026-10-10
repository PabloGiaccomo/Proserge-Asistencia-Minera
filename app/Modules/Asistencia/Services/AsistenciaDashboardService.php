<?php

namespace App\Modules\Asistencia\Services;

use App\Models\AsistenciaDetalle;
use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AsistenciaDashboardService
{
    public function __construct(private readonly AsistenciaPolicy $policy)
    {
    }

    public function build(Usuario $usuario, array $filters): array
    {
        if (!$this->policy->manage($usuario)) {
            return $this->emptyDashboard($filters);
        }

        $query = GrupoTrabajo::query()
            ->with([
                'rqMina.mina:id,nombre',
                'supervisor:id,nombre_completo',
                'detalle',
                'asistencia.detalle',
            ])
            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO);

        $this->applyFilters($query, $usuario, $filters);

        $groups = $query
            ->orderByDesc('fecha')
            ->orderByRaw("CASE WHEN turno = 'DIA' THEN 0 ELSE 1 END")
            ->get()
            ->map(fn (GrupoTrabajo $group): array => $this->mapGroup($group));

        $absenceCounts = $this->absenceCountsFor($groups->pluck('asistencia_id')->filter()->values());
        $groups = $groups->map(function (array $group) use ($absenceCounts): array {
            $group['faltas_activas'] = (int) ($absenceCounts[$group['asistencia_id']] ?? 0);

            return $group;
        });

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = in_array((int) ($filters['per_page'] ?? 10), [10, 25, 50], true)
            ? (int) ($filters['per_page'] ?? 10)
            : 10;
        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'filters' => $filters,
            'summary' => $this->summary($groups),
            'trend' => $this->trend($groups),
            'by_mine' => $this->byMine($groups),
            'group_states' => $groups->countBy('estado_asistencia')->map(fn ($count, $state) => [
                'state' => (string) $state,
                'total' => (int) $count,
            ])->values()->all(),
            'priority_groups' => $groups
                ->filter(fn (array $group): bool => $group['estado_asistencia'] !== 'CERRADO' || $group['pendientes'] > 0 || $group['cobertura'] < 85)
                ->sortBy([
                    fn (array $a, array $b) => $b['pendientes'] <=> $a['pendientes'],
                    fn (array $a, array $b) => $a['cobertura'] <=> $b['cobertura'],
                ])
                ->take(6)
                ->values()
                ->all(),
            'groups' => $groups->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
            ],
            'options' => [
                'mines' => $groups->map(fn (array $group) => ['id' => $group['mina_id'], 'name' => $group['mina']])
                    ->filter(fn (array $mine) => filled($mine['id']))
                    ->unique('id')->sortBy('name')->values()->all(),
                'supervisors' => $groups->map(fn (array $group) => ['id' => $group['supervisor_id'], 'name' => $group['supervisor']])
                    ->filter(fn (array $supervisor) => filled($supervisor['id']))
                    ->unique('id')->sortBy('name')->values()->all(),
            ],
        ];
    }

    private function applyFilters(Builder $query, Usuario $usuario, array $filters): void
    {
        if (!empty($filters['fecha_desde'])) {
            $query->whereDate('fecha', '>=', $filters['fecha_desde']);
        }
        if (!empty($filters['fecha_hasta'])) {
            $query->whereDate('fecha', '<=', $filters['fecha_hasta']);
        }
        if (!empty($filters['turno'])) {
            $query->where('turno', strtoupper((string) $filters['turno']));
        }
        if (!empty($filters['mina_id'])) {
            $query->whereHas('rqMina', fn (Builder $rq) => $rq->where('mina_id', $filters['mina_id']));
        }
        if (!empty($filters['supervisor_id'])) {
            $query->where('supervisor_id', $filters['supervisor_id']);
        }
        if (!empty($filters['estado_asistencia'])) {
            $state = strtoupper((string) $filters['estado_asistencia']);
            if ($state === 'PENDIENTE') {
                $query->whereDoesntHave('asistencia');
            } else {
                $query->whereHas('asistencia', fn (Builder $attendance) => $attendance->where('estado', $state));
            }
        }
        if (!empty($filters['buscar'])) {
            $search = trim((string) $filters['buscar']);
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('codigo_grupo', 'like', "%{$search}%")
                    ->orWhere('nombre_snapshot', 'like', "%{$search}%")
                    ->orWhere('servicio', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%")
                    ->orWhereHas('supervisor', fn (Builder $supervisor) => $supervisor->where('nombre_completo', 'like', "%{$search}%"))
                    ->orWhereHas('rqMina.mina', fn (Builder $mine) => $mine->where('nombre', 'like', "%{$search}%"));
            });
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeIds = $usuario->scopesMina->pluck('mina_id')->filter()->values();
            $query->whereHas('rqMina', fn (Builder $rq) => $rq->whereIn('mina_id', $scopeIds));
        }
    }

    private function mapGroup(GrupoTrabajo $group): array
    {
        $scheduled = $group->detalle
            ->filter(fn (GrupoTrabajoDetalle $detail): bool => $detail->isDistribucionActiva());
        $attendance = $group->asistencia;
        $marks = ($attendance?->detalle ?? collect())->reject(
            fn (AsistenciaDetalle $mark): bool => $attendance?->estado !== 'CERRADO' && $mark->pendienteDeMarcacion()
        );
        $present = $marks->whereIn('estado', AsistenciaDetalle::ESTADOS_REAL)->count();
        $notApplicable = $marks->where('estado', AsistenciaDetalle::ESTADO_NO_CORRESPONDE)->count();
        $expected = max(0, $scheduled->count() - $notApplicable);
        $marked = $marks->where('estado', '!=', AsistenciaDetalle::ESTADO_NO_CORRESPONDE)->count();

        return [
            'id' => (string) $group->id,
            'asistencia_id' => $attendance?->id,
            'fecha' => optional($group->fecha)->toDateString(),
            'turno' => (string) ($group->turno ?: 'SIN TURNO'),
            'codigo' => $group->codigo_grupo ?: $group->nombre_snapshot ?: 'Grupo operativo',
            'servicio' => $group->servicio ?: $group->area ?: 'Sin servicio indicado',
            'mina_id' => $group->rqMina?->mina_id,
            'mina' => $group->rqMina?->mina?->nombre ?: $group->mina ?: 'Sin destino',
            'supervisor_id' => $group->supervisor_id,
            'supervisor' => $group->supervisor?->nombre_completo ?: 'Sin responsable',
            'estado_grupo' => (string) $group->estado,
            'estado_asistencia' => $attendance?->estado ?? 'PENDIENTE',
            'programados' => $scheduled->count(),
            'presentes' => $present,
            'tardanzas' => $marks->where('estado', AsistenciaDetalle::ESTADO_TARDANZA)->count(),
            'ausentes' => $marks->where('estado', AsistenciaDetalle::ESTADO_AUSENTE)->count(),
            'justificados' => $marks->where('estado', AsistenciaDetalle::ESTADO_JUSTIFICADO)->count(),
            'no_corresponde' => $notApplicable,
            'pendientes' => max(0, $expected - $marked),
            'cobertura' => $expected > 0 ? round(($present / $expected) * 100, 1) : 0.0,
            'faltas_activas' => 0,
        ];
    }

    private function summary(Collection $groups): array
    {
        $scheduled = (int) $groups->sum('programados');
        $present = (int) $groups->sum('presentes');

        return [
            'groups' => $groups->count(),
            'closed_groups' => $groups->where('estado_asistencia', 'CERRADO')->count(),
            'open_groups' => $groups->where('estado_asistencia', 'REGISTRADO')->count(),
            'pending_groups' => $groups->where('estado_asistencia', 'PENDIENTE')->count(),
            'scheduled' => $scheduled,
            'present' => $present,
            'late' => (int) $groups->sum('tardanzas'),
            'absent' => (int) $groups->sum('ausentes'),
            'justified' => (int) $groups->sum('justificados'),
            'pending_marks' => (int) $groups->sum('pendientes'),
            'active_absences' => (int) $groups->sum('faltas_activas'),
            'coverage' => $scheduled > 0 ? round(($present / $scheduled) * 100, 1) : 0.0,
        ];
    }

    private function trend(Collection $groups): array
    {
        return $groups->groupBy('fecha')->sortKeys()->map(function (Collection $day, string $date): array {
            $scheduled = (int) $day->sum('programados');
            $present = (int) $day->sum('presentes');

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d/m'),
                'scheduled' => $scheduled,
                'present' => $present,
                'absent' => (int) $day->sum('ausentes'),
                'coverage' => $scheduled > 0 ? round(($present / $scheduled) * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    private function byMine(Collection $groups): array
    {
        return $groups->groupBy(fn (array $group) => $group['mina_id'] ?: $group['mina'])
            ->map(function (Collection $mineGroups): array {
                $scheduled = (int) $mineGroups->sum('programados');
                $present = (int) $mineGroups->sum('presentes');

                return [
                    'id' => $mineGroups->first()['mina_id'],
                    'name' => $mineGroups->first()['mina'],
                    'groups' => $mineGroups->count(),
                    'scheduled' => $scheduled,
                    'present' => $present,
                    'absent' => (int) $mineGroups->sum('ausentes'),
                    'pending' => (int) $mineGroups->sum('pendientes'),
                    'coverage' => $scheduled > 0 ? round(($present / $scheduled) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('scheduled')
            ->values()
            ->all();
    }

    private function absenceCountsFor(Collection $attendanceIds): Collection
    {
        if ($attendanceIds->isEmpty()) {
            return collect();
        }

        return DB::table('faltas')
            ->whereIn('asistencia_encabezado_id', $attendanceIds)
            ->where('estado', '!=', 'ANULADA')
            ->selectRaw('asistencia_encabezado_id, COUNT(*) as total')
            ->groupBy('asistencia_encabezado_id')
            ->pluck('total', 'asistencia_encabezado_id');
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        return count(array_intersect(PermissionMatrix::activeRoleNames($usuario), ['ADMIN', 'GERENTE', 'SUPERADMIN'])) > 0
            || PermissionMatrix::userCanDirect($usuario, 'asistencias', 'administrar');
    }

    private function emptyDashboard(array $filters): array
    {
        return [
            'filters' => $filters,
            'summary' => array_fill_keys(['groups', 'closed_groups', 'open_groups', 'pending_groups', 'scheduled', 'present', 'late', 'absent', 'justified', 'pending_marks', 'active_absences'], 0) + ['coverage' => 0.0],
            'trend' => [],
            'by_mine' => [],
            'group_states' => [],
            'priority_groups' => [],
            'groups' => [],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0],
            'options' => ['mines' => [], 'supervisors' => []],
        ];
    }
}
