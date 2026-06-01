<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_encabezado', function (Blueprint $table): void {
            if (!Schema::hasColumn('asistencia_encabezado', 'grupo_trabajo_id')) {
                $table->char('grupo_trabajo_id', 36)->nullable()->after('id');
            }
        });

        Schema::table('asistencia_encabezado', function (Blueprint $table): void {
            if (!Schema::hasColumn('asistencia_encabezado', 'grupo_trabajo_id')) {
                return;
            }

            $table->unique('grupo_trabajo_id', 'uq_asistencia_grupo');
            $table->foreign('grupo_trabajo_id', 'fk_asistencia_encabezado_grupo')
                ->references('id')
                ->on('grupo_trabajo')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_encabezado', function (Blueprint $table): void {
            if (!Schema::hasColumn('asistencia_encabezado', 'grupo_trabajo_id')) {
                return;
            }

            $table->dropForeign('fk_asistencia_encabezado_grupo');
            $table->dropUnique('uq_asistencia_grupo');
            $table->dropColumn('grupo_trabajo_id');
        });
    }
};
