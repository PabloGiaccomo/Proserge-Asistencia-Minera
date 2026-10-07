<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAceptacion;
use App\Models\EppOperacion;
use App\Models\Usuario;
use App\Modules\Epps\Support\EppCanonicalPayload;
use App\Modules\Epps\Support\EppSignaturePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class EppAcceptanceService
{
    public function canonicalPayload(EppOperacion $operation): array
    {
        $payload = $operation->resumen_json;
        if (! is_array($payload) || ($payload['schema_version'] ?? null) !== 1 || empty($payload['details'])) {
            throw new InvalidArgumentException('Prepara y revisa nuevamente la operacion antes de firmar.');
        }

        return EppCanonicalPayload::normalize($payload);
    }

    /** Standalone capture uses the same atomic confirmation boundary as the web flow. */
    public function capture(EppOperacion $operation, EppSignaturePayload $signature, Usuario $actor): EppAceptacion
    {
        if ($signature->signaturePath === null) {
            $confirmation = app(EppOperationConfirmationService::class);

            return ($signature->data['tipo_firmante'] === 'TESTIGO_AUTORIZADO'
                ? $confirmation->confirmWithWitness($operation, $signature, $actor)
                : $confirmation->confirm($operation, $signature, $actor))->aceptacion;
        }
        if (DB::transactionLevel() === 0 || $operation->estado !== 'LISTO_PARA_FIRMA') {
            throw new InvalidArgumentException('La aceptacion debe guardarse dentro de la confirmacion de la operacion.');
        }
        $context = $this->signerContext($operation, $signature, $actor);
        $payload = $this->canonicalPayload($operation);
        $json = EppCanonicalPayload::json($payload);
        $hash = hash('sha256', $json);
        $path = $signature->signaturePath;
        if (! hash_equals($hash, $operation->resumen_sha256 ?? '') || ! hash_equals($hash, $signature->data['review_hash'])
            || ! str_starts_with($path, 'epp/firmas/'.$operation->id.'/')
            || ! Storage::disk('local')->exists($path)
            || ! hash_equals($signature->signatureSha256 ?? '', hash('sha256', Storage::disk('local')->get($path)))) {
            throw new InvalidArgumentException('La firma o el contenido revisado cambio. Revisa nuevamente la operacion.');
        }
        $signedAt = CarbonImmutable::now(config('app.timezone'));
        $request = request();

        return EppAceptacion::query()->create([
            'operacion_id' => $operation->id, 'personal_id' => $operation->personal_id,
            'tipo_firmante' => $signature->data['tipo_firmante'],
            'firmante_nombre' => $context['name'], 'documento_snapshot' => $context['document'],
            'testigo_usuario_id' => $context['witness_id'], 'aprobador_usuario_id' => $context['approver_id'],
            'motivo_excepcion' => $context['reason'], 'operador_usuario_id' => $context['operator_id'],
            'firma_path' => $path, 'firma_sha256' => $signature->signatureSha256,
            'contenido_json' => $json, 'contenido_sha256' => $hash,
            'codigo_verificacion' => bin2hex(random_bytes(20)), 'submission_uuid' => $operation->submission_uuid,
            'firmado_at' => $signedAt->format('Y-m-d H:i:s.u'), 'firmado_at_utc' => $signedAt->utc()->format('Y-m-d H:i:s.u'),
            'zona_horaria' => config('app.timezone'), 'declaracion_version' => $payload['declaration_version'],
            'user_agent' => Str::limit(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $request->userAgent()), 500, ''),
            'sesion_sha256' => $request->hasSession() ? $this->privateHash($request->session()->getId()) : null,
            'ip_sha256' => $request->ip() ? $this->privateHash($request->ip()) : null,
            'estado_integridad' => EppAceptacion::INTEGRIDAD_VALIDA,
        ]);
    }

    /** Uses current users/permissions and server identities; no supplied name or document is trusted. */
    public function signerContext(EppOperacion $operation, EppSignaturePayload $signature, Usuario $actor): array
    {
        $actor = Usuario::query()->findOrFail($actor->id);
        $ability = $operation->tipo === 'DEVOLUCION' ? 'confirmReturn' : 'confirmDelivery';
        if ($signature->data['tipo_firmante'] === 'TRABAJADOR') {
            Gate::forUser($actor)->authorize($ability, $operation);
            if (! empty($signature->data['testigo_usuario_id']) || ! empty($signature->data['motivo_excepcion'])) {
                throw new InvalidArgumentException('La firma del trabajador no admite datos de testigo.');
            }
            $worker = $this->canonicalPayload($operation)['worker'];
            if (empty($worker['name']) || empty($worker['document'])) {
                throw new InvalidArgumentException('Completa la identidad del trabajador antes de firmar.');
            }

            return ['name' => $worker['name'], 'document' => $worker['document'], 'witness_id' => null, 'approver_id' => null, 'reason' => null, 'operator_id' => $actor->id];
        }
        Gate::forUser($actor)->authorize('approveSignatureException', $operation);
        $operator = Usuario::query()->findOrFail($operation->registrado_por_usuario_id);
        Gate::forUser($operator)->authorize($ability, $operation);
        $witness = Usuario::query()->with('personal')->findOrFail($signature->data['testigo_usuario_id'] ?? '');
        $reason = trim((string) ($signature->data['motivo_excepcion'] ?? ''));
        if ($reason === '' || $actor->id === $witness->id || $actor->id === $operator->id
            || $witness->estado !== 'ACTIVO' || $witness->personal === null
            || $witness->personal_id === $operation->personal_id
            || trim((string) $witness->personal->nombre_completo) === '' || trim((string) $witness->personal->dni) === '') {
            throw new InvalidArgumentException('La excepcion requiere testigo identificado, motivo y aprobador distinto del testigo y operador.');
        }
        if ($operation->estado === 'RECHAZADO' || $operation->eventos()->where('accion', 'FIRMA_RECHAZADA')->exists()) {
            throw new InvalidArgumentException('Una negativa a firmar no admite excepcion por testigo.');
        }

        return ['name' => $witness->personal->nombre_completo, 'document' => $witness->personal->dni, 'witness_id' => $witness->id, 'approver_id' => $actor->id, 'reason' => $reason, 'operator_id' => $operator->id];
    }

    public function verify(EppAceptacion $acceptance): array
    {
        $acceptance = EppAceptacion::query()->findOrFail($acceptance->id);
        $reasons = [];
        $disk = Storage::disk('local');
        $expectedPrefix = 'epp/firmas/'.$acceptance->operacion_id.'/';
        try {
            if (! str_starts_with($acceptance->firma_path, $expectedPrefix) || str_contains($acceptance->firma_path, '..')) {
                $reasons[] = 'RUTA_FIRMA_INVALIDA';
            } elseif (! $disk->exists($acceptance->firma_path)) {
                $reasons[] = 'ARCHIVO_FALTANTE';
            } elseif (! hash_equals($acceptance->firma_sha256, hash('sha256', $disk->get($acceptance->firma_path)))) {
                $reasons[] = 'FIRMA_HASH_NO_COINCIDE';
            }
        } catch (Throwable) {
            $reasons[] = 'ARCHIVO_NO_LEGIBLE';
        }
        $raw = $acceptance->getRawOriginal('contenido_json');
        if (! is_string($raw) || ! hash_equals($acceptance->contenido_sha256, hash('sha256', $raw))) {
            $reasons[] = 'CONTENIDO_HASH_NO_COINCIDE';
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || EppCanonicalPayload::json($payload) !== $raw
                || ($payload['operation_id'] ?? null) !== $acceptance->operacion_id
                || ($payload['worker']['id'] ?? null) !== $acceptance->personal_id
                || ($payload['submission_uuid'] ?? null) !== $acceptance->submission_uuid) {
                $reasons[] = 'CONTENIDO_CANONICO_INVALIDO';
            }
        } catch (Throwable) {
            $reasons[] = 'CONTENIDO_CANONICO_INVALIDO';
        }
        $valid = $reasons === [];
        $state = $valid ? EppAceptacion::INTEGRIDAD_VALIDA : (in_array('ARCHIVO_FALTANTE', $reasons, true) ? EppAceptacion::INTEGRIDAD_ARCHIVO_FALTANTE : EppAceptacion::INTEGRIDAD_INVALIDA);
        if ($acceptance->estado_integridad !== $state) {
            $acceptance->update(['estado_integridad' => $state]);
            $acceptance->operacion?->entregas()->update(['estado_integridad' => $state]);
        }

        return ['valid' => $valid, 'reasons' => $valid ? [] : ['EVIDENCIA_INVALIDA', ...array_unique($reasons)]];
    }

    private function privateHash(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }
}
