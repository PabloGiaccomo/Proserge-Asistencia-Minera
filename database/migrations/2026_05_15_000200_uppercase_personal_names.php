<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal') || !Schema::hasColumn('personal', 'nombre_completo')) {
            return;
        }

        DB::table('personal')
            ->select(['id', 'nombre_completo'])
            ->whereNotNull('nombre_completo')
            ->orderBy('id')
            ->chunk(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('personal')
                        ->where('id', $row->id)
                        ->update([
                            'nombre_completo' => mb_strtoupper(trim((string) $row->nombre_completo), 'UTF-8'),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // No se puede reconstruir de forma confiable la capitalizacion anterior.
    }
};
