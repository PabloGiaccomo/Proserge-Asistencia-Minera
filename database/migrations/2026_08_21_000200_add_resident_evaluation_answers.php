<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('evaluacion_residente')) {
            return;
        }

        Schema::table('evaluacion_residente', function (Blueprint $table): void {
            if (!Schema::hasColumn('evaluacion_residente', 'indicadores_kpi_items')) {
                $table->json('indicadores_kpi_items')->nullable()->after('indicadores_kpi');
            }
            if (!Schema::hasColumn('evaluacion_residente', 'costos_servicio_items')) {
                $table->json('costos_servicio_items')->nullable()->after('costos_servicio');
            }
            if (!Schema::hasColumn('evaluacion_residente', 'eventos_seguridad_respuesta')) {
                $table->string('eventos_seguridad_respuesta', 2)->nullable()->after('eventos_seguridad');
            }
            if (!Schema::hasColumn('evaluacion_residente', 'reportes_calidad_respuesta')) {
                $table->string('reportes_calidad_respuesta', 2)->nullable()->after('reportes_calidad');
            }
            if (!Schema::hasColumn('evaluacion_residente', 'liderazgo_gestion_innovacion')) {
                $table->unsignedTinyInteger('liderazgo_gestion_innovacion')->nullable()->after('liderazgo_gestion');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('evaluacion_residente')) {
            return;
        }

        $columns = collect([
            'indicadores_kpi_items',
            'costos_servicio_items',
            'eventos_seguridad_respuesta',
            'reportes_calidad_respuesta',
            'liderazgo_gestion_innovacion',
        ])->filter(fn (string $column): bool => Schema::hasColumn('evaluacion_residente', $column))->all();

        if ($columns !== []) {
            Schema::table('evaluacion_residente', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
