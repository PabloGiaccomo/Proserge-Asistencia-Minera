<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('parada_herramienta_items', 'cantidad_entregada')) {
                $table->unsignedInteger('cantidad_entregada')->default(0)->after('cantidad_solicitada');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'cantidad_recibida')) {
                $table->unsignedInteger('cantidad_recibida')->default(0)->after('cantidad_entregada');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
            if (Schema::hasColumn('parada_herramienta_items', 'cantidad_recibida')) {
                $table->dropColumn('cantidad_recibida');
            }

            if (Schema::hasColumn('parada_herramienta_items', 'cantidad_entregada')) {
                $table->dropColumn('cantidad_entregada');
            }
        });
    }
};
