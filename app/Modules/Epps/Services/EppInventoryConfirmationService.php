<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAlmacen;
use App\Models\EppExistencia;
use App\Models\EppInventarioConfirmacion;
use App\Models\EppOperacion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EppInventoryConfirmationService
{
    public function __construct(private readonly EppInventoryService $inventory) {}

    public function preview(Usuario $actor, string $action, array $payload): EppInventarioConfirmacion
    {
        $this->authorize($actor, $action, $payload);

        // Coordination metadata only. Preview never changes quantities or appends ledger entries.
        return EppInventarioConfirmacion::query()->create([
            'actor_usuario_id' => $actor->id, 'accion' => $action,
            'payload_json' => $payload, 'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function confirm(string $token, Usuario $actor, string $action): array
    {
        return DB::transaction(function () use ($token, $actor, $action): array {
            // The same row guards both the business effect and its persisted result.
            $confirmation = EppInventarioConfirmacion::query()->whereKey($token)->lockForUpdate()->first();
            if (! $confirmation || $confirmation->actor_usuario_id !== $actor->id || $confirmation->accion !== $action) {
                throw ValidationException::withMessages(['preview_token' => 'La revision no corresponde al usuario o accion. Genera una nueva vista previa.']);
            }
            $payload = $confirmation->payload_json;
            $this->authorize($actor, $action, $payload, $confirmation->resultado_json);
            if ($confirmation->confirmado_at !== null) {
                return $confirmation->resultado_json;
            }
            if ($confirmation->expires_at->lte(now())) {
                throw ValidationException::withMessages(['preview_token' => 'La revision vencio. Genera una nueva vista previa.']);
            }

            $result = match ($action) {
                'almacen' => $this->createWarehouse($payload, $actor),
                'apertura' => ['count' => $this->inventory->openStock(EppAlmacen::query()->findOrFail($payload['almacen_id']), $payload['lines'], $actor, $payload['motivo'])->count(), 'almacen_id' => $payload['almacen_id']],
                'ajuste' => $this->adjust($payload, $actor),
            };
            $confirmation->update(['confirmado_at' => now(), 'resultado_json' => $result]);

            return $result;
        }, 3);
    }

    private function authorize(Usuario $actor, string $action, array $payload, ?array $result = null): void
    {
        $warehouse = match ($action) {
            'almacen' => $result
                ? EppAlmacen::query()->findOrFail($result['almacen_id'])
                : new EppAlmacen(['mina_id' => $payload['mina_id'] ?? null]),
            'apertura' => EppAlmacen::query()->findOrFail($payload['almacen_id']),
            'ajuste' => EppExistencia::query()->findOrFail($payload['existencia_id'])->almacen,
            default => throw new InvalidArgumentException('La accion de inventario no es valida.'),
        };
        Gate::forUser($actor)->authorize($action === 'ajuste' ? 'adjustInventory' : 'manageInventory', [EppOperacion::class, $warehouse]);
    }

    private function createWarehouse(array $payload, Usuario $actor): array
    {
        $warehouse = EppAlmacen::query()->create([
            'id' => $payload['new_warehouse_id'], 'nombre' => trim($payload['nombre']),
            'mina_id' => $payload['mina_id'] ?? null, 'estado' => 'ACTIVO',
        ]);
        DB::afterCommit(fn () => Log::info('epp.warehouse_created', [
            'almacen_id' => $warehouse->id, 'mina_id' => $warehouse->mina_id,
            'actor_usuario_id' => $actor->id, 'origen' => 'WEB', 'motivo' => $payload['motivo'],
        ]));

        return ['count' => 1, 'almacen_id' => $warehouse->id];
    }

    private function adjust(array $payload, Usuario $actor): array
    {
        $existence = EppExistencia::query()->findOrFail($payload['existencia_id']);
        $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($existence->almacen_id);
        $existence = EppExistencia::query()->lockForUpdate()->findOrFail($existence->id);
        Gate::forUser($actor)->authorize('adjustInventory', [EppOperacion::class, $warehouse]);
        if ($existence->cantidad_disponible !== $payload['saldo_anterior']) {
            throw new InvalidArgumentException('El saldo cambio despues de la revision. Genera una nueva vista previa.');
        }
        $this->inventory->adjust($warehouse, $existence->epp, $existence->atributos_json ?: [], (int) $payload['delta'], $actor, $payload['motivo']);

        return ['count' => 1, 'almacen_id' => $warehouse->id];
    }
}
