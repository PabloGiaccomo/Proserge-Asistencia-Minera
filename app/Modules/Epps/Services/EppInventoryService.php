<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAlmacen;
use App\Models\EppExistencia;
use App\Models\EppInventarioMovimiento;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\EppRegistro;
use App\Models\EppReserva;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppReservationBalanceException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class EppInventoryService
{
    private const MAX_QUANTITY = 4294967295;

    public function variantKey(array $attributes): string
    {
        return hash('sha256', json_encode($this->canonicalAttributes($attributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function canonicalAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $name => $value) {
            if (is_array($value)) {
                $name = $value['nombre'] ?? '';
                $value = $value['valor'] ?? '';
            }
            if (! is_string($name) || ! is_scalar($value)) {
                throw new InvalidArgumentException('Los atributos de la variante deben tener nombre y valor.');
            }
            $name = $this->label($name);
            $value = $this->label((string) $value);
            if ($name !== '' && $value !== '') {
                if (isset($result[$name]) && $result[$name] !== $value) {
                    throw new InvalidArgumentException('La variante contiene atributos duplicados con valores diferentes.');
                }
                $result[$name] = $value;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    public function available(EppAlmacen $warehouse, EppRegistro $epp, array $attributes): ?int
    {
        app(EppOperationService::class)->expireReservations(now());
        $existence = $this->existenceQuery($warehouse, $epp, $attributes)->first();

        return $existence?->conciliado_at === null ? null : $existence->cantidad_disponible;
    }

    public function openStock(EppAlmacen $warehouse, array $lines, Usuario $actor, string $reason): Collection
    {
        $warehouse = EppAlmacen::query()->findOrFail($warehouse->id);
        $this->authorize($warehouse, $actor, 'manageInventory');
        $this->assertReason($reason);
        if ($lines === [] || count($lines) > 100) {
            throw new InvalidArgumentException('Registra entre 1 y 100 variantes por apertura.');
        }

        return DB::transaction(function () use ($warehouse, $lines, $actor, $reason): Collection {
            // Serializes first openings too, where an existence row does not yet exist.
            $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($warehouse->id);
            $this->authorize($warehouse, $actor, 'manageInventory');
            $moves = collect();
            foreach ($lines as $line) {
                $epp = EppRegistro::query()->lockForUpdate()->findOrFail($line['epp_id'] ?? '');
                $attributes = $this->lineAttributes($line);
                $this->assertVariant($epp, $attributes);
                $quantity = filter_var($line['cantidad'] ?? null, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 0 || $quantity > 1000000) {
                    throw new InvalidArgumentException('El conteo debe ser una cantidad entera entre 0 y 1000000.');
                }
                $existence = $this->existenceQuery($warehouse, $epp, $attributes)->lockForUpdate()->first();
                if ($existence?->conciliado_at !== null) {
                    throw new InvalidArgumentException('La variante ya tiene apertura. Registra un ajuste con motivo.');
                }
                if ($existence && ($existence->cantidad_disponible !== 0 || $existence->cantidad_reservada !== 0 || $existence->cantidad_cuarentena !== 0)) {
                    throw new InvalidArgumentException('La existencia sin conciliar contiene saldos. Revisa el historial antes de abrirla.');
                }
                $existence ??= EppExistencia::query()->create([
                    'almacen_id' => $warehouse->id, 'epp_id' => $epp->id,
                    'variante_clave' => $this->variantKey($attributes), 'atributos_json' => $attributes,
                    'cantidad_disponible' => 0, 'cantidad_reservada' => 0, 'cantidad_cuarentena' => 0,
                ]);
                $existence = EppExistencia::query()->lockForUpdate()->findOrFail($existence->id);
                $moves->push($this->record($existence, $quantity, 'APERTURA', $actor, $reason));
                $existence->update(['cantidad_disponible' => $quantity, 'conciliado_at' => now()]);
            }

            return $moves;
        }, 3);
    }

    public function adjust(EppAlmacen $warehouse, EppRegistro $epp, array $attributes, int $delta, Usuario $actor, string $reason): EppInventarioMovimiento
    {
        $warehouse = EppAlmacen::query()->findOrFail($warehouse->id);
        $this->authorize($warehouse, $actor, 'adjustInventory');
        $this->assertReason($reason);
        if ($delta === 0 || abs($delta) > 1000000) {
            throw new InvalidArgumentException('Indica una diferencia entre -1000000 y 1000000 distinta de cero.');
        }

        return DB::transaction(function () use ($warehouse, $epp, $attributes, $delta, $actor, $reason): EppInventarioMovimiento {
            $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($warehouse->id);
            $this->authorize($warehouse, $actor, 'adjustInventory');
            $existence = $this->existenceQuery($warehouse, $epp, $attributes)->lockForUpdate()->first();
            if ($existence?->conciliado_at === null) {
                throw new InvalidArgumentException('Registra y confirma la apertura de esta variante antes de ajustar.');
            }
            $after = $existence->cantidad_disponible + $delta;
            if ($after < 0 || $after + $existence->cantidad_reservada > self::MAX_QUANTITY) {
                throw new InvalidArgumentException('El ajuste no puede dejar un saldo negativo o fuera de rango, incluyendo las unidades reservadas.');
            }
            $move = $this->record($existence, $after, 'AJUSTE', $actor, $reason);
            $existence->update(['cantidad_disponible' => $after]);

            return $move;
        }, 3);
    }

    public function lineAttributes(array $line): array
    {
        $attributes = $line['atributos'] ?? [];
        foreach (['talla', 'color', 'serie'] as $name) {
            if (filled($line[$name] ?? null)) {
                $attributes[$name] = $line[$name];
            }
        }

        return $this->canonicalAttributes($attributes);
    }

    public function assertVariant(EppRegistro $epp, array $attributes, bool $requireInventory = true): void
    {
        $attributes = $this->canonicalAttributes($attributes);
        if ($epp->estado !== 'ACTIVO' || ($requireInventory && ! $epp->control_inventario)) {
            throw new InvalidArgumentException('El EPP debe estar activo y tener control de inventario.');
        }
        $required = [];
        if ($epp->requiere_talla) {
            $required['TALLA'] = $epp->tallas ?: [];
        }
        if ($epp->requiere_color) {
            $required['COLOR'] = $epp->colores ?: [];
        }
        foreach ($epp->otros_atributos ?: [] as $attribute) {
            $required[$this->label($attribute['nombre'])] = $attribute['valores'];
        }
        if ($epp->requiere_serie) {
            $required['SERIE'] = null;
        }
        foreach ($required as $name => $options) {
            if (! isset($attributes[$name]) || ($options !== null && ! in_array($attributes[$name], array_map(fn ($v) => $this->label($v), $options), true))) {
                throw new InvalidArgumentException('Revisa el atributo '.$name.' de '.$epp->nombre.'.');
            }
        }
        if (array_diff(array_keys($attributes), array_keys($required)) !== []) {
            throw new InvalidArgumentException('La variante tiene atributos que no corresponden al catalogo.');
        }
    }

    /** The caller holds the operation and warehouse locks for the entire batch. */
    public function reserveDetail(EppOperacion $operation, EppOperacionDetalle $detail, Usuario $actor, CarbonInterface $expires, bool $acceptPartial): int
    {
        if (DB::transactionLevel() === 0 || $detail->operacion_id !== $operation->id || ! $detail->control_inventario_snapshot) {
            throw new InvalidArgumentException('La reserva requiere una linea controlada y una transaccion de operacion.');
        }
        $existence = EppExistencia::query()->where('almacen_id', $operation->almacen_id)->where('epp_id', $detail->epp_id)->where('variante_clave', $detail->variante_clave)->lockForUpdate()->first();
        $snapshot = $detail->snapshot_json;
        $snapshot['disponibilidad'] = $existence?->conciliado_at === null ? 'STOCK_NO_CONCILIADO' : 'CONCILIADO';
        $detail->update(['existencia_id' => $existence?->id, 'snapshot_json' => $snapshot]);
        if ($existence?->conciliado_at === null) {
            return 0;
        }
        $quantity = min($detail->cantidad_solicitada, $existence->cantidad_disponible);
        if ($quantity <= 0 || (! $acceptPartial && $quantity < $detail->cantidad_solicitada)) {
            return 0;
        }
        $after = $existence->cantidad_disponible - $quantity;
        $this->reservationMovement($existence, $operation, $detail->id, $quantity, $after, 'RESERVA', $actor, 'Stock reservado para firma.', 'WEB');
        $existence->update(['cantidad_disponible' => $after, 'cantidad_reservada' => $existence->cantidad_reservada + $quantity]);
        EppReserva::query()->create(['existencia_id' => $existence->id, 'operacion_id' => $operation->id, 'operacion_detalle_id' => $detail->id, 'cantidad' => $quantity, 'estado' => 'ACTIVA', 'expires_at' => $expires]);

        return $quantity;
    }

    /** Must run after locking the operation, before locking its existence and reservation rows. */
    public function releaseOperationReservations(EppOperacion $operation, ?Usuario $actor, string $state, CarbonInterface $releasedAt): int
    {
        if (DB::transactionLevel() === 0 || ! in_array($state, ['LIBERADA', 'EXPIRADA'], true)) {
            throw new InvalidArgumentException('La liberacion requiere una transaccion y un estado valido.');
        }
        EppAlmacen::query()->lockForUpdate()->findOrFail($operation->almacen_id);
        $count = 0;
        $reservations = EppReserva::query()->where('operacion_id', $operation->id)->where('estado', 'ACTIVA')->orderBy('existencia_id')->orderBy('id')->get();
        foreach ($reservations as $candidate) {
            $existence = EppExistencia::query()->lockForUpdate()->findOrFail($candidate->existencia_id);
            $reservation = EppReserva::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($reservation->estado !== 'ACTIVA') {
                continue;
            }
            $snapshot = ['existencia_id' => $existence->id, 'reserva_id' => $reservation->id,
                'cantidad_disponible' => $existence->cantidad_disponible, 'cantidad_reservada' => $existence->cantidad_reservada,
                'cantidad_liberar' => $reservation->cantidad];
            if ($existence->cantidad_reservada < $reservation->cantidad) {
                throw new EppReservationBalanceException('El saldo reservado no coincide con la reserva. Revisa el inventario.', $snapshot);
            }
            $after = $existence->cantidad_disponible + $reservation->cantidad;
            if ($after > self::MAX_QUANTITY) {
                throw new EppReservationBalanceException('La liberacion dejaria el saldo disponible fuera de rango. Revisa el inventario.', $snapshot);
            }
            $this->reservationMovement($existence, $operation, $reservation->operacion_detalle_id, $reservation->cantidad, $after, 'LIBERACION', $actor, $state === 'EXPIRADA' ? 'Reserva vencida sin firma.' : 'Reserva liberada por rechazo.', $actor ? 'WEB' : 'SYSTEM');
            $existence->update(['cantidad_disponible' => $after, 'cantidad_reservada' => $existence->cantidad_reservada - $reservation->cantidad]);
            $reservation->update(['estado' => $state, 'liberada_at' => $releasedAt]);
            $count++;
        }

        return $count;
    }

    private function reservationMovement(EppExistencia $existence, EppOperacion $operation, string $detailId, int $quantity, int $after, string $type, ?Usuario $actor, string $reason, string $origin): void
    {
        EppInventarioMovimiento::query()->create([
            'existencia_id' => $existence->id, 'almacen_id' => $existence->almacen_id, 'epp_id' => $existence->epp_id,
            'operacion_id' => $operation->id, 'operacion_detalle_id' => $detailId, 'tipo' => $type,
            'cantidad' => $quantity, 'saldo_anterior' => $existence->cantidad_disponible, 'saldo_posterior' => $after,
            'destino' => 'DISPONIBLE', 'actor_usuario_id' => $actor?->id, 'origen' => $origin, 'motivo' => $reason,
            'snapshot_json' => ['atributos' => $existence->atributos_json, 'delta' => $after - $existence->cantidad_disponible, 'reservada_anterior' => $existence->cantidad_reservada, 'reservada_posterior' => $existence->cantidad_reservada + ($type === 'RESERVA' ? $quantity : -$quantity)],
        ]);
    }

    private function authorize(EppAlmacen $warehouse, Usuario $actor, string $ability): void
    {
        Gate::forUser($actor)->authorize($ability, [EppOperacion::class, $warehouse]);
        if ($warehouse->estado !== 'ACTIVO') {
            throw new InvalidArgumentException('El almacen debe estar activo.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Indica un motivo de hasta 1000 caracteres.');
        }
    }

    private function existenceQuery(EppAlmacen $warehouse, EppRegistro $epp, array $attributes): Builder
    {
        return EppExistencia::query()->where('almacen_id', $warehouse->id)->where('epp_id', $epp->id)->where('variante_clave', $this->variantKey($attributes));
    }

    private function record(EppExistencia $existence, int $after, string $type, Usuario $actor, string $reason): EppInventarioMovimiento
    {
        return EppInventarioMovimiento::query()->create([
            'existencia_id' => $existence->id, 'almacen_id' => $existence->almacen_id, 'epp_id' => $existence->epp_id,
            'tipo' => $type, 'cantidad' => abs($after - $existence->cantidad_disponible),
            'saldo_anterior' => $existence->cantidad_disponible, 'saldo_posterior' => $after,
            'destino' => 'DISPONIBLE', 'actor_usuario_id' => $actor->id, 'origen' => 'WEB', 'motivo' => trim($reason),
            'snapshot_json' => ['atributos' => $existence->atributos_json, 'delta' => $after - $existence->cantidad_disponible],
        ]);
    }

    private function label(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim($value), 'UTF-8')) ?: '';
    }
}
