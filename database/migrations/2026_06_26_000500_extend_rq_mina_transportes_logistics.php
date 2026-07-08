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
            $this->addColumnIfMissing('origen', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN origen VARCHAR(30) NULL AFTER unidad_carga");
            $this->addColumnIfMissing('placas_asignadas', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN placas_asignadas TEXT NULL AFTER unidades_transporte");
            $this->addColumnIfMissing('fecha_inicio', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN fecha_inicio DATE NULL AFTER placas_asignadas");
            $this->addColumnIfMissing('fecha_fin', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN fecha_fin DATE NULL AFTER fecha_inicio");
            $this->addColumnIfMissing('dias_uso', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN dias_uso INT UNSIGNED NULL AFTER fecha_fin");
            $this->addColumnIfMissing('estado_logistico', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN estado_logistico VARCHAR(40) NOT NULL DEFAULT 'REQUERIDO' AFTER dias_uso");
            $this->addColumnIfMissing('comentario_cambio', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN comentario_cambio TEXT NULL AFTER indicaciones");
            $this->addColumnIfMissing('incidencia_operativa', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN incidencia_operativa TEXT NULL AFTER comentario_cambio");
            $this->addColumnIfMissing('recepcion_fecha', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN recepcion_fecha DATE NULL AFTER incidencia_operativa");
            $this->addColumnIfMissing('recepcion_estado', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN recepcion_estado VARCHAR(40) NOT NULL DEFAULT 'PENDIENTE' AFTER recepcion_fecha");
            $this->addColumnIfMissing('recepcion_observacion', "ALTER TABLE rq_mina_actividad_transportes ADD COLUMN recepcion_observacion TEXT NULL AFTER recepcion_estado");
        }

        if (!Schema::hasTable('rq_mina_actividad_transporte_eventos')) {
            Schema::create('rq_mina_actividad_transporte_eventos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36)->index();
                $table->char('transporte_id', 36)->nullable()->index();
                $table->string('tipo', 50);
                $table->string('estado_anterior', 40)->nullable();
                $table->string('estado_nuevo', 40)->nullable();
                $table->text('descripcion')->nullable();
                $table->json('transporte_snapshot')->nullable();
                $table->timestamp('fecha_evento')->nullable();
                $table->char('usuario_id', 36)->nullable()->index();
                $table->timestamps();

                $table->foreign('rq_mina_id')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rq_mina_actividad_transporte_eventos');

        if (!Schema::hasTable('rq_mina_actividad_transportes')) {
            return;
        }

        foreach ([
            'recepcion_observacion',
            'recepcion_estado',
            'recepcion_fecha',
            'incidencia_operativa',
            'comentario_cambio',
            'estado_logistico',
            'dias_uso',
            'fecha_fin',
            'fecha_inicio',
            'placas_asignadas',
            'origen',
        ] as $column) {
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
