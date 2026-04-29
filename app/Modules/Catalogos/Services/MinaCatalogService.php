<?php

namespace App\Modules\Catalogos\Services;

use App\Models\Mina;
use App\Models\MinaParadero;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MinaCatalogService
{
    public function list(array $filters = []): Collection
    {
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Mina::query()->with(['paraderos' => function ($q): void {
            $q->orderBy('nombre');
        }])->orderBy('nombre');

        $this->applyOfficeOverlapGuard($query);

        if (in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $query->where('estado', $estado);
        }

        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';
            $query->where(function ($sub) use ($needle): void {
                $sub->whereRaw('LOWER(nombre) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(unidad_minera) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(ubicacion) LIKE ?', [$needle]);
            });
        }

        return $query->get();
    }

    public function find(string $id): ?Mina
    {
        $query = Mina::query()->with(['paraderos' => function ($q): void {
            $q->orderBy('nombre');
        }]);

        $this->applyOfficeOverlapGuard($query);

        return $query->find($id);
    }

    public function create(array $payload): Mina
    {
        return DB::transaction(function () use ($payload): Mina {
            $mina = Mina::query()->create([
                'id' => (string) Str::uuid(),
                'nombre' => PersonalNormalizer::text($payload['nombre'] ?? ''),
                'unidad_minera' => PersonalNormalizer::text($payload['unidad_minera'] ?? $payload['nombre'] ?? ''),
                'ubicacion' => PersonalNormalizer::text($payload['ubicacion'] ?? '') ?: null,
                'link_ubicacion' => PersonalNormalizer::text($payload['link_ubicacion'] ?? '') ?: null,
                'color' => PersonalNormalizer::text($payload['color'] ?? '') ?: null,
                'estado' => $this->resolveState($payload['estado'] ?? 'ACTIVO'),
            ]);

            $this->syncParaderos($mina, $payload['paraderos'] ?? []);

            return $mina->load('paraderos');
        });
    }

    public function update(Mina $mina, array $payload): Mina
    {
        return DB::transaction(function () use ($mina, $payload): Mina {
            $mina->fill([
                'nombre' => PersonalNormalizer::text($payload['nombre'] ?? $mina->nombre),
                'unidad_minera' => PersonalNormalizer::text($payload['unidad_minera'] ?? $mina->unidad_minera),
                'ubicacion' => PersonalNormalizer::text($payload['ubicacion'] ?? '') ?: null,
                'link_ubicacion' => PersonalNormalizer::text($payload['link_ubicacion'] ?? '') ?: null,
                'color' => PersonalNormalizer::text($payload['color'] ?? '') ?: null,
                'estado' => $this->resolveState($payload['estado'] ?? $mina->estado),
            ]);
            $mina->save();

            $this->syncParaderos($mina, $payload['paraderos'] ?? []);

            return $mina->load('paraderos');
        });
    }

    public function inactivate(Mina $mina): Mina
    {
        return DB::transaction(function () use ($mina): Mina {
            $mina->estado = 'INACTIVO';
            $mina->save();

            MinaParadero::query()
                ->where('mina_id', $mina->id)
                ->where('estado', '!=', 'INACTIVO')
                ->update(['estado' => 'INACTIVO']);

            return $mina->load('paraderos');
        });
    }

    private function syncParaderos(Mina $mina, array $paraderos): void
    {
        $existing = MinaParadero::query()->where('mina_id', $mina->id)->get()->keyBy('id');
        $keptIds = [];

        foreach ($paraderos as $paradero) {
            if (!is_array($paradero)) {
                continue;
            }

            $name = PersonalNormalizer::text($paradero['nombre'] ?? '');
            if ($name === '') {
                continue;
            }

            $id = PersonalNormalizer::text($paradero['id'] ?? '');
            $payload = [
                'nombre' => $name,
                'ubicacion' => PersonalNormalizer::text($paradero['ubicacion'] ?? '') ?: null,
                'link_ubicacion' => PersonalNormalizer::text($paradero['link_ubicacion'] ?? '') ?: null,
                'estado' => $this->resolveState($paradero['estado'] ?? 'ACTIVO'),
            ];

            if ($id !== '' && $existing->has($id)) {
                $item = $existing->get($id);
                $item->fill($payload);
                $item->save();
                $keptIds[$id] = true;
                continue;
            }

            $new = MinaParadero::query()->create([
                'id' => (string) Str::uuid(),
                'mina_id' => $mina->id,
                ...$payload,
            ]);

            $keptIds[$new->id] = true;
        }

        foreach ($existing as $item) {
            if (!isset($keptIds[$item->id]) && strtoupper((string) $item->estado) !== 'INACTIVO') {
                $item->estado = 'INACTIVO';
                $item->save();
            }
        }
    }

    private function resolveState(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'ACTIVO' : 'INACTIVO';
        }

        $state = strtoupper(trim((string) $value));

        return in_array($state, ['1', 'ACTIVO', 'ACTIVE'], true) ? 'ACTIVO' : 'INACTIVO';
    }

    private function applyOfficeOverlapGuard(Builder $query): void
    {
        $query->whereNotExists(function ($sub): void {
            $sub->select(DB::raw(1))
                ->from('oficinas as o')
                ->whereRaw('LOWER(TRIM(o.nombre)) = LOWER(TRIM(minas.nombre))');
        });
    }
}
