<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_turnos')) {
            return;
        }

        Schema::table('rq_mina_actividad_turnos', function (Blueprint $table): void {
            if (!Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_a')) {
                $table->string('real_turno_a', 191)->nullable()->after('turno_a');
            }

            if (!Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_b')) {
                $table->string('real_turno_b', 191)->nullable()->after('turno_b');
            }
        });

        if (Schema::hasColumn('rq_mina_actividad_turnos', 'real')) {
            DB::table('rq_mina_actividad_turnos')
                ->whereNull('real_turno_b')
                ->whereNotNull('real')
                ->update(['real_turno_b' => DB::raw('`real`')]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_turnos')) {
            return;
        }

        Schema::table('rq_mina_actividad_turnos', function (Blueprint $table): void {
            if (Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_b')) {
                $table->dropColumn('real_turno_b');
            }

            if (Schema::hasColumn('rq_mina_actividad_turnos', 'real_turno_a')) {
                $table->dropColumn('real_turno_a');
            }
        });
    }
};
