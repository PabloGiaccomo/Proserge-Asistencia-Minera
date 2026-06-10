<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal')) {
            return;
        }

        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'pendiente_regularizacion')) {
                $table->boolean('pendiente_regularizacion')->default(false)->after('observacion_historica');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal')) {
            return;
        }

        Schema::table('personal', function (Blueprint $table): void {
            if (Schema::hasColumn('personal', 'pendiente_regularizacion')) {
                $table->dropColumn('pendiente_regularizacion');
            }
        });
    }
};
