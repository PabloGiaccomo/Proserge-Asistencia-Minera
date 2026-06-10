<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_ficha_familiares') || Schema::hasColumn('personal_ficha_familiares', 'estudia')) {
            return;
        }

        Schema::table('personal_ficha_familiares', function (Blueprint $table): void {
            $table->boolean('estudia')->default(false)->after('vive_con_trabajador');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ficha_familiares') || !Schema::hasColumn('personal_ficha_familiares', 'estudia')) {
            return;
        }

        Schema::table('personal_ficha_familiares', function (Blueprint $table): void {
            $table->dropColumn('estudia');
        });
    }
};
