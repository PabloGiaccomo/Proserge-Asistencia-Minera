<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroAlias;
use App\Models\ExamenMineroAsociacion;
use App\Models\MinaRequisito;
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

    public function resolve(string $name, ?string $mineId = null): array
    {
        $key = $this->normalizedName($name);
        $exact = ExamenMinero::query()->get(['id', 'nombre', 'activo'])
            ->filter(fn ($exam) => $this->normalizedName($exam->nombre) === $key);
        $aliasIds = $this->schemaReady() ? ExamenMineroAlias::query()
            ->where('clave', hash('sha256', $key))
            ->where(fn ($query) => $query->whereNull('mina_id')->when($mineId, fn ($q) => $q->orWhere('mina_id', $mineId)))
            ->pluck('examen_id')->all() : [];
        $ids = array_unique(array_merge($exact->pluck('id')->all(), $aliasIds));
        $principals = array_values(array_unique(array_map(fn ($id) => $this->canonicalId($id), $ids)));
        $result = ['status' => 'missing', 'examen_id' => null, 'principal_id' => null,
            'candidates' => array_values($ids), 'match_type' => $aliasIds ? 'alias' : 'exact'];
        if (!$principals) {
            return $result;
        }
        if (count($principals) !== 1) {
            return array_replace($result, ['status' => 'ambiguous']);
        }
        $principal = $principals[0];
        $group = $this->groupIds($principal);
        $configured = $mineId ? MinaRequisito::query()->where('mina_id', $mineId)->where('activo', true)
            ->whereIn('examen_id', $group)->pluck('examen_id')->unique()->values()->all() : [];
        if (count($configured) > 1) {
            return array_replace($result, ['status' => 'ambiguous', 'candidates' => $configured, 'principal_id' => $principal]);
        }
        $examId = $configured[0] ?? (count($group) > 1 ? $principal : ($ids[0] ?? $principal));
        if (!ExamenMinero::query()->whereKey($examId)->where('activo', true)->exists()) {
            return $result;
        }
        return array_replace($result, ['status' => 'matched', 'examen_id' => $examId, 'principal_id' => $principal,
            'match_type' => count($group) > 1 ? 'group' : $result['match_type']]);
    }
}
