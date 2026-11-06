<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grupo_trabajo_detalle_movimientos')) {
            return;
        }

        Schema::create('grupo_trabajo_detalle_movimientos', function (Blueprint $table): void {
            $table->id();
            // Store identifiers independently of rows that older workflows may remove.
            $table->uuid('grupo_trabajo_id');
            $table->uuid('grupo_trabajo_detalle_id');
            $table->uuid('personal_id');
            $table->uuid('actor_id')->nullable();
            $table->uuid('grupo_destino_id')->nullable();
            $table->uuid('asignacion_anterior_id')->nullable();
            $table->uuid('asignacion_nueva_id')->nullable();
            $table->string('tipo', 24);
            $table->string('origen', 24);
            $table->string('estado_anterior', 24)->nullable();
            $table->string('estado_nuevo', 24);
            $table->text('motivo')->nullable();
            $table->dateTime('ocurrido_at')->nullable();
            $table->index(['grupo_trabajo_id', 'id'], 'idx_grupo_det_mov_grupo');
            $table->index(['grupo_trabajo_detalle_id', 'id'], 'idx_grupo_det_mov_detalle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_trabajo_detalle_movimientos');
    }
};
