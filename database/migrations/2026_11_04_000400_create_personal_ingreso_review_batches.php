<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_ingreso_lotes', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('usuario_id', 36)->index();
            $table->char('revision', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('personal_ingreso_lote_filas', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('lote_id', 36);
            $table->char('ingreso_id', 36)->index();
            $table->unsignedTinyInteger('orden');
            $table->string('proposito', 24);
            $table->char('source_hash', 64);
            $table->date('fecha_inicio_contrato')->nullable();
            $table->date('fecha_fin_contrato')->nullable();
            $table->string('estado', 20);
            $table->string('mensaje', 255)->nullable();
            $table->char('personal_id', 36)->nullable();
            $table->char('contrato_id', 36)->nullable();
            $table->timestamps();
            $table->unique(['lote_id', 'ingreso_id'], 'ingreso_lote_fila_unique');
            $table->foreign('lote_id', 'ingreso_lote_fila_fk')->references('id')->on('personal_ingreso_lotes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ingreso_lote_filas');
        Schema::dropIfExists('personal_ingreso_lotes');
    }
};
