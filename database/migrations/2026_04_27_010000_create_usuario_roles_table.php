<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usuario_roles')) {
            return;
        }

        Schema::create('usuario_roles', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('usuario_id', 36);
            $table->char('rol_id', 36);
            $table->string('tipo', 20)->nullable();
            $table->timestamps();

            $table->unique(['usuario_id', 'rol_id']);
            $table->index(['usuario_id', 'tipo']);
            $table->index('rol_id');

            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
            $table->foreign('rol_id')->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_roles');
    }
};
