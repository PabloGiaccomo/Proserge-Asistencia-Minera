<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_registro')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            if (! Schema::hasColumn('epp_registro', 'otros_atributos')) {
                $table->json('otros_atributos')->nullable()->after('colores');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('epp_registro')) {
            return;
        }

        Schema::table('epp_registro', function (Blueprint $table): void {
            if (Schema::hasColumn('epp_registro', 'otros_atributos')) {
                $table->dropColumn('otros_atributos');
            }
        });
    }
};
