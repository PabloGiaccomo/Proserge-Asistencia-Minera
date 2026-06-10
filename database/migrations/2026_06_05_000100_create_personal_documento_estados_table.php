<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_documento_estados')) {
            return;
        }

        Schema::create('personal_documento_estados', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('personal_ficha_id');
            $table->string('tipo', 80);
            $table->string('estado', 30)->default('PENDIENTE');
            $table->text('observacion')->nullable();
            $table->string('vida_ley_entrega_fisica', 40)->nullable();
            $table->text('vida_ley_entrega_observacion')->nullable();
            $table->uuid('updated_by_usuario_id')->nullable();
            $table->timestamp('estado_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['personal_ficha_id', 'tipo'], 'uq_personal_documento_estado_tipo');
            $table->index(['personal_ficha_id', 'estado'], 'idx_personal_documento_estado');

            $table->foreign('personal_ficha_id', 'fk_personal_documento_estado_ficha')
                ->references('id')
                ->on('personal_fichas')
                ->cascadeOnDelete();
            $table->foreign('updated_by_usuario_id', 'fk_personal_documento_estado_usuario')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_documento_estados')) {
            Schema::drop('personal_documento_estados');
        }
    }
};
