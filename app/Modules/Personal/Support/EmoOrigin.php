<?php

namespace App\Modules\Personal\Support;

use Illuminate\Validation\ValidationException;

enum EmoOrigin: string
{
    case MANUAL = 'MANUAL';
    case CARGA_MASIVA = 'CARGA_MASIVA';
    case REGULARIZACION = 'REGULARIZACION';

    public static function fromInput(mixed $value): self
    {
        $origin = is_string($value) ? self::tryFrom(strtoupper(trim($value))) : null;
        if ($origin === null) {
            throw ValidationException::withMessages(['origen' => 'Seleccione un origen valido para la cita EMO.']);
        }

        return $origin;
    }
}
