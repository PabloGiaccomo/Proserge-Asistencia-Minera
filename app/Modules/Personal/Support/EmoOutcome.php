<?php

namespace App\Modules\Personal\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

enum EmoOutcome: string
{
    case APTO = 'APTO';
    case NO_APTO = 'NO_APTO';
    case APTO_CON_RESTRICCION = 'APTO_CON_RESTRICCION';
    case APTO_CON_OBSERVACION = 'APTO_CON_OBSERVACION';

    public static function fromInput(mixed $value): self
    {
        $normalized = is_string($value) ? self::normalized($value) : '';
        $normalized = match ($normalized) {
            'APTO_CON_RESTRICCIONES' => self::APTO_CON_RESTRICCION->value,
            'APTO_CON_OBSERVACIONES' => self::APTO_CON_OBSERVACION->value,
            default => $normalized,
        };
        $outcome = self::tryFrom($normalized);

        if ($outcome === null) {
            throw ValidationException::withMessages(['resultado' => 'Seleccione un resultado EMO valido.']);
        }

        return $outcome;
    }

    /**
     * Reads historical medical wording without inventing a missing detail.
     *
     * @return array{outcome:self, detail:?string, tooltip:string}|null
     */
    public static function fromLegacyText(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $original = trim($value);
        $parts = preg_split('/\s*[:\-]\s*/u', $original, 2) ?: [$original];
        $normalized = preg_replace('/[\s_]+/u', ' ', trim(Str::ascii($parts[0]))) ?: '';
        $detail = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null;
        $patterns = [
            self::NO_APTO->value => '/^NO APTO$/iu',
            self::APTO_CON_RESTRICCION->value => '/^APTO CON RESTRICCION(?:ES)?$/iu',
            self::APTO_CON_OBSERVACION->value => '/^APTO CON OBSERVACION(?:ES)?$/iu',
            self::APTO->value => '/^APTO$/iu',
        ];

        foreach ($patterns as $outcomeValue => $pattern) {
            if (! preg_match($pattern, $normalized, $matches)) {
                continue;
            }
            $outcome = self::from($outcomeValue);

            return ['outcome' => $outcome, 'detail' => $detail, 'tooltip' => $outcome->detailTooltip($detail)];
        }

        return null;
    }

    public function detailTooltip(?string $detail): string
    {
        $detail = trim((string) $detail);

        return match ($this) {
            self::APTO_CON_RESTRICCION => $detail !== '' ? 'Restriccion: '.$detail : 'Detalle de restriccion no registrado',
            self::APTO_CON_OBSERVACION => $detail !== '' ? 'Observacion: '.$detail : 'Detalle de observacion no registrado',
            default => '',
        };
    }

    public function legacyObservation(?string $detail): ?string
    {
        $detail = trim((string) $detail);
        if (! $this->requiresDetail()) {
            return $detail !== '' ? $detail : null;
        }

        $prefix = $this === self::APTO_CON_RESTRICCION ? 'APTO CON RESTRICCION' : 'APTO CON OBSERVACION';

        return $prefix.($detail !== '' ? ': '.$detail : '');
    }

    private static function normalized(string $value): string
    {
        return preg_replace('/[\s_]+/', '_', strtoupper(trim(Str::ascii($value)))) ?: '';
    }

    public function genericResult(): string
    {
        return $this === self::NO_APTO ? 'DESAPROBADO' : 'APROBADO';
    }

    public function requiresDetail(): bool
    {
        return in_array($this, [self::APTO_CON_RESTRICCION, self::APTO_CON_OBSERVACION], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::APTO => 'Apto',
            self::NO_APTO => 'No apto',
            self::APTO_CON_RESTRICCION => 'Apto con restriccion',
            self::APTO_CON_OBSERVACION => 'Apto con observacion',
        };
    }
}
