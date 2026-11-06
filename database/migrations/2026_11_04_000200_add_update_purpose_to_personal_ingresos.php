<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_ingresos', function (Blueprint $table): void {
            if (! Schema::hasColumn('personal_ingresos', 'proposito')) {
                $table->string('proposito', 24)->default('ALTA');
            }
            if (! Schema::hasColumn('personal_ingresos', 'revision')) {
                $table->unsignedInteger('revision')->default(1);
            }
            if (! Schema::hasColumn('personal_ingresos', 'base_hash')) {
                $table->char('base_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('personal_ingresos', 'completitud_json')) {
                $table->json('completitud_json')->nullable();
            }
            if (! Schema::hasColumn('personal_ingresos', 'requested_fields_json')) {
                $table->json('requested_fields_json')->nullable();
            }
            if (! Schema::hasIndex('personal_ingresos', 'personal_ingreso_proposito_estado_envio_idx')) {
                $table->index(['proposito', 'estado', 'submitted_at'], 'personal_ingreso_proposito_estado_envio_idx');
            }
        });

        if (! Schema::hasTable('personal_ingreso_revisiones')) {
            Schema::create('personal_ingreso_revisiones', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('ingreso_id')->constrained('personal_ingresos')->restrictOnDelete();
                $table->unsignedInteger('revision');
                $table->string('evento', 24);
                $table->longText('snapshot');
                $table->char('base_hash', 64);
                $table->char('review_hash', 64)->nullable();
                $table->foreignUuid('actor_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $table->string('origen', 24);
                $table->timestamp('created_at');
                $table->unique(['ingreso_id', 'revision', 'evento'], 'personal_ingreso_revision_evento_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ingreso_revisiones');
        Schema::table('personal_ingresos', function (Blueprint $table): void {
            $table->dropIndex('personal_ingreso_proposito_estado_envio_idx');
            $table->dropColumn(['proposito', 'revision', 'base_hash', 'completitud_json', 'requested_fields_json']);
        });
    }
};
