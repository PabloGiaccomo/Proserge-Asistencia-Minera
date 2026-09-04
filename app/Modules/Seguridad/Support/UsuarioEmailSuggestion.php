<?php

namespace App\Modules\Seguridad\Support;

use Illuminate\Support\Str;

class UsuarioEmailSuggestion
{
    public static function fromFicha(array $data): ?string
    {
        $names = preg_split('/\s+/u', trim((string) ($data['nombres'] ?? ''))) ?: [];
        $name = self::normalize($names[0] ?? '');
        $surname = self::normalize((string) ($data['apellido_paterno'] ?? ''));

        if ($name === '' || $surname === '') {
            return null;
        }

        $local = $name . '.' . $surname;

        return strlen($local) <= 64 ? $local . '@proserge.com' : null;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value))) ?? '';
    }
}
