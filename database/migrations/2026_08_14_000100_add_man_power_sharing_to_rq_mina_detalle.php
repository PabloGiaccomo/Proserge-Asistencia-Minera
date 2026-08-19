<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rq_mina_detalle') && !Schema::hasColumn('rq_mina_detalle', 'compartible_man_power')) {
            Schema::table('rq_mina_detalle', function (Blueprint $table): void {
                $table->boolean('compartible_man_power')->default(false)->after('cantidad_atendida');
                $table->index(['rq_mina_id', 'compartible_man_power'], 'idx_rq_mina_detalle_man_power_shared');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rq_mina_detalle') && Schema::hasColumn('rq_mina_detalle', 'compartible_man_power')) {
            Schema::table('rq_mina_detalle', function (Blueprint $table): void {
                $table->dropIndex('idx_rq_mina_detalle_man_power_shared');
                $table->dropColumn('compartible_man_power');
            });
        }
    }
};
