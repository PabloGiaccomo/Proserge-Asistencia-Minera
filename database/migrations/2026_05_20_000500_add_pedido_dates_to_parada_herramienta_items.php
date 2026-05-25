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
            if (!Schema::hasColumn('parada_herramienta_items', 'pedido_solicitado_at')) {
                $table->date('pedido_solicitado_at')->nullable()->after('observaciones');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'pedido_llego_at')) {
                $table->date('pedido_llego_at')->nullable()->after('pedido_solicitado_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
            if (Schema::hasColumn('parada_herramienta_items', 'pedido_llego_at')) {
                $table->dropColumn('pedido_llego_at');
            }

            if (Schema::hasColumn('parada_herramienta_items', 'pedido_solicitado_at')) {
                $table->dropColumn('pedido_solicitado_at');
            }
        });
    }
};
