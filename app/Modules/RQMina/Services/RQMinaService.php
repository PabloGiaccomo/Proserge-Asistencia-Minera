<?php

namespace App\Modules\RQMina\Services;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\RQMina\Policies\RQMinaPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RQMinaService
{
    public function __construct(private readonly RQMinaPolicy $policy)
    {
    }

    public function listForUser(Usuario $usuario, array $filters): Collection
    {
        $query = RQMina::query()->with(['mina:id,nombre', 'creador:id,email']);

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['created_by_usuario_id'])) {
            $query->where('created_by_usuario_id', $filters['created_by_usuario_id']);
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

        if (!$this->isPrivileged($usuario)) {
            $minaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereIn('mina_id', $minaIds);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function findForUser(Usuario $usuario, string $id): ?RQMina
    {
        $rqMina = RQMina::query()
            ->with(['mina:id,nombre', 'creador:id,email', 'detalle'])
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
        if (!$this->policy->canAccessMina($usuario, $payload['mina_id'])) {
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

            return $rqMina->load(['mina:id,nombre', 'creador:id,email', 'detalle']);
        });
    }

    public function update(Usuario $usuario, RQMina $rqMina, array $payload): ?RQMina
    {
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

            return $rqMina->load(['mina:id,nombre', 'creador:id,email', 'detalle']);
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

        return $rqMina->load(['mina:id,nombre', 'creador:id,email', 'detalle']);
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

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function getAvailableMinas(Usuario $usuario): Collection
    {
        if ($this->isPrivileged($usuario)) {
            return \App\Models\Mina::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        }

        return $usuario->scopesMina()
            ->with('mina:id,nombre')
            ->get()
            ->pluck('mina')
            ->filter()
            ->unique('id')
            ->values();
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
