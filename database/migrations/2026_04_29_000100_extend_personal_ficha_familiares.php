<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_ficha_familiares')) {
            return;
        }

        Schema::table('personal_ficha_familiares', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal_ficha_familiares', 'fecha_nacimiento')) {
                $table->date('fecha_nacimiento')->nullable()->after('parentesco');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ficha_familiares') || !Schema::hasColumn('personal_ficha_familiares', 'fecha_nacimiento')) {
            return;
        }

        Schema::table('personal_ficha_familiares', function (Blueprint $table): void {
            $table->dropColumn('fecha_nacimiento');
        });
    }
};
