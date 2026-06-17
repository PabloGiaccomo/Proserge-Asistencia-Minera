<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\RQMina;
use App\Models\RQMinaDetalleCambio;
use App\Models\RQMinaFieldOption;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Personal\Services\PersonalService;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class RQMinaPageController extends WebPageController
{
    public function __construct(
        private readonly RQMinaService $service,
        private readonly NotificationService $notificationService,
        private readonly PersonalService $personalService,
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
        $creatorOptions = $this->service->getCreatorOptionsForUser($usuario);
        $lugarOptions = $this->service->getLugarOptions($usuario);

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
        $items = $result['items']->map(fn (RQMina $rq): array => $this->toViewItem($rq))->values()->all();

        $data = [
            'items' => $items,
            'minaOptions' => $availableMinas->map(fn (Mina $mina): array => [
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
            ])->values()->all(),
            'lugarOptions' => $lugarOptions->all(),
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

        $item = $this->toViewItem($rqMina);
        $item['personal_parada'] = $this->getPersonalParadaForRQMina($id);
        $item['cambios_pedido'] = $this->getCambiosPedidoForRQMina($id);

        return view('rq-mina.show', compact('item'));
    }

    public function create(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $lugares = $this->service->getLugarOptions($usuario)->all();
        $copyFrom = (string) $request->query('copy_from', '');
        $copyData = null;

        if ($copyFrom !== '') {
            $rqMina = $this->service->findForUser($usuario, $copyFrom);
            if ($rqMina) {
                $copyData = $this->toViewItem($rqMina);
            }
        }

        $formMode = 'create';
        $formAction = route('rq-mina.store');
        $formMethod = 'POST';
        $submitLabel = 'Guardar Parada';

        return view('rq-mina.create', compact('lugares', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel'));
    }

    public function buscarPersonal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
            'tipo' => ['nullable', 'string', 'in:personal,supervisor'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        $items = $this->personalService
            ->searchSelector(
                $search,
                ($validated['tipo'] ?? 'personal') === 'supervisor',
                (int) ($validated['limit'] ?? 10)
            )
            ->map(fn ($personal): array => [
                'id' => (string) $personal->id,
                'nombre' => (string) $personal->nombre_completo,
                'dni' => (string) $personal->dni,
                'puesto' => (string) $personal->puesto,
                'es_supervisor' => (bool) $personal->es_supervisor,
                'minas' => $personal->relationLoaded('minas')
                    ? $personal->minas->pluck('nombre')->filter()->values()->all()
                    : [],
            ])
            ->values()
            ->all();

        return response()->json(['ok' => true, 'data' => $items]);
    }

    public function opcionesCampo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_.-]+$/i'],
            'q' => ['nullable', 'string', 'max:191'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $normalizedQuery = $this->normalizeFieldOptionValue($query);
        $limit = (int) ($validated['limit'] ?? 12);

        $options = RQMinaFieldOption::query()
            ->where('field_key', $validated['field'])
            ->when($normalizedQuery !== '', function ($optionQuery) use ($normalizedQuery): void {
                $optionQuery->where('value_normalized', 'like', '%' . $normalizedQuery . '%');
            })
            ->orderByDesc('usage_count')
            ->orderBy('value')
            ->limit($limit)
            ->get(['id', 'value', 'usage_count'])
            ->map(fn (RQMinaFieldOption $option): array => [
                'id' => (string) $option->id,
                'value' => (string) $option->value,
                'usage_count' => (int) $option->usage_count,
            ])
            ->values()
            ->all();

        return response()->json(['ok' => true, 'data' => $options]);
    }

    public function guardarOpcionCampo(Request $request): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $validated = $request->validate([
            'field' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_.-]+$/i'],
            'value' => ['required', 'string', 'max:1000'],
        ]);

        $value = preg_replace('/\s+/u', ' ', trim((string) $validated['value']));
        $normalized = $this->normalizeFieldOptionValue($value);

        if ($value === '' || $normalized === '') {
            return response()->json(['ok' => false, 'message' => 'Valor vacio.'], 422);
        }

        $option = RQMinaFieldOption::query()
            ->where('field_key', $validated['field'])
            ->where('value_normalized', $normalized)
            ->first();

        if (!$option) {
            $option = new RQMinaFieldOption([
                'id' => (string) Str::uuid(),
                'field_key' => $validated['field'],
                'value_normalized' => $normalized,
                'usage_count' => 0,
                'created_by_usuario_id' => $usuario->id,
            ]);
        }

        $option->value = mb_substr($value, 0, 1000);
        $option->usage_count = ((int) $option->usage_count) + 1;
        $option->save();

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => (string) $option->id,
                'value' => (string) $option->value,
                'usage_count' => (int) $option->usage_count,
            ],
        ]);
    }

    public function eliminarOpcionCampo(Request $request, string $optionId): JsonResponse
    {
        $deleted = RQMinaFieldOption::query()->where('id', $optionId)->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted > 0]);
    }

    public function edit(Request $request, string $id): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $lugares = $this->service->getLugarOptions($usuario)->all();
        $copyData = $this->toViewItem($rqMina);
        $formMode = 'edit';
        $formAction = route('rq-mina.update', $id);
        $formMethod = 'PUT';
        $submitLabel = 'Guardar Cambios';

        return view('rq-mina.create', compact('lugares', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel'));
    }

    public function plan(Request $request, string $id): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $item = $this->toViewItem($rqMina);

        return view('rq-mina.plan', compact('item'));
    }

    public function importarPlan(Request $request, string $id): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $item = $this->toViewItem($rqMina);

        return view('rq-mina.import-plan', compact('item'));
    }

    public function updatePlan(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $planOperativo = $this->normalizePlanOperativoFromRequest($request);
        $detalle = $this->normalizeDetalleFromRequest($request);
        $updated = $this->service->updatePlanOperativo($usuario, $rqMina, $planOperativo, $detalle);

        if (!$updated) {
            return back()->with('error', 'No tienes permiso para actualizar el plan operativo.')->withInput();
        }

        return redirect()
            ->route('rq-mina.show', $id)
            ->with('success', 'Pedido de personal y plan operativo semanal actualizados correctamente.')
            ->with('clear_rq_mina_plan_draft', $id);
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
                'destino_tipo' => $request->input('destino_tipo'),
                'destino_id' => $request->input('destino_id'),
                'area' => $request->input('area'),
                'fecha_inicio' => $request->input('fecha_inicio'),
                'fecha_fin' => $request->input('fecha_fin'),
                'observaciones' => $request->input('observaciones'),
                'detalle' => $request->input('detalle', []),
                'puesto' => $request->input('puesto', []),
                'cantidad' => $request->input('cantidad', []),
                'transporte' => $request->input('transporte', []),
                'supervisor_id' => $request->input('supervisor_id'),
                'plan_operativo_count' => count((array) $request->input('plan_operativo', [])),
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

            return back()->with('error', 'No tienes acceso al lugar seleccionado o el destino no es valido.')->withInput();
        }

        return redirect()
            ->route('rq-mina.plan', $rqMina->id)
            ->with('success', 'Parada registrada. Ahora agrega las areas y el plan operativo semanal.');
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
                'destino_tipo' => $request->input('destino_tipo'),
                'destino_id' => $request->input('destino_id'),
                'area' => $request->input('area'),
                'fecha_inicio' => $request->input('fecha_inicio'),
                'fecha_fin' => $request->input('fecha_fin'),
                'observaciones' => $request->input('observaciones'),
                'detalle' => $request->input('detalle', []),
                'puesto' => $request->input('puesto', []),
                'cantidad' => $request->input('cantidad', []),
                'transporte' => $request->input('transporte', []),
                'supervisor_id' => $request->input('supervisor_id'),
                'plan_operativo_count' => count((array) $request->input('plan_operativo', [])),
            ],
        ]);

        if (!$payload['valid']) {
            return back()->withErrors($payload['errors'])->withInput();
        }

        $updated = $this->service->updateGeneral($usuario, $rqMina, $payload['data']);

        if (!$updated) {
            return back()->with('error', 'No tienes permiso para actualizar este RQ.')->withInput();
        }

        return redirect()->route('rq-mina.show', $id)->with('success', 'Parada actualizada correctamente');
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

        $mineName = (string) ($sent->destino_nombre ?: ($sent->mina?->nombre ?? 'lugar no definido'));
        $areaName = (string) ($sent->area ?? 'sin área');
        $fechaInicio = $sent->fecha_inicio ? \Carbon\Carbon::parse($sent->fecha_inicio)->format('d/m/Y') : 'sin fecha';
        $fechaFin = $sent->fecha_fin ? \Carbon\Carbon::parse($sent->fecha_fin)->format('d/m/Y') : '';

        $context = [
            'actor_user_id' => (string) $usuario->id,
            'mine_id' => (string) $sent->mina_id,
            'entity_type' => 'rq_mina',
            'entity_id' => (string) $sent->id,
            'title' => 'RQ Mina enviado',
            'permission_module' => 'rq_proserge',
            'permission_action' => 'asignar',
            'require_permission' => true,
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

    private function getCambiosPedidoForRQMina(string $rqMinaId): array
    {
        return RQMinaDetalleCambio::query()
            ->where('rq_mina_id', $rqMinaId)
            ->where('estado', RQMinaDetalleCambio::ESTADO_PENDIENTE)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (RQMinaDetalleCambio $cambio): array => [
                'tipo' => $cambio->tipo,
                'puesto' => $cambio->puesto,
                'cantidad_anterior' => $cambio->cantidad_anterior,
                'cantidad_nueva' => $cambio->cantidad_nueva,
                'asignaciones_retiradas' => $cambio->asignaciones_retiradas,
                'mensaje' => $cambio->mensaje,
                'fecha' => $cambio->created_at?->format('Y-m-d H:i'),
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

    private function toViewItem(RQMina $rq): array
    {
        $destinoTipo = strtoupper((string) ($rq->destino_tipo ?: 'MINA'));
        $destinoId = (string) ($rq->destino_id ?: $rq->mina_id);
        $destinoNombre = (string) ($rq->destino_nombre ?: ($rq->mina?->nombre ?? '-'));

        return [
            'id' => $rq->id,
            'mina_id' => $rq->mina_id,
            'mina' => $rq->mina?->nombre ?? '-',
            'destino_tipo' => $destinoTipo,
            'destino_id' => $destinoId,
            'destino_nombre' => $destinoNombre,
            'supervisor_id' => $rq->supervisor_id,
            'supervisor' => $this->compactPersonal($rq->supervisor ?? null),
            'lugar' => $destinoNombre,
            'area' => $rq->area,
            'fecha_inicio' => $rq->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $rq->fecha_fin?->format('Y-m-d'),
            'estado' => $rq->estado,
            'creador_id' => $rq->creador_id,
            'creador' => $rq->creador?->personal?->nombre_completo ?? $rq->creador?->email ?? '-',
            'creado_at' => $rq->creado_at?->format('Y-m-d H:i:s'),
            'enviado_at' => $rq->enviado_at?->format('Y-m-d H:i:s'),
            'detalle' => $this->collectionToArrayRows($rq->detalle ?? []),
            'transporte' => $this->collectionToArrayRows($rq->transportes ?? []),
            'plan_operativo' => $this->planOperativoToArray($rq->actividadGrupos ?? []),
            'observaciones' => $rq->observaciones,
            'personal_parada' => $rq->personal_parada,
        ];
    }

    private function buildPayloadFromWebForm(Request $request, Usuario $usuario): array
    {
        $receivedMinaId = trim((string) $request->input('mina_id', ''));
        $receivedMinaName = trim((string) $request->input('mina', ''));
        [$destinoTipo, $destinoId] = $this->extractDestinoFromRequest($request);

        Log::info('rqmina.mine_value_received', [
            'usuario_id' => (string) $usuario->id,
            'mina_id' => $receivedMinaId,
            'mina_nombre' => $receivedMinaName,
        ]);

        $destination = $this->service->resolveDestination(
            usuario: $usuario,
            destinoTipo: $destinoTipo,
            destinoId: $destinoId,
            legacyMinaId: $receivedMinaId,
            legacyMinaName: $receivedMinaName,
        );
        $normalizedTransporte = $this->normalizeTransporteFromRequest($request);
        $supervisorId = trim((string) $request->input('supervisor_id', ''));
        $planOperativo = $this->normalizePlanOperativoFromRequest($request);

        $normalizedDetalle = $this->normalizeDetalleFromRequest($request);
        if (empty($normalizedDetalle) && !empty($planOperativo)) {
            $normalizedDetalle = $this->buildDetalleFromPlanOperativo($planOperativo);
        }
        $cantidadPuestos = count($normalizedDetalle);
        $cantidadTotal = array_sum(array_map(static fn (array $line): int => (int) $line['cantidad'], $normalizedDetalle));

        Log::info('rqmina.mine_id_resolved', [
            'usuario_id' => (string) $usuario->id,
            'mina_id_resuelto' => $destination['mina_id'] ?? null,
            'destino_resuelto' => $destination,
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
            'mina_id' => $destination['mina_id'] ?? null,
            'destino_tipo' => $destination['tipo'] ?? null,
            'destino_id' => $destination['id'] ?? null,
            'destino_nombre' => $destination['nombre'] ?? null,
            'area' => $request->input('area'),
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'observaciones' => $request->input('observaciones'),
            'supervisor_id' => $supervisorId !== '' ? $supervisorId : null,
            'detalle' => $normalizedDetalle,
            'transporte' => $normalizedTransporte,
            'plan_operativo' => $planOperativo,
        ];

        $errors = [];
        if (empty($rqData['destino_tipo']) || empty($rqData['destino_id'])) {
            $errors['destino_id'] = 'El lugar es requerido';
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
        if ($supervisorId !== '' && !Personal::query()->where('id', $supervisorId)->where('es_supervisor', true)->exists()) {
            $errors['supervisor_id'] = 'Selecciona un supervisor válido.';
        }

        return [
            'data' => $rqData,
            'valid' => empty($errors),
            'errors' => $errors,
        ];
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
            $key = $this->normalizeFieldOptionValue((string) $row['puesto']);
            if ($key === '') {
                continue;
            }

            if (!isset($unique[$key])) {
                $unique[$key] = [
                    'puesto' => $row['puesto'],
                    'cantidad' => 0,
                ];
            }

            $unique[$key]['cantidad'] += (int) $row['cantidad'];
        }

        return array_values($unique);
    }

    private function extractDestinoFromRequest(Request $request): array
    {
        $tipo = strtoupper(trim((string) $request->input('destino_tipo', '')));
        $id = trim((string) $request->input('destino_id', ''));

        if ($tipo === '' && str_contains($id, '|')) {
            [$rawTipo, $rawId] = array_pad(explode('|', $id, 2), 2, '');
            $tipo = strtoupper(trim($rawTipo));
            $id = trim($rawId);
        }

        return [$tipo, $id];
    }

    private function normalizeTransporteFromRequest(Request $request): array
    {
        $normalized = [];

        $rows = $request->input('transporte', []);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $transporte = trim((string) ($row['transporte'] ?? ''));
                $cantidad = (int) ($row['cantidad'] ?? 0);

                if ($transporte === '' && $cantidad <= 0) {
                    continue;
                }

                if ($transporte !== '' && $cantidad > 0) {
                    $normalized[] = [
                        'transporte' => $transporte,
                        'cantidad' => $cantidad,
                    ];
                }
            }
        }

        return array_values($normalized);
    }

    private function collectionToArrayRows(mixed $items): array
    {
        if ($items instanceof \Illuminate\Support\Collection) {
            return $items->map(fn ($item): array => $item instanceof \Illuminate\Database\Eloquent\Model ? $item->toArray() : (array) $item)
                ->values()
                ->all();
        }

        if (is_array($items)) {
            return array_map(static fn ($item): array => $item instanceof \Illuminate\Database\Eloquent\Model ? $item->toArray() : (array) $item, $items);
        }

        return [];
    }

    private function normalizeFieldOptionValue(string $value): string
    {
        $normalized = Str::ascii(Str::lower(preg_replace('/\s+/u', ' ', trim($value))));

        return mb_substr($normalized, 0, 191);
    }

    private function planOperativoToArray(mixed $groups): array
    {
        if (!$groups instanceof \Illuminate\Support\Collection) {
            return [];
        }

        return $groups
            ->map(fn ($group): array => [
                'id' => (string) ($group->id ?? ''),
                'area_operativa' => (string) ($group->area_operativa ?? ''),
                'modulo' => (string) ($group->modulo ?? ''),
                'nombre' => (string) ($group->nombre ?? ''),
                'observaciones' => (string) ($group->observaciones ?? ''),
                'actividades' => ($group->actividades ?? collect())->map(fn ($activity): array => [
                    'id' => (string) ($activity->id ?? ''),
                    'client_key' => (string) ($activity->id ?? ''),
                    'sait' => (string) ($activity->sait ?? ''),
                    'sector' => (string) ($activity->sector ?? ''),
                    'area' => (string) ($activity->area ?? ''),
                    'ait_trabajo' => (string) ($activity->ait_trabajo ?? ''),
                    'detalle_trabajos_relevantes' => (string) ($activity->detalle_trabajos_relevantes ?? ''),
                    'supervisor_campo_dia' => (string) ($activity->supervisor_campo_dia ?? ''),
                    'supervisor_campo_noche' => (string) ($activity->supervisor_campo_noche ?? ''),
                    'supervisor_seguridad_dia' => (string) ($activity->supervisor_seguridad_dia ?? ''),
                    'supervisor_seguridad_noche' => (string) ($activity->supervisor_seguridad_noche ?? ''),
                    'turnos' => ($activity->turnos ?? collect())->map(fn ($turno): array => [
                        'fecha' => $turno->fecha?->toDateString(),
                        'dia_label' => (string) ($turno->dia_label ?? ''),
                        'turno_a' => (string) ($turno->turno_a ?? ''),
                        'real_turno_a' => (string) ($turno->real_turno_a ?? ''),
                        'turno_b' => (string) ($turno->turno_b ?? ''),
                        'real_turno_b' => (string) ($turno->real_turno_b ?? $turno->real ?? ''),
                        'real' => (string) ($turno->real_turno_b ?? $turno->real ?? ''),
                    ])->values()->all(),
                ])->values()->all(),
                'transportes' => ($group->transportes ?? collect())->map(fn ($transporte): array => [
                    'id' => (string) ($transporte->id ?? ''),
                    'actividad_id' => (string) ($transporte->actividad_id ?? ''),
                    'actividad_key' => (string) ($transporte->actividad_id ?? ''),
                    'alcance' => (string) ($transporte->alcance ?? ''),
                    'unidad_carga' => (string) ($transporte->unidad_carga ?? ''),
                    'unidades_transporte' => (string) ($transporte->unidades_transporte ?? ''),
                    'indicaciones' => (string) ($transporte->indicaciones ?? ''),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function normalizePlanOperativoFromRequest(Request $request): array
    {
        $groups = $request->input('plan_operativo', []);
        if (!is_array($groups)) {
            return [];
        }

        $normalized = [];
        foreach ($groups as $groupIndex => $group) {
            if (!is_array($group)) {
                continue;
            }

            $areaOperativa = trim((string) ($group['area_operativa'] ?? ''));
            $modulo = trim((string) ($group['modulo'] ?? ''));
            $nombre = trim((string) ($group['nombre'] ?? ''));
            $observaciones = trim((string) ($group['observaciones'] ?? ''));
            $actividades = $this->normalizePlanActivities((array) ($group['actividades'] ?? []));
            $transportes = $this->normalizePlanTransportes((array) ($group['transportes'] ?? []));

            if ($areaOperativa === '' && $modulo === '' && $nombre === '' && empty($actividades) && empty($transportes)) {
                continue;
            }

            $normalized[] = [
                'area_operativa' => $areaOperativa,
                'modulo' => $modulo,
                'nombre' => $nombre !== '' ? $nombre : 'Grupo ' . ((int) $groupIndex + 1),
                'observaciones' => $observaciones,
                'actividades' => $actividades,
                'transportes' => $transportes,
            ];
        }

        return $normalized;
    }

    private function normalizePlanActivities(array $activities): array
    {
        $normalized = [];
        foreach ($activities as $activityIndex => $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $row = [
                'client_key' => trim((string) ($activity['client_key'] ?? $activityIndex)),
                'sait' => trim((string) ($activity['sait'] ?? '')),
                'sector' => trim((string) ($activity['sector'] ?? '')),
                'area' => trim((string) ($activity['area'] ?? '')),
                'ait_trabajo' => trim((string) ($activity['ait_trabajo'] ?? '')),
                'detalle_trabajos_relevantes' => trim((string) ($activity['detalle_trabajos_relevantes'] ?? '')),
                'supervisor_campo_dia' => trim((string) ($activity['supervisor_campo_dia'] ?? '')),
                'supervisor_campo_noche' => trim((string) ($activity['supervisor_campo_noche'] ?? '')),
                'supervisor_seguridad_dia' => trim((string) ($activity['supervisor_seguridad_dia'] ?? '')),
                'supervisor_seguridad_noche' => trim((string) ($activity['supervisor_seguridad_noche'] ?? '')),
                'turnos' => $this->normalizePlanTurnos((array) ($activity['turnos'] ?? [])),
            ];

            $hasContent = collect($row)
                ->except(['client_key', 'turnos'])
                ->filter(fn ($value): bool => trim((string) $value) !== '')
                ->isNotEmpty();

            if (!$hasContent && empty($row['turnos'])) {
                continue;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function normalizePlanTurnos(array $turnos): array
    {
        $normalized = [];
        foreach ($turnos as $turno) {
            if (!is_array($turno)) {
                continue;
            }

            $fecha = trim((string) ($turno['fecha'] ?? ''));
            $diaLabel = trim((string) ($turno['dia_label'] ?? ''));
            $turnoA = trim((string) ($turno['turno_a'] ?? ''));
            $realTurnoA = trim((string) ($turno['real_turno_a'] ?? ''));
            $turnoB = trim((string) ($turno['turno_b'] ?? ''));
            $realTurnoB = trim((string) ($turno['real_turno_b'] ?? $turno['real'] ?? ''));

            if ($fecha === '' && $diaLabel === '' && $turnoA === '' && $realTurnoA === '' && $turnoB === '' && $realTurnoB === '') {
                continue;
            }

            $normalized[] = [
                'fecha' => $fecha,
                'dia_label' => $diaLabel,
                'turno_a' => $turnoA,
                'real_turno_a' => $realTurnoA,
                'turno_b' => $turnoB,
                'real_turno_b' => $realTurnoB,
                'real' => $realTurnoB,
            ];
        }

        return $normalized;
    }

    private function normalizePlanTransportes(array $transportes): array
    {
        $normalized = [];
        foreach ($transportes as $transporte) {
            if (!is_array($transporte)) {
                continue;
            }

            $row = [
                'actividad_key' => trim((string) ($transporte['actividad_key'] ?? '')),
                'alcance' => trim((string) ($transporte['alcance'] ?? '')),
                'unidad_carga' => trim((string) ($transporte['unidad_carga'] ?? '')),
                'unidades_transporte' => trim((string) ($transporte['unidades_transporte'] ?? '')),
                'indicaciones' => trim((string) ($transporte['indicaciones'] ?? '')),
            ];

            if ($row['alcance'] === '' && $row['unidad_carga'] === '' && $row['unidades_transporte'] === '' && $row['indicaciones'] === '') {
                continue;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function buildDetalleFromPlanOperativo(array $planOperativo): array
    {
        return collect($planOperativo)
            ->map(function (array $group): ?array {
                $count = count($group['actividades'] ?? []);
                if ($count <= 0) {
                    return null;
                }

                $label = trim(implode(' / ', array_filter([
                    $group['area_operativa'] ?? '',
                    $group['modulo'] ?? '',
                    $group['nombre'] ?? '',
                ])));

                return [
                    'puesto' => $label !== '' ? 'Plan operativo - ' . $label : 'Plan operativo',
                    'cantidad' => $count,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function compactPersonal(mixed $personal): ?array
    {
        if (!$personal) {
            return null;
        }

        return [
            'id' => (string) ($personal->id ?? ''),
            'nombre' => (string) ($personal->nombre_completo ?? ''),
            'dni' => (string) ($personal->dni ?? ''),
            'puesto' => (string) ($personal->puesto ?? ''),
            'es_supervisor' => (bool) ($personal->es_supervisor ?? false),
        ];
    }

    private function hasValidEstado(string $estado): bool
    {
        return in_array(strtoupper($estado), ['BORRADOR', 'ENVIADO', 'CERRADO', 'CANCELADO'], true);
    }
}
