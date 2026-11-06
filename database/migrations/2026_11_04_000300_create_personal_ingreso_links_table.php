<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_ingreso_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ingreso_id')->constrained('personal_ingresos')->restrictOnDelete();
            $table->foreignUuid('personal_id')->constrained('personal')->restrictOnDelete();
            $table->string('proposito', 24);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignUuid('revoked_by_actor_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ingreso_links');
    }
};
