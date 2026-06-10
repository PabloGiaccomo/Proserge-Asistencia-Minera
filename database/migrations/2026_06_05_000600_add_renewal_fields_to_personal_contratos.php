<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal_contratos', 'tipo_movimiento')) {
                $table->string('tipo_movimiento', 30)->nullable()->after('origen_contrato_id');
            }

            if (!Schema::hasColumn('personal_contratos', 'observacion_renovacion')) {
                $table->text('observacion_renovacion')->nullable()->after('tipo_movimiento');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            foreach (['observacion_renovacion', 'tipo_movimiento'] as $column) {
                if (Schema::hasColumn('personal_contratos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
