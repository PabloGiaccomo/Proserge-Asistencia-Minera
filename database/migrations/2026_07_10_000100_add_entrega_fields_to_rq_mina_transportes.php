<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_transportes')) {
            return;
        }

        $this->addColumnIfMissing('capacidad_camion', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN capacidad_camion VARCHAR(50) NULL AFTER placas_asignadas");
        $this->addColumnIfMissing('doc_vehiculo_path', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN doc_vehiculo_path TEXT NULL AFTER recepcion_observacion");
        $this->addColumnIfMissing('doc_proserge_path', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN doc_proserge_path TEXT NULL AFTER doc_vehiculo_path");
        $this->addColumnIfMissing('doc_mantenimiento_path', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN doc_mantenimiento_path TEXT NULL AFTER doc_proserge_path");
        $this->addColumnIfMissing('doc_checklist_path', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN doc_checklist_path TEXT NULL AFTER doc_mantenimiento_path");
    }

    public function down(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_transportes')) {
            return;
        }

        foreach (['capacidad_camion', 'doc_vehiculo_path', 'doc_proserge_path', 'doc_mantenimiento_path', 'doc_checklist_path'] as $column) {
            if (Schema::hasColumn('rq_mina_actividad_transportes', $column)) {
                DB::statement("ALTER TABLE rq_mina_actividad_transportes DROP COLUMN {$column}");
            }
        }
    }

    private function addColumnIfMissing(string $column, string $statement): void
    {
        if (Schema::hasColumn('rq_mina_actividad_transportes', $column)) {
            return;
        }

        DB::statement($statement);
    }
};
