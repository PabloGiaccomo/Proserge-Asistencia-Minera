<?php

namespace App\Modules\Epps\Services;

use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Returns responsibility from the existing catalog workflow, without stock movements. */
class EppCatalogReturnService
{
    public function __construct(private readonly EppScopeService $scope) {}

    public function balances(Personal $worker, Usuario $actor): Collection
    {
        $this->authorize($worker, $actor);

        return $this->query($worker)->with(['epp', 'operacionDetalle', 'operacion.aceptacion'])->orderBy('fecha_entrega')->orderBy('id')->get();
    }

    public function prepare(array $input, Usuario $actor): EppOperacion
    {
        $worker = Personal::query()->findOrFail($input['personal_id']);
        $this->authorize($worker, $actor);

        return DB::transaction(function () use ($input, $actor, $worker) {
            $existing = EppOperacion::query()->where('submission_uuid', $input['submission_uuid'])->lockForUpdate()->first();
            if ($existing) {
                if (! $existing->isCatalogWorkflow() || ! ($existing->resumen_json['return_selection'] ?? false)
                    || $existing->registrado_por_usuario_id !== $actor->id
                    || EppCanonicalPayload::json($existing->resumen_json['input']) !== EppCanonicalPayload::json($input)) {
                    throw new InvalidArgumentException('Este envio corresponde a otro movimiento. Abre nuevamente Registrar devolucion.');
                }

                return $existing;
            }
            $ids = collect($input['items'])->pluck('entrega_id');
            if ($ids->isEmpty() || $ids->count() > 50 || $ids->unique()->count() !== $ids->count()) {
                throw new InvalidArgumentException('Selecciona entre 1 y 50 entregas diferentes.');
            }
            $sources = $this->query($worker)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operation = EppOperacion::query()->create(['personal_id' => $worker->id, 'almacen_id' => null, 'tipo' => 'DEVOLUCION', 'estado' => 'LISTO_PARA_FIRMA', 'submission_uuid' => $input['submission_uuid'], 'registrado_por_usuario_id' => $actor->id, 'reserva_expires_at' => now()->addMinutes(30), 'observacion' => $input['observacion'] ?? null]);
            $lines = [];
            foreach ($input['items'] as $item) {
                $source = $sources->get($item['entrega_id']);
                $quantity = (int) $item['cantidad'];
                if (! $source || $quantity < 1 || $quantity > $source->cantidad_pendiente_devolucion || $input['devuelto_at'] < $source->fecha_entrega->toDateString()) {
                    throw new InvalidArgumentException('Revisa el trabajador, la fecha y las cantidades: solo puedes devolver sus saldos retornables vigentes.');
                }
                $signed = collect($source->operacion?->aceptacion?->contenido_json['details'] ?? [])->firstWhere('id', $source->operacion_detalle_id);
                $snapshot = ['real' => $signed['snapshot']['real'] ?? ['id' => $source->epp_id, 'nombre' => $source->epp->nombre, 'codigo' => $source->epp->codigo, 'vida_util_dias' => $source->vida_util_dias_snapshot, 'politica_devolucion' => 'RETORNABLE'],
                    'saldo_anterior' => $source->cantidad_pendiente_devolucion, 'saldo_restante' => $source->cantidad_pendiente_devolucion - $quantity, 'source_snapshot' => $source->getAttributes()];
                $detail = $operation->detalles()->create(['epp_solicitado_id' => $source->epp_id, 'epp_id' => $source->epp_id, 'entrega_origen_id' => $source->id, 'cantidad_solicitada' => $quantity, 'cantidad_procesada' => $quantity, 'cantidad_pendiente' => 0, 'estado' => 'RESERVADO', 'talla' => $source->talla, 'color' => $source->color, 'atributos_json' => $source->atributos_json, 'politica_devolucion_snapshot' => 'RETORNABLE', 'control_inventario_snapshot' => false, 'snapshot_json' => $snapshot]);
                $lines[] = ['id' => $detail->id, 'epp_id' => $source->epp_id, 'requested_quantity' => $quantity, 'processed_quantity' => $quantity, 'pending_quantity' => 0, 'size' => $source->talla, 'color' => $source->color, 'attributes' => collect($source->atributos_json ?? [])->pluck('valor', 'nombre')->all(), 'snapshot' => $snapshot, 'original_delivery_id' => $source->id, 'return_policy' => 'RETORNABLE', 'reason' => $input['observacion'] ?? null];
            }
            $payload = ['schema_version' => 1, 'origin' => 'ENTREGAS_CAMBIOS', 'return_selection' => true, 'operation_id' => $operation->id, 'submission_uuid' => $operation->submission_uuid, 'revision' => 1, 'type' => 'DEVOLUCION', 'declaration_version' => 'RETURN_V1', 'worker' => ['id' => $worker->id, 'name' => $worker->nombre_completo, 'document' => $worker->dni ?: $worker->numero_documento], 'warehouse' => ['id' => null, 'name' => null, 'mine_id' => null], 'input' => $input, 'closing_delivery_id' => null, 'date' => $input['devuelto_at'], 'details' => $lines, 'pending' => [], 'rejected' => []];
            $operation->update(['resumen_json' => $payload, 'resumen_sha256' => hash('sha256', EppCanonicalPayload::json($payload))]);
            $operation->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'DEVOLUCION_PREPARADA', 'origen' => 'WEB', 'snapshot_json' => ['entregas_origen' => $ids->all()]]);

            return $operation;
        }, 3);
    }

    /** Called within signature confirmation, before capture; locks every source in stable order. */
    public function validate(EppOperacion $operation, Collection $details, Usuario $actor): Collection
    {
        if (DB::transactionLevel() === 0 || ! $operation->isCatalogWorkflow() || $operation->tipo !== 'DEVOLUCION') {
            throw new InvalidArgumentException('La devolucion requiere confirmacion firmada.');
        }
        $worker = $operation->personal;
        $this->scope->assertPersonalAccess($actor, $worker);
        $lines = collect($operation->resumen_json['details']);
        $ids = $lines->pluck('original_delivery_id');
        $sources = $this->query($worker)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($ids->count() !== $ids->unique()->count() || $sources->count() !== $lines->count()) {
            throw new InvalidArgumentException('Las entregas seleccionadas cambiaron. Prepara nuevamente la devolucion.');
        }
        foreach ($lines as $line) {
            $source = $sources->get($line['original_delivery_id']);
            $detail = $details->get($line['id']);
            if (! $detail || $detail->estado !== 'RESERVADO' || $detail->entrega_origen_id !== $source->id
                || $detail->epp_id !== $source->epp_id || $detail->politica_devolucion_snapshot !== 'RETORNABLE'
                || $detail->cantidad_procesada < 1 || $detail->cantidad_procesada > $source->cantidad_pendiente_devolucion
                || $detail->cantidad_procesada !== $line['processed_quantity']
                || EppCanonicalPayload::json($source->getAttributes()) !== EppCanonicalPayload::json($line['snapshot']['source_snapshot'])
                || EppCanonicalPayload::json($detail->snapshot_json) !== EppCanonicalPayload::json($line['snapshot'])
                || $detail->talla !== $line['size'] || $detail->color !== $line['color']
                || collect($detail->atributos_json ?? [])->pluck('valor', 'nombre')->all() !== $line['attributes']) {
                throw new InvalidArgumentException('El saldo o las caracteristicas cambiaron. Revisa y firma nuevamente la devolucion.');
            }
        }

        return $sources;
    }

    /** Sources have been validated and locked; acceptance is captured in the same transaction. */
    public function apply(EppOperacion $operation, Collection $details, Collection $sources, Usuario $actor): void
    {
        if (DB::transactionLevel() === 0 || ! $operation->aceptacion) {
            throw new InvalidArgumentException('Falta la aceptacion de la devolucion.');
        }
        foreach ($operation->resumen_json['details'] as $line) {
            $source = $sources->get($line['original_delivery_id']);
            $remaining = $source->cantidad_pendiente_devolucion - $line['processed_quantity'];
            $source->update(['cantidad_pendiente_devolucion' => $remaining, 'estado' => $remaining === 0 ? 'DEVUELTO' : 'ENTREGADO', 'devuelto_at' => $remaining === 0 ? $operation->resumen_json['date'] : null, 'cerrado_por_usuario_id' => $remaining === 0 ? $actor->id : null]);
            $details->get($line['id'])->update(['estado' => 'DEVUELTO']);
        }
    }

    private function query(Personal $worker)
    {
        return EppEntrega::query()->where('personal_id', $worker->id)->where('estado', 'ENTREGADO')
            ->where('politica_devolucion_snapshot', 'RETORNABLE')->where('cantidad_pendiente_devolucion', '>', 0)
            ->where(fn ($query) => $query->whereNull('operacion_id')->orWhereHas('operacion', fn ($op) => $op->whereNull('almacen_id')->where('resumen_json->origin', 'ENTREGAS_CAMBIOS')));
    }

    private function authorize(Personal $worker, Usuario $actor): void
    {
        abort_unless($actor->estado === 'ACTIVO' && PermissionMatrix::userCanDirect($actor, 'epps', 'registrar'), 403);
        $this->scope->assertPersonalAccess($actor, $worker);
    }
}
