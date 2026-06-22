<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rq_mina', 'supervisor_pets_id')) {
            Schema::table('rq_mina', function (Blueprint $table): void {
                $table->char('supervisor_pets_id', 36)->nullable()->after('supervisor_id');
                $table->index('supervisor_pets_id', 'idx_rq_mina_supervisor_pets');
                $table->foreign('supervisor_pets_id', 'fk_rq_mina_supervisor_pets')
                    ->references('id')
                    ->on('personal')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rq_mina', 'supervisor_pets_id')) {
            Schema::table('rq_mina', function (Blueprint $table): void {
                $table->dropForeign('fk_rq_mina_supervisor_pets');
                $table->dropIndex('idx_rq_mina_supervisor_pets');
                $table->dropColumn('supervisor_pets_id');
            });
        }
    }
};
