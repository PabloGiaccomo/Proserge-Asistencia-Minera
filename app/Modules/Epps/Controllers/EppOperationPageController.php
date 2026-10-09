<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Requests\AnnulEppOperationRequest;
use App\Modules\Epps\Requests\CorrectEppOperationRequest;
use App\Modules\Epps\Requests\SignEppOperationRequest;
use App\Modules\Epps\Requests\StoreEppOperationRequest;
use App\Modules\Epps\Requests\StoreEppReturnRequest;
use App\Modules\Epps\Requests\UpdateEppOperationRequest;
use App\Modules\Epps\Services\EppBalanceService;
use App\Modules\Epps\Services\EppCorrectionService;
use App\Modules\Epps\Services\EppOperationConfirmationService;
use App\Modules\Epps\Services\EppOperationService;
use App\Modules\Epps\Services\EppScopeService;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class EppOperationPageController extends WebPageController
{
    public function __construct(private readonly EppOperationService $operations, private readonly EppOperationConfirmationService $confirmations, private readonly EppScopeService $scope, private readonly EppCorrectionService $corrections) {}

    public function create(Request $request): View
    {
        $actor = $this->actor();
        abort_unless(PermissionMatrix::userCanDirect($actor, 'epps', 'preparar_entrega'), 403);

        return $this->form($request, $actor);
    }

    public function searchWorkers(Request $request): JsonResponse
    {
        $actor = $this->actor();
        abort_unless(PermissionMatrix::userCanDirect($actor, 'epps', $request->boolean('devolucion') ? 'preparar_devolucion' : 'preparar_entrega'), 403);
        $q = trim((string) $request->query('q'));
        $items = [];
        if (mb_strlen($q) >= 2) {
            $query = $this->workers($actor);
            foreach (preg_split('/\s+/', mb_substr($q, 0, 100)) as $word) {
                $query->where(fn ($query) => $query->where('nombre_completo', 'like', '%'.$word.'%')->orWhere('dni', 'like', '%'.$word.'%'));
            }
            $items = $query->orderBy('nombre_completo')->limit(15)->get(['id', 'nombre_completo', 'dni'])->all();
        }

        return response()->json(['items' => $items])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreEppOperationRequest $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validated();
        abort_unless($data['tipo'] === 'ENTREGA', 422, 'Usa el flujo de devoluciones para registrar un retorno.');
        try {
            $operation = DB::transaction(function () use ($actor, $data) {
                $draft = $this->operations->createDraft($actor, Personal::query()->findOrFail($data['personal_id']), EppAlmacen::query()->findOrFail($data['almacen_id']), 'ENTREGA', $data['submission_uuid']);
                if ($draft->estado !== 'BORRADOR') {
                    return $draft;
                }

                return $this->operations->prepareForSignature($this->operations->syncDetails($draft, $data['lines'], $actor), $actor);
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return $this->next($operation);
    }

    public function createReturn(Request $request): View
    {
        $actor = $this->actor();
        abort_unless(PermissionMatrix::userCanDirect($actor, 'epps', 'preparar_devolucion'), 403);
        $warehouses = EppAlmacen::query()->whereIn('id', $this->scope->visibleWarehouseIds($actor))->where('estado', 'ACTIVO')->orderBy('nombre')->get();
        $warehouseId = $request->input('almacen_id', old('almacen_id'));
        $warehouse = $warehouseId ? EppAlmacen::query()->findOrFail($warehouseId) : $warehouses->first();
        if ($warehouse) {
            $this->scope->assertWarehouseAccess($actor, $warehouse);
        }
        $workerId = $request->input('personal_id', old('personal_id'));
        $worker = $workerId ? Personal::query()->findOrFail($workerId) : null;
        $balances = $worker && $warehouse ? app(EppBalanceService::class)->forWorker($worker, $actor)->filter(fn ($delivery) => $delivery->operacion === null || $delivery->operacion->almacen_id === $warehouse->id) : collect();

        return view('epps.operations.return-form', ['worker' => $worker, 'warehouse' => $warehouse, 'warehouses' => $warehouses, 'balances' => $balances, 'submissionUuid' => old('submission_uuid', (string) Str::uuid())]);
    }

    public function storeReturn(StoreEppReturnRequest $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validated();
        try {
            $operation = DB::transaction(function () use ($actor, $data) {
                $draft = $this->operations->createDraft($actor, Personal::query()->findOrFail($data['personal_id']), EppAlmacen::query()->findOrFail($data['almacen_id']), 'DEVOLUCION', $data['submission_uuid']);
                if ($draft->estado !== 'BORRADOR') {
                    return $draft;
                }

                return $this->operations->prepareForSignature($this->operations->syncDetails($draft, $data['lines'], $actor), $actor);
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return $this->next($operation);
    }

    public function edit(Request $request, EppOperacion $operation): View
    {
        $actor = $this->actor();
        $this->authorizePreparation($actor, $operation);
        abort_unless($operation->estado === 'BORRADOR', 409, 'Este lote ya fue preparado. Revisa o registra un desacuerdo antes de crear otro.');
        abort_if($operation->tipo === 'DEVOLUCION', 409, 'Prepara una nueva devolucion desde sus saldos vigentes.');

        return $this->form($request, $actor, $operation);
    }

    public function update(UpdateEppOperationRequest $request, EppOperacion $operation): RedirectResponse
    {
        $actor = $this->actor();
        $this->authorizePreparation($actor, $operation);
        try {
            $operation = DB::transaction(fn () => $this->operations->prepareForSignature($this->operations->syncDetails($operation, $request->validated('lines'), $actor), $actor));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return $this->next($operation);
    }

    public function review(Request $request, EppOperacion $operation): View|RedirectResponse
    {
        $actor = $this->actor();
        $witness = $request->boolean('testigo');
        Gate::forUser($actor)->authorize($witness ? 'approveSignatureException' : ($operation->tipo === 'DEVOLUCION' ? 'confirmReturn' : 'confirmDelivery'), $operation);
        $payload = $this->operations->reviewPayload($operation);
        $operation->refresh();
        if ($operation->estado !== 'LISTO_PARA_FIRMA') {
            return redirect()->route('epps.operations.show', $operation)->with('warning', 'Este lote ya no esta disponible para firmar. Revisa su estado.');
        }
        if ($witness) {
            abort_if($operation->eventos()->where('accion', 'FIRMA_RECHAZADA')->exists(), 409, 'Una negativa no admite testigo.');
        }

        return view('epps.operations.review', [
            'operation' => $operation, 'payload' => $payload, 'witness' => $witness, 'approver' => $actor,
            'canWitness' => Gate::forUser($actor)->allows('approveSignatureException', $operation),
            'operator' => Usuario::query()->with('personal')->find($operation->registrado_por_usuario_id),
            'witnesses' => $witness ? Usuario::query()->with('personal')->where('estado', 'ACTIVO')->whereNotIn('id', [$actor->id, $operation->registrado_por_usuario_id])->whereIn('personal_id', $this->workers($actor)->select('personal.id'))->whereHas('personal', fn ($worker) => $worker->where('id', '!=', $operation->personal_id)->whereNotNull('dni'))->get(['id', 'personal_id']) : collect(),
        ]);
    }

    public function sign(SignEppOperationRequest $request, EppOperacion $operation): RedirectResponse
    {
        $actor = $this->actor();
        $signature = $request->signaturePayload();
        $request->validate(['confirmacion' => ['required', 'accepted']]);
        $witness = $signature->data['tipo_firmante'] === 'TESTIGO_AUTORIZADO';
        Gate::forUser($actor)->authorize($witness ? 'approveSignatureException' : ($operation->tipo === 'DEVOLUCION' ? 'confirmReturn' : 'confirmDelivery'), $operation);
        try {
            // SignaturePayload admits only identity/revision/signature. Quantities remain in MySQL.
            $confirmed = $witness ? $this->confirmations->confirmWithWitness($operation, $signature, $actor) : $this->confirmations->confirm($operation, $signature, $actor);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['firma_data_url' => $exception->getMessage()]);
        }
        $valid = (bool) ($confirmed->verificacion_evidencia['valid'] ?? false);

        $label = $confirmed->tipo === 'DEVOLUCION' ? 'Devolucion' : 'Entrega';

        return redirect()->route('epps.operations.show', $confirmed)->with($valid ? 'success' : 'warning', $valid ? $label.' confirmada. Se registraron solo las unidades revisadas.' : $label.' registrada con alerta de integridad. Solicita revision de la evidencia.');
    }

    public function reject(Request $request, EppOperacion $operation): RedirectResponse
    {
        $actor = $this->actor();
        $this->authorizePreparation($actor, $operation);
        $data = $request->validate(['decision' => ['required', 'in:DESACUERDO,NEGATIVA'], 'motivo' => ['required', 'string', 'max:900']]);
        try {
            $this->operations->reject($operation, $actor, ($data['decision'] === 'NEGATIVA' ? 'Negativa a firmar: ' : 'Desacuerdo: ').$data['motivo']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['motivo' => $exception->getMessage()]);
        }

        return redirect()->route('epps.operations.show', $operation)->with('success', $operation->tipo === 'DEVOLUCION' ? 'Lote rechazado. No se registro devolucion ni cambio de saldo.' : 'Lote rechazado. No se registro entrega y se liberaron las reservas.');
    }

    public function show(EppOperacion $operation): View
    {
        $actor = $this->actor();
        Gate::forUser($actor)->authorize('view', $operation);
        if (in_array($operation->tipo, ['CORRECCION', 'ANULACION'], true)) {
            return view('epps.operations.compensation', ['operation' => $operation->load('personal', 'almacen', 'detalles.epp', 'operacionOrigen.aceptacion')]);
        }
        $payload = $this->operations->reviewPayload($operation);

        return view('epps.operations.show', [
            'operation' => $operation->fresh()->load('detalles'), 'payload' => $payload,
            'integrityState' => $operation->aceptacion()->value('estado_integridad'),
        ]);
    }

    public function correct(CorrectEppOperationRequest $request, EppOperacion $operation): RedirectResponse
    {
        $actor = $this->actor();
        try {
            $correction = $this->corrections->correct($operation, $request->validated(), $actor, $request->validated('motivo'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['correccion' => $exception->getMessage()]);
        }

        return redirect()->route('epps.operations.show', $correction)->with('success', 'Correccion compensatoria registrada. La operacion original se conserva sin cambios.');
    }

    public function legacyForm(EppEntrega $delivery): View
    {
        $actor = $this->actor();
        $this->scope->assertPersonalAccess($actor, $delivery->personal);
        abort_unless(PermissionMatrix::userCanDirect($actor, 'epps', 'corregir') || PermissionMatrix::userCanDirect($actor, 'epps', 'anular'), 403);
        abort_if($delivery->operacion_id !== null, 409, 'Usa la operacion firmada para compensar este registro.');

        return view('epps.operations.legacy-correction', ['delivery' => $delivery, 'warehouses' => EppAlmacen::query()->whereIn('id', $this->scope->visibleWarehouseIds($actor))->where('estado', 'ACTIVO')->get()]);
    }

    public function legacyCompensate(Request $request, EppEntrega $delivery): RedirectResponse
    {
        $data = $request->validate(['almacen_id' => ['required', 'uuid', 'exists:epp_almacenes,id'], 'cantidad' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'max:1000'], 'tipo' => ['required', 'in:CORRECCION,ANULACION']]);
        try {
            $operation = $this->corrections->compensateLegacy($delivery, EppAlmacen::query()->findOrFail($data['almacen_id']), (int) $data['cantidad'], $this->actor(), $data['motivo'], $data['tipo'] === 'ANULACION');
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cantidad' => $exception->getMessage()]);
        }

        return redirect()->route('epps.operations.show', $operation)->with('success', 'Saldo legacy compensado con auditoria. No se modifico inventario.');
    }

    public function annul(AnnulEppOperationRequest $request, EppOperacion $operation): RedirectResponse
    {
        $actor = $this->actor();
        try {
            $annulment = $this->corrections->annul($operation, $actor, $request->validated('motivo'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['anulacion' => $exception->getMessage()]);
        }

        return redirect()->route('epps.operations.show', $annulment)->with('success', 'Anulacion compensatoria registrada. La operacion original se conserva sin cambios.');
    }

    private function form(Request $request, Usuario $actor, ?EppOperacion $operation = null): View
    {
        $warehouses = EppAlmacen::query()->whereIn('id', $this->scope->visibleWarehouseIds($actor))->where('estado', 'ACTIVO')->with('mina')->orderBy('nombre')->get();
        $warehouseId = $operation?->almacen_id ?? $request->input('almacen_id', old('almacen_id'));
        $warehouse = $warehouseId ? EppAlmacen::query()->findOrFail($warehouseId) : $warehouses->first();
        if ($warehouse) {
            $this->scope->assertWarehouseAccess($actor, $warehouse);
        }
        $personalId = $operation?->personal_id ?? $request->input('personal_id', old('personal_id'));
        $worker = $personalId ? Personal::query()->findOrFail($personalId) : null;
        if ($worker) {
            $this->scope->assertPersonalAccess($actor, $worker);
        }
        $lines = $operation ? $operation->detalles->map(fn ($detail) => [
            'epp_id' => $detail->epp_solicitado_id, 'epp_real_id' => $detail->epp_id,
            'cantidad_solicitada' => $detail->cantidad_solicitada, 'atributos' => $detail->atributos_json,
            'aceptar_parcial' => $detail->snapshot_json['aceptar_parcial'] ?? false,
            'aceptar_sustitucion' => $detail->snapshot_json['aceptar_sustitucion'] ?? false, 'motivo' => $detail->motivo,
        ])->all() : [['cantidad_solicitada' => 1]];

        return view('epps.operations.form', [
            'operation' => $operation, 'warehouses' => $warehouses, 'warehouse' => $warehouse, 'worker' => $worker,
            'catalog' => EppRegistro::query()->where('estado', 'ACTIVO')->orderBy('nombre')->get(),
            'existences' => $warehouse ? EppExistencia::query()->where('almacen_id', $warehouse->id)->get() : collect(),
            'lines' => old('lines', $lines), 'submissionUuid' => old('submission_uuid', $operation?->submission_uuid ?? (string) Str::uuid()),
        ]);
    }

    private function authorizePreparation(Usuario $actor, EppOperacion $operation): void
    {
        Gate::forUser($actor)->authorize($operation->tipo === 'DEVOLUCION' ? 'prepareReturn' : 'prepareDelivery', [EppOperacion::class, $operation->almacen, $operation->personal]);
    }

    private function workers(Usuario $actor): Builder
    {
        $query = Personal::query();
        if (! MinaScopeAccess::isPrivileged($actor)) {
            $query->whereHas('minas', fn ($mine) => $mine->where('personal_mina.activo', true)->whereIn('minas.id', $actor->scopesMina()->select('mina_id')));
        }

        return $query;
    }

    private function next(EppOperacion $operation): RedirectResponse
    {
        return redirect()->route($operation->estado === 'LISTO_PARA_FIRMA' ? 'epps.operations.review' : 'epps.operations.show', $operation);
    }

    private function actor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }
}
