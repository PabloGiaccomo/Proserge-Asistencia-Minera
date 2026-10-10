<?php

namespace App\Modules\Epps\Support;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class EppKardexTemplateColumns
{
    public static function definitions(): array
    {
        $book = IOFactory::load(resource_path('epp-templates/SGC-FOR-59.xlsx'));
        $columns = [];
        foreach ($book->getAllSheets() as $side => $sheet) {
            $header = $side === 0 ? 12 : 5;
            for ($col = 3; $col <= 30; $col++) {
                $label = (string) $sheet->getCell([$col, $header])->getValue();
                if (trim($label) !== '') {
                    $columns[] = ['side' => $side, 'col' => $col, 'label' => $label];
                }
            }
        }
        $book->disconnectWorksheets();

        return $columns;
    }

    public static function match(string $name, array $columns): ?array
    {
        $name = Str::ascii(mb_strtolower($name));
        $matches = [];
        foreach ($columns as $column) {
            $tokens = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($column['label'])), -1, PREG_SPLIT_NO_EMPTY);
            $tokens = array_values(array_diff($tokens, ['de', 'para', 'del', 'seguridad', 'el']));
            if ($tokens && collect($tokens)->every(fn ($token) => str_contains($name, $token))) {
                $matches[] = $column + ['score' => count($tokens)];
            }
        }

        return collect($matches)->sortByDesc('score')->first();
    }
}
