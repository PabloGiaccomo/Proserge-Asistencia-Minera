<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rq_mina_detalle_cambios')) {
            return;
        }

        Schema::create('rq_mina_detalle_cambios', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('rq_mina_id', 36);
            $table->char('rq_mina_detalle_id', 36)->nullable();
            $table->char('rq_proserge_id', 36)->nullable();
            $table->string('puesto');
            $table->string('tipo', 40);
            $table->integer('cantidad_anterior')->nullable();
            $table->integer('cantidad_nueva')->nullable();
            $table->integer('asignaciones_retiradas')->default(0);
            $table->text('mensaje')->nullable();
            $table->string('estado', 20)->default('PENDIENTE');
            $table->char('created_by_usuario_id', 36)->nullable();
            $table->timestamps();

            $table->index(['rq_mina_id', 'estado'], 'idx_rq_mina_cambios_rq_estado');
            $table->index('rq_mina_detalle_id', 'idx_rq_mina_cambios_detalle');
            $table->index('rq_proserge_id', 'idx_rq_mina_cambios_proserge');

            $table->foreign('rq_mina_id', 'fk_rq_mina_cambios_rq')
                ->references('id')
                ->on('rq_mina')
                ->cascadeOnDelete();
            $table->foreign('rq_mina_detalle_id', 'fk_rq_mina_cambios_detalle')
                ->references('id')
                ->on('rq_mina_detalle')
                ->nullOnDelete();
            $table->foreign('rq_proserge_id', 'fk_rq_mina_cambios_proserge')
                ->references('id')
                ->on('rq_proserge')
                ->nullOnDelete();
            $table->foreign('created_by_usuario_id', 'fk_rq_mina_cambios_usuario')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rq_mina_detalle_cambios');
    }
};
