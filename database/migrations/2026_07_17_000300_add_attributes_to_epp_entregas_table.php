<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_entregas')) {
            return;
        }

        Schema::table('epp_entregas', function (Blueprint $table): void {
            if (! Schema::hasColumn('epp_entregas', 'talla')) {
                $table->string('talla', 80)->nullable()->after('cantidad');
            }

            if (! Schema::hasColumn('epp_entregas', 'color')) {
                $table->string('color', 120)->nullable()->after('talla');
            }

            if (! Schema::hasColumn('epp_entregas', 'atributos_json')) {
                $table->json('atributos_json')->nullable()->after('color');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('epp_entregas')) {
            return;
        }

        Schema::table('epp_entregas', function (Blueprint $table): void {
            foreach (['atributos_json', 'color', 'talla'] as $column) {
                if (Schema::hasColumn('epp_entregas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
