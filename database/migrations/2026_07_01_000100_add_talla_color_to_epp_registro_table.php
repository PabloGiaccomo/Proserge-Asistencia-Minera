<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('epp_registro')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            if (!Schema::hasColumn('epp_registro', 'requiere_talla')) {
                $table->boolean('requiere_talla')->default(false)->after('vida_util_dias');
            }

            if (!Schema::hasColumn('epp_registro', 'tallas')) {
                $table->json('tallas')->nullable()->after('requiere_talla');
            }

            if (!Schema::hasColumn('epp_registro', 'requiere_color')) {
                $table->boolean('requiere_color')->default(false)->after('tallas');
            }

            if (!Schema::hasColumn('epp_registro', 'colores')) {
                $table->json('colores')->nullable()->after('requiere_color');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('epp_registro')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            foreach (['colores', 'requiere_color', 'tallas', 'requiere_talla'] as $column) {
                if (Schema::hasColumn('epp_registro', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
