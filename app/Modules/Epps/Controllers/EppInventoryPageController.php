<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EppAlmacen;
use App\Models\EppExistencia;
use App\Models\EppInventarioMovimiento;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\Usuario;
use App\Modules\Epps\Requests\AdjustEppStockRequest;
use App\Modules\Epps\Requests\OpenEppStockRequest;
use App\Modules\Epps\Services\EppInventoryService;
use App\Modules\Epps\Services\EppScopeService;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class EppInventoryPageController extends WebPageController
{
    public function __construct(private readonly EppInventoryService $inventory, private readonly EppScopeService $scope) {}

    public function index(Request $request): View
    {
        return $this->page($request, $this->actor());
    }

    public function previewOpening(OpenEppStockRequest $request): View
    {
        $actor = $this->actor();
        $payload = $request->validated();
        $warehouse = EppAlmacen::query()->findOrFail($payload['almacen_id']);
        Gate::forUser($actor)->authorize('manageInventory', [EppOperacion::class, $warehouse]);
        $rows = [];
        $seen = [];
        try {
            foreach ($payload['lines'] as $line) {
                $epp = EppRegistro::query()->findOrFail($line['epp_id']);
                $attributes = $this->inventory->lineAttributes($line);
                $this->inventory->assertVariant($epp, $attributes);
                $key = $epp->id.$this->inventory->variantKey($attributes);
                if (isset($seen[$key]) || $this->inventory->available($warehouse, $epp, $attributes) !== null) {
                    throw new InvalidArgumentException('La variante esta repetida o ya tiene apertura. Revisa el conteo.');
                }
                $seen[$key] = true;
                $rows[] = ['nombre' => $epp->nombre, 'atributos' => $attributes, 'antes' => 'Sin conciliar', 'despues' => (int) $line['cantidad']];
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return $this->savePreview($request, $actor, 'apertura', $payload, $rows, $warehouse->nombre);
    }

    public function previewAdjustment(AdjustEppStockRequest $request): View
    {
        $actor = $this->actor();
        $payload = $request->validated();
        $existence = EppExistencia::query()->with(['epp', 'almacen'])->findOrFail($payload['existencia_id']);
        Gate::forUser($actor)->authorize('adjustInventory', [EppOperacion::class, $existence->almacen]);
        $after = $existence->cantidad_disponible + (int) $payload['delta'];
        if ($existence->conciliado_at === null || $after < 0) {
            throw ValidationException::withMessages(['delta' => 'La variante debe estar conciliada y el saldo no puede quedar negativo.']);
        }
        $payload['saldo_anterior'] = $existence->cantidad_disponible;

        return $this->savePreview($request, $actor, 'ajuste', $payload, [[
            'nombre' => $existence->epp->nombre, 'atributos' => $existence->atributos_json,
            'antes' => $existence->cantidad_disponible, 'despues' => $after,
        ]], $existence->almacen->nombre);
    }

    public function previewWarehouse(Request $request): View
    {
        $actor = $this->actor();
        $payload = $request->validate(['nombre' => ['required', 'string', 'max:191'], 'mina_id' => ['nullable', 'uuid', 'exists:minas,id'], 'motivo' => ['required', 'string', 'max:1000']]);
        $payload['new_warehouse_id'] = (string) Str::uuid();
        $warehouse = new EppAlmacen(['mina_id' => $payload['mina_id'] ?? null]);
        Gate::forUser($actor)->authorize('manageInventory', [EppOperacion::class, $warehouse]);

        return $this->savePreview($request, $actor, 'almacen', $payload, [], $payload['nombre']);
    }

    public function confirm(Request $request, string $action): RedirectResponse
    {
        $actor = $this->actor();
        $validated = $request->validate(['preview_token' => ['required', 'uuid'], 'confirmacion' => ['accepted']]);
        $preview = $request->session()->get('epp_inventory_preview');
        if (! $preview || $preview['actor_id'] !== $actor->id || $preview['action'] !== $action
            || ! hash_equals($preview['token'], $validated['preview_token']) || $preview['expires_at'] < time()) {
            throw ValidationException::withMessages(['preview_token' => 'La revision vencio o ya fue confirmada. Genera una nueva vista previa.']);
        }
        $payload = $preview['payload'];
        try {
            $count = DB::transaction(function () use ($action, $actor, $payload): int {
                if ($action === 'almacen') {
                    $warehouse = new EppAlmacen(['id' => $payload['new_warehouse_id'], 'nombre' => trim($payload['nombre']), 'mina_id' => $payload['mina_id'] ?? null, 'estado' => 'ACTIVO']);
                    Gate::forUser($actor)->authorize('manageInventory', [EppOperacion::class, $warehouse]);
                    $warehouse->save();
                    DB::afterCommit(fn () => Log::info('epp.warehouse_created', ['almacen_id' => $warehouse->id, 'mina_id' => $warehouse->mina_id, 'actor_usuario_id' => $actor->id, 'origen' => 'WEB', 'motivo' => $payload['motivo']]));

                    return 1;
                }
                if ($action === 'apertura') {
                    return $this->inventory->openStock(EppAlmacen::query()->findOrFail($payload['almacen_id']), $payload['lines'], $actor, $payload['motivo'])->count();
                }
                $existence = EppExistencia::query()->findOrFail($payload['existencia_id']);
                $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($existence->almacen_id);
                $existence = EppExistencia::query()->lockForUpdate()->findOrFail($existence->id);
                Gate::forUser($actor)->authorize('adjustInventory', [EppOperacion::class, $warehouse]);
                if ($existence->cantidad_disponible !== $payload['saldo_anterior']) {
                    throw new InvalidArgumentException('El saldo cambio despues de la revision. Genera una nueva vista previa.');
                }
                $this->inventory->adjust($warehouse, $existence->epp, $existence->atributos_json ?: [], (int) $payload['delta'], $actor, $payload['motivo']);

                return 1;
            }, 3);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inventario' => $exception->getMessage()]);
        }
        $request->session()->forget('epp_inventory_preview');

        return redirect()->route('epps.inventory.index')->with('success', $action === 'almacen' ? 'Almacen creado. Siguiente paso: registrar el conteo inicial.' : "Confirmado: {$count} variantes procesadas; 0 omitidas; 0 errores.");
    }

    private function savePreview(Request $request, Usuario $actor, string $action, array $payload, array $rows, string $warehouseName): View
    {
        $preview = ['token' => (string) Str::uuid(), 'actor_id' => $actor->id, 'action' => $action, 'payload' => $payload, 'rows' => $rows, 'warehouse_name' => $warehouseName, 'expires_at' => time() + 900];
        $request->session()->put('epp_inventory_preview', $preview);

        return $this->page($request, $actor, $preview);
    }

    private function page(Request $request, Usuario $actor, ?array $preview = null): View
    {
        $ids = $this->scope->visibleWarehouseIds($actor);
        $warehouses = EppAlmacen::query()->whereIn('id', $ids)->with('mina')->orderBy('nombre')->get();
        $warehouseId = (string) $request->input('almacen_id', '');
        if ($warehouseId !== '') {
            $this->scope->assertWarehouseAccess($actor, EppAlmacen::query()->findOrFail($warehouseId));
        }
        $query = EppExistencia::query()->with(['almacen', 'epp'])->whereIn('almacen_id', $ids)
            ->when($warehouseId !== '', fn ($q) => $q->where('almacen_id', $warehouseId))
            ->when($request->filled('q'), fn ($q) => $q->whereHas('epp', fn ($e) => $e->where('nombre', 'like', '%'.$request->input('q').'%')));
        $mines = Mina::query()->when(! MinaScopeAccess::isPrivileged($actor), fn ($q) => $q->whereIn('id', $actor->scopesMina()->select('mina_id')))->orderBy('nombre')->get(['id', 'nombre']);
        $catalog = EppRegistro::query()->where('estado', 'ACTIVO')->where('control_inventario', true)->orderBy('nombre')->get();

        return view('epps.inventory.index', [
            'warehouses' => $warehouses, 'existences' => $query->orderBy('almacen_id')->paginate(20)->withQueryString(),
            'catalog' => $catalog, 'openingProduct' => $catalog->firstWhere('id', $request->input('opening_epp_id')),
            'openingRows' => max(1, min(20, (int) $request->input('opening_rows', 1))),
            'mines' => $mines, 'allowGlobal' => MinaScopeAccess::isPrivileged($actor),
            'canManage' => PermissionMatrix::userCanDirect($actor, 'epps', 'gestionar_inventario'),
            'canAdjust' => PermissionMatrix::userCanDirect($actor, 'epps', 'ajustar_inventario'), 'preview' => $preview,
            'movements' => EppInventarioMovimiento::query()->with(['epp', 'almacen', 'actor'])->whereIn('almacen_id', $ids)->when($warehouseId !== '', fn ($q) => $q->where('almacen_id', $warehouseId))->latest('created_at')->limit(20)->get(),
        ]);
    }

    private function actor(): Usuario
    {
        return Usuario::query()->with(['rol', 'rolesAdicionales'])->where('estado', 'ACTIVO')->findOrFail(session('user_id') ?: session('user.id'));
    }
}
