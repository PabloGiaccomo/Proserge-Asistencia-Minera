<?php

namespace App\Modules\Personal\Support;

use Illuminate\Validation\ValidationException;

class CursoResultado
{
    public static function payload(array $payload): array
    {
        $result = str_replace('_', ' ', strtoupper(trim((string) ($payload['resultado'] ?? ''))));
        if ($result === 'APTO CON RESTRICCION') {
            $observation = trim((string) ($payload['observacion'] ?? ''));
            if ($observation === '') {
                throw ValidationException::withMessages(['observacion' => 'Apto con restriccion requiere detallar la restriccion.']);
            }
            $payload['resultado'] = 'APROBADO';
            $payload['observacion'] = str_starts_with($observation, 'APTO CON RESTRICCION: ')
                ? $observation : 'APTO CON RESTRICCION: '.$observation;
        } elseif ($result === 'APTO') {
            $payload['resultado'] = 'APROBADO';
        } elseif ($result === 'NO APTO') {
            $payload['resultado'] = 'DESAPROBADO';
        }

        return $payload;
    }
}
