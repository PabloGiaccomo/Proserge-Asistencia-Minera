<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluacion_desempeno')) {
            Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
                if (!Schema::hasColumn('evaluacion_desempeno', 'evaluado_por_usuario_id')) {
                    $table->char('evaluado_por_usuario_id', 36)->nullable()->after('trabajador_id');
                }
                if (!Schema::hasColumn('evaluacion_desempeno', 'asistencia_encabezado_id')) {
                    $table->char('asistencia_encabezado_id', 36)->nullable()->after('asistencia_detalle_id');
                }
                if (!Schema::hasColumn('evaluacion_desempeno', 'grupo_trabajo_id')) {
                    $table->char('grupo_trabajo_id', 36)->nullable()->after('mina_id');
                }
                if (!Schema::hasColumn('evaluacion_desempeno', 'destino_tipo')) {
                    $table->string('destino_tipo', 20)->nullable()->after('asistencia_encabezado_id');
                }
                if (!Schema::hasColumn('evaluacion_desempeno', 'destino_id')) {
                    $table->char('destino_id', 36)->nullable()->after('destino_tipo');
                }
            });

            if (!$this->indexExists('evaluacion_desempeno', 'uq_eval_des_asistencia_detalle')
                && !DB::table('evaluacion_desempeno')
                    ->whereNotNull('asistencia_detalle_id')
                    ->select('asistencia_detalle_id')
                    ->groupBy('asistencia_detalle_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists()
            ) {
                Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
                    $table->unique('asistencia_detalle_id', 'uq_eval_des_asistencia_detalle');
                });
            }
        }

        if (Schema::hasTable('evaluacion_residente')) {
            Schema::table('evaluacion_residente', function (Blueprint $table): void {
                if (!Schema::hasColumn('evaluacion_residente', 'periodo_mes')) {
                    $table->date('periodo_mes')->nullable()->after('fecha');
                }
                if (!Schema::hasColumn('evaluacion_residente', 'estado')) {
                    $table->string('estado', 20)->default('REGISTRADA')->after('comentarios');
                }
                if (!Schema::hasColumn('evaluacion_residente', 'created_by_usuario_id')) {
                    $table->char('created_by_usuario_id', 36)->nullable()->after('estado');
                }
                if (!Schema::hasColumn('evaluacion_residente', 'updated_by_usuario_id')) {
                    $table->char('updated_by_usuario_id', 36)->nullable()->after('created_by_usuario_id');
                }
            });

            DB::table('evaluacion_residente')
                ->whereNull('periodo_mes')
                ->update(['periodo_mes' => DB::raw("DATE_FORMAT(fecha, '%Y-%m-01')")]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evaluacion_desempeno') && $this->indexExists('evaluacion_desempeno', 'uq_eval_des_asistencia_detalle')) {
            Schema::table('evaluacion_desempeno', fn (Blueprint $table) => $table->dropUnique('uq_eval_des_asistencia_detalle'));
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
