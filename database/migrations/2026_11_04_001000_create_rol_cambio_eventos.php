<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rol_cambio_eventos', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('rol_id', 36)->index();
            $table->char('actor_usuario_id', 36)->nullable()->index();
            $table->json('antes_json');
            $table->json('despues_json');
            $table->boolean('permisos_cambiados');
            $table->string('notificaciones_estado', 24)->default('PENDIENTE');
            $table->unsignedInteger('intentos')->default(0);
            $table->dateTime('proximo_intento_at')->nullable();
            $table->json('avisos_json')->nullable();
            $table->timestamps();
            $table->index(['notificaciones_estado', 'proximo_intento_at'], 'rol_cambio_aviso_pendiente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_cambio_eventos');
    }
};
