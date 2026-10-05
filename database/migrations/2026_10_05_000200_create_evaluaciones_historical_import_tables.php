<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evaluaciones_import_lotes')) {
            Schema::create('evaluaciones_import_lotes', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('hash', 64);
                $table->string('estado', 20);
                $table->string('manifest_path', 500)->nullable();
                $table->json('resumen')->nullable();
                $table->char('created_by_usuario_id', 36);
                $table->char('confirmed_by_usuario_id', 36)->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->index(['hash', 'estado']);
                $table->foreign('created_by_usuario_id')->references('id')->on('usuarios');
            });
        }

        if (! Schema::hasTable('asistencia_historica')) {
            Schema::create('asistencia_historica', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('lote_id', 36);
                $table->char('trabajador_id', 36);
                $table->date('fecha');
                $table->boolean('presente')->default(true);
                $table->string('source_file', 191);
                $table->string('source_sheet', 100);
                $table->unsignedInteger('source_row');
                $table->string('source_id', 100)->nullable();
                $table->string('source_mina', 191)->nullable();
                $table->char('source_key', 64)->unique();
                $table->timestamps();
                $table->index(['trabajador_id', 'fecha']);
                $table->foreign('trabajador_id')->references('id')->on('personal');
                $table->foreign('lote_id')->references('id')->on('evaluaciones_import_lotes');
            });
        }

        if (! Schema::hasTable('evaluacion_desempeno')) {
            return;
        }

        Schema::table('evaluacion_desempeno', function (Blueprint $table): void {
            if (! Schema::hasColumn('evaluacion_desempeno', 'import_lote_id')) {
                $table->char('import_lote_id', 36)->nullable()->after('submission_key');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_file')) {
                $table->string('source_file', 191)->nullable()->after('import_lote_id');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_sheet')) {
                $table->string('source_sheet', 100)->nullable()->after('source_file');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_row')) {
                $table->unsignedInteger('source_row')->nullable()->after('source_sheet');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_id')) {
                $table->string('source_id', 100)->nullable()->after('source_row');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_mina')) {
                $table->string('source_mina', 191)->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_evaluador')) {
                $table->string('source_evaluador', 191)->nullable()->after('source_mina');
            }
            if (! Schema::hasColumn('evaluacion_desempeno', 'source_key')) {
                $table->char('source_key', 64)->nullable()->after('source_evaluador');
            }
        });

        if ($this->columnIsRequired('mina_id')) {
            Schema::table('evaluacion_desempeno', fn (Blueprint $table) => $table->char('mina_id', 36)->nullable()->change());
        }

        if (! $this->indexExists('uq_eval_des_source_key')) {
            Schema::table('evaluacion_desempeno', fn (Blueprint $table) => $table->unique('source_key', 'uq_eval_des_source_key'));
        }
    }

    public function down(): void
    {
        // El historial confirmado y su trazabilidad no deben borrarse con rollback.
    }

    private function columnIsRequired(string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->where('column_name', $column)
            ->where('is_nullable', 'NO')
            ->exists();
    }

    private function indexExists(string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_desempeno')
            ->where('index_name', $index)
            ->exists();
    }
};
