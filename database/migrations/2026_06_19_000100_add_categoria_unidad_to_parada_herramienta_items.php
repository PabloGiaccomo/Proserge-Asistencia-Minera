<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('parada_herramienta_items', 'categoria')) {
                $table->string('categoria', 20)->default('HERRAMIENTA')->after('tipo');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'unidad')) {
                $table->string('unidad', 40)->nullable()->after('cantidad_solicitada');
            }
        });

        if (!$this->indexExists('parada_herramienta_items', 'idx_parada_herr_items_grupo_categoria_tipo')) {
            Schema::table('parada_herramienta_items', function (Blueprint $table): void {
                $table->index(['grupo_id', 'categoria', 'tipo'], 'idx_parada_herr_items_grupo_categoria_tipo');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
            if ($this->indexExists('parada_herramienta_items', 'idx_parada_herr_items_grupo_categoria_tipo')) {
                $table->dropIndex('idx_parada_herr_items_grupo_categoria_tipo');
            }

            if (Schema::hasColumn('parada_herramienta_items', 'unidad')) {
                $table->dropColumn('unidad');
            }

            if (Schema::hasColumn('parada_herramienta_items', 'categoria')) {
                $table->dropColumn('categoria');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
