<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Repair partially restored schemas without rewriting historical assignments.
        $columns = [
            'posicion_asignacion' => ['string', 20],
            'tipo_asignacion' => ['string', 20],
            'puesto_asignado_snapshot' => ['string', 255],
            'estado_habilitacion_snapshot' => ['string', 40],
            'disponibilidad_snapshot' => ['json', null],
            'asignado_por_id' => ['char', 36],
            'asignado_at' => ['timestamp', null],
            'actualizado_por_id' => ['char', 36],
            'reemplaza_a_id' => ['char', 36],
            'retirado_por_id' => ['char', 36],
            'retirado_at' => ['timestamp', null],
            'motivo_retiro' => ['text', null],
        ];

        foreach ($columns as $name => [$type, $length]) {
            if (Schema::hasColumn('rq_proserge_detalle', $name)) {
                continue;
            }

            Schema::table('rq_proserge_detalle', function (Blueprint $table) use ($name, $type, $length): void {
                $column = $length === null ? $table->{$type}($name) : $table->{$type}($name, $length);
                $column->nullable();
            });
        }
    }

    public function down(): void
    {
        // Audit data must survive application rollbacks.
    }
};
