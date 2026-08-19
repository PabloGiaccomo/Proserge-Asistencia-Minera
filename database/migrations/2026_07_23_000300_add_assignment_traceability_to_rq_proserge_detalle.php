<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rq_proserge_detalle', function (Blueprint $table): void {
            if (!Schema::hasColumn('rq_proserge_detalle', 'posicion_asignacion')) {
                $table->string('posicion_asignacion', 20)->nullable()->after('ultimo_turno_referencia');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'tipo_asignacion')) {
                $table->string('tipo_asignacion', 20)->nullable()->after('posicion_asignacion');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'puesto_asignado_snapshot')) {
                $table->string('puesto_asignado_snapshot')->nullable()->after('puesto_asignado');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'estado_habilitacion_snapshot')) {
                $table->string('estado_habilitacion_snapshot', 40)->nullable()->after('tipo_asignacion');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'disponibilidad_snapshot')) {
                $table->json('disponibilidad_snapshot')->nullable()->after('estado_habilitacion_snapshot');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'asignado_por_id')) {
                $table->char('asignado_por_id', 36)->nullable()->after('disponibilidad_snapshot');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'asignado_at')) {
                $table->timestamp('asignado_at')->nullable()->after('asignado_por_id');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'actualizado_por_id')) {
                $table->char('actualizado_por_id', 36)->nullable()->after('asignado_at');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'reemplaza_a_id')) {
                $table->char('reemplaza_a_id', 36)->nullable()->after('actualizado_por_id');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'retirado_por_id')) {
                $table->char('retirado_por_id', 36)->nullable()->after('reemplaza_a_id');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'retirado_at')) {
                $table->timestamp('retirado_at')->nullable()->after('retirado_por_id');
            }

            if (!Schema::hasColumn('rq_proserge_detalle', 'motivo_retiro')) {
                $table->text('motivo_retiro')->nullable()->after('retirado_at');
            }
        });

        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_unique_legacy', ['rq_proserge_id', 'rq_mina_detalle_id', 'personal_id', 'fecha_inicio', 'fecha_fin']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_rq_estado', ['rq_proserge_id', 'estado']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_posicion', ['rq_mina_detalle_id', 'posicion_asignacion']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_tipo', ['rq_mina_detalle_id', 'tipo_asignacion']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_personal_rango', ['personal_id', 'fecha_inicio', 'fecha_fin']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_asignado_por', ['asignado_por_id']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_reemplaza', ['reemplaza_a_id']);
        $this->addIndexIfMissing('rq_proserge_detalle', 'idx_rq_proserge_detalle_retirado_at', ['retirado_at']);
        $this->dropIndexIfExists('rq_proserge_detalle', 'uq_rq_proserge_detalle');

        $this->addForeignIfMissing('rq_proserge_detalle', 'fk_rq_proserge_detalle_asignado_por', 'asignado_por_id', 'usuarios', 'id');
        $this->addForeignIfMissing('rq_proserge_detalle', 'fk_rq_proserge_detalle_actualizado_por', 'actualizado_por_id', 'usuarios', 'id');
        $this->addForeignIfMissing('rq_proserge_detalle', 'fk_rq_proserge_detalle_retirado_por', 'retirado_por_id', 'usuarios', 'id');
        $this->addForeignIfMissing('rq_proserge_detalle', 'fk_rq_proserge_detalle_reemplaza', 'reemplaza_a_id', 'rq_proserge_detalle', 'id');
    }

    public function down(): void
    {
        foreach ([
            'fk_rq_proserge_detalle_asignado_por',
            'fk_rq_proserge_detalle_actualizado_por',
            'fk_rq_proserge_detalle_retirado_por',
            'fk_rq_proserge_detalle_reemplaza',
        ] as $foreign) {
            $this->dropForeignIfExists('rq_proserge_detalle', $foreign);
        }

        foreach ([
            'idx_rq_proserge_detalle_retirado_at',
            'idx_rq_proserge_detalle_reemplaza',
            'idx_rq_proserge_detalle_asignado_por',
            'idx_rq_proserge_detalle_personal_rango',
            'idx_rq_proserge_detalle_tipo',
            'idx_rq_proserge_detalle_posicion',
            'idx_rq_proserge_detalle_rq_estado',
            'idx_rq_proserge_detalle_unique_legacy',
        ] as $index) {
            $this->dropIndexIfExists('rq_proserge_detalle', $index);
        }

        Schema::table('rq_proserge_detalle', function (Blueprint $table): void {
            foreach ([
                'motivo_retiro',
                'retirado_at',
                'retirado_por_id',
                'reemplaza_a_id',
                'actualizado_por_id',
                'asignado_at',
                'asignado_por_id',
                'disponibilidad_snapshot',
                'estado_habilitacion_snapshot',
                'tipo_asignacion',
                'posicion_asignacion',
                'puesto_asignado_snapshot',
            ] as $column) {
                if (Schema::hasColumn('rq_proserge_detalle', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index, $columns): void {
            $blueprint->index($columns, $index);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (!$this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    private function addForeignIfMissing(string $table, string $foreign, string $column, string $referencesTable, string $referencesColumn): void
    {
        if ($this->foreignExists($table, $foreign)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($foreign, $column, $referencesTable, $referencesColumn): void {
            $blueprint->foreign($column, $foreign)->references($referencesColumn)->on($referencesTable)->nullOnDelete();
        });
    }

    private function dropForeignIfExists(string $table, string $foreign): void
    {
        if (!$this->foreignExists($table, $foreign)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($foreign): void {
            $blueprint->dropForeign($foreign);
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

    private function foreignExists(string $table, string $foreign): bool
    {
        return DB::table('information_schema.table_constraints')
            ->whereRaw('constraint_schema = database()')
            ->where('table_name', $table)
            ->where('constraint_name', $foreign)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
