<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usuario_alta_lotes')) {
            return;
        }

        Schema::create('usuario_alta_lotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuid('rol_id');
            $table->string('estado', 20);
            $table->char('solicitud_hash', 64);
            $table->json('usuario_ids')->nullable();
            $table->longText('credenciales')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('expires_at')->index();
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_alta_lotes');
    }
};
