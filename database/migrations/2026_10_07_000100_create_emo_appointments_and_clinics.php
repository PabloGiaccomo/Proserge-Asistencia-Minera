<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinicas_emo')) {
            Schema::create('clinicas_emo', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->string('nombre', 191);
                $table->string('nombre_normalizado', 191);
                $table->boolean('activo')->default(true);
                $table->char('created_by_usuario_id', 36)->nullable();
                $table->char('updated_by_usuario_id', 36)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('personal_emo_citas')) {
            Schema::create('personal_emo_citas', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_id', 36);
                $table->char('clinica_id', 36);
                $table->string('clinica_nombre_snapshot', 191);
                $table->date('fecha_programacion');
                $table->date('fecha_realizacion')->nullable();
                $table->string('estado', 20)->default('PROGRAMADA');
                $table->unsignedInteger('revision')->default(1);
                $table->string('resultado_emo', 30)->nullable();
                $table->text('detalle_resultado_emo')->nullable();
                $table->string('origen', 30);
                $table->char('usuario_programacion_id', 36)->nullable();
                $table->char('usuario_resultado_id', 36)->nullable();
                $table->char('usuario_actualizacion_id', 36)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('personal_emo_citas', 'revision')) {
            Schema::table('personal_emo_citas', function (Blueprint $table): void {
                $table->unsignedInteger('revision')->default(1);
            });
        }

        if (! Schema::hasTable('personal_emo_cita_historial')) {
            Schema::create('personal_emo_cita_historial', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('emo_cita_id', 36);
                $table->string('evento', 50);
                $table->json('valores_anteriores_json')->nullable();
                $table->json('valores_nuevos_json')->nullable();
                $table->char('usuario_id', 36)->nullable();
                $table->string('origen', 30);
                $table->timestamp('fecha_cambio');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('personal_emo_export_audits')) {
            Schema::create('personal_emo_export_audits', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('usuario_id', 36)->nullable();
                $table->json('filtros_json');
                $table->char('filtros_hash', 64);
                $table->unsignedInteger('cantidad_filas');
                $table->timestamp('exportado_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('examen_minero_categoria_historial')) {
            Schema::create('examen_minero_categoria_historial', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('examen_id', 36);
                $table->string('categoria_anterior', 10);
                $table->string('categoria_nueva', 10);
                $table->char('usuario_id', 36)->nullable();
                $table->text('motivo');
                $table->timestamp('fecha_cambio');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('examenes_mineros') && ! Schema::hasColumn('examenes_mineros', 'categoria_operativa')) {
            Schema::table('examenes_mineros', function (Blueprint $table): void {
                // No inference from names, tipo, observations or historical results.
                $table->string('categoria_operativa', 10)->default('OTRO');
            });
        }

        foreach (['personal_mina_examen_intentos', 'personal_curso_registros'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'emo_cita_id')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->char('emo_cita_id', 36)->nullable();
                });
            }
        }

        $this->addIndex('clinicas_emo', ['nombre_normalizado'], 'uq_clinicas_emo_nombre_normalizado', true);
        $this->addIndex('clinicas_emo', ['activo', 'nombre'], 'idx_clinicas_emo_activo_nombre');
        $this->addIndex('personal_emo_citas', ['personal_id', 'fecha_programacion'], 'uq_emo_cita_personal_fecha', true);
        $this->addIndex('personal_emo_citas', ['fecha_programacion', 'estado'], 'idx_emo_cita_fecha_estado');
        $this->addIndex('personal_emo_citas', ['personal_id', 'fecha_programacion', 'estado'], 'idx_emo_cita_personal_fecha_estado');
        $this->addIndex('personal_emo_cita_historial', ['emo_cita_id', 'fecha_cambio'], 'idx_emo_historial_cita_fecha');
        $this->addIndex('personal_emo_export_audits', ['usuario_id', 'exportado_at'], 'idx_emo_export_actor_fecha');
        $this->addIndex('examen_minero_categoria_historial', ['examen_id', 'fecha_cambio'], 'idx_examen_categoria_fecha');
        $this->addIndex('examenes_mineros', ['categoria_operativa', 'activo'], 'idx_examen_categoria_activo');
        $this->addIndex('personal_mina_examen_intentos', ['personal_mina_examen_id', 'emo_cita_id'], 'uq_examen_intento_emo_cita', true);
        $this->addIndex('personal_mina_examen_intentos', ['emo_cita_id'], 'idx_examen_intento_emo_cita');
        $this->addIndex('personal_curso_registros', ['emo_cita_id'], 'idx_curso_registro_emo_cita');

        foreach ([
            ['clinicas_emo', 'created_by_usuario_id', 'usuarios', 'fk_clinica_emo_creador', true],
            ['clinicas_emo', 'updated_by_usuario_id', 'usuarios', 'fk_clinica_emo_editor', true],
            ['personal_emo_citas', 'personal_id', 'personal', 'fk_emo_cita_personal', false],
            ['personal_emo_citas', 'clinica_id', 'clinicas_emo', 'fk_emo_cita_clinica', false],
            ['personal_emo_citas', 'usuario_programacion_id', 'usuarios', 'fk_emo_cita_programador', true],
            ['personal_emo_citas', 'usuario_resultado_id', 'usuarios', 'fk_emo_cita_registrador', true],
            ['personal_emo_citas', 'usuario_actualizacion_id', 'usuarios', 'fk_emo_cita_editor', true],
            ['personal_emo_cita_historial', 'emo_cita_id', 'personal_emo_citas', 'fk_emo_historial_cita', false],
            ['personal_emo_cita_historial', 'usuario_id', 'usuarios', 'fk_emo_historial_actor', true],
            ['personal_emo_export_audits', 'usuario_id', 'usuarios', 'fk_emo_export_actor', true],
            ['examen_minero_categoria_historial', 'examen_id', 'examenes_mineros', 'fk_examen_categoria_examen', false],
            ['examen_minero_categoria_historial', 'usuario_id', 'usuarios', 'fk_examen_categoria_actor', true],
            ['personal_mina_examen_intentos', 'emo_cita_id', 'personal_emo_citas', 'fk_examen_intento_emo_cita', false],
            ['personal_curso_registros', 'emo_cita_id', 'personal_emo_citas', 'fk_curso_registro_emo_cita', false],
        ] as [$table, $column, $target, $name, $nullOnDelete]) {
            $this->addForeignKey($table, $column, $target, $name, $nullOnDelete);
        }
    }

    public function down(): void
    {
        // Operational rollback reverts application code only. Keep additive
        // schema, medical outcomes, links and audit history intact.
    }

    private function addIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $columns, $unique ? 'unique' : null)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            if ($unique) {
                $blueprint->unique($columns, $name);
            } else {
                $blueprint->index($columns, $name);
            }
        });
    }

    private function addForeignKey(string $table, string $column, string $target, string $name, bool $nullOnDelete): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // Query the constraint column directly: legacy MariaDB deployments
        // cannot safely use the grouped referential-constraint introspection.
        $connection = DB::connection();
        $existing = $connection->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            .' AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ? LIMIT 1',
            [$connection->getDatabaseName(), $connection->getTablePrefix().$table, $column,
                $connection->getTablePrefix().$target, 'id'],
        );
        if ($existing !== null) {
            return;
        }

        // MySQL constraint names are unique within a database, also for
        // isolated prefixed schema variants used by integration tests.
        $name = $connection->getTablePrefix().$name;
        Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $name, $nullOnDelete): void {
            $foreignKey = $blueprint->foreign($column, $name)->references('id')->on($target);
            if ($nullOnDelete) {
                $foreignKey->nullOnDelete();
            } else {
                $foreignKey->restrictOnDelete();
            }
        });
    }
};
