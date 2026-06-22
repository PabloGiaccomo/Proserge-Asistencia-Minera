<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal')) {
            return;
        }

        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'en_lista_negra')) {
                $table->boolean('en_lista_negra')->default(false)->after('pendiente_contrato_firmado');
            }

            if (!Schema::hasColumn('personal', 'lista_negra_motivo')) {
                $table->text('lista_negra_motivo')->nullable()->after('en_lista_negra');
            }

            if (!Schema::hasColumn('personal', 'lista_negra_at')) {
                $table->timestamp('lista_negra_at')->nullable()->after('lista_negra_motivo');
            }

            if (!Schema::hasColumn('personal', 'lista_negra_by_usuario_id')) {
                $table->uuid('lista_negra_by_usuario_id')->nullable()->after('lista_negra_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal')) {
            return;
        }

        Schema::table('personal', function (Blueprint $table): void {
            foreach (['lista_negra_by_usuario_id', 'lista_negra_at', 'lista_negra_motivo', 'en_lista_negra'] as $column) {
                if (Schema::hasColumn('personal', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
