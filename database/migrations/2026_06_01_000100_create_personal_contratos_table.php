<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::create('personal_contratos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('personal_id');
            $table->unsignedInteger('contrato_numero');
            $table->string('estado', 20)->default('ACTIVO');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('motivo_cese')->nullable();
            $table->timestamp('activado_at')->nullable();
            $table->uuid('activado_by_usuario_id')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->uuid('cerrado_by_usuario_id')->nullable();
            $table->uuid('origen_contrato_id')->nullable();
            $table->uuid('personal_ficha_id')->nullable();
            $table->json('snapshot_inicial_json')->nullable();
            $table->json('snapshot_json')->nullable();
            $table->timestamps();

            $table->unique(['personal_id', 'contrato_numero'], 'uq_personal_contratos_numero');
            $table->index(['personal_id', 'estado'], 'idx_personal_contratos_estado');
            $table->index(['fecha_inicio', 'fecha_fin'], 'idx_personal_contratos_fechas');

            $table->foreign('personal_id')
                ->references('id')
                ->on('personal')
                ->cascadeOnDelete();

            $table->foreign('activado_by_usuario_id', 'fk_personal_contrato_activado_by')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();

            $table->foreign('cerrado_by_usuario_id', 'fk_personal_contrato_cerrado_by')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();

            $table->foreign('origen_contrato_id', 'fk_personal_contrato_origen')
                ->references('id')
                ->on('personal_contratos')
                ->nullOnDelete();

            $table->foreign('personal_ficha_id', 'fk_personal_contrato_ficha')
                ->references('id')
                ->on('personal_fichas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_contratos');
    }
};
