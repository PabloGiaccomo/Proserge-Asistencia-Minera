<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_encabezado', function (Blueprint $table): void {
            if (!Schema::hasColumn('asistencia_encabezado', 'grupo_trabajo_id')) {
                $table->char('grupo_trabajo_id', 36)->nullable()->after('id');
            }
        });

        if (!Schema::hasColumn('asistencia_encabezado', 'grupo_trabajo_id')) {
            return;
        }

        if (!$this->hasUniqueIndex('asistencia_encabezado', 'uq_asistencia_grupo', ['grupo_trabajo_id'])) {
            Schema::table('asistencia_encabezado', function (Blueprint $table): void {
                $table->unique('grupo_trabajo_id', 'uq_asistencia_grupo');
            });
        }

        if (!$this->hasForeignKey('asistencia_encabezado', 'fk_asistencia_encabezado_grupo', 'grupo_trabajo_id')) {
            Schema::table('asistencia_encabezado', function (Blueprint $table): void {
                $table->foreign('grupo_trabajo_id', 'fk_asistencia_encabezado_grupo')
                    ->references('id')
                    ->on('grupo_trabajo')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('asistencia_encabezado', 'grupo_trabajo_id')) {
            return;
        }

        Schema::table('asistencia_encabezado', function (Blueprint $table): void {
            if ($this->hasForeignKey('asistencia_encabezado', 'fk_asistencia_encabezado_grupo', 'grupo_trabajo_id')) {
                $table->dropForeign('fk_asistencia_encabezado_grupo');
            }
        });

        Schema::table('asistencia_encabezado', function (Blueprint $table): void {
            if ($this->hasIndexNamed('asistencia_encabezado', 'uq_asistencia_grupo')) {
                $table->dropUnique('uq_asistencia_grupo');
            }

            $table->dropColumn('grupo_trabajo_id');
        });
    }

    private function hasUniqueIndex(string $table, string $name, array $columns): bool
    {
        if ($this->hasIndexNamed($table, $name)) {
            return true;
        }

        $database = DB::getDatabaseName();
        $indexes = collect(DB::table('information_schema.STATISTICS')
            ->select('INDEX_NAME', 'COLUMN_NAME', 'SEQ_IN_INDEX', 'NON_UNIQUE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('NON_UNIQUE', 0)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get())
            ->groupBy('INDEX_NAME')
            ->map(fn ($items) => $items->pluck('COLUMN_NAME')->values()->all());

        return $indexes->contains(fn (array $indexColumns): bool => $indexColumns === array_values($columns));
    }

    private function hasIndexNamed(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function hasForeignKey(string $table, string $name, string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->where(function ($query) use ($name): void {
                $query->where('CONSTRAINT_NAME', $name)
                    ->orWhere('REFERENCED_TABLE_NAME', 'grupo_trabajo');
            })
            ->exists();
    }
};
