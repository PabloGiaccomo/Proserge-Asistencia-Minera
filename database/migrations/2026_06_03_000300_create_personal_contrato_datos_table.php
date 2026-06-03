<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_contrato_datos')) {
            return;
        }

        Schema::create('personal_contrato_datos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('personal_id');
            $table->date('fecha_inicio_contrato')->nullable();
            $table->date('fecha_fin_contrato')->nullable();
            $table->date('periodo_prueba_inicio')->nullable();
            $table->date('periodo_prueba_fin')->nullable();
            $table->string('sueldo_hora_paradas', 80)->nullable();
            $table->string('sueldo_hora_paradas_texto', 191)->nullable();
            $table->string('sueldo_dia_taller', 80)->nullable();
            $table->string('sueldo_dia_taller_texto', 191)->nullable();
            $table->text('funciones')->nullable();
            $table->string('sueldo_num', 80)->nullable();
            $table->string('sueldo_texto', 191)->nullable();
            $table->string('puesto', 191)->nullable();
            $table->date('fecha_firma')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_contract_path', 500)->nullable();
            $table->string('signed_contract_original_name', 191)->nullable();
            $table->string('signed_contract_mime', 120)->nullable();
            $table->unsignedBigInteger('signed_contract_size')->nullable();
            $table->uuid('updated_by_usuario_id')->nullable();
            $table->timestamps();

            $table->unique('personal_id', 'uq_personal_contrato_datos_personal');
            $table->index(['downloaded_at', 'signed_at'], 'idx_personal_contrato_datos_estado');

            $table->foreign('personal_id', 'fk_personal_contrato_datos_personal')
                ->references('id')
                ->on('personal')
                ->cascadeOnDelete();

            $table->foreign('updated_by_usuario_id', 'fk_personal_contrato_datos_usuario')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_contrato_datos');
    }
};
