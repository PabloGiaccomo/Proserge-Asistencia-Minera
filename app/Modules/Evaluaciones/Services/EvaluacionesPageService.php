<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\EvaluacionDesempeno;
use App\Models\EvaluacionResidente;
use App\Models\EvaluacionSupervisor;
use App\Models\Mina;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EvaluacionesPageService
{
    public function __construct(
        private readonly EvaluacionesPolicy $policy,
        private readonly EvaluationCandidateService $candidates,
        private readonly EvaluacionDesempenoService $performanceService,
    ) {
    }

    public function build(Usuario $usuario, string $requestedType, string $date): array
    {
        $access = collect(['desempeno', 'supervisores', 'residentes'])
            ->mapWithKeys(fn (string $type): array => [$type => $this->policy->canViewType($usuario, $type)])
            ->all();
        $availableTypes = collect($access)->filter()->keys()->values();
        if ($availableTypes->isNotEmpty()) $availableTypes->push('dashboard');
        $activeType = $availableTypes->contains($requestedType) ? $requestedType : (string) $availableTypes->first();

        $mineIds = $this->accessibleMineIds($usuario);
        $mines = Mina::query()
            ->activeOperational()
            ->when($activeType !== 'supervisores' && !$this->policy->isPrivileged($usuario), fn ($query) => $query->whereIn('id', $mineIds))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'unidad_minera']);

        return [
            'evaluatorName' => $usuario->personal?->nombre_completo ?: $usuario->email,
            'access' => $access,
            'canEvaluate' => [
                'desempeno' => $this->policy->canEvaluateType($usuario, 'desempeno'),
                'supervisores' => $this->policy->canEvaluateType($usuario, 'supervisores'),
                'residentes' => $this->policy->canEvaluateType($usuario, 'residentes'),
            ],
            'canEvaluateOthers' => $this->policy->canEvaluateOthers($usuario),
            'availableTypes' => $availableTypes,
            'activeType' => $activeType,
            'selectedDate' => $date,
            'mines' => $mines,
            'directMines' => $activeType === 'desempeno' && $access['desempeno']
                ? Mina::query()->activeOperational()->orderBy('nombre')->get(['id', 'nombre'])
                : collect(),
            'dailyPending' => $access['desempeno'] && $activeType !== 'dashboard' ? $this->dailyPending($usuario, $date) : collect(),
            'dailyHistory' => $access['desempeno'] && $activeType !== 'dashboard' ? $this->dailyHistory($usuario, $mineIds) : collect(),
            'supervisorHistory' => $access['supervisores'] && $activeType !== 'dashboard' ? $this->supervisorHistory($usuario, $mineIds) : collect(),
            'residentHistory' => $access['residentes'] && $activeType !== 'dashboard' ? $this->residentHistory($usuario) : collect(),
        ];
    }

    public function searchPersonal(
        Usuario $usuario,
        string $type,
        string $search,
        ?string $personalId = null,
        ?string $mineId = null,
        int $limit = 12,
    ): Collection
    {
        return $this->candidates->search($usuario, $type, $search, $personalId, $mineId, $limit);
    }

    private function dailyPending(Usuario $usuario, string $date): Collection
    {
        if (!$usuario->personal_id) {
            return collect();
        }

        $headers = AsistenciaEncabezado::query()
            ->with([
                'mina:id,nombre,unidad_minera',
                'grupoTrabajo:id,servicio,area,turno,supervisor_id',
                'detalle.trabajador:id,nombre_completo,dni,numero_documento,puesto',
            ])
            ->whereDate('fecha', $date)
            ->when(!$this->policy->canEvaluateOthers($usuario), function ($query) use ($usuario): void {
                $query->where(function ($ownerQuery) use ($usuario): void {
                    $ownerQuery->where('supervisor_id', $usuario->personal_id)
                        ->orWhereHas('detalle', fn ($detail) => $detail->where('marcado_por_id', $usuario->id));
                });
            })
            ->orderBy('hora_ingreso')
            ->get();

        $evaluatedDetailIds = EvaluacionDesempeno::query()
            ->whereIn('asistencia_encabezado_id', $headers->pluck('id'))
            ->pluck('asistencia_detalle_id')
            ->filter()
            ->flip();

        return $headers->map(function (AsistenciaEncabezado $header) use ($usuario, $evaluatedDetailIds): array {
            $markedByUser = $header->detalle->contains(fn (AsistenciaDetalle $detail): bool => (string) $detail->marcado_por_id === (string) $usuario->id);
            $workers = $header->detalle->map(function (AsistenciaDetalle $detail) use ($usuario, $evaluatedDetailIds, $markedByUser, $header): array {
                $detail->setRelation('asistencia', $header);
                $availability = $this->performanceService->dailyAvailability(
                    $usuario, $detail, $evaluatedDetailIds->has($detail->id), $markedByUser,
                );

                return [
                    'detail' => $detail,
                    'status' => $evaluatedDetailIds->has($detail->id)
                        ? 'evaluated'
                        : ($availability['ok'] ? 'available' : 'ineligible'),
                ];
            })->values();

            return [
                'attendance' => $header,
                'week' => Carbon::parse($header->fecha)->isoWeek(),
                'week_range' => Carbon::parse($header->fecha)->startOfWeek()->format('d/m').' - '.Carbon::parse($header->fecha)->endOfWeek()->format('d/m/Y'),
                'workers' => $workers,
            ];
        })->filter(fn (array $group): bool => $group['workers']->isNotEmpty())->values();
    }

    private function dailyHistory(Usuario $usuario, Collection $mineIds): Collection
    {
        $canViewOthers = $this->policy->canViewOthers($usuario);

        return EvaluacionDesempeno::query()
            ->with(['trabajador:id,nombre_completo,puesto', 'evaluador:id,nombre_completo', 'mina:id,nombre'])
            ->when(!$canViewOthers, fn ($query) => $query->where('evaluado_por_usuario_id', $usuario->id))
            ->when($canViewOthers && !$this->policy->isPrivileged($usuario), fn ($query) => $query->where(function ($scope) use ($mineIds, $usuario): void {
                $scope->whereIn('mina_id', $mineIds)
                    ->orWhere(fn ($own) => $own->where('origen', 'DIRECTA')->where('evaluado_por_usuario_id', $usuario->id));
            }))
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->limit(20)
            ->get();
    }

    private function supervisorHistory(Usuario $usuario, Collection $mineIds): Collection
    {
        return EvaluacionSupervisor::query()
            ->with(['evaluador:id,nombre_completo', 'usuarioEvaluador:id,email', 'evaluado:id,nombre_completo,puesto', 'mina:id,nombre'])
            ->when(!$this->policy->canViewOthers($usuario), fn ($query) => $this->policy->ownedBy($query, $usuario))
            ->orderByDesc('fecha')
            ->limit(20)
            ->get();
    }

    private function residentHistory(Usuario $usuario): Collection
    {
        return EvaluacionResidente::query()
            ->with(['evaluador:id,nombre_completo', 'usuarioEvaluador:id,email', 'residente:id,nombre_completo,puesto'])
            ->when(!$this->policy->canViewOthers($usuario), fn ($query) => $this->policy->ownedBy($query, $usuario))
            ->orderByDesc('periodo_mes')
            ->orderByDesc('fecha')
            ->limit(20)
            ->get();
    }

    private function accessibleMineIds(Usuario $usuario): Collection
    {
        if ($this->policy->isPrivileged($usuario)) {
            return Mina::query()->activeOperational()->pluck('id');
        }

        if ($usuario->relationLoaded('scopesMina')) {
            return $usuario->scopesMina->pluck('mina_id')->map(fn ($id): string => (string) $id)->filter()->values();
        }

        return $usuario->scopesMina()->pluck('mina_id');
    }
}
