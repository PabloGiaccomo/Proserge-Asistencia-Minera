<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('parada_herramienta_items', 'incidencia_durante_parada')) {
                $table->text('incidencia_durante_parada')->nullable()->after('observaciones');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'recepcion_estado')) {
                $table->string('recepcion_estado', 30)->default('PENDIENTE')->after('cantidad_recibida');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'recepcion_fecha')) {
                $table->date('recepcion_fecha')->nullable()->after('recepcion_estado');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'recepcion_observacion')) {
                $table->text('recepcion_observacion')->nullable()->after('recepcion_fecha');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'recepcion_registrada_at')) {
                $table->timestamp('recepcion_registrada_at')->nullable()->after('recepcion_observacion');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'recepcion_registrada_por_usuario_id')) {
                $table->char('recepcion_registrada_por_usuario_id', 36)->nullable()->after('recepcion_registrada_at');
            }

            if (!Schema::hasColumn('parada_herramienta_items', 'comentario_cambio_previo')) {
                $table->text('comentario_cambio_previo')->nullable()->after('recepcion_registrada_por_usuario_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('parada_herramienta_items')) {
            return;
        }

        Schema::table('parada_herramienta_items', function (Blueprint $table): void {
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
                    $table->dropColumn($column);
                }
            }
        });
    }
};
