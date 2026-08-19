<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rq_mina_actividad_transportes')) {
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'rq_mina_plan_id', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN rq_mina_plan_id CHAR(36) NULL AFTER actividad_id");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'fecha', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN fecha DATE NULL AFTER unidad_carga");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'turno', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN turno VARCHAR(10) NULL AFTER fecha");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'tipo_transporte', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN tipo_transporte VARCHAR(20) NULL AFTER turno");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'capacidad_requerida', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN capacidad_requerida INT UNSIGNED NULL AFTER tipo_transporte");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'cantidad_unidades_requeridas', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN cantidad_unidades_requeridas INT UNSIGNED NULL AFTER capacidad_requerida");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'origen_snapshot', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN origen_snapshot VARCHAR(191) NULL AFTER origen");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'destino_snapshot', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN destino_snapshot VARCHAR(191) NULL AFTER origen_snapshot");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'observaciones', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN observaciones TEXT NULL AFTER indicaciones");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'created_by', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN created_by CHAR(36) NULL AFTER orden");
            $this->addColumnIfMissing('rq_mina_actividad_transportes', 'updated_by', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN updated_by CHAR(36) NULL AFTER created_by");

            $this->addIndexIfMissing('rq_mina_actividad_transportes', 'idx_rq_mina_act_trans_plan_fecha_turno', ['rq_mina_plan_id', 'fecha', 'turno']);
            $this->addIndexIfMissing('rq_mina_actividad_transportes', 'idx_rq_mina_act_trans_tipo', ['tipo_transporte']);
        }

        if (!Schema::hasTable('transporte_servicios')) {
            Schema::create('transporte_servicios', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36);
                $table->char('rq_mina_plan_id', 36)->nullable();
                $table->string('tipo', 20)->default('PERSONAL');
                $table->date('fecha');
                $table->string('turno', 10);
                $table->string('tramo', 30)->default('IDA');
                $table->string('transportista', 191)->nullable();
                $table->string('tipo_vehiculo', 120)->nullable();
                $table->string('placa', 50)->nullable();
                $table->char('conductor_personal_id', 36)->nullable();
                $table->string('conductor_nombre_snapshot', 191)->nullable();
                $table->unsignedInteger('capacidad')->nullable();
                $table->time('hora_salida')->nullable();
                $table->time('hora_retorno')->nullable();
                $table->string('origen', 191)->nullable();
                $table->string('destino', 191)->nullable();
                $table->string('estado', 30)->default('BORRADOR');
                $table->text('observaciones')->nullable();
                $table->char('created_by', 36)->nullable();
                $table->char('updated_by', 36)->nullable();
                $table->timestamps();

                $table->index(['rq_mina_id', 'fecha', 'turno'], 'idx_trans_serv_rq_fecha_turno');
                $table->index(['rq_mina_plan_id', 'fecha', 'turno'], 'idx_trans_serv_plan_fecha_turno');
                $table->index(['placa', 'fecha', 'turno', 'tramo'], 'idx_trans_serv_placa_fecha_turno');
                $table->index(['conductor_personal_id', 'fecha', 'turno', 'tramo'], 'idx_trans_serv_cond_fecha_turno');
                $table->index(['tipo', 'estado'], 'idx_trans_serv_tipo_estado');
                $table->foreign('rq_mina_id', 'fk_trans_serv_rq')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();
                $table->foreign('rq_mina_plan_id', 'fk_trans_serv_plan')
                    ->references('id')
                    ->on('rq_mina_planes')
                    ->nullOnDelete();
                $table->foreign('conductor_personal_id', 'fk_trans_serv_conductor')
                    ->references('id')
                    ->on('personal')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('transporte_servicio_alcances')) {
            Schema::create('transporte_servicio_alcances', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('transporte_servicio_id', 36);
                $table->char('rq_mina_actividad_grupo_id', 36)->nullable();
                $table->char('rq_mina_actividad_id', 36)->nullable();
                $table->char('grupo_trabajo_id', 36)->nullable();
                $table->string('sait_snapshot', 191)->nullable();
                $table->unsignedInteger('orden')->nullable();
                $table->timestamps();

                $table->index('transporte_servicio_id', 'idx_trans_alc_servicio');
                $table->index('rq_mina_actividad_grupo_id', 'idx_trans_alc_grupo_operativo');
                $table->index('rq_mina_actividad_id', 'idx_trans_alc_actividad');
                $table->index('grupo_trabajo_id', 'idx_trans_alc_grupo_trabajo');
                $table->foreign('transporte_servicio_id', 'fk_trans_alc_servicio')
                    ->references('id')
                    ->on('transporte_servicios')
                    ->cascadeOnDelete();
                $table->foreign('rq_mina_actividad_grupo_id', 'fk_trans_alc_grupo_operativo')
                    ->references('id')
                    ->on('rq_mina_actividad_grupos')
                    ->nullOnDelete();
                $table->foreign('rq_mina_actividad_id', 'fk_trans_alc_actividad')
                    ->references('id')
                    ->on('rq_mina_actividades')
                    ->nullOnDelete();
                $table->foreign('grupo_trabajo_id', 'fk_trans_alc_grupo_trabajo')
                    ->references('id')
                    ->on('grupo_trabajo')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('transporte_servicio_pasajeros')) {
            Schema::create('transporte_servicio_pasajeros', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('transporte_servicio_id', 36);
                $table->char('grupo_trabajo_detalle_id', 36);
                $table->char('personal_id', 36);
                $table->string('tramo', 30)->default('IDA');
                $table->string('estado', 30)->default('ASIGNADO');
                $table->char('asignado_por_id', 36)->nullable();
                $table->timestamp('asignado_at')->nullable();
                $table->char('retirado_por_id', 36)->nullable();
                $table->timestamp('retirado_at')->nullable();
                $table->text('motivo_retiro')->nullable();
                $table->timestamps();

                $table->index(['transporte_servicio_id', 'estado'], 'idx_trans_pas_serv_estado');
                $table->index(['personal_id', 'estado'], 'idx_trans_pas_personal_estado');
                $table->index(['grupo_trabajo_detalle_id', 'estado'], 'idx_trans_pas_detalle_estado');
                $table->foreign('transporte_servicio_id', 'fk_trans_pas_servicio')
                    ->references('id')
                    ->on('transporte_servicios')
                    ->cascadeOnDelete();
                $table->foreign('grupo_trabajo_detalle_id', 'fk_trans_pas_detalle')
                    ->references('id')
                    ->on('grupo_trabajo_detalle')
                    ->cascadeOnDelete();
                $table->foreign('personal_id', 'fk_trans_pas_personal')
                    ->references('id')
                    ->on('personal')
                    ->restrictOnDelete();
            });
        }

        if (!Schema::hasTable('transporte_servicio_eventos')) {
            Schema::create('transporte_servicio_eventos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('transporte_servicio_id', 36)->nullable();
                $table->string('tipo', 60);
                $table->string('estado_anterior', 30)->nullable();
                $table->string('estado_nuevo', 30)->nullable();
                $table->json('snapshot')->nullable();
                $table->text('observacion')->nullable();
                $table->char('usuario_id', 36)->nullable();
                $table->timestamp('fecha_evento')->nullable();
                $table->timestamps();

                $table->index('transporte_servicio_id', 'idx_trans_event_servicio');
                $table->index(['tipo', 'fecha_evento'], 'idx_trans_event_tipo_fecha');
                $table->foreign('transporte_servicio_id', 'fk_trans_event_servicio')
                    ->references('id')
                    ->on('transporte_servicios')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transporte_servicio_eventos');
        Schema::dropIfExists('transporte_servicio_pasajeros');
        Schema::dropIfExists('transporte_servicio_alcances');
        Schema::dropIfExists('transporte_servicios');
    }

    private function addColumnIfMissing(string $table, string $column, string $sql): void
    {
        if (!Schema::hasColumn($table, $column)) {
            DB::statement($sql);
        }
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        $exists = DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $schema) use ($columns, $index): void {
            $schema->index($columns, $index);
        });
    }
};
