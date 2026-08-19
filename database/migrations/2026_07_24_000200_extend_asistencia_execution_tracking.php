<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asistencia_detalle')) {
            Schema::table('asistencia_detalle', function (Blueprint $table): void {
                if (!Schema::hasColumn('asistencia_detalle', 'grupo_trabajo_detalle_id')) {
                    $table->char('grupo_trabajo_detalle_id', 36)->nullable()->after('asistencia_id');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'rq_proserge_detalle_id')) {
                    $table->char('rq_proserge_detalle_id', 36)->nullable()->after('grupo_trabajo_detalle_id');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'puesto_snapshot')) {
                    $table->string('puesto_snapshot')->nullable()->after('trabajador_id');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'posicion_asignacion_snapshot')) {
                    $table->string('posicion_asignacion_snapshot', 20)->nullable()->after('puesto_snapshot');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'tipo_asignacion_snapshot')) {
                    $table->string('tipo_asignacion_snapshot', 20)->nullable()->after('posicion_asignacion_snapshot');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'estado_distribucion_snapshot')) {
                    $table->string('estado_distribucion_snapshot', 20)->nullable()->after('tipo_asignacion_snapshot');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'motivo_estado')) {
                    $table->text('motivo_estado')->nullable()->after('estado');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'origen_registro')) {
                    $table->string('origen_registro', 20)->nullable()->after('motivo_estado');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'marcado_por_id')) {
                    $table->char('marcado_por_id', 36)->nullable()->after('observaciones');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'marcado_at')) {
                    $table->timestamp('marcado_at')->nullable()->after('marcado_por_id');
                }

                if (!Schema::hasColumn('asistencia_detalle', 'updated_by')) {
                    $table->char('updated_by', 36)->nullable()->after('marcado_at');
                }
            });

            $this->addIndexIfMissing('asistencia_detalle', 'idx_asistencia_detalle_gtd', ['grupo_trabajo_detalle_id']);
            $this->addIndexIfMissing('asistencia_detalle', 'idx_asistencia_detalle_rqpd', ['rq_proserge_detalle_id']);
            $this->addIndexIfMissing('asistencia_detalle', 'idx_asistencia_detalle_trabajador', ['trabajador_id']);
            $this->addIndexIfMissing('asistencia_detalle', 'idx_asistencia_detalle_estado', ['estado']);
            $this->addIndexIfMissing('asistencia_detalle', 'idx_asistencia_detalle_marcado_at', ['marcado_at']);
            $this->addUniqueIfMissing('asistencia_detalle', 'uq_asistencia_detalle_gtd', ['asistencia_id', 'grupo_trabajo_detalle_id']);

            $this->addForeignIfMissing('asistencia_detalle', 'fk_asistencia_detalle_gtd', 'grupo_trabajo_detalle_id', 'grupo_trabajo_detalle', 'id', 'set null');
            $this->addForeignIfMissing('asistencia_detalle', 'fk_asistencia_detalle_rqpd', 'rq_proserge_detalle_id', 'rq_proserge_detalle', 'id', 'set null');
            $this->addForeignIfMissing('asistencia_detalle', 'fk_asistencia_detalle_marcado_por', 'marcado_por_id', 'usuarios', 'id', 'set null');
            $this->addForeignIfMissing('asistencia_detalle', 'fk_asistencia_detalle_updated_by', 'updated_by', 'usuarios', 'id', 'set null');
        }

        if (!Schema::hasTable('grupo_trabajo_detalle_actividades')) {
            Schema::create('grupo_trabajo_detalle_actividades', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('grupo_trabajo_detalle_id', 36);
                $table->char('rq_mina_actividad_id', 36);
                $table->boolean('es_principal')->default(true);
                $table->text('observacion')->nullable();
                $table->timestamps();

                $table->unique(['grupo_trabajo_detalle_id', 'rq_mina_actividad_id'], 'uq_gtd_actividad');
                $table->index('rq_mina_actividad_id', 'idx_gtd_actividades_actividad');
                $table->unique(['grupo_trabajo_detalle_id', 'es_principal'], 'uq_gtd_actividad_principal');

                $table->foreign('grupo_trabajo_detalle_id', 'fk_gtd_actividades_detalle')
                    ->references('id')
                    ->on('grupo_trabajo_detalle')
                    ->cascadeOnDelete();

                $table->foreign('rq_mina_actividad_id', 'fk_gtd_actividades_actividad')
                    ->references('id')
                    ->on('rq_mina_actividades')
                    ->restrictOnDelete();
            });
        }

        if (!Schema::hasTable('parada_ejecucion_resumen')) {
            Schema::create('parada_ejecucion_resumen', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36);
                $table->char('rq_mina_plan_id', 36)->nullable();
                $table->char('rq_mina_actividad_grupo_id', 36)->nullable();
                $table->char('rq_mina_actividad_id', 36)->nullable();
                $table->string('actividad_key', 50)->default('__GRUPO__');
                $table->date('fecha');
                $table->string('turno', 20);
                $table->integer('planificado')->default(0);
                $table->integer('programado')->default(0);
                $table->integer('presentes')->default(0);
                $table->integer('tardanzas')->default(0);
                $table->integer('ausentes')->default(0);
                $table->integer('justificados')->default(0);
                $table->integer('no_corresponde')->default(0);
                $table->integer('pendientes_marcacion')->default(0);
                $table->integer('titulares_presentes')->default(0);
                $table->integer('suplentes_presentes')->default(0);
                $table->integer('adicionales_presentes')->default(0);
                $table->integer('sin_clasificar_presentes')->default(0);
                $table->integer('personal_sin_actividad')->default(0);
                $table->integer('brecha_plan_programado')->default(0);
                $table->integer('brecha_programado_real')->default(0);
                $table->integer('brecha_plan_real')->default(0);
                $table->integer('exceso_programado')->default(0);
                $table->integer('exceso_real')->default(0);
                $table->decimal('porcentaje_programacion', 8, 2)->default(0);
                $table->decimal('porcentaje_asistencia', 8, 2)->default(0);
                $table->decimal('porcentaje_cumplimiento_real', 8, 2)->default(0);
                $table->boolean('asistencia_cerrada')->default(false);
                $table->boolean('datos_completos')->default(false);
                $table->timestamp('source_closed_at')->nullable();
                $table->timestamp('recalculated_at')->nullable();
                $table->timestamps();

                $table->unique(['rq_mina_plan_id', 'rq_mina_actividad_grupo_id', 'actividad_key', 'fecha', 'turno'], 'uq_parada_ejecucion_scope');
                $table->index(['rq_mina_id', 'fecha', 'turno'], 'idx_parada_ejecucion_rq_fecha_turno');
                $table->index('rq_mina_actividad_id', 'idx_parada_ejecucion_actividad');

                $table->foreign('rq_mina_id', 'fk_parada_ejecucion_rq')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();

                $table->foreign('rq_mina_plan_id', 'fk_parada_ejecucion_plan')
                    ->references('id')
                    ->on('rq_mina_planes')
                    ->nullOnDelete();

                $table->foreign('rq_mina_actividad_grupo_id', 'fk_parada_ejecucion_grupo')
                    ->references('id')
                    ->on('rq_mina_actividad_grupos')
                    ->nullOnDelete();

                $table->foreign('rq_mina_actividad_id', 'fk_parada_ejecucion_actividad')
                    ->references('id')
                    ->on('rq_mina_actividades')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parada_ejecucion_resumen');
        Schema::dropIfExists('grupo_trabajo_detalle_actividades');

        if (!Schema::hasTable('asistencia_detalle')) {
            return;
        }

        foreach ([
            'fk_asistencia_detalle_updated_by',
            'fk_asistencia_detalle_marcado_por',
            'fk_asistencia_detalle_rqpd',
            'fk_asistencia_detalle_gtd',
        ] as $foreign) {
            $this->dropForeignIfExists('asistencia_detalle', $foreign);
        }

        foreach ([
            'uq_asistencia_detalle_gtd',
            'idx_asistencia_detalle_marcado_at',
            'idx_asistencia_detalle_estado',
            'idx_asistencia_detalle_trabajador',
            'idx_asistencia_detalle_rqpd',
            'idx_asistencia_detalle_gtd',
        ] as $index) {
            $this->dropIndexIfExists('asistencia_detalle', $index);
        }

        Schema::table('asistencia_detalle', function (Blueprint $table): void {
            foreach ([
                'updated_by',
                'marcado_at',
                'marcado_por_id',
                'origen_registro',
                'motivo_estado',
                'estado_distribucion_snapshot',
                'tipo_asignacion_snapshot',
                'posicion_asignacion_snapshot',
                'puesto_snapshot',
                'rq_proserge_detalle_id',
                'grupo_trabajo_detalle_id',
            ] as $column) {
                if (Schema::hasColumn('asistencia_detalle', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->index($columns, $name));
    }

    private function addUniqueIfMissing(string $table, string $name, array $columns): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->unique($columns, $name));
    }

    private function addForeignIfMissing(string $table, string $name, string $column, string $referencesTable, string $referencesColumn, string $onDelete): void
    {
        if ($this->hasForeign($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $schema) use ($name, $column, $referencesTable, $referencesColumn, $onDelete): void {
            $foreign = $schema->foreign($column, $name)
                ->references($referencesColumn)
                ->on($referencesTable);

            if ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            } elseif ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }

    private function dropForeignIfExists(string $table, string $name): void
    {
        if (!$this->hasForeign($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->dropForeign($name));
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (!$this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->dropIndex($name));
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function hasForeign(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
