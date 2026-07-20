<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_transportes')) {
            return;
        }

        if (!Schema::hasColumn('rq_mina_actividad_transportes', 'documentos')) {
            DB::statement("ALTER TABLE rq_mina_actividad_transportes ADD COLUMN documentos JSON NULL AFTER doc_checklist_path");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_transportes')) {
            return;
        }

        if (Schema::hasColumn('rq_mina_actividad_transportes', 'documentos')) {
            DB::statement("ALTER TABLE rq_mina_actividad_transportes DROP COLUMN documentos");
        }
    }
};
