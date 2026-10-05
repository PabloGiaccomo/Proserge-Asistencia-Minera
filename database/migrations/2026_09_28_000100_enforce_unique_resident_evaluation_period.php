<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'uq_eval_res_residente_periodo';

    public function up(): void
    {
        if (! Schema::hasTable('evaluacion_residente')) {
            return;
        }

        if (! Schema::hasColumn('evaluacion_residente', 'periodo_mes')) {
            throw new RuntimeException('Falta periodo_mes en evaluacion_residente. Ejecuta antes las migraciones historicas.');
        }

        $duplicates = DB::table('evaluacion_residente')
            ->selectRaw("residente_id, DATE_FORMAT(COALESCE(periodo_mes, fecha), '%Y-%m-01') AS mes")
            ->groupBy('residente_id', 'mes')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Hay evaluaciones de residente duplicadas por mes. Revisalas antes de crear el indice unico; no se modifico el historial.');
        }

        DB::table('evaluacion_residente')->update([
            'periodo_mes' => DB::raw("DATE_FORMAT(COALESCE(periodo_mes, fecha), '%Y-%m-01')"),
        ]);

        Schema::table('evaluacion_residente', function (Blueprint $table): void {
            $table->date('periodo_mes')->nullable(false)->change();
        });

        if (! $this->indexExists()) {
            Schema::table('evaluacion_residente', function (Blueprint $table): void {
                $table->unique(['residente_id', 'periodo_mes'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('evaluacion_residente')) {
            return;
        }

        if ($this->indexExists()) {
            Schema::table('evaluacion_residente', fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        }

        Schema::table('evaluacion_residente', function (Blueprint $table): void {
            $table->date('periodo_mes')->nullable()->change();
        });
    }

    private function indexExists(): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluacion_residente')
            ->where('index_name', self::INDEX)
            ->exists();
    }
};
