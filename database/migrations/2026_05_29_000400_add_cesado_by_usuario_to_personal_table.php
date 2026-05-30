<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
                $table->uuid('cesado_by_usuario_id')->nullable()->after('cesado_at');
            }
        });

        if (Schema::hasTable('usuarios') && Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
            Schema::table('personal', function (Blueprint $table): void {
                $table->foreign('cesado_by_usuario_id', 'fk_personal_cesado_by_usuario')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (Schema::hasColumn('personal', 'cesado_by_usuario_id')) {
                $table->dropForeign('fk_personal_cesado_by_usuario');
                $table->dropColumn('cesado_by_usuario_id');
            }
        });
    }
};
