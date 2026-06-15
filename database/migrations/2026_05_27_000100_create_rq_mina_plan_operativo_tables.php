<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_grupos')) {
            Schema::create('rq_mina_actividad_grupos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36);
                $table->string('area_operativa', 80)->nullable();
                $table->string('modulo', 80)->nullable();
                $table->string('nombre', 191);
                $table->text('observaciones')->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index('rq_mina_id', 'idx_rq_mina_act_grupos_rq');
                $table->foreign('rq_mina_id', 'fk_rq_mina_act_grupos_rq')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('rq_mina_actividades')) {
            Schema::create('rq_mina_actividades', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('grupo_id', 36);
                $table->string('sait', 191)->nullable();
                $table->string('sector', 191)->nullable();
                $table->string('area', 191)->nullable();
                $table->text('ait_trabajo')->nullable();
                $table->text('detalle_trabajos_relevantes')->nullable();
                $table->string('supervisor_campo_dia', 191)->nullable();
                $table->string('supervisor_campo_noche', 191)->nullable();
                $table->string('supervisor_seguridad_dia', 191)->nullable();
                $table->string('supervisor_seguridad_noche', 191)->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index('grupo_id', 'idx_rq_mina_actividades_grupo');
                $table->foreign('grupo_id', 'fk_rq_mina_actividades_grupo')
                    ->references('id')
                    ->on('rq_mina_actividad_grupos')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('rq_mina_actividad_turnos')) {
            Schema::create('rq_mina_actividad_turnos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('actividad_id', 36);
                $table->date('fecha')->nullable();
                $table->string('dia_label', 40)->nullable();
                $table->string('turno_a', 191)->nullable();
                $table->string('real_turno_a', 191)->nullable();
                $table->string('turno_b', 191)->nullable();
                $table->string('real_turno_b', 191)->nullable();
                $table->string('real', 191)->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index('actividad_id', 'idx_rq_mina_act_turnos_actividad');
                $table->index('fecha', 'idx_rq_mina_act_turnos_fecha');
                $table->foreign('actividad_id', 'fk_rq_mina_act_turnos_actividad')
                    ->references('id')
                    ->on('rq_mina_actividades')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('rq_mina_actividad_transportes')) {
            Schema::create('rq_mina_actividad_transportes', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('grupo_id', 36);
                $table->char('actividad_id', 36)->nullable();
                $table->string('alcance', 191)->nullable();
                $table->string('unidad_carga', 191)->nullable();
                $table->text('unidades_transporte')->nullable();
                $table->text('indicaciones')->nullable();
                $table->unsignedInteger('orden')->default(1);
                $table->timestamps();

                $table->index('grupo_id', 'idx_rq_mina_act_transportes_grupo');
                $table->index('actividad_id', 'idx_rq_mina_act_transportes_actividad');
                $table->foreign('grupo_id', 'fk_rq_mina_act_transportes_grupo')
                    ->references('id')
                    ->on('rq_mina_actividad_grupos')
                    ->cascadeOnDelete();
                $table->foreign('actividad_id', 'fk_rq_mina_act_transportes_actividad')
                    ->references('id')
                    ->on('rq_mina_actividades')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rq_mina_actividad_transportes');
        Schema::dropIfExists('rq_mina_actividad_turnos');
        Schema::dropIfExists('rq_mina_actividades');
        Schema::dropIfExists('rq_mina_actividad_grupos');
    }
};
