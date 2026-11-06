<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_archivo_versiones', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('owner_type', 12);
            $table->char('owner_id', 36);
            $table->char('archivo_id', 36)->nullable()->index();
            $table->string('tipo', 80);
            $table->string('path', 500);
            $table->char('sha256', 64)->nullable();
            $table->longText('metadata');
            $table->char('actor_usuario_id', 36)->nullable();
            $table->string('origen', 40);
            $table->dateTime('created_at');
            $table->index(['owner_type', 'owner_id', 'tipo', 'created_at'], 'personal_archivo_version_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_archivo_versiones');
    }
};
