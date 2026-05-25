<?php

namespace App\Modules\ParadaHerramientas\Services;

use App\Models\GrupoTrabajo;
use App\Models\ParadaHerramientaGrupo;
use App\Models\ParadaHerramientaItem;
use App\Models\ParadaHerramientaLista;
use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Support\Rbac\PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ParadaHerramientaService
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function listParadas(Usuario $usuario, array $filters = []): Collection
    {
        $query = RQMina::query()
            ->with([
                'mina:id,nombre',
                'listaHerramientas:id,rq_mina_id,anio_iso,semana_iso,fecha_limite_envio,estado,enviado_at',
                'gruposTrabajo:id,rq_mina_id,servicio,turno,fecha',
            ])
            ->whereNotNull('fecha_inicio');

        $this->applyMineScope($query, $usuario);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(' ', '%', $search) . '%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('area', 'like', $like)
                    ->orWhere('destino_nombre', 'like', $like)
                    ->orWhereHas('mina', fn ($mineQuery) => $mineQuery->where('nombre', 'like', $like));
            });
        }

        if (!empty($filters['estado_lista'])) {
            $estado = strtoupper((string) $filters['estado_lista']);
            if ($estado === 'PENDIENTE') {
                $query->whereDoesntHave('listaHerramientas');
            } elseif (in_array($estado, ['BORRADOR', 'ENVIADO'], true)) {
                $query->whereHas('listaHerramientas', fn ($listQuery) => $listQuery->where('estado', $estado));
            }
        }

        return $query
            ->orderBy('fecha_inicio', 'asc')
            ->orderBy('fecha_fin', 'asc')
            ->orderBy('area', 'asc')
            ->get()
            ->map(fn (RQMina $rq): array => $this->toListRow($rq));
    }

    public function findParadaForUser(Usuario $usuario, string $rqMinaId): ?RQMina
    {
        $rq = RQMina::query()
            ->with([
                'mina:id,nombre',
                'gruposTrabajo:id,rq_mina_id,servicio,turno,fecha',
                'listaHerramientas.grupos.items',
            ])
            ->find($rqMinaId);

        if (!$rq || !$this->canAccessRQMina($usuario, $rq)) {
            return null;
        }

        return $rq;
    }

    public function ensureLista(RQMina $rq, Usuario $usuario): ParadaHerramientaLista
    {
        $fechaInicio = Carbon::parse($rq->fecha_inicio);
        $lista = $rq->listaHerramientas;

        if (!$lista) {
            $lista = ParadaHerramientaLista::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rq->id,
                'anio_iso' => (int) $fechaInicio->isoWeekYear(),
                'semana_iso' => (int) $fechaInicio->isoWeek(),
                'fecha_limite_envio' => $this->deadlineFor($rq),
                'estado' => 'BORRADOR',
                'created_by_usuario_id' => $usuario->id,
                'updated_by_usuario_id' => $usuario->id,
            ]);

            $this->seedGroupsFromParada($lista, $rq);
        }

        return $lista->fresh(['grupos.items', 'rqMina.mina']);
    }

    public function saveLista(Usuario $usuario, RQMina $rq, array $payload): array
    {
        $lista = $this->ensureLista($rq, $usuario);

        if (!$this->canEditLista($usuario, $rq, $lista)) {
            return [
                'ok' => false,
                'message' => 'La lista ya no puede editarse porque vencio el plazo de envio o ya fue enviada.',
            ];
        }

        $groups = $this->normalizeGroups($payload['grupos'] ?? []);
        if (empty($groups)) {
            return [
                'ok' => false,
                'message' => 'Debes registrar al menos un grupo con herramientas.',
            ];
        }

        DB::transaction(function () use ($lista, $usuario, $payload, $groups): void {
            $lista->fill([
                'observaciones' => trim((string) ($payload['observaciones'] ?? '')) ?: null,
                'updated_by_usuario_id' => $usuario->id,
            ]);
            $lista->save();

            $lista->grupos()->delete();

            foreach ($groups as $groupIndex => $group) {
                $grupo = ParadaHerramientaGrupo::query()->create([
                    'id' => (string) Str::uuid(),
                    'lista_id' => $lista->id,
                    'grupo_trabajo_id' => $group['grupo_trabajo_id'] ?: null,
                    'nombre' => $group['nombre'],
                    'orden' => $groupIndex + 1,
                    'observaciones' => $group['observaciones'] ?: null,
                ]);

                $rows = [];
                $order = 1;
                foreach ($group['items'] as $item) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'grupo_id' => $grupo->id,
                        'tipo' => $item['tipo'],
                        'descripcion' => $item['descripcion'],
                        'cantidad_solicitada' => $item['cantidad_solicitada'],
                        'observaciones' => $item['observaciones'] ?: null,
                        'pedido_solicitado_at' => $item['pedido_solicitado_at'] ?? null,
                        'pedido_llego_at' => $item['pedido_llego_at'] ?? null,
                        'orden' => $order++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($rows)) {
                    $grupo->items()->insert($rows);
                }
            }
        });

        return [
            'ok' => true,
            'lista' => $lista->fresh(['grupos.items', 'rqMina.mina']),
            'message' => 'Lista de herramientas guardada correctamente.',
        ];
    }

    public function enviarLista(Usuario $usuario, RQMina $rq): array
    {
        $lista = $this->ensureLista($rq, $usuario);

        if (!$this->canEditLista($usuario, $rq, $lista)) {
            return [
                'ok' => false,
                'message' => 'La lista no puede enviarse porque vencio el plazo o ya fue enviada.',
            ];
        }

        $hasItems = $lista->grupos()
            ->whereHas('items')
            ->exists();

        if (!$hasItems) {
            return [
                'ok' => false,
                'message' => 'La lista debe tener al menos una herramienta antes de enviarse.',
            ];
        }

        $lista->fill([
            'estado' => 'ENVIADO',
            'enviado_at' => now(),
            'updated_by_usuario_id' => $usuario->id,
        ]);
        $lista->save();

        return [
            'ok' => true,
            'lista' => $lista->fresh(['grupos.items', 'rqMina.mina']),
            'message' => 'Lista de herramientas enviada correctamente.',
        ];
    }

    public function emitDeadlineReminders(): int
    {
        if (!Schema::hasTable('notification_types') || !Schema::hasTable('parada_herramienta_listas')) {
            return 0;
        }

        $targetDeadline = now()->addDays(2)->toDateString();
        $targetStart = now()->addDays(9)->toDateString();
        $count = 0;

        $rqs = RQMina::query()
            ->with(['mina:id,nombre', 'listaHerramientas:id,rq_mina_id,fecha_limite_envio,estado'])
            ->whereDate('fecha_inicio', $targetStart)
            ->where(function ($query): void {
                $query->whereDoesntHave('listaHerramientas')
                    ->orWhereHas('listaHerramientas', fn ($listQuery) => $listQuery->where('estado', '!=', 'ENVIADO'));
            })
            ->get();

        foreach ($rqs as $rq) {
            $deadline = $rq->listaHerramientas?->fecha_limite_envio
                ? Carbon::parse($rq->listaHerramientas->fecha_limite_envio)
                : $this->deadlineFor($rq);

            if ($deadline->toDateString() !== $targetDeadline) {
                continue;
            }

            $recipients = $this->jefesPlaneamiento();
            $event = $this->notificationService->emit('lista_herramientas_por_vencer', [
                'actor_user_id' => null,
                'entity_type' => 'rq_mina',
                'entity_id' => (string) $rq->id,
                'title' => 'Lista de herramientas por vencer',
                'message' => sprintf(
                    'La lista de herramientas para %s vence el %s. Faltan 2 dias para el limite de envio.',
                    $rq->destino_nombre ?: ($rq->mina?->nombre ?? 'la parada'),
                    $deadline->format('d/m/Y')
                ),
                'priority' => 'high',
                'category' => 'accion_requerida',
                'permission_module' => 'man_power',
                'permission_action' => 'ver',
                'require_permission' => false,
                'target_user_ids' => $recipients,
                'payload' => [
                    'rq_mina_id' => (string) $rq->id,
                    'fecha_limite_envio' => $deadline->toDateString(),
                ],
                'dedupe_key' => 'lista_herramientas_por_vencer:' . $rq->id . ':' . $deadline->format('Ymd'),
            ]);

            if ($event) {
                $count++;
            }
        }

        return $count;
    }

    public function toDetailView(RQMina $rq, Usuario $usuario): array
    {
        $lista = $this->ensureLista($rq, $usuario);
        $fechaInicio = Carbon::parse($rq->fecha_inicio);
        $fechaFin = $rq->fecha_fin ? Carbon::parse($rq->fecha_fin) : null;

        return [
            'rq_mina_id' => (string) $rq->id,
            'lugar' => $rq->destino_nombre ?: ($rq->mina?->nombre ?? '-'),
            'mina' => $rq->mina?->nombre ?? '-',
            'area' => $rq->area,
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $fechaFin?->toDateString(),
            'semana' => (int) $fechaInicio->isoWeek(),
            'anio_semana' => (int) $fechaInicio->isoWeekYear(),
            'fecha_limite_envio' => Carbon::parse($lista->fecha_limite_envio)->toDateString(),
            'dias_para_limite' => now()->startOfDay()->diffInDays(Carbon::parse($lista->fecha_limite_envio)->startOfDay(), false),
            'estado_lista' => $lista->estado,
            'enviado_at' => $lista->enviado_at?->format('Y-m-d H:i:s'),
            'observaciones' => $lista->observaciones,
            'puede_editar' => $this->canEditLista($usuario, $rq, $lista),
            'puede_actualizar_pedido' => $this->canUpdatePedido($usuario),
            'grupos' => $lista->grupos->map(fn (ParadaHerramientaGrupo $grupo): array => [
                'id' => (string) $grupo->id,
                'grupo_trabajo_id' => (string) ($grupo->grupo_trabajo_id ?? ''),
                'nombre' => $grupo->nombre,
                'observaciones' => $grupo->observaciones,
                'base' => $grupo->items->where('tipo', 'BASE')->values()->map(fn ($item): array => [
                    'id' => (string) $item->id,
                    'descripcion' => $item->descripcion,
                    'cantidad_solicitada' => (int) $item->cantidad_solicitada,
                    'observaciones' => $item->observaciones,
                    'pedido_solicitado_at' => $item->pedido_solicitado_at?->toDateString(),
                    'pedido_llego_at' => $item->pedido_llego_at?->toDateString(),
                ])->all(),
                'adicional' => $grupo->items->where('tipo', 'ADICIONAL')->values()->map(fn ($item): array => [
                    'id' => (string) $item->id,
                    'descripcion' => $item->descripcion,
                    'cantidad_solicitada' => (int) $item->cantidad_solicitada,
                    'observaciones' => $item->observaciones,
                    'pedido_solicitado_at' => $item->pedido_solicitado_at?->toDateString(),
                    'pedido_llego_at' => $item->pedido_llego_at?->toDateString(),
                ])->all(),
            ])->values()->all(),
        ];
    }

    private function toListRow(RQMina $rq): array
    {
        $fechaInicio = Carbon::parse($rq->fecha_inicio);
        $lista = $rq->listaHerramientas;
        $deadline = $lista?->fecha_limite_envio ? Carbon::parse($lista->fecha_limite_envio) : $this->deadlineFor($rq);
        $dias = now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);

        return [
            'rq_mina_id' => (string) $rq->id,
            'lugar' => $rq->destino_nombre ?: ($rq->mina?->nombre ?? '-'),
            'mina' => $rq->mina?->nombre ?? '-',
            'area' => $rq->area,
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $rq->fecha_fin?->toDateString(),
            'semana' => (int) $fechaInicio->isoWeek(),
            'anio_semana' => (int) $fechaInicio->isoWeekYear(),
            'fecha_limite_envio' => $deadline->toDateString(),
            'dias_para_limite' => $dias,
            'estado_lista' => $lista?->estado ?? 'PENDIENTE',
            'enviado_at' => $lista?->enviado_at?->format('Y-m-d H:i:s'),
            'grupos_count' => $lista?->grupos?->count() ?? $rq->gruposTrabajo->count(),
        ];
    }

    private function seedGroupsFromParada(ParadaHerramientaLista $lista, RQMina $rq): void
    {
        $grupos = $rq->gruposTrabajo->values();

        if ($grupos->isEmpty()) {
            ParadaHerramientaGrupo::query()->create([
                'id' => (string) Str::uuid(),
                'lista_id' => $lista->id,
                'nombre' => 'Grupo 1',
                'orden' => 1,
            ]);

            return;
        }

        foreach ($grupos as $index => $grupoTrabajo) {
            ParadaHerramientaGrupo::query()->create([
                'id' => (string) Str::uuid(),
                'lista_id' => $lista->id,
                'grupo_trabajo_id' => $grupoTrabajo->id,
                'nombre' => trim(($grupoTrabajo->servicio ?: 'Grupo') . ' ' . ($grupoTrabajo->turno ? '(' . $grupoTrabajo->turno . ')' : '')),
                'orden' => $index + 1,
            ]);
        }
    }

    private function normalizeGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $nombre = trim((string) ($group['nombre'] ?? ''));
            $observaciones = trim((string) ($group['observaciones'] ?? ''));
            $grupoTrabajoId = trim((string) ($group['grupo_trabajo_id'] ?? ''));
            $items = array_merge(
                $this->normalizeItems($group['base'] ?? [], 'BASE'),
                $this->normalizeItems($group['adicional'] ?? [], 'ADICIONAL'),
            );

            if ($nombre === '' && empty($items)) {
                continue;
            }

            if ($nombre === '') {
                $nombre = 'Grupo ' . (count($normalized) + 1);
            }

            if (empty($items)) {
                continue;
            }

            $normalized[] = [
                'nombre' => $nombre,
                'grupo_trabajo_id' => $grupoTrabajoId,
                'observaciones' => $observaciones,
                'items' => $items,
            ];
        }

        return $normalized;
    }

    private function normalizeItems(mixed $items, string $tipo): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $descripcion = trim((string) ($item['descripcion'] ?? ''));
            $cantidad = (int) ($item['cantidad_solicitada'] ?? $item['cantidad'] ?? 0);
            $observaciones = trim((string) ($item['observaciones'] ?? ''));
            $pedidoSolicitado = $this->sanitizeDate($item['pedido_solicitado_at'] ?? null);
            $pedidoLlego = $this->sanitizeDate($item['pedido_llego_at'] ?? null);

            if ($descripcion === '' && $cantidad <= 0 && $observaciones === '') {
                continue;
            }

            if ($descripcion !== '' && $cantidad > 0) {
                $normalized[] = [
                    'tipo' => $tipo,
                    'descripcion' => $descripcion,
                    'cantidad_solicitada' => $cantidad,
                    'observaciones' => $observaciones,
                    'pedido_solicitado_at' => $pedidoSolicitado,
                    'pedido_llego_at' => $pedidoLlego,
                ];
            }
        }

        return $normalized;
    }

    private function canEditLista(Usuario $usuario, RQMina $rq, ParadaHerramientaLista $lista): bool
    {
        if (strtoupper((string) $lista->estado) === 'ENVIADO') {
            return false;
        }

        if (PermissionMatrix::userCan($usuario, 'herramientas', 'administrar')) {
            return true;
        }

        return now()->startOfDay()->lte(Carbon::parse($lista->fecha_limite_envio)->startOfDay());
    }

    public function updatePedido(Usuario $usuario, RQMina $rq, array $payload): array
    {
        $lista = $this->ensureLista($rq, $usuario)->load('grupos.items');

        if (!$this->canUpdatePedido($usuario)) {
            return [
                'ok' => false,
                'message' => 'No tienes permisos para actualizar el pedido de herramientas.',
            ];
        }

        $updates = $this->normalizePedidoUpdates($payload['grupos'] ?? []);
        if (empty($updates)) {
            return [
                'ok' => false,
                'message' => 'No hay pedidos para actualizar.',
            ];
        }

        $allowedIds = $lista->grupos
            ->flatMap(fn (ParadaHerramientaGrupo $grupo) => $grupo->items->pluck('id'))
            ->map(fn ($id) => (string) $id)
            ->flip();

        DB::transaction(function () use ($updates, $allowedIds): void {
            foreach ($updates as $itemId => $fields) {
                if (!$allowedIds->has($itemId)) {
                    continue;
                }

                ParadaHerramientaItem::query()
                    ->where('id', $itemId)
                    ->update([
                        'pedido_solicitado_at' => $fields['pedido_solicitado_at'],
                        'pedido_llego_at' => $fields['pedido_llego_at'],
                        'updated_at' => now(),
                    ]);
            }
        });

        return [
            'ok' => true,
            'message' => 'Pedido actualizado correctamente.',
        ];
    }

    private function deadlineFor(RQMina $rq): Carbon
    {
        return Carbon::parse($rq->fecha_inicio)->subDays(7)->startOfDay();
    }

    private function canUpdatePedido(Usuario $usuario): bool
    {
        return $this->isLogistica($usuario) || PermissionMatrix::userCan($usuario, 'man_power', 'administrar');
    }

    private function isLogistica(Usuario $usuario): bool
    {
        $roles = collect([$usuario->rol])->merge($usuario->rolesAdicionales)->filter();

        return $roles->contains(function ($rol): bool {
            $name = strtoupper((string) $rol->nombre);

            return str_contains($name, 'LOGIST');
        });
    }

    private function applyMineScope($query, Usuario $usuario): void
    {
        if ($this->isPrivileged($usuario)) {
            return;
        }

        $minaIds = $usuario->scopesMina()->pluck('mina_id');
        $query->whereIn('mina_id', $minaIds);
    }

    private function canAccessRQMina(Usuario $usuario, RQMina $rq): bool
    {
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return $usuario->scopesMina()->where('mina_id', $rq->mina_id)->exists();
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'herramientas', 'administrar');
    }

    private function jefesPlaneamiento(): array
    {
        $query = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos']);

        if (Schema::hasColumn('usuarios', 'estado')) {
            $query->where('estado', 'ACTIVO');
        }

        $users = $query->get();

        $jefes = $users->filter(function (Usuario $usuario): bool {
            $roles = collect([$usuario->rol])->merge($usuario->rolesAdicionales)->filter();

            return $roles->contains(function ($rol): bool {
                $name = strtoupper((string) $rol->nombre);

                return str_contains($name, 'JEFE') && (str_contains($name, 'PLANE') || str_contains($name, 'PLANIF'));
            });
        })->pluck('id')->map(fn ($id) => (string) $id)->values();

        if ($jefes->isNotEmpty()) {
            return $jefes->all();
        }

        return $users
            ->filter(function (Usuario $usuario): bool {
                $roleName = strtoupper((string) optional($usuario->rol)->nombre);

                return in_array($roleName, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
                    || PermissionMatrix::userCan($usuario, 'man_power', 'administrar');
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function sanitizeDate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));

        return $raw !== '' ? $raw : null;
    }

    private function normalizePedidoUpdates(mixed $groups): array
    {
        if (!is_array($groups)) {
            return [];
        }

        $updates = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach (['base', 'adicional'] as $type) {
                foreach ((array) ($group[$type] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $itemId = trim((string) ($item['id'] ?? ''));
                    if ($itemId === '') {
                        continue;
                    }

                    $updates[$itemId] = [
                        'pedido_solicitado_at' => $this->sanitizeDate($item['pedido_solicitado_at'] ?? null),
                        'pedido_llego_at' => $this->sanitizeDate($item['pedido_llego_at'] ?? null),
                    ];
                }
            }
        }

        return $updates;
    }
}
