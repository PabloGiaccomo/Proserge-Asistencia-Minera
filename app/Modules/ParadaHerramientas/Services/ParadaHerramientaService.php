<?php

namespace App\Modules\ParadaHerramientas\Services;

use App\Models\GrupoTrabajo;
use App\Models\ParadaHerramientaCatalogo;
use App\Models\ParadaHerramientaCatalogoObservacion;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ParadaHerramientaService
{
    private const CATEGORY_TOOL = ParadaHerramientaItem::CATEGORIA_HERRAMIENTA;
    private const CATEGORY_CONSUMABLE = ParadaHerramientaItem::CATEGORIA_CONSUMIBLE;

    private const TYPE_BASE = ParadaHerramientaItem::TIPO_BASE;
    private const TYPE_ADDITIONAL = ParadaHerramientaItem::TIPO_ADICIONAL;

    private const FORM_BUCKETS = [
        'base' => [self::TYPE_BASE, self::CATEGORY_TOOL],
        'adicional' => [self::TYPE_ADDITIONAL, self::CATEGORY_TOOL],
        'consumibles_base' => [self::TYPE_BASE, self::CATEGORY_CONSUMABLE],
        'consumibles_adicional' => [self::TYPE_ADDITIONAL, self::CATEGORY_CONSUMABLE],
    ];

    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function listParadas(Usuario $usuario, array $filters = []): Collection
    {
        $query = RQMina::query()
            ->with([
                'mina:id,nombre',
                'supervisor:id,nombre_completo,correo',
                'listaHerramientas:id,rq_mina_id,anio_iso,semana_iso,fecha_limite_envio,estado,enviado_at',
                'gruposTrabajo:id,rq_mina_id,servicio,turno,fecha',
            ])
            ->whereNotNull('fecha_inicio');

        $this->applyMineScope($query, $usuario);
        $this->applySupervisorScope($query, $usuario);

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

    public function deadlineAlerts(iterable $items, int $limit = 5): array
    {
        return collect($items)
            ->filter(function (array $item): bool {
                $days = (int) ($item['dias_para_limite'] ?? 999);
                $status = strtoupper((string) ($item['estado_lista'] ?? ''));

                return $status !== 'ENVIADO' && $days >= 0 && $days <= 7;
            })
            ->sortBy([
                ['dias_para_limite', 'asc'],
                ['fecha_limite_envio', 'asc'],
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    public function findParadaForUser(Usuario $usuario, string $rqMinaId): ?RQMina
    {
        $rq = RQMina::query()
            ->with([
                'mina:id,nombre',
                'supervisor:id,nombre_completo,correo',
                'supervisor.usuario:id,personal_id,email',
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
        $lista = $rq->relationLoaded('listaHerramientas')
            ? $rq->getRelation('listaHerramientas')
            : null;

        if (!$lista) {
            $lista = $rq->listaHerramientas()->first();
        }

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

        $lateChangeComment = trim((string) ($payload['comentario_cambio_previo'] ?? ''));
        if ($this->requiresLateChangeComment($rq, $lista) && $lateChangeComment === '') {
            return [
                'ok' => false,
                'message' => 'Para modificar el requerimiento dentro de la semana previa a la parada debes registrar el motivo del cambio.',
            ];
        }

        $groups = $this->normalizeGroups($payload['grupos'] ?? []);
        if (empty($groups)) {
            return [
                'ok' => false,
                'message' => 'Debes registrar al menos un grupo con herramientas.',
            ];
        }

        DB::transaction(function () use ($lista, $usuario, $payload, $groups, $lateChangeComment): void {
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
                        'categoria' => $item['categoria'],
                        'descripcion' => $item['descripcion'],
                        'cantidad_solicitada' => $item['cantidad_solicitada'],
                        'cantidad_entregada' => $item['cantidad_entregada'],
                        'cantidad_recibida' => $item['cantidad_recibida'],
                        'unidad' => $item['unidad'],
                        'observaciones' => $item['observaciones'] ?: null,
                        'incidencia_durante_parada' => $item['incidencia_durante_parada'] ?: null,
                        'pedido_solicitado_at' => $item['pedido_solicitado_at'] ?? null,
                        'pedido_llego_at' => $item['pedido_llego_at'] ?? null,
                        'recepcion_estado' => $item['recepcion_estado'],
                        'recepcion_fecha' => $item['recepcion_fecha'] ?? null,
                        'recepcion_observacion' => $item['recepcion_observacion'] ?: null,
                        'recepcion_registrada_at' => $item['recepcion_registrada_at'] ?? null,
                        'recepcion_registrada_por_usuario_id' => $item['recepcion_registrada_por_usuario_id'] ?: null,
                        'comentario_cambio_previo' => $lateChangeComment !== '' ? $lateChangeComment : ($item['comentario_cambio_previo'] ?: null),
                        'orden' => $order++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($rows)) {
                    $grupo->items()->insert($rows);
                    $this->rememberCatalogRows($rows, $usuario);
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
            ->whereHas('items', fn ($query) => $query->where('cantidad_solicitada', '>', 0))
            ->exists();

        if (!$hasItems) {
            return [
                'ok' => false,
                'message' => 'La lista debe tener al menos una herramienta o consumible con cantidad solicitada antes de enviarse.',
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

    public function enviarRecordatorioSupervisor(Usuario $usuario, RQMina $rq, string $grupoId): array
    {
        $lista = $this->ensureLista($rq, $usuario)->load(['grupos.items', 'rqMina.supervisor.usuario']);
        $grupo = $lista->grupos->firstWhere('id', $grupoId);

        if (!$grupo) {
            return ['ok' => false, 'message' => 'Grupo de herramientas no encontrado.'];
        }

        $supervisor = $lista->rqMina?->supervisor;
        $email = trim((string) ($supervisor?->correo ?: $supervisor?->usuario?->email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'El supervisor a cargo no tiene un correo valido registrado.'];
        }

        $url = route('herramientas-parada.show', $rq->id);
        $subject = 'Recordatorio lista de herramientas - ' . ($grupo->nombre ?: 'Grupo');
        $message = implode("\n", [
            'Hola ' . ($supervisor?->nombre_completo ?: 'supervisor') . ',',
            '',
            'Por favor revisa y envia la lista de herramientas del grupo: ' . ($grupo->nombre ?: 'Grupo'),
            'Parada: ' . ($rq->area ?: '-'),
            'Lugar: ' . ($rq->destino_nombre ?: ($rq->mina?->nombre ?? '-')),
            'Semana: ' . Carbon::parse($rq->fecha_inicio)->isoWeek(),
            'Limite de envio: ' . Carbon::parse($lista->fecha_limite_envio)->format('d/m/Y'),
            '',
            'Link: ' . $url,
        ]);

        try {
            Mail::raw($message, function ($mail) use ($email, $subject): void {
                $mail->to($email)->subject($subject);
            });
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'No se pudo enviar el correo: ' . $exception->getMessage()];
        }

        return ['ok' => true, 'message' => 'Correo enviado al supervisor responsable: ' . $email];
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
        $deadline = Carbon::parse($lista->fecha_limite_envio)->startOfDay();
        $diasParaLimite = now()->startOfDay()->diffInDays($deadline, false);
        $puedeEditar = $this->canEditLista($usuario, $rq, $lista);
        $today = now()->startOfDay();
        $paradaIniciada = $today->gte($fechaInicio->copy()->startOfDay());
        $paradaFinalizada = $fechaFin ? $today->gte($fechaFin->copy()->startOfDay()) : false;

        return [
            'rq_mina_id' => (string) $rq->id,
            'lugar' => $rq->destino_nombre ?: ($rq->mina?->nombre ?? '-'),
            'mina' => $rq->mina?->nombre ?? '-',
            'area' => $rq->area,
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $fechaFin?->toDateString(),
            'parada_iniciada' => $paradaIniciada,
            'parada_finalizada' => $paradaFinalizada,
            'fase_parada' => $paradaFinalizada ? 'FINALIZADA' : ($paradaIniciada ? 'DURANTE' : 'PREVIA'),
            'semana' => (int) $fechaInicio->isoWeek(),
            'anio_semana' => (int) $fechaInicio->isoWeekYear(),
            'fecha_limite_envio' => $deadline->toDateString(),
            'dias_para_limite' => $diasParaLimite,
            'limite_envio_vencido' => $diasParaLimite < 0,
            'estado_lista' => $lista->estado,
            'enviado_at' => $lista->enviado_at?->format('Y-m-d H:i:s'),
            'observaciones' => $lista->observaciones,
            'supervisor_responsable' => [
                'nombre' => $rq->supervisor?->nombre_completo,
                'correo' => $rq->supervisor?->correo ?: $rq->supervisor?->usuario?->email,
            ],
            'puede_editar' => $puedeEditar,
            'puede_completar_requerimiento' => $puedeEditar,
            'puede_actualizar_pedido' => $this->canUpdatePedido($usuario),
            'requiere_comentario_cambio_previo' => $this->requiresLateChangeComment($rq, $lista),
            'fecha_recepcion_default' => now()->toDateString(),
            'grupos' => $lista->grupos->map(fn (ParadaHerramientaGrupo $grupo): array => [
                'id' => (string) $grupo->id,
                'grupo_trabajo_id' => (string) ($grupo->grupo_trabajo_id ?? ''),
                'nombre' => $grupo->nombre,
                'observaciones' => $grupo->observaciones,
                'base' => $this->itemsForBucket($grupo, self::TYPE_BASE, self::CATEGORY_TOOL),
                'adicional' => $this->itemsForBucket($grupo, self::TYPE_ADDITIONAL, self::CATEGORY_TOOL),
                'consumibles_base' => $this->itemsForBucket($grupo, self::TYPE_BASE, self::CATEGORY_CONSUMABLE),
                'consumibles_adicional' => $this->itemsForBucket($grupo, self::TYPE_ADDITIONAL, self::CATEGORY_CONSUMABLE),
            ])->values()->all(),
        ];
    }

    private function toListRow(RQMina $rq): array
    {
        $fechaInicio = Carbon::parse($rq->fecha_inicio);
        $fechaFin = $rq->fecha_fin ? Carbon::parse($rq->fecha_fin) : null;
        $lista = $rq->listaHerramientas;
        $deadline = $lista?->fecha_limite_envio ? Carbon::parse($lista->fecha_limite_envio) : $this->deadlineFor($rq);
        $dias = now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);
        $today = now()->startOfDay();
        $estadoLista = $lista?->estado ?? 'PENDIENTE';
        $limiteEnvioVencido = $dias < 0;
        $puedeCompletarRequerimiento = !$limiteEnvioVencido && strtoupper((string) $estadoLista) !== 'ENVIADO';

        return [
            'rq_mina_id' => (string) $rq->id,
            'lugar' => $rq->destino_nombre ?: ($rq->mina?->nombre ?? '-'),
            'mina' => $rq->mina?->nombre ?? '-',
            'area' => $rq->area,
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $fechaFin?->toDateString(),
            'parada_iniciada' => $today->gte($fechaInicio->copy()->startOfDay()),
            'parada_finalizada' => $fechaFin ? $today->gte($fechaFin->copy()->startOfDay()) : false,
            'semana' => (int) $fechaInicio->isoWeek(),
            'anio_semana' => (int) $fechaInicio->isoWeekYear(),
            'fecha_limite_envio' => $deadline->toDateString(),
            'dias_para_limite' => $dias,
            'limite_envio_vencido' => $limiteEnvioVencido,
            'estado_lista' => $estadoLista,
            'enviado_at' => $lista?->enviado_at?->format('Y-m-d H:i:s'),
            'grupos_count' => $lista?->grupos?->count() ?? $rq->gruposTrabajo->count(),
            'puede_completar_requerimiento' => $puedeCompletarRequerimiento,
        ];
    }

    private function itemsForBucket(ParadaHerramientaGrupo $grupo, string $tipo, string $categoria): array
    {
        return $grupo->items
            ->where('tipo', $tipo)
            ->filter(function ($item) use ($categoria): bool {
                $itemCategory = strtoupper((string) ($item->categoria ?: self::CATEGORY_TOOL));

                return $itemCategory === $categoria;
            })
            ->values()
            ->map(function ($item): array {
                $solicitada = (int) $item->cantidad_solicitada;
                $entregada = (int) ($item->cantidad_entregada ?? 0);

                return [
                    'id' => (string) $item->id,
                    'descripcion' => $item->descripcion,
                    'cantidad_solicitada' => $solicitada,
                    'cantidad_entregada' => $entregada,
                    'cantidad_recibida' => (int) ($item->cantidad_recibida ?? 0),
                    'cantidad_faltante' => max(0, $solicitada - $entregada),
                    'unidad' => $item->unidad,
                    'observaciones' => $item->observaciones,
                    'incidencia_durante_parada' => $item->incidencia_durante_parada,
                    'pedido_solicitado_at' => $item->pedido_solicitado_at?->toDateString(),
                    'pedido_llego_at' => $item->pedido_llego_at?->toDateString(),
                    'recepcion_estado' => $item->recepcion_estado ?: ParadaHerramientaItem::RECEPCION_PENDIENTE,
                    'recepcion_fecha' => $item->recepcion_fecha?->toDateString(),
                    'recepcion_observacion' => $item->recepcion_observacion,
                    'recepcion_registrada_at' => $item->recepcion_registrada_at?->format('Y-m-d H:i:s'),
                    'recepcion_registrada_por_usuario_id' => $item->recepcion_registrada_por_usuario_id,
                    'comentario_cambio_previo' => $item->comentario_cambio_previo,
                ];
            })
            ->all();
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
            $items = [];
            foreach (self::FORM_BUCKETS as $bucket => [$tipo, $categoria]) {
                $items = array_merge($items, $this->normalizeItems($group[$bucket] ?? [], $tipo, $categoria));
            }

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

    private function normalizeItems(mixed $items, string $tipo, string $categoria): array
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
            $cantidad = max(0, (int) ($item['cantidad_solicitada'] ?? $item['cantidad'] ?? 0));
            $cantidadEntregada = max(0, (int) ($item['cantidad_entregada'] ?? 0));
            $cantidadRecibida = max(0, (int) ($item['cantidad_recibida'] ?? 0));
            $unidad = trim((string) ($item['unidad'] ?? ''));
            $observaciones = trim((string) ($item['observaciones'] ?? ''));
            $pedidoSolicitado = $this->sanitizeDate($item['pedido_solicitado_at'] ?? null);
            $pedidoLlego = $this->sanitizeDate($item['pedido_llego_at'] ?? null);
            $incidenciaDuranteParada = trim((string) ($item['incidencia_durante_parada'] ?? ''));
            $recepcionEstado = $this->normalizeRecepcionEstado($item['recepcion_estado'] ?? ParadaHerramientaItem::RECEPCION_PENDIENTE);
            $recepcionFecha = $this->sanitizeDate($item['recepcion_fecha'] ?? null);
            $recepcionObservacion = trim((string) ($item['recepcion_observacion'] ?? ''));
            $recepcionRegistradaAt = trim((string) ($item['recepcion_registrada_at'] ?? ''));
            $recepcionRegistradaPor = trim((string) ($item['recepcion_registrada_por_usuario_id'] ?? ''));
            $comentarioCambioPrevio = trim((string) ($item['comentario_cambio_previo'] ?? ''));

            if ($descripcion === '' && $cantidad <= 0 && $observaciones === '') {
                continue;
            }

            if ($descripcion !== '') {
                $normalized[] = [
                    'tipo' => $tipo,
                    'categoria' => $categoria,
                    'descripcion' => $descripcion,
                    'cantidad_solicitada' => $cantidad,
                    'cantidad_entregada' => $cantidadEntregada,
                    'cantidad_recibida' => $cantidadRecibida,
                    'unidad' => $unidad !== '' ? $unidad : null,
                    'observaciones' => $observaciones,
                    'incidencia_durante_parada' => $incidenciaDuranteParada,
                    'pedido_solicitado_at' => $pedidoSolicitado,
                    'pedido_llego_at' => $pedidoLlego,
                    'recepcion_estado' => $recepcionEstado,
                    'recepcion_fecha' => $recepcionFecha,
                    'recepcion_observacion' => $recepcionObservacion,
                    'recepcion_registrada_at' => $recepcionRegistradaAt !== '' ? $recepcionRegistradaAt : null,
                    'recepcion_registrada_por_usuario_id' => $recepcionRegistradaPor,
                    'comentario_cambio_previo' => $comentarioCambioPrevio,
                ];
            }
        }

        return $normalized;
    }

    private function canEditLista(Usuario $usuario, RQMina $rq, ParadaHerramientaLista $lista): bool
    {
        $today = now()->startOfDay();
        $deadline = Carbon::parse($lista->fecha_limite_envio)->startOfDay();

        if ($today->gt($deadline)) {
            return false;
        }

        return strtoupper((string) $lista->estado) !== 'ENVIADO';
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

        $mode = in_array(($payload['modo'] ?? ''), ['entrega', 'recepcion'], true)
            ? (string) $payload['modo']
            : 'todo';

        if ($mode === 'entrega' && !$this->hasParadaStarted($rq)) {
            return [
                'ok' => false,
                'message' => 'Las entregas se habilitan cuando inicia la parada.',
            ];
        }

        if ($mode === 'recepcion' && !$this->hasParadaEnded($rq)) {
            return [
                'ok' => false,
                'message' => 'La recepcion final se habilita cuando termina la parada.',
            ];
        }

        $updates = $this->normalizePedidoUpdates($payload['grupos'] ?? []);
        if (empty($updates)) {
            return [
                'ok' => false,
                'message' => 'No hay pedidos para actualizar.',
            ];
        }
        $fechaRecepcion = $this->sanitizeDate($payload['fecha_recepcion'] ?? null) ?: now()->toDateString();

        $allowedIds = $lista->grupos
            ->flatMap(fn (ParadaHerramientaGrupo $grupo) => $grupo->items->pluck('id'))
            ->map(fn ($id) => (string) $id)
            ->flip();

        DB::transaction(function () use ($updates, $allowedIds, $mode, $fechaRecepcion, $usuario): void {
            foreach ($updates as $itemId => $fields) {
                if (!$allowedIds->has($itemId)) {
                    continue;
                }

                $data = [
                    'pedido_solicitado_at' => $fields['pedido_solicitado_at'],
                    'pedido_llego_at' => $fields['pedido_llego_at'],
                    'updated_at' => now(),
                ];

                if ($mode === 'todo' || $mode === 'entrega') {
                    $data['cantidad_entregada'] = $fields['cantidad_entregada'];
                    $data['incidencia_durante_parada'] = $fields['incidencia_durante_parada'] ?: null;
                }

                if ($mode === 'todo' || $mode === 'recepcion') {
                    $data['cantidad_recibida'] = $fields['cantidad_recibida'];
                    $data['recepcion_estado'] = $fields['recepcion_estado'];
                    $data['recepcion_fecha'] = $fields['recepcion_fecha'] ?: $fechaRecepcion;
                    $data['recepcion_observacion'] = $fields['recepcion_observacion'] ?: null;
                    $data['recepcion_registrada_at'] = now();
                    $data['recepcion_registrada_por_usuario_id'] = $usuario->id;
                }

                ParadaHerramientaItem::query()
                    ->where('id', $itemId)
                    ->update($data);
            }
        });

        return [
            'ok' => true,
            'message' => 'Pedido actualizado correctamente.',
        ];
    }

    public function importarFormatoGrupo(Usuario $usuario, RQMina $rq, string $grupoId, UploadedFile $file): array
    {
        $lista = $this->ensureLista($rq, $usuario)->load('grupos.items');

        if (!$this->canEditLista($usuario, $rq, $lista)) {
            return [
                'ok' => false,
                'message' => 'La lista ya no puede editarse porque vencio el plazo de envio o ya fue enviada.',
            ];
        }

        /** @var ParadaHerramientaGrupo|null $grupo */
        $grupo = $lista->grupos->firstWhere('id', $grupoId);
        if (!$grupo) {
            return [
                'ok' => false,
                'message' => 'Grupo no encontrado para actualizar herramientas y consumibles.',
            ];
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'No se pudo leer el Excel: ' . $exception->getMessage(),
            ];
        }

        $parsed = [
            self::CATEGORY_TOOL => [],
            self::CATEGORY_CONSUMABLE => [],
        ];

        foreach ($spreadsheet->getWorksheetIterator() as $index => $sheet) {
            $category = $this->categoryForSheet($sheet->getTitle(), $index);
            if (!$category) {
                continue;
            }

            $parsed[$category] = array_merge($parsed[$category], $this->parseFormatoSheet($sheet, $category));
        }

        $totalRows = count($parsed[self::CATEGORY_TOOL]) + count($parsed[self::CATEGORY_CONSUMABLE]);
        if ($totalRows === 0) {
            return [
                'ok' => false,
                'message' => 'No se encontraron herramientas ni consumibles con descripcion en el formato.',
            ];
        }

        $summary = DB::transaction(function () use ($grupo, $parsed, $usuario): array {
            $this->rememberCatalogRows(array_merge($parsed[self::CATEGORY_TOOL], $parsed[self::CATEGORY_CONSUMABLE]), $usuario);

            return [
                self::CATEGORY_TOOL => $this->syncImportedRows($grupo, self::CATEGORY_TOOL, $parsed[self::CATEGORY_TOOL]),
                self::CATEGORY_CONSUMABLE => $this->syncImportedRows($grupo, self::CATEGORY_CONSUMABLE, $parsed[self::CATEGORY_CONSUMABLE]),
            ];
        });

        return [
            'ok' => true,
            'message' => sprintf(
                'Formato actualizado para %s. Herramientas: %d creadas, %d actualizadas, %d retiradas. Consumibles: %d creados, %d actualizados, %d retirados.',
                $grupo->nombre,
                $summary[self::CATEGORY_TOOL]['creados'],
                $summary[self::CATEGORY_TOOL]['actualizados'],
                $summary[self::CATEGORY_TOOL]['retirados'],
                $summary[self::CATEGORY_CONSUMABLE]['creados'],
                $summary[self::CATEGORY_CONSUMABLE]['actualizados'],
                $summary[self::CATEGORY_CONSUMABLE]['retirados'],
            ),
            'summary' => $summary,
        ];
    }

    public function importarCatalogo(Usuario $usuario, UploadedFile $file): array
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'No se pudo leer el Excel: ' . $exception->getMessage(),
            ];
        }

        $rows = [];
        foreach ($spreadsheet->getWorksheetIterator() as $index => $sheet) {
            $category = match ((int) $index) {
                0 => self::CATEGORY_TOOL,
                1 => self::CATEGORY_CONSUMABLE,
                default => null,
            };

            if (!$category) {
                continue;
            }

            $rows = array_merge($rows, $this->parseCatalogSheet($sheet, $category));
        }

        if (empty($rows)) {
            return [
                'ok' => false,
                'message' => 'No se encontraron descripciones para cargar al catalogo.',
            ];
        }

        $summary = [
            'creados' => 0,
            'actualizados' => 0,
            'observaciones' => 0,
            'herramientas' => 0,
            'consumibles' => 0,
        ];

        DB::transaction(function () use ($rows, $usuario, &$summary): void {
            $seen = [];

            foreach ($rows as $row) {
                $key = $this->itemKey($row['descripcion'], $row['unidad']);
                $key = $row['categoria'] . '|' . $key;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $catalog = $this->rememberCatalogEntry(
                    $row['descripcion'],
                    $row['categoria'],
                    $row['unidad'] ?? null,
                    $usuario
                );

                if (!$catalog) {
                    continue;
                }

                if ($catalog->wasRecentlyCreated) {
                    $summary['creados']++;
                } else {
                    $summary['actualizados']++;
                }

                if ($row['categoria'] === self::CATEGORY_CONSUMABLE) {
                    $summary['consumibles']++;
                } else {
                    $summary['herramientas']++;
                }

                if (!empty($row['observaciones']) && $this->rememberCatalogObservation($catalog, $row['observaciones'])) {
                    $summary['observaciones']++;
                }
            }
        });

        return [
            'ok' => true,
            'message' => sprintf(
                'Catalogo actualizado. Herramientas: %d. Consumibles: %d. Nuevos: %d. Actualizados: %d. Observaciones aprendidas: %d.',
                $summary['herramientas'],
                $summary['consumibles'],
                $summary['creados'],
                $summary['actualizados'],
                $summary['observaciones'],
            ),
            'summary' => $summary,
        ];
    }

    public function sugerirCatalogo(string $term = '', ?string $category = null, int $limit = 20): array
    {
        if (!Schema::hasTable('parada_herramienta_catalogos')) {
            return [];
        }

        $limit = min(max($limit, 5), 50);
        $normalized = $this->normalizeSearchText($term);
        $category = $this->normalizeCategory($category);

        $query = ParadaHerramientaCatalogo::query()
            ->where('activo', true);

        if ($category) {
            $query->where('categoria', $category);
        }

        if ($normalized !== '') {
            foreach (explode(' ', $normalized) as $word) {
                $query->where('descripcion_normalizada', 'like', '%' . $word . '%');
            }
        }

        if ($normalized !== '') {
            $query->orderByRaw('CASE WHEN descripcion_normalizada LIKE ? THEN 0 ELSE 1 END', [$normalized . '%']);
        } else {
            $query->orderByDesc('updated_at');
        }

        return $query
            ->orderBy('descripcion')
            ->limit($limit)
            ->get()
            ->map(fn (ParadaHerramientaCatalogo $catalog): array => [
                'id' => (string) $catalog->id,
                'categoria' => (string) $catalog->categoria,
                'descripcion' => (string) $catalog->descripcion,
                'unidad' => (string) ($catalog->unidad ?? ''),
            ])
            ->all();
    }

    public function sugerirObservaciones(string $description, ?string $category = null, int $limit = 10): array
    {
        if (!Schema::hasTable('parada_herramienta_catalogos') || !Schema::hasTable('parada_herramienta_catalogo_observaciones')) {
            return [];
        }

        $normalized = $this->normalizeSearchText($description);
        if ($normalized === '') {
            return [];
        }

        $category = $this->normalizeCategory($category);
        $limit = min(max($limit, 5), 30);

        $catalogQuery = ParadaHerramientaCatalogo::query()
            ->where('activo', true)
            ->where('descripcion_normalizada', $normalized);

        if ($category) {
            $catalogQuery->where('categoria', $category);
        }

        $catalogIds = $catalogQuery->pluck('id');
        if ($catalogIds->isEmpty()) {
            return [];
        }

        return ParadaHerramientaCatalogoObservacion::query()
            ->whereIn('catalogo_id', $catalogIds->all())
            ->orderByDesc('usos')
            ->orderByDesc('last_used_at')
            ->limit($limit)
            ->get()
            ->map(fn (ParadaHerramientaCatalogoObservacion $observation): array => [
                'observacion' => (string) $observation->observacion,
                'usos' => (int) $observation->usos,
            ])
            ->all();
    }

    private function categoryForSheet(string $sheetName, int $index): ?string
    {
        $normalized = $this->normalizeSearchText($sheetName);

        if (str_contains($normalized, 'CONSM') || str_contains($normalized, 'CONSUM')) {
            return self::CATEGORY_CONSUMABLE;
        }

        if (str_contains($normalized, 'HRRT') || str_contains($normalized, 'HERR') || str_contains($normalized, 'UTIL')) {
            return self::CATEGORY_TOOL;
        }

        return match ($index) {
            0 => self::CATEGORY_TOOL,
            1 => self::CATEGORY_CONSUMABLE,
            default => null,
        };
    }

    private function parseFormatoSheet(Worksheet $sheet, string $category): array
    {
        $headerRow = $this->findFormatoHeaderRow($sheet);
        if (!$headerRow) {
            return [];
        }

        $columns = $this->resolveFormatoColumns($sheet, $headerRow);
        if (empty($columns['descripcion'])) {
            return [];
        }

        $rows = [];
        $seen = [];
        $highestRow = min((int) $sheet->getHighestDataRow(), 1200);

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $descripcion = $this->cleanExcelText($this->cellValue($sheet, (int) $columns['descripcion'], $row));
            if ($descripcion === '') {
                continue;
            }

            $headerLike = $this->normalizeSearchText($descripcion);
            if (str_contains($headerLike, 'DESCRIPCION')) {
                continue;
            }

            $unidad = !empty($columns['unidad'])
                ? $this->cleanExcelText($this->cellValue($sheet, (int) $columns['unidad'], $row))
                : null;
            $observacion = !empty($columns['observacion'])
                ? $this->cleanExcelText($this->cellValue($sheet, (int) $columns['observacion'], $row))
                : null;
            $cantidad = !empty($columns['cantidad'])
                ? $this->parseQuantity($this->cellValue($sheet, (int) $columns['cantidad'], $row))
                : 0;

            $key = $this->itemKey($descripcion, $category === self::CATEGORY_CONSUMABLE ? $unidad : null);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'tipo' => self::TYPE_BASE,
                'categoria' => $category,
                'descripcion' => mb_substr($descripcion, 0, 300),
                'cantidad_solicitada' => $cantidad,
                'unidad' => $category === self::CATEGORY_CONSUMABLE && $unidad !== '' ? mb_substr((string) $unidad, 0, 40) : null,
                'observaciones' => $observacion !== '' ? $observacion : null,
            ];
        }

        return $rows;
    }

    private function findFormatoHeaderRow(Worksheet $sheet): ?int
    {
        $highestRow = min((int) $sheet->getHighestDataRow(), 80);
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);

        for ($row = 1; $row <= $highestRow; $row++) {
            $labels = [];

            for ($column = 1; $column <= $highestColumn; $column++) {
                $labels[] = $this->normalizeSearchText($this->cellValue($sheet, $column, $row));
            }

            $joined = implode(' ', array_filter($labels));
            if (str_contains($joined, 'DESCRIPCION') && str_contains($joined, 'CANT SOLICITADA')) {
                return $row;
            }
        }

        return null;
    }

    private function resolveFormatoColumns(Worksheet $sheet, int $headerRow): array
    {
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);
        $columns = [
            'descripcion' => null,
            'cantidad' => null,
            'unidad' => null,
            'observacion' => null,
        ];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $label = $this->normalizeSearchText($this->cellValue($sheet, $column, $headerRow));

            if ($label === '') {
                continue;
            }

            if (!$columns['descripcion'] && str_contains($label, 'DESCRIPCION')) {
                $columns['descripcion'] = $column;
                continue;
            }

            if (!$columns['cantidad'] && str_contains($label, 'CANT SOLICITADA')) {
                $columns['cantidad'] = $column;
                continue;
            }

            if (!$columns['unidad'] && $label === 'UNIDAD') {
                $columns['unidad'] = $column;
                continue;
            }

            if (!$columns['observacion'] && str_contains($label, 'OBSERVACION')) {
                $columns['observacion'] = $column;
            }
        }

        return $columns;
    }

    private function parseCatalogSheet(Worksheet $sheet, string $category): array
    {
        $headerRow = $this->findFormatoHeaderRow($sheet);
        if (!$headerRow) {
            $headerRow = $this->findCatalogHeaderRow($sheet);
            if (!$headerRow) {
                return [];
            }

            $columns = $this->resolveCatalogColumns($sheet, $headerRow, $category);
        } else {
            $columns = $this->resolveFormatoColumns($sheet, $headerRow);
        }

        if (empty($columns['descripcion'])) {
            return [];
        }

        $rows = [];
        $seen = [];
        $highestRow = min((int) $sheet->getHighestDataRow(), 2000);

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $description = $this->cleanExcelText($this->cellValue($sheet, (int) $columns['descripcion'], $row));
            if ($description === '') {
                continue;
            }

            $normalized = $this->normalizeSearchText($description);
            if ($normalized === '' || str_contains($normalized, 'DESCRIPCION')) {
                continue;
            }

            $unit = !empty($columns['unidad'])
                ? $this->cleanExcelText($this->cellValue($sheet, (int) $columns['unidad'], $row))
                : null;
            $observation = !empty($columns['observacion'])
                ? $this->cleanExcelText($this->cellValue($sheet, (int) $columns['observacion'], $row))
                : null;

            $key = $category . '|' . $this->itemKey($description, $category === self::CATEGORY_CONSUMABLE ? $unit : null);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'categoria' => $category,
                'descripcion' => mb_substr($description, 0, 300),
                'unidad' => $category === self::CATEGORY_CONSUMABLE && $unit !== '' ? mb_substr((string) $unit, 0, 40) : null,
                'observaciones' => $observation !== '' ? $observation : null,
            ];
        }

        return $rows;
    }

    private function findCatalogHeaderRow(Worksheet $sheet): ?int
    {
        $highestRow = min((int) $sheet->getHighestDataRow(), 90);
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);

        for ($row = 1; $row <= $highestRow; $row++) {
            $hasDescription = false;

            for ($column = 1; $column <= $highestColumn; $column++) {
                $label = $this->normalizeSearchText($this->cellValue($sheet, $column, $row));
                if (str_contains($label, 'DESCRIPCION')) {
                    $hasDescription = true;
                    break;
                }
            }

            if ($hasDescription) {
                return $row;
            }
        }

        return null;
    }

    private function resolveCatalogColumns(Worksheet $sheet, int $headerRow, string $category): array
    {
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);
        $columns = [
            'descripcion' => null,
            'unidad' => null,
            'observacion' => null,
        ];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $label = $this->normalizeSearchText($this->cellValue($sheet, $column, $headerRow));

            if ($label === '') {
                continue;
            }

            if (!$columns['descripcion'] && str_contains($label, 'DESCRIPCION')) {
                $columns['descripcion'] = $column;
                continue;
            }

            if (!$columns['unidad'] && ($label === 'UNIDAD' || $label === 'UND' || $label === 'U M')) {
                $columns['unidad'] = $column;
                continue;
            }

            if (!$columns['observacion'] && str_contains($label, 'OBSERVACION')) {
                $columns['observacion'] = $column;
            }
        }

        if (!$columns['unidad'] && $category === self::CATEGORY_CONSUMABLE && $columns['descripcion']) {
            $columns['unidad'] = $this->guessUnitColumn($sheet, $headerRow, (int) $columns['descripcion']);
        }

        return $columns;
    }

    private function guessUnitColumn(Worksheet $sheet, int $headerRow, int $descriptionColumn): ?int
    {
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);
        $highestRow = min((int) $sheet->getHighestDataRow(), $headerRow + 20);
        $bestColumn = null;
        $bestScore = 0;

        for ($column = 1; $column <= $highestColumn; $column++) {
            if ($column === $descriptionColumn) {
                continue;
            }

            $score = 0;
            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $value = $this->cleanExcelText($this->cellValue($sheet, $column, $row));
                if ($value !== '' && $this->isLikelyUnitValue($value)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestColumn = $column;
            }
        }

        return $bestScore >= 2 ? $bestColumn : null;
    }

    private function isLikelyUnitValue(string $value): bool
    {
        $normalized = $this->normalizeSearchText($value);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'UND',
            'UNID',
            'UNIDAD',
            'UNIDADES',
            'PQT',
            'PAQ',
            'PAQUETE',
            'CAJA',
            'CJ',
            'KG',
            'GR',
            'LT',
            'L',
            'M',
            'MT',
            'MTS',
            'ROLLO',
            'GLN',
        ], true);
    }

    private function rememberCatalogRows(array $rows, ?Usuario $usuario = null): void
    {
        if (!Schema::hasTable('parada_herramienta_catalogos')) {
            return;
        }

        foreach ($rows as $row) {
            $description = trim((string) ($row['descripcion'] ?? ''));
            if ($description === '') {
                continue;
            }

            $catalog = $this->rememberCatalogEntry(
                $description,
                (string) ($row['categoria'] ?? self::CATEGORY_TOOL),
                $row['unidad'] ?? null,
                $usuario
            );

            if ($catalog && !empty($row['observaciones'])) {
                $this->rememberCatalogObservation($catalog, (string) $row['observaciones']);
            }
        }
    }

    private function rememberCatalogEntry(
        string $description,
        string $category,
        ?string $unit = null,
        ?Usuario $usuario = null
    ): ?ParadaHerramientaCatalogo {
        $category = $this->normalizeCategory($category) ?? self::CATEGORY_TOOL;
        $description = $this->cleanExcelText($description);
        $unit = $this->cleanExcelText((string) ($unit ?? ''));
        $normalizedDescription = $this->normalizeSearchText($description);
        $normalizedUnit = $this->normalizeSearchText($unit);

        if ($normalizedDescription === '') {
            return null;
        }

        /** @var ParadaHerramientaCatalogo $catalog */
        $catalog = ParadaHerramientaCatalogo::query()->firstOrNew([
            'categoria' => $category,
            'descripcion_normalizada' => $normalizedDescription,
            'unidad_normalizada' => $normalizedUnit,
        ]);

        if (!$catalog->exists) {
            $catalog->id = (string) Str::uuid();
            $catalog->created_by_usuario_id = $usuario?->id;
        }

        $catalog->descripcion = mb_substr($description, 0, 300);
        $catalog->unidad = $unit !== '' ? mb_substr($unit, 0, 40) : null;
        $catalog->activo = true;
        $catalog->updated_by_usuario_id = $usuario?->id;
        $catalog->save();

        return $catalog;
    }

    private function rememberCatalogObservation(ParadaHerramientaCatalogo $catalog, string $observation): bool
    {
        if (!Schema::hasTable('parada_herramienta_catalogo_observaciones')) {
            return false;
        }

        $observation = $this->cleanExcelText($observation);
        $normalized = $this->normalizeSearchText($observation);
        if ($normalized === '') {
            return false;
        }

        $hash = sha1($normalized);

        /** @var ParadaHerramientaCatalogoObservacion $record */
        $record = ParadaHerramientaCatalogoObservacion::query()->firstOrNew([
            'catalogo_id' => $catalog->id,
            'observacion_hash' => $hash,
        ]);

        if (!$record->exists) {
            $record->id = (string) Str::uuid();
            $record->usos = 0;
        }

        $record->observacion = mb_substr($observation, 0, 1000);
        $record->observacion_normalizada = mb_substr($normalized, 0, 500);
        $record->usos = ((int) $record->usos) + 1;
        $record->last_used_at = now();
        $record->save();

        return true;
    }

    private function normalizeCategory(?string $category): ?string
    {
        $normalized = $this->normalizeSearchText((string) $category);

        if (in_array($normalized, ['CONSUMIBLE', 'CONSUMIBLES', 'CONSUMO'], true)) {
            return self::CATEGORY_CONSUMABLE;
        }

        if (in_array($normalized, ['HERRAMIENTA', 'HERRAMIENTAS', 'HERR', 'UTILAJE', 'EQUIPO', 'EQUIPOS'], true)) {
            return self::CATEGORY_TOOL;
        }

        return null;
    }

    private function syncImportedRows(ParadaHerramientaGrupo $grupo, string $category, array $rows): array
    {
        if (empty($rows)) {
            return [
                'creados' => 0,
                'actualizados' => 0,
                'retirados' => 0,
            ];
        }

        $existing = $grupo->items()
            ->where('tipo', self::TYPE_BASE)
            ->where('categoria', $category)
            ->get()
            ->keyBy(fn (ParadaHerramientaItem $item): string => $this->itemKey($item->descripcion, $item->unidad));

        $keptIds = [];
        $created = 0;
        $updated = 0;

        foreach (array_values($rows) as $index => $row) {
            $key = $this->itemKey($row['descripcion'], $row['unidad']);
            /** @var ParadaHerramientaItem|null $item */
            $item = $existing->get($key);

            $data = [
                'tipo' => self::TYPE_BASE,
                'categoria' => $category,
                'descripcion' => $row['descripcion'],
                'cantidad_solicitada' => $row['cantidad_solicitada'],
                'unidad' => $row['unidad'],
                'observaciones' => $row['observaciones'],
                'orden' => $index + 1,
            ];

            if ($item) {
                $item->fill($data);
                $item->save();
                $keptIds[] = (string) $item->id;
                $updated++;
                continue;
            }

            $item = ParadaHerramientaItem::query()->create(array_merge($data, [
                'id' => (string) Str::uuid(),
                'grupo_id' => $grupo->id,
            ]));
            $keptIds[] = (string) $item->id;
            $created++;
        }

        $deleteQuery = $grupo->items()
            ->where('tipo', self::TYPE_BASE)
            ->where('categoria', $category);

        if (!empty($keptIds)) {
            $deleteQuery->whereNotIn('id', $keptIds);
        }

        $removed = $deleteQuery->count();
        $deleteQuery->delete();

        return [
            'creados' => $created,
            'actualizados' => $updated,
            'retirados' => $removed,
        ];
    }

    private function cellValue(Worksheet $sheet, int $column, int $row): mixed
    {
        return $sheet->getCell(Coordinate::stringFromColumnIndex($column) . $row)->getFormattedValue();
    }

    private function cleanExcelText(mixed $value): string
    {
        $text = trim((string) $value);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function parseQuantity(mixed $value): int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $raw) ?? '');
        if ($normalized === '' || $normalized === '-' || !is_numeric($normalized)) {
            return 0;
        }

        return max(0, (int) round((float) $normalized));
    }

    private function itemKey(?string $description, ?string $unit = null): string
    {
        return $this->normalizeSearchText((string) $description . '|' . (string) $unit);
    }

    private function normalizeSearchText(string $value): string
    {
        $value = Str::ascii($value);
        $value = strtoupper($value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function deadlineFor(RQMina $rq): Carbon
    {
        return Carbon::parse($rq->fecha_inicio)->subDays(7)->startOfDay();
    }

    private function canUpdatePedido(Usuario $usuario): bool
    {
        $permissions = PermissionMatrix::effectivePermissions($usuario);

        return PermissionMatrix::allowsDirect($permissions, 'herramientas', 'registrar')
            || PermissionMatrix::allowsDirect($permissions, 'herramientas', 'administrar');
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
        $personalId = (string) ($usuario->personal_id ?? '');

        $query->where(function ($scope) use ($minaIds, $personalId): void {
            $scope->whereIn('mina_id', $minaIds);

            if ($personalId !== '') {
                $scope->orWhere('supervisor_id', $personalId);
            }
        });
    }

    private function applySupervisorScope($query, Usuario $usuario): void
    {
        if ($this->isPrivileged($usuario)) {
            return;
        }

        $personalId = (string) ($usuario->personal_id ?? '');
        $query->where(function ($scope) use ($personalId): void {
            $scope->whereNull('supervisor_id');

            if ($personalId !== '') {
                $scope->orWhere('supervisor_id', $personalId);
            }
        });
    }

    private function canAccessRQMina(Usuario $usuario, RQMina $rq): bool
    {
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        $supervisorId = (string) ($rq->supervisor_id ?? '');
        if ($supervisorId !== '' && $supervisorId !== (string) ($usuario->personal_id ?? '')) {
            return false;
        }

        if ($supervisorId !== '' && $supervisorId === (string) ($usuario->personal_id ?? '')) {
            return true;
        }

        return $usuario->scopesMina()->where('mina_id', $rq->mina_id)->exists();
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCanDirect($usuario, 'herramientas', 'administrar');
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
                    || PermissionMatrix::userCanDirect($usuario, 'man_power', 'administrar');
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

    private function normalizeRecepcionEstado(mixed $value): string
    {
        $estado = strtoupper(trim((string) ($value ?? '')));

        return in_array($estado, ParadaHerramientaItem::recepcionEstados(), true)
            ? $estado
            : ParadaHerramientaItem::RECEPCION_PENDIENTE;
    }

    private function hasParadaStarted(RQMina $rq): bool
    {
        return now()->startOfDay()->gte(Carbon::parse($rq->fecha_inicio)->startOfDay());
    }

    private function hasParadaEnded(RQMina $rq): bool
    {
        if (!$rq->fecha_fin) {
            return false;
        }

        return now()->startOfDay()->gte(Carbon::parse($rq->fecha_fin)->startOfDay());
    }

    private function requiresLateChangeComment(RQMina $rq, ParadaHerramientaLista $lista): bool
    {
        return false;
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

            foreach (array_keys(self::FORM_BUCKETS) as $bucket) {
                foreach ((array) ($group[$bucket] ?? []) as $item) {
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
                        'cantidad_entregada' => max(0, (int) ($item['cantidad_entregada'] ?? 0)),
                        'cantidad_recibida' => max(0, (int) ($item['cantidad_recibida'] ?? 0)),
                        'incidencia_durante_parada' => trim((string) ($item['incidencia_durante_parada'] ?? '')),
                        'recepcion_estado' => $this->normalizeRecepcionEstado($item['recepcion_estado'] ?? ParadaHerramientaItem::RECEPCION_PENDIENTE),
                        'recepcion_fecha' => $this->sanitizeDate($item['recepcion_fecha'] ?? null),
                        'recepcion_observacion' => trim((string) ($item['recepcion_observacion'] ?? '')),
                    ];
                }
            }
        }

        return $updates;
    }
}
