<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->allowNull('evaluacion_desempeno', 'hora', 'time');

        foreach (['indicadores_kpi', 'costos_servicio', 'eventos_seguridad', 'reportes_calidad', 'liderazgo_gestion', 'innovacion'] as $column) {
            $this->allowNull('evaluacion_residente', $column, 'decimal');
        }
    }

    public function down(): void
    {
        // Los registros historicos pueden contener valores desconocidos.
    }

    private function allowNull(string $table, string $column, string $type): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $required = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('is_nullable', 'NO')
            ->exists();
        if (! $required) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $type): void {
            if ($type === 'time') {
                $blueprint->time($column)->nullable()->change();
            } else {
                $blueprint->decimal($column, 8, 2)->nullable()->change();
            }
        });
    }
};
