<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_bloqueo')) {
            return;
        }

        Schema::create('personal_bloqueo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('personal_id');
            $table->string('tipo', 40);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('motivo', 191);
            $table->text('detalle')->nullable();
            $table->uuid('bloqueado_por_id');
            $table->string('estado', 20)->default('ACTIVO');
            $table->boolean('visible_para_planner')->default(true);
            $table->timestamps();

            $table->index('personal_id', 'idx_personal_bloqueo_personal');
            $table->index(['fecha_inicio', 'fecha_fin'], 'idx_personal_bloqueo_rango');

            $table->foreign('personal_id', 'fk_personal_bloqueo_personal')->references('id')->on('personal');
            $table->foreign('bloqueado_por_id', 'fk_personal_bloqueo_usuario')->references('id')->on('usuarios');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_bloqueo')) {
            Schema::drop('personal_bloqueo');
        }
    }
};
