<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('examen_minero_asociaciones')) {
            Schema::create('examen_minero_asociaciones', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('variante_id', 36)->unique();
                $table->char('principal_id', 36)->index();
                $table->char('usuario_id', 36)->nullable();
                $table->string('motivo', 1000);
                $table->timestamps();
                $table->foreign('variante_id', 'fk_exam_variant')->references('id')->on('examenes_mineros')->restrictOnDelete();
                $table->foreign('principal_id', 'fk_exam_principal')->references('id')->on('examenes_mineros')->restrictOnDelete();
            });
        }
        if (!Schema::hasTable('examen_minero_aliases')) {
            Schema::create('examen_minero_aliases', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('examen_id', 36)->index();
                $table->char('mina_id', 36)->nullable();
                $table->string('scope_key', 36)->default('global');
                $table->string('nombre', 191);
                $table->char('clave', 64);
                $table->char('usuario_id', 36)->nullable();
                $table->timestamps();
                $table->unique(['clave', 'scope_key'], 'uq_exam_alias_scope');
                $table->foreign('examen_id', 'fk_exam_alias_exam')->references('id')->on('examenes_mineros')->restrictOnDelete();
                $table->foreign('mina_id', 'fk_exam_alias_mine')->references('id')->on('minas')->restrictOnDelete();
            });
        }
        if (!Schema::hasTable('examen_minero_catalogo_cambios')) {
            Schema::create('examen_minero_catalogo_cambios', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('usuario_id', 36);
                $table->string('accion', 30);
                $table->string('motivo', 1000);
                $table->json('antes_json');
                $table->json('despues_json');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examen_minero_catalogo_cambios');
        Schema::dropIfExists('examen_minero_aliases');
        Schema::dropIfExists('examen_minero_asociaciones');
    }
};
