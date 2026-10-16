<?php

namespace App\Modules\Personal\Support;

final class PersonalSearchQuery
{
    /** @return list<string> */
    public static function dniList(string $search): array
    {
        $search = trim($search);

        if (preg_match('/\A[0-9]+(?:\s+[0-9]+)+\z/u', $search) !== 1) {
            return [];
        }

        $numbers = preg_split('/\s+/u', $search) ?: [];

        return array_values(array_unique(array_filter($numbers, static fn (string $number): bool => strlen($number) === 8)));
    }
}
