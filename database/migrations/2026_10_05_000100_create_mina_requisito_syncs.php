<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('minas') && ! Schema::hasTable('mina_requisito_syncs')) {
            Schema::create('mina_requisito_syncs', function (Blueprint $table): void {
                $table->char('mina_id', 36)->primary();
                $table->char('usuario_id', 36);
                $table->string('estado', 20)->default('PENDIENTE');
                $table->char('cursor_id', 36)->nullable();
                $table->unsignedInteger('procesados')->default(0);
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('intentos_fallidos')->default(0);
                $table->text('ultimo_error')->nullable();
                $table->timestamps();

                $table->index('estado');
                $table->foreign('mina_id')->references('id')->on('minas')->cascadeOnDelete();
                $table->foreign('usuario_id')->references('id')->on('usuarios');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mina_requisito_syncs');
    }
};
