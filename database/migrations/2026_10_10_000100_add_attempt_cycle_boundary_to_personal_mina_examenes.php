<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_mina_examenes')
            && ! Schema::hasColumn('personal_mina_examenes', 'intentos_ciclo_desde')) {
            Schema::table('personal_mina_examenes', function (Blueprint $table): void {
                $table->timestamp('intentos_ciclo_desde')->nullable()->after('fecha_actualizacion');
            });
        }
        if (Schema::hasTable('personal_mina_examenes')
            && ! Schema::hasColumn('personal_mina_examenes', 'intentos_ciclo_numero_desde')) {
            Schema::table('personal_mina_examenes', function (Blueprint $table): void {
                $table->unsignedInteger('intentos_ciclo_numero_desde')->nullable()->after('intentos_ciclo_desde');
            });
        }
        if (Schema::hasTable('personal_mina_examenes')
            && Schema::hasColumn('personal_mina_examenes', 'intentos_ciclo_desde')
            && ! Schema::hasIndex('personal_mina_examenes', 'idx_examen_ciclo_intentos')) {
            Schema::table('personal_mina_examenes', function (Blueprint $table): void {
                $table->index(['personal_mina_id', 'intentos_ciclo_desde'], 'idx_examen_ciclo_intentos');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_mina_examenes')
            && Schema::hasColumn('personal_mina_examenes', 'intentos_ciclo_numero_desde')) {
            Schema::table('personal_mina_examenes', function (Blueprint $table): void {
                $table->dropColumn('intentos_ciclo_numero_desde');
            });
        }
        if (Schema::hasTable('personal_mina_examenes')
            && Schema::hasIndex('personal_mina_examenes', 'idx_examen_ciclo_intentos')) {
            Schema::table('personal_mina_examenes', fn (Blueprint $table) => $table->dropIndex('idx_examen_ciclo_intentos'));
        }
        if (Schema::hasTable('personal_mina_examenes')
            && Schema::hasColumn('personal_mina_examenes', 'intentos_ciclo_desde')) {
            Schema::table('personal_mina_examenes', fn (Blueprint $table) => $table->dropColumn('intentos_ciclo_desde'));
        }
    }
};
