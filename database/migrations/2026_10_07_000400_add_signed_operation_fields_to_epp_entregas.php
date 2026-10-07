<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_entregas')) {
            return;
        }

        Schema::table('epp_entregas', function (Blueprint $table): void {
            foreach (['operacion_id', 'operacion_detalle_id'] as $column) {
                if (! Schema::hasColumn('epp_entregas', $column)) {
                    $table->char($column, 36)->nullable()->index();
                }
            }
            foreach (['cantidad_original', 'cantidad_pendiente_devolucion'] as $column) {
                if (! Schema::hasColumn('epp_entregas', $column)) {
                    // Legacy balances are deliberately left unknown for the audited backfill.
                    $table->unsignedInteger($column)->nullable();
                }
            }
            if (! Schema::hasColumn('epp_entregas', 'politica_devolucion_snapshot')) {
                $table->string('politica_devolucion_snapshot', 20)->default('RETORNABLE');
            }
            if (! Schema::hasColumn('epp_entregas', 'es_legacy')) {
                $table->boolean('es_legacy')->default(true);
            }
            if (! Schema::hasColumn('epp_entregas', 'estado_integridad')) {
                $table->string('estado_integridad', 30)->default('LEGACY_SIN_FIRMA')->index();
            }
        });
    }

    public function down(): void
    {
        // Never erase signed origins, snapshots or legacy audit markers.
    }
};
