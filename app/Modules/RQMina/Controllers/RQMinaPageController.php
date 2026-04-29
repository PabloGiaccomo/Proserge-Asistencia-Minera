<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Models\RQMina;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class RQMinaPageController extends WebPageController
{
    public function __construct(
        private readonly RQMinaService $service,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function index(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();

        $filters = $request->only([
            'q',
            'mina_id',
            'estado',
            'created_by_usuario_id',
            'fecha_inicio_desde',
            'fecha_inicio_hasta',
            'fecha_fin_desde',
            'fecha_fin_hasta',
        ]);
        $availableMinas = $this->service->getAvailableMinas($usuario);
        $mineNameById = $availableMinas->pluck('nombre', 'id');
        $creatorOptions = $this->service->getCreatorOptionsForUser($usuario);

        $perPage = max(1, (int) ($request->input('per_page', 10)));
        $currentPage = max(1, (int) ($request->input('page', 1)));

        $serviceFilters = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'mina_id' => trim((string) ($filters['mina_id'] ?? '')),
            'estado' => $this->toEstadoDatabase((string) ($filters['estado'] ?? '')),
            'created_by_usuario_id' => trim((string) ($filters['created_by_usuario_id'] ?? '')),
            'fecha_inicio_desde' => $filters['fecha_inicio_desde'] ?? null,
            'fecha_inicio_hasta' => $filters['fecha_inicio_hasta'] ?? null,
            'fecha_fin_desde' => $filters['fecha_fin_desde'] ?? null,
            'fecha_fin_hasta' => $filters['fecha_fin_hasta'] ?? null,
        ];

        $result = $this->service->listForUser($usuario, array_filter($serviceFilters, fn ($value) => $value !== null && $value !== ''), $perPage, $currentPage);
        $items = $result['items']->map(fn (RQMina $rq): array => $this->toViewItem($rq, $mineNameById->get((string) $rq->mina_id, '-')))->values()->all();

        $data = [
            'items' => $items,
            'minaOptions' => $availableMinas->map(fn (Mina $mina): array => [
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
            ])->values()->all(),
            'estadoOptions' => ['borrador', 'enviado', 'cerrado', 'cancelado'],
            'creadores' => $creatorOptions->all(),
            'filters' => [
                'q' => trim((string) ($filters['q'] ?? '')),
                'mina_id' => trim((string) ($filters['mina_id'] ?? '')),
                'estado' => trim((string) ($filters['estado'] ?? '')),
                'created_by_usuario_id' => trim((string) ($filters['created_by_usuario_id'] ?? '')),
                'fecha_inicio_desde' => (string) ($filters['fecha_inicio_desde'] ?? ''),
                'fecha_inicio_hasta' => (string) ($filters['fecha_inicio_hasta'] ?? ''),
                'fecha_fin_desde' => (string) ($filters['fecha_fin_desde'] ?? ''),
                'fecha_fin_hasta' => (string) ($filters['fecha_fin_hasta'] ?? ''),
            ],
            'pagination' => [
                'current_page' => $result['current_page'],
                'total_pages' => $result['total_pages'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
            ],
        ];

        return view('rq-mina.index', compact('data'));
    }

    public function show(Request $request, string $id): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $mineName = (string) ($rqMina->mina?->nombre ?? '-');
        $item = $this->toViewItem($rqMina, $mineName);
        $item['personal_parada'] = $this->getPersonalParadaForRQMina($id);

        return view('rq-mina.show', compact('item'));
    }

    public function create(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $minas = $this->service->getAvailableMinas($usuario)->pluck('nombre')->values()->all();
        $copyFrom = (string) $request->query('copy_from', '');
        $copyData = null;

        if ($copyFrom !== '') {
            $rqMina = $this->service->findForUser($usuario, $copyFrom);
            if ($rqMina) {
                $copyData = $this->toViewItem($rqMina, (string) ($rqMina->mina?->nombre ?? '-'));
            }
        }

        $formMode = 'create';
        $formAction = route('rq-mina.store');
        $formMethod = 'POST';
        $submitLabel = 'Guardar como Borrador';

        return view('rq-mina.create', compact('minas', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel'));
    }

    public function edit(Request $request, string $id): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $minas = $this->service->getAvailableMinas($usuario)->pluck('nombre')->values()->all();
        $copyData = $this->toViewItem($rqMina, (string) ($rqMina->mina?->nombre ?? '-'));
        $formMode = 'edit';
        $formAction = route('rq-mina.update', $id);
        $formMethod = 'PUT';
        $submitLabel = 'Guardar Cambios';

        return view('rq-mina.create', compact('minas', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel'));
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $this->buildPayloadFromWebForm($request, $usuario);

        Log::info('rqmina.store_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'payload' => [
                'mina' => $request->input('mina'),
                'mina_id' => $request->input('mina_id'),
                'area' => $request->input('area'),
                'fecha_inicio' => $request->input('fecha_inicio'),
                'fecha_fin' => $request->input('fecha_fin'),
                'observaciones' => $request->input('observaciones'),
                'detalle' => $request->input('detalle', []),
                'puesto' => $request->input('puesto', []),
                'cantidad' => $request->input('cantidad', []),
            ],
        ]);

        if (!$payload['valid']) {
            Log::warning('rqmina.store_validation_failed', [
                'usuario_id' => (string) $usuario->id,
                'errors' => $payload['errors'] ?? [],
            ]);

            return back()->withErrors($payload['errors'])->withInput();
        }

        $rqMina = $this->service->create($usuario, $payload['data']);

        if (!$rqMina) {
            Log::warning('rqmina.store_create_failed', [
                'usuario_id' => (string) $usuario->id,
                'mina_id' => $payload['data']['mina_id'] ?? null,
            ]);

            return back()->with('error', 'No tienes acceso a la mina seleccionada.')->withInput();
        }

        return redirect()->route('rq-mina.index')->with('success', 'RQ creado correctamente');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $payload = $this->buildPayloadFromWebForm($request, $usuario);

        Log::info('rqmina.update_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'rq_mina_id' => $id,
            'payload' => [
                'mina' => $request->input('mina'),
                'mina_id' => $request->input('mina_id'),
                'area' => $request->input('area'),
                'fecha_inicio' => $request->input('fecha_inicio'),
                'fecha_fin' => $request->input('fecha_fin'),
                'observaciones' => $request->input('observaciones'),
                'detalle' => $request->input('detalle', []),
                'puesto' => $request->input('puesto', []),
                'cantidad' => $request->input('cantidad', []),
            ],
        ]);

        if (!$payload['valid']) {
            return back()->withErrors($payload['errors'])->withInput();
        }

        $updated = $this->service->update($usuario, $rqMina, $payload['data']);

        if (!$updated) {
            return back()->with('error', 'No tienes permiso para actualizar este RQ.')->withInput();
        }

        return redirect()->route('rq-mina.show', $id)->with('success', 'RQ actualizado correctamente');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $deleted = $this->service->delete($usuario, $rqMina);

        if (!$deleted) {
            return redirect()
                ->route('rq-mina.index')
                ->with('error', 'Solo se puede eliminar un RQ en estado borrador y con permisos de edición.');
        }

        return redirect()->route('rq-mina.index')->with('success', 'RQ eliminado correctamente.');
    }

    public function enviar(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $sent = $this->service->send($usuario, $rqMina);

        if (!$sent) {
            Log::warning('rqmina.send_failed', [
                'rq_id' => $rqMina->id,
                'actor_usuario_id' => (string) $usuario->id,
                'estado' => (string) $rqMina->estado,
            ]);

            return redirect()
                ->route('rq-mina.index')
                ->with('error', 'Solo se puede enviar un RQ en estado borrador y con permisos de edición.');
        }

        Log::info('rqmina.send_state_changed', [
            'rq_id' => (string) $sent->id,
            'mina_id' => (string) $sent->mina_id,
            'actor_usuario_id' => (string) $usuario->id,
            'estado' => (string) $sent->estado,
            'enviado_at' => optional($sent->enviado_at)->toIso8601String(),
        ]);

        $mineName = (string) ($sent->mina?->nombre ?? 'mina no definida');
        $areaName = (string) ($sent->area ?? 'sin área');
        $fechaInicio = $sent->fecha_inicio ? \Carbon\Carbon::parse($sent->fecha_inicio)->format('d/m/Y') : 'sin fecha';
        $fechaFin = $sent->fecha_fin ? \Carbon\Carbon::parse($sent->fecha_fin)->format('d/m/Y') : '';

        $context = [
            'actor_user_id' => (string) $usuario->id,
            'mine_id' => (string) $sent->mina_id,
            'entity_type' => 'rq_mina',
            'entity_id' => (string) $sent->id,
            'title' => 'RQ Mina enviado',
            'permission_module' => 'rq_mina',
            'permission_action' => 'ver',
            'require_permission' => false,
            'message' => sprintf(
                '%s | Área: %s | %s al %s. Requiere atención RRHH/Planner.',
                $mineName,
                $areaName,
                $fechaInicio,
                $fechaFin ?: $fechaInicio
            ),
            'dedupe_key' => 'rq_mina_enviado:' . $sent->id,
        ];

        Log::info('rqmina.send_notification_dispatch', [
            'rq_id' => (string) $sent->id,
            'mina_id' => (string) $sent->mina_id,
            'actor_usuario_id' => (string) $usuario->id,
            'notification_type' => 'rq_mina_enviado',
            'notification_context' => $context,
        ]);

        try {
            $notifEvent = $this->notificationService->emit('rq_mina_enviado', $context);

            if (!$notifEvent) {
                Log::warning('rqmina.send_notification_not_created', [
                    'rq_id' => (string) $sent->id,
                    'mina_id' => (string) $sent->mina_id,
                    'actor_usuario_id' => (string) $usuario->id,
                    'notification_type' => 'rq_mina_enviado',
                ]);
            } else {
                $notifEvent->loadMissing('recipients');
                $recipientIds = $notifEvent->recipients instanceof EloquentCollection
                    ? $notifEvent->recipients->pluck('usuario_id')->map(fn ($id) => (string) $id)->values()->all()
                    : [];

                Log::info('rqmina.send_notification_created', [
                    'rq_id' => (string) $sent->id,
                    'mina_id' => (string) $sent->mina_id,
                    'actor_usuario_id' => (string) $usuario->id,
                    'notification_type' => 'rq_mina_enviado',
                    'notification_event_id' => (string) $notifEvent->id,
                    'recipient_count' => count($recipientIds),
                    'recipient_user_ids' => $recipientIds,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('rqmina.send_notification_exception', [
                'rq_id' => (string) $sent->id,
                'mina_id' => (string) $sent->mina_id,
                'actor_usuario_id' => (string) $usuario->id,
                'notification_type' => 'rq_mina_enviado',
                'error_message' => $exception->getMessage(),
                'error_trace' => $exception->getTraceAsString(),
            ]);

            return redirect()
                ->route('rq-mina.index')
                ->with('error', 'El RQ fue enviado, pero ocurrió un error al generar la notificación. Revisa logs.');
        }

        return redirect()->route('rq-mina.index')->with('success', 'RQ enviado correctamente.');
    }

    private function getPersonalParadaForRQMina(string $rqMinaId): array
    {
        return RQProsergeDetalle::query()
            ->with(['personal:id,nombre_completo,puesto', 'rqProserge:id,rq_mina_id'])
            ->whereHas('rqProserge', fn ($query) => $query->where('rq_mina_id', $rqMinaId))
            ->get()
            ->map(fn (RQProsergeDetalle $detalle): array => [
                'nombre' => $detalle->personal?->nombre_completo ?? '-',
                'puesto' => $detalle->personal?->puesto ?? $detalle->puesto_asignado ?? '-',
                'cargo_parada' => $detalle->puesto_asignado ?? '-',
            ])
            ->values()
            ->all();
    }

    private function extractUniqueValues(array $items, string $field): array
    {
        $values = [];
        foreach ($items as $item) {
            if (!empty($item[$field])) {
                $values[] = $item[$field];
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private function toEstadoDatabase(string $estado): ?string
    {
        $estado = strtoupper(trim($estado));
        if ($estado === '') {
            return null;
        }

        $estadosValidos = ['BORRADOR', 'ENVIADO', 'CERRADO', 'CANCELADO'];
        return in_array($estado, $estadosValidos, true) ? $estado : null;
    }

    private function toViewItem(RQMina $rq, string $mineName): array
    {
        return [
            'id' => $rq->id,
            'mina_id' => $rq->mina_id,
            'mina' => $mineName,
            'area' => $rq->area,
            'fecha_inicio' => $rq->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $rq->fecha_fin?->format('Y-m-d'),
            'estado' => $rq->estado,
            'creador_id' => $rq->creador_id,
            'creador' => $rq->creador?->personal?->nombre_completo ?? $rq->creador?->email ?? '-',
            'creado_at' => $rq->creado_at?->format('Y-m-d H:i:s'),
            'enviado_at' => $rq->enviado_at?->format('Y-m-d H:i:s'),
            'detalle' => $rq->detalle instanceof \Illuminate\Database\Eloquent\Collection ? $rq->detalle->values()->all() : ($rq->detalle ?? []),
            'observaciones' => $rq->observaciones,
            'personal_parada' => $rq->personal_parada,
        ];
    }

    private function buildPayloadFromWebForm(Request $request, Usuario $usuario): array
    {
        $receivedMinaId = trim((string) $request->input('mina_id', ''));
        $receivedMinaName = trim((string) $request->input('mina', ''));

        Log::info('rqmina.mine_value_received', [
            'usuario_id' => (string) $usuario->id,
            'mina_id' => $receivedMinaId,
            'mina_nombre' => $receivedMinaName,
        ]);

        $resolvedMinaId = $this->resolveMinaId($receivedMinaId, $receivedMinaName, $usuario);

        $normalizedDetalle = $this->normalizeDetalleFromRequest($request);
        $cantidadPuestos = count($normalizedDetalle);
        $cantidadTotal = array_sum(array_map(static fn (array $line): int => (int) $line['cantidad'], $normalizedDetalle));

        Log::info('rqmina.mine_id_resolved', [
            'usuario_id' => (string) $usuario->id,
            'mina_id_resuelto' => $resolvedMinaId,
        ]);

        Log::info('rqmina.detail_received', [
            'usuario_id' => (string) $usuario->id,
            'detalle_recibido' => [
                'detalle' => $request->input('detalle', []),
                'puesto' => $request->input('puesto', []),
                'cantidad' => $request->input('cantidad', []),
            ],
            'detalle_normalizado' => $normalizedDetalle,
            'cantidad_puestos' => $cantidadPuestos,
            'cantidad_total' => $cantidadTotal,
        ]);

        $rqData = [
            'mina_id' => $resolvedMinaId,
            'area' => $request->input('area'),
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'observaciones' => $request->input('observaciones'),
            'detalle' => $normalizedDetalle,
        ];

        $errors = [];
        if (empty($rqData['mina_id'])) {
            $errors['mina'] = 'La mina es requerida';
        }
        if (empty($rqData['area'])) {
            $errors['area'] = 'El área es requerida';
        }
        if (empty($rqData['fecha_inicio'])) {
            $errors['fecha_inicio'] = 'La fecha de inicio es requerida';
        }
        if (empty($rqData['fecha_fin'])) {
            $errors['fecha_fin'] = 'La fecha fin es requerida';
        }
        if (count($rqData['detalle']) === 0) {
            $errors['detalle'] = 'Debes registrar al menos un puesto con cantidad válida';
        }

        return [
            'data' => $rqData,
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function resolveMinaId(string $receivedMinaId, string $receivedMinaName, Usuario $usuario): ?string
    {
        if ($receivedMinaId !== '' && Mina::query()->where('id', $receivedMinaId)->exists()) {
            return $receivedMinaId;
        }

        if ($receivedMinaName === '') {
            return null;
        }

        $mineFromScope = $this->service->getAvailableMinas($usuario)
            ->first(fn ($mina): bool => mb_strtolower(trim((string) $mina->nombre)) === mb_strtolower(trim($receivedMinaName)));

        if ($mineFromScope) {
            return (string) $mineFromScope->id;
        }

        $mine = Mina::query()
            ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($receivedMinaName))])
            ->first(['id']);

        return $mine ? (string) $mine->id : null;
    }

    private function normalizeDetalleFromRequest(Request $request): array
    {
        $normalized = [];

        $detalleRows = $request->input('detalle', []);
        if (is_array($detalleRows) && !empty($detalleRows)) {
            foreach ($detalleRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $puesto = trim((string) ($row['puesto'] ?? ''));
                $cantidad = (int) ($row['cantidad'] ?? 0);

                if ($puesto === '' && $cantidad <= 0) {
                    continue;
                }

                if ($puesto !== '' && $cantidad > 0) {
                    $normalized[] = [
                        'puesto' => $puesto,
                        'cantidad' => $cantidad,
                    ];
                }
            }
        }

        $puestos = $request->input('puesto', []);
        $cantidades = $request->input('cantidad', []);
        if (is_array($puestos) && is_array($cantidades)) {
            $max = max(count($puestos), count($cantidades));
            for ($index = 0; $index < $max; $index++) {
                $puesto = trim((string) ($puestos[$index] ?? ''));
                $cantidad = (int) ($cantidades[$index] ?? 0);

                if ($puesto === '' && $cantidad <= 0) {
                    continue;
                }

                if ($puesto !== '' && $cantidad > 0) {
                    $normalized[] = [
                        'puesto' => $puesto,
                        'cantidad' => $cantidad,
                    ];
                }
            }
        }

        $unique = [];
        foreach ($normalized as $row) {
            $unique[] = [
                'puesto' => $row['puesto'],
                'cantidad' => (int) $row['cantidad'],
            ];
        }

        return array_values($unique);
    }

    private function hasValidEstado(string $estado): bool
    {
        return in_array(strtoupper($estado), ['BORRADOR', 'ENVIADO', 'CERRADO', 'CANCELADO'], true);
    }
}
