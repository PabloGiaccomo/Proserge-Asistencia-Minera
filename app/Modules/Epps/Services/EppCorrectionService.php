<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppInventarioMovimiento;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EppCorrectionService
{
    private const MAX_QUANTITY = 4294967295;

    public function compensateLegacy(EppEntrega $delivery, EppAlmacen $warehouse, int $quantity, Usuario $actor, string $reason, bool $annul = false): EppOperacion
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000 || $quantity < 1) {
            throw new InvalidArgumentException('Indica cantidad positiva y motivo de hasta 1000 caracteres.');
        }
        $context = new EppOperacion(['almacen_id' => $warehouse->id, 'personal_id' => $delivery->personal_id]);
        Gate::forUser($actor)->authorize($annul ? 'cancel' : 'correct', $context);

        return DB::transaction(function () use ($delivery, $warehouse, $quantity, $actor, $reason, $annul): EppOperacion {
            $source = EppEntrega::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($source->operacion_id !== null || ! $source->es_legacy || $source->politica_devolucion_snapshot !== 'RETORNABLE'
                || $quantity > $source->cantidad_pendiente_devolucion
                || ($annul && $quantity !== $source->cantidad_pendiente_devolucion)) {
                throw new InvalidArgumentException('El legado no tiene ese saldo retornable vigente. Revisa el historial.');
            }
            $operation = EppOperacion::query()->create([
                'personal_id' => $source->personal_id, 'almacen_id' => $warehouse->id, 'mina_id' => $warehouse->mina_id,
                'tipo' => $annul ? 'ANULACION' : 'CORRECCION', 'estado' => 'FIRMADO', 'submission_uuid' => (string) Str::uuid(),
                'registrado_por_usuario_id' => $actor->id, 'confirmado_por_usuario_id' => $actor->id, 'motivo' => $reason,
                'resumen_json' => ['legacy_delivery_id' => $source->id, 'inventory_effect' => 'NINGUNO_ORIGEN_NO_CONCILIADO'],
            ]);
            $operation->detalles()->create([
                'epp_solicitado_id' => $source->epp_id, 'epp_id' => $source->epp_id, 'entrega_origen_id' => $source->id,
                'cantidad_solicitada' => $quantity, 'cantidad_procesada' => $quantity, 'cantidad_pendiente' => 0, 'estado' => 'ANULADO',
                'politica_devolucion_snapshot' => $source->politica_devolucion_snapshot, 'control_inventario_snapshot' => false,
                'snapshot_json' => ['legacy_delivery_id' => $source->id, 'saldo_anterior' => $source->cantidad_pendiente_devolucion, 'sin_movimiento_inventario' => true],
            ]);
            $source->update(['cantidad_pendiente_devolucion' => $source->cantidad_pendiente_devolucion - $quantity]);
            $operation->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'LEGADO_COMPENSADO', 'origen' => 'WEB', 'motivo' => $reason, 'snapshot_json' => ['legacy_delivery_id' => $source->id, 'cantidad' => $quantity, 'sin_movimiento_inventario' => true]]);

            return $operation->load('detalles');
        });
    }

    public function correct(EppOperacion $operation, array $payload, Usuario $actor, string $reason): EppOperacion
    {
        return $this->compensate($operation, $payload['lines'] ?? [], $actor, $reason, EppOperacion::TIPO_CORRECCION);
    }

    public function annul(EppOperacion $operation, Usuario $actor, string $reason): EppOperacion
    {
        $operation = EppOperacion::query()->with('detalles')->findOrFail($operation->id);
        $lines = $operation->detalles->map(fn (EppOperacionDetalle $detail): array => [
            'detalle_origen_id' => $detail->id,
            'cantidad' => $this->remainingReversibleQuantity($operation, $detail),
        ])->filter(fn (array $line): bool => $line['cantidad'] > 0)->values()->all();

        if ($lines === []) {
            throw new InvalidArgumentException('La operacion ya no tiene cantidades que puedan anularse.');
        }

        return $this->compensate($operation, $lines, $actor, $reason, EppOperacion::TIPO_ANULACION);
    }

    private function compensate(EppOperacion $operation, array $lines, Usuario $actor, string $reason, string $type): EppOperacion
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Indica un motivo de hasta 1000 caracteres.');
        }
        Gate::forUser($actor)->authorize($type === EppOperacion::TIPO_ANULACION ? 'cancel' : 'correct', $operation);

        return DB::transaction(function () use ($operation, $lines, $actor, $reason, $type): EppOperacion {
            $source = EppOperacion::query()->lockForUpdate()->findOrFail($operation->id);
            if (! in_array($source->tipo, [EppOperacion::TIPO_ENTREGA, EppOperacion::TIPO_DEVOLUCION], true)
                || ! in_array($source->estado, [EppOperacion::ESTADO_FIRMADO, EppOperacion::ESTADO_FIRMADO_PARCIAL], true)) {
                throw new InvalidArgumentException('Solo una entrega o devolucion firmada puede corregirse o anularse.');
            }
            Gate::forUser($actor)->authorize($type === EppOperacion::TIPO_ANULACION ? 'cancel' : 'correct', $source);
            EppAlmacen::query()->lockForUpdate()->findOrFail($source->almacen_id);
            if ($type === EppOperacion::TIPO_ANULACION && EppOperacion::query()->where('operacion_origen_id', $source->id)->where('tipo', EppOperacion::TIPO_ANULACION)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('La operacion ya tiene una anulacion compensatoria.');
            }

            $sourceDetails = $source->detalles()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $normalized = $this->normalizeLines($lines);
            if ($normalized->isEmpty()) {
                throw new InvalidArgumentException('Selecciona al menos una cantidad para compensar.');
            }

            $compensation = EppOperacion::query()->create([
                'personal_id' => $source->personal_id,
                'almacen_id' => $source->almacen_id,
                'mina_id' => $source->mina_id,
                'tipo' => $type,
                'estado' => EppOperacion::ESTADO_FIRMADO,
                'submission_uuid' => (string) Str::uuid(),
                'operacion_origen_id' => $source->id,
                'registrado_por_usuario_id' => $actor->id,
                'confirmado_por_usuario_id' => $actor->id,
                'firmado_at' => now(),
                'motivo' => $reason,
                'resumen_json' => ['operation_origin_id' => $source->id, 'type' => $type, 'reason' => $reason],
            ]);

            foreach ($normalized as $sourceDetailId => $quantity) {
                $detail = $sourceDetails->get($sourceDetailId);
                if (! $detail instanceof EppOperacionDetalle || $quantity > $this->remainingReversibleQuantity($source, $detail)) {
                    throw new InvalidArgumentException('La cantidad a compensar supera el efecto vigente de la linea original.');
                }
                $source->tipo === EppOperacion::TIPO_ENTREGA
                    ? $this->reverseDelivery($source, $detail, $quantity, $compensation, $actor, $reason)
                    : $this->reverseReturn($source, $detail, $quantity, $compensation, $actor, $reason);
            }

            $compensation->eventos()->create([
                'actor_usuario_id' => $actor->id,
                'accion' => $type === EppOperacion::TIPO_ANULACION ? 'OPERACION_ANULADA_COMPENSATORIAMENTE' : 'OPERACION_CORREGIDA_COMPENSATORIAMENTE',
                'origen' => 'WEB',
                'motivo' => $reason,
                'snapshot_json' => ['operacion_origen_id' => $source->id],
            ]);
            $source->eventos()->create([
                'actor_usuario_id' => $actor->id,
                'accion' => 'COMPENSACION_REGISTRADA',
                'origen' => 'WEB',
                'motivo' => $reason,
                'snapshot_json' => ['operacion_compensatoria_id' => $compensation->id, 'tipo' => $type],
            ]);

            return $compensation->load('detalles', 'operacionOrigen');
        }, 3);
    }

    private function normalizeLines(array $lines): Collection
    {
        return collect($lines)->groupBy('detalle_origen_id')->map(function (Collection $group): int {
            $quantity = $group->sum(fn (array $line): int => (int) ($line['cantidad'] ?? 0));
            if ($quantity <= 0 || $quantity > 1000000) {
                throw new InvalidArgumentException('Cada cantidad compensada debe estar entre 1 y 1000000.');
            }

            return $quantity;
        });
    }

    private function remainingReversibleQuantity(EppOperacion $source, EppOperacionDetalle $detail): int
    {
        $already = EppOperacionDetalle::query()
            ->where('detalle_origen_id', $detail->id)
            ->whereHas('operacion', fn ($query) => $query->where('operacion_origen_id', $source->id)->whereIn('tipo', [EppOperacion::TIPO_CORRECCION, EppOperacion::TIPO_ANULACION]))
            ->sum('cantidad_procesada');

        if ($source->tipo === EppOperacion::TIPO_DEVOLUCION) {
            return max(0, $detail->cantidad_procesada - (int) $already);
        }

        $delivery = EppEntrega::query()->where('operacion_detalle_id', $detail->id)->first();
        if ($delivery?->politica_devolucion_snapshot === 'RETORNABLE') {
            return max(0, (int) $delivery->cantidad_pendiente_devolucion);
        }

        return max(0, $detail->cantidad_procesada - (int) $already);
    }

    private function reverseDelivery(EppOperacion $source, EppOperacionDetalle $detail, int $quantity, EppOperacion $compensation, Usuario $actor, string $reason): void
    {
        $delivery = EppEntrega::query()->where('operacion_detalle_id', $detail->id)->lockForUpdate()->firstOrFail();
        if ($delivery->politica_devolucion_snapshot === 'RETORNABLE') {
            $remaining = $delivery->cantidad_pendiente_devolucion - $quantity;
            $delivery->update([
                'cantidad_pendiente_devolucion' => $remaining,
                'estado' => $remaining === 0 ? EppEntrega::ESTADO_ANULADO : EppEntrega::ESTADO_ENTREGADO,
                'cerrado_por_usuario_id' => $remaining === 0 ? $actor->id : $delivery->cerrado_por_usuario_id,
                'observacion' => $this->appendReason($delivery->observacion, $reason),
            ]);
        } elseif ($quantity === $this->remainingReversibleQuantity($source, $detail)) {
            $delivery->update(['estado' => EppEntrega::ESTADO_ANULADO, 'cerrado_por_usuario_id' => $actor->id, 'observacion' => $this->appendReason($delivery->observacion, $reason)]);
        }

        $compensationDetail = $this->copyDetail($detail, $delivery, $quantity, $compensation, $reason);
        $this->changeInventory($detail, $compensationDetail, $quantity, 1, 'DISPONIBLE', $compensation, $actor, $reason);
    }

    private function reverseReturn(EppOperacion $source, EppOperacionDetalle $detail, int $quantity, EppOperacion $compensation, Usuario $actor, string $reason): void
    {
        $delivery = EppEntrega::query()->lockForUpdate()->findOrFail($detail->entrega_origen_id);
        $restored = $delivery->cantidad_pendiente_devolucion + $quantity;
        if ($restored > $delivery->cantidad_original) {
            throw new InvalidArgumentException('La compensacion superaria la cantidad originalmente entregada.');
        }
        if ($detail->destino_inventario === 'BAJA') {
            throw new InvalidArgumentException('Una baja fisica no puede revertirse automaticamente; registra una correccion de inventario separada.');
        }
        $compensationDetail = $this->copyDetail($detail, $delivery, $quantity, $compensation, $reason);
        $this->changeInventory($detail, $compensationDetail, $quantity, -1, $detail->destino_inventario ?: 'DISPONIBLE', $compensation, $actor, $reason);
        $delivery->update(['cantidad_pendiente_devolucion' => $restored, 'estado' => EppEntrega::ESTADO_ENTREGADO, 'devuelto_at' => null, 'cerrado_por_usuario_id' => null, 'observacion' => $this->appendReason($delivery->observacion, $reason)]);
    }

    private function changeInventory(EppOperacionDetalle $sourceDetail, EppOperacionDetalle $compensationDetail, int $quantity, int $direction, string $destination, EppOperacion $compensation, Usuario $actor, string $reason): void
    {
        if (! $sourceDetail->control_inventario_snapshot) {
            return;
        }
        $existence = EppExistencia::query()->lockForUpdate()->findOrFail($sourceDetail->existencia_id);
        $column = $destination === 'CUARENTENA' ? 'cantidad_cuarentena' : 'cantidad_disponible';
        $before = (int) $existence->$column;
        $after = $before + ($direction * $quantity);
        if ($after < 0 || $after > self::MAX_QUANTITY || ($column === 'cantidad_disponible' && $after + $existence->cantidad_reservada > self::MAX_QUANTITY)) {
            throw new InvalidArgumentException('La compensacion dejaria el inventario fuera de rango.');
        }
        EppInventarioMovimiento::query()->create([
            'existencia_id' => $existence->id,
            'almacen_id' => $existence->almacen_id,
            'epp_id' => $existence->epp_id,
            'operacion_id' => $compensation->id,
            'operacion_detalle_id' => $compensationDetail->id,
            'tipo' => EppInventarioMovimiento::TIPO_CORRECCION,
            'cantidad' => $quantity,
            'saldo_anterior' => $before,
            'saldo_posterior' => $after,
            'destino' => $destination,
            'actor_usuario_id' => $actor->id,
            'origen' => 'WEB',
            'motivo' => $reason,
            'snapshot_json' => ['detalle_origen_id' => $sourceDetail->id, 'delta' => $after - $before, 'tipo_compensacion' => $compensation->tipo],
        ]);
        $existence->update([$column => $after]);
    }

    private function copyDetail(EppOperacionDetalle $source, EppEntrega $delivery, int $quantity, EppOperacion $compensation, string $reason): EppOperacionDetalle
    {
        return $compensation->detalles()->create([
            'epp_solicitado_id' => $source->epp_solicitado_id,
            'epp_id' => $source->epp_id,
            'existencia_id' => $source->existencia_id,
            'entrega_origen_id' => $delivery->id,
            'detalle_origen_id' => $source->id,
            'cantidad_solicitada' => $quantity,
            'cantidad_procesada' => $quantity,
            'cantidad_pendiente' => 0,
            'estado' => EppOperacionDetalle::ESTADO_ANULADO,
            'talla' => $source->talla,
            'color' => $source->color,
            'atributos_json' => $source->atributos_json,
            'variante_clave' => $source->variante_clave,
            'politica_devolucion_snapshot' => $source->politica_devolucion_snapshot,
            'control_inventario_snapshot' => $source->control_inventario_snapshot,
            'precio_unitario_snapshot' => $source->precio_unitario_snapshot,
            'condicion_devolucion' => $source->condicion_devolucion,
            'destino_inventario' => $source->destino_inventario,
            'motivo' => $reason,
            'snapshot_json' => ['detalle_origen_id' => $source->id, 'operacion_origen_id' => $source->operacion_id, 'tipo_compensacion' => $compensation->tipo],
        ]);
    }

    private function appendReason(?string $current, string $reason): string
    {
        return trim(collect([$current, 'Compensacion: '.$reason])->filter()->implode("\n"));
    }
}
