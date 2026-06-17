<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_contrato_correcciones')) {
            return;
        }

        Schema::create('personal_contrato_correcciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('personal_contrato_id');
            $table->uuid('personal_id');
            $table->uuid('usuario_id')->nullable();
            $table->string('accion', 40);
            $table->text('motivo');
            $table->json('datos_anteriores_json')->nullable();
            $table->json('datos_nuevos_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['personal_contrato_id', 'accion'], 'idx_contrato_correcciones_accion');
            $table->index(['personal_id', 'created_at'], 'idx_contrato_correcciones_personal');

            $table->foreign('personal_contrato_id', 'fk_contrato_correcciones_contrato')
                ->references('id')
                ->on('personal_contratos')
                ->cascadeOnDelete();

            $table->foreign('personal_id', 'fk_contrato_correcciones_personal')
                ->references('id')
                ->on('personal')
                ->cascadeOnDelete();

            $table->foreign('usuario_id', 'fk_contrato_correcciones_usuario')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_contrato_correcciones');
    }
};
