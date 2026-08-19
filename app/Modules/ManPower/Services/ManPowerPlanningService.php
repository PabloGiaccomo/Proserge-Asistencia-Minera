<?php

namespace App\Modules\ManPower\Services;

use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\RQMina;
use App\Models\RQMinaActividad;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaPlan;
use App\Models\RQProserge;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\ManPower\Policies\ManPowerPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ManPowerPlanningService
{
    public function __construct(private readonly ManPowerPolicy $policy)
    {
    }

    public function buildContext(Usuario $usuario, array $filters): array
    {
        $fecha = $this->normalizeDate((string) ($filters['fecha'] ?? now()->addDay()->toDateString()));
        $turno = $this->normalizeTurno((string) ($filters['turno'] ?? 'DIA')) ?: 'DIA';

        $paradas = $this->listParadas($usuario, (string) ($filters['q'] ?? ''), $fecha);
        $rqMinaId = (string) ($filters['rq_mina_id'] ?? '');

        if ($rqMinaId === '' || !$paradas->contains(fn (array $item): bool => $item['rq_mina_id'] === $rqMinaId)) {
            $rqMinaId = (string) ($paradas->first()['rq_mina_id'] ?? '');
        }

        $rqMina = $rqMinaId !== ''
            ? RQMina::query()->with(['mina:id,nombre', 'planes', 'rqProserge', 'detalle'])->find($rqMinaId)
            : null;

        if (!$rqMina || !$this->policy->canAccessMina($usuario, (string) $rqMina->mina_id)) {
            return $this->emptyContext($paradas, $fecha, $turno);
        }

        $plan = $this->resolvePlan($rqMina, (string) ($filters['plan_id'] ?? ''), $fecha);
        $fecha = $this->clampDateToPlan($fecha, $plan);
        $rqProsergeIds = $rqMina->rqProserge->pluck('id')->map(fn ($id): string => (string) $id)->values();
        $allOperationalGroups = $this->buildOperationalGroups($rqMina, $plan, $fecha, $turno);
        $activities = $this->activityOptions($allOperationalGroups);
        $activity = $this->resolveActivity($activities, (string) ($filters['actividad_id'] ?? ''));
        $activityId = (string) ($activity['id'] ?? '');
        $gruposOperativos = $this->filterOperationalGroupsByActivity($allOperationalGroups, $activityId);
        $manPowerGroups = $this->listGroups($rqMina->id, $plan?->id, $fecha, $turno, $activityId);
        $gruposOperativos = $this->attachActualCoverage($gruposOperativos, $manPowerGroups);
        $distributed = $this->distributedMap($rqMina->id, $fecha, $turno);
        $oppositeTurn = $turno === 'NOCHE' ? 'DIA' : 'NOCHE';
        $distributedOppositeTurn = $this->distributedMap($rqMina->id, $fecha, $oppositeTurn);
        $assignments = $this->listAssignments($rqProsergeIds, $fecha, $distributed, $distributedOppositeTurn, $oppositeTurn);
        $positions = $this->buildPositionCoverage($rqMina, $assignments, $manPowerGroups);
        $summary = $this->summary($gruposOperativos, $manPowerGroups, $assignments, $distributed);

        return [
            'paradas' => $paradas->values()->all(),
            'selected' => [
                'rq_mina_id' => $rqMina->id,
                'plan_id' => $plan?->id,
                'actividad_id' => $activityId,
                'fecha' => $fecha,
                'turno' => $turno,
            ],
            'rq_mina' => [
                'id' => $rqMina->id,
                'mina_id' => $rqMina->mina_id,
                'mina_nombre' => $rqMina->mina?->nombre,
                'destino_tipo' => $rqMina->destino_tipo ?? 'MINA',
                'destino_id' => $rqMina->destino_id ?? $rqMina->mina_id,
                'destino_nombre' => $rqMina->destino_nombre ?? $rqMina->mina?->nombre,
                'area' => $rqMina->area,
                'fecha_inicio' => optional($rqMina->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($rqMina->fecha_fin)->toDateString(),
                'estado' => $rqMina->estado,
            ],
            'plans' => $rqMina->planes->map(fn (RQMinaPlan $item): array => [
                'id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'estado' => $item->estado,
                'fecha_inicio' => optional($item->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($item->fecha_fin)->toDateString(),
            ])->values()->all(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'codigo' => $plan->codigo,
                'nombre' => $plan->nombre,
                'estado' => $plan->estado,
                'fecha_inicio' => optional($plan->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($plan->fecha_fin)->toDateString(),
                'archivado' => $plan->estado === RQMinaPlan::ESTADO_ARCHIVADO,
            ] : null,
            'actividades' => $activities,
            'actividad' => $activity,
            'rq_proserge_ids' => $rqProsergeIds->all(),
            'grupos_operativos' => $gruposOperativos,
            'grupos_man_power' => $manPowerGroups,
            'asignaciones' => $assignments,
            'puestos' => $positions,
            'distribuidos' => $distributed,
            'resumen' => $summary,
            'legacy' => [
                'grupos' => collect($manPowerGroups)->filter(fn (array $group): bool => (bool) ($group['legacy'] ?? false))->values()->all(),
            ],
        ];
    }

    public function cantidadPlanificada(RQMinaActividad $actividad, string $fecha, string $turno): int
    {
        $turnoRow = $actividad->turnos->first(function ($row) use ($fecha): bool {
            return optional($row->fecha)->toDateString() === $fecha;
        });

        if (!$turnoRow) {
            $weekday = $this->weekdayKey($fecha);
            $turnoRow = $actividad->turnos->first(function ($row) use ($weekday): bool {
                return !$row->fecha && $this->dayLabelKey((string) $row->dia_label) === $weekday;
            });
        }

        if (!$turnoRow) {
            return 0;
        }

        $value = $turno === 'NOCHE' ? $turnoRow->turno_b : $turnoRow->turno_a;

        return $this->numericValue($value);
    }

    public function buildPeriodSummary(Usuario $usuario, array $selection): array
    {
        $rqMinaId = (string) ($selection['rq_mina_id'] ?? '');
        $planId = (string) ($selection['plan_id'] ?? '');
        $activityId = (string) ($selection['actividad_id'] ?? '');
        $fallbackDate = $this->normalizeDate((string) ($selection['fecha'] ?? now()->toDateString()));

        $rqMina = RQMina::query()->with('rqProserge:id,rq_mina_id')->find($rqMinaId);
        if (!$rqMina || !$this->policy->canAccessMina($usuario, (string) $rqMina->mina_id)) {
            return $this->emptyPeriodSummary($fallbackDate);
        }

        $plan = $planId !== ''
            ? RQMinaPlan::query()->where('rq_mina_id', $rqMina->id)->find($planId)
            : null;
        $periodStart = CarbonImmutable::parse($plan?->fecha_inicio ?? $rqMina->fecha_inicio ?? $fallbackDate)->startOfDay();
        $periodEnd = CarbonImmutable::parse($plan?->fecha_fin ?? $rqMina->fecha_fin ?? $fallbackDate)->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            [$periodStart, $periodEnd] = [$periodEnd, $periodStart];
        }

        $activity = $activityId !== ''
            ? RQMinaActividad::query()
                ->with(['turnos', 'grupo:id,rq_mina_id,rq_mina_plan_id'])
                ->whereHas('grupo', function ($query) use ($rqMina, $plan): void {
                    $query->where('rq_mina_id', $rqMina->id);
                    if ($plan) {
                        $query->where(function ($planQuery) use ($plan): void {
                            $planQuery->where('rq_mina_plan_id', $plan->id)
                                ->orWhereNull('rq_mina_plan_id');
                        });
                    }
                })
                ->find($activityId)
            : null;

        $referenceByTurn = ['DIA' => 0, 'NOCHE' => 0];
        $daysWithReference = 0;
        for ($cursor = $periodStart; $cursor->lte($periodEnd); $cursor = $cursor->addDay()) {
            $dayReference = $activity ? $this->cantidadPlanificada($activity, $cursor->toDateString(), 'DIA') : 0;
            $nightReference = $activity ? $this->cantidadPlanificada($activity, $cursor->toDateString(), 'NOCHE') : 0;
            $referenceByTurn['DIA'] += $dayReference;
            $referenceByTurn['NOCHE'] += $nightReference;
            if (($dayReference + $nightReference) > 0) {
                $daysWithReference++;
            }
        }

        $groupQuery = GrupoTrabajo::query()
            ->with('detalle')
            ->where('rq_mina_id', $rqMina->id)
            ->whereBetween('fecha', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO);

        if ($plan && Schema::hasColumn('grupo_trabajo', 'rq_mina_plan_id')) {
            $groupQuery->where('rq_mina_plan_id', $plan->id);
        }

        if ($activity && Schema::hasTable('grupo_trabajo_actividades')) {
            $groupQuery->whereHas('actividades', fn ($query) => $query->where('rq_mina_actividades.id', $activity->id));
        } elseif ($activity) {
            $groupQuery->where('rq_mina_actividad_grupo_id', $activity->grupo_id);
        }

        $groups = $groupQuery->get();
        $activeDetails = $groups->flatMap(fn (GrupoTrabajo $group) => $group->detalle
            ->filter(fn (GrupoTrabajoDetalle $detail): bool => $this->detailIsActive($detail)));
        $assignedByTurn = $groups->groupBy('turno')->map(fn (Collection $turnGroups): int => $turnGroups
            ->flatMap(fn (GrupoTrabajo $group) => $group->detalle
                ->filter(fn (GrupoTrabajoDetalle $detail): bool => $this->detailIsActive($detail)))
            ->count());
        $groupsByTurn = $groups->groupBy('turno')->map->count();
        $totalReference = array_sum($referenceByTurn);
        $totalAssigned = $activeDetails->count();
        $rqProsergeIds = $rqMina->rqProserge->pluck('id')->filter()->values();
        $approvedPersonnel = $rqProsergeIds->isEmpty()
            ? 0
            : RQProsergeDetalle::query()
                ->whereIn('rq_proserge_id', $rqProsergeIds->all())
                ->whereIn('estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
                ->whereDate('fecha_inicio', '<=', $periodEnd->toDateString())
                ->whereDate('fecha_fin', '>=', $periodStart->toDateString())
                ->distinct('personal_id')
                ->count('personal_id');

        return [
            'fecha_inicio' => $periodStart->toDateString(),
            'fecha_fin' => $periodEnd->toDateString(),
            'dias_periodo' => (int) $periodStart->diffInDays($periodEnd) + 1,
            'dias_con_referencia' => $daysWithReference,
            'dias_con_grupos' => $groups->pluck('fecha')->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())->unique()->count(),
            'personal_aprobado' => $approvedPersonnel,
            'personal_unico_distribuido' => $activeDetails->pluck('personal_id')->filter()->unique()->count(),
            'grupos_total' => $groups->count(),
            'referencia_total' => $totalReference,
            'distribuciones_total' => $totalAssigned,
            'diferencia' => $totalAssigned - $totalReference,
            'cobertura_porcentaje' => $totalReference > 0 ? round(($totalAssigned / $totalReference) * 100, 1) : null,
            'turnos' => collect(['DIA', 'NOCHE'])->mapWithKeys(function (string $turn) use ($referenceByTurn, $assignedByTurn, $groupsByTurn): array {
                $reference = (int) ($referenceByTurn[$turn] ?? 0);
                $assigned = (int) ($assignedByTurn[$turn] ?? 0);

                return [$turn => [
                    'referencia' => $reference,
                    'distribuciones' => $assigned,
                    'diferencia' => $assigned - $reference,
                    'grupos' => (int) ($groupsByTurn[$turn] ?? 0),
                    'cobertura_porcentaje' => $reference > 0 ? round(($assigned / $reference) * 100, 1) : null,
                ]];
            })->all(),
        ];
    }

    public function buildGroupSnapshot(RQMinaActividadGrupo $grupoOperativo, string $fecha, string $turno, array $activityIds): array
    {
        $grupoOperativo->loadMissing(['actividades.turnos', 'actividades.transportes']);
        $selected = $grupoOperativo->actividades
            ->filter(fn (RQMinaActividad $actividad): bool => in_array($actividad->id, $activityIds, true));

        if ($selected->isEmpty()) {
            $selected = $grupoOperativo->actividades;
        }

        $supervisorColumn = $turno === 'NOCHE' ? 'supervisor_campo_noche' : 'supervisor_campo_dia';
        $seguridadColumn = $turno === 'NOCHE' ? 'supervisor_seguridad_noche' : 'supervisor_seguridad_dia';
        $quantities = $selected->mapWithKeys(fn (RQMinaActividad $actividad): array => [
            $actividad->id => $this->cantidadPlanificada($actividad, $fecha, $turno),
        ]);
        $primaryActivity = $selected->first();

        return [
            'nombre_snapshot' => $primaryActivity?->sait ?: $grupoOperativo->nombre,
            'area_snapshot' => $primaryActivity?->area ?: $grupoOperativo->area_operativa,
            'sector_snapshot' => $primaryActivity?->sector,
            'modulo_snapshot' => $grupoOperativo->modulo,
            'sait_snapshot' => $selected->pluck('sait')->filter()->unique()->implode(', '),
            'supervisor_operativo_snapshot' => $selected->pluck($supervisorColumn)->filter()->unique()->implode(', '),
            'supervisor_seguridad_snapshot' => $selected->pluck($seguridadColumn)->filter()->unique()->implode(', '),
            'cantidad_planificada_snapshot' => (int) ($quantities->first() ?? 0),
            'actividad_cantidades' => $quantities->all(),
        ];
    }

    private function listParadas(Usuario $usuario, string $search, string $fecha): Collection
    {
        if (!$this->policy->viewParadas($usuario)) {
            return collect();
        }

        $query = RQMina::query()->with(['mina:id,nombre,unidad_minera', 'planes'])
            ->whereExists(function ($q) use ($fecha): void {
                $q->select(DB::raw(1))
                    ->from('rq_proserge as rp')
                    ->join('rq_proserge_detalle as rpd', 'rpd.rq_proserge_id', '=', 'rp.id')
                    ->whereColumn('rp.rq_mina_id', 'rq_mina.id')
                    ->whereIn('rpd.estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('rpd.fecha_inicio')
                            ->orWhereDate('rpd.fecha_inicio', '<=', $fecha);
                    })
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('rpd.fecha_fin')
                            ->orWhereDate('rpd.fecha_fin', '>=', $fecha);
                    });
            });

        $query
            ->whereNotIn('estado', ['CERRADO', 'CANCELADO'])
            ->where(function ($dateQuery) use ($fecha): void {
                $dateQuery
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('fecha_inicio')
                            ->orWhereDate('fecha_inicio', '<=', $fecha);
                    })
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('fecha_fin')
                            ->orWhereDate('fecha_fin', '>=', $fecha);
                    });
            });

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery
                    ->where('rq_mina.area', 'like', $like)
                    ->orWhere('rq_mina.destino_nombre', 'like', $like)
                    ->orWhereHas('mina', fn ($minaQuery) => $minaQuery->where('nombre', 'like', $like));
            });
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeMinaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereIn('mina_id', $scopeMinaIds);
        }

        return $query->orderByDesc('created_at')->get()->map(function (RQMina $rq): array {
            return [
                'rq_mina_id' => $rq->id,
                'mina_id' => $rq->mina_id,
                'mina_nombre' => $rq->mina?->nombre,
                'unidad_minera' => $rq->mina?->unidad_minera,
                'destino_nombre' => $rq->destino_nombre ?? $rq->mina?->nombre,
                'area' => $rq->area,
                'fecha_inicio' => optional($rq->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($rq->fecha_fin)->toDateString(),
                'planes_count' => $rq->planes->count(),
            ];
        });
    }

    private function resolvePlan(RQMina $rqMina, string $planId, string $fecha): ?RQMinaPlan
    {
        $plans = $rqMina->planes;

        if ($planId !== '') {
            $selected = $plans->firstWhere('id', $planId);
            if ($selected) {
                return $selected;
            }
        }

        return $plans->first(function (RQMinaPlan $plan) use ($fecha): bool {
            return $plan->estado === RQMinaPlan::ESTADO_VIGENTE
                && $plan->fecha_inicio?->toDateString() <= $fecha
                && $plan->fecha_fin?->toDateString() >= $fecha;
        }) ?? $plans->first();
    }

    private function clampDateToPlan(string $fecha, ?RQMinaPlan $plan): string
    {
        if (!$plan || !$plan->fecha_inicio || !$plan->fecha_fin) {
            return $fecha;
        }

        if ($fecha < $plan->fecha_inicio->toDateString()) {
            return $plan->fecha_inicio->toDateString();
        }

        if ($fecha > $plan->fecha_fin->toDateString()) {
            return $plan->fecha_fin->toDateString();
        }

        return $fecha;
    }

    private function buildOperationalGroups(RQMina $rqMina, ?RQMinaPlan $plan, string $fecha, string $turno): array
    {
        $query = RQMinaActividadGrupo::query()
            ->with(['actividades.turnos', 'actividades.transportes'])
            ->where('rq_mina_id', $rqMina->id)
            ->orderBy('orden');

        if ($plan) {
            $hasPlanGroups = RQMinaActividadGrupo::query()
                ->where('rq_mina_id', $rqMina->id)
                ->where('rq_mina_plan_id', $plan->id)
                ->exists();

            if ($hasPlanGroups) {
                $query->where('rq_mina_plan_id', $plan->id);
            } else {
                $query->whereNull('rq_mina_plan_id');
            }
        } else {
            $query->whereNull('rq_mina_plan_id');
        }

        return $query->get()->map(function (RQMinaActividadGrupo $grupo) use ($fecha, $turno): array {
            $activities = $grupo->actividades->map(function (RQMinaActividad $actividad) use ($fecha, $turno): array {
                return [
                    'id' => $actividad->id,
                    'sait' => $actividad->sait,
                    'sector' => $actividad->sector,
                    'area' => $actividad->area,
                    'trabajo' => $actividad->ait_trabajo,
                    'cantidad_planificada' => $this->cantidadPlanificada($actividad, $fecha, $turno),
                ];
            })->values();

            $supervisorColumn = $turno === 'NOCHE' ? 'supervisor_campo_noche' : 'supervisor_campo_dia';
            $seguridadColumn = $turno === 'NOCHE' ? 'supervisor_seguridad_noche' : 'supervisor_seguridad_dia';

            return [
                'id' => $grupo->id,
                'rq_mina_plan_id' => $grupo->rq_mina_plan_id,
                'nombre' => $grupo->nombre,
                'codigo' => $grupo->modulo ?: $grupo->area_operativa,
                'area' => $grupo->area_operativa,
                'modulo' => $grupo->modulo,
                'supervisor_operativo' => $grupo->actividades->pluck($supervisorColumn)->filter()->unique()->implode(', '),
                'supervisor_seguridad' => $grupo->actividades->pluck($seguridadColumn)->filter()->unique()->implode(', '),
                'actividades' => $activities->all(),
                'actividad_referencia_id' => $activities->first()['id'] ?? null,
                'requerido' => (int) ($activities->first()['cantidad_planificada'] ?? 0),
                'transportes' => $grupo->transportes->map(fn ($item): array => [
                    'alcance' => $item->alcance,
                    'unidad_carga' => $item->unidad_carga,
                    'transporte' => $item->unidades_transporte,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    private function activityOptions(array $operationalGroups): array
    {
        return collect($operationalGroups)->flatMap(function (array $group): array {
            return collect($group['actividades'] ?? [])->map(fn (array $activity): array => [
                'id' => (string) ($activity['id'] ?? ''),
                'grupo_id' => (string) ($group['id'] ?? ''),
                'grupo_nombre' => (string) ($group['nombre'] ?? ''),
                'grupo_area' => (string) ($group['area'] ?? ''),
                'modulo' => (string) ($group['modulo'] ?? ''),
                'sait' => (string) ($activity['sait'] ?? ''),
                'sector' => (string) ($activity['sector'] ?? ''),
                'area' => (string) ($activity['area'] ?? ''),
                'trabajo' => (string) ($activity['trabajo'] ?? ''),
            ])->all();
        })->filter(fn (array $activity): bool => $activity['id'] !== '')->values()->all();
    }

    private function resolveActivity(array $activities, string $activityId): ?array
    {
        $items = collect($activities);

        return $items->firstWhere('id', $activityId) ?? $items->first();
    }

    private function filterOperationalGroupsByActivity(array $operationalGroups, string $activityId): array
    {
        if ($activityId === '') {
            return $operationalGroups;
        }

        return collect($operationalGroups)->map(function (array $group) use ($activityId): ?array {
            $activity = collect($group['actividades'] ?? [])->firstWhere('id', $activityId);
            if (!$activity) {
                return null;
            }

            $group['actividades'] = [$activity];
            $group['actividad_referencia_id'] = $activityId;
            $group['requerido'] = (int) ($activity['cantidad_planificada'] ?? 0);

            return $group;
        })->filter()->values()->all();
    }

    private function attachActualCoverage(array $operationalGroups, array $manPowerGroups): array
    {
        $groups = collect($manPowerGroups);
        $unlinkedAssigned = (int) $groups
            ->filter(fn (array $group): bool => empty($group['rq_mina_actividad_grupo_id']))
            ->sum('asignado');
        $singleOperationalGroup = count($operationalGroups) === 1;

        return collect($operationalGroups)->map(function (array $group) use ($groups, $unlinkedAssigned, $singleOperationalGroup): array {
            $linkedAssigned = (int) $groups
                ->where('rq_mina_actividad_grupo_id', $group['id'])
                ->sum('asignado');

            $group['asignado'] = $linkedAssigned + ($singleOperationalGroup ? $unlinkedAssigned : 0);
            $group['brecha'] = max(0, (int) $group['requerido'] - (int) $group['asignado']);
            $group['exceso'] = max(0, (int) $group['asignado'] - (int) $group['requerido']);

            return $group;
        })->values()->all();
    }

    private function buildPositionCoverage(RQMina $rqMina, array $assignments, array $manPowerGroups): array
    {
        $assignmentMap = collect($assignments)->keyBy('rq_proserge_detalle_id');
        $distributedByPosition = collect($manPowerGroups)
            ->flatMap(fn (array $group): array => $group['detalle'] ?? [])
            ->filter(fn (array $detail): bool => ($detail['estado_distribucion'] ?? GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO) === GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO)
            ->pluck('rq_proserge_detalle_id')
            ->filter()
            ->unique()
            ->map(fn (string $assignmentId) => data_get($assignmentMap->get($assignmentId), 'rq_mina_detalle_id'))
            ->filter()
            ->countBy();

        return $rqMina->detalle
            ->sortBy(fn ($item): string => Str::lower((string) $item->puesto))
            ->map(function ($detail) use ($distributedByPosition): array {
                $base = max(0, (int) $detail->cantidad);
                $backup = max(0, (int) $detail->cantidad_backup);
                $total = $detail->cantidad_total !== null
                    ? max(0, (int) $detail->cantidad_total)
                    : $base + $backup;

                return [
                    'id' => (string) $detail->id,
                    'puesto' => (string) $detail->puesto,
                    'cantidad_rq' => $base,
                    'cantidad_backup' => $backup,
                    'cantidad_total' => $total,
                    'cantidad_atendida' => max(0, (int) $detail->cantidad_atendida),
                    'distribuidos_turno' => (int) ($distributedByPosition[(string) $detail->id] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function listGroups(string $rqMinaId, ?string $planId, string $fecha, string $turno, string $activityId = ''): array
    {
        $relations = [
            'supervisor:id,nombre_completo,puesto,dni,numero_documento',
            'rqMina:id,mina_id,area,fecha_inicio,fecha_fin,estado',
            'rqMina.mina:id,nombre',
            'rqProserge:id,estado',
            'detalle.personal:id,nombre_completo,puesto,dni,numero_documento',
            'asistencia:id,grupo_trabajo_id,estado',
        ];

        if (Schema::hasTable('grupo_trabajo_actividades')) {
            $relations[] = 'actividades:id';
        }

        if (Schema::hasColumn('grupo_trabajo_detalle', 'rq_proserge_detalle_id')) {
            $relations[] = 'detalle.rqProsergeDetalle:id,posicion_asignacion,tipo_asignacion,puesto_asignado,estado_habilitacion_snapshot,estado';
        }

        $query = GrupoTrabajo::query()->with($relations)
            ->where('rq_mina_id', $rqMinaId)
            ->whereDate('fecha', $fecha)
            ->where('turno', $turno)
            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO)
            ->orderBy('area')
            ->orderBy('paradero')
            ->orderBy('horario_salida');

        if ($planId && Schema::hasColumn('grupo_trabajo', 'rq_mina_plan_id')) {
            $query->where(function ($q) use ($planId): void {
                $q->where('rq_mina_plan_id', $planId)
                    ->orWhereNull('rq_mina_plan_id');
            });
        }

        if ($activityId !== '' && Schema::hasTable('grupo_trabajo_actividades')) {
            $query->whereHas('actividades', fn ($activityQuery) => $activityQuery->where('rq_mina_actividades.id', $activityId));
        }

        return $query->get()->map(fn (GrupoTrabajo $grupo): array => $this->groupArray($grupo))->values()->all();
    }

    private function groupArray(GrupoTrabajo $grupo): array
    {
        $activos = $grupo->detalle->filter(fn (GrupoTrabajoDetalle $item): bool => $this->detailIsActive($item));
        $requerido = (int) ($grupo->cantidad_planificada_snapshot ?? 0);
        $asignado = $activos->count();
        $brecha = max(0, $requerido - $asignado);
        $exceso = max(0, $asignado - $requerido);

        return [
            'id' => $grupo->id,
            'fecha' => optional($grupo->fecha)->toDateString(),
            'turno' => $grupo->turno,
            'estado' => $grupo->estado,
            'rq_mina_id' => $grupo->rq_mina_id,
            'rq_proserge_id' => $grupo->rq_proserge_id,
            'rq_mina_plan_id' => $grupo->rq_mina_plan_id,
            'rq_mina_actividad_grupo_id' => $grupo->rq_mina_actividad_grupo_id,
            'servicio' => $grupo->servicio,
            'area' => $grupo->area,
            'paradero' => $grupo->paradero,
            'horario_salida' => $grupo->horario_salida,
            'unidad' => $grupo->unidad,
            'destino_tipo' => $grupo->destino_tipo,
            'destino_id' => $grupo->destino_id,
            'observaciones' => $grupo->observaciones,
            'observacion_planificacion' => $grupo->observacion_planificacion,
            'justificacion_brecha' => $grupo->justificacion_brecha,
            'nombre_snapshot' => $grupo->nombre_snapshot,
            'area_snapshot' => $grupo->area_snapshot,
            'sector_snapshot' => $grupo->sector_snapshot,
            'modulo_snapshot' => $grupo->modulo_snapshot,
            'sait_snapshot' => $grupo->sait_snapshot,
            'supervisor_operativo_snapshot' => $grupo->supervisor_operativo_snapshot,
            'supervisor_seguridad_snapshot' => $grupo->supervisor_seguridad_snapshot,
            'requerido' => $requerido,
            'asignado' => $asignado,
            'brecha' => $brecha,
            'exceso' => $exceso,
            'porcentaje' => $requerido > 0 ? round(($asignado / $requerido) * 100, 1) : null,
            'legacy' => !$grupo->rq_mina_plan_id || !$grupo->rq_mina_actividad_grupo_id,
            'supervisor' => [
                'id' => $grupo->supervisor?->id,
                'nombre_completo' => $grupo->supervisor?->nombre_completo,
                'puesto' => $grupo->supervisor?->puesto,
            ],
            'asistencia' => [
                'id' => $grupo->asistencia?->id,
                'estado' => $grupo->asistencia?->estado ?? 'PENDIENTE',
                'cerrada' => $grupo->asistencia?->estado === 'CERRADO',
            ],
            'actividad_ids' => Schema::hasTable('grupo_trabajo_actividades') && $grupo->relationLoaded('actividades')
                ? $grupo->actividades->pluck('id')->values()->all()
                : [],
            'detalle' => $grupo->detalle->map(fn (GrupoTrabajoDetalle $item): array => $this->detailArray($item))->values()->all(),
        ];
    }

    private function detailArray(GrupoTrabajoDetalle $item): array
    {
        return [
            'id' => $item->id,
            'personal_id' => $item->personal_id,
            'rq_proserge_detalle_id' => $item->rq_proserge_detalle_id,
            'puesto_asignado_snapshot' => $item->puesto_asignado_snapshot,
            'posicion_asignacion_snapshot' => $item->posicion_asignacion_snapshot,
            'tipo_asignacion_snapshot' => $item->tipo_asignacion_snapshot,
            'estado_habilitacion_snapshot' => $item->estado_habilitacion_snapshot,
            'estado_distribucion' => $item->estado_distribucion ?? GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
            'motivo_retiro' => $item->motivo_retiro,
            'estado_asistencia' => $item->estado_asistencia,
            'personal' => [
                'id' => $item->personal?->id,
                'nombre_completo' => $item->personal?->nombre_completo,
                'dni' => $item->personal?->dni ?: $item->personal?->numero_documento,
                'puesto' => $item->personal?->puesto,
            ],
        ];
    }

    private function distributedMap(string $rqMinaId, string $fecha, string $turno): array
    {
        $query = DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->where('gt.rq_mina_id', $rqMinaId)
            ->whereDate('gt.fecha', $fecha)
            ->where('gt.turno', $turno)
            ->whereNotIn('gt.estado', [GrupoTrabajo::ESTADO_CANCELADO]);

        if (Schema::hasColumn('grupo_trabajo_detalle', 'estado_distribucion')) {
            $query->where(function ($state): void {
                $state->where('gtd.estado_distribucion', GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO)
                    ->orWhereNull('gtd.estado_distribucion');
            });
        }

        $assignmentColumn = Schema::hasColumn('grupo_trabajo_detalle', 'rq_proserge_detalle_id')
            ? 'gtd.rq_proserge_detalle_id'
            : DB::raw('NULL as rq_proserge_detalle_id');

        return $query->select([
            'gtd.personal_id',
            $assignmentColumn,
            'gt.id as grupo_id',
            'gt.servicio',
            'gt.area',
            'gt.paradero',
        ])->get()->mapWithKeys(function ($row): array {
            $key = $row->rq_proserge_detalle_id ?: 'personal:'.$row->personal_id;

            return [$key => [
                'personal_id' => $row->personal_id,
                'rq_proserge_detalle_id' => $row->rq_proserge_detalle_id,
                'grupo_id' => $row->grupo_id,
                'label' => trim(collect([$row->servicio, $row->area, $row->paradero])->filter()->implode(' - ')),
            ]];
        })->all();
    }

    private function listAssignments(Collection $rqProsergeIds, string $fecha, array $distributed, array $distributedOppositeTurn, string $oppositeTurn): array
    {
        if ($rqProsergeIds->isEmpty()) {
            return [];
        }

        /** @var EloquentCollection<int, RQProsergeDetalle> $items */
        $items = RQProsergeDetalle::query()
            ->with([
                'personal:id,nombre_completo,puesto,dni,numero_documento',
                'rqMinaDetalle:id,puesto,cantidad,cantidad_backup,cantidad_total,compartible_man_power',
                'rqProserge:id,rq_mina_id,estado',
            ])
            ->whereIn('rq_proserge_id', $rqProsergeIds->all())
            ->whereIn('estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->orderBy('created_at')
            ->get();

        return $items->map(function (RQProsergeDetalle $item) use ($distributed, $distributedOppositeTurn, $oppositeTurn): array {
            $distributedByAssignment = $distributed[$item->id] ?? null;
            $distributedByPerson = $distributed['personal:'.$item->personal_id] ?? null;
            $currentGroup = $distributedByAssignment ?: $distributedByPerson;
            $oppositeByAssignment = $distributedOppositeTurn[$item->id] ?? null;
            $oppositeByPerson = $distributedOppositeTurn['personal:'.$item->personal_id] ?? null;
            $oppositeGroup = $oppositeByAssignment ?: $oppositeByPerson;
            $shareable = (bool) ($item->rqMinaDetalle?->compartible_man_power ?? false);
            $available = $oppositeGroup === null && ($currentGroup === null || $shareable);

            return [
                'rq_proserge_detalle_id' => $item->id,
                'rq_proserge_id' => $item->rq_proserge_id,
                'rq_mina_detalle_id' => $item->rq_mina_detalle_id,
                'personal_id' => $item->personal_id,
                'trabajador' => $item->personal?->nombre_completo,
                'dni' => $item->personal?->dni ?: $item->personal?->numero_documento,
                'cargo_solicitado' => $item->rqMinaDetalle?->puesto,
                'cargo_compartible' => $shareable,
                'puesto_asignado' => $item->puesto_asignado_snapshot ?: $item->puesto_asignado ?: $item->personal?->puesto,
                'puesto_actual' => $item->personal?->puesto,
                'posicion' => $item->posicion_asignacion,
                'tipo' => $item->tipo_asignacion,
                'sin_clasificar' => $item->isSinClasificar(),
                'habilitacion' => $item->estado_habilitacion_snapshot,
                'fecha_inicio' => optional($item->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($item->fecha_fin)->toDateString(),
                'estado' => $oppositeGroup
                    ? 'DISTRIBUIDO_OTRO_TURNO'
                    : ($currentGroup && $shareable ? 'COMPARTIDO' : ($available ? 'DISPONIBLE' : 'DISTRIBUIDO')),
                'disponible' => $available,
                'grupo_actual' => $currentGroup ?: $oppositeGroup,
                'turno_bloqueado' => $oppositeGroup ? $oppositeTurn : null,
            ];
        })->values()->all();
    }

    private function summary(array $gruposOperativos, array $groups, array $assignments, array $distributed): array
    {
        $distributedAssignments = collect($assignments)->filter(fn (array $item): bool => !($item['disponible'] ?? false));
        $manPowerGroups = collect($groups);
        $required = collect($gruposOperativos)->sum('requerido');
        $assigned = $manPowerGroups->sum('asignado');

        return [
            'total_aprobado_activo' => count($assignments),
            'total_disponible' => collect($assignments)->where('disponible', true)->count(),
            'total_distribuido' => $assigned,
            'total_pendiente' => collect($assignments)->where('disponible', true)->count(),
            'requeridos_por_plan' => (int) $required,
            'brecha' => max(0, (int) $required - (int) $assigned),
            'exceso' => max(0, (int) $assigned - (int) $required),
            'titulares_distribuidos' => $distributedAssignments->where('posicion', RQProsergeDetalle::POSICION_TITULAR)->count(),
            'suplentes_distribuidos' => $distributedAssignments->where('posicion', RQProsergeDetalle::POSICION_SUPLENTE)->count(),
            'adicionales_distribuidos' => $distributedAssignments->where('tipo', RQProsergeDetalle::TIPO_ADICIONAL)->count(),
            'sin_clasificar_distribuidos' => $distributedAssignments->where('sin_clasificar', true)->count(),
            'grupos_sin_responsable' => $manPowerGroups->filter(fn (array $group): bool => empty($group['supervisor']['id'] ?? null))->count(),
            'grupos_con_brecha' => collect($gruposOperativos)->filter(fn (array $group): bool => (int) ($group['brecha'] ?? 0) > 0)->count(),
            'trabajadores_duplicados_detectados' => collect($distributed)->pluck('personal_id')->duplicates()->count(),
        ];
    }

    private function detailIsActive(GrupoTrabajoDetalle $item): bool
    {
        if (!Schema::hasColumn('grupo_trabajo_detalle', 'estado_distribucion')) {
            return true;
        }

        return ($item->estado_distribucion ?? GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO) === GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO;
    }

    private function numericValue(mixed $value): int
    {
        $text = trim((string) $value);

        if ($text === '' || !is_numeric($text)) {
            return 0;
        }

        return max(0, (int) $text);
    }

    private function weekdayKey(string $fecha): string
    {
        $keys = [1 => 'lun', 2 => 'mar', 3 => 'mie', 4 => 'jue', 5 => 'vie', 6 => 'sab', 7 => 'dom'];

        return $keys[CarbonImmutable::parse($fecha)->isoWeekday()] ?? '';
    }

    private function dayLabelKey(string $label): string
    {
        return Str::of($label)->ascii()->lower()->substr(0, 3)->toString();
    }

    private function normalizeDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (\Throwable) {
            return now()->addDay()->toDateString();
        }
    }

    private function normalizeTurno(string $turno): ?string
    {
        $value = strtoupper(trim($turno));

        return in_array($value, ['DIA', 'NOCHE'], true) ? $value : null;
    }

    private function emptyContext(Collection $paradas, string $fecha, string $turno): array
    {
        return [
            'paradas' => $paradas->values()->all(),
            'selected' => [
                'rq_mina_id' => '',
                'plan_id' => '',
                'actividad_id' => '',
                'fecha' => $fecha,
                'turno' => $turno,
            ],
            'rq_mina' => null,
            'plans' => [],
            'plan' => null,
            'actividades' => [],
            'actividad' => null,
            'rq_proserge_ids' => [],
            'grupos_operativos' => [],
            'grupos_man_power' => [],
            'asignaciones' => [],
            'puestos' => [],
            'distribuidos' => [],
            'resumen' => [
                'total_aprobado_activo' => 0,
                'total_disponible' => 0,
                'total_distribuido' => 0,
                'total_pendiente' => 0,
                'requeridos_por_plan' => 0,
                'brecha' => 0,
                'exceso' => 0,
                'titulares_distribuidos' => 0,
                'suplentes_distribuidos' => 0,
                'adicionales_distribuidos' => 0,
                'sin_clasificar_distribuidos' => 0,
                'grupos_sin_responsable' => 0,
                'grupos_con_brecha' => 0,
                'trabajadores_duplicados_detectados' => 0,
            ],
            'legacy' => ['grupos' => []],
        ];
    }

    private function emptyPeriodSummary(string $date): array
    {
        return [
            'fecha_inicio' => $date,
            'fecha_fin' => $date,
            'dias_periodo' => 1,
            'dias_con_referencia' => 0,
            'dias_con_grupos' => 0,
            'personal_aprobado' => 0,
            'personal_unico_distribuido' => 0,
            'grupos_total' => 0,
            'referencia_total' => 0,
            'distribuciones_total' => 0,
            'diferencia' => 0,
            'cobertura_porcentaje' => null,
            'turnos' => [
                'DIA' => ['referencia' => 0, 'distribuciones' => 0, 'diferencia' => 0, 'grupos' => 0, 'cobertura_porcentaje' => null],
                'NOCHE' => ['referencia' => 0, 'distribuciones' => 0, 'diferencia' => 0, 'grupos' => 0, 'cobertura_porcentaje' => null],
            ],
        ];
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        return $this->policy->canAccessMina($usuario, $minaId);
    }
}
