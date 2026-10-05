<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use Illuminate\Support\Collection;

class EvaluationCandidateService
{
    public function __construct(private readonly EvaluacionesPolicy $policy)
    {
    }

    public function search(
        Usuario $usuario,
        string $type,
        string $search,
        ?string $personalId,
        ?string $mineId,
        int $limit,
    ): Collection {
        if (!$this->policy->canEvaluateType($usuario, $type)) {
            return collect();
        }

        $query = Personal::query()
            ->select(['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado', 'es_supervisor'])
            ->orderBy('nombre_completo');

        if ($type === 'supervisores') {
            $query->where('es_supervisor', true);
        } elseif ($type === 'residentes') {
            $query->whereRaw('UPPER(COALESCE(puesto, ?)) LIKE ?', ['', '%RESIDENTE%']);
        } elseif ($type !== 'desempeno') {
            return collect();
        }

        if ($personalId) {
            $query->whereKey($personalId);
        } elseif (mb_strlen(trim($search)) >= 2) {
            $term = '%'.trim($search).'%';
            $query->where(function ($match) use ($term): void {
                $match->where('nombre_completo', 'like', $term)
                    ->orWhere('dni', 'like', $term)
                    ->orWhere('numero_documento', 'like', $term)
                    ->orWhere('puesto', 'like', $term);
            });
        } else {
            return collect();
        }

        return $query->limit($limit)->get()->map(fn (Personal $personal): array => [
            'id' => (string) $personal->id,
            'nombre' => (string) $personal->nombre_completo,
            'documento' => (string) ($personal->numero_documento ?: $personal->dni),
            'puesto' => (string) ($personal->puesto ?: 'Sin puesto registrado'),
            'estado' => (string) ($personal->estado ?: 'SIN ESTADO'),
        ])->values();
    }
}
