<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('personal_curso_registros', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('personal_id')->index();
            $table->uuid('examen_id')->index();
            $table->date('fecha_programacion')->nullable();
            $table->date('fecha_realizacion')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('resultado', 30);
            $table->decimal('nota', 10, 2)->nullable();
            $table->text('observacion')->nullable();
            $table->json('catalogo_snapshot');
            $table->uuid('usuario_registro_id');
            $table->uuid('usuario_actualizacion_id')->nullable();
            $table->string('origen', 255);
            $table->timestamps();
            $table->unique(['personal_id', 'examen_id', 'fecha_programacion'], 'curso_programacion_unique');
            $table->unique(['personal_id', 'examen_id', 'fecha_realizacion'], 'curso_realizacion_unique');
        });
    }

    public function down(): void
    {
        // Operational history is retained on rollback.
    }
};
