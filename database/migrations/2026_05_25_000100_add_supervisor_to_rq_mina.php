<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rq_mina', 'supervisor_id')) {
            Schema::table('rq_mina', function (Blueprint $table): void {
                $table->char('supervisor_id', 36)->nullable()->after('destino_nombre');
                $table->index('supervisor_id', 'idx_rq_mina_supervisor');
                $table->foreign('supervisor_id', 'fk_rq_mina_supervisor')
                    ->references('id')
                    ->on('personal')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rq_mina', 'supervisor_id')) {
            Schema::table('rq_mina', function (Blueprint $table): void {
                $table->dropForeign('fk_rq_mina_supervisor');
                $table->dropIndex('idx_rq_mina_supervisor');
                $table->dropColumn('supervisor_id');
            });
        }
    }
};
