<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evaluacion_desempeno')) {
            return;
        }

        Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
            if (! Schema::hasColumn('evaluacion_desempeno', 'origen')) {
                $table->string('origen', 20)->nullable()->after('evaluado_por_usuario_id');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'submission_key')) {
                $table->char('submission_key', 36)->nullable()->after('origen');
            }
        });

        DB::table('evaluacion_desempeno')
            ->whereNull('origen')
            ->whereNotNull('asistencia_detalle_id')
            ->update(['origen' => 'ASISTENCIA']);

        if ($this->columnIsRequired('supervisor_id')) {
            Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
                $table->char('supervisor_id', 36)->nullable()->change();
            });
        }

        if (! $this->indexExists('uq_eval_des_submission_key')) {
            Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
                $table->unique('submission_key', 'uq_eval_des_submission_key');
            });
        }
    }

    public function down(): void
    {
        // Eliminar estas columnas perderia el origen y la clave de auditoria.
    }

    private function columnIsRequired(string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->where('column_name', $column)
            ->where('is_nullable', 'NO')
            ->exists();
    }

    private function indexExists(string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->where('index_name', $index)
            ->exists();
    }
};
