<?php

namespace Tests\Unit;

use App\Modules\Epps\Support\EppKardexRowGrouper;
use PHPUnit\Framework\TestCase;

class EppKardexRowGrouperTest extends TestCase
{
    public function test_units_share_rows_only_across_different_columns(): void
    {
        $base = ['date' => '2026-10-09', 'code' => 'N', 'batch_id' => 'signed-a'];
        $rows = EppKardexRowGrouper::group([
            $base + ['column_key' => 'casco', 'quantity' => 2, 'description' => 'Blanco'],
            $base + ['column_key' => 'casco', 'quantity' => 1, 'description' => 'Rojo'],
            $base + ['column_key' => 'chaleco', 'quantity' => 2, 'description' => 'L'],
        ]);
        $this->assertCount(3, $rows);
        $this->assertSame(['casco', 'chaleco'], array_keys($rows[0]['cells']));
        $this->assertSame(['casco', 'chaleco'], array_keys($rows[1]['cells']));
        $this->assertSame(['casco'], array_keys($rows[2]['cells']));
        $this->assertSame('Rojo', $rows[2]['cells']['casco']['description']);
    }

    public function test_different_dates_movements_and_batches_never_share_a_row(): void
    {
        $base = ['date' => '2026-10-09', 'code' => 'N', 'batch_id' => 'signed-a', 'quantity' => 1];
        $rows = EppKardexRowGrouper::group([
            $base + ['column_key' => 'casco'],
            array_replace($base, ['column_key' => 'chaleco', 'code' => 'D']),
            array_replace($base, ['column_key' => 'botas', 'batch_id' => 'signed-b']),
            array_replace($base, ['column_key' => 'guantes', 'date' => '2026-10-10']),
        ]);
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertCount(1, $row['cells']);
        }
    }

    public function test_unidentified_legacy_batches_are_not_assumed_to_have_been_signed_together(): void
    {
        $base = ['date' => '2026-10-09', 'code' => 'N', 'batch_id' => null, 'quantity' => 1];
        $rows = EppKardexRowGrouper::group([$base + ['column_key' => 'casco'], $base + ['column_key' => 'chaleco']]);
        $this->assertCount(2, $rows);
    }

    public function test_pagination_keeps_quantity_ranges_without_expanding_all_units(): void
    {
        $base = ['date' => '2026-10-09', 'code' => 'N', 'batch_id' => 'signed-a'];
        $events = [
            $base + ['column_key' => 'casco', 'quantity' => 100000000, 'description' => 'Blanco'],
            $base + ['column_key' => 'chaleco', 'quantity' => 100000000],
            $base + ['column_key' => 'casco', 'quantity' => 2, 'description' => 'Rojo'],
            array_replace($base, ['batch_id' => 'signed-b', 'column_key' => 'guantes', 'quantity' => 1]),
        ];
        $result = EppKardexRowGrouper::paginate($events, 5000001, 20);
        $this->assertSame(['current_page' => 5000001, 'per_page' => 20, 'total' => 100000003, 'last_page' => 5000001, 'from' => 100000001, 'to' => 100000003], $result['pagination']);
        $this->assertCount(3, $result['rows']);
        $this->assertSame('Rojo', $result['rows'][0]['cells']['casco']['description']);
        $this->assertSame(['casco'], array_keys($result['rows'][1]['cells']));
        $this->assertSame(['guantes'], array_keys($result['rows'][2]['cells']));
        $this->assertSame(0, EppKardexRowGrouper::paginate([], 9, 20)['pagination']['from']);
    }
}
