<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations applied these columns through setup/004 and 005 only.
        foreach (['grupo_trabajo', 'asistencia_encabezado', 'faltas'] as $name) {
            if (!Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (!Schema::hasColumn($name, 'destino_tipo')) {
                    $table->string('destino_tipo', 20)->nullable();
                }
                if (!Schema::hasColumn($name, 'destino_id')) {
                    $table->char('destino_id', 36)->nullable();
                }
            });
        }

        if (!Schema::hasTable('faltas')) {
            return;
        }

        Schema::table('faltas', function (Blueprint $table): void {
            if (!Schema::hasColumn('faltas', 'estado')) {
                $table->string('estado', 20)->default('ACTIVA');
            }
            foreach (['asistencia_encabezado_id', 'asistencia_detalle_id', 'corregido_por_usuario_id', 'anulado_por_usuario_id'] as $column) {
                if (!Schema::hasColumn('faltas', $column)) {
                    $table->char($column, 36)->nullable();
                }
            }
            foreach (['motivo_correccion', 'motivo_anulacion'] as $column) {
                if (!Schema::hasColumn('faltas', $column)) {
                    $table->text($column)->nullable();
                }
            }
            foreach (['corregido_at', 'anulado_at'] as $column) {
                if (!Schema::hasColumn('faltas', $column)) {
                    $table->dateTime($column)->nullable();
                }
            }
        });
        if (!Schema::hasIndex('faltas', 'idx_faltas_estado')) {
            Schema::table('faltas', fn (Blueprint $table) => $table->index('estado', 'idx_faltas_estado'));
        }
    }

    public function down(): void
    {
        // Keep destination and absence audit data when rolling back application code.
    }
};
