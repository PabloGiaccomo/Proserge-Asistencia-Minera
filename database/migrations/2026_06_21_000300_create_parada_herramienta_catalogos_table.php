<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parada_herramienta_catalogos')) {
            Schema::create('parada_herramienta_catalogos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->string('categoria', 20)->default('HERRAMIENTA');
                $table->string('descripcion', 300);
                $table->string('descripcion_normalizada', 320);
                $table->string('unidad', 40)->nullable();
                $table->string('unidad_normalizada', 40)->default('');
                $table->boolean('activo')->default(true);
                $table->char('created_by_usuario_id', 36)->nullable();
                $table->char('updated_by_usuario_id', 36)->nullable();
                $table->timestamps();

                $table->unique(['categoria', 'descripcion_normalizada', 'unidad_normalizada'], 'uq_parada_herr_catalogo_desc');
                $table->index(['categoria', 'activo'], 'idx_parada_herr_catalogo_categoria');

                $table->foreign('created_by_usuario_id')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
                $table->foreign('updated_by_usuario_id')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('parada_herramienta_catalogo_observaciones')) {
            Schema::create('parada_herramienta_catalogo_observaciones', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('catalogo_id', 36);
                $table->text('observacion');
                $table->string('observacion_normalizada', 500);
                $table->char('observacion_hash', 40);
                $table->unsignedInteger('usos')->default(1);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['catalogo_id', 'observacion_hash'], 'uq_parada_herr_catalogo_obs');
                $table->index(['catalogo_id', 'usos'], 'idx_parada_herr_catalogo_obs_usos');

                $table->foreign('catalogo_id')
                    ->references('id')
                    ->on('parada_herramienta_catalogos')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parada_herramienta_catalogo_observaciones');
        Schema::dropIfExists('parada_herramienta_catalogos');
    }
};
