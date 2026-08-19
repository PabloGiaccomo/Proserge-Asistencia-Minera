<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grupo_trabajo')) {
            Schema::table('grupo_trabajo', function (Blueprint $table): void {
                if (!Schema::hasColumn('grupo_trabajo', 'rq_mina_plan_id')) {
                    $table->char('rq_mina_plan_id', 36)->nullable()->after('rq_mina_id');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'rq_mina_actividad_grupo_id')) {
                    $table->char('rq_mina_actividad_grupo_id', 36)->nullable()->after('rq_mina_plan_id');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'codigo_grupo')) {
                    $table->string('codigo_grupo', 80)->nullable()->after('rq_mina_actividad_grupo_id');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'nombre_snapshot')) {
                    $table->string('nombre_snapshot')->nullable()->after('codigo_grupo');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'area_snapshot')) {
                    $table->string('area_snapshot')->nullable()->after('nombre_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'modulo_snapshot')) {
                    $table->string('modulo_snapshot')->nullable()->after('area_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'sait_snapshot')) {
                    $table->text('sait_snapshot')->nullable()->after('modulo_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'supervisor_operativo_snapshot')) {
                    $table->string('supervisor_operativo_snapshot')->nullable()->after('sait_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'supervisor_seguridad_snapshot')) {
                    $table->string('supervisor_seguridad_snapshot')->nullable()->after('supervisor_operativo_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'cantidad_planificada_snapshot')) {
                    $table->unsignedInteger('cantidad_planificada_snapshot')->nullable()->after('supervisor_seguridad_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'observacion_planificacion')) {
                    $table->text('observacion_planificacion')->nullable()->after('observaciones');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'justificacion_brecha')) {
                    $table->text('justificacion_brecha')->nullable()->after('observacion_planificacion');
                }

                if (!Schema::hasColumn('grupo_trabajo', 'updated_by_id')) {
                    $table->char('updated_by_id', 36)->nullable()->after('created_by_id');
                }
            });

            $this->addIndexIfMissing('grupo_trabajo', 'idx_grupo_trabajo_rq_fecha_turno', ['rq_mina_id', 'fecha', 'turno']);
            $this->addIndexIfMissing('grupo_trabajo', 'idx_grupo_trabajo_plan_fecha_turno', ['rq_mina_plan_id', 'fecha', 'turno']);
            $this->addIndexIfMissing('grupo_trabajo', 'idx_grupo_trabajo_act_grupo_fecha_turno', ['rq_mina_actividad_grupo_id', 'fecha', 'turno']);
            $this->addIndexIfMissing('grupo_trabajo', 'idx_grupo_trabajo_rp_fecha_turno', ['rq_proserge_id', 'fecha', 'turno']);

            $this->addForeignIfMissing('grupo_trabajo', 'fk_grupo_trabajo_plan', 'rq_mina_plan_id', 'rq_mina_planes', 'id');
            $this->addForeignIfMissing('grupo_trabajo', 'fk_grupo_trabajo_actividad_grupo', 'rq_mina_actividad_grupo_id', 'rq_mina_actividad_grupos', 'id');
            $this->addForeignIfMissing('grupo_trabajo', 'fk_grupo_trabajo_updated_by', 'updated_by_id', 'usuarios', 'id');
        }

        if (!Schema::hasTable('grupo_trabajo_actividades')) {
            Schema::create('grupo_trabajo_actividades', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('grupo_trabajo_id', 36);
                $table->char('rq_mina_actividad_id', 36);
                $table->unsignedInteger('cantidad_planificada_snapshot')->nullable();
                $table->timestamps();

                $table->unique(['grupo_trabajo_id', 'rq_mina_actividad_id'], 'uq_grupo_trabajo_actividad');
                $table->index('rq_mina_actividad_id', 'idx_grupo_trabajo_actividades_actividad');

                $table->foreign('grupo_trabajo_id', 'fk_grupo_trabajo_actividades_grupo')
                    ->references('id')
                    ->on('grupo_trabajo')
                    ->cascadeOnDelete();

                $table->foreign('rq_mina_actividad_id', 'fk_grupo_trabajo_actividades_actividad')
                    ->references('id')
                    ->on('rq_mina_actividades')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('grupo_trabajo_detalle')) {
            Schema::table('grupo_trabajo_detalle', function (Blueprint $table): void {
                if (!Schema::hasColumn('grupo_trabajo_detalle', 'rq_proserge_detalle_id')) {
                    $table->char('rq_proserge_detalle_id', 36)->nullable()->after('personal_id');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'puesto_asignado_snapshot')) {
                    $table->string('puesto_asignado_snapshot')->nullable()->after('rq_proserge_detalle_id');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'posicion_asignacion_snapshot')) {
                    $table->string('posicion_asignacion_snapshot', 20)->nullable()->after('puesto_asignado_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'tipo_asignacion_snapshot')) {
                    $table->string('tipo_asignacion_snapshot', 20)->nullable()->after('posicion_asignacion_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'estado_habilitacion_snapshot')) {
                    $table->string('estado_habilitacion_snapshot', 40)->nullable()->after('tipo_asignacion_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'estado_distribucion')) {
                    $table->string('estado_distribucion', 20)->default('ASIGNADO')->after('estado_habilitacion_snapshot');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'asignado_por_id')) {
                    $table->char('asignado_por_id', 36)->nullable()->after('estado_distribucion');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'asignado_at')) {
                    $table->timestamp('asignado_at')->nullable()->after('asignado_por_id');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'retirado_por_id')) {
                    $table->char('retirado_por_id', 36)->nullable()->after('asignado_at');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'retirado_at')) {
                    $table->timestamp('retirado_at')->nullable()->after('retirado_por_id');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'motivo_retiro')) {
                    $table->text('motivo_retiro')->nullable()->after('retirado_at');
                }

                if (!Schema::hasColumn('grupo_trabajo_detalle', 'observacion')) {
                    $table->text('observacion')->nullable()->after('motivo_retiro');
                }
            });

            $this->addIndexIfMissing('grupo_trabajo_detalle', 'idx_grupo_detalle_rq_proserge_detalle', ['rq_proserge_detalle_id']);
            $this->addIndexIfMissing('grupo_trabajo_detalle', 'idx_grupo_detalle_grupo_estado_dist', ['grupo_trabajo_id', 'estado_distribucion']);
            $this->addIndexIfMissing('grupo_trabajo_detalle', 'idx_grupo_detalle_asignado_at', ['asignado_at']);
            $this->addIndexIfMissing('grupo_trabajo_detalle', 'idx_grupo_detalle_retirado_at', ['retirado_at']);

            $this->addForeignIfMissing('grupo_trabajo_detalle', 'fk_grupo_detalle_rq_proserge_detalle', 'rq_proserge_detalle_id', 'rq_proserge_detalle', 'id');
            $this->addForeignIfMissing('grupo_trabajo_detalle', 'fk_grupo_detalle_asignado_por', 'asignado_por_id', 'usuarios', 'id');
            $this->addForeignIfMissing('grupo_trabajo_detalle', 'fk_grupo_detalle_retirado_por', 'retirado_por_id', 'usuarios', 'id');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('grupo_trabajo_detalle')) {
            foreach ([
                'fk_grupo_detalle_retirado_por',
                'fk_grupo_detalle_asignado_por',
                'fk_grupo_detalle_rq_proserge_detalle',
            ] as $foreign) {
                $this->dropForeignIfExists('grupo_trabajo_detalle', $foreign);
            }

            foreach ([
                'idx_grupo_detalle_retirado_at',
                'idx_grupo_detalle_asignado_at',
                'idx_grupo_detalle_grupo_estado_dist',
                'idx_grupo_detalle_rq_proserge_detalle',
            ] as $index) {
                $this->dropIndexIfExists('grupo_trabajo_detalle', $index);
            }

            Schema::table('grupo_trabajo_detalle', function (Blueprint $table): void {
                foreach ([
                    'observacion',
                    'motivo_retiro',
                    'retirado_at',
                    'retirado_por_id',
                    'asignado_at',
                    'asignado_por_id',
                    'estado_distribucion',
                    'estado_habilitacion_snapshot',
                    'tipo_asignacion_snapshot',
                    'posicion_asignacion_snapshot',
                    'puesto_asignado_snapshot',
                    'rq_proserge_detalle_id',
                ] as $column) {
                    if (Schema::hasColumn('grupo_trabajo_detalle', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('grupo_trabajo_actividades');

        if (Schema::hasTable('grupo_trabajo')) {
            foreach ([
                'fk_grupo_trabajo_updated_by',
                'fk_grupo_trabajo_actividad_grupo',
                'fk_grupo_trabajo_plan',
            ] as $foreign) {
                $this->dropForeignIfExists('grupo_trabajo', $foreign);
            }

            foreach ([
                'idx_grupo_trabajo_rp_fecha_turno',
                'idx_grupo_trabajo_act_grupo_fecha_turno',
                'idx_grupo_trabajo_plan_fecha_turno',
                'idx_grupo_trabajo_rq_fecha_turno',
            ] as $index) {
                $this->dropIndexIfExists('grupo_trabajo', $index);
            }

            Schema::table('grupo_trabajo', function (Blueprint $table): void {
                foreach ([
                    'updated_by_id',
                    'justificacion_brecha',
                    'observacion_planificacion',
                    'cantidad_planificada_snapshot',
                    'supervisor_seguridad_snapshot',
                    'supervisor_operativo_snapshot',
                    'sait_snapshot',
                    'modulo_snapshot',
                    'area_snapshot',
                    'nombre_snapshot',
                    'codigo_grupo',
                    'rq_mina_actividad_grupo_id',
                    'rq_mina_plan_id',
                ] as $column) {
                    if (Schema::hasColumn('grupo_trabajo', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
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
        if (!Schema::hasTable($referencesTable) || $this->foreignExists($table, $foreign)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $foreign, $referencesColumn, $referencesTable): void {
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
