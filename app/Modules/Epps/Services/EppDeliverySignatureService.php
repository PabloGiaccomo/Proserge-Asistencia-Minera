<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAceptacion;
use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Modules\Epps\Support\EppDeliveryDeclaration;
use App\Modules\Epps\Support\EppSignaturePayload;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** Adds acceptance to the existing catalog workflow, without inventing inventory. */
class EppDeliverySignatureService
{
    public function __construct(private readonly EppService $deliveries, private readonly EppScopeService $scope, private readonly EppAcceptanceService $acceptances, private readonly EppCatalogReturnService $returns) {}

    public function prepare(array $input, Usuario $actor, ?EppEntrega $closing = null): EppOperacion
    {
        abort_unless(PermissionMatrix::userCanDirect($actor, 'epps', 'registrar'), 403);
        $worker = Personal::query()->findOrFail($closing?->personal_id ?? $input['personal_id']);
        $this->scope->assertPersonalAccess($actor, $worker);
        $uuid = $input['submission_uuid'] ?? (string) Str::uuid();

        return DB::transaction(function () use ($input, $actor, $worker, $uuid, $closing): EppOperacion {
            $existing = EppOperacion::query()->where('submission_uuid', $uuid)->lockForUpdate()->first();
            if ($existing) {
                if (! $existing->isCatalogWorkflow() || $existing->personal_id !== $worker->id || $existing->registrado_por_usuario_id !== $actor->id
                    || EppCanonicalPayload::json($existing->resumen_json['input'] ?? []) !== EppCanonicalPayload::json($input)) {
                    throw new InvalidArgumentException('El envio ya corresponde a otra entrega. Vuelve a abrir el formulario.');
                }

                return $existing;
            }
            $originalId = $closing?->id ?? ($input['_epp_replaces_entrega_id'] ?? null);
            $original = null;
            if ($originalId) {
                $original = EppEntrega::query()->lockForUpdate()->findOrFail($originalId);
                $this->assertOriginal($original, $worker->id);
            }
            $lines = $closing ? [$input] : ($input['items'] ?? [$input]);
            if (count($lines) < 1 || count($lines) > 50 || ($original && count($lines) !== 1)) {
                throw new InvalidArgumentException('Agrega entre 1 y 50 articulos. Un cambio de EPP admite un solo reemplazo.');
            }
            $operation = EppOperacion::query()->create(['personal_id' => $worker->id, 'almacen_id' => null, 'tipo' => $closing ? 'DEVOLUCION' : 'ENTREGA', 'estado' => 'LISTO_PARA_FIRMA', 'submission_uuid' => $uuid, 'registrado_por_usuario_id' => $actor->id, 'reserva_expires_at' => now()->addMinutes(30), 'observacion' => $input['observacion'] ?? null]);
            $details = [];
            foreach ($lines as $line) {
                $deliveryInput = isset($input['items']) ? array_merge(['fecha_entrega' => $input['fecha_entrega']], $line, ['personal_id' => $worker->id]) : $input;
                unset($deliveryInput['items']);
                $details[] = $this->prepareLine($operation, $deliveryInput, $closing ? $original : null, $originalId);
            }
            $payload = ['schema_version' => 1, 'origin' => 'ENTREGAS_CAMBIOS', 'operation_id' => $operation->id, 'submission_uuid' => $uuid, 'revision' => 1, 'type' => $operation->tipo, 'declaration_version' => $closing ? 'RETURN_V1' : 'DELIVERY_V1', 'worker' => ['id' => $worker->id, 'name' => $worker->nombre_completo, 'document' => $worker->dni ?: $worker->numero_documento], 'warehouse' => ['id' => null, 'name' => null, 'mine_id' => null], 'input' => $input, 'closing_delivery_id' => $closing?->id, 'date' => $input['fecha_entrega'] ?? $input['devuelto_at'], 'details' => $details, 'pending' => [], 'rejected' => []];
            $payload['original_snapshot'] = $original?->getAttributes();
            if (! $closing) {
                $payload['declaration_version'] = EppDeliveryDeclaration::VERSION;
                $payload['declaration_text'] = EppDeliveryDeclaration::TEXT;
            }
            if ($closing && ($input['estado'] ?? '') !== 'DEVUELTO') {
                $payload['declaration_version'] = 'MOVEMENT_V1';
            }
            $operation->update(['resumen_json' => $payload, 'resumen_sha256' => hash('sha256', EppCanonicalPayload::json($payload))]);
            $operation->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'ENTREGA_PREPARADA', 'origen' => 'WEB', 'snapshot_json' => ['flujo' => 'ENTREGAS_CAMBIOS']]);

            return $operation;
        }, 3);
    }

    public function confirm(EppOperacion $operation, EppSignaturePayload $signature, Usuario $actor): EppOperacion
    {
        if (! $operation->isCatalogWorkflow() || $signature->data['tipo_firmante'] !== 'TRABAJADOR') {
            throw new InvalidArgumentException('Esta firma no corresponde al formulario de entregas y cambios.');
        }
        $this->acceptances->signerContext($operation, $signature, $actor);
        $bytes = $signature->pngBytes();
        // One exclusively owned file per HTTP execution, outside DB retry loops.
        $path = 'epp/firmas/'.$operation->id.'/'.Str::uuid().'.png';
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new InvalidArgumentException('No se pudo guardar la firma. No se registro entrega.');
        }
        try {
            $confirmed = DB::transaction(function () use ($operation, $signature, $actor, $bytes, $path): EppOperacion {
                $operation = EppOperacion::query()->lockForUpdate()->findOrFail($operation->id);
                $payload = $operation->resumen_json;
                $this->acceptances->signerContext($operation, $signature, $actor);
                if ($operation->submission_uuid !== $signature->data['submission_uuid'] || $operation->personal_id !== $signature->data['personal_id'] || $operation->version !== (int) $signature->data['review_version']
                    || ! hash_equals($operation->resumen_sha256, $signature->data['review_hash']) || ! hash_equals($operation->resumen_sha256, hash('sha256', EppCanonicalPayload::json($payload)))) {
                    throw new InvalidArgumentException('El resumen cambio. Revisa y firma nuevamente.');
                }
                if ($operation->aceptacion) {
                    if ($operation->estado !== 'FIRMADO' || ! hash_equals($operation->aceptacion->firma_sha256, hash('sha256', $bytes))) {
                        throw new InvalidArgumentException('La operacion ya tiene otra firma registrada.');
                    }

                    return $operation;
                }
                if ($operation->estado !== 'LISTO_PARA_FIRMA' || $operation->reserva_expires_at?->lte(now())) {
                    throw new InvalidArgumentException('La revision vencio. Abre nuevamente Registrar entrega.');
                }
                $input = $payload['input'];
                $worker = Personal::query()->lockForUpdate()->findOrFail($operation->personal_id);
                if ($worker->nombre_completo !== $payload['worker']['name'] || ($worker->dni ?: $worker->numero_documento) !== $payload['worker']['document']) {
                    throw new InvalidArgumentException('La identidad del trabajador cambio. Revisa nuevamente.');
                }
                $details = $operation->detalles()->get()->keyBy('id');
                $products = EppRegistro::query()->whereIn('id', $details->pluck('epp_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($details->count() !== count($payload['details'])) {
                    throw new InvalidArgumentException('Los articulos del resumen cambiaron. Revisa nuevamente.');
                }
                foreach ($payload['details'] as $line) {
                    $detail = $details->get($line['id']);
                    $product = $detail ? $products->get($detail->epp_id) : null;
                    if (! $product || $detail->epp_id !== $line['epp_id'] || (int) $detail->cantidad_procesada !== (int) $line['processed_quantity']) {
                        throw new InvalidArgumentException('Los articulos del resumen cambiaron. Revisa nuevamente.');
                    }
                    if (! $payload['closing_delivery_id'] && ! ($payload['return_selection'] ?? false)) {
                        $configuration = $line['catalog_configuration'] ?? $payload['catalog_configuration'] ?? null;
                        $deliveryInput = $line['delivery_input'] ?? $input;
                        if (($configuration && EppCanonicalPayload::json($configuration) !== EppCanonicalPayload::json($this->catalogConfiguration($product)))
                            || ($product->requiere_talla && ! $detail->talla) || ($product->requiere_color && ! $detail->color) || count($detail->atributos_json ?? []) !== count($product->otros_atributos ?? [])
                            || $product->estado !== 'ACTIVO' || $product->nombre !== $line['snapshot']['real']['nombre'] || (int) $product->vida_util_dias !== $line['snapshot']['real']['vida_util_dias'] || $product->politica_devolucion !== $detail->politica_devolucion_snapshot
                            || $this->deliveries->normalizeDeliveryAttributes($product, $deliveryInput) !== ['talla' => $detail->talla, 'color' => $detail->color, 'atributos_json' => $detail->atributos_json]) {
                            throw new InvalidArgumentException('El item o sus caracteristicas cambiaron. Prepara nuevamente la entrega.');
                        }
                    }
                }
                $originalId = $payload['closing_delivery_id'] ?? ($input['_epp_replaces_entrega_id'] ?? null);
                $original = $originalId ? EppEntrega::query()->lockForUpdate()->findOrFail($originalId) : null;
                if ($original) {
                    $this->assertOriginal($original, $operation->personal_id);
                    if (EppCanonicalPayload::json($original->getAttributes()) !== EppCanonicalPayload::json($payload['original_snapshot'] ?? [])) {
                        throw new InvalidArgumentException('La entrega anterior cambio. Revisa nuevamente antes de firmar.');
                    }
                }
                $returnSources = ($payload['return_selection'] ?? false) ? $this->returns->validate($operation, $details, $actor) : null;
                $acceptance = $this->acceptances->capture($operation, $signature->withPreparedSignature($path, hash('sha256', $bytes)), $actor);
                if ($returnSources !== null) {
                    $operation->unsetRelation('aceptacion');
                    $this->returns->apply($operation, $details, $returnSources, $actor);
                } elseif ($payload['closing_delivery_id']) {
                    $this->deliveries->closeEntrega($original->id, $input, $actor);
                    $original->refresh()->update(['cantidad_pendiente_devolucion' => 0]);
                } else {
                    foreach ($payload['details'] as $line) {
                        $detail = $details->get($line['id']);
                        $deliveryInput = $line['delivery_input'] ?? $input;
                        $delivery = $original ? $this->deliveries->replaceEntrega($original->id, $deliveryInput, ['devuelto_at' => $input['_epp_replacement_fecha'] ?? $input['fecha_entrega'], 'motivo_cambio' => $input['_epp_replacement_motivo'] ?? null, 'observacion' => $input['_epp_replacement_observacion'] ?? null], $actor) : $this->deliveries->deliver($deliveryInput, $actor);
                        $returnable = $detail->politica_devolucion_snapshot === 'RETORNABLE';
                        $delivery->update(['operacion_id' => $operation->id, 'operacion_detalle_id' => $detail->id, 'cantidad_original' => $delivery->cantidad, 'cantidad_pendiente_devolucion' => $returnable ? $delivery->cantidad : 0, 'politica_devolucion_snapshot' => $detail->politica_devolucion_snapshot, 'es_legacy' => false, 'estado_integridad' => 'FIRMA_VALIDA', 'estado' => $returnable ? 'ENTREGADO' : 'CONSUMIDO']);
                    }
                    $original?->update(['cantidad_pendiente_devolucion' => 0]);
                }
                $operation->update(['estado' => 'FIRMADO', 'firmado_at' => $acceptance->firmado_at, 'confirmado_por_usuario_id' => $actor->id]);
                $operation->eventos()->create(['actor_usuario_id' => $actor->id, 'accion' => 'OPERACION_FIRMADA', 'origen' => 'WEB', 'snapshot_json' => ['aceptacion_id' => $acceptance->id, 'contenido_sha256' => $acceptance->contenido_sha256, 'entrega_origen_id' => $originalId]]);

                return $operation;
            }, 3);
        } catch (Throwable $exception) {
            if ($path && ! EppAceptacion::query()->where('firma_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        $confirmed->load('aceptacion');
        if ($confirmed->aceptacion->firma_path !== $path) {
            Storage::disk('local')->delete($path);
        }

        return $confirmed->withEvidenceVerification($this->acceptances->verify($confirmed->aceptacion));
    }

    private function prepareLine(EppOperacion $operation, array $input, ?EppEntrega $closing, ?string $originalId): array
    {
        $product = EppRegistro::query()->findOrFail($closing?->epp_id ?? $input['epp_id']);
        if (! $closing && $product->estado !== 'ACTIVO') {
            throw new InvalidArgumentException('El item ya no esta activo en Identificacion de items.');
        }
        $attributes = $closing ? ['talla' => $closing->talla, 'color' => $closing->color, 'atributos_json' => $closing->atributos_json] : $this->deliveries->normalizeDeliveryAttributes($product, $input);
        if (! $closing && (($product->requiere_talla && ! $attributes['talla']) || ($product->requiere_color && ! $attributes['color']) || count($attributes['atributos_json'] ?? []) !== count($product->otros_atributos ?? []))) {
            throw new InvalidArgumentException('Selecciona las caracteristicas del item configuradas en Identificacion de items.');
        }
        $snapshot = ['id' => $product->id, 'nombre' => $product->nombre, 'codigo' => $product->codigo, 'vida_util_dias' => (int) $product->vida_util_dias, 'politica_devolucion' => $product->politica_devolucion];
        if ($closing) {
            $signedLine = collect(data_get($closing->operacion?->aceptacion?->contenido_json, 'details', []))->firstWhere('id', $closing->operacion_detalle_id);
            $snapshot = $signedLine['snapshot']['real'] ?? $snapshot;
            $snapshot['vida_util_dias'] = (int) $closing->vida_util_dias_snapshot;
            $snapshot['politica_devolucion'] = $closing->politica_devolucion_snapshot ?: $product->politica_devolucion;
        }
        $quantity = $closing ? ($closing->politica_devolucion_snapshot === 'RETORNABLE' ? $closing->cantidad_pendiente_devolucion : $closing->cantidad) : $input['cantidad'];
        $detail = $operation->detalles()->create(['epp_solicitado_id' => $product->id, 'epp_id' => $product->id, 'entrega_origen_id' => $originalId, 'cantidad_solicitada' => $quantity, 'cantidad_procesada' => $quantity, 'cantidad_pendiente' => 0, 'estado' => 'RESERVADO', 'talla' => $attributes['talla'], 'color' => $attributes['color'], 'atributos_json' => $attributes['atributos_json'], 'politica_devolucion_snapshot' => $snapshot['politica_devolucion'], 'control_inventario_snapshot' => false, 'snapshot_json' => ['real' => $snapshot, 'solicitado' => $snapshot]]);

        return ['id' => $detail->id, 'epp_id' => $product->id, 'requested_quantity' => $quantity, 'processed_quantity' => $quantity, 'pending_quantity' => 0, 'size' => $attributes['talla'], 'color' => $attributes['color'], 'attributes' => collect($attributes['atributos_json'] ?? [])->pluck('valor', 'nombre')->all(), 'snapshot' => $detail->snapshot_json, 'original_delivery_id' => $originalId, 'return_policy' => $snapshot['politica_devolucion'], 'reason' => $input['observacion'] ?? $input['motivo_cambio'] ?? null, 'delivery_input' => $input, 'catalog_configuration' => $this->catalogConfiguration($product)];
    }

    private function catalogConfiguration(EppRegistro $product): array
    {
        return $product->only(['nombre', 'codigo', 'vida_util_dias', 'politica_devolucion', 'requiere_talla', 'requiere_color', 'tallas', 'colores', 'otros_atributos', 'estado']);
    }

    private function assertOriginal(EppEntrega $original, string $workerId): void
    {
        if ($original->personal_id !== $workerId || $original->estado !== 'ENTREGADO' || ($original->operacion_id && ! $original->operacion?->isCatalogWorkflow())) {
            throw new InvalidArgumentException('La entrega de origen no esta disponible para este movimiento.');
        }
    }
}
