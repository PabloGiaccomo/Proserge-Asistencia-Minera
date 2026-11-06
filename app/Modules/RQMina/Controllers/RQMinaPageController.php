<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\RQMina;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaDetalleCambio;
use App\Models\RQMinaFieldOption;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Personal\Services\PersonalService;
use App\Modules\RQMina\Services\RQMinaPlanService;
use App\Modules\RQMina\Services\RQMinaService;
use App\Modules\RQMina\Support\RQMinaAtencionRrhhException;
use App\Modules\RQMina\Support\RQMinaDeletionConflictException;
use App\Support\Rbac\PermissionMatrix;
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
        private readonly RQMinaPlanService $planService,
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

    public function show(Request $request, string $id): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $item = $this->toViewItem($rqMina);
        $item['personal_parada'] = $this->getPersonalParadaForRQMina($id);
        $item['cambios_pedido'] = $this->getCambiosPedidoForRQMina($id);
        $item['puede_actualizar'] = $this->service->canUpdate($usuario, $rqMina);
        $item['puede_registrar_rrhh'] = $this->service->puedeIniciarAtencionRrhh($usuario, $rqMina)
            && $rqMina->detalle->contains(fn ($row): bool => $this->service->esCargoDePersonal((string) $row->puesto) && (int) $row->cantidad > 0);

        return view('rq-mina.show', compact('item'));
    }

    public function create(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $lugares = $this->service->getLugarOptions($usuario)->all();
        $copyFrom = (string) $request->query('copy_from', '');
        $copyData = null;

        if ($copyFrom !== '') {
            abort_unless(
                PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'duplicar'),
                403,
                'No tienes permiso para realizar esta accion.'
            );

            $rqMina = $this->service->findForUser($usuario, $copyFrom);
            if ($rqMina) {
                $copyData = $this->toViewItem($rqMina);
            }
        } else {
            abort_unless(
                PermissionMatrix::allowsDirect(session('user.permissions', []), 'rq_mina', 'crear'),
                403,
                'No tienes permiso para realizar esta accion.'
            );
        }

        $formMode = 'create';
        $lista = $this->filtrosDeRegreso($request);
        $formAction = route('rq-mina.store', ['lista' => $lista]);
        $formMethod = 'POST';
        $submitLabel = 'Guardar Parada';

        return view('rq-mina.create', compact('lugares', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel', 'lista'));
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

    public function edit(Request $request, string $id): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        if (!$this->service->canUpdate($usuario, $rqMina)) {
            return redirect()->route('rq-mina.show', ['id' => $id, 'lista' => $this->filtrosDeRegreso($request)])
                ->with('error', 'Puedes consultar esta parada. La actualización corresponde a una persona autorizada.');
        }

        $lugares = $this->service->getLugarOptions($usuario)->all();
        $copyData = $this->toViewItem($rqMina);
        $formMode = 'edit';
        $lista = $this->filtrosDeRegreso($request);
        $formAction = route('rq-mina.update', ['id' => $id, 'lista' => $lista]);
        $formMethod = 'PUT';
        $submitLabel = 'Guardar Cambios';

        return view('rq-mina.create', compact('lugares', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel', 'lista'));
    }

    public function plan(Request $request, string $id): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        try {
            $selectedPlan = $this->planService->resolveSelectedPlan($rqMina, $request->query('plan_id'), $usuario);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('rq-mina.plan', $id)
                ->with('error', $exception->getMessage());
        }

        $item = $this->toViewItem($rqMina);
        $item['plan_inicial_id'] = $this->defaultPlanId($rqMina);
        $item['selected_plan'] = $this->planService->planToArray($selectedPlan);
        $item['selected_plan_id'] = (string) $selectedPlan->id;
        $item['semana_plan'] = $this->planService->buildSemanaReferencia($selectedPlan->fecha_inicio, $selectedPlan->fecha_fin);
        $item['plan_operativo'] = $this->planOperativoToArray($this->groupsForSelectedPlan($rqMina, $selectedPlan));
        $item['puede_iniciar_rrhh'] = $this->service->puedeIniciarAtencionRrhh($usuario, $rqMina);
        $item['puede_actualizar'] = $this->service->canUpdate($usuario, $rqMina);
        $item['plan_revision'] = $this->service->revisionPlan($rqMina, (string) $selectedPlan->id);
        if ($rqMina->estado === 'BORRADOR') {
            $item['detalle'] = array_values(array_filter($item['detalle'], fn (array $row): bool =>
                $this->service->esCargoDePersonal((string) ($row['puesto'] ?? ''))
            ));
        }

        return view('rq-mina.plan', compact('item'));
    }

    public function importarPlan(Request $request, string $id): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        try {
            $selectedPlan = $this->planService->resolveSelectedPlan($rqMina, $request->query('plan_id'), $usuario);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('rq-mina.plan', $id)
                ->with('error', $exception->getMessage());
        }

        $item = $this->toViewItem($rqMina);
        $item['selected_plan'] = $this->planService->planToArray($selectedPlan);
        $item['selected_plan_id'] = (string) $selectedPlan->id;

        return view('rq-mina.import-plan', compact('item'));
    }

    public function updatePlan(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $programaCompacto = $request->input('plan_editor_complete') === 'json';
        if ($request->boolean('plan_editor_submission') && !$programaCompacto && !$request->boolean('plan_editor_complete')) {
            return back()->with('error', 'El sistema recibió solo una parte del plan. No se guardaron cambios.');
        }

        if ($programaCompacto || $request->has('plan_operativo_json')) {
            $programa = $request->input('plan_operativo_json');
            $grupos = is_string($programa) && str_starts_with(ltrim($programa), '[')
                ? json_decode($programa, true)
                : null;
            $estructura = Validator::make(['grupos' => $grupos], [
                'grupos' => ['present', 'array', 'list'],
                'grupos.*' => ['array'],
                'grupos.*.actividades' => ['present', 'array', 'list'],
                'grupos.*.actividades.*' => ['array'],
                'grupos.*.actividades.*.turnos' => ['present', 'array', 'list'],
                'grupos.*.actividades.*.turnos.*' => ['array'],
                'grupos.*.transportes' => ['present', 'array', 'list'],
                'grupos.*.transportes.*' => ['array'],
            ]);
            if ($estructura->fails()) {
                return back()->with('error', 'No se pudo leer el programa del plan. Vuelve a guardar.');
            }

            $request->merge(['plan_operativo' => $grupos, 'plan_editor_complete' => '1']);
            $request->offsetUnset('plan_operativo_json');
        }

        if ($request->boolean('plan_editor_submission')) {
            $error = $this->validarFilasDePersonal($request);
            if ($error !== null) {
                return back()->with('error', $error)->withInput();
            }
        }

        $planOperativo = $this->normalizePlanOperativoFromRequest($request);
        $detalle = $this->normalizeDetalleFromRequest($request);
        if ($rqMina->estado === 'BORRADOR') {
            $detalle = array_values(array_filter($detalle, fn (array $row): bool =>
                $this->service->esCargoDePersonal($row['puesto'])
            ));
        }
        $revision = $request->string('plan_revision')->trim()->toString();
        try {
            $updated = $this->service->updatePlanOperativo(
                $usuario,
                $rqMina,
                $planOperativo,
                $detalle,
                $request->input('plan_id'),
                iniciarAtencionRrhh: true,
                revisionEsperada: $revision !== '' ? $revision : null,
            );
        } catch (\InvalidArgumentException|RQMinaAtencionRrhhException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        if (!$updated) {
            return back()->with('error', 'No tienes permiso para actualizar el plan operativo.')->withInput();
        }

        $message = 'Pedido de personal y plan operativo guardados en el sistema.';
        if ($updated->estado === 'ENVIADO' && $updated->rqProserge()->exists()) {
            $message = 'Plan guardado. El pedido de personal está registrado en RQ Proserge.';
            if ($updated->wasChanged('estado')) {
                $this->notifyRrhh($usuario, $updated);
            }
        } elseif ($detalle !== []) {
            $message = 'Plan y pedido guardados. El pedido queda en preparación hasta que lo guarde una persona autorizada para iniciar la atención de RR. HH.';
        }

        $selectedPlanId = trim((string) $request->input('plan_id', ''));

        return redirect()
            ->route('rq-mina.plan', ['id' => $id, 'plan_id' => $selectedPlanId ?: null, 'lista' => $this->filtrosDeRegreso($request)])
            ->with('success', $message)
            ->with('rq_mina_saved_draft_id', Str::limit((string) $request->string('draft_id'), 80, ''));
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $error = $this->validarFilasDePersonal($request);
        if ($error !== null) {
            return back()->with('error', $error)->withInput();
        }
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
                'supervisor_pets_id' => $request->input('supervisor_pets_id'),
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

        try {
            $rqMina = $this->service->create($usuario, $payload['data'], iniciarAtencionRrhh: true);
        } catch (\InvalidArgumentException|RQMinaAtencionRrhhException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        if (!$rqMina) {
            Log::warning('rqmina.store_create_failed', [
                'usuario_id' => (string) $usuario->id,
                'mina_id' => $payload['data']['mina_id'] ?? null,
            ]);

            return back()->with('error', 'No tienes acceso al lugar seleccionado o el destino no es valido.')->withInput();
        }

        $message = 'Parada registrada. Ahora agrega las áreas y el plan operativo.';
        if ($rqMina->estado === 'ENVIADO') {
            $this->notifyRrhh($usuario, $rqMina);
            $message = 'Parada guardada. RR. HH. ya puede atender el pedido de personal.';
        }

        $permissions = session('user.permissions', []);
        $lista = $this->filtrosDeRegreso($request);
        if (!PermissionMatrix::allowsDirect($permissions, 'rq_mina', 'ver')) {
            return redirect()->route('rq-mina.create', ['lista' => $lista])->with('success', $message);
        }
        $route = PermissionMatrix::allowsDirect($permissions, 'rq_mina', 'editar') ? 'rq-mina.plan' : 'rq-mina.show';

        return redirect()->route($route, ['id' => $rqMina->id, 'lista' => $lista])->with('success', $message);
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
                'supervisor_pets_id' => $request->input('supervisor_pets_id'),
                'plan_operativo_count' => count((array) $request->input('plan_operativo', [])),
            ],
        ]);

        if (!$payload['valid']) {
            return back()->withErrors($payload['errors'])->withInput();
        }

        try {
            $updated = $this->service->updateGeneral($usuario, $rqMina, $payload['data']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['fecha_fin' => $exception->getMessage()])->withInput();
        }

        if (!$updated) {
            return back()->with('error', 'No tienes permiso para actualizar este RQ.')->withInput();
        }

        return redirect()->route('rq-mina.show', ['id' => $id, 'lista' => $this->filtrosDeRegreso($request)])
            ->with('success', 'Cambios de la parada guardados en el sistema.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        try {
            $deleted = $this->service->delete($usuario, $rqMina);
        } catch (RQMinaDeletionConflictException $exception) {
            return redirect()->route('rq-mina.index')->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('rqmina.delete_failed', [
                'rq_mina_id' => (string) $rqMina->id,
                'actor_usuario_id' => (string) $usuario->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('rq-mina.index')
                ->with('error', 'No se pudo eliminar el RQ Mina porque existen registros relacionados que no pudieron limpiarse.');
        }

        if (!$deleted) {
            return redirect()
                ->route('rq-mina.index')
                ->with('error', 'No tienes permiso para eliminar este RQ Mina.');
        }

        return redirect()
            ->route('rq-mina.index')
            ->with('success', 'RQ Mina eliminado junto con sus registros operativos relacionados.');
    }

    public function enviar(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rqMina = $this->service->findForUser($usuario, $id);

        if (!$rqMina) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        try {
            $sent = $this->service->send($usuario, $rqMina);
        } catch (RQMinaAtencionRrhhException $exception) {
            return redirect()->route('rq-mina.index')->with('error', $exception->getMessage());
        }

        if (!$sent) {
            Log::warning('rqmina.send_failed', [
                'rq_id' => $rqMina->id,
                'actor_usuario_id' => (string) $usuario->id,
                'estado' => (string) $rqMina->estado,
            ]);

            return redirect()
                ->route('rq-mina.index')
                ->with('error', 'Para registrar el pedido en RR. HH. necesitas un borrador con cargos válidos y permiso de envío.');
        }

        Log::info('rqmina.send_state_changed', [
            'rq_id' => (string) $sent->id,
            'mina_id' => (string) $sent->mina_id,
            'actor_usuario_id' => (string) $usuario->id,
            'estado' => (string) $sent->estado,
            'enviado_at' => optional($sent->enviado_at)->toIso8601String(),
        ]);

        $this->notifyRrhh($usuario, $sent);

        return redirect()->route('rq-mina.show', ['id' => $id, 'lista' => $this->filtrosDeRegreso($request)])
            ->with('success', 'Pedido registrado en RR. HH.');
    }

    private function notifyRrhh(Usuario $usuario, RQMina $sent): void
    {
        $mineName = (string) ($sent->destino_nombre ?: ($sent->mina?->nombre ?? 'lugar no definido'));
        $areaName = (string) ($sent->area ?? 'sin área');
        $fechaInicio = $sent->fecha_inicio ? \Carbon\Carbon::parse($sent->fecha_inicio)->format('d/m/Y') : 'sin fecha';
        $fechaFin = $sent->fecha_fin ? \Carbon\Carbon::parse($sent->fecha_fin)->format('d/m/Y') : '';

        $context = [
            'actor_user_id' => (string) $usuario->id,
            'mine_id' => (string) $sent->mina_id,
            'entity_type' => 'rq_mina',
            'entity_id' => (string) $sent->id,
            'title' => 'Nuevo pedido de personal',
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

        }
    }

    private function filtrosDeRegreso(Request $request): array
    {
        return array_filter(\Illuminate\Support\Arr::only((array) $request->query('lista', []), [
            'q', 'mina_id', 'estado', 'created_by_usuario_id', 'fecha_inicio_desde', 'fecha_inicio_hasta',
            'fecha_fin_desde', 'fecha_fin_hasta', 'page', 'per_page',
        ]), static fn (mixed $value): bool => is_scalar($value) && $value !== '');
    }

    private function validarFilasDePersonal(Request $request): ?string
    {
        $rows = $request->input('detalle', []);
        if (!is_array($rows)) {
            return 'Revisa los cargos del pedido de personal.';
        }

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                return 'Revisa los cargos del pedido de personal.';
            }

            $puesto = $row['puesto'] ?? '';
            $cantidad = $row['cantidad'] ?? '';
            if (is_string($puesto) && trim($puesto) === '' && $cantidad === '') {
                continue;
            }

            if (!is_string($puesto) || trim($puesto) === '' || filter_var($cantidad, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return 'Completa el cargo y una cantidad mayor a cero en la fila '.($index + 1).' del pedido de personal.';
            }
        }

        return null;
    }

    private function getPersonalParadaForRQMina(string $rqMinaId): array
    {
        return RQProsergeDetalle::query()
            ->with(['personal:id,nombre_completo,puesto', 'rqProserge:id,rq_mina_id'])
            ->whereHas('rqProserge', fn ($query) => $query->where('rq_mina_id', $rqMinaId))
            ->whereIn('estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
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
            'supervisor_pets_id' => $rq->supervisor_pets_id,
            'supervisor_pets' => $this->compactPersonal($rq->supervisorPets ?? null),
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
            'plan_inicial_id' => $this->defaultPlanId($rq),
            'plans' => $this->planesToArray($rq),
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
        $supervisorPetsId = trim((string) $request->input('supervisor_pets_id', ''));
        $planOperativo = $this->normalizePlanOperativoFromRequest($request);

        $normalizedDetalle = $this->normalizeDetalleFromRequest($request);
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
            'supervisor_pets_id' => $supervisorPetsId !== '' ? $supervisorPetsId : null,
            'plan_id' => trim((string) $request->input('plan_id', '')) ?: null,
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
        $dateErrors = Validator::make($rqData, [
            'fecha_inicio' => ['bail', 'required', 'date_format:Y-m-d'],
            'fecha_fin' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
        ], [
            'fecha_inicio.required' => 'Ingresa la fecha de inicio.',
            'fecha_inicio.date_format' => 'Ingresa una fecha de inicio válida.',
            'fecha_fin.required' => 'Ingresa la fecha de fin.',
            'fecha_fin.date_format' => 'Ingresa una fecha de fin válida.',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
        ])->errors();
        foreach ($dateErrors->keys() as $field) {
            $errors[$field] = $dateErrors->first($field);
        }
        if ($supervisorId !== '' && !Personal::query()->where('id', $supervisorId)->where('es_supervisor', true)->exists()) {
            $errors['supervisor_id'] = 'Selecciona un supervisor válido.';
        }

        if ($supervisorPetsId !== '' && !Personal::query()->where('id', $supervisorPetsId)->where('es_supervisor', true)->exists()) {
            $errors['supervisor_pets_id'] = 'Selecciona un supervisor PETS valido.';
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
                'rq_mina_plan_id' => (string) ($group->rq_mina_plan_id ?? ''),
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
                    'fecha' => $transporte->fecha?->toDateString() ?? '',
                    'turno' => (string) ($transporte->turno ?? ''),
                    'tipo_transporte' => (string) ($transporte->tipo_transporte ?? ''),
                    'capacidad_requerida' => (string) ($transporte->capacidad_requerida ?? ''),
                    'cantidad_unidades_requeridas' => (string) ($transporte->cantidad_unidades_requeridas ?? ''),
                    'origen' => (string) ($transporte->origen ?? ''),
                    'unidades_transporte' => (string) ($transporte->unidades_transporte ?? ''),
                    'placas_asignadas' => (string) ($transporte->placas_asignadas ?? ''),
                    'capacidad_camion' => (string) ($transporte->capacidad_camion ?? ''),
                    'fecha_inicio' => $transporte->fecha_inicio?->toDateString() ?? '',
                    'fecha_fin' => $transporte->fecha_fin?->toDateString() ?? '',
                    'dias_uso' => (string) ($transporte->dias_uso ?? ''),
                    'estado_logistico' => (string) ($transporte->estado_logistico ?? ''),
                    'indicaciones' => (string) ($transporte->indicaciones ?? ''),
                    'comentario_cambio' => (string) ($transporte->comentario_cambio ?? ''),
                    'incidencia_operativa' => (string) ($transporte->incidencia_operativa ?? ''),
                    'recepcion_fecha' => $transporte->recepcion_fecha?->toDateString() ?? '',
                    'recepcion_estado' => (string) ($transporte->recepcion_estado ?? ''),
                    'recepcion_observacion' => (string) ($transporte->recepcion_observacion ?? ''),
                    // Solo etiquetas de lectura: la vista las muestra para que RQ Mina
                    // sepa que Logistica ya trabaja este requerimiento y no lo borre
                    // sin advertirlo. No se reenvian al guardar.
                    'atencion' => $transporte->estadoAtencion(),
                    'atencion_label' => RQMinaActividadTransporte::estadoAtencionLabel($transporte->estadoAtencion()),
                    'atencion_tone' => RQMinaActividadTransporte::estadoAtencionTone($transporte->estadoAtencion()),
                    'estado_logistico_label' => $transporte->estado_logistico
                        ? RQMinaActividadTransporte::estadoLogisticoLabel((string) $transporte->estado_logistico)
                        : '',
                    'recepcion_estado_label' => $transporte->recepcion_estado
                        ? RQMinaActividadTransporte::recepcionEstadoLabel((string) $transporte->recepcion_estado)
                        : '',
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function planesToArray(RQMina $rq): array
    {
        if (!$rq->relationLoaded('planes')) {
            return [];
        }

        return $rq->planes
            ->map(fn ($plan): array => [
                'id' => (string) $plan->id,
                'codigo' => (string) $plan->codigo,
                'nombre' => (string) $plan->nombre,
                'version' => (int) $plan->version,
                'fecha_inicio' => $plan->fecha_inicio?->toDateString(),
                'fecha_fin' => $plan->fecha_fin?->toDateString(),
                'semana_referencia' => (string) ($plan->semana_referencia ?? ''),
                'estado' => (string) $plan->estado,
                'observaciones' => (string) ($plan->observaciones ?? ''),
            ])
            ->values()
            ->all();
    }

    private function defaultPlanId(RQMina $rq): ?string
    {
        if (!$rq->relationLoaded('planes')) {
            return null;
        }

        return $rq->planes
            ->first(fn ($plan): bool => $this->planService->isDefaultPlan($plan))
            ?->id;
    }

    private function groupsForSelectedPlan(RQMina $rq, \App\Models\RQMinaPlan $selectedPlan): EloquentCollection
    {
        $selectedPlan->loadMissing(['grupos.actividades.turnos', 'grupos.transportes']);
        $groups = $selectedPlan->grupos ?? new EloquentCollection();

        if (!$this->planService->isDefaultPlan($selectedPlan) || !$rq->relationLoaded('actividadGrupos')) {
            return new EloquentCollection($groups->values()->all());
        }

        $legacyGroups = $rq->actividadGrupos
            ->filter(fn ($group): bool => empty($group->rq_mina_plan_id));

        return new EloquentCollection(
            $groups
                ->concat($legacyGroups)
                ->unique('id')
                ->sortBy('orden')
                ->values()
                ->all()
        );
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
                // El id permite actualizar la fila en su lugar al guardar, en vez de
                // recrearla y perder historial de eventos y documentos de Logistica.
                'id' => trim((string) ($group['id'] ?? '')),
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
                'id' => trim((string) ($activity['id'] ?? '')),
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
                ->except(['id', 'client_key', 'turnos'])
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

            // Los campos de estructura (turno, tipo, cantidad y capacidad requerida)
            // alimentan a Logistica y a Transporte operativo; el resto de columnas
            // logisticas las administra Logistica y no se sobrescriben aqui.
            $row = [
                'id' => trim((string) ($transporte['id'] ?? '')),
                'actividad_key' => trim((string) ($transporte['actividad_key'] ?? '')),
                'alcance' => trim((string) ($transporte['alcance'] ?? '')),
                'unidad_carga' => trim((string) ($transporte['unidad_carga'] ?? '')),
                'fecha' => trim((string) ($transporte['fecha'] ?? '')),
                'turno' => trim((string) ($transporte['turno'] ?? '')),
                'tipo_transporte' => trim((string) ($transporte['tipo_transporte'] ?? '')),
                'capacidad_requerida' => trim((string) ($transporte['capacidad_requerida'] ?? '')),
                'cantidad_unidades_requeridas' => trim((string) ($transporte['cantidad_unidades_requeridas'] ?? '')),
                'origen' => trim((string) ($transporte['origen'] ?? '')),
                'origen_snapshot' => trim((string) ($transporte['origen_snapshot'] ?? '')),
                'destino_snapshot' => trim((string) ($transporte['destino_snapshot'] ?? '')),
                'unidades_transporte' => trim((string) ($transporte['unidades_transporte'] ?? '')),
                'placas_asignadas' => trim((string) ($transporte['placas_asignadas'] ?? '')),
                'fecha_inicio' => trim((string) ($transporte['fecha_inicio'] ?? '')),
                'fecha_fin' => trim((string) ($transporte['fecha_fin'] ?? '')),
                'dias_uso' => trim((string) ($transporte['dias_uso'] ?? '')),
                'estado_logistico' => trim((string) ($transporte['estado_logistico'] ?? '')),
                'indicaciones' => trim((string) ($transporte['indicaciones'] ?? '')),
                'observaciones' => trim((string) ($transporte['observaciones'] ?? '')),
                'comentario_cambio' => trim((string) ($transporte['comentario_cambio'] ?? '')),
                'incidencia_operativa' => trim((string) ($transporte['incidencia_operativa'] ?? '')),
                'recepcion_fecha' => trim((string) ($transporte['recepcion_fecha'] ?? '')),
                'recepcion_estado' => trim((string) ($transporte['recepcion_estado'] ?? '')),
                'recepcion_observacion' => trim((string) ($transporte['recepcion_observacion'] ?? '')),
            ];

            $hasData = collect($row)
                ->except(['id', 'actividad_key', 'estado_logistico', 'recepcion_estado'])
                ->filter(fn ($value): bool => trim((string) $value) !== '')
                ->isNotEmpty();

            if (!$hasData) {
                continue;
            }

            $normalized[] = $row;
        }

        return $normalized;
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
