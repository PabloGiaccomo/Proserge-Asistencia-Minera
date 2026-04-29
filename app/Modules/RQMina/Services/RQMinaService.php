<?php

namespace App\Modules\RQMina\Services;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\RQMina\Policies\RQMinaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RQMinaService
{
    public function __construct(private readonly RQMinaPolicy $policy)
    {
    }

    public function listForUser(Usuario $usuario, array $filters, int $perPage = 10, int $page = 1): array
    {
        $query = RQMina::query()->with([
            'mina:id,nombre',
            'creador:id,email,personal_id',
            'creador.personal:id,nombre_completo',
            'detalle:id,rq_mina_id,puesto,cantidad,cantidad_atendida',
        ]);

        $this->applyMineScope($query, $usuario);

        if (!empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $like = '%' . str_replace(' ', '%', $search) . '%';

            $query->where(function ($innerQuery) use ($like) {
                $innerQuery
                    ->where('area', 'like', $like)
                    ->orWhere('estado', 'like', $like)
                    ->orWhere('observaciones', 'like', $like)
                    ->orWhereHas('mina', fn ($mineQuery) => $mineQuery->where('nombre', 'like', $like))
                    ->orWhereHas('creador', function ($creatorQuery) use ($like) {
                        $creatorQuery
                            ->where('email', 'like', $like)
                            ->orWhereHas('personal', fn ($personalQuery) => $personalQuery->where('nombre_completo', 'like', $like));
                    });
            });
        }

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['created_by_usuario_id'])) {
            $query->where('created_by_usuario_id', (string) $filters['created_by_usuario_id']);
        }

        if (!empty($filters['fecha_inicio_desde'])) {
            $query->whereDate('fecha_inicio', '>=', $filters['fecha_inicio_desde']);
        }

        if (!empty($filters['fecha_inicio_hasta'])) {
            $query->whereDate('fecha_inicio', '<=', $filters['fecha_inicio_hasta']);
        }

        if (!empty($filters['fecha_fin_desde'])) {
            $query->whereDate('fecha_fin', '>=', $filters['fecha_fin_desde']);
        }

        if (!empty($filters['fecha_fin_hasta'])) {
            $query->whereDate('fecha_fin', '<=', $filters['fecha_fin_hasta']);
        }

        $total = $query->count();
        $items = $query->orderByDesc('created_at')->skip(($page - 1) * $perPage)->take($perPage)->get();
        $totalPages = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'total_pages' => $totalPages,
        ];
    }

    public function getCreatorOptionsForUser(Usuario $usuario): Collection
    {
        $query = RQMina::query()
            ->selectRaw('DISTINCT rq_mina.created_by_usuario_id as id')
            ->selectRaw("COALESCE(personal.nombre_completo, usuarios.email, 'Sin creador') as nombre")
            ->leftJoin('usuarios', 'usuarios.id', '=', 'rq_mina.created_by_usuario_id')
            ->leftJoin('personal', 'personal.id', '=', 'usuarios.personal_id')
            ->whereNotNull('rq_mina.created_by_usuario_id');

        $this->applyMineScope($query, $usuario);

        return $query
            ->orderByRaw("COALESCE(personal.nombre_completo, usuarios.email) asc")
            ->get()
            ->map(fn ($row): array => [
                'id' => (string) ($row->id ?? ''),
                'nombre' => (string) ($row->nombre ?? 'Sin creador'),
            ])
            ->filter(fn (array $row): bool => $row['id'] !== '')
            ->values();
    }

    public function findForUser(Usuario $usuario, string $id): ?RQMina
    {
        $rqMina = RQMina::query()
            ->with(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle'])
            ->find($id);

        if (!$rqMina) {
            return null;
        }

        if (!$this->policy->view($usuario, $rqMina)) {
            return null;
        }

        return $rqMina;
    }

    public function create(Usuario $usuario, array $payload): ?RQMina
    {
        Log::info('rqmina.create_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'mina_id' => (string) ($payload['mina_id'] ?? ''),
            'detalle_count' => count($payload['detalle'] ?? []),
            'detalle_total_cantidad' => collect($payload['detalle'] ?? [])->sum(fn (array $item) => (int) ($item['cantidad'] ?? 0)),
        ]);

        if (!PermissionMatrix::userCan($usuario, 'rq_mina', 'crear') || !$this->policy->canAccessMina($usuario, $payload['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $payload): RQMina {
            $rqMina = RQMina::query()->create([
                'id' => (string) Str::uuid(),
                'mina_id' => $payload['mina_id'],
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
                'estado' => 'BORRADOR',
                'created_by_usuario_id' => $usuario->id,
            ]);

            $rows = collect($payload['detalle'])->map(fn (array $item): array => [
                'id' => (string) Str::uuid(),
                'rq_mina_id' => $rqMina->id,
                'puesto' => $item['puesto'],
                'cantidad' => (int) $item['cantidad'],
                'cantidad_atendida' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            $rqMina->detalle()->insert($rows);

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad'] ?? 0)),
            ]);

            return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle']);
        });
    }

    public function update(Usuario $usuario, RQMina $rqMina, array $payload): ?RQMina
    {
        Log::info('rqmina.update_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'rq_mina_id' => (string) $rqMina->id,
            'mina_id' => (string) ($payload['mina_id'] ?? ''),
            'detalle_count' => count($payload['detalle'] ?? []),
            'detalle_total_cantidad' => collect($payload['detalle'] ?? [])->sum(fn (array $item) => (int) ($item['cantidad'] ?? 0)),
        ]);

        if (!$this->policy->update($usuario, $rqMina)) {
            return null;
        }

        if (!$this->policy->canAccessMina($usuario, $payload['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($rqMina, $payload): RQMina {
            $rqMina->fill([
                'mina_id' => $payload['mina_id'],
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]);
            $rqMina->save();

            $rqMina->detalle()->delete();

            $rows = collect($payload['detalle'])->map(fn (array $item): array => [
                'id' => (string) Str::uuid(),
                'rq_mina_id' => $rqMina->id,
                'puesto' => $item['puesto'],
                'cantidad' => (int) $item['cantidad'],
                'cantidad_atendida' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            $rqMina->detalle()->insert($rows);

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad'] ?? 0)),
            ]);

            return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle']);
        });
    }

    public function send(Usuario $usuario, RQMina $rqMina): ?RQMina
    {
        if (!$this->policy->send($usuario, $rqMina)) {
            return null;
        }

        $rqMina->fill([
            'estado' => 'ENVIADO',
            'enviado_at' => now(),
        ]);
        $rqMina->save();

        return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle']);
    }

    public function delete(Usuario $usuario, RQMina $rqMina): bool
    {
        if (!$this->policy->delete($usuario, $rqMina)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($rqMina): bool {
            $rqId = (string) $rqMina->id;
            $rqMina->delete();

            Log::info('rqmina.deleted', [
                'rq_mina_id' => $rqId,
            ]);

            return true;
        });
    }

    public function canUpdate(Usuario $usuario, RQMina $rqMina): bool
    {
        return $this->policy->update($usuario, $rqMina);
    }

    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        return $this->policy->canAccessMina($usuario, $minaId);
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'rq_mina', 'administrar');
    }

    private function applyMineScope($query, Usuario $usuario): void
    {
        if ($this->isPrivileged($usuario)) {
            return;
        }

        $scopeTable = \Illuminate\Support\Facades\Schema::hasTable('usuario_mina_scope') ? 'usuario_mina_scope' : 'usuario_mina_scopes';
        $minaIds = \DB::table($scopeTable)->where('usuario_id', $usuario->id)->pluck('mina_id');
        $query->whereIn('mina_id', $minaIds);
    }

    public function getAvailableMinas(Usuario $usuario): Collection
    {
        if ($this->isPrivileged($usuario)) {
            return \App\Models\Mina::query()->where('estado', 'ACTIVO')->orderBy('nombre')->get(['id', 'nombre']);
        }

        $minaIds = DB::table('usuario_mina_scope')->where('usuario_id', $usuario->id)->pluck('mina_id');

        Log::info('rqmina.available_minas_scope_loaded', [
            'usuario_id' => (string) $usuario->id,
            'scope_minas' => $minaIds->map(fn ($id) => (string) $id)->values()->all(),
        ]);

        return \App\Models\Mina::query()
            ->whereIn('id', $minaIds)
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    public function createForUser(Usuario $usuario, array $payload): array
    {
        $rqMina = $this->create($usuario, $payload);
        
        if (!$rqMina) {
            return ['success' => false, 'message' => 'No tienes permiso para crear solicitudes en esta mina'];
        }
        
        return ['success' => true, 'message' => 'Solicitud creada correctamente', 'data' => $rqMina];
    }

    public function updateForUser(Usuario $usuario, string $id, array $payload): array
    {
        $rqMina = RQMina::query()->find($id);
        
        if (!$rqMina) {
            return ['success' => false, 'message' => 'Solicitud no encontrada'];
        }
        
        $updated = $this->update($usuario, $rqMina, $payload);
        
        if (!$updated) {
            return ['success' => false, 'message' => 'No tienes permiso para actualizar esta solicitud'];
        }
        
        return ['success' => true, 'message' => 'Solicitud actualizada correctamente', 'data' => $updated];
    }
}
