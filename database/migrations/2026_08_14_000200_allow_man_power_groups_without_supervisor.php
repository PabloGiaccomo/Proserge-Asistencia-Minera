<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('grupo_trabajo') || !Schema::hasColumn('grupo_trabajo', 'supervisor_id')) {
            return;
        }

        Schema::table('grupo_trabajo', function (Blueprint $table): void {
            $table->char('supervisor_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('grupo_trabajo')
            || !Schema::hasColumn('grupo_trabajo', 'supervisor_id')
            || DB::table('grupo_trabajo')->whereNull('supervisor_id')->exists()) {
            return;
        }

        Schema::table('grupo_trabajo', function (Blueprint $table): void {
            $table->char('supervisor_id', 36)->nullable(false)->change();
        });
    }
};
