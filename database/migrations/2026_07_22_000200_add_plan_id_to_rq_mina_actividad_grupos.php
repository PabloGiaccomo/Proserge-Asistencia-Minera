<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_grupos') || !Schema::hasTable('rq_mina_planes')) {
            return;
        }

        Schema::table('rq_mina_actividad_grupos', function (Blueprint $table): void {
            if (!Schema::hasColumn('rq_mina_actividad_grupos', 'rq_mina_plan_id')) {
                $table->char('rq_mina_plan_id', 36)->nullable()->after('rq_mina_id');
                $table->index('rq_mina_plan_id', 'idx_rq_mina_act_grupos_plan');
                $table->foreign('rq_mina_plan_id', 'fk_rq_mina_act_grupos_plan')
                    ->references('id')
                    ->on('rq_mina_planes')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rq_mina_actividad_grupos') || !Schema::hasColumn('rq_mina_actividad_grupos', 'rq_mina_plan_id')) {
            return;
        }

        Schema::table('rq_mina_actividad_grupos', function (Blueprint $table): void {
            $table->dropForeign('fk_rq_mina_act_grupos_plan');
            $table->dropIndex('idx_rq_mina_act_grupos_plan');
            $table->dropColumn('rq_mina_plan_id');
        });
    }
};
