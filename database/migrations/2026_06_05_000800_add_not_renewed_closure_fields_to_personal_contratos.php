<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal_contratos', 'fecha_cierre_no_renovacion')) {
                $table->timestamp('fecha_cierre_no_renovacion')->nullable()->after('fecha_decision');
            }

            if (!Schema::hasColumn('personal_contratos', 'usuario_cierre_no_renovacion_id')) {
                $table->char('usuario_cierre_no_renovacion_id', 36)->nullable()->after('fecha_cierre_no_renovacion')->index();
            }

            if (!Schema::hasColumn('personal_contratos', 'observacion_cierre_no_renovacion')) {
                $table->text('observacion_cierre_no_renovacion')->nullable()->after('usuario_cierre_no_renovacion_id');
            }

            if (!Schema::hasColumn('personal_contratos', 'motivo_cese_controlado')) {
                $table->string('motivo_cese_controlado', 80)->nullable()->after('observacion_cierre_no_renovacion');
            }

            if (!Schema::hasColumn('personal_contratos', 'observacion_cese_controlado')) {
                $table->text('observacion_cese_controlado')->nullable()->after('motivo_cese_controlado');
            }

            if (!Schema::hasColumn('personal_contratos', 'fecha_cese_controlado')) {
                $table->date('fecha_cese_controlado')->nullable()->after('observacion_cese_controlado');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if (Schema::hasColumn('personal_contratos', 'usuario_cierre_no_renovacion_id')) {
                $table->dropIndex(['usuario_cierre_no_renovacion_id']);
            }

            foreach ([
                'fecha_cese_controlado',
                'observacion_cese_controlado',
                'motivo_cese_controlado',
                'observacion_cierre_no_renovacion',
                'usuario_cierre_no_renovacion_id',
                'fecha_cierre_no_renovacion',
            ] as $column) {
                if (Schema::hasColumn('personal_contratos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
