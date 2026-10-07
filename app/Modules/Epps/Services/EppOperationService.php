<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppExistencia;
use App\Models\EppOperacion;
use App\Models\EppOperacionDetalle;
use App\Models\EppOperacionEvento;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Modules\Epps\Support\EppReservationBalanceException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EppOperationService
{
    public function __construct(private readonly EppInventoryService $inventory) {}

    public function createDraft(Usuario $actor, Personal $personal, EppAlmacen $warehouse, string $type, string $submissionUuid): EppOperacion
    {
        if (! in_array($type, ['ENTREGA', 'DEVOLUCION'], true) || ! Str::isUuid($submissionUuid)) {
            throw new InvalidArgumentException('Revisa el tipo de operacion y el identificador del envio.');
        }

        return DB::transaction(function () use ($actor, $personal, $warehouse, $type, $submissionUuid): EppOperacion {
            $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($warehouse->id);
            $personal = Personal::query()->findOrFail($personal->id);
            $this->authorize($actor, $personal, $warehouse, $type);
            $operation = EppOperacion::query()->firstOrCreate(['submission_uuid' => $submissionUuid], [
                'personal_id' => $personal->id, 'almacen_id' => $warehouse->id, 'mina_id' => $warehouse->mina_id,
                'tipo' => $type, 'estado' => 'BORRADOR', 'registrado_por_usuario_id' => $actor->id,
            ]);
            if ($operation->personal_id !== $personal->id || $operation->almacen_id !== $warehouse->id || $operation->tipo !== $type || $operation->registrado_por_usuario_id !== $actor->id) {
                throw new InvalidArgumentException('El identificador de envio pertenece a otra operacion.');
            }
            if ($operation->wasRecentlyCreated) {
                $this->event($operation, $actor, 'BORRADOR_CREADO');
            }

            return $operation->load('detalles');
        }, 3);
    }

    public function syncDetails(EppOperacion $operation, array $lines, Usuario $actor): EppOperacion
    {
        if ($lines === [] || count($lines) > 100) {
            throw new InvalidArgumentException('Registra entre 1 y 100 lineas por operacion.');
        }

        return DB::transaction(function () use ($operation, $lines, $actor): EppOperacion {
            $operation = $this->lockedOperation($operation, $actor);
            if ($operation->estado !== 'BORRADOR') {
                throw new InvalidArgumentException('Solo puedes editar una operacion en borrador.');
            }
            $prepared = [];
            $seen = [];
            foreach ($lines as $line) {
                $requested = EppRegistro::query()->findOrFail($line['epp_id'] ?? '');
                $actual = EppRegistro::query()->findOrFail($line['epp_real_id'] ?? $requested->id);
                $attributes = $this->inventory->lineAttributes($line);
                $this->inventory->assertVariant($actual, $attributes, false);
                if ($requested->estado !== 'ACTIVO') {
                    throw new InvalidArgumentException('El EPP solicitado debe estar activo.');
                }
                $quantity = filter_var($line['cantidad_solicitada'] ?? null, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 1 || $quantity > 1000000) {
                    throw new InvalidArgumentException('La cantidad solicitada debe ser entera entre 1 y 1000000.');
                }
                $reason = trim((string) ($line['motivo'] ?? ''));
                if (mb_strlen($reason) > 1000) {
                    throw new InvalidArgumentException('El motivo admite hasta 1000 caracteres.');
                }
                $rejected = $this->flag($line, 'rechazar');
                $substitution = $requested->id !== $actual->id;
                if (($rejected || $substitution) && ($reason === '' || ($substitution && ! $this->flag($line, 'aceptar_sustitucion')))) {
                    throw new InvalidArgumentException('Confirma la sustitucion e indica el motivo de sustitucion o rechazo.');
                }
                $key = $actual->id.':'.$this->inventory->variantKey($attributes);
                if (! $rejected && isset($seen[$key]) && ! $actual->permite_entrega_repetida) {
                    throw new InvalidArgumentException('El EPP no permite repetir la misma variante en el lote.');
                }
                if (! $rejected) {
                    $seen[$key] = true;
                }
                $existenceId = $line['existencia_id'] ?? null;
                if ($existenceId) {
                    $existence = EppExistencia::query()->findOrFail($existenceId);
                    if ($existence->almacen_id !== $operation->almacen_id || $existence->epp_id !== $actual->id || $existence->variante_clave !== $this->inventory->variantKey($attributes)) {
                        throw new InvalidArgumentException('La existencia no corresponde al almacen, EPP y variante de la operacion.');
                    }
                }
                $this->assertReferences($operation, $actual, $line);
                $prepared[] = [
                    'epp_solicitado_id' => $requested->id, 'epp_id' => $actual->id, 'existencia_id' => $existenceId,
                    'entrega_origen_id' => $line['entrega_origen_id'] ?? null, 'detalle_origen_id' => $line['detalle_origen_id'] ?? null,
                    'cantidad_solicitada' => $quantity, 'cantidad_procesada' => 0, 'cantidad_pendiente' => $quantity,
                    'estado' => $rejected ? 'RECHAZADO' : 'PENDIENTE',
                    'talla' => $attributes['TALLA'] ?? null, 'color' => $attributes['COLOR'] ?? null,
                    'atributos_json' => $attributes, 'variante_clave' => $this->inventory->variantKey($attributes),
                    'politica_devolucion_snapshot' => $actual->politica_devolucion,
                    'control_inventario_snapshot' => $actual->control_inventario, 'precio_unitario_snapshot' => $actual->precio_unitario,
                    'condicion_devolucion' => $line['condicion_devolucion'] ?? null, 'destino_inventario' => $line['destino_inventario'] ?? null,
                    'motivo' => $reason ?: null,
                    'snapshot_json' => [
                        'solicitado' => $this->productSnapshot($requested), 'real' => $this->productSnapshot($actual),
                        'atributos' => $attributes, 'aceptar_parcial' => $this->flag($line, 'aceptar_parcial'),
                        'aceptar_sustitucion' => $substitution && $this->flag($line, 'aceptar_sustitucion'),
                    ],
                ];
            }
            // Only unsigned drafts replace their editable lines; events retain the previous revision.
            $operation->detalles()->delete();
            $operation->detalles()->createMany($prepared);
            $operation->update(['version' => $operation->version + 1, 'resumen_json' => null, 'resumen_sha256' => null]);
            $this->event($operation, $actor, 'BORRADOR_ACTUALIZADO', null, ['detalles' => $prepared]);

            return $operation->load('detalles');
        }, 3);
    }

    public function prepareForSignature(EppOperacion $operation, Usuario $actor): EppOperacion
    {
        $this->expireReservations(now());

        return DB::transaction(function () use ($operation, $actor): EppOperacion {
            $operation = $this->lockedOperation($operation, $actor);
            if ($operation->estado === 'LISTO_PARA_FIRMA') {
                return $operation->load('detalles', 'reservas');
            }
            if ($operation->estado !== 'BORRADOR') {
                throw new InvalidArgumentException('La operacion debe estar en borrador para prepararla.');
            }
            $details = $operation->detalles()->orderBy('id')->lockForUpdate()->get();
            if ($details->isEmpty()) {
                throw new InvalidArgumentException('Agrega al menos una linea antes de preparar la firma.');
            }
            $expires = now()->addMinutes(10);
            $seen = [];
            foreach ($details as $detail) {
                if ($detail->estado === 'RECHAZADO') {
                    continue;
                }
                $actual = EppRegistro::query()->lockForUpdate()->findOrFail($detail->epp_id);
                $this->inventory->assertVariant($actual, $detail->atributos_json ?: [], false);
                $key = $actual->id.':'.$detail->variante_clave;
                if (isset($seen[$key]) && ! $actual->permite_entrega_repetida) {
                    throw new InvalidArgumentException('El catalogo ya no permite repetir esta variante. Revisa el borrador.');
                }
                $seen[$key] = true;
                $requested = EppRegistro::query()->findOrFail($detail->epp_solicitado_id);
                if ($requested->estado !== 'ACTIVO') {
                    throw new InvalidArgumentException('El EPP solicitado fue desactivado. Revisa el borrador.');
                }
                $this->assertReferences($operation, $actual, $detail->toArray());
                $snapshot = $detail->snapshot_json;
                $snapshot['real'] = $this->productSnapshot($actual);
                $detail->update(['politica_devolucion_snapshot' => $actual->politica_devolucion, 'control_inventario_snapshot' => $actual->control_inventario, 'precio_unitario_snapshot' => $actual->precio_unitario, 'snapshot_json' => $snapshot]);
                if ($operation->tipo === 'ENTREGA') {
                    if ($actual->politica_devolucion === 'RETORNABLE' && EppEntrega::query()->where('personal_id', $operation->personal_id)->where('epp_id', $actual->id)->where('politica_devolucion_snapshot', 'RETORNABLE')->where('cantidad_pendiente_devolucion', '>', 0)->where('estado', 'ENTREGADO')->exists() && trim((string) $detail->motivo) === '') {
                        throw new InvalidArgumentException('El trabajador tiene un retornable activo. Indica el motivo de la nueva entrega.');
                    }
                    $quantity = $actual->control_inventario
                        ? $this->inventory->reserveDetail($operation, $detail, $actor, $expires, (bool) ($snapshot['aceptar_parcial'] ?? false))
                        : $detail->cantidad_solicitada;
                } else {
                    $delivery = EppEntrega::query()->lockForUpdate()->findOrFail($detail->entrega_origen_id);
                    if ($delivery->politica_devolucion_snapshot !== 'RETORNABLE' || $detail->cantidad_solicitada > $delivery->cantidad_pendiente_devolucion || $delivery->estado !== 'ENTREGADO') {
                        throw new InvalidArgumentException('La devolucion debe corresponder a un saldo retornable vigente.');
                    }
                    $quantity = $detail->cantidad_solicitada;
                }
                $detail->update(['cantidad_procesada' => $quantity, 'cantidad_pendiente' => $detail->cantidad_solicitada - $quantity, 'estado' => $quantity > 0 ? 'RESERVADO' : 'NO_DISPONIBLE']);
            }
            $operation->load('detalles');
            $processed = $operation->detalles->sum('cantidad_procesada');
            $allRejected = $operation->detalles->every(fn ($detail) => $detail->estado === 'RECHAZADO');
            $state = $processed > 0 ? 'LISTO_PARA_FIRMA' : ($allRejected ? 'RECHAZADO' : 'PENDIENTE_STOCK');
            $operation->update(['estado' => $state, 'reserva_expires_at' => $processed > 0 ? $expires : null]);
            $payload = EppCanonicalPayload::normalize($this->payload($operation));
            $operation->update(['resumen_json' => $payload, 'resumen_sha256' => hash('sha256', EppCanonicalPayload::json($payload))]);
            $this->event($operation, $actor, $allRejected ? 'LINEAS_RECHAZADAS' : ($processed > 0 ? 'PREPARADO_PARA_FIRMA' : 'PENDIENTE_STOCK'), null, $payload);

            return $operation->load('detalles', 'reservas');
        }, 3);
    }

    public function reject(EppOperacion $operation, Usuario $actor, string $reason): EppOperacion
    {
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Indica el motivo del rechazo, hasta 1000 caracteres.');
        }

        return DB::transaction(function () use ($operation, $actor, $reason): EppOperacion {
            $operation = $this->lockedOperation($operation, $actor);
            if ($operation->estado === 'RECHAZADO') {
                return $operation->load('detalles', 'reservas');
            }
            if (! in_array($operation->estado, ['BORRADOR', 'LISTO_PARA_FIRMA', 'RESERVADO'], true)) {
                throw new InvalidArgumentException('Esta operacion ya no admite rechazo.');
            }
            $action = $operation->estado === 'BORRADOR' ? 'LINEAS_RECHAZADAS' : 'FIRMA_RECHAZADA';
            $this->inventory->releaseOperationReservations($operation, $actor, 'LIBERADA', now());
            foreach ($operation->detalles()->lockForUpdate()->get() as $detail) {
                $detail->update(['estado' => 'RECHAZADO', 'cantidad_procesada' => 0, 'cantidad_pendiente' => $detail->cantidad_solicitada, 'motivo' => trim($reason)]);
            }
            $operation->update(['estado' => 'RECHAZADO', 'motivo' => trim($reason), 'reserva_expires_at' => null, 'resumen_json' => null, 'resumen_sha256' => null]);
            $this->event($operation, $actor, $action, trim($reason));

            return $operation->load('detalles', 'reservas');
        }, 3);
    }

    public function expireReservations(CarbonInterface $now): int
    {
        $count = 0;
        $ids = EppOperacion::query()->whereIn('estado', ['RESERVADO', 'LISTO_PARA_FIRMA'])->where('reserva_expires_at', '<=', $now)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            try {
                $count += DB::transaction(function () use ($id, $now): int {
                    $operation = EppOperacion::query()->lockForUpdate()->findOrFail($id);
                    if (! in_array($operation->estado, ['RESERVADO', 'LISTO_PARA_FIRMA'], true) || $operation->reserva_expires_at === null || $operation->reserva_expires_at->gt($now)) {
                        return 0;
                    }
                    $released = $this->inventory->releaseOperationReservations($operation, null, 'EXPIRADA', $now);
                    foreach ($operation->detalles()->where('estado', 'RESERVADO')->lockForUpdate()->get() as $detail) {
                        $detail->update(['estado' => 'PENDIENTE', 'cantidad_procesada' => 0, 'cantidad_pendiente' => $detail->cantidad_solicitada]);
                    }
                    $operation->update(['estado' => 'EXPIRADO', 'resumen_json' => null, 'resumen_sha256' => null]);
                    $this->event($operation, null, 'RESERVA_EXPIRADA', 'Vencio el plazo para firmar.', null, 'SYSTEM');

                    return $released;
                }, 3);
            } catch (EppReservationBalanceException $exception) {
                // Keep this batch and its ledger intact; other warehouses must still expire.
                $this->event(EppOperacion::query()->findOrFail($id), null, 'RESERVA_LIBERACION_BLOQUEADA', $exception->getMessage(), $exception->snapshot, 'SYSTEM');
            }
        }

        return $count;
    }

    public function reviewPayload(EppOperacion $operation): array
    {
        $this->expireReservations(now());
        $operation = EppOperacion::query()->findOrFail($operation->id)->load('detalles', 'personal', 'almacen');

        return EppCanonicalPayload::normalize($operation->resumen_json ?: $this->payload($operation));
    }

    private function lockedOperation(EppOperacion $operation, Usuario $actor): EppOperacion
    {
        $operation = EppOperacion::query()->lockForUpdate()->findOrFail($operation->id);
        $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($operation->almacen_id);
        $personal = Personal::query()->findOrFail($operation->personal_id);
        $this->authorize($actor, $personal, $warehouse, $operation->tipo);

        return $operation;
    }

    private function authorize(Usuario $actor, Personal $personal, EppAlmacen $warehouse, string $type): void
    {
        $actor = Usuario::query()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize($type === 'ENTREGA' ? 'prepareDelivery' : 'prepareReturn', [EppOperacion::class, $warehouse, $personal]);
        if ($warehouse->estado !== 'ACTIVO') {
            throw new InvalidArgumentException('El almacen debe estar activo.');
        }
    }

    private function assertReferences(EppOperacion $operation, EppRegistro $actual, array $line): void
    {
        if (! empty($line['detalle_origen_id'])) {
            $origin = EppOperacionDetalle::query()->findOrFail($line['detalle_origen_id']);
            $source = $origin->operacion;
            if ($source === null || $source->personal_id !== $operation->personal_id || $source->almacen_id !== $operation->almacen_id || $origin->epp_solicitado_id !== ($line['epp_solicitado_id'] ?? $line['epp_id']) || ! in_array($source->estado, ['FIRMADO', 'FIRMADO_PARCIAL'], true)) {
                throw new InvalidArgumentException('La linea de origen no corresponde a este trabajador y almacen.');
            }
            if ($origin->cantidad_pendiente < ($line['cantidad_solicitada'] ?? 0)) {
                throw new InvalidArgumentException('La cantidad supera el pendiente de la linea de origen.');
            }
        }
        if ($operation->tipo === 'DEVOLUCION' || ! empty($line['entrega_origen_id'])) {
            $delivery = EppEntrega::query()->findOrFail($line['entrega_origen_id'] ?? '');
            if ($delivery->personal_id !== $operation->personal_id || $delivery->epp_id !== $actual->id || ($delivery->operacion_id && $delivery->operacion?->almacen_id !== $operation->almacen_id)) {
                throw new InvalidArgumentException('La entrega de origen no corresponde a este trabajador, EPP y almacen.');
            }
            if ($operation->tipo === 'DEVOLUCION') {
                $original = $this->inventory->lineAttributes(['atributos' => $delivery->atributos_json ?: [], 'talla' => $delivery->talla, 'color' => $delivery->color]);
                $actualAttributes = $line['atributos_json'] ?? $this->inventory->lineAttributes($line);
                if ($this->inventory->variantKey($original) !== $this->inventory->variantKey($actualAttributes)) {
                    throw new InvalidArgumentException('La variante devuelta debe coincidir con la entrega de origen.');
                }
            }
        }
    }

    private function productSnapshot(EppRegistro $epp): array
    {
        return ['id' => $epp->id, 'codigo' => $epp->codigo, 'nombre' => $epp->nombre, 'politica_devolucion' => $epp->politica_devolucion, 'control_inventario' => $epp->control_inventario, 'precio_unitario' => $epp->precio_unitario, 'vida_util_dias' => $epp->vida_util_dias];
    }

    private function payload(EppOperacion $operation): array
    {
        $operation->loadMissing('personal', 'almacen', 'detalles');
        $details = $operation->detalles->sortBy('id')->map(fn ($detail) => [
            'id' => $detail->id, 'epp_solicitado_id' => $detail->epp_solicitado_id, 'epp_id' => $detail->epp_id,
            'cantidad_solicitada' => $detail->cantidad_solicitada, 'cantidad_procesada' => $detail->cantidad_procesada,
            'cantidad_pendiente' => $detail->cantidad_pendiente, 'estado' => $detail->estado,
            'atributos' => $detail->atributos_json, 'motivo' => $detail->motivo, 'snapshot' => $detail->snapshot_json,
            'entrega_origen_id' => $detail->entrega_origen_id, 'detalle_origen_id' => $detail->detalle_origen_id,
            'condicion_devolucion' => $detail->condicion_devolucion, 'destino_inventario' => $detail->destino_inventario,
        ]);

        return [
            'operacion_id' => $operation->id, 'submission_uuid' => $operation->submission_uuid, 'version' => $operation->version,
            'tipo' => $operation->tipo, 'estado' => $operation->estado,
            'personal' => ['id' => $operation->personal_id, 'nombre' => $operation->personal->nombre_completo, 'documento' => $operation->personal->dni],
            'almacen' => ['id' => $operation->almacen_id, 'nombre' => $operation->almacen->nombre, 'mina_id' => $operation->mina_id],
            'detalles' => $details->filter(fn ($detail) => $detail['cantidad_procesada'] > 0 && $detail['estado'] !== 'RECHAZADO')->values()->all(),
            'pendientes' => $details->filter(fn ($detail) => $detail['cantidad_pendiente'] > 0 && $detail['estado'] !== 'RECHAZADO')->values()->all(),
            'rechazados' => $details->where('estado', 'RECHAZADO')->values()->all(),
        ];
    }

    private function flag(array $line, string $key): bool
    {
        return filter_var($line[$key] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function event(EppOperacion $operation, ?Usuario $actor, string $action, ?string $reason = null, ?array $snapshot = null, string $origin = 'WEB'): void
    {
        EppOperacionEvento::query()->create(['operacion_id' => $operation->id, 'actor_usuario_id' => $actor?->id, 'accion' => $action, 'origen' => $origin, 'motivo' => $reason, 'snapshot_json' => $snapshot]);
    }
}
