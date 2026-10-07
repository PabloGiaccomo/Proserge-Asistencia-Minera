<?php

namespace App\Models\Builders;

use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Append-only SQL boundary for queries created by EppInventarioMovimiento.
 * Deliberate DB::table/raw SQL access is outside this Eloquent contract.
 */
class EppInventarioQueryBuilder extends Builder
{
    public function update(array $values): never
    {
        $this->rejectMutation();
    }

    public function updateFrom(array $values): never
    {
        $this->rejectMutation();
    }

    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): never
    {
        $this->rejectMutation();
    }

    public function delete($id = null): never
    {
        $this->rejectMutation();
    }

    public function truncate(): never
    {
        $this->rejectMutation();
    }

    private function rejectMutation(): never
    {
        throw new LogicException('El libro de inventario es inmutable; registre un movimiento compensatorio.');
    }
}
