<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_registro')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            if (! Schema::hasColumn('epp_registro', 'politica_devolucion')) {
                $table->string('politica_devolucion', 20)->default('RETORNABLE');
            }
            foreach (['control_inventario' => true, 'permite_entrega_repetida' => false, 'requiere_serie' => false, 'es_requerido_cobertura' => false] as $column => $default) {
                if (! Schema::hasColumn('epp_registro', $column)) {
                    $table->boolean($column)->default($default);
                }
            }
        });
    }

    public function down(): void
    {
        // Preserve catalog policy used by historical signed snapshots.
    }
};
