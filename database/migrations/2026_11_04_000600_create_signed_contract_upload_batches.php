<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_contrato_firmado_lotes')) {
            Schema::create('personal_contrato_firmado_lotes', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('usuario_id', 36)->index();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('personal_contrato_firmado_lote_archivos')) {
            Schema::create('personal_contrato_firmado_lote_archivos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('lote_id', 36);
                $table->char('operacion_id', 36);
                $table->unsignedInteger('orden');
                $table->string('nombre', 191);
                $table->string('path', 255)->nullable();
                $table->char('sha256', 64)->index();
                $table->char('confirmed_sha256', 64)->nullable()->unique('pc_firmado_hash_confirmado');
                $table->char('contrato_id', 36)->nullable()->index();
                $table->char('contrato_revision', 64)->nullable();
                $table->string('estado', 30)->default('PENDIENTE');
                $table->string('mensaje', 255)->nullable();
                $table->timestamps();
                $table->unique(['lote_id', 'operacion_id'], 'pc_firmado_lote_operacion');
                $table->foreign('lote_id', 'pc_firmado_lote_fk')->references('id')->on('personal_contrato_firmado_lotes');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_contrato_firmado_lote_archivos');
        Schema::dropIfExists('personal_contrato_firmado_lotes');
    }
};
