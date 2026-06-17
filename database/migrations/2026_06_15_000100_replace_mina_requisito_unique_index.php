<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mina_requisitos')) {
            return;
        }

        Schema::table('mina_requisitos', function (Blueprint $table): void {
            if ($this->hasIndexNamed('mina_requisitos', 'uq_mina_requisito_nombre_activo')) {
                $table->dropUnique('uq_mina_requisito_nombre_activo');
            }

            if (!$this->hasIndexNamed('mina_requisitos', 'idx_mina_requisito_nombre_activo')) {
                $table->index(['mina_id', 'nombre', 'activo'], 'idx_mina_requisito_nombre_activo');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mina_requisitos')) {
            return;
        }

        Schema::table('mina_requisitos', function (Blueprint $table): void {
            if ($this->hasIndexNamed('mina_requisitos', 'idx_mina_requisito_nombre_activo')) {
                $table->dropIndex('idx_mina_requisito_nombre_activo');
            }
        });
    }

    private function hasIndexNamed(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
