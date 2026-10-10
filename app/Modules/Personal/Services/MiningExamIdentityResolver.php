<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroAlias;
use App\Models\ExamenMineroAsociacion;
use App\Models\MinaRequisito;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MiningExamIdentityResolver
{
    public function schemaReady(): bool
    {
        return Schema::hasTable('examen_minero_asociaciones')
            && Schema::hasTable('examen_minero_aliases')
            && Schema::hasTable('examen_minero_catalogo_cambios');
    }

    public function normalizedName(string $name): string
    {
        return Str::squish(preg_replace('/[^A-Z0-9]+/', ' ', Str::upper(Str::ascii($name))) ?? '');
    }

    public function canonicalId(string $examId): string
    {
        return $this->schemaReady()
            ? (ExamenMineroAsociacion::query()->where('variante_id', $examId)->value('principal_id') ?? $examId)
            : $examId;
    }

    public function groupIds(string $examId): array
    {
        $principal = $this->canonicalId($examId);

        return $this->schemaReady()
            ? array_merge([$principal], ExamenMineroAsociacion::query()->where('principal_id', $principal)->pluck('variante_id')->all())
            : [$examId];
    }

    public function resolve(string $name, ?string $mineId = null, ?Collection $catalog = null, bool $allowInactive = false): array
    {
        $key = $this->normalizedName($name);
        $catalog ??= ExamenMinero::query()->get(['id', 'nombre', 'activo']);
        $exact = $catalog
            ->filter(fn ($exam) => $this->normalizedName($exam->nombre) === $key);
        $aliasIds = $this->schemaReady() ? ExamenMineroAlias::query()
            ->where('clave', hash('sha256', $key))
            ->where(fn ($query) => $query->whereNull('mina_id')->when($mineId, fn ($q) => $q->orWhere('mina_id', $mineId)))
            ->pluck('examen_id')->all() : [];
        $ids = array_unique(array_merge($exact->pluck('id')->all(), $aliasIds));
        $principals = array_values(array_unique(array_map(fn ($id) => $this->canonicalId($id), $ids)));
        $result = ['status' => 'missing', 'examen_id' => null, 'principal_id' => null,
            'candidates' => array_values($ids), 'match_type' => $aliasIds ? 'alias' : 'exact'];
        if (! $principals) {
            return $result;
        }
        $groups = [];
        $activePrincipals = [];
        foreach ($principals as $principal) {
            $groups[$principal] = $this->groupIds($principal);
            if ($catalog->contains(fn ($exam) => $exam->activo && in_array($exam->id, $groups[$principal], true))) {
                $activePrincipals[] = $principal;
            }
        }
        if ($activePrincipals !== []) {
            $principals = $activePrincipals;
        } elseif (! $allowInactive) {
            return $result;
        }
        if (count($principals) !== 1) {
            return array_replace($result, ['status' => 'ambiguous']);
        }
        $principal = $principals[0];
        $group = $groups[$principal];
        $configured = $mineId ? MinaRequisito::query()->where('mina_id', $mineId)->where('activo', true)
            ->whereIn('examen_id', $group)->pluck('examen_id')->unique()->values()->all() : [];
        if (count($configured) > 1) {
            return array_replace($result, ['status' => 'ambiguous', 'candidates' => $configured, 'principal_id' => $principal]);
        }
        $matchingIds = array_values(array_intersect($ids, $group));
        $examId = $configured[0] ?? (count($group) > 1 ? $principal : ($matchingIds[0] ?? $principal));
        if (! $catalog->contains(fn ($exam) => $exam->id === $examId && ($exam->activo || $allowInactive))) {
            return $result;
        }

        return array_replace($result, ['status' => 'matched', 'examen_id' => $examId, 'principal_id' => $principal,
            'match_type' => count($group) > 1 ? 'group' : $result['match_type']]);
    }

    public function suggestions(string $name, ?Collection $catalog = null): array
    {
        $key = $this->normalizedName($name);
        if ($key === '') {
            return [];
        }

        return ($catalog ?? ExamenMinero::query()->get(['id', 'nombre', 'activo']))
            ->map(function ($exam) use ($key): array {
                $other = $this->normalizedName($exam->nombre);
                $ratio = 1 - levenshtein($key, $other) / max(strlen($key), strlen($other), 1);

                return ['id' => $exam->id, 'nombre' => $exam->nombre, 'similitud' => $ratio];
            })->filter(fn ($exam) => $exam['similitud'] >= .78)
            ->sortByDesc('similitud')->take(3)->values()->all();
    }
}
