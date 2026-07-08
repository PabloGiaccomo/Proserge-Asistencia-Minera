<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('epp_registro') || Schema::hasColumn('epp_registro', 'vida_util_dias')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            $table->unsignedInteger('vida_util_dias')->default(0)->after('stock');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('epp_registro') || !Schema::hasColumn('epp_registro', 'vida_util_dias')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            $table->dropColumn('vida_util_dias');
        });
    }
};
