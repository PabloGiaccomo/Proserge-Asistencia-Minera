<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['evaluacion_supervisor', 'evaluacion_residente'] as $name) {
            if (!Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->char('evaluador_id', 36)->nullable()->change();
                foreach (['created_by_usuario_id', 'updated_by_usuario_id'] as $column) {
                    if (!Schema::hasColumn($name, $column)) {
                        $table->char($column, 36)->nullable()->index();
                    }
                }
            });
        }
        if (Schema::hasTable('evaluacion_supervisor')) {
            Schema::table('evaluacion_supervisor', function (Blueprint $table): void {
                foreach (['grupo_trabajo_id', 'asistencia_encabezado_id', 'destino_id'] as $column) {
                    if (!Schema::hasColumn('evaluacion_supervisor', $column)) {
                        $table->char($column, 36)->nullable();
                    }
                }
                if (!Schema::hasColumn('evaluacion_supervisor', 'destino_tipo')) {
                    $table->string('destino_tipo', 20)->nullable();
                }
                if (!Schema::hasColumn('evaluacion_supervisor', 'estado')) {
                    $table->string('estado', 20)->default('REGISTRADA');
                }
            });
        }
    }

    public function down(): void
    {
        // Preserve account-only evaluations and their audit data on rollback.
    }
};
