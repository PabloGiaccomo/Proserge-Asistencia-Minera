<?php

namespace App\Modules\Personal\Support;

use Illuminate\Validation\ValidationException;

class CursoResultado
{
    public static function payload(array $payload, bool $isEmo = false): array
    {
        if ($isEmo) {
            $outcome = EmoOutcome::fromInput($payload['resultado'] ?? null);
            if ($outcome->requiresDetail()) {
                $observation = trim((string) ($payload['observacion'] ?? ''));
                if ($observation === '') {
                    throw ValidationException::withMessages(['observacion' => $outcome->label().' requiere detallar el resultado.']);
                }
                // Preserve the visible legacy contract; the appointment owns the structured detail.
                $prefix = strtoupper($outcome->label()).': ';
                $payload['observacion'] = str_starts_with($observation, $prefix)
                    ? $observation : $prefix.$observation;
            }
            $payload['resultado'] = $outcome->genericResult();

            return $payload;
        }

        $legacy = EmoOutcome::fromLegacyText($payload['resultado'] ?? null);
        if ($legacy && $legacy['outcome']->requiresDetail()) {
            $observation = trim((string) ($payload['observacion'] ?? ''));
            if ($observation === '') {
                throw ValidationException::withMessages(['observacion' => $legacy['outcome']->label().' requiere detallar el resultado.']);
            }
            $payload['resultado'] = 'APROBADO';
            $alreadyStructured = EmoOutcome::fromLegacyText($observation);
            $payload['observacion'] = $alreadyStructured && $alreadyStructured['outcome'] === $legacy['outcome']
                ? $legacy['outcome']->legacyObservation($alreadyStructured['detail'])
                : $legacy['outcome']->legacyObservation($observation);
        } elseif ($legacy) {
            $payload['resultado'] = $legacy['outcome']->genericResult();
        }

        return $payload;
    }
}
