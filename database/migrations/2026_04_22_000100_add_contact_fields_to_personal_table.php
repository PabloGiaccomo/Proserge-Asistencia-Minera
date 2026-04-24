<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'telefono')) {
                $table->string('telefono', 30)->nullable()->after('estado');
            }

            if (!Schema::hasColumn('personal', 'correo')) {
                $table->string('correo', 191)->nullable()->after('telefono');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (Schema::hasColumn('personal', 'correo')) {
                $table->dropColumn('correo');
            }

            if (Schema::hasColumn('personal', 'telefono')) {
                $table->dropColumn('telefono');
            }
        });
    }
};
