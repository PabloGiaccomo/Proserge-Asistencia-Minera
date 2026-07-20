<?php

namespace App\Modules\Catalogos\Services;

use App\Models\Mina;
use App\Models\MinaParadero;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MinaCatalogService
{
    public function list(array $filters = []): Collection
    {
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Mina::query()
            ->operationalCatalog()
            ->with(['paraderos' => function ($q): void {
                $q->orderBy('nombre');
            }])
            ->orderBy('nombre');

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
        $query = Mina::query()
            ->operationalCatalog()
            ->with(['paraderos' => function ($q): void {
                $q->orderBy('nombre');
            }]);

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

    public function delete(Mina $mina): void
    {
        $blockers = $this->deleteBlockers($mina);

        if (!empty($blockers)) {
            throw ValidationException::withMessages([
                'mina' => 'No se puede eliminar esta mina porque ya tiene movimientos asociados: ' . implode(', ', $blockers) . '. Puedes inactivarla para que ya no se use.',
            ]);
        }

        DB::transaction(function () use ($mina): void {
            if (Schema::hasTable('mina_paraderos')) {
                MinaParadero::query()->where('mina_id', $mina->id)->delete();
            }

            if (Schema::hasTable('usuario_mina_scope')) {
                DB::table('usuario_mina_scope')->where('mina_id', $mina->id)->delete();
            }

            if (Schema::hasTable('mina_requisitos')) {
                DB::table('mina_requisitos')->where('mina_id', $mina->id)->delete();
            }

            $mina->delete();
        });
    }

    public function forceDeleteAccidental(Mina $mina): array
    {
        $blockers = $this->forceDeleteBlockers($mina);

        if (!empty($blockers)) {
            throw ValidationException::withMessages([
                'mina' => 'No se puede eliminar definitivamente esta mina porque tiene movimientos operativos reales: ' . implode(', ', $blockers) . '. Primero revisa esos movimientos para no perder trazabilidad.',
            ]);
        }

        return DB::transaction(function () use ($mina): array {
            $mineId = (string) $mina->id;
            $summary = [
                'asignaciones' => 0,
                'historiales' => 0,
                'examenes' => 0,
                'intentos' => 0,
                'requisitos' => 0,
                'paraderos' => 0,
                'alcances_usuario' => 0,
                'notificaciones' => 0,
                'mina' => 0,
            ];

            $personalMineIds = $this->pluckIds('personal_mina', 'id', 'mina_id', $mineId);
            $requirementIds = $this->pluckIds('mina_requisitos', 'id', 'mina_id', $mineId);

            if (Schema::hasTable('personal_mina_examenes')) {
                if (Schema::hasColumn('personal_mina_examenes', 'mina_origen_convalidacion_id')) {
                    $convalidationPayload = ['mina_origen_convalidacion_id' => null];
                    if (Schema::hasColumn('personal_mina_examenes', 'examen_origen_convalidado_id')) {
                        $convalidationPayload['examen_origen_convalidado_id'] = null;
                    }
                    if (Schema::hasColumn('personal_mina_examenes', 'updated_at')) {
                        $convalidationPayload['updated_at'] = now();
                    }

                    $summary['examenes_convalidacion_limpiados'] = DB::table('personal_mina_examenes')
                        ->where('mina_origen_convalidacion_id', $mineId)
                        ->update($convalidationPayload);
                }

                if ($personalMineIds->isNotEmpty() || $requirementIds->isNotEmpty()) {
                    $examQuery = DB::table('personal_mina_examenes')->where(function ($query) use ($personalMineIds, $requirementIds): void {
                        if ($personalMineIds->isNotEmpty() && Schema::hasColumn('personal_mina_examenes', 'personal_mina_id')) {
                            $query->whereIn('personal_mina_id', $personalMineIds);
                        }

                        if ($requirementIds->isNotEmpty() && Schema::hasColumn('personal_mina_examenes', 'mina_requisito_id')) {
                            $method = $personalMineIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                            $query->{$method}('mina_requisito_id', $requirementIds);
                        }
                    });

                    $examIds = $examQuery->pluck('id');

                    if ($examIds->isNotEmpty()) {
                        $summary['intentos'] = $this->deleteWhereIn('personal_mina_examen_intentos', 'personal_mina_examen_id', $examIds);
                        $summary['examenes'] = $this->deleteWhereIn('personal_mina_examenes', 'id', $examIds);
                    }
                }
            }

            $summary['historiales'] = $this->deleteWhereIn('personal_mina_historial', 'personal_mina_id', $personalMineIds);
            $summary['asignaciones'] = $this->deleteWhere('personal_mina', 'mina_id', $mineId);
            $summary['requisitos'] = $this->deleteWhere('mina_requisitos', 'mina_id', $mineId);
            $summary['paraderos'] = $this->deleteWhere('mina_paraderos', 'mina_id', $mineId);
            $summary['alcances_usuario'] = $this->deleteWhere('usuario_mina_scope', 'mina_id', $mineId);

            if (Schema::hasTable('notification_events') && Schema::hasColumn('notification_events', 'mina_id')) {
                $notificationPayload = ['mina_id' => null];
                if (Schema::hasColumn('notification_events', 'updated_at')) {
                    $notificationPayload['updated_at'] = now();
                }

                $summary['notificaciones'] = DB::table('notification_events')
                    ->where('mina_id', $mineId)
                    ->update($notificationPayload);
            }

            $summary['mina'] = $mina->delete() ? 1 : 0;

            return $summary;
        });
    }

    private function deleteBlockers(Mina $mina): array
    {
        $id = (string) $mina->id;
        $checks = [
            'RQ Mina' => fn (): int => $this->countMineUsage('rq_mina', $id, includeDestination: true),
            'RQ Proserge' => fn (): int => $this->countMineUsage('rq_proserge', $id),
            'Personal habilitado/asignado' => fn (): int => $this->countMineUsage('personal_mina', $id),
            'Asistencias' => fn (): int => $this->countMineUsage('asistencia_encabezado', $id, includeDestination: true),
            'Evaluaciones de desempeño' => fn (): int => $this->countMineUsage('evaluacion_desempeno', $id, includeDestination: true),
            'Evaluaciones de supervisor' => fn (): int => $this->countMineUsage('evaluacion_supervisor', $id, includeDestination: true),
            'Evaluaciones de residente' => fn (): int => $this->countMineUsage('evaluacion_residente', $id, includeDestination: true),
        ];

        $blockers = [];
        foreach ($checks as $label => $resolver) {
            if ($resolver() > 0) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }

    private function forceDeleteBlockers(Mina $mina): array
    {
        $id = (string) $mina->id;
        $checks = [
            'RQ Mina' => fn (): int => $this->countMineUsage('rq_mina', $id, includeDestination: true),
            'RQ Proserge' => fn (): int => $this->countMineUsage('rq_proserge', $id),
            'Asistencias' => fn (): int => $this->countMineUsage('asistencia_encabezado', $id, includeDestination: true),
            'Evaluaciones de desempeño' => fn (): int => $this->countMineUsage('evaluacion_desempeno', $id, includeDestination: true),
            'Evaluaciones de supervisor' => fn (): int => $this->countMineUsage('evaluacion_supervisor', $id, includeDestination: true),
            'Evaluaciones de residente' => fn (): int => $this->countMineUsage('evaluacion_residente', $id, includeDestination: true),
        ];

        $blockers = [];
        foreach ($checks as $label => $resolver) {
            if ($resolver() > 0) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }

    private function countMineUsage(string $table, string $mineId, bool $includeDestination = false): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $hasMineId = Schema::hasColumn($table, 'mina_id');
        $hasDestination = $includeDestination && Schema::hasColumn($table, 'destino_tipo') && Schema::hasColumn($table, 'destino_id');

        if (!$hasMineId && !$hasDestination) {
            return 0;
        }

        $query = DB::table($table)->where(function ($usage) use ($hasMineId, $hasDestination, $mineId): void {
            if ($hasMineId) {
                $usage->where('mina_id', $mineId);
            }

            if ($hasDestination) {
                $method = $hasMineId ? 'orWhere' : 'where';
                $usage->{$method}(function ($destination) use ($mineId): void {
                    $destination
                    ->where('destino_tipo', 'mina')
                    ->where('destino_id', $mineId);
                });
            }
        });

        return (int) $query->count();
    }

    private function pluckIds(string $table, string $idColumn, string $whereColumn, string $value): SupportCollection
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $idColumn) || !Schema::hasColumn($table, $whereColumn)) {
            return collect();
        }

        return DB::table($table)->where($whereColumn, $value)->pluck($idColumn);
    }

    private function deleteWhere(string $table, string $column, string $value): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->where($column, $value)->delete();
    }

    private function deleteWhereIn(string $table, string $column, SupportCollection $values): int
    {
        if ($values->isEmpty() || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->whereIn($column, $values)->delete();
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

}
