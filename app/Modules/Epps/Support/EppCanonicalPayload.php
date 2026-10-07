<?php

namespace App\Modules\Epps\Support;

final class EppCanonicalPayload
{
    public static function normalize(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::normalize($value);
            }
        }

        return $payload;
    }

    public static function json(array $payload): string
    {
        return json_encode(self::normalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
