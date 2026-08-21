<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\EvaluacionDesempeno;
use App\Models\EvaluacionResidente;
use App\Models\EvaluacionSupervisor;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EvaluacionesPageService
{
    public function __construct(
        private readonly EvaluacionesPolicy $policy,
        private readonly PersonalService $personalService,
    ) {
    }

    public function build(Usuario $usuario, string $requestedType, string $date): array
    {
        $access = collect(['desempeno', 'supervisores', 'residentes'])
            ->mapWithKeys(fn (string $type): array => [$type => $this->policy->canViewType($usuario, $type)])
            ->all();
        $availableTypes = collect($access)->filter()->keys()->values();
        $activeType = $availableTypes->contains($requestedType) ? $requestedType : (string) $availableTypes->first();

        $mineIds = $this->accessibleMineIds($usuario);
        $mines = Mina::query()
            ->activeOperational()
            ->when(!$this->policy->isPrivileged($usuario), fn ($query) => $query->whereIn('id', $mineIds))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'unidad_minera']);

        return [
            'access' => $access,
            'canEvaluate' => [
                'desempeno' => $this->policy->canEvaluateType($usuario, 'desempeno'),
                'supervisores' => $this->policy->canEvaluateType($usuario, 'supervisores'),
                'residentes' => $this->policy->canEvaluateType($usuario, 'residentes'),
            ],
            'availableTypes' => $availableTypes,
            'activeType' => $activeType,
            'selectedDate' => $date,
            'mines' => $mines,
            'dailyPending' => $access['desempeno'] ? $this->dailyPending($usuario, $date) : collect(),
            'dailyHistory' => $access['desempeno'] ? $this->dailyHistory($usuario) : collect(),
            'supervisorHistory' => $access['supervisores'] ? $this->supervisorHistory($usuario, $mineIds) : collect(),
            'residentHistory' => $access['residentes'] ? $this->residentHistory() : collect(),
        ];
    }

    public function searchPersonal(string $search, ?string $personalId = null, int $limit = 12): Collection
    {
        if ($personalId) {
            $items = Personal::query()
                ->whereKey($personalId)
                ->get(['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado']);
        } elseif (mb_strlen(trim($search)) >= 2) {
            $items = $this->personalService
                ->searchSelector(trim($search), false, $limit);
        } else {
            return collect();
        }

        return $items->map(fn (Personal $personal): array => [
            'id' => (string) $personal->id,
            'nombre' => (string) $personal->nombre_completo,
            'documento' => (string) ($personal->numero_documento ?: $personal->dni),
            'puesto' => (string) ($personal->puesto ?: 'Sin puesto registrado'),
            'estado' => (string) ($personal->estado ?: 'SIN ESTADO'),
        ])->values();
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
            ->where('estado', 'CERRADO')
            ->where(function ($query) use ($usuario): void {
                $query->where('supervisor_id', $usuario->personal_id)
                    ->orWhereHas('detalle', fn ($detail) => $detail->where('marcado_por_id', $usuario->id));
            })
            ->orderBy('hora_ingreso')
            ->get();

        $evaluatedDetailIds = EvaluacionDesempeno::query()
            ->whereIn('asistencia_encabezado_id', $headers->pluck('id'))
            ->pluck('asistencia_detalle_id')
            ->filter()
            ->flip();

        return $headers->map(function (AsistenciaEncabezado $header) use ($usuario, $evaluatedDetailIds): array {
            $workers = $header->detalle
                ->filter(fn (AsistenciaDetalle $detail): bool => in_array($detail->estado, AsistenciaDetalle::ESTADOS_REAL, true))
                ->reject(fn (AsistenciaDetalle $detail): bool => (string) $detail->trabajador_id === (string) $usuario->personal_id)
                ->reject(fn (AsistenciaDetalle $detail): bool => $evaluatedDetailIds->has($detail->id))
                ->values();

            return [
                'attendance' => $header,
                'week' => Carbon::parse($header->fecha)->isoWeek(),
                'week_range' => Carbon::parse($header->fecha)->startOfWeek()->format('d/m').' - '.Carbon::parse($header->fecha)->endOfWeek()->format('d/m/Y'),
                'workers' => $workers,
            ];
        })->filter(fn (array $group): bool => $group['workers']->isNotEmpty())->values();
    }

    private function dailyHistory(Usuario $usuario): Collection
    {
        return EvaluacionDesempeno::query()
            ->with(['trabajador:id,nombre_completo,puesto', 'evaluador:id,nombre_completo', 'mina:id,nombre'])
            ->when(!$this->policy->isPrivileged($usuario), fn ($query) => $query->where('evaluado_por_usuario_id', $usuario->id))
            ->orderByDesc('fecha')
            ->orderByDesc('hora')
            ->limit(20)
            ->get();
    }

    private function supervisorHistory(Usuario $usuario, Collection $mineIds): Collection
    {
        return EvaluacionSupervisor::query()
            ->with(['evaluador:id,nombre_completo', 'evaluado:id,nombre_completo,puesto', 'mina:id,nombre'])
            ->when(!$this->policy->isPrivileged($usuario), fn ($query) => $query->whereIn('mina_id', $mineIds))
            ->orderByDesc('fecha')
            ->limit(20)
            ->get();
    }

    private function residentHistory(): Collection
    {
        return EvaluacionResidente::query()
            ->with(['evaluador:id,nombre_completo', 'residente:id,nombre_completo,puesto'])
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
