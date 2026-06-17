<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rq_mina_detalle', function (Blueprint $table): void {
            if (!Schema::hasColumn('rq_mina_detalle', 'cantidad_backup')) {
                $table->integer('cantidad_backup')->default(0)->after('cantidad');
            }

            if (!Schema::hasColumn('rq_mina_detalle', 'cantidad_total')) {
                $table->integer('cantidad_total')->default(0)->after('cantidad_backup');
            }
        });

        if (Schema::hasColumn('rq_mina_detalle', 'cantidad_backup') && Schema::hasColumn('rq_mina_detalle', 'cantidad_total')) {
            DB::table('rq_mina_detalle')
                ->select(['id', 'cantidad'])
                ->orderBy('id')
                ->chunk(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $cantidad = max(0, (int) $row->cantidad);
                        $backup = (int) round($cantidad * 0.2);

                        DB::table('rq_mina_detalle')
                            ->where('id', $row->id)
                            ->update([
                                'cantidad_backup' => $backup,
                                'cantidad_total' => $cantidad + $backup,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('rq_mina_detalle', function (Blueprint $table): void {
            if (Schema::hasColumn('rq_mina_detalle', 'cantidad_total')) {
                $table->dropColumn('cantidad_total');
            }

            if (Schema::hasColumn('rq_mina_detalle', 'cantidad_backup')) {
                $table->dropColumn('cantidad_backup');
            }
        });
    }
};
