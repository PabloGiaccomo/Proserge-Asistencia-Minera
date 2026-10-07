<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAceptacion;
use App\Models\EppAlmacen;
use App\Models\EppEntrega;
use App\Models\EppOperacion;
use App\Models\EppRegistro;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Modules\Epps\Support\EppSignaturePayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EppOperationConfirmationService
{
    public function __construct(
        private readonly EppAcceptanceService $acceptance,
        private readonly EppInventoryService $inventory,
        private readonly EppOperationService $operations,
    ) {}

    public function confirm(EppOperacion $operation, EppSignaturePayload $signature, Usuario $actor): EppOperacion
    {
        if ($signature->data['tipo_firmante'] !== 'TRABAJADOR') {
            throw new InvalidArgumentException('La firma de testigo requiere el flujo de aprobacion excepcional.');
        }

        return $this->confirmOperation($operation, $signature, $actor);
    }

    public function confirmWithWitness(EppOperacion $operation, EppSignaturePayload $signature, Usuario $approver): EppOperacion
    {
        if ($signature->data['tipo_firmante'] !== 'TESTIGO_AUTORIZADO') {
            throw new InvalidArgumentException('Identifica al testigo autorizado para este flujo.');
        }

        return $this->confirmOperation($operation, $signature, $approver);
    }

    private function confirmOperation(EppOperacion $operation, EppSignaturePayload $signature, Usuario $actor): EppOperacion
    {
        $operation = EppOperacion::query()->findOrFail($operation->id)->load('personal', 'almacen');
        $this->assertSubmission($operation, $signature);
        $this->acceptance->signerContext($operation, $signature, $actor);
        $existing = $operation->aceptacion()->first();
        if ($existing !== null) {
            return $this->retry($operation, $signature, $existing, $actor);
        }
        $this->assertReady($operation, $signature);
        // Each execution owns an unpredictable, exclusively created file. Competing
        // requests can never overwrite or clean up the winner's signed evidence.
        $prepared = $this->writeSignature($operation, $signature);
        try {
            $confirmed = DB::transaction(function () use ($operation, $prepared, $actor): EppOperacion {
                $locked = EppOperacion::query()->lockForUpdate()->findOrFail($operation->id);
                $warehouse = EppAlmacen::query()->lockForUpdate()->findOrFail($locked->almacen_id);
                $locked->setRelation('almacen', $warehouse)->load('personal');
                $this->assertSubmission($locked, $prepared);
                $this->acceptance->signerContext($locked, $prepared, $actor);
                $existing = $locked->aceptacion()->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->retry($locked, $prepared, $existing, $actor, false);
                }
                $this->assertReady($locked, $prepared);
                $details = $locked->detalles()->orderBy('id')->lockForUpdate()->get();
                $locked->setRelation('detalles', $details);
                $current = $this->operations->currentReviewPayload($locked);
                if (! hash_equals($locked->resumen_sha256, hash('sha256', EppCanonicalPayload::json($current)))) {
                    throw new InvalidArgumentException('El contenido cambio desde la revision. Prepara y revisa nuevamente.');
                }
                $products = EppRegistro::query()->whereIn('id', $details->pluck('epp_id')->merge($details->pluck('epp_solicitado_id'))->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($details as $detail) {
                    if ($detail->cantidad_procesada <= 0 || $detail->estado === 'RECHAZADO') {
                        continue;
                    }
                    if ($locked->tipo === 'DEVOLUCION') {
                        // Returns match the source delivery snapshot, including retired catalog variants.
                        continue;
                    }
                    $product = $products->get($detail->epp_id);
                    $requested = $products->get($detail->epp_solicitado_id);
                    if ($product === null || $requested?->estado !== 'ACTIVO' || $detail->estado !== 'RESERVADO'
                        || $product->politica_devolucion !== $detail->politica_devolucion_snapshot
                        || $product->control_inventario !== $detail->control_inventario_snapshot) {
                        throw new InvalidArgumentException('El catalogo o la linea cambio. Revisa nuevamente la operacion.');
                    }
                    $this->inventory->assertVariant($product, $detail->atributos_json ?: [], false);
                }
                $operator = $prepared->data['tipo_firmante'] === 'TESTIGO_AUTORIZADO'
                    ? Usuario::query()->findOrFail($locked->registrado_por_usuario_id) : $actor;
                if ($locked->tipo === 'DEVOLUCION') {
                    app(EppBalanceService::class)->validateBatch($locked, $details, 'cantidad_procesada', true);
                } else {
                    $this->inventory->consumeOperationReservations($locked, $operator, now());
                }
                $acceptance = $this->acceptance->capture($locked, $prepared, $actor);
                foreach ($details as $detail) {
                    if ($detail->cantidad_procesada <= 0 || $detail->estado === 'RECHAZADO') {
                        continue;
                    }
                    if ($locked->tipo === 'DEVOLUCION') {
                        app(EppBalanceService::class)->applyReturn($detail, $operator);

                        continue;
                    }
                    $returnable = $detail->politica_devolucion_snapshot === 'RETORNABLE';
                    $life = (int) ($detail->snapshot_json['real']['vida_util_dias'] ?? 0);
                    $date = $acceptance->firmado_at->copy()->startOfDay();
                    $locked->entregas()->create([
                        'id' => (string) Str::uuid(), 'personal_id' => $locked->personal_id, 'epp_id' => $detail->epp_id,
                        'operacion_detalle_id' => $detail->id, 'cantidad' => $detail->cantidad_procesada,
                        'cantidad_original' => $detail->cantidad_procesada, 'cantidad_pendiente_devolucion' => $returnable ? $detail->cantidad_procesada : 0,
                        'talla' => $detail->talla, 'color' => $detail->color, 'atributos_json' => $detail->atributos_json,
                        'fecha_entrega' => $date, 'vida_util_dias_snapshot' => $life,
                        'fecha_vencimiento_calendario' => $life > 0 ? $date->copy()->addDays($life) : null,
                        'estado' => $returnable ? EppEntrega::ESTADO_ENTREGADO : EppEntrega::ESTADO_CONSUMIDO,
                        'politica_devolucion_snapshot' => $detail->politica_devolucion_snapshot,
                        'es_legacy' => false, 'estado_integridad' => EppAceptacion::INTEGRIDAD_VALIDA,
                        'registrado_por_usuario_id' => $operator->id, 'observacion' => $detail->motivo,
                    ]);
                    $detail->update(['estado' => $returnable ? 'ENTREGADO' : 'CONSUMIDO']);
                }
                $partial = $details->contains(fn ($detail) => $detail->cantidad_pendiente > 0);
                $locked->update(['estado' => $partial ? 'FIRMADO_PARCIAL' : 'FIRMADO', 'firmado_at' => $acceptance->firmado_at, 'confirmado_por_usuario_id' => $actor->id]);
                $locked->eventos()->create([
                    'actor_usuario_id' => $actor->id, 'accion' => 'OPERACION_FIRMADA', 'origen' => 'WEB',
                    'motivo' => $acceptance->motivo_excepcion,
                    'snapshot_json' => ['aceptacion_id' => $acceptance->id, 'contenido_sha256' => $acceptance->contenido_sha256, 'tipo_firmante' => $acceptance->tipo_firmante, 'estado' => $locked->estado],
                ]);

                return $locked->load('detalles', 'reservas', 'aceptacion', 'entregas');
            }, 3);
        } catch (Throwable $exception) {
            $this->removeUnreferencedFile($prepared);
            throw $exception;
        }
        if ($confirmed->aceptacion->firma_path !== $prepared->signaturePath) {
            $this->removeUnreferencedFile($prepared);
        }

        // Integrity failures after commit never enter rollback cleanup or repeat stock effects.
        return $this->verifiedResult($confirmed);
    }

    private function assertSubmission(EppOperacion $operation, EppSignaturePayload $signature): void
    {
        if ($operation->submission_uuid !== $signature->data['submission_uuid'] || $operation->personal_id !== $signature->data['personal_id']) {
            throw new InvalidArgumentException('El envio y el trabajador deben corresponder a la operacion revisada.');
        }
    }

    private function assertReady(EppOperacion $operation, EppSignaturePayload $signature): void
    {
        $payload = $this->acceptance->canonicalPayload($operation);
        if ($operation->estado !== 'LISTO_PARA_FIRMA' || $operation->almacen?->estado !== 'ACTIVO'
            || $operation->reserva_expires_at === null || $operation->reserva_expires_at->lte(now())
            || $operation->version !== (int) $signature->data['review_version']
            || ! hash_equals($operation->resumen_sha256 ?? '', $signature->data['review_hash'])
            || ! hash_equals($signature->data['review_hash'], hash('sha256', EppCanonicalPayload::json($payload)))
            || ($payload['operation_id'] ?? null) !== $operation->id || ($payload['worker']['id'] ?? null) !== $operation->personal_id
            || ($payload['warehouse']['id'] ?? null) !== $operation->almacen_id
            || ($payload['warehouse']['mine_id'] ?? null) !== $operation->almacen?->mina_id
            || $operation->mina_id !== $operation->almacen?->mina_id) {
            throw new InvalidArgumentException('La revision o la reserva ya no es valida. Prepara y revisa nuevamente.');
        }
    }

    private function retry(EppOperacion $operation, EppSignaturePayload $signature, EppAceptacion $acceptance, Usuario $actor, bool $verify = true): EppOperacion
    {
        $context = $this->acceptance->signerContext($operation, $signature, $actor);
        if (! in_array($operation->estado, ['FIRMADO', 'FIRMADO_PARCIAL'], true)
            || $operation->version !== (int) $signature->data['review_version']
            || $acceptance->submission_uuid !== $signature->data['submission_uuid']
            || ! hash_equals($acceptance->contenido_sha256, $signature->data['review_hash'])
            || $acceptance->tipo_firmante !== $signature->data['tipo_firmante']
            || $acceptance->operador_usuario_id !== $context['operator_id']
            || $acceptance->testigo_usuario_id !== $context['witness_id']
            || $acceptance->aprobador_usuario_id !== $context['approver_id']
            || $acceptance->motivo_excepcion !== $context['reason']) {
            throw new InvalidArgumentException('El reintento debe corresponder a la misma aceptacion y revision.');
        }
        $operation->load('detalles', 'reservas', 'aceptacion', 'entregas');

        return $verify ? $this->verifiedResult($operation) : $operation;
    }

    private function verifiedResult(EppOperacion $operation): EppOperacion
    {
        $verification = $this->acceptance->verify($operation->aceptacion);

        return $operation->load('aceptacion', 'entregas')->withEvidenceVerification($verification);
    }

    private function writeSignature(EppOperacion $operation, EppSignaturePayload $signature): EppSignaturePayload
    {
        $bytes = $signature->pngBytes();
        $disk = Storage::disk('local');
        $directory = 'epp/firmas/'.$operation->id;
        if (! $disk->makeDirectory($directory) && ! $disk->directoryExists($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de la firma.');
        }
        $path = $directory.'/'.Str::uuid().'.png';
        $handle = @fopen($disk->path($path), 'x+b');
        if ($handle === false) {
            throw new RuntimeException('No se pudo guardar la firma privada.');
        }
        try {
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = fwrite($handle, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('No se pudo completar la firma privada.');
                }
                $written += $count;
            }
            if (! fflush($handle)) {
                throw new RuntimeException('No se pudo confirmar el archivo privado de firma.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            $disk->delete($path);
            throw $exception;
        }
        fclose($handle);
        try {
            if (! $disk->setVisibility($path, 'private')) {
                throw new RuntimeException('No se pudo proteger el archivo privado de firma.');
            }
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return $signature->withPreparedSignature($path, hash('sha256', $bytes));
    }

    private function removeUnreferencedFile(EppSignaturePayload $prepared): void
    {
        // If a commit outcome is uncertain, preserve evidence rather than delete it.
        try {
            if (! EppAceptacion::query()->where('firma_path', $prepared->signaturePath)->exists()) {
                Storage::disk('local')->delete($prepared->signaturePath);
            }
        } catch (Throwable) {
            // An inaccessible DB cannot prove rollback; a later orphan audit may clean this file.
        }
    }
}
