<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addIndex('personal_mina', ['activo', 'personal_id', 'mina_id'], 'idx_pm_activo_personal_mina');
        $this->addIndex('personal_mina_examenes', ['estado', 'fecha_vencimiento'], 'idx_pme_estado_vencimiento');
        $this->addIndex('personal_mina_examen_intentos', ['resultado', 'fecha_programacion'], 'idx_pmei_resultado_programacion');
        $this->addIndex('mina_requisitos', ['activo', 'examen_id', 'mina_id'], 'idx_mr_activo_examen_mina');
        $this->addIndex('personal_curso_registros', ['personal_id', 'examen_id', 'resultado'], 'idx_pcr_personal_examen_resultado');
    }

    public function down(): void
    {
        $this->dropIndex('personal_mina', 'idx_pm_activo_personal_mina');
        $this->dropIndex('personal_mina_examenes', 'idx_pme_estado_vencimiento');
        $this->dropIndex('personal_mina_examen_intentos', 'idx_pmei_resultado_programacion');
        $this->dropIndex('mina_requisitos', 'idx_mr_activo_examen_mina');
        $this->dropIndex('personal_curso_registros', 'idx_pcr_personal_examen_resultado');
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }
};
