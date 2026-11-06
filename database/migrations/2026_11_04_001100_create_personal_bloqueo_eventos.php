<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_bloqueo_eventos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('personal_bloqueo_id');
            $table->uuid('actor_id');
            $table->string('tipo', 20);
            $table->longText('motivo_decision');
            $table->longText('antes');
            $table->longText('despues');
            $table->dateTime('ocurrido_at');
            $table->index(['personal_bloqueo_id', 'ocurrido_at'], 'idx_bloqueo_eventos_historial');
            $table->foreign('personal_bloqueo_id', 'fk_bloqueo_eventos_bloqueo')
                ->references('id')->on('personal_bloqueo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_bloqueo_eventos');
    }
};
