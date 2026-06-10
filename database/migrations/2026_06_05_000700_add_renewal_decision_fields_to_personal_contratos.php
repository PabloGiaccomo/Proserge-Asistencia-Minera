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
            if (!Schema::hasColumn('personal_contratos', 'estado_decision_renovacion')) {
                $table->string('estado_decision_renovacion', 40)->nullable()->after('observacion_renovacion');
            }

            if (!Schema::hasColumn('personal_contratos', 'decision_final')) {
                $table->string('decision_final', 30)->nullable()->after('estado_decision_renovacion');
            }

            if (!Schema::hasColumn('personal_contratos', 'motivo_no_renovacion')) {
                $table->string('motivo_no_renovacion', 80)->nullable()->after('decision_final');
            }

            if (!Schema::hasColumn('personal_contratos', 'observacion_decision')) {
                $table->text('observacion_decision')->nullable()->after('motivo_no_renovacion');
            }

            if (!Schema::hasColumn('personal_contratos', 'fecha_decision')) {
                $table->timestamp('fecha_decision')->nullable()->after('observacion_decision');
            }

            if (!Schema::hasColumn('personal_contratos', 'usuario_decision_id')) {
                $table->char('usuario_decision_id', 36)->nullable()->after('fecha_decision')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if (Schema::hasColumn('personal_contratos', 'usuario_decision_id')) {
                $table->dropIndex(['usuario_decision_id']);
            }

            foreach ([
                'usuario_decision_id',
                'fecha_decision',
                'observacion_decision',
                'motivo_no_renovacion',
                'decision_final',
                'estado_decision_renovacion',
            ] as $column) {
                if (Schema::hasColumn('personal_contratos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
