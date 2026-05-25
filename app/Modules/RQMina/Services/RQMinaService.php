<?php

namespace App\Modules\RQMina\Services;

use App\Models\Mina;
use App\Models\Oficina;
use App\Models\RQMina;
use App\Models\Taller;
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
            'transportes:id,rq_mina_id,transporte,cantidad',
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
                    ->orWhere('destino_nombre', 'like', $like)
                    ->orWhere('destino_tipo', 'like', $like)
                    ->orWhereHas('mina', fn ($mineQuery) => $mineQuery->where('nombre', 'like', $like))
                    ->orWhereHas('transportes', fn ($transportQuery) => $transportQuery->where('transporte', 'like', $like))
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
            ->with(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle', 'transportes'])
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
            'destino_tipo' => (string) ($payload['destino_tipo'] ?? ''),
            'destino_id' => (string) ($payload['destino_id'] ?? ''),
            'detalle_count' => count($payload['detalle'] ?? []),
            'detalle_total_cantidad' => collect($payload['detalle'] ?? [])->sum(fn (array $item) => (int) ($item['cantidad'] ?? 0)),
            'transporte_count' => count($payload['transporte'] ?? []),
        ]);

        $destination = $this->resolveDestination(
            usuario: $usuario,
            destinoTipo: $payload['destino_tipo'] ?? null,
            destinoId: $payload['destino_id'] ?? null,
            legacyMinaId: $payload['mina_id'] ?? null,
            legacyMinaName: $payload['mina'] ?? null,
        );

        if (!$destination || !PermissionMatrix::userCan($usuario, 'rq_mina', 'crear') || !$this->policy->canAccessMina($usuario, $destination['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $payload, $destination): RQMina {
            $rqMina = RQMina::query()->create([
                'id' => (string) Str::uuid(),
                'mina_id' => $destination['mina_id'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'destino_nombre' => $destination['nombre'],
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

            $transportRows = $this->buildTransporteRows((string) $rqMina->id, $payload['transporte'] ?? []);
            if (!empty($transportRows)) {
                $rqMina->transportes()->insert($transportRows);
            }

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad'] ?? 0)),
                'transporte_guardado' => array_map(static fn (array $row): array => [
                    'transporte' => (string) ($row['transporte'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $transportRows),
            ]);

            return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle', 'transportes']);
        });
    }

    public function update(Usuario $usuario, RQMina $rqMina, array $payload): ?RQMina
    {
        Log::info('rqmina.update_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'rq_mina_id' => (string) $rqMina->id,
            'mina_id' => (string) ($payload['mina_id'] ?? ''),
            'destino_tipo' => (string) ($payload['destino_tipo'] ?? ''),
            'destino_id' => (string) ($payload['destino_id'] ?? ''),
            'detalle_count' => count($payload['detalle'] ?? []),
            'detalle_total_cantidad' => collect($payload['detalle'] ?? [])->sum(fn (array $item) => (int) ($item['cantidad'] ?? 0)),
            'transporte_count' => count($payload['transporte'] ?? []),
        ]);

        if (!$this->policy->update($usuario, $rqMina)) {
            return null;
        }

        $destination = $this->resolveDestination(
            usuario: $usuario,
            destinoTipo: $payload['destino_tipo'] ?? null,
            destinoId: $payload['destino_id'] ?? null,
            legacyMinaId: $payload['mina_id'] ?? $rqMina->mina_id,
            legacyMinaName: $payload['mina'] ?? null,
        );

        if (!$destination || !$this->policy->canAccessMina($usuario, $destination['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($rqMina, $payload, $destination): RQMina {
            $rqMina->fill([
                'mina_id' => $destination['mina_id'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'destino_nombre' => $destination['nombre'],
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]);
            $rqMina->save();

            $rqMina->detalle()->delete();
            $rqMina->transportes()->delete();

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

            $transportRows = $this->buildTransporteRows((string) $rqMina->id, $payload['transporte'] ?? []);
            if (!empty($transportRows)) {
                $rqMina->transportes()->insert($transportRows);
            }

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad'] ?? 0)),
                'transporte_guardado' => array_map(static fn (array $row): array => [
                    'transporte' => (string) ($row['transporte'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $transportRows),
            ]);

            return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle', 'transportes']);
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

        return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'detalle', 'transportes']);
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
            return Mina::query()->where('estado', 'ACTIVO')->orderBy('nombre')->get(['id', 'nombre']);
        }

        $minaIds = DB::table('usuario_mina_scope')->where('usuario_id', $usuario->id)->pluck('mina_id');

        Log::info('rqmina.available_minas_scope_loaded', [
            'usuario_id' => (string) $usuario->id,
            'scope_minas' => $minaIds->map(fn ($id) => (string) $id)->values()->all(),
        ]);

        return Mina::query()
            ->whereIn('id', $minaIds)
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    public function getLugarOptions(Usuario $usuario): Collection
    {
        $minas = $this->getAvailableMinas($usuario)
            ->map(fn (Mina $mina): array => [
                'tipo' => 'MINA',
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
                'label' => 'Mina - '.$mina->nombre,
            ]);

        $talleres = Taller::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Taller $taller): array => [
                'tipo' => 'TALLER',
                'id' => (string) $taller->id,
                'nombre' => (string) $taller->nombre,
                'label' => 'Taller - '.$taller->nombre,
            ]);

        $oficinas = Oficina::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Oficina $oficina): array => [
                'tipo' => 'OFICINA',
                'id' => (string) $oficina->id,
                'nombre' => (string) $oficina->nombre,
                'label' => 'Oficina - '.$oficina->nombre,
            ]);

        return $minas->concat($talleres)->concat($oficinas)->values();
    }

    public function resolveDestination(
        Usuario $usuario,
        mixed $destinoTipo = null,
        mixed $destinoId = null,
        mixed $legacyMinaId = null,
        mixed $legacyMinaName = null,
    ): ?array {
        $tipo = strtoupper(trim((string) $destinoTipo));
        $id = trim((string) $destinoId);
        $legacyId = trim((string) $legacyMinaId);
        $legacyName = trim((string) $legacyMinaName);

        if ($tipo === '' && $id === '') {
            if ($legacyId !== '') {
                $tipo = 'MINA';
                $id = $legacyId;
            } elseif ($legacyName !== '') {
                $mina = $this->findAvailableMinaByName($usuario, $legacyName);
                if (!$mina) {
                    return null;
                }

                return [
                    'tipo' => 'MINA',
                    'id' => (string) $mina->id,
                    'nombre' => (string) $mina->nombre,
                    'mina_id' => (string) $mina->id,
                ];
            }
        }

        if (!in_array($tipo, ['MINA', 'TALLER', 'OFICINA'], true) || $id === '') {
            return null;
        }

        if ($tipo === 'MINA') {
            $mina = Mina::query()
                ->where('id', $id)
                ->where('estado', 'ACTIVO')
                ->first(['id', 'nombre']);

            return $mina ? [
                'tipo' => 'MINA',
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
                'mina_id' => (string) $mina->id,
            ] : null;
        }

        $item = $tipo === 'TALLER'
            ? Taller::query()->where('id', $id)->where('estado', 'ACTIVO')->first(['id', 'nombre'])
            : Oficina::query()->where('id', $id)->where('estado', 'ACTIVO')->first(['id', 'nombre']);

        if (!$item) {
            return null;
        }

        $anchorMinaId = $this->resolveAnchorMinaId($usuario, $legacyId);
        if (!$anchorMinaId) {
            return null;
        }

        return [
            'tipo' => $tipo,
            'id' => (string) $item->id,
            'nombre' => (string) $item->nombre,
            'mina_id' => $anchorMinaId,
        ];
    }

    private function buildTransporteRows(string $rqMinaId, array $items): array
    {
        return collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item) use ($rqMinaId): ?array {
                $transporte = trim((string) ($item['transporte'] ?? ''));
                $cantidad = (int) ($item['cantidad'] ?? 0);

                if ($transporte === '' || $cantidad <= 0) {
                    return null;
                }

                return [
                    'id' => (string) Str::uuid(),
                    'rq_mina_id' => $rqMinaId,
                    'transporte' => $transporte,
                    'cantidad' => $cantidad,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function findAvailableMinaByName(Usuario $usuario, string $name): ?Mina
    {
        $normalized = mb_strtolower(trim($name));

        return $this->getAvailableMinas($usuario)
            ->first(fn (Mina $mina): bool => mb_strtolower(trim((string) $mina->nombre)) === $normalized);
    }

    private function resolveAnchorMinaId(Usuario $usuario, string $preferredMinaId = ''): ?string
    {
        if (
            $preferredMinaId !== ''
            && Mina::query()->where('id', $preferredMinaId)->where('estado', 'ACTIVO')->exists()
            && $this->policy->canAccessMina($usuario, $preferredMinaId)
        ) {
            return $preferredMinaId;
        }

        $available = $this->getAvailableMinas($usuario)->first();

        return $available ? (string) $available->id : null;
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
