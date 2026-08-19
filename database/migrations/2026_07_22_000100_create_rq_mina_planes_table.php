<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_planes')) {
            Schema::create('rq_mina_planes', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36);
                $table->string('codigo', 40);
                $table->string('nombre', 191);
                $table->unsignedInteger('version')->default(1);
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->string('semana_referencia', 80)->nullable();
                $table->string('estado', 30)->default('BORRADOR');
                $table->text('observaciones')->nullable();
                $table->char('created_by_usuario_id', 36)->nullable();
                $table->char('updated_by_usuario_id', 36)->nullable();
                $table->timestamps();

                $table->index('rq_mina_id', 'idx_rq_mina_planes_rq');
                $table->index(['rq_mina_id', 'estado'], 'idx_rq_mina_planes_rq_estado');
                $table->index(['fecha_inicio', 'fecha_fin'], 'idx_rq_mina_planes_rango');
                $table->unique(['rq_mina_id', 'codigo', 'version'], 'uq_rq_mina_planes_codigo_version');

                $table->foreign('rq_mina_id', 'fk_rq_mina_planes_rq')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();

                $table->foreign('created_by_usuario_id', 'fk_rq_mina_planes_created_by')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();

                $table->foreign('updated_by_usuario_id', 'fk_rq_mina_planes_updated_by')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rq_mina_planes');
    }
};
