<?php

namespace App\Shared\Support;

use Illuminate\Support\Str;

final class SearchTerms
{
    public static function terms(string $search): array
    {
        return array_values(array_unique(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    public static function apply($query, string $search, array $columns): void
    {
        foreach (self::terms($search) as $term) {
            $query->where(function ($where) use ($term, $columns): void {
                foreach (array_unique([$term, Str::ascii($term)]) as $variant) {
                    $like = '%'.addcslashes($variant, '\\%_').'%';
                    foreach ($columns as $column) {
                        $where->orWhere($column, 'like', $like);
                    }
                }
            });
        }
    }

    public static function matches(string $text, string $search): bool
    {
        $text = Str::lower(Str::ascii($text));
        foreach (self::terms($search) as $term) {
            if (! str_contains($text, Str::lower(Str::ascii($term)))) {
                return false;
            }
        }

        return true;
    }
}
