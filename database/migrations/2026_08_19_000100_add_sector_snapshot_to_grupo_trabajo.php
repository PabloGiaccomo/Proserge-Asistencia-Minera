<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grupo_trabajo') && !Schema::hasColumn('grupo_trabajo', 'sector_snapshot')) {
            Schema::table('grupo_trabajo', function (Blueprint $table): void {
                $table->string('sector_snapshot')->nullable()->after('area_snapshot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('grupo_trabajo') && Schema::hasColumn('grupo_trabajo', 'sector_snapshot')) {
            Schema::table('grupo_trabajo', function (Blueprint $table): void {
                $table->dropColumn('sector_snapshot');
            });
        }
    }
};
