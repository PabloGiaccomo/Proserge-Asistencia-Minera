<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['evaluacion_supervisor', 'evaluacion_residente'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'import_lote_id')) {
                    $table->char('import_lote_id', 36)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_key')) {
                    $table->char('source_key', 64)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_file')) {
                    $table->string('source_file', 191)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_sheet')) {
                    $table->string('source_sheet', 100)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_row')) {
                    $table->unsignedInteger('source_row')->nullable();
                }
                if (! Schema::hasColumn($name, 'source_id')) {
                    $table->string('source_id', 100)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_evaluador')) {
                    $table->string('source_evaluador', 191)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_mina')) {
                    $table->string('source_mina', 191)->nullable();
                }
                if (! Schema::hasColumn($name, 'calculo_version')) {
                    $table->string('calculo_version', 20)->nullable();
                }
                if (! Schema::hasColumn($name, 'source_payload')) {
                    $table->json('source_payload')->nullable();
                }
            });

            if ($name === 'evaluacion_supervisor' && $this->columnIsRequired($name, 'mina_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->char('mina_id', 36)->nullable()->change());
            }

            $index = $name === 'evaluacion_supervisor' ? 'uq_eval_sup_source_key' : 'uq_eval_res_source_key';
            if (! $this->indexExists($name, $index)) {
                Schema::table($name, fn (Blueprint $table) => $table->unique('source_key', $index));
            }
        }
    }

    public function down(): void
    {
        // El historial confirmado no debe perderse por rollback de la migracion.
    }

    private function columnIsRequired(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('is_nullable', 'NO')
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
