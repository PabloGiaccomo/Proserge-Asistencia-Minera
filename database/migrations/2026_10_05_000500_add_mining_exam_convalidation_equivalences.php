<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['examenes_equivalentes_json', 'convalidacion_configurada_por_id', 'convalidacion_configurada_at'] as $column) {
            if (! Schema::hasColumn('mina_requisitos', $column)) {
                Schema::table('mina_requisitos', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'examenes_equivalentes_json' => $table->json($column)->nullable(),
                        'convalidacion_configurada_por_id' => $table->char($column, 36)->nullable(),
                        default => $table->timestamp($column)->nullable(),
                    };
                });
            }
        }
        if (! Schema::hasColumn('personal_mina_examenes', 'contexto_convalidacion_json')) {
            Schema::table('personal_mina_examenes', fn (Blueprint $table) => $table->json('contexto_convalidacion_json')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['examenes_equivalentes_json', 'convalidacion_configurada_por_id', 'convalidacion_configurada_at'] as $column) {
            if (Schema::hasColumn('mina_requisitos', $column)) {
                Schema::table('mina_requisitos', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasColumn('personal_mina_examenes', 'contexto_convalidacion_json')) {
            Schema::table('personal_mina_examenes', fn (Blueprint $table) => $table->dropColumn('contexto_convalidacion_json'));
        }
    }
};
