<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\Personal;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EppBalanceService
{
    public function __construct(private readonly EppScopeService $scope, private readonly EppInventoryService $inventory) {}

    public function forWorker(Personal $worker, Usuario $actor): Collection
    {
        $actor = Usuario::query()->findOrFail($actor->id);
        if (! PermissionMatrix::userCanDirectAny($actor, 'epps', ['ver_operaciones', 'preparar_devolucion'])) {
            throw new AuthorizationException('No tienes permiso para consultar saldos EPP.');
        }
        $this->scope->assertPersonalAccess($actor, $worker);

        // A ceased worker keeps the same obligations, without modifying their employment state.
        return EppEntrega::query()->with('epp', 'operacion.almacen', 'operacionDetalle')->where('personal_id', $worker->id)
            ->where('politica_devolucion_snapshot', 'RETORNABLE')->where('cantidad_pendiente_devolucion', '>', 0)
            ->where('estado', EppEntrega::ESTADO_ENTREGADO)
            ->where(fn ($query) => $query->whereHas('operacion', fn ($operation) => $operation->whereIn('almacen_id', $this->scope->visibleWarehouseIds($actor)))
                ->orWhere(fn ($legacy) => $legacy->whereNull('operacion_id')->where('es_legacy', true)))
            ->orderBy('fecha_entrega')->orderBy('id')->get();
    }

    /** Caller locks operation, warehouse and catalog first. Always lock source IDs in order. */
    public function validateBatch(EppOperacion $operation, Collection $details, string $quantityField, bool $checkReview = false): Collection
    {
        if (DB::transactionLevel() === 0 || $operation->tipo !== 'DEVOLUCION') {
            throw new InvalidArgumentException('La devolucion requiere una transaccion de operacion.');
        }
        $details = $details->filter(fn ($detail) => $detail->estado !== 'RECHAZADO');
        $sources = EppEntrega::query()->whereIn('id', $details->pluck('entrega_origen_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($details->groupBy('entrega_origen_id') as $sourceId => $lines) {
            $source = $sources->get($sourceId);
            if ($source === null || $source->personal_id !== $operation->personal_id
                || ($source->operacion_id === null ? ! $source->es_legacy : $source->operacion?->almacen_id !== $operation->almacen_id)
                || $source->politica_devolucion_snapshot !== 'RETORNABLE' || $source->estado !== EppEntrega::ESTADO_ENTREGADO
                || $lines->sum($quantityField) <= 0 || $lines->sum($quantityField) > $source->cantidad_pendiente_devolucion) {
                throw new InvalidArgumentException('La cantidad total devuelta supera el saldo retornable vigente o la entrega no corresponde al trabajador y almacen.');
            }
            $attributes = $this->inventory->lineAttributes(['atributos' => $source->atributos_json ?: [], 'talla' => $source->talla, 'color' => $source->color]);
            foreach ($lines as $line) {
                if ($checkReview && ($line->snapshot_json['saldo_anterior'] ?? null) !== $source->cantidad_pendiente_devolucion) {
                    throw new InvalidArgumentException('El saldo restante cambio desde la revision. Prepara y revisa nuevamente la devolucion.');
                }
                if ($line->politica_devolucion_snapshot !== 'RETORNABLE' || $line->epp_id !== $source->epp_id || $line->epp_solicitado_id !== $source->epp_id
                    || $line->variante_clave !== $this->inventory->variantKey($attributes)) {
                    throw new InvalidArgumentException('El EPP y variante devueltos deben coincidir con la entrega de origen.');
                }
                $this->assertDisposition($line->condicion_devolucion, $line->destino_inventario);
                if ($line->condicion_devolucion === 'OTRO' && trim((string) $line->motivo) === '') {
                    throw new InvalidArgumentException('Describe la condicion OTRO antes de preparar la devolucion.');
                }
            }
        }

        return $sources;
    }

    public function assertDisposition(?string $condition, ?string $destination): void
    {
        if (($condition === 'BUENO' && $destination === 'DISPONIBLE')
            || (in_array($condition, ['DESGASTADO', 'DANADO', 'INCOMPLETO', 'OTRO'], true) && in_array($destination, ['CUARENTENA', 'BAJA'], true))) {
            return;
        }
        throw new InvalidArgumentException('BUENO vuelve a disponible; DESGASTADO, DANADO o INCOMPLETO requiere cuarentena o baja.');
    }

    /** Applies only a line covered by the acceptance in the caller's confirmation transaction. */
    public function applyReturn(EppOperacionDetalle $detail, Usuario $actor): EppEntrega
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Confirma la devolucion mediante su aceptacion firmada.');
        }
        $candidate = EppOperacionDetalle::query()->findOrFail($detail->id);
        $operation = EppOperacion::query()->lockForUpdate()->findOrFail($candidate->operacion_id);
        $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($operation->almacen_id);
        $detail = EppOperacionDetalle::query()->lockForUpdate()->findOrFail($candidate->id);
        $actor = Usuario::query()->findOrFail($actor->id);
        $this->scope->assertWarehouseAccess($actor, $warehouse);
        $this->scope->assertPersonalAccess($actor, $operation->personal);
        $acceptance = $operation?->aceptacion;
        $signed = collect($acceptance?->contenido_json['details'] ?? [])->firstWhere('id', $detail->id);
        if ($operation?->tipo !== 'DEVOLUCION' || $acceptance === null || $acceptance->operador_usuario_id !== $actor->id || $signed === null
            || ($signed['processed_quantity'] ?? null) !== $detail->cantidad_procesada
            || ($signed['original_delivery_id'] ?? null) !== $detail->entrega_origen_id
            || ($signed['return_condition'] ?? null) !== $detail->condicion_devolucion
            || ($signed['inventory_destination'] ?? null) !== $detail->destino_inventario) {
            throw new InvalidArgumentException('La linea devuelta debe estar cubierta por la aceptacion firmada.');
        }
        $source = EppEntrega::query()->lockForUpdate()->findOrFail($detail->entrega_origen_id);
        if ($detail->estado === 'DEVUELTO') {
            return $source;
        }
        $this->validateBatch($operation, collect([$detail]), 'cantidad_procesada');
        if ($detail->estado !== 'RESERVADO' || $detail->cantidad_procesada < 1) {
            throw new InvalidArgumentException('La linea no esta preparada para devolucion.');
        }
        $this->inventory->receiveReturn($operation, $detail, $actor);
        $remaining = $source->cantidad_pendiente_devolucion - $detail->cantidad_procesada;
        $source->update(['cantidad_pendiente_devolucion' => $remaining, 'estado' => $remaining === 0 ? EppEntrega::ESTADO_DEVUELTO : EppEntrega::ESTADO_ENTREGADO,
            'devuelto_at' => $remaining === 0 ? $acceptance->firmado_at : null, 'cerrado_por_usuario_id' => $remaining === 0 ? $actor->id : null]);
        $detail->update(['estado' => 'DEVUELTO']);

        return $source;
    }

    public function markLoss(EppEntrega $delivery, int $quantity, string $reason, Usuario $actor): EppOperacion
    {
        if ($quantity < 1 || $quantity > 1000000 || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Indica cantidad positiva y motivo de la perdida, hasta 1000 caracteres.');
        }
        $delivery = EppEntrega::query()->findOrFail($delivery->id);
        $sourceOperation = $delivery->operacion;
        if ($sourceOperation === null) {
            throw new InvalidArgumentException('Regulariza el almacen de la entrega historica antes de registrar la perdida.');
        }

        return DB::transaction(function () use ($sourceOperation, $delivery, $quantity, $reason, $actor) {
            EppOperacion::query()->lockForUpdate()->findOrFail($sourceOperation->id);
            $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($sourceOperation->almacen_id);
            Gate::forUser(Usuario::query()->findOrFail($actor->id))->authorize('correct', $sourceOperation->fresh()->setRelation('almacen', $warehouse));
            $source = EppEntrega::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($source->politica_devolucion_snapshot !== 'RETORNABLE' || $source->estado !== 'ENTREGADO' || $quantity > $source->cantidad_pendiente_devolucion) {
                throw new InvalidArgumentException('La perdida supera el saldo retornable vigente.');
            }
            $remaining = $source->cantidad_pendiente_devolucion - $quantity;
            $operation = EppOperacion::query()->create(['personal_id' => $source->personal_id, 'almacen_id' => $warehouse->id, 'mina_id' => $warehouse->mina_id, 'tipo' => 'CORRECCION', 'estado' => 'FIRMADO', 'submission_uuid' => (string) Str::uuid(), 'operacion_origen_id' => $sourceOperation->id, 'registrado_por_usuario_id' => $actor->id, 'confirmado_por_usuario_id' => $actor->id, 'motivo' => trim($reason)]);
            $source->update(['cantidad_pendiente_devolucion' => $remaining, 'estado' => $remaining === 0 ? 'PERDIDA_OLVIDO' : 'ENTREGADO', 'cerrado_por_usuario_id' => $remaining === 0 ? $actor->id : null]);
            $operation->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'PERDIDA_REGISTRADA', 'origen' => 'WEB', 'motivo' => trim($reason), 'snapshot_json' => ['entrega_origen_id' => $source->id, 'cantidad' => $quantity, 'saldo_anterior' => $remaining + $quantity, 'saldo_restante' => $remaining]]);

            return $operation;
        }, 3);
    }
}
