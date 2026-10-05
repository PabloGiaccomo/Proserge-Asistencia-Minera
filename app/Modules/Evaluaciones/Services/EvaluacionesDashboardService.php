<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\{EvaluacionDesempeno, EvaluacionSupervisor, EvaluacionResidente, Usuario};
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use Illuminate\Support\Collection;

class EvaluacionesDashboardService
{
    public function __construct(
        private readonly EvaluacionesPolicy $policy,
        private readonly EvaluacionesDetailAnalytics $detailAnalytics,
    ) {
    }

    public function build(Usuario $user, array $filters): array
    {
        $selectedType = $filters['evaluacion_tipo'] ?? '';
        $definitions = [
            'desempeno' => [EvaluacionDesempeno::class, 'trabajador', 'trabajador_id', 'total', 20, 'Diaria', '#138b80'],
            'supervisores' => [EvaluacionSupervisor::class, 'evaluado', 'evaluado_id', 'resultado_final', 100, 'Supervisores', '#4e79bc'],
            'residentes' => [EvaluacionResidente::class, 'residente', 'residente_id', 'total', 20, 'Residentes', '#af6b23'],
        ];
        $typeOptions = collect($definitions)
            ->filter(fn ($definition, $key) => $this->policy->canViewType($user, $key))
            ->map(fn ($definition) => $definition[5])->all();

        $authorizedRows = $this->authorizedRows($user, $filters, $definitions, $selectedType);
        $cargos = $authorizedRows->pluck('cargo')->unique()->sort()->values();
        if ($filters['cargo'] && !$cargos->contains($filters['cargo'])) $cargos->push($filters['cargo']);

        $rows = $authorizedRows->filter(
            fn (array $row): bool => (!$filters['mina']
                || ($filters['mina'] === 'sin_mina' ? $row['mine_id'] === '' : $row['mine_id'] === $filters['mina']))
                && (!$filters['cargo'] || $row['cargo'] === $filters['cargo'])
        )->values();

        $summary = [];
        $offset = 0;
        $segments = [];
        foreach ($definitions as $key => $definition) {
            if ($selectedType !== '' && $selectedType !== $key) continue;
            if (!$this->policy->canViewType($user, $key)) continue;
            $items = $rows->where('type', $key);
            $scores = $items->filter(fn ($r) => $r['score'] !== null && $r['score'] >= 0 && $r['score'] <= $r['max']);
            $share = $rows->count() ? $items->count() / $rows->count() * 100 : 0;
            $segments[] = $definition[6].' '.$offset.'% '.($offset + $share).'%';
            $offset += $share;
            $summary[] = ['label' => $definition[5], 'color' => $definition[6], 'count' => $items->count(), 'max' => $definition[4],
                'average' => $scores->isEmpty() ? null : round($scores->avg('score'), 1), 'scored' => $scores->count()];
        }

        $scored = $rows->filter(fn (array $row): bool => $row['score'] !== null && $row['score'] >= 0 && $row['score'] <= $row['max']);

        return ['types' => $typeOptions, 'total' => $rows->count(), 'people' => $rows->pluck('person')->filter()->unique()->count(),
            'scored' => $scored->count(), 'unscored' => $rows->count() - $scored->count(),
            'scoreCoverage' => $rows->isEmpty() ? 0.0 : round($scored->count() / $rows->count() * 100, 1),
            'activeFilterCount' => collect([$selectedType, $filters['mina'], $filters['cargo']])->filter(fn ($value) => filled($value))->count() + 1,
            'details' => $selectedType !== '' && isset($typeOptions[$selectedType])
                ? $this->detailAnalytics->build($selectedType, $rows) : null,
            'summary' => $summary, 'cargos' => $cargos, 'pie' => $rows->isEmpty() ? '#e5e9ed 0% 100%' : implode(', ', $segments),
            'months' => $this->groups($rows, 'month'), 'byMine' => $this->groups($rows, 'mine'), 'byCargo' => $this->groups($rows, 'cargo')];
    }

    private function authorizedRows(Usuario $user, array $filters, array $definitions, string $selectedType): Collection
    {
        $rows = collect();
        $mineIds = $this->policy->isPrivileged($user) ? collect() : $user->scopesMina()->pluck('mina_id');

        foreach ($definitions as $type => [$model, $relation, $personField, $scoreField, $max, $label, $color]) {
            if (($selectedType !== '' && $selectedType !== $type) || !$this->policy->canViewType($user, $type)) {
                continue;
            }

            $query = $model::query()
                ->with($relation.':id,puesto')
                ->whereDate('fecha', '>=', $filters['desde'])
                ->whereDate('fecha', '<=', $filters['hasta']);

            if ($type !== 'residentes') {
                $query->with('mina:id,nombre');
                if (!$this->policy->isPrivileged($user)) {
                    $query->where(function ($scope) use ($mineIds, $type, $user): void {
                        $scope->where(function ($mine) use ($mineIds): void {
                            $mine->where(function ($destination): void {
                                $destination->whereNull('destino_tipo')->orWhere('destino_tipo', 'MINA');
                            })->where(function ($allowed) use ($mineIds): void {
                                $allowed->whereIn('destino_id', $mineIds)->orWhereIn('mina_id', $mineIds);
                            });
                        })->orWhere(function ($other): void {
                            $other->whereNotNull('destino_tipo')->where('destino_tipo', '<>', 'MINA');
                        });
                        if ($type === 'desempeno') {
                            $scope->orWhere(fn ($own) => $own->where('origen', 'DIRECTA')->where('evaluado_por_usuario_id', $user->id));
                        }
                    });
                }
            }

            if (!$this->policy->canViewOthers($user)) {
                if ($type === 'desempeno') {
                    $query->where('evaluado_por_usuario_id', $user->id);
                } else {
                    $this->policy->ownedBy($query, $user);
                }
            }

            foreach ($query->get() as $evaluation) {
                $rows->push([
                    'type' => $type,
                    'label' => $label,
                    'color' => $color,
                    'max' => $max,
                    'detail' => $selectedType !== '' ? $evaluation->attributesToArray() : [],
                    'person' => $evaluation->{$personField},
                    'month' => $evaluation->fecha->format('Y-m'),
                    'score' => $evaluation->{$scoreField} === null ? null : (float) $evaluation->{$scoreField},
                    'cargo' => $evaluation->{$relation}?->puesto ?: 'Sin cargo',
                    'mine_id' => $type === 'residentes' ? '' : (string) $evaluation->mina_id,
                    'mine' => $type === 'residentes' ? 'Sin mina' : ($evaluation->mina?->nombre ?: 'Sin mina'),
                ]);
            }
        }

        return $rows;
    }

    private function groups(Collection $rows, string $field): array
    {
        return $rows->groupBy($field)->sortKeys()->map(fn ($items, $name) => ['name' => $name, 'count' => $items->count(),
            'types' => $items->groupBy('label')->map(fn ($group) => ['count' => $group->count(), 'color' => $group->first()['color']])->all()])->values()->all();
    }
}
