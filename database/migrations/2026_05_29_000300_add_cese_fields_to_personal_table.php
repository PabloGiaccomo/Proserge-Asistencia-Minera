<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'fecha_cese')) {
                $table->date('fecha_cese')->nullable()->after('fecha_ingreso');
            }

            if (!Schema::hasColumn('personal', 'motivo_cese')) {
                $table->text('motivo_cese')->nullable()->after('fecha_cese');
            }

            if (!Schema::hasColumn('personal', 'cesado_at')) {
                $table->timestamp('cesado_at')->nullable()->after('motivo_cese');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (Schema::hasColumn('personal', 'cesado_at')) {
                $table->dropColumn('cesado_at');
            }

            if (Schema::hasColumn('personal', 'motivo_cese')) {
                $table->dropColumn('motivo_cese');
            }

            if (Schema::hasColumn('personal', 'fecha_cese')) {
                $table->dropColumn('fecha_cese');
            }
        });
    }
};
