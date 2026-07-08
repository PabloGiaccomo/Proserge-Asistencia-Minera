<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        $this->addColumnIfMissing(
            'incidencia_durante_parada',
            'ALTER TABLE parada_herramienta_items ADD COLUMN incidencia_durante_parada TEXT NULL AFTER observaciones'
        );
        $this->addColumnIfMissing(
            'recepcion_estado',
            "ALTER TABLE parada_herramienta_items ADD COLUMN recepcion_estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE' AFTER cantidad_recibida"
        );
        $this->addColumnIfMissing(
            'recepcion_fecha',
            'ALTER TABLE parada_herramienta_items ADD COLUMN recepcion_fecha DATE NULL AFTER recepcion_estado'
        );
        $this->addColumnIfMissing(
            'recepcion_observacion',
            'ALTER TABLE parada_herramienta_items ADD COLUMN recepcion_observacion TEXT NULL AFTER recepcion_fecha'
        );
        $this->addColumnIfMissing(
            'recepcion_registrada_at',
            'ALTER TABLE parada_herramienta_items ADD COLUMN recepcion_registrada_at TIMESTAMP NULL AFTER recepcion_observacion'
        );
        $this->addColumnIfMissing(
            'recepcion_registrada_por_usuario_id',
            'ALTER TABLE parada_herramienta_items ADD COLUMN recepcion_registrada_por_usuario_id CHAR(36) NULL AFTER recepcion_registrada_at'
        );
        $this->addColumnIfMissing(
            'comentario_cambio_previo',
            'ALTER TABLE parada_herramienta_items ADD COLUMN comentario_cambio_previo TEXT NULL AFTER recepcion_registrada_por_usuario_id'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        foreach ([
            'comentario_cambio_previo',
            'recepcion_registrada_por_usuario_id',
            'recepcion_registrada_at',
            'recepcion_observacion',
            'recepcion_fecha',
            'recepcion_estado',
            'incidencia_durante_parada',
        ] as $column) {
            if (Schema::hasColumn('parada_herramienta_items', $column)) {
                DB::statement("ALTER TABLE parada_herramienta_items DROP COLUMN {$column}");
            }
        }
    }

    private function addColumnIfMissing(string $column, string $statement): void
    {
        if (Schema::hasColumn('parada_herramienta_items', $column)) {
            return;
        }

        DB::statement($statement);
    }
};
