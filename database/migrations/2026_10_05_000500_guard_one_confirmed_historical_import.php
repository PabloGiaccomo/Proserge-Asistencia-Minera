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
            return;
        }

        if (! Schema::hasColumn('evaluaciones_import_lotes', 'confirmation_key')) {
            Schema::table('evaluaciones_import_lotes', fn (Blueprint $table) => $table->string('confirmation_key', 32)->nullable());
        }

        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'evaluaciones_import_lotes')
            ->where('index_name', 'uq_eval_hist_confirmation')
            ->exists();
        if (! $exists) {
            Schema::table('evaluaciones_import_lotes', fn (Blueprint $table) => $table->unique('confirmation_key', 'uq_eval_hist_confirmation'));
        }
    }

    public function down(): void
    {
        // El candado protege un lote confirmado; no retirarlo en un rollback.
    }
};
