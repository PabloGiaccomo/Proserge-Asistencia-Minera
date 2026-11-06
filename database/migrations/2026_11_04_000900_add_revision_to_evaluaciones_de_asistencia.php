<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
            if (! Schema::hasColumn('evaluacion_desempeno', 'estado_revision')) {
                $table->string('estado_revision', 24)->default('VALIDA');
            }
            if (! Schema::hasIndex('evaluacion_desempeno', 'evaluacion_desempeno_estado_revision_index')) {
                $table->index('estado_revision');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'revision_asistencia')) {
                $table->unsignedInteger('revision_asistencia')->default(0);
            }
        });
        if (! Schema::hasTable('evaluacion_asistencia_revision_eventos')) {
            Schema::create('evaluacion_asistencia_revision_eventos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('evaluacion_id', 36)->index();
                $table->unsignedInteger('revision');
                $table->char('actor_usuario_id', 36)->nullable();
                $table->string('estado_anterior', 24);
                $table->string('estado_nuevo', 24);
                $table->text('motivo');
                $table->dateTime('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluacion_asistencia_revision_eventos');
        Schema::table('evaluacion_desempeno', fn (Blueprint $table) => $table->dropColumn(['estado_revision', 'revision_asistencia']));
    }
};
